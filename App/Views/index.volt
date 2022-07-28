
<form class="ui large grey segment form" id="module-bitrix24-integration-form">
    <input type="hidden" name="dirrty" id="dirrty"/>
    <input type="hidden" name="isREST" id="isREST"/>
    <div class="ui grey top right attached label" id="status"><i class="spinner loading icon"></i>{{ t._("mod_b24_i_UpdateStatus") }}</div>
    <div class="ui top attached tabular menu" id="extensions-menu">
        <a class="item active" data-tab="general">{{ t._('mod_b24_i_GeneralSettings') }}</a>
        <a class="item" data-tab="users">{{ t._('mod_b24_i_UsersFilter') }}</a>
        <a class="item" data-tab="external_lines">{{ t._('mod_b24_i_ExternalLines') }}</a>
        <a class="item" data-tab="docs">{{ t._('mod_b24_i_Docs') }}</a>
        <a class="item" data-tab="other">{{ t._('mod_b24_i_Other') }}</a>
    </div>
    <div class="ui bottom attached tab segment" data-tab="other">
        <div class="ten wide field">
            <label >{{ t._('mod_b24_i_callbackQueue') }}</label>
            {{ form.render('callbackQueue') }}
        </div>
        <div class="ten wide field">
            <label >{{ t._('mod_b24_i_responsibleMissedCalls') }}</label>
            {{ form.render('responsibleMissedCalls') }}
        </div>
        <div class="field">
            <div class="ui segment">
                <div class="ui toggle checkbox">
                    {{ form.render('export_records') }}
                    <label>{{ t._('mod_b24_i_ExportRecords') }}</label>
                </div>
            </div>
        </div>
        <div class="field">
            <div class="ui segment">
                <div class="ui toggle checkbox ">
                    {{ form.render('backgroundUpload') }}
                    <label>{{ t._('mod_b24_i_backgroundUpload') }}</label>
                </div>
            </div>
        </div>
    </div>
    <div class="ui bottom attached tab segment active" data-tab="general">
        <div class="ten wide field disability">
            <label >{{ t._('mod_b24_i_PortalUrl') }}</label>
            <div class="disability ui fluid action input">
                {{ form.render('portal') }}
                <button class="ui positive basic button" id="login-button">{{ t._("mod_b24_i_Auth") }}</button>
            </div>
        </div>

        <div class="field">
            <label>{{ t._('mod_b24_i_Region') }}</label>
            {{ form.render('b24_region') }}
        </div>
        <div class="ui message" id="RU-INFO">
            <i class="close icon"></i>
            <div class="header">
                Внимание:
            </div>
            <p>Если на вашем портале bitrix24 установлено приложение типом цены "<b>подписка</b>", то регион выбран верно.</p>
            <p>Если тип цены "<b>бесплатно</b>", укажите регион "<b>Весь мир</b>".</p>
        </div>
        <div id="b24-app-data">
            <div class="field">
                <label>{{ t._('mod_b24_i_client_id') }}</label>
                {{ form.render('client_id') }}
            </div>
            <div class="field">
                <label>{{ t._('mod_b24_i_client_secret') }}</label>
                {{ form.render('client_secret') }}
            </div>
            <br>
        </div>

        <div class="field">
            <div class="ui segment">
                <div class="ui toggle checkbox ">
                    {{ form.render('export_cdr') }}
                    <label>{{ t._('mod_b24_i_NotifyOnCall') }}</label>
                </div>
            </div>
        </div>
        <div class="field">
            <div class="ui segment">
                <div class="ui toggle checkbox ">
                    {{ form.render('crmCreateLead') }}
                    <label>{{ t._('mod_b24_i_CrmCreate') }}</label>
                </div>
            </div>
        </div>
        <div class="field">
            <div class="ui segment">
                <div class="ui toggle checkbox">
                    {{ form.render('use_interception') }}
                    <label>{{ t._('mod_b24_i_useInterception') }}</label>
                </div>
            </div>
        </div>
        <div class="field">
            <label>{{ t._('mod_b24_i_interceptionCallDuration') }}</label>
            {{ form.render('interception_call_duration') }}
        </div>
    </div>
    <div class="ui bottom attached tab segment" data-tab="users">
        <div class="ui basic buttons">
            <button class="ui small check button">{{ t._('mod_b24_i_EnableAll') }}</button>
            <button class="ui small uncheck button">{{ t._('mod_b24_i_DisableAll') }}</button>
        </div>
        {% for extension in extensions %}
            {% if loop.first %}
                <table class="ui selectable compact table" id="extensions-table" data-page-length='12'>
                <thead>
                <tr>
                    <th></th>
                    <th>{{ t._('ex_Name') }}</th>
                    <th class="center aligned">{{ t._('ex_Extension') }}</th>
                    <th class="center aligned">{{ t._('ex_Mobile') }}</th>
                </tr>
                </thead>
                <tbody>
            {% endif %}

            <tr class="extension-row" id="{{ extension['id'] }}">
                <td class="collapsing">
                <div class="ui fitted toggle checkbox">
                <input type="checkbox" {% if extension['status']!='disabled' %} checked {% endif %} name="user-{{ extension['userid'] }}" data-value="{{ extension['id'] }}"> <label></label>
                </div>
                </td>
                <td class="{{ extension['status'] }}"><img src="{{ extension['avatar'] }}" class="ui avatar image"
                                                                      data-value="{{ extension['userid'] }}"> {{ extension['username'] }}
                </td>
                <td class="center aligned {{ extension['status'] }}">{{ extension['number'] }}</td>
                <td class="center aligned {{ extension['status'] }}">{{ extension['mobile'] }}</td>
            </tr>
            {% if loop.last %}
                </tbody>
                </table>
            {% endif %}
        {% endfor %}
    </div>
    <div class="ui bottom attached tab segment" data-tab="external_lines">
        {{ link_to("#", '<i class="add phone square icon"></i>  '~t._('mod_b24_i_AddNewRecord'), "class": "ui blue button", "id":"add-new-external-line-button") }}
        <div class="ui hidden divider"></div>
        <table id="external-line-table" class="ui compact table" data-page-length='17'>
            <thead>
            <tr>
                <th class="collapsing"></th>
                <th class="eight wide">{{ t._('mod_b24_i_ColumnName') }}</th>
                <th class="four wide">{{ t._('mod_b24_i_ColumnNumber') }}</th>
                <th class="four wide">{{ t._('mod_b24_i_ColumnDidAlias') }}</th>
                <th class="collapsing"></th>
            </tr>
            </thead>
            <tbody>
        </table>
    </div>

    <div class="ui bottom attached tab segment" data-tab="docs">
        <div class="ui bulleted link list">
            <a class="item" href="https://wiki.mikopbx.com/module-bitrix24-integration">{{ t._('mod_b24_i_WikiDocsRussian') }}</a>
            <a class="item" href="https://youtu.be/y9E1dPFQpHk">{{ t._('mod_b24_i_WebinarLinkRussian') }}</a>
            <a class="item" href="https://qa.askozia.ru/интеграции/интеграция-с-bitrix24" target="_blank">{{ t._('mod_b24_i_QaGroupRussian') }}</a>
        </div>
    </div>

    {{ partial("partials/submitbutton",['indexurl':'pbx-extension-modules/index/']) }}
</form>