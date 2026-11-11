<?php
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/database.php';
require_once __DIR__ . '/app/authorization.php';
require_once __DIR__ . '/app/sla.php';
require_once __DIR__ . '/app/user_menu.php';
startApplicationSession();
requireLocalUser();

$pdo = databaseConnection();
$profiles = loadActiveProfiles($pdo, $_SESSION['user_id']);
$isAdministrator = userHasProfile($profiles, 'administrador');
$canViewDecisionHistory = $isAdministrator || userHasProfile($profiles, 'gestor') || userHasProfile($profiles, 'diretor');
$roleNames = array();
foreach ($profiles as $profile) {
    if (in_array($profile['codigo'], array('solicitante', 'comprador', 'gestor', 'diretor'), true)) {
        $roleNames[$profile['codigo']] = $profile['nome'];
    }
}
if ($isAdministrator) {
    $roleNames['administrador'] = 'Administrador';
}
$pendingApprovalSql = 'SELECT es.id AS etapa_id, es.responsavel_tipo, es.responsavel_id, es.regra_snapshot, s.id, s.numero, s.etapa_atual, s.criada_em, cc.nome AS centro, u.nome AS solicitante, (SELECT COUNT(*) FROM decisoes_aprovacao da WHERE da.etapa_solicitacao_id = es.id AND da.decisao = \'aprovado\') AS aprovacoes_feitas FROM etapas_solicitacao es INNER JOIN solicitacoes s ON s.id = es.solicitacao_id INNER JOIN usuarios u ON u.id = s.solicitante_id INNER JOIN centros_custo cc ON cc.id = s.centro_custo_id WHERE es.status = \'pendente\' AND s.status = \'em_aprovacao\'';
if (!$isAdministrator) {
    $pendingApprovalSql .= ' AND NOT EXISTS (SELECT 1 FROM decisoes_aprovacao da WHERE da.etapa_solicitacao_id = es.id AND da.aprovador_id = :decision_user_id)';
}
$pendingApprovalQuery = $pdo->prepare($pendingApprovalSql . ' ORDER BY s.criada_em ASC');
$pendingApprovalQuery->execute($isAdministrator ? array() : array('decision_user_id' => $_SESSION['user_id']));
$pendingApprovalRows = array();
foreach ($pendingApprovalQuery->fetchAll() as $pendingStage) {
    $snapshot = json_decode($pendingStage['regra_snapshot'], true);
    $approverIds = isset($snapshot['aprovadores_ids']) && is_array($snapshot['aprovadores_ids']) ? array_values(array_map('intval', $snapshot['aprovadores_ids'])) : array();
    $member = false;
    if (in_array($pendingStage['responsavel_tipo'], array('grupo', 'usuario', 'grupo_usuario'), true)) {
        if (!$approverIds && $pendingStage['responsavel_tipo'] === 'usuario') {
            $approverIds = array((int) $pendingStage['responsavel_id']);
        }
        $member = in_array((int) $_SESSION['user_id'], $approverIds, true);
    }
    if ($isAdministrator) {
        $pendingApprovalRows[] = $pendingStage;
        continue;
    }
    if (!$member) {
        continue;
    }
    if (isset($snapshot['modo']) && $snapshot['modo'] === 'OU') {
        $mayAct = in_array((int) $_SESSION['user_id'], $approverIds, true);
    } else {
        $approvalIndex = (int) $pendingStage['aprovacoes_feitas'];
        $nextApprover = isset($approverIds[$approvalIndex]) ? (int) $approverIds[$approvalIndex] : null;
        $mayAct = $nextApprover === (int) $_SESSION['user_id'];
    }
    if ($mayAct) {
        $pendingApprovalRows[] = $pendingStage;
    }
}
$collegiateQuery = $pdo->prepare('SELECT es.id AS etapa_id, es.codigo_etapa, s.id, s.numero, s.etapa_atual, s.criada_em, cc.nome AS centro, requester.nome AS solicitante, winner.nome AS aprovado_por FROM etapas_solicitacao es INNER JOIN solicitacoes s ON s.id = es.solicitacao_id INNER JOIN centros_custo cc ON cc.id = s.centro_custo_id INNER JOIN usuarios requester ON requester.id = s.solicitante_id INNER JOIN decisoes_aprovacao da ON da.etapa_solicitacao_id = es.id AND da.decisao = \'aprovado\' INNER JOIN usuarios winner ON winner.id = da.aprovador_id WHERE es.status = \'aprovada\' AND JSON_UNQUOTE(JSON_EXTRACT(es.regra_snapshot, \'$.modo\')) = \'OU\' AND JSON_CONTAINS(es.regra_snapshot, JSON_ARRAY(CAST(:snapshot_user_id AS UNSIGNED)), \'$.aprovadores_ids\') AND da.aprovador_id <> :winner_user_id ORDER BY da.criada_em DESC, da.id DESC LIMIT 50');
$collegiateQuery->execute(array('snapshot_user_id' => $_SESSION['user_id'], 'winner_user_id' => $_SESSION['user_id']));
$collegiateRows = $collegiateQuery->fetchAll();
if (($pendingApprovalRows || $collegiateRows) && !isset($roleNames['gestor'])) {
    $roleNames['aprovador'] = 'Aprovador';
}
$selectedRole = isset($_GET['perfil']) && is_string($_GET['perfil']) ? $_GET['perfil'] : '';
if (!isset($roleNames[$selectedRole])) {
    $rolePriority = isset($roleNames['gestor'])
        ? array('administrador', 'gestor', 'aprovador', 'diretor', 'comprador', 'solicitante')
        : array('administrador', 'aprovador', 'diretor', 'comprador', 'solicitante');
    foreach ($rolePriority as $candidate) {
        if (isset($roleNames[$candidate])) {
            $selectedRole = $candidate;
            break;
        }
    }
}
if (!isset($roleNames[$selectedRole])) {
    http_response_code(403);
    exit('Seu usuário ainda não possui um perfil de negócio ativo.');
}
$managerSections = array(
    'resumo' => array('title' => 'Visão geral', 'description' => 'Acompanhe um resumo das atividades no seu escopo.'),
    'contratos' => array('title' => 'Contratos', 'description' => 'Consulte os contratos vinculados aos seus centros de custo.'),
    'aprovacoes' => array('title' => 'Aprovações pendentes', 'description' => 'Consulte as solicitações que aguardam sua aprovação.'),
    'historico' => array('title' => 'Histórico de solicitações', 'description' => 'Consulte as decisões registradas por você.')
);
$directorSections = array(
    'resumo' => array('title' => 'Dashboard', 'description' => 'Acompanhe um resumo das solicitações e contratos da organização.'),
    'contratos' => array('title' => 'Contratos', 'description' => 'Consulte os contratos da organização.'),
    'solicitacoes' => array('title' => 'Solicitações', 'description' => 'Consulte as solicitações da organização.')
);
$administratorSections = array(
    'resumo' => array('title' => 'Dashboard', 'description' => 'Acompanhe os principais indicadores da organização.'),
    'solicitacoes' => array('title' => 'Solicitações', 'description' => 'Consulte as solicitações da organização.'),
    'minhas_solicitacoes' => array('title' => 'Minhas solicitações', 'description' => 'Consulte as solicitações abertas por você.'),
    'contratos' => array('title' => 'Contratos', 'description' => 'Consulte os contratos da organização.'),
    'aprovacoes' => array('title' => 'Aprovações pendentes', 'description' => 'Consulte as etapas de aprovação pendentes. As decisões só podem ser registradas por aprovadores designados.'),
    'historico' => array('title' => 'Histórico de solicitações', 'description' => 'Consulte as decisões de aprovação registradas por você.')
);
$selectedSection = 'resumo';
if ($selectedRole === 'gestor' && isset($_GET['secao']) && is_string($_GET['secao']) && isset($managerSections[$_GET['secao']])) {
    $selectedSection = $_GET['secao'];
}
if ($selectedRole === 'diretor' && isset($_GET['secao']) && is_string($_GET['secao']) && isset($directorSections[$_GET['secao']])) {
    $selectedSection = $_GET['secao'];
}
if ($selectedRole === 'administrador' && isset($_GET['secao']) && is_string($_GET['secao']) && isset($administratorSections[$_GET['secao']])) {
    $selectedSection = $_GET['secao'];
}
$hasSidebar = in_array($selectedRole, array('gestor', 'solicitante', 'comprador', 'diretor', 'administrador'), true);

$rows = array();
$contractRows = array();
$approvalRows = array();
if (in_array($selectedRole, array('diretor', 'administrador'), true)) {
    $rows = $pdo->query('SELECT s.id, s.numero, s.status, s.etapa_atual, s.criada_em, u.nome AS pessoa, cc.nome AS centro FROM solicitacoes s INNER JOIN usuarios u ON u.id = s.solicitante_id INNER JOIN centros_custo cc ON cc.id = s.centro_custo_id ORDER BY s.criada_em DESC LIMIT 50')->fetchAll();
    $contractRows = $pdo->query('SELECT c.id, c.numero_omie, c.descricao, c.status, c.valor_total, cc.nome AS centro, f.razao_social AS fornecedor FROM contratos c INNER JOIN centros_custo cc ON cc.id = c.centro_custo_id LEFT JOIN fornecedores f ON f.id = c.fornecedor_id ORDER BY c.criado_em DESC LIMIT 50')->fetchAll();
}
if ($selectedRole === 'solicitante') {
    $statement = $pdo->prepare('SELECT s.id, s.numero, s.status, s.etapa_atual, s.criada_em, cc.nome AS centro FROM solicitacoes s INNER JOIN centros_custo cc ON cc.id = s.centro_custo_id WHERE s.solicitante_id = :user_id ORDER BY s.criada_em DESC LIMIT 50');
    $statement->execute(array('user_id' => $_SESSION['user_id']));
    $rows = $statement->fetchAll();
} elseif ($selectedRole === 'comprador') {
    $statement = $pdo->prepare('SELECT s.id, s.numero, s.status, s.etapa_atual, s.comprador_id, s.criada_em, u.nome AS pessoa, cc.nome AS centro FROM solicitacoes s INNER JOIN usuarios u ON u.id = s.solicitante_id INNER JOIN centros_custo cc ON cc.id = s.centro_custo_id WHERE s.comprador_id = :assigned_user OR (s.status = \'em_aprovacao\' AND EXISTS (SELECT 1 FROM usuario_perfis up INNER JOIN perfis p ON p.id = up.perfil_id WHERE up.usuario_id = :scope_user AND p.codigo = \'comprador\' AND p.ativo = 1 AND (up.valido_ate IS NULL OR up.valido_ate >= CURDATE()) AND ( (up.centro_custo_id IS NULL AND up.unidade_id IS NULL) OR up.centro_custo_id = s.centro_custo_id OR up.unidade_id = cc.unidade_id ))) ORDER BY s.criada_em DESC LIMIT 50');
    $statement->execute(array('assigned_user' => $_SESSION['user_id'], 'scope_user' => $_SESSION['user_id']));
    $rows = $statement->fetchAll();
} elseif ($selectedRole === 'gestor') {
    $statement = $pdo->prepare('SELECT c.id, c.descricao, c.numero_omie, c.status, c.valor_total, c.fim_vigencia, cc.nome AS centro, f.razao_social AS fornecedor FROM contratos c INNER JOIN centros_custo cc ON cc.id = c.centro_custo_id LEFT JOIN fornecedores f ON f.id = c.fornecedor_id WHERE EXISTS (SELECT 1 FROM usuario_perfis up INNER JOIN perfis p ON p.id = up.perfil_id WHERE up.usuario_id = :user_id AND p.codigo = \'gestor\' AND p.ativo = 1 AND (up.valido_ate IS NULL OR up.valido_ate >= CURDATE()) AND ((up.centro_custo_id IS NOT NULL AND up.centro_custo_id = c.centro_custo_id) OR (up.unidade_id IS NOT NULL AND up.unidade_id = cc.unidade_id))) ORDER BY c.criado_em DESC LIMIT 50');
    $statement->execute(array('user_id' => $_SESSION['user_id']));
    $rows = $statement->fetchAll();
}

$approvalRows = array_slice($pendingApprovalRows, 0, 50);
foreach ($approvalRows as $approvalIndex => $approvalRow) {
    $approvalRows[$approvalIndex]['sla'] = slaProgressForStage($pdo, $approvalRow['etapa_id']);
}
$myRequestRows = array();
if ($selectedRole === 'administrador' && $selectedSection === 'minhas_solicitacoes') {
    $statement = $pdo->prepare('SELECT s.id, s.numero, s.status, s.etapa_atual, s.criada_em, cc.nome AS centro FROM solicitacoes s INNER JOIN centros_custo cc ON cc.id = s.centro_custo_id WHERE s.solicitante_id = :user_id ORDER BY s.criada_em DESC LIMIT 50');
    $statement->execute(array('user_id' => $_SESSION['user_id']));
    $myRequestRows = $statement->fetchAll();
}
$omieLastSyncAt = null;
if ($selectedRole === 'administrador' && $selectedSection === 'resumo') {
    $omieSyncQuery = $pdo->query(
        'SELECT MAX(sync_at) FROM (
            SELECT MAX(sincronizado_em) AS sync_at FROM unidades
            UNION ALL SELECT MAX(sincronizado_em) FROM centros_custo
            UNION ALL SELECT MAX(sincronizado_em) FROM categorias
            UNION ALL SELECT MAX(sincronizado_em) FROM produtos
            UNION ALL SELECT MAX(sincronizado_em) FROM fornecedores
        ) AS catalog_sync_dates'
    );
    $omieLastSyncAt = $omieSyncQuery->fetchColumn();
}
$decisionRows = array();
if ($canViewDecisionHistory) {
    $decisionHistoryQuery = $pdo->prepare('SELECT s.numero, s.status AS solicitacao_status, u.nome AS solicitante, cc.nome AS centro, es.codigo_etapa, da.decisao, da.criada_em FROM decisoes_aprovacao da INNER JOIN etapas_solicitacao es ON es.id = da.etapa_solicitacao_id INNER JOIN solicitacoes s ON s.id = es.solicitacao_id INNER JOIN usuarios u ON u.id = s.solicitante_id INNER JOIN centros_custo cc ON cc.id = s.centro_custo_id WHERE da.aprovador_id = :user_id ORDER BY da.criada_em DESC, da.id DESC LIMIT 50');
    $decisionHistoryQuery->execute(array('user_id' => $_SESSION['user_id']));
    $decisionRows = $decisionHistoryQuery->fetchAll();
}

$displayName = htmlspecialchars(isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Usuário', ENT_QUOTES, 'UTF-8');
$selectedName = htmlspecialchars($roleNames[$selectedRole], ENT_QUOTES, 'UTF-8');
function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
function stageDisplayName($stageCode)
{
    $stageNames = array('solicitacao' => 'Solicitação', 'cotacao' => 'Mapa de cotação', 'aprovacao_valor' => 'Aprovação de valor', 'pedido' => 'Pedido de compra', 'diretor_alcada' => 'Aprovação do Diretor');
    return isset($stageNames[$stageCode]) ? $stageNames[$stageCode] : ($stageCode !== null && $stageCode !== '' ? ucfirst(str_replace('_', ' ', $stageCode)) : '—');
}
function formatBrazilianDateTime($value)
{
    $dateTime = DateTime::createFromFormat('Y-m-d H:i:s', (string) $value);
    return $dateTime ? $dateTime->format('d/m/Y H:i:s') : (string) $value;
}
function formatSlaDeadline($value)
{
    if ($value === null || $value === '') {
        return '—';
    }
    $dateTime = DateTime::createFromFormat('Y-m-d H:i:s', (string) $value, new DateTimeZone('America/Sao_Paulo'));
    return $dateTime ? $dateTime->format('d/m H:i') : (string) $value;
}
?>
<!doctype html>
<html lang="pt-BR">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Meu painel · Central de Compras</title>
    <link rel="shortcut icon" href="https://centraldeservicos.logos-ma.com.br/plugins/trademark/front/picture.send.php?path=8d/66be65b142d8d.png" type="image/x-icon">
    <?php if ($hasSidebar) { ?><link rel="stylesheet" href="assets/css/admin.css"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.2/font/bootstrap-icons.min.css"><?php } ?>
    <link rel="stylesheet" href="assets/css/painel.css">
    <link rel="stylesheet" href="assets/css/account.css">
</head>

<body>
    <?php if ($hasSidebar) { ?>
    <main class="admin-layout" id="adminLayout">
        <aside class="sidebar" id="adminSidebar">
            <div class="sidebar-brand">
                <img class="sidebar-brand-expanded" src="assets/images/logos.png" alt="Logos">
                <img class="sidebar-brand-collapsed" src="https://centraldeservicos.logos-ma.com.br/plugins/trademark/front/picture.send.php?path=8d/66be65b142d8d.png" alt="Logos">
                <p style="color:#fff">Central de Compras</p>
            </div>
            <br>
            <button class="sidebar-toggle" id="sidebarToggle" type="button" aria-controls="adminSidebar" aria-expanded="true" aria-label="Recolher menu lateral">
                <span class="sidebar-toggle-icon" aria-hidden="true"><i class="bi bi-chevron-double-left"></i></span>
            </button>
            <nav class="sidebar-nav" aria-label="<?php echo $selectedRole === 'gestor' ? 'Seções do painel do Gestor' : ($selectedRole === 'diretor' ? 'Seções do painel do Diretor' : ($selectedRole === 'administrador' ? 'Visões disponíveis ao Administrador' : 'Seções do painel do Solicitante')); ?>">
                <?php if ($selectedRole === 'solicitante') { ?>
                <a class="sidebar-link active" href="painel.php?perfil=solicitante" aria-current="page" title="Visão geral">
                    <i class="bi bi-speedometer2"></i><span class="sidebar-link-label">Visão geral</span>
                </a>
                <a class="sidebar-link" href="solicitacao_nova.php" title="Nova solicitação">
                    <i class="bi bi-plus-circle"></i><span class="sidebar-link-label">Nova solicitação</span>
                </a>

                <?php } elseif ($selectedRole === 'comprador') { ?>

                    <a class="sidebar-link active"
                    href="painel.php?perfil=comprador"
                    aria-current="page"
                    title="Dashboard">
                        <i class="bi bi-speedometer2"></i>
                        <span class="sidebar-link-label">Dashboard</span>
                    </a>

                <?php } elseif ($selectedRole === 'administrador') { ?>
                <details class="sidebar-group">
                    <summary class="sidebar-link sidebar-group-toggle" title="Administração">
                        <i class="bi bi-gear"></i><span class="sidebar-link-label">Administração</span><i class="bi bi-chevron-down sidebar-group-chevron" aria-hidden="true"></i>
                    </summary>
                    <div class="sidebar-group-links">
                        <a class="sidebar-link" href="admin.php?secao=usuarios" title="Gerenciamento de usuários">
                            <i class="bi bi-person-gear"></i><span class="sidebar-link-label">Gerenciamento de usuários</span>
                        </a>
                        <a class="sidebar-link" href="admin.php?secao=produtos" title="Gerenciamento de produtos">
                            <i class="bi bi-box-seam"></i><span class="sidebar-link-label">Gerenciamento de produtos</span>
                        </a>
                        <a class="sidebar-link" href="admin.php?secao=categorias" title="Gerenciamento de categorias">
                            <i class="bi bi-tags"></i><span class="sidebar-link-label">Gerenciamento de categorias</span>
                        </a>
                        <a class="sidebar-link" href="admin.php?secao=grupos" title="Grupos e membros aprovadores">
                            <i class="bi bi-people"></i><span class="sidebar-link-label">Grupos e membros aprovadores</span>
                        </a>
                        <a class="sidebar-link" href="admin.php?secao=calendarios" title="Calendários de trabalho e feriados">
                            <i class="bi bi-calendar3"></i><span class="sidebar-link-label">Calendários de trabalho e feriados</span>
                        </a>
                        <a class="sidebar-link" href="admin.php?secao=trilhas" title="Trilha por Centro de Custo">
                            <i class="bi bi-currency-dollar"></i><span class="sidebar-link-label">Trilha por Centro de Custo</span>
                        </a>
                        <a class="sidebar-link" href="admin.php?secao=logs" title="Logs do sistema">
                            <i class="bi bi-journal-text"></i><span class="sidebar-link-label">Logs do sistema</span>
                        </a>
                    </div>
                </details>
                <details class="sidebar-group" open>
                    <summary class="sidebar-link sidebar-group-toggle" title="Sistema">
                        <i class="bi bi-grid"></i><span class="sidebar-link-label">Sistema</span><i class="bi bi-chevron-down sidebar-group-chevron" aria-hidden="true"></i>
                    </summary>
                    <div class="sidebar-group-links">
                        <a class="sidebar-link<?php echo $selectedSection === 'resumo' ? ' active' : ''; ?>" href="painel.php?perfil=administrador&amp;secao=resumo" <?php echo $selectedSection === 'resumo' ? 'aria-current="page"' : ''; ?> title="Dashboard">
                            <i class="bi bi-speedometer2"></i><span class="sidebar-link-label">Dashboard</span>
                        </a>
                        <a class="sidebar-link<?php echo $selectedSection === 'solicitacoes' ? ' active' : ''; ?>" href="painel.php?perfil=administrador&amp;secao=solicitacoes" <?php echo $selectedSection === 'solicitacoes' ? 'aria-current="page"' : ''; ?> title="Solicitações">
                            <i class="bi bi-clipboard-check"></i><span class="sidebar-link-label">Solicitações</span>
                        </a>
                        <a class="sidebar-link<?php echo $selectedSection === 'minhas_solicitacoes' ? ' active' : ''; ?>" href="painel.php?perfil=administrador&amp;secao=minhas_solicitacoes" <?php echo $selectedSection === 'minhas_solicitacoes' ? 'aria-current="page"' : ''; ?> title="Minhas solicitações">
                            <i class="bi bi-person-lines-fill"></i><span class="sidebar-link-label">Minhas solicitações</span>
                        </a>
                        <a class="sidebar-link<?php echo $selectedSection === 'contratos' ? ' active' : ''; ?>" href="painel.php?perfil=administrador&amp;secao=contratos" <?php echo $selectedSection === 'contratos' ? 'aria-current="page"' : ''; ?> title="Contratos">
                            <i class="bi bi-file-earmark-text"></i><span class="sidebar-link-label">Contratos</span>
                        </a>
                        <a class="sidebar-link<?php echo $selectedSection === 'aprovacoes' ? ' active' : ''; ?>" href="painel.php?perfil=administrador&amp;secao=aprovacoes" <?php echo $selectedSection === 'aprovacoes' ? 'aria-current="page"' : ''; ?> title="Aprovações pendentes">
                            <i class="bi bi-check2-square"></i><span class="sidebar-link-label">Aprovações pendentes</span>
                        </a>
                        <a class="sidebar-link<?php echo $selectedSection === 'historico' ? ' active' : ''; ?>" href="painel.php?perfil=administrador&amp;secao=historico" <?php echo $selectedSection === 'historico' ? 'aria-current="page"' : ''; ?> title="Histórico de solicitações">
                            <i class="bi bi-clock-history"></i><span class="sidebar-link-label">Histórico de solicitações</span>
                        </a>
                    </div>
                </details>
                <?php } elseif ($selectedRole === 'diretor') { ?>
                <a class="sidebar-link<?php echo $selectedSection === 'resumo' ? ' active' : ''; ?>" href="painel.php?perfil=diretor&amp;secao=resumo" <?php echo $selectedSection === 'resumo' ? 'aria-current="page"' : ''; ?> title="Dashboard">
                    <i class="bi bi-speedometer2"></i><span class="sidebar-link-label">Dashboard</span>
                </a>
                <a class="sidebar-link<?php echo $selectedSection === 'contratos' ? ' active' : ''; ?>" href="painel.php?perfil=diretor&amp;secao=contratos" <?php echo $selectedSection === 'contratos' ? 'aria-current="page"' : ''; ?> title="Contratos">
                    <i class="bi bi-file-earmark-text"></i><span class="sidebar-link-label">Contratos</span>
                </a>
                <a class="sidebar-link<?php echo $selectedSection === 'solicitacoes' ? ' active' : ''; ?>" href="painel.php?perfil=diretor&amp;secao=solicitacoes" <?php echo $selectedSection === 'solicitacoes' ? 'aria-current="page"' : ''; ?> title="Solicitações">
                    <i class="bi bi-clipboard-check"></i><span class="sidebar-link-label">Solicitações</span>
                </a>
                <?php } else { ?>
                <a class="sidebar-link<?php echo $selectedSection === 'resumo' ? ' active' : ''; ?>" href="painel.php?perfil=gestor&amp;secao=resumo" <?php echo $selectedSection === 'resumo' ? 'aria-current="page"' : ''; ?> title="Visão geral">
                    <i class="bi bi-speedometer2"></i><span class="sidebar-link-label">Visão geral</span>
                </a>
                <a class="sidebar-link<?php echo $selectedSection === 'contratos' ? ' active' : ''; ?>" href="painel.php?perfil=gestor&amp;secao=contratos" <?php echo $selectedSection === 'contratos' ? 'aria-current="page"' : ''; ?> title="Contratos">
                    <i class="bi bi-file-earmark-text"></i><span class="sidebar-link-label">Contratos</span>
                </a>
                <a class="sidebar-link<?php echo $selectedSection === 'aprovacoes' ? ' active' : ''; ?>" href="painel.php?perfil=gestor&amp;secao=aprovacoes" <?php echo $selectedSection === 'aprovacoes' ? 'aria-current="page"' : ''; ?> title="Aprovações pendentes">
                    <i class="bi bi-check2-square"></i><span class="sidebar-link-label">Aprovações pendentes</span>
                </a>
                <a class="sidebar-link<?php echo $selectedSection === 'historico' ? ' active' : ''; ?>" href="painel.php?perfil=gestor&amp;secao=historico" <?php echo $selectedSection === 'historico' ? 'aria-current="page"' : ''; ?> title="Histórico de solicitações">
                    <i class="bi bi-clock-history"></i><span class="sidebar-link-label">Histórico de solicitações</span>
                </a>
                <?php } ?>
                <details class="sidebar-group">
                    <summary class="sidebar-link sidebar-group-toggle" title="Minha conta">
                        <i class="bi bi-person-circle"></i><span class="sidebar-link-label">Minha conta</span><i class="bi bi-chevron-down sidebar-group-chevron" aria-hidden="true"></i>
                    </summary>
                    <div class="sidebar-group-links">
                        <a class="sidebar-link" href="meu_perfil.php" title="Meu perfil">
                            <i class="bi bi-person"></i><span class="sidebar-link-label">Meu perfil</span>
                        </a>
                        <a class="sidebar-link" href="ajuda.php" title="Ajuda">
                            <i class="bi bi-question-circle"></i><span class="sidebar-link-label">Ajuda</span>
                        </a>
                    </div>
                </details>
            </nav>
        </aside>
        <div class="admin-content">
            <header class="top">
                <div class="brand"><?php echo $selectedRole === 'gestor' ? 'Painel do Gestor' : ($selectedRole === 'diretor' ? 'Painel do Diretor' : ($selectedRole === 'administrador' ? 'Painel do Administrador' : 'Painel do Solicitante')); ?></div>
                <div class="account-nav-actions"><?php if (!$isAdministrator && userHasProfile($profiles, 'administrador')) { ?><a href="admin.php">Administração</a><?php } ?><?php renderApplicationUserMenu(); ?></div>
            </header>
            <div class="wrap">
    <?php } else { ?>
    <header class="top">
        <div class="brand">Central de Compras</div>
        <div class="account-nav-actions"><?php if (userHasProfile($profiles, 'administrador')) { ?><a href="admin.php">Administração</a><?php } ?><?php renderApplicationUserMenu(); ?></div>
    </header>
    <main class="wrap">
    <?php } ?>
        <div class="head">
            <div>
                <?php if ($selectedRole === 'gestor') { ?>
                    <h1><?php echo h($managerSections[$selectedSection]['title']); ?></h1>
                    <p class="sub"><?php echo h($managerSections[$selectedSection]['description']); ?></p>
                <?php } elseif ($selectedRole === 'diretor') { ?>
                    <h1><?php echo h($directorSections[$selectedSection]['title']); ?></h1>
                    <p class="sub"><?php echo h($directorSections[$selectedSection]['description']); ?></p>
                <?php } elseif ($selectedRole === 'administrador') { ?>
                    <h1><?php echo h($administratorSections[$selectedSection]['title']); ?></h1>
                    <p class="sub"><?php echo h($administratorSections[$selectedSection]['description']); ?></p>
                <?php } else { ?>
                    <h1>Olá, <?php echo $displayName; ?>.</h1>
                    <p class="sub"><?php echo $selectedRole === 'diretor' ? 'Visão geral de solicitações e contratos.' : ($selectedRole === 'comprador' ? 'Solicitações atribuídas a você em Compras.' : 'Acompanhe as solicitações que você abriu.'); ?></p>
                <?php } ?>
            </div>
            <div class="buttons"></div>
        </div>
        <?php if (!$isAdministrator && count($roleNames) > 1) { ?><nav class="tabs"><?php foreach ($roleNames as $code => $name) { ?><a class="tab<?php echo $selectedRole === $code ? ' active' : ''; ?>" href="painel.php?perfil=<?php echo h($code); ?>"><?php echo h($name); ?></a><?php } ?></nav><?php } ?>
        <?php if ($selectedRole === 'gestor') { ?>
            <?php if ($selectedSection === 'resumo') { ?>
                <section class="stats">
                    <div class="stat"><small>Contratos no seu escopo</small><b><?php echo count($rows); ?></b></div>
                    <div class="stat"><small>Aguardando sua aprovação</small><b><?php echo count($approvalRows); ?></b></div>
                    <div class="stat"><small>Decisões registradas</small><b><?php echo count($decisionRows); ?></b></div>
                </section>
                <section class="panel">
                    <p>Use o menu lateral para consultar contratos, aprovações pendentes e o histórico das suas decisões.</p>
                </section>
            <?php } elseif ($selectedSection === 'contratos') { ?>
            <section class="panel">
                <table>
                    <thead>
                        <tr>
                            <th>Contrato</th>
                            <th>Fornecedor</th>
                            <th>Centro de custo</th>
                            <th>Status</th>
                            <th>Valor</th>
                            <th>Vigência</th>
                        </tr>
                    </thead>
                    <tbody><?php if (!$rows) { ?><tr>
                                <td colspan="6" class="empty"><b>Nenhum contrato neste escopo</b>Quando contratos forem vinculados aos seus centros de custo, aparecerão aqui.</td>
                            </tr><?php } else {
                                    foreach ($rows as $row) { ?><tr>
                                    <td><a href="contrato_ver.php?id=<?php echo (int) $row['id']; ?>" style="color:#315be8;text-decoration:none;font-weight:700"><?php echo h($row['numero_omie'] ? $row['numero_omie'] : 'Contrato'); ?></a> · <?php echo h($row['descricao']); ?></td>
                                    <td><?php echo h($row['fornecedor']); ?></td>
                                    <td><?php echo h($row['centro']); ?></td>
                                    <td><span class="status"><?php echo h($row['status']); ?></span></td>
                                    <td><?php echo h($row['valor_total']); ?></td>
                                    <td><?php echo h($row['fim_vigencia']); ?></td>
                                </tr><?php }
                                } ?></tbody>
                </table>
            </section>
            <?php } elseif ($selectedSection === 'aprovacoes') { ?>
                <?php if ($approvalRows) { ?><section class="panel">
                    <table>
                        <thead><tr><th>Solicitação</th><th>Solicitante</th><th>Centro de custo</th><th>Etapa</th><th>SLA da etapa</th><th>Status</th></tr></thead>
                        <tbody><?php foreach ($approvalRows as $approval) { ?><tr>
                            <td><a href="solicitacao_ver.php?id=<?php echo (int) $approval['id']; ?>" style="color:#315be8;text-decoration:none;font-weight:700"><?php echo h($approval['numero']); ?></a></td>
                            <td><?php echo h($approval['solicitante']); ?></td>
                            <td><?php echo h($approval['centro']); ?></td>
                            <td><?php echo h(stageDisplayName($approval['etapa_atual'])); ?></td>
                            <td><?php if (!empty($approval['sla'])) { $slaPercent = (int) $approval['sla']['percentual']; ?><div class="mini-sla"><div class="mini-sla-head"><strong><?php echo $slaPercent; ?>%</strong><span><?php echo $approval['sla']['estourado'] ? 'Atingido' : 'Em andamento'; ?></span></div><div class="mini-sla-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo $slaPercent; ?>" aria-label="Consumo do SLA"><span class="mini-sla-fill <?php echo $approval['sla']['estourado'] ? 'expired' : ''; ?>" style="width:<?php echo $slaPercent; ?>%"></span></div><small>Vence <?php echo h(formatSlaDeadline($approval['sla']['vence_em'])); ?></small></div><?php } else { ?><span style="color:var(--muted)">SLA não disponível</span><?php } ?></td>
                            <td><span class="status">Pendente</span></td>
                        </tr><?php } ?></tbody>
                    </table>
                </section><?php } else { ?><section class="panel"><p class="empty"><b>Nenhuma aprovação pendente</b>As solicitações que aguardarem sua aprovação aparecerão aqui.</p></section><?php } ?>
            <?php } ?>
        <?php } elseif ($selectedRole === 'diretor') { ?>
            <?php if ($selectedSection === 'resumo') { ?>
                <section class="stats">
                    <div class="stat"><small>Solicitações na organização</small><b><?php echo count($rows); ?></b></div>
                    <div class="stat"><small>Aguardando aprovação</small><b><?php echo count(array_filter($rows, function ($row) {
                        return strpos(strtolower($row['status']), 'aprova') !== false;
                    })); ?></b></div>
                    <div class="stat"><small>Contratos cadastrados</small><b><?php echo count($contractRows); ?></b></div>
                </section>
            <?php } elseif ($selectedSection === 'contratos') { ?>
                <section class="panel">
                    <table>
                        <thead>
                            <tr><th>Contrato</th><th>Fornecedor</th><th>Centro de custo</th><th>Status</th><th>Valor</th></tr>
                        </thead>
                        <tbody><?php if (!$contractRows) { ?><tr>
                            <td colspan="5" class="empty"><b>Nenhum contrato cadastrado</b>Os contratos aparecerão nesta visão quando forem registrados.</td>
                        </tr><?php } else {
                            foreach ($contractRows as $contract) { ?><tr>
                                <td><a href="contrato_ver.php?id=<?php echo (int) $contract['id']; ?>" style="color:#315be8;text-decoration:none;font-weight:700"><?php echo h($contract['numero_omie']); ?></a> · <?php echo h($contract['descricao']); ?></td>
                                <td><?php echo h($contract['fornecedor']); ?></td>
                                <td><?php echo h($contract['centro']); ?></td>
                                <td><span class="status"><?php echo h($contract['status']); ?></span></td>
                                <td><?php echo h($contract['valor_total']); ?></td>
                            </tr><?php }
                        } ?></tbody>
                    </table>
                </section>
            <?php } else { ?>
                <section class="panel">
                    <table>
                        <thead>
                            <tr><th>Solicitação</th><th>Solicitante</th><th>Centro de custo</th><th>Etapa</th><th>Status</th><th>Data</th></tr>
                        </thead>
                        <tbody><?php if (!$rows) { ?><tr>
                            <td colspan="6" class="empty"><b>Nenhuma solicitação para exibir</b>Quando houver solicitações, elas aparecerão aqui.</td>
                        </tr><?php } else {
                            foreach ($rows as $row) { ?><tr>
                                <td><a href="solicitacao_ver.php?id=<?php echo (int) $row['id']; ?>" style="color:#315be8;text-decoration:none;font-weight:700"><?php echo h($row['numero']); ?></a></td>
                                <td><?php echo h($row['pessoa']); ?></td>
                                <td><?php echo h($row['centro']); ?></td>
                                <td><?php echo h(stageDisplayName($row['etapa_atual'])); ?></td>
                                <td><span class="status"><?php echo h($row['status']); ?></span></td>
                                <td><?php echo h(formatBrazilianDateTime($row['criada_em'])); ?></td>
                            </tr><?php }
                        } ?></tbody>
                    </table>
                </section>
            <?php } ?>
        <?php } elseif ($selectedRole === 'administrador') { ?>
            <?php if ($selectedSection === 'resumo') { ?>
                <section class="stats">
                    <div class="stat"><small>Solicitações na organização</small><b><?php echo count($rows); ?></b></div>
                    <div class="stat"><small>Minhas Solicitações</small><b><?php echo count($myRequestRows); ?></b></div>
                    <div class="stat"><small>Contratos cadastrados</small><b><?php echo count($contractRows); ?></b></div>
                    <div class="stat"><small>Aprovações pendentes</small><b><?php echo count($approvalRows); ?></b></div>
                    <div class="stat" style="background: transparent; border: 0;"><small></small><b></b></div>
                    <?php 
                        $dr = 0; 
                        foreach ($decisionRows as $decisionRow) {
                            if (isset($decisionRow['decisao']) && strtolower($decisionRow['decisao']) === 'aprovado') {
                                $dr++;
                            }
                        }
                    ?>
                    <div class="stat"><small>Aprovadas por mim</small><b><?php echo $dr; ?></b></div>
                </section>
                <section class="panel">
                    <h2>Conexão com o OMIE</h2>
                    <div class="integration-status">
                        <div>
                            <small>Status da conexão</small>
                            <strong>Não disponível — integração ainda não implementada</strong>
                        </div>
                        <div>
                            <small>Última sincronização de cadastros</small>
                            <strong><?php
                                if ($omieLastSyncAt) {
                                    $syncDate = DateTime::createFromFormat('Y-m-d H:i:s', (string) $omieLastSyncAt);
                                    echo h($syncDate ? $syncDate->format('d/m/Y H:i:s') : $omieLastSyncAt);
                                } else {
                                    echo 'Nenhuma sincronização registrada';
                                }
                            ?></strong>
                        </div>
                    </div>
                </section>
            <?php } elseif ($selectedSection === 'contratos') { ?>
                <section class="panel">
                    <table>
                        <thead><tr><th>Contrato</th><th>Fornecedor</th><th>Centro de custo</th><th>Status</th><th>Valor</th></tr></thead>
                        <tbody><?php if (!$contractRows) { ?><tr><td colspan="5" class="empty"><b>Nenhum contrato cadastrado</b>Os contratos aparecerão nesta visão quando forem registrados.</td></tr><?php } else {
                            foreach ($contractRows as $contract) { ?><tr>
                                <td><a href="contrato_ver.php?id=<?php echo (int) $contract['id']; ?>" style="color:#315be8;text-decoration:none;font-weight:700"><?php echo h($contract['numero_omie']); ?></a> · <?php echo h($contract['descricao']); ?></td>
                                <td><?php echo h($contract['fornecedor']); ?></td>
                                <td><?php echo h($contract['centro']); ?></td>
                                <td><span class="status"><?php echo h($contract['status']); ?></span></td>
                                <td><?php echo h($contract['valor_total']); ?></td>
                            </tr><?php }
                        } ?></tbody>
                    </table>
                </section>
            <?php } elseif ($selectedSection === 'solicitacoes' || $selectedSection === 'minhas_solicitacoes') {
                $visibleRequests = $selectedSection === 'minhas_solicitacoes' ? $myRequestRows : $rows; ?>
                <section class="panel">
                    <table>
                        <thead><tr><th>Solicitação</th><?php if ($selectedSection === 'solicitacoes') { ?><th>Solicitante</th><?php } ?><th>Centro de custo</th><th>Etapa</th><th>Status</th><th>Data</th></tr></thead>
                        <tbody><?php if (!$visibleRequests) { ?><tr><td colspan="<?php echo $selectedSection === 'solicitacoes' ? '6' : '5'; ?>" class="empty"><b>Nenhuma solicitação para exibir</b><?php echo $selectedSection === 'minhas_solicitacoes' ? 'As solicitações abertas por você aparecerão aqui.' : 'Quando houver solicitações, elas aparecerão aqui.'; ?></td></tr><?php } else {
                            foreach ($visibleRequests as $row) { ?><tr>
                                <td><a href="solicitacao_ver.php?id=<?php echo (int) $row['id']; ?>" style="color:#315be8;text-decoration:none;font-weight:700"><?php echo h($row['numero']); ?></a></td>
                                <?php if ($selectedSection === 'solicitacoes') { ?><td><?php echo h($row['pessoa']); ?></td><?php } ?>
                                <td><?php echo h($row['centro']); ?></td>
                                <td><?php echo h(stageDisplayName($row['etapa_atual'])); ?></td>
                                <td><span class="status"><?php echo h($row['status']); ?></span></td>
                                <td><?php echo h(formatBrazilianDateTime($row['criada_em'])); ?></td>
                            </tr><?php }
                        } ?></tbody>
                    </table>
                </section>
            <?php } elseif ($selectedSection === 'aprovacoes') { ?>
                <section class="panel">
                    <table>
                        <thead><tr><th>Solicitação</th><th>Solicitante</th><th>Centro de custo</th><th>Etapa</th><th>SLA da etapa</th><th>Status</th></tr></thead>
                        <tbody><?php if (!$approvalRows) { ?><tr><td colspan="6" class="empty"><b>Nenhuma aprovação pendente</b>As etapas pendentes aparecerão aqui.</td></tr><?php } else {
                            foreach ($approvalRows as $approval) { ?><tr>
                                <td><a href="solicitacao_ver.php?id=<?php echo (int) $approval['id']; ?>" style="color:#315be8;text-decoration:none;font-weight:700"><?php echo h($approval['numero']); ?></a></td>
                                <td><?php echo h($approval['solicitante']); ?></td>
                                <td><?php echo h($approval['centro']); ?></td>
                                <td><?php echo h(stageDisplayName($approval['etapa_atual'])); ?></td>
                                <td><?php if (!empty($approval['sla'])) { $slaPercent = (int) $approval['sla']['percentual']; ?><div class="mini-sla"><div class="mini-sla-head"><strong><?php echo $slaPercent; ?>%</strong><span><?php echo $approval['sla']['estourado'] ? 'Atingido' : 'Em andamento'; ?></span></div><div class="mini-sla-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo $slaPercent; ?>" aria-label="Consumo do SLA"><span class="mini-sla-fill <?php echo $approval['sla']['estourado'] ? 'expired' : ''; ?>" style="width:<?php echo $slaPercent; ?>%"></span></div><small>Vence <?php echo h(formatSlaDeadline($approval['sla']['vence_em'])); ?></small></div><?php } else { ?><span style="color:var(--muted)">SLA não disponível</span><?php } ?></td>
                                <td><span class="status">Pendente</span></td>
                            </tr><?php }
                        } ?></tbody>
                    </table>
                </section>
            <?php } ?>
        <?php } else { ?><section class="stats">
                <div class="stat"><small><?php echo $selectedRole === 'diretor' ? 'Solicitações na organização' : ($selectedRole === 'comprador' ? 'Solicitações no seu escopo' : 'Solicitações abertas por você'); ?></small><b><?php echo count($rows); ?></b></div>
                <div class="stat"><small>Aguardando aprovação</small><b><?php echo count(array_filter($rows, function ($row) {
                                                                            return strpos(strtolower($row['status']), 'aprova') !== false;
                                                                        })); ?></b></div>
                <div class="stat"><small>Perfil desta visão</small><b><?php echo h($selectedName); ?></b></div>
            </section>
            <section class="panel">
                <table>
                    <thead>
                        <tr>
                            <th>Solicitação</th><?php if ($selectedRole === 'diretor') { ?><th>Solicitante</th><?php } ?><th>Centro de custo</th>
                            <th>Etapa</th>
                            <th>Status</th>
                            <th>Data</th>
                            <?php if ($selectedRole === 'comprador') { ?><th>Ações</th><?php } ?>
                        </tr>
                    </thead>
                    <tbody><?php if (!$rows) { ?><tr>
                                <td colspan="6" class="empty"><b>Nenhuma solicitação para exibir</b><?php echo $selectedRole === 'comprador' ? 'Solicitações do seu escopo aparecerão aqui para revisão.' : 'Quando houver solicitações, elas aparecerão aqui.'; ?></td>
                            </tr><?php } else {
                                    foreach ($rows as $row) { ?><tr>
                                    <td><a href="solicitacao_ver.php?id=<?php echo (int) $row['id']; ?>" style="color:#315be8;text-decoration:none;font-weight:700"><?php echo h($row['numero']); ?></a></td><?php if ($selectedRole === 'diretor') { ?><td><?php echo h($row['pessoa']); ?></td><?php } ?><td><?php echo h($row['centro']); ?></td>
                                    <td><?php echo h(stageDisplayName($row['etapa_atual'])); ?></td>
                                    <td><span class="status"><?php echo h($row['status']); ?></span></td>
                                    <td><?php echo h(formatBrazilianDateTime($row['criada_em'])); ?></td>
                                    <?php if ($selectedRole === 'comprador') { ?>
                                        <td><?php if ($row['status'] === 'em_aprovacao') { ?>
                                            <div class="request-action-buttons">
                                                <a class="btn" href="solicitacao_ver.php?id=<?php echo (int) $row['id']; ?>">Revisar alçadas</a>
                                                <?php if ($row['etapa_atual'] === 'cotacao' && ($row['comprador_id'] === null || (int) $row['comprador_id'] === (int) $_SESSION['user_id'])) { ?>
                                                    <a class="btn primary" href="mapa_cotacao.php?id=<?php echo (int) $row['id']; ?>">Abrir mapa de cotação</a>
                                                <?php } ?>
                                            </div>
                                        <?php } else { ?>—<?php } ?>
                                        </td>
                                    <?php } ?>
                                </tr><?php }
                                } ?></tbody>
                </table>
            </section><?php } ?>
        <?php if ($selectedRole !== 'gestor' && $selectedRole !== 'administrador' && ($selectedRole !== 'diretor' || $selectedSection === 'resumo') && $approvalRows) { ?><section style="margin-top:24px">
                <div style="font-weight:700;margin-bottom:11px">Aguardando sua aprovação · <?php echo count($approvalRows); ?></div>
                <div class="panel">
                    <table>
                        <thead>
                            <tr>
                                <th>Solicitação</th>
                                <th>Solicitante</th>
                                <th>Centro de custo</th>
                                <th>Etapa</th>
                                <th>SLA da etapa</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody><?php foreach ($approvalRows as $approval) { ?><tr>
                                    <td><a href="solicitacao_ver.php?id=<?php echo (int) $approval['id']; ?>" style="color:#315be8;text-decoration:none;font-weight:700"><?php echo h($approval['numero']); ?></a></td>
                                    <td><?php echo h($approval['solicitante']); ?></td>
                                    <td><?php echo h($approval['centro']); ?></td>
                                    <td><?php echo h(stageDisplayName($approval['etapa_atual'])); ?></td>
                                    <td><?php if (!empty($approval['sla'])) { $slaPercent = (int) $approval['sla']['percentual']; ?><div class="mini-sla"><div class="mini-sla-head"><strong><?php echo $slaPercent; ?>%</strong><span><?php echo $approval['sla']['estourado'] ? 'Atingido' : 'Em andamento'; ?></span></div><div class="mini-sla-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo $slaPercent; ?>" aria-label="Consumo do SLA"><span class="mini-sla-fill <?php echo $approval['sla']['estourado'] ? 'expired' : ''; ?>" style="width:<?php echo $slaPercent; ?>%"></span></div><small>Vence <?php echo h(formatSlaDeadline($approval['sla']['vence_em'])); ?></small></div><?php } else { ?><span style="color:var(--muted)">SLA não disponível</span><?php } ?></td>
                                    <td><span class="status">Pendente</span></td>
                                </tr><?php } ?></tbody>
                    </table>
                </div>
            </section><?php } ?>
        <?php if ($selectedRole === 'aprovador' && $collegiateRows) { ?><section style="margin-top:24px">
                <div style="font-weight:700;margin-bottom:11px">Colegiado concluído · <?php echo count($collegiateRows); ?></div>
                <div class="panel">
                    <table>
                        <thead>
                            <tr>
                                <th>Solicitação</th>
                                <th>Solicitante</th>
                                <th>Centro de custo</th>
                                <th>Etapa</th>
                                <th>Resultado</th>
                            </tr>
                        </thead>
                        <tbody><?php foreach ($collegiateRows as $collegiate) { ?><tr>
                                    <td><a href="solicitacao_ver.php?id=<?php echo (int) $collegiate['id']; ?>" style="color:#315be8;text-decoration:none;font-weight:700"><?php echo h($collegiate['numero']); ?></a></td>
                                    <td><?php echo h($collegiate['solicitante']); ?></td>
                                    <td><?php echo h($collegiate['centro']); ?></td>
                                    <td><?php echo h(stageDisplayName($collegiate['codigo_etapa'])); ?></td>
                                    <td>Aprovado por <?php echo h($collegiate['aprovado_por']); ?></td>
                                </tr><?php } ?></tbody>
                    </table>
                </div>
            </section><?php } ?>
        <?php if ($canViewDecisionHistory && ($selectedRole === 'gestor' ? $selectedSection === 'historico' : ($selectedRole === 'diretor' ? $selectedSection === 'resumo' : ($selectedRole === 'administrador' ? $selectedSection === 'historico' : true)))) { ?><section style="margin-top:24px">
            <div style="font-weight:700;margin-bottom:11px">Solicitações decididas por você · <?php echo count($decisionRows); ?></div>
            <div class="panel">
                <table>
                    <thead>
                        <tr>
                            <th>Solicitação</th>
                            <th>Solicitante</th>
                            <th>Centro de custo</th>
                            <th>Etapa decidida</th>
                            <th>Sua decisão</th>
                            <th>Status atual</th>
                            <th>Data da decisão</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$decisionRows) { ?>
                            <tr>
                                <td colspan="7" class="empty">
                                    <b>Nenhuma decisão registrada</b>
                                    As solicitações que você aprovar ou reprovar aparecerão aqui.
                                </td>
                            </tr>
                        <?php } else {
                            foreach ($decisionRows as $decisionRow) {
                                $decisionLabels = array('aprovado' => 'Aprovou', 'reprovado' => 'Reprovou', 'devolvido' => 'Devolveu', 'informacao' => 'Solicitou informação'); ?>
                                <tr>
                                    <td><?php echo h($decisionRow['numero']); ?></td>
                                    <td><?php echo h($decisionRow['solicitante']); ?></td>
                                    <td><?php echo h($decisionRow['centro']); ?></td>
                                    <td><?php echo h(stageDisplayName($decisionRow['codigo_etapa'])); ?></td>
                                    <td><?php echo h(isset($decisionLabels[$decisionRow['decisao']]) ? $decisionLabels[$decisionRow['decisao']] : ucfirst($decisionRow['decisao'])); ?></td>
                                    <td><?php echo h($decisionRow['solicitacao_status']); ?></td>
                                    <td><?php echo h(formatBrazilianDateTime($decisionRow['criada_em'])); ?></td>
                                </tr>
                            <?php }
                        } ?>
                    </tbody>
                </table>
            </div>
        </section><?php } ?>
    <?php if ($hasSidebar) { ?></div>
        </div>
    </main>
    <script>
        (function() {
            var adminLayout = document.getElementById('adminLayout');
            var sidebarToggle = document.getElementById('sidebarToggle');
            if (window.matchMedia && window.matchMedia('(max-width: 680px)').matches) {
                adminLayout.classList.add('sidebar-collapsed');
                sidebarToggle.setAttribute('aria-expanded', 'false');
                sidebarToggle.setAttribute('aria-label', 'Expandir menu lateral');
            }
            sidebarToggle.addEventListener('click', function() {
                var collapsed = adminLayout.classList.toggle('sidebar-collapsed');
                sidebarToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                sidebarToggle.setAttribute('aria-label', collapsed ? 'Expandir menu lateral' : 'Recolher menu lateral');
            });
        }());
    </script>
    <?php } else { ?></main><?php } ?>
</body>

</html>
