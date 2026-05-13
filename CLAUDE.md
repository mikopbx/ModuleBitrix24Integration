# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Модуль интеграции Bitrix24 CRM с MikoPBX — телефонной системой на базе Asterisk. Обеспечивает управление звонками, синхронизацию контактов, создание лидов/сделок и загрузку записей разговоров в Bitrix24.

**Стек:** PHP 7.4+, Phalcon MVC framework, Redis (кеш), Beanstalk (очереди), Asterisk AMI/AGI.

## Architecture

### Worker-процессы (bin/)

Модуль работает через набор долгоживущих воркеров, взаимодействующих через Beanstalk-очереди:

- **WorkerBitrix24IntegrationHTTP** — основной демон: OAuth-авторизация, REST API Bitrix24, обработка событий звонков, синхронизация контактов
- **WorkerBitrix24IntegrationAMI** — слушает события Asterisk AMI (состояния каналов, звонки, очереди)
- **ConnectorDb** — все операции с БД: настройки, CDR, пользователи, контакты. Другие воркеры обращаются к нему через `ConnectorDb::invoke()`
- **UploaderB24** — асинхронная загрузка записей разговоров в Bitrix24
- **safe.php** — cron-скрипт (раз в минуту), следит за жизнью воркеров и перезапускает упавшие

### Ключевые библиотеки (Lib/)

- **Bitrix24Integration** — ядро интеграции: OAuth-токены, REST API, работа с CRM (лиды, контакты, компании, сделки), управление звонками
- **Bitrix24IntegrationConf** — конфигурация модуля в MikoPBX: регистрация REST-маршрутов, генерация диалплана Asterisk, cron-задачи, lifecycle-хуки модуля
- **CacheManager** — Redis-кеш с префиксом `b24_token_`
- **Logger** — логирование с ротацией (40MB, 9 бэкапов)
- **Bitrix24InvokeRest** — очередь вызовов REST API

### MVC-слой (App/)

Единственный контроллер `ModuleBitrix24IntegrationController` — admin-интерфейс настроек. Views используют Volt-шаблоны Phalcon.

### Модели (Models/)

Phalcon ORM модели. Основные таблицы:
- `ModuleBitrix24Integration` — главные настройки (OAuth-токены, регион, параметры интеграции)
- `ModuleBitrix24Users` — настройки per-user (режим открытия карточки)
- `ModuleBitrix24ExternalLines` — маппинг внешних линий
- `ModuleBitrix24CDR` — данные о звонках
- `B24PhoneBook` / `ContactLinks` — кеш контактов

### Inter-process Communication

Воркеры общаются через Beanstalk-каналы:
- `B24_INTEGRATION_CHANNEL` — основной канал интеграции
- `B24_SEARCH_CHANNEL` — поиск контактов
- `B24_INVOKE_REST_CHANNEL` — очередь REST-вызовов

`ConnectorDb::invoke()` — универсальный паттерн RPC-вызова к БД-воркеру из других процессов.

### Frontend (public/assets/js/)

Исходники в `src/`, собранные файлы и sourcemaps рядом. Два основных модуля:
- `module-bitrix24-integration-index.js` — основная форма настроек
- `module-bitrix24-integration-status-worker.js` — мониторинг состояния воркеров

### AGI-скрипты (agi-bin/)

`b24CheckResponsible.php` — определяет ответственного за входящий звонок и перехватывает вызов.

## Bitrix24 OAuth Regions

Модуль поддерживает несколько регионов с разными OAuth-эндпоинтами: Россия (`ru`), Беларусь (`by`), Казахстан (`kz`), Мир (`com`). Регион определяет URL для авторизации и REST API.

## Namespace Convention

```
Modules\ModuleBitrix24Integration\{App,Lib,Models,bin,Setup}
```

PSR-4 маппинг: корень проекта = `Modules\ModuleBitrix24Integration\`.

## Internationalization

Переводы в `Messages/*.php` (~30 языков). Ключи переводов: `mod_b24_i_*`, `ex_*`. Используется система переводов Phalcon.

## Module Lifecycle

`Setup/PbxExtensionSetup.php` — установка/удаление модуля (создание таблиц, миграция старых настроек). `Bitrix24IntegrationConf` наследует `ConfigClass` — стандартный lifecycle MikoPBX-модулей: `onAfterModuleEnable`, `onBeforeModuleDisable`, генерация dialplan-контекстов.

## REST API

Модуль регистрирует эндпоинт:
```
GET /pbxcore/api/bitrix-integration/workers/state (без авторизации)
```

## PHP Compatibility

Целевая версия PHP 7.4+, код должен работать и на PHP 8.x. Ключевые различия:
- `array_is_list()` — только PHP 8.1+, использовать `array_keys($a) !== range(0, count($a) - 1)`
- `findFirst()` в Phalcon 3/4 возвращает `false`, не `null` — проверять через `!$record`
- `array_search()` возвращает `false` → при использовании как индекс массива тихо приводится к `0` (PHP 7.4) или Deprecated (PHP 8.1). Всегда проверять `=== false`
- `count(null)` — E_WARNING на всех версиях, но работает (возвращает 0). Инициализировать переменные перед count()
- `tempnam()` может вернуть `false` — на PHP 8 `chown(false, ...)` даёт TypeError

## Call Processing Flow

Входящий звонок в очередь: AMI event → WorkerBitrix24IntegrationAMI → Beanstalk → WorkerBitrix24IntegrationHTTP → Bitrix24 REST API.
- `linkedid` — связывает все плечи одного звонка
- `UNIQUEID` — идентификатор отдельного плеча (leg)
- `telephony.externalcall.register` — отправляется один раз на звонок (первый участник), параметр `SHOW=1` открывает карточку
- `telephony.externalcall.show` — отдельный вызов для открытия карточки остальным участникам очереди
- `open_card_mode`: `DIRECTLY` (сразу), `ANSWERED` (при ответе), `NONE` (никогда) — настройка per-user в `ModuleBitrix24Users`

### Register policy: one register per call

`addDataToQueue` (HTTP-воркер): первый `telephonyExternalCallRegister` для `linkedid` → реальный `telephony.externalcall.register` в B24 (создаёт CALL_ID). Последующие события того же linkedid:
- очередь (нет маркера `b24-answered-<linkedid>`) → `telephony.externalcall.show` на оригинальном CALL_ID;
- перевод (маркер стоит) → новый register, кладётся в `ARGS_REGISTER_<UNIQUEID>` и уходит только при finish'е этого плеча (per-leg CALL_ID).

При отладке: «много register» в `IntegrationAMI.log` ≠ много REST-запросов в B24. Считать REST по REQUEST'ам в `HttpConnection.log`.

### Two sources of "internal number → user" mapping

- **`inner_numbers`** — карта B24 user.get (`UF_PHONE_INNER` → `{ID, NAME, EMAIL, ...}`). Источник USER_ID для register/finish/show/hide. Обновляется `b24GetPhones` раз в 5 мин на ping AMI. Защита от полного обнуления есть, от частичного пропуска сотрудника — нет (см. `inner_numbers_diff` в `Bitrix24Integration.log`).
- **`usersSettingsB24`** — таблица модуля (`ModuleBitrix24Users`): `inner_phone` → `{user_id: MikoPBX-id, open_card_mode}`. Источник режима открытия карточки.
- Расхождение возможно: модуль знает номер (есть в `usersSettingsB24`), а в B24 этому номеру не присвоен `UF_PHONE_INNER`/`PERSONAL_MOBILE`/`WORK_PHONE` → `inner_numbers[$num]` пуст → USER_ID="" в register. AMI-воркер `actionCreateCdr` фильтрует такие плечи: `Skip register` (`WorkerBitrix24IntegrationAMI.php:518`). В `addDataToQueue` есть зеркальная страховка: `show` не отправляется с пустым USER_ID.

### Orphan-leg фильтр в HTTP-воркере

Для входящих звонков AMI-воркер шлёт `telephonyExternalCallFinish` на каждое CDR-плечо. Плечи, не дошедшие до оператора (IVR/queue), помечаются `USER_ID=responsibleMissedCalls`, `GLOBAL_STATUS=NOANSWER`. Эти эвенты могут опередить ANSWERED-finish реального оператора и переписать статус звонка на «пропущен».

HTTP-воркер фильтрует их: при `action_dial_answer` ставит маркер `b24-answered-<linkedid>` в Redis (TTL 3 ч, через `CacheManager`/`b24->saveCache` — переживает рестарт воркера). При обработке `telephonyExternalCallFinish` с `GLOBAL_STATUS!=ANSWERED`, если маркер стоит — эвент пропускается. Полностью пропущенные звонки (никто не ответил) идут штатно — маркера нет.

## MTS Historical Import

Опциональный импорт CDR из соседнего модуля `ModuleMtsPbx` (таблица `mts_cdr`). Cron `bin/MtsImporter.php` каждые 5 минут читает `Modules\ModuleMtsPbx\Models\CallHistory` через ORM (без HTTP к соседу), шлёт пачки по 10 звонков через `Bitrix24InvokeRest::invoke('importHistoricalCalls', ...)`. HTTP-воркер кладёт `register+finish` в общую `q_req` — звонки уходят обычным batch'ем как AMI-эвенты.

Почему mts_cdr, а не общая `cdr_general` (cdr.db): cdr_general — большая Asterisk-таблица без индекса на `from_account`, на крупных инсталляциях первичный скан медленный. `mts_cdr` — узкая таблица соседнего модуля, заполняемая `ModuleMtsPbx/bin/synchCdr.php` через MTS API, содержит только fs-mts звонки и MTS-API метаданные (включая `mts_rec_status` для отслеживания скачивания MP3).

- Опция: `import_mts_calls` в `ModuleBitrix24Integration` (галка в админке, видна только при установленном ModuleMtsPbx).
- Курсор: `mts_import_last_id` (в той же таблице, добавлен в `$syncKeys` в `Bitrix24IntegrationConf::modelsEventChangeData` — иначе апдейты курсора триггерили бы `onAfterModuleEnable`).
- Pending-логика: `mts_rec_status='pending'` означает, что MP3 ещё скачивается ModuleMtsPbx (`downloadRecords.php`). Cron не двигает курсор через такую запись, ждёт следующего тика.
- ACK-протокол invoke: `IMPORT_ACK_OK` / `IMPORT_ACK_NOT_READY` (если карты сотрудников `b24->inner_numbers`/`mobile_numbers` пусты после рестарта). Cron при `not_ready` не двигает курсор.
- Защита от повторного запуска: `flock(LOCK_EX|LOCK_NB)` на `tmp/.../mts_importer.lock`.
- Dedup: `ConnectorDb::FUNC_GET_EXPORTED_CALL_ID` — строгий поиск по `linkedid` в `b24_cdr_data` с непустым `call_id` (без leg-семантики `getCdrDataByLinkedId`).

Модель `Modules\ModuleMtsPbx\Models\CallHistory` доступна через PSR-4 (соседний модуль регистрирует свой namespace). НО при отсутствии модуля любое `use` приведёт к fatal — используем только FQCN-строки + `class_exists($fqcn)` перед обращением.

## Worker initialization patterns

**AMI-воркер: listener регистрируется ДО тяжёлого init.** `WorkerSafeScriptsCore::checkWorkerAMI` (core MikoPBX) шлёт ping через AMI UserEvent. После `MAX_PING_FAILURES` промахов подряд — SIGUSR1 → воркер крашится → safe.php стартует заново. Окно типично 15 секунд. `new Bitrix24Integration('_ami')` делает синхронный REST к B24 и может занимать дольше, поэтому `setFilter()` и `addEventHandler('userevent', [$this, 'callback'])` нужно вызвать **сразу после `createAstManager()`**, до тяжёлого init. `replyOnPingRequest` (WorkerBase) зависит только от `$this->am`, поэтому отвечать на ping можно без `$b24`. `callback()` использует `$this->processState === 'init'` как маркер «не готов» — пока state='init', события (кроме pong) игнорируются.

## ConnectorDb::invoke contract

`ConnectorDb::invoke(FUNC_GET_GENERAL_SETTINGS)` возвращает гетерогенное значение:
- cache-hit → `stdClass` (через `(object)$cached`)
- cache-miss + RPC success → `stdClass`
- cache-miss + RPC fail/timeout → `[]` (пустой массив)
- DB-empty (модель отсутствует) → может быть `false` (Phalcon 3/4)

Проверять: `is_object($settings) && !empty($settings->portal)` — это и есть инвариант «настройки готовы». Обращаться к полям через `->`, **не** через `[...]`.

## Logs

Логи воркеров: `ConnectorDb.log`, `HttpConnection.log`, `HttpConnection_SYNC.log`, `IntegrationAMI.log`, `Bitrix24Integration.log` (сюда пишется `inner_numbers_diff` — added/removed между снимками b24GetPhones).
Формат: `[ISO8601][level] message(pid): JSON`. Для анализа звонка — искать по `linkedid` во всех логах.
