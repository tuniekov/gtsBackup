<?php
/**
 * gtsBackup — клиент на локальном компьютере. Забирает бекап с сайта по HTTP/HTTPS (curl),
 * хранит полные и дифференциальные бекапы, крутит ротацию, проверяет и восстанавливает.
 *
 * Запуск — через gtsbackup.cmd. Команды: php gtsbackup.php help
 * Обмен с сайтом — docs/protocol.md. PHP 7.4+, расширения curl и zlib.
 */

const GB_CLIENT_VERSION = '1.0.0';
const GB_PROTOCOL = 1;

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');
set_time_limit(0);

foreach (['curl', 'zlib', 'json'] as $ext) {
    if (!extension_loaded($ext)) { fwrite(STDERR, "Нет расширения PHP: {$ext}. Включите его в php.ini ({$ext}).\n"); exit(1); }
}

$CONFIG_FILE = getenv('GTSBACKUP_CONFIG') ?: __DIR__ . DIRECTORY_SEPARATOR . 'gtsbackup.json';

$DEFAULTS = [
    'files' => true,          // бекап файлов
    'db' => true,             // дамп базы
    'mode' => 'auto',         // auto | full | diff — режим по умолчанию для команды backup
    'keep_full' => 4,         // сколько цепочек (полный + его дифференциальные) хранить
    'max_diff' => 6,          // после стольких дифференциальных следующий — полный
    'full_every_days' => 7,   // полный не реже, чем раз в столько дней
    'keep_db_only' => 14,     // сколько бекапов «только база» хранить
    'verify' => true,         // после скачивания прочитать архив и дамп до конца
    'insecure' => false,      // не проверять сертификат HTTPS (самоподписанный)
    'cacert' => '',           // путь к cacert.pem, если в php.ini не задан curl.cainfo
    'timeout' => 900,         // таймаут одного запроса, сек (шаг, дамп — дольше обычного)
    'retries' => 5,           // повторы при обрыве
];

// ════════════════════════════════════════════════════════════════════════════
//  Точка входа
// ════════════════════════════════════════════════════════════════════════════

$argv0 = $argv;
array_shift($argv0);
$opts = [];
$args = [];
foreach ($argv0 as $a) {
    if (preg_match('/^--([a-z_-]+)(?:=(.*))?$/', $a, $m)) $opts[str_replace('-', '_', $m[1])] = isset($m[2]) ? $m[2] : true;
    else $args[] = $a;
}
$cmd = $args ? strtolower(array_shift($args)) : 'help';

try {
    switch ($cmd) {
        case 'add':     exit(cmd_add($args, $opts));
        case 'ping':    exit(cmd_ping($args));
        case 'backup':  exit(cmd_backup($args, $opts));
        case 'list':    exit(cmd_list($args));
        case 'rotate':  exit(cmd_rotate($args));
        case 'verify':  exit(cmd_verify($args));
        case 'restore': exit(cmd_restore($args));
        case 'task':    exit(cmd_task($args));
        case 'sites':   exit(cmd_sites());
        default:        exit(cmd_help());
    }
} catch (Throwable $e) {
    gb_log('ОШИБКА: ' . $e->getMessage());
    exit(1);
}

function cmd_help()
{
    echo <<<TXT
gtsBackup client {$GLOBALS['CONFIG_FILE']}

  gtsbackup add <имя> <адрес> <токен> [--dest=D:\\Backups]
        добавить сайт. Адрес — https://site.ru или полный .../assets/components/gtsbackup/action.php
  gtsbackup sites                         список сайтов из конфига
  gtsbackup ping <имя>                    проверить связь и токен
  gtsbackup backup <имя|all> [auto|full|diff] [--no-files] [--no-db]
        снять бекап. auto: полный, если его нет, он старше full_every_days
        или накопилось max_diff дифференциальных; иначе дифференциальный
  gtsbackup list <имя>                    бекапы сайта
  gtsbackup rotate <имя|all>              только ротация
  gtsbackup verify <имя> <бекап>          прочитать архив и дамп до конца
  gtsbackup restore <имя> <бекап> <папка> развернуть файлы (полный + дифференциальный) и положить дамп
  gtsbackup task <имя|all> <ЧЧ:ММ>        задача в Планировщике Windows: ежедневный backup auto

Настройки — gtsbackup.json рядом со скриптом (или переменная GTSBACKUP_CONFIG).
Код выхода: 0 — успех, 1 — ошибка.

TXT;
    return 0;
}

// ════════════════════════════════════════════════════════════════════════════
//  Конфиг
// ════════════════════════════════════════════════════════════════════════════

function cfg_load()
{
    $f = $GLOBALS['CONFIG_FILE'];
    if (!file_exists($f)) return ['dest' => __DIR__ . DIRECTORY_SEPARATOR . 'backups', 'defaults' => new stdClass(), 'sites' => new stdClass()];
    $c = json_decode(file_get_contents($f), true);
    if (!is_array($c)) gb_die("Не разобрать {$f}: " . json_last_error_msg());
    return $c;
}

function cfg_save($c)
{
    file_put_contents($GLOBALS['CONFIG_FILE'], json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** Настройки сайта = встроенные умолчания + defaults из конфига + настройки сайта. */
function site_cfg($name)
{
    $c = cfg_load();
    if (empty($c['sites'][$name])) gb_die("Сайт «{$name}» не найден в конфиге. Есть: " . implode(', ', array_keys((array)$c['sites'])));
    $s = array_merge($GLOBALS['DEFAULTS'], (array)(isset($c['defaults']) ? $c['defaults'] : []), $c['sites'][$name]);
    $s['name'] = $name;
    $dest = isset($s['dest']) ? $s['dest'] : (isset($c['dest']) ? $c['dest'] : __DIR__ . '/backups');
    $s['dir'] = rtrim(str_replace('\\', '/', $dest), '/') . '/' . $name . '/';
    if (!is_dir($s['dir']) && !@mkdir($s['dir'], 0750, true)) gb_die("Не создать папку {$s['dir']}");
    $GLOBALS['LOG_FILE'] = $s['dir'] . 'gtsbackup.log';
    return $s;
}

function site_names($arg)
{
    $c = cfg_load();
    if ($arg === 'all') return array_keys((array)$c['sites']);
    if (!$arg) gb_die('Укажите имя сайта (или all)');
    return [$arg];
}

function cmd_add($args, $opts)
{
    if (count($args) < 3) gb_die('gtsbackup add <имя> <адрес> <токен>');
    list($name, $url, $token) = $args;
    if (!preg_match('/^[a-z0-9_.-]+$/i', $name)) gb_die('Имя — латиница, цифры, точка, дефис, подчёркивание');
    if (!preg_match('~^https?://~i', $url)) $url = 'https://' . $url;
    if (!preg_match('~\.php$~i', $url)) $url = rtrim($url, '/') . '/assets/components/gtsbackup/action.php';
    $c = cfg_load();
    $c['sites'] = (array)$c['sites'];
    $site = isset($c['sites'][$name]) ? $c['sites'][$name] : [];
    $site['url'] = $url;
    $site['token'] = $token;
    if (!empty($opts['dest'])) $site['dest'] = $opts['dest'];
    $c['sites'][$name] = $site;
    if (empty($c['defaults'])) $c['defaults'] = new stdClass();
    cfg_save($c);
    echo "Сайт «{$name}» записан в {$GLOBALS['CONFIG_FILE']}\n";
    if (stripos($url, 'http://') === 0) echo "⚠ HTTP: токен и бекап идут открытым текстом. Используйте только в своей сети.\n";
    return cmd_ping([$name]);
}

function cmd_sites()
{
    $c = cfg_load();
    foreach ((array)$c['sites'] as $n => $s) echo str_pad($n, 24) . ' ' . $s['url'] . "\n";
    return 0;
}

function cmd_ping($args)
{
    $s = site_cfg(isset($args[0]) ? $args[0] : '');
    $r = api($s, 'ping');
    $d = $r['data'];
    echo "OK: {$d['site_name']} ({$d['http_host']}), gtsBackup {$d['version']}, PHP {$d['php']}, токен «{$d['token']}»\n"
        . "   база: {$d['db_method']}, шаг {$d['step_time']} с, источники: " . implode(', ', array_map(function ($x) { return $x === '' ? 'корень' : $x; }, $d['sources'])) . "\n";
    if ((int)$d['protocol'] !== GB_PROTOCOL) echo "⚠ Версия протокола сайта {$d['protocol']}, клиента " . GB_PROTOCOL . "\n";
    return 0;
}

// ════════════════════════════════════════════════════════════════════════════
//  Бекап
// ════════════════════════════════════════════════════════════════════════════

function cmd_backup($args, $opts)
{
    $names = site_names(isset($args[0]) ? $args[0] : '');
    $mode = isset($args[1]) ? strtolower($args[1]) : null;
    $fail = 0;
    foreach ($names as $name) {
        try {
            backup_site($name, $mode, $opts);
        } catch (Throwable $e) {
            gb_log("[{$name}] ОШИБКА: " . $e->getMessage());
            $fail++;
        }
    }
    return $fail ? 1 : 0;
}

function backup_site($name, $mode, $opts)
{
    $s = site_cfg($name);
    $withFiles = $s['files'] && empty($opts['no_files']);
    $withDb = $s['db'] && empty($opts['no_db']);
    if (!$withFiles && !$withDb) gb_die('Нечего бекапить: выключены и файлы, и база');
    if (!$mode) $mode = $s['mode'];
    if (!in_array($mode, ['auto', 'full', 'diff'], true)) gb_die('Режим: auto, full или diff');

    cleanup_parts($s);
    $t0 = microtime(true);
    $ping = api($s, 'ping');
    gb_log("[{$name}] сайт {$ping['data']['http_host']}, токен «{$ping['data']['token']}»");

    $type = 'db';
    $base = null;
    if ($withFiles) {
        $type = $mode === 'auto' ? choose_mode($s, $base) : $mode;
        if ($type === 'diff' && !$base) {
            $base = last_full($s);
            if (!$base) { gb_log("[{$name}] полного бекапа нет — делаю полный"); $type = 'full'; }
        }
    }

    $stamp = date('Ymd-His');
    $folder = $stamp . '-' . $type;
    $part = $s['dir'] . $folder . '.part/';
    mkdir($part, 0750, true);
    $info = [
        'type' => $type, 'created' => date('c'), 'site' => $ping['data']['http_host'],
        'client' => GB_CLIENT_VERSION, 'files' => null, 'db' => null,
    ];

    try {
        if ($withFiles) {
            gb_log("[{$name}] файлы: " . ($type === 'full' ? 'полный' : 'дифференциальный от ' . $base['folder']));
            $info['files'] = backup_files($s, $type, $base, $part);
            if ($type === 'diff') $info['base'] = $base['folder'];
        }
        if ($withDb) {
            gb_log("[{$name}] база: дамп…");
            $info['db'] = backup_db($s, $part);
        }
    } catch (Throwable $e) {
        file_put_contents($part . 'info.json', json_encode($info + ['error' => $e->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        throw $e;
    }

    $info['status'] = 'ok';
    $info['seconds'] = round(microtime(true) - $t0);
    file_put_contents($part . 'info.json', json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if (!rename(rtrim($part, '/'), $s['dir'] . $folder)) throw new RuntimeException("Не переименовать {$part}");
    gb_log("[{$name}] готово: {$folder}, " . human(dir_size($s['dir'] . $folder)) . ", {$info['seconds']} с");

    rotate($s);
}

/** auto: полный, если нет полного, он старше full_every_days или дифференциальных уже max_diff. */
function choose_mode($s, &$base)
{
    $base = last_full($s);
    if (!$base) return 'full';
    $age = (time() - strtotime($base['info']['created'])) / 86400;
    if ($s['full_every_days'] > 0 && $age >= $s['full_every_days']) return 'full';
    $diffs = 0;
    foreach (list_backups($s) as $b) if ($b['info']['type'] === 'diff' && isset($b['info']['base']) && $b['info']['base'] === $base['folder']) $diffs++;
    if ($s['max_diff'] >= 0 && $diffs >= $s['max_diff']) return 'full';
    return 'diff';
}

function backup_files($s, $type, $base, $part)
{
    $post = ['mode' => $type];
    $files = null;
    if ($type === 'diff') $post['base_job'] = $base['info']['files']['job'];
    $r = api($s, 'create', $post, null, true);
    if (!$r['success'] && $type === 'diff' && isset($r['data']['code']) && $r['data']['code'] === 'base_missing') {
        gb_log("[{$s['name']}] сервер не помнит базу — отправляю манифест полного бекапа");
        $r = api($s, 'create', $post, ['base' => $base['path'] . 'manifest.jsonl.gz'], true);
    }
    if (!$r['success']) throw new RuntimeException('create: ' . $r['message']);
    $d = $r['data'];
    $job = $d['job'];
    gb_log("[{$s['name']}] задание {$job}: к архивации {$d['files_total']} файлов, " . human($d['bytes_total'])
        . " (на сайте {$d['files_site']}" . ($type === 'diff' ? ", удалено {$d['deleted']}" : '') . ')');

    try {
        $last = 0;
        do {
            $r = api($s, 'step', ['job' => $job]);
            $p = $r['data'];
            if (time() - $last >= 10 || $p['done']) {
                $pct = $p['bytes_total'] > 0 ? round($p['bytes_done'] * 100 / $p['bytes_total']) : 100;
                gb_log("[{$s['name']}]   {$pct}% — {$p['files_done']}/{$p['files_total']} файлов, архив " . human($p['archive_size']));
                $last = time();
            }
            foreach ((array)(isset($p['step_warnings']) ? $p['step_warnings'] : []) as $w) gb_log("[{$s['name']}]   ⚠ {$w}");
        } while (!$p['done']);

        download($s, ['action' => 'download', 'job' => $job, 'what' => 'manifest'], $part . 'manifest.jsonl.gz', true);
        $size = download($s, ['action' => 'download', 'job' => $job, 'what' => 'files'], $part . 'files.tar.gz', true);
        gb_log("[{$s['name']}] архив скачан: " . human($size));

        $meta = manifest_meta($part . 'manifest.jsonl.gz');
        if (!$meta) throw new RuntimeException('Манифест без meta');
        if ($s['verify']) {
            $n = tar_walk($part . 'files.tar.gz', null);
            if ($n !== (int)$p['files_done']) throw new RuntimeException("Проверка архива: в архиве {$n} файлов, сервер записал {$p['files_done']}");
            gb_log("[{$s['name']}] архив проверен: {$n} файлов");
        }
        api($s, 'finish', ['job' => $job]);
    } catch (Throwable $e) {
        try { api($s, 'cancel', ['job' => $job], null, true); } catch (Throwable $e2) {}
        throw $e;
    }
    return [
        'job' => $job, 'base_job' => $type === 'diff' ? $base['info']['files']['job'] : '',
        'count' => (int)$p['files_done'], 'bytes' => (int)$p['bytes_done'], 'archive' => (int)$p['archive_size'],
        'files_site' => (int)$meta['files_total'], 'deleted' => count($meta['deleted']), 'warnings' => (int)$p['warnings'],
    ];
}

function backup_db($s, $part)
{
    $file = $part . 'db.sql.gz';
    $tries = 0;
    while (true) {
        $tries++;
        try {
            $hdr = [];
            $size = download($s, ['action' => 'db'], $file, false, $hdr);
            break;
        } catch (Throwable $e) {
            if ($tries >= min(3, $s['retries'])) throw $e;
            gb_log("[{$s['name']}]   обрыв дампа ({$e->getMessage()}), повтор {$tries}…");
            sleep(5 * $tries);
        }
    }
    $tail = gz_tail($file);
    if (strpos($tail, '-- gtsBackup-end: ok') === false) {
        preg_match('/-- gtsBackup-end: (.*)/', $tail, $m);
        throw new RuntimeException('Дамп базы неполный: ' . ($m ? $m[1] : 'нет строки окончания (обрыв?)'));
    }
    $method = isset($hdr['x-gtsbackup-db-method']) ? $hdr['x-gtsbackup-db-method'] : '';
    gb_log("[{$s['name']}] база: " . human($size) . " ({$method}), дамп проверен");
    return ['bytes' => $size, 'method' => $method];
}

/** Незаконченные папки .part от упавших запусков. */
function cleanup_parts($s)
{
    foreach ((array)glob($s['dir'] . '*.part', GLOB_ONLYDIR) as $d) {
        gb_log("[{$s['name']}] удаляю незаконченный " . basename($d));
        rrmdir($d);
    }
}

// ════════════════════════════════════════════════════════════════════════════
//  Список и ротация
// ════════════════════════════════════════════════════════════════════════════

/** Готовые бекапы сайта, старые первыми. */
function list_backups($s)
{
    $out = [];
    foreach ((array)glob($s['dir'] . '*', GLOB_ONLYDIR) as $d) {
        if (!preg_match('/^\d{8}-\d{6}-(full|diff|db)$/', basename($d))) continue;
        $info = json_decode((string)@file_get_contents($d . '/info.json'), true);
        if (!$info || (isset($info['status']) ? $info['status'] : '') !== 'ok') continue;
        $out[] = ['folder' => basename($d), 'path' => $d . '/', 'info' => $info];
    }
    usort($out, function ($a, $b) { return strcmp($a['folder'], $b['folder']); });
    return $out;
}

function last_full($s)
{
    $full = null;
    foreach (list_backups($s) as $b) if ($b['info']['type'] === 'full') $full = $b;
    return $full;
}

function cmd_list($args)
{
    $s = site_cfg(isset($args[0]) ? $args[0] : '');
    foreach (list_backups($s) as $b) {
        $i = $b['info'];
        $f = $i['files'] ? "файлов {$i['files']['count']}" . ($i['type'] === 'diff' ? " (удалено {$i['files']['deleted']}) от {$i['base']}" : '') : '';
        $db = $i['db'] ? 'база ' . human($i['db']['bytes']) : '';
        echo str_pad($b['folder'], 24) . str_pad(human(dir_size($b['path'])), 11) . trim($f . '; ' . $db, '; ') . "\n";
    }
    return 0;
}

function cmd_rotate($args)
{
    foreach (site_names(isset($args[0]) ? $args[0] : '') as $n) rotate(site_cfg($n));
    return 0;
}

/**
 * Ротация: держим keep_full последних цепочек (полный + его дифференциальные);
 * дифференциальные без своего полного удаляются; «только база» — keep_db_only последних.
 */
function rotate($s)
{
    $all = list_backups($s);
    $fulls = array_values(array_filter($all, function ($b) { return $b['info']['type'] === 'full'; }));
    $keepFull = max(1, (int)$s['keep_full']);
    $keepNames = array_map(function ($b) { return $b['folder']; }, array_slice($fulls, -$keepFull));
    $del = [];
    foreach ($fulls as $b) if (!in_array($b['folder'], $keepNames, true)) $del[] = $b;
    foreach ($all as $b) {
        if ($b['info']['type'] === 'diff' && !in_array(isset($b['info']['base']) ? $b['info']['base'] : '', $keepNames, true)) $del[] = $b;
    }
    $dbs = array_values(array_filter($all, function ($b) { return $b['info']['type'] === 'db'; }));
    $keepDb = max(0, (int)$s['keep_db_only']);
    foreach (array_slice($dbs, 0, max(0, count($dbs) - $keepDb)) as $b) $del[] = $b;
    foreach ($del as $b) {
        gb_log("[{$s['name']}] ротация: удаляю {$b['folder']}");
        rrmdir(rtrim($b['path'], '/'));
    }
}

// ════════════════════════════════════════════════════════════════════════════
//  Проверка и восстановление
// ════════════════════════════════════════════════════════════════════════════

function find_backup($s, $folder)
{
    foreach (list_backups($s) as $b) if ($b['folder'] === $folder) return $b;
    gb_die("Бекап {$folder} не найден (gtsbackup list {$s['name']})");
}

function cmd_verify($args)
{
    if (count($args) < 2) gb_die('gtsbackup verify <имя> <бекап>');
    $s = site_cfg($args[0]);
    $b = find_backup($s, $args[1]);
    $ok = true;
    if (file_exists($b['path'] . 'files.tar.gz')) {
        $n = tar_walk($b['path'] . 'files.tar.gz', null);
        $want = $b['info']['files']['count'];
        echo "файлы: {$n} из {$want}" . ($n === $want ? " — OK\n" : " — НЕ СХОДИТСЯ\n");
        $ok = $ok && $n === $want;
    }
    if (file_exists($b['path'] . 'db.sql.gz')) {
        $tail = gz_tail($b['path'] . 'db.sql.gz');
        $dbOk = strpos($tail, '-- gtsBackup-end: ok') !== false;
        echo 'база: ' . ($dbOk ? 'OK' : 'НЕПОЛНЫЙ ДАМП') . "\n";
        $ok = $ok && $dbOk;
    }
    return $ok ? 0 : 1;
}

/**
 * restore: в пустую папку разворачивается полный бекап, поверх — дифференциальный,
 * удалённые на сайте файлы убираются. Дамп базы кладётся рядом (_db.sql.gz) — заливать руками.
 * Файлы core вне корня сайта — в папке «@core».
 */
function cmd_restore($args)
{
    if (count($args) < 3) gb_die('gtsbackup restore <имя> <бекап> <папка>');
    $s = site_cfg($args[0]);
    $b = find_backup($s, $args[1]);
    $dir = rtrim(str_replace('\\', '/', $args[2]), '/') . '/';
    if (is_dir($dir) && count(scandir($dir)) > 2) gb_die("Папка {$dir} не пустая — разворачиваю только в пустую");
    @mkdir($dir, 0750, true);

    if ($b['info']['files']) {
        $chain = [$b];
        if ($b['info']['type'] === 'diff') array_unshift($chain, find_backup($s, $b['info']['base']));
        foreach ($chain as $c) {
            echo "разворачиваю {$c['folder']}…\n";
            $n = tar_walk($c['path'] . 'files.tar.gz', $dir);
            echo "  файлов: {$n}\n";
        }
        if ($b['info']['type'] === 'diff') {
            $meta = manifest_meta($b['path'] . 'manifest.jsonl.gz');
            foreach ($meta['deleted'] as $rel) {
                $p = safe_path($dir, rtrim($rel, '/'));
                if (!$p) continue;
                if (substr($rel, -1) === '/') @rmdir($p); // пустая папка — только если так и осталась пустой
                elseif (is_file($p)) @unlink($p);
            }
            echo '  удалено (как на сайте): ' . count($meta['deleted']) . "\n";
        }
    }
    if (file_exists($b['path'] . 'db.sql.gz')) {
        copy($b['path'] . 'db.sql.gz', $dir . '_db.sql.gz');
        echo "дамп базы: {$dir}_db.sql.gz\n";
    }
    echo "готово: {$dir}\n";
    return 0;
}

// ════════════════════════════════════════════════════════════════════════════
//  Планировщик Windows
// ════════════════════════════════════════════════════════════════════════════

function cmd_task($args)
{
    if (count($args) < 2 || !preg_match('/^\d{1,2}:\d{2}$/', $args[1])) gb_die('gtsbackup task <имя|all> <ЧЧ:ММ>');
    if (stripos(PHP_OS, 'WIN') !== 0) gb_die('Только для Windows. На Linux — cron: php gtsbackup.php backup all');
    $cmdFile = __DIR__ . '\\gtsbackup.cmd';
    $tn = 'gtsBackup ' . $args[0];
    $line = 'schtasks /Create /F /SC DAILY /ST ' . $args[1] . ' /TN "' . $tn . '" /TR "\"' . $cmdFile . '\" backup ' . $args[0] . ' auto"';
    echo $line . "\n";
    passthru($line, $code);
    return $code;
}

// ════════════════════════════════════════════════════════════════════════════
//  HTTP (curl)
// ════════════════════════════════════════════════════════════════════════════

function curl_base($s, $url)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['X-Gtsbackup-Token: ' . $s['token'], 'Expect:'],
        CURLOPT_USERAGENT => 'gtsBackup-client/' . GB_CLIENT_VERSION,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT => (int)$s['timeout'],
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => !$s['insecure'],
        CURLOPT_SSL_VERIFYHOST => $s['insecure'] ? 0 : 2,
    ]);
    if (!empty($s['cacert'])) curl_setopt($ch, CURLOPT_CAINFO, $s['cacert']);
    return $ch;
}

/**
 * JSON-запрос к эндпоинту. Повторы — на сетевых ошибках и 5xx.
 * $soft — вернуть ответ с success=0 вместо исключения (для разбора кода ошибки).
 */
function api($s, $action, $post = [], $files = null, $soft = false)
{
    $post['action'] = $action;
    if ($files) foreach ($files as $k => $f) $post[$k] = new CURLFile($f, 'application/gzip', basename($f));
    $tries = 0;
    while (true) {
        $tries++;
        $ch = curl_base($s, $s['url']);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post, CURLOPT_RETURNTRANSFER => true]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false || $code >= 500 || $code === 0) {
            $why = $body === false ? $err : "HTTP {$code}: " . substr(strip_tags((string)$body), 0, 300);
            // create не повторяем: на сервере могло остаться задание; повтор безопасен для ping/step/finish
            if ($tries >= $s['retries'] || $action === 'create') throw new RuntimeException("{$action}: {$why}");
            gb_log("[{$s['name']}]   {$action}: {$why} — повтор {$tries}");
            sleep(min(60, 5 * $tries));
            continue;
        }
        $r = json_decode($body, true);
        if (!is_array($r)) throw new RuntimeException("{$action}: не JSON (HTTP {$code}): " . substr(strip_tags($body), 0, 300));
        if (!$r['success'] && !$soft) throw new RuntimeException("{$action}: {$r['message']} (HTTP {$code})");
        return $r;
    }
}

/**
 * Скачать в файл. $resume — докачка по Range с места обрыва (архивы заданий);
 * без неё (дамп базы идёт потоком) каждая попытка — с нуля. Возвращает размер.
 */
function download($s, $params, $file, $resume, &$headers = [])
{
    $tries = 0;
    $expected = null;
    while (true) {
        $tries++;
        $have = ($resume && file_exists($file)) ? filesize($file) : 0;
        $fh = fopen($file, $have ? 'ab' : 'wb');
        $headers = [];
        $ch = curl_base($s, $s['url']);
        $hdrs = ['X-Gtsbackup-Token: ' . $s['token'], 'Expect:'];
        if ($have) $hdrs[] = 'Range: bytes=' . $have . '-';
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $params,
            CURLOPT_HTTPHEADER => $hdrs,
            CURLOPT_TIMEOUT => 0,               // большой файл — без общего таймаута…
            CURLOPT_LOW_SPEED_LIMIT => 1,       // …но обрыв, если 5 минут ни байта
            CURLOPT_LOW_SPEED_TIME => 300,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
                if (strpos($line, ':') !== false) {
                    list($k, $v) = explode(':', $line, 2);
                    $headers[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use ($fh) {
                return fwrite($fh, $data);
            },
        ]);
        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fh);
        clearstatcache(true, $file);

        if ($ok && ($code === 200 || $code === 206)) {
            if (isset($headers['x-gtsbackup-size'])) $expected = (int)$headers['x-gtsbackup-size'];
            $got = filesize($file);
            if ($expected === null || $got === $expected) return $got;
            $err = "получено {$got} из {$expected} байт";
        } elseif ($ok) {
            $body = $code >= 400 ? (string)@file_get_contents($file, false, null, 0, 2000) : '';
            @unlink($file);
            $j = json_decode($body, true);
            $msg = is_array($j) && isset($j['message']) ? $j['message'] : substr(strip_tags($body), 0, 300);
            if ($code === 416 && $resume) { $resume = false; continue; }
            if ($code < 500) throw new RuntimeException("{$params['action']}: HTTP {$code} {$msg}");
            $err = "HTTP {$code} {$msg}";
        }
        if (!$resume) @unlink($file);
        if ($tries >= $s['retries']) throw new RuntimeException("{$params['action']}: {$err}");
        gb_log("[{$s['name']}]   скачивание: {$err} — повтор {$tries}" . ($resume ? ' (докачка)' : ''));
        sleep(min(60, 5 * $tries));
    }
}

// ════════════════════════════════════════════════════════════════════════════
//  tar.gz, манифест
// ════════════════════════════════════════════════════════════════════════════

function gz_read_exact($h, $n)
{
    $buf = '';
    while (strlen($buf) < $n) {
        $chunk = gzread($h, (int)min(1048576, $n - strlen($buf)));
        if ($chunk === '' || $chunk === false) break;
        $buf .= $chunk;
    }
    return $buf;
}

/**
 * Пройти tar.gz (склейка членов gzip читается как один поток).
 * $dest = null — только счёт и проверка целостности; иначе распаковка в $dest.
 * Возвращает число файлов.
 */
function tar_walk($file, $dest)
{
    $h = gzopen($file, 'rb');
    if (!$h) throw new RuntimeException("Не открыть {$file}");
    $count = 0;
    $long = null;
    while (true) {
        $hdr = gz_read_exact($h, 512);
        if (strlen($hdr) < 512) {
            gzclose($h);
            throw new RuntimeException("{$file}: архив оборван (нет конца tar)");
        }
        if (trim($hdr, "\0") === '') break;
        $sum = 0;
        for ($i = 0; $i < 512; $i++) $sum += ($i >= 148 && $i < 156) ? 32 : ord($hdr[$i]);
        if ($sum !== octdec(trim(substr($hdr, 148, 8), " \0"))) {
            gzclose($h);
            throw new RuntimeException("{$file}: испорчен заголовок tar после {$count} файлов");
        }
        $name = rtrim(substr($hdr, 0, 100), "\0");
        $sf = substr($hdr, 124, 12);
        $size = (ord($sf[0]) & 0x80) ? unpack('J', substr($sf, 4, 8))[1] : octdec(trim($sf, " \0"));
        $mtime = octdec(trim(substr($hdr, 136, 12), " \0"));
        $type = $hdr[156];
        $pad = (512 - $size % 512) % 512;

        if ($type === 'L') {
            $long = rtrim(gz_read_exact($h, $size), "\0");
            gz_read_exact($h, $pad);
            continue;
        }
        if ($long !== null) { $name = $long; $long = null; }
        if ($type === '5') { // пустая папка
            $count++;
            if ($dest !== null && ($p = safe_path($dest, rtrim($name, '/'))) && !is_dir($p)) mkdir($p, 0755, true);
            gz_skip($h, $size + $pad);
            continue;
        }
        if ($type !== '0' && $type !== "\0") {
            gz_skip($h, $size + $pad);
            continue;
        }
        $count++;
        if ($dest === null) {
            gz_skip($h, $size + $pad);
            continue;
        }
        $p = safe_path($dest, $name);
        if (!$p) { gz_skip($h, $size + $pad); echo "  ⚠ пропущен небезопасный путь: {$name}\n"; continue; }
        if (!is_dir(dirname($p))) mkdir(dirname($p), 0755, true);
        $out = fopen($p, 'wb');
        $left = $size;
        while ($left > 0) {
            $chunk = gz_read_exact($h, (int)min(1048576, $left));
            if ($chunk === '') { fclose($out); gzclose($h); throw new RuntimeException("{$file}: оборван на {$name}"); }
            fwrite($out, $chunk);
            $left -= strlen($chunk);
        }
        fclose($out);
        @touch($p, $mtime);
        gz_read_exact($h, $pad);
    }
    gzclose($h);
    return $count;
}

function gz_skip($h, $n)
{
    while ($n > 0) {
        $chunk = gzread($h, (int)min(1048576, $n));
        if ($chunk === '' || $chunk === false) throw new RuntimeException('архив оборван');
        $n -= strlen($chunk);
    }
}

/** Путь внутри $dest без выхода наружу (.., абсолютные пути). */
function safe_path($dest, $rel)
{
    $rel = str_replace('\\', '/', $rel);
    if ($rel === '' || $rel[0] === '/' || preg_match('~^[a-z]:~i', $rel)) return null;
    foreach (explode('/', $rel) as $seg) if ($seg === '..') return null;
    return rtrim($dest, '/') . '/' . $rel;
}

function manifest_meta($file)
{
    $h = gzopen($file, 'rb');
    if (!$h) return null;
    $meta = null;
    while (($line = gzgets($h, 1 << 22)) !== false) {
        if ($line !== '' && $line[0] === '{') {
            $j = json_decode($line, true);
            if (isset($j['meta'])) $meta = $j['meta'];
        }
    }
    gzclose($h);
    return $meta;
}

/** Последние ~4 КБ распакованного gz (чтение до конца — заодно проверка целостности). */
function gz_tail($file)
{
    $h = gzopen($file, 'rb');
    if (!$h) return '';
    $tail = '';
    while (!gzeof($h)) {
        $chunk = gzread($h, 1048576);
        if ($chunk === '' || $chunk === false) break;
        $tail = substr($tail . $chunk, -4096);
    }
    gzclose($h);
    return $tail;
}

// ════════════════════════════════════════════════════════════════════════════
//  Мелочи
// ════════════════════════════════════════════════════════════════════════════

function gb_log($msg)
{
    $line = date('Y-m-d H:i:s') . ' ' . $msg;
    echo $line . "\n";
    if (!empty($GLOBALS['LOG_FILE'])) @file_put_contents($GLOBALS['LOG_FILE'], $line . "\n", FILE_APPEND);
}

function gb_die($msg)
{
    throw new RuntimeException($msg);
}

function human($b)
{
    $u = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
    $i = 0;
    while ($b >= 1024 && $i < 4) { $b /= 1024; $i++; }
    return round($b, $i ? 1 : 0) . ' ' . $u[$i];
}

function dir_size($d)
{
    $t = 0;
    foreach ((array)glob(rtrim($d, '/') . '/*') as $f) $t += is_file($f) ? filesize($f) : 0;
    return $t;
}

function rrmdir($dir)
{
    if (!is_dir($dir)) return;
    foreach (array_diff((array)scandir($dir), ['.', '..']) as $f) {
        $p = $dir . '/' . $f;
        is_dir($p) ? rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}
