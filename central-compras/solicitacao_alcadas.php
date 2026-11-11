<?php
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/database.php';
require_once __DIR__ . '/app/authorization.php';
require_once __DIR__ . '/app/system_log.php';
startApplicationSession();
requireLocalUser();

class ApproverScopeException extends RuntimeException {}

class InvalidApproverSelectionException extends RuntimeException {}

function approverAssignmentFailure($status, $message)
{
    systemLogEvent(
        'tentativa_incluir_alcadas_aprovacao',
        'solicitacao',
        isset($GLOBALS['requestId']) ? (int) $GLOBALS['requestId'] : null,
        $status >= 500 ? 'falha' : 'negado',
        array('http_status' => (int) $status, 'motivo' => $message)
    );
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><title>Alçadas da solicitação</title><body style="font:15px system-ui;max-width:640px;margin:60px auto;padding:20px"><h1>Não foi possível atualizar as alçadas</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p><a href="painel.php">Voltar ao painel</a></body></html>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validApplicationCsrfToken(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '')) {
    approverAssignmentFailure(400, 'A solicitação expirou ou é inválida. Atualize a página e tente novamente.');
}

$requestIdRaw = isset($_POST['solicitacao_id']) && is_scalar($_POST['solicitacao_id']) ? $_POST['solicitacao_id'] : null;
$requestId = filter_var($requestIdRaw, FILTER_VALIDATE_INT);
$GLOBALS['requestId'] = $requestId ?: null;
$rawGroupIds = isset($_POST['grupo_ids']) && is_array($_POST['grupo_ids']) ? $_POST['grupo_ids'] : array();
$rawUserIds = isset($_POST['usuario_ids']) && is_array($_POST['usuario_ids']) ? $_POST['usuario_ids'] : array();
$groupIds = array();
$userIds = array();
foreach ($rawGroupIds as $rawId) {
    if (!is_scalar($rawId)) {
        approverAssignmentFailure(422, 'A seleção de grupos contém um valor inválido ou duplicado.');
    }
    $id = filter_var($rawId, FILTER_VALIDATE_INT);
    if (!$id || in_array((int) $id, $groupIds, true)) {
        approverAssignmentFailure(422, 'A seleção de grupos contém um valor inválido ou duplicado.');
    }
    $groupIds[] = (int) $id;
}
foreach ($rawUserIds as $rawId) {
    if (!is_scalar($rawId)) {
        approverAssignmentFailure(422, 'A seleção de usuários contém um valor inválido ou duplicado.');
    }
    $id = filter_var($rawId, FILTER_VALIDATE_INT);
    if (!$id || in_array((int) $id, $userIds, true)) {
        approverAssignmentFailure(422, 'A seleção de usuários contém um valor inválido ou duplicado.');
    }
    $userIds[] = (int) $id;
}
if (!$requestId || (!$groupIds && !$userIds)) {
    approverAssignmentFailure(422, 'Selecione ao menos um grupo ou aprovador individual.');
}

$pdo = databaseConnection();
$profiles = loadActiveProfiles($pdo, (int) $_SESSION['user_id']);
if (!userHasProfile($profiles, 'comprador')) {
    approverAssignmentFailure(403, 'Somente um comprador pode incluir alçadas na solicitação.');
}

try {
    $pdo->beginTransaction();
    $requestQuery = $pdo->prepare('SELECT s.id, s.status, s.etapa_atual, s.centro_custo_id, cc.unidade_id FROM solicitacoes s INNER JOIN centros_custo cc ON cc.id = s.centro_custo_id WHERE s.id = :id FOR UPDATE');
    $requestQuery->execute(array('id' => $requestId));
    $request = $requestQuery->fetch();
    if (!$request || $request['status'] !== 'em_aprovacao') {
        throw new RuntimeException('A solicitação não está em andamento.');
    }

    $buyerInScope = false;
    foreach ($profiles as $profile) {
        if ($profile['codigo'] !== 'comprador') {
            continue;
        }
        $unrestricted = $profile['centro_custo_id'] === null && $profile['unidade_id'] === null;
        $centerMatch = $profile['centro_custo_id'] !== null && (int) $profile['centro_custo_id'] === (int) $request['centro_custo_id'];
        $unitMatch = $profile['unidade_id'] !== null && $request['unidade_id'] !== null && (int) $profile['unidade_id'] === (int) $request['unidade_id'];
        if ($unrestricted || $centerMatch || $unitMatch) {
            $buyerInScope = true;
            break;
        }
    }
    if (!$buyerInScope) {
        throw new ApproverScopeException('A solicitação não pertence ao escopo de Centro de Custo do seu perfil de Comprador.');
    }

    $stageQuery = $pdo->prepare('SELECT id, codigo_etapa, regra_snapshot, responsavel_tipo, responsavel_id FROM etapas_solicitacao WHERE solicitacao_id = :request_id AND status = \'pendente\' AND codigo_etapa = :stage_code ORDER BY ordem_execucao LIMIT 1 FOR UPDATE');
    $stageQuery->execute(array('request_id' => $requestId, 'stage_code' => $request['etapa_atual']));
    $stage = $stageQuery->fetch();
    if (!$stage) {
        throw new RuntimeException('A solicitação não possui uma etapa vigente para receber alçadas.');
    }
    $decisionsQuery = $pdo->prepare('SELECT COUNT(*) FROM decisoes_aprovacao WHERE etapa_solicitacao_id = :stage_id');
    $decisionsQuery->execute(array('stage_id' => $stage['id']));
    if ((int) $decisionsQuery->fetchColumn() > 0) {
        throw new RuntimeException('As alçadas só podem ser alteradas antes da primeira decisão da etapa vigente.');
    }

    $snapshot = json_decode($stage['regra_snapshot'], true);
    if (!is_array($snapshot)) {
        throw new RuntimeException('A configuração de aprovação desta etapa está inválida.');
    }
    $approverIds = isset($snapshot['aprovadores_ids']) && is_array($snapshot['aprovadores_ids'])
        ? array_values(array_unique(array_map('intval', $snapshot['aprovadores_ids'])))
        : array();
    $addedApprovers = array();

    $groupMembers = $pdo->prepare('SELECT ga.usuario_id, u.nome FROM grupos_aprovacao g INNER JOIN grupo_aprovadores ga ON ga.grupo_id = g.id INNER JOIN usuarios u ON u.id = ga.usuario_id AND u.ativo = 1 WHERE g.id = :group_id AND g.ativo = 1 AND ga.ativo = 1 AND (ga.inicio_vigencia IS NULL OR ga.inicio_vigencia <= CURDATE()) AND (ga.fim_vigencia IS NULL OR ga.fim_vigencia >= CURDATE()) ORDER BY ga.ordem_aprovacao, ga.usuario_id');
    $groupNameQuery = $pdo->prepare('SELECT nome FROM grupos_aprovacao WHERE id = :id AND ativo = 1');
    foreach ($groupIds as $groupId) {
        $groupNameQuery->execute(array('id' => $groupId));
        $groupName = $groupNameQuery->fetchColumn();
        if (!$groupName) {
            throw new InvalidApproverSelectionException('Um dos grupos selecionados não existe ou está inativo.');
        }
        $groupMembers->execute(array('group_id' => $groupId));
        $members = $groupMembers->fetchAll();
        if (!$members) {
            throw new InvalidApproverSelectionException('O grupo "' . $groupName . '" não possui aprovadores ativos e vigentes.');
        }
        $groupAdded = false;
        foreach ($members as $member) {
            $memberId = (int) $member['usuario_id'];
            if (!in_array($memberId, $approverIds, true)) {
                $approverIds[] = $memberId;
                $addedApprovers[] = $memberId;
                $groupAdded = true;
            }
        }
        if ($groupAdded) {
            $snapshot['aprovadores_adicionais'][] = array('tipo' => 'grupo', 'id' => $groupId, 'nome' => $groupName);
        }
    }

    $userQuery = $pdo->prepare('SELECT id, nome FROM usuarios WHERE id = :id AND ativo = 1');
    foreach ($userIds as $userId) {
        $userQuery->execute(array('id' => $userId));
        $user = $userQuery->fetch();
        if (!$user) {
            throw new InvalidApproverSelectionException('Um dos usuários selecionados não está ativo.');
        }
        if (!in_array((int) $user['id'], $approverIds, true)) {
            $approverIds[] = (int) $user['id'];
            $addedApprovers[] = (int) $user['id'];
            $snapshot['aprovadores_adicionais'][] = array('tipo' => 'usuario', 'id' => (int) $user['id'], 'nome' => $user['nome']);
        }
    }
    if (!$addedApprovers) {
        throw new RuntimeException('Todos os aprovadores selecionados já fazem parte desta etapa.');
    }

    $snapshot['aprovadores_ids'] = $approverIds;
    $snapshot['aprovadores_adicionais'] = isset($snapshot['aprovadores_adicionais']) ? $snapshot['aprovadores_adicionais'] : array();
    $snapshot['aprovadores_adicionais'][] = array(
        'incluido_por' => (int) $_SESSION['user_id'],
        'incluido_em' => date('Y-m-d H:i:s')
    );
    $encodedSnapshot = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encodedSnapshot === false) {
        throw new RuntimeException('Não foi possível serializar a configuração dos aprovadores.');
    }
    $update = $pdo->prepare('UPDATE etapas_solicitacao SET regra_snapshot = :snapshot WHERE id = :stage_id AND status = \'pendente\'');
    $update->execute(array(
        'snapshot' => $encodedSnapshot,
        'stage_id' => $stage['id']
    ));
    if ($update->rowCount() !== 1) {
        throw new RuntimeException('A etapa mudou durante a atualização. Recarregue a solicitação e tente novamente.');
    }
    $audit = $pdo->prepare('INSERT INTO auditoria_eventos (ator_id, solicitacao_id, acao, entidade, entidade_id, valor_anterior, valor_novo, ip_origem) VALUES (:user_id, :request_id, \'incluiu_alcadas_aprovacao\', \'etapa_aprovacao\', :stage_id, :old_value, :new_value, :ip)');
    $audit->execute(array(
        'user_id' => $_SESSION['user_id'],
        'request_id' => $requestId,
        'stage_id' => (string) $stage['id'],
        'old_value' => json_encode(array('aprovadores_ids' => array_values(array_diff($approverIds, $addedApprovers)))),
        'new_value' => json_encode(array('aprovadores_ids' => $approverIds, 'grupos_ids' => $groupIds, 'usuarios_ids' => $userIds)),
        'ip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null
    ));
    systemLogEvent('incluiu_alcadas_aprovacao', 'solicitacao', $requestId, 'sucesso', array('etapa_id' => (int) $stage['id'], 'grupos_ids' => $groupIds, 'usuarios_ids' => $userIds), null, (int) $_SESSION['user_id'], $pdo);
    $pdo->commit();
    header('Location: solicitacao_ver.php?id=' . $requestId, true, 303);
    exit;
} catch (ApproverScopeException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    approverAssignmentFailure(403, $exception->getMessage());
} catch (InvalidApproverSelectionException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    approverAssignmentFailure(422, $exception->getMessage());
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Falha ao persistir alçadas da solicitação: ' . $exception->getMessage());
    systemLogEvent('erro_sistema', 'solicitacao', $requestId, 'falha', array('operacao' => 'incluiu_alcadas_aprovacao'), $exception->getMessage(), (int) $_SESSION['user_id'], $pdo);
    approverAssignmentFailure(500, 'Ocorreu um erro ao atualizar as alçadas. Nenhuma alteração foi confirmada.');
} catch (RuntimeException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    approverAssignmentFailure(409, $exception->getMessage());
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Falha ao incluir alçadas na solicitação: ' . $exception->getMessage());
    systemLogEvent('erro_sistema', 'solicitacao', $requestId, 'falha', array('operacao' => 'incluiu_alcadas_aprovacao'), $exception->getMessage(), (int) $_SESSION['user_id'], $pdo);
    approverAssignmentFailure(500, 'Ocorreu um erro ao atualizar as alçadas. Nenhuma alteração foi confirmada.');
}
