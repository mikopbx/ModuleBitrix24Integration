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

use MikoPBX\Common\Models\PbxSettings;
use MikoPBX\Common\Providers\ConfigProvider;
use MikoPBX\Core\System\Util;
use Phalcon\Cache\Adapter\Redis;
use Phalcon\Storage\SerializerFactory;

class CacheManager
{
    public const REDIS_PREFIX       = 'b24_token_';

    /**
     * Возвращает адаптер для подключения к Redis.
     * @return Redis
     */
    public static function cacheAdapter():Redis
    {
        $serializerFactory = new SerializerFactory();

        $pbxVersion = PbxSettings::getValueByKey('PBXVersion');
        if (version_compare($pbxVersion, '2024.2.30', '>')) {
            $di     = \Phalcon\Di\Di::getDefault();
        } else {
            $di     = \Phalcon\Di::getDefault();
        }

        $options = [
            'defaultSerializer' => 'Php',
            'lifetime'          => 86400,
            'index'             => 3,
            'prefix'            => self::REDIS_PREFIX
        ];
        if($di !== null){
            $config          = $di->getShared(ConfigProvider::SERVICE_NAME);
            $options['host'] = $config->path('redis.host');
            $options['port'] = $config->path('redis.port');
        }
        return (new Redis($serializerFactory, $options));
    }

    /**
     * Сохранение кэш в redis
     * @param $key
     * @param $value
     * @param int $ttl
     * @return void
     */
    public static function setCacheData($key, $value, int $ttl = 86400):void
    {
        $cacheAdapter = self::cacheAdapter();
        try {
            $cacheAdapter->set($key, $value, $ttl);
        }catch (\Throwable $e){
            Util::sysLogMsg(self::class, $e->getMessage());
        }
    }

    /**
     * Получение данных из кэш
     * @param $key
     * @return array
     */
    public static function getCacheData($key):array
    {
        $result = [];
        $cacheAdapter = self::cacheAdapter();
        try {
            $result = $cacheAdapter->get($key);
            $result = (array)$result;
        }catch (\Throwable $e){
            Util::sysLogMsg(self::class, $e->getMessage());
        }
        if(empty($result)){
            $result = [];
        }
        return $result;
    }

}