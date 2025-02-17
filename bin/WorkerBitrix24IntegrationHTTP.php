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
    private array $q_pre_req = [];
    private array $q_pre_req2 = [];
    private bool $need_get_events = false;
    private int $last_update_inner_num = 0;
    private BeanstalkClient $queueAgent;

    private bool  $searchEntities = false;
    private array $tmpCallsData = [];
    private array $didUsers = [];

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
        cli_set_process_title('SHUTDOWN_'.cli_get_process_title());
    }

    /**
     * Начало работы демона.
     *
     * @param $argv
     */
    public function start($argv): void
    {
        $this->b24 = new Bitrix24Integration();
        if (!$this->b24->initialized) {
            die('Settings not set...');
        }
        $this->b24->logger->writeInfo('Starting...');
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
        /** Основной цикл демона. */
        $this->initBeanstalk();
        while ($this->needRestart === false) {
            try {
                $this->queueAgent->wait(1);
            } catch (Exception $e) {
                sleep(1);
                $this->initBeanstalk();
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
        $this->b24->logger->writeInfo('Get ping event...');
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
        }
        if(!empty($arg)){
            $this->q_req = array_merge($arg, $this->q_req);
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
        if(!empty($tube)){
            $resFile = ConnectorDb::saveResultInTmpFile($partResponse);
            $this->queueAgent->publish($resFile, $tube);
        }
    }

    public function b24ChannelSearch($client): void
    {
        $data = json_decode($client->getBody(), true);
        $phone = $data['PHONE_NUMBER']??'';
        if(!empty($phone)){
            // Добавляем запрос в очередь
            $this->createTmpCallData($data);
        }
    }

    /**
     * @param BeanstalkClient $client
     */
    public function b24ChannelCallBack($client): void
    {
        $srcData = $client->getBody();
        $this->b24->logger->writeInfo('Get AMI Event'. $srcData);
        try {
            /** @var array $data */
            $data = json_decode($srcData, true, 512, JSON_THROW_ON_ERROR);
        }catch (Exception $e){
            $this->b24->logger->writeInfo('AMI Event'. $e->getMessage());
            return;
        }

        $id = $data['linkedid']??'';
        if ($this->checkPreAction($data)) {
            // Получение сведений об организации по номеру телефона
            // Формирование лида.
            // Предварительные действия перед обработкой звонков.
            // Отрабатывает только если заполенн "$didUsers"
            $this->b24->logger->writeInfo('Ignore event...'.$id);
            return;
        }
        $this->addDataToQueue($data);
    }

    private function addDataToQueue(array $data):void
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
                $arg = $this->b24->telephonyExternalCallRegister($data);
                if (count($arg) > 0) {
                    // Основная очередь запросов.
                    $this->q_req = array_merge($arg, $this->q_req);
                }
            }
        } else {
            // Дополнительная очередь ожидания.
            // Будет обработана после поулчения результата telephonyExternalCallRegister.
            $this->q_pre_req[uniqid('', true)] = $data;
        }
        if (count($this->q_req) >= 49) {
            $this->executeTasks();
        }
    }

    public function checkPreAction(&$data): bool
    {
        $needActions = true;
        $id = $data['linkedid']??'';
        if ($this->searchEntities) {
            if (!isset($this->tmpCallsData[$data['linkedid']]) && $data['action'] === 'telephonyExternalCallRegister') {
                $this->createTmpCallData($data);
            }
            $callData = &$this->tmpCallsData[$data['linkedid']];
            if ($data['action'] === 'telephonyExternalCallRegister'
                && ($callData['data']['action']??'') !== 'telephonyExternalCallRegister'){
                $callData['data'] = $data;
                $data['CRM_ENTITY_TYPE'] = $callData['crm-data']['CRM_ENTITY_TYPE'];
                $data['CRM_ENTITY_ID']   = $callData['crm-data']['CRM_ENTITY_ID'];
            }
            $wait = $callData['wait']?? false;
            if ($wait === false) {
                $this->b24->logger->writeInfo('checkPreAction wait === false...'.$id);
                // Не требуется предварительная обработка. Выполнить сразу.
                return false;
            }
            $callData['events'][] = $data;
        } else {
            $this->b24->logger->writeInfo('checkPreAction needActions = false...'.$id);
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
        $this->tmpCallsData[$data['linkedid']] = [
            'wait'      => true,
            'events'    => [],
            'search'    => -1, // -1 - запрос не отправлен, 0 - запрос отправлен, 1 ответ получен
            'lead'      => -1,
            'list-lead' => -1,
            'company'   => -1,
            'data'      => $data,
            'crm-data'  => [],
            'inbox_tube'=> $data['inbox_tube']??'',
            'responsible'=> ''
        ];
        $phone = $data['PHONE_NUMBER'] ?? '';
        if(empty($phone)){
            $this->b24->logger->writeError('Empty phone number... '. json_encode($data, JSON_UNESCAPED_UNICODE));
        }elseif ($this->tmpCallsData[$data['linkedid']]['search'] === -1 ) {
            $this->tmpCallsData[$data['linkedid']]['search'] = 1;
            $this->findEntitiesByPhone($phone, $data['linkedid']);
        }
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
        $args = [];
        foreach ($events as $event) {
            $eventData = $event['EVENT_DATA'];
            if (isset($eventActionsDelete[$event['EVENT_NAME']])) {
                $id = array_values($eventData['FIELDS']);
                ConnectorDb::invoke(ConnectorDb::FUNC_DELETE_CONTACT_DATA, [$eventActionsDelete[$event['EVENT_NAME']], $id],false);
            }
            if (isset($eventActionsUpdate[$event['EVENT_NAME']])){
                $id = array_values($eventData['FIELDS']);
                $args[] = $this->b24->crmListEnt($eventActionsUpdate[$event['EVENT_NAME']], $id);
            }
            $this->b24->handleEvent([ 'event' => $event, 'data'  => $eventData]);
        }
        if(!empty($args)){
            $this->q_req = array_merge(array_merge(...$args), $this->q_req);
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
        if(!empty($this->pidSyncProcContacts) && file_exists("/proc/$this->pidSyncProcContacts")){
            if(time() - $this->timeSyncProcContacts > 40){
                posix_kill($this->pidSyncProcContacts, SIGKILL);
                pcntl_waitpid($this->pidSyncProcContacts, $status);
            }
            return;
        }
        $this->timeSyncProcContacts = time();
        $this->pidSyncProcContacts = pcntl_fork();
        if ($this->pidSyncProcContacts == -1) {
            $this->b24->logger->writeError('Fail fork sync contacts... ');
            return;
        } elseif ($this->pidSyncProcContacts) {
            $this->b24->logger->writeInfo('Start sync contacts... '.$this->pidSyncProcContacts);
            usleep(100000);
            return;
        }
        $this->needRestart = true;
        set_time_limit(50);
        cli_set_process_title(cli_get_process_title()."_SYNC_CONTACTS");
        $syncProcReq = [];
        $arg = $this->b24->crmListEnt(Bitrix24Integration::API_CRM_LIST_CONTACT);
        $syncProcReq = array_merge($arg, $syncProcReq);

        $arg = $this->b24->crmListEnt(Bitrix24Integration::API_CRM_LIST_COMPANY);
        $syncProcReq = array_merge($arg, $syncProcReq);

        $arg = $this->b24->crmListEnt(Bitrix24Integration::API_CRM_LIST_LEAD);
        $syncProcReq = array_merge($arg, $syncProcReq);

        $response = $this->b24->sendBatch($syncProcReq);
        $result = $response['result']['result'] ?? [];
        foreach ($result as $key => $partResponse) {
            [$actionName, $id] = explode('_', $key);
            if (in_array($actionName,
                         [
                             Bitrix24Integration::API_CRM_LIST_CONTACT,
                             Bitrix24Integration::API_CRM_LIST_COMPANY,
                             Bitrix24Integration::API_CRM_LIST_LEAD
                         ],
                         true
            )) {
                $this->b24->crmListEntResults($actionName, $id, $partResponse);
            }
        }
        // Это дочерний процесс, завершаем его.
        exit(0);
    }

    /**
     *
     */
    public function executeTasks(): void
    {
        $this->b24->mainLogger->rotate();

        $delta = time() - $this->last_update_inner_num;
        if ($delta > 10) {
            // Обновляем список внутренних номеров. Обновляем кэш внутренних номеров.
            $this->b24->b24GetPhones();
            $this->b24->updateSettings();
            $this->last_update_inner_num = time();
            // Очистка $this->tmpCallsData
            $this->checkActiveChannels();
            $this->syncProcContacts();
        }

        // Получать новые события будем каждое 2ое обращение к этой функции ~ 1.2 секунды.
        $this->need_get_events = !$this->need_get_events;

        if ($this->need_get_events) {
            // Запрос на получение offline событий.
            $arg = $this->b24->eventOfflineGet();
            $this->q_req = array_merge($arg, $this->q_req);
        }
        if (count($this->q_req) > 0) {
            $chunks = $this->chunkAssociativeArray($this->q_req);
            $finalResult = [];
            $errors = [];
            foreach ($chunks as $chunk) {
                $response = $this->b24->sendBatch($chunk);
                $finalResult[] = $response['result']['result'] ?? [];
                $errors[]      = $response['result']['result_error']??[];
            }
            $result = array_merge(...$finalResult);
            // Чистим очередь запросов.
            $this->q_req = [];
            $this->postReceivingResponseProcessing($result);
            $this->handleEvent($result);

            CacheManager::setCacheData('module_state', array_merge(...$errors), 60);
        }

        if (count($this->q_pre_req) > 0) {
            $tmpArr = [$this->q_req];
            foreach ($this->q_pre_req as $data) {
                if ('action_hangup_chan' === $data['action']) {
                    $cdr = ConnectorDb::invoke(ConnectorDb::FUNC_FIND_CDR_BY_UID,[$data['UNIQUEID']]);
                    if ($cdr) {
                        $data['CALL_ID'] = $cdr->call_id;
                        $data['USER_ID'] = (int)$cdr->user_id;
                        $tmpArr[] = $this->b24->telephonyExternalCallHide($data);
                    }
                } elseif ('action_dial_answer' === $data['action']) {
                    $cdr = null;
                    $userId = '';
                    $dealId = '';
                    $leadId = '';
                    $filter = [
                        "linkedid='{$data['linkedid']}'",
                        'order' => 'uniq_id'
                    ];
                    $b24CdrRows = ConnectorDb::invoke(ConnectorDb::FUNC_GET_CDR_BY_FILTER, [$filter]);
                    foreach ($b24CdrRows as $cdrData) {
                        $row = (object)$cdrData;
                        if ($row->uniq_id === $data['UNIQUEID']) {
                            $cdr = $row;
                            if (!empty($cdr->dealId)) {
                                $dealId = max($dealId, $cdr->dealId);
                            }
                            if (!empty($cdr->lead_id)) {
                                $leadId = max($leadId, $cdr->lead_id);
                            }
                            // Определяем, кто ответил на вызов.
                            $userId = $cdr->user_id;
                            // Отмечаем вызов как отвеченный.
                            $cdr->answer = 1;
                            ConnectorDb::invoke(ConnectorDb::FUNC_UPDATE_FROM_ARRAY_CDR_BY_UID, [$row->uniq_id, (array)$cdr]);
                        }
                        if ($row->answer !== 1) {
                            if (!empty($row->dealId)) {
                                $dealId = max($dealId, $row->dealId);
                            }
                            if (!empty($row->lead_id)) {
                                $leadId = max($leadId, $row->lead_id);
                            }
                            if ($cdr && $row->user_id === $cdr->user_id) {
                                continue;
                            }
                            // Для всех CDR, где вызов НЕ отвечен определяем сотрудника и закрываем карточку звонка.
                            $data['CALL_ID'] = $row->call_id;
                            $data['USER_ID'] = (int)$row->user_id;
                            $tmpArr[] = $this->b24->telephonyExternalCallHide($data);
                        }
                        if ($row->answer === 1) {
                            // Меняем ответственного, на последнего, кто ответил.
                            $userId = $row->user_id;

                            // Открываем карточку клиента тому, кто ответил. (если разрешено).
                            $data['CALL_ID'] = $row->call_id;
                            $data['USER_ID'] = (int)$row->user_id;

                            $tmpInnerNumArray = array_values($this->b24->inner_numbers);
                            // Поиск внутреннего номера пользователя b24.
                            $innerNumber      = $tmpInnerNumArray[array_search($userId, array_column($tmpInnerNumArray, 'ID'),true)]['UF_PHONE_INNER']??'';
                            $cardOpenSetting  = $this->b24->usersSettingsB24[$innerNumber]['open_card_mode']??'';
                            if($cardOpenSetting === Bitrix24Integration::OPEN_CARD_ANSWERED){
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
                        $tmpArr[] = $this->b24->crmLeadUpdate($this->tmpCallsData[$data['linkedid']]['crm-data']['CRM_ENTITY_ID'], $userId, $data['linkedid']);
                    }

                    if (!empty($dealId) && !empty($userId)) {
                        $tmpArr[] = $this->b24->crmDealUpdate($dealId, $userId, $data['linkedid']);
                    }
                } elseif ('telephonyExternalCallFinish' === $data['action']) {
                    $tmpArr[] = $this->b24->telephonyExternalCallFinish($data);
                }
            }
            $this->q_req = array_merge(...$tmpArr);
            unset($tmpArr);
            // Обработали все пердварительные данные.
            $this->q_pre_req = [];
        }
        foreach ($this->q_pre_req2 as $event){
            $this->addDataToQueue($event);
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
            [$actionName, $id, $tube] = explode('_', $key);
            if(!empty($tube)){
                $this->invokeRestCheckResponse($key, $tube, $partResponse);
                continue;
            }
            if ($actionName === Bitrix24Integration::API_CALL_REGISTER) {
                $this->b24->telephonyExternalCallPostRegister($key, $partResponse);
            } elseif (in_array($id,['init', 'update'], true)){
                $this->b24->crmListEntResults($actionName, $id, $partResponse);
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
        $this->q_req = array_merge($tmpArr, $this->q_req);
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
        $users       = $this->didUsers[$did];

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
                    $this->b24->logger->writeInfo("findContactByPhone: $type id:". $phoneData['b24id']. ', TITLE: '.$phoneData['name']. ', responsible id: '.$phoneData['userId'].', responsible number: '. $innerPhone);
                    $callData['crm-data']    = [
                        'ID' => $phoneData['b24id'],
                        'CRM_ENTITY_TYPE' => $type,
                        'CRM_ENTITY_ID' => $phoneData['b24id']
                    ];
                    $callData['wait']            = false;
                    $callData['responsible']     = $innerPhone;
                    $callData['responsibleName'] = $phoneData['name'];
                    $this->addEventsToMainQueue($linkedId, 'LEAD', $phoneData['b24id']);
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

        if($callData['wait'] === false){
            foreach ($callData['events'] as $event){
                $this->addDataToQueue($event);
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
        $this->addEventsToMainQueue($key, 'LEAD', $response);
    }

    public function postCrmAddContact(string $key, $response): void
    {
        $key = explode('_', $key)[1]??'';
        if (!isset($this->tmpCallsData[$key])) {
            return;
        }
        $this->tmpCallsData[$key]['lead'] = 1;
        $this->tmpCallsData[$key]['wait'] = false;
        $this->addEventsToMainQueue($key, 'CONTACT', $response);
    }

    private function addEventsToMainQueue($linkedID, $eType = '', $eID = ''):void
    {
        foreach ($this->tmpCallsData[$linkedID]['events'] as $index => $event){
            if($event['action'] === 'telephonyExternalCallRegister'){
                $event['CRM_ENTITY_TYPE'] = $eType;
                $event['CRM_ENTITY_ID']   = $eID;
                $this->addDataToQueue($event);
                unset($this->tmpCallsData[$linkedID]['events'][$index]);
            }
        }
        foreach ($this->tmpCallsData[$linkedID]['events'] as $event){
            $this->q_pre_req2[] = $event;
        }
    }

    private function checkActiveChannels():void
    {
        if(!$this->searchEntities){
            return;
        }
        $am = Util::getAstManager();
        $channels = $am->GetChannels();
        foreach ($this->tmpCallsData as $linkedid => $data){
            if($data['wait'] === false && !isset($channels[$linkedid])){
                unset($this->tmpCallsData[$linkedid]);
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
