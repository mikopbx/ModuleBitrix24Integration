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
const ModuleBitrix24IntegrationStatusWorker = {

	$moduleStatus: $('#status'),
	$statusToggle: $('#module-status-toggle'),
	$submitButton: $('#submitbutton'),
	$formObj: $('#module-bitrix24-integration-form'),
	timeOut: 3000,
	timeOutHandle: '',
	errorCounts: 0,
	initialize() {
		ModuleBitrix24IntegrationStatusWorker.restartWorker();
	},
	restartWorker() {
		ModuleBitrix24IntegrationStatusWorker.errorCounts = 0;
		ModuleBitrix24IntegrationStatusWorker.changeStatus('Updating');
		window.clearTimeout(ModuleBitrix24IntegrationStatusWorker.timeoutHandle);
		ModuleBitrix24IntegrationStatusWorker.worker();
	},
	worker() {
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
	testConnection() {
		$.api({
			url: `${globalRootUrl}module-bitrix24-integration/checkState`,
			on: 'now',
			successTest: PbxApi.successTest,
			onComplete() {
				ModuleBitrix24IntegrationStatusWorker.timeoutHandle = window.setTimeout(
					ModuleBitrix24IntegrationStatusWorker.worker,
					ModuleBitrix24IntegrationStatusWorker.timeOut,
				);
			},
			onSuccess() {
				ModuleBitrix24IntegrationStatusWorker.changeStatus('Connected');
				ModuleBitrix24IntegrationStatusWorker.errorCounts = 0;
				ModuleBitrix24IntegrationStatusWorker.$formObj.removeClass('error');
			},
			onFailure() {
				ModuleBitrix24IntegrationStatusWorker.errorCounts++;
				if (ModuleBitrix24IntegrationStatusWorker.errorCounts > 3){
					ModuleBitrix24IntegrationStatusWorker.changeStatus('ConnectionError');
				}
			},
			onResponse(response) {
				$('.message.ajax').remove();
				if (ModuleBitrix24IntegrationStatusWorker.errorCounts < 3){
					return;
				}

				// Debug mode
				if (typeof (response.data) !== 'undefined') {
					let visualErrorString = JSON.stringify(response.messages, null, 2);

					if (typeof visualErrorString === 'string') {
						visualErrorString = visualErrorString.replace(/\n/g, '<br/>');
						visualErrorString = visualErrorString.replace(/[\[\]']+/g,'');

						if (Object.keys(response).length > 0 && response.result !== true) {
							ModuleBitrix24IntegrationStatusWorker.$moduleStatus
								.after(`<div class="ui error icon message ajax">
									<i class="exclamation circle icon"></i>
									<div class="content">													
										<pre style='white-space: pre-wrap'>${visualErrorString}</pre>
									</div>										  
								</div>`);
							ModuleBitrix24IntegrationStatusWorker.$formObj.addClass('error');

						}
					}
				}
			},
		});

		$.ajax({
			url: window.location.origin + '/pbxcore/api/bitrix-integration/workers/state',
			method: 'GET',
			dataType: 'json',
			success: function(response) {
				const $container = $('#status-workers');
				$container.empty();
				const $label = $('<div class="ui small basic label" style="font-weight: bold; padding: 0.6em 1em;">'+globalTranslate.mod_b24_i_ServiceStateTitle+'</div>');
				$container.append($label);
				if (response.result === true && Array.isArray(response.data)) {
					// Для каждого сервиса создаём label
					response.data.forEach(service => {
						const colorClass = service.state === 'OK' ? 'green' : 'red';
						const $label = $(`<div class="ui ${colorClass} label">${service.label}</div>`);
						$container.append($label);
					});
				} else {
					// Если result: false или data не массив
					const $label = $('<div class="ui red label">'+globalTranslate.mod_b24_i_GetServiceStateError+'</div>');
					$container.append($label);
				}
			},
			error: function() {
				const $container = $('#status-workers');
				$container.empty();
				const $label = $('<div class="ui red label">'+globalTranslate.mod_b24_i_GetServiceStateError+'</div>');
				$container.append($label);
			}
		});
	},
	/**
	 * Updates module status on the right corner label
	 * @param status
	 */
	changeStatus(status) {
		ModuleBitrix24IntegrationStatusWorker.$moduleStatus
			.removeClass('grey')
			.removeClass('yellow')
			.removeClass('green')
			.removeClass('red');

		switch (status) {
			case 'Connected':
				ModuleBitrix24IntegrationStatusWorker.$moduleStatus
					.addClass('green')
					.html(globalTranslate.mod_b24_i_Connected_v2);
				break;
			case 'Disconnected':
				ModuleBitrix24IntegrationStatusWorker.$moduleStatus
					.addClass('grey')
					.html(globalTranslate.mod_b24_i_Disconnected);
				break;
			case 'ConnectionError':
				ModuleBitrix24IntegrationStatusWorker.$moduleStatus
					.addClass('red')
					.html(globalTranslate.mod_b24_i_StatusError);
				break;
			case 'Updating':
				ModuleBitrix24IntegrationStatusWorker.$moduleStatus
					.addClass('grey')
					.html(`<i class="spinner loading icon"></i>${globalTranslate.mod_b24_i_UpdateStatus}`);
				break;
			default:
				ModuleBitrix24IntegrationStatusWorker.$moduleStatus
					.addClass('red')
					.html(globalTranslate.mod_b24_i_StatusError);
				break;
		}
	}
}