<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2023 Alexey Portnov and Nikolay Beketov
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

use MikoPBX\Core\Asterisk\AsteriskManager as CoreAsteriskManager;
use Throwable;

/**
 * Custom AsteriskManager with guaranteed socket timeout and break support.
 *
 * Extends the core AsteriskManager to:
 * 1. Ensure stream_set_timeout is always applied (5s), preventing fgets() from blocking forever
 * 2. Override waitUserEvent() to support setBreak() — allows the worker's signal handler
 *    to interrupt the event loop within 5 seconds (socket timeout), so SIGUSR1 restarts
 *    don't hang indefinitely
 */
class AsteriskManager extends CoreAsteriskManager
{
    /** @var bool Flag to break out of waitUserEvent() loop on SHUTDOWN signal */
    private bool $shouldBreak = false;

    /**
     * Signal waitUserEvent() to exit on next iteration.
     * Called from WorkerBitrix24IntegrationAMI::signalHandler().
     */
    public function setBreak(): void
    {
        $this->shouldBreak = true;
    }

    /**
     * Connect to Asterisk with a reliable socket timeout.
     *
     * After parent::connect(), applies a 5-second read timeout on the socket.
     * This ensures fgets() in waitUserEvent() returns on dead connections
     * instead of blocking the worker forever.
     */
    public function connect(?string $server = null, ?string $username = null, ?string $secret = null, string $events = 'on'): bool
    {
        $result = parent::connect($server, $username, $secret, $events);
        if ($result && is_resource($this->socket)) {
            stream_set_timeout($this->socket, 5, 0);
        }
        return $result;
    }

    /**
     * Wait for user events with break support.
     *
     * Unlike parent's waitUserEvent(), this version checks $shouldBreak each iteration.
     * When setBreak() is called (from signal handler), the loop exits within max 5 seconds
     * (one socket timeout cycle). This prevents the worker from hanging in SHUTDOWN state.
     *
     * Also handles:
     * - Socket timeout → ping AMI → if dead, exit; if alive, continue
     * - ChanVariable multi-value parsing (same as core)
     *
     * @param bool $allow_timeout If true, return on AMI connection loss
     * @return array Event parameters, empty on timeout/break
     */
    public function waitUserEvent(bool $allow_timeout = false): array
    {
        $this->shouldBreak = false;
        $timeout = false;
        do {
            if ($this->shouldBreak) {
                break;
            }
            $type       = '';
            $parameters = [];
            $buffer = $this->readSocketLine();
            while ($buffer !== '') {
                $pos = strpos($buffer, ':');
                if ($pos) {
                    if (!count($parameters)) {
                        $type = strtolower(substr($buffer, 0, $pos));
                    }
                    $key      = substr($buffer, 0, $pos);
                    $newValue = substr($buffer, $pos + 2);
                    if ($key === 'ChanVariable') {
                        [$val_key, $val_value] = explode('=', $newValue);
                        if (!isset($parameters[$key])) {
                            $parameters[$key] = [];
                        }
                        $parameters[$key][$val_key] = $val_value;
                    } else {
                        $parameters[$key] = $newValue;
                    }
                }
                $buffer = $this->readSocketLine();
            }
            if ($type === '' && count($this->ping()) === 0) {
                $timeout = $allow_timeout;
            } elseif (stripos($type, 'event') !== false) {
                $this->processEvent($parameters);
            }
        } while (!$timeout);

        return $parameters;
    }

    /**
     * Read a single line from the AMI socket.
     *
     * Replaces parent's private getStringDataFromSocket() which can't be called
     * from child class. Returns empty string on error, timeout, or closed socket.
     *
     * @return string Trimmed line or empty string
     */
    private function readSocketLine(): string
    {
        if (!is_resource($this->socket)) {
            return '';
        }
        try {
            $result = fgets($this->socket, 4096);
            return ($result !== false) ? trim($result) : '';
        } catch (Throwable $e) {
            return '';
        }
    }
}
