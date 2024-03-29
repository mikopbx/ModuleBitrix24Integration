<?php
/**
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 4 2020
 */
namespace Modules\ModuleBitrix24Integration\App\Forms;

use Modules\ModuleBitrix24Integration\Lib\Bitrix24Integration;
use Modules\ModuleBitrix24Integration\Models\ModuleBitrix24Integration;
use Phalcon\Forms\Element\AbstractElement;
use Phalcon\Forms\Element\Check;
use Phalcon\Forms\Element\Hidden;
use Phalcon\Forms\Element\Numeric;
use Phalcon\Forms\Element\Password;
use Phalcon\Forms\Element\Select;
use Phalcon\Forms\Element\Text;
use Phalcon\Forms\Form;


class ModuleBitrix24IntegrationForm extends Form
{

    public function initialize($entity = null, $options = null):void
    {
        $this->add(new Text('portal'));
        $this->add(new Text('refresh_token'));
        $this->add(new Text('client_id'));
        $this->add(new Password('client_secret'));
        $this->add(new Hidden('modify'));

        // Export cdr
        $cheskarr = ['value' => null];
        if ($entity->export_cdr) {
            $cheskarr = ['checked' => 'checked', 'value' => null];
        }
        $crmCreateLead = ['value' => null];
        if ($entity->crmCreateLead !== '0') {
            $crmCreateLead = ['checked' => 'checked', 'value' => null];
        }
        $backgroundUpload = ['value' => null];
        if ($entity->backgroundUpload === '1') {
            $backgroundUpload = ['checked' => 'checked', 'value' => null];
        }
        $this->add(new Check('export_cdr', $cheskarr));
        $this->add(new Check('crmCreateLead', $crmCreateLead));
        $this->add(new Check('backgroundUpload', $backgroundUpload));

        // Export records
        $cheskarr = ['value' => null];
        if ($entity->export_records) {
            $cheskarr = ['checked' => 'checked', 'value' => null];
        }

        $this->add(new Check('export_records', $cheskarr));

        $cheskarr = ['value' => null];
        if ($entity->use_interception) {
            $cheskarr = ['checked' => 'checked', 'value' => null];
        }
        $this->add(new Check('use_interception', $cheskarr));
        $this->add(new Numeric('interception_call_duration'));

        // Region
        $regionsForSelect = [];
        foreach (ModuleBitrix24Integration::getAvailableRegions() as $region=>$keys){
            $regionsForSelect[$region]=$this->translation->_('mod_b24_i_region_'.$region);
        }

        $this->add(new Select('b24_region', $regionsForSelect, [
                 'using'    => [
                     'id',
                     'name',
                 ],
                 'value'    => $entity->b24_region,
                 'useEmpty' => false,
                 'class'    => 'ui selection dropdown b24_regions-select',
             ]
        ));
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
            Bitrix24Integration::API_LEAD_TYPE_ALL => $this->translation->_('mod_b24_i_lead_type_'.Bitrix24Integration::API_LEAD_TYPE_ALL),
            Bitrix24Integration::API_LEAD_TYPE_IN => $this->translation->_('mod_b24_i_lead_type_'.Bitrix24Integration::API_LEAD_TYPE_IN),
            Bitrix24Integration::API_LEAD_TYPE_OUT => $this->translation->_('mod_b24_i_lead_type_'.Bitrix24Integration::API_LEAD_TYPE_OUT)
        ];

        $this->add(new Select('leadType', $leadType, [
                                  'using'    => [
                                      'id',
                                      'name',
                                  ],
                                  'value'    => empty($entity->leadType)?Bitrix24Integration::API_LEAD_TYPE_ALL:$entity->leadType,
                                  'useEmpty' => false,
                                  'class'    => 'ui selection dropdown b24_regions-select',
                              ]
       ));

    }
}