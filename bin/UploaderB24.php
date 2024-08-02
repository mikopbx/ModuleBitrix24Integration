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
use Modules\ModuleBitrix24Integration\Lib\Logger;

class UploaderB24 extends WorkerBase
{
    public const B24_UPLOADER_CHANNEL = 'b24-uploader';
    private Bitrix24Integration $b24;
    private BeanstalkClient $queueAgent;
    private Logger $logger;

    /**
     * Начало работы демона.
     *
     * @param $argv
     */
    public function start($argv):void
    {
        $this->logger = new Logger('UploaderB24', 'ModuleBitrix24Integration');
        $this->logger->writeInfo('Start daemon...');

        $this->b24    = new Bitrix24Integration('_uploader');
        $this->initBeanstalk();
        $this->logger->writeInfo('Start waiting...');
        while (!$this->needRestart) {
            try {
                $this->queueAgent->wait(10);
            } catch (Exception $e) {
                $this->logger->writeError($e->getLine().';'.$e->getCode().';'.$e->getMessage());
                sleep(1);
                $this->initBeanstalk();
            }
            $this->logger->rotate();
        }
    }

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
        $this->logger->writeInfo("NEED SHUTDOWN ($signal)...");

    }

    /**
     * Инициализация Beanstalk
     * @return void
     */
    private function initBeanstalk():void
    {
        $this->logger->writeInfo('Init Beanstalk...');
        $this->queueAgent = new BeanstalkClient(self::B24_UPLOADER_CHANNEL);
        $this->queueAgent->subscribe($this->makePingTubeName(self::class), [$this, 'pingCallBack']);
        $this->queueAgent->subscribe(self::B24_UPLOADER_CHANNEL,  [$this, 'callBack']);
    }

    /**
     * @param BeanstalkClient $client
     */
    public function callBack(BeanstalkClient $client): void
    {
        $stringData = $client->getBody();
        $this->logger->writeInfo("Start upload file.");
        $this->logger->writeInfo("Raw data: $stringData");
        try {
            /** @var array $data */
            $data = json_decode($stringData, true, 512, JSON_THROW_ON_ERROR);
        }catch (Exception $e){
            $data = null;
        }
        if(!is_array($data)){
            $this->logger->writeError("Data is not valid JSON.");
            return;
        }
        $filename = $data['FILENAME']??'';
        if(!file_exists($filename)){
            $this->logger->writeError("File '$filename' not exists!!!");
            return;
        }
        $result = $this->b24->uploadRecord($data['uploadUrl'], $filename);
        try {
            $rawResult = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $this->logger->writeInfo('Result: ' . $rawResult);
        }catch (Exception $e){
            $this->logger->writeError('Exception upload file: ' . $e->getMessage());
            return;
        }
        if(!isset($result['result']["FILE_ID"])){
            $this->logger->writeError('Fail upload file. Req: ' . $rawResult);
        }
        usleep(300000);
    }
}

// Start worker process
if(isset($argv) && count($argv) !== 1) {
    UploaderB24::startWorker($argv??[]);
}
