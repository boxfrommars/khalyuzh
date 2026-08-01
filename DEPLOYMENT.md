# Deployment requirements: `Дневник Халюжа`

Этот документ задаёт декларативные требования приложения и проверяемый
результат размещения. Он не содержит конкретный сервер, абсолютные
production-пути, пользователей ОС, секреты, сертификаты или команды доступа.

## Приложение

| Параметр | Значение |
| --- | --- |
| Название | `Дневник Халюжа` |
| Репозиторий | `git@github.com:boxfrommars/khalyuzh.git` |
| Production-ветка | `main` |
| Канонический URL | `https://khalyuzh.dgroza.ru/` |
| Тип процесса | HTTP request |
| Runtime | PHP 8.3 |
| База данных | SQLite |

## Runtime-интерфейс

- Package manager: Composer 2.
- Runtime-зависимости зафиксированы в `composer.lock`.
- Установка production-зависимостей:
  `composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader`.
- Относительный публичный каталог: `public`.
- Единственный PHP entry point: `public/index.php`.
- Все HTML- и API-маршруты диспетчеризуются внутри приложения по исходному
  request path; произвольный файл из `public` не исполняется.
- Публичные assets:
  `public/assets/app.css`, `public/assets/common.js`,
  `public/assets/food.js`, `public/assets/weight.js`.
- Frontend-сборка: отсутствует.
- Требуемые возможности runtime:
  PHP 8.3 с `PDO` и `pdo_sqlite`; для backup также нужен `sqlite3` CLI.
- Twig, Symfony HttpFoundation и Symfony Clock устанавливаются Composer.
- PHPUnit и PHPStan являются dev-зависимостями и в production не
  устанавливаются.
- Административные маршруты защищаются HTTP Basic Authentication на уровне
  веб-сервера.
- Приложение не запускает long-running или singleton-процессы.
- `bin/router.php` отдаёт напрямую только разрешённые assets и передаёт
  остальные запросы в `public/index.php`; он используется только встроенным
  development-сервером и не используется PHP-FPM.

## Конфигурация и безопасность

Переменные окружения приложению не требуются.

`app/config.php` задаёт путь к SQLite, часовой пояс `Asia/Yerevan`, параметры
профиля, названия кормов, калорийность и диапазон нормы. Изменение профиля
влияет только на новые записи кормления.

Для административных маршрутов веб-сервер обязан передавать подтверждённое имя
пользователя в `REMOTE_USER` или `PHP_AUTH_USER`. Без этого front controller
возвращает `403`. Учётные данные HTTP Basic Authentication являются секретом
окружения и не хранятся в репозитории.

Document root обязан указывать только на `public`. `app`, `storage`, `bin`,
`tests`, `vendor`, Git-файлы и документация не публикуются.

## Состояние и запись

- Writable-путь runtime: `storage`.
- Постоянные данные: `storage/records.sqlite`.
- Таблица `records` хранит рацион и snapshot параметров профиля.
- Таблица `weight_records` хранит одно взвешивание на календарную дату.
- Таблица `schema_migrations` хранит применённые версии схемы.
- Вес и рацион являются независимыми наборами данных.
- Генерируемые backup:
  `storage/backups/records-*.sqlite`; сохраняются последние 30 файлов.
- Пользовательские загрузки отсутствуют.
- Согласованная копия создаётся только через SQLite `.backup` командой
  `bin/backup.sh`.
- Проверка целостности:
  `sqlite3 storage/records.sqlite 'PRAGMA integrity_check;'` возвращает `ok`.
- Восстановление проверяется на отдельной копии, не изменяющей production-БД.

Постоянная база и backup не удаляются и не заменяются вместе с checkout кода.

## Lifecycle hooks и миграции

После установки Composer-зависимостей и до активации новой версии обязательно:

1. Создать согласованный backup текущей базы через `bin/backup.sh`.
2. Убедиться, что backup создан и доступен для восстановления.
3. Выполнить `php bin/migrate.php`.
4. Активировать новую версию приложения.

Runner миграций создаёт `schema_migrations`, применяет отсутствующие версии по
порядку и выполняет каждую миграцию в отдельной транзакции:

- версия 1 — идемпотентно фиксирует существующую таблицу `records`;
- версия 2 — создаёт `weight_records`.

Повторный запуск безопасен и не изменяет уже применённые версии. Существующие
строки `records` не преобразуются. Изменение аддитивно и не требует плановой
недоступности; старая версия приложения игнорирует новые таблицы.

Если миграция завершается ошибкой, её транзакция откатывается, новая версия не
активируется, а текущая версия продолжает обслуживать рацион.

## Внешнее поведение

- Любой HTTP URL канонического домена возвращает `301` на тот же path и query
  string по HTTPS.
- Redirects:
  `/admin` → `/admin/`, `/weight` → `/weight/`,
  `/admin/weight` → `/admin/weight/`.
- Неизвестные URL, произвольные PHP-файлы и dotfiles возвращают `404`.
- Nginx передаёт все динамические запросы только в `public/index.php`; точный
  dispatcher приложения является allowlist публичных HTTP-маршрутов.
- Все JSON-ответы API используют `application/json; charset=utf-8` и запрещают
  кэширование директивой `no-store`.
- Публичные API разрешают только `GET`.

API рациона сохраняет существующий контракт:

- `GET /api.php` и аутентифицированный `GET /admin/api.php` возвращают
  `{"records":[]}`;
- `PUT /admin/api.php?date=YYYY-MM-DD` с конечными неотрицательными числовыми
  `dryAmount` и `wetAmount` возвращает `201` при создании или `200` при
  обновлении;
- `DELETE /admin/api.php?date=YYYY-MM-DD` удаляет рацион и возвращает `204`;
- дата должна существовать и соответствовать формату `YYYY-MM-DD`; будущие
  даты для рациона допустимы, а неверная дата или значения возвращают `400`.

API веса:

- `GET /weight/api.php` и аутентифицированный
  `GET /admin/weight/api.php` возвращают `{"records":[]}`;
- `PUT /admin/weight/api.php?date=YYYY-MM-DD` с числовым положительным
  `weightKg` возвращает `201` при создании или `200` при обновлении;
- `DELETE /admin/weight/api.php?date=YYYY-MM-DD` возвращает `204`;
- будущая дата, неверный формат или неположительный вес возвращают `400`.

## Read-only smoke-проверки

| Метод и путь | Ожидаемый результат |
| --- | --- |
| `GET /` | `200`, HTML с заголовком «Рацион Халюжа» и навигацией |
| `GET /api.php` | `200`, JSON и массив `records` |
| `GET /weight` | `301` на `/weight/` |
| `GET /weight/` | `200`, HTML с заголовком «Вес Халюжа» и навигацией |
| `GET /weight/api.php` | `200`, JSON и массив `records` |
| `GET /admin` | `301` на `/admin/` |
| `GET /admin/` без credentials | `401` от веб-сервера |
| `GET /admin/weight` | `301` на `/admin/weight/` |
| `GET /admin/weight/` без credentials | `401` от веб-сервера |
| `GET /admin/` с credentials | `200`, admin-страница рациона |
| `GET /admin/api.php` с credentials | `200`, JSON и массив `records` |
| `GET /admin/weight/` с credentials | `200`, admin-страница веса |
| `GET /admin/weight/api.php` с credentials | `200`, JSON и массив `records` |
| `POST /api.php` | `405`, `Allow: GET` |
| `POST /weight/api.php` | `405`, `Allow: GET` |
| `GET /несуществующий-путь` | `404` |
| `GET /.git/config` | `404` |
| `GET /arbitrary.php` | `404` |

Изменяющие `PUT` и `DELETE` не входят в production smoke-проверку.

## Проверка релиза

До размещения выполнить:

```shell
composer validate --strict
composer check
find app public bin tests -type f -name '*.php' -exec php -l {} \;
php -r 'exit(extension_loaded("PDO") && extension_loaded("pdo_sqlite") ? 0 : 1);'
sh -n bin/backup.sh
sh -n bin/start-dev.sh
```

PHPUnit использует только временную или in-memory SQLite. Проверки не должны
читать или изменять production-базу.

После размещения выполнить read-only smoke-сценарии, проверить HTTPS,
редиректы, Basic Auth, ожидаемые `404`, отсутствие PHP/JavaScript-ошибок и
desktop/mobile layout. Затем создать очередной штатный backup и проверить его
на отдельном экземпляре.

## Rollback

- Предыдущая версия кода совместима с мигрированной базой, поскольку новые
  таблицы аддитивны и старым кодом не используются.
- При обычном rollback возвращается предыдущая версия кода; таблицы
  `schema_migrations` и `weight_records` не удаляются, чтобы не потерять данные.
- Восстановление backup требуется только при повреждении данных или отдельно
  согласованном полном возврате состояния.
- После rollback проверить `/`, `/api.php`, Basic Auth `/admin/`, ожидаемые
  `404` и `PRAGMA integrity_check`.
- Production checkout, переключение версий и инфраструктурные команды
  описываются во внешнем deployment-проекте.

## Когда обновлять документ

`DEPLOYMENT.md` обновляется при изменении runtime, Composer-зависимостей,
конфигурации, публичных маршрутов/assets, writable-путей, постоянных данных,
миграций, lifecycle hooks, внешнего поведения или rollback.
