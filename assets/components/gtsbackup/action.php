<?php
/**
 * gtsBackup — эндпоинт cmd-клиента. Без MODX-сессии, авторизация — токен (таблица gbToken).
 * Действия: ping, create, step, download, finish, cancel, db. Описание — docs/protocol.md.
 */
@set_time_limit(0);
@ini_set('memory_limit', '1024M');
ignore_user_abort(false);

$root = __DIR__;
for ($i = 0; $i < 6; $i++) {
    if (file_exists($root . '/config.core.php')) break;
    $root = dirname($root);
}
if (!file_exists($root . '/config.core.php')) {
    http_response_code(500);
    header('Content-Type: application/json');
    exit(json_encode(['success' => 0, 'message' => 'config.core.php not found']));
}
require $root . '/config.core.php';
require MODX_CORE_PATH . 'model/modx/modx.class.php';

$modx = new modX();
$modx->initialize('web');
$modx->getService('error', 'error.modError');

$gtsbackup = $modx->getService('gtsbackup', 'gtsBackup', MODX_CORE_PATH . 'components/gtsbackup/model/', []);
header('X-Robots-Tag: noindex');
if (!($gtsbackup instanceof gtsBackup)) {
    http_response_code(500);
    header('Content-Type: application/json');
    exit(json_encode(['success' => 0, 'message' => 'Could not load gtsBackup service']));
}

// Потоковые действия (download, db) отдают ответ сами и делают exit.
$resp = $gtsbackup->endpoint();
header('Content-Type: application/json; charset=utf-8');
echo json_encode($resp, JSON_UNESCAPED_UNICODE);
