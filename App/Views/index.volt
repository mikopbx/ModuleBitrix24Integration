
<form class="ui grey form" id="module-bitrix24-integration-form">
    <input type="hidden" name="dirrty" id="dirrty"/>
    <input type="hidden" name="modify" id="dirrty"/>
    <input type="hidden" name="isREST" id="isREST"/>
    <div class="ui grey top right attached label" id="status"><i class="spinner loading icon"></i>{{ t._("mod_b24_i_UpdateStatus") }}</div>
    <div class="ui top attached tabular menu" id="extensions-menu">
        <a class="item active" data-tab="general">{{ t._('mod_b24_i_GeneralSettings') }}</a>
        <a class="item" data-tab="users">{{ t._('mod_b24_i_UsersFilter') }}</a>
        <a class="item" data-tab="external_lines">{{ t._('mod_b24_i_ExternalLines') }}</a>
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
            <div class="ui toggle checkbox">
                {{ form.render('export_records') }}
                <label>{{ t._('mod_b24_i_ExportRecords') }}</label>
            </div>
        </div>
        <div class="field">
            <div class="ui toggle checkbox ">
                {{ form.render('backgroundUpload') }}
                <label>{{ t._('mod_b24_i_backgroundUpload') }}</label>
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
        <div id="b24-app-data">
            <div class="field">
                <label>{{ t._('mod_b24_i_client_id') }}</label>
                {{ form.render('client_id') }}
            </div>
            <div class="field">
                <label>{{ t._('mod_b24_i_client_secret') }}</label>
                {{ form.render('client_secret') }}
            </div>
            <div class="field">
                <label>{{ t._('mod_b24_i_RefreshToken') }}</label>
                {{ form.render('refresh_token') }}
            </div>
            <br>
        </div>

        <div class="field">
            <div class="ui toggle checkbox ">
                {{ form.render('export_cdr') }}
                <label>{{ t._('mod_b24_i_NotifyOnCall') }}</label>
            </div>
        </div>
        <div class="field">
            <div class="ui toggle checkbox" id='create-lead'>
                {{ form.render('crmCreateLead') }}
                <label>{{ t._('mod_b24_i_CrmCreate') }}</label>
            </div>
            <div class="field" id='lead-type'>
                <br>
                <label>{{ t._('mod_b24_i_leadType') }}</label>
                {{ form.render('leadType') }}
            </div>
        </div>
        <div class="field">
            <div class="ui toggle checkbox">
                {{ form.render('use_interception') }}
                <label>{{ t._('mod_b24_i_useInterception') }}</label>
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
                    <th class="center aligned">{{t._('mod_b24_i_OPEN_CARD')}}</th>
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
                <td class="center aligned">
                    <div class="ui dropdown select-group" data-value="{{ extension['open_card_mode'] }}">
                        <div class="text">{{ extension['open_card_mode'] }}</div>
                        <i class="dropdown icon"></i>
                    </div>
                </td>

                <td class="{{ extension['status'] }}"><img src="{{ extension['avatar'] }}" class="ui avatar image"
                                                                      data-value="{{ extension['userid'] }}"> {{ extension['username'] }}
                </td>
                {% if extension['b24Name'] === "" %}
                <td class="center aligned {{ extension['status'] }}">{{ extension['number'] }}</td>
                {% else %}
                <td class="center aligned {{ extension['status'] }}">
                    <div class="ui blue label">
                      <i class="user icon"></i>
                      {{ extension['number'] }}
                      <a class="detail">{{ extension['b24Name'] }}</a>
                    </div>
                </td>

                {% endif %}

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

    {{ partial("partials/submitbutton",['indexurl':'pbx-extension-modules/index/']) }}
</form>

<select id="open-cards-list" style="display: none;">
    {% for record in cardMods %}
        <option value="{{ record }}">{{ record }}</option>
    {% endfor %}
</select>