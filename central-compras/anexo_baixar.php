<?php
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/database.php';
require_once __DIR__ . '/app/authorization.php';
require_once __DIR__ . '/app/system_log.php';
require_once __DIR__ . '/app/quote_files.php';
startApplicationSession();
requireLocalUser();

$attachmentId = filter_var(isset($_GET['id']) ? $_GET['id'] : null, FILTER_VALIDATE_INT);
if (!$attachmentId) {
    http_response_code(404);
    exit('Comprovante não encontrado.');
}
$pdo = databaseConnection();
$userId = (int) $_SESSION['user_id'];
$profiles = loadActiveProfiles($pdo, $userId);
$query = $pdo->prepare('SELECT a.id, a.solicitacao_id, a.cotacao_id, a.nome_original, a.mime_type, a.tamanho, a.storage_key, s.solicitante_id, s.comprador_id, s.etapa_atual, s.centro_custo_id, cc.unidade_id FROM anexos a INNER JOIN solicitacoes s ON s.id = a.solicitacao_id INNER JOIN centros_custo cc ON cc.id = s.centro_custo_id WHERE a.id = :id AND a.cotacao_id IS NOT NULL');
$query->execute(array('id' => $attachmentId));
$attachment = $query->fetch();
if (!$attachment) {
    http_response_code(404);
    exit('Comprovante não encontrado.');
}

$allowed = (int) $attachment['solicitante_id'] === $userId
    || userHasProfile($profiles, 'diretor')
    || userHasProfile($profiles, 'administrador')
    || ($attachment['comprador_id'] !== null && (int) $attachment['comprador_id'] === $userId);
if (!$allowed && userHasProfile($profiles, 'comprador') && $attachment['etapa_atual'] === 'cotacao') {
    $hasScope = false;
    $scopeMatch = false;
    $hasGlobalScope = false;
    foreach ($profiles as $profile) {
        if ($profile['codigo'] !== 'comprador') {
            continue;
        }
        if ($profile['centro_custo_id'] === null && $profile['unidade_id'] === null) {
            $hasGlobalScope = true;
            continue;
        }
        $hasScope = true;
        if (($profile['centro_custo_id'] !== null && (int) $profile['centro_custo_id'] === (int) $attachment['centro_custo_id'])
            || ($profile['unidade_id'] !== null && $attachment['unidade_id'] !== null && (int) $profile['unidade_id'] === (int) $attachment['unidade_id'])) {
            $scopeMatch = true;
        }
    }
    $allowed = $hasGlobalScope || !$hasScope || $scopeMatch;
}
if (!$allowed) {
    $stageQuery = $pdo->prepare('SELECT regra_snapshot FROM etapas_solicitacao WHERE solicitacao_id = :request_id');
    $stageQuery->execute(array('request_id' => $attachment['solicitacao_id']));
    foreach ($stageQuery->fetchAll(PDO::FETCH_COLUMN) as $snapshotJson) {
        $snapshot = json_decode($snapshotJson, true);
        if (isset($snapshot['aprovadores_ids']) && is_array($snapshot['aprovadores_ids']) && in_array($userId, array_map('intval', $snapshot['aprovadores_ids']), true)) {
            $allowed = true;
            break;
        }
    }
}
if (!$allowed) {
    http_response_code(403);
    exit('Você não tem acesso a este comprovante.');
}

$path = quoteEvidencePath($attachment['storage_key']);
if (!$path || !is_file($path) || !is_readable($path)) {
    http_response_code(404);
    exit('O arquivo do comprovante não está disponível.');
}

$inline = isset($_GET['visualizar']) && $_GET['visualizar'] === '1';
$imageExtensions = array('image/jpeg' => 'jpg', 'image/png' => 'png');
if ($inline && !isset($imageExtensions[$attachment['mime_type']])) {
    http_response_code(415);
    exit('A visualização está disponível somente para imagens JPG e PNG.');
}

$hasCustomDownloadName = !$inline && isset($_GET['nome_download']) && is_string($_GET['nome_download']);
if ($hasCustomDownloadName) {
    if (!isset($imageExtensions[$attachment['mime_type']])) {
        http_response_code(415);
        exit('O nome personalizado está disponível somente para imagens JPG e PNG.');
    }
    $downloadPrefix = preg_replace('/[^\pL\pN_-]+/u', '_', trim($_GET['nome_download']));
    $downloadPrefix = trim($downloadPrefix, '_-');
    if ($downloadPrefix === '') {
        http_response_code(400);
        exit('O nome do arquivo para download é inválido.');
    }
    $safeName = $downloadPrefix . '_' . date('Ymd_His') . '.' . $imageExtensions[$attachment['mime_type']];
} else {
    $safeName = str_replace(array("\r", "\n", '"', '\\'), '_', $attachment['nome_original']);
}

systemLogEvent($inline ? 'visualizou_comprovante_cotacao' : 'baixou_comprovante_cotacao', 'anexo', (int) $attachment['id'], 'sucesso', array('solicitacao_id' => (int) $attachment['solicitacao_id'], 'cotacao_id' => (int) $attachment['cotacao_id']), null, $userId, $pdo);
header('Content-Type: ' . $attachment['mime_type']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $safeName) . '"; filename*=UTF-8\'\'' . rawurlencode($safeName));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
readfile($path);
exit;
