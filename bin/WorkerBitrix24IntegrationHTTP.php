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
use MikoPBX\Core\Workers\WorkerBase;
use Modules\ModuleBitrix24Integration\Lib\Bitrix24Integration;
use Modules\ModuleBitrix24Integration\Models\ModuleBitrix24CDR;

class WorkerBitrix24IntegrationHTTP extends WorkerBase
{
    private Bitrix24Integration $b24;
    private array $q_req      = [];
    private array $q_pre_req  = [];
    private bool $need_get_events = false;
    private int $last_update_inner_num = 0;
    private array $channelSearchCashe = [];
    private BeanstalkClient $queueAgent;

    /**
     * Начало работы демона.
     *
     * @param $argv
     */
    public function start($argv):void
    {
        $this->b24       = new Bitrix24Integration();
        if (!$this->b24->initialized) {
            die('Settings not set...');
        }
        $this->b24->checkNeedUpdateToken();
        // При старте синхронизируем внешние линии.
        $this->b24->syncExternalLines();

        /** Основной цикл демона. */
        $this->initBeanstalk();
        while (true) {
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
    private function initBeanstalk():void{
        $this->queueAgent = new BeanstalkClient(Bitrix24Integration::B24_INTEGRATION_CHANNEL);
        $this->queueAgent->subscribe($this->makePingTubeName(self::class), [$this, 'pingCallBack']);
        $this->queueAgent->subscribe(Bitrix24Integration::B24_INTEGRATION_CHANNEL,  [$this, 'b24ChannelCallBack']);
        $this->queueAgent->subscribe(Bitrix24Integration::B24_SEARCH_CHANNEL,       [$this, 'b24ChannelSearch']);
        $this->queueAgent->setTimeoutHandler([$this, 'executeTasks']);
    }

    public function b24ChannelSearch($client):void
    {
        $data = json_decode($client->getBody(), true);
        // Добавляем запрос в очередь
        $arg    = $this->b24->searchCrmEntities($data['phone']??'');
        $this->q_req = array_merge($arg, $this->q_req);

        // Сохраняем ID, куда вернуть ответ.
        $taskId = array_keys($arg)[0]??'';
        $this->channelSearchCashe[$taskId] = $data['inbox_tube']??'';
    }
    /**
     * @param BeanstalkClient $client
     */
    public function b24ChannelCallBack($client): void
    {
        /** @var array $data */
        $data = json_decode($client->getBody(), true);

        if ('telephonyExternalCallRegister' === $data['action']) {
            $cache_key    = 'tmp10'.__FUNCTION__.$data['UNIQUEID'];
            $res_data     = $this->b24->getCache($cache_key);
            if ($res_data === null) {
                $this->b24->saveCache($cache_key, $data);

                $pre_call_key = "tmp5_{$data['USER_PHONE_INNER']}_".$this->b24->getPhoneIndex($data['PHONE_NUMBER']);
                $cache_data   = $this->b24->getCache($pre_call_key);
                if($cache_data !== null){
                    $data['PHONE_NUMBER'] = $cache_data['PHONE_NUMBER']??$data['PHONE_NUMBER'];
                }
                $pre_call_key = "tmp5_ONEXTERNALCALLBACKSTART_".$this->b24->getPhoneIndex($data['PHONE_NUMBER']);
                $cache_data   = $this->b24->getCache($pre_call_key);
                if($cache_data !== null){
                    $data['PHONE_NUMBER']    = $cache_data['PHONE_NUMBER']??$data['PHONE_NUMBER'];
                    $data['CRM_ENTITY_ID']   = $cache_data['CRM_ENTITY_ID']??'';
                    $data['CRM_ENTITY_TYPE'] = $cache_data['CRM_ENTITY_TYPE']??'';
                }
                $arg = $this->b24->telephonyExternalCallRegister($data);
                if(count($arg)>0){
                    // Основная очередь запросов.
                    $this->q_req = array_merge($arg, $this->q_req);
                }
            }
        }else{
            // Дополнительная очередь ожидания.
            // Будет обработана после поулчения результата telephonyExternalCallRegister.
            $this->q_pre_req[uniqid('', true)] = $data;
        }
        if(count($this->q_req) >= 49){
            $this->executeTasks();
        }
    }

    /**
     *
     */
    public function executeTasks():void
    {
        $delta = time() - $this->last_update_inner_num;
        if($delta > 10){
            // Обновляем список внутренних номеров. Обновляем кэш внутренних номеров.
            $this->b24->b24GetPhones();
            $this->b24->updateSettings();
            $this->last_update_inner_num = time();
        }

        // Получать новые события будем каждое 2ое обращение к этой функции ~ 1.2 секунды.
        $this->need_get_events = !$this->need_get_events;

        if($this->need_get_events){
            // Запрос на полуение offline событий.
            $arg         = $this->b24->eventOfflineGet();
            $this->q_req = array_merge($arg, $this->q_req);
        }
        if(count($this->q_req)>0){
            $response = $this->b24->sendBatch($this->q_req);
            $result   = $response['result']['result']??[];
            // Чистим очередь запросов.
            $this->q_req = [];
            $this->postReceivingResponseProcessing($result);
            $events = $response['result']['result']['event.offline.get']['events']??[];
            foreach ($events as $event){
                $delta = time()-strtotime($event['TIMESTAMP_X']);
                if($delta > 15 ){
                    $this->b24->logger->writeInfo("An outdated response was received {$delta}s: ". json_encode($event));
                    continue;
                }
                $req_data = [
                    'event' => $event['EVENT_NAME'],
                    'data'  => $event['EVENT_DATA'],
                ];
                $this->b24->handleEvent($req_data);
            }
        }

        if(count($this->q_pre_req) > 0){
            $tmpArr = [$this->q_req];
            foreach ($this->q_pre_req as $data){
                if ('action_hangup_chan' === $data['action']) {
                    /** @var ModuleBitrix24CDR $cdr */
                    $cdr = ModuleBitrix24CDR::findFirst("uniq_id='{$data['UNIQUEID']}'");
                    if ($cdr) {
                        $data['CALL_ID'] = $cdr->call_id;
                        $data['USER_ID'] = $cdr->user_id;
                        $tmpArr[] = $this->b24->telephonyExternalCallHide($data);
                    }
                } elseif ('action_dial_answer' === $data['action']) {
                    $userId = ''; $dealId = ''; $leadId = '';
                    /** @var ModuleBitrix24CDR $cdr */
                    $cdr = ModuleBitrix24CDR::findFirst("uniq_id='{$data['UNIQUEID']}'");
                    if ($cdr) {
                        if(!empty($cdr->dealId)){
                            $dealId = max($dealId, $cdr->dealId);
                        }
                        if(!empty($cdr->lead_id)){
                            $leadId = max($leadId, $cdr->lead_id);
                        }
                        // Определяем, кто ответил на вызов.
                        $userId = $cdr->user_id;
                        // Отмечаем вызов как отвеченный.
                        $cdr->answer = 1;
                        $cdr->save();
                    }
                    /** @var ModuleBitrix24CDR $result */
                    /** @var ModuleBitrix24CDR $row */
                    $result = ModuleBitrix24CDR::find("linkedid='{$data['linkedid']}' AND answer IS NULL");
                    foreach ($result as $row) {
                        if(!empty($row->dealId)){
                            $dealId = max($dealId, $row->dealId);
                        }
                        if(!empty($row->lead_id)){
                            $leadId = max($leadId, $row->lead_id);
                        }
                        if($cdr && $row->user_id === $cdr->user_id){
                            continue;
                        }
                        // Для всех CDR, где вызов НЕ отвечен определяем сотрудника и закрываем карточку звонка.
                        $data['CALL_ID'] = $row->call_id;
                        $data['USER_ID'] = $row->user_id;
                        $tmpArr[] = $this->b24->telephonyExternalCallHide($data);
                    }

                    if(!empty($leadId) && !empty($userId)){
                        $tmpArr[] = $this->b24->crmLeadUpdate($dealId, $userId);
                    }
                    if(!empty($dealId) && !empty($userId)){
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

    }

    /**
     * Дополнительные действия после получения овета на запрос к API.
     * @param array $result
     */
    public function postReceivingResponseProcessing(array $result):void{
        $tmpArr = [];
        foreach ($result as $key => $partResponse){
            $actionName = explode('_', $key)[0]??'';
            if($actionName === Bitrix24Integration::API_CALL_REGISTER){
                $this->b24->telephonyExternalCallPostRegister($key, $partResponse);
            }elseif ($actionName === Bitrix24Integration::API_SEARCH_ENTITIES){
                $this->postSearchCrmEntities($key, $partResponse);
            }elseif ($actionName === Bitrix24Integration::API_ATTACH_RECORD){
                $uploadUrl = $partResponse["uploadUrl"]??'';
                $data = $this->b24->telephonyPostAttachRecord($key, $uploadUrl);
                if(!empty($data)){
                    $this->queueAgent->publish(json_encode($data, JSON_UNESCAPED_SLASHES), 'b24-uploader');
                }
            }elseif ($actionName === Bitrix24Integration::API_CALL_FINISH){
                $this->b24->telephonyExternalCallPostFinish($key, $partResponse, $tmpArr);
            }
        }
        $this->q_req = array_merge(...$tmpArr);
        unset($tmpArr);
    }

    /**
     * Удаление Дела по ID
     *
     * @param  string $key
     * @param  array $response
     *
     */
    public function postSearchCrmEntities(string $key, array $response): void
    {
        $tube = $this->channelSearchCashe[$key]??'';
        if(!empty($tube)){
            $this->queueAgent->publish(json_encode($response), $tube);
        }
    }

}


// Start worker process
WorkerBitrix24IntegrationHTTP::startWorker($argv??null);
