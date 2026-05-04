<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2022 Alexey Portnov and Nikolay Beketov
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with this program.
 * If not, see <https://www.gnu.org/licenses/>.
 */

namespace Modules\ModuleBitrix24Integration\bin;
require_once 'Globals.php';

use MikoPBX\Core\System\BeanstalkClient;
use Exception;
use MikoPBX\Core\System\Processes;
use MikoPBX\Core\System\Util;
use MikoPBX\Core\Workers\WorkerBase;
use Modules\ModuleBitrix24Integration\Lib\Bitrix24Integration;
use Modules\ModuleBitrix24Integration\Lib\CacheManager;

class WorkerBitrix24IntegrationHTTP extends WorkerBase
{
    private Bitrix24Integration $b24;
    private $pidSyncProcContacts;
    private $timeSyncProcContacts;
    private array $q_req = [];
    private int $last_update_inner_num = 0;
    private BeanstalkClient $queueAgent;

    private bool  $searchEntities = false;
    private array $tmpCallsData = [];
    private array $didUsers = [];
    private array $perCallQueues = [];
    private bool  $hasPendingEvents = false;
    private bool  $insideExecuteTasks = false;
    private string $processState = 'init';

    private int $lastSyncTime = 0;
    private int $syncInterval = 10;
    private const SYNC_INTERVAL_MIN  = 60;
    private const SYNC_INTERVAL_MAX  = 600;
    private const SYNC_INTERVAL_STEP = 2;

    private ?int $pidLinksSyncProc = null;
    private int  $timeLinksSyncProc = 0;

    /**
     * Handles the received signal.
     *
     * @param int $signal The signal to handle.
     *
     * @return void
     */
    public function signalHandler(int $signal): void
    {
        parent::signalHandler($signal);
        cli_set_process_title("SHUTDOWN[{$this->processState}]_" . self::class);
    }

    /**
     * Начало работы демона.
     *
     * @param $argv
     */
    public function start($argv): void
    {
        // Поднимаем PHP memory_limit. Дефолт 128M ловит OOM на batch-ответах
        // crm.lead.list / crm.contact.list / crm.company.list, когда в портале
        // десятки тысяч записей. См. Sentry MIKOPBX-MH7 / issue #135.
        ini_set('memory_limit', '256M');
        $this->b24 = new Bitrix24Integration();
        if (!$this->b24->initialized) {
            die('Settings not set...');
        }
        $this->b24->mainLogger->writeInfo('Starting...');
        $this->b24->checkNeedUpdateToken();
        // При старте синхронизируем внешние линии.
        $externalLines = $this->b24->syncExternalLines();
        foreach ($externalLines as $line){
            if($line['disabled'] === '1'){
                continue;
            }
            $nums = $this->parseInnerNumbers($line['name']);
            if(empty($nums)){
                continue;
            }
            $this->didUsers[$line['alias']] = $nums;
        }

        $this->searchEntities = !empty($this->didUsers);

        // Автоматический reap дочерних процессов (без зомби)
        pcntl_signal(SIGCHLD, SIG_IGN);

        // Watchdog: SIGALRM прерывает зависший poll() в reserveWithTimeout()
        pcntl_signal(SIGALRM, function () {
            // Пустой обработчик — достаточно прервать poll()
        }, false); // false = НЕ перезапускать прерванные syscall

        /** Основной цикл демона. */
        $this->initBeanstalk();
        $this->processState = 'idle';
        while ($this->needRestart === false) {
            try {
                $this->processState = 'beanstalk_wait';
                pcntl_alarm(5);
                $timeout = $this->hasPendingEvents ? 0 : 1;
                $this->queueAgent->wait($timeout);
                pcntl_alarm(0);
                $this->processState = 'idle';
            } catch (Exception $e) {
                pcntl_alarm(0);
                $this->processState = 'reconnect';
                sleep(1);
                $this->initBeanstalk();
                $this->processState = 'idle';
            }
        }
    }

    /**
     * Инициализация BeanstalkClient.
     */
    private function initBeanstalk(): void
    {
        $this->queueAgent = new BeanstalkClient(Bitrix24Integration::B24_INTEGRATION_CHANNEL);
        $this->queueAgent->subscribe($this->makePingTubeName(self::class), [$this, 'pingCallBack']);
        $this->queueAgent->subscribe(Bitrix24Integration::B24_INTEGRATION_CHANNEL, [$this, 'b24ChannelCallBack']);
        $this->queueAgent->subscribe(Bitrix24Integration::B24_SEARCH_CHANNEL, [$this, 'b24ChannelSearch']);
        $this->queueAgent->subscribe(Bitrix24Integration::B24_INVOKE_REST_CHANNEL, [$this, 'invokeRest']);
        $this->queueAgent->setTimeoutHandler([$this, 'executeTasks']);
    }
    public function pingCallBack(BeanstalkClient $message): void
    {
        if($this->needRestart){
            // Нет смысла отвечать,
            return;
        }
        $this->b24->mainLogger->writeInfo('Get ping event...');
        parent::pingCallBack($message);
    }

    /**
     * Обращение к API из внешнего скрипта.
     * @param $client
     * @return void
     */
    public function invokeRest($client): void
    {
        $data = json_decode($client->getBody(), true);
        $action = $data['action']??'';
        $arg = [];
        if($action === 'scope'){
            $arg = $this->b24->getScopeAsync($data['inbox_tube']??'');
        }elseif($action === 'needRestart'){
            $this->needRestart = true;
        }
        if(!empty($arg)){
            $this->b24->mainLogger->writeInfo($data, "Add action $action in queue...");
            $this->q_req = array_merge($this->q_req, $arg);
        }
    }

    /**
     * Обработка ответа API внешнему скрипту.
     * @param $response
     * @param $tube
     * @param $partResponse
     * @return void
     */
    public function invokeRestCheckResponse($response,$tube, $partResponse): void
    {
        $this->b24->mainLogger->writeInfo([$response, $partResponse],"Response to tube $tube");
        $resFile = ConnectorDb::saveResultInTmpFile($partResponse);
        $this->queueAgent->publish($resFile, $tube);
    }

    public function b24ChannelSearch($client): void
    {
        $data = json_decode($client->getBody(), true);
        $this->createTmpCallData($data);
    }

    /**
     * @param BeanstalkClient $client
     */
    public function b24ChannelCallBack($client): void
    {
        $srcData = $client->getBody();
        try {
            /** @var array $data */
            $data = json_decode($srcData, true, 512, JSON_THROW_ON_ERROR);
        }catch (Exception $e){
            $this->mainLogger->logger->writeInfo('AMI Event'. $e->getMessage());
            return;
        }
        $linkedId = $data['linkedid']??'';
        if(empty($linkedId)){
            $this->b24->mainLogger->writeError($data, 'Get AMI, EMPTY linkedid');
            return;
        }
        $this->b24->mainLogger->writeInfo($data, 'Get AMI Event');
        if ($this->searchEntities
            && !isset($this->tmpCallsData[$linkedId])
            && $data['action'] === 'telephonyExternalCallRegister') {
            $this->createTmpCallData($data);
        }
        if(!isset($this->perCallQueues[$linkedId])){
            $this->perCallQueues[$linkedId] = new \SplQueue();
        }
        $this->perCallQueues[$linkedId]->enqueue($data);
    }

    /**
     * Add job to req queue
     * @param array $data
     * @return void
     */
    private function addDataToQueue(array $data): void
    {
        if ('telephonyExternalCallRegister' === $data['action']) {
            $cache_key = 'tmp10' . __FUNCTION__ . $data['UNIQUEID'] . '_' . $data['USER_PHONE_INNER'];
            $res_data = $this->b24->getCache($cache_key);
            if ($res_data === null) {
                $this->b24->saveCache($cache_key, $data);

                $pre_call_key = "tmp5_{$data['USER_PHONE_INNER']}_" . Bitrix24Integration::getPhoneIndex($data['PHONE_NUMBER']);
                $cache_data = $this->b24->getCache($pre_call_key);
                if ($cache_data !== null) {
                    $data['PHONE_NUMBER'] = $cache_data['PHONE_NUMBER'] ?? $data['PHONE_NUMBER'];
                }
                $pre_call_key = "tmp5_ONEXTERNALCALLBACKSTART_" . Bitrix24Integration::getPhoneIndex($data['PHONE_NUMBER']);
                $cache_data = $this->b24->getCache($pre_call_key);
                if ($cache_data !== null) {
                    $data['PHONE_NUMBER']    = $cache_data['PHONE_NUMBER'] ?? $data['PHONE_NUMBER'];
                    $data['CRM_ENTITY_ID']   = $cache_data['CRM_ENTITY_ID'] ?? '';
                    $data['CRM_ENTITY_TYPE'] = $cache_data['CRM_ENTITY_TYPE'] ?? '';
                }elseif($data['TYPE'] === '1'){
                    // Для исходящих определяем идентификатор и тип контакта.
                    $contactsData = ConnectorDb::invoke(ConnectorDb::FUNC_GET_CONTACT_BY_PHONE_USER, [$data['PHONE_NUMBER'], $data['USER_ID']]);
                    $data['PHONE_NUMBER']    = $contactsData['phone']??$data['PHONE_NUMBER'];
                    $data['CRM_ENTITY_ID']   = $contactsData['b24id']??'';
                    $data['CRM_ENTITY_TYPE'] = $contactsData['contactType']??'';
                }

                $arg = [];
                $callId = &$this->tmpCallsData[$data['linkedid']]['CALL_ID'];
                if(empty($callId)){
                    // Save user id for current unique leg to use on dial_answer
                    $this->tmpCallsData[$data['linkedid']]['ARG_REGISTER_USER_'.$data['UNIQUEID']] = $data['USER_ID']??'';
                    [$arg, $key] = $this->b24->telephonyExternalCallRegister($data);
                    if(!empty($key)){
                        // Это Метод register
                        $callId = $key;
                    }
                }else{
                    $this->tmpCallsData[$data['linkedid']]['ARG_REGISTER_USER_'.$data['UNIQUEID']] = $data['USER_ID']??'';
                    $this->tmpCallsData[$data['linkedid']]['ARGS_REGISTER_'.$data['UNIQUEID']] = $this->b24->telephonyExternalCallRegister($data);
                    $data['CALL_ID'] = $this->b24->resolveBatchCallId((string)$callId);
                    if($this->needShowCardDirectly($data['USER_ID'])){
                        $arg = $this->b24->telephonyExternalCallShow($data);
                    }
                }
                if (count($arg) > 0) {
                    // Основная очередь запросов.
                    $this->q_req = array_merge($this->q_req, $arg);
                }
                unset($callId);
            }
        } elseif ('action_hangup_chan' === $data['action']) {
            // Надежнее вычислить внутренний номер из канала.
            $number = $this->parsePJSIP($data['channel']);
            $callData = $this->tmpCallsData[$data['linkedid']] ?? [];
            $data['CALL_ID'] = $callData['CALL_ID']??'';
            $data['USER_ID'] = $this->b24->inner_numbers[$number]['ID']??'';
            if (!empty($data['CALL_ID']) && !empty($data['USER_ID'])) {
                $arg = $this->b24->telephonyExternalCallHide($data);
                $this->q_req = array_merge($this->q_req, $arg);
            }
        } elseif ('action_dial_answer' === $data['action']) {
            $tmpArr = [];
            $userId = $this->tmpCallsData[$data['linkedid']]['ARG_REGISTER_USER_'.$data['UNIQUEID']]??'';
            $dealId = '';
            $leadId = '';
            $filter = [
                "linkedid='{$data['linkedid']}'",
                'order' => 'uniq_id'
            ];
            $b24CdrRows = ConnectorDb::invoke(ConnectorDb::FUNC_GET_CDR_BY_FILTER, [$filter]);
            foreach ($b24CdrRows as $cdrData) {
                $row = (object)$cdrData;
                $cdr = $row;
                if (!empty($cdr->dealId)) {
                    $dealId = max($dealId, $cdr->dealId);
                }
                if (!empty($cdr->lead_id)) {
                    $leadId = max($leadId, $cdr->lead_id);
                }
                // Отмечаем вызов как отвеченный.
                $cdr->answer = 1;
                ConnectorDb::invoke(ConnectorDb::FUNC_UPDATE_FROM_ARRAY_CDR_BY_UID, [$row->uniq_id, (array)$cdr]);
                if (intval($userId) !== intval($row->user_id)) {
                    // Открываем карточку клиента тому, кто ответил. (если разрешено).
                    $data['CALL_ID'] = $row->call_id;
                    $data['USER_ID'] = (int)$userId;
                    // Поиск внутреннего номера пользователя b24.
                    if($this->needShowCardOnAnswer($userId)){
                        $tmpArr[] = $this->b24->telephonyExternalCallShow($data);
                    }
                }
            }
            if (!empty($leadId) && !empty($userId)) {
                $tmpArr[] = $this->b24->crmLeadUpdate($leadId, $userId, $data['linkedid']);
            }
            // Если лид добавляется вручную, до звонка методом crm.lead.add
            if(($this->tmpCallsData[$data['linkedid']]['crm-data']['CRM_ENTITY_TYPE']??'') === 'LEAD'
               && !isset($this->tmpCallsData[$data['linkedid']]['crm-data']['ID'])){
                // Update lead only if we know the responsible user
                if(!empty($userId)){
                    $tmpArr[] = $this->b24->crmLeadUpdate($this->tmpCallsData[$data['linkedid']]['crm-data']['CRM_ENTITY_ID'], $userId, $data['linkedid']);
                }else{
                    $this->b24->mainLogger->writeInfo($data, "Error empty userId, can not update lead ($data[linkedid])");
                }
            }
            if(!empty($tmpArr)){
                $this->q_req = array_merge($this->q_req, ...$tmpArr);
            }
        } elseif ('telephonyExternalCallFinish' === $data['action']) {
            [$arg,$finishKey] = $this->b24->telephonyExternalCallFinish($data, $this->tmpCallsData);
            $this->q_req = array_merge($this->q_req, $arg);

            if(!empty($finishKey)){
                $arg = $this->b24->crmActivityUpdate('$result['.$finishKey.'][CRM_ACTIVITY_ID]', $data['linkedid'], $data['linkedid']);
                $this->q_req = array_merge($this->q_req, $arg);
            }
        }else{
            $this->b24->mainLogger->writeInfo($data, "The event handler was not found ($data[linkedid])");
        }

        if (!$this->insideExecuteTasks && count($this->q_req) >= 49) {
            $this->executeTasks();
        }
    }

    /**
     * @param $s
     * @return string|null
     */
    private function parsePJSIP($s):?string
    {
        if (strpos($s, 'PJSIP/') === 0) {
            $s = substr($s, 6);
        }else{
            return null;
        }
        $parts = explode('-', $s);
        if (count($parts) < 2) {
            return null;
        }
        array_pop($parts);
        return implode('-', $parts);
    }

    /**
     * Нужно ли показать карточку сразу при начале звонка (режим DIRECTLY или по умолчанию).
     * Используется для второго и последующих участников очереди, которым нужно отправить отдельный show.
     * @param $userId
     * @return bool
     */
    private function needShowCardDirectly($userId): bool
    {
        $mode = $this->getUserOpenCardMode($userId);
        return $mode === Bitrix24Integration::OPEN_CARD_DIRECTLY || $mode === '';
    }

    /**
     * Нужно ли показать карточку при ответе на звонок (режим ANSWERED).
     * Используется в обработчике action_dial_answer для открытия карточки ответившему сотруднику.
     * @param $userId
     * @return bool
     */
    private function needShowCardOnAnswer($userId): bool
    {
        return $this->getUserOpenCardMode($userId) === Bitrix24Integration::OPEN_CARD_ANSWERED;
    }

    /**
     * Получить настройку open_card_mode для пользователя по его ID в Bitrix24.
     * @param $userId
     * @return string
     */
    private function getUserOpenCardMode($userId): string
    {
        $tmpInnerNumArray = array_values($this->b24->inner_numbers);
        $index = array_search($userId, array_column($tmpInnerNumArray, 'ID'), true);
        if ($index === false) {
            return '';
        }
        $innerNumber = $tmpInnerNumArray[$index]['UF_PHONE_INNER'] ?? '';
        return $this->b24->usersSettingsB24[$innerNumber]['open_card_mode'] ?? '';
    }

    public function shouldDeferForPreAction(&$data): bool
    {
        $action = $data['action']??'';
        $id     = $data['linkedid']??'';

        if($data['UNIQUEID'] === ''){
            $this->b24->mainLogger->writeError($data, "Empty UID $id...");
            return false;
        }
        $needActions = true;
        if ($this->searchEntities) {
            if (!isset($this->tmpCallsData[$id]) && $action === 'telephonyExternalCallRegister') {
                $this->createTmpCallData($data);
            }
            $callData = &$this->tmpCallsData[$id];
            if ($action === 'telephonyExternalCallRegister'
                && ($callData['data']['action']??'') !== 'telephonyExternalCallRegister'){
                $callData['data'] = $data;
                $data['CRM_ENTITY_TYPE'] = $callData['crm-data']['CRM_ENTITY_TYPE'];
                $data['CRM_ENTITY_ID']   = $callData['crm-data']['CRM_ENTITY_ID'];
            }
            $wait = $callData['wait']?? false;
            if ($wait === false) {
                $this->b24->mainLogger->writeInfo($data, "Process (1) $id...");
                $needActions = false;
            }else{
                $this->b24->mainLogger->writeInfo($data, "Event wait call register(2)... $id: ");
            }
        } else {
            $this->b24->mainLogger->writeInfo($data, "Process (2) $id...");
            $needActions = false;
        }
        return $needActions;
    }

    /**
     * Запуск процесса поиска килениета по номеру. Подготовка временной таблицы.
     * @param $data
     * @return void
     */
    private function createTmpCallData($data):void
    {
        if(isset($this->tmpCallsData[$data['linkedid']])){
            // Выполнять однократно.
            return;
        }
        $this->tmpCallsData[$data['linkedid']] = [
            'wait'       => true,
            'events'     => [],
            'search'     => -1, // -1 - запрос не отправлен, 0 - запрос отправлен, 1 ответ получен
            'lead'       => -1,
            'list-lead'  => -1,
            'company'    => -1,
            'data'       => $data,
            'crm-data'   => [],
            'inbox_tube' => $data['inbox_tube']??'',
            'responsible'=> '',
            'CALL_ID'    => '',
        ];
        $phone = $data['PHONE_NUMBER'] ?? '';
        if(empty($phone)){
            $this->b24->mainLogger->writeError($data, 'Empty phone number... ');
        }elseif ($this->tmpCallsData[$data['linkedid']]['search'] === -1 ) {
            $this->tmpCallsData[$data['linkedid']]['search'] = 1;
            $this->findEntitiesByPhone($phone, $data['linkedid']);
        }
    }

    /**
     * Тонкая обёртка: оборачивает events в формат, ожидаемый handleEvent().
     */
    private function processOfflineEvents(array $events): void
    {
        $this->handleEvent(['event.offline.get' => ['events' => $events]]);
    }

    /**
     * Обработка событий b24.
     * @param $result
     * @return void
     */
    public function handleEvent($result):void
    {
        $eventActionsDelete = [
            'ONCRMLEADDELETE'    => 'LEAD',
            'ONCRMCONTACTDELETE' => 'CONTACT',
            'ONCRMCOMPANYDELETE' => 'COMPANY',
        ];
        $eventActionsUpdate = [
            'ONCRMLEADUPDATE'    => Bitrix24Integration::API_CRM_LIST_LEAD,
            'ONCRMCONTACTUPDATE' => Bitrix24Integration::API_CRM_LIST_CONTACT,
            'ONCRMCOMPANYUPDATE' => Bitrix24Integration::API_CRM_LIST_COMPANY,
        ];
        $events = $result['event.offline.get']['events'] ?? [];

        // Дедупликация: собираем уникальные ID по типу сущности.
        $updateIds = [];
        $contactIdsForLinks = [];
        $companyIdsForLinks = [];

        foreach ($events as $event) {
            $eventData = $event['EVENT_DATA'];
            if (isset($eventActionsDelete[$event['EVENT_NAME']])) {
                $id = array_values($eventData['FIELDS']);
                ConnectorDb::invoke(ConnectorDb::FUNC_DELETE_CONTACT_DATA, [$eventActionsDelete[$event['EVENT_NAME']], $id], false);
            }
            if (isset($eventActionsUpdate[$event['EVENT_NAME']])){
                $type = $eventActionsUpdate[$event['EVENT_NAME']];
                $arIds = array_values($eventData['FIELDS']);
                foreach ($arIds as $id) {
                    $updateIds[$type][$id] = true;
                    if ($event['EVENT_NAME'] === 'ONCRMCONTACTUPDATE') {
                        $contactIdsForLinks[$id] = true;
                    } elseif ($event['EVENT_NAME'] === 'ONCRMCOMPANYUPDATE') {
                        $companyIdsForLinks[$id] = true;
                    }
                }
            }
            $this->b24->handleEvent(['event' => $event, 'data' => $eventData]);
        }

        // Один crmListEnt на тип сущности вместо отдельного на каждое событие.
        $args = [];
        foreach ($updateIds as $type => $idsMap) {
            $args[] = $this->b24->crmListEnt($type, array_keys($idsMap));
        }
        // Relationship-запросы с кешем (TTL 5 мин) — пропускаем недавно запрошенные.
        foreach (array_keys($contactIdsForLinks) as $id) {
            $cacheKey = 'rel_contact_' . $id;
            if ($this->b24->getCache($cacheKey) === null) {
                $args[] = $this->b24->getContactCompany($id);
                $this->b24->saveCache($cacheKey, '1', 300);
            }
        }
        foreach (array_keys($companyIdsForLinks) as $id) {
            $cacheKey = 'rel_company_' . $id;
            if ($this->b24->getCache($cacheKey) === null) {
                $args[] = $this->b24->getCompanyContacts($id);
                $this->b24->saveCache($cacheKey, '1', 300);
            }
        }

        if (!empty($args)) {
            $this->q_req = array_merge($this->q_req, array_merge(...$args));
        }
    }


    /**
     * Делит массив на части.
     * @param array $array
     * @return array
     */
    private function chunkAssociativeArray(array $array):array
    {
        $chunks = [];
        $chunk = [];
        $count = 0;

        foreach ($array as $key => $value) {
            if ($count >= 49) {
                $chunks[] = $chunk;
                $chunk = [];
                $count = 0;
            }
            $chunk[$key] = $value;
            $count++;
        }

        if ($count > 0) {
            $chunks[] = $chunk;
        }

        return $chunks;
    }

    private function syncProcContacts()
    {
        if(!empty($this->pidSyncProcContacts)){
            $res = pcntl_waitpid($this->pidSyncProcContacts, $status, WNOHANG);
            if ($res === 0) {
                // Ребёнок ещё работает
                if(time() - $this->timeSyncProcContacts > 40){
                    posix_kill($this->pidSyncProcContacts, SIGKILL);
                    pcntl_waitpid($this->pidSyncProcContacts, $status);
                }
                return;
            }
            // Ребёнок завершён (или уже подчищен SIG_IGN) — можно форкать снова
            $this->pidSyncProcContacts = null;
        }

        $this->timeSyncProcContacts = time();
        $this->pidSyncProcContacts = pcntl_fork();
        if ($this->pidSyncProcContacts == -1) {
            $this->b24->mainLogger->writeError('Fail fork sync contacts... ');
            return;
        } elseif ($this->pidSyncProcContacts) {
            $this->b24->mainLogger->writeInfo('Start sync contacts... '.$this->pidSyncProcContacts);
            usleep(100000);
            return;
        }
        $this->b24->setIsNotMainProcess();
        $this->needRestart = true;
        set_time_limit(50);
        cli_set_process_title("B24_HTTP_SYNC_CONTACTS");
        $syncProcReq = [];
        $arg = $this->b24->crmListEnt(Bitrix24Integration::API_CRM_LIST_CONTACT);
        $syncProcReq = array_merge($syncProcReq, $arg);

        $arg = $this->b24->crmListEnt(Bitrix24Integration::API_CRM_LIST_COMPANY);
        $syncProcReq = array_merge($syncProcReq, $arg);

        $arg = $this->b24->crmListEnt(Bitrix24Integration::API_CRM_LIST_LEAD);
        $syncProcReq = array_merge($syncProcReq, $arg);

        $response = $this->b24->sendBatch($syncProcReq);
        $result = $response['result']['result'] ?? [];

        $contactCompanyLinks = [];
        foreach ($result as $key => $partResponse) {
            [$actionName, $id] = explode('_', $key);
            if (in_array($actionName, [ Bitrix24Integration::API_CRM_LIST_CONTACT,Bitrix24Integration::API_CRM_LIST_COMPANY, Bitrix24Integration::API_CRM_LIST_LEAD], true )) {
                $this->b24->crmListEntResults($actionName, $id, $partResponse, false);
                // Извлекаем связи контакт-компания из уже полученных данных вместо
                // отдельных API-вызовов crm.company.contact.items.get / crm.contact.company.items.get.
                if (Bitrix24Integration::API_CRM_LIST_CONTACT === $actionName) {
                    foreach ($partResponse as $data) {
                        $companyId = $data['COMPANY_ID'] ?? '';
                        if (!empty($companyId) && !empty($data['ID'])) {
                            $contactCompanyLinks[] = [
                                'contactId' => $data['ID'],
                                'companyId' => $companyId,
                            ];
                        }
                    }
                }
            }
        }
        if (!empty($contactCompanyLinks)) {
            ConnectorDb::invoke(ConnectorDb::FUNC_UPDATE_LINKS_BATCH, [$contactCompanyLinks], false);
        }
        // Это дочерний процесс, завершаем его.
        exit(0);
    }

    /**
     * Проверяет наличие запроса на ручную синхронизацию связей и запускает процесс.
     */
    private function checkLinksSync(): void
    {
        // Проверяем завершение предыдущего процесса.
        if (!empty($this->pidLinksSyncProc)) {
            $res = pcntl_waitpid($this->pidLinksSyncProc, $status, WNOHANG);
            if ($res === 0) {
                // Ещё работает. Таймаут 12 часов — на крупных порталах
                // синхронизация всех связей может занять несколько часов.
                if (time() - $this->timeLinksSyncProc > 43200) {
                    posix_kill($this->pidLinksSyncProc, SIGKILL);
                    pcntl_waitpid($this->pidLinksSyncProc, $status);
                    CacheManager::setCacheData('links_sync_state', [
                        'status' => 'error',
                        'message' => 'Синхронизация прервана по таймауту (12ч)',
                    ], 300);
                } else {
                    return;
                }
            }
            $this->pidLinksSyncProc = null;
        }

        $state = CacheManager::getCacheData('links_sync_state');
        if (($state['status'] ?? '') !== 'pending') {
            return;
        }

        CacheManager::setCacheData('links_sync_state', [
            'status' => 'running',
            'progress' => 0,
            'total' => 0,
            'message' => 'Подсчёт сущностей...',
        ], 86400);

        $this->timeLinksSyncProc = time();
        $this->pidLinksSyncProc = pcntl_fork();
        if ($this->pidLinksSyncProc == -1) {
            $this->b24->mainLogger->writeError('Fail fork links sync');
            CacheManager::setCacheData('links_sync_state', [
                'status' => 'error',
                'message' => 'Не удалось запустить процесс синхронизации',
            ], 300);
            return;
        } elseif ($this->pidLinksSyncProc) {
            $this->b24->mainLogger->writeInfo('Start links sync... ' . $this->pidLinksSyncProc);
            return;
        }

        // Дочерний процесс — долгоживущий, без ограничения по времени.
        $this->b24->setIsNotMainProcess();
        $this->needRestart = true;
        set_time_limit(0);
        cli_set_process_title("B24_HTTP_SYNC_LINKS");
        try {
            $this->syncAllLinks();
        } catch (\Throwable $e) {
            $this->b24->mainLogger->writeError('Links sync crashed: ' . $e->getMessage());
            CacheManager::setCacheData('links_sync_state', [
                'status' => 'error',
                'message' => 'Ошибка: ' . $e->getMessage(),
            ], 3600);
        }
        exit(0);
    }

    /**
     * Полная синхронизация связей контактов и компаний через API Bitrix24.
     * Запускается вручную из UI. Работает в fork-процессе.
     */
    private function syncAllLinks(): void
    {
        // 1. Получаем все ID контактов и компаний из локальной телефонной книги.
        $contactIds = ConnectorDb::invoke(ConnectorDb::FUNC_GET_ENTITY_IDS, ['CONTACT']);
        $companyIds = ConnectorDb::invoke(ConnectorDb::FUNC_GET_ENTITY_IDS, ['COMPANY']);

        if (!is_array($contactIds)) {
            $contactIds = [];
        }
        if (!is_array($companyIds)) {
            $companyIds = [];
        }

        $totalContacts = count($contactIds);
        $totalCompanies = count($companyIds);
        $total = $totalContacts + $totalCompanies;

        CacheManager::setCacheData('links_sync_state', [
            'status' => 'running',
            'progress' => 0,
            'total' => $total,
            'message' => "Контактов: $totalContacts, компаний: $totalCompanies",
        ], 86400);

        $processed = 0;

        // 2. Синхронизация связей компаний (crm.company.contact.items.get).
        $batchSize = 50;
        $errors = 0;
        $chunks = array_chunk($companyIds, $batchSize);
        foreach ($chunks as $chunk) {
            $batchReq = [];
            foreach ($chunk as $id) {
                $batchReq = array_merge($batchReq, $this->b24->getCompanyContacts($id));
            }
            $response = $this->b24->sendBatch($batchReq);
            if (!empty($response['result']['result'])) {
                ConnectorDb::invoke(ConnectorDb::FUNC_UPDATE_LINKS, [$response['result']['result']], false);
            } elseif (empty($response)) {
                $errors++;
                $this->b24->mainLogger->writeError("Links sync: empty response for companies batch at $processed");
            }
            $processed += count($chunk);
            CacheManager::setCacheData('links_sync_state', [
                'status' => 'running',
                'progress' => $processed,
                'total' => $total,
                'message' => "Компании: $processed / $totalCompanies" . ($errors > 0 ? " (ошибок: $errors)" : ''),
            ], 86400);
        }

        // 3. Синхронизация связей контактов (crm.contact.company.items.get).
        $chunks = array_chunk($contactIds, $batchSize);
        foreach ($chunks as $chunk) {
            $batchReq = [];
            foreach ($chunk as $id) {
                $batchReq = array_merge($batchReq, $this->b24->getContactCompany($id));
            }
            $response = $this->b24->sendBatch($batchReq);
            if (!empty($response['result']['result'])) {
                ConnectorDb::invoke(ConnectorDb::FUNC_UPDATE_LINKS, [$response['result']['result']], false);
            } elseif (empty($response)) {
                $errors++;
                $this->b24->mainLogger->writeError("Links sync: empty response for contacts batch at $processed");
            }
            $processed += count($chunk);
            CacheManager::setCacheData('links_sync_state', [
                'status' => 'running',
                'progress' => $processed,
                'total' => $total,
                'message' => "Контакты: " . ($processed - $totalCompanies) . " / $totalContacts" . ($errors > 0 ? " (ошибок: $errors)" : ''),
            ], 86400);
        }

        CacheManager::setCacheData('links_sync_state', [
            'status' => 'done',
            'progress' => $total,
            'total' => $total,
            'message' => "Готово. Обработано компаний: $totalCompanies, контактов: $totalContacts",
        ], 3600);
    }

    /**
     *
     */
    public function executeTasks(): void
    {
        $this->insideExecuteTasks = true;
        try {
            $this->executeTasksInner();
        } finally {
            $this->insideExecuteTasks = false;
        }
    }

    private function executeTasksInner(): void
    {
        if ($this->needRestart) {
            return;
        }
        $this->b24->mainLogger->rotate();

        $now = time();
        $delta = $now - $this->last_update_inner_num;
        if ($delta > 10) {
            $this->processState = 'updateSettings';
            $this->b24->checkNeedUpdateToken();
            if ($this->needRestart) {
                return;
            }
            // Прямой опрос offline-событий — гарантированно каждые ~10с.
            $evResponse = $this->b24->sendBatch($this->b24->eventOfflineGet());
            $offlineEvents = $evResponse['result']['result']['event.offline.get']['events'] ?? [];
            if (!empty($offlineEvents)) {
                $evCount = count($offlineEvents);
                $this->b24->mainLogger->writeInfo(
                    "event.offline.get: {$evCount} events: "
                    . json_encode(array_column($offlineEvents, 'EVENT_NAME'))
                );
                $this->processOfflineEvents($offlineEvents);
            }
            if ($this->b24->getAuthFailureCount() >= Bitrix24Integration::AUTH_FAILURE_THRESHOLD) {
                $this->b24->mainLogger->writeError(
                    'Auth failure threshold reached (' . $this->b24->getAuthFailureCount() . '), restarting worker'
                );
                $this->needRestart = true;
                return;
            }
            $prevLastContactId = $this->b24->lastContactId;
            $prevLastCompanyId = $this->b24->lastCompanyId;
            $prevLastLeadId    = $this->b24->lastLeadId;

            $this->b24->b24GetPhones();
            if ($this->needRestart) {
                return;
            }
            $this->b24->updateSettings();
            $this->last_update_inner_num = $now;
            $this->checkActiveChannels();

            // Адаптивный интервал синхронизации контактов.
            // Начальная синхронизация (lastId == "0") — каждые 10с.
            // Steady state — backoff до 300с если нет новых данных.
            $isInitialSync = ($this->b24->lastContactId === '0'
                || $this->b24->lastCompanyId === '0'
                || $this->b24->lastLeadId === '0');

            if ($isInitialSync || ($now - $this->lastSyncTime) >= $this->syncInterval) {
                $this->syncProcContacts();
                $this->lastSyncTime = $now;

                // Проверяем, были ли загружены новые данные (lastId изменился).
                $hasNewData = ($this->b24->lastContactId !== $prevLastContactId
                    || $this->b24->lastCompanyId !== $prevLastCompanyId
                    || $this->b24->lastLeadId    !== $prevLastLeadId);

                if ($isInitialSync || $hasNewData) {
                    $this->syncInterval = self::SYNC_INTERVAL_MIN;
                } else {
                    $this->syncInterval = min($this->syncInterval * self::SYNC_INTERVAL_STEP, self::SYNC_INTERVAL_MAX);
                }
            }

            $this->checkLinksSync();

            $this->processState = 'idle';
            if ($this->needRestart) {
                return;
            }
        }

        if ($this->needRestart) {
            return;
        }

        // Обработка очередей: все доступные события для каждого вызова
        $this->drainPerCallQueues();

        if (count($this->q_req) > 0) {
            $this->processState = 'sendBatch';
            $chunks = $this->chunkAssociativeArray($this->q_req);
            $finalResult = [];
            foreach ($chunks as $chunk) {
                if ($this->needRestart) {
                    break;
                }
                $response = $this->b24->sendBatch($chunk);
                $finalResult[] = $response['result']['result'] ?? [];
            }
            $this->processState = 'postProcessing';
            $result = array_merge(...$finalResult);
            // Чистим очередь запросов.
            $this->q_req = [];
            $this->postReceivingResponseProcessing($result);
            $this->handleEvent($result);

            // Re-drain: post-processing мог разблокировать отложенные события
            // (postCrmAddLead/postCrmAddContact ставят wait=false)
            $this->drainPerCallQueues();
            $this->processState = 'idle';
        }

        $this->hasPendingEvents = false;
        foreach ($this->perCallQueues as $queue) {
            if (!$queue->isEmpty()) {
                $this->hasPendingEvents = true;
                break;
            }
        }
    }

    /**
     * Извлечение всех не-отложенных событий из очередей вызовов и добавление в q_req.
     * Обработка останавливается для очереди, если shouldDeferForPreAction вернёт true.
     */
    private function drainPerCallQueues(): void
    {
        foreach ($this->perCallQueues as $queue) {
            while (!$queue->isEmpty()) {
                $event = $queue->bottom();
                if ($this->shouldDeferForPreAction($event)) {
                    break;
                }
                $queue->dequeue();
                $this->addDataToQueue($event);
            }
        }
    }

    /**
     * Дополнительные действия после получения овета на запрос к API.
     * @param array $result
     */
    public function postReceivingResponseProcessing(array $result): void
    {
        $tmpArr = [];
        foreach ($result as $key => $partResponse) {
            $id = '';
            $tube = '';
            $keyData = explode('_', $key);
            if(count($keyData) === 3){
                [$actionName, $id, $tube] = $keyData;
            }elseif(count($keyData) === 2){
                [$actionName, $id] = $keyData;
            }else{
                $actionName = $key;
            }

            if ($actionName === Bitrix24Integration::API_CALL_REGISTER) {
                $resultRegister = $this->b24->telephonyExternalCallPostRegister($key, $partResponse);
                if(!empty($resultRegister)){
                    [$linkedId, $callId] = $resultRegister;
                    $this->b24->mainLogger->writeInfo("Update call_id for $linkedId - $callId");
                    $this->tmpCallsData[$linkedId]['CALL_ID'] = $callId;
                }else{
                    $this->b24->mainLogger->writeInfo("fail Update call_id for $key");
                }
            } elseif (in_array($actionName,[Bitrix24Integration::API_CRM_CONTACT_COMPANY,Bitrix24Integration::API_CRM_COMPANY_CONTACT], true)) {
                ConnectorDb::invoke(ConnectorDb::FUNC_UPDATE_LINKS, [[$key => $partResponse]], false);
            } elseif (in_array($id,['init', 'update'], true)){
                $this->b24->crmListEntResults($actionName, $id, $partResponse);
            } elseif(stripos($tube, Bitrix24Integration::B24_INVOKE_REST_CHANNEL) !== false){
                $this->invokeRestCheckResponse($key, $tube, $partResponse);
            } elseif ($actionName === Bitrix24Integration::API_ATTACH_RECORD) {
                $uploadUrl = $partResponse["uploadUrl"] ?? '';
                $data = $this->b24->telephonyPostAttachRecord($key, $uploadUrl);
                if (!empty($data)) {
                    $this->queueAgent->publish(json_encode($data, JSON_UNESCAPED_SLASHES), UploaderB24::B24_UPLOADER_CHANNEL);
                }
            } elseif ($actionName === Bitrix24Integration::API_CRM_ADD_LEAD) {
                $this->postCrmAddLead($key, $partResponse);
            } elseif ($actionName === Bitrix24Integration::API_CRM_ADD_CONTACT) {
                $this->postCrmAddContact($key, $partResponse);
            } elseif ($actionName === Bitrix24Integration::API_CALL_FINISH) {
                $this->b24->telephonyExternalCallPostFinish($key, $partResponse, $tmpArr);
            }
        }
        $tmpArr = array_merge(...$tmpArr);
        $this->q_req = array_merge($this->q_req, $tmpArr);
    }

    /**
     * Поиск сущности 'COMPANY', 'LEAD', 'CONTACT' по номеру телефона;
     * @param string $phone
     * @param string $linkedId
     * @return void
     */
    public function findEntitiesByPhone(string $phone, string $linkedId = ''):void
    {
        $callData = &$this->tmpCallsData[$linkedId];
        $contactsData = ConnectorDb::invoke(ConnectorDb::FUNC_GET_CONTACT_BY_PHONE, [$phone]);

        $did         = $callData['data']['did']??'';
        $chooseFirst = !isset($this->didUsers[$did]);
        $users       = $this->didUsers[$did]??[];

        foreach (['LEAD', 'CONTACT', 'COMPANY'] as $type){
            if($callData['wait'] === false){
                break;
            }
            foreach ($contactsData as $phoneData){
                if($phoneData['contactType'] !== $type){
                    continue;
                }
                $innerPhone = $this->b24->b24Users[$phoneData['userId']]??'';
                if ($chooseFirst || in_array($innerPhone, $users, true)) {
                    $this->b24->mainLogger->writeInfo("findContactByPhone: $type id:". $phoneData['b24id']. ', TITLE: '.$phoneData['name']. ', responsible id: '.$phoneData['userId'].', responsible number: '. $innerPhone);
                    $callData['crm-data']    = [
                        'ID' => $phoneData['b24id'],
                        'CRM_ENTITY_TYPE' => $type,
                        'CRM_ENTITY_ID' => $phoneData['b24id']
                    ];
                    $callData['wait']            = false;
                    $callData['responsible']     = $innerPhone;
                    $callData['responsibleName'] = $phoneData['name'];
                    break;
                }
            }
        }

        if($callData['wait'] === true) {
            // Сущность не найдена^
            $userNum = $this->didUsers[$callData['data']['did']][0]??'';
            if(!empty($userNum)){
                // Если для каждого DID описаны уточнения по сотрудникам.
                // Нужно создать новый ЛИД.
                $l_phone = $callData['data']['PHONE_NUMBER']??'';
                $l_id    = $callData['data']['linkedid']??'';
                $l_user  = $this->b24->inner_numbers[$userNum]['ID']??'';
                $l_did   = $callData['data']['did']??'';
                $arg = $this->b24->crmAddLead($l_phone, $l_id, $l_user, $l_did);
                $this->q_req = array_merge($arg, $this->q_req);
            }else{
                // Обычное поведение, доп. лид не создаем.
                $callData['wait'] = false;
            }
        }

        if(!empty($callData['inbox_tube'])){
            $this->queueAgent->publish(json_encode($callData), $callData['inbox_tube']);
            $callData['inbox_tube']='';
        }
    }

    public function postCrmAddLead(string $key, $response): void
    {
        $key = explode('_', $key)[1]??'';
        if (!isset($this->tmpCallsData[$key])) {
            return;
        }
        $this->tmpCallsData[$key]['lead'] = 1;
        $this->tmpCallsData[$key]['wait'] = false;
        $this->tmpCallsData[$key]['crm-data']['CRM_ENTITY_TYPE'] = 'LEAD';
        $this->tmpCallsData[$key]['crm-data']['CRM_ENTITY_ID']   = $response;
    }

    public function postCrmAddContact(string $key, $response): void
    {
        $key = explode('_', $key)[1]??'';
        if (!isset($this->tmpCallsData[$key])) {
            return;
        }
        $this->tmpCallsData[$key]['lead'] = 1;
        $this->tmpCallsData[$key]['wait'] = false;
    }

    private function checkActiveChannels():void
    {
        if(!$this->searchEntities){
            return;
        }
        $am = Util::getAstManager();
        $channels = $am->GetChannels();
        foreach ($this->tmpCallsData as $linkedid => $data){
            if(!isset($channels[$linkedid])){
                if (isset($this->perCallQueues[$linkedid])
                    && !$this->perCallQueues[$linkedid]->isEmpty()) {
                    continue;
                }
                // Используем отложенное удаление, чтобы дождаться finish событий.
                $cleanTime = $this->tmpCallsData[$linkedid]['cleanTime'] ?? 0;
                if ($cleanTime === 0) {
                    $this->tmpCallsData[$linkedid]['cleanTime'] = time();
                    $this->b24->mainLogger->writeInfo("Clearing the event queue wait 120s. $linkedid");
                    continue; // Ставим метку времени первой попытки очистки.
                }
                if ((time() - $cleanTime) < 120) {
                    continue;
                }
                unset($this->tmpCallsData[$linkedid]);
                unset($this->perCallQueues[$linkedid]);
                $this->b24->mainLogger->writeInfo("Clearing the event queue. All channels are completed $linkedid");
            }
        }
    }

    private function parseInnerNumbers(string $data):array
    {
        $result = [];
        preg_match_all('/\[(\d{2,},?)+\]/m', $data, $matches, PREG_SET_ORDER);
        if(!empty($matches)){
            $result = explode(',', str_replace(['[',']'], ['', ''], $matches[0][0]??''));
        }
        return $result;
    }

}

// Start worker process
if(isset($argv) && count($argv) !== 1) {
    WorkerBitrix24IntegrationHTTP::startWorker($argv??[]);
}
