<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2023 Alexey Portnov and Nikolay Beketov
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

/*
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 10 2020
 */

namespace Modules\ModuleBitrix24Integration\bin;
require_once 'Globals.php';

use MikoPBX\Core\Asterisk\AsteriskManager;
use MikoPBX\Core\System\BeanstalkClient;
use Exception;
use MikoPBX\Core\System\MikoPBXConfig;
use MikoPBX\Core\Workers\WorkerBase;
use MikoPBX\Common\Models\Extensions;
use MikoPBX\Core\Workers\WorkerCdr;
use Modules\ModuleBitrix24Integration\Lib\Bitrix24Integration;
use Modules\ModuleBitrix24Integration\Models\ModuleBitrix24Integration;
use MikoPBX\Core\System\Util;
use Modules\ModuleBitrix24Integration\Lib\Logger;

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
    private string $leadType = Bitrix24Integration::API_LEAD_TYPE_ALL;
    private array $external_lines = [];
    private array $disabledDid = [];
    private bool $crmCreateLead = false;
    private BeanstalkClient $client;

    private array $outChannels = [];

    private array $channelCounter = [];
    private string $responsibleMissedCalls = '';
    private Logger $logger;

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
        $this->logger->writeInfo('Need SHUTDOWN... '.$signal);
        cli_set_process_title('SHUTDOWN_'.cli_get_process_title());
    }

    /**
     * Старт работы листнера.
     *
     * @param $argv
     */
    public function start($argv):void
    {
        $this->logger =  new Logger('IntegrationAMI', 'ModuleBitrix24Integration');
        $this->logger->writeInfo('Start daemon...');

        $this->client = new BeanstalkClient(Bitrix24Integration::B24_INTEGRATION_CHANNEL);
        $this->am     = Util::getAstManager();
        $this->b24    = new Bitrix24Integration('_ami');

        if (!$this->b24->initialized) {
            $this->logger->writeError('Settings not set... exit');
            exit();
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
        while ($this->needRestart === false) {
            $this->am->waitUserEvent(true);
            if ( !is_resource($this->am->socket) === false) {
                $this->logger->writeError('Need reconnect AMI...');
                usleep(100000);
                $this->am = Util::getAstManager();
                $this->setFilter();
            }
            $this->logger->rotate();
        }
    }

    /**
     * Отправка данных на сервер очередей.
     *
     * @param array $result - данные в ормате json для отправки.
     */
    private function Action_SendToBeanstalk(array $result): void
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
            $this->logger->writeInfo('Error send data to queue. '.$error);
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
            $this->logger->writeError('inner numbers is empty. Wait 2 seconds.');
            sleep(2);
            $iterationCount++;
            if ($iterationCount>25){ // Сейчас WorkerSafeScriptsCore создаст копию этого процесса, т.к. он не отвечает на Ping
                $this->logger->writeError('Internal numbers not installed.');
                exit(1);
            }
        }
        $this->logger->writeInfo('Update settings...');
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
        $settings = ConnectorDb::invoke(ConnectorDb::FUNC_GET_GENERAL_SETTINGS);
        if ($settings !== null) {
            $this->export_records         = (intval($settings->export_records) === 1);
            $this->export_cdr             = (intval($settings->export_cdr) === 1);
            $this->crmCreateLead          = (intval($settings->crmCreateLead) !== 0);
            $this->leadType               = (empty($settings->leadType))?Bitrix24Integration::API_LEAD_TYPE_ALL:$settings->leadType;

            $responsible       = $this->b24->inner_numbers[$settings->responsibleMissedCalls]??[];
            $this->responsibleMissedCalls = empty($responsible)?'':$responsible['ID'];
        }
        $this->updateExternalLines();
        $this->b24->updateSettings();
        $this->logger->writeInfo($this->b24->usersSettingsB24, 'usersSettingsB24');

    }

    /**
     * Обновление массива  external_lines для сопоставления внешних линий и номера DID.
     */
    private function updateExternalLines(): void
    {
        $this->external_lines = [];
        $lines = ConnectorDb::invoke(ConnectorDb::FUNC_GET_EXTERNAL_LINES, []);
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
                if(intval($line['disabled']) === 1){
                    $this->disabledDid[] = $alias;
                }
            }
        }
    }

    /**
     * Установка фильтра
     *
     */
    private function setFilter():void
    {
        $pingTube = $this->makePingTubeName(self::class);
        $params = ['Operation' => 'Add', 'Filter' => 'UserEvent: '.$pingTube];
        $this->am->sendRequestTimeout('Filter', $params);
        $params = ['Operation' => 'Add', 'Filter' => 'UserEvent: CdrConnector'];
        $this->am->sendRequestTimeout('Filter', $params);
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
        $stringData = base64_decode($parameters['AgiData']);
        $data = json_decode($stringData, true);
        $this->logger->writeInfo($stringData, $data['action']);
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
            case 'transfer_dial_answer':
                $this->actionDialAnswer($data['transfer_UNIQUEID'], $data['linkedid']);
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
            default:
                break;
        }

    }

    /**
     * Обработка оповещения о звонке.
     *
     * @param $data
     */
    private function actionDial($data):void {
        $general_src_num = '';
        if ($data['transfer'] === '1') {
            $linkedId = $data['linkedid']??'';
            // Попробуем выяснить кого переадресуют.
            $filter                        = [
                '(linkedid = {linkedid})',
                'bind'  => [
                    'linkedid' => $linkedId,
                ],
                'order' => 'id',
                'limit' => 1,
            ];
            $filter['miko_result_in_file'] = true;
            $filter['miko_tmp_db']         = true;

            $clientBeanstalk  = new BeanstalkClient(WorkerCdr::SELECT_CDR_TUBE);
            $message = $clientBeanstalk->request(json_encode($filter), 2);
            if ($message !== false) {
                $filename = json_decode($message, false);
                if (file_exists($filename)) {
                    $history = json_decode(file_get_contents($filename), false);
                    if (!empty($history)) {
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
                $this->logger->writeError("Error get data from queue 'select_cdr'". $linkedId);
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

        if($data['action'] === 'dial' && stripos($data['src_chan'], '/'.$data['src_num'].'-') !== false){
            // Это исходящий вызов, начальный канал.
            $this->outChannels[$data['src_chan']] = [
                'start'     => $data['start'],
                'UNIQUEID'  => $data['UNIQUEID'],
                'src_num'   => $data['src_num'],
            ];
        }

        $linkedId = $data['linkedid']??'';

        $dstNum = Bitrix24Integration::getPhoneIndex($data['dst_num']);
        $dstUserShotNum = $this->b24->mobile_numbers[$dstNum]['UF_PHONE_INNER']??'';
        if( !isset($this->b24->usersSettingsB24[$data['dst_num']])
            && !isset($this->b24->usersSettingsB24[$data['src_num']])
            && !isset($this->b24->usersSettingsB24[$dstUserShotNum])){
            // Вызов по этому звонку не следует грузить в b24, внутренний номер не участвует в интеграции.
            // Или тут нет внутреннего номера.
            $this->logger->writeInfo("the internal number ($data[src_num] -> $data[dst_num], $dstUserShotNum) is not involved in the integration. $linkedId");
            return;
        }
        if(in_array($data['did'], $this->disabledDid, true)){
            $this->b24->saveCache('finish-cdr-'.$data['UNIQUEID'], true, 3600);
            $this->b24->saveCache('finish-cdr-'.$data['linkedid'], true, 3600);
            $this->logger->writeInfo("Integration is disabled for this DID $linkedId");
            return;
        }
        $LINE_NUMBER = $this->external_lines[$data['did']]??'';
        if (isset($this->inner_numbers[$data['src_num']]) && strlen($general_src_num) <= $this->extensionLength) {
            $this->logger->writeInfo("This is an outgoing call from an internal number. $linkedId");
            if (strlen($data['dst_num']) > $this->extensionLength && ! in_array($data['dst_num'], $this->extensions, true)) {
                $createLead = ($this->leadType !== Bitrix24Integration::API_LEAD_TYPE_IN && $this->crmCreateLead)?'1':"0";
                $req_data = [
                    'CALL_START_DATE'  => date(\DateTimeInterface::ATOM, strtotime($data['start'])),
                    'USER_ID'          => $this->inner_numbers[$data['src_num']]['ID'],
                    'USER_PHONE_INNER' => $data['src_num'],
                    'CRM_CREATE'       => $createLead,
                    'DST_USER_CHANNEL' => '',
                    'PHONE_NUMBER'     => $data['dst_num'],
                    'TYPE'             => '1',
                    'UNIQUEID'         => $data['UNIQUEID'],
                    'linkedid'         => $data['linkedid'],
                    'LINE_NUMBER'      => $LINE_NUMBER,
                    'action'           => 'telephonyExternalCallRegister',
                    'did'              => $data['did']
                ];
                $this->Action_SendToBeanstalk($req_data);
                $this->b24->saveCache('reg-cdr-'.$req_data['linkedid'], true, 600);
            }else{
                $this->logger->writeInfo("{$data['dst_num']} <= $this->extensionLength || {$data['dst_num']} is  internal... cancellation $linkedId");
            }
        } elseif (isset($this->inner_numbers[$data['dst_num']]) || isset($this->b24->mobile_numbers[$dstNum])) {
            if(isset($this->b24->mobile_numbers[$dstNum])){
                $userId = $this->b24->mobile_numbers[$dstNum]['ID'];
            }else{
                $userId = $this->inner_numbers[$data['dst_num']]['ID'];
            }
            $inner  = $data['dst_num'];
            $this->logger->writeInfo("This is an incoming call to an employee's internal number. $linkedId");
            $createLead = ($this->leadType !== Bitrix24Integration::API_LEAD_TYPE_OUT && $this->crmCreateLead)?'1':'0';
            if (strlen($general_src_num) > $this->extensionLength && ! in_array($general_src_num, $this->extensions, true)) {
                $this->logger->writeInfo("This is transfer... $linkedId");
                $req_data = [
                    'UNIQUEID'         => $data['UNIQUEID'],
                    'linkedid'         => $data['linkedid'],
                    'CALL_START_DATE'  => date(\DateTimeInterface::ATOM, strtotime($data['start'])),
                    'USER_ID'          => $userId,
                    'USER_PHONE_INNER' => $inner,
                    'DST_USER_CHANNEL' => $data['dst_chan']??'',
                    'PHONE_NUMBER'     => $general_src_num,
                    'TYPE'             => '3',
                    'CRM_CREATE'       => $createLead,
                    'LINE_NUMBER'      => $LINE_NUMBER,
                    'action'           => 'telephonyExternalCallRegister',
                    'did'              => $data['did']
                ];
                $this->Action_SendToBeanstalk($req_data);
                $this->b24->saveCache('reg-cdr-'.$req_data['linkedid'], true, 600);
            } elseif (strlen($data['src_num']) > $this->extensionLength && ! in_array($data['src_num'], $this->extensions, true)) {
                // Это вызов с номера клиента.
                $this->logger->writeInfo("This is incoming... $linkedId");
                $req_data = [
                    'UNIQUEID'         => $data['UNIQUEID'],
                    'linkedid'         => $data['linkedid'],
                    'CALL_START_DATE'  => date(\DateTimeInterface::ATOM, strtotime($data['start'])),
                    'USER_ID'          => $userId,
                    'USER_PHONE_INNER' => $inner,
                    'PHONE_NUMBER'     => $data['src_num'],
                    'DST_USER_CHANNEL' => $data['dst_chan']??'',
                    'CRM_CREATE'       => $createLead,
                    'TYPE'             => '2',
                    'LINE_NUMBER'      => $LINE_NUMBER,
                    'action'           => 'telephonyExternalCallRegister',
                    'did'              => $data['did']
                ];
                $this->Action_SendToBeanstalk($req_data);
                $this->b24->saveCache('reg-cdr-'.$req_data['linkedid'], true, 600);
            }else{
                $this->logger->writeInfo("cancellation... $linkedId");

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
        $outChanData = $this->outChannels[$data['agi_channel']]??[];
        unset($this->outChannels[$data['agi_channel']]);
        // end
        if(isset($this->channelCounter[$data['UNIQUEID']])){
            // Не все каналы с этим ID были завершены.
            // Вероятно это множественная регистрация.
            return;
        }
        $not_local = (stripos($data['agi_channel'], 'local/') === false);
        if ($not_local) {
            $dataForSend = [
                'UNIQUEID' => $data['UNIQUEID'],
                'linkedid' => $data['linkedid'],
                'channel'  => $data['agi_channel'],
                'action'   => 'action_hangup_chan',
            ];
            $this->Action_SendToBeanstalk($dataForSend);
        }

        $srsUserId = $this->getInnerNum($data['src_num']);
        if(!empty($srsUserId) && !empty($outChanData) && $data['dialstatus'] !== 'ANSWERED'){
            $this->logger->writeInfo("Send finish event from hangup...".$data['linkedid']);
            $params = [
                'UNIQUEID'       => $outChanData['UNIQUEID'],
                'USER_ID'        => $srsUserId,
                'DURATION'       => '0',
                'FILE'           => '',
                'GLOBAL_STATUS'  => 'NOANSWER',
                'disposition'    => 'NOANSWER',
                "export_records" => $this->export_records,
                'linkedid'       => $data['linkedid'],
                'action'         => 'telephonyExternalCallFinish',
            ];
            $this->Action_SendToBeanstalk($params);
        }

    }

    /**
     * Обработка события завершения телефонного звонка.
     *
     * @param $data
     */
    private function actionCompleteCdr($data):void
    {
        $linkedId = $data['linkedid']??'';
        if(in_array($data['did'],$this->disabledDid, true)){
            $this->logger->writeInfo("Integration is disabled for this DID ".$linkedId);
            return;
        }

        $srsUserId = $this->getInnerNum($data['src_num']);
        $dstUserId = $this->getInnerNum($data['dst_num']);

        if(isset($this->channelCounter[$data['UNIQUEID']])
            // Не все каналы с этим ID были завершены.
            // Вероятно это множественная регистрация.
            // Либо это CDR по внутреннему вызову.
            || (!empty($srsUserId) && !empty($dstUserId)) ){
            $this->logger->writeInfo("Not all channels with this ID have been completed. This is probably a multiple registration. Or it's CD R on an internal call.. cancellation".$linkedId);
            return;
        }

        $isOutgoing = false;
        if (!empty($srsUserId)) {
            // Это исходящий вызов.
            $USER_ID     = $srsUserId;
            $isOutgoing  = true;
        } else{
            // Это входящие вызов.
            $USER_ID     = $dstUserId;
        }

        $responsible = '';
        $isMissed = $data['GLOBAL_STATUS'] !== 'ANSWERED';

        $finishKeyID = 'finish-cdr-'.$data['UNIQUEID'];
        if(!empty($USER_ID) && !$isMissed) {
            if ($data['disposition'] === 'ANSWERED') {
                // Вызов был отвечен в рамках этой CDR.
                $responsible = $USER_ID;
            }
        }elseif ($isMissed && $isOutgoing === false && !empty($this->responsibleMissedCalls)) {
            // Назначаем пропущенный на ответственного.
            $responsible = $this->responsibleMissedCalls;
            // Если пропущен, то финишируем только один CDR.
            $finishKeyID = 'finish-cdr-'.$data['linkedid'];
        }elseif ($isMissed && !empty($USER_ID) ){
            // Рандомно назначаем ответственного для пропущенного.
            $responsible = $USER_ID;
        }else{
            $this->logger->writeInfo("The responsible person was not found. cancellation ".$linkedId);
            return;
        }

        if(!empty($responsible)
           && !$this->b24->getCache($finishKeyID)){

            $LINE_NUMBER = $this->external_lines[$data['did']]??'';
            if(!$isOutgoing && strlen($data['src_num']) > $this->extensionLength
                && !$this->b24->getCache('reg-cdr-'.$data['linkedid'])){
                $this->logger->writeInfo("Send Register event... For incoming users only. If it is a missed one, then you need to register it first.".$linkedId);
                $createLead = ($this->leadType !== Bitrix24Integration::API_LEAD_TYPE_OUT && $this->crmCreateLead)?'1':'0';
                $req_data = [
                    'UNIQUEID'         => $data['UNIQUEID'],
                    'linkedid'         => $data['linkedid'],
                    'CALL_START_DATE'  => date(\DateTimeInterface::ATOM, strtotime($data['start'])),
                    'USER_ID'          => $responsible,
                    'USER_PHONE_INNER' => $data['dst_num'],
                    'PHONE_NUMBER'     => $data['src_num'],
                    'DST_USER_CHANNEL' => $data['dst_chan']??'',
                    'CRM_CREATE'       => $createLead,
                    'TYPE'             => '2',
                    'LINE_NUMBER'      => $LINE_NUMBER,
                    'action'           => 'telephonyExternalCallRegister',
                    'did'              => $data['did']
                ];
                $this->Action_SendToBeanstalk($req_data);
            }

            $this->logger->writeInfo("Send finish event...".$linkedId);
            $params = [
                'UNIQUEID'       => $data['UNIQUEID'],
                'USER_ID'        => $responsible,
                'DURATION'       => $data['billsec'],
                'FILE'           => $data['recordingfile'],
                'GLOBAL_STATUS'  => $data['GLOBAL_STATUS'],
                'disposition'    => $data['disposition'],
                "export_records" => $this->export_records,
                'linkedid'       => $data['linkedid'],
                'LINE_NUMBER'    => $LINE_NUMBER,
                'action'         => 'telephonyExternalCallFinish',
            ];
            $this->Action_SendToBeanstalk($params);
            if($isMissed){
                $this->b24->saveCache($finishKeyID, true, 60);
            }
        }
    }

    /**
     * Событие создания нового канала назначения.
     * @param $data
     * @return void
     */
    public function actionDialCreateChan($data):void{
        // Считаем каналы с одинаковым UID
        $countChannel = $this->channelCounter[$data['UNIQUEID']]??0;
        $countChannel++;
        $this->channelCounter[$data['UNIQUEID']] = $countChannel;
    }

    /**
     * Является ли номер внутренним.
     * @param string $number
     * @return string
     */
    private function getInnerNum(string $number):string
    {
        $userId = '';
        $number = Bitrix24Integration::getPhoneIndex($number);
        if(isset($this->inner_numbers[$number])){
            $userId = $this->inner_numbers[$number]['ID'];
        } elseif(isset($this->b24->mobile_numbers[$number])){
            $userId = $this->b24->mobile_numbers[$number]['ID'];
        }
        return  $userId;
    }
}

// Start worker process
WorkerBitrix24IntegrationAMI::startWorker($argv??[]);