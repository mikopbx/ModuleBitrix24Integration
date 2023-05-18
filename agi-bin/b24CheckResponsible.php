#!/usr/bin/php
<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright (C) 2017-2023 Alexey Portnov and Nikolay Beketov
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

use MikoPBX\Core\Asterisk\AGI;
use Modules\ModuleBitrix24Integration\Lib\ModuleBitrix24GetResponsible;
use Modules\ModuleBitrix24Integration\Models\ModuleBitrix24Integration;

require_once 'Globals.php';
$settings = ModuleBitrix24Integration::findFirst()->toArray();

$useInterception = $settings['use_interception']??'0';
if($useInterception !== '1'){
    exit(0);
}

$agi        = new AGI();
$number     = $agi->request['agi_callerid'];
$extension  = $agi->request['agi_extension'];
$linkedId   = $agi->get_variable("CHANNEL(linkedid)", true);

$agent = new ModuleBitrix24GetResponsible();
$resposibleNumber = $agent->getResposibleNumber($number, $linkedId, $extension);
$agi->verbose("getResposibleNumber($number, $linkedId, $extension)");
if(!empty($resposibleNumber)){
    $agi->set_variable('B24_RESPONSIBLE_NUMBER', $resposibleNumber);
    $agi->set_variable('B24_RESPONSIBLE_TIMEOUT', $settings['interception_call_duration']??60);
    $agi->exec('Gosub', "b24-interception,{$extension},1");
}
