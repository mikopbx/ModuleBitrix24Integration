<?php
/**
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 4 2020
 */
namespace Modules\ModuleBitrix24Integration\App\Forms;

use Modules\ModuleBitrix24Integration\Models\ModuleBitrix24Integration;
use Phalcon\Forms\Element\Check;
use Phalcon\Forms\Element\Select;
use Phalcon\Forms\Element\Text;
use Phalcon\Forms\Form;


class ModuleBitrix24IntegrationForm extends Form
{

    public function initialize($entity = null, $options = null)
    {
        $this->add(new Text('portal'));
        $this->add(new Text('refresh_token'));

        // Export cdr
        $cheskarr = ['value' => null];
        if ($entity->export_cdr) {
            $cheskarr = ['checked' => 'checked', 'value' => null];
        }

        $this->add(new Check('export_cdr', $cheskarr));

        // Export records
        $cheskarr = ['value' => null];
        if ($entity->export_records) {
            $cheskarr = ['checked' => 'checked', 'value' => null];
        }

        $this->add(new Check('export_records', $cheskarr));


        // Region
        $regionsForSelect = [];
        foreach (ModuleBitrix24Integration::getAvailableRegions() as $region=>$keys){
            $regionsForSelect[$region]=$this->translation->_('mod_b24_i_region_'.$region);
        }

        $regions = new Select(
            'b24_region',
            $regionsForSelect
            , [
                'using'    => [
                    'id',
                    'name',
                ],
                'value'    => $entity->b24_region,
                'useEmpty' => false,
                'class'    => 'ui selection dropdown b24_regions-select',
            ]
        );
        $this->add($regions);

    }
}