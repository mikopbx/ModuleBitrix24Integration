<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2021 Alexey Portnov and Nikolay Beketov
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

namespace Modules\ModuleBitrix24Integration\Lib;
use MikoPBX\Core\System\BeanstalkClient;
use MikoPBX\Core\System\Util;

class ModuleBitrix24GetResponsible{
    private BeanstalkClient $queueAgent;
    private string $responsibleNumber = '';
    private array $data = [];

    public function __construct()
    {
        $this->queueAgent = new BeanstalkClient();
    }

    public function getResposibleNumber(string $number, string $linkedId, string $did, int $timeout = 10):string
    {
        $inbox_tube    = uniqid(Bitrix24Integration::B24_SEARCH_CHANNEL, true);
        $params = [
            'PHONE_NUMBER'  => $number,
            'linkedid'      => $linkedId,
            'inbox_tube'    => $inbox_tube,
            'did'           => $did
        ];

        $this->queueAgent->subscribe($inbox_tube, [$this, 'calback']);
        $this->queueAgent->publish(json_encode($params), Bitrix24Integration::B24_SEARCH_CHANNEL);
        $this->queueAgent->setTimeoutHandler([$this, 'timeOutCallback']);

        try {
            $this->queueAgent->wait($timeout);
        } catch (\Exception $e) {
            Util::sysLogMsg('ModuleBitrix24GetResponsible', $e->getMessage());
        }
        return $this->responsibleNumber;
    }

    public function getData():array
    {
        return $this->data;
    }

    public function calback($client):void
    {
        $dataFromJson = json_decode($client->getBody(), true);
        if(is_array($dataFromJson)){
            $this->responsibleNumber = $dataFromJson['responsible']??'';
            $this->data = $dataFromJson;
        }
    }

    public function timeOutCallback():void
    {
        Util::sysLogMsg('ModuleBitrix24GetResponsible', 'timeOutCallback');
    }
}