gtsBackup — бекап сайта MODX на ваш компьютер

Сайт собирает архив файлов (полный или дифференциальный) и дамп базы и отдаёт их по токену.
Клиент на компьютере (Windows, Linux, macOS — нужен PHP 7.4+ с curl и zlib) забирает бекапы
по HTTP или HTTPS, проверяет их, хранит и сам удаляет старые.

Требования: MODX 2.x, PHP 7.4+, пакет gtsAPI (ставится автоматически как зависимость).

ГДЕ ЧТО ЛЕЖИТ ПОСЛЕ УСТАНОВКИ

  core/components/gtsbackup/client/        клиент — скопируйте эту папку на компьютер для бекапов
      gtsbackup.cmd                        запуск в Windows
      gtsbackup.php                        сам клиент (в Linux/macOS: php gtsbackup.php …)
      gtsbackup.example.json               пример настроек клиента
  core/components/gtsbackup/docs/protocol.md
                                           описание протокола обмена клиента с сайтом
                                           (для своего клиента или интеграции)
  assets/components/gtsbackup/action.php   адрес, на который ходит клиент

НАСТРОЙКА САЙТА

1. Менеджер → Компоненты → Бекапы → Токены → «Создать токен».
   Токен бессрочный, умеет только снимать бекап. Показывается один раз — скопируйте.
   В том же окне — готовая команда для клиента.
2. Системные настройки, пространство gtsbackup: что бекапить и что исключать, способ дампа базы
   (mysqldump или PHP), только HTTPS, белый список IP.
3. Журнал бекапов — вкладка «Журнал».

Если сайт закрыт белым списком на веб-сервере, откройте путь /assets/components/gtsbackup/action.php.

КЛИЕНТ (WINDOWS)

  gtsbackup.cmd add mysite https://example.com gbk_… --dest=D:\Backups
  gtsbackup.cmd backup mysite          авто: полный или дифференциальный
  gtsbackup.cmd backup all             все сайты из настроек
  gtsbackup.cmd list mysite
  gtsbackup.cmd verify mysite <бекап>
  gtsbackup.cmd restore mysite <бекап> D:\restore\mysite
  gtsbackup.cmd task all 03:30         ежедневно в Планировщике Windows
  gtsbackup.cmd help

Ротация (gtsbackup.json): keep_full — сколько цепочек «полный + дифференциальные» хранить,
max_diff — дифференциальных подряд, full_every_days — полный не реже, keep_db_only.

Linux / macOS: php gtsbackup.php backup all — в cron.
