<?php
/*
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 10 2020
 */

namespace Modules\ModuleBitrix24Integration\Lib;
require_once 'Globals.php';

use MikoPBX\Core\Asterisk\AsteriskManager;
use MikoPBX\Core\System\BeanstalkClient;
use DateTime;
use Exception;
use MikoPBX\Core\System\MikoPBXConfig;
use MikoPBX\Core\Workers\WorkerBase;
use MikoPBX\Common\Models\Extensions;
use MikoPBX\Core\Workers\WorkerCdr;
use Modules\ModuleBitrix24Integration\Models\ModuleBitrix24ExternalLines;
use Modules\ModuleBitrix24Integration\Models\ModuleBitrix24Integration;
use MikoPBX\Core\System\Util;

class WorkerBitrix24IntegrationAMI extends WorkerBase
{
    /** @var AsteriskManager $am */
    protected AsteriskManager $am;
    /** @var Bitrix24Integration $b24*/
    private Bitrix24Integration $b24;
    private array $inner_numbers;
    private int $counter = 0;
    private array $msg = [];
    private int $extensionLength;
    private array $extensions = [];
    private bool $export_records = false;
    private bool $export_cdr = false;
    private array $external_lines = [];
    private BeanstalkClient $client;

    private array $channelCounter = [];

    /**
     * Старт работы листнера.
     *
     * @param $argv
     */
    public function start($argv):void
    {
        $this->client = new BeanstalkClient(Bitrix24Integration::B24_INTEGRATION_CHANNEL);
        $this->am     = Util::getAstManager();
        $this->b24    = new Bitrix24Integration();

        $this->b24->logger->writeInfo("Bitrix24IntegrationAMI: starting...");
        if (!$this->b24->initialized) {
            die('Settings not set...');
        }
        $this->setFilter();

        /**
         * Без b24GetPhones не стартует AMI воркер, если нет корректного соедниения с Bitrix,
         * Может быть после какой то из проверок вызывать
         * $state = new PbxExtensionState('ModuleBitrix24Integration');
         *         $result = $state->disableModule();
         */
        $this->b24->b24GetPhones();
        $this->updateSettings();

        $config                = new MikoPBXConfig();
        $this->extensionLength = $config->getGeneralSettings('PBXInternalExtensionLength');

        $this->am->addEventHandler("userevent", [$this, "callback"]);
        while (true) {
            $result = $this->am->waitUserEvent(true);
            if ($result == false) {
                // Нужен реконнект.
                usleep(100000);
                $this->am = Util::getAstManager();
                $this->setFilter();
            }
        }
    }


    /**
     * Отправка данных на сервер очередей.
     *
     * @param array $result - данные в ормате json для отправки.
     */
    private function Action_SendToBeanstalk($result): void
    {
        $message_is_sent = false;
        $error           = '';
        for ($i = 1; $i <= 10; $i++) {
            try {

                $result_send = $this->client->publish(json_encode($result));
                if ($result_send === false) {
                    $this->client->reconnect();
                }
                $message_is_sent = ($result_send !== false);
                if ($message_is_sent === true) {
                    // Проверка
                    break;
                }
            } catch (Exception $e) {
                $this->client = new BeanstalkClient(Bitrix24Integration::B24_INTEGRATION_CHANNEL);
                $error        = $e->getMessage();
            }
        }

        if ($message_is_sent === false) {
            Util::sysLogMsg('b24_AMI_Connector', 'Error send data to queue. ' . $error);
        }

    }

    /**
     * Обновление списка номеров для отслеживания.
     */
    private function updateSettings():void{
        $iterationCount = 0;
        while (true){
            $innerNumbers = $this->b24->getCache('inner_numbers');
            if (is_array($innerNumbers) && count($innerNumbers)>0) {
                $this->inner_numbers = (array)$innerNumbers;
                break;
            }
            $this->b24->logger->writeInfo("Bitrix24IntegrationAMI: inner numbers is empty. Wait 2 seconds...");
            sleep(2);
            $iterationCount++;
            if ($iterationCount>25){ // Сейчас WorkerSafeScriptsCore создаст копию этого процесса, т.к. он не отвечает на Ping
                die('Internal numbers not installed');
            }
        }

        $keys_for_del  = [];
        foreach ($this->msg as $key => $time) {
            if ((time() - $time) > 5) {
                $keys_for_del[] = $key;
            }
        }
        foreach ($keys_for_del as $key) {
            unset($this->msg[$key]);
        }

        /** @var Extensions $res_ext */
        /** @var Extensions $exten */
        $res_ext = Extensions::find('type<>"EXTERNAL"');
        foreach ($res_ext as $exten) {
            $this->extensions[] = $exten->number;
        }

        /** @var ModuleBitrix24Integration $settings */
        $settings = ModuleBitrix24Integration::findFirst();
        if ($settings !== null) {
            $this->export_records = ($settings->export_records === '1');
            $this->export_cdr     = ($settings->export_cdr === '1');
        }

        $this->updateExternalLines();
    }

    /**
     * Обновление массива  external_lines для сопоставления внешних линий и номера DID.
     */
    private function updateExternalLines(): void
    {
        $this->external_lines = [];
        $lines = ModuleBitrix24ExternalLines::find()->toArray();
        foreach ($lines as $line){
            $this->external_lines[$line['number']] = $line['number'];
            if(empty($line['alias'])){
                continue;
            }
            $aliases = explode(' ', $line['alias']);
            foreach ($aliases as $alias){
                if(empty($alias)){
                    continue;
                }
                $this->external_lines[$alias] = $line['number'];
            }
        }
    }

    /**
     * Установка фильтра
     *
     * @return array
     */
    private function setFilter():array
    {
        $pingTube = $this->makePingTubeName(self::class);
        $params = ['Operation' => 'Add', 'Filter' => 'UserEvent: '.$pingTube];
        $this->am->sendRequestTimeout('Filter', $params);
        $params = ['Operation' => 'Add', 'Filter' => 'UserEvent: CdrConnector'];
        return $this->am->sendRequestTimeout('Filter', $params);
    }

    /**
     * Функция обработки оповещений.
     *
     * @param $parameters
     */
    public function callback($parameters):void{

        if ($this->replyOnPingRequest($parameters)){
            $this->counter++;
            if ($this->counter > 5) {
                // Обновляем список номеров. Получаем актуальные настройки.
                // Пинг приходит раз в минуту. Интервал обновления списка номеров 5 минут.
                $this->updateSettings();
                $this->counter = 0;
            }
            return;
        }

        if ('CdrConnector' !== $parameters['UserEvent']) {
            return;
        }
        if(!isset($parameters['AgiData'])){
            return;
        }

        $data = json_decode(base64_decode($parameters['AgiData']), true);
        switch ($data['action']) {
            case 'hangup_chan':
                $this->actionHangupChan($data);
                break;
            case 'dial_create_chan':
                $this->actionDialCreateChan($data);
                break;
            case 'dial_answer':
                $this->actionDialAnswer($data['id'], $data['linkedid']);
                break;
            case 'dial':
            case 'transfer_dial':
            case 'sip_transfer':
            case 'answer_pickup_create_cdr':
            case 'transfer_dial_create_cdr':
                $this->actionDial($data);
                break;
            case 'hangup_update_cdr':
                $this->actionCompleteCdr($data);
                break;
        }

    }

    /**
     * Обработка оповещения о звонке.
     *
     * @param $data
     */
    private function actionDial($data):void {
        $general_src_num = null;
        if ($data['transfer'] === '1') {
            // Попробуем выяснить кого переадресуют.
            $filter                        = [
                '(linkedid = {linkedid})',
                'bind'  => [
                    'linkedid' => $data['linkedid'],
                ],
                'order' => 'id',
                'limit' => 1,
            ];
            $filter['miko_result_in_file'] = true;
            $filter['miko_tmp_db']         = true;

            $client  = new BeanstalkClient(WorkerCdr::SELECT_CDR_TUBE);
            $message = $client->request(json_encode($filter), 2);
            if ($message !== false) {
                $filename = json_decode($message, false);
                if (file_exists($filename)) {
                    $history = json_decode(file_get_contents($filename), false);

                    // file_put_contents('/tmp/test.txt', json_encode($history, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);
                    if (count($history) > 0) {
                        // Определим номер того, кого переадресуют.
                        if ($data['src_num'] === $history[0]->src_num) {
                            $general_src_num = $history[0]->dst_num;
                        } else {
                            $general_src_num = $history[0]->src_num;
                        }
                    }
                    unlink($filename);
                }
            } else {
                Util::sysLogMsg('Bitrix24NotifyAMI', "Error get data from queue 'select_cdr'. ");
            }
        }
        $this->actionCreateCdr($data, $general_src_num);
    }

    /**
     * Обработка оповещения о начале телефонного звонка.
     *
     * @param $data
     * @param $general_src_num
     */
    private function actionCreateCdr($data, $general_src_num):void
    {
        if ( ! $this->export_cdr) {
            return;
        }
        $LINE_NUMBER = $this->external_lines[$data['did']]??'';
        if (isset($this->inner_numbers[$data['src_num']]) && strlen($general_src_num) <= $this->extensionLength) {
            // Это исходящий вызов с внутреннего номера.
            if (strlen($data['dst_num']) > $this->extensionLength && ! in_array($data['dst_num'], $this->extensions, true)) {
                $req_data = [
                    'CALL_START_DATE'  => date(\DateTimeInterface::ATOM, strtotime($data['start'])),
                    'USER_ID'          => $this->inner_numbers[$data['src_num']]['ID'],
                    'USER_PHONE_INNER' => $data['src_num'],
                    'DST_USER_CHANNEL'     => '',
                    'PHONE_NUMBER'     => $data['dst_num'],
                    'TYPE'             => '1',
                    'UNIQUEID'         => $data['UNIQUEID'],
                    'linkedid'         => $data['linkedid'],
                    'LINE_NUMBER'      => $LINE_NUMBER,
                    'action'           => 'telephonyExternalCallRegister',
                ];
                $this->Action_SendToBeanstalk($req_data);
            }
        } elseif (isset($this->inner_numbers[$data['dst_num']])) {
            // Это входящий вызов на внутренний номер сотрудника.
            if (strlen($general_src_num) > $this->extensionLength && ! in_array($general_src_num, $this->extensions, true)) {
                // Это переадресация от с.
                $req_data = [
                    'UNIQUEID'         => $data['UNIQUEID'],
                    'linkedid'         => $data['linkedid'],
                    'CALL_START_DATE'  => date(\DateTimeInterface::ATOM, strtotime($data['start'])),
                    'USER_ID'          => $this->inner_numbers[$data['dst_num']]['ID'],
                    'USER_PHONE_INNER' => $data['dst_num'],
                    'DST_USER_CHANNEL' => $data['dst_chan']??'',
                    'PHONE_NUMBER'     => $general_src_num,
                    'TYPE'             => '3',
                    'LINE_NUMBER'      => $LINE_NUMBER,
                    'action'           => 'telephonyExternalCallRegister',
                ];
                $this->Action_SendToBeanstalk($req_data);
            } elseif (strlen($data['src_num']) > $this->extensionLength && ! in_array($data['src_num'], $this->extensions, true)) {
                // Это вызов с номера клиента.
                $req_data = [
                    'UNIQUEID'         => $data['UNIQUEID'],
                    'linkedid'         => $data['linkedid'],
                    'CALL_START_DATE'  => date(\DateTimeInterface::ATOM, strtotime($data['start'])),
                    'USER_ID'          => $this->inner_numbers[$data['dst_num']]['ID'],
                    'USER_PHONE_INNER' => $data['dst_num'],
                    'PHONE_NUMBER'     => $data['src_num'],
                    'DST_USER_CHANNEL' => $data['dst_chan']??'',
                    'TYPE'             => '2',
                    'LINE_NUMBER'      => $LINE_NUMBER,
                    'action'           => 'telephonyExternalCallRegister',
                ];
                $this->Action_SendToBeanstalk($req_data);
            }
        }

    }

    /**
     * Скрываем карточку звонка для всех агентов, кто пропустил вызов.
     *
     * @param $UNIQUEID
     * @param $linkedid
     */
    private function actionDialAnswer($UNIQUEID, $linkedid):void
    {
        $data = [
            'action'   => 'action_dial_answer',
            'UNIQUEID' => $UNIQUEID,
            'linkedid' => $linkedid,
        ];
        $this->Action_SendToBeanstalk($data);
    }

    /**
     * Обработка события завершения телефонного звонка.
     *
     * @param $data
     */
    private function actionCompleteCdr($data):void
    {
        if ( ! $this->export_cdr) {
            return;
        }

        if(isset($this->channelCounter[$data['UNIQUEID']])){
            // Не все каналы с этим ID были завершены.
            // Вероятно это множественная регистрация.
            return;
        }

        if (isset($this->inner_numbers[$data['src_num']])) {
            // Это исходящий вызов.
            $USER_ID = $this->inner_numbers[$data['src_num']]['ID'];
        } elseif (isset($this->inner_numbers[$data['dst_num']])) {
            // Это входящие вызов.
            $USER_ID = $this->inner_numbers[$data['dst_num']]['ID'];
        } else {
            return;
        }
        $data = [
            'UNIQUEID'       => $data['UNIQUEID'],
            'USER_ID'        => $USER_ID,
            'DURATION'       => $data['billsec'],
            'FILE'           => $data['recordingfile'],
            'GLOBAL_STATUS'  => $data['GLOBAL_STATUS'],
            'disposition'    => $data['disposition'],
            'action'         => 'telephonyExternalCallFinish',
            "export_records" => $this->export_records,
        ];
        $this->Action_SendToBeanstalk($data);
    }

    /**
     * Обработка завершения звонка.
     * @param $data
     */
    public function actionHangupChan($data):void
    {
        // Считаем каналы с одинаковым UID
        $countChannel = $this->channelCounter[$data['UNIQUEID']]??0;
        $countChannel--;
        if($countChannel>0){
            $this->channelCounter[$data['UNIQUEID']] = $countChannel;
        }else{
            unset($this->channelCounter[$data['UNIQUEID']]);
        }
        // end
        if(isset($this->channelCounter[$data['UNIQUEID']])){
            // Не все каналы с этим ID были завершены.
            // Вероятно это множественная регистрация.
            return;
        }

        $not_local = (stripos($data['agi_channel'], 'local/') === false);
        if ($not_local) {
            $data = [
                'UNIQUEID' => $data['UNIQUEID'],
                'action'   => 'action_hangup_chan',
            ];
            $this->Action_SendToBeanstalk($data);
        }
    }
    public function actionDialCreateChan($data):void{
        // Считаем каналы с одинаковым UID
        $countChannel = $this->channelCounter[$data['UNIQUEID']]??0;
        $countChannel++;
        $this->channelCounter[$data['UNIQUEID']] = $countChannel;
    }

}

// Start worker process
WorkerBitrix24IntegrationAMI::startWorker($argv??null);