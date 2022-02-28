<?php
/**
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 4 2020
 */

namespace Modules\ModuleBitrix24Integration\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

class ModuleBitrix24Integration extends ModulesModelsBase
{
    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     * refresh_token
     *
     * @Column(type="string", nullable=true)
     */
    public $refresh_token;

    /**
     * Адрес портала b24.
     * @Column(type="string", nullable=true)
     */
    public $portal;

    /**
     * Данные сессии. Токены. Служебное поле.
     *
     * @Column(type="string", nullable=true)
     */
    public $session;

    /**
     * Выгружать данные позвонка в b24 в реальном времени. Будет открываться карточка клиента
     *
     * @Column(type="integer", nullable=true)
     */
    public $export_cdr;

    /**
     *
     * @Column(type="integer", nullable=true)
     */
    public $export_records;

    /**
     *
     * @Column(type="integer", nullable=true)
     */
    public $backgroundUpload;

    /**
     *
     * @Column(type="integer", nullable=true)
     */
    public $use_interception;

    /**
     *
     * @Column(type="integer", nullable=true)
     */
    public $interception_call_duration;

    /**
     *
     * @Column(type="string", nullable=true)
     */
    public $crmCreateLead = '1';

    /**
     *
     * @Column(type="integer", nullable=true)
     */
    public $responsibleMissedCalls = '';

    /**
     *
     * @Column(type="integer", nullable=true)
     */
    public $callbackQueue = '';

    /**
     * Bitrix24 region
     *
     * @Column(type="string", nullable=true)
     */
    public $b24_region;

    /**
     * Bitrix24 region
     *
     * @Column(type="string", nullable=true)
     */
    public $client_id;

    /**
     * Bitrix24 region
     *
     * @Column(type="string", nullable=true)
     */
    public $client_secret;



    public function initialize(): void
    {
        $this->setSource('m_ModuleBitrix24Integration');
        parent::initialize();
    }

    /**
     * Returns b24 regions
     *
     * @return array
     */
    public static function getAvailableRegions():array
    {
        return [
            'RUSSIA'=>[
                'CLIENT_ID'=>'app.5ea2ab337deab1.57263195',
                'CLIENT_SECRET'=>'XUMGJmFTgg2mjAnuZ0XykBODqToLT2f0HPDZagKP3HKtH6RT18',
            ],
            'BELARUS'=>[
                'CLIENT_ID'=>'app.609f6df4629d95.85311286',
                'CLIENT_SECRET'=>'cWNRrpmDzye1nqzE2lTjEcILaYl4iECo4h7LZfbzfUf8cuBU7G',
            ],
            'UKRAINE'=>[
                'CLIENT_ID'=>'app.609f6df4629d95.85311286',
                'CLIENT_SECRET'=>'cWNRrpmDzye1nqzE2lTjEcILaYl4iECo4h7LZfbzfUf8cuBU7G',
            ],
            'WORLD'=>[
                'CLIENT_ID'=>'app.609f6df4629d95.85311286',
                'CLIENT_SECRET'=>'cWNRrpmDzye1nqzE2lTjEcILaYl4iECo4h7LZfbzfUf8cuBU7G',
            ],
            'REST_API'=>[
                'CLIENT_ID'=>'',
                'CLIENT_SECRET'=>'',
            ],
        ];
    }

}