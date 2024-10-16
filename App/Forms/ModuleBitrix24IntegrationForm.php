<?php

/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2024 Alexey Portnov and Nikolay Beketov
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

namespace Modules\ModuleBitrix24Integration\App\Forms;

use Modules\ModuleBitrix24Integration\Lib\Bitrix24Integration;
use Modules\ModuleBitrix24Integration\Models\ModuleBitrix24Integration;
use Phalcon\Forms\Element\Check;
use Phalcon\Forms\Element\Hidden;
use Phalcon\Forms\Element\Numeric;
use Phalcon\Forms\Element\Password;
use Phalcon\Forms\Element\Select;
use Phalcon\Forms\Element\Text;
use Phalcon\Forms\Form;

class ModuleBitrix24IntegrationForm extends Form
{
    public function initialize($entity = null, $options = null): void
    {
        $this->add(new Text('portal'));
        $this->add(new Text('refresh_token'));
        $this->add(new Text('client_id'));
        $this->add(new Password('client_secret'));
        $this->add(new Hidden('modify'));

        // CheckBoxes
        $this->addCheckBox('export_cdr', intval($entity->export_cdr) === 1);
        $this->addCheckBox('crmCreateLead', intval($entity->crmCreateLead) === 1);
        $this->addCheckBox('backgroundUpload', intval($entity->backgroundUpload) === 1);
        $this->addCheckBox('export_records', intval($entity->export_records) === 1);
        $this->addCheckBox('use_interception', intval($entity->use_interception) === 1);

        // Numeric
        $this->add(new Numeric('interception_call_duration'));

        // Region
        $regionsForSelect = [];
        foreach (ModuleBitrix24Integration::getAvailableRegions() as $region => $keys) {
            $regionsForSelect[$region] = $this->translation->_('mod_b24_i_region_' . $region);
        }

        $this->add(new Select('b24_region', $regionsForSelect, [
                 'using'    => [
                     'id',
                     'name',
                 ],
                 'value'    => $entity->b24_region,
                 'useEmpty' => false,
                 'class'    => 'ui selection dropdown b24_regions-select',
             ]));
        $this->add(new Select('callbackQueue', $options['queues'], [
            'using'    => [
                'id',
                'name',
            ],
            'useEmpty' => true,
            'class'    => 'ui search dropdown',
        ]));
        $this->add(new Select('responsibleMissedCalls', $options['users'], [
            'using'    => [
                'number',
                'callerid',
            ],
            'useEmpty' => true,
            'class'    => 'ui search dropdown',
        ]));



        $leadType = [
            Bitrix24Integration::API_LEAD_TYPE_ALL => $this->translation->_('mod_b24_i_lead_type_' . Bitrix24Integration::API_LEAD_TYPE_ALL),
            Bitrix24Integration::API_LEAD_TYPE_IN => $this->translation->_('mod_b24_i_lead_type_' . Bitrix24Integration::API_LEAD_TYPE_IN),
            Bitrix24Integration::API_LEAD_TYPE_OUT => $this->translation->_('mod_b24_i_lead_type_' . Bitrix24Integration::API_LEAD_TYPE_OUT)
        ];

        $this->add(new Select('leadType', $leadType, [
                                  'using'    => [
                                      'id',
                                      'name',
                                  ],
                                  'value'    => empty($entity->leadType) ? Bitrix24Integration::API_LEAD_TYPE_ALL : $entity->leadType,
                                  'useEmpty' => false,
                                  'class'    => 'ui selection dropdown b24_regions-select',
                              ]));
    }
    /**
     * Adds a checkbox to the form field with the given name.
     * Can be deleted if the module depends on MikoPBX later than 2024.3.0
     *
     * @param string $fieldName The name of the form field.
     * @param bool $checked Indicates whether the checkbox is checked by default.
     * @param string $checkedValue The value assigned to the checkbox when it is checked.
     * @return void
     */
    public function addCheckBox(string $fieldName, bool $checked, string $checkedValue = 'on'): void
    {
        $checkAr = ['value' => null];
        if ($checked) {
            $checkAr = ['checked' => $checkedValue,'value' => $checkedValue];
        }
        $this->add(new Check($fieldName, $checkAr));
    }
}
