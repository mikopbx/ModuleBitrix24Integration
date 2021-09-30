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
  $elRegion: $('#b24_region'),
  $elAppData: $('#b24-app-data'),
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
  onChangeRegion: function onChangeRegion() {
    var region = ModuleBitrix24Integration.$elRegion.val();

    if (region === 'REST_API') {
      ModuleBitrix24Integration.$elAppData.show();
    } else {
      ModuleBitrix24Integration.$elAppData.hide();
    }
  },
  initialize: function initialize() {
    var _this = this;

    ModuleBitrix24Integration.checkStatusToggle();
    window.addEventListener('ModuleStatusChanged', ModuleBitrix24Integration.checkStatusToggle);
    ModuleBitrix24Integration.initializeForm();
    $('.b24_regions-select').dropdown();
    ModuleBitrix24Integration.onChangeRegion();
    ModuleBitrix24Integration.$elRegion.change(ModuleBitrix24Integration.onChangeRegion);
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
    result.data.portal = result.data.portal.replace(/^(https?|http):\/\//, '');
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
//# sourceMappingURL=data:application/json;charset=utf-8;base64,eyJ2ZXJzaW9uIjozLCJzb3VyY2VzIjpbIi4uLy4uL2pzL3NyYy9tb2R1bGUtYml0cml4MjQtaW50ZWdyYXRpb24taW5kZXguanMiXSwibmFtZXMiOlsiTW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbiIsIiRmb3JtT2JqIiwiJCIsIiRzdWJtaXRCdXR0b24iLCIkc3RhdHVzVG9nZ2xlIiwiJG1vZHVsZVN0YXR1cyIsIiRkaXJydHlGaWVsZCIsIiR1c2Vyc0NoZWNrQm94ZXMiLCIkZ2xvYmFsU2VhcmNoIiwiJHJlY29yZHNUYWJsZSIsIiRhZGROZXdCdXR0b24iLCIkZWxSZWdpb24iLCIkZWxBcHBEYXRhIiwiaW5wdXROdW1iZXJKUVRQTCIsIiRtYXNrTGlzdCIsImdldE5ld1JlY29yZHNBSkFYVXJsIiwiZ2xvYmFsUm9vdFVybCIsInZhbGlkYXRlUnVsZXMiLCJwb3J0YWwiLCJpZGVudGlmaWVyIiwicnVsZXMiLCJ0eXBlIiwicHJvbXB0IiwiZ2xvYmFsVHJhbnNsYXRlIiwibW9kX2IyNF9pX1ZhbGlkYXRlUG9ydGFsRW1wdHkiLCJjbGllbnRfaWQiLCJtb2RfYjI0X2lfVmFsaWRhdGVDbGllbnRJREVtcHR5IiwiY2xpZW50X3NlY3JldCIsIm1vZF9iMjRfaV9WYWxpZGF0ZUNsaWVudFNlY3JldEVtcHR5Iiwib25DaGFuZ2VSZWdpb24iLCJyZWdpb24iLCJ2YWwiLCJzaG93IiwiaGlkZSIsImluaXRpYWxpemUiLCJjaGVja1N0YXR1c1RvZ2dsZSIsIndpbmRvdyIsImFkZEV2ZW50TGlzdGVuZXIiLCJpbml0aWFsaXplRm9ybSIsImRyb3Bkb3duIiwiY2hhbmdlIiwiZWFjaCIsImF0dHIiLCJ0YWIiLCJEYXRhVGFibGUiLCJsZW5ndGhDaGFuZ2UiLCJwYWdpbmciLCJjb2x1bW5zIiwib3JkZXJhYmxlIiwic2VhcmNoYWJsZSIsIm9yZGVyIiwibGFuZ3VhZ2UiLCJTZW1hbnRpY0xvY2FsaXphdGlvbiIsImRhdGFUYWJsZUxvY2FsaXNhdGlvbiIsImNoZWNrYm94Iiwib25DaGFuZ2UiLCJNYXRoIiwicmFuZG9tIiwidHJpZ2dlciIsIm9uQ2hlY2tlZCIsIm51bWJlciIsInJlbW92ZUNsYXNzIiwib25VbmNoZWNrZWQiLCJhZGRDbGFzcyIsIm9uIiwiZSIsImtleUNvZGUiLCJsZW5ndGgiLCJ0ZXh0IiwiYXBwbHlGaWx0ZXIiLCJkYXRhVGFibGUiLCJzZXJ2ZXJTaWRlIiwicHJvY2Vzc2luZyIsImFqYXgiLCJ1cmwiLCJkYXRhU3JjIiwiZGF0YSIsInNEb20iLCJkZWZlclJlbmRlciIsInBhZ2VMZW5ndGgiLCJiQXV0b1dpZHRoIiwiY3JlYXRlZFJvdyIsInJvdyIsInRlbXBsYXRlTmFtZSIsIm5hbWUiLCJ0ZW1wbGF0ZU51bWJlciIsInRlbXBsYXRlRGlkIiwiYWxpYXMiLCJ0ZW1wbGF0ZURlbGV0ZUJ1dHRvbiIsImlkIiwiYnRfVG9vbFRpcERlbGV0ZSIsImVxIiwiaHRtbCIsImRyYXdDYWxsYmFjayIsImluaXRpYWxpemVJbnB1dG1hc2siLCJvcmRlcmluZyIsInRhcmdldCIsInRyYW5zaXRpb24iLCJjbG9zZXN0IiwicHJldmVudERlZmF1bHQiLCJyZW1vdmUiLCJmaW5kIiwiYXBwZW5kIiwiZmxvb3IiLCJyb3dUZW1wbGF0ZSIsImJlZm9yZSIsImZvY3VzIiwiTW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvblN0YXR1c1dvcmtlciIsIiRlbCIsIm1hc2tzU29ydCIsIklucHV0TWFza1BhdHRlcm5zIiwiaW5wdXRtYXNrcyIsImlucHV0bWFzayIsImRlZmluaXRpb25zIiwidmFsaWRhdG9yIiwiY2FyZGluYWxpdHkiLCJzaG93TWFza09uSG92ZXIiLCJvbmNvbXBsZXRlIiwiY2JPbkNvbXBsZXRlTnVtYmVyIiwib25CZWZvcmVQYXN0ZSIsImNiT25OdW1iZXJCZWZvcmVQYXN0ZSIsIm1hdGNoIiwicmVwbGFjZSIsImxpc3QiLCJsaXN0S2V5IiwicGFzdGVkVmFsdWUiLCJkaWRFbCIsImNiQmVmb3JlU2VuZEZvcm0iLCJzZXR0aW5ncyIsInJlc3VsdCIsImZvcm0iLCJhcnJFeHRlcm5hbExpbmVzIiwiaW5kZXgiLCJvYmoiLCJwdXNoIiwiZXh0ZXJuYWxMaW5lcyIsIkpTT04iLCJzdHJpbmdpZnkiLCJjYkFmdGVyU2VuZEZvcm0iLCJGb3JtIiwiZG9jdW1lbnQiLCJyZWFkeSJdLCJtYXBwaW5ncyI6Ijs7QUFBQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7O0FBRUE7QUFFQSxJQUFNQSx5QkFBeUIsR0FBRztBQUNqQ0MsRUFBQUEsUUFBUSxFQUFFQyxDQUFDLENBQUMsbUNBQUQsQ0FEc0I7QUFFakNDLEVBQUFBLGFBQWEsRUFBRUQsQ0FBQyxDQUFDLGVBQUQsQ0FGaUI7QUFHakNFLEVBQUFBLGFBQWEsRUFBRUYsQ0FBQyxDQUFDLHVCQUFELENBSGlCO0FBSWpDRyxFQUFBQSxhQUFhLEVBQUVILENBQUMsQ0FBQyxTQUFELENBSmlCO0FBS2pDSSxFQUFBQSxZQUFZLEVBQUVKLENBQUMsQ0FBQyxTQUFELENBTGtCO0FBTWpDSyxFQUFBQSxnQkFBZ0IsRUFBRUwsQ0FBQyxDQUFDLDZCQUFELENBTmM7QUFRakNNLEVBQUFBLGFBQWEsRUFBRU4sQ0FBQyxDQUFDLGVBQUQsQ0FSaUI7QUFTakNPLEVBQUFBLGFBQWEsRUFBRVAsQ0FBQyxDQUFDLHNCQUFELENBVGlCO0FBVWpDUSxFQUFBQSxhQUFhLEVBQUVSLENBQUMsQ0FBQywrQkFBRCxDQVZpQjtBQVdqQ1MsRUFBQUEsU0FBUyxFQUFFVCxDQUFDLENBQUMsYUFBRCxDQVhxQjtBQVlqQ1UsRUFBQUEsVUFBVSxFQUFFVixDQUFDLENBQUMsZUFBRCxDQVpvQjtBQWNqQ1csRUFBQUEsZ0JBQWdCLEVBQUUsdUJBZGU7QUFlakNDLEVBQUFBLFNBQVMsRUFBRSxJQWZzQjtBQWdCakNDLEVBQUFBLG9CQUFvQixZQUFLQyxhQUFMLGlEQWhCYTtBQWtCakNDLEVBQUFBLGFBQWEsRUFBRTtBQUNkQyxJQUFBQSxNQUFNLEVBQUU7QUFDUEMsTUFBQUEsVUFBVSxFQUFFLFFBREw7QUFFUEMsTUFBQUEsS0FBSyxFQUFFLENBQ047QUFDQ0MsUUFBQUEsSUFBSSxFQUFFLE9BRFA7QUFF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QUQsQ0FBT2hDLElBQVAsQ0FBWSx3QkFBWixFQUFzQ3RFLEdBQXRDO0FBSmMsT0FBdEI7QUFNQSxLQVBEO0FBUUFrRyxJQUFBQSxNQUFNLENBQUNyRCxJQUFQLENBQVkyRCxhQUFaLEdBQTRCQyxJQUFJLENBQUNDLFNBQUwsQ0FBZU4sZ0JBQWYsQ0FBNUI7QUFDQUYsSUFBQUEsTUFBTSxDQUFDckQsSUFBUCxDQUFZMUQsTUFBWixHQUFxQitHLE1BQU0sQ0FBQ3JELElBQVAsQ0FBWTFELE1BQVosQ0FBbUJ3RyxPQUFuQixDQUEyQixxQkFBM0IsRUFBa0QsRUFBbEQsQ0FBckI7QUFFQSxXQUFPTyxNQUFQO0FBQ0EsR0EzVGdDOztBQTZUakM7QUFDRDtBQUNBO0FBQ0NTLEVBQUFBLGVBaFVpQyw2QkFnVWY7QUFDakIvQixJQUFBQSxxQ0FBcUMsQ0FBQ3pFLFVBQXRDO0FBQ0EsR0FsVWdDO0FBbVVqQ0ksRUFBQUEsY0FuVWlDLDRCQW1VaEI7QUFDaEJxRyxJQUFBQSxJQUFJLENBQUMxSSxRQUFMLEdBQWdCRCx5QkFBeUIsQ0FBQ0MsUUFBMUM7QUFDQTBJLElBQUFBLElBQUksQ0FBQ2pFLEdBQUwsYUFBYzFELGFBQWQ7QUFDQTJILElBQUFBLElBQUksQ0FBQzFILGFBQUwsR0FBcUJqQix5QkFBeUIsQ0FBQ2lCLGFBQS9DO0FBQ0EwSCxJQUFBQSxJQUFJLENBQUNaLGdCQUFMLEdBQXdCL0gseUJBQXlCLENBQUMrSCxnQkFBbEQ7QUFDQVksSUFBQUEsSUFBSSxDQUFDRCxlQUFMLEdBQXVCMUkseUJBQXlCLENBQUMwSSxlQUFqRDtBQUNBQyxJQUFBQSxJQUFJLENBQUN6RyxVQUFMO0FBQ0E7QUExVWdDLENBQWxDO0FBNlVBaEMsQ0FBQyxDQUFDMEksUUFBRCxDQUFELENBQVlDLEtBQVosQ0FBa0IsWUFBTTtBQUN2QjdJLEVBQUFBLHlCQUF5QixDQUFDa0MsVUFBMUI7QUFDQSxDQUZEIiwic291cmNlc0NvbnRlbnQiOlsiLypcbiAqIENvcHlyaWdodCDCqSBNSUtPIExMQyAtIEFsbCBSaWdodHMgUmVzZXJ2ZWRcbiAqIFVuYXV0aG9yaXplZCBjb3B5aW5nIG9mIHRoaXMgZmlsZSwgdmlhIGFueSBtZWRpdW0gaXMgc3RyaWN0bHkgcHJvaGliaXRlZFxuICogUHJvcHJpZXRhcnkgYW5kIGNvbmZpZGVudGlhbFxuICogV3JpdHRlbiBieSBBbGV4ZXkgUG9ydG5vdiwgNSAyMDIwXG4gKi9cblxuLyogZ2xvYmFsIGdsb2JhbFJvb3RVcmwsIGdsb2JhbFRyYW5zbGF0ZSwgRm9ybSwgU2VtYW50aWNMb2NhbGl6YXRpb24sIElucHV0TWFza1BhdHRlcm5zICAqL1xuXG5jb25zdCBNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uID0ge1xuXHQkZm9ybU9iajogJCgnI21vZHVsZS1iaXRyaXgyNC1pbnRlZ3JhdGlvbi1mb3JtJyksXG5cdCRzdWJtaXRCdXR0b246ICQoJyNzdWJtaXRidXR0b24nKSxcblx0JHN0YXR1c1RvZ2dsZTogJCgnI21vZHVsZS1zdGF0dXMtdG9nZ2xlJyksXG5cdCRtb2R1bGVTdGF0dXM6ICQoJyNzdGF0dXMnKSxcblx0JGRpcnJ0eUZpZWxkOiAkKCcjZGlycnR5JyksXG5cdCR1c2Vyc0NoZWNrQm94ZXM6ICQoJyNleHRlbnNpb25zLXRhYmxlIC5jaGVja2JveCcpLFxuXG5cdCRnbG9iYWxTZWFyY2g6ICQoJyNnbG9iYWxzZWFyY2gnKSxcblx0JHJlY29yZHNUYWJsZTogJCgnI2V4dGVybmFsLWxpbmUtdGFibGUnKSxcblx0JGFkZE5ld0J1dHRvbjogJCgnI2FkZC1uZXctZXh0ZXJuYWwtbGluZS1idXR0b24nKSxcblx0JGVsUmVnaW9uOiAkKCcjYjI0X3JlZ2lvbicpLFxuXHQkZWxBcHBEYXRhOiAkKCcjYjI0LWFwcC1kYXRhJyksXG5cblx0aW5wdXROdW1iZXJKUVRQTDogJ2lucHV0LmV4dGVybmFsLW51bWJlcicsXG5cdCRtYXNrTGlzdDogbnVsbCxcblx0Z2V0TmV3UmVjb3Jkc0FKQVhVcmw6IGAke2dsb2JhbFJvb3RVcmx9bW9kdWxlLWJpdHJpeDI0LWludGVncmF0aW9uL2dldEV4dGVybmFsTGluZXNgLFxuXG5cdHZhbGlkYXRlUnVsZXM6IHtcblx0XHRwb3J0YWw6IHtcblx0XHRcdGlkZW50aWZpZXI6ICdwb3J0YWwnLFxuXHRcdFx0cnVsZXM6IFtcblx0XHRcdFx0e1xuXHRcdFx0XHRcdHR5cGU6ICdlbXB0eScsXG5cdFx0XHRcdFx0cHJvbXB0OiBnbG9iYWxUcmFuc2xhdGUubW9kX2IyNF9pX1ZhbGlkYXRlUG9ydGFsRW1wdHksXG5cdFx0XHRcdH0sXG5cdFx0XHRdLFxuXHRcdH0sXG5cdFx0Y2xpZW50X2lkOiB7XG5cdFx0XHRpZGVudGlmaWVyOiAnY2xpZW50X2lkJyxcblx0XHRcdHJ1bGVzOiBbXG5cdFx0XHRcdHtcblx0XHRcdFx0XHR0eXBlOiAnZW1wdHknLFxuXHRcdFx0XHRcdHByb21wdDogZ2xvYmFsVHJhbnNsYXRlLm1vZF9iMjRfaV9WYWxpZGF0ZUNsaWVudElERW1wdHksXG5cdFx0XHRcdH0sXG5cdFx0XHRdLFxuXHRcdH0sXG5cdFx0Y2xpZW50X3NlY3JldDoge1xuXHRcdFx0aWRlbnRpZmllcjogJ2NsaWVudF9zZWNyZXQnLFxuXHRcdFx0cnVsZXM6IFtcblx0XHRcdFx0e1xuXHRcdFx0XHRcdHR5cGU6ICdlbXB0eScsXG5cdFx0XHRcdFx0cHJvbXB0OiBnbG9iYWxUcmFuc2xhdGUubW9kX2IyNF9pX1ZhbGlkYXRlQ2xpZW50U2VjcmV0RW1wdHksXG5cdFx0XHRcdH0sXG5cdFx0XHRdLFxuXHRcdH0sXG5cdH0sXG5cdG9uQ2hhbmdlUmVnaW9uKCl7XG5cdFx0bGV0IHJlZ2lvbiA9IE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uJGVsUmVnaW9uLnZhbCgpO1xuXHRcdGlmKHJlZ2lvbiA9PT0gJ1JFU1RfQVBJJyl7XG5cdFx0XHRNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLiRlbEFwcERhdGEuc2hvdygpO1xuXHRcdH1lbHNle1xuXHRcdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi4kZWxBcHBEYXRhLmhpZGUoKTtcblx0XHR9XG5cdH0sXG5cdGluaXRpYWxpemUoKSB7XG5cdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi5jaGVja1N0YXR1c1RvZ2dsZSgpO1xuXHRcdHdpbmRvdy5hZGRFdmVudExpc3RlbmVyKCdNb2R1bGVTdGF0dXNDaGFuZ2VkJywgTW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi5jaGVja1N0YXR1c1RvZ2dsZSk7XG5cdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi5pbml0aWFsaXplRm9ybSgpO1xuXHRcdCQoJy5iMjRfcmVnaW9ucy1zZWxlY3QnKS5kcm9wZG93bigpO1xuXG5cdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi5vbkNoYW5nZVJlZ2lvbigpO1xuXHRcdE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uJGVsUmVnaW9uLmNoYW5nZShNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLm9uQ2hhbmdlUmVnaW9uKTtcblxuXHRcdCQoJy5hdmF0YXInKS5lYWNoKCgpID0+IHtcblx0XHRcdGlmICgkKHRoaXMpLmF0dHIoJ3NyYycpID09PSAnJykge1xuXHRcdFx0XHQkKHRoaXMpLmF0dHIoJ3NyYycsIGAke2dsb2JhbFJvb3RVcmx9YXNzZXRzL2ltZy91bmtub3duUGVyc29uLmpwZ2ApO1xuXHRcdFx0fVxuXHRcdH0pO1xuXG5cdFx0JCgnI2V4dGVuc2lvbnMtbWVudSAuaXRlbScpLnRhYigpO1xuXG5cdFx0JCgnI2V4dGVuc2lvbnMtdGFibGUnKS5EYXRhVGFibGUoe1xuXHRcdFx0bGVuZ3RoQ2hhbmdlOiBmYWxzZSxcblx0XHRcdHBhZ2luZzogZmFsc2UsXG5cdFx0XHRjb2x1bW5zOiBbXG5cdFx0XHRcdHsgb3JkZXJhYmxlOiBmYWxzZSwgc2VhcmNoYWJsZTogZmFsc2UgfSxcblx0XHRcdFx0bnVsbCxcblx0XHRcdFx0bnVsbCxcblx0XHRcdFx0bnVsbCxcblx0XHRcdF0sXG5cdFx0XHRvcmRlcjogWzEsICdhc2MnXSxcblx0XHRcdGxhbmd1YWdlOiBTZW1hbnRpY0xvY2FsaXphdGlvbi5kYXRhVGFibGVMb2NhbGlzYXRpb24sXG5cdFx0fSk7XG5cblx0XHRNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLiR1c2Vyc0NoZWNrQm94ZXMuY2hlY2tib3goe1xuXHRcdFx0b25DaGFuZ2UoKSB7XG5cdFx0XHRcdE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uJGRpcnJ0eUZpZWxkLnZhbChNYXRoLnJhbmRvbSgpKTtcblx0XHRcdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi4kZGlycnR5RmllbGQudHJpZ2dlcignY2hhbmdlJyk7XG5cdFx0XHR9LFxuXHRcdFx0b25DaGVja2VkKCkge1xuXHRcdFx0XHRjb25zdCBudW1iZXIgPSAkKHRoaXMpLmF0dHIoJ2RhdGEtdmFsdWUnKTtcblx0XHRcdFx0JChgIyR7bnVtYmVyfSAuZGlzYWJpbGl0eWApLnJlbW92ZUNsYXNzKCdkaXNhYmxlZCcpO1xuXHRcdFx0fSxcblx0XHRcdG9uVW5jaGVja2VkKCkge1xuXHRcdFx0XHRjb25zdCBudW1iZXIgPSAkKHRoaXMpLmF0dHIoJ2RhdGEtdmFsdWUnKTtcblx0XHRcdFx0JChgIyR7bnVtYmVyfSAuZGlzYWJpbGl0eWApLmFkZENsYXNzKCdkaXNhYmxlZCcpO1xuXHRcdFx0fSxcblx0XHR9KTtcblx0XHRNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLiR1c2Vyc0NoZWNrQm94ZXMuY2hlY2tib3goJ2F0dGFjaCBldmVudHMnLCAnLmNoZWNrLmJ1dHRvbicsICdjaGVjaycpO1xuXHRcdE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uJHVzZXJzQ2hlY2tCb3hlcy5jaGVja2JveCgnYXR0YWNoIGV2ZW50cycsICcudW5jaGVjay5idXR0b24nLCAndW5jaGVjaycpO1xuXG5cdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi4kZ2xvYmFsU2VhcmNoLm9uKCdrZXl1cCcsIChlKSA9PiB7XG5cdFx0XHRpZiAoZS5rZXlDb2RlID09PSAxM1xuXHRcdFx0XHR8fCBlLmtleUNvZGUgPT09IDhcblx0XHRcdFx0fHwgTW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi4kZ2xvYmFsU2VhcmNoLnZhbCgpLmxlbmd0aCA9PT0gMCkge1xuXHRcdFx0XHRjb25zdCB0ZXh0ID0gYCR7TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi4kZ2xvYmFsU2VhcmNoLnZhbCgpfWA7XG5cdFx0XHRcdE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uYXBwbHlGaWx0ZXIodGV4dCk7XG5cdFx0XHR9XG5cdFx0fSk7XG5cblx0XHRNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLiRyZWNvcmRzVGFibGUuZGF0YVRhYmxlKHtcblx0XHRcdHNlcnZlclNpZGU6IHRydWUsXG5cdFx0XHRwcm9jZXNzaW5nOiB0cnVlLFxuXHRcdFx0YWpheDoge1xuXHRcdFx0XHR1cmw6IE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uZ2V0TmV3UmVjb3Jkc0FKQVhVcmwsXG5cdFx0XHRcdHR5cGU6ICdQT1NUJyxcblx0XHRcdFx0ZGF0YVNyYzogJ2RhdGEnLFxuXHRcdFx0fSxcblx0XHRcdGNvbHVtbnM6IFtcblx0XHRcdFx0eyBkYXRhOiBudWxsIH0sXG5cdFx0XHRcdHsgZGF0YTogJ25hbWUnIH0sXG5cdFx0XHRcdHsgZGF0YTogJ251bWJlcicgfSxcblx0XHRcdFx0eyBkYXRhOiAnYWxpYXMnIH0sXG5cdFx0XHRcdHsgZGF0YTogbnVsbCB9LFxuXHRcdFx0XSxcblx0XHRcdHBhZ2luZzogdHJ1ZSxcblx0XHRcdC8vIHNjcm9sbFk6ICQod2luZG93KS5oZWlnaHQoKSAtIE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uJHJlY29yZHNUYWJsZS5vZmZzZXQoKS50b3AtMjAwLFxuXHRcdFx0Ly8gc3RhdGVTYXZlOiB0cnVlLFxuXHRcdFx0c0RvbTogJ3J0aXAnLFxuXHRcdFx0ZGVmZXJSZW5kZXI6IHRydWUsXG5cdFx0XHRwYWdlTGVuZ3RoOiAxNyxcblx0XHRcdGJBdXRvV2lkdGg6IGZhbHNlLFxuXG5cdFx0XHQvLyBzY3JvbGxDb2xsYXBzZTogdHJ1ZSxcblx0XHRcdC8vIHNjcm9sbGVyOiB0cnVlLFxuXHRcdFx0LyoqXG5cdFx0XHQgKiDQmtC+0L3RgdGC0YDRg9C60YLQvtGAINGB0YLRgNC+0LrQuCDQt9Cw0L/QuNGB0Lhcblx0XHRcdCAqIEBwYXJhbSByb3dcblx0XHRcdCAqIEBwYXJhbSBkYXRhXG5cdFx0XHQgKi9cblx0XHRcdGNyZWF0ZWRSb3cocm93LCBkYXRhKSB7XG5cdFx0XHRcdGNvbnN0IHRlbXBsYXRlTmFtZSA9XG5cdFx0XHRcdFx0JzxkaXYgY2xhc3M9XCJ1aSB0cmFuc3BhcmVudCBmbHVpZCBpbnB1dCBpbmxpbmUtZWRpdFwiPicgK1xuXHRcdFx0XHRcdGA8aW5wdXQgY2xhc3M9XCJleHRlcm5hbC1uYW1lXCIgdHlwZT1cInRleHRcIiBkYXRhLXZhbHVlPVwiJHtkYXRhLm5hbWV9XCIgdmFsdWU9XCIke2RhdGEubmFtZX1cIj5gICtcblx0XHRcdFx0XHQnPC9kaXY+JztcblxuXHRcdFx0XHRjb25zdCB0ZW1wbGF0ZU51bWJlciA9XG5cdFx0XHRcdFx0JzxkaXYgY2xhc3M9XCJ1aSB0cmFuc3BhcmVudCBmbHVpZCBpbnB1dCBpbmxpbmUtZWRpdFwiPicgK1xuXHRcdFx0XHRcdGA8aW5wdXQgY2xhc3M9XCJleHRlcm5hbC1udW1iZXJcIiB0eXBlPVwidGV4dFwiIGRhdGEtdmFsdWU9XCIke2RhdGEubnVtYmVyfVwiIHZhbHVlPVwiJHtkYXRhLm51bWJlcn1cIj5gICtcblx0XHRcdFx0XHQnPC9kaXY+JztcblxuXHRcdFx0XHRjb25zdCB0ZW1wbGF0ZURpZCA9XG5cdFx0XHRcdFx0JzxkaXYgY2xhc3M9XCJ1aSB0cmFuc3BhcmVudCBpbnB1dCBpbmxpbmUtZWRpdFwiPicgK1xuXHRcdFx0XHRcdGA8aW5wdXQgY2xhc3M9XCJleHRlcm5hbC1hbGlhc2VzXCIgdHlwZT1cInRleHRcIiBkYXRhLXZhbHVlPVwiJHtkYXRhLmFsaWFzfVwiIHZhbHVlPVwiJHtkYXRhLmFsaWFzfVwiPmAgK1xuXHRcdFx0XHRcdCc8L2Rpdj4nO1xuXG5cdFx0XHRcdGNvbnN0IHRlbXBsYXRlRGVsZXRlQnV0dG9uID0gJzxkaXYgY2xhc3M9XCJ1aSBzbWFsbCBiYXNpYyBpY29uIGJ1dHRvbnMgYWN0aW9uLWJ1dHRvbnNcIj4nICtcblx0XHRcdFx0XHRgPGEgaHJlZj1cIiNcIiBkYXRhLXZhbHVlID0gXCIke2RhdGEuaWR9XCJgICtcblx0XHRcdFx0XHRgIGNsYXNzPVwidWkgYnV0dG9uIGRlbGV0ZSB0d28tc3RlcHMtZGVsZXRlIHBvcHVwZWRcIiBkYXRhLWNvbnRlbnQ9XCIke2dsb2JhbFRyYW5zbGF0ZS5idF9Ub29sVGlwRGVsZXRlfVwiPmAgK1xuXHRcdFx0XHRcdCc8aSBjbGFzcz1cImljb24gdHJhc2ggcmVkXCI+PC9pPjwvYT48L2Rpdj4nO1xuXG5cdFx0XHRcdCQoJ3RkJywgcm93KS5lcSgwKS5odG1sKCc8aSBjbGFzcz1cInVpIHVzZXIgY2lyY2xlIGljb25cIj48L2k+Jyk7XG5cdFx0XHRcdCQoJ3RkJywgcm93KS5lcSgxKS5odG1sKHRlbXBsYXRlTmFtZSk7XG5cdFx0XHRcdCQoJ3RkJywgcm93KS5lcSgyKS5odG1sKHRlbXBsYXRlTnVtYmVyKTtcblx0XHRcdFx0JCgndGQnLCByb3cpLmVxKDMpLmh0bWwodGVtcGxhdGVEaWQpO1xuXHRcdFx0XHQkKCd0ZCcsIHJvdykuZXEoNCkuaHRtbCh0ZW1wbGF0ZURlbGV0ZUJ1dHRvbik7XG5cdFx0XHR9LFxuXHRcdFx0LyoqXG5cdFx0XHQgKiBEcmF3IGV2ZW50IC0gZmlyZWQgb25jZSB0aGUgdGFibGUgaGFzIGNvbXBsZXRlZCBhIGRyYXcuXG5cdFx0XHQgKi9cblx0XHRcdGRyYXdDYWxsYmFjaygpIHtcblx0XHRcdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi5pbml0aWFsaXplSW5wdXRtYXNrKCQoTW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi5pbnB1dE51bWJlckpRVFBMKSk7XG5cdFx0XHR9LFxuXHRcdFx0bGFuZ3VhZ2U6IFNlbWFudGljTG9jYWxpemF0aW9uLmRhdGFUYWJsZUxvY2FsaXNhdGlvbixcblx0XHRcdG9yZGVyaW5nOiBmYWxzZSxcblx0XHR9KTtcblx0XHRNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLmRhdGFUYWJsZSA9IE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uJHJlY29yZHNUYWJsZS5EYXRhVGFibGUoKTtcblxuXHRcdC8vINCU0LLQvtC50L3QvtC5INC60LvQuNC6INC90LAg0L/QvtC70LUg0LLQstC+0LTQsCDQvdC+0LzQtdGA0LBcblx0XHQkKCdib2R5Jykub24oJ2ZvY3VzaW4nLCAnLmV4dGVybmFsLW5hbWUsIC5leHRlcm5hbC1udW1iZXIsIC5leHRlcm5hbC1hbGlhc2VzICcsIChlKSA9PiB7XG5cdFx0XHQkKGUudGFyZ2V0KS50cmFuc2l0aW9uKCdnbG93Jyk7XG5cdFx0XHQkKGUudGFyZ2V0KS5jbG9zZXN0KCdkaXYnKVxuXHRcdFx0XHQucmVtb3ZlQ2xhc3MoJ3RyYW5zcGFyZW50Jylcblx0XHRcdFx0LmFkZENsYXNzKCdjaGFuZ2VkLWZpZWxkJyk7XG5cdFx0XHQkKGUudGFyZ2V0KS5hdHRyKCdyZWFkb25seScsIGZhbHNlKTtcblx0XHRcdE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uJGRpcnJ0eUZpZWxkLnZhbChNYXRoLnJhbmRvbSgpKTtcblx0XHRcdE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uJGRpcnJ0eUZpZWxkLnRyaWdnZXIoJ2NoYW5nZScpO1xuXHRcdH0pO1xuXG5cdFx0Ly8g0J7RgtC/0YDQsNCy0LrQsCDRhNC+0YDQvNGLINC90LAg0YHQtdGA0LLQtdGAINC/0L4g0YPRhdC+0LTRgyDRgSDQv9C+0LvRjyDQstCy0L7QtNCwXG5cdFx0JCgnYm9keScpLm9uKCdmb2N1c291dCcsICcuZXh0ZXJuYWwtbmFtZSwgLmV4dGVybmFsLW51bWJlciwgLmV4dGVybmFsLWFsaWFzZXMnLCAoZSkgPT4ge1xuXHRcdFx0JChlLnRhcmdldCkuY2xvc2VzdCgnZGl2Jylcblx0XHRcdFx0LmFkZENsYXNzKCd0cmFuc3BhcmVudCcpXG5cdFx0XHRcdC5yZW1vdmVDbGFzcygnY2hhbmdlZC1maWVsZCcpO1xuXHRcdFx0JChlLnRhcmdldCkuYXR0cigncmVhZG9ubHknLCB0cnVlKTtcblx0XHRcdE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uJGRpcnJ0eUZpZWxkLnZhbChNYXRoLnJhbmRvbSgpKTtcblx0XHRcdE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uJGRpcnJ0eUZpZWxkLnRyaWdnZXIoJ2NoYW5nZScpO1xuXHRcdH0pO1xuXG5cdFx0Ly8g0JrQu9C40Log0L3QsCDQutC90L7Qv9C60YMg0YPQtNCw0LvQuNGC0Yxcblx0XHQkKCdib2R5Jykub24oJ2NsaWNrJywgJ2EuZGVsZXRlJywgKGUpID0+IHtcblx0XHRcdGUucHJldmVudERlZmF1bHQoKTtcblx0XHRcdCQoZS50YXJnZXQpLmNsb3Nlc3QoJ3RyJykucmVtb3ZlKCk7XG5cdFx0XHRpZiAoTW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi4kcmVjb3Jkc1RhYmxlLmZpbmQoJ3Rib2R5ID4gdHInKS5sZW5ndGg9PT0wKXtcblx0XHRcdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi4kcmVjb3Jkc1RhYmxlLmZpbmQoJ3Rib2R5JykuYXBwZW5kKCc8dHIgY2xhc3M9XCJvZGRcIj48L3RyPicpO1xuXHRcdFx0fVxuXHRcdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi4kZGlycnR5RmllbGQudmFsKE1hdGgucmFuZG9tKCkpO1xuXHRcdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi4kZGlycnR5RmllbGQudHJpZ2dlcignY2hhbmdlJyk7XG5cdFx0fSk7XG5cblx0XHQvLyDQlNC+0LHQsNCy0LvQtdC90LjQtSDQvdC+0LLQvtC5INGB0YLRgNC+0LrQuFxuXHRcdE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uJGFkZE5ld0J1dHRvbi5vbignY2xpY2snLCAoZSkgPT4ge1xuXHRcdFx0ZS5wcmV2ZW50RGVmYXVsdCgpO1xuXHRcdFx0JCgnLmRhdGFUYWJsZXNfZW1wdHknKS5yZW1vdmUoKTtcblx0XHRcdGNvbnN0IGlkID0gYG5ldyR7TWF0aC5mbG9vcihNYXRoLnJhbmRvbSgpICogTWF0aC5mbG9vcig1MDApKX1gO1xuXHRcdFx0Y29uc3Qgcm93VGVtcGxhdGUgPSBgPHRyIGlkPVwiJHtpZH1cIiBjbGFzcz1cImV4dC1saW5lLXJvd1wiPmAgK1xuXHRcdFx0XHQnPHRkPjxpIGNsYXNzPVwidWkgdXNlciBjaXJjbGUgaWNvblwiPjwvaT48L3RkPicgK1xuXHRcdFx0XHQnPHRkPjxkaXYgY2xhc3M9XCJ1aSBmbHVpZCBpbnB1dCBpbmxpbmUtZWRpdCBjaGFuZ2VkLWZpZWxkXCI+PGlucHV0IGNsYXNzPVwiZXh0ZXJuYWwtbmFtZVwiIHR5cGU9XCJ0ZXh0XCIgZGF0YS12YWx1ZT1cIlwiIHZhbHVlPVwiXCI+PC9kaXY+PC90ZD4nICtcblx0XHRcdFx0Jzx0ZD48ZGl2IGNsYXNzPVwidWkgaW5wdXQgaW5saW5lLWVkaXQgY2hhbmdlZC1maWVsZFwiPjxpbnB1dCBjbGFzcz1cImV4dGVybmFsLW51bWJlclwiIHR5cGU9XCJ0ZXh0XCIgZGF0YS12YWx1ZT1cIlwiIHZhbHVlPVwiXCI+PC9kaXY+PC90ZD4nICtcblx0XHRcdFx0Jzx0ZD48ZGl2IGNsYXNzPVwidWkgaW5wdXQgaW5saW5lLWVkaXQgY2hhbmdlZC1maWVsZFwiPjxpbnB1dCBjbGFzcz1cImV4dGVybmFsLWFsaWFzZXNcIiB0eXBlPVwidGV4dFwiIGRhdGEtdmFsdWU9XCJcIiB2YWx1ZT1cIlwiPjwvZGl2PjwvdGQ+JyArXG5cdFx0XHRcdCc8dGQ+PGRpdiBjbGFzcz1cInVpIHNtYWxsIGJhc2ljIGljb24gYnV0dG9ucyBhY3Rpb24tYnV0dG9uc1wiPicgK1xuXHRcdFx0XHRgPGEgaHJlZj1cIiNcIiBjbGFzcz1cInVpIGJ1dHRvbiBkZWxldGUgdHdvLXN0ZXBzLWRlbGV0ZSBwb3B1cGVkXCIgZGF0YS12YWx1ZSA9IFwibmV3XCIgZGF0YS1jb250ZW50PVwiJHtnbG9iYWxUcmFuc2xhdGUuYnRfVG9vbFRpcERlbGV0ZX1cIj5gICtcblx0XHRcdFx0JzxpIGNsYXNzPVwiaWNvbiB0cmFzaCByZWRcIj48L2k+PC9hPjwvZGl2PjwvdGQ+JyArXG5cdFx0XHRcdCc8L3RyPic7XG5cdFx0XHRNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLiRyZWNvcmRzVGFibGUuZmluZCgndGJvZHkgPiB0cjpmaXJzdCcpLmJlZm9yZShyb3dUZW1wbGF0ZSk7XG5cdFx0XHQkKGB0ciMke2lkfSBpbnB1dGApLnRyYW5zaXRpb24oJ2dsb3cnKTtcblx0XHRcdCQoYHRyIyR7aWR9IC5leHRlcm5hbC1uYW1lYCkuZm9jdXMoKTtcblx0XHRcdE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uaW5pdGlhbGl6ZUlucHV0bWFzaygkKGB0ciMke2lkfSAuZXh0ZXJuYWwtbnVtYmVyYCkpO1xuXHRcdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi4kZGlycnR5RmllbGQudmFsKE1hdGgucmFuZG9tKCkpO1xuXHRcdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi4kZGlycnR5RmllbGQudHJpZ2dlcignY2hhbmdlJyk7XG5cdFx0fSk7XG5cdH0sXG5cdC8qKlxuXHQgKiDQmNC30LzQtdC90LXQvdC40LUg0YHRgtCw0YLRg9GB0LAg0LrQvdC+0L/QvtC6INC/0YDQuCDQuNC30LzQtdC90LXQvdC40Lgg0YHRgtCw0YLRg9GB0LAg0LzQvtC00YPQu9GPXG5cdCAqL1xuXHRjaGVja1N0YXR1c1RvZ2dsZSgpIHtcblx0XHRpZiAoTW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi4kc3RhdHVzVG9nZ2xlLmNoZWNrYm94KCdpcyBjaGVja2VkJykpIHtcblx0XHRcdCQoJ1tkYXRhLXRhYiA9IFwiZ2VuZXJhbFwiXSAuZGlzYWJpbGl0eScpLnJlbW92ZUNsYXNzKCdkaXNhYmxlZCcpO1xuXHRcdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi4kbW9kdWxlU3RhdHVzLnNob3coKTtcblx0XHRcdE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb25TdGF0dXNXb3JrZXIuaW5pdGlhbGl6ZSgpO1xuXHRcdH0gZWxzZSB7XG5cdFx0XHRNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLiRtb2R1bGVTdGF0dXMuaGlkZSgpO1xuXHRcdFx0JCgnW2RhdGEtdGFiID0gXCJnZW5lcmFsXCJdIC5kaXNhYmlsaXR5JykuYWRkQ2xhc3MoJ2Rpc2FibGVkJyk7XG5cdFx0fVxuXHR9LFxuXG5cdC8qKlxuXHQgKiDQmNC90LjRhtC40LDQu9C40LfQuNGA0YPQtdGCINC60YDQsNGB0LjQstC+0LUg0L/RgNC10LTRgdGC0LDQstC70LXQvdC40LUg0L3QvtC80LXRgNC+0LJcblx0ICovXG5cdGluaXRpYWxpemVJbnB1dG1hc2soJGVsKSB7XG5cdFx0aWYgKE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uJG1hc2tMaXN0ID09PSBudWxsKSB7XG5cdFx0XHQvLyDQn9C+0LTQs9C+0YLQvtCy0LjQvCDRgtCw0LHQu9C40YbRgyDQtNC70Y8g0YHQvtGA0YLQuNGA0L7QstC60Lhcblx0XHRcdE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uJG1hc2tMaXN0ID0gJC5tYXNrc1NvcnQoSW5wdXRNYXNrUGF0dGVybnMsIFsnIyddLCAvWzAtOV18Iy8sICdtYXNrJyk7XG5cdFx0fVxuXHRcdCRlbC5pbnB1dG1hc2tzKHtcblx0XHRcdGlucHV0bWFzazoge1xuXHRcdFx0XHRkZWZpbml0aW9uczoge1xuXHRcdFx0XHRcdCcjJzoge1xuXHRcdFx0XHRcdFx0dmFsaWRhdG9yOiAnWzAtOV0nLFxuXHRcdFx0XHRcdFx0Y2FyZGluYWxpdHk6IDEsXG5cdFx0XHRcdFx0fSxcblx0XHRcdFx0fSxcblx0XHRcdFx0c2hvd01hc2tPbkhvdmVyOiBmYWxzZSxcblx0XHRcdFx0Ly8gb25jbGVhcmVkOiBleHRlbnNpb24uY2JPbkNsZWFyZWRNb2JpbGVOdW1iZXIsXG5cdFx0XHRcdG9uY29tcGxldGU6IE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uY2JPbkNvbXBsZXRlTnVtYmVyLFxuXHRcdFx0XHQvLyBjbGVhckluY29tcGxldGU6IHRydWUsXG5cdFx0XHRcdG9uQmVmb3JlUGFzdGU6IE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uY2JPbk51bWJlckJlZm9yZVBhc3RlLFxuXHRcdFx0XHQvLyByZWdleDogL1xcRCsvLFxuXHRcdFx0fSxcblx0XHRcdG1hdGNoOiAvWzAtOV0vLFxuXHRcdFx0cmVwbGFjZTogJzknLFxuXHRcdFx0bGlzdDogTW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi4kbWFza0xpc3QsXG5cdFx0XHRsaXN0S2V5OiAnbWFzaycsXG5cblx0XHR9KTtcblx0fSxcblx0LyoqXG5cdCAqINCe0YfQuNGB0YLQutCwINC90L7QvNC10YDQsCDQv9C10YDQtdC0INCy0YHRgtCw0LLQutC+0Lkg0L7RgiDQu9C40YjQvdC40YUg0YHQuNC80LLQvtC70L7QslxuXHQgKiBAcmV0dXJucyB7Ym9vbGVhbnwqfHZvaWR8c3RyaW5nfVxuXHQgKi9cblx0Y2JPbk51bWJlckJlZm9yZVBhc3RlKHBhc3RlZFZhbHVlKSB7XG5cdFx0cmV0dXJuIHBhc3RlZFZhbHVlLnJlcGxhY2UoL1xcRCsvZywgJycpO1xuXHR9LFxuXHQvKipcblx0ICog0J/QvtGB0LvQtSDQstCy0L7QtNCwINC90L7QvNC10YDQsFxuXHQgKi9cblx0Y2JPbkNvbXBsZXRlTnVtYmVyKGUpe1xuXHRcdGNvbnN0IGRpZEVsID0gJChlLnRhcmdldCkuY2xvc2VzdCgndHInKS5maW5kKCdpbnB1dC5leHRlcm5hbC1hbGlhc2VzJyk7XG5cdFx0aWYgKGRpZEVsLnZhbCgpPT09Jycpe1xuXHRcdFx0ZGlkRWwudmFsKCQoZS50YXJnZXQpLmlucHV0bWFzaygndW5tYXNrZWR2YWx1ZScpKTtcblx0XHR9XG5cdH0sXG5cdC8qKlxuXHQgKiDQmtC+0LvQsdC10Log0L/QtdGA0LXQtCDQvtGC0L/RgNCw0LLQutC+0Lkg0YTQvtGA0LzRi1xuXHQgKiBAcGFyYW0gc2V0dGluZ3Ncblx0ICogQHJldHVybnMgeyp9XG5cdCAqL1xuXHRjYkJlZm9yZVNlbmRGb3JtKHNldHRpbmdzKSB7XG5cdFx0Y29uc3QgcmVzdWx0ID0gc2V0dGluZ3M7XG5cdFx0cmVzdWx0LmRhdGEgPSBNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLiRmb3JtT2JqLmZvcm0oJ2dldCB2YWx1ZXMnKTtcblxuXHRcdGNvbnN0IGFyckV4dGVybmFsTGluZXMgPSBbXTtcblx0XHQkKCcjZXh0ZXJuYWwtbGluZS10YWJsZSB0cicpLmVhY2goKGluZGV4LCBvYmopID0+IHtcblx0XHRcdGFyckV4dGVybmFsTGluZXMucHVzaCh7XG5cdFx0XHRcdGlkOiAkKG9iaikuYXR0cignaWQnKSxcblx0XHRcdFx0bmFtZTogJChvYmopLmZpbmQoJ2lucHV0LmV4dGVybmFsLW5hbWUnKS52YWwoKSxcblx0XHRcdFx0bnVtYmVyOiAkKG9iaikuZmluZCgnaW5wdXQuZXh0ZXJuYWwtbnVtYmVyJykudmFsKCksXG5cdFx0XHRcdGFsaWFzOiAkKG9iaikuZmluZCgnaW5wdXQuZXh0ZXJuYWwtYWxpYXNlcycpLnZhbCgpLFxuXHRcdFx0fSk7XG5cdFx0fSk7XG5cdFx0cmVzdWx0LmRhdGEuZXh0ZXJuYWxMaW5lcyA9IEpTT04uc3RyaW5naWZ5KGFyckV4dGVybmFsTGluZXMpO1xuXHRcdHJlc3VsdC5kYXRhLnBvcnRhbCA9IHJlc3VsdC5kYXRhLnBvcnRhbC5yZXBsYWNlKC9eKGh0dHBzP3xodHRwKTpcXC9cXC8vLCAnJyk7XG5cblx0XHRyZXR1cm4gcmVzdWx0O1xuXHR9LFxuXG5cdC8qKlxuXHQgKiDQmtC+0LvQsdC10Log0L/QvtGB0LvQtSDQvtGC0L/RgNCw0LLQutC4INGE0L7RgNC80Ytcblx0ICovXG5cdGNiQWZ0ZXJTZW5kRm9ybSgpIHtcblx0XHRNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uU3RhdHVzV29ya2VyLmluaXRpYWxpemUoKTtcblx0fSxcblx0aW5pdGlhbGl6ZUZvcm0oKSB7XG5cdFx0Rm9ybS4kZm9ybU9iaiA9IE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uJGZvcm1PYmo7XG5cdFx0Rm9ybS51cmwgPSBgJHtnbG9iYWxSb290VXJsfW1vZHVsZS1iaXRyaXgyNC1pbnRlZ3JhdGlvbi9zYXZlYDtcblx0XHRGb3JtLnZhbGlkYXRlUnVsZXMgPSBNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLnZhbGlkYXRlUnVsZXM7XG5cdFx0Rm9ybS5jYkJlZm9yZVNlbmRGb3JtID0gTW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi5jYkJlZm9yZVNlbmRGb3JtO1xuXHRcdEZvcm0uY2JBZnRlclNlbmRGb3JtID0gTW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi5jYkFmdGVyU2VuZEZvcm07XG5cdFx0Rm9ybS5pbml0aWFsaXplKCk7XG5cdH0sXG59O1xuXG4kKGRvY3VtZW50KS5yZWFkeSgoKSA9PiB7XG5cdE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uaW5pdGlhbGl6ZSgpO1xufSk7XG5cbiJdfQ==