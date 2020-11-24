"use strict";

/*
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 5 2020
 */

/* global globalRootUrl, globalTranslate, Form, Config, SemanticLocalization, InputMaskPatterns  */
var ModuleBitrix24Integration = {
  $formObj: $('#module-bitrix24-integration-form'),
  apiRoot: "".concat(Config.pbxUrl, "/pbxcore/api/modules/ModuleBitrix24Integration"),
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
  initialize: function () {
    function initialize() {
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
        onChange: function () {
          function onChange() {
            ModuleBitrix24Integration.$dirrtyField.val(Math.random());
            ModuleBitrix24Integration.$dirrtyField.trigger('change');
          }

          return onChange;
        }(),
        onChecked: function () {
          function onChecked() {
            var number = $(this).attr('data-value');
            $("#".concat(number, " .disability")).removeClass('disabled');
          }

          return onChecked;
        }(),
        onUnchecked: function () {
          function onUnchecked() {
            var number = $(this).attr('data-value');
            $("#".concat(number, " .disability")).addClass('disabled');
          }

          return onUnchecked;
        }()
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
        createdRow: function () {
          function createdRow(row, data) {
            var templateName = '<div class="ui transparent fluid input inline-edit">' + "<input class=\"external-name\" type=\"text\" data-value=\"".concat(data.name, "\" value=\"").concat(data.name, "\">") + '</div>';
            var templateNumber = '<div class="ui transparent fluid input inline-edit">' + "<input class=\"external-number\" type=\"text\" data-value=\"".concat(data.number, "\" value=\"").concat(data.number, "\">") + '</div>';
            var templateDid = '<div class="ui transparent input inline-edit">' + "<input class=\"external-aliases\" type=\"text\" data-value=\"".concat(data.alias, "\" value=\"").concat(data.alias, "\">") + '</div>';
            var templateDeleteButton = '<div class="ui small basic icon buttons action-buttons">' + "<a href=\"#\" data-value = \"".concat(data.id, "\"") + " class=\"ui button delete two-steps-delete popuped\" data-content=\"".concat(globalTranslate.bt_ToolTipDelete, "\">") + '<i class="icon trash red"></i></a></div>';
            $('td', row).eq(0).html('<i class="ui user circle icon"></i>');
            $('td', row).eq(1).html(templateName);
            $('td', row).eq(2).html(templateNumber);
            $('td', row).eq(3).html(templateDid);
            $('td', row).eq(4).html(templateDeleteButton);
          }

          return createdRow;
        }(),

        /**
         * Draw event - fired once the table has completed a draw.
         */
        drawCallback: function () {
          function drawCallback() {
            ModuleBitrix24Integration.initializeInputmask($(ModuleBitrix24Integration.inputNumberJQTPL));
          }

          return drawCallback;
        }(),
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
    }

    return initialize;
  }(),

  /**
   * Изменение статуса кнопок при изменении статуса модуля
   */
  checkStatusToggle: function () {
    function checkStatusToggle() {
      if (ModuleBitrix24Integration.$statusToggle.checkbox('is checked')) {
        $('[data-tab = "general"] .disability').removeClass('disabled');
        ModuleBitrix24Integration.$moduleStatus.show();
        ModuleBitrix24Integration.testConnection();
      } else {
        ModuleBitrix24Integration.$moduleStatus.hide();
        $('[data-tab = "general"] .disability').addClass('disabled');
      }
    }

    return checkStatusToggle;
  }(),

  /**
   * Применение настроек модуля после изменения данных формы
   */
  applyConfigurationChanges: function () {
    function applyConfigurationChanges() {
      $.api({
        url: "".concat(ModuleBitrix24Integration.apiRoot, "/reload"),
        on: 'now',
        successTest: function () {
          function successTest(response) {
            // test whether a JSON response is valid
            return response !== undefined && Object.keys(response).length > 0 && response.result === true;
          }

          return successTest;
        }(),
        onSuccess: function () {
          function onSuccess() {
            ModuleBitrix24Integration.checkStatusToggle();
          }

          return onSuccess;
        }()
      });
    }

    return applyConfigurationChanges;
  }(),

  /**
   * Проверка соединения с сервером Bitrix24
   * @returns {boolean}
   */
  testConnection: function () {
    function testConnection() {
      $.api({
        url: "".concat(ModuleBitrix24Integration.apiRoot, "/check"),
        on: 'now',
        successTest: function () {
          function successTest(response) {
            return response !== undefined && Object.keys(response).length > 0 && response.result !== undefined && response.result === true;
          }

          return successTest;
        }(),
        onSuccess: function () {
          function onSuccess() {
            ModuleBitrix24Integration.$moduleStatus.removeClass('grey').addClass('green');
            ModuleBitrix24Integration.$moduleStatus.html(globalTranslate.mod_b24_i_Connected); // const FullName = `${response.data.data.LAST_NAME} ${response.data.data.NAME}`;
          }

          return onSuccess;
        }(),
        onFailure: function () {
          function onFailure() {
            ModuleBitrix24Integration.$moduleStatus.removeClass('green').addClass('grey');
            ModuleBitrix24Integration.$moduleStatus.html(globalTranslate.mod_b24_i_Disconnected);
          }

          return onFailure;
        }(),
        onResponse: function () {
          function onResponse(response) {
            $('.message.ajax').remove(); // Debug mode

            if (typeof response.data !== 'undefined') {
              var visualErrorString = JSON.stringify(response.data, null, 2);

              if (typeof visualErrorString === 'string') {
                visualErrorString = visualErrorString.replace(/\n/g, '<br/>');

                if (Object.keys(response).length > 0 && response.result !== true) {
                  ModuleBitrix24Integration.$formObj.after("<div class=\"ui error message ajax\">\t\t\t\t\t\t\n\t\t\t\t\t\t\t\t\t<pre style='white-space: pre-wrap'>".concat(visualErrorString, "</pre>\t\t\t\t\t\t\t\t\t\t  \n\t\t\t\t\t\t\t\t</div>"));
                }
              }
            }
          }

          return onResponse;
        }()
      });
    }

    return testConnection;
  }(),

  /**
   * Инициализирует красивое представление номеров
   */
  initializeInputmask: function () {
    function initializeInputmask($el) {
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
    }

    return initializeInputmask;
  }(),

  /**
   * Очистка номера перед вставкой от лишних символов
   * @returns {boolean|*|void|string}
   */
  cbOnNumberBeforePaste: function () {
    function cbOnNumberBeforePaste(pastedValue) {
      return pastedValue.replace(/\D+/g, '');
    }

    return cbOnNumberBeforePaste;
  }(),

  /**
   * После ввода номера
   */
  cbOnCompleteNumber: function () {
    function cbOnCompleteNumber(e) {
      var didEl = $(e.target).closest('tr').find('input.external-aliases');

      if (didEl.val() === '') {
        didEl.val($(e.target).inputmask('unmaskedvalue'));
      }
    }

    return cbOnCompleteNumber;
  }(),

  /**
   * Колбек перед отправкой формы
   * @param settings
   * @returns {*}
   */
  cbBeforeSendForm: function () {
    function cbBeforeSendForm(settings) {
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
    }

    return cbBeforeSendForm;
  }(),

  /**
   * Колбек после отправки формы
   */
  cbAfterSendForm: function () {
    function cbAfterSendForm() {
      ModuleBitrix24Integration.applyConfigurationChanges();
    }

    return cbAfterSendForm;
  }(),
  initializeForm: function () {
    function initializeForm() {
      Form.$formObj = ModuleBitrix24Integration.$formObj;
      Form.url = "".concat(globalRootUrl, "module-bitrix24-integration/save");
      Form.validateRules = ModuleBitrix24Integration.validateRules;
      Form.cbBeforeSendForm = ModuleBitrix24Integration.cbBeforeSendForm;
      Form.cbAfterSendForm = ModuleBitrix24Integration.cbAfterSendForm;
      Form.initialize();
    }

    return initializeForm;
  }()
};
$(document).ready(function () {
  ModuleBitrix24Integration.initialize();
});
//# sourceMappingURL=data:application/json;charset=utf-8;base64,eyJ2ZXJzaW9uIjozLCJzb3VyY2VzIjpbInNyYy9tb2R1bGUtYml0cml4MjQtaW50ZWdyYXRpb24taW5kZXguanMiXSwibmFtZXMiOlsiTW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbiIsIiRmb3JtT2JqIiwiJCIsImFwaVJvb3QiLCJDb25maWciLCJwYnhVcmwiLCIkc3VibWl0QnV0dG9uIiwiJHN0YXR1c1RvZ2dsZSIsIiRtb2R1bGVTdGF0dXMiLCIkZGlycnR5RmllbGQiLCIkdXNlcnNDaGVja0JveGVzIiwiJGdsb2JhbFNlYXJjaCIsIiRyZWNvcmRzVGFibGUiLCIkYWRkTmV3QnV0dG9uIiwiaW5wdXROdW1iZXJKUVRQTCIsIiRtYXNrTGlzdCIsImdldE5ld1JlY29yZHNBSkFYVXJsIiwiZ2xvYmFsUm9vdFVybCIsInZhbGlkYXRlUnVsZXMiLCJwb3J0YWwiLCJpZGVudGlmaWVyIiwicnVsZXMiLCJ0eXBlIiwicHJvbXB0IiwiZ2xvYmFsVHJhbnNsYXRlIiwibW9kX2IyNF9pX1ZhbGlkYXRlUG9ydGFsRW1wdHkiLCJjbGllbnRfaWQiLCJtb2RfYjI0X2lfVmFsaWRhdGVDbGllbnRJREVtcHR5IiwiY2xpZW50X3NlY3JldCIsIm1vZF9iMjRfaV9WYWxpZGF0ZUNsaWVudFNlY3JldEVtcHR5IiwiaW5pdGlhbGl6ZSIsImNoZWNrU3RhdHVzVG9nZ2xlIiwid2luZG93IiwiYWRkRXZlbnRMaXN0ZW5lciIsImluaXRpYWxpemVGb3JtIiwiZWFjaCIsImF0dHIiLCJ0YWIiLCJEYXRhVGFibGUiLCJsZW5ndGhDaGFuZ2UiLCJwYWdpbmciLCJjb2x1bW5zIiwib3JkZXJhYmxlIiwic2VhcmNoYWJsZSIsIm9yZGVyIiwibGFuZ3VhZ2UiLCJTZW1hbnRpY0xvY2FsaXphdGlvbiIsImRhdGFUYWJsZUxvY2FsaXNhdGlvbiIsImNoZWNrYm94Iiwib25DaGFuZ2UiLCJ2YWwiLCJNYXRoIiwicmFuZG9tIiwidHJpZ2dlciIsIm9uQ2hlY2tlZCIsIm51bWJlciIsInJlbW92ZUNsYXNzIiwib25VbmNoZWNrZWQiLCJhZGRDbGFzcyIsIm9uIiwiZSIsImtleUNvZGUiLCJsZW5ndGgiLCJ0ZXh0IiwiYXBwbHlGaWx0ZXIiLCJkYXRhVGFibGUiLCJzZXJ2ZXJTaWRlIiwicHJvY2Vzc2luZyIsImFqYXgiLCJ1cmwiLCJkYXRhU3JjIiwiZGF0YSIsInNEb20iLCJkZWZlclJlbmRlciIsInBhZ2VMZW5ndGgiLCJiQXV0b1dpZHRoIiwiY3JlYXRlZFJvdyIsInJvdyIsInRlbXBsYXRlTmFtZSIsIm5hbWUiLCJ0ZW1wbGF0ZU51bWJlciIsInRlbXBsYXRlRGlkIiwiYWxpYXMiLCJ0ZW1wbGF0ZURlbGV0ZUJ1dHRvbiIsImlkIiwiYnRfVG9vbFRpcERlbGV0ZSIsImVxIiwiaHRtbCIsImRyYXdDYWxsYmFjayIsImluaXRpYWxpemVJbnB1dG1hc2siLCJvcmRlcmluZyIsInRhcmdldCIsInRyYW5zaXRpb24iLCJjbG9zZXN0IiwicHJldmVudERlZmF1bHQiLCJyZW1vdmUiLCJmaW5kIiwiYXBwZW5kIiwiZmxvb3IiLCJyb3dUZW1wbGF0ZSIsImJlZm9yZSIsImZvY3VzIiwic2hvdyIsInRlc3RDb25uZWN0aW9uIiwiaGlkZSIsImFwcGx5Q29uZmlndXJhdGlvbkNoYW5nZXMiLCJhcGkiLCJzdWNjZXNzVGVzdCIsInJlc3BvbnNlIiwidW5kZWZpbmVkIiwiT2JqZWN0Iiwia2V5cyIsInJlc3VsdCIsIm9uU3VjY2VzcyIsIm1vZF9iMjRfaV9Db25uZWN0ZWQiLCJvbkZhaWx1cmUiLCJtb2RfYjI0X2lfRGlzY29ubmVjdGVkIiwib25SZXNwb25zZSIsInZpc3VhbEVycm9yU3RyaW5nIiwiSlNPTiIsInN0cmluZ2lmeSIsInJlcGxhY2UiLCJhZnRlciIsIiRlbCIsIm1hc2tzU29ydCIsIklucHV0TWFza1BhdHRlcm5zIiwiaW5wdXRtYXNrcyIsImlucHV0bWFzayIsImRlZmluaXRpb25zIiwidmFsaWRhdG9yIiwiY2FyZGluYWxpdHkiLCJzaG93TWFza09uSG92ZXIiLCJvbmNvbXBsZXRlIiwiY2JPbkNvbXBsZXRlTnVtYmVyIiwib25CZWZvcmVQYXN0ZSIsImNiT25OdW1iZXJCZWZvcmVQYXN0ZSIsIm1hdGNoIiwibGlzdCIsImxpc3RLZXkiLCJwYXN0ZWRWYWx1ZSIsImRpZEVsIiwiY2JCZWZvcmVTZW5kRm9ybSIsInNldHRpbmdzIiwiZm9ybSIsImFyckV4dGVybmFsTGluZXMiLCJpbmRleCIsIm9iaiIsInB1c2giLCJleHRlcm5hbExpbmVzIiwiY2JBZnRlclNlbmRGb3JtIiwiRm9ybSIsImRvY3VtZW50IiwicmVhZHkiXSwibWFwcGluZ3MiOiI7O0FBQUE7Ozs7Ozs7QUFPQTtBQUVBLElBQU1BLHlCQUF5QixHQUFHO0FBQ2pDQyxFQUFBQSxRQUFRLEVBQUVDLENBQUMsQ0FBQyxtQ0FBRCxDQURzQjtBQUVqQ0MsRUFBQUEsT0FBTyxZQUFLQyxNQUFNLENBQUNDLE1BQVosbURBRjBCO0FBR2pDQyxFQUFBQSxhQUFhLEVBQUVKLENBQUMsQ0FBQyxlQUFELENBSGlCO0FBSWpDSyxFQUFBQSxhQUFhLEVBQUVMLENBQUMsQ0FBQyx1QkFBRCxDQUppQjtBQUtqQ00sRUFBQUEsYUFBYSxFQUFFTixDQUFDLENBQUMsU0FBRCxDQUxpQjtBQU1qQ08sRUFBQUEsWUFBWSxFQUFFUCxDQUFDLENBQUMsU0FBRCxDQU5rQjtBQU9qQ1EsRUFBQUEsZ0JBQWdCLEVBQUVSLENBQUMsQ0FBQyw2QkFBRCxDQVBjO0FBU2pDUyxFQUFBQSxhQUFhLEVBQUVULENBQUMsQ0FBQyxlQUFELENBVGlCO0FBVWpDVSxFQUFBQSxhQUFhLEVBQUVWLENBQUMsQ0FBQyxzQkFBRCxDQVZpQjtBQVdqQ1csRUFBQUEsYUFBYSxFQUFFWCxDQUFDLENBQUMsK0JBQUQsQ0FYaUI7QUFhakNZLEVBQUFBLGdCQUFnQixFQUFFLHVCQWJlO0FBY2pDQyxFQUFBQSxTQUFTLEVBQUUsSUFkc0I7QUFlakNDLEVBQUFBLG9CQUFvQixZQUFLQyxhQUFMLGlEQWZhO0FBaUJqQ0MsRUFBQUEsYUFBYSxFQUFFO0FBQ2RDLElBQUFBLE1BQU0sRUFBRTtBQUNQQyxNQUFBQSxVQUFVLEVBQUUsUUFE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QUFDQTs7QUFoWGdDO0FBQUE7QUFpWGpDdkUsRUFBQUEsY0FqWGlDO0FBQUEsOEJBaVhoQjtBQUNoQm9ILE1BQUFBLElBQUksQ0FBQ3JKLFFBQUwsR0FBZ0JELHlCQUF5QixDQUFDQyxRQUExQztBQUNBcUosTUFBQUEsSUFBSSxDQUFDakYsR0FBTCxhQUFjcEQsYUFBZDtBQUNBcUksTUFBQUEsSUFBSSxDQUFDcEksYUFBTCxHQUFxQmxCLHlCQUF5QixDQUFDa0IsYUFBL0M7QUFDQW9JLE1BQUFBLElBQUksQ0FBQ1QsZ0JBQUwsR0FBd0I3SSx5QkFBeUIsQ0FBQzZJLGdCQUFsRDtBQUNBUyxNQUFBQSxJQUFJLENBQUNELGVBQUwsR0FBdUJySix5QkFBeUIsQ0FBQ3FKLGVBQWpEO0FBQ0FDLE1BQUFBLElBQUksQ0FBQ3hILFVBQUw7QUFDQTs7QUF4WGdDO0FBQUE7QUFBQSxDQUFsQztBQTJYQTVCLENBQUMsQ0FBQ3FKLFFBQUQsQ0FBRCxDQUFZQyxLQUFaLENBQWtCLFlBQU07QUFDdkJ4SixFQUFBQSx5QkFBeUIsQ0FBQzhCLFVBQTFCO0FBQ0EsQ0FGRCIsInNvdXJjZXNDb250ZW50IjpbIi8qXG4gKiBDb3B5cmlnaHQgwqkgTUlLTyBMTEMgLSBBbGwgUmlnaHRzIFJlc2VydmVkXG4gKiBVbmF1dGhvcml6ZWQgY29weWluZyBvZiB0aGlzIGZpbGUsIHZpYSBhbnkgbWVkaXVtIGlzIHN0cmljdGx5IHByb2hpYml0ZWRcbiAqIFByb3ByaWV0YXJ5IGFuZCBjb25maWRlbnRpYWxcbiAqIFdyaXR0ZW4gYnkgQWxleGV5IFBvcnRub3YsIDUgMjAyMFxuICovXG5cbi8qIGdsb2JhbCBnbG9iYWxSb290VXJsLCBnbG9iYWxUcmFuc2xhdGUsIEZvcm0sIENvbmZpZywgU2VtYW50aWNMb2NhbGl6YXRpb24sIElucHV0TWFza1BhdHRlcm5zICAqL1xuXG5jb25zdCBNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uID0ge1xuXHQkZm9ybU9iajogJCgnI21vZHVsZS1iaXRyaXgyNC1pbnRlZ3JhdGlvbi1mb3JtJyksXG5cdGFwaVJvb3Q6IGAke0NvbmZpZy5wYnhVcmx9L3BieGNvcmUvYXBpL21vZHVsZXMvTW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbmAsXG5cdCRzdWJtaXRCdXR0b246ICQoJyNzdWJtaXRidXR0b24nKSxcblx0JHN0YXR1c1RvZ2dsZTogJCgnI21vZHVsZS1zdGF0dXMtdG9nZ2xlJyksXG5cdCRtb2R1bGVTdGF0dXM6ICQoJyNzdGF0dXMnKSxcblx0JGRpcnJ0eUZpZWxkOiAkKCcjZGlycnR5JyksXG5cdCR1c2Vyc0NoZWNrQm94ZXM6ICQoJyNleHRlbnNpb25zLXRhYmxlIC5jaGVja2JveCcpLFxuXG5cdCRnbG9iYWxTZWFyY2g6ICQoJyNnbG9iYWxzZWFyY2gnKSxcblx0JHJlY29yZHNUYWJsZTogJCgnI2V4dGVybmFsLWxpbmUtdGFibGUnKSxcblx0JGFkZE5ld0J1dHRvbjogJCgnI2FkZC1uZXctZXh0ZXJuYWwtbGluZS1idXR0b24nKSxcblxuXHRpbnB1dE51bWJlckpRVFBMOiAnaW5wdXQuZXh0ZXJuYWwtbnVtYmVyJyxcblx0JG1hc2tMaXN0OiBudWxsLFxuXHRnZXROZXdSZWNvcmRzQUpBWFVybDogYCR7Z2xvYmFsUm9vdFVybH1tb2R1bGUtYml0cml4MjQtaW50ZWdyYXRpb24vZ2V0RXh0ZXJuYWxMaW5lc2AsXG5cblx0dmFsaWRhdGVSdWxlczoge1xuXHRcdHBvcnRhbDoge1xuXHRcdFx0aWRlbnRpZmllcjogJ3BvcnRhbCcsXG5cdFx0XHRydWxlczogW1xuXHRcdFx0XHR7XG5cdFx0XHRcdFx0dHlwZTogJ2VtcHR5Jyxcblx0XHRcdFx0XHRwcm9tcHQ6IGdsb2JhbFRyYW5zbGF0ZS5tb2RfYjI0X2lfVmFsaWRhdGVQb3J0YWxFbXB0eSxcblx0XHRcdFx0fSxcblx0XHRcdF0sXG5cdFx0fSxcblx0XHRjbGllbnRfaWQ6IHtcblx0XHRcdGlkZW50aWZpZXI6ICdjbGllbnRfaWQnLFxuXHRcdFx0cnVsZXM6IFtcblx0XHRcdFx0e1xuXHRcdFx0XHRcdHR5cGU6ICdlbXB0eScsXG5cdFx0XHRcdFx0cHJvbXB0OiBnbG9iYWxUcmFuc2xhdGUubW9kX2IyNF9pX1ZhbGlkYXRlQ2xpZW50SURFbXB0eSxcblx0XHRcdFx0fSxcblx0XHRcdF0sXG5cdFx0fSxcblx0XHRjbGllbnRfc2VjcmV0OiB7XG5cdFx0XHRpZGVudGlmaWVyOiAnY2xpZW50X3NlY3JldCcsXG5cdFx0XHRydWxlczogW1xuXHRcdFx0XHR7XG5cdFx0XHRcdFx0dHlwZTogJ2VtcHR5Jyxcblx0XHRcdFx0XHRwcm9tcHQ6IGdsb2JhbFRyYW5zbGF0ZS5tb2RfYjI0X2lfVmFsaWRhdGVDbGllbnRTZWNyZXRFbXB0eSxcblx0XHRcdFx0fSxcblx0XHRcdF0sXG5cdFx0fSxcblx0fSxcblx0aW5pdGlhbGl6ZSgpIHtcblx0XHRNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLmNoZWNrU3RhdHVzVG9nZ2xlKCk7XG5cdFx0d2luZG93LmFkZEV2ZW50TGlzdGVuZXIoJ01vZHVsZVN0YXR1c0NoYW5nZWQnLCBNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLmNoZWNrU3RhdHVzVG9nZ2xlKTtcblx0XHRNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLmluaXRpYWxpemVGb3JtKCk7XG5cblx0XHQkKCcuYXZhdGFyJykuZWFjaCgoKSA9PiB7XG5cdFx0XHRpZiAoJCh0aGlzKS5hdHRyKCdzcmMnKSA9PT0gJycpIHtcblx0XHRcdFx0JCh0aGlzKS5hdHRyKCdzcmMnLCBgJHtnbG9iYWxSb290VXJsfWFzc2V0cy9pbWcvdW5rbm93blBlcnNvbi5qcGdgKTtcblx0XHRcdH1cblx0XHR9KTtcblxuXHRcdCQoJyNleHRlbnNpb25zLW1lbnUgLml0ZW0nKS50YWIoKTtcblxuXHRcdCQoJyNleHRlbnNpb25zLXRhYmxlJykuRGF0YVRhYmxlKHtcblx0XHRcdGxlbmd0aENoYW5nZTogZmFsc2UsXG5cdFx0XHRwYWdpbmc6IGZhbHNlLFxuXHRcdFx0Y29sdW1uczogW1xuXHRcdFx0XHR7IG9yZGVyYWJsZTogZmFsc2UsIHNlYXJjaGFibGU6IGZhbHNlIH0sXG5cdFx0XHRcdG51bGwsXG5cdFx0XHRcdG51bGwsXG5cdFx0XHRcdG51bGwsXG5cdFx0XHRdLFxuXHRcdFx0b3JkZXI6IFsxLCAnYXNjJ10sXG5cdFx0XHRsYW5ndWFnZTogU2VtYW50aWNMb2NhbGl6YXRpb24uZGF0YVRhYmxlTG9jYWxpc2F0aW9uLFxuXHRcdH0pO1xuXG5cdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi4kdXNlcnNDaGVja0JveGVzLmNoZWNrYm94KHtcblx0XHRcdG9uQ2hhbmdlKCkge1xuXHRcdFx0XHRNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLiRkaXJydHlGaWVsZC52YWwoTWF0aC5yYW5kb20oKSk7XG5cdFx0XHRcdE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uJGRpcnJ0eUZpZWxkLnRyaWdnZXIoJ2NoYW5nZScpO1xuXHRcdFx0fSxcblx0XHRcdG9uQ2hlY2tlZCgpIHtcblx0XHRcdFx0Y29uc3QgbnVtYmVyID0gJCh0aGlzKS5hdHRyKCdkYXRhLXZhbHVlJyk7XG5cdFx0XHRcdCQoYCMke251bWJlcn0gLmRpc2FiaWxpdHlgKS5yZW1vdmVDbGFzcygnZGlzYWJsZWQnKTtcblx0XHRcdH0sXG5cdFx0XHRvblVuY2hlY2tlZCgpIHtcblx0XHRcdFx0Y29uc3QgbnVtYmVyID0gJCh0aGlzKS5hdHRyKCdkYXRhLXZhbHVlJyk7XG5cdFx0XHRcdCQoYCMke251bWJlcn0gLmRpc2FiaWxpdHlgKS5hZGRDbGFzcygnZGlzYWJsZWQnKTtcblx0XHRcdH0sXG5cdFx0fSk7XG5cdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi4kdXNlcnNDaGVja0JveGVzLmNoZWNrYm94KCdhdHRhY2ggZXZlbnRzJywgJy5jaGVjay5idXR0b24nLCAnY2hlY2snKTtcblx0XHRNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLiR1c2Vyc0NoZWNrQm94ZXMuY2hlY2tib3goJ2F0dGFjaCBldmVudHMnLCAnLnVuY2hlY2suYnV0dG9uJywgJ3VuY2hlY2snKTtcblxuXHRcdE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uJGdsb2JhbFNlYXJjaC5vbigna2V5dXAnLCAoZSkgPT4ge1xuXHRcdFx0aWYgKGUua2V5Q29kZSA9PT0gMTNcblx0XHRcdFx0fHwgZS5rZXlDb2RlID09PSA4XG5cdFx0XHRcdHx8IE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uJGdsb2JhbFNlYXJjaC52YWwoKS5sZW5ndGggPT09IDApIHtcblx0XHRcdFx0Y29uc3QgdGV4dCA9IGAke01vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uJGdsb2JhbFNlYXJjaC52YWwoKX1gO1xuXHRcdFx0XHRNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLmFwcGx5RmlsdGVyKHRleHQpO1xuXHRcdFx0fVxuXHRcdH0pO1xuXG5cdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi4kcmVjb3Jkc1RhYmxlLmRhdGFUYWJsZSh7XG5cdFx0XHRzZXJ2ZXJTaWRlOiB0cnVlLFxuXHRcdFx0cHJvY2Vzc2luZzogdHJ1ZSxcblx0XHRcdGFqYXg6IHtcblx0XHRcdFx0dXJsOiBNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLmdldE5ld1JlY29yZHNBSkFYVXJsLFxuXHRcdFx0XHR0eXBlOiAnUE9TVCcsXG5cdFx0XHRcdGRhdGFTcmM6ICdkYXRhJyxcblx0XHRcdH0sXG5cdFx0XHRjb2x1bW5zOiBbXG5cdFx0XHRcdHsgZGF0YTogbnVsbCB9LFxuXHRcdFx0XHR7IGRhdGE6ICduYW1lJyB9LFxuXHRcdFx0XHR7IGRhdGE6ICdudW1iZXInIH0sXG5cdFx0XHRcdHsgZGF0YTogJ2FsaWFzJyB9LFxuXHRcdFx0XHR7IGRhdGE6IG51bGwgfSxcblx0XHRcdF0sXG5cdFx0XHRwYWdpbmc6IHRydWUsXG5cdFx0XHQvLyBzY3JvbGxZOiAkKHdpbmRvdykuaGVpZ2h0KCkgLSBNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLiRyZWNvcmRzVGFibGUub2Zmc2V0KCkudG9wLTIwMCxcblx0XHRcdC8vIHN0YXRlU2F2ZTogdHJ1ZSxcblx0XHRcdHNEb206ICdydGlwJyxcblx0XHRcdGRlZmVyUmVuZGVyOiB0cnVlLFxuXHRcdFx0cGFnZUxlbmd0aDogMTcsXG5cdFx0XHRiQXV0b1dpZHRoOiBmYWxzZSxcblxuXHRcdFx0Ly8gc2Nyb2xsQ29sbGFwc2U6IHRydWUsXG5cdFx0XHQvLyBzY3JvbGxlcjogdHJ1ZSxcblx0XHRcdC8qKlxuXHRcdFx0ICog0JrQvtC90YHRgtGA0YPQutGC0L7RgCDRgdGC0YDQvtC60Lgg0LfQsNC/0LjRgdC4XG5cdFx0XHQgKiBAcGFyYW0gcm93XG5cdFx0XHQgKiBAcGFyYW0gZGF0YVxuXHRcdFx0ICovXG5cdFx0XHRjcmVhdGVkUm93KHJvdywgZGF0YSkge1xuXHRcdFx0XHRjb25zdCB0ZW1wbGF0ZU5hbWUgPVxuXHRcdFx0XHRcdCc8ZGl2IGNsYXNzPVwidWkgdHJhbnNwYXJlbnQgZmx1aWQgaW5wdXQgaW5saW5lLWVkaXRcIj4nICtcblx0XHRcdFx0XHRgPGlucHV0IGNsYXNzPVwiZXh0ZXJuYWwtbmFtZVwiIHR5cGU9XCJ0ZXh0XCIgZGF0YS12YWx1ZT1cIiR7ZGF0YS5uYW1lfVwiIHZhbHVlPVwiJHtkYXRhLm5hbWV9XCI+YCArXG5cdFx0XHRcdFx0JzwvZGl2Pic7XG5cblx0XHRcdFx0Y29uc3QgdGVtcGxhdGVOdW1iZXIgPVxuXHRcdFx0XHRcdCc8ZGl2IGNsYXNzPVwidWkgdHJhbnNwYXJlbnQgZmx1aWQgaW5wdXQgaW5saW5lLWVkaXRcIj4nICtcblx0XHRcdFx0XHRgPGlucHV0IGNsYXNzPVwiZXh0ZXJuYWwtbnVtYmVyXCIgdHlwZT1cInRleHRcIiBkYXRhLXZhbHVlPVwiJHtkYXRhLm51bWJlcn1cIiB2YWx1ZT1cIiR7ZGF0YS5udW1iZXJ9XCI+YCArXG5cdFx0XHRcdFx0JzwvZGl2Pic7XG5cblx0XHRcdFx0Y29uc3QgdGVtcGxhdGVEaWQgPVxuXHRcdFx0XHRcdCc8ZGl2IGNsYXNzPVwidWkgdHJhbnNwYXJlbnQgaW5wdXQgaW5saW5lLWVkaXRcIj4nICtcblx0XHRcdFx0XHRgPGlucHV0IGNsYXNzPVwiZXh0ZXJuYWwtYWxpYXNlc1wiIHR5cGU9XCJ0ZXh0XCIgZGF0YS12YWx1ZT1cIiR7ZGF0YS5hbGlhc31cIiB2YWx1ZT1cIiR7ZGF0YS5hbGlhc31cIj5gICtcblx0XHRcdFx0XHQnPC9kaXY+JztcblxuXHRcdFx0XHRjb25zdCB0ZW1wbGF0ZURlbGV0ZUJ1dHRvbiA9ICc8ZGl2IGNsYXNzPVwidWkgc21hbGwgYmFzaWMgaWNvbiBidXR0b25zIGFjdGlvbi1idXR0b25zXCI+JyArXG5cdFx0XHRcdFx0YDxhIGhyZWY9XCIjXCIgZGF0YS12YWx1ZSA9IFwiJHtkYXRhLmlkfVwiYCArXG5cdFx0XHRcdFx0YCBjbGFzcz1cInVpIGJ1dHRvbiBkZWxldGUgdHdvLXN0ZXBzLWRlbGV0ZSBwb3B1cGVkXCIgZGF0YS1jb250ZW50PVwiJHtnbG9iYWxUcmFuc2xhdGUuYnRfVG9vbFRpcERlbGV0ZX1cIj5gICtcblx0XHRcdFx0XHQnPGkgY2xhc3M9XCJpY29uIHRyYXNoIHJlZFwiPjwvaT48L2E+PC9kaXY+JztcblxuXHRcdFx0XHQkKCd0ZCcsIHJvdykuZXEoMCkuaHRtbCgnPGkgY2xhc3M9XCJ1aSB1c2VyIGNpcmNsZSBpY29uXCI+PC9pPicpO1xuXHRcdFx0XHQkKCd0ZCcsIHJvdykuZXEoMSkuaHRtbCh0ZW1wbGF0ZU5hbWUpO1xuXHRcdFx0XHQkKCd0ZCcsIHJvdykuZXEoMikuaHRtbCh0ZW1wbGF0ZU51bWJlcik7XG5cdFx0XHRcdCQoJ3RkJywgcm93KS5lcSgzKS5odG1sKHRlbXBsYXRlRGlkKTtcblx0XHRcdFx0JCgndGQnLCByb3cpLmVxKDQpLmh0bWwodGVtcGxhdGVEZWxldGVCdXR0b24pO1xuXHRcdFx0fSxcblx0XHRcdC8qKlxuXHRcdFx0ICogRHJhdyBldmVudCAtIGZpcmVkIG9uY2UgdGhlIHRhYmxlIGhhcyBjb21wbGV0ZWQgYSBkcmF3LlxuXHRcdFx0ICovXG5cdFx0XHRkcmF3Q2FsbGJhY2soKSB7XG5cdFx0XHRcdE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uaW5pdGlhbGl6ZUlucHV0bWFzaygkKE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uaW5wdXROdW1iZXJKUVRQTCkpO1xuXHRcdFx0fSxcblx0XHRcdGxhbmd1YWdlOiBTZW1hbnRpY0xvY2FsaXphdGlvbi5kYXRhVGFibGVMb2NhbGlzYXRpb24sXG5cdFx0XHRvcmRlcmluZzogZmFsc2UsXG5cdFx0fSk7XG5cdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi5kYXRhVGFibGUgPSBNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLiRyZWNvcmRzVGFibGUuRGF0YVRhYmxlKCk7XG5cblx0XHQvLyDQlNCy0L7QudC90L7QuSDQutC70LjQuiDQvdCwINC/0L7Qu9C1INCy0LLQvtC00LAg0L3QvtC80LXRgNCwXG5cdFx0JCgnYm9keScpLm9uKCdmb2N1c2luJywgJy5leHRlcm5hbC1uYW1lLCAuZXh0ZXJuYWwtbnVtYmVyLCAuZXh0ZXJuYWwtYWxpYXNlcyAnLCAoZSkgPT4ge1xuXHRcdFx0JChlLnRhcmdldCkudHJhbnNpdGlvbignZ2xvdycpO1xuXHRcdFx0JChlLnRhcmdldCkuY2xvc2VzdCgnZGl2Jylcblx0XHRcdFx0LnJlbW92ZUNsYXNzKCd0cmFuc3BhcmVudCcpXG5cdFx0XHRcdC5hZGRDbGFzcygnY2hhbmdlZC1maWVsZCcpO1xuXHRcdFx0JChlLnRhcmdldCkuYXR0cigncmVhZG9ubHknLCBmYWxzZSk7XG5cdFx0XHRNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLiRkaXJydHlGaWVsZC52YWwoTWF0aC5yYW5kb20oKSk7XG5cdFx0XHRNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLiRkaXJydHlGaWVsZC50cmlnZ2VyKCdjaGFuZ2UnKTtcblx0XHR9KTtcblxuXHRcdC8vINCe0YLQv9GA0LDQstC60LAg0YTQvtGA0LzRiyDQvdCwINGB0LXRgNCy0LXRgCDQv9C+INGD0YXQvtC00YMg0YEg0L/QvtC70Y8g0LLQstC+0LTQsFxuXHRcdCQoJ2JvZHknKS5vbignZm9jdXNvdXQnLCAnLmV4dGVybmFsLW5hbWUsIC5leHRlcm5hbC1udW1iZXIsIC5leHRlcm5hbC1hbGlhc2VzJywgKGUpID0+IHtcblx0XHRcdCQoZS50YXJnZXQpLmNsb3Nlc3QoJ2RpdicpXG5cdFx0XHRcdC5hZGRDbGFzcygndHJhbnNwYXJlbnQnKVxuXHRcdFx0XHQucmVtb3ZlQ2xhc3MoJ2NoYW5nZWQtZmllbGQnKTtcblx0XHRcdCQoZS50YXJnZXQpLmF0dHIoJ3JlYWRvbmx5JywgdHJ1ZSk7XG5cdFx0XHRNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLiRkaXJydHlGaWVsZC52YWwoTWF0aC5yYW5kb20oKSk7XG5cdFx0XHRNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLiRkaXJydHlGaWVsZC50cmlnZ2VyKCdjaGFuZ2UnKTtcblx0XHR9KTtcblxuXHRcdC8vINCa0LvQuNC6INC90LAg0LrQvdC+0L/QutGDINGD0LTQsNC70LjRgtGMXG5cdFx0JCgnYm9keScpLm9uKCdjbGljaycsICdhLmRlbGV0ZScsIChlKSA9PiB7XG5cdFx0XHRlLnByZXZlbnREZWZhdWx0KCk7XG5cdFx0XHQkKGUudGFyZ2V0KS5jbG9zZXN0KCd0cicpLnJlbW92ZSgpO1xuXHRcdFx0aWYgKE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uJHJlY29yZHNUYWJsZS5maW5kKCd0Ym9keSA+IHRyJykubGVuZ3RoPT09MCl7XG5cdFx0XHRcdE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uJHJlY29yZHNUYWJsZS5maW5kKCd0Ym9keScpLmFwcGVuZCgnPHRyIGNsYXNzPVwib2RkXCI+PC90cj4nKTtcblx0XHRcdH1cblx0XHRcdE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uJGRpcnJ0eUZpZWxkLnZhbChNYXRoLnJhbmRvbSgpKTtcblx0XHRcdE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uJGRpcnJ0eUZpZWxkLnRyaWdnZXIoJ2NoYW5nZScpO1xuXHRcdH0pO1xuXG5cdFx0Ly8g0JTQvtCx0LDQstC70LXQvdC40LUg0L3QvtCy0L7QuSDRgdGC0YDQvtC60Lhcblx0XHRNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLiRhZGROZXdCdXR0b24ub24oJ2NsaWNrJywgKGUpID0+IHtcblx0XHRcdGUucHJldmVudERlZmF1bHQoKTtcblx0XHRcdCQoJy5kYXRhVGFibGVzX2VtcHR5JykucmVtb3ZlKCk7XG5cdFx0XHRjb25zdCBpZCA9IGBuZXcke01hdGguZmxvb3IoTWF0aC5yYW5kb20oKSAqIE1hdGguZmxvb3IoNTAwKSl9YDtcblx0XHRcdGNvbnN0IHJvd1RlbXBsYXRlID0gYDx0ciBpZD1cIiR7aWR9XCIgY2xhc3M9XCJleHQtbGluZS1yb3dcIj5gICtcblx0XHRcdFx0Jzx0ZD48aSBjbGFzcz1cInVpIHVzZXIgY2lyY2xlIGljb25cIj48L2k+PC90ZD4nICtcblx0XHRcdFx0Jzx0ZD48ZGl2IGNsYXNzPVwidWkgZmx1aWQgaW5wdXQgaW5saW5lLWVkaXQgY2hhbmdlZC1maWVsZFwiPjxpbnB1dCBjbGFzcz1cImV4dGVybmFsLW5hbWVcIiB0eXBlPVwidGV4dFwiIGRhdGEtdmFsdWU9XCJcIiB2YWx1ZT1cIlwiPjwvZGl2PjwvdGQ+JyArXG5cdFx0XHRcdCc8dGQ+PGRpdiBjbGFzcz1cInVpIGlucHV0IGlubGluZS1lZGl0IGNoYW5nZWQtZmllbGRcIj48aW5wdXQgY2xhc3M9XCJleHRlcm5hbC1udW1iZXJcIiB0eXBlPVwidGV4dFwiIGRhdGEtdmFsdWU9XCJcIiB2YWx1ZT1cIlwiPjwvZGl2PjwvdGQ+JyArXG5cdFx0XHRcdCc8dGQ+PGRpdiBjbGFzcz1cInVpIGlucHV0IGlubGluZS1lZGl0IGNoYW5nZWQtZmllbGRcIj48aW5wdXQgY2xhc3M9XCJleHRlcm5hbC1hbGlhc2VzXCIgdHlwZT1cInRleHRcIiBkYXRhLXZhbHVlPVwiXCIgdmFsdWU9XCJcIj48L2Rpdj48L3RkPicgK1xuXHRcdFx0XHQnPHRkPjxkaXYgY2xhc3M9XCJ1aSBzbWFsbCBiYXNpYyBpY29uIGJ1dHRvbnMgYWN0aW9uLWJ1dHRvbnNcIj4nICtcblx0XHRcdFx0YDxhIGhyZWY9XCIjXCIgY2xhc3M9XCJ1aSBidXR0b24gZGVsZXRlIHR3by1zdGVwcy1kZWxldGUgcG9wdXBlZFwiIGRhdGEtdmFsdWUgPSBcIm5ld1wiIGRhdGEtY29udGVudD1cIiR7Z2xvYmFsVHJhbnNsYXRlLmJ0X1Rvb2xUaXBEZWxldGV9XCI+YCArXG5cdFx0XHRcdCc8aSBjbGFzcz1cImljb24gdHJhc2ggcmVkXCI+PC9pPjwvYT48L2Rpdj48L3RkPicgK1xuXHRcdFx0XHQnPC90cj4nO1xuXHRcdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi4kcmVjb3Jkc1RhYmxlLmZpbmQoJ3Rib2R5ID4gdHI6Zmlyc3QnKS5iZWZvcmUocm93VGVtcGxhdGUpO1xuXHRcdFx0JChgdHIjJHtpZH0gaW5wdXRgKS50cmFuc2l0aW9uKCdnbG93Jyk7XG5cdFx0XHQkKGB0ciMke2lkfSAuZXh0ZXJuYWwtbmFtZWApLmZvY3VzKCk7XG5cdFx0XHRNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLmluaXRpYWxpemVJbnB1dG1hc2soJChgdHIjJHtpZH0gLmV4dGVybmFsLW51bWJlcmApKTtcblx0XHRcdE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uJGRpcnJ0eUZpZWxkLnZhbChNYXRoLnJhbmRvbSgpKTtcblx0XHRcdE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uJGRpcnJ0eUZpZWxkLnRyaWdnZXIoJ2NoYW5nZScpO1xuXHRcdH0pO1xuXHR9LFxuXHQvKipcblx0ICog0JjQt9C80LXQvdC10L3QuNC1INGB0YLQsNGC0YPRgdCwINC60L3QvtC/0L7QuiDQv9GA0Lgg0LjQt9C80LXQvdC10L3QuNC4INGB0YLQsNGC0YPRgdCwINC80L7QtNGD0LvRj1xuXHQgKi9cblx0Y2hlY2tTdGF0dXNUb2dnbGUoKSB7XG5cdFx0aWYgKE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uJHN0YXR1c1RvZ2dsZS5jaGVja2JveCgnaXMgY2hlY2tlZCcpKSB7XG5cdFx0XHQkKCdbZGF0YS10YWIgPSBcImdlbmVyYWxcIl0gLmRpc2FiaWxpdHknKS5yZW1vdmVDbGFzcygnZGlzYWJsZWQnKTtcblx0XHRcdE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uJG1vZHVsZVN0YXR1cy5zaG93KCk7XG5cdFx0XHRNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLnRlc3RDb25uZWN0aW9uKCk7XG5cdFx0fSBlbHNlIHtcblx0XHRcdE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uJG1vZHVsZVN0YXR1cy5oaWRlKCk7XG5cdFx0XHQkKCdbZGF0YS10YWIgPSBcImdlbmVyYWxcIl0gLmRpc2FiaWxpdHknKS5hZGRDbGFzcygnZGlzYWJsZWQnKTtcblx0XHR9XG5cdH0sXG5cdC8qKlxuXHQgKiDQn9GA0LjQvNC10L3QtdC90LjQtSDQvdCw0YHRgtGA0L7QtdC6INC80L7QtNGD0LvRjyDQv9C+0YHQu9C1INC40LfQvNC10L3QtdC90LjRjyDQtNCw0L3QvdGL0YUg0YTQvtGA0LzRi1xuXHQgKi9cblx0YXBwbHlDb25maWd1cmF0aW9uQ2hhbmdlcygpIHtcblx0XHQkLmFwaSh7XG5cdFx0XHR1cmw6IGAke01vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uYXBpUm9vdH0vcmVsb2FkYCxcblx0XHRcdG9uOiAnbm93Jyxcblx0XHRcdHN1Y2Nlc3NUZXN0KHJlc3BvbnNlKSB7XG5cdFx0XHRcdC8vIHRlc3Qgd2hldGhlciBhIEpTT04gcmVzcG9uc2UgaXMgdmFsaWRcblx0XHRcdFx0cmV0dXJuIHJlc3BvbnNlICE9PSB1bmRlZmluZWRcblx0XHRcdFx0XHQmJiBPYmplY3Qua2V5cyhyZXNwb25zZSkubGVuZ3RoID4gMFxuXHRcdFx0XHRcdCYmIHJlc3BvbnNlLnJlc3VsdCA9PT0gdHJ1ZTtcblx0XHRcdH0sXG5cdFx0XHRvblN1Y2Nlc3MoKSB7XG5cdFx0XHRcdE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uY2hlY2tTdGF0dXNUb2dnbGUoKTtcblx0XHRcdH0sXG5cdFx0fSk7XG5cdH0sXG5cdC8qKlxuXHQgKiDQn9GA0L7QstC10YDQutCwINGB0L7QtdC00LjQvdC10L3QuNGPINGBINGB0LXRgNCy0LXRgNC+0LwgQml0cml4MjRcblx0ICogQHJldHVybnMge2Jvb2xlYW59XG5cdCAqL1xuXHR0ZXN0Q29ubmVjdGlvbigpIHtcblx0XHQkLmFwaSh7XG5cdFx0XHR1cmw6IGAke01vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uYXBpUm9vdH0vY2hlY2tgLFxuXHRcdFx0b246ICdub3cnLFxuXHRcdFx0c3VjY2Vzc1Rlc3QocmVzcG9uc2UpIHtcblx0XHRcdFx0cmV0dXJuIHJlc3BvbnNlICE9PSB1bmRlZmluZWRcblx0XHRcdFx0JiYgT2JqZWN0LmtleXMocmVzcG9uc2UpLmxlbmd0aCA+IDBcblx0XHRcdFx0JiYgcmVzcG9uc2UucmVzdWx0ICE9PSB1bmRlZmluZWRcblx0XHRcdFx0JiYgcmVzcG9uc2UucmVzdWx0ID09PSB0cnVlO1xuXHRcdFx0fSxcblx0XHRcdG9uU3VjY2VzcygpIHtcblx0XHRcdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi4kbW9kdWxlU3RhdHVzLnJlbW92ZUNsYXNzKCdncmV5JykuYWRkQ2xhc3MoJ2dyZWVuJyk7XG5cdFx0XHRcdE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uJG1vZHVsZVN0YXR1cy5odG1sKGdsb2JhbFRyYW5zbGF0ZS5tb2RfYjI0X2lfQ29ubmVjdGVkKTtcblx0XHRcdFx0Ly8gY29uc3QgRnVsbE5hbWUgPSBgJHtyZXNwb25zZS5kYXRhLmRhdGEuTEFTVF9OQU1FfSAke3Jlc3BvbnNlLmRhdGEuZGF0YS5OQU1FfWA7XG5cdFx0XHR9LFxuXHRcdFx0b25GYWlsdXJlKCkge1xuXHRcdFx0XHRNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLiRtb2R1bGVTdGF0dXMucmVtb3ZlQ2xhc3MoJ2dyZWVuJykuYWRkQ2xhc3MoJ2dyZXknKTtcblx0XHRcdFx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi4kbW9kdWxlU3RhdHVzLmh0bWwoZ2xvYmFsVHJhbnNsYXRlLm1vZF9iMjRfaV9EaXNjb25uZWN0ZWQpO1xuXHRcdFx0fSxcblx0XHRcdG9uUmVzcG9uc2UocmVzcG9uc2UpIHtcblx0XHRcdFx0JCgnLm1lc3NhZ2UuYWpheCcpLnJlbW92ZSgpO1xuXHRcdFx0XHQvLyBEZWJ1ZyBtb2RlXG5cdFx0XHRcdGlmICh0eXBlb2YgKHJlc3BvbnNlLmRhdGEpICE9PSAndW5kZWZpbmVkJykge1xuXHRcdFx0XHRcdGxldCB2aXN1YWxFcnJvclN0cmluZyA9IEpTT04uc3RyaW5naWZ5KHJlc3BvbnNlLmRhdGEsIG51bGwsIDIpO1xuXG5cdFx0XHRcdFx0aWYgKHR5cGVvZiB2aXN1YWxFcnJvclN0cmluZyA9PT0gJ3N0cmluZycpIHtcblx0XHRcdFx0XHRcdHZpc3VhbEVycm9yU3RyaW5nID0gdmlzdWFsRXJyb3JTdHJpbmcucmVwbGFjZSgvXFxuL2csICc8YnIvPicpO1xuXG5cdFx0XHRcdFx0XHRpZiAoT2JqZWN0LmtleXMocmVzcG9uc2UpLmxlbmd0aCA+IDAgJiYgcmVzcG9uc2UucmVzdWx0ICE9PSB0cnVlKSB7XG5cdFx0XHRcdFx0XHRcdE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uJGZvcm1PYmpcblx0XHRcdFx0XHRcdFx0XHQuYWZ0ZXIoYDxkaXYgY2xhc3M9XCJ1aSBlcnJvciBtZXNzYWdlIGFqYXhcIj5cdFx0XHRcdFx0XHRcblx0XHRcdFx0XHRcdFx0XHRcdDxwcmUgc3R5bGU9J3doaXRlLXNwYWNlOiBwcmUtd3JhcCc+JHt2aXN1YWxFcnJvclN0cmluZ308L3ByZT5cdFx0XHRcdFx0XHRcdFx0XHRcdCAgXG5cdFx0XHRcdFx0XHRcdFx0PC9kaXY+YCk7XG5cdFx0XHRcdFx0XHR9XG5cdFx0XHRcdFx0fVxuXHRcdFx0XHR9XG5cdFx0XHR9LFxuXHRcdH0pO1xuXHR9LFxuXHQvKipcblx0ICog0JjQvdC40YbQuNCw0LvQuNC30LjRgNGD0LXRgiDQutGA0LDRgdC40LLQvtC1INC/0YDQtdC00YHRgtCw0LLQu9C10L3QuNC1INC90L7QvNC10YDQvtCyXG5cdCAqL1xuXHRpbml0aWFsaXplSW5wdXRtYXNrKCRlbCkge1xuXHRcdGlmIChNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLiRtYXNrTGlzdCA9PT0gbnVsbCkge1xuXHRcdFx0Ly8g0J/QvtC00LPQvtGC0L7QstC40Lwg0YLQsNCx0LvQuNGG0YMg0LTQu9GPINGB0L7RgNGC0LjRgNC+0LLQutC4XG5cdFx0XHRNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLiRtYXNrTGlzdCA9ICQubWFza3NTb3J0KElucHV0TWFza1BhdHRlcm5zLCBbJyMnXSwgL1swLTldfCMvLCAnbWFzaycpO1xuXHRcdH1cblx0XHQkZWwuaW5wdXRtYXNrcyh7XG5cdFx0XHRpbnB1dG1hc2s6IHtcblx0XHRcdFx0ZGVmaW5pdGlvbnM6IHtcblx0XHRcdFx0XHQnIyc6IHtcblx0XHRcdFx0XHRcdHZhbGlkYXRvcjogJ1swLTldJyxcblx0XHRcdFx0XHRcdGNhcmRpbmFsaXR5OiAxLFxuXHRcdFx0XHRcdH0sXG5cdFx0XHRcdH0sXG5cdFx0XHRcdHNob3dNYXNrT25Ib3ZlcjogZmFsc2UsXG5cdFx0XHRcdC8vIG9uY2xlYXJlZDogZXh0ZW5zaW9uLmNiT25DbGVhcmVkTW9iaWxlTnVtYmVyLFxuXHRcdFx0XHRvbmNvbXBsZXRlOiBNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLmNiT25Db21wbGV0ZU51bWJlcixcblx0XHRcdFx0Ly8gY2xlYXJJbmNvbXBsZXRlOiB0cnVlLFxuXHRcdFx0XHRvbkJlZm9yZVBhc3RlOiBNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLmNiT25OdW1iZXJCZWZvcmVQYXN0ZSxcblx0XHRcdFx0Ly8gcmVnZXg6IC9cXEQrLyxcblx0XHRcdH0sXG5cdFx0XHRtYXRjaDogL1swLTldLyxcblx0XHRcdHJlcGxhY2U6ICc5Jyxcblx0XHRcdGxpc3Q6IE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uJG1hc2tMaXN0LFxuXHRcdFx0bGlzdEtleTogJ21hc2snLFxuXG5cdFx0fSk7XG5cdH0sXG5cdC8qKlxuXHQgKiDQntGH0LjRgdGC0LrQsCDQvdC+0LzQtdGA0LAg0L/QtdGA0LXQtCDQstGB0YLQsNCy0LrQvtC5INC+0YIg0LvQuNGI0L3QuNGFINGB0LjQvNCy0L7Qu9C+0LJcblx0ICogQHJldHVybnMge2Jvb2xlYW58Knx2b2lkfHN0cmluZ31cblx0ICovXG5cdGNiT25OdW1iZXJCZWZvcmVQYXN0ZShwYXN0ZWRWYWx1ZSkge1xuXHRcdHJldHVybiBwYXN0ZWRWYWx1ZS5yZXBsYWNlKC9cXEQrL2csICcnKTtcblx0fSxcblx0LyoqXG5cdCAqINCf0L7RgdC70LUg0LLQstC+0LTQsCDQvdC+0LzQtdGA0LBcblx0ICovXG5cdGNiT25Db21wbGV0ZU51bWJlcihlKXtcblx0XHRjb25zdCBkaWRFbCA9ICQoZS50YXJnZXQpLmNsb3Nlc3QoJ3RyJykuZmluZCgnaW5wdXQuZXh0ZXJuYWwtYWxpYXNlcycpO1xuXHRcdGlmIChkaWRFbC52YWwoKT09PScnKXtcblx0XHRcdGRpZEVsLnZhbCgkKGUudGFyZ2V0KS5pbnB1dG1hc2soJ3VubWFza2VkdmFsdWUnKSk7XG5cdFx0fVxuXHR9LFxuXHQvKipcblx0ICog0JrQvtC70LHQtdC6INC/0LXRgNC10LQg0L7RgtC/0YDQsNCy0LrQvtC5INGE0L7RgNC80Ytcblx0ICogQHBhcmFtIHNldHRpbmdzXG5cdCAqIEByZXR1cm5zIHsqfVxuXHQgKi9cblx0Y2JCZWZvcmVTZW5kRm9ybShzZXR0aW5ncykge1xuXHRcdGNvbnN0IHJlc3VsdCA9IHNldHRpbmdzO1xuXHRcdHJlc3VsdC5kYXRhID0gTW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi4kZm9ybU9iai5mb3JtKCdnZXQgdmFsdWVzJyk7XG5cblx0XHRjb25zdCBhcnJFeHRlcm5hbExpbmVzID0gW107XG5cdFx0JCgnI2V4dGVybmFsLWxpbmUtdGFibGUgdHInKS5lYWNoKChpbmRleCwgb2JqKSA9PiB7XG5cdFx0XHRhcnJFeHRlcm5hbExpbmVzLnB1c2goe1xuXHRcdFx0XHRpZDogJChvYmopLmF0dHIoJ2lkJyksXG5cdFx0XHRcdG5hbWU6ICQob2JqKS5maW5kKCdpbnB1dC5leHRlcm5hbC1uYW1lJykudmFsKCksXG5cdFx0XHRcdG51bWJlcjogJChvYmopLmZpbmQoJ2lucHV0LmV4dGVybmFsLW51bWJlcicpLnZhbCgpLFxuXHRcdFx0XHRhbGlhczogJChvYmopLmZpbmQoJ2lucHV0LmV4dGVybmFsLWFsaWFzZXMnKS52YWwoKSxcblx0XHRcdH0pO1xuXHRcdH0pO1xuXHRcdHJlc3VsdC5kYXRhLmV4dGVybmFsTGluZXMgPSBKU09OLnN0cmluZ2lmeShhcnJFeHRlcm5hbExpbmVzKTtcblxuXHRcdHJldHVybiByZXN1bHQ7XG5cdH0sXG5cblx0LyoqXG5cdCAqINCa0L7Qu9Cx0LXQuiDQv9C+0YHQu9C1INC+0YLQv9GA0LDQstC60Lgg0YTQvtGA0LzRi1xuXHQgKi9cblx0Y2JBZnRlclNlbmRGb3JtKCkge1xuXHRcdE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24uYXBwbHlDb25maWd1cmF0aW9uQ2hhbmdlcygpO1xuXHR9LFxuXHRpbml0aWFsaXplRm9ybSgpIHtcblx0XHRGb3JtLiRmb3JtT2JqID0gTW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi4kZm9ybU9iajtcblx0XHRGb3JtLnVybCA9IGAke2dsb2JhbFJvb3RVcmx9bW9kdWxlLWJpdHJpeDI0LWludGVncmF0aW9uL3NhdmVgO1xuXHRcdEZvcm0udmFsaWRhdGVSdWxlcyA9IE1vZHVsZUJpdHJpeDI0SW50ZWdyYXRpb24udmFsaWRhdGVSdWxlcztcblx0XHRGb3JtLmNiQmVmb3JlU2VuZEZvcm0gPSBNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLmNiQmVmb3JlU2VuZEZvcm07XG5cdFx0Rm9ybS5jYkFmdGVyU2VuZEZvcm0gPSBNb2R1bGVCaXRyaXgyNEludGVncmF0aW9uLmNiQWZ0ZXJTZW5kRm9ybTtcblx0XHRGb3JtLmluaXRpYWxpemUoKTtcblx0fSxcbn07XG5cbiQoZG9jdW1lbnQpLnJlYWR5KCgpID0+IHtcblx0TW9kdWxlQml0cml4MjRJbnRlZ3JhdGlvbi5pbml0aWFsaXplKCk7XG59KTtcblxuIl19