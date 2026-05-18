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
    public const LEVEL_NONE  = 0;
    public const LEVEL_ERROR = 1;
    public const LEVEL_INFO  = 2;
    public const LEVEL_DEBUG = 3;

    /**
     * Безопасный максимум для одной записи в лог. Большие данные обрезаются,
     * чтобы json_encode не съел всю PHP-память (по умолчанию memory_limit = 128M).
     */
    private const MAX_PAYLOAD_BYTES = 512 * 1024;

    /**
     * Совместимость с прежним кодом, где встречается обращение к публичному
     * полю $debug. Теперь оно отражает: "уровень ≥ INFO".
     */
    public bool $debug = true;

    private int $level = self::LEVEL_INFO;
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
        $logPath = Directories::getDir(Directories::CORE_LOGS_DIR) . '/' . $this->module_name . '/';
        if (!file_exists($logPath)) {
            Util::mwMkdir($logPath);
            Util::addRegularWWWRights($logPath);
        }
        $this->logFile = $logPath . $class . '.log';
        $this->initLogger();
    }

    /**
     * Преобразует строковое имя уровня (NONE/ERROR/INFO/DEBUG) во внутренний int.
     */
    public static function resolveLevel(?string $name): int
    {
        switch (strtoupper((string)$name)) {
            case 'NONE':  return self::LEVEL_NONE;
            case 'ERROR': return self::LEVEL_ERROR;
            case 'DEBUG': return self::LEVEL_DEBUG;
            case 'INFO':
            default:      return self::LEVEL_INFO;
        }
    }

    /**
     * Установить уровень логирования. Принимает строку (NONE/ERROR/INFO/DEBUG)
     * или int-константу LEVEL_*.
     *
     * @param int|string $level
     */
    public function setLevel($level): void
    {
        $this->level = is_int($level) ? $level : self::resolveLevel($level);
        $this->debug = $this->level >= self::LEVEL_INFO;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function isDebugEnabled(): bool
    {
        return $this->level >= self::LEVEL_DEBUG;
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
        if ($this->level < self::LEVEL_ERROR) {
            return;
        }
        $this->rotate();
        if (!empty($header)) {
            $header .= '(' . posix_getpid() . "): ";
        }
        $this->logger->error($header . $this->getDecodedString($data));
    }

    public function writeInfo($data, string $header = ''): void
    {
        if ($this->level < self::LEVEL_INFO) {
            return;
        }
        $this->rotate();
        if (!empty($header)) {
            $header .= '(' . posix_getpid() . "): ";
        }
        $this->logger->info($header . $this->getDecodedString($data));
    }

    /**
     * Подробная запись — выводится только при уровне DEBUG. Используется для
     * больших payload'ов (полный JSON ответа CRM), которые на INFO дают
     * только summary.
     */
    public function writeDebug($data, string $header = ''): void
    {
        if ($this->level < self::LEVEL_DEBUG) {
            return;
        }
        $this->rotate();
        if (!empty($header)) {
            $header .= '(' . posix_getpid() . "): ";
        }
        $this->logger->debug($header . $this->getDecodedString($data));
    }

    /**
     * Сериализует данные для лога с защитой от перегрузки памяти.
     *
     * Раньше использовалась цепочка json_encode + urldecode, которая
     * удваивала пиковую память (urldecode возвращает новую строку даже
     * если декодировать нечего). При больших payload'ах из batch CRM это
     * приводило к OOM (см. Sentry MIKOPBX-MH7).
     *
     * Теперь:
     *  - сразу проверяем грубую оценку размера через strlen на строках;
     *  - для массивов/объектов кодируем без urldecode и с лимитом в байтах;
     *  - при превышении лимита возвращаем обрезанную строку с маркером.
     */
    private function getDecodedString($data): string
    {
        if (is_string($data)) {
            $len = strlen($data);
            return $len > self::MAX_PAYLOAD_BYTES
                ? substr($data, 0, self::MAX_PAYLOAD_BYTES) . '...[truncated, ' . $len . ' bytes]'
                : $data;
        }
        if (is_scalar($data) || $data === null) {
            return (string)$data;
        }
        $printedData = json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if (!is_string($printedData)) {
            return '';
        }
        $len = strlen($printedData);
        if ($len > self::MAX_PAYLOAD_BYTES) {
            return substr($printedData, 0, self::MAX_PAYLOAD_BYTES)
                . '...[truncated, ' . $len . ' bytes]';
        }
        return $printedData;
    }
}
