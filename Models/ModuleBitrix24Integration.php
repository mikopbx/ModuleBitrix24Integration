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
    public $export_records; // Выгружать в b24 записи разговоров.


    public function initialize(): void
    {
        $this->setSource('m_ModuleBitrix24Integration');
        parent::initialize();
    }

}