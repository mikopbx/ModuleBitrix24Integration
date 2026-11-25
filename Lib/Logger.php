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

use MikoPBX\Core\System\Directories;
use MikoPBX\Core\System\SystemMessages;
use MikoPBX\Core\System\Util;
use Phalcon\Logger\Adapter\Stream;
use Throwable;

require_once('Globals.php');

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
     * Как часто проверять, что открытый файл лога всё ещё является текущим.
     */
    private const REOPEN_CHECK_INTERVAL = 30;

    /**
     * Ключи, значения которых не должны попадать в лог в открытом виде.
     * Логи регулярно забирают у клиентов для разбора — вместе с ними уходили бы
     * действующие токены портала. Соответствует ключу в JSON ("auth":"…"),
     * в query-строке (&auth=…) и в экранированном JSON внутри строки (\"auth\":\"…\").
     */
    private const SECRET_KEY_REGEX = '(?<![A-Za-z0-9_])(?:access_token|refresh_token|client_secret|auth|token)(?![A-Za-z0-9_-])\\\\?["\']?\s*[:=]\s*\\\\?["\']?';

    /**
     * Значение секрета. Короткие значения (маски, статусы) не трогаем.
     */
    private const SECRET_VALUE_REGEX = '[A-Za-z0-9._~+%\/=\-]{8,}';

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
    private ?int $logInode = null;

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
        clearstatcache(true, $this->logFile);
        if (!file_exists($this->logFile)) {
            file_put_contents($this->logFile, '');
        }
        Util::addRegularWWWRights($this->logFile);

        $loggerClass = MikoPBXVersion::getLoggerClass();

        // Inode берём ДО открытия, а не после: если файл переименует внешняя
        // ротация между stat и open, мы запомним старый inode, откроем новый —
        // и на следующей проверке лишний раз переоткроем тот же файл. Обратный
        // порядок мог бы скрыть устаревший дескриптор до следующей ротации.
        clearstatcache(true, $this->logFile);
        $inode = @fileinode($this->logFile);

        $this->logger = new $loggerClass(
            'messages',
            [
                'main' => new Stream($this->logFile),
            ]
        );

        // Запоминаем inode только после успешного открытия: иначе при ошибке
        // открытия новый inode был бы записан при старом дескрипторе, и проверка
        // в rotate() больше не заметила бы расхождения.
        $this->logInode = ($inode === false) ? null : $inode;
    }

    /**
     * Следит за тем, чтобы записи уходили в текущий файл лога.
     *
     * Сам модуль логи не ротирует — это делает ядро (ежечасный logrotate по всем
     * *.log в каталоге модуля). Задача метода — не отстать от ядра:
     *  - copytruncate (прошивки с 07.2026) обрезает тот же файл, inode не меняется;
     *  - переименование (старые прошивки, а также ручной mv) оставляет у воркера
     *    открытый дескриптор переименованного файла. Без переоткрытия воркер
     *    дописывал в него всю свою жизнь — так вырастали файлы в гигабайты.
     *
     * Имя сохранено ради точек вызова (rotate() зовут воркеры в основных циклах).
     * @return void
     */
    public function rotate(): void
    {
        $now = time();
        if ($this->lastRotateCheckTs !== 0 && ($now - $this->lastRotateCheckTs) < self::REOPEN_CHECK_INTERVAL) {
            return;
        }
        $this->lastRotateCheckTs = $now;

        // clearstatcache обязателен: на PHP <= 8.2 дописывание файла не сбрасывает
        // кеш stat, и file_exists()/fileinode() отдавали бы устаревшие данные.
        clearstatcache(true, $this->logFile);
        if (!file_exists($this->logFile)) {
            $this->reopenLogSafely();
            return;
        }
        $inode = @fileinode($this->logFile);
        if ($inode !== false && $this->logInode !== null && $inode !== $this->logInode) {
            $this->reopenLogSafely();
        }
    }

    /**
     * Переоткрывает файл лога, не роняя воркер, если открыть не получилось:
     * тогда пишем дальше в прежний дескриптор и сообщаем в syslog.
     * @return void
     */
    private function reopenLogSafely(): void
    {
        $logDir = dirname($this->logFile);
        // mkdir напрямую: Util::mwMkdir() создаёт каталоги только от root,
        // а воркеры модуля работают от www.
        if (!is_dir($logDir) && !@mkdir($logDir, 0777, true) && !is_dir($logDir)) {
            SystemMessages::sysLogMsg($this->module_name, 'Cannot recreate log directory: ' . $logDir, LOG_WARNING);
            return;
        }
        if (!is_writable($this->logFile) && file_exists($this->logFile)) {
            SystemMessages::sysLogMsg($this->module_name, 'Log file is not writable: ' . $this->logFile, LOG_WARNING);
            return;
        }
        try {
            $this->initLogger();
        } catch (Throwable $e) {
            SystemMessages::sysLogMsg(
                $this->module_name,
                'Cannot reopen log file ' . $this->logFile . ': ' . $e->getMessage(),
                LOG_WARNING
            );
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
            return $this->maskSecrets(
                $len > self::MAX_PAYLOAD_BYTES
                    ? substr($data, 0, self::MAX_PAYLOAD_BYTES) . '...[truncated, ' . $len . ' bytes]'
                    : $data
            );
        }
        if (is_scalar($data) || $data === null) {
            return $this->maskSecrets((string)$data);
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
            $printedData = substr($printedData, 0, self::MAX_PAYLOAD_BYTES)
                . '...[truncated, ' . $len . ' bytes]';
        }
        return $this->maskSecrets($printedData);
    }

    /**
     * Заменяет значения токенов в тексте лога на первые 6 символов + «…».
     * Префикс оставлен намеренно: по нему видно, сменился ли токен между
     * записями (разбор шторма обновлений токена), не раскрывая сам токен.
     *
     * @param string $text
     * @return string
     */
    private function maskSecrets(string $text): string
    {
        if ($text === '') {
            return $text;
        }
        // Быстрый предфильтр: preg_replace_callback по каждому сообщению не нужен.
        if (strpos($text, 'token') === false && strpos($text, 'auth') === false && strpos($text, 'client_secret') === false) {
            return $text;
        }
        $result = preg_replace_callback(
            '/(' . self::SECRET_KEY_REGEX . ')(' . self::SECRET_VALUE_REGEX . ')/',
            static function (array $match): string {
                return $match[1] . substr($match[2], 0, 6) . '…';
            },
            $text
        );
        return is_string($result) ? $result : $text;
    }
}
