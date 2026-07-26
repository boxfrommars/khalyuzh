# Дневник Халюжа

Небольшой сайт для учёта рациона и веса Халюжа. Приложение работает на
PHP 8.3 и SQLite, использует Twig, Symfony HttpFoundation и Symfony Clock.
Frontend-сборки и полноценного PHP-фреймворка нет.

## Разделы

- `/` — публичный калькулятор рациона и история кормления;
- `/weight/` — публичное последнее взвешивание, среднее за 7 дней и история;
- `/admin/` — управление рационом;
- `/admin/weight/` — управление взвешиваниями.

Публичные страницы доступны без авторизации. Все административные страницы и
API в production защищаются HTTP Basic Authentication на уровне веб-сервера.

## Структура

- `public/index.php` — единственный HTTP front controller;
- `public/` — единственный публичный document root;
- `public/assets/` — CSS и ES-модули без frontend-сборки;
- `app/src/` — семь классов в namespace `Khalyuzh`: composition root,
  HTTP application, два controller, Database и два repository;
- `app/templates/` — Twig-layout и страницы;
- `app/config.php` — путь к БД, часовой пояс и параметры рациона;
- `storage/` — SQLite-база и локальные резервные копии;
- `bin/migrate.php` — версионированные миграции;
- `bin/backup.sh` — согласованные SQLite backup-копии;
- `tests/` — PHPUnit-тесты на in-memory SQLite.

Записи веса не меняют параметры рациона. Профиль из `app/config.php`
по-прежнему копируется в каждую новую запись кормления, поэтому история
сохраняет исходные параметры.

## Локальный запуск

Требуется запущенный Docker Desktop:

```sh
docker compose up --build
```

Сайт доступен по `http://127.0.0.1:8088/`, раздел веса — по `/weight/`,
административные страницы — по `/admin/` и `/admin/weight/`.

При старте development-контейнер применяет миграции к локальной базе и запускает
встроенный PHP-сервер через `bin/router.php`. Router отдаёт напрямую только
четыре разрешённых asset-файла, а все HTTP-маршруты передаёт единому front
controller. Admin разрешён без пароля только при `PHP_SAPI=cli-server`; это
исключение нельзя использовать в production.

## Проверки

```sh
docker compose run --rm app composer validate --strict
docker compose run --rm app composer check
docker compose run --rm app sh -c \
  "find app public bin tests -type f -name '*.php' -exec php -l {} \; && \
   php -r 'exit(extension_loaded(\"PDO\") && extension_loaded(\"pdo_sqlite\") ? 0 : 1);' && \
   sh -n bin/backup.sh && sh -n bin/start-dev.sh"
```

`composer check` запускает PHPUnit и PHPStan level 8. Тесты создают только
in-memory SQLite и не обращаются к `storage/records.sqlite`.

## API

Рацион:

- `GET /api.php` — публичная история;
- `GET /admin/api.php` — история для admin;
- `PUT /admin/api.php?date=YYYY-MM-DD` — создание или обновление;
- `DELETE /admin/api.php?date=YYYY-MM-DD` — удаление.

Тело `PUT`:

```json
{
  "dryAmount": 40,
  "wetAmount": 1
}
```

Вес:

- `GET /weight/api.php` — публичная история;
- `GET /admin/weight/api.php` — история для admin;
- `PUT /admin/weight/api.php?date=YYYY-MM-DD` — создание или обновление;
- `DELETE /admin/weight/api.php?date=YYYY-MM-DD` — удаление.

Тело `PUT`:

```json
{
  "weightKg": 5.48
}
```

Для каждой даты хранится не более одной записи веса. Будущие даты и
неположительные значения отклоняются.

## Миграции и backup

Новая или существующая база подготавливается командой:

```sh
php bin/migrate.php
```

Перед миграцией постоянной базы обязательна согласованная backup-копия:

```sh
./bin/backup.sh
```

Backup использует SQLite `.backup`, содержит обе предметные таблицы и хранит
последние 30 файлов. Обычное копирование открытой SQLite-базы не заменяет этот
механизм.

## Production

Production-зависимости устанавливаются воспроизводимо:

```sh
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
```

`public/` должен оставаться единственным document root. Nginx должен исполнять
только `public/index.php`, отдавать напрямую только известные assets и защищать
весь `/admin/` через HTTP Basic Authentication. Пример конфигурации находится
в `deploy/nginx.example.conf`. Полный
deployment-контракт, порядок миграции и rollback описаны в `DEPLOYMENT.md`;
фактические production-runbooks находятся в отдельном инфраструктурном проекте.
