<?php
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/database.php';
require_once __DIR__ . '/app/authorization.php';
require_once __DIR__ . '/app/system_log.php';
require_once __DIR__ . '/app/sla.php';
startApplicationSession();
requireLocalUser();

function finishWithError($code, $message)
{
    if ($code < 500) {
        systemLogEvent('tentativa_criar_solicitacao', 'solicitacao', null, 'negado', array('http_status' => (int) $code, 'motivo' => $message));
    }
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><title>Solicitação</title><body style="font:15px system-ui;max-width:640px;margin:60px auto;padding:20px"><h1>Não foi possível enviar</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p><a href="solicitacao_nova.php">Voltar ao formulário</a></body></html>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: solicitacao_nova.php');
    exit;
}
if (!validApplicationCsrfToken(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '')) {
    finishWithError(400, 'O formulário expirou. Volte e tente novamente.');
}

$userId = (int) $_SESSION['user_id'];
$idempotencyKey = isset($_POST['idempotency_key']) ? $_POST['idempotency_key'] : '';
$centerId = filter_var(isset($_POST['centro_custo_id']) ? $_POST['centro_custo_id'] : null, FILTER_VALIDATE_INT);
$categoryId = filter_var(isset($_POST['categoria_id']) ? $_POST['categoria_id'] : null, FILTER_VALIDATE_INT);
$productId = filter_var(isset($_POST['produto_id']) ? $_POST['produto_id'] : null, FILTER_VALIDATE_INT);
$quantityRaw = isset($_POST['quantidade']) ? str_replace(',', '.', trim($_POST['quantidade'])) : '';
$dueDate = isset($_POST['prazo_estimado']) ? $_POST['prazo_estimado'] : '';
$justification = trim(isset($_POST['justificativa']) ? $_POST['justificativa'] : '');
$dueDateObject = DateTime::createFromFormat('!Y-m-d', $dueDate);
$dueDateErrors = DateTime::getLastErrors();
$validDueDate = $dueDateObject && $dueDateObject->format('Y-m-d') === $dueDate && (!$dueDateErrors || (!$dueDateErrors['warning_count'] && !$dueDateErrors['error_count']));

if (!preg_match('/^[a-f0-9]{64}$/', $idempotencyKey) || !$centerId || !$categoryId || !$productId || !is_numeric($quantityRaw) || (float) $quantityRaw <= 0 || !$validDueDate || $justification === '') {
    finishWithError(422, 'Revise os campos obrigatórios e tente novamente.');
}

$payload = array(
    'centro_custo_id' => (int) $centerId,
    'categoria_id' => (int) $categoryId,
    'produto_id' => (int) $productId,
    'quantidade' => number_format((float) $quantityRaw, 3, '.', ''),
    'prazo_estimado' => $dueDate,
    'justificativa' => $justification
);
$payloadHash = hash('sha256', json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE));

try {
    $pdo = databaseConnection();
    $profiles = loadActiveProfiles($pdo, $userId);
    if (!userHasProfile($profiles, 'solicitante')) {
        finishWithError(403, 'Seu perfil não permite abrir solicitações.');
    }

    $existingStatement = $pdo->prepare('SELECT id, numero, payload_hash FROM solicitacoes WHERE solicitante_id = :user_id AND chave_idempotencia = :key LIMIT 1');
    $existingStatement->execute(array('user_id' => $userId, 'key' => $idempotencyKey));
    $existing = $existingStatement->fetch();
    if ($existing) {
        if (!hash_equals((string) $existing['payload_hash'], $payloadHash)) {
            systemLogEvent('reenvio_idempotente_conflitante', 'solicitacao', (int) $existing['id'], 'negado', array('numero' => $existing['numero']), null, $userId, $pdo);
            finishWithError(409, 'Esta chave de envio já foi usada com outros dados. Abra um novo formulário para criar outra solicitação.');
        }
        systemLogEvent('reenvio_idempotente', 'solicitacao', (int) $existing['id'], 'sucesso', array('numero' => $existing['numero']), null, $userId, $pdo);
        header('Location: solicitacao_ver.php?id=' . (int) $existing['id'] . '&repetida=1', true, 303);
        exit;
    }

    $centerStatement = $pdo->prepare('SELECT id, unidade_id FROM centros_custo WHERE id = :id AND ativo_omie = 1');
    $centerStatement->execute(array('id' => $centerId));
    $center = $centerStatement->fetch();
    $categoryStatement = $pdo->prepare('SELECT id FROM categorias WHERE id = :id AND ativo_omie = 1');
    $categoryStatement->execute(array('id' => $categoryId));
    $category = $categoryStatement->fetchColumn();
    $productStatement = $pdo->prepare('SELECT id, categoria_id, nome, unidade_medida FROM produtos WHERE id = :id AND ativo_omie = 1');
    $productStatement->execute(array('id' => $productId));
    $product = $productStatement->fetch();
    if (!$center || !$category || !$product || (int) $product['categoria_id'] !== (int) $categoryId) {
        finishWithError(422, 'Selecione um centro de custo, categoria e produto ativos no cadastro sincronizado. O produto deve pertencer à categoria selecionada. Atualize os dados e tente novamente.');
    }

    $hasExplicitScope = false;
    $allowedCenter = false;
    foreach ($profiles as $profile) {
        if ($profile['codigo'] === 'solicitante' && ($profile['centro_custo_id'] !== null || $profile['unidade_id'] !== null)) {
            $hasExplicitScope = true;
            if (($profile['centro_custo_id'] !== null && (int) $profile['centro_custo_id'] === (int) $centerId)
                || ($profile['unidade_id'] !== null && $center['unidade_id'] !== null && (int) $profile['unidade_id'] === (int) $center['unidade_id'])
            ) {
                $allowedCenter = true;
            }
        }
    }
    if ($hasExplicitScope && !$allowedCenter) {
        finishWithError(403, 'Seu perfil não permite solicitar compras para este centro de custo.');
    }

    $pdo->beginTransaction();
    $flowStatement = $pdo->prepare('SELECT f.id FROM fluxo_centro_custo fcc INNER JOIN fluxos_aprovacao f ON f.id = fcc.fluxo_id WHERE fcc.centro_custo_id = :cc_id AND f.ativo = 1 AND (f.vigente_desde IS NULL OR f.vigente_desde <= NOW()) AND (f.vigente_ate IS NULL OR f.vigente_ate >= NOW()) ORDER BY f.versao DESC LIMIT 1');
    $flowStatement->execute(array('cc_id' => $centerId));
    $flowId = $flowStatement->fetchColumn();
    if (!$flowId) {
        finishWithError(409, 'O Setor de Compras ainda não configurou um fluxo de aprovação para este Centro de Custo. Solicite a configuração antes de enviar a solicitação.');
    }
    $rulesStatement = $pdo->prepare('SELECT id, codigo_etapa, ordem, modo, responsavel_tipo, grupo_id, usuario_id FROM regras_etapa WHERE fluxo_id = :flow_id AND ativa = 1 ORDER BY ordem');
    $rulesStatement->execute(array('flow_id' => $flowId));
    $activeStages = $rulesStatement->fetchAll();
    if (!$activeStages) {
        finishWithError(409, 'O fluxo deste Centro de Custo não possui etapas ativas. Solicite a revisão da configuração ao Setor de Compras.');
    }
    $firstStage = $activeStages[0]['codigo_etapa'];
    $requestNumber = 'SC-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
    $insert = $pdo->prepare('INSERT INTO solicitacoes (numero, solicitante_id, centro_custo_id, categoria_id, fluxo_id, status, etapa_atual, prazo_estimado, justificativa, chave_idempotencia, payload_hash) VALUES (:numero, :user_id, :cc_id, :category_id, :flow_id, :status, :stage, :due_date, :justification, :idempotency_key, :payload_hash)');
    $insert->execute(array(
        'numero' => $requestNumber,
        'user_id' => $userId,
        'cc_id' => $centerId,
        'category_id' => $categoryId,
        'flow_id' => $flowId ?: null,
        'status' => 'em_aprovacao',
        'stage' => $firstStage,
        'due_date' => $dueDate,
        'justification' => $justification,
        'idempotency_key' => $idempotencyKey,
        'payload_hash' => $payloadHash
    ));
    $requestId = (int) $pdo->lastInsertId();
    $item = $pdo->prepare('INSERT INTO solicitacao_itens (solicitacao_id, produto_id, descricao_snapshot, unidade_snapshot, quantidade) VALUES (:request_id, :product_id, :description, :unit, :quantity)');
    $item->execute(array(
        'request_id' => $requestId,
        'product_id' => $productId,
        'description' => $product['nome'],
        'unit' => $product['unidade_medida'],
        'quantity' => $quantityRaw
    ));
    $requestItemId = (int) $pdo->lastInsertId();
    $stageInsert = $pdo->prepare('INSERT INTO etapas_solicitacao (solicitacao_id, codigo_etapa, ordem_execucao, status, regra_snapshot, responsavel_tipo, responsavel_id, iniciada_em) VALUES (:request_id, :stage, :stage_order, :status, :snapshot, :responsible_type, :responsible_id, :started_at)');
    $firstStageId = null;
    $firstStageStartedAt = date('Y-m-d H:i:s');
    foreach ($activeStages as $index => $stage) {
        $isCurrent = $index === 0;
        $approverIds = array();
        if ($stage['responsavel_tipo'] === 'grupo' || $stage['responsavel_tipo'] === 'grupo_usuario') {
            $groupApprovers = $pdo->prepare('SELECT ga.usuario_id FROM grupo_aprovadores ga INNER JOIN usuarios u ON u.id = ga.usuario_id AND u.ativo = 1 WHERE ga.grupo_id = :group_id AND ga.ativo = 1 AND (ga.inicio_vigencia IS NULL OR ga.inicio_vigencia <= CURDATE()) AND (ga.fim_vigencia IS NULL OR ga.fim_vigencia >= CURDATE()) ORDER BY ga.ordem_aprovacao, ga.usuario_id');
            $groupApprovers->execute(array('group_id' => $stage['grupo_id']));
            $approverIds = array_map('intval', $groupApprovers->fetchAll(PDO::FETCH_COLUMN));
        }
        $directApprovers = $pdo->prepare('SELECT ru.usuario_id FROM regras_etapa_usuarios ru INNER JOIN usuarios u ON u.id = ru.usuario_id AND u.ativo = 1 WHERE ru.regra_etapa_id = :rule_id ORDER BY ru.ordem, ru.usuario_id');
        $directApprovers->execute(array('rule_id' => $stage['id']));
        $configuredUsers = array_map('intval', $directApprovers->fetchAll(PDO::FETCH_COLUMN));
        if (!$configuredUsers && $stage['usuario_id']) {
            $configuredUsers = array((int) $stage['usuario_id']);
        }
        foreach ($configuredUsers as $configuredUserId) {
            if (!in_array($configuredUserId, $approverIds, true)) {
                $approverIds[] = $configuredUserId;
            }
        }
        $responsibleId = ($stage['responsavel_tipo'] === 'grupo' || $stage['responsavel_tipo'] === 'grupo_usuario')
            ? (int) $stage['grupo_id']
            : (int) ($configuredUsers ? $configuredUsers[0] : 0);
        if (!$approverIds) {
            $pdo->rollBack();
            finishWithError(409, 'O aprovador configurado para uma etapa não está mais ativo. O Setor de Compras deve revisar o fluxo antes do envio.');
        }
        $stageInsert->execute(array(
            'request_id' => $requestId,
            'stage' => $stage['codigo_etapa'],
            'stage_order' => (int) $stage['ordem'],
            'status' => $isCurrent ? 'pendente' : 'aguardando',
            'snapshot' => json_encode(array(
                'codigo_etapa' => $stage['codigo_etapa'],
                'ordem' => (int) $stage['ordem'],
                'modo' => $stage['modo'],
                'responsavel_tipo' => $stage['responsavel_tipo'],
                'responsavel_id' => $responsibleId,
                'aprovadores_ids' => $approverIds
            )),
            'responsible_type' => $stage['responsavel_tipo'],
            'responsible_id' => $responsibleId,
            'started_at' => $isCurrent ? $firstStageStartedAt : null
        ));
        if ($isCurrent) {
            $firstStageId = (int) $pdo->lastInsertId();
        }
    }
    if ($firstStageId) {
        slaStartForStage($pdo, $flowId, $firstStageId, $firstStage, $firstStageStartedAt);
    }
    systemLogEvent('criou_solicitacao', 'solicitacao', $requestId, 'sucesso', array('numero' => $requestNumber, 'item_id' => $requestItemId), null, $userId, $pdo);
    $pdo->commit();
    header('Location: solicitacao_ver.php?id=' . $requestId, true, 303);
    exit;
} catch (PDOException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($exception->getCode() === '23000' && isset($pdo)) {
        $retry = $pdo->prepare('SELECT id, payload_hash FROM solicitacoes WHERE solicitante_id = :user_id AND chave_idempotencia = :key LIMIT 1');
        $retry->execute(array('user_id' => $userId, 'key' => $idempotencyKey));
        $duplicate = $retry->fetch();
        if ($duplicate && hash_equals((string) $duplicate['payload_hash'], $payloadHash)) {
            systemLogEvent('reenvio_idempotente', 'solicitacao', (int) $duplicate['id'], 'sucesso', array('resultado' => 'solicitacao_existente'), null, $userId, $pdo);
            header('Location: solicitacao_ver.php?id=' . (int) $duplicate['id'] . '&repetida=1', true, 303);
            exit;
        }
        if ($duplicate) {
            systemLogEvent('reenvio_idempotente_conflitante', 'solicitacao', (int) $duplicate['id'], 'negado', array(), null, $userId, $pdo);
            finishWithError(409, 'Esta chave de envio já foi usada com outros dados. Abra um novo formulário para criar outra solicitação.');
        }
    }
    error_log('Falha ao criar solicitação: ' . $exception->getMessage());
    systemLogEvent('erro_sistema', 'solicitacao', isset($requestId) ? $requestId : null, 'falha', array('operacao' => 'criou_solicitacao'), $exception->getMessage(), $userId, isset($pdo) ? $pdo : null);
    finishWithError(500, 'Ocorreu um erro ao salvar. Nenhuma solicitação foi confirmada. Tente novamente.');
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Erro inesperado ao criar solicitação: ' . $exception->getMessage());
    systemLogEvent('erro_sistema', 'solicitacao', isset($requestId) ? $requestId : null, 'falha', array('operacao' => 'criou_solicitacao'), $exception->getMessage(), $userId, isset($pdo) ? $pdo : null);
    finishWithError(500, 'Ocorreu um erro ao salvar. Nenhuma solicitação foi confirmada. Tente novamente.');
}
