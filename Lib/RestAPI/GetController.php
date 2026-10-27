<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2025 Alexey Portnov and Nikolay Beketov
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

namespace Modules\ModuleBitrix24Integration\Lib\RestAPI;
use MikoPBX\PBXCoreREST\Controllers\Modules\ModulesControllerBase;

class GetController extends ModulesControllerBase
{
    /**
     * http://127.0.0.1/pbxcore/api/bitrix-integration/workers/state
     */
    public function getWorkerState(): void
    {
        $this->callActionForModule('ModuleBitrix24Integration', 'STATE');
        $this->response->sendRaw();
    }

    /**
     * http://127.0.0.1/pbxcore/api/bitrix-integration/links-sync/state
     */
    public function getLinksSyncState(): void
    {
        $this->callActionForModule('ModuleBitrix24Integration', 'LINKS_SYNC_STATE');
        $this->response->sendRaw();
    }

    /**
     * http://127.0.0.1/pbxcore/api/bitrix-integration/cache-rebuild/state
     */
    public function getCacheRebuildState(): void
    {
        $this->callActionForModule('ModuleBitrix24Integration', 'CACHE_REBUILD_STATE');
        $this->response->sendRaw();
    }
}