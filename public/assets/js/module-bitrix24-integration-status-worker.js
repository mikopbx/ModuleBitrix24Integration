"use strict";

/*
 * MikoPBX - free phone system for small business
 * Copyright (C) 2017-2021 Alexey Portnov and Nikolay Beketov
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

/* global globalTranslate, Form, Config, PbxApi */

/**
 * Тестирование соединения модуля с Bitrix24
 */
var ModuleBitrix24IntegrationStatusWorker = {
  $moduleStatus: $('#status'),
  $statusToggle: $('#module-status-toggle'),
  $submitButton: $('#submitbutton'),
  $formObj: $('#module-bitrix24-integration-form'),
  timeOut: 3000,
  timeOutHandle: '',
  errorCounts: 0,
  initialize: function initialize() {
    ModuleBitrix24IntegrationStatusWorker.restartWorker();
  },
  restartWorker: function restartWorker() {
    ModuleBitrix24IntegrationStatusWorker.errorCounts = 0;
    ModuleBitrix24IntegrationStatusWorker.changeStatus('Updating');
    window.clearTimeout(ModuleBitrix24IntegrationStatusWorker.timeoutHandle);
    ModuleBitrix24IntegrationStatusWorker.worker();
  },
  worker: function worker() {
    if (ModuleBitrix24IntegrationStatusWorker.$statusToggle.checkbox('is checked')) {
      ModuleBitrix24IntegrationStatusWorker.testConnection();
    } else {
      ModuleBitrix24IntegrationStatusWorker.errorCounts = 0;
      ModuleBitrix24IntegrationStatusWorker.changeStatus('Disconnected');
    }
  },

  /**
   * Проверка соединения с сервером Bitrix24
   * @returns {boolean}
   */
  testConnection: function testConnection() {
    $.api({
      url: "".concat(globalRootUrl, "module-bitrix24-integration/checkState"),
      on: 'now',
      successTest: PbxApi.successTest,
      onComplete: function onComplete() {
        ModuleBitrix24IntegrationStatusWorker.timeoutHandle = window.setTimeout(ModuleBitrix24IntegrationStatusWorker.worker, ModuleBitrix24IntegrationStatusWorker.timeOut);
      },
      onSuccess: function onSuccess() {
        ModuleBitrix24IntegrationStatusWorker.changeStatus('Connected');
        ModuleBitrix24IntegrationStatusWorker.errorCounts = 0;
        ModuleBitrix24IntegrationStatusWorker.$formObj.removeClass('error');
      },
      onFailure: function onFailure() {
        ModuleBitrix24IntegrationStatusWorker.errorCounts++;

        if (ModuleBitrix24IntegrationStatusWorker.errorCounts > 3) {
          ModuleBitrix24IntegrationStatusWorker.changeStatus('ConnectionError');
        }
      },
      onResponse: function onResponse(response) {
        $('.message.ajax').remove();

        if (ModuleBitrix24IntegrationStatusWorker.errorCounts < 3) {
          return;
        } // Debug mode


        if (typeof response.data !== 'undefined') {
          var visualErrorString = JSON.stringify(response.messages, null, 2);

          if (typeof visualErrorString === 'string') {
            visualErrorString = visualErrorString.replace(/\n/g, '<br/>');
            visualErrorString = visualErrorString.replace(/[\[\]']+/g, '');

            if (Object.keys(response).length > 0 && response.result !== true) {
              ModuleBitrix24IntegrationStatusWorker.$moduleStatus.after("<div class=\"ui error icon message ajax\">\n\t\t\t\t\t\t\t\t\t<i class=\"exclamation circle icon\"></i>\n\t\t\t\t\t\t\t\t\t<div class=\"content\">\t\t\t\t\t\t\t\t\t\t\t\t\t\n\t\t\t\t\t\t\t\t\t\t<pre style='white-space: pre-wrap'>".concat(visualErrorString, "</pre>\n\t\t\t\t\t\t\t\t\t</div>\t\t\t\t\t\t\t\t\t\t  \n\t\t\t\t\t\t\t\t</div>"));
              ModuleBitrix24IntegrationStatusWorker.$formObj.addClass('error');
            }
          }
        }
      }
    });
  },

  /**
   * Updates module status on the right corner label
   * @param status
   */
  changeStatus: function changeStatus(status) {
    ModuleBitrix24IntegrationStatusWorker.$moduleStatus.removeClass('grey').removeClass('yellow').removeClass('green').removeClass('red');

    switch (status) {
      case 'Connected':
        ModuleBitrix24IntegrationStatusWorker.$moduleStatus.addClass('green').html(globalTranslate.mod_b24_i_Connected);
        break;

      case 'Disconnected':
        ModuleBitrix24IntegrationStatusWorker.$moduleStatus.addClass('grey').html(globalTranslate.mod_b24_i_Disconnected);
        break;

      case 'ConnectionError':
        ModuleBitrix24IntegrationStatusWorker.$moduleStatus.addClass('red').html(globalTranslate.mod_b24_i_StatusError);
        break;

      case 'Updating':
        ModuleBitrix24IntegrationStatusWorker.$moduleStatus.addClass('grey').html("<i class=\"spinner loading icon\"></i>".concat(globalTranslate.mod_b24_i_UpdateStatus));
        break;

      default:
        ModuleBitrix24IntegrationStatusWorker.$moduleStatus.addClass('red').html(globalTranslate.mod_b24_i_StatusError);
        break;
    }
  }
};
//# sourceMappingURL=module-bitrix24-integration-status-worker.js.map