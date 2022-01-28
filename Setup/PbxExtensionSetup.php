<?php
/**
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 4 2020
 */


namespace Modules\ModuleBitrix24Integration\Setup;

use MikoPBX\Common\Models\PbxSettings;
use Modules\ModuleBitrix24Integration\Models\ModuleBitrix24Integration;
use MikoPBX\Modules\Setup\PbxExtensionSetupBase;
use Phalcon\Mvc\Model;

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
        // Создаем базу данных
        $result = $this->createSettingsTableByModelsAnnotations();

        // Регаем модуль в PBX Extensions
        if ($result) {
            $result = $this->registerNewModule();
        }

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
     * Выполняет активацию триалов, проверку лицензионного клчюча
     *
     * @return bool результат активации лицензии
     */
    public function activateLicense(): bool
    {
        $lic = PbxSettings::getValueByKey('PBXLicense');
        if (empty($lic)) {
            $this->messages[] = 'License key not found...';
            return false;
        }
        // Получение пробной лицензии. Продукт "Bitrix24Integration".
        $this->license->addtrial('31');

        return true;
    }

}