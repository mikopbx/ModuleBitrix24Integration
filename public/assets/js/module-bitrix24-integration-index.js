"use strict";

/*
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 5 2020
 */

/* global globalRootUrl, globalTranslate, Form, SemanticLocalization, InputMaskPatterns  */
var ModuleBitrix24Integration = {
  $formObj: $('#module-bitrix24-integration-form'),
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
  getNewRecordsAJAXUrl: "".concat(globalRootUrl, "module-bitrix24-integration/getExternalLines"),
  validateRules: {
    portal: {
      identifier: 'portal',
      rules: [{
        type: 'empty',
        prompt: globalTranslate.mod_b24_i_ValidatePortalEmpty
      }]
    },
    client_id: {
      identifier: 'client_id',
      rules: [{
        type: 'empty',
        prompt: globalTranslate.mod_b24_i_ValidateClientIDEmpty
      }]
    },
    client_secret: {
      identifier: 'client_secret',
      rules: [{
        type: 'empty',
        prompt: globalTranslate.mod_b24_i_ValidateClientSecretEmpty
      }]
    }
  },
  initialize: function initialize() {
    var _this = this;

    ModuleBitrix24Integration.checkStatusToggle();
    window.addEventListener('ModuleStatusChanged', ModuleBitrix24Integration.checkStatusToggle);
    ModuleBitrix24Integration.initializeForm();
    $('.avatar').each(function () {
      if ($(_this).attr('src') === '') {
        $(_this).attr('src', "".concat(globalRootUrl, "assets/img/unknownPerson.jpg"));
      }
    });
    $('#extensions-menu .item').tab();
    $('#extensions-table').DataTable({
      lengthChange: false,
      paging: false,
      columns: [{
        orderable: false,
        searchable: false
      }, null, null, null],
      order: [1, 'asc'],
      language: SemanticLocalization.dataTableLocalisation
    });
    ModuleBitrix24Integration.$usersCheckBoxes.checkbox({
      onChange: function onChange() {
        ModuleBitrix24Integration.$dirrtyField.val(Math.random());
        ModuleBitrix24Integration.$dirrtyField.trigger('change');
      },
      onChecked: function onChecked() {
        var number = $(this).attr('data-value');
        $("#".concat(number, " .disability")).removeClass('disabled');
      },
      onUnchecked: function onUnchecked() {
        var number = $(this).attr('data-value');
        $("#".concat(number, " .disability")).addClass('disabled');
      }
    });
    ModuleBitrix24Integration.$usersCheckBoxes.checkbox('attach events', '.check.button', 'check');
    ModuleBitrix24Integration.$usersCheckBoxes.checkbox('attach events', '.uncheck.button', 'uncheck');
    ModuleBitrix24Integration.$globalSearch.on('keyup', function (e) {
      if (e.keyCode === 13 || e.keyCode === 8 || ModuleBitrix24Integration.$globalSearch.val().length === 0) {
        var text = "".concat(ModuleBitrix24Integration.$globalSearch.val());
        ModuleBitrix24Integration.applyFilter(text);
      }
    });
    ModuleBitrix24Integration.$recordsTable.dataTable({
      serverSide: true,
      processing: true,
      ajax: {
        url: ModuleBitrix24Integration.getNewRecordsAJAXUrl,
        type: 'POST',
        dataSrc: 'data'
      },
      columns: [{
        data: null
      }, {
        data: 'name'
      }, {
        data: 'number'
      }, {
        data: 'alias'
      }, {
        data: null
      }],
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
      createdRow: function createdRow(row, data) {
        var templateName = '<div class="ui transparent fluid input inline-edit">' + "<input class=\"external-name\" type=\"text\" data-value=\"".concat(data.name, "\" value=\"").concat(data.name, "\">") + '</div>';
        var templateNumber = '<div class="ui transparent fluid input inline-edit">' + "<input class=\"external-number\" type=\"text\" data-value=\"".concat(data.number, "\" value=\"").concat(data.number, "\">") + '</div>';
        var templateDid = '<div class="ui transparent input inline-edit">' + "<input class=\"external-aliases\" type=\"text\" data-value=\"".concat(data.alias, "\" value=\"").concat(data.alias, "\">") + '</div>';
        var templateDeleteButton = '<div class="ui small basic icon buttons action-buttons">' + "<a href=\"#\" data-value = \"".concat(data.id, "\"") + " class=\"ui button delete two-steps-delete popuped\" data-content=\"".concat(globalTranslate.bt_ToolTipDelete, "\">") + '<i class="icon trash red"></i></a></div>';
        $('td', row).eq(0).html('<i class="ui user circle icon"></i>');
        $('td', row).eq(1).html(templateName);
        $('td', row).eq(2).html(templateNumber);
        $('td', row).eq(3).html(templateDid);
        $('td', row).eq(4).html(templateDeleteButton);
      },

      /**
       * Draw event - fired once the table has completed a draw.
       */
      drawCallback: function drawCallback() {
        ModuleBitrix24Integration.initializeInputmask($(ModuleBitrix24Integration.inputNumberJQTPL));
      },
      language: SemanticLocalization.dataTableLocalisation,
      ordering: false
    });
    ModuleBitrix24Integration.dataTable = ModuleBitrix24Integration.$recordsTable.DataTable(); // Двойной клик на поле ввода номера

    $('body').on('focusin', '.external-name, .external-number, .external-aliases ', function (e) {
      $(e.target).transition('glow');
      $(e.target).closest('div').removeClass('transparent').addClass('changed-field');
      $(e.target).attr('readonly', false);
      ModuleBitrix24Integration.$dirrtyField.val(Math.random());
      ModuleBitrix24Integration.$dirrtyField.trigger('change');
    }); // Отправка формы на сервер по уходу с поля ввода

    $('body').on('focusout', '.external-name, .external-number, .external-aliases', function (e) {
      $(e.target).closest('div').addClass('transparent').removeClass('changed-field');
      $(e.target).attr('readonly', true);
      ModuleBitrix24Integration.$dirrtyField.val(Math.random());
      ModuleBitrix24Integration.$dirrtyField.trigger('change');
    }); // Клик на кнопку удалить

    $('body').on('click', 'a.delete', function (e) {
      e.preventDefault();
      $(e.target).closest('tr').remove();

      if (ModuleBitrix24Integration.$recordsTable.find('tbody > tr').length === 0) {
        ModuleBitrix24Integration.$recordsTable.find('tbody').append('<tr class="odd"></tr>');
      }

      ModuleBitrix24Integration.$dirrtyField.val(Math.random());
      ModuleBitrix24Integration.$dirrtyField.trigger('change');
    }); // Добавление новой строки

    ModuleBitrix24Integration.$addNewButton.on('click', function (e) {
      e.preventDefault();
      $('.dataTables_empty').remove();
      var id = "new".concat(Math.floor(Math.random() * Math.floor(500)));
      var rowTemplate = "<tr id=\"".concat(id, "\" class=\"ext-line-row\">") + '<td><i class="ui user circle icon"></i></td>' + '<td><div class="ui fluid input inline-edit changed-field"><input class="external-name" type="text" data-value="" value=""></div></td>' + '<td><div class="ui input inline-edit changed-field"><input class="external-number" type="text" data-value="" value=""></div></td>' + '<td><div class="ui input inline-edit changed-field"><input class="external-aliases" type="text" data-value="" value=""></div></td>' + '<td><div class="ui small basic icon buttons action-buttons">' + "<a href=\"#\" class=\"ui button delete two-steps-delete popuped\" data-value = \"new\" data-content=\"".concat(globalTranslate.bt_ToolTipDelete, "\">") + '<i class="icon trash red"></i></a></div></td>' + '</tr>';
      ModuleBitrix24Integration.$recordsTable.find('tbody > tr:first').before(rowTemplate);
      $("tr#".concat(id, " input")).transition('glow');
      $("tr#".concat(id, " .external-name")).focus();
      ModuleBitrix24Integration.initializeInputmask($("tr#".concat(id, " .external-number")));
      ModuleBitrix24Integration.$dirrtyField.val(Math.random());
      ModuleBitrix24Integration.$dirrtyField.trigger('change');
    });
  },

  /**
   * Изменение статуса кнопок при изменении статуса модуля
   */
  checkStatusToggle: function checkStatusToggle() {
    if (ModuleBitrix24Integration.$statusToggle.checkbox('is checked')) {
      $('[data-tab = "general"] .disability').removeClass('disabled');
      ModuleBitrix24Integration.$moduleStatus.show();
      ModuleBitrix24IntegrationStatusWorker.initialize();
    } else {
      ModuleBitrix24Integration.$moduleStatus.hide();
      $('[data-tab = "general"] .disability').addClass('disabled');
    }
  },

  /**
   * Инициализирует красивое представление номеров
   */
  initializeInputmask: function initializeInputmask($el) {
    if (ModuleBitrix24Integration.$maskList === null) {
      // Подготовим таблицу для сортировки
      ModuleBitrix24Integration.$maskList = $.masksSort(InputMaskPatterns, ['#'], /[0-9]|#/, 'mask');
    }

    $el.inputmasks({
      inputmask: {
        definitions: {
          '#': {
            validator: '[0-9]',
            cardinality: 1
          }
        },
        showMaskOnHover: false,
        // oncleared: extension.cbOnClearedMobileNumber,
        oncomplete: ModuleBitrix24Integration.cbOnCompleteNumber,
        // clearIncomplete: true,
        onBeforePaste: ModuleBitrix24Integration.cbOnNumberBeforePaste // regex: /\D+/,

      },
      match: /[0-9]/,
      replace: '9',
      list: ModuleBitrix24Integration.$maskList,
      listKey: 'mask'
    });
  },

  /**
   * Очистка номера перед вставкой от лишних символов
   * @returns {boolean|*|void|string}
   */
  cbOnNumberBeforePaste: function cbOnNumberBeforePaste(pastedValue) {
    return pastedValue.replace(/\D+/g, '');
  },

  /**
   * После ввода номера
   */
  cbOnCompleteNumber: function cbOnCompleteNumber(e) {
    var didEl = $(e.target).closest('tr').find('input.external-aliases');

    if (didEl.val() === '') {
      didEl.val($(e.target).inputmask('unmaskedvalue'));
    }
  },

  /**
   * Колбек перед отправкой формы
   * @param settings
   * @returns {*}
   */
  cbBeforeSendForm: function cbBeforeSendForm(settings) {
    var result = settings;
    result.data = ModuleBitrix24Integration.$formObj.form('get values');
    var arrExternalLines = [];
    $('#external-line-table tr').each(function (index, obj) {
      arrExternalLines.push({
        id: $(obj).attr('id'),
        name: $(obj).find('input.external-name').val(),
        number: $(obj).find('input.external-number').val(),
        alias: $(obj).find('input.external-aliases').val()
      });
    });
    result.data.externalLines = JSON.stringify(arrExternalLines);
    return result;
  },

  /**
   * Колбек после отправки формы
   */
  cbAfterSendForm: function cbAfterSendForm() {
    ModuleBitrix24IntegrationStatusWorker.initialize();
  },
  initializeForm: function initializeForm() {
    Form.$formObj = ModuleBitrix24Integration.$formObj;
    Form.url = "".concat(globalRootUrl, "module-bitrix24-integration/save");
    Form.validateRules = ModuleBitrix24Integration.validateRules;
    Form.cbBeforeSendForm = ModuleBitrix24Integration.cbBeforeSendForm;
    Form.cbAfterSendForm = ModuleBitrix24Integration.cbAfterSendForm;
    Form.initialize();
  }
};
$(document).ready(function () {
  ModuleBitrix24Integration.initialize();
});
//# sourceMappingURL=data:application/json;charset=utf-8;base64,eyJ2ZXJzaW9uIjozLCJzb3VyY2VzIjpbInNyYy9tb2R1bGUtYml0cml4MjQtaW50ZWdyYXRpb24taW5kZXguanMiXSwibmFtZXMiOlsiTW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbiIsIiRmb3JtT2JqIiwiJCIsIiRzdWJtaXRCdXR0b24iLCIkc3RhdHVzVG9nZ2xlIiwiJG1vZHVsZVN0YXR1cyIsIiRkaXJydHlGaWVsZCIsIiR1c2Vyc0NoZWNrQm94ZXMiLCIkZ2xvYmFsU2VhcmNoIiwiJHJlY29yZHNUYWJsZSIsIiRhZGROZXdCdXR0b24iLCJpbnB1dE51bWJlckpRVFBMIiwiJG1hc2tMaXN0IiwiZ2V0TmV3UmVjb3Jkc0FKQVhVcmwiLCJnbG9iYWxSb290VXJsIiwidmFsaWRhdGVSdWxlcyIsInBvcnRhbCIsImlkZW50aWZpZXIiLCJydWxlcyIsInR5cGUiLCJwcm9tcHQiLCJnbG9iYWxUcmFuc2xhdGUiLCJtb2RfYjI0X2lfVmFsaWRhdGVQb3J0YWxFbXB0eSIsImNsaWVudF9pZCIsIm1vZF9iMjRfaV9WYWxpZGF0ZUNsaWVudElERW1wdHkiLCJjbGllbnRfc2VjcmV0IiwibW9kX2IyNF9pX1ZhbGlkYXRlQ2xpZW50U2VjcmV0RW1wdHkiLCJpbml0aWFsaXplIiwiY2hlY2tTdGF0dXNUb2dnbGUiLCJ3aW5kb3ciLCJhZGRFdmVudExpc3RlbmVyIiwiaW5pdGlhbGl6ZUZvcm0iLCJlYWNoIiwiYXR0ciIsInRhYiIsIkRhdGFUYWJsZSIsImxlbmd0aENoYW5nZSIsInBhZ2luZyIsImNvbHVtbnMiLCJvcmRlcmFibGUiLCJzZWFyY2hhYmxlIiwib3JkZXIiLCJsYW5ndWFnZSIsIlNlbWFudGljTG9jYWxpemF0aW9uIiwiZGF0YVRhYmxlTG9jYWxpc2F0aW9uIiwiY2hlY2tib3giLCJvbkNoYW5nZSIsInZhbCIsIk1hdGgiLCJyYW5kb20iLCJ0cmlnZ2VyIiwib25DaGVja2VkIiwibnVtYmVyIiwicmVtb3ZlQ2xhc3MiLCJvblVuY2hlY2tlZCIsImFkZENsYXNzIiwib24iLCJlIiwia2V5Q29kZSIsImxlbmd0aCIsInRleHQiLCJhcHBseUZpbHRlciIsImRhdGFUYWJsZSIsInNlcnZlclNpZGUiLCJwcm9jZXNzaW5nIiwiYWpheCIsInVybCIsImRhdGFTcmMiLCJkYXRhIiwic0RvbSIsImRlZmVyUmVuZGVyIiwicGFnZUxlbmd0aCIsImJBdXRvV2lkdGgiLCJjcmVhdGVkUm93Iiwicm93IiwidGVtcGxhdGVOYW1lIiwibmFtZSIsInRlbXBsYXRlTnVtYmVyIiwidGVtcGxhdGVEaWQiLCJhbGlhcyIsInRlbXBsYXRlRGVsZXRlQnV0dG9uIiwiaWQiLCJidF9Ub29sVGlwRGVsZXRlIiwiZXEiLCJodG1sIiwiZHJhd0NhbGxiYWNrIiwiaW5pdGlhbGl6ZUlucHV0bWFzayIsIm9yZGVyaW5nIiwidGFyZ2V0IiwidHJhbnNpdGlvbiIsImNsb3Nlc3QiLCJwcmV2ZW50RGVmYXVsdCIsInJlbW92ZSIsImZpbmQiLCJhcHBlbmQiLCJmbG9vciIsInJvd1RlbXBsYXRlIiwiYmVmb3JlIiwiZm9jdXMiLCJzaG93IiwiTW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvblN0YXR1c1dvcmtlciIsImhpZGUiLCIkZWwiLCJtYXNrc1NvcnQiLCJJbnB1dE1hc2tQYXR0ZXJucyIsImlucHV0bWFza3MiLCJpbnB1dG1hc2siLCJkZWZpbml0aW9ucyIsInZhbGlkYXRvciIsImNhcmRpbmFsaXR5Iiwic2hvd01hc2tPbkhvdmVyIiwib25jb21wbGV0ZSIsImNiT25Db21wbGV0ZU51bWJlciIsIm9uQmVmb3JlUGFzdGUiLCJjYk9uTnVtYmVyQmVmb3JlUGFzdGUiLCJtYXRjaCIsInJlcGxhY2UiLCJsaXN0IiwibGlzdEtleSIsInBhc3RlZFZhbHVlIiwiZGlkRWwiLCJjYkJlZm9yZVNlbmRGb3JtIiwic2V0dGluZ3MiLCJyZXN1bHQiLCJmb3JtIiwiYXJyRXh0ZXJuYWxMaW5lcyIsImluZGV4Iiwib2JqIiwicHVzaCIsImV4dGVybmFsTGluZXMiLCJKU09OIiwic3RyaW5naWZ5IiwiY2JBZnRlclNlbmRGb3JtIiwiRm9ybSIsImRv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MUcsVUFBTDtBQUNBO0FBM1RnQyxDQUFsQztBQThUQXpCLENBQUMsQ0FBQ29JLFFBQUQsQ0FBRCxDQUFZQyxLQUFaLENBQWtCLFlBQU07QUFDdkJ2SSxFQUFBQSx5QkFBeUIsQ0FBQzJCLFVBQTFCO0FBQ0EsQ0FGRCIsInNvdXJjZXNDb250ZW50IjpbIi8qXG4gKiBDb3B5cmlnaHQgwqkgTUlLTyBMTEMgLSBBbGwgUmlnaHRzIFJlc2VydmVkXG4gKiBVbmF1dGhvcml6ZWQgY29weWluZyBvZiB0aGlzIGZpbGUsIHZpYSBhbnkgbWVkaXVtIGlzIHN0cmljdGx5IHByb2hpYml0ZWRcbiAqIFByb3ByaWV0YXJ5IGFuZCBjb25maWRlbnRpYWxcbiAqIFdyaXR0ZW4gYnkgQWxleGV5IFBvcnRub3YsIDUgMjAyMFxuICovXG5cbi8qIGdsb2JhbCBnbG9iYWxSb290VXJsLCBnbG9iYWxUcmFuc2xhdGUsIEZvcm0sIFNlbWFudGljTG9jYWxpemF0aW9uLCBJbnB1dE1hc2tQYXR0ZXJucyAgKi9cblxuY29uc3QgTW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbiA9IHtcblx0JGZvcm1PYmo6ICQoJyNtb2R1bGUtYml0cml4MjQtaW50ZWdyYXRpb24tZm9ybScpLFxuXHQkc3VibWl0QnV0dG9uOiAkKCcjc3VibWl0YnV0dG9uJyksXG5cdCRzdGF0dXNUb2dnbGU6ICQoJyNtb2R1bGUtc3RhdHVzLXRvZ2dsZScpLFxuXHQkbW9kdWxlU3RhdHVzOiAkKCcjc3RhdHVzJyksXG5cdCRkaXJydHlGaWVsZDogJCgnI2RpcnJ0eScpLFxuXHQkdXNlcnNDaGVja0JveGVzOiAkKCcjZXh0ZW5zaW9ucy10YWJsZSAuY2hlY2tib3gnKSxcblxuXHQkZ2xvYmFsU2VhcmNoOiAkKCcjZ2xvYmFsc2VhcmNoJyksXG5cdCRyZWNvcmRzVGFibGU6ICQoJyNleHRlcm5hbC1saW5lLXRhYmxlJyksXG5cdCRhZGROZXdCdXR0b246ICQoJyNhZGQtbmV3LWV4dGVybmFsLWxpbmUtYnV0dG9uJyksXG5cblx0aW5wdXROdW1iZXJKUVRQTDogJ2lucHV0LmV4dGVybmFsLW51bWJlcicsXG5cdCRtYXNrTGlzdDogbnVsbCxcblx0Z2V0TmV3UmVjb3Jkc0FKQVhVcmw6IGAke2dsb2JhbFJvb3RVcmx9bW9kdWxlLWJpdHJpeDI0LWludGVncmF0aW9uL2dldEV4dGVybmFsTGluZXNgLFxuXG5cdHZhbGlkYXRlUnVsZXM6IHtcblx0XHRwb3J0YWw6IHtcblx0XHRcdGlkZW50aWZpZXI6ICdwb3J0YWwnLFxuXHRcdFx0cnVsZXM6IFtcblx0XHRcdFx0e1xuXHRcdFx0XHRcdHR5cGU6ICdlbXB0eScsXG5cdFx0XHRcdFx0cHJvbXB0OiBnbG9iYWxUcmFuc2xhdGUubW9kX2IyNF9pX1ZhbGlkYXRlUG9ydGFsRW1wdHksXG5cdFx0XHRcdH0sXG5cdFx0XHRdLFxuXHRcdH0sXG5cdFx0Y2xpZW50X2lkOiB7XG5cdFx0XHRpZGVudGlmaWVyOiAnY2xpZW50X2lkJyxcblx0XHRcdHJ1bGVzOiBbXG5cdFx0XHRcdHtcblx0XHRcdFx0XHR0eXBlOiAnZW1wdHknLFxuXHRcdFx0XHRcdHByb21wdDogZ2xvYmFsVHJhbnNsYXRlLm1vZF9iMjRfaV9WYWxpZGF0ZUNsaWVudElERW1wdHksXG5cdFx0XHRcdH0sXG5cdFx0XHRdLFxuXHRcdH0sXG5cdFx0Y2xpZW50X3NlY3JldDoge1xuXHRcdFx0aWRlbnRpZmllcjogJ2NsaWVudF9zZWNyZXQnLFxuXHRcdFx0cnVsZXM6IFtcblx0XHRcdFx0e1xuXHRcdFx0XHRcdHR5cGU6ICdlbXB0eScsXG5cdFx0XHRcdFx0cHJvbXB0OiBnbG9iYWxUcmFuc2xhdGUubW9kX2IyNF9pX1ZhbGlkYXRlQ2xpZW50U2VjcmV0RW1wdHksXG5cdFx0XHRcdH0sXG5cdFx0XHRdLFxuXHRcdH0sXG5cdH0sXG5cdGluaXRpYWxpemUoKSB7XG5cdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi5jaGVja1N0YXR1c1RvZ2dsZSgpO1xuXHRcdHdpbmRvdy5hZGRFdmVudExpc3RlbmVyKCdNb2R1bGVTdGF0dXNDaGFuZ2VkJywgTW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi5jaGVja1N0YXR1c1RvZ2dsZSk7XG5cdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi5pbml0aWFsaXplRm9ybSgpO1xuXG5cdFx0JCgnLmF2YXRhcicpLmVhY2goKCkgPT4ge1xuXHRcdFx0aWYgKCQodGhpcykuYXR0cignc3JjJykgPT09ICcnKSB7XG5cdFx0XHRcdCQodGhpcykuYXR0cignc3JjJywgYCR7Z2xvYmFsUm9vdFVybH1hc3NldHMvaW1nL3Vua25vd25QZXJzb24uanBnYCk7XG5cdFx0XHR9XG5cdFx0fSk7XG5cblx0XHQkKCcjZXh0ZW5zaW9ucy1tZW51IC5pdGVtJykudGFiKCk7XG5cblx0XHQkKCcjZXh0ZW5zaW9ucy10YWJsZScpLkRhdGFUYWJsZSh7XG5cdFx0XHRsZW5ndGhDaGFuZ2U6IGZhbHNlLFxuXHRcdFx0cGFnaW5nOiBmYWxzZSxcblx0XHRcdGNvbHVtbnM6IFtcblx0XHRcdFx0eyBvcmRlcmFibGU6IGZhbHNlLCBzZWFyY2hhYmxlOiBmYWxzZSB9LFxuXHRcdFx0XHRudWxsLFxuXHRcdFx0XHRudWxsLFxuXHRcdFx0XHRudWxsLFxuXHRcdFx0XSxcblx0XHRcdG9yZGVyOiBbMSwgJ2FzYyddLFxuXHRcdFx0bGFuZ3VhZ2U6IFNlbWFudGljTG9jYWxpemF0aW9uLmRhdGFUYWJsZUxvY2FsaXNhdGlvbixcblx0XHR9KTtcblxuXHRcdE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uJHVzZXJzQ2hlY2tCb3hlcy5jaGVja2JveCh7XG5cdFx0XHRvbkNoYW5nZSgpIHtcblx0XHRcdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi4kZGlycnR5RmllbGQudmFsKE1hdGgucmFuZG9tKCkpO1xuXHRcdFx0XHRNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLiRkaXJydHlGaWVsZC50cmlnZ2VyKCdjaGFuZ2UnKTtcblx0XHRcdH0sXG5cdFx0XHRvbkNoZWNrZWQoKSB7XG5cdFx0XHRcdGNvbnN0IG51bWJlciA9ICQodGhpcykuYXR0cignZGF0YS12YWx1ZScpO1xuXHRcdFx0XHQkKGAjJHtudW1iZXJ9IC5kaXNhYmlsaXR5YCkucmVtb3ZlQ2xhc3MoJ2Rpc2FibGVkJyk7XG5cdFx0XHR9LFxuXHRcdFx0b25VbmNoZWNrZWQoKSB7XG5cdFx0XHRcdGNvbnN0IG51bWJlciA9ICQodGhpcykuYXR0cignZGF0YS12YWx1ZScpO1xuXHRcdFx0XHQkKGAjJHtudW1iZXJ9IC5kaXNhYmlsaXR5YCkuYWRkQ2xhc3MoJ2Rpc2FibGVkJyk7XG5cdFx0XHR9LFxuXHRcdH0pO1xuXHRcdE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uJHVzZXJzQ2hlY2tCb3hlcy5jaGVja2JveCgnYXR0YWNoIGV2ZW50cycsICcuY2hlY2suYnV0dG9uJywgJ2NoZWNrJyk7XG5cdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi4kdXNlcnNDaGVja0JveGVzLmNoZWNrYm94KCdhdHRhY2ggZXZlbnRzJywgJy51bmNoZWNrLmJ1dHRvbicsICd1bmNoZWNrJyk7XG5cblx0XHRNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLiRnbG9iYWxTZWFyY2gub24oJ2tleXVwJywgKGUpID0+IHtcblx0XHRcdGlmIChlLmtleUNvZGUgPT09IDEzXG5cdFx0XHRcdHx8IGUua2V5Q29kZSA9PT0gOFxuXHRcdFx0XHR8fCBNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLiRnbG9iYWxTZWFyY2gudmFsKCkubGVuZ3RoID09PSAwKSB7XG5cdFx0XHRcdGNvbnN0IHRleHQgPSBgJHtNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLiRnbG9iYWxTZWFyY2gudmFsKCl9YDtcblx0XHRcdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi5hcHBseUZpbHRlcih0ZXh0KTtcblx0XHRcdH1cblx0XHR9KTtcblxuXHRcdE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uJHJlY29yZHNUYWJsZS5kYXRhVGFibGUoe1xuXHRcdFx0c2VydmVyU2lkZTogdHJ1ZSxcblx0XHRcdHByb2Nlc3Npbmc6IHRydWUsXG5cdFx0XHRhamF4OiB7XG5cdFx0XHRcdHVybDogTW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi5nZXROZXdSZWNvcmRzQUpBWFVybCxcblx0XHRcdFx0dHlwZTogJ1BPU1QnLFxuXHRcdFx0XHRkYXRhU3JjOiAnZGF0YScsXG5cdFx0XHR9LFxuXHRcdFx0Y29sdW1uczogW1xuXHRcdFx0XHR7IGRhdGE6IG51bGwgfSxcblx0XHRcdFx0eyBkYXRhOiAnbmFtZScgfSxcblx0XHRcdFx0eyBkYXRhOiAnbnVtYmVyJyB9LFxuXHRcdFx0XHR7IGRhdGE6ICdhbGlhcycgfSxcblx0XHRcdFx0eyBkYXRhOiBudWxsIH0sXG5cdFx0XHRdLFxuXHRcdFx0cGFnaW5nOiB0cnVlLFxuXHRcdFx0Ly8gc2Nyb2xsWTogJCh3aW5kb3cpLmhlaWdodCgpIC0gTW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi4kcmVjb3Jkc1RhYmxlLm9mZnNldCgpLnRvcC0yMDAsXG5cdFx0XHQvLyBzdGF0ZVNhdmU6IHRydWUsXG5cdFx0XHRzRG9tOiAncnRpcCcsXG5cdFx0XHRkZWZlclJlbmRlcjogdHJ1ZSxcblx0XHRcdHBhZ2VMZW5ndGg6IDE3LFxuXHRcdFx0YkF1dG9XaWR0aDogZmFsc2UsXG5cblx0XHRcdC8vIHNjcm9sbENvbGxhcHNlOiB0cnVlLFxuXHRcdFx0Ly8gc2Nyb2xsZXI6IHRydWUsXG5cdFx0XHQvKipcblx0XHRcdCAqINCa0L7QvdGB0YLRgNGD0LrRgtC+0YAg0YHRgtGA0L7QutC4INC30LDQv9C40YHQuFxuXHRcdFx0ICogQHBhcmFtIHJvd1xuXHRcdFx0ICogQHBhcmFtIGRhdGFcblx0XHRcdCAqL1xuXHRcdFx0Y3JlYXRlZFJvdyhyb3csIGRhdGEpIHtcblx0XHRcdFx0Y29uc3QgdGVtcGxhdGVOYW1lID1cblx0XHRcdFx0XHQnPGRpdiBjbGFzcz1cInVpIHRyYW5zcGFyZW50IGZsdWlkIGlucHV0IGlubGluZS1lZGl0XCI+JyArXG5cdFx0XHRcdFx0YDxpbnB1dCBjbGFzcz1cImV4dGVybmFsLW5hbWVcIiB0eXBlPVwidGV4dFwiIGRhdGEtdmFsdWU9XCIke2RhdGEubmFtZX1cIiB2YWx1ZT1cIiR7ZGF0YS5uYW1lfVwiPmAgK1xuXHRcdFx0XHRcdCc8L2Rpdj4nO1xuXG5cdFx0XHRcdGNvbnN0IHRlbXBsYXRlTnVtYmVyID1cblx0XHRcdFx0XHQnPGRpdiBjbGFzcz1cInVpIHRyYW5zcGFyZW50IGZsdWlkIGlucHV0IGlubGluZS1lZGl0XCI+JyArXG5cdFx0XHRcdFx0YDxpbnB1dCBjbGFzcz1cImV4dGVybmFsLW51bWJlclwiIHR5cGU9XCJ0ZXh0XCIgZGF0YS12YWx1ZT1cIiR7ZGF0YS5udW1iZXJ9XCIgdmFsdWU9XCIke2RhdGEubnVtYmVyfVwiPmAgK1xuXHRcdFx0XHRcdCc8L2Rpdj4nO1xuXG5cdFx0XHRcdGNvbnN0IHRlbXBsYXRlRGlkID1cblx0XHRcdFx0XHQnPGRpdiBjbGFzcz1cInVpIHRyYW5zcGFyZW50IGlucHV0IGlubGluZS1lZGl0XCI+JyArXG5cdFx0XHRcdFx0YDxpbnB1dCBjbGFzcz1cImV4dGVybmFsLWFsaWFzZXNcIiB0eXBlPVwidGV4dFwiIGRhdGEtdmFsdWU9XCIke2RhdGEuYWxpYXN9XCIgdmFsdWU9XCIke2RhdGEuYWxpYXN9XCI+YCArXG5cdFx0XHRcdFx0JzwvZGl2Pic7XG5cblx0XHRcdFx0Y29uc3QgdGVtcGxhdGVEZWxldGVCdXR0b24gPSAnPGRpdiBjbGFzcz1cInVpIHNtYWxsIGJhc2ljIGljb24gYnV0dG9ucyBhY3Rpb24tYnV0dG9uc1wiPicgK1xuXHRcdFx0XHRcdGA8YSBocmVmPVwiI1wiIGRhdGEtdmFsdWUgPSBcIiR7ZGF0YS5pZH1cImAgK1xuXHRcdFx0XHRcdGAgY2xhc3M9XCJ1aSBidXR0b24gZGVsZXRlIHR3by1zdGVwcy1kZWxldGUgcG9wdXBlZFwiIGRhdGEtY29udGVudD1cIiR7Z2xvYmFsVHJhbnNsYXRlLmJ0X1Rvb2xUaXBEZWxldGV9XCI+YCArXG5cdFx0XHRcdFx0JzxpIGNsYXNzPVwiaWNvbiB0cmFzaCByZWRcIj48L2k+PC9hPjwvZGl2Pic7XG5cblx0XHRcdFx0JCgndGQnLCByb3cpLmVxKDApLmh0bWwoJzxpIGNsYXNzPVwidWkgdXNlciBjaXJjbGUgaWNvblwiPjwvaT4nKTtcblx0XHRcdFx0JCgndGQnLCByb3cpLmVxKDEpLmh0bWwodGVtcGxhdGVOYW1lKTtcblx0XHRcdFx0JCgndGQnLCByb3cpLmVxKDIpLmh0bWwodGVtcGxhdGVOdW1iZXIpO1xuXHRcdFx0XHQkKCd0ZCcsIHJvdykuZXEoMykuaHRtbCh0ZW1wbGF0ZURpZCk7XG5cdFx0XHRcdCQoJ3RkJywgcm93KS5lcSg0KS5odG1sKHRlbXBsYXRlRGVsZXRlQnV0dG9uKTtcblx0XHRcdH0sXG5cdFx0XHQvKipcblx0XHRcdCAqIERyYXcgZXZlbnQgLSBmaXJlZCBvbmNlIHRoZSB0YWJsZSBoYXMgY29tcGxldGVkIGEgZHJhdy5cblx0XHRcdCAqL1xuXHRcdFx0ZHJhd0NhbGxiYWNrKCkge1xuXHRcdFx0XHRNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLmluaXRpYWxpemVJbnB1dG1hc2soJChNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLmlucHV0TnVtYmVySlFUUEwpKTtcblx0XHRcdH0sXG5cdFx0XHRsYW5ndWFnZTogU2VtYW50aWNMb2NhbGl6YXRpb24uZGF0YVRhYmxlTG9jYWxpc2F0aW9uLFxuXHRcdFx0b3JkZXJpbmc6IGZhbHNlLFxuXHRcdH0pO1xuXHRcdE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uZGF0YVRhYmxlID0gTW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi4kcmVjb3Jkc1RhYmxlLkRhdGFUYWJsZSgpO1xuXG5cdFx0Ly8g0JTQstC+0LnQvdC+0Lkg0LrQu9C40Log0L3QsCDQv9C+0LvQtSDQstCy0L7QtNCwINC90L7QvNC10YDQsFxuXHRcdCQoJ2JvZHknKS5vbignZm9jdXNpbicsICcuZXh0ZXJuYWwtbmFtZSwgLmV4dGVybmFsLW51bWJlciwgLmV4dGVybmFsLWFsaWFzZXMgJywgKGUpID0+IHtcblx0XHRcdCQoZS50YXJnZXQpLnRyYW5zaXRpb24oJ2dsb3cnKTtcblx0XHRcdCQoZS50YXJnZXQpLmNsb3Nlc3QoJ2RpdicpXG5cdFx0XHRcdC5yZW1vdmVDbGFzcygndHJhbnNwYXJlbnQnKVxuXHRcdFx0XHQuYWRkQ2xhc3MoJ2NoYW5nZWQtZmllbGQnKTtcblx0XHRcdCQoZS50YXJnZXQpLmF0dHIoJ3JlYWRvbmx5JywgZmFsc2UpO1xuXHRcdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi4kZGlycnR5RmllbGQudmFsKE1hdGgucmFuZG9tKCkpO1xuXHRcdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi4kZGlycnR5RmllbGQudHJpZ2dlcignY2hhbmdlJyk7XG5cdFx0fSk7XG5cblx0XHQvLyDQntGC0L/RgNCw0LLQutCwINGE0L7RgNC80Ysg0L3QsCDRgdC10YDQstC10YAg0L/QviDRg9GF0L7QtNGDINGBINC/0L7Qu9GPINCy0LLQvtC00LBcblx0XHQkKCdib2R5Jykub24oJ2ZvY3Vzb3V0JywgJy5leHRlcm5hbC1uYW1lLCAuZXh0ZXJuYWwtbnVtYmVyLCAuZXh0ZXJuYWwtYWxpYXNlcycsIChlKSA9PiB7XG5cdFx0XHQkKGUudGFyZ2V0KS5jbG9zZXN0KCdkaXYnKVxuXHRcdFx0XHQuYWRkQ2xhc3MoJ3RyYW5zcGFyZW50Jylcblx0XHRcdFx0LnJlbW92ZUNsYXNzKCdjaGFuZ2VkLWZpZWxkJyk7XG5cdFx0XHQkKGUudGFyZ2V0KS5hdHRyKCdyZWFkb25seScsIHRydWUpO1xuXHRcdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi4kZGlycnR5RmllbGQudmFsKE1hdGgucmFuZG9tKCkpO1xuXHRcdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi4kZGlycnR5RmllbGQudHJpZ2dlcignY2hhbmdlJyk7XG5cdFx0fSk7XG5cblx0XHQvLyDQmtC70LjQuiDQvdCwINC60L3QvtC/0LrRgyDRg9C00LDQu9C40YLRjFxuXHRcdCQoJ2JvZHknKS5vbignY2xpY2snLCAnYS5kZWxldGUnLCAoZSkgPT4ge1xuXHRcdFx0ZS5wcmV2ZW50RGVmYXVsdCgpO1xuXHRcdFx0JChlLnRhcmdldCkuY2xvc2VzdCgndHInKS5yZW1vdmUoKTtcblx0XHRcdGlmIChNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLiRyZWNvcmRzVGFibGUuZmluZCgndGJvZHkgPiB0cicpLmxlbmd0aD09PTApe1xuXHRcdFx0XHRNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLiRyZWNvcmRzVGFibGUuZmluZCgndGJvZHknKS5hcHBlbmQoJzx0ciBjbGFzcz1cIm9kZFwiPjwvdHI+Jyk7XG5cdFx0XHR9XG5cdFx0XHRNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLiRkaXJydHlGaWVsZC52YWwoTWF0aC5yYW5kb20oKSk7XG5cdFx0XHRNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLiRkaXJydHlGaWVsZC50cmlnZ2VyKCdjaGFuZ2UnKTtcblx0XHR9KTtcblxuXHRcdC8vINCU0L7QsdCw0LLQu9C10L3QuNC1INC90L7QstC+0Lkg0YHRgtGA0L7QutC4XG5cdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi4kYWRkTmV3QnV0dG9uLm9uKCdjbGljaycsIChlKSA9PiB7XG5cdFx0XHRlLnByZXZlbnREZWZhdWx0KCk7XG5cdFx0XHQkKCcuZGF0YVRhYmxlc19lbXB0eScpLnJlbW92ZSgpO1xuXHRcdFx0Y29uc3QgaWQgPSBgbmV3JHtNYXRoLmZsb29yKE1hdGgucmFuZG9tKCkgKiBNYXRoLmZsb29yKDUwMCkpfWA7XG5cdFx0XHRjb25zdCByb3dUZW1wbGF0ZSA9IGA8dHIgaWQ9XCIke2lkfVwiIGNsYXNzPVwiZXh0LWxpbmUtcm93XCI+YCArXG5cdFx0XHRcdCc8dGQ+PGkgY2xhc3M9XCJ1aSB1c2VyIGNpcmNsZSBpY29uXCI+PC9pPjwvdGQ+JyArXG5cdFx0XHRcdCc8dGQ+PGRpdiBjbGFzcz1cInVpIGZsdWlkIGlucHV0IGlubGluZS1lZGl0IGNoYW5nZWQtZmllbGRcIj48aW5wdXQgY2xhc3M9XCJleHRlcm5hbC1uYW1lXCIgdHlwZT1cInRleHRcIiBkYXRhLXZhbHVlPVwiXCIgdmFsdWU9XCJcIj48L2Rpdj48L3RkPicgK1xuXHRcdFx0XHQnPHRkPjxkaXYgY2xhc3M9XCJ1aSBpbnB1dCBpbmxpbmUtZWRpdCBjaGFuZ2VkLWZpZWxkXCI+PGlucHV0IGNsYXNzPVwiZXh0ZXJuYWwtbnVtYmVyXCIgdHlwZT1cInRleHRcIiBkYXRhLXZhbHVlPVwiXCIgdmFsdWU9XCJcIj48L2Rpdj48L3RkPicgK1xuXHRcdFx0XHQnPHRkPjxkaXYgY2xhc3M9XCJ1aSBpbnB1dCBpbmxpbmUtZWRpdCBjaGFuZ2VkLWZpZWxkXCI+PGlucHV0IGNsYXNzPVwiZXh0ZXJuYWwtYWxpYXNlc1wiIHR5cGU9XCJ0ZXh0XCIgZGF0YS12YWx1ZT1cIlwiIHZhbHVlPVwiXCI+PC9kaXY+PC90ZD4nICtcblx0XHRcdFx0Jzx0ZD48ZGl2IGNsYXNzPVwidWkgc21hbGwgYmFzaWMgaWNvbiBidXR0b25zIGFjdGlvbi1idXR0b25zXCI+JyArXG5cdFx0XHRcdGA8YSBocmVmPVwiI1wiIGNsYXNzPVwidWkgYnV0dG9uIGRlbGV0ZSB0d28tc3RlcHMtZGVsZXRlIHBvcHVwZWRcIiBkYXRhLXZhbHVlID0gXCJuZXdcIiBkYXRhLWNvbnRlbnQ9XCIke2dsb2JhbFRyYW5zbGF0ZS5idF9Ub29sVGlwRGVsZXRlfVwiPmAgK1xuXHRcdFx0XHQnPGkgY2xhc3M9XCJpY29uIHRyYXNoIHJlZFwiPjwvaT48L2E+PC9kaXY+PC90ZD4nICtcblx0XHRcdFx0JzwvdHI+Jztcblx0XHRcdE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uJHJlY29yZHNUYWJsZS5maW5kKCd0Ym9keSA+IHRyOmZpcnN0JykuYmVmb3JlKHJvd1RlbXBsYXRlKTtcblx0XHRcdCQoYHRyIyR7aWR9IGlucHV0YCkudHJhbnNpdGlvbignZ2xvdycpO1xuXHRcdFx0JChgdHIjJHtpZH0gLmV4dGVybmFsLW5hbWVgKS5mb2N1cygpO1xuXHRcdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi5pbml0aWFsaXplSW5wdXRtYXNrKCQoYHRyIyR7aWR9IC5leHRlcm5hbC1udW1iZXJgKSk7XG5cdFx0XHRNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLiRkaXJydHlGaWVsZC52YWwoTWF0aC5yYW5kb20oKSk7XG5cdFx0XHRNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLiRkaXJydHlGaWVsZC50cmlnZ2VyKCdjaGFuZ2UnKTtcblx0XHR9KTtcblx0fSxcblx0LyoqXG5cdCAqINCY0LfQvNC10L3QtdC90LjQtSDRgdGC0LDRgtGD0YHQsCDQutC90L7Qv9C+0Log0L/RgNC4INC40LfQvNC10L3QtdC90LjQuCDRgdGC0LDRgtGD0YHQsCDQvNC+0LTRg9C70Y9cblx0ICovXG5cdGNoZWNrU3RhdHVzVG9nZ2xlKCkge1xuXHRcdGlmIChNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLiRzdGF0dXNUb2dnbGUuY2hlY2tib3goJ2lzIGNoZWNrZWQnKSkge1xuXHRcdFx0JCgnW2RhdGEtdGFiID0gXCJnZW5lcmFsXCJdIC5kaXNhYmlsaXR5JykucmVtb3ZlQ2xhc3MoJ2Rpc2FibGVkJyk7XG5cdFx0XHRNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLiRtb2R1bGVTdGF0dXMuc2hvdygpO1xuXHRcdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvblN0YXR1c1dvcmtlci5pbml0aWFsaXplKCk7XG5cdFx0fSBlbHNlIHtcblx0XHRcdE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uJG1vZHVsZVN0YXR1cy5oaWRlKCk7XG5cdFx0XHQkKCdbZGF0YS10YWIgPSBcImdlbmVyYWxcIl0gLmRpc2FiaWxpdHknKS5hZGRDbGFzcygnZGlzYWJsZWQnKTtcblx0XHR9XG5cdH0sXG5cblx0LyoqXG5cdCAqINCY0L3QuNGG0LjQsNC70LjQt9C40YDRg9C10YIg0LrRgNCw0YHQuNCy0L7QtSDQv9GA0LXQtNGB0YLQsNCy0LvQtdC90LjQtSDQvdC+0LzQtdGA0L7QslxuXHQgKi9cblx0aW5pdGlhbGl6ZUlucHV0bWFzaygkZWwpIHtcblx0XHRpZiAoTW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi4kbWFza0xpc3QgPT09IG51bGwpIHtcblx0XHRcdC8vINCf0L7QtNCz0L7RgtC+0LLQuNC8INGC0LDQsdC70LjRhtGDINC00LvRjyDRgdC+0YDRgtC40YDQvtCy0LrQuFxuXHRcdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi4kbWFza0xpc3QgPSAkLm1hc2tzU29ydChJbnB1dE1hc2tQYXR0ZXJucywgWycjJ10sIC9bMC05XXwjLywgJ21hc2snKTtcblx0XHR9XG5cdFx0JGVsLmlucHV0bWFza3Moe1xuXHRcdFx0aW5wdXRtYXNrOiB7XG5cdFx0XHRcdGRlZmluaXRpb25zOiB7XG5cdFx0XHRcdFx0JyMnOiB7XG5cdFx0XHRcdFx0XHR2YWxpZGF0b3I6ICdbMC05XScsXG5cdFx0XHRcdFx0XHRjYXJkaW5hbGl0eTogMSxcblx0XHRcdFx0XHR9LFxuXHRcdFx0XHR9LFxuXHRcdFx0XHRzaG93TWFza09uSG92ZXI6IGZhbHNlLFxuXHRcdFx0XHQvLyBvbmNsZWFyZWQ6IGV4dGVuc2lvbi5jYk9uQ2xlYXJlZE1vYmlsZU51bWJlcixcblx0XHRcdFx0b25jb21wbGV0ZTogTW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi5jYk9uQ29tcGxldGVOdW1iZXIsXG5cdFx0XHRcdC8vIGNsZWFySW5jb21wbGV0ZTogdHJ1ZSxcblx0XHRcdFx0b25CZWZvcmVQYXN0ZTogTW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi5jYk9uTnVtYmVyQmVmb3JlUGFzdGUsXG5cdFx0XHRcdC8vIHJlZ2V4OiAvXFxEKy8sXG5cdFx0XHR9LFxuXHRcdFx0bWF0Y2g6IC9bMC05XS8sXG5cdFx0XHRyZXBsYWNlOiAnOScsXG5cdFx0XHRsaXN0OiBNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLiRtYXNrTGlzdCxcblx0XHRcdGxpc3RLZXk6ICdtYXNrJyxcblxuXHRcdH0pO1xuXHR9LFxuXHQvKipcblx0ICog0J7Rh9C40YHRgtC60LAg0L3QvtC80LXRgNCwINC/0LXRgNC10LQg0LLRgdGC0LDQstC60L7QuSDQvtGCINC70LjRiNC90LjRhSDRgdC40LzQstC+0LvQvtCyXG5cdCAqIEByZXR1cm5zIHtib29sZWFufCp8dm9pZHxzdHJpbmd9XG5cdCAqL1xuXHRjYk9uTnVtYmVyQmVmb3JlUGFzdGUocGFzdGVkVmFsdWUpIHtcblx0XHRyZXR1cm4gcGFzdGVkVmFsdWUucmVwbGFjZSgvXFxEKy9nLCAnJyk7XG5cdH0sXG5cdC8qKlxuXHQgKiDQn9C+0YHQu9C1INCy0LLQvtC00LAg0L3QvtC80LXRgNCwXG5cdCAqL1xuXHRjYk9uQ29tcGxldGVOdW1iZXIoZSl7XG5cdFx0Y29uc3QgZGlkRWwgPSAkKGUudGFyZ2V0KS5jbG9zZXN0KCd0cicpLmZpbmQoJ2lucHV0LmV4dGVybmFsLWFsaWFzZXMnKTtcblx0XHRpZiAoZGlkRWwudmFsKCk9PT0nJyl7XG5cdFx0XHRkaWRFbC52YWwoJChlLnRhcmdldCkuaW5wdXRtYXNrKCd1bm1hc2tlZHZhbHVlJykpO1xuXHRcdH1cblx0fSxcblx0LyoqXG5cdCAqINCa0L7Qu9Cx0LXQuiDQv9C10YDQtdC0INC+0YLQv9GA0LDQstC60L7QuSDRhNC+0YDQvNGLXG5cdCAqIEBwYXJhbSBzZXR0aW5nc1xuXHQgKiBAcmV0dXJucyB7Kn1cblx0ICovXG5cdGNiQmVmb3JlU2VuZEZvcm0oc2V0dGluZ3MpIHtcblx0XHRjb25zdCByZXN1bHQgPSBzZXR0aW5ncztcblx0XHRyZXN1bHQuZGF0YSA9IE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uJGZvcm1PYmouZm9ybSgnZ2V0IHZhbHVlcycpO1xuXG5cdFx0Y29uc3QgYXJyRXh0ZXJuYWxMaW5lcyA9IFtdO1xuXHRcdCQoJyNleHRlcm5hbC1saW5lLXRhYmxlIHRyJykuZWFjaCgoaW5kZXgsIG9iaikgPT4ge1xuXHRcdFx0YXJyRXh0ZXJuYWxMaW5lcy5wdXNoKHtcblx0XHRcdFx0aWQ6ICQob2JqKS5hdHRyKCdpZCcpLFxuXHRcdFx0XHRuYW1lOiAkKG9iaikuZmluZCgnaW5wdXQuZXh0ZXJuYWwtbmFtZScpLnZhbCgpLFxuXHRcdFx0XHRudW1iZXI6ICQob2JqKS5maW5kKCdpbnB1dC5leHRlcm5hbC1udW1iZXInKS52YWwoKSxcblx0XHRcdFx0YWxpYXM6ICQob2JqKS5maW5kKCdpbnB1dC5leHRlcm5hbC1hbGlhc2VzJykudmFsKCksXG5cdFx0XHR9KTtcblx0XHR9KTtcblx0XHRyZXN1bHQuZGF0YS5leHRlcm5hbExpbmVzID0gSlNPTi5zdHJpbmdpZnkoYXJyRXh0ZXJuYWxMaW5lcyk7XG5cblx0XHRyZXR1cm4gcmVzdWx0O1xuXHR9LFxuXG5cdC8qKlxuXHQgKiDQmtC+0LvQsdC10Log0L/QvtGB0LvQtSDQvtGC0L/RgNCw0LLQutC4INGE0L7RgNC80Ytcblx0ICovXG5cdGNiQWZ0ZXJTZW5kRm9ybSgpIHtcblx0XHRNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uU3RhdHVzV29ya2VyLmluaXRpYWxpemUoKTtcblx0fSxcblx0aW5pdGlhbGl6ZUZvcm0oKSB7XG5cdFx0Rm9ybS4kZm9ybU9iaiA9IE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uJGZvcm1PYmo7XG5cdFx0Rm9ybS51cmwgPSBgJHtnbG9iYWxSb290VXJsfW1vZHVsZS1iaXRyaXgyNC1pbnRlZ3JhdGlvbi9zYXZlYDtcblx0XHRGb3JtLnZhbGlkYXRlUnVsZXMgPSBNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLnZhbGlkYXRlUnVsZXM7XG5cdFx0Rm9ybS5jYkJlZm9yZVNlbmRGb3JtID0gTW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi5jYkJlZm9yZVNlbmRGb3JtO1xuXHRcdEZvcm0uY2JBZnRlclNlbmRGb3JtID0gTW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi5jYkFmdGVyU2VuZEZvcm07XG5cdFx0Rm9ybS5pbml0aWFsaXplKCk7XG5cdH0sXG59O1xuXG4kKGRvY3VtZW50KS5yZWFkeSgoKSA9PiB7XG5cdE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uaW5pdGlhbGl6ZSgpO1xufSk7XG5cbiJdfQ==