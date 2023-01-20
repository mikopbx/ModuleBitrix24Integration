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
class ModuleBitrix24GetResponsible{
    private BeanstalkClient $queueAgent;
    private string $responsibleNumber = '';

    public function __construct()
    {
        $this->queueAgent = new BeanstalkClient();
    }

    public function getResposibleNumber(string $number, string $linkedId, string $did, int $timeout = 5):string
    {
        $inbox_tube    = uniqid(Bitrix24Integration::B24_SEARCH_CHANNEL, true);
        $data = [
            'PHONE_NUMBER'  => $number,
            'linkedid'      => $linkedId,
            'inbox_tube'    => $inbox_tube,
            'did'           => $did
        ];

        $this->queueAgent->subscribe($inbox_tube, [$this, 'calback']);
        $this->queueAgent->publish(json_encode($data), Bitrix24Integration::B24_SEARCH_CHANNEL);
        $this->queueAgent->setTimeoutHandler([$this, 'timeOutCallback']);

        try {
            $this->queueAgent->wait($timeout);
        } catch (\Exception $e) {
            $this->exceptionCallback();
        }
        return $this->responsibleNumber;
    }

    public function calback($client):void
    {
        $data = json_decode($client->getBody(), true);
        $this->responsibleNumber = $data['responsible']??'';
    }

    public function timeOutCallback():void
    {
        // Таймаут обработки запроса
    }

    public function exceptionCallback():void
    {
        // Исключение при обработке запроса
    }
}