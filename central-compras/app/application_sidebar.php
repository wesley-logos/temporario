<?php

function applicationSidebarLink(array $item, $currentPage)
{
    $active = isset($item['page']) && $item['page'] === $currentPage;
    echo '<a class="sidebar-link' . ($active ? ' active' : '') . '" href="' . htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') . '"'
        . ($active ? ' aria-current="page"' : '')
        . ' title="' . htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') . '">'
        . '<i class="bi ' . htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') . '"></i>'
        . '<span class="sidebar-link-label">' . htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') . '</span></a>';
}

function applicationSidebarGroup($title, $icon, array $items, $currentPage, $open = false)
{
    if (!$items) {
        return;
    }

    echo '<details class="sidebar-group"' . ($open ? ' open' : '') . '>'
        . '<summary class="sidebar-link sidebar-group-toggle" title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '">'
        . '<i class="bi ' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '"></i>'
        . '<span class="sidebar-link-label">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</span>'
        . '<i class="bi bi-chevron-down sidebar-group-chevron" aria-hidden="true"></i></summary>'
        . '<div class="sidebar-group-links">';
    foreach ($items as $item) {
        applicationSidebarLink($item, $currentPage);
    }
    echo '</div></details>';
}

function applicationUserHasPendingApproval(PDO $pdo, $userId)
{
    $statement = $pdo->prepare(
        'SELECT es.id, es.responsavel_tipo, es.responsavel_id, es.regra_snapshot, '
        . '(SELECT COUNT(*) FROM decisoes_aprovacao da WHERE da.etapa_solicitacao_id = es.id AND da.decisao = \'aprovado\') AS aprovacoes_feitas '
        . 'FROM etapas_solicitacao es INNER JOIN solicitacoes s ON s.id = es.solicitacao_id '
        . 'WHERE es.status = \'pendente\' AND s.status = \'em_aprovacao\' '
        . 'AND NOT EXISTS (SELECT 1 FROM decisoes_aprovacao da WHERE da.etapa_solicitacao_id = es.id AND da.aprovador_id = :user_id)'
    );
    $statement->execute(array('user_id' => (int) $userId));

    foreach ($statement->fetchAll() as $stage) {
        if (!in_array($stage['responsavel_tipo'], array('grupo', 'usuario', 'grupo_usuario'), true)) {
            continue;
        }
        $snapshot = json_decode($stage['regra_snapshot'], true);
        $approverIds = isset($snapshot['aprovadores_ids']) && is_array($snapshot['aprovadores_ids'])
            ? array_values(array_map('intval', $snapshot['aprovadores_ids']))
            : array();
        if (!$approverIds && $stage['responsavel_tipo'] === 'usuario') {
            $approverIds = array((int) $stage['responsavel_id']);
        }
        if (!in_array((int) $userId, $approverIds, true)) {
            continue;
        }
        if (isset($snapshot['modo']) && $snapshot['modo'] === 'OU') {
            return true;
        }
        $nextApprover = isset($approverIds[(int) $stage['aprovacoes_feitas']])
            ? (int) $approverIds[(int) $stage['aprovacoes_feitas']]
            : null;
        if ($nextApprover === (int) $userId) {
            return true;
        }
    }

    return false;
}

function renderApplicationSidebar(PDO $pdo, $userId, array $profiles, $currentPage)
{
    $profileCodes = array();
    foreach ($profiles as $profile) {
        $profileCodes[$profile['codigo']] = true;
    }

    echo '<aside class="sidebar" id="adminSidebar">'
        . '<div class="sidebar-brand"><img class="sidebar-brand-expanded" src="assets/images/logos.png" alt="Logos">'
        . '<img class="sidebar-brand-collapsed" src="https://centraldeservicos.logos-ma.com.br/plugins/trademark/front/picture.send.php?path=8d/66be65b142d8d.png" alt="Logos">'
        . '<p style="color:#fff">Central de Compras</p></div><br>'
        . '<button class="sidebar-toggle" id="sidebarToggle" type="button" aria-controls="adminSidebar" aria-expanded="true" aria-label="Recolher menu lateral">'
        . '<span class="sidebar-toggle-icon" aria-hidden="true"><i class="bi bi-chevron-double-left"></i></span>'
        . '</button>'
        . '<nav class="sidebar-nav" aria-label="Navegação da conta">';

    if (isset($profileCodes['administrador'])) {
        applicationSidebarGroup('Administração', 'bi-gear', array(
            array('href' => 'admin.php?secao=usuarios', 'icon' => 'bi-person-gear', 'label' => 'Gerenciamento de usuários'),
            array('href' => 'admin.php?secao=produtos', 'icon' => 'bi-box-seam', 'label' => 'Gerenciamento de produtos'),
            array('href' => 'admin.php?secao=categorias', 'icon' => 'bi-tags', 'label' => 'Gerenciamento de categorias'),
            array('href' => 'admin.php?secao=grupos', 'icon' => 'bi-people', 'label' => 'Grupos e membros aprovadores'),
            array('href' => 'admin.php?secao=calendarios', 'icon' => 'bi-calendar3', 'label' => 'Calendários de trabalho e feriados'),
            array('href' => 'admin.php?secao=trilhas', 'icon' => 'bi-currency-dollar', 'label' => 'Trilha por Centro de Custo'),
            array('href' => 'admin.php?secao=logs', 'icon' => 'bi-journal-text', 'label' => 'Logs do sistema')
        ), $currentPage);
        applicationSidebarGroup('Sistema', 'bi-grid', array(
            array('href' => 'painel.php?perfil=administrador&secao=resumo', 'icon' => 'bi-speedometer2', 'label' => 'Dashboard'),
            array('href' => 'painel.php?perfil=administrador&secao=solicitacoes', 'icon' => 'bi-clipboard-check', 'label' => 'Solicitações'),
            array('href' => 'painel.php?perfil=administrador&secao=minhas_solicitacoes', 'icon' => 'bi-person-lines-fill', 'label' => 'Minhas solicitações'),
            array('href' => 'painel.php?perfil=administrador&secao=contratos', 'icon' => 'bi-file-earmark-text', 'label' => 'Contratos'),
            array('href' => 'painel.php?perfil=administrador&secao=aprovacoes', 'icon' => 'bi-check2-square', 'label' => 'Aprovações pendentes'),
            array('href' => 'painel.php?perfil=administrador&secao=historico', 'icon' => 'bi-clock-history', 'label' => 'Histórico de solicitações')
        ), $currentPage);
    }

    if (isset($profileCodes['solicitante'])) {
        applicationSidebarGroup('Solicitante', 'bi-person', array(
            array('href' => 'painel.php?perfil=solicitante', 'icon' => 'bi-speedometer2', 'label' => 'Visão geral'),
            array('href' => 'solicitacao_nova.php', 'icon' => 'bi-plus-circle', 'label' => 'Nova solicitação')
        ), $currentPage);
    }

    if (isset($profileCodes['comprador'])) {
    applicationSidebarGroup('Comprador', 'bi-grid', array(
        array(
            'href' => 'painel.php?perfil=comprador&secao=resumo',
            'icon' => 'bi-speedometer2',
            'label' => 'Dashboard'
        )
    ), $currentPage);
}

    if (isset($profileCodes['gestor'])) {
        applicationSidebarGroup('Gestor', 'bi-briefcase', array(
            array('href' => 'painel.php?perfil=gestor&secao=resumo', 'icon' => 'bi-speedometer2', 'label' => 'Visão geral'),
            array('href' => 'painel.php?perfil=gestor&secao=contratos', 'icon' => 'bi-file-earmark-text', 'label' => 'Contratos'),
            array('href' => 'painel.php?perfil=gestor&secao=aprovacoes', 'icon' => 'bi-check2-square', 'label' => 'Aprovações pendentes'),
            array('href' => 'painel.php?perfil=gestor&secao=historico', 'icon' => 'bi-clock-history', 'label' => 'Histórico de solicitações')
        ), $currentPage);
    }

    if (isset($profileCodes['diretor'])) {
        applicationSidebarGroup('Diretor', 'bi-building', array(
            array('href' => 'painel.php?perfil=diretor&secao=resumo', 'icon' => 'bi-speedometer2', 'label' => 'Dashboard'),
            array('href' => 'painel.php?perfil=diretor&secao=contratos', 'icon' => 'bi-file-earmark-text', 'label' => 'Contratos'),
            array('href' => 'painel.php?perfil=diretor&secao=solicitacoes', 'icon' => 'bi-clipboard-check', 'label' => 'Solicitações')
        ), $currentPage);
    }

    if (!isset($profileCodes['administrador']) && applicationUserHasPendingApproval($pdo, $userId)) {
        applicationSidebarGroup('Aprovações', 'bi-check2-square', array(
            array('href' => 'painel.php?perfil=aprovador', 'icon' => 'bi-inbox', 'label' => 'Aprovações pendentes')
        ), $currentPage);
    }

    applicationSidebarGroup('Minha conta', 'bi-person-circle', array(
        array('href' => 'meu_perfil.php', 'icon' => 'bi-person', 'label' => 'Meu perfil', 'page' => 'profile'),
        array('href' => 'ajuda.php', 'icon' => 'bi-question-circle', 'label' => 'Ajuda', 'page' => 'help')
    ), $currentPage, true);

    echo '</nav></aside>';
}
