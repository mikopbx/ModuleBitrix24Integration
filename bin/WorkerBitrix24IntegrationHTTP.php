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
use MikoPBX\Core\System\Util;
use MikoPBX\Core\Workers\WorkerBase;
use Modules\ModuleBitrix24Integration\Lib\Bitrix24Integration;
use Modules\ModuleBitrix24Integration\Models\ModuleBitrix24CDR;

class WorkerBitrix24IntegrationHTTP extends WorkerBase
{
    private Bitrix24Integration $b24;
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
        $this->b24->checkNeedUpdateToken();
        // При старте синхронизируем внешние линии.
        $externalLines = $this->b24->syncExternalLines();
        foreach ($externalLines as $line){
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
        $this->queueAgent->setTimeoutHandler([$this, 'executeTasks']);
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
        /** @var array $data */
        $data = json_decode($client->getBody(), true);
        if ($this->checkPreAction($data)) {
            // Получение сведений об организации по номеру телефона
            // Формирование лида.
            // Предварительные действия перед обработкой звонков.
            // Отрабатывает только если заполенн "$didUsers"
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
                    $data['PHONE_NUMBER'] = $cache_data['PHONE_NUMBER'] ?? $data['PHONE_NUMBER'];
                    $data['CRM_ENTITY_ID'] = $cache_data['CRM_ENTITY_ID'] ?? '';
                    $data['CRM_ENTITY_TYPE'] = $cache_data['CRM_ENTITY_TYPE'] ?? '';
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
        if ($this->searchEntities) {
            if (!isset($this->tmpCallsData[$data['linkedid']]) && $data['action'] === 'telephonyExternalCallRegister') {
                $this->createTmpCallData($data);
            }
            if ($data['action'] === 'telephonyExternalCallRegister'
                && ($this->tmpCallsData[$data['linkedid']]['data']['action']??'') !== 'telephonyExternalCallRegister'){
                $this->tmpCallsData[$data['linkedid']]['data'] = $data;
                $data['CRM_ENTITY_TYPE'] = $this->tmpCallsData[$data['linkedid']]['crm-data']['CRM_ENTITY_TYPE'];
                $data['CRM_ENTITY_ID']   = $this->tmpCallsData[$data['linkedid']]['crm-data']['CRM_ENTITY_ID'];
            }

            $wait = $this->tmpCallsData[$data['linkedid']]['wait']?? false;
            if ($wait === false) {
                // Не требуется предварительная обработка. Выполнить сразу.
                return false;
            }
            $this->tmpCallsData[$data['linkedid']]['events'][] = $data;
        } else {
            $needActions = false;
        }
        return $needActions;
    }

    private function createTmpCallData($data){

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
            $arg = $this->b24->searchCrmEntities($phone, $data['linkedid']);
            $this->q_req = array_merge($arg, $this->q_req);
            $arg = $this->b24->crmLeadListByPhone($phone, $data['linkedid']);
            $this->q_req = array_merge($arg, $this->q_req);
            $arg = $this->b24->crmCompanyListByPhone($phone, $data['linkedid']);
            $this->q_req = array_merge($arg, $this->q_req);
            $this->tmpCallsData[$data['linkedid']]['search'] = 0;
        }
    }

    /**
     *
     */
    public function executeTasks(): void
    {
        $delta = time() - $this->last_update_inner_num;
        if ($delta > 10) {
            // Обновляем список внутренних номеров. Обновляем кэш внутренних номеров.
            $this->b24->b24GetPhones();
            $this->b24->updateSettings();
            $this->last_update_inner_num = time();
            // Очистка $this->tmpCallsData
            $this->checkActiveChannels();
        }

        // Получать новые события будем каждое 2ое обращение к этой функции ~ 1.2 секунды.
        $this->need_get_events = !$this->need_get_events;

        if ($this->need_get_events) {
            // Запрос на полуение offline событий.
            $arg = $this->b24->eventOfflineGet();
            $this->q_req = array_merge($arg, $this->q_req);
        }
        if (count($this->q_req) > 0) {
            $response = $this->b24->sendBatch($this->q_req);
            $result = $response['result']['result'] ?? [];
            // Чистим очередь запросов.
            $this->q_req = [];
            $this->postReceivingResponseProcessing($result);
            $events = $response['result']['result']['event.offline.get']['events'] ?? [];
            foreach ($events as $event) {
                $delta = time() - strtotime($event['TIMESTAMP_X']);
                if ($delta > 15) {
                    $this->b24->logger->writeInfo(
                        "An outdated response was received {$delta}s: " . json_encode($event)
                    );
                    continue;
                }
                $req_data = [
                    'event' => $event['EVENT_NAME'],
                    'data' => $event['EVENT_DATA'],
                ];
                $this->b24->handleEvent($req_data);
            }
        }

        if (count($this->q_pre_req) > 0) {
            $tmpArr = [$this->q_req];
            foreach ($this->q_pre_req as $data) {
                if ('action_hangup_chan' === $data['action']) {
                    /** @var ModuleBitrix24CDR $cdr */
                    $cdr = ModuleBitrix24CDR::findFirst("uniq_id='{$data['UNIQUEID']}'");
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
                    $b24CdrRows = ModuleBitrix24CDR::find($filter);
                    foreach ($b24CdrRows as $row) {
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
                            $cdr->save();
                        }
                        if ($cdr->answer !== 1) {
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
                        $tmpArr[] = $this->b24->crmLeadUpdate($dealId, $userId);
                    }
                    if (!empty($dealId) && !empty($userId)) {
                        $tmpArr[] = $this->b24->crmDealUpdate($dealId, $userId);
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
            $actionName = explode('_', $key)[0] ?? '';
            if ($actionName === Bitrix24Integration::API_CALL_REGISTER) {
                $this->b24->telephonyExternalCallPostRegister($key, $partResponse);
            } elseif ($actionName === Bitrix24Integration::API_CRM_LIST_LEAD) {
                $tmpArr[] = $this->postCrmLeadListByPhone($key, $partResponse);
            } elseif ($actionName === Bitrix24Integration::API_CRM_LIST_COMPANY) {
                $tmpArr[] = $this->postCrmCompanyListByPhone($key, $partResponse);
            } elseif ($actionName === Bitrix24Integration::API_SEARCH_ENTITIES) {
                $tmpArr[] = $this->postSearchCrmEntities($key, $partResponse);
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

    public function postCrmLeadListByPhone(string $key, array $response): array
    {
        $tmpArr = [];
        $key = explode('_', $key)[1]??'';
        if (isset($this->tmpCallsData[$key])) {
            $this->tmpCallsData[$key]['list-lead'] = 1;
            foreach ($response as &$row){
                $row['CRM_ENTITY_TYPE'] = 'LEAD';
                $row['CRM_ENTITY_ID']   = $row['ID'];
                foreach ($this->b24->inner_numbers as $inner){
                    if($row['ASSIGNED_BY_ID'] === $inner['ID']){
                        $row['USER_PHONE_INNER'] = $inner['UF_PHONE_INNER'];
                        break;
                    }
                }
            }
            unset($row);
            $this->tmpCallsData[$key]['leads'] = $response;
            if($this->tmpCallsData[$key]['search']  === 1 && $this->tmpCallsData[$key]['company'] === 1){
                $tmpArr = $this->postEndSearchCrm($key);
            }
        }
        return $tmpArr;
    }
    public function postCrmCompanyListByPhone(string $key, array $response): array
    {
        $tmpArr = [];
        $key = explode('_', $key)[1]??'';
        if (isset($this->tmpCallsData[$key])) {
            $this->tmpCallsData[$key]['company'] = 1;
            foreach ($response as &$row){
                $row['CRM_ENTITY_TYPE'] = 'COMPANY';
                $row['CRM_ENTITY_ID']   = $row['ID'];
                foreach ($this->b24->inner_numbers as $inner){
                    if($row['ASSIGNED_BY_ID'] === $inner['ID']){
                        $row['USER_PHONE_INNER'] = $inner['UF_PHONE_INNER'];
                        break;
                    }
                }
            }
            unset($row);
            $this->tmpCallsData[$key]['company-list'] = $response;
            if($this->tmpCallsData[$key]['search']  === 1 && $this->tmpCallsData[$key]['list-lead']  === 1){
                $tmpArr = $this->postEndSearchCrm($key);
            }
        }
        return $tmpArr;
    }

    /**
     *
     * @param string $key
     * @param array  $response
     *
     */
    public function postSearchCrmEntities(string $key, array $response): array
    {
        $tmpArr = [];
        $key = explode('_', $key)[1]??'';
        if (isset($this->tmpCallsData[$key])) {
            $this->tmpCallsData[$key]['search'] = 1;
            $this->tmpCallsData[$key]['entities'] = $response;
            if($this->tmpCallsData[$key]['list-lead'] === 1 && $this->tmpCallsData[$key]['company'] = 1){
                $tmpArr = $this->postEndSearchCrm($key);
            }
        }
        return $tmpArr;
    }

    private function postEndSearchCrm($key):array
    {
        $tmpArr = [];
        $entities = $this->tmpCallsData[$key]['entities'];
        $leads    = $this->tmpCallsData[$key]['leads'];
        $company  = $this->tmpCallsData[$key]['company-list'];

        $this->b24->logger->writeInfo("findContactByPhone: company: ".count($company).', leads: '.count($leads). ', entities: '.count($entities));
        if (empty($entities) && empty($leads)) {
            // Это новый лид. Пользователь сам сопоставит его с компанией.
            $this->tmpCallsData[$key]['wait'] = false;
            foreach ($this->tmpCallsData[$key]['events'] as $event){
                $this->addDataToQueue($event);
            }
        } else {
            $chooseFirst = !isset($this->didUsers[$this->tmpCallsData[$key]['data']['did']]);
            $users = $this->didUsers[$this->tmpCallsData[$key]['data']['did']];
            // Ищем среди компаний.
            foreach ($company as $entity) {
                if ($chooseFirst || in_array($entity['USER_PHONE_INNER'], $users, true)) {
                    $this->b24->logger->writeInfo("findContactByPhone: company id:". $entity['ID']. ', TITLE: '.$entity['TITLE']. ', responsible: '. $entity['USER_PHONE_INNER']);
                    $this->tmpCallsData[$key]['crm-data'] = $entity;
                    $this->tmpCallsData[$key]['wait'] = false;
                    $this->tmpCallsData[$key]['responsible'] = $entity['ASSIGNED_BY']['USER_PHONE_INNER'];
                    $this->addEventsToMainQueue($key, $entity['CRM_ENTITY_TYPE'], $entity['CRM_ENTITY_ID']);
                    break;
                }
            }
            if (empty($this->tmpCallsData[$key]['crm-data'])) {
                // Сперва ищем среди лидов.
                // Пользователи, закрепленные за DID. (Невасофт доработки)
                foreach ($leads as $lead){
                    if ($chooseFirst || in_array($lead['USER_PHONE_INNER'], $users, true)) {
                        $this->b24->logger->writeInfo("findContactByPhone: lead id:". $lead['ID']. ', TITLE: '.$lead['TITLE']. ', responsible: '. $lead['USER_PHONE_INNER']);
                        $this->tmpCallsData[$key]['crm-data'] = $lead;
                        $this->tmpCallsData[$key]['wait'] = false;
                        $this->tmpCallsData[$key]['responsible'] = $lead['USER_PHONE_INNER'];
                        $this->addEventsToMainQueue($key, 'LEAD', $lead['ID']);
                        break;
                    }
                }
            }
            if (empty($this->tmpCallsData[$key]['crm-data'])) {
                // Если не нашли среди лидов, ищем среди контактов / компаний.
                foreach ($entities as $entity) {
                    if ($chooseFirst || in_array($entity['ASSIGNED_BY']['USER_PHONE_INNER'], $users, true)) {
                        $this->b24->logger->writeInfo("findContactByPhone: ".$entity['CRM_ENTITY_TYPE']." id:". $entity['CRM_ENTITY_ID']. ', TITLE: '.$entity['NAME']. ', responsible: '. $entity['ASSIGNED_BY']['USER_PHONE_INNER']);
                        $this->tmpCallsData[$key]['crm-data'] = $entity;
                        $this->tmpCallsData[$key]['wait'] = false;
                        $this->tmpCallsData[$key]['responsible'] = $entity['ASSIGNED_BY']['USER_PHONE_INNER'];
                        $this->addEventsToMainQueue($key, $entity['CRM_ENTITY_TYPE'], $entity['CRM_ENTITY_ID']);
                        break;
                    }
                }
            }
            if (empty($this->tmpCallsData[$key]['crm-data'])) {
                $userNum = $this->didUsers[$this->tmpCallsData[$key]['data']['did']][0]??'';
                $arg = $this->b24->crmAddLead(
                    $this->tmpCallsData[$key]['data']['PHONE_NUMBER'] ?? '',
                    $this->tmpCallsData[$key]['data']['linkedid'],
                    $this->b24->inner_numbers[$userNum]['ID']??''
                );
                $tmpArr = array_merge($arg, $this->q_req);
            }
        }

        if(!empty($this->tmpCallsData[$key]['inbox_tube'])){
            $this->queueAgent->publish(json_encode($this->tmpCallsData[$key]), $this->tmpCallsData[$key]['inbox_tube']);
            $this->tmpCallsData[$key]['inbox_tube']='';
        }

        return $tmpArr;
    }

    public function postCrmAddLead(string $key, $response): void
    {
        $key = explode('_', $key)[1]??'';
        if (!isset($this->tmpCallsData[$key])) {
            return;
        }
        $this->tmpCallsData[$key]['lead'] = 1;
        $this->tmpCallsData[$key]['wait'] = false;
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

    private function addEventsToMainQueue($linkedID, $eType = '', $eID = ''){
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
