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
use JsonException;

class Bitrix24InvokeRest{
    private BeanstalkClient $queueAgent;
    private string $responsibleNumber = '';
    private array $data = [];

    public function __construct()
    {
        $this->queueAgent = new BeanstalkClient();
    }

    public function invoke(string $action, array $args, int $timeout = 10):array
    {
        $inbox_tube    = uniqid(Bitrix24Integration::B24_INVOKE_REST_CHANNEL.'.', true);
        $params = [
            'action'    => $action,
            'args'      => $args,
            'inbox_tube'=> $inbox_tube,
        ];

        $this->queueAgent->subscribe($inbox_tube, [$this, 'callback']);
        $this->queueAgent->publish(json_encode($params), Bitrix24Integration::B24_INVOKE_REST_CHANNEL);
        $this->queueAgent->setTimeoutHandler([$this, 'timeOutCallback']);

        try {
            $this->queueAgent->wait($timeout);
        } catch (\Exception $e) {
            Util::sysLogMsg('Bitrix24InvokeRest', $e->getMessage());
        }
        return $this->data;
    }

    public function callback($client):void
    {
        $resFile = trim($client->getBody());
        try {
            $object = json_decode(file_get_contents($resFile), true, 512, JSON_THROW_ON_ERROR);
        }catch (JsonException $e){
            $object = [];
        }
        unlink($resFile);
        $this->data = $object;
    }

    public function timeOutCallback():void
    {
        Util::sysLogMsg('Bitrix24InvokeRest', 'timeOutCallback');
    }
}