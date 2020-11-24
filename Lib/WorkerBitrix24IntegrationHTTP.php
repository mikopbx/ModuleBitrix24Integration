<?php
/*
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 10 2020
 */

namespace Modules\ModuleBitrix24Integration\Lib;
require_once 'Globals.php';

use MikoPBX\Core\System\BeanstalkClient;
use Exception;
use MikoPBX\Core\Workers\WorkerBase;
use Modules\ModuleBitrix24Integration\Models\ModuleBitrix24CDR;
use MikoPBX\Core\System\Util;

class WorkerBitrix24IntegrationHTTP extends WorkerBase
{
    private int $id_worker;
    private Bitrix24Integration $b24;
    private array $q_req      = [];
    private array $q_pre_req  = [];
    private bool $need_get_events = false;
    private int $last_update_inner_num = 0;

    /**
     * Начало работы демона.
     *
     * @param $argv
     */
    public function start($argv):void
    {
        $id = count($argv) > 2 ? $argv[2] : 1;
        $this->id_worker = $id;
        $this->b24       = new Bitrix24Integration();
        if (!$this->b24->initialized) {
            die('Settings not set...');
        }
        $this->b24->checkNeedUpdateToken();
        // При старте синхронизируем внешние линии.
        $this->b24->syncExternalLines();

        /** Основной цикл демона. */
        $client = $this->initBeanstalk();
        while (true) {
            try {
                $client->wait(1);
            } catch (Exception $e) {
                sleep(1);
                $client = $this->initBeanstalk();
            }
        }
    }

    /**
     * Инициализация BeanstalkClient.
     * @return BeanstalkClient
     */
    private function initBeanstalk():BeanstalkClient{
        $client = new BeanstalkClient(Bitrix24Integration::B24_INTEGRATION_CHANNEL);
        $client->subscribe($this->makePingTubeName(self::class),    [$this, 'pingCallBack']);
        $client->subscribe(Bitrix24Integration::B24_INTEGRATION_CHANNEL,      [$this, 'b24ChannelCallBack']);
        $client->setTimeoutHandler([$this, 'executeTasks']);
        return $client;
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

            $tmpArr = [];
            foreach ($result as $key => $value){
                $sub_key = substr($key, 0, 8);
                if($sub_key === 'register'){
                    $this->b24->registerCallData($key, $value);
                }elseif ($sub_key === 'finish__'){
                    $cache_data = $this->b24->getMemCache($key);
                    if($cache_data && isset($cache_data['lead_id'])){
                        // Удаляем лид.
                        $lead_delete = $this->b24->crmLeadDelete($cache_data['lead_id']);
                        if($lead_delete){
                            $tmpArr[] = $lead_delete;
                        }
                    }
                    if($cache_data && !empty($value['CRM_ACTIVITY_ID'])){
                        // Удаляем activity.
                        $activity_delete = $this->b24->crmActivityDelete($value['CRM_ACTIVITY_ID']);
                        $tmpArr[] = $activity_delete;
                    }
                }
            }
            $this->q_req = array_merge(...$tmpArr);
            unset($tmpArr);

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
                    /** @var ModuleBitrix24CDR $cdr */
                    $cdr = ModuleBitrix24CDR::findFirst("uniq_id='{$data['UNIQUEID']}'");
                    if ($cdr) {
                        // Отмечаем вызов как отвеченный.
                        $cdr->answer = 1;
                        $cdr->save();
                    }
                    /** @var ModuleBitrix24CDR $result */
                    /** @var ModuleBitrix24CDR $row */
                    $result = ModuleBitrix24CDR::find("linkedid='{$data['linkedid']}' AND answer IS NULL");
                    foreach ($result as $row) {
                        if($cdr && $row->user_id === $cdr->user_id){
                            continue;
                        }
                        // Для всех CDR, где вызов НЕ отвечен определяем сотрудника и закрываем карточку звонка.
                        $data['CALL_ID'] = $row->call_id;
                        $data['USER_ID'] = $row->user_id;
                        $tmpArr[] = $this->b24->telephonyExternalCallHide($data);
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

}

// Start worker process
$workerClassname = WorkerBitrix24IntegrationHTTP::class;
if (isset($argv) && count($argv) > 1) {
    cli_set_process_title($workerClassname);
    try {
        $worker = new $workerClassname();
        $worker->start($argv);
    } catch (\Throwable $e) {
        global $errorLogger;
        $errorLogger->captureException($e);
        Util::sysLogMsg("{$workerClassname}_EXCEPTION", $e->getMessage());
    }
}
