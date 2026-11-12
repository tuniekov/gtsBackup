# gtsBackup — клиент для Windows: ничего ставить не нужно.
# Использует только то, что есть в Windows 10 (1803+) и 11: PowerShell 5.1, curl.exe, tar.exe.
# Запуск — gtsbackup.cmd (он вызывает этот файл с -ExecutionPolicy Bypass). Справка: gtsbackup.cmd help
# Настройки и папки бекапов — те же, что у gtsbackup.php (Linux/macOS): один gtsbackup.json.
# Обмен с сайтом — protocol.md рядом.

$ErrorActionPreference = 'Stop'
$Version = '1.1.1'
$Protocol = 1
$TaskName = 'gtsBackup'
$TaskTimeDefault = '03:30'
$Dir = $PSScriptRoot
$Curl = Join-Path $env:SystemRoot 'System32\curl.exe'
$Tar = Join-Path $env:SystemRoot 'System32\tar.exe'
$Utf8 = New-Object System.Text.UTF8Encoding $false
try { [Console]::OutputEncoding = [System.Text.Encoding]::UTF8 } catch {}

$ConfigFile = if ($env:GTSBACKUP_CONFIG) { $env:GTSBACKUP_CONFIG } else { Join-Path $Dir 'gtsbackup.json' }
$script:LogFile = $null

# Настройки сайта по умолчанию — те же, что в gtsbackup.php
$Defaults = [ordered]@{
    files              = $true   # бекап файлов
    db                 = $true   # дамп базы
    mode               = 'auto'  # auto | full | diff
    keep_full          = 4       # цепочек «полный + его дифференциальные»
    max_diff           = 6       # дифференциальных подряд, потом полный
    full_every_days    = 7       # полный не реже
    keep_db_only       = 14      # бекапов «только база»
    verify             = $true   # проверить архив и дамп после скачивания
    insecure           = $false  # не проверять сертификат HTTPS
    cacert             = ''      # свой корневой сертификат (PEM)
    ssl_no_revoke      = $false  # не проверять отзыв сертификата (сеть без доступа к спискам отзыва)
    timeout            = 900     # таймаут одного запроса, сек
    retries            = 5       # повторы при обрыве
    alert_days         = 2       # нет удачного бекапа столько дней — окно (0 — не показывать)
    min_interval_hours = 20      # плановый запуск пропускает сайт, если удачный бекап свежее
}

# ════════════════════════════════════════════════════════════════════════════
#  Мелочи
# ════════════════════════════════════════════════════════════════════════════

function Log([string]$msg) {
    $line = (Get-Date -Format 'yyyy-MM-dd HH:mm:ss') + ' ' + $msg
    Write-Host $line
    if ($script:LogFile) { try { [IO.File]::AppendAllText($script:LogFile, $line + "`r`n", $Utf8) } catch {} }
}

function Human([double]$b) {
    $u = 'Б', 'КБ', 'МБ', 'ГБ', 'ТБ'; $i = 0
    while ($b -ge 1024 -and $i -lt 4) { $b /= 1024; $i++ }
    if ($i -eq 0) { return "$([math]::Round($b)) $($u[$i])" }
    return ('{0:0.#} {1}' -f $b, $u[$i])
}

function Now-Iso { (Get-Date).ToString('yyyy-MM-ddTHH:mm:sszzz') }
function To-Time($s) { if (-not $s) { return $null }; try { return [DateTimeOffset]::Parse([string]$s).LocalDateTime } catch { return $null } }
function Fmt-Time($t) { if ($t) { $t.ToString('dd.MM.yyyy HH:mm') } else { '' } }
function Fmt-Id([string]$id) { if ($id -match '^(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})\d{2}$') { "$($matches[3]).$($matches[2]).$($matches[1]) $($matches[4]):$($matches[5])" } else { $id } }

function Dir-Size([string]$path) {
    if (-not (Test-Path -LiteralPath $path)) { return 0 }
    $sum = (Get-ChildItem -LiteralPath $path -Recurse -File -Force -ErrorAction SilentlyContinue | Measure-Object -Property Length -Sum).Sum
    if ($sum) { return [double]$sum } else { return 0 }
}

function Remove-Dir([string]$path) { if (Test-Path -LiteralPath $path) { Remove-Item -LiteralPath $path -Recurse -Force } }

function New-TempFile { [IO.Path]::GetTempFileName() }

# Объект ConvertFrom-Json → упорядоченные словари и массивы
function ConvertTo-Hash($o) {
    if ($null -eq $o) { return $null }
    if ($o -is [System.Management.Automation.PSCustomObject]) {
        $h = [ordered]@{}
        foreach ($p in $o.PSObject.Properties) { $h[$p.Name] = ConvertTo-Hash $p.Value }
        return $h
    }
    if ($o -is [System.Collections.IEnumerable] -and -not ($o -is [string])) {
        $arr = @(); foreach ($x in $o) { $arr += , (ConvertTo-Hash $x) }
        return , $arr
    }
    return $o
}

function Read-Json([string]$path) {
    try { return ConvertTo-Hash ([IO.File]::ReadAllText($path, [Text.Encoding]::UTF8) | ConvertFrom-Json) } catch { return $null }
}

function Write-Json([string]$path, $obj) { [IO.File]::WriteAllText($path, ($obj | ConvertTo-Json -Depth 20), $Utf8) }

# Аргумент для командной строки Windows (правила CommandLineToArgvW)
function Quote-Arg([string]$a) {
    if ($a -eq '') { return '""' }
    if ($a -notmatch '[\s"]') { return $a }
    $r = '"'; $bs = 0
    foreach ($ch in $a.ToCharArray()) {
        if ($ch -eq '\') { $bs++ }
        elseif ($ch -eq '"') { $r += ('\' * (2 * $bs + 1)) + '"'; $bs = 0 }
        else { $r += ('\' * $bs) + $ch; $bs = 0 }
    }
    return $r + ('\' * (2 * $bs)) + '"'
}

# Запуск программы: код выхода, stdout (или счёт строк), stderr. Окна не создаёт.
function Invoke-Exe([string]$exe, [string[]]$argList, [switch]$CountLines) {
    $psi = New-Object System.Diagnostics.ProcessStartInfo
    $psi.FileName = $exe
    $psi.Arguments = (($argList | ForEach-Object { Quote-Arg $_ }) -join ' ')
    $psi.UseShellExecute = $false; $psi.CreateNoWindow = $true
    $psi.RedirectStandardOutput = $true; $psi.RedirectStandardError = $true
    $p = [System.Diagnostics.Process]::Start($psi)
    $errTask = $p.StandardError.ReadToEndAsync()
    $sb = New-Object System.Text.StringBuilder; $lines = 0
    while ($null -ne ($line = $p.StandardOutput.ReadLine())) {
        $lines++
        if (-not $CountLines -and $sb.Length -lt 65536) { [void]$sb.AppendLine($line) }
    }
    $p.WaitForExit()
    return @{ code = $p.ExitCode; out = $sb.ToString(); err = $errTask.Result; lines = $lines }
}

function Need-Tools {
    foreach ($t in $Curl, $Tar) {
        if (-not (Test-Path -LiteralPath $t)) { throw "Нет $t — нужна Windows 10 версии 1803 или новее (или Windows 11)" }
    }
}

# ════════════════════════════════════════════════════════════════════════════
#  Конфиг
# ════════════════════════════════════════════════════════════════════════════

function Load-Config {
    if (-not (Test-Path -LiteralPath $ConfigFile)) { return [ordered]@{ sites = [ordered]@{} } }
    $c = Read-Json $ConfigFile
    if ($null -eq $c) { throw "Не разобрать $ConfigFile — проверьте JSON" }
    if ($null -eq $c['sites']) { $c['sites'] = [ordered]@{} }
    return $c
}

function Save-Config($c) { Write-Json $ConfigFile $c }

# Настройки сайта: умолчания + старый общий блок defaults + блок сайта
function Site-Cfg([string]$name) {
    $c = Load-Config
    if (-not $c.sites.Contains($name)) { throw "Сайт «$name» не найден в настройках. Есть: $(@($c.sites.Keys) -join ', ')" }
    $s = [ordered]@{}
    foreach ($k in $Defaults.Keys) { $s[$k] = $Defaults[$k] }
    if ($c.Contains('defaults') -and $c.defaults) { foreach ($k in $c.defaults.Keys) { $s[$k] = $c.defaults[$k] } }
    foreach ($k in $c.sites[$name].Keys) { $s[$k] = $c.sites[$name][$k] }
    $s.name = $name
    $dest = if ($s.Contains('dest') -and $s.dest) { $s.dest } elseif ($c.Contains('dest') -and $c.dest) { $c.dest } else { Join-Path $Dir 'backups' }
    $s.dir = (Join-Path ($dest -replace '/', '\') $name) + '\'
    if (-not (Test-Path -LiteralPath $s.dir)) { New-Item -ItemType Directory -Path $s.dir -Force | Out-Null }
    $script:LogFile = Join-Path $s.dir 'gtsbackup.log'
    return $s
}

function Site-Names([string]$arg) {
    $c = Load-Config
    if ($arg -eq 'all' -or $arg -eq '') { return @($c.sites.Keys) }
    return @($arg)
}

# ════════════════════════════════════════════════════════════════════════════
#  HTTP (curl.exe). Токен — в файле заголовков, не в командной строке.
# ════════════════════════════════════════════════════════════════════════════

function Curl-Args($s, [string]$headerFile) {
    # --http1.1: за nginx-прокси (proxy_pass на Apache) при HTTP/2 теряется тело ответа
    $a = @('-sS', '--http1.1', '--connect-timeout', '30', '-H', "@$headerFile", '-A', "gtsBackup-client-ps/$Version")
    if ($s.insecure) { $a += '-k' }
    if ($s.cacert) { $a += @('--cacert', [string]$s.cacert) }
    if ($s.ssl_no_revoke) { $a += '--ssl-no-revoke' }
    return , $a
}

function Write-Headers($s) {
    $hf = New-TempFile
    [IO.File]::WriteAllText($hf, "X-Gtsbackup-Token: $($s.token)`r`nExpect:`r`n", [Text.Encoding]::ASCII)
    return $hf
}

function Short-Text([string]$t) { (($t -replace '<[^>]+>', ' ') -replace '\s+', ' ').Trim() | ForEach-Object { if ($_.Length -gt 300) { $_.Substring(0, 300) } else { $_ } } }

# JSON-запрос к эндпоинту. Повторы — на сетевых ошибках и 5xx (create не повторяется).
function Api($s, [string]$action, $post = @{}, $files = @{}, [switch]$Soft) {
    $tries = 0
    while ($true) {
        $tries++
        $hf = Write-Headers $s; $body = New-TempFile
        try {
            $a = Curl-Args $s $hf
            $a += @('-X', 'POST', '-o', $body, '-w', '%{http_code}', '--max-time', [string]$s.timeout)
            if ($files.Count -gt 0) {
                $a += @('-F', "action=$action")
                foreach ($k in $post.Keys) { $a += @('-F', "$k=$($post[$k])") }
                foreach ($k in $files.Keys) { $a += @('-F', "$k=@`"$($files[$k])`";type=application/gzip") }
            } else {
                $a += @('--data-urlencode', "action=$action")
                foreach ($k in $post.Keys) { $a += @('--data-urlencode', "$k=$($post[$k])") }
            }
            $a += [string]$s.url
            $r = Invoke-Exe $Curl $a
            $code = 0; [void][int]::TryParse($r.out.Trim(), [ref]$code)
            $text = if (Test-Path -LiteralPath $body) { [IO.File]::ReadAllText($body, [Text.Encoding]::UTF8) } else { '' }
        } finally { Remove-Item -LiteralPath $hf, $body -Force -ErrorAction SilentlyContinue }

        if ($r.code -ne 0 -or $code -ge 500 -or $code -eq 0) {
            $why = if ($r.code -ne 0) { "curl $($r.code): $($r.err.Trim())" } else { "HTTP ${code}: $(Short-Text $text)" }
            if ($tries -ge $s.retries -or $action -eq 'create') { throw "${action}: $why" }
            Log "[$($s.name)]   ${action}: $why — повтор $tries"
            Start-Sleep -Seconds ([math]::Min(60, 5 * $tries))
            continue
        }
        try { $j = $text | ConvertFrom-Json } catch { throw "${action}: не JSON (HTTP $code): $(Short-Text $text)" }
        if (-not $j.success -and -not $Soft) { throw "${action}: $($j.message) (HTTP $code)" }
        return $j
    }
}

# Скачать в файл. $Resume — докачка (curl -C -) с места обрыва; без неё каждая попытка с нуля.
function Download($s, $params, [string]$file, [bool]$Resume) {
    $tries = 0
    while ($true) {
        $tries++
        if (-not $Resume -and (Test-Path -LiteralPath $file)) { Remove-Item -LiteralPath $file -Force }
        $have = if (Test-Path -LiteralPath $file) { (Get-Item -LiteralPath $file).Length } else { 0 }
        $hf = Write-Headers $s; $hd = New-TempFile
        try {
            $a = Curl-Args $s $hf
            $a += @('-X', 'POST', '-D', $hd, '-o', $file, '-w', '%{http_code}', '--speed-limit', '1', '--speed-time', '300')
            if ($have -gt 0) { $a += @('-C', '-', '-f') }   # при докачке ошибку сервера не дописывать в файл
            foreach ($k in $params.Keys) { $a += @('--data-urlencode', "$k=$($params[$k])") }
            $a += [string]$s.url
            $r = Invoke-Exe $Curl $a
            $code = 0; [void][int]::TryParse($r.out.Trim(), [ref]$code)
            $headers = @{}
            if (Test-Path -LiteralPath $hd) {
                foreach ($l in [IO.File]::ReadAllLines($hd)) {
                    if ($l -match '^HTTP/') { $headers = @{} }
                    elseif ($l -match '^([^:]+):\s*(.*)$') { $headers[$matches[1].ToLower()] = $matches[2].Trim() }
                }
            }
        } finally { Remove-Item -LiteralPath $hf, $hd -Force -ErrorAction SilentlyContinue }

        $err = ''
        if ($r.code -eq 0 -and ($code -eq 200 -or $code -eq 206)) {
            $got = (Get-Item -LiteralPath $file).Length
            if (-not $headers.ContainsKey('x-gtsbackup-size') -or [int64]$headers['x-gtsbackup-size'] -eq $got) {
                return @{ size = $got; headers = $headers }
            }
            $err = "получено $got из $($headers['x-gtsbackup-size']) байт"
        } elseif ($r.code -eq 0 -and $code -ge 400) {
            $msg = ''
            if (Test-Path -LiteralPath $file) {
                $t = [IO.File]::ReadAllText($file, [Text.Encoding]::UTF8)
                try { $msg = ($t | ConvertFrom-Json).message } catch { $msg = Short-Text $t }
                Remove-Item -LiteralPath $file -Force
            }
            if ($code -lt 500) { throw "$($params.action): HTTP $code $msg" }
            $err = "HTTP $code $msg"
        } else {
            $err = "curl $($r.code): $($r.err.Trim())"
            if ($have -gt 0 -and $r.code -in 22, 33, 36) { Remove-Item -LiteralPath $file -Force -ErrorAction SilentlyContinue } # докачка не вышла — заново
        }
        if (-not $Resume -and (Test-Path -LiteralPath $file)) { Remove-Item -LiteralPath $file -Force }
        if ($tries -ge $s.retries) { throw "$($params.action): $err" }
        Log "[$($s.name)]   скачивание: $err — повтор $tries$(if ($Resume) { ' (докачка)' })"
        Start-Sleep -Seconds ([math]::Min(60, 5 * $tries))
    }
}

# ════════════════════════════════════════════════════════════════════════════
#  gzip и tar
# ════════════════════════════════════════════════════════════════════════════

# Последние ~4 КБ распакованного gz (чтение до конца — заодно проверка целостности)
function Gz-Tail([string]$file) {
    $fs = [IO.File]::OpenRead($file)
    try {
        $gz = New-Object System.IO.Compression.GZipStream($fs, [IO.Compression.CompressionMode]::Decompress)
        $buf = New-Object byte[] 1048576; $tail = New-Object byte[] 0
        while (($n = $gz.Read($buf, 0, $buf.Length)) -gt 0) {
            $keep = [math]::Min(4096, $tail.Length + $n)
            $new = New-Object byte[] $keep
            $fromTail = $keep - [math]::Min($n, $keep)
            if ($fromTail -gt 0) { [Array]::Copy($tail, $tail.Length - $fromTail, $new, 0, $fromTail) }
            [Array]::Copy($buf, $n - ($keep - $fromTail), $new, $fromTail, $keep - $fromTail)
            $tail = $new
        }
        return [Text.Encoding]::UTF8.GetString($tail)
    } finally { $fs.Dispose() }
}

# meta манифеста (последняя строка {"meta": …})
function Manifest-Meta([string]$file) {
    $fs = [IO.File]::OpenRead($file)
    try {
        $gz = New-Object System.IO.Compression.GZipStream($fs, [IO.Compression.CompressionMode]::Decompress)
        $sr = New-Object System.IO.StreamReader($gz, [Text.Encoding]::UTF8)
        $meta = $null
        while ($null -ne ($line = $sr.ReadLine())) {
            if ($line.StartsWith('{')) { $j = $line | ConvertFrom-Json; if ($j.meta) { $meta = ConvertTo-Hash $j.meta } }
        }
        return $meta
    } finally { $fs.Dispose() }
}

# Число записей в tar.gz (tar.exe читает архив до конца — заодно проверка)
function Tar-Count([string]$file) {
    $r = Invoke-Exe $Tar @('-tzf', $file) -CountLines
    if ($r.code -ne 0) { throw "$file — архив повреждён: $($r.err.Trim())" }
    return $r.lines
}

# ════════════════════════════════════════════════════════════════════════════
#  Бекап
# ════════════════════════════════════════════════════════════════════════════

function Roots-Text($d) {
    if ($d -and $d.Contains('roots') -and $d.roots) {
        $p = @()
        foreach ($r in $d.roots) {
            if ($r.prefix -eq '') { $p += "корень $($r.path)" } else { $p += "core $($r.path) (в архиве $($r.prefix))" }
        }
        return $p -join ' + '
    }
    return 'корень сайта'
}

function Cmd-Ping([string]$name) {
    $s = Site-Cfg $name
    $d = ConvertTo-Hash (Api $s 'ping').data
    Write-Host "OK: $($d.site_name) ($($d.http_host)), gtsBackup $($d.version), PHP $($d.php), токен «$($d.token)»"
    Write-Host "   с сайта бекапится: $(Roots-Text $d)"
    Write-Host "   база: $($d.db_method), шаг упаковки $($d.step_time) с"
    if ([int]$d.protocol -ne $Protocol) { Write-Host "⚠ Версия протокола сайта $($d.protocol), клиента $Protocol" }
    return 0
}

<#
  Хранение (папка сайта <dest>\<сайт>\):
    20261112-033000\               цепочка — имя = время полного бекапа
        full\                      полный: files.tar.gz, manifest.jsonl.gz, db.sql.gz, info.json
        diff-20261113-033000\      дифференциальный от этого полного
    db-only\20261115-120000\       «только база» (--no-files)
    .part-…\                       собирается сейчас (или упал — удалится при следующем запуске)
#>
function Backup-Site([string]$name, [string]$mode, $opts) {
    $s = Site-Cfg $name
    $withFiles = [bool]$s.files -and -not $opts.Contains('no_files')
    $withDb = [bool]$s.db -and -not $opts.Contains('no_db')
    if (-not $withFiles -and -not $withDb) { throw 'Нечего бекапить: выключены и файлы, и база' }
    if (-not $mode) { $mode = $s.mode }
    if ($mode -notin 'auto', 'full', 'diff') { throw 'Режим: auto, full или diff' }

    Cleanup-Parts $s
    $t0 = Get-Date
    $ping = ConvertTo-Hash (Api $s 'ping').data
    Log "[$name] сайт $($ping.http_host), токен «$($ping.token)»"

    $type = 'db'; $chain = $null
    if ($withFiles) {
        if ($mode -eq 'auto') {
            $m = Choose-Mode $s; $type = $m.type; $chain = $m.chain
            Log "[$name] режим auto: $($m.why)"
        } else {
            $type = $mode; $chain = Last-Chain $s
            if ($type -eq 'diff' -and -not $chain) { Log "[$name] полного бекапа нет — делаю полный"; $type = 'full' }
        }
    }

    $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
    $part = Join-Path $s.dir ".part-$stamp"
    New-Item -ItemType Directory -Path $part -Force | Out-Null
    $info = [ordered]@{
        type = $type; id = $stamp; created = (Now-Iso); site = $ping.http_host; roots = $ping.roots
        client = "ps $Version"; files = $null; db = $null
    }
    try {
        if ($withFiles) {
            Log "[$name] файлы: $(if ($type -eq 'full') { 'полный' } else { "дифференциальный от полного $($chain.id)" })"
            $info.files = Backup-Files $s $type $(if ($type -eq 'diff') { $chain.full } else { $null }) $part
            if ($type -eq 'diff') { $info.base = $chain.id }
        }
        if ($withDb) {
            Log "[$name] база: дамп…"
            $info.db = Backup-Db $s $part
        }
    } catch {
        $info.error = $_.Exception.Message
        Write-Json (Join-Path $part 'info.json') $info
        throw
    }
    $info.status = 'ok'
    $info.seconds = [math]::Round(((Get-Date) - $t0).TotalSeconds)
    Write-Json (Join-Path $part 'info.json') $info

    if ($type -eq 'full') {
        $chainDir = Join-Path $s.dir $stamp
        New-Item -ItemType Directory -Path $chainDir -Force | Out-Null
        $dest = Join-Path $chainDir 'full'
    } elseif ($type -eq 'diff') {
        $dest = Join-Path (Join-Path $s.dir $chain.id) "diff-$stamp"
    } else {
        $dbDir = Join-Path $s.dir 'db-only'
        if (-not (Test-Path -LiteralPath $dbDir)) { New-Item -ItemType Directory -Path $dbDir -Force | Out-Null }
        $dest = Join-Path $dbDir $stamp
    }
    Move-Item -LiteralPath $part -Destination $dest
    Log "[$name] готово: $($dest.Substring($s.dir.Length)), $(Human (Dir-Size $dest)), $($info.seconds) с"
    Rotate $s
}

# auto: полный — если полного нет, он старше full_every_days или от него уже max_diff дифференциальных
function Choose-Mode($s) {
    $chain = Last-Chain $s
    if (-not $chain) { return @{ type = 'full'; chain = $null; why = 'полного бекапа ещё нет — полный' } }
    $created = To-Time $chain.full.info.created
    $age = if ($created) { ((Get-Date) - $created).TotalDays } else { 0 }
    if ($s.full_every_days -gt 0 -and $age -ge $s.full_every_days) {
        return @{ type = 'full'; chain = $chain; why = "последнему полному $([math]::Floor($age)) дн. (full_every_days = $($s.full_every_days)) — полный" }
    }
    $n = @($chain.diffs).Count
    if ($s.max_diff -ge 0 -and $n -ge $s.max_diff) {
        return @{ type = 'full'; chain = $chain; why = "от полного $($chain.id) уже $n дифф. (max_diff = $($s.max_diff)) — полный" }
    }
    return @{ type = 'diff'; chain = $chain; why = "дифференциальный $($n + 1) из $($s.max_diff) от полного $($chain.id)" }
}

function Backup-Files($s, [string]$type, $base, [string]$part) {
    $post = [ordered]@{ mode = $type }
    if ($type -eq 'diff') { $post.base_job = $base.info.files.job }
    $r = Api $s 'create' $post @{} -Soft
    if (-not $r.success -and $type -eq 'diff' -and $r.data.code -eq 'base_missing') {
        Log "[$($s.name)] сервер не помнит базу — отправляю манифест полного бекапа"
        $r = Api $s 'create' $post @{ base = (Join-Path $base.path 'manifest.jsonl.gz') } -Soft
    }
    if (-not $r.success) { throw "create: $($r.message)" }
    $d = $r.data; $job = $d.job
    Log "[$($s.name)] задание ${job}: к архивации $($d.files_total) файлов, $(Human $d.bytes_total) (на сайте $($d.files_site)$(if ($type -eq 'diff') { ", удалено $($d.deleted)" }))"

    try {
        $last = [datetime]::MinValue
        do {
            $p = (Api $s 'step' @{ job = $job }).data
            if (((Get-Date) - $last).TotalSeconds -ge 10 -or $p.done) {
                $pct = if ($p.done) { 100 } elseif ($p.bytes_total -gt 0) { [math]::Min(99, [math]::Floor($p.bytes_done * 100 / $p.bytes_total)) } else { 0 }
                Log "[$($s.name)]   $pct% — $($p.files_done)/$($p.files_total) файлов, архив $(Human $p.archive_size)"
                $last = Get-Date
            }
            foreach ($w in @($p.step_warnings)) { if ($w) { Log "[$($s.name)]   ⚠ $w" } }
        } while (-not $p.done)

        [void](Download $s ([ordered]@{ action = 'download'; job = $job; what = 'manifest' }) (Join-Path $part 'manifest.jsonl.gz') $true)
        $dl = Download $s ([ordered]@{ action = 'download'; job = $job; what = 'files' }) (Join-Path $part 'files.tar.gz') $true
        Log "[$($s.name)] архив скачан: $(Human $dl.size)"

        $meta = Manifest-Meta (Join-Path $part 'manifest.jsonl.gz')
        if (-not $meta) { throw 'Манифест без meta' }
        if ($s.verify) {
            $n = Tar-Count (Join-Path $part 'files.tar.gz')
            if ($n -ne [int]$p.files_done) { throw "Проверка архива: в архиве $n записей, сервер записал $($p.files_done)" }
            Log "[$($s.name)] архив проверен: $n записей"
        }
        [void](Api $s 'finish' @{ job = $job })
    } catch {
        try { [void](Api $s 'cancel' @{ job = $job } @{} -Soft) } catch {}
        throw
    }
    return [ordered]@{
        job = $job; base_job = $(if ($type -eq 'diff') { $base.info.files.job } else { '' })
        count = [int]$p.files_done; bytes = [int64]$p.bytes_done; archive = [int64]$p.archive_size
        files_site = [int]$meta.files_total; deleted = @($meta.deleted).Count; warnings = [int]$p.warnings
    }
}

function Backup-Db($s, [string]$part) {
    $file = Join-Path $part 'db.sql.gz'
    $tries = 0
    while ($true) {
        $tries++
        try { $dl = Download $s ([ordered]@{ action = 'db' }) $file $false; break }
        catch {
            if ($tries -ge [math]::Min(3, $s.retries)) { throw }
            Log "[$($s.name)]   обрыв дампа ($($_.Exception.Message)), повтор $tries…"
            Start-Sleep -Seconds (5 * $tries)
        }
    }
    $tail = Gz-Tail $file
    if ($tail -notmatch '-- gtsBackup-end: ok') {
        $why = if ($tail -match '-- gtsBackup-end: (.*)') { $matches[1] } else { 'нет строки окончания (обрыв?)' }
        throw "Дамп базы неполный: $why"
    }
    $method = if ($dl.headers.ContainsKey('x-gtsbackup-db-method')) { $dl.headers['x-gtsbackup-db-method'] } else { '' }
    Log "[$($s.name)] база: $(Human $dl.size) ($method), дамп проверен"
    return [ordered]@{ bytes = [int64]$dl.size; method = $method }
}

function Cleanup-Parts($s) {
    foreach ($d in @(Get-ChildItem -LiteralPath $s.dir -Directory -Force -Filter '.part-*' -ErrorAction SilentlyContinue)) {
        Log "[$($s.name)] удаляю незаконченный $($d.Name)"
        Remove-Dir $d.FullName
    }
}

# ════════════════════════════════════════════════════════════════════════════
#  Цепочки, список, ротация
# ════════════════════════════════════════════════════════════════════════════

function Read-Info([string]$dir) {
    $f = Join-Path $dir 'info.json'
    if (-not (Test-Path -LiteralPath $f)) { return $null }
    $i = Read-Json $f
    if ($i -and $i.status -eq 'ok') { return $i } else { return $null }
}

# Цепочки, старые первыми: @{ id; path; full; diffs }. Бекап: @{ id; type; path; info }
function Get-Chains($s) {
    $out = @()
    foreach ($c in @(Get-ChildItem -LiteralPath $s.dir -Directory -ErrorAction SilentlyContinue | Where-Object { $_.Name -match '^\d{8}-\d{6}$' } | Sort-Object Name)) {
        $fi = Read-Info (Join-Path $c.FullName 'full')
        $full = if ($fi) { @{ id = $c.Name; type = 'full'; path = (Join-Path $c.FullName 'full') + '\'; info = $fi } } else { $null }
        $diffs = @()
        foreach ($d in @(Get-ChildItem -LiteralPath $c.FullName -Directory -Filter 'diff-*' | Sort-Object Name)) {
            $did = $d.Name.Substring(5)
            if ($did -notmatch '^\d{8}-\d{6}$') { continue }
            $di = Read-Info $d.FullName
            if ($di) { $diffs += , @{ id = $did; type = 'diff'; path = $d.FullName + '\'; info = $di; full = $full } }
        }
        $out += , @{ id = $c.Name; path = $c.FullName; full = $full; diffs = $diffs }
    }
    return , $out
}

function Get-DbOnly($s) {
    $out = @()
    $dbDir = Join-Path $s.dir 'db-only'
    if (Test-Path -LiteralPath $dbDir) {
        foreach ($d in @(Get-ChildItem -LiteralPath $dbDir -Directory | Where-Object { $_.Name -match '^\d{8}-\d{6}$' } | Sort-Object Name)) {
            $i = Read-Info $d.FullName
            if ($i) { $out += , @{ id = $d.Name; type = 'db'; path = $d.FullName + '\'; info = $i } }
        }
    }
    return , $out
}

function Last-Chain($s) {
    $last = $null
    foreach ($c in (Get-Chains $s)) { if ($c.full) { $last = $c } }
    return $last
}

function Last-Success($s) {
    $t = $null
    foreach ($c in (Get-Chains $s)) {
        $all = @(); if ($c.full) { $all += , $c.full }; $all += $c.diffs
        foreach ($b in $all) { $x = To-Time $b.info.created; if ($x -and (-not $t -or $x -gt $t)) { $t = $x } }
    }
    foreach ($b in (Get-DbOnly $s)) { $x = To-Time $b.info.created; if ($x -and (-not $t -or $x -gt $t)) { $t = $x } }
    return $t
}

function Describe($i) {
    $p = @()
    if ($i.files) {
        if ($i.type -eq 'diff') { $p += "изменено $($i.files['count']), удалено $($i.files.deleted)" } else { $p += "файлов $($i.files['count'])" }
    }
    if ($i.db) { $p += "база $(Human $i.db.bytes)" }
    return $p -join ', '
}

function Cmd-List([string]$arg) {
    foreach ($name in (Site-Names $arg)) {
        $s = Site-Cfg $name
        $chains = @((Get-Chains $s) | Where-Object { $_.full })
        $dbs = Get-DbOnly $s
        Write-Host ""
        Write-Host "$name — $($s.dir)  (всего $(Human (Dir-Size $s.dir)))"
        $withRoots = $null
        foreach ($c in $chains) { if ($c.full.info.roots) { $withRoots = $c.full.info }; foreach ($d in $c.diffs) { if ($d.info.roots) { $withRoots = $d.info } } }
        if ($withRoots) { Write-Host "  с сайта бекапится: $(Roots-Text $withRoots)" }
        $ls = Last-Success $s
        $ld = if ($ls) { [math]::Floor(((Get-Date) - $ls).TotalDays) } else { $null }
        $warn = if ($s.alert_days -gt 0 -and (-not $ls -or $ld -ge $s.alert_days)) { "  ⚠ ПРОПУСК — больше alert_days = $($s.alert_days)" } else { '' }
        Write-Host "  последний удачный бекап: $(if ($ls) { "$(Fmt-Time $ls) ($ld дн. назад)" } else { 'нет' })$warn"
        Write-Host "  хранится цепочек: $($chains.Count) из $($s.keep_full) (keep_full), в цепочке до $($s.max_diff) дифф. (max_diff), полный не реже раза в $($s.full_every_days) дн."
        Write-Host "  следующий запуск auto: $((Choose-Mode $s).why)"
        Write-Host ""
        foreach ($c in $chains) {
            Write-Host ("  {0}  ПОЛНЫЙ   {1}  {2,-10}{3}" -f $c.id, (Fmt-Id $c.id), (Human (Dir-Size $c.full.path)), (Describe $c.full.info))
            for ($i = 0; $i -lt $c.diffs.Count; $i++) {
                $d = $c.diffs[$i]
                $mark = if ($i -eq $c.diffs.Count - 1) { '└' } else { '├' }
                Write-Host ("    {0} {1}  дифф   {2}  {3,-10}{4}" -f $mark, $d.id, (Fmt-Id $d.id), (Human (Dir-Size $d.path)), (Describe $d.info))
            }
        }
        if ($chains.Count -eq 0) { Write-Host '  бекапов файлов нет' }
        if ($dbs.Count -gt 0) {
            Write-Host ""
            Write-Host "  только база (db-only), хранится $($dbs.Count) из $($s.keep_db_only):"
            foreach ($d in $dbs) { Write-Host "    $($d.id)  $(Fmt-Id $d.id)  $(Human (Dir-Size $d.path))" }
        }
    }
    Write-Host ""
    Write-Host 'Восстановить: gtsbackup restore <сайт> <номер> <пустая папка> — для дифф. сам возьмёт его полный.'
    return 0
}

# Ротация: keep_full последних цепочек целиком, папки без целого полного — тоже; db-only — keep_db_only
function Rotate($s) {
    $chains = Get-Chains $s
    $good = @($chains | Where-Object { $_.full })
    $keepN = [math]::Max(1, [int]$s.keep_full)
    $keep = @($good | Select-Object -Last $keepN | ForEach-Object { $_.id })
    foreach ($c in $chains) {
        if ($keep -contains $c.id) { continue }
        Log "[$($s.name)] ротация: удаляю цепочку $($c.id) (полный + $($c.diffs.Count) дифф.)"
        Remove-Dir $c.path
    }
    $dbs = Get-DbOnly $s
    $extra = $dbs.Count - [math]::Max(0, [int]$s.keep_db_only)
    for ($i = 0; $i -lt $extra; $i++) {
        Log "[$($s.name)] ротация: удаляю db-only\$($dbs[$i].id)"
        Remove-Dir $dbs[$i].path
    }
}

function Find-Backup($s, [string]$id) {
    foreach ($c in (Get-Chains $s)) {
        if ($c.full -and $c.id -eq $id) { return $c.full }
        foreach ($d in $c.diffs) { if ($d.id -eq $id) { return $d } }
    }
    foreach ($d in (Get-DbOnly $s)) { if ($d.id -eq $id) { return $d } }
    throw "Бекап $id не найден (gtsbackup list $($s.name))"
}

# ════════════════════════════════════════════════════════════════════════════
#  Проверка и восстановление
# ════════════════════════════════════════════════════════════════════════════

function Cmd-Verify($a) {
    if ($a.Count -lt 2) { throw 'gtsbackup verify <имя> <номер>' }
    $s = Site-Cfg $a[0]; $b = Find-Backup $s $a[1]; $ok = $true
    $tgz = Join-Path $b.path 'files.tar.gz'
    if (Test-Path -LiteralPath $tgz) {
        $n = Tar-Count $tgz; $want = [int]$b.info.files['count']
        Write-Host "файлы: $n из $want $(if ($n -eq $want) { '— OK' } else { '— НЕ СХОДИТСЯ' })"
        if ($n -ne $want) { $ok = $false }
    }
    $db = Join-Path $b.path 'db.sql.gz'
    if (Test-Path -LiteralPath $db) {
        $dbOk = (Gz-Tail $db) -match '-- gtsBackup-end: ok'
        Write-Host "база: $(if ($dbOk) { 'OK' } else { 'НЕПОЛНЫЙ ДАМП' })"
        if (-not $dbOk) { $ok = $false }
    }
    if ($ok) { return 0 } else { return 1 }
}

# В пустую папку: полный, поверх — дифференциальный, удалённые на сайте файлы убираются, дамп — рядом
function Cmd-Restore($a) {
    if ($a.Count -lt 3) { throw 'gtsbackup restore <имя> <номер> <папка>' }
    $s = Site-Cfg $a[0]; $b = Find-Backup $s $a[1]
    $dir = $a[2]
    if ((Test-Path -LiteralPath $dir) -and @(Get-ChildItem -LiteralPath $dir -Force).Count -gt 0) { throw "Папка $dir не пустая — разворачиваю только в пустую" }
    New-Item -ItemType Directory -Path $dir -Force | Out-Null
    $dir = (Resolve-Path -LiteralPath $dir).Path

    if ($b.info.files) {
        $chain = if ($b.type -eq 'diff') { @($b.full, $b) } else { @($b) }
        foreach ($c in $chain) {
            Write-Host "разворачиваю $(if ($c.type -eq 'full') { 'полный' } else { 'дифференциальный' }) $($c.id)…"
            $r = Invoke-Exe $Tar @('-xzf', (Join-Path $c.path 'files.tar.gz'), '-C', $dir)
            if ($r.code -ne 0) { throw "tar: $($r.err.Trim())" }
        }
        if ($b.type -eq 'diff') {
            $meta = Manifest-Meta (Join-Path $b.path 'manifest.jsonl.gz')
            $n = 0
            foreach ($rel in @($meta.deleted)) {
                if (-not $rel -or $rel -match '(^|/)\.\.(/|$)' -or $rel -match '^[/\\]|^[a-zA-Z]:') { continue }
                $p = Join-Path $dir ($rel.TrimEnd('/') -replace '/', '\')
                if ($rel.EndsWith('/')) { if ((Test-Path -LiteralPath $p) -and @(Get-ChildItem -LiteralPath $p -Force).Count -eq 0) { Remove-Item -LiteralPath $p } }
                elseif (Test-Path -LiteralPath $p -PathType Leaf) { Remove-Item -LiteralPath $p -Force; $n++ }
            }
            Write-Host "  удалено (как на сайте): $n"
        }
    }
    $db = Join-Path $b.path 'db.sql.gz'
    if (Test-Path -LiteralPath $db) {
        Copy-Item -LiteralPath $db -Destination (Join-Path $dir '_db.sql.gz')
        Write-Host "дамп базы: $(Join-Path $dir '_db.sql.gz')"
    }
    Write-Host "готово: $dir"
    return 0
}

# ════════════════════════════════════════════════════════════════════════════
#  Планировщик: задача от текущего пользователя, окно-предупреждение
# ════════════════════════════════════════════════════════════════════════════

function Task-Exists { [bool](Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) }

# VBScript: показать и удалить gtsbackup-alert.txt (MsgBox поверх всех окон)
function Alert-Vbs {
    $p = (Join-Path $Dir 'gtsbackup-alert.txt') -replace '"', '""'
    return "Set fso = CreateObject(""Scripting.FileSystemObject"")`r`n" +
        "If fso.FileExists(""$p"") Then`r`n" +
        "  Set f = fso.OpenTextFile(""$p"", 1, False, -1)`r`n" +
        "  t = f.ReadAll`r`n" +
        "  f.Close`r`n" +
        "  fso.DeleteFile ""$p""`r`n" +
        "  MsgBox t, vbExclamation + vbSystemModal, ""gtsBackup""`r`n" +
        "End If`r`n"
}

function Install-Task([string]$time) {
    if ($time -notmatch '^(\d{1,2}):(\d{2})$' -or [int]$matches[1] -gt 23 -or [int]$matches[2] -gt 59) { throw "Время — ЧЧ:ММ, а не «$time»" }
    $time = '{0:00}:{1:00}' -f [int]$matches[1], [int]$matches[2]
    $user = if ($env:USERDOMAIN) { "$env:USERDOMAIN\$env:USERNAME" } else { $env:USERNAME }

    # wscript запускает клиент без окна и ждёт его; окно-предупреждение показывает сам
    # (Планировщик запускает его видимым, а окна процессов из скрытой цепочки Windows не показывает)
    $vbs = Join-Path $Dir 'gtsbackup-task.vbs'
    $cmdPath = (Join-Path $Dir 'gtsbackup.cmd') -replace '"', '""'
    $text = "' gtsBackup: запуск задачи Планировщика без окна консоли (создан командой task)`r`n" +
        "Set sh = CreateObject(""WScript.Shell"")`r`n" +
        "sh.Environment(""PROCESS"")(""GTSBACKUP_TASK"") = ""1""`r`n" +
        "rc = sh.Run(""""""$cmdPath"""" scheduled"", 0, True)`r`n" +
        (Alert-Vbs) +
        "WScript.Quit rc`r`n"
    [IO.File]::WriteAllText($vbs, $text, [Text.Encoding]::Unicode)

    $action = New-ScheduledTaskAction -Execute 'wscript.exe' -Argument ('"' + $vbs + '"') -WorkingDirectory $Dir
    $daily = New-ScheduledTaskTrigger -Daily -At $time
    $logon = New-ScheduledTaskTrigger -AtLogOn -User $user
    $logon.Delay = 'PT5M'
    $set = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
        -ExecutionTimeLimit (New-TimeSpan -Hours 12) -MultipleInstances IgnoreNew
    $pr = New-ScheduledTaskPrincipal -UserId $user -LogonType Interactive -RunLevel Limited
    Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $daily, $logon -Settings $set -Principal $pr `
        -Description "gtsBackup: бекап сайтов ($Dir)" -Force | Out-Null

    Write-Host ""
    Write-Host "Задача «$TaskName» в Планировщике (от пользователя $user):"
    Write-Host "  каждый день в $time и при входе в Windows; если компьютер был выключен — при первой возможности."
    Write-Host "  Бекапит все сайты из настроек, которым пора (удачный бекап старше min_interval_hours),"
    Write-Host "  и показывает окно, если у сайта нет удачного бекапа alert_days дней."
    Write-Host "  Запустить сейчас: schtasks /Run /TN $TaskName    Убрать: gtsbackup.cmd task remove"
    return 0
}

function Cmd-Task($a) {
    $x = if ($a.Count) { [string]$a[0] } else { '' }
    if ($x -in 'remove', 'delete') {
        if (Task-Exists) { Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false; Write-Host "Задача «$TaskName» удалена" } else { Write-Host "Задачи «$TaskName» нет" }
        return 0
    }
    if ($x) {
        $c = Load-Config; $c.task_time = $x; Save-Config $c
        return Install-Task $x
    }
    $t = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    if (-not $t) { Write-Host "Задачи «$TaskName» нет. Поставить: gtsbackup.cmd task 03:30"; return 1 }
    $ti = Get-ScheduledTaskInfo -TaskName $TaskName
    Write-Host "Задача «$TaskName»: $($t.State), от $($t.Principal.UserId)"
    foreach ($tr in $t.Triggers) {
        if ($tr.CimClass.CimClassName -match 'Daily') { Write-Host "  каждый день в $(([datetime]$tr.StartBoundary).ToString('HH:mm'))" }
        elseif ($tr.CimClass.CimClassName -match 'Logon') { Write-Host "  при входе в Windows (через $($tr.Delay -replace 'PT', '' -replace 'M', ' мин'))" }
    }
    Write-Host "  последний запуск: $(if ($ti.LastRunTime.Year -gt 2000) { $ti.LastRunTime.ToString('dd.MM.yyyy HH:mm') + ", результат $($ti.LastTaskResult)" } else { 'не было' })"
    Write-Host "  следующий: $(if ($ti.NextRunTime) { $ti.NextRunTime.ToString('dd.MM.yyyy HH:mm') } else { '—' })"
    return 0
}

# Окно-предупреждение: в задаче — покажет gtsbackup-task.vbs после работы клиента, из консоли — сразу
function Notify([string]$text) {
    [Console]::Error.WriteLine($text)
    if ($text.Length -gt 1500) { $text = $text.Substring(0, 1500) }
    [IO.File]::WriteAllText((Join-Path $Dir 'gtsbackup-alert.txt'), ($text -replace "`r?`n", "`r`n"), [Text.Encoding]::Unicode)
    if ($env:GTSBACKUP_TASK) { return }
    $vbs = Join-Path $Dir 'gtsbackup-alert.vbs'
    [IO.File]::WriteAllText($vbs, (Alert-Vbs), [Text.Encoding]::Unicode)
    Start-Process -FilePath 'wscript.exe' -ArgumentList @('//nologo', ('"' + $vbs + '"'))
}

# Сайты без удачного бекапа alert_days дней
function Stale-Sites {
    $out = @()
    foreach ($name in (Site-Names 'all')) {
        $s = Site-Cfg $name
        if ([int]$s.alert_days -le 0) { continue }
        $last = Last-Success $s
        $since = if ($last) { $last } else { To-Time $s.added }
        if (-not $since) { continue }
        $days = [math]::Floor(((Get-Date) - $since).TotalDays)
        if ($days -lt $s.alert_days) { continue }
        if ($last) { $out += "• ${name}: последний удачный бекап $(Fmt-Time $last) — $days дн. назад" }
        else { $out += "• ${name}: ни одного удачного бекапа за $days дн. с подключения" }
        Log "[$name] ⚠ нет удачного бекапа $days дн. (alert_days = $($s.alert_days))"
    }
    return , $out
}

# То, что запускает задача: по времени и при входе в Windows
function Cmd-Scheduled {
    $fail = 0
    foreach ($name in (Site-Names 'all')) {
        try {
            $s = Site-Cfg $name
            $last = Last-Success $s
            if ($last -and ((Get-Date) - $last).TotalHours -lt $s.min_interval_hours) {
                Log "[$name] плановый запуск: удачный бекап $(Fmt-Time $last) — свежее $($s.min_interval_hours) ч, пропускаю"
                continue
            }
            Backup-Site $name 'auto' @{}
        } catch {
            Log "[$name] ОШИБКА: $($_.Exception.Message)"
            $fail++
        }
    }
    $stale = Stale-Sites
    if ($stale.Count -gt 0) {
        Notify ("Бекапы сайтов не делаются:`n`n" + ($stale -join "`n") + "`n`nЛог: <папка бекапов>\<сайт>\gtsbackup.log`nПроверить: gtsbackup.cmd check")
    }
    if ($fail -or $stale.Count) { return 1 } else { return 0 }
}

function Cmd-Check($opts) {
    $bad = 0
    foreach ($name in (Site-Names 'all')) {
        $s = Site-Cfg $name
        $last = Last-Success $s
        $days = if ($last) { [math]::Floor(((Get-Date) - $last).TotalDays) } else { $null }
        $ok = $last -and ($s.alert_days -le 0 -or $days -lt $s.alert_days)
        if (-not $ok) { $bad++ }
        $state = if ($last) { "последний удачный $(Fmt-Time $last) ($days дн.)" } else { 'удачных бекапов нет' }
        Write-Host ("{0,-24}{1}{2}" -f $name, $state, $(if ($ok) { '  — OK' } else { "  — ПРОПУСК (alert_days = $($s.alert_days))" }))
    }
    if (Task-Exists) { Write-Host "задача «$TaskName»: есть" }
    else { Write-Host "задача «$TaskName»: НЕТ — бекапы сами не делаются (gtsbackup.cmd task 03:30)"; $bad++ }
    if ($bad -and $opts.Contains('popup')) {
        $stale = Stale-Sites
        Notify ("Бекапы сайтов не делаются:`n`n" + $(if ($stale.Count) { $stale -join "`n" } else { 'нет задачи в Планировщике' }))
    }
    if ($bad) { return 1 } else { return 0 }
}

# ════════════════════════════════════════════════════════════════════════════
#  add, sites, backup, rotate, help
# ════════════════════════════════════════════════════════════════════════════

function Cmd-Add($a, $opts) {
    if ($a.Count -lt 3) { throw 'gtsbackup add <имя> <адрес> <токен>' }
    $name = [string]$a[0]; $url = [string]$a[1]; $token = [string]$a[2]
    if ($name -notmatch '^[a-zA-Z0-9_.-]+$') { throw 'Имя — латиница, цифры, точка, дефис, подчёркивание' }
    if ($url -notmatch '^https?://') { $url = 'https://' + $url }
    if ($url -notmatch '\.php$') { $url = $url.TrimEnd('/') + '/assets/components/gtsbackup/action.php' }

    $c = Load-Config
    $legacy = if ($c.Contains('defaults') -and $c.defaults) { $c.defaults } else { @{} }
    $site = if ($c.sites.Contains($name)) { $c.sites[$name] } else { [ordered]@{ added = (Now-Iso) } }
    $site.url = $url; $site.token = $token
    if ($opts.Contains('dest') -and $opts.dest -isnot [bool]) { $site.dest = $opts.dest }
    if (-not $site.Contains('dest') -or -not $site.dest) { $site.dest = if ($c.Contains('dest') -and $c.dest) { $c.dest } else { Join-Path $Dir 'backups' } }
    # все настройки сайта — явно в его блоке
    foreach ($k in $Defaults.Keys) {
        $v = $Defaults[$k]
        if ($opts.Contains($k) -and $opts[$k] -isnot [bool]) {
            $site[$k] = if ($v -is [bool]) { $opts[$k] -in '1', 'true', 'yes' } elseif ($v -is [int]) { [int]$opts[$k] } else { [string]$opts[$k] }
        } elseif (-not $site.Contains($k)) {
            $site[$k] = if ($legacy.Contains($k)) { $legacy[$k] } else { $v }
        }
    }
    $c.sites[$name] = $site
    $c.Remove('defaults'); $c.Remove('dest')
    $time = if ($opts.Contains('time') -and $opts.time -isnot [bool]) { $opts.time } elseif ($c.Contains('task_time') -and $c.task_time) { $c.task_time } else { $TaskTimeDefault }
    $c.task_time = $time
    Save-Config $c
    Write-Host "Сайт «$name» записан в $ConfigFile (его настройки — в блоке sites.$name)"
    if ($url -match '^http://') { Write-Host '⚠ HTTP: токен и бекап идут открытым текстом. Используйте только в своей сети.' }
    [void](Cmd-Ping $name)
    $s = Site-Cfg $name
    Write-Host "Бекапы сохраняются в: $($s.dir)"
    Write-Host "Хранится: $($s.keep_full) цепочки (полный + до $($s.max_diff) дифф.), полный не реже раза в $($s.full_every_days) дн.; окно-предупреждение, если нет удачного бекапа $($s.alert_days) дн."
    if (-not $opts.Contains('no_task') -and ($opts.Contains('time') -or -not (Task-Exists))) { [void](Install-Task $time) }
    elseif (Task-Exists) { Write-Host "Задача «$TaskName» уже стоит — бекапит и этот сайт (gtsbackup task — подробности)." }
    if ($opts.Contains('now')) {
        Write-Host ""; Write-Host 'Первый бекап…'
        Backup-Site $name 'auto' $opts
    } else {
        Write-Host ""; Write-Host "Снять первый бекап сейчас: gtsbackup.cmd backup $name"
    }
    return 0
}

function Cmd-Sites {
    $c = Load-Config
    foreach ($n in $c.sites.Keys) { Write-Host ("{0,-24} {1}" -f $n, $c.sites[$n].url) }
    return 0
}

function Cmd-Backup($a, $opts) {
    $names = Site-Names $(if ($a.Count) { [string]$a[0] } else { '' })
    if ($a.Count -eq 0) { throw 'Укажите имя сайта или all' }
    $mode = if ($a.Count -gt 1) { ([string]$a[1]).ToLower() } else { $null }
    $fail = 0
    foreach ($n in $names) {
        try { Backup-Site $n $mode $opts } catch { Log "[$n] ОШИБКА: $($_.Exception.Message)"; $fail++ }
    }
    if ($fail) { return 1 } else { return 0 }
}

function Cmd-Rotate([string]$arg) { foreach ($n in (Site-Names $arg)) { Rotate (Site-Cfg $n) }; return 0 }

function Cmd-Help {
    Write-Host @"
gtsBackup client $Version (PowerShell)  $ConfigFile

  БЫСТРЫЙ СТАРТ (обычная консоль, в папке клиента):
  gtsbackup add <имя> <адрес> <токен> --dest=D:\Backups [--time=03:30] [--now]
        подключить сайт, проверить связь и (если её ещё нет) поставить задачу «gtsBackup»
        в Планировщик от текущего пользователя: каждый день в --time (по умолчанию 03:30) и при входе
        в Windows, пропущенный запуск — при первой возможности. --now — сразу снять первый бекап.
        Любую настройку сайта можно задать тут же: --keep-full=8 --max-diff=6 --alert-days=3 …

  gtsbackup sites                         список сайтов из настроек
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
Папки: <dest>\<сайт>\<время полного>\full и рядом diff-<время> — дифференциальные этого полного.
Настройки — gtsbackup.json рядом с клиентом, у каждого сайта свои (блок "sites" → "<имя>").
Нужно только то, что есть в Windows 10 (1803+) и 11: PowerShell, curl.exe, tar.exe.
Код выхода: 0 — успех, 1 — ошибка.
"@
    return 0
}

# ════════════════════════════════════════════════════════════════════════════
#  Точка входа
# ════════════════════════════════════════════════════════════════════════════

$cmdArgs = @(); $opts = @{}
foreach ($x in $args) {
    if ($x -match '^--([a-z_-]+)(?:=(.*))?$') { $opts[$matches[1] -replace '-', '_'] = $(if ($null -ne $matches[2]) { $matches[2] } else { $true }) }
    else { $cmdArgs += [string]$x }
}
$command = if ($cmdArgs.Count) { $cmdArgs[0].ToLower() } else { 'help' }
$rest = @(if ($cmdArgs.Count -gt 1) { $cmdArgs[1..($cmdArgs.Count - 1)] })

$code = 0
try {
    if ($command -ne 'help') { Need-Tools }
    switch ($command) {
        'add'       { $code = Cmd-Add $rest $opts }
        'ping'      { $code = Cmd-Ping $(if ($rest.Count) { $rest[0] } else { throw 'Укажите имя сайта' }) }
        'backup'    { $code = Cmd-Backup $rest $opts }
        'list'      { $code = Cmd-List $(if ($rest.Count) { $rest[0] } else { 'all' }) }
        'rotate'    { $code = Cmd-Rotate $(if ($rest.Count) { $rest[0] } else { 'all' }) }
        'verify'    { $code = Cmd-Verify $rest }
        'restore'   { $code = Cmd-Restore $rest }
        'task'      { $code = Cmd-Task $rest }
        'scheduled' { $code = Cmd-Scheduled }
        'check'     { $code = Cmd-Check $opts }
        'sites'     { $code = Cmd-Sites }
        default     { $code = Cmd-Help }
    }
} catch {
    Log "ОШИБКА: $($_.Exception.Message)"
    $code = 1
}
exit $code
