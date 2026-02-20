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

## Logs

Логи воркеров: `ConnectorDb.log`, `HttpConnection.log`, `HttpConnection_SYNC.log`, `IntegrationAMI.log`.
Формат: `[ISO8601][level] message(pid): JSON`. Для анализа звонка — искать по `linkedid` во всех логах.
