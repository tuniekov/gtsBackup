# gtsBackup

Бекап сайта MODX на локальный компьютер. Сайт по токену собирает полный или дифференциальный архив
файлов (tar.gz) и отдаёт дамп базы. Клиент забирает бекапы по HTTP/HTTPS (PHP curl), проверяет их,
хранит и сам удаляет старые (ротация).

## Где что лежит

| Путь в пакете | Что это |
|---|---|
| [`core/components/gtsbackup/client/`](core/components/gtsbackup/client/) | **клиент**: `gtsbackup.cmd` (Windows), `gtsbackup.php`, пример настроек `gtsbackup.example.json` и [`protocol.md`](core/components/gtsbackup/client/protocol.md) — **описание протокола** обмена с сайтом. Папку целиком копируют на компьютер для бекапов |
| [`core/components/gtsbackup/docs/readme.txt`](core/components/gtsbackup/docs/readme.txt) | краткая инструкция покупателю (показывается при установке) |
| `core/components/gtsbackup/model/` | сервис `gtsBackup` и схема таблиц `gtsbackup_tokens`, `gtsbackup_log` |
| `assets/components/gtsbackup/action.php` | эндпоинт, на который ходит клиент |
| `_build/configs/` | настройки, меню, таблицы gtsAPI, зависимость от gtsAPI |

Клиент вместе с протоколом лежит в `core`, поэтому попадает в transport-пакет и на сайте после
установки оказывается в `core/components/gtsbackup/client/`. Клиент через веб не выполняется: он работает только
из командной строки.

## Сборка

`npm install`, затем `npm run build` — пакет ставится на сайт из `.env` (`VITE_APP_HOST`,
`VITE_DEV_TOKEN`, можно положить в `../.env`). Токен сборки — `npm run get_token`.

## Сайт

1. «Компоненты → Бекапы → Токены → Создать токен», указать название (чей компьютер).
   Токен бессрочный, право у него одно — бекап. Он показывается один раз — скопировать.
   В том же окне — готовая команда `gtsbackup.cmd add …`.
2. Настройки — «Системные настройки», пространство `gtsbackup`:

| Настройка | По умолчанию | Что делает |
|---|---|---|
| `enabled` | 1 | выключатель эндпоинта |
| `require_https` | 0 | пускать только по HTTPS |
| `ip_whitelist` | — | с каких IP пускать (через запятую) |
| `root` | корень сайта | что бекапить; core вне корня добавляется сам (`@core/`) |
| `exclude` | `core/cache/`, `core/packages/_gtsbackup/`, `.git/`, `node_modules/` | исключения: путь с `/` внутри — от корня, без — имя на любой глубине, `/` в конце — только папка |
| `max_file_mb` | 0 | пропускать файлы больше N МБ |
| `step_time` | 20 | архив на сервере собирается порциями: один запрос `step` упаковывает файлы в общий tar.gz столько секунд (тысячи файлов), клиент повторяет, пока не готово, затем качает архив целиком одним файлом. Должно быть меньше таймаута веб-сервера |
| `compress_level` | 6 | gzip 1–9 |
| `tmp_path` | `core/packages/_gtsbackup/` | где собирается архив до скачивания (нужно место) |
| `job_ttl_hours` | 24 | когда удалять брошенные задания |
| `db_method` | auto | `mysqldump` / `php` / `auto` |
| `mysqldump_bin` | — | путь к mysqldump; пусто — из PATH |
| `db_nodata_tables` | session | только структура |
| `db_ignore_tables` | — | не брать совсем |

Журнал бекапов — вкладка «Журнал» там же.

Если сайт закрыт белым списком на веб-сервере, нужно открыть путь `/assets/components/gtsbackup/action.php`.

## Компьютер с бекапами

Нужен PHP 7.4+ с расширениями `curl` и `zlib`. В Windows `gtsbackup.cmd` ищет php.exe так:
переменная `GTSBACKUP_PHP`, потом `php\php.exe` рядом с клиентом, потом `php` из PATH.
В Linux/macOS — `php gtsbackup.php …`, расписание — cron.

```bat
rem скопировать core\components\gtsbackup\client\ на компьютер, например в C:\gtsBackup
gtsbackup.cmd add mysite https://example.com gbk_… --dest=D:\Backups
gtsbackup.cmd backup mysite            &rem auto: полный или дифференциальный
gtsbackup.cmd backup all full          &rem все сайты из конфига, принудительно полный
gtsbackup.cmd list mysite
gtsbackup.cmd verify mysite 20261112-184424-diff
gtsbackup.cmd restore mysite 20261112-184424-diff D:\restore\mysite
gtsbackup.cmd task all 03:30           &rem ежедневно в Планировщике Windows
```

Ротация и режимы — в `gtsbackup.json` (`defaults` — для всех сайтов, `sites.<имя>` — для одного):

- `keep_full: 4` — цепочек «полный + дифференциальные»;
- `max_diff: 6` — дифференциальных подряд;
- `full_every_days: 7`;
- `keep_db_only: 14`;
- `files` / `db` — что снимать;
- `insecure` — самоподписанный сертификат;
- `cacert` — путь к `cacert.pem`, если в php.ini не задан `curl.cainfo` (частая ошибка HTTPS на Windows).

`gtsbackup.json` содержит токены — в git его не класть (есть в `.gitignore`).

Код выхода: 0 — успех, 1 — ошибка. Лог: `<dest>\<сайт>\gtsbackup.log`.
