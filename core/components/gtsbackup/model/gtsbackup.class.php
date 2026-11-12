<?php
/**
 * gtsBackup — бекап сайта по токену для cmd-клиента на локальном компьютере.
 *
 * Две части:
 *  - админка (gtsAPI, кастомные действия gtsbackup/*): создать токен;
 *  - эндпоинт assets/components/gtsbackup/action.php (endpoint()): клиент по токену
 *    собирает архив файлов (полный / дифференциальный) по шагам, скачивает его и
 *    забирает дамп базы потоком.
 *
 * Описание обмена — core/components/gtsbackup/client/protocol.md,
 * клиент — core/components/gtsbackup/client/. Код совместим с PHP 7.4.
 */
class gtsBackup
{
    const VERSION = '1.0.1';
    const PROTOCOL = 1;

    /** @var modX */
    public $modx;
    public $config = [];

    /** @var gbToken|null токен текущего запроса эндпоинта */
    protected $token;
    protected $excludes = null;

    function __construct(modX &$modx, array $config = [])
    {
        $this->modx =& $modx;
        $corePath = MODX_CORE_PATH . 'components/gtsbackup/';
        $this->config = array_merge([
            'corePath' => $corePath,
            'modelPath' => $corePath . 'model/',
        ], $config);
        $this->modx->addPackage('gtsbackup', $this->config['modelPath']);
    }

    public function success($message = '', $data = [])
    {
        return ['success' => 1, 'message' => $message, 'data' => $data];
    }

    public function error($message = '', $data = [])
    {
        return ['success' => 0, 'message' => $message, 'data' => $data];
    }

    public function opt($key, $default = null)
    {
        return $this->modx->getOption('gtsbackup_' . $key, null, $default);
    }

    // ════════════════════════════════════════════════════════════════════════
    //  Админка: кастомные действия gtsAPI (gtsbackup/<action>). Права — здесь.
    // ════════════════════════════════════════════════════════════════════════

    public function handleRequest($action, $data = [])
    {
        switch ($action) {
            case 'create_token':
                return $this->createToken($data);
            case 'token_shown':
                return $this->success('');
        }
        return $this->error('Not found action: ' . $action);
    }

    public function isAdmin()
    {
        return $this->modx->user && $this->modx->user->id > 0 && $this->modx->user->isMember('Administrator');
    }

    /**
     * Новый токен. Бессрочный, право одно — бекап. В базе только sha256,
     * сам токен возвращается один раз — в окне после создания.
     */
    public function createToken($data)
    {
        if (!$this->isAdmin()) return $this->error('Токены создаёт только администратор');
        $name = trim(strip_tags(isset($data['name']) ? (string)$data['name'] : ''));
        if ($name === '') return $this->error('Укажите название токена (например, чей это компьютер)');

        $token = 'gbk_' . bin2hex(random_bytes(24));
        /** @var gbToken $t */
        $t = $this->modx->newObject('gbToken');
        $t->fromArray([
            'name' => mb_substr($name, 0, 191),
            'token_hash' => hash('sha256', $token),
            'token_hint' => substr($token, 0, 8) . '…' . substr($token, -4),
            'active' => 1,
            'comment' => isset($data['comment']) ? strip_tags((string)$data['comment']) : '',
            'createdon' => date('Y-m-d H:i:s'),
            'createdby' => (int)$this->modx->user->id,
        ]);
        if (!$t->save()) return $this->error('Не удалось сохранить токен');

        $url = $this->siteUrl();
        $site = preg_replace('/[^a-z0-9_.-]+/i', '_', parse_url($url, PHP_URL_HOST) ?: 'site');
        return $this->success('Токен создан', [
            'modal' => [
                'title' => 'Токен «' . $name . '» — скопируйте сейчас, второй раз он не покажется',
                'fields' => [
                    'token' => ['label' => 'Токен', 'type' => 'text'],
                    'url' => ['label' => 'Адрес сайта', 'type' => 'text'],
                    'cmd' => ['label' => 'Команда на компьютере с бекапами', 'type' => 'textarea'],
                ],
                'values' => [
                    'token' => $token,
                    'url' => $url,
                    'cmd' => 'gtsbackup.cmd add ' . $site . ' ' . $url . ' ' . $token,
                ],
                'action' => 'gtsbackup/token_shown',
                'buttons' => ['submit' => ['label' => 'Скопировал', 'icon' => 'pi pi-check']],
            ],
        ]);
    }

    /** Адрес эндпоинта: схема и хост из site_url + assets_url (сайт может жить в подпапке). */
    public function siteUrl()
    {
        $p = parse_url((string)$this->modx->getOption('site_url'));
        $base = (isset($p['scheme']) ? $p['scheme'] : 'https') . '://' . (isset($p['host']) ? $p['host'] : '')
            . (isset($p['port']) ? ':' . $p['port'] : '');
        return $base . $this->modx->getOption('assets_url', null, '/assets/') . 'components/gtsbackup/action.php';
    }

    // ════════════════════════════════════════════════════════════════════════
    //  Эндпоинт cmd-клиента (action.php). Авторизация — токен из gbToken.
    // ════════════════════════════════════════════════════════════════════════

    public function endpoint()
    {
        $action = isset($_REQUEST['action']) ? (string)$_REQUEST['action'] : '';

        if (!$this->opt('enabled', 1)) return $this->fail(403, 'gtsBackup выключен (gtsbackup_enabled)');
        if ($this->opt('require_https', 0) && !$this->isHttps()) return $this->fail(403, 'Только HTTPS (gtsbackup_require_https)');
        $wl = array_filter(array_map('trim', explode(',', (string)$this->opt('ip_whitelist', ''))));
        if ($wl && !in_array($this->ip(), $wl, true)) return $this->fail(403, 'IP ' . $this->ip() . ' не в gtsbackup_ip_whitelist');

        $this->token = $this->findToken($this->requestToken());
        if (!$this->token) {
            usleep(500000); // перебор токенов — медленнее
            return $this->fail(403, 'Неверный, выключенный или отсутствующий токен');
        }
        $this->touchToken();
        $this->cleanupJobs();

        switch ($action) {
            case 'ping':     return $this->actPing();
            case 'create':   return $this->actCreate();
            case 'step':     return $this->actStep();
            case 'download': return $this->actDownload();   // поток + exit
            case 'finish':   return $this->actFinish(false);
            case 'cancel':   return $this->actFinish(true);
            case 'db':       return $this->actDb();         // поток + exit
        }
        return $this->fail(400, 'Неизвестное действие: ' . $action);
    }

    protected function fail($code, $message, $data = [])
    {
        http_response_code($code);
        return $this->error($message, $data);
    }

    public function isHttps()
    {
        if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') return true;
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') return true;
        return isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443;
    }

    public function ip()
    {
        return isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
    }

    /** Токен из заголовка X-Gtsbackup-Token, Authorization: Bearer или POST token (не из GET — уйдёт в логи). */
    protected function requestToken()
    {
        if (!empty($_SERVER['HTTP_X_GTSBACKUP_TOKEN'])) return trim($_SERVER['HTTP_X_GTSBACKUP_TOKEN']);
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $k) {
            if (!empty($_SERVER[$k]) && preg_match('/^Bearer\s+(\S+)/i', $_SERVER[$k], $m)) return $m[1];
        }
        return isset($_POST['token']) ? trim((string)$_POST['token']) : '';
    }

    protected function findToken($token)
    {
        if ($token === '' || strlen($token) > 200) return null;
        return $this->modx->getObject('gbToken', ['token_hash' => hash('sha256', $token), 'active' => 1]);
    }

    protected function touchToken()
    {
        $t = $this->modx->getTableName('gbToken');
        $stmt = $this->modx->prepare("UPDATE {$t} SET last_used = ?, last_ip = ?, used_count = used_count + 1 WHERE id = ?");
        if ($stmt) $stmt->execute([date('Y-m-d H:i:s'), $this->ip(), (int)$this->token->get('id')]);
    }

    public function actPing()
    {
        return $this->success('pong', [
            'protocol' => self::PROTOCOL,
            'version' => self::VERSION,
            'site_name' => $this->modx->getOption('site_name'),
            'http_host' => $this->modx->getOption('http_host'),
            'token' => $this->token->get('name'),
            'php' => PHP_VERSION,
            'sources' => array_keys($this->sources()),
            'step_time' => $this->stepTime(),
            'db_method' => $this->dbMethod(),
            'https' => $this->isHttps(),
        ]);
    }

    // ── рабочая папка и задания ─────────────────────────────────────────────

    public function tmpDir()
    {
        $dir = trim((string)$this->opt('tmp_path', ''));
        if ($dir === '') $dir = MODX_CORE_PATH . 'packages/_gtsbackup/';
        $dir = rtrim(str_replace('\\', '/', $dir), '/') . '/';
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        if (!file_exists($dir . '.htaccess')) @file_put_contents($dir . '.htaccess', "Require all denied\nDeny from all\n");
        if (!file_exists($dir . 'index.html')) @file_put_contents($dir . 'index.html', '');
        if (!is_dir($dir . 'manifests')) @mkdir($dir . 'manifests', 0750, true);
        return $dir;
    }

    protected function jobDir($job)
    {
        if (!preg_match('/^\d{8}-\d{6}-[0-9a-f]{8}$/', (string)$job)) return null;
        $d = $this->tmpDir() . $job . '/';
        return is_dir($d) ? $d : null;
    }

    protected function loadState($dir)
    {
        $s = json_decode((string)@file_get_contents($dir . 'state.json'), true);
        return is_array($s) ? $s : null;
    }

    protected function saveState($dir, $s)
    {
        file_put_contents($dir . 'state.json.tmp', json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        @unlink($dir . 'state.json');
        rename($dir . 'state.json.tmp', $dir . 'state.json');
    }

    /** Задание принадлежит токену запроса — чужое не отдаём. */
    protected function ownJob(&$dir, &$state)
    {
        $job = isset($_REQUEST['job']) ? (string)$_REQUEST['job'] : '';
        $dir = $this->jobDir($job);
        $state = $dir ? $this->loadState($dir) : null;
        if (!$state || (int)$state['token_id'] !== (int)$this->token->get('id')) return false;
        return true;
    }

    /** Брошенные задания старше gtsbackup_job_ttl_hours удаляются. */
    protected function cleanupJobs()
    {
        $ttl = max(1, (int)$this->opt('job_ttl_hours', 24)) * 3600;
        $root = $this->tmpDir();
        foreach ((array)glob($root . '*', GLOB_ONLYDIR) as $d) {
            $name = basename($d);
            if (!preg_match('/^\d{8}-\d{6}-[0-9a-f]{8}$/', $name)) continue;
            $f = file_exists($d . '/state.json') ? $d . '/state.json' : $d;
            if (time() - filemtime($f) < $ttl) continue;
            $s = $this->loadState($d . '/');
            if ($s && !empty($s['log_id'])) $this->updateLog($s['log_id'], ['status' => 'abandoned', 'finishedon' => date('Y-m-d H:i:s')]);
            $this->rrmdir($d);
        }
    }

    public function rrmdir($dir)
    {
        if (!is_dir($dir)) return;
        foreach (array_diff((array)scandir($dir), ['.', '..']) as $f) {
            $p = $dir . '/' . $f;
            (is_dir($p) && !is_link($p)) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    protected function stepTime()
    {
        return max(3, min(300, (int)$this->opt('step_time', 20)));
    }

    protected function level()
    {
        return max(1, min(9, (int)$this->opt('compress_level', 6)));
    }

    // ── журнал ──────────────────────────────────────────────────────────────

    protected function addLog($kind, $mode, $job = '', $status = 'running')
    {
        $l = $this->modx->newObject('gbLog');
        $l->fromArray([
            'token_id' => (int)$this->token->get('id'),
            'kind' => $kind, 'mode' => $mode, 'job' => $job, 'status' => $status,
            'ip' => $this->ip(), 'createdon' => date('Y-m-d H:i:s'),
        ]);
        $l->save();
        return (int)$l->get('id');
    }

    protected function updateLog($id, $fields)
    {
        if (!$id) return;
        if ($l = $this->modx->getObject('gbLog', (int)$id)) {
            $l->fromArray($fields);
            $l->save();
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    //  Файлы
    // ════════════════════════════════════════════════════════════════════════

    /** Что бекапим: префикс в архиве => абсолютная папка. core вне корня — под @core/. */
    public function sources()
    {
        $root = trim((string)$this->opt('root', ''));
        if ($root === '') $root = MODX_BASE_PATH;
        $root = $this->normDir($root);
        $src = ['' => $root];
        $core = $this->normDir(MODX_CORE_PATH);
        if (strpos($core, $root) !== 0) $src['@core/'] = $core;
        return $src;
    }

    protected function normDir($d)
    {
        $r = realpath($d);
        return rtrim(str_replace('\\', '/', $r ?: $d), '/') . '/';
    }

    protected function loadExcludes()
    {
        if ($this->excludes !== null) return;
        $this->excludes = [];
        foreach (preg_split('/[\r\n,]+/', (string)$this->opt('exclude', '')) as $p) {
            $p = trim(str_replace('\\', '/', $p));
            if ($p === '') continue;
            $dirOnly = substr($p, -1) === '/';
            $p = trim($p, '/');
            if ($p === '') continue;
            $this->excludes[] = [$p, $dirOnly, strpos($p, '/') !== false];
        }
    }

    /** $rel — путь от корня сайта («core/…» и для core вне корня). */
    protected function isExcluded($rel, $isDir)
    {
        $this->loadExcludes();
        $base = basename($rel);
        foreach ($this->excludes as $e) {
            list($p, $dirOnly, $hasSlash) = $e;
            if ($dirOnly && !$isDir) continue;
            if ($hasSlash ? fnmatch($p, $rel, FNM_PATHNAME) : fnmatch($p, $base)) return true;
        }
        return false;
    }

    /**
     * Обход дерева: колбэк получает (путь в архиве, абсолютный путь, размер, mtime).
     * Ссылки на папки не обходятся (петли), рабочая папка бекапа — никогда.
     */
    protected function walk(callable $cb, &$warnings)
    {
        $tmp = $this->tmpDir();
        $maxBytes = (float)$this->opt('max_file_mb', 0) * 1048576;
        foreach ($this->sources() as $prefix => $dir) {
            $relBase = $prefix === '@core/' ? 'core/' : '';
            $stack = [''];
            while ($stack) {
                $sub = array_pop($stack);
                $abs = $dir . $sub;
                $h = @opendir($abs);
                if (!$h) { $warnings[] = 'нет доступа к папке: ' . $prefix . $sub; continue; }
                $has = false;
                while (($f = readdir($h)) !== false) {
                    if ($f === '.' || $f === '..') continue;
                    $p = $abs . $f;
                    $rel = $sub . $f;
                    if (is_dir($p)) {
                        if (is_link($p)) continue;
                        if (strpos($p . '/', $tmp) === 0) continue;
                        if ($this->isExcluded($relBase . $rel, true)) continue;
                        $stack[] = $rel . '/';
                        $has = true;
                        continue;
                    }
                    if (!is_file($p)) continue;
                    if ($this->isExcluded($relBase . $rel, false)) continue;
                    $st = @stat($p);
                    if (!$st) { $warnings[] = 'нет доступа к файлу: ' . $prefix . $rel; continue; }
                    if ($maxBytes > 0 && $st['size'] > $maxBytes) { $warnings[] = 'больше лимита, пропущен: ' . $prefix . $rel; continue; }
                    $cb($prefix . $rel, $p, (int)$st['size'], (int)$st['mtime']);
                    $has = true;
                }
                closedir($h);
                // пустая папка (cache, загрузки) — запись «папка» (путь с «/» на конце), чтобы восстановилась
                if (!$has && $sub !== '') $cb($prefix . $sub, $abs, 0, 0);
            }
        }
    }

    /** Манифест: строки JSON [путь, размер, mtime], последняя — {"meta": …}. */
    protected function readManifest($file)
    {
        $map = [];
        $meta = null;
        $h = @gzopen($file, 'rb');
        if (!$h) return null;
        while (($line = gzgets($h, 1 << 20)) !== false) {
            $line = trim($line);
            if ($line === '') continue;
            $row = json_decode($line, true);
            if (!is_array($row)) continue;
            if (isset($row['meta'])) { $meta = $row['meta']; continue; }
            if (isset($row[0], $row[1], $row[2])) $map[$row[0]] = [(int)$row[1], (int)$row[2]];
        }
        gzclose($h);
        return ['files' => $map, 'meta' => $meta];
    }

    /**
     * create: mode=full|diff. Для diff база — base_job (полный бекап, который сервер помнит)
     * или загруженный файл base (manifest.jsonl.gz полного бекапа).
     * Обходит дерево, пишет список файлов к архивации и манифест, архив собирают шаги.
     */
    public function actCreate()
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');
        $mode = isset($_REQUEST['mode']) ? (string)$_REQUEST['mode'] : 'full';
        if (!in_array($mode, ['full', 'diff'], true)) return $this->fail(400, 'mode: full или diff');

        $base = null;
        $baseJob = '';
        if ($mode === 'diff') {
            $tid = (int)$this->token->get('id');
            if (!empty($_FILES['base']['tmp_name']) && is_uploaded_file($_FILES['base']['tmp_name'])) {
                $base = $this->readManifest($_FILES['base']['tmp_name']);
            } elseif (!empty($_REQUEST['base_job']) && preg_match('/^\d{8}-\d{6}-[0-9a-f]{8}$/', $_REQUEST['base_job'])) {
                $f = $this->tmpDir() . 'manifests/t' . $tid . '-' . $_REQUEST['base_job'] . '.jsonl.gz';
                if (file_exists($f)) $base = $this->readManifest($f);
            }
            if (!$base) return $this->fail(409, 'Нет манифеста полного бекапа на сервере — пришлите файл base или сделайте полный', ['code' => 'base_missing']);
            $baseJob = isset($base['meta']['job']) ? (string)$base['meta']['job'] : (string)(isset($_REQUEST['base_job']) ? $_REQUEST['base_job'] : '');
        }

        $job = date('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $dir = $this->tmpDir() . $job . '/';
        if (!@mkdir($dir, 0750, true)) return $this->fail(500, 'Не создать рабочую папку ' . $dir);

        $list = fopen($dir . 'list.jsonl', 'wb');
        $man = gzopen($dir . 'manifest.jsonl.gz', 'wb' . $this->level());
        $baseFiles = $base ? $base['files'] : null;
        unset($base); // одна копия карты в памяти, а не две
        $cnt = 0; $bytes = 0; $all = 0; $warnings = [];
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

        $this->walk(function ($arc, $abs, $size, $mtime) use ($list, $man, &$baseFiles, &$cnt, &$bytes, &$all, $flags) {
            $all++;
            gzwrite($man, json_encode([$arc, $size, $mtime], $flags) . "\n");
            if ($baseFiles !== null) {
                $old = isset($baseFiles[$arc]) ? $baseFiles[$arc] : null;
                unset($baseFiles[$arc]);
                if ($old && $old[0] === $size && $old[1] === $mtime) return;
            }
            fwrite($list, json_encode([$arc, $abs, $size, $mtime], $flags) . "\n");
            $cnt++;
            $bytes += $size;
        }, $warnings);

        $deleted = $baseFiles !== null ? array_keys($baseFiles) : [];
        $meta = [
            'gtsbackup' => self::PROTOCOL,
            'job' => $job, 'mode' => $mode, 'base_job' => $baseJob,
            'created' => date('c'), 'site' => $this->modx->getOption('http_host'),
            'sources' => array_keys($this->sources()),
            'files_total' => $all, 'files_in_archive' => $cnt, 'bytes_in_archive' => $bytes,
            'deleted' => $deleted,
            'warnings' => array_slice($warnings, 0, 1000),
        ];
        gzwrite($man, json_encode(['meta' => $meta], $flags) . "\n");
        gzclose($man);
        fclose($list);

        $logId = $this->addLog('files', $mode, $job);
        $this->saveState($dir, [
            'job' => $job, 'mode' => $mode, 'base_job' => $baseJob, 'token_id' => (int)$this->token->get('id'),
            'log_id' => $logId, 'list_pos' => 0, 'cur' => null, 'done' => false,
            'files_done' => 0, 'bytes_done' => 0, 'files_total' => $cnt, 'bytes_total' => $bytes,
            'warnings' => count($warnings), 'step_warnings' => [],
        ]);
        return $this->success('Задание создано', [
            'job' => $job, 'mode' => $mode, 'base_job' => $baseJob,
            'files_total' => $cnt, 'bytes_total' => $bytes, 'files_site' => $all,
            'deleted' => count($deleted), 'warnings' => count($warnings), 'step_time' => $this->stepTime(),
        ]);
    }

    /**
     * step: дописывает в files.tar.gz столько, сколько успеет за gtsbackup_step_time.
     * Каждый шаг — отдельный член gzip (склейка членов — валидный gzip), tar идёт одним потоком,
     * большой файл может переходить из шага в шаг.
     */
    public function actStep()
    {
        @set_time_limit(0);
        if (!$this->ownJob($dir, $s)) return $this->fail(404, 'Задание не найдено');
        if ($s['done']) return $this->success('done', $this->progress($s));

        $lock = fopen($dir . 'lock', 'c');
        if (!flock($lock, LOCK_EX | LOCK_NB)) return $this->fail(409, 'Шаг этого задания уже выполняется');

        $deadline = microtime(true) + $this->stepTime();
        $out = fopen($dir . 'files.tar.gz', 'ab');
        $z = deflate_init(ZLIB_ENCODING_GZIP, ['level' => $this->level()]);
        $buf = '';
        $put = function ($data) use (&$buf, $z, $out) {
            $buf .= deflate_add($z, $data, ZLIB_NO_FLUSH);
            if (strlen($buf) > 1048576) { fwrite($out, $buf); $buf = ''; }
        };
        $list = fopen($dir . 'list.jsonl', 'rb');
        fseek($list, (int)$s['list_pos']);
        $warn = [];

        while (microtime(true) < $deadline) {
            if ($s['cur']) {
                $c = $s['cur'];
                $left = $c['size'] - $c['offset'];
                $fh = @fopen($c['abs'], 'rb');
                if ($fh && $c['offset'] > 0) fseek($fh, $c['offset']);
                while ($left > 0 && microtime(true) < $deadline) {
                    $chunk = $fh ? fread($fh, (int)min(1048576, $left)) : '';
                    if ($chunk === '' || $chunk === false) {
                        // файл стал короче или пропал после заголовка — добиваем нулями
                        $warn[] = 'файл изменился при чтении: ' . $c['arc'];
                        $chunk = str_repeat("\0", (int)min(1048576, $left));
                        $fh && fclose($fh);
                        $fh = null;
                    }
                    $put($chunk);
                    $left -= strlen($chunk);
                    $c['offset'] += strlen($chunk);
                }
                if ($fh) fclose($fh);
                if ($left > 0) { $s['cur'] = $c; break; }
                $pad = (512 - $c['size'] % 512) % 512;
                if ($pad) $put(str_repeat("\0", $pad));
                $s['cur'] = null;
                $s['files_done']++;
                $s['bytes_done'] += $c['size'];
                continue;
            }
            $line = fgets($list);
            if ($line === false) {
                $put(str_repeat("\0", 1024)); // конец tar
                $s['done'] = true;
                break;
            }
            $row = json_decode($line, true);
            if (!is_array($row)) continue;
            list($arc, $abs, , $mtime) = $row;
            if (substr($arc, -1) === '/') { // пустая папка
                $put($this->tarHeader($arc, 0, time(), '5'));
                $s['files_done']++;
                continue;
            }
            clearstatcache(true, $abs);
            if (!is_readable($abs) || ($size = @filesize($abs)) === false) {
                $warn[] = 'файл пропал или не читается: ' . $arc;
                continue;
            }
            $put($this->tarHeader($arc, (int)$size, (int)$mtime));
            $s['cur'] = ['arc' => $arc, 'abs' => $abs, 'size' => (int)$size, 'offset' => 0];
        }

        $s['list_pos'] = ftell($list);
        fclose($list);
        $buf .= deflate_add($z, '', ZLIB_FINISH);
        fwrite($out, $buf);
        fclose($out);
        if ($warn) {
            $s['warnings'] += count($warn);
            $s['step_warnings'] = array_slice(array_merge($s['step_warnings'], $warn), -200);
        }
        $this->saveState($dir, $s);
        flock($lock, LOCK_UN);
        fclose($lock);
        return $this->success($s['done'] ? 'done' : 'progress', $this->progress($s) + ['step_warnings' => $warn]);
    }

    protected function progress($s)
    {
        $dir = $this->jobDir($s['job']);
        return [
            'job' => $s['job'], 'done' => (bool)$s['done'],
            'files_done' => $s['files_done'], 'files_total' => $s['files_total'],
            'bytes_done' => $s['bytes_done'], 'bytes_total' => $s['bytes_total'],
            'archive_size' => $dir && file_exists($dir . 'files.tar.gz') ? filesize($dir . 'files.tar.gz') : 0,
            'warnings' => $s['warnings'],
        ];
    }

    /** Заголовок tar (GNU): длинные имена — через ././@LongLink, размер > 8 ГБ — base-256. */
    protected function tarHeader($name, $size, $mtime, $type = '0')
    {
        $out = '';
        if (strlen($name) > 99) {
            $data = $name . "\0";
            $out .= $this->tarHeaderRaw('././@LongLink', strlen($data), 0, 'L');
            $out .= $data . str_repeat("\0", (512 - strlen($data) % 512) % 512);
            $name = substr($name, 0, 99);
        }
        return $out . $this->tarHeaderRaw($name, $size, $mtime, $type);
    }

    protected function tarHeaderRaw($name, $size, $mtime, $type)
    {
        $mode = $type === '5' ? "0000755\0" : "0000644\0";
        $sizeField = $size < 8589934591
            ? sprintf('%011o', $size) . "\0"
            : chr(0x80) . "\0\0\0" . pack('J', $size);
        $h = str_pad($name, 100, "\0")
            . $mode . "0000000\0" . "0000000\0"
            . $sizeField
            . sprintf('%011o', max(0, $mtime)) . "\0"
            . '        '
            . $type
            . str_repeat("\0", 100)
            . "ustar  \0"
            . str_repeat("\0", 32 + 32 + 8 + 8 + 155);
        $h = str_pad($h, 512, "\0");
        $sum = 0;
        for ($i = 0; $i < 512; $i++) $sum += ord($h[$i]);
        return substr_replace($h, sprintf('%06o', $sum) . "\0 ", 148, 8);
    }

    /** download: what=files (готовый архив) | manifest. Поддерживает Range: bytes=N- (докачка). */
    public function actDownload()
    {
        if (!$this->ownJob($dir, $s)) return $this->fail(404, 'Задание не найдено');
        $what = isset($_REQUEST['what']) ? (string)$_REQUEST['what'] : 'files';
        if ($what === 'manifest') {
            $file = $dir . 'manifest.jsonl.gz';
        } elseif ($what === 'files') {
            if (!$s['done']) return $this->fail(409, 'Архив ещё собирается — вызывайте step');
            $file = $dir . 'files.tar.gz';
        } else {
            return $this->fail(400, 'what: files или manifest');
        }
        if (!file_exists($file)) return $this->fail(404, 'Файла нет');
        $this->sendFile($file, $s['job'] . '-' . basename($file));
    }

    protected function sendFile($file, $name)
    {
        @set_time_limit(0);
        clearstatcache(true, $file);
        $size = filesize($file);
        $start = 0;
        if (!empty($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d+)-/', $_SERVER['HTTP_RANGE'], $m)) {
            $start = (int)$m[1];
            if ($start >= $size) {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                exit;
            }
            http_response_code(206);
            header('Content-Range: bytes ' . $start . '-' . ($size - 1) . '/' . $size);
        }
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Accept-Ranges: bytes');
        header('Content-Length: ' . ($size - $start));
        header('X-Gtsbackup-Size: ' . $size);
        header('X-Accel-Buffering: no');
        $h = fopen($file, 'rb');
        fseek($h, $start);
        while (!feof($h)) {
            echo fread($h, 1048576);
            flush();
            if (connection_aborted()) break;
        }
        fclose($h);
        exit;
    }

    /**
     * finish: клиент забрал архив — пишем журнал, у полного бекапа сохраняем манифест
     * (база для следующих дифференциальных), рабочую папку удаляем. cancel — то же без манифеста.
     */
    public function actFinish($cancel)
    {
        if (!$this->ownJob($dir, $s)) return $this->fail(404, 'Задание не найдено');
        $archive = file_exists($dir . 'files.tar.gz') ? filesize($dir . 'files.tar.gz') : 0;
        if (!$cancel && $s['done'] && $s['mode'] === 'full') {
            $tid = (int)$s['token_id'];
            $mdir = $this->tmpDir() . 'manifests/';
            copy($dir . 'manifest.jsonl.gz', $mdir . 't' . $tid . '-' . $s['job'] . '.jsonl.gz');
            // помним 3 последних полных на токен — клиент держит свою ротацию и при нужде пришлёт манифест сам
            $old = (array)glob($mdir . 't' . $tid . '-*.jsonl.gz');
            sort($old);
            foreach (array_slice($old, 0, max(0, count($old) - 3)) as $f) @unlink($f);
        }
        $msg = $s['warnings'] ? 'предупреждений: ' . $s['warnings'] . '; ' . implode('; ', array_slice($s['step_warnings'], 0, 20)) : '';
        $this->updateLog($s['log_id'], [
            'status' => $cancel ? 'cancelled' : ($s['done'] ? 'ok' : 'incomplete'),
            'files' => $s['files_done'], 'size' => $archive, 'message' => $msg,
            'finishedon' => date('Y-m-d H:i:s'),
        ]);
        $this->rrmdir(rtrim($dir, '/'));
        return $this->success($cancel ? 'Задание отменено' : 'Задание закрыто');
    }

    // ════════════════════════════════════════════════════════════════════════
    //  База: дамп потоком в gzip. Последняя строка — «-- gtsBackup-end: ok» или ошибка.
    // ════════════════════════════════════════════════════════════════════════

    public function getDbConfig()
    {
        $cfgFile = MODX_CORE_PATH . 'config/' . MODX_CONFIG_KEY . '.inc.php';
        if (!file_exists($cfgFile)) return null;
        include $cfgFile;
        return [
            'host' => isset($database_server) ? $database_server : 'localhost',
            'user' => isset($database_user) ? $database_user : '',
            'pass' => isset($database_password) ? $database_password : '',
            'db' => isset($dbase) ? trim($dbase, '`') : '',
            'prefix' => isset($table_prefix) ? $table_prefix : '',
            'dsn' => isset($database_dsn) ? $database_dsn : '',
            'charset' => isset($database_connection_charset) ? $database_connection_charset : 'utf8mb4',
        ];
    }

    /** Имена из настройки: «session» → modx_session, и как есть — для таблиц без префикса. */
    protected function tableList($key, $prefix)
    {
        $out = [];
        foreach (explode(',', (string)$this->opt($key, '')) as $t) {
            $t = trim($t);
            if ($t === '') continue;
            $out[] = $prefix . $t;
            if ($prefix !== '') $out[] = $t;
        }
        return array_values(array_unique($out));
    }

    protected function mysqldumpBin()
    {
        $bin = trim((string)$this->opt('mysqldump_bin', ''));
        return $bin !== '' ? $bin : 'mysqldump';
    }

    /** Итоговый способ выгрузки: mysqldump | php. */
    public function dbMethod()
    {
        $m = strtolower(trim((string)$this->opt('db_method', 'auto')));
        if ($m === 'php' || $m === 'mysqldump') return $m;
        if (!function_exists('proc_open')) return 'php';
        $p = $this->procStart([$this->mysqldumpBin(), '--version']);
        if (!$p) return 'php';
        stream_get_contents($p['pipes'][1]);
        list($code) = $this->procEnd($p);
        return $code === 0 ? 'mysqldump' : 'php';
    }

    public function actDb()
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');
        $cfg = $this->getDbConfig();
        if (!$cfg || $cfg['db'] === '') return $this->fail(500, 'Не прочитать настройки базы');
        $method = $this->dbMethod();
        $logId = $this->addLog('db', $method);

        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $cfg['db'] . '-' . date('YmdHis') . '.sql.gz"');
        header('X-Gtsbackup-Db-Method: ' . $method);
        header('X-Accel-Buffering: no');

        $z = deflate_init(ZLIB_ENCODING_GZIP, ['level' => $this->level()]);
        $bytes = 0;
        $emit = function ($s) use ($z, &$bytes) {
            $bytes += strlen($s);
            $o = deflate_add($z, $s, ZLIB_NO_FLUSH);
            if ($o !== '') { echo $o; flush(); }
        };

        $err = '';
        try {
            $err = $method === 'mysqldump' ? $this->dumpMysqldump($cfg, $emit) : $this->dumpPhp($cfg, $emit);
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
        $emit("\n-- gtsBackup-end: " . ($err === '' ? 'ok' : 'error ' . str_replace("\n", ' ', $err)) . "\n");
        echo deflate_add($z, '', ZLIB_FINISH);
        flush();
        $this->updateLog($logId, [
            'status' => $err === '' ? 'ok' : 'error', 'size' => $bytes,
            'message' => $err, 'finishedon' => date('Y-m-d H:i:s'),
        ]);
        exit;
    }

    /** mysqldump: основной проход без «только структура» и пропущенных таблиц, второй — их структура. */
    protected function dumpMysqldump($cfg, callable $emit)
    {
        $ignore = $this->tableList('db_ignore_tables', $cfg['prefix']);
        $nodata = array_values(array_diff($this->tableList('db_nodata_tables', $cfg['prefix']), $ignore)); // исключение главнее
        // ignore-table — в файле опций, а не в командной строке: длинный список упрётся в лимит Windows
        $skip = [];
        foreach (array_merge($nodata, $ignore) as $t) $skip[] = 'ignore-table=' . $cfg['db'] . '.' . $t;
        $cred = $this->writeMysqlCred($cfg, $skip);
        $cred2 = $this->writeMysqlCred($cfg);
        $base = ['--single-transaction', '--quick', '--no-tablespaces', '--hex-blob', '--default-character-set=utf8mb4'];
        $bin = $this->mysqldumpBin();
        $err = '';
        try {
            $err = $this->pipeProc(array_merge([$bin, '--defaults-extra-file=' . $cred], $base, [$cfg['db']]), $emit);
            if ($err === '' && $nodata) {
                $existing = $this->existingTables($cfg, $nodata);
                if ($existing) {
                    $err = $this->pipeProc(array_merge([$bin, '--defaults-extra-file=' . $cred2], $base, ['--no-data', $cfg['db']], $existing), $emit);
                }
            }
        } finally {
            @unlink($cred);
            @unlink($cred2);
        }
        return $err;
    }

    protected function existingTables($cfg, $names)
    {
        $out = [];
        foreach ($names as $t) {
            $stmt = $this->modx->query('SHOW TABLES LIKE ' . $this->modx->quote($t));
            if ($stmt && $stmt->fetchColumn()) $out[] = $t;
        }
        return $out;
    }

    /** Запуск процесса, stdout — в $emit. Возвращает '' или текст ошибки. */
    protected function pipeProc($args, callable $emit)
    {
        $p = $this->procStart($args);
        if (!$p) return 'mysqldump не запустился';
        while (!feof($p['pipes'][1])) {
            $chunk = fread($p['pipes'][1], 1 << 16);
            if ($chunk !== '' && $chunk !== false) $emit($chunk);
        }
        list($code, $stderr) = $this->procEnd($p);
        return $code === 0 ? '' : 'mysqldump код ' . $code . ': ' . trim(substr($stderr, 0, 2000));
    }

    /** Кросс-платформенный запуск из массива аргументов (Windows — без cmd.exe, *nix — escapeshellarg). */
    public function procStart($args)
    {
        if (stripos(PHP_OS, 'WIN') === 0) {
            $parts = [];
            foreach ($args as $i => $a) {
                if ($i === 0) $parts[] = '"' . str_replace('/', '\\', $a) . '"';
                else $parts[] = (strpos($a, ' ') !== false) ? '"' . str_replace('"', '', $a) . '"' : $a;
            }
            $cmd = implode(' ', $parts);
            $opts = ['bypass_shell' => true];
        } else {
            $cmd = implode(' ', array_map('escapeshellarg', $args));
            $opts = [];
        }
        // stderr — во временный файл, не в пайп: на Windows неблокирующее чтение пайпов не работает,
        // и чтение stderr в цикле вешает процесс, пока он пишет в stdout.
        $errFile = tempnam(sys_get_temp_dir(), 'gtsbe');
        $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']], $pipes, null, null, $opts);
        if (!is_resource($proc)) { @unlink($errFile); return false; }
        return ['proc' => $proc, 'pipes' => $pipes, 'errfile' => $errFile];
    }

    /** Дождаться процесса: [код выхода, stderr]. */
    protected function procEnd($p)
    {
        if (is_resource($p['pipes'][1])) fclose($p['pipes'][1]);
        $code = proc_close($p['proc']);
        $err = (string)@file_get_contents($p['errfile'], false, null, 0, 4000);
        @unlink($p['errfile']);
        return [$code, $err];
    }

    /** Пароль — во временном --defaults-extra-file, а не в командной строке. */
    protected function writeMysqlCred($cfg, $mysqldumpOpts = [])
    {
        $host = $cfg['host'];
        $port = '';
        if (preg_match('/^(.+):(\d+)$/', $host, $m)) { $host = $m[1]; $port = "port=\"{$m[2]}\"\n"; }
        $f = tempnam(sys_get_temp_dir(), 'gtsb');
        file_put_contents($f, "[client]\nhost=\"{$host}\"\n{$port}user=\"{$cfg['user']}\"\npassword=\"" . addcslashes($cfg['pass'], '"\\') . "\"\n"
            . ($mysqldumpOpts ? "[mysqldump]\n" . implode("\n", $mysqldumpOpts) . "\n" : ''));
        @chmod($f, 0600);
        return $f;
    }

    /** Выгрузка средствами PHP — когда mysqldump нет (хостинг). Таблицы, данные, представления. */
    protected function dumpPhp($cfg, callable $emit)
    {
        $dsn = $cfg['dsn'] ?: 'mysql:host=' . $cfg['host'] . ';dbname=' . $cfg['db'] . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
        ]);
        $pdo->exec('SET NAMES utf8mb4');
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');

        $nodata = $this->tableList('db_nodata_tables', $cfg['prefix']);
        $ignore = $this->tableList('db_ignore_tables', $cfg['prefix']);

        $emit("-- gtsBackup PHP dump\n-- База: {$cfg['db']}, " . date('c') . "\n\n"
            . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET UNIQUE_CHECKS=0;\n\n");

        $tables = $pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
        $views = [];
        foreach ($tables as $row) {
            list($t, $type) = $row;
            if ($type === 'VIEW') { $views[] = $t; continue; }
            if (in_array($t, $ignore, true)) continue;
            $q = '`' . str_replace('`', '``', $t) . '`';
            $create = $pdo->query('SHOW CREATE TABLE ' . $q)->fetch(PDO::FETCH_NUM);
            $emit("DROP TABLE IF EXISTS {$q};\n" . $create[1] . ";\n\n");
            if (in_array($t, $nodata, true)) continue;

            $kinds = [];
            foreach ($pdo->query('SHOW COLUMNS FROM ' . $q)->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $ty = strtolower($c['Type']);
                $kinds[] = preg_match('/blob|binary|^bit/', $ty) ? 'hex'
                    : (preg_match('/^(tinyint|smallint|mediumint|int|bigint|decimal|float|double)/', $ty) ? 'num' : 'str');
            }
            $stmt = $pdo->query('SELECT * FROM ' . $q);
            $batch = '';
            $head = "INSERT INTO {$q} VALUES\n";
            while ($r = $stmt->fetch(PDO::FETCH_NUM)) {
                $vals = [];
                foreach ($r as $i => $v) {
                    if ($v === null) $vals[] = 'NULL';
                    elseif ($kinds[$i] === 'hex') $vals[] = $v === '' ? "''" : '0x' . bin2hex($v);
                    elseif ($kinds[$i] === 'num' && is_numeric($v)) $vals[] = $v;
                    else $vals[] = $pdo->quote($v);
                }
                $batch .= ($batch === '' ? $head : ",\n") . '(' . implode(',', $vals) . ')';
                if (strlen($batch) > 524288) { $emit($batch . ";\n"); $batch = ''; }
            }
            $stmt->closeCursor();
            if ($batch !== '') $emit($batch . ";\n");
            $emit("\n");
        }
        foreach ($views as $v) {
            if (in_array($v, $ignore, true)) continue;
            $q = '`' . str_replace('`', '``', $v) . '`';
            $create = $pdo->query('SHOW CREATE VIEW ' . $q)->fetch(PDO::FETCH_NUM);
            $sql = preg_replace('/DEFINER=`[^`]*`@`[^`]*`\s*/', '', $create[1]);
            $emit("DROP VIEW IF EXISTS {$q};\n{$sql};\n\n");
        }
        $pdo->exec('COMMIT');
        $emit("SET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n");
        return '';
    }
}
