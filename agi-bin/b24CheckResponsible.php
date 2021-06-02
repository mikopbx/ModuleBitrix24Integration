#!/usr/bin/php
<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright (C) 2017-2020 Alexey Portnov and Nikolay Beketov
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

require_once 'Globals.php';

$agi        = new AGI();
$number     = $agi->request['agi_callerid'];
$extensions = $agi->request['agi_extension'];

$agent = new ModuleBitrix24GetResponsible();
$resposibleNumber = $agent->getResposibleNumber($number);
if(!empty($resposibleNumber)){
    $agi->set_variable('B24_RESPONSIBLE_NUMBER', $resposibleNumber);
    $agi->set_variable('B24_RESPONSIBLE_TIMEOUT', 60);
    $agi->exec('Gosub', "b24-interception,{$extensions},1");
}
