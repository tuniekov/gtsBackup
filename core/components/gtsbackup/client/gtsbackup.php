<?php
/**
 * gtsBackup — клиент для Linux/macOS: забирает бекап с сайта по HTTP/HTTPS (curl), хранит полные
 * и дифференциальные бекапы, крутит ротацию, проверяет и восстанавливает.
 * В Windows ставить PHP не нужно — там клиент gtsbackup.ps1 (запуск gtsbackup.cmd) с теми же командами,
 * настройками (gtsbackup.json) и папками бекапов.
 *
 * Поставляется в пакете: core/components/gtsbackup/client/ — папку копируют на компьютер для бекапов.
 * Запуск — php gtsbackup.php <команда>, расписание — cron: php gtsbackup.php scheduled. Команды: help
 * Обмен с сайтом — core/components/gtsbackup/client/protocol.md. PHP 7.4+, расширения curl и zlib.
 */

// Только командная строка: на сайте файл лежит в core и через веб ничего делать не должен.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

const GB_CLIENT_VERSION = '1.1.4';
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
    'alert_days' => 2,        // нет удачного бекапа столько дней — окно-предупреждение (0 — не предупреждать)
    'min_interval_hours' => 20, // плановый запуск пропускает сайт, если удачный бекап свежее (запуск и по времени, и при входе)
];

const GB_TASK = 'gtsBackup';
const GB_TASK_TIME = '03:30';

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
        case 'scheduled': exit(cmd_scheduled());
        case 'check':   exit(cmd_check($opts));
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

  БЫСТРЫЙ СТАРТ (обычная консоль, в папке клиента):
  gtsbackup add <имя> <адрес> <токен> --dest=D:\\Backups [--time=03:30] [--now]
        подключить сайт, проверить связь и (если её ещё нет) поставить задачу «gtsBackup»
        в Планировщик от текущего пользователя: каждый день в --time (по умолчанию 03:30) и при входе
        в Windows, пропущенный запуск — при первой возможности. --now — сразу снять первый бекап.
        Любую настройку сайта можно задать тут же: --keep-full=8 --max-diff=6 --alert-days=3 …

  gtsbackup sites                         список сайтов из конфига
  gtsbackup ping <имя>                    проверить связь и токен, показать корень бекапа на сайте
  gtsbackup check [--popup]               у кого нет удачного бекапа alert_days дней (--popup — окном)
  gtsbackup scheduled                     то, что запускает задача: бекап всех сайтов, которым пора, и check
  gtsbackup backup <имя|all> [auto|full|diff] [--no-files] [--no-db]
        снять бекап. auto: полный, если его нет, он старше full_every_days
        или от него уже max_diff дифференциальных; иначе дифференциальный от последнего полного
  gtsbackup list [имя|all]                цепочки «полный → его дифференциальные», сколько хранится, что дальше
  gtsbackup rotate [имя|all]              только ротация
  gtsbackup verify <имя> <номер>          прочитать архив и дамп до конца
  gtsbackup restore <имя> <номер> <папка> развернуть файлы (для дифф. — свой полный + он) и положить дамп
  gtsbackup task [ЧЧ:ММ | remove]         задача Планировщика: показать, поставить/сменить время, удалить

Номер бекапа — время его снятия, например 20261113-033000 (см. list).
Папки: <dest>\\<сайт>\\<время полного>\\full и рядом diff-<время> — дифференциальные этого полного.

Настройки — gtsbackup.json рядом со скриптом (или переменная GTSBACKUP_CONFIG), у каждого сайта свои:
блок "sites": { "<имя>": { url, token, dest, keep_full, max_diff, … } }.
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
    $defaults = (array)(isset($c['defaults']) ? $c['defaults'] : []);
    $site = isset($c['sites'][$name]) ? $c['sites'][$name] : ['added' => date('c')];
    $site['url'] = $url;
    $site['token'] = $token;
    if (!empty($opts['dest']) && $opts['dest'] !== true) $site['dest'] = $opts['dest'];
    if (empty($site['dest'])) $site['dest'] = isset($c['dest']) ? $c['dest'] : __DIR__ . DIRECTORY_SEPARATOR . 'backups';
    // все настройки сайта — явно в его блоке: видно и правится по каждому сайту отдельно
    foreach ($GLOBALS['DEFAULTS'] as $k => $v) {
        if (isset($opts[$k]) && $opts[$k] !== true) $site[$k] = is_bool($v) ? in_array(strtolower($opts[$k]), ['1', 'true', 'yes'], true) : (is_int($v) ? (int)$opts[$k] : $opts[$k]);
        elseif (!array_key_exists($k, $site)) $site[$k] = array_key_exists($k, $defaults) ? $defaults[$k] : $v;
    }
    $c['sites'][$name] = $site;
    unset($c['defaults'], $c['dest']); // всё — в блоке сайта
    $time = (!empty($opts['time']) && $opts['time'] !== true) ? $opts['time'] : (isset($c['task_time']) ? $c['task_time'] : GB_TASK_TIME);
    $c['task_time'] = $time;
    cfg_save($c);
    save_php_path();
    echo "Сайт «{$name}» записан в {$GLOBALS['CONFIG_FILE']} (его настройки — в блоке sites.{$name})\n";
    if (stripos($url, 'http://') === 0) echo "⚠ HTTP: токен и бекап идут открытым текстом. Используйте только в своей сети.\n";
    cmd_ping([$name]);
    $s = site_cfg($name);
    echo "Бекапы сохраняются в: {$s['dir']}\n"
        . "Хранится: {$s['keep_full']} цепочки (полный + до {$s['max_diff']} дифф.), полный не реже раза в {$s['full_every_days']} дн.;"
        . " окно-предупреждение, если нет удачного бекапа {$s['alert_days']} дн.\n";
    if (empty($opts['no_task']) && (!empty($opts['time']) || !task_exists())) install_task($time);
    elseif (task_exists()) echo "Задача «" . GB_TASK . "» уже стоит — бекапит и этот сайт (gtsbackup task — подробности).\n";
    if (!empty($opts['now'])) {
        echo "\nПервый бекап…\n";
        backup_site($name, 'auto', $opts);
    } else {
        echo "\nСнять первый бекап сейчас: gtsbackup.cmd backup {$name}\n";
    }
    return 0;
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
        . "   с сайта бекапится: " . roots_text($d) . "\n"
        . "   база: {$d['db_method']}, шаг упаковки {$d['step_time']} с\n";
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

/**
 * Хранение (папка сайта <dest>/<сайт>/):
 *   20261112-033000/                 цепочка — имя = время полного бекапа
 *       full/                        полный: files.tar.gz, manifest.jsonl.gz, db.sql.gz, info.json
 *       diff-20261113-033000/        дифференциальный от этого полного
 *       diff-20261114-033000/
 *   db-only/20261115-120000/         бекапы «только база» (--no-files)
 *   .part-…/                         собирается сейчас (или упал — удалится при следующем запуске)
 * Какой дифференциальный от какого полного — видно по папке.
 */
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
    $chain = null;
    if ($withFiles) {
        if ($mode === 'auto') {
            list($type, $why) = choose_mode($s, $chain);
            gb_log("[{$name}] режим auto: {$why}");
        } else {
            $type = $mode;
            $chain = last_chain($s);
            if ($type === 'diff' && !$chain) { gb_log("[{$name}] полного бекапа нет — делаю полный"); $type = 'full'; }
        }
    }

    $stamp = date('Ymd-His');
    $part = $s['dir'] . '.part-' . $stamp . '/';
    mkdir($part, 0750, true);
    $info = [
        'type' => $type, 'id' => $stamp, 'created' => date('c'), 'site' => $ping['data']['http_host'],
        'roots' => isset($ping['data']['roots']) ? $ping['data']['roots'] : null,
        'client' => GB_CLIENT_VERSION, 'files' => null, 'db' => null,
    ];

    try {
        if ($withFiles) {
            gb_log("[{$name}] файлы: " . ($type === 'full' ? 'полный' : 'дифференциальный от полного ' . $chain['id']));
            $info['files'] = backup_files($s, $type, $type === 'diff' ? $chain['full'] : null, $part);
            if ($type === 'diff') $info['base'] = $chain['id'];
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

    if ($type === 'full') {
        mkdir($s['dir'] . $stamp, 0750, true);
        $dest = $s['dir'] . $stamp . '/full';
    } elseif ($type === 'diff') {
        $dest = $s['dir'] . $chain['id'] . '/diff-' . $stamp;
    } else {
        if (!is_dir($s['dir'] . 'db-only')) mkdir($s['dir'] . 'db-only', 0750, true);
        $dest = $s['dir'] . 'db-only/' . $stamp;
    }
    if (!rename(rtrim($part, '/'), $dest)) throw new RuntimeException("Не переименовать {$part} в {$dest}");
    $rel = substr($dest, strlen($s['dir']));
    gb_log("[{$name}] готово: {$rel}, " . human(dir_size($dest)) . ", {$info['seconds']} с");

    rotate($s);
}

/**
 * auto: полный — если полного нет, он старше full_every_days или от него уже max_diff дифференциальных.
 * Возвращает [тип, почему]; $chain — последняя цепочка.
 */
function choose_mode($s, &$chain)
{
    $chain = last_chain($s);
    if (!$chain) return ['full', 'полного бекапа ещё нет — полный'];
    $age = (time() - strtotime($chain['full']['info']['created'])) / 86400;
    if ($s['full_every_days'] > 0 && $age >= $s['full_every_days']) {
        return ['full', 'последнему полному ' . floor($age) . ' дн. (full_every_days = ' . $s['full_every_days'] . ') — полный'];
    }
    $n = count($chain['diffs']);
    if ($s['max_diff'] >= 0 && $n >= $s['max_diff']) {
        return ['full', "от полного {$chain['id']} уже {$n} дифф. (max_diff = {$s['max_diff']}) — полный"];
    }
    return ['diff', 'дифференциальный ' . ($n + 1) . " из {$s['max_diff']} от полного {$chain['id']}"];
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
                $pct = $p['done'] ? 100 : ($p['bytes_total'] > 0 ? min(99, floor($p['bytes_done'] * 100 / $p['bytes_total'])) : 0);
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

/** Незаконченные папки .part-… от упавших запусков. */
function cleanup_parts($s)
{
    foreach ((array)glob($s['dir'] . '.part-*', GLOB_ONLYDIR) as $d) {
        gb_log("[{$s['name']}] удаляю незаконченный " . basename($d));
        rrmdir($d);
    }
}

// ════════════════════════════════════════════════════════════════════════════
//  Цепочки, список и ротация
// ════════════════════════════════════════════════════════════════════════════

function read_info($dir)
{
    $info = json_decode((string)@file_get_contents($dir . '/info.json'), true);
    return ($info && (isset($info['status']) ? $info['status'] : '') === 'ok') ? $info : null;
}

/**
 * Цепочки сайта, старые первыми: [id, path, full => бекап|null, diffs => [бекап…]].
 * Бекап: [id, type, path, info].
 */
function chains($s)
{
    $out = [];
    foreach ((array)glob($s['dir'] . '*', GLOB_ONLYDIR) as $c) {
        $id = basename($c);
        if (!preg_match('/^\d{8}-\d{6}$/', $id)) continue;
        $full = read_info($c . '/full');
        $diffs = [];
        foreach ((array)glob($c . '/diff-*', GLOB_ONLYDIR) as $d) {
            $did = substr(basename($d), 5);
            if (!preg_match('/^\d{8}-\d{6}$/', $did) || !($di = read_info($d))) continue;
            $diffs[] = ['id' => $did, 'type' => 'diff', 'path' => $d . '/', 'info' => $di, 'chain' => $id];
        }
        usort($diffs, function ($a, $b) { return strcmp($a['id'], $b['id']); });
        $out[] = [
            'id' => $id, 'path' => $c . '/',
            'full' => $full ? ['id' => $id, 'type' => 'full', 'path' => $c . '/full/', 'info' => $full, 'chain' => $id] : null,
            'diffs' => $diffs,
        ];
    }
    usort($out, function ($a, $b) { return strcmp($a['id'], $b['id']); });
    return $out;
}

function db_only($s)
{
    $out = [];
    foreach ((array)glob($s['dir'] . 'db-only/*', GLOB_ONLYDIR) as $d) {
        $id = basename($d);
        if (preg_match('/^\d{8}-\d{6}$/', $id) && ($i = read_info($d))) $out[] = ['id' => $id, 'type' => 'db', 'path' => $d . '/', 'info' => $i];
    }
    usort($out, function ($a, $b) { return strcmp($a['id'], $b['id']); });
    return $out;
}

/** Последняя цепочка с целым полным бекапом. */
function last_chain($s)
{
    $last = null;
    foreach (chains($s) as $c) if ($c['full']) $last = $c;
    return $last;
}

function fmt_id($id)
{
    return preg_replace('/^(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})\d{2}$/', '$3.$2.$1 $4:$5', $id);
}

function cmd_list($args)
{
    $names = site_names(isset($args[0]) ? $args[0] : 'all');
    foreach ($names as $name) {
        $s = site_cfg($name);
        $chains = array_values(array_filter(chains($s), function ($c) { return $c['full']; }));
        $dbs = db_only($s);
        $total = dir_size_rec($s['dir']);
        echo "\n{$name} — {$s['dir']}  (всего " . human($total) . ")\n";
        $lastB = null;
        foreach ($chains as $c) foreach (array_merge([$c['full']], $c['diffs']) as $b) if (!empty($b['info']['roots'])) $lastB = $b;
        if ($lastB) echo '  с сайта бекапится: ' . roots_text($lastB['info']) . "\n";
        $ls = last_success($s);
        $ld = $ls ? floor((time() - $ls) / 86400) : null;
        echo '  последний удачный бекап: ' . ($ls ? date('d.m.Y H:i', $ls) . " ({$ld} дн. назад)" : 'нет')
            . (($s['alert_days'] > 0 && (!$ls || $ld >= $s['alert_days'])) ? "  ⚠ ПРОПУСК — больше alert_days = {$s['alert_days']}" : '') . "\n";
        echo '  хранится цепочек: ' . count($chains) . " из {$s['keep_full']} (keep_full), "
            . "в цепочке до {$s['max_diff']} дифф. (max_diff), полный не реже раза в {$s['full_every_days']} дн.\n";
        $tmp = null;
        list(, $why) = choose_mode($s, $tmp);
        echo "  следующий запуск auto: {$why}\n\n";
        foreach ($chains as $c) {
            $f = $c['full'];
            echo '  ' . $c['id'] . '  ПОЛНЫЙ   ' . fmt_id($c['id']) . '  ' . str_pad(human(dir_size($f['path'])), 10) . describe($f['info']) . "\n";
            foreach ($c['diffs'] as $i => $d) {
                $last = $i === count($c['diffs']) - 1;
                echo '    ' . ($last ? '└' : '├') . ' ' . $d['id'] . '  дифф   ' . fmt_id($d['id']) . '  ' . str_pad(human(dir_size($d['path'])), 10) . describe($d['info']) . "\n";
            }
        }
        if (!$chains) echo "  бекапов файлов нет\n";
        if ($dbs) {
            echo "\n  только база (db-only), хранится " . count($dbs) . " из {$s['keep_db_only']}:\n";
            foreach ($dbs as $d) echo '    ' . $d['id'] . '  ' . fmt_id($d['id']) . '  ' . human(dir_size($d['path'])) . "\n";
        }
    }
    echo "\nВосстановить: gtsbackup restore <сайт> <номер> <пустая папка> — для дифф. сам возьмёт его полный.\n";
    return 0;
}

function describe($i)
{
    $p = [];
    if (!empty($i['files'])) {
        $p[] = $i['type'] === 'diff'
            ? "изменено {$i['files']['count']}, удалено {$i['files']['deleted']}"
            : "файлов {$i['files']['count']}";
    }
    if (!empty($i['db'])) $p[] = 'база ' . human($i['db']['bytes']);
    return implode(', ', $p);
}

function cmd_rotate($args)
{
    foreach (site_names(isset($args[0]) ? $args[0] : 'all') as $n) rotate(site_cfg($n));
    return 0;
}

/**
 * Ротация: keep_full последних цепочек (полный + его дифференциальные) — остальные удаляются целиком,
 * папки цепочек без целого полного — тоже; «только база» — keep_db_only последних.
 */
function rotate($s)
{
    $chains = chains($s);
    $good = array_values(array_filter($chains, function ($c) { return $c['full']; }));
    $keep = array_map(function ($c) { return $c['id']; }, array_slice($good, -max(1, (int)$s['keep_full'])));
    foreach ($chains as $c) {
        if (in_array($c['id'], $keep, true)) continue;
        gb_log("[{$s['name']}] ротация: удаляю цепочку {$c['id']} (полный + " . count($c['diffs']) . ' дифф.)');
        rrmdir(rtrim($c['path'], '/'));
    }
    $dbs = db_only($s);
    foreach (array_slice($dbs, 0, max(0, count($dbs) - max(0, (int)$s['keep_db_only']))) as $b) {
        gb_log("[{$s['name']}] ротация: удаляю db-only/{$b['id']}");
        rrmdir(rtrim($b['path'], '/'));
    }
}

/** Бекап по номеру (время): полный, дифференциальный или db-only. */
function find_backup($s, $id)
{
    foreach (chains($s) as $c) {
        if ($c['full'] && $c['id'] === $id) return $c['full'];
        foreach ($c['diffs'] as $d) if ($d['id'] === $id) return $d + ['full' => $c['full']];
    }
    foreach (db_only($s) as $d) if ($d['id'] === $id) return $d;
    gb_die("Бекап {$id} не найден (gtsbackup list {$s['name']})");
}

// ════════════════════════════════════════════════════════════════════════════
//  Проверка и восстановление
// ════════════════════════════════════════════════════════════════════════════

function cmd_verify($args)
{
    if (count($args) < 2) gb_die('gtsbackup verify <имя> <номер>');
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
    if (count($args) < 3) gb_die('gtsbackup restore <имя> <номер> <папка>');
    $s = site_cfg($args[0]);
    $b = find_backup($s, $args[1]);
    $dir = rtrim(str_replace('\\', '/', $args[2]), '/') . '/';
    if (is_dir($dir) && count(scandir($dir)) > 2) gb_die("Папка {$dir} не пустая — разворачиваю только в пустую");
    @mkdir($dir, 0750, true);

    if (!empty($b['info']['files'])) {
        $chain = $b['type'] === 'diff' ? [$b['full'], $b] : [$b];
        foreach ($chain as $c) {
            echo 'разворачиваю ' . ($c['type'] === 'full' ? 'полный ' : 'дифференциальный ') . "{$c['id']}…\n";
            $n = tar_walk($c['path'] . 'files.tar.gz', $dir);
            echo "  записей: {$n}\n";
        }
        if ($b['type'] === 'diff') {
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

/**
 * Задача «gtsBackup» — всегда от текущего пользователя (прав администратора не нужно):
 *  - каждый день в task_time и при входе в Windows (через 5 минут);
 *  - пропущенный запуск (компьютер был выключен) — при первой возможности;
 *  - окно консоли скрыто (запуск через gtsbackup-task.vbs);
 *  - действие — «scheduled»: бекап сайтов, которым пора, и проверка пропусков с окном-предупреждением.
 */
function cmd_task($args)
{
    $a = isset($args[0]) ? strtolower($args[0]) : '';
    if ($a === 'remove' || $a === 'delete') {
        if (!is_windows()) { echo "Удалите строку gtsbackup из crontab -e\n"; return 0; }
        exec('schtasks /Delete /TN "' . GB_TASK . '" /F 2>&1', $o, $code);
        echo $code === 0 ? "Задача «" . GB_TASK . "» удалена\n" : trim(implode(' ', $o)) . "\n";
        return $code;
    }
    if ($a !== '') {
        if (!preg_match('/^\d{1,2}:\d{2}$/', $a)) gb_die('gtsbackup task [ЧЧ:ММ | remove]');
        $c = cfg_load();
        $c['task_time'] = $a;
        cfg_save($c);
        return install_task($a);
    }
    if (!is_windows()) { echo "Расписание в Linux/macOS — cron, см. help\n"; return 0; }
    exec('schtasks /Query /TN "' . GB_TASK . '" /V /FO LIST 2>&1', $o, $code);
    if ($code !== 0) { echo "Задачи «" . GB_TASK . "» нет. Поставить: gtsbackup.cmd task 03:30\n"; return 1; }
    echo implode("\n", array_map(function ($l) { return to_utf8($l); }, $o)) . "\n";
    return 0;
}

function is_windows()
{
    return stripos(PHP_OS, 'WIN') === 0;
}

/** Вывод консольных программ Windows — в OEM-кодировке (cp866). */
function to_utf8($s)
{
    if (preg_match('//u', $s)) return $s;
    if (function_exists('mb_convert_encoding')) return mb_convert_encoding($s, 'UTF-8', 'CP866');
    if (function_exists('iconv')) return (string)@iconv('CP866', 'UTF-8//IGNORE', $s);
    return $s;
}

/** Запомнить php.exe, которым запущен клиент: задача Планировщика не видит переменные консоли. */
function save_php_path()
{
    if (PHP_BINARY) @file_put_contents(__DIR__ . DIRECTORY_SEPARATOR . 'php.path', PHP_BINARY);
}

function task_exists()
{
    if (!is_windows()) return true; // в Linux расписание ставит человек (cron)
    exec('schtasks /Query /TN "' . GB_TASK . '" >nul 2>&1', $o, $code);
    return $code === 0;
}

function install_task($time)
{
    if (!is_windows()) {
        echo "Планировщик Windows недоступен. Добавьте в crontab -e:\n"
            . '  30 3 * * * ' . PHP_BINARY . ' ' . __FILE__ . " scheduled\n";
        return 0;
    }
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m) || $m[1] > 23 || $m[2] > 59) gb_die("Время — ЧЧ:ММ, а не «{$time}»");
    $time = sprintf('%02d:%02d', $m[1], $m[2]);
    save_php_path();

    $dir = __DIR__;
    $user = getenv('USERDOMAIN') ? getenv('USERDOMAIN') . '\\' . getenv('USERNAME') : getenv('USERNAME');
    // Запуск без окна консоли: wscript запускает cmd скрыто и ждёт его. Предупреждение показывает
    // сам wscript (его Планировщик запускает видимым): окна процессов из скрытой цепочки Windows не показывает.
    $vbs = $dir . '\\gtsbackup-task.vbs';
    $vbsText = "' gtsBackup: запуск задачи Планировщика без окна консоли (создан командой task)\r\n"
        . "Set sh = CreateObject(\"WScript.Shell\")\r\n"
        . "sh.Environment(\"PROCESS\")(\"GTSBACKUP_TASK\") = \"1\"\r\n"
        . "rc = sh.Run(\"\"\"" . str_replace('"', '""', $dir . '\\gtsbackup.cmd') . "\"\" scheduled\", 0, True)\r\n"
        . alert_vbs_code()
        . "WScript.Quit rc\r\n";
    file_put_contents($vbs, "\xFF\xFE" . utf16le($vbsText));

    $x = function ($s) { return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); };
    $xml = '<?xml version="1.0" encoding="UTF-16"?>
<Task version="1.2" xmlns="http://schemas.microsoft.com/windows/2004/02/mit/task">
  <RegistrationInfo><Description>' . $x('gtsBackup: бекап сайтов (' . $dir . ')') . '</Description></RegistrationInfo>
  <Triggers>
    <CalendarTrigger>
      <StartBoundary>' . date('Y-m-d') . 'T' . $time . ':00</StartBoundary>
      <Enabled>true</Enabled>
      <ScheduleByDay><DaysInterval>1</DaysInterval></ScheduleByDay>
    </CalendarTrigger>
    <LogonTrigger>
      <Enabled>true</Enabled>
      <UserId>' . $x($user) . '</UserId>
      <Delay>PT5M</Delay>
    </LogonTrigger>
  </Triggers>
  <Principals>
    <Principal id="Author">
      <UserId>' . $x($user) . '</UserId>
      <LogonType>InteractiveToken</LogonType>
      <RunLevel>LeastPrivilege</RunLevel>
    </Principal>
  </Principals>
  <Settings>
    <MultipleInstancesPolicy>IgnoreNew</MultipleInstancesPolicy>
    <DisallowStartIfOnBatteries>false</DisallowStartIfOnBatteries>
    <StopIfGoingOnBatteries>false</StopIfGoingOnBatteries>
    <StartWhenAvailable>true</StartWhenAvailable>
    <RunOnlyIfNetworkAvailable>false</RunOnlyIfNetworkAvailable>
    <IdleSettings><StopOnIdleEnd>false</StopOnIdleEnd><RestartOnIdle>false</RestartOnIdle></IdleSettings>
    <AllowStartOnDemand>true</AllowStartOnDemand>
    <Enabled>true</Enabled>
    <Hidden>false</Hidden>
    <ExecutionTimeLimit>PT12H</ExecutionTimeLimit>
    <Priority>7</Priority>
  </Settings>
  <Actions Context="Author">
    <Exec>
      <Command>wscript.exe</Command>
      <Arguments>' . $x('"' . $vbs . '"') . '</Arguments>
      <WorkingDirectory>' . $x($dir) . '</WorkingDirectory>
    </Exec>
  </Actions>
</Task>
';
    $xmlFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gtsbackup-task.xml';
    file_put_contents($xmlFile, "\xFF\xFE" . utf16le($xml));
    exec('schtasks /Create /F /TN "' . GB_TASK . '" /XML "' . $xmlFile . '" 2>&1', $out, $code);
    @unlink($xmlFile);
    if ($code !== 0) gb_die('schtasks: ' . to_utf8(trim(implode(' ', $out))));
    echo "\nЗадача «" . GB_TASK . "» в Планировщике (от пользователя {$user}):\n"
        . "  каждый день в {$time} и при входе в Windows; если компьютер был выключен — при первой возможности.\n"
        . "  Бекапит все сайты из настроек, которым пора (удачный бекап старше min_interval_hours),\n"
        . "  и показывает окно, если у сайта нет удачного бекапа alert_days дней.\n"
        . "  Запустить сейчас: schtasks /Run /TN " . GB_TASK . "    Убрать: gtsbackup.cmd task remove\n";
    return 0;
}

/** UTF-8 → UTF-16LE без зависимости от mbstring/iconv. */
function utf16le($s)
{
    if (function_exists('mb_convert_encoding')) return mb_convert_encoding($s, 'UTF-16LE', 'UTF-8');
    if (function_exists('iconv')) return iconv('UTF-8', 'UTF-16LE', $s);
    $out = '';
    foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        $b = array_values(unpack('C*', $ch));
        switch (count($b)) {
            case 1: $cp = $b[0]; break;
            case 2: $cp = (($b[0] & 0x1F) << 6) | ($b[1] & 0x3F); break;
            case 3: $cp = (($b[0] & 0x0F) << 12) | (($b[1] & 0x3F) << 6) | ($b[2] & 0x3F); break;
            default: $cp = (($b[0] & 0x07) << 18) | (($b[1] & 0x3F) << 12) | (($b[2] & 0x3F) << 6) | ($b[3] & 0x3F);
        }
        if ($cp > 0xFFFF) { $cp -= 0x10000; $out .= pack('v', 0xD800 | ($cp >> 10)) . pack('v', 0xDC00 | ($cp & 0x3FF)); }
        else $out .= pack('v', $cp);
    }
    return $out;
}

// ════════════════════════════════════════════════════════════════════════════
//  Плановый запуск и проверка пропусков
// ════════════════════════════════════════════════════════════════════════════

/** Время последнего удачного бекапа сайта (любого вида) или null. */
function last_success($s)
{
    $t = null;
    foreach (chains($s) as $c) {
        foreach (array_merge($c['full'] ? [$c['full']] : [], $c['diffs']) as $b) {
            $ts = strtotime($b['info']['created']);
            if ($ts && $ts > $t) $t = $ts;
        }
    }
    foreach (db_only($s) as $b) {
        $ts = strtotime($b['info']['created']);
        if ($ts && $ts > $t) $t = $ts;
    }
    return $t;
}

/** scheduled: то, что запускает задача — по времени и при входе в Windows. */
function cmd_scheduled()
{
    $fail = 0;
    foreach (site_names('all') as $name) {
        try {
            $s = site_cfg($name);
            $last = last_success($s);
            if ($last && time() - $last < $s['min_interval_hours'] * 3600) {
                gb_log("[{$name}] плановый запуск: удачный бекап " . date('d.m.Y H:i', $last) . " — свежее {$s['min_interval_hours']} ч, пропускаю");
                continue;
            }
            backup_site($name, 'auto', []);
        } catch (Throwable $e) {
            gb_log("[{$name}] ОШИБКА: " . $e->getMessage());
            $fail++;
        }
    }
    $stale = stale_sites();
    if ($stale) notify("Бекапы сайтов не делаются:\n\n" . implode("\n", $stale)
        . "\n\nЛог: <папка бекапов>\\<сайт>\\gtsbackup.log\nПроверить: gtsbackup.cmd check");
    return ($fail || $stale) ? 1 : 0;
}

/** Сайты без удачного бекапа alert_days дней — строки для сообщения. */
function stale_sites()
{
    $out = [];
    foreach (site_names('all') as $name) {
        $s = site_cfg($name);
        if ((int)$s['alert_days'] <= 0) continue;
        $last = last_success($s);
        $since = $last ?: (isset($s['added']) ? strtotime($s['added']) : null);
        if (!$since) continue;
        $days = floor((time() - $since) / 86400);
        if ($days < $s['alert_days']) continue;
        $out[] = $last
            ? "• {$name}: последний удачный бекап " . date('d.m.Y H:i', $last) . " — {$days} дн. назад"
            : "• {$name}: ни одного удачного бекапа за {$days} дн. с подключения";
        gb_log("[{$name}] ⚠ нет удачного бекапа {$days} дн. (alert_days = {$s['alert_days']})");
    }
    return $out;
}

function cmd_check($opts)
{
    $bad = 0;
    foreach (site_names('all') as $name) {
        $s = site_cfg($name);
        $last = last_success($s);
        $days = $last ? floor((time() - $last) / 86400) : null;
        $ok = $last && ($s['alert_days'] <= 0 || $days < $s['alert_days']);
        if (!$ok) $bad++;
        echo str_pad($name, 24) . ($last ? 'последний удачный ' . date('d.m.Y H:i', $last) . " ({$days} дн.)" : 'удачных бекапов нет')
            . ($ok ? '  — OK' : "  — ПРОПУСК (alert_days = {$s['alert_days']})") . "\n";
    }
    if (!is_windows() || task_exists()) echo "задача «" . GB_TASK . "»: есть\n";
    else { echo "задача «" . GB_TASK . "»: НЕТ — бекапы сами не делаются (gtsbackup.cmd task 03:30)\n"; $bad++; }
    if ($bad && !empty($opts['popup'])) {
        $stale = stale_sites();
        notify("Бекапы сайтов не делаются:\n\n" . ($stale ? implode("\n", $stale) : 'нет задачи в Планировщике'));
    }
    return $bad ? 1 : 0;
}

/**
 * Окно-предупреждение (Windows). Текст пишется в gtsbackup-alert.txt, показывает его VBScript MsgBox
 * поверх всех окон: в задаче — сам gtsbackup-task.vbs после работы клиента, из консоли — gtsbackup-alert.vbs.
 * В Linux — только stderr.
 */
function notify($text)
{
    fwrite(STDERR, $text . "\n");
    if (!is_windows()) return;
    $file = __DIR__ . '\\gtsbackup-alert.txt';
    file_put_contents($file, "\xFF\xFE" . utf16le(str_replace("\n", "\r\n", mb_substr_safe($text, 1500))));
    if (getenv('GTSBACKUP_TASK')) return; // покажет gtsbackup-task.vbs, когда клиент закончит
    $vbs = __DIR__ . '\\gtsbackup-alert.vbs';
    file_put_contents($vbs, "\xFF\xFE" . utf16le(alert_vbs_code()));
    pclose(popen('start "" wscript.exe //nologo "' . $vbs . '"', 'r'));
}

/** VBScript: показать и удалить gtsbackup-alert.txt, если он есть. */
function alert_vbs_code()
{
    $p = str_replace('"', '""', __DIR__ . '\\gtsbackup-alert.txt');
    return "Set fso = CreateObject(\"Scripting.FileSystemObject\")\r\n"
        . "If fso.FileExists(\"{$p}\") Then\r\n"
        . "  Set f = fso.OpenTextFile(\"{$p}\", 1, False, -1)\r\n"
        . "  t = f.ReadAll\r\n"
        . "  f.Close\r\n"
        . "  fso.DeleteFile \"{$p}\"\r\n"
        . "  MsgBox t, vbExclamation + vbSystemModal, \"gtsBackup\"\r\n"
        . "End If\r\n";
}

function mb_substr_safe($s, $n)
{
    if (function_exists('mb_substr')) return mb_substr($s, 0, $n);
    preg_match('/^.{0,' . (int)$n . '}/us', $s, $m);
    return $m ? $m[0] : $s;
}

function roots_text($d)
{
    if (!empty($d['roots'])) {
        $p = [];
        foreach ($d['roots'] as $k => $r) {
            // список [{prefix, path}] (1.1+) или карта prefix => path (1.0.1)
            list($prefix, $path) = is_array($r) ? [$r['prefix'], $r['path']] : [$k, $r];
            $p[] = $prefix === '' ? "корень {$path}" : ($prefix === '@core/' ? "core {$path} (в архиве @core/)" : "папка {$path} (в архиве {$prefix})");
        }
        return implode(' + ', $p);
    }
    return implode(', ', array_map(function ($x) { return $x === '' ? 'корень сайта' : $x; }, isset($d['sources']) ? $d['sources'] : []));
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
        // за nginx-прокси (proxy_pass на Apache) при HTTP/2 теряется тело ответа
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
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
    $pax = null;
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

        if ($type === 'L') { // GNU: длинное имя (архивы gtsBackup 1.0.0)
            $long = rtrim(gz_read_exact($h, $size), "\0");
            gz_read_exact($h, $pad);
            continue;
        }
        if ($type === 'x' || $type === 'g') { // pax: path= / size= для следующей записи; g — глобальный, пропускаем
            $data = gz_read_exact($h, $size);
            gz_read_exact($h, $pad);
            if ($type === 'x') $pax = pax_parse($data);
            continue;
        }
        if ($long !== null) { $name = $long; $long = null; }
        if (!empty($pax)) {
            if (isset($pax['path'])) $name = $pax['path'];
            if (isset($pax['size'])) { $size = (int)$pax['size']; $pad = (512 - $size % 512) % 512; }
            $pax = null;
        }
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

/** Записи pax «<длина> ключ=значение\n» → [ключ => значение]. */
function pax_parse($data)
{
    $out = [];
    $pos = 0;
    while ($pos < strlen($data) && preg_match('/^(\d+) /', substr($data, $pos, 24), $m)) {
        $rec = substr($data, $pos + strlen($m[0]), (int)$m[1] - strlen($m[0]) - 1);
        $eq = strpos($rec, '=');
        if ($eq !== false) $out[substr($rec, 0, $eq)] = substr($rec, $eq + 1);
        $pos += (int)$m[1];
        if ((int)$m[1] <= 0) break;
    }
    return $out;
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

function dir_size_rec($d)
{
    $t = 0;
    if (!is_dir($d)) return 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) if ($f->isFile()) $t += $f->getSize();
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
