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
      url: "".concat(Config.pbxUrl, "/pbxcore/api/modules/ModuleBitrix24Integration/check"),
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
//# sourceMappingURL=data:application/json;charset=utf-8;base64,eyJ2ZXJzaW9uIjozLCJzb3VyY2VzIjpbInNyYy9tb2R1bGUtYml0cml4MjQtaW50ZWdyYXRpb24tc3RhdHVzLXdvcmtlci5qcyJdLCJuYW1lcyI6WyJNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uU3RhdHVzV29ya2VyIiwiJG1vZHVsZVN0YXR1cyIsIiQiLCIkc3RhdHVzVG9nZ2xlIiwiJHN1Ym1pdEJ1dHRvbiIsIiRmb3JtT2JqIiwidGltZU91dCIsInRpbWVPdXRIYW5kbGUiLCJlcnJvckNvdW50cyIsImluaXRpYWxpemUiLCJyZXN0YXJ0V29ya2VyIiwiY2hhbmdlU3RhdHVzIiwid2luZG93IiwiY2xlYXJUaW1lb3V0IiwidGltZW91dEhhbmRsZSIsIndvcmtlciIsImNoZWNrYm94IiwidGVzdENvbm5lY3Rpb24iLCJhcGkiLCJ1cmwiLCJDb25maWciLCJwYnhVcmwiLCJvbiIsInN1Y2Nlc3NUZXN0IiwiUGJ4QXBpIiwib25Db21wbGV0ZSIsInNldFRpbWVvdXQiLCJvblN1Y2Nlc3MiLCJyZW1vdmVDbGFzcyIsIm9uRmFpbHVyZSIsIm9uUmVzcG9uc2UiLCJyZXNwb25zZSIsInJlbW92ZSIsImRhdGEiLCJ2aXN1YWxFcnJvclN0cmluZyIsIkpTT04iLCJzdHJpbmdpZnkiLCJtZXNzYWdlcyIsInJlcGxhY2UiLCJPYmplY3QiLCJrZXlzIiwibGVuZ3RoIiwicmVzdWx0IiwiYWZ0ZXIiLCJhZGRDbGFzcyIsInN0YXR1cyIsImh0bWwiLCJnbG9iYWxUcmFuc2xhdGUiLCJtb2RfYjI0X2lfQ29ubmVjdGVkIiwibW9kX2IyNF9pX0Rpc2Nvbm5lY3RlZCIsIm1vZF9iMjRfaV9TdGF0dXNFcnJvciIsIm1vZF9iMjRfaV9VcGRhdGVTdGF0dXMiXSwibWFwcGluZ3MiOiI7O0FBQUE7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTs7QUFDQTs7QUFFQTtBQUNBO0FBQ0E7QUFDQSxJQUFNQSxxQ0FBcUMsR0FBRztB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QXFDLENBQUNDLGFBQXRDLENBQ0UyQyxRQURGLENBQ1csS0FEWCxFQUVFRSxJQUZGLENBRU9DLGVBQWUsQ0FBQ0cscUJBRnZCO0FBR0E7O0FBQ0QsV0FBSyxVQUFMO0FBQ0NsRCxRQUFBQSxxQ0FBcUMsQ0FBQ0MsYUFBdEMsQ0FDRTJDLFFBREYsQ0FDVyxNQURYLEVBRUVFLElBRkYsaURBRThDQyxlQUFlLENBQUNJLHNCQUY5RDtBQUdBOztBQUNEO0FBQ0NuRCxRQUFBQSxxQ0FBcUMsQ0FBQ0MsYUFBdEMsQ0FDRTJDLFFBREYsQ0FDVyxLQURYLEVBRUVFLElBRkYsQ0FFT0MsZUFBZSxDQUFDRyxxQkFGdkI7QUFHQTtBQXpCRjtBQTJCQTtBQXpINEMsQ0FBOUMiLCJzb3VyY2VzQ29udGVudCI6WyIvKlxuICogTWlrb1BCWCAtIGZyZWUgcGhvbmUgc3lzdGVtIGZvciBzbWFsbCBidXNpbmVzc1xuICogQ29weXJpZ2h0IChDKSAyMDE3LTIwMjEgQWxleGV5IFBvcnRub3YgYW5kIE5pa29sYXkgQmVrZXRvdlxuICpcbiAqIFRoaXMgcHJvZ3JhbSBpcyBmcmVlIHNvZnR3YXJlOiB5b3UgY2FuIHJlZGlzdHJpYnV0ZSBpdCBhbmQvb3IgbW9kaWZ5XG4gKiBpdCB1bmRlciB0aGUgdGVybXMgb2YgdGhlIEdOVSBHZW5lcmFsIFB1YmxpYyBMaWNlbnNlIGFzIHB1Ymxpc2hlZCBieVxuICogdGhlIEZyZWUgU29mdHdhcmUgRm91bmRhdGlvbjsgZWl0aGVyIHZlcnNpb24gMyBvZiB0aGUgTGljZW5zZSwgb3JcbiAqIChhdCB5b3VyIG9wdGlvbikgYW55IGxhdGVyIHZlcnNpb24uXG4gKlxuICogVGhpcyBwcm9ncmFtIGlzIGRpc3RyaWJ1dGVkIGluIHRoZSBob3BlIHRoYXQgaXQgd2lsbCBiZSB1c2VmdWwsXG4gKiBidXQgV0lUSE9VVCBBTlkgV0FSUkFOVFk7IHdpdGhvdXQgZXZlbiB0aGUgaW1wbGllZCB3YXJyYW50eSBvZlxuICogTUVSQ0hBTlRBQklMSVRZIG9yIEZJVE5FU1MgRk9SIEEgUEFSVElDVUxBUiBQVVJQT1NFLiAgU2VlIHRoZVxuICogR05VIEdlbmVyYWwgUHVibGljIExpY2Vuc2UgZm9yIG1vcmUgZGV0YWlscy5cbiAqXG4gKiBZb3Ugc2hvdWxkIGhhdmUgcmVjZWl2ZWQgYSBjb3B5IG9mIHRoZSBHTlUgR2VuZXJhbCBQdWJsaWMgTGljZW5zZSBhbG9uZyB3aXRoIHRoaXMgcHJvZ3JhbS5cbiAqIElmIG5vdCwgc2VlIDxodHRwczovL3d3dy5nbnUub3JnL2xpY2Vuc2VzLz4uXG4gKi9cbi8qIGdsb2JhbCBnbG9iYWxUcmFuc2xhdGUsIEZvcm0sIENvbmZpZywgUGJ4QXBpICovXG5cbi8qKlxuICog0KLQtdGB0YLQuNGA0L7QstCw0L3QuNC1INGB0L7QtdC00LjQvdC10L3QuNGPINC80L7QtNGD0LvRjyDRgSBCaXRyaXgyNFxuICovXG5jb25zdCBNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uU3RhdHVzV29ya2VyID0ge1xuXG5cdCRtb2R1bGVTdGF0dXM6ICQoJyNzdGF0dXMnKSxcblx0JHN0YXR1c1RvZ2dsZTogJCgnI21vZHVsZS1zdGF0dXMtdG9nZ2xlJyksXG5cdCRzdWJtaXRCdXR0b246ICQoJyNzdWJtaXRidXR0b24nKSxcblx0JGZvcm1PYmo6ICQoJyNtb2R1bGUtYml0cml4MjQtaW50ZWdyYXRpb24tZm9ybScpLFxuXHR0aW1lT3V0OiAzMDAwLFxuXHR0aW1lT3V0SGFuZGxlOiAnJyxcblx0ZXJyb3JDb3VudHM6IDAsXG5cdGluaXRpYWxpemUoKSB7XG5cdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvblN0YXR1c1dvcmtlci5yZXN0YXJ0V29ya2VyKCk7XG5cdH0sXG5cdHJlc3RhcnRXb3JrZXIoKSB7XG5cdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvblN0YXR1c1dvcmtlci5lcnJvckNvdW50cyA9IDA7XG5cdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvblN0YXR1c1dvcmtlci5jaGFuZ2VTdGF0dXMoJ1VwZGF0aW5nJyk7XG5cdFx0d2luZG93LmNsZWFyVGltZW91dChNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uU3RhdHVzV29ya2VyLnRpbWVvdXRIYW5kbGUpO1xuXHRcdE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb25TdGF0dXNXb3JrZXIud29ya2VyKCk7XG5cdH0sXG5cdHdvcmtlcigpIHtcblx0XHRpZiAoTW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvblN0YXR1c1dvcmtlci4kc3RhdHVzVG9nZ2xlLmNoZWNrYm94KCdpcyBjaGVja2VkJykpIHtcblx0XHRcdE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb25TdGF0dXNXb3JrZXIudGVzdENvbm5lY3Rpb24oKTtcblx0XHR9IGVsc2Uge1xuXHRcdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvblN0YXR1c1dvcmtlci5lcnJvckNvdW50cyA9IDA7XG5cdFx0XHRNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uU3RhdHVzV29ya2VyLmNoYW5nZVN0YXR1cygnRGlzY29ubmVjdGVkJyk7XG5cdFx0fVxuXHR9LFxuXG5cdC8qKlxuXHQgKiDQn9GA0L7QstC10YDQutCwINGB0L7QtdC00LjQvdC10L3QuNGPINGBINGB0LXRgNCy0LXRgNC+0LwgQml0cml4MjRcblx0ICogQHJldHVybnMge2Jvb2xlYW59XG5cdCAqL1xuXHR0ZXN0Q29ubmVjdGlvbigpIHtcblx0XHQkLmFwaSh7XG5cdFx0XHR1cmw6IGAke0NvbmZpZy5wYnhVcmx9L3BieGNvcmUvYXBpL21vZHVsZXMvTW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi9jaGVja2AsXG5cdFx0XHRvbjogJ25vdycsXG5cdFx0XHRzdWNjZXNzVGVzdDogUGJ4QXBpLnN1Y2Nlc3NUZXN0LFxuXHRcdFx0b25Db21wbGV0ZSgpIHtcblx0XHRcdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvblN0YXR1c1dvcmtlci50aW1lb3V0SGFuZGxlID0gd2luZG93LnNldFRpbWVvdXQoXG5cdFx0XHRcdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvblN0YXR1c1dvcmtlci53b3JrZXIsXG5cdFx0XHRcdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvblN0YXR1c1dvcmtlci50aW1lT3V0LFxuXHRcdFx0XHQpO1xuXHRcdFx0fSxcblx0XHRcdG9uU3VjY2VzcygpIHtcblx0XHRcdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvblN0YXR1c1dvcmtlci5jaGFuZ2VTdGF0dXMoJ0Nvbm5lY3RlZCcpO1xuXHRcdFx0XHRNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uU3RhdHVzV29ya2VyLmVycm9yQ291bnRzID0gMDtcblx0XHRcdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvblN0YXR1c1dvcmtlci4kZm9ybU9iai5yZW1vdmVDbGFzcygnZXJyb3InKTtcblx0XHRcdH0sXG5cdFx0XHRvbkZhaWx1cmUoKSB7XG5cdFx0XHRcdE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb25TdGF0dXNXb3JrZXIuZXJyb3JDb3VudHMrKztcblx0XHRcdFx0aWYgKE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb25TdGF0dXNXb3JrZXIuZXJyb3JDb3VudHMgPiAzKXtcblx0XHRcdFx0XHRNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uU3RhdHVzV29ya2VyLmNoYW5nZVN0YXR1cygnQ29ubmVjdGlvbkVycm9yJyk7XG5cdFx0XHRcdH1cblx0XHRcdH0sXG5cdFx0XHRvblJlc3BvbnNlKHJlc3BvbnNlKSB7XG5cdFx0XHRcdCQoJy5tZXNzYWdlLmFqYXgnKS5yZW1vdmUoKTtcblx0XHRcdFx0aWYgKE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb25TdGF0dXNXb3JrZXIuZXJyb3JDb3VudHMgPCAzKXtcblx0XHRcdFx0XHRyZXR1cm47XG5cdFx0XHRcdH1cblxuXHRcdFx0XHQvLyBEZWJ1ZyBtb2RlXG5cdFx0XHRcdGlmICh0eXBlb2YgKHJlc3BvbnNlLmRhdGEpICE9PSAndW5kZWZpbmVkJykge1xuXHRcdFx0XHRcdGxldCB2aXN1YWxFcnJvclN0cmluZyA9IEpTT04uc3RyaW5naWZ5KHJlc3BvbnNlLm1lc3NhZ2VzLCBudWxsLCAyKTtcblxuXHRcdFx0XHRcdGlmICh0eXBlb2YgdmlzdWFsRXJyb3JTdHJpbmcgPT09ICdzdHJpbmcnKSB7XG5cdFx0XHRcdFx0XHR2aXN1YWxFcnJvclN0cmluZyA9IHZpc3VhbEVycm9yU3RyaW5nLnJlcGxhY2UoL1xcbi9nLCAnPGJyLz4nKTtcblx0XHRcdFx0XHRcdHZpc3VhbEVycm9yU3RyaW5nID0gdmlzdWFsRXJyb3JTdHJpbmcucmVwbGFjZSgvW1xcW1xcXSddKy9nLCcnKTtcblxuXHRcdFx0XHRcdFx0aWYgKE9iamVjdC5rZXlzKHJlc3BvbnNlKS5sZW5ndGggPiAwICYmIHJlc3BvbnNlLnJlc3VsdCAhPT0gdHJ1ZSkge1xuXHRcdFx0XHRcdFx0XHRNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uU3RhdHVzV29ya2VyLiRtb2R1bGVTdGF0dXNcblx0XHRcdFx0XHRcdFx0XHQuYWZ0ZXIoYDxkaXYgY2xhc3M9XCJ1aSBlcnJvciBpY29uIG1lc3NhZ2UgYWpheFwiPlxuXHRcdFx0XHRcdFx0XHRcdFx0PGkgY2xhc3M9XCJleGNsYW1hdGlvbiBjaXJjbGUgaWNvblwiPjwvaT5cblx0XHRcdFx0XHRcdFx0XHRcdDxkaXYgY2xhc3M9XCJjb250ZW50XCI+XHRcdFx0XHRcdFx0XHRcdFx0XHRcdFx0XHRcblx0XHRcdFx0XHRcdFx0XHRcdFx0PHByZSBzdHlsZT0nd2hpdGUtc3BhY2U6IHByZS13cmFwJz4ke3Zpc3VhbEVycm9yU3RyaW5nfTwvcHJlPlxuXHRcdFx0XHRcdFx0XHRcdFx0PC9kaXY+XHRcdFx0XHRcdFx0XHRcdFx0XHQgIFxuXHRcdFx0XHRcdFx0XHRcdDwvZGl2PmApO1xuXHRcdFx0XHRcdFx0XHRNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uU3RhdHVzV29ya2VyLiRmb3JtT2JqLmFkZENsYXNzKCdlcnJvcicpO1xuXG5cdFx0XHRcdFx0XHR9XG5cdFx0XHRcdFx0fVxuXHRcdFx0XHR9XG5cdFx0XHR9LFxuXHRcdH0pO1xuXHR9LFxuXHQvKipcblx0ICogVXBkYXRlcyBtb2R1bGUgc3RhdHVzIG9uIHRoZSByaWdodCBjb3JuZXIgbGFiZWxcblx0ICogQHBhcmFtIHN0YXR1c1xuXHQgKi9cblx0Y2hhbmdlU3RhdHVzKHN0YXR1cykge1xuXHRcdE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb25TdGF0dXNXb3JrZXIuJG1vZHVsZVN0YXR1c1xuXHRcdFx0LnJlbW92ZUNsYXNzKCdncmV5Jylcblx0XHRcdC5yZW1vdmVDbGFzcygneWVsbG93Jylcblx0XHRcdC5yZW1vdmVDbGFzcygnZ3JlZW4nKVxuXHRcdFx0LnJlbW92ZUNsYXNzKCdyZWQnKTtcblxuXHRcdHN3aXRjaCAoc3RhdHVzKSB7XG5cdFx0XHRjYXNlICdDb25uZWN0ZWQnOlxuXHRcdFx0XHRNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uU3RhdHVzV29ya2VyLiRtb2R1bGVTdGF0dXNcblx0XHRcdFx0XHQuYWRkQ2xhc3MoJ2dyZWVuJylcblx0XHRcdFx0XHQuaHRtbChnbG9iYWxUcmFuc2xhdGUubW9kX2IyNF9pX0Nvbm5lY3RlZCk7XG5cdFx0XHRcdGJyZWFrO1xuXHRcdFx0Y2FzZSAnRGlzY29ubmVjdGVkJzpcblx0XHRcdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvblN0YXR1c1dvcmtlci4kbW9kdWxlU3RhdHVzXG5cdFx0XHRcdFx0LmFkZENsYXNzKCdncmV5Jylcblx0XHRcdFx0XHQuaHRtbChnbG9iYWxUcmFuc2xhdGUubW9kX2IyNF9pX0Rpc2Nvbm5lY3RlZCk7XG5cdFx0XHRcdGJyZWFrO1xuXHRcdFx0Y2FzZSAnQ29ubmVjdGlvbkVycm9yJzpcblx0XHRcdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvblN0YXR1c1dvcmtlci4kbW9kdWxlU3RhdHVzXG5cdFx0XHRcdFx0LmFkZENsYXNzKCdyZWQnKVxuXHRcdFx0XHRcdC5odG1sKGdsb2JhbFRyYW5zbGF0ZS5tb2RfYjI0X2lfU3RhdHVzRXJyb3IpO1xuXHRcdFx0XHRicmVhaztcblx0XHRcdGNhc2UgJ1VwZGF0aW5nJzpcblx0XHRcdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvblN0YXR1c1dvcmtlci4kbW9kdWxlU3RhdHVzXG5cdFx0XHRcdFx0LmFkZENsYXNzKCdncmV5Jylcblx0XHRcdFx0XHQuaHRtbChgPGkgY2xhc3M9XCJzcGlubmVyIGxvYWRpbmcgaWNvblwiPjwvaT4ke2dsb2JhbFRyYW5zbGF0ZS5tb2RfYjI0X2lfVXBkYXRlU3RhdHVzfWApO1xuXHRcdFx0XHRicmVhaztcblx0XHRcdGRlZmF1bHQ6XG5cdFx0XHRcdE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb25TdGF0dXNXb3JrZXIuJG1vZHVsZVN0YXR1c1xuXHRcdFx0XHRcdC5hZGRDbGFzcygncmVkJylcblx0XHRcdFx0XHQuaHRtbChnbG9iYWxUcmFuc2xhdGUubW9kX2IyNF9pX1N0YXR1c0Vycm9yKTtcblx0XHRcdFx0YnJlYWs7XG5cdFx0fVxuXHR9XG59Il19