// Билд сам добавляет префикс gtsbackup_ → gtsbackup_enabled и т.д.
// Сборка настройки только создаёт (config.update.settings = false): значения на сайте не перетираются.
export default {
    // Страница «Бекапы» в меню менеджера (Компоненты → Бекапы): токены и журнал.
    'admin': {
        'xtype': 'textarea',
        'value': '{"title":"Бекапы","PVTabs":{"tabs":{"gbToken":{"title":"Токены","table":"gbToken"},"gbLog":{"title":"Журнал","table":"gbLog"}}}}',
        'area': 'gtsbackup_main',
    },
    // Адрес, по которому клиент ходит на сайт снаружи (сайт за прокси, админка по внутреннему адресу):
    // https://backup.example.com или полный …/assets/components/gtsbackup/action.php.
    // Пусто — из адреса сайта. Его показывает окно «Создать токен» и ссылка на клиент.
    'public_url': {
        'xtype': 'textfield',
        'value': '',
        'area': 'gtsbackup_main',
    },
    // Выключатель эндпоинта. 0 — action.php отвечает 403 на всё.
    'enabled': {
        'xtype': 'combo-boolean',
        'value': 1,
        'area': 'gtsbackup_main',
    },
    // Пускать только по HTTPS. 0 — можно и по HTTP (токен идёт открытым текстом — только в своей сети).
    'require_https': {
        'xtype': 'combo-boolean',
        'value': 0,
        'area': 'gtsbackup_main',
    },
    // С каких IP можно ходить (через запятую). Пусто — с любых.
    'ip_whitelist': {
        'xtype': 'textfield',
        'value': '',
        'area': 'gtsbackup_main',
    },

    // ── Файлы ──
    // Корень бекапа. Пусто — корень сайта (MODX_BASE_PATH). core вне корня добавляется сам (в архиве — @core/).
    'root': {
        'xtype': 'textfield',
        'value': '',
        'area': 'gtsbackup_files',
    },
    // Что не брать, по строке или через запятую. Путь от корня с «/» внутри — шаблон пути (core/cache/),
    // без «/» внутри — имя на любой глубине (node_modules/, *.tmp). «/» в конце — только папка.
    'exclude': {
        'xtype': 'textarea',
        'value': "core/cache/\ncore/packages/_gtsbackup/\n.git/\nnode_modules/",
        'area': 'gtsbackup_files',
    },
    // Файлы больше этого размера (МБ) пропускаются. 0 — без ограничения.
    'max_file_mb': {
        'xtype': 'numberfield',
        'value': 0,
        'area': 'gtsbackup_files',
    },
    // Рабочая папка заданий (архивы до скачивания). Пусто — core/packages/_gtsbackup/.
    'tmp_path': {
        'xtype': 'textfield',
        'value': '',
        'area': 'gtsbackup_files',
    },
    // Сколько секунд архивировать за один запрос step (меньше таймаута nginx/php-fpm).
    'step_time': {
        'xtype': 'numberfield',
        'value': 20,
        'area': 'gtsbackup_files',
    },
    // Степень сжатия gzip 1–9.
    'compress_level': {
        'xtype': 'numberfield',
        'value': 6,
        'area': 'gtsbackup_files',
    },
    // Брошенные задания (клиент не забрал архив) удаляются через столько часов.
    'job_ttl_hours': {
        'xtype': 'numberfield',
        'value': 24,
        'area': 'gtsbackup_files',
    },

    // ── База ──
    // auto — mysqldump, если запускается, иначе выгрузка средствами PHP; mysqldump; php.
    'db_method': {
        'xtype': 'textfield',
        'value': 'auto',
        'area': 'gtsbackup_db',
    },
    // Путь к mysqldump. Пусто — mysqldump из PATH.
    'mysqldump_bin': {
        'xtype': 'textfield',
        'value': '',
        'area': 'gtsbackup_db',
    },
    // Таблицы (без префикса, через запятую), у которых берётся только структура.
    'db_nodata_tables': {
        'xtype': 'textfield',
        'value': 'session',
        'area': 'gtsbackup_db',
    },
    // Таблицы (без префикса, через запятую), которые не берутся совсем.
    'db_ignore_tables': {
        'xtype': 'textfield',
        'value': '',
        'area': 'gtsbackup_db',
    },
}
