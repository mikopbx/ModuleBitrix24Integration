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

namespace Modules\ModuleBitrix24Integration\Lib;

use Cesargb\Log\Exceptions\RotationFailed;
use Cesargb\Log\Rotation;
use MikoPBX\Core\System\Directories;
use MikoPBX\Core\System\SystemMessages;
use MikoPBX\Core\System\Util;
use Phalcon\Logger\Adapter\Stream;

require_once('Globals.php');
require_once(dirname(__DIR__) . '/vendor/autoload.php');

class Logger
{
    public bool $debug;
    private $logger;
    private string $module_name;
    private string $logFile;
    private int $lastRotateCheckTs = 0;

    /**
     * Logger constructor.
     *
     * @param string $class
     * @param string $module_name
     */
    public function __construct(string $class, string $module_name)
    {
        $this->module_name = $module_name;
        $this->debug = true;
        $logPath = Directories::getDir(Directories::CORE_LOGS_DIR) . '/' . $this->module_name . '/';
        if (!file_exists($logPath)) {
            Util::mwMkdir($logPath);
            Util::addRegularWWWRights($logPath);
        }
        $this->logFile = $logPath . $class . '.log';
        $this->initLogger();
    }

    /**
     * Инициализация логгера.
     * @return void
     */
    private function initLogger(): void
    {
        if (!file_exists($this->logFile)) {
            file_put_contents($this->logFile, '');
        }
        Util::addRegularWWWRights($this->logFile);
        $adapter = new Stream($this->logFile);

        $loggerClass = MikoPBXVersion::getLoggerClass();


        $this->logger = new $loggerClass(
            'messages',
            [
                'main' => $adapter,
            ]
        );


    }

    public function rotate(): void
    {
        // Throttle rotation checks to reduce overhead in tight loops (fixed interval).
        $rotateInterval = 30;
        $now = time();
        if ($this->lastRotateCheckTs !== 0 && ($now - $this->lastRotateCheckTs) < $rotateInterval) {
            return;
        }
        $this->lastRotateCheckTs = $now;
        $rotation = new Rotation([
            'files'    => 4,
            'compress' => false,
            'min-size' => 10 * 1024 * 1024,
            'truncate' => false,
            'catch'    => function (RotationFailed $exception) {
                SystemMessages::sysLogMsg($this->module_name, $exception->getMessage());
            },
        ]);
        if ($rotation->rotate($this->logFile)) {
            $this->initLogger();
            $this->cleanupOrphanedLogs();
        }
    }

    /**
     * Удаляет осиротевшие .gz и .0 файлы, которые Cesargb не отслеживает.
     */
    private function cleanupOrphanedLogs(): void
    {
        $pattern = $this->logFile . '.*';
        $files = glob($pattern);
        if (!is_array($files)) {
            return;
        }
        foreach ($files as $file) {
            $basename = basename($file);
            // Удаляем .gz файлы (остатки от compress=true) и .0 файлы (от другой ротации)
            if (preg_match('/\.\d+\.gz$/', $basename) || preg_match('/\.0$/', $basename)) {
                unlink($file);
            }
            // Удаляем файлы с номером > 4 (старые бэкапы от files=9)
            if (preg_match('/\.(\d+)$/', $basename, $m) && (int)$m[1] > 4) {
                unlink($file);
            }
        }
    }

    public function writeError($data, string $header = ''): void
    {
        $this->rotate();
        if ($this->debug) {
            if(!empty($header)){
                $header.= '('.posix_getpid()."): ";
            }
            $this->logger->error($header . $this->getDecodedString($data));
        }
    }

    public function writeInfo($data, string $header = ''): void
    {
        $this->rotate();
        if ($this->debug) {
            if(!empty($header)){
                $header.= '('.posix_getpid()."): ";
            }
            $this->logger->info($header . $this->getDecodedString($data));
        }
    }

    private function getDecodedString($data): string
    {
        $printedData = json_encode($data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if (is_bool($printedData)) {
            $result = '';
        } else {
            $result = urldecode($printedData);
        }
        return $result;
    }
}