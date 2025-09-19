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
    private bool $need_get_events = false;
    private int $last_update_inner_num = 0;
    private BeanstalkClient $queueAgent;

    private bool  $searchEntities = false;
    private array $tmpCallsData = [];
    private array $didUsers = [];
    private array $perCallQueues = [];

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

                $callId = &$this->tmpCallsData[$data['linkedid']]['CALL_ID'];
                if(empty($callId)){
                    [$arg, $key] = $this->b24->telephonyExternalCallRegister($data);
                    if(!empty($key)){
                        // Это Метод register
                        $callId = $key;
                    }
                }elseif(stripos($callId,Bitrix24Integration::API_CALL_REGISTER) === false){
                    $this->tmpCallsData[$data['linkedid']]['ARG_REGISTER_USER_'.$data['UNIQUEID']] = $data['USER_ID']??'';
                    $this->tmpCallsData[$data['linkedid']]['ARGS_REGISTER_'.$data['UNIQUEID']] = $this->b24->telephonyExternalCallRegister($data);
                    $data['CALL_ID'] = $callId;
                    if($this->needOpenCard($data['USER_ID'])){
                        $arg = $this->b24->telephonyExternalCallShow($data);
                    }
                }else{
                    $this->tmpCallsData[$data['linkedid']]['ARG_REGISTER_USER_'.$data['UNIQUEID']] = $data['USER_ID']??'';
                    $this->tmpCallsData[$data['linkedid']]['ARGS_REGISTER_'.$data['UNIQUEID']] = $this->b24->telephonyExternalCallRegister($data);
                    $data['CALL_ID'] = '$result['.$callId.'][CALL_ID]';
                    if($this->needOpenCard($data['USER_ID'])){
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
                if ($userId !== $row->user_id) {
                    // Открываем карточку клиента тому, кто ответил. (если разрешено).
                    $data['CALL_ID'] = $row->call_id;
                    $data['USER_ID'] = (int)$userId;
                    // Поиск внутреннего номера пользователя b24.
                    if($this->needOpenCard($userId)){
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
            if(!empty($tmpArr)){
                $this->q_req = array_merge($this->q_req, ...$tmpArr);
            }
        } elseif ('telephonyExternalCallFinish' === $data['action']) {
            [$arg,$finishKey] = $this->b24->telephonyExternalCallFinish($data, $this->tmpCallsData);
            $this->q_req = array_merge($this->q_req, $arg);

            $arg = $this->b24->crmActivityUpdate('$result['.$finishKey.'][CRM_ACTIVITY_ID]', $data['linkedid'], $data['linkedid']);
            $this->q_req = array_merge($this->q_req, $arg);
        }else{
            $this->b24->mainLogger->writeInfo($data, "The event handler was not found ($data[linkedid])");
        }

        if (count($this->q_req) >= 49) {
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
     * Проверка, нужно ли открывать карточку пользователю bitrix24
     * @param $userId
     * @return bool
     */
    private function needOpenCard($userId)
    {
        $tmpInnerNumArray = array_values($this->b24->inner_numbers);
        // Поиск внутреннего номера пользователя b24.
        $innerNumber      = $tmpInnerNumArray[array_search($userId, array_column($tmpInnerNumArray, 'ID'),true)]['UF_PHONE_INNER']??'';
        return $this->b24->usersSettingsB24[$innerNumber]['open_card_mode']??'' === Bitrix24Integration::OPEN_CARD_ANSWERED;
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
                $arIds = array_values($eventData['FIELDS']);
                $args[] = $this->b24->crmListEnt($eventActionsUpdate[$event['EVENT_NAME']], $arIds);
                foreach ($arIds as $id){
                    if($event['EVENT_NAME'] === 'ONCRMCONTACTUPDATE'){
                        $args[] = $this->b24->getContactCompany($id);
                    }elseif($event['EVENT_NAME'] === 'ONCRMCOMPANYUPDATE'){
                        $args[] = $this->b24->getCompanyContacts($id);
                    }
                }
            }
            $this->b24->handleEvent([ 'event' => $event, 'data'  => $eventData]);
        }
        if(!empty($args)){
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

        $syncProcReqContact = [];
        $syncProcReqCompany = [];
        foreach ($result as $key => $partResponse) {
            [$actionName, $id] = explode('_', $key);
            if (in_array($actionName, [ Bitrix24Integration::API_CRM_LIST_CONTACT,Bitrix24Integration::API_CRM_LIST_COMPANY, Bitrix24Integration::API_CRM_LIST_LEAD], true )) {
                $this->b24->crmListEntResults($actionName, $id, $partResponse, false);
                foreach ($partResponse as $data){
                    if(Bitrix24Integration::API_CRM_LIST_COMPANY === $actionName){
                        $arg = $this->b24->getCompanyContacts($data['ID']);
                        $syncProcReqCompany = array_merge($syncProcReqCompany, $arg);
                    }elseif (Bitrix24Integration::API_CRM_LIST_CONTACT === $actionName){
                        $arg = $this->b24->getContactCompany($data['ID']);
                        $syncProcReqContact = array_merge($syncProcReqContact, $arg);
                    }
                }
            }
        }
        $response = $this->b24->sendBatch($syncProcReqCompany);
        if(!empty($response)){
            ConnectorDb::invoke(ConnectorDb::FUNC_UPDATE_LINKS, [$response['result']['result']??[]], false);
        }
        $response = $this->b24->sendBatch($syncProcReqContact);
        if(!empty($response)) {
            ConnectorDb::invoke(ConnectorDb::FUNC_UPDATE_LINKS, [$response['result']['result']??[]], false);
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
            $this->checkActiveChannels();
            $this->syncProcContacts();
        }

        // Получать новые события будем каждое 2ое обращение к этой функции ~ 1.2 секунды.
        $this->need_get_events = !$this->need_get_events;

        // Обработка очередей: по одному событию с головы для каждой очереди
        foreach ($this->perCallQueues as $queue) {
            if ($queue->isEmpty()) {
                continue;
            }
            $event = $queue->bottom();
            if ($this->shouldDeferForPreAction($event)) {
                continue;
            }
            $queue->dequeue();
            $this->addDataToQueue($event);
        }

        if ($this->need_get_events) {
            // Запрос на получение offline событий.
            $arg = $this->b24->eventOfflineGet();
            $this->q_req = array_merge($this->q_req, $arg);
        }
        if (count($this->q_req) > 0) {
            $chunks = $this->chunkAssociativeArray($this->q_req);
            $finalResult = [];
            foreach ($chunks as $chunk) {
                $response = $this->b24->sendBatch($chunk);
                $finalResult[] = $response['result']['result'] ?? [];
            }
            $result = array_merge(...$finalResult);
            // Чистим очередь запросов.
            $this->q_req = [];
            $this->postReceivingResponseProcessing($result);
            $this->handleEvent($result);
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
            if($data['wait'] === false && !isset($channels[$linkedid])){
                if (isset($this->perCallQueues[$linkedid])
                    && !$this->perCallQueues[$linkedid]->isEmpty()) {
                    continue; // Есть события, не чистим.
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
