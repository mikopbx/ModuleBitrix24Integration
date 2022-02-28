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

class UploaderB24 extends WorkerBase
{
    public const B24_UPLOADER_CHANNEL = 'b24-uploader';
    private Bitrix24Integration $b24;
    private BeanstalkClient $queueAgent;

    /**
     * Начало работы демона.
     *
     * @param $params
     */
    public function start($params):void
    {
        $this->b24       = new Bitrix24Integration();
        $this->initBeanstalk();
        /** Основной цикл демона. */
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
     * Инициализация Beanstalk
     * @return void
     */
    private function initBeanstalk():void
    {
        $this->queueAgent = new BeanstalkClient(self::B24_UPLOADER_CHANNEL);
        $this->queueAgent->subscribe($this->makePingTubeName(self::class), [$this, 'pingCallBack']);
        $this->queueAgent->subscribe(self::B24_UPLOADER_CHANNEL,  [$this, 'callBack']);
    }

    /**
     * @param BeanstalkClient $client
     */
    public function callBack($client): void
    {
        /** @var array $data */
        $data = json_decode($client->getBody(), true);
        $result = $this->b24->uploadRecord($data['uploadUrl'], $data['FILENAME']);
        if(!isset($result['result']["FILE_ID"])){
            Util::sysLogMsg(self::class, 'Fail upload file. ' . json_encode($data, JSON_UNESCAPED_SLASHES));
        }
        usleep(300000);
    }
}


// Start worker process
UploaderB24::startWorker($argv??null);
