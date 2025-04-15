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
use Modules\ModuleBitrix24Integration\Models\ContactLinks;
use Modules\ModuleBitrix24Integration\Models\ModuleBitrix24Integration;
use MikoPBX\Modules\Setup\PbxExtensionSetupBase;

class PbxExtensionSetup extends PbxExtensionSetupBase
{

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
            if(!ContactLinks::findFirst()){
                $settings->lastCompanyId = 0;
                $settings->lastContactId = 0;
            }
            $result = $settings->save();
        }

        return $result;
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