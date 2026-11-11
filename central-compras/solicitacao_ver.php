<?php
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/database.php';
require_once __DIR__ . '/app/authorization.php';
require_once __DIR__ . '/app/sla.php';
require_once __DIR__ . '/app/request_integrity.php';
startApplicationSession();
requireLocalUser();

$requestId = filter_var(isset($_GET['id']) ? $_GET['id'] : null, FILTER_VALIDATE_INT);
if (!$requestId) {
    http_response_code(404);
    exit('Solicitação não encontrada.');
}

$pdo = databaseConnection();
$profiles = loadActiveProfiles($pdo, $_SESSION['user_id']);
$isDirector = userHasProfile($profiles, 'diretor');
$isAdministrator = userHasProfile($profiles, 'administrador');
$isRequester = userHasProfile($profiles, 'solicitante');
$isBuyer = userHasProfile($profiles, 'comprador');
$isManager = userHasProfile($profiles, 'gestor');

$query = 'SELECT s.id, s.solicitante_id, s.comprador_id, s.centro_custo_id, s.fluxo_id, s.numero, s.status, s.etapa_atual, s.omie_integracao_status, s.omie_pedido_id, s.prazo_estimado, s.justificativa, s.criada_em, u.nome AS solicitante, cc.nome AS centro, cc.unidade_id, cat.nome AS categoria FROM solicitacoes s INNER JOIN usuarios u ON u.id = s.solicitante_id INNER JOIN centros_custo cc ON cc.id = s.centro_custo_id LEFT JOIN categorias cat ON cat.id = s.categoria_id WHERE s.id = :id';
$statement = $pdo->prepare($query);
$statement->execute(array('id' => $requestId));
$request = $statement->fetch();
if (!$request) {
    http_response_code(404);
    exit('Solicitação não encontrada.');
}

$isOwnRequest = $isRequester && (int) $request['solicitante_id'] === (int) $_SESSION['user_id'];
$isAssignedBuyer = $isBuyer && isset($request['comprador_id']) && (int) $request['comprador_id'] === (int) $_SESSION['user_id'];
$isScopedBuyer = false;
$isQuotationBuyer = false;
if ($isBuyer) {
    foreach ($profiles as $profile) {
        if ($profile['codigo'] !== 'comprador') {
            continue;
        }
        $unrestricted = $profile['centro_custo_id'] === null && $profile['unidade_id'] === null;
        $centerMatch = $profile['centro_custo_id'] !== null && (int) $profile['centro_custo_id'] === (int) $request['centro_custo_id'];
        $unitMatch = $profile['unidade_id'] !== null && $request['unidade_id'] !== null && (int) $profile['unidade_id'] === (int) $request['unidade_id'];
        if ($unrestricted || $centerMatch || $unitMatch) {
            $isScopedBuyer = true;
            if ($request['status'] === 'em_aprovacao' && $request['etapa_atual'] === 'cotacao' && ($request['comprador_id'] === null || (int) $request['comprador_id'] === (int) $_SESSION['user_id'])) {
                $isQuotationBuyer = true;
            }
        }
    }
}
$approvalStageQuery = $pdo->prepare('SELECT id, codigo_etapa, responsavel_tipo, responsavel_id, status, regra_snapshot FROM etapas_solicitacao WHERE solicitacao_id = :request_id AND status = \'pendente\' ORDER BY ordem_execucao LIMIT 1');
$approvalStageQuery->execute(array('request_id' => $requestId));
$approvalStage = $approvalStageQuery->fetch();
$canManageApprovers = false;
if ($isScopedBuyer && $request['status'] === 'em_aprovacao' && $approvalStage && $approvalStage['codigo_etapa'] === $request['etapa_atual']) {
    $stageDecisionCount = $pdo->prepare('SELECT COUNT(*) FROM decisoes_aprovacao WHERE etapa_solicitacao_id = :stage_id');
    $stageDecisionCount->execute(array('stage_id' => $approvalStage['id']));
    $canManageApprovers = (int) $stageDecisionCount->fetchColumn() === 0;
}
if ($canManageApprovers) {
    $availableApprovalGroups = $pdo->query('SELECT g.id, g.nome FROM grupos_aprovacao g WHERE g.ativo = 1 AND EXISTS (SELECT 1 FROM grupo_aprovadores ga INNER JOIN usuarios u ON u.id = ga.usuario_id WHERE ga.grupo_id = g.id AND ga.ativo = 1 AND u.ativo = 1 AND (ga.inicio_vigencia IS NULL OR ga.inicio_vigencia <= CURDATE()) AND (ga.fim_vigencia IS NULL OR ga.fim_vigencia >= CURDATE())) ORDER BY g.nome')->fetchAll();
    $availableApprovers = $pdo->query('SELECT id, nome, matricula FROM usuarios WHERE ativo = 1 ORDER BY nome')->fetchAll();
}
$canApprove = false;

if ($approvalStage && $approvalStage['codigo_etapa'] === $request['etapa_atual'] && $request['status'] === 'em_aprovacao') {
    if (in_array($approvalStage['responsavel_tipo'], array('grupo', 'usuario', 'grupo_usuario'), true)) {
        $snapshot = json_decode($approvalStage['regra_snapshot'], true);
        $approverIds = isset($snapshot['aprovadores_ids']) && is_array($snapshot['aprovadores_ids']) ? array_values(array_map('intval', $snapshot['aprovadores_ids'])) : array();
        if (!$approverIds && $approvalStage['responsavel_tipo'] === 'usuario') {
            $approverIds = array((int) $approvalStage['responsavel_id']);
        }
        if (isset($snapshot['modo']) && $snapshot['modo'] === 'OU') {
            $canApprove = in_array((int) $_SESSION['user_id'], $approverIds, true);
        } else {
            $approvedCount = $pdo->prepare('SELECT COUNT(*) FROM decisoes_aprovacao WHERE etapa_solicitacao_id = :stage_id AND decisao = \'aprovado\'');
            $approvedCount->execute(array('stage_id' => $approvalStage['id']));
            $approvalIndex = (int) $approvedCount->fetchColumn();
            $nextApproverId = isset($approverIds[$approvalIndex]) ? (int) $approverIds[$approvalIndex] : null;
            $canApprove = $nextApproverId === (int) $_SESSION['user_id'];
        }
    }
}

$isAssignedApprover = $canApprove;
if ($canApprove) {
    $priorDecision = $pdo->prepare('SELECT 1 FROM decisoes_aprovacao WHERE etapa_solicitacao_id = :stage_id AND aprovador_id = :user_id');
    $priorDecision->execute(array('stage_id' => $approvalStage['id'], 'user_id' => $_SESSION['user_id']));
    $canApprove = !$priorDecision->fetchColumn();
}
$historicalApproverQuery = $pdo->prepare('SELECT regra_snapshot FROM etapas_solicitacao WHERE solicitacao_id = :request_id');
$historicalApproverQuery->execute(array('request_id' => $requestId));
$wasAssignedApprover = false;
foreach ($historicalApproverQuery->fetchAll(PDO::FETCH_COLUMN) as $historicalSnapshotJson) {
    $historicalSnapshot = json_decode($historicalSnapshotJson, true);
    if (isset($historicalSnapshot['aprovadores_ids']) && is_array($historicalSnapshot['aprovadores_ids']) && in_array((int) $_SESSION['user_id'], array_map('intval', $historicalSnapshot['aprovadores_ids']), true)) {
        $wasAssignedApprover = true;
        break;
    }
}
$approvalIntegrityIssues = array();
if ($canApprove && $approvalStage) {
    $approvalIntegrityIssues = requestWorkflowIntegrityIssues($pdo, $requestId, $approvalStage['codigo_etapa']);
}
$visible = $isAdministrator || $isDirector || $isOwnRequest || $isAssignedBuyer || $isQuotationBuyer || ($isScopedBuyer && $request['status'] === 'em_aprovacao') || $isAssignedApprover || $wasAssignedApprover;
if ($isManager) {
    $scope = $pdo->prepare('SELECT c.id FROM contratos c INNER JOIN centros_custo cc ON cc.id = c.centro_custo_id WHERE c.solicitacao_id = :request_id AND EXISTS (SELECT 1 FROM usuario_perfis up INNER JOIN perfis p ON p.id = up.perfil_id WHERE up.usuario_id = :user_id AND p.codigo = \'gestor\' AND p.ativo = 1 AND (up.valido_ate IS NULL OR up.valido_ate >= CURDATE()) AND ((up.centro_custo_id IS NOT NULL AND up.centro_custo_id = c.centro_custo_id) OR (up.unidade_id IS NOT NULL AND up.unidade_id = cc.unidade_id))) LIMIT 1');
    $scope->execute(array('request_id' => $requestId, 'user_id' => $_SESSION['user_id']));
    $contractId = $scope->fetchColumn();
    if ($contractId && !$visible) {
        header('Location: contrato_ver.php?id=' . (int) $contractId);
        exit;
    }
}
if (!$visible) {
    http_response_code(403);
    exit('Você não tem acesso a esta solicitação.');
}

$itemsQuery = $pdo->prepare('SELECT descricao_snapshot, unidade_snapshot, quantidade FROM solicitacao_itens WHERE solicitacao_id = :id');
$itemsQuery->execute(array('id' => $requestId));
$items = $itemsQuery->fetchAll();
$quotationRounds = array();
$roundQuery = $pdo->prepare('SELECT id, versao, status, justificativa_menos_tres, aprovado_em FROM mapas_cotacao WHERE solicitacao_id = :request_id ORDER BY versao DESC');
$roundQuery->execute(array('request_id' => $requestId));
$quoteRowsQuery = $pdo->prepare('SELECT c.id, c.fornecedor_id, c.valor_total, c.moeda, c.prazo_entrega_dias, c.condicao_pagamento, c.frete_tipo, c.vencedora, c.justificativa_escolha, f.razao_social AS fornecedor FROM cotacoes c INNER JOIN fornecedores f ON f.id = c.fornecedor_id WHERE c.mapa_id = :map_id ORDER BY f.razao_social');
$quoteItemsQuery = $pdo->prepare('SELECT ci.valor_unitario, ci.quantidade, ci.valor_total, si.descricao_snapshot, si.unidade_snapshot FROM cotacao_itens ci INNER JOIN solicitacao_itens si ON si.id = ci.solicitacao_item_id WHERE ci.cotacao_id = :quote_id ORDER BY ci.id');
$quoteAttachmentsQuery = $pdo->prepare('SELECT id, nome_original, mime_type FROM anexos WHERE cotacao_id = :quote_id ORDER BY id');
foreach ($roundQuery->fetchAll() as $round) {
    $quoteRowsQuery->execute(array('map_id' => $round['id']));
    $round['ofertas'] = $quoteRowsQuery->fetchAll();
    foreach ($round['ofertas'] as $quoteIndex => $quoteRow) {
        $quoteItemsQuery->execute(array('quote_id' => $quoteRow['id']));
        $round['ofertas'][$quoteIndex]['itens'] = $quoteItemsQuery->fetchAll();
        $quoteAttachmentsQuery->execute(array('quote_id' => $quoteRow['id']));
        $round['ofertas'][$quoteIndex]['anexos'] = $quoteAttachmentsQuery->fetchAll();
    }
    $quotationRounds[] = $round;
}
$progressStages = array();
$progressLabels = array('solicitacao' => 'Solicitação', 'cotacao' => 'Mapa de cotação', 'aprovacao_valor' => 'Aprovação de valor', 'pedido' => 'Pedido de compra', 'diretor_alcada' => 'Aprovação do Diretor', 'integracao_omie' => 'Integração OMIE');
$currentStageDisplay = isset($progressLabels[$request['etapa_atual']])
    ? $progressLabels[$request['etapa_atual']]
    : ($request['etapa_atual'] !== null && $request['etapa_atual'] !== '' ? ucfirst(str_replace('_', ' ', $request['etapa_atual'])) : '—');
$estimatedDate = DateTime::createFromFormat('!Y-m-d', (string) $request['prazo_estimado']);
$estimatedDateDisplay = $estimatedDate ? $estimatedDate->format('d/m/Y') : '—';
$progressQuery = $pdo->prepare('SELECT id, codigo_etapa, ordem_execucao, status, concluida_em, regra_snapshot, responsavel_tipo, responsavel_id FROM etapas_solicitacao WHERE solicitacao_id = :id ORDER BY ordem_execucao');
$progressQuery->execute(array('id' => $requestId));
$progressStages = $progressQuery->fetchAll();
$slaProgress = null;
if ($request['status'] === 'em_aprovacao') {
    foreach ($progressStages as $progressStage) {
        if ($progressStage['codigo_etapa'] === $request['etapa_atual'] && $progressStage['status'] === 'pendente' && !empty($progressStage['id'])) {
            $slaProgress = slaProgressForStage($pdo, $progressStage['id']);
            break;
        }
    }
}
$slaDeadlineDisplay = '—';
$slaLimitDisplay = '';
if ($slaProgress) {
    try {
        $deadline = new DateTime($slaProgress['vence_em'], new DateTimeZone(date_default_timezone_get()));
        $deadline->setTimezone(new DateTimeZone('America/Sao_Paulo'));
        $slaDeadlineDisplay = $deadline->format('d/m/Y H:i');
    } catch (Exception $exception) {
        $slaDeadlineDisplay = (string) $slaProgress['vence_em'];
    }
    $slaLimitDisplay = $slaProgress['snapshot']['duracao'] . ' ' . $slaProgress['snapshot']['unidade_tempo'] . ' (' . $slaProgress['snapshot']['regime_contagem'] . ')';
}

if (!$progressStages && $request['fluxo_id']) {
    $legacyProgress = $pdo->prepare('SELECT codigo_etapa, ordem FROM regras_etapa WHERE fluxo_id = :flow_id AND ativa = 1 ORDER BY ordem');
    $legacyProgress->execute(array('flow_id' => $request['fluxo_id']));
    foreach ($legacyProgress->fetchAll() as $legacyStage) {
        $progressStages[] = array('id' => null, 'codigo_etapa' => $legacyStage['codigo_etapa'], 'ordem_execucao' => $legacyStage['ordem'], 'status' => null, 'concluida_em' => null, 'regra_snapshot' => null, 'responsavel_tipo' => null, 'responsavel_id' => null);
    }
}

// A integração ainda não envia pedidos; este marco aparece no fluxo e receberá o retorno futuro do OMIE.
$integrationStatus = isset($request['omie_integracao_status']) ? $request['omie_integracao_status'] : 'pendente';
$progressStages[] = array(
    'id' => null,
    'codigo_etapa' => 'integracao_omie',
    'ordem_execucao' => count($progressStages) + 1,
    'status' => $integrationStatus === 'confirmado' ? 'concluida' : ($request['status'] === 'aprovada' ? 'pendente' : 'aguardando'),
    'concluida_em' => null,
    'regra_snapshot' => null,
    'responsavel_tipo' => null,
    'responsavel_id' => null
);

$currentProgressPosition = null;
foreach ($progressStages as $progressIndex => $progressStage) {
    if ($progressStage['codigo_etapa'] === $request['etapa_atual']) {
        $currentProgressPosition = $progressIndex;
        break;
    }
}
if ($request['status'] === 'aprovada' && $integrationStatus !== 'confirmado') {
    $currentProgressPosition = count($progressStages) - 1;
}

$progressDetails = array();
$approvalHistory = array();
if ($progressStages) {
    $decisionHistoryQuery = $pdo->prepare('SELECT da.etapa_solicitacao_id, da.decisao, da.justificativa, u.nome, da.criada_em FROM decisoes_aprovacao da INNER JOIN etapas_solicitacao es ON es.id = da.etapa_solicitacao_id INNER JOIN usuarios u ON u.id = da.aprovador_id WHERE es.solicitacao_id = :request_id ORDER BY da.criada_em, da.id');
    $decisionHistoryQuery->execute(array('request_id' => $requestId));
    foreach ($decisionHistoryQuery->fetchAll() as $decisionRow) {
        if (!isset($approvalHistory[$decisionRow['etapa_solicitacao_id']])) {
            $approvalHistory[$decisionRow['etapa_solicitacao_id']] = array();
        }
        $approvalHistory[$decisionRow['etapa_solicitacao_id']][] = $decisionRow;
    }
    foreach ($progressStages as $progressIndex => $progressStage) {
        $snapshot = !empty($progressStage['regra_snapshot']) ? json_decode($progressStage['regra_snapshot'], true) : array();
        if (isset($progressStage['status']) && in_array($progressStage['status'], array('aprovada', 'aprovado'), true) && isset($approvalHistory[$progressStage['id']])) {
            $approvedNames = array();
            foreach ($approvalHistory[$progressStage['id']] as $historyEntry) {
                if ($historyEntry['decisao'] === 'aprovado') {
                    $approvedNames[] = $historyEntry['nome'];
                }
            }
            if (isset($snapshot['modo']) && $snapshot['modo'] === 'OU' && $approvedNames) {
                $progressDetails[$progressIndex] = 'Aprovado por ' . $approvedNames[0];
            } elseif ($approvedNames) {
                $progressDetails[$progressIndex] = 'Aprovadores: ' . implode(', ', $approvedNames);
            }
        } elseif ($progressStage['codigo_etapa'] === $request['etapa_atual'] && $progressStage['status'] === 'pendente') {
            $responsibleName = '';
            if (in_array($progressStage['responsavel_tipo'], array('grupo', 'usuario', 'grupo_usuario'), true)) {
                $responsibleQuery = $pdo->prepare('SELECT nome FROM grupos_aprovacao WHERE id = :id');
                if ($progressStage['responsavel_tipo'] === 'grupo' || $progressStage['responsavel_tipo'] === 'grupo_usuario') {
                    $responsibleQuery->execute(array('id' => $progressStage['responsavel_id']));
                    $responsibleName = (string) $responsibleQuery->fetchColumn();
                    if ($progressStage['responsavel_tipo'] === 'grupo_usuario') {
                        $responsibleName .= ' + usuários selecionados';
                    }
                } else {
                    $responsibleName = 'Usuários selecionados';
                }
                if (isset($snapshot['modo']) && $snapshot['modo'] === 'E' && isset($snapshot['aprovadores_ids']) && is_array($snapshot['aprovadores_ids'])) {
                    $approvedCount = $pdo->prepare('SELECT COUNT(*) FROM decisoes_aprovacao WHERE etapa_solicitacao_id = :stage_id AND decisao = \'aprovado\'');
                    $approvedCount->execute(array('stage_id' => $progressStage['id']));
                    $approvedNumber = (int) $approvedCount->fetchColumn();
                    $nextId = isset($snapshot['aprovadores_ids'][$approvedNumber]) ? (int) $snapshot['aprovadores_ids'][$approvedNumber] : 0;
                    if ($nextId) {
                        $nextApprover = $pdo->prepare('SELECT nome FROM usuarios WHERE id = :id');
                        $nextApprover->execute(array('id' => $nextId));
                        $responsibleName .= ' · próximo: ' . (string) $nextApprover->fetchColumn();
                    }
                }
                if (!empty($snapshot['aprovadores_adicionais']) && is_array($snapshot['aprovadores_adicionais'])) {
                    $additionalNames = array();
                    foreach ($snapshot['aprovadores_adicionais'] as $additionalApprover) {
                        if (isset($additionalApprover['nome'])) {
                            $additionalNames[] = $additionalApprover['nome'];
                        }
                    }
                    if ($additionalNames) {
                        $responsibleName .= ' · alçadas adicionais: ' . implode(', ', array_unique($additionalNames));
                    }
                }
            }
            if ($responsibleName !== '') {
                $progressDetails[$progressIndex] = 'Responsável: ' . $responsibleName;
            }
        }
    }
}
$integrationIndex = count($progressStages) - 1;
if (isset($progressStages[$integrationIndex]) && $progressStages[$integrationIndex]['codigo_etapa'] === 'integracao_omie') {
    $integrationDetail = $integrationStatus === 'confirmado'
        ? 'Pedido confirmado pelo OMIE.'
        : ($integrationStatus === 'enviado' ? 'Aguardando confirmação do OMIE.' : ($integrationStatus === 'erro' ? 'A integração precisa ser reenviada ou corrigida.' : 'Etapa preparada; o envio ao OMIE será habilitado em uma fase posterior.'));
    if (!empty($request['omie_pedido_id'])) {
        $integrationDetail .= ' ID do pedido OMIE: ' . $request['omie_pedido_id'];
    }
    $progressDetails[$integrationIndex] = $integrationDetail;
}

$valueApprovalQuote = null;
if ($request['etapa_atual'] === 'aprovacao_valor') {
    $quoteQuery = $pdo->prepare('SELECT c.id, c.valor_total, c.moeda, f.razao_social AS fornecedor FROM mapas_cotacao m INNER JOIN cotacoes c ON c.mapa_id = m.id INNER JOIN fornecedores f ON f.id = c.fornecedor_id WHERE m.solicitacao_id = :request_id AND m.versao = (SELECT MAX(m2.versao) FROM mapas_cotacao m2 WHERE m2.solicitacao_id = m.solicitacao_id) AND c.vencedora = 1 ORDER BY c.id DESC LIMIT 1');
    $quoteQuery->execute(array('request_id' => $requestId));
    $valueApprovalQuote = $quoteQuery->fetch();
}

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$requestStatusLabels = array('encerrado_reprovado' => 'Encerrado - Reprovado', 'aprovada' => 'Aprovada', 'em_aprovacao' => 'Em aprovação');
$requestStatusDisplay = isset($requestStatusLabels[$request['status']]) ? $requestStatusLabels[$request['status']] : $request['status'];
?>
<!doctype html>
<html lang="pt-BR">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?php echo h($request['numero']); ?> · Central de Compras</title>
    <link rel="shortcut icon" href="https://centraldeservicos.logos-ma.com.br/plugins/trademark/front/picture.send.php?path=8d/66be65b142d8d.png" type="image/x-icon">
    <link rel="stylesheet" href="assets/css/solicitacao_ver.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.2/font/bootstrap-icons.min.css">
    <style>
        .request-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 22px 0 0;
            border-bottom: 1px solid #e8ebef
        }

        .request-tab {
            padding: 9px 14px;
            border: 1px solid #e8ebef;
            border-bottom: 0;
            border-radius: 8px 8px 0 0;
            background: #fff;
            color: #536071;
            font: inherit;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer
        }

        .request-tab[aria-selected="true"] {
            background: #315be8;
            border-color: #315be8;
            color: #fff
        }

        .request-tabs + [data-request-tab-panel] {
            margin-top: 0
        }

        .progress {
            display: grid;
            grid-template-columns: repeat(<?php echo max(1, count($progressStages)); ?>, minmax(0, 1fr));
            gap: 10px;
            margin: 18px 0
        }
    </style>
</head>

<body>
    <main class="wrap"><a class="back" href="painel.php">← Voltar ao painel</a>
        <div class="request-tabs" role="tablist" aria-label="Seções da solicitação">
            <button class="request-tab" id="request-tab-summary" type="button" role="tab" aria-selected="true" aria-controls="request-panel-summary" tabindex="0">Visão geral</button>
            <button class="request-tab" id="request-tab-items" type="button" role="tab" aria-selected="false" aria-controls="request-panel-items" tabindex="-1">Itens solicitados</button>
            <?php if ($quotationRounds) { ?><button class="request-tab" id="request-tab-quotations" type="button" role="tab" aria-selected="false" aria-controls="request-panel-quotations" tabindex="-1">Mapa de cotação</button><?php } ?>
            <button class="request-tab" id="request-tab-justification" type="button" role="tab" aria-selected="false" aria-controls="request-panel-justification" tabindex="-1">Justificativa</button>
            <?php if (isset($_GET['repetida'])) { ?><button class="request-tab" id="request-tab-notice" type="button" role="tab" aria-selected="false" aria-controls="request-panel-notice" tabindex="-1">Aviso</button><?php } ?>
        </div>
        <section class="panel" id="request-panel-summary" role="tabpanel" aria-labelledby="request-tab-summary" data-request-tab-panel="summary">
            <div class="eyebrow">Solicitação · <?php echo h($request['numero']); ?></div>
            <h1><?php echo h($request['categoria'] ? $request['categoria'] : 'Compra'); ?></h1>
            <span class="status"><?php echo h($requestStatusDisplay); ?></span>
            <?php if ($isQuotationBuyer) { ?>
                <p style="margin:14px 0 0">
                    <a class="status" href="mapa_cotacao.php?id=<?php echo (int) $request['id']; ?>" style="display:inline-block;padding:10px 14px;background:#315be8;color:#fff;text-decoration:none">Gerenciar mapa de cotação</a>
                </p>
            <?php } ?>
            <?php if ($progressStages) { ?>
                <div class="progress" aria-label="Progresso das etapas de aprovação">
                    <?php foreach ($progressStages as $progressIndex => $progressStage) {
                        $stageStatus = strtolower((string) $progressStage['status']);
                        $isRejected = in_array($stageStatus, array('reprovada', 'reprovado'), true);
                        $isDone = !$isRejected && (!empty($progressStage['concluida_em']) || in_array($stageStatus, array('concluida', 'concluido', 'aprovado', 'aprovada', 'finalizada'), true) || ($currentProgressPosition !== null && $progressIndex < $currentProgressPosition));
                        $isCurrent = !$isDone && !$isRejected && (($currentProgressPosition !== null && $progressIndex === $currentProgressPosition) || ($currentProgressPosition === null && in_array($stageStatus, array('pendente', 'em_andamento'), true)));
                        $stateClass = $isRejected ? 'rejected' : ($isDone ? 'done' : ($isCurrent ? 'current' : ''));
                        $waitingLabels = array('solicitacao' => 'Aguardando aprovação da Solicitação', 'cotacao' => 'Aguardando Cotação', 'aprovacao_valor' => 'Aguardando Aprovação de Valor', 'pedido' => 'Aguardando aprovação do Pedido de Compra', 'diretor_alcada' => 'Aguardando aprovação do Diretor', 'integracao_omie' => 'Aguardando integração com o OMIE');
                        $currentStepText = isset($waitingLabels[$progressStage['codigo_etapa']]) ? $waitingLabels[$progressStage['codigo_etapa']] : 'Aguardando andamento';
                        if ($progressStage['codigo_etapa'] === 'integracao_omie' && $integrationStatus === 'enviado') {
                            $currentStepText = 'Pedido enviado ao OMIE';
                        } elseif ($progressStage['codigo_etapa'] === 'integracao_omie' && $integrationStatus === 'erro') {
                            $currentStepText = 'Falha na integração com o OMIE';
                        } elseif ($progressStage['codigo_etapa'] === 'integracao_omie' && $integrationStatus === 'confirmado') {
                            $currentStepText = 'Pedido confirmado pelo OMIE';
                        }
                        $stateText = $isRejected ? 'Reprovada' : ($isDone ? 'Concluída' : ($isCurrent ? 'Etapa ' . ($progressIndex + 1) . ' de ' . count($progressStages) . ' – ' . $currentStepText : 'Próxima'));
                        $stepTooltip = isset($progressDetails[$progressIndex]) ? $progressDetails[$progressIndex] : $currentStepText; ?>

                        <div class="progress-step <?php echo $stateClass; ?>" title="<?php echo h($stepTooltip); ?>" aria-label="<?php echo h($stepTooltip); ?>">
                            <b><?php echo h(isset($progressLabels[$progressStage['codigo_etapa']]) ? $progressLabels[$progressStage['codigo_etapa']] : $progressStage['codigo_etapa']); ?></b>
                            <?php echo h($stateText); ?>
                            <?php if (isset($progressDetails[$progressIndex])) { ?>
                                <small style="display:block;margin-top:4px;font-weight:400"><?php echo h($progressDetails[$progressIndex]); ?></small>
                            <?php } ?>
                        </div>
                    <?php } ?>
                </div>
            <?php } else { ?>
                <p style="margin-top:16px;color:#727b88;font-size:12px">Este Centro de Custo ainda não possui etapas de workflow configuradas.</p>
            <?php } ?>
            <?php if ($slaProgress) { ?>
                <section class="sla-box" aria-label="Indicador de SLA"
                    data-sla-consumed="<?php echo (int) $slaProgress["consumidos_segundos"]; ?>"
                    data-sla-limit="<?php echo (int) $slaProgress["limite_segundos"]; ?>"
                    data-sla-server-now="<?php echo time(); ?>"
                    data-sla-policy="<?php echo htmlspecialchars(json_encode($slaProgress["snapshot"], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES, "UTF-8"); ?>">
                    <div class="sla-heading"><span>SLA da etapa <?php echo h($currentStageDisplay); ?></span><span data-sla-percent><?php echo (int) $slaProgress["percentual"]; ?>%</span></div>
                    <div class="sla-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo (int) $slaProgress["percentual"]; ?>" aria-label="Tempo consumido do SLA">
                        <div data-sla-fill class="sla-fill <?php echo $slaProgress["estourado"] ? "expired" : ""; ?>" style="width:<?php echo (int) $slaProgress["percentual"]; ?>%"></div>
                    </div>
                    <div class="sla-meta"><span>Limite: <?php echo h($slaLimitDisplay); ?> · Vencimento: <?php echo h($slaDeadlineDisplay); ?></span><span data-sla-status><?php echo $slaProgress["estourado"] ? "SLA atingido" : "Em andamento"; ?></span></div>
                </section>
            <?php } ?>
            <div class="grid">
                <div><span class="label">Solicitante</span>
                    <div class="value"><?php echo h($request['solicitante']); ?></div>
                </div>
                <div><span class="label">Centro de custo</span>
                    <div class="value"><?php echo h($request['centro']); ?></div>
                </div>
                <div><span class="label">Etapa atual</span>
                    <div class="value"><?php echo h($currentStageDisplay); ?></div>
                </div>
                <div><span class="label">Prazo estimado</span>
                    <div class="value"><?php echo h($estimatedDateDisplay); ?></div>
                </div>
            </div>
            <?php if ($canManageApprovers) { ?>
                <form method="post" action="solicitacao_alcadas.php" style="margin-top:18px;padding:15px;border:1px solid #e5e9ef;border-radius:9px;background:#fbfcfe">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(applicationCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="solicitacao_id" value="<?php echo (int) $request['id']; ?>">
                    <strong>Incluir alçadas adicionais</strong>
                    <p style="margin:5px 0 12px;color:#727b88;font-size:13px">Inclua grupos ou aprovadores individuais antes da primeira decisão da etapa vigente. Na regra E, os novos aprovadores entram após a hierarquia já configurada; na regra OU, qualquer aprovador pode decidir primeiro e encerrar a pendência dos demais.</p>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px">
                        <label>Grupos de aprovação
                            <select name="grupo_ids[]" multiple size="4" style="display:block;width:100%;margin-top:5px;padding:8px;border:1px solid #dfe3e9;border-radius:8px;font:inherit">
                                <?php foreach ($availableApprovalGroups as $group) { ?><option value="<?php echo (int) $group['id']; ?>"><?php echo h($group['nome']); ?></option><?php } ?>
                            </select>
                        </label>
                        <label>Aprovadores individuais
                            <select name="usuario_ids[]" multiple size="4" style="display:block;width:100%;margin-top:5px;padding:8px;border:1px solid #dfe3e9;border-radius:8px;font:inherit">
                                <?php foreach ($availableApprovers as $approver) { ?><option value="<?php echo (int) $approver['id']; ?>"><?php echo h($approver['nome'] . ' · ' . $approver['matricula']); ?></option><?php } ?>
                            </select>
                        </label>
                    </div>
                    <button type="submit" style="margin-top:12px;border:0;border-radius:8px;background:#315be8;color:#fff;padding:10px 15px;font:inherit;cursor:pointer">Adicionar à etapa</button>
                </form>
            <?php } ?>

            <?php if ($canApprove) { ?>
                <form method="post" action="aprovar_etapa.php" style="margin-top:18px;border-top:1px solid #eef0f3;padding-top:15px">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(applicationCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="solicitacao_id" value="<?php echo (int) $request['id']; ?>">
                    <input type="hidden" name="etapa_id" value="<?php echo (int) $approvalStage['id']; ?>">
                    <?php if ($approvalIntegrityIssues) { ?><div role="alert" style="flex-basis:100%;margin-bottom:12px;padding:12px 14px;border:1px solid #edc8c5;border-radius:8px;background:#fff0ef;color:#8c302b"><strong>A aprovação está bloqueada porque faltam dados:</strong>
                            <ul style="margin:7px 0 0;padding-left:20px"><?php foreach ($approvalIntegrityIssues as $integrityIssue) { ?><li><?php echo h($integrityIssue); ?></li><?php } ?></ul>
                        </div><?php } ?>
                    <div style="display:flex;gap:9px;align-items:center;flex-wrap:wrap">
                        <textarea name="justificativa" rows="2" maxlength="2000" placeholder="Comentário opcional" style="flex:1;min-width:220px;padding:9px;border:1px solid #dfe3e9;border-radius:8px;font:inherit"></textarea>
                        <button class="status" type="submit" name="decisao" value="aprovado" <?php echo $approvalIntegrityIssues ? 'disabled title="Corrija as inconsistências antes de aprovar"' : ''; ?> style="border:0;background:<?php echo $approvalIntegrityIssues ? '#e2e5e9' : '#eaf6f0'; ?>;color:<?php echo $approvalIntegrityIssues ? '#777' : '#176747'; ?>;padding:10px 15px;cursor:<?php echo $approvalIntegrityIssues ? 'not-allowed' : 'pointer'; ?>">Aprovar etapa</button>
                        <button class="status" type="submit" name="decisao" value="reprovado" style="border:0;background:#fff0ef;color:#a03933;padding:10px 15px;cursor:pointer">Reprovar solicitação</button>
                    </div>
                </form>
            <?php } ?>
        </section>
        <section class="panel" id="request-panel-items" role="tabpanel" aria-labelledby="request-tab-items" data-request-tab-panel="items">
            <div class="eyebrow">Itens solicitados</div>
            <?php foreach ($items as $item) { ?>
                <div class="item">
                    <span><?php echo h($item['descricao_snapshot']); ?></span>
                    <strong><?php echo h(str_replace('.', ',', $item['quantidade']) . ' ' . $item['unidade_snapshot']); ?></strong>
                </div>
            <?php } ?>
            <?php if ($request['etapa_atual'] === 'aprovacao_valor') { ?>
                <div class="value-approval-box" style="margin:14px 0;padding:13px 15px;border:1px solid #e5e9ef;border-radius:9px;background:#fbfcfe">
                    <strong>Valor da oferta para aprovação</strong>
                    <?php if ($valueApprovalQuote) { ?>
                        <div style="margin-top:5px"><?php echo h($valueApprovalQuote['moeda']); ?> <?php echo h(number_format((float) $valueApprovalQuote['valor_total'], 2, ',', '.')); ?> · <?php echo h($valueApprovalQuote['fornecedor']); ?></div>
                    <?php } else { ?>
                        <div style="margin-top:5px;color:#a03933">A cotação vencedora ainda não está disponível para aprovação.</div>
                    <?php } ?>
                </div>
            <?php } ?>
        </section>
        <?php if ($quotationRounds) { ?><section class="panel" id="request-panel-quotations" role="tabpanel" aria-labelledby="request-tab-quotations" data-request-tab-panel="quotations">
                <div class="eyebrow">Mapa de cotação · comparativo por rodada</div>
                <?php foreach ($quotationRounds as $round) { ?>
                    <div style="margin-top:16px;padding-top:14px;border-top:1px solid #e8ebef">
                        <h2 style="font-size:17px;margin:0 0 7px">Rodada <?php echo (int) $round['versao']; ?> · <?php echo h(str_replace('_', ' ', ucfirst($round['status']))); ?></h2>
                        <?php if ($round['justificativa_menos_tres']) { ?><p><strong>Justificativa para menos de três fornecedores:</strong> <?php echo h($round['justificativa_menos_tres']); ?></p><?php } ?>
                        <?php if (!$round['ofertas']) { ?><p>Nenhuma oferta registrada nesta rodada.</p>
                        <?php } else {
                            $roundLowestTotal = min(array_map(function ($offer) {
                                return (float) $offer['valor_total'];
                            }, $round['ofertas'])); ?>
                            <div style="overflow-x:auto">
                                <table style="width:100%;border-collapse:collapse;text-align:left">
                                    <thead>
                                        <tr>
                                            <th style="padding:8px">Fornecedor</th>
                                            <th style="padding:8px">Total</th>
                                            <th style="padding:8px">Entrega</th>
                                            <th style="padding:8px">Pagamento</th>
                                            <th style="padding:8px">Frete</th>
                                            <th style="padding:8px">Oferta</th>
                                        </tr>
                                    </thead>
                                    <tbody><?php foreach ($round['ofertas'] as $quoteRow) { ?>
                                            <tr style="border-top:1px solid #e8ebef">
                                                <td style="padding:8px"><strong><?php echo h($quoteRow['fornecedor']); ?></strong><?php if ((float) $quoteRow['valor_total'] === $roundLowestTotal) { ?> <span class="status" style="background:#eaf6f0;color:#176747">Menor valor</span><?php } ?><?php if ((int) $quoteRow['vencedora'] === 1) { ?> <span class="status">Vencedora</span><?php } ?>
                                                    <?php if ($quoteRow['justificativa_escolha']) { ?><div style="font-size:12px;margin-top:5px">Justificativa da escolha: <?php echo h($quoteRow['justificativa_escolha']); ?></div><?php } ?>
                                                </td>
                                                <td style="padding:8px"><?php echo h($quoteRow['moeda']); ?> <?php echo h(number_format((float) $quoteRow['valor_total'], 2, ',', '.')); ?></td>
                                                <td style="padding:8px"><?php echo (int) $quoteRow['prazo_entrega_dias']; ?> dias</td>
                                                <td style="padding:8px"><?php echo h($quoteRow['condicao_pagamento']); ?></td>
                                                <td style="padding:8px"><?php echo h($quoteRow['frete_tipo']); ?></td>
                                                <td style="padding:8px"><?php foreach ($quoteRow['anexos'] as $quoteAttachment) {
                                                    $attachmentId = (int) $quoteAttachment['id'];
                                                    $isPreviewableImage = in_array($quoteAttachment['mime_type'], array('image/jpeg', 'image/png'), true);
                                                    if ($isPreviewableImage) {
                                                        $downloadPrefix = $request['numero'] . '_' . $quoteRow['fornecedor'];
                                                        $previewUrl = 'anexo_baixar.php?id=' . $attachmentId . '&visualizar=1';
                                                        $downloadUrl = 'anexo_baixar.php?id=' . $attachmentId . '&nome_download=' . rawurlencode($downloadPrefix);
                                                        ?>
                                                        <div>
                                                            <button class="quote-image-preview" type="button" 
                                                                data-image-url="<?php echo h($previewUrl); ?>" 
                                                                data-download-url="<?php echo h($downloadUrl); ?>" 
                                                                data-image-title="<?php echo h($request['numero'] . ' · ' . $quoteRow['fornecedor']); ?>" 
                                                                aria-label="Visualizar imagem da oferta de <?php echo h($quoteRow['fornecedor']); ?>" 
                                                                title="Visualizar imagem">
                                                                <i class="bi bi-eye" aria-hidden="true"></i>
                                                            </button>
                                                        </div>
                                                    <?php } else { ?>
                                                        <div><a href="anexo_baixar.php?id=<?php echo $attachmentId; ?>" aria-label="Baixar arquivo da oferta de <?php echo h($quoteRow['fornecedor']); ?>" title="Baixar arquivo"><i class="bi bi-download" aria-hidden="true"></i></a></div>
                                                    <?php }
                                                } ?></td>
                                            </tr>
                                            <tr>
                                                <td colspan="6" style="padding:0 8px 10px">
                                                    <details>
                                                        <summary>Preços por item</summary>
                                                        <div style="padding:8px 0">
                                                            <?php foreach ($quoteRow['itens'] as $quotedItem) { ?>
                                                                <div>
                                                                    <?php echo h($quotedItem['descricao_snapshot']); ?> · <?php echo h(number_format((float) $quotedItem['valor_unitario'], 4, ',', '.')); ?><?php echo h($quotedItem['unidade_snapshot']); ?><br>
                                                                    quantidade · <?php echo h(number_format((float) $quotedItem['quantidade'], 3, ',', '.')); ?>
                                                                </div>
                                                            <?php } ?>
                                                        </div>
                                                    </details>
                                                </td>
                                            </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php } ?>
                    </div>
                <?php } ?>
            </section><?php } ?>
        <section class="panel" id="request-panel-justification" role="tabpanel" aria-labelledby="request-tab-justification" data-request-tab-panel="justification">
            <div class="eyebrow">Justificativa</div>
            <p class="text"><?php echo h($request['justificativa']); ?></p>
        </section><?php if (isset($_GET['repetida'])) { ?><section class="panel" id="request-panel-notice" role="tabpanel" aria-labelledby="request-tab-notice" data-request-tab-panel="notice" style="background:#eff8f3;color:#176747">Este envio já havia sido registrado. Mostrando a solicitação existente para evitar duplicidade.</section><?php } ?>
    </main>
    <dialog class="quote-image-modal" id="quoteImageModal" aria-labelledby="quoteImageTitle">
        <div class="quote-image-modal-header">
            <h2 id="quoteImageTitle"></h2>
            <button type="button" class="quote-image-modal-close" data-close-quote-image aria-label="Fechar visualização">&times;</button>
        </div>
        <div class="quote-image-modal-body"><img id="quoteImagePreview" alt=""></div>
        <div class="quote-image-modal-actions">
            <a class="quote-image-download" id="quoteImageDownload" href=""><i class="bi bi-download" aria-hidden="true"></i> Baixar imagem</a>
            <button type="button" data-close-quote-image>Fechar</button>
        </div>
    </dialog>
    <script>
        (function() {
            var modal = document.getElementById('quoteImageModal');
            var image = document.getElementById('quoteImagePreview');
            var title = document.getElementById('quoteImageTitle');
            var download = document.getElementById('quoteImageDownload');
            if (!modal || !image || !title || !download) return;

            document.querySelectorAll('.quote-image-preview').forEach(function(button) {
                button.addEventListener('click', function() {
                    image.src = button.getAttribute('data-image-url');
                    image.alt = button.getAttribute('data-image-title');
                    title.textContent = button.getAttribute('data-image-title');
                    download.href = button.getAttribute('data-download-url');
                    modal.showModal();
                });
            });
            modal.querySelectorAll('[data-close-quote-image]').forEach(function(button) {
                button.addEventListener('click', function() {
                    modal.close();
                });
            });
            modal.addEventListener('close', function() {
                image.removeAttribute('src');
                download.removeAttribute('href');
            });
            modal.addEventListener('click', function(event) {
                if (event.target === modal) modal.close();
            });
        }());
    </script>
    <script>
        (function() {
            var tabs = Array.prototype.slice.call(document.querySelectorAll('[role="tab"][aria-controls^="request-panel-"]'));
            if (!tabs.length) return;

            var panels = tabs.map(function(tab) {
                return document.getElementById(tab.getAttribute('aria-controls'));
            }).filter(Boolean);

            function activateTab(tab, moveFocus) {
                tabs.forEach(function(candidate) {
                    var selected = candidate === tab;
                    candidate.setAttribute('aria-selected', selected ? 'true' : 'false');
                    candidate.setAttribute('tabindex', selected ? '0' : '-1');
                    if (moveFocus && selected) candidate.focus();
                });
                panels.forEach(function(panel) {
                    panel.hidden = panel.id !== tab.getAttribute('aria-controls');
                });
            }

            tabs.forEach(function(tab, index) {
                tab.addEventListener('click', function() {
                    activateTab(tab, false);
                });
                tab.addEventListener('keydown', function(event) {
                    var nextIndex = index;
                    if (event.key === 'ArrowRight') nextIndex = (index + 1) % tabs.length;
                    else if (event.key === 'ArrowLeft') nextIndex = (index - 1 + tabs.length) % tabs.length;
                    else if (event.key === 'Home') nextIndex = 0;
                    else if (event.key === 'End') nextIndex = tabs.length - 1;
                    else return;
                    event.preventDefault();
                    activateTab(tabs[nextIndex], true);
                });
            });
            activateTab(tabs[0], false);
        }());
    </script>
    <script>
        (function() {
            var box = document.querySelector("[data-sla-policy]");
            if (!box) return;
            var policy;
            try {
                policy = JSON.parse(box.getAttribute("data-sla-policy"));
            } catch (error) {
                return;
            }
            var consumed = Number(box.getAttribute("data-sla-consumed")) || 0;
            var limit = Number(box.getAttribute("data-sla-limit")) || 0;
            var serverNow = Number(box.getAttribute("data-sla-server-now")) || Math.floor(Date.now() / 1000);
            var clientStart = Date.now();
            var lastTimestamp = serverNow;
            var fill = box.querySelector("[data-sla-fill]");
            var percentLabel = box.querySelector("[data-sla-percent]");
            var statusLabel = box.querySelector("[data-sla-status]");
            var weekdayNumbers = {
                Mon: 1,
                Tue: 2,
                Wed: 3,
                Thu: 4,
                Fri: 5,
                Sat: 6,
                Sun: 7
            };
            var holidays = {};
            (policy.feriados || []).forEach(function(date) {
                holidays[date] = true;
            });
            var formatter = new Intl.DateTimeFormat("en-US", {
                timeZone: policy.fuso_horario,
                weekday: "short",
                year: "numeric",
                month: "2-digit",
                day: "2-digit",
                hour: "2-digit",
                minute: "2-digit",
                second: "2-digit",
                hourCycle: "h23"
            });

            function isWorking(timestamp) {
                if (policy.unidade_tempo === "dias" && policy.regime_contagem === "corridos") return true;
                var parts = {};
                formatter.formatToParts(new Date(timestamp * 1000)).forEach(function(part) {
                    parts[part.type] = part.value;
                });
                var day = weekdayNumbers[parts.weekday];
                var date = parts.year + "-" + parts.month + "-" + parts.day;
                var clock = Number(parts.hour) * 3600 + Number(parts.minute) * 60 + Number(parts.second);
                var startParts = policy.janela_inicio.split(":").map(Number);
                var endParts = policy.janela_fim.split(":").map(Number);
                var start = startParts[0] * 3600 + startParts[1] * 60 + (startParts[2] || 0);
                var end = endParts[0] * 3600 + endParts[1] * 60 + (endParts[2] || 0);
                return (policy.dias_semana || []).indexOf(day) !== -1 && !holidays[date] && clock >= start && clock < end;
            }

            function render() {
                var now = serverNow + Math.floor((Date.now() - clientStart) / 1000);
                var delta = Math.max(0, now - lastTimestamp);
                if (policy.unidade_tempo === "dias" && policy.regime_contagem === "corridos") {
                    consumed += delta;
                } else {
                    for (var offset = 0; offset < delta; offset += 30) {
                        var chunk = Math.min(30, delta - offset);
                        if (isWorking(lastTimestamp + offset + Math.floor(chunk / 2))) consumed += chunk;
                    }
                }
                lastTimestamp = now;
                var percent = limit ? Math.min(100, Math.floor(consumed * 100 / limit)) : 0;
                fill.style.width = percent + "%";
                fill.classList.toggle("expired", percent >= 100);
                percentLabel.textContent = percent + "%";
                box.querySelector(".sla-track").setAttribute("aria-valuenow", percent);
                statusLabel.textContent = percent >= 100 ? "SLA atingido" : "Em andamento";
            }
            window.setInterval(render, 15000);
        }());
    </script>
</body>

</html>