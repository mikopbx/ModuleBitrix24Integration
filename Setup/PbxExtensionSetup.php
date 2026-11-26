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

namespace Modules\ModuleBitrix24Integration\Setup;

use MikoPBX\Common\Models\PbxSettings;
use MikoPBX\Core\System\Directories;
use MikoPBX\Core\System\SystemMessages;
use Modules\ModuleBitrix24Integration\Models\ContactLinks;
use Modules\ModuleBitrix24Integration\Models\ModuleBitrix24Integration;
use MikoPBX\Modules\Setup\PbxExtensionSetupBase;

class PbxExtensionSetup extends PbxExtensionSetupBase
{
    /**
     * Размер, выше которого обычный (не сжатый) ротированный лог считается
     * следом прежней сломанной ротации. Нормальный кусок — чуть больше 10 МБ.
     */
    private const OVERSIZED_LOG_BYTES = 50 * 1048576;

    /**
     * Создает структуру для хранения настроек модуля в своей модели
     * и заполняет настройки по-умолчанию если таблицы не было в системе
     * см (unInstallDB)
     *
     * Регистрирует модуль в PbxExtensionModules
     *
     * @return bool результат установки
     */
    public function installDB(): bool
    {
        $result = parent::installDB();
        if ($result) {
            $this->transferOldSettings();
        }
        if ($result) {
            $settings = ModuleBitrix24Integration::findFirst();
            if ($settings === null) {
                $settings = new ModuleBitrix24Integration();
            }
            if (empty($settings->b24_region)) {
                $settings->b24_region = 'RUSSIA';
            }
            if (empty($settings->logLevel)) {
                $settings->logLevel = ModuleBitrix24Integration::LOG_LEVEL_INFO;
            }
            // Синхронная загрузка записей удалена — режим всегда фоновый.
            $settings->backgroundUpload = '1';
            $result = $settings->save();
        }
        if ($result) {
            $this->cleanupOversizedRotatedLogs();
        }

        return $result;
    }

    /**
     * Удаляет следы прежней (сломанной) ротации логов модуля.
     *
     * Раньше модуль ротировал логи сам, переименованием. Пока воркер не
     * перезапустится, он дописывал в переименованный файл всю свою жизнь: у клиента
     * так выросли IntegrationAMI.log.1 (1,17 ГБ) и .2 (962 МБ). Ротирует теперь
     * ядро, а эти файлы никто не убирает — его очистка срабатывает только при
     * нехватке места. Удаляем обычные *.log.N крупнее OVERSIZED_LOG_BYTES; сжатые
     * *.log.N.gz и текущий *.log.0 принадлежат logrotate ядра, их не трогаем.
     *
     * @return void
     */
    private function cleanupOversizedRotatedLogs(): void
    {
        $logDir = Directories::getDir(Directories::CORE_LOGS_DIR) . '/' . $this->moduleUniqueID;
        if (!is_dir($logDir)) {
            return;
        }
        $removed = 0;
        $freed   = 0;
        $files   = glob($logDir . '/*.log.[1-9]*', GLOB_NOSORT);
        foreach (is_array($files) ? $files : [] as $file) {
            if (!preg_match('/\.log\.\d+$/', basename($file))) {
                continue;
            }
            $size = @filesize($file);
            if ($size === false || $size <= self::OVERSIZED_LOG_BYTES) {
                continue;
            }
            if (@unlink($file)) {
                $removed++;
                $freed += $size;
            }
        }
        if ($removed > 0) {
            SystemMessages::sysLogMsg(
                $this->moduleUniqueID,
                'Removed ' . $removed . ' oversized rotated log file(s), ' . round($freed / 1048576) . ' MB (left by the old rotation).',
                LOG_NOTICE
            );
        }
    }

    /**
     *  Transfer settings from db to own module database
     */
    protected function transferOldSettings(): void
    {
        if ( ! $this->db->tableExists('m_ModuleBitrix24Integration')) {
            return;
        }
        $oldSettings = $this->db->fetchOne('Select * from m_ModuleBitrix24Integration', \Phalcon\Db\Enum::FETCH_ASSOC);

        $settings = ModuleBitrix24Integration::findFirst();
        if ($settings === null) {
            $settings = new ModuleBitrix24Integration();
        }
        foreach ($settings as $key => $value) {
            if (isset($oldSettings[$key])) {
                $settings->$key = $oldSettings[$key];
            }
        }

        if ($settings->save()) {
            $this->db->dropTable('m_ModuleBitrix24Integration');
        } else {
            $this->messges[] = "Error on transfer old settings for $this->moduleUniqueID";
        }
    }

    /**
     * Adds the module to the sidebar menu.
     * @see https://docs.mikopbx.com/mikopbx-development/module-developement/module-installer#addtosidebar
     *
     * @return bool The result of the addition process.
     */
    public function addToSidebar(): bool
    {
        $menuSettingsKey           = "AdditionalMenuItem{$this->moduleUniqueID}";
        $menuSettings              = PbxSettings::findFirstByKey($menuSettingsKey);
        if ($menuSettings === null) {
            $menuSettings      = new PbxSettings();
            $menuSettings->key = $menuSettingsKey;
        }
        $value               = [
            'uniqid'        => $this->moduleUniqueID,
            'group'         => 'integrations',
            'iconClass'     => 'puzzle',
            'caption'       => "Breadcrumb{$this->moduleUniqueID}",
            'showAtSidebar' => true,
        ];
        $menuSettings->value = json_encode($value);

        return $menuSettings->save();
    }

}