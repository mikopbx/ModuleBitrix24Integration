/*
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 5 2020
 */

/* global globalRootUrl, globalTranslate, Form, SemanticLocalization, InputMaskPatterns  */

const ModuleBitrix24Integration = {
	$formObj: $('#module-bitrix24-integration-form'),
	$submitButton: $('#submitbutton'),
	$statusToggle: $('#module-status-toggle'),
	$moduleStatus: $('#status'),
	$dirrtyField: $('#dirrty'),
	$usersCheckBoxes: $('#extensions-table .checkbox'),

	$globalSearch: $('#globalsearch'),
	$recordsTable: $('#external-line-table'),
	$addNewButton: $('#add-new-external-line-button'),
	$elRegion: $('#b24_region'),
	$elAppData: $('#b24-app-data'),

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
			depends: 'isREST',
			rules: [
				{
					type: 'empty',
					prompt: globalTranslate.mod_b24_i_ValidateClientIDEmpty,
				},
			],
		},
		client_secret: {
			identifier: 'client_secret',
			depends: 'isREST',
			rules: [
				{
					type: 'empty',
					prompt: globalTranslate.mod_b24_i_ValidateClientSecretEmpty,
				},
			],
		},
	},
	onChangeRegion(){
		let region = ModuleBitrix24Integration.$elRegion.val();
		if(region === 'REST_API'){
			ModuleBitrix24Integration.$formObj.form('set value', 'isREST', true);
			ModuleBitrix24Integration.$elAppData.show();
		}else{
			ModuleBitrix24Integration.$formObj.form('set value', 'isREST', '');
			ModuleBitrix24Integration.$elAppData.hide();
		}
		ModuleBitrix24Integration.onChangeField();
	},

	updateAuthInfo(e) {
		let data = e.originalEvent.data;
		if(typeof data !== 'string' && data.code !== undefined){
			data.region = ModuleBitrix24Integration.$elRegion.val();
			$.post(`${globalRootUrl}module-bitrix24-integration/activateCode`, data, function( result ) {
				console.log(result);
			});
			ModuleBitrix24Integration.popup.close();
		}
	},

	onChangeField(){
		if($('#create-lead').checkbox('is checked')){
			$('#lead-type').show()
		}else{
			$('#lead-type').hide()
		}
	},
	initialize() {
		ModuleBitrix24Integration.checkStatusToggle();
		window.addEventListener('ModuleStatusChanged', ModuleBitrix24Integration.checkStatusToggle);
		ModuleBitrix24Integration.initializeForm();
		$('.dropdown').dropdown();

		ModuleBitrix24Integration.onChangeRegion();
		ModuleBitrix24Integration.onChangeField();
		ModuleBitrix24Integration.$elRegion.change(ModuleBitrix24Integration.onChangeRegion);

		$('.avatar').each(() => {
			if ($(this).attr('src') === '') {
				$(this).attr('src', `${globalRootUrl}assets/img/unknownPerson.jpg`);
			}
		});

		$('#extensions-menu .item').tab();

		$('#extensions-table').DataTable({
			lengthChange: false,
			paging: false,
			info: false,
			columns: [
				{ orderable: false, searchable: false },
				null,
				null,
				null,
				null,
			],
			/**
			 * Draw event - fired once the table has completed a draw.
			 */
			drawCallback() {
				$('#extensions-table .select-group').each((index, obj) => {
					$(obj).dropdown({
						values: ModuleBitrix24Integration.makeDropdownList($(obj).attr('data-value')),
					});
				});
				$('#extensions-table .select-group').dropdown({
					onChange: ModuleBitrix24Integration.changeCardModeInList,
				});

				$('#extensions-table div.text').each((index, obj) => {
					$(obj).text(globalTranslate['mod_b24_i_OPEN_CARD_'+$(obj).parent().attr('data-value')]);
				});
			},
			order: [1, 'asc'],
			language: SemanticLocalization.dataTableLocalisation,
		});
		$(window).bind('message',  ModuleBitrix24Integration.updateAuthInfo);
		$("#login-button").on('click', function (e) {
			let portal       = $('#portal').val();
			$.post(`${Config.pbxUrl}/admin-cabinet/module-bitrix24-integration/getAppId`, {'region': $('#b24_region').val()}, function( data ) {
				let url 		 = `https://${portal}/oauth/authorize/?client_id=${data.client_id}&`;
				ModuleBitrix24Integration.popup = window.open(url, 'Auth', 'scrollbars, status, resizable, width=750, height=580');
			});
		});
		$('#create-lead').checkbox({
			onChange() {
				ModuleBitrix24Integration.onChangeField()
			}
		});
		ModuleBitrix24Integration.$usersCheckBoxes.checkbox({
			onChange() {
				ModuleBitrix24Integration.$dirrtyField.val(Math.random());
				ModuleBitrix24Integration.$dirrtyField.trigger('change');
				ModuleBitrix24Integration.onChangeField()
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
			paging: false,
			// scrollY: $(window).height() - ModuleBitrix24Integration.$recordsTable.offset().top-200,
			// stateSave: true,
			sDom: 'rtip',
			deferRender: true,
			bAutoWidth: false,
			info: false,
			// scrollCollapse: true,
			// scroller: true,
			/**
			 * Конструктор строки записи
			 * @param row
			 * @param data
			 */
			createdRow(row, data) {
				const templateDisable = '<div class="ui fitted toggle checkbox">\n' +
					'                <input type="checkbox" '+(((''+data.disabled)==='0')?'checked=""':'')+' tabindex="0" class="hidden"> <label></label>\n' +
					'                </div>';
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

				$('td', row).eq(0).html(templateDisable);
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
				ModuleBitrix24Integration.$recordsTable.find('div.checkbox').checkbox(
					{
						onChange() {
							Form.$formObj.form('set value', 'dirrty', Math.random());
							Form.checkValues();
						},
					}
				);
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

			Form.$formObj.form('set value', 'dirrty', Math.random());
			Form.checkValues();
		});

		// Отправка формы на сервер по уходу с поля ввода
		$('body').on('focusout', '.external-name, .external-number, .external-aliases', (e) => {
			$(e.target).closest('div')
				.addClass('transparent')
				.removeClass('changed-field');
			$(e.target).attr('readonly', true);
			ModuleBitrix24Integration.$dirrtyField.val(Math.random());
			ModuleBitrix24Integration.$dirrtyField.trigger('change');

			Form.$formObj.form('set value', 'dirrty', Math.random());
			Form.checkValues();
		});

		// Клик на кнопку удалить
		$('body').on('click', 'a.delete', (e) => {
			e.preventDefault();
			$(e.target).closest('tr').remove();
			if (ModuleBitrix24Integration.$recordsTable.find('tbody > tr').length===0){
				ModuleBitrix24Integration.$recordsTable.find('tbody').append('<tr class="odd"></tr>');
			}
			Form.$formObj.form('set value', 'dirrty', Math.random());
			Form.checkValues();
		});

		// Добавление новой строки
		ModuleBitrix24Integration.$addNewButton.on('click', (e) => {
			e.preventDefault();
			$('.dataTables_empty').remove();
			const id = `new${Math.floor(Math.random() * Math.floor(500))}`;
			const rowTemplate = `<tr id="${id}" class="ext-line-row">` +
				'<td><div class="ui fitted toggle checkbox"><input type="checkbox" checked="" tabindex="0" class="hidden"> <label></label></div></td>' +
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
			Form.$formObj.form('set value', 'dirrty', Math.random());
			Form.checkValues();
		});

		$('#open-cards-list option').each((index, obj) => {
			$(obj).html(globalTranslate["mod_b24_i_OPEN_CARD_"+$(obj).val()]);
		});
	},

	/**
	 * Подготавливает список выбора пользователей
	 * @param selected
	 * @returns {[]}
	 */
	makeDropdownList(selected) {
		const values = [];
		$('#open-cards-list option').each((index, obj) => {
			if (selected === obj.text) {
				values.push({
					name: globalTranslate["mod_b24_i_OPEN_CARD_"+obj.value],
					value: obj.value,
					selected: true,
				});
			} else {
				values.push({
					name: globalTranslate["mod_b24_i_OPEN_CARD_"+obj.value],
					value: obj.value,
				});
			}
		});
		return values;
	},

	/**
	 * Обработка изменения группы в списке
	 */
	changeCardModeInList(value, text, $choice) {
		ModuleBitrix24Integration.$dirrtyField.val(Math.random());
		ModuleBitrix24Integration.$dirrtyField.trigger('change');
		ModuleBitrix24Integration.onChangeField()
	},

	/**
	 * Изменение статуса кнопок при изменении статуса модуля
	 */
	checkStatusToggle() {
		if (ModuleBitrix24Integration.$statusToggle.checkbox('is checked')) {
			$('[data-tab = "general"] .disability').removeClass('disabled');
			$('[data-tab = "users"]').removeClass('disabled');
			$('[data-tab = "external_lines"]').removeClass('disabled');
			ModuleBitrix24Integration.$moduleStatus.show();
			ModuleBitrix24IntegrationStatusWorker.initialize();
		} else {
			ModuleBitrix24Integration.$moduleStatus.hide();
			$('[data-tab = "general"] .disability').addClass('disabled');
			$('[data-tab = "users"]').addClass('disabled');
			$('[data-tab = "external_lines"]').addClass('disabled');
		}
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
				disabled: $(obj).find('div.checkbox').checkbox('is checked') === false,
				id: $(obj).attr('id'),
				name: $(obj).find('input.external-name').val(),
				number: $(obj).find('input.external-number').val(),
				alias: $(obj).find('input.external-aliases').val(),
			});
		});
		result.data.externalLines = JSON.stringify(arrExternalLines);
		result.data.portal = result.data.portal.replace(/^(https?|http):\/\//, '');

		const arrUsers = [];
		$('#extensions-table tr').each((index, obj) => {
			let uname = $(obj).find('td input[type="checkbox"]').attr('name');
			if(uname === undefined){
				return;
			}
			arrUsers.push({
				id: $(obj).attr('id'),
				user_id: uname.replace('user-',''),
				open_card_mode: $(obj).find('td div.select-group').dropdown('get value'),
				disabled: $(obj).find('td div.checkbox').checkbox('is unchecked'),
				comment: $(obj).find('td').eq(3).text()
			});
		});
		result.data.arrUsers = JSON.stringify(arrUsers);
		return result;
	},

	/**
	 * Колбек после отправки формы
	 */
	cbAfterSendForm() {
		ModuleBitrix24IntegrationStatusWorker.initialize();
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

