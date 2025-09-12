<?php
/**
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 4 2020
 */

namespace Modules\ModuleBitrix24Integration\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;
use Modules\ModuleBitrix24Integration\Lib\Constants;

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
     * @Column(type="integer", nullable=true, default="1")
     */
    public $export_cdr = "1";

    /**
     *
     * @Column(type="integer", nullable=true, default="1")
     */
    public $export_records = "1";

    /**
     *
     * @Column(type="integer", nullable=true, default="1")
     */
    public $backgroundUpload = "1";

    /**
     *
     * @Column(type="integer", nullable=true, default="0")
     */
    public $use_interception = "0";

    /**
     *
     * @Column(type="integer", nullable=true, default="20")
     */
    public $interception_call_duration = "20";

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
     * @Column(type="string", nullable=true)
     */
    public $callbackQueue = '';

    /**
     * Bitrix24 region
     *
     * @Column(type="string", nullable=true)
     */
    public $b24_region;

    /**
     * Bitrix24 leadType
     *
     * @Column(type="string", nullable=true)
     */
    public $leadType;

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

    /**
     *
     * @Column(type="string", nullable=true)
     */
    public $lastContactId;

    /**
     *
     * @Column(type="string", nullable=true)
     */
    public $lastCompanyId;

    /**
     *
     * @Column(type="string", nullable=true)
     */
    public $lastLeadId;


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
                'CLIENT_ID'=>Constants::VAL_CLIENT_ID_RU,
                'CLIENT_SECRET'=>Constants::VAL_CLIENT_SECRET_RU,
            ],
            'BELARUS'=>[
                'CLIENT_ID'=>Constants::VAL_CLIENT_ID_BEL,
                'CLIENT_SECRET'=>Constants::VAL_CLIENT_SECRET_BEL,
            ],
            'KAZAKHSTAN'=>[
                'CLIENT_ID'=>Constants::VAL_CLIENT_ID_KZ,
                'CLIENT_SECRET'=>Constants::VAL_CLIENT_SECRET_KZ,
            ],
            /*
            'UKRAINE'=>[
                'CLIENT_ID'=>Constants::VAL_CLIENT_ID_W,
                'CLIENT_SECRET'=>Constants::VAL_CLIENT_SECRET_W,
            ],//*/
            'WORLD'=>[
                'CLIENT_ID'=>Constants::VAL_CLIENT_ID_RU,
                'CLIENT_SECRET'=>Constants::VAL_CLIENT_SECRET_RU,
            ],
            'REST_API'=>[
                'CLIENT_ID'=>'',
                'CLIENT_SECRET'=>'',
            ],
        ];
    }

}