/*
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 5 2020
 */

/* global globalRootUrl, globalTranslate, Form, Config, SemanticLocalization, InputMaskPatterns  */

const ModuleBitrix24Integration = {
	$formObj: $('#module-bitrix24-integration-form'),
	apiRoot: `${Config.pbxUrl}/pbxcore/api/modules/ModuleBitrix24Integration`,
	$submitButton: $('#submitbutton'),
	$statusToggle: $('#module-status-toggle'),
	$moduleStatus: $('#status'),
	$dirrtyField: $('#dirrty'),
	$usersCheckBoxes: $('#extensions-table .checkbox'),

	$globalSearch: $('#globalsearch'),
	$recordsTable: $('#external-line-table'),
	$addNewButton: $('#add-new-external-line-button'),

	inputNumberJQTPL: 'input.external-number',
	$maskList: null,
	getNewRecordsAJAXUrl: `${globalRootUrl}module-bitrix24-integration/getExternalLines`,

	validateRules: {
		portal: {
			identifier: 'portal',
			rules: [
				{
					type: 'empty',
					prompt: globalTranslate.mod_b24_i_ValidatePortalEmpty,
				},
			],
		},
		client_id: {
			identifier: 'client_id',
			rules: [
				{
					type: 'empty',
					prompt: globalTranslate.mod_b24_i_ValidateClientIDEmpty,
				},
			],
		},
		client_secret: {
			identifier: 'client_secret',
			rules: [
				{
					type: 'empty',
					prompt: globalTranslate.mod_b24_i_ValidateClientSecretEmpty,
				},
			],
		},
	},
	initialize() {
		ModuleBitrix24Integration.checkStatusToggle();
		window.addEventListener('ModuleStatusChanged', ModuleBitrix24Integration.checkStatusToggle);
		ModuleBitrix24Integration.initializeForm();

		$('.avatar').each(() => {
			if ($(this).attr('src') === '') {
				$(this).attr('src', `${globalRootUrl}assets/img/unknownPerson.jpg`);
			}
		});

		$('#extensions-menu .item').tab();

		$('#extensions-table').DataTable({
			lengthChange: false,
			paging: false,
			columns: [
				{ orderable: false, searchable: false },
				null,
				null,
				null,
			],
			order: [1, 'asc'],
			language: SemanticLocalization.dataTableLocalisation,
		});

		ModuleBitrix24Integration.$usersCheckBoxes.checkbox({
			onChange() {
				ModuleBitrix24Integration.$dirrtyField.val(Math.random());
				ModuleBitrix24Integration.$dirrtyField.trigger('change');
			},
			onChecked() {
				const number = $(this).attr('data-value');
				$(`#${number} .disability`).removeClass('disabled');
			},
			onUnchecked() {
				const number = $(this).attr('data-value');
				$(`#${number} .disability`).addClass('disabled');
			},
		});
		ModuleBitrix24Integration.$usersCheckBoxes.checkbox('attach events', '.check.button', 'check');
		ModuleBitrix24Integration.$usersCheckBoxes.checkbox('attach events', '.uncheck.button', 'uncheck');

		ModuleBitrix24Integration.$globalSearch.on('keyup', (e) => {
			if (e.keyCode === 13
				|| e.keyCode === 8
				|| ModuleBitrix24Integration.$globalSearch.val().length === 0) {
				const text = `${ModuleBitrix24Integration.$globalSearch.val()}`;
				ModuleBitrix24Integration.applyFilter(text);
			}
		});

		ModuleBitrix24Integration.$recordsTable.dataTable({
			serverSide: true,
			processing: true,
			ajax: {
				url: ModuleBitrix24Integration.getNewRecordsAJAXUrl,
				type: 'POST',
				dataSrc: 'data',
			},
			columns: [
				{ data: null },
				{ data: 'name' },
				{ data: 'number' },
				{ data: 'alias' },
				{ data: null },
			],
			paging: true,
			// scrollY: $(window).height() - ModuleBitrix24Integration.$recordsTable.offset().top-200,
			// stateSave: true,
			sDom: 'rtip',
			deferRender: true,
			pageLength: 17,
			bAutoWidth: false,

			// scrollCollapse: true,
			// scroller: true,
			/**
			 * Конструктор строки записи
			 * @param row
			 * @param data
			 */
			createdRow(row, data) {
				const templateName =
					'<div class="ui transparent fluid input inline-edit">' +
					`<input class="external-name" type="text" data-value="${data.name}" value="${data.name}">` +
					'</div>';

				const templateNumber =
					'<div class="ui transparent fluid input inline-edit">' +
					`<input class="external-number" type="text" data-value="${data.number}" value="${data.number}">` +
					'</div>';

				const templateDid =
					'<div class="ui transparent input inline-edit">' +
					`<input class="external-aliases" type="text" data-value="${data.alias}" value="${data.alias}">` +
					'</div>';

				const templateDeleteButton = '<div class="ui small basic icon buttons action-buttons">' +
					`<a href="#" data-value = "${data.id}"` +
					` class="ui button delete two-steps-delete popuped" data-content="${globalTranslate.bt_ToolTipDelete}">` +
					'<i class="icon trash red"></i></a></div>';

				$('td', row).eq(0).html('<i class="ui user circle icon"></i>');
				$('td', row).eq(1).html(templateName);
				$('td', row).eq(2).html(templateNumber);
				$('td', row).eq(3).html(templateDid);
				$('td', row).eq(4).html(templateDeleteButton);
			},
			/**
			 * Draw event - fired once the table has completed a draw.
			 */
			drawCallback() {
				ModuleBitrix24Integration.initializeInputmask($(ModuleBitrix24Integration.inputNumberJQTPL));
			},
			language: SemanticLocalization.dataTableLocalisation,
			ordering: false,
		});
		ModuleBitrix24Integration.dataTable = ModuleBitrix24Integration.$recordsTable.DataTable();

		// Двойной клик на поле ввода номера
		$('body').on('focusin', '.external-name, .external-number, .external-aliases ', (e) => {
			$(e.target).transition('glow');
			$(e.target).closest('div')
				.removeClass('transparent')
				.addClass('changed-field');
			$(e.target).attr('readonly', false);
			ModuleBitrix24Integration.$dirrtyField.val(Math.random());
			ModuleBitrix24Integration.$dirrtyField.trigger('change');
		});

		// Отправка формы на сервер по уходу с поля ввода
		$('body').on('focusout', '.external-name, .external-number, .external-aliases', (e) => {
			$(e.target).closest('div')
				.addClass('transparent')
				.removeClass('changed-field');
			$(e.target).attr('readonly', true);
			ModuleBitrix24Integration.$dirrtyField.val(Math.random());
			ModuleBitrix24Integration.$dirrtyField.trigger('change');
		});

		// Клик на кнопку удалить
		$('body').on('click', 'a.delete', (e) => {
			e.preventDefault();
			$(e.target).closest('tr').remove();
			if (ModuleBitrix24Integration.$recordsTable.find('tbody > tr').length===0){
				ModuleBitrix24Integration.$recordsTable.find('tbody').append('<tr class="odd"></tr>');
			}
			ModuleBitrix24Integration.$dirrtyField.val(Math.random());
			ModuleBitrix24Integration.$dirrtyField.trigger('change');
		});

		// Добавление новой строки
		ModuleBitrix24Integration.$addNewButton.on('click', (e) => {
			e.preventDefault();
			$('.dataTables_empty').remove();
			const id = `new${Math.floor(Math.random() * Math.floor(500))}`;
			const rowTemplate = `<tr id="${id}" class="ext-line-row">` +
				'<td><i class="ui user circle icon"></i></td>' +
				'<td><div class="ui fluid input inline-edit changed-field"><input class="external-name" type="text" data-value="" value=""></div></td>' +
				'<td><div class="ui input inline-edit changed-field"><input class="external-number" type="text" data-value="" value=""></div></td>' +
				'<td><div class="ui input inline-edit changed-field"><input class="external-aliases" type="text" data-value="" value=""></div></td>' +
				'<td><div class="ui small basic icon buttons action-buttons">' +
				`<a href="#" class="ui button delete two-steps-delete popuped" data-value = "new" data-content="${globalTranslate.bt_ToolTipDelete}">` +
				'<i class="icon trash red"></i></a></div></td>' +
				'</tr>';
			ModuleBitrix24Integration.$recordsTable.find('tbody > tr:first').before(rowTemplate);
			$(`tr#${id} input`).transition('glow');
			$(`tr#${id} .external-name`).focus();
			ModuleBitrix24Integration.initializeInputmask($(`tr#${id} .external-number`));
			ModuleBitrix24Integration.$dirrtyField.val(Math.random());
			ModuleBitrix24Integration.$dirrtyField.trigger('change');
		});
	},
	/**
	 * Изменение статуса кнопок при изменении статуса модуля
	 */
	checkStatusToggle() {
		if (ModuleBitrix24Integration.$statusToggle.checkbox('is checked')) {
			$('[data-tab = "general"] .disability').removeClass('disabled');
			ModuleBitrix24Integration.$moduleStatus.show();
			ModuleBitrix24Integration.testConnection();
		} else {
			ModuleBitrix24Integration.$moduleStatus.hide();
			$('[data-tab = "general"] .disability').addClass('disabled');
		}
	},
	/**
	 * Применение настроек модуля после изменения данных формы
	 */
	applyConfigurationChanges() {
		$.api({
			url: `${ModuleBitrix24Integration.apiRoot}/reload`,
			on: 'now',
			successTest(response) {
				// test whether a JSON response is valid
				return response !== undefined
					&& Object.keys(response).length > 0
					&& response.result === true;
			},
			onSuccess() {
				ModuleBitrix24Integration.checkStatusToggle();
			},
		});
	},
	/**
	 * Проверка соединения с сервером Bitrix24
	 * @returns {boolean}
	 */
	testConnection() {
		$.api({
			url: `${ModuleBitrix24Integration.apiRoot}/check`,
			on: 'now',
			successTest(response) {
				return response !== undefined
				&& Object.keys(response).length > 0
				&& response.result !== undefined
				&& response.result === true;
			},
			onSuccess() {
				ModuleBitrix24Integration.$moduleStatus.removeClass('grey').addClass('green');
				ModuleBitrix24Integration.$moduleStatus.html(globalTranslate.mod_b24_i_Connected);
				// const FullName = `${response.data.data.LAST_NAME} ${response.data.data.NAME}`;
			},
			onFailure() {
				ModuleBitrix24Integration.$moduleStatus.removeClass('green').addClass('grey');
				ModuleBitrix24Integration.$moduleStatus.html(globalTranslate.mod_b24_i_Disconnected);
			},
			onResponse(response) {
				$('.message.ajax').remove();
				// Debug mode
				if (typeof (response.data) !== 'undefined') {
					let visualErrorString = JSON.stringify(response.data, null, 2);

					if (typeof visualErrorString === 'string') {
						visualErrorString = visualErrorString.replace(/\n/g, '<br/>');

						if (Object.keys(response).length > 0 && response.result !== true) {
							ModuleBitrix24Integration.$formObj
								.after(`<div class="ui error message ajax">						
									<pre style='white-space: pre-wrap'>${visualErrorString}</pre>										  
								</div>`);
						}
					}
				}
			},
		});
	},
	/**
	 * Инициализирует красивое представление номеров
	 */
	initializeInputmask($el) {
		if (ModuleBitrix24Integration.$maskList === null) {
			// Подготовим таблицу для сортировки
			ModuleBitrix24Integration.$maskList = $.masksSort(InputMaskPatterns, ['#'], /[0-9]|#/, 'mask');
		}
		$el.inputmasks({
			inputmask: {
				definitions: {
					'#': {
						validator: '[0-9]',
						cardinality: 1,
					},
				},
				showMaskOnHover: false,
				// oncleared: extension.cbOnClearedMobileNumber,
				oncomplete: ModuleBitrix24Integration.cbOnCompleteNumber,
				// clearIncomplete: true,
				onBeforePaste: ModuleBitrix24Integration.cbOnNumberBeforePaste,
				// regex: /\D+/,
			},
			match: /[0-9]/,
			replace: '9',
			list: ModuleBitrix24Integration.$maskList,
			listKey: 'mask',

		});
	},
	/**
	 * Очистка номера перед вставкой от лишних символов
	 * @returns {boolean|*|void|string}
	 */
	cbOnNumberBeforePaste(pastedValue) {
		return pastedValue.replace(/\D+/g, '');
	},
	/**
	 * После ввода номера
	 */
	cbOnCompleteNumber(e){
		const didEl = $(e.target).closest('tr').find('input.external-aliases');
		if (didEl.val()===''){
			didEl.val($(e.target).inputmask('unmaskedvalue'));
		}
	},
	/**
	 * Колбек перед отправкой формы
	 * @param settings
	 * @returns {*}
	 */
	cbBeforeSendForm(settings) {
		const result = settings;
		result.data = ModuleBitrix24Integration.$formObj.form('get values');

		const arrExternalLines = [];
		$('#external-line-table tr').each((index, obj) => {
			arrExternalLines.push({
				id: $(obj).attr('id'),
				name: $(obj).find('input.external-name').val(),
				number: $(obj).find('input.external-number').val(),
				alias: $(obj).find('input.external-aliases').val(),
			});
		});
		result.data.externalLines = JSON.stringify(arrExternalLines);

		return result;
	},

	/**
	 * Колбек после отправки формы
	 */
	cbAfterSendForm() {
		ModuleBitrix24Integration.applyConfigurationChanges();
	},
	initializeForm() {
		Form.$formObj = ModuleBitrix24Integration.$formObj;
		Form.url = `${globalRootUrl}module-bitrix24-integration/save`;
		Form.validateRules = ModuleBitrix24Integration.validateRules;
		Form.cbBeforeSendForm = ModuleBitrix24Integration.cbBeforeSendForm;
		Form.cbAfterSendForm = ModuleBitrix24Integration.cbAfterSendForm;
		Form.initialize();
	},
};

$(document).ready(() => {
	ModuleBitrix24Integration.initialize();
});

