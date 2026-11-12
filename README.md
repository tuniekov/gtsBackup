# gtsBackup

Бекап сайта MODX на локальный компьютер. Сайт по токену собирает полный или дифференциальный архив
файлов (tar.gz) и отдаёт дамп базы. Клиент `gtsbackup.cmd` забирает бекапы по HTTP/HTTPS, хранит их
и сам удаляет старые (ротация).

- Сервер — пакет MODX (gtsAPI): `core/components/gtsbackup/`, эндпоинт `assets/components/gtsbackup/action.php`.
- Клиент — папка [`client/`](client/): `gtsbackup.cmd`, `gtsbackup.php`, пример конфига `gtsbackup.example.json`.
- Протокол обмена — [`docs/protocol.md`](docs/protocol.md).

## Сайт

1. Установить пакет: `npm run build` в этой папке (дев) или выложить transport-пакет.
2. «Компоненты → Бекапы → Токены → Создать токен», указать название (чей компьютер).
   Токен бессрочный, право у него одно — бекап. Он показывается один раз — скопировать.
   В том же окне — готовая команда `gtsbackup.cmd add …`.
3. Настройки — «Системные настройки», пространство `gtsbackup`:

| Настройка | По умолчанию | Что делает |
|---|---|---|
| `enabled` | 1 | выключатель эндпоинта |
| `require_https` | 0 | пускать только по HTTPS |
| `ip_whitelist` | — | с каких IP пускать (через запятую) |
| `root` | корень сайта | что бекапить; core вне корня добавляется сам (`@core/`) |
| `exclude` | `core/cache/`, рабочие папки бекапа и gtsDeploy, `.git/`, `node_modules/` | исключения: путь с `/` внутри — от корня, без — имя на любой глубине, `/` в конце — только папка |
| `max_file_mb` | 0 | пропускать файлы больше N МБ |
| `step_time` | 20 | архив на сервере собирается порциями: один запрос `step` упаковывает файлы в общий tar.gz столько секунд (тысячи файлов), клиент повторяет, пока не готово, затем качает архив целиком одним файлом. Должно быть меньше таймаута nginx/php-fpm |
| `compress_level` | 6 | gzip 1–9 |
| `tmp_path` | `core/packages/_gtsbackup/` | где собирается архив до скачивания (нужно место) |
| `job_ttl_hours` | 24 | когда удалять брошенные задания |
| `db_method` | auto | `mysqldump` / `php` / `auto` |
| `mysqldump_bin` | — | путь к mysqldump; пусто — как у gtsDeploy или из PATH |
| `db_nodata_tables` | session | только структура |
| `db_ignore_tables` | — | не брать совсем |

Журнал бекапов — вкладка «Журнал» там же.

Если сайт закрыт снаружи белым списком nginx, нужно открыть путь `/assets/components/gtsbackup/action.php`.

## Компьютер с бекапами (Windows)

Нужен PHP 7.4+ с расширениями `curl` и `zlib`. Где искать php.exe: переменная `GTSBACKUP_PHP`,
потом `client\php\php.exe`, потом `php` из PATH.

```bat
rem скопировать папку client\ куда угодно, например C:\gtsBackup
gtsbackup.cmd add office https://office.vk-24.ru gbk_… --dest=D:\Backups
gtsbackup.cmd backup office            &rem auto: полный или дифференциальный
gtsbackup.cmd backup all full          &rem все сайты из конфига, принудительно полный
gtsbackup.cmd list office
gtsbackup.cmd verify office 20261112-184424-diff
gtsbackup.cmd restore office 20261112-184424-diff D:\restore\office
gtsbackup.cmd task all 03:30           &rem ежедневно в Планировщике Windows
```

Ротация и режимы задаются в `gtsbackup.json` (`defaults` — для всех сайтов, в `sites.<имя>` — для одного):

- `keep_full: 4` — цепочек «полный + дифференциальные»;
- `max_diff: 6` — дифференциальных подряд;
- `full_every_days: 7`;
- `keep_db_only: 14`;
- `files` / `db` — что снимать;
- `insecure` — самоподписанный сертификат;
- `cacert` — путь к `cacert.pem`, если в php.ini не задан `curl.cainfo` (частая ошибка HTTPS на Windows).

Файл `gtsbackup.json` содержит токены — в git его не класть (есть в `.gitignore`).

Код выхода: 0 — успех, 1 — ошибка (для планировщика). Лог: `<dest>\<сайт>\gtsbackup.log`.
