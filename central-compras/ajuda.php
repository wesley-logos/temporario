<?php
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/database.php';
require_once __DIR__ . '/app/user_menu.php';
require_once __DIR__ . '/app/authorization.php';
require_once __DIR__ . '/app/application_sidebar.php';

startApplicationSession();
requireLocalUser();
$pdo = databaseConnection();
$userId = (int) $_SESSION['user_id'];
$profiles = loadActiveProfiles($pdo, $userId);

function helpH($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ajuda · Central de Compras</title>
    <link rel="shortcut icon" href="https://centraldeservicos.logos-ma.com.br/plugins/trademark/front/picture.send.php?path=8d/66be65b142d8d.png" type="image/x-icon">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.2/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/admin.css">
    <link rel="stylesheet" href="assets/css/account.css">
</head>
<body>
    <main class="admin-layout" id="adminLayout">
        <?php renderApplicationSidebar($pdo, $userId, $profiles, 'help'); ?>
        <div class="admin-content">
            <header class="top">
                <div class="brand">Central de Compras · Ajuda</div>
                <?php renderApplicationUserMenu(); ?>
            </header>
            <div class="account-page">
        <h1>Como usar a Central de Compras</h1>
        <p class="intro">Siga as etapas abaixo para encontrar suas tarefas e acompanhar cada processo.</p>
        <section class="account-panel">
            <div class="help-section">
                <h2>Entrar e acessar sua conta</h2>
                <ol>
                    <li>Entre com sua matrícula e senha na tela inicial. Se sua conta usar senha temporária, defina uma senha pessoal antes de continuar.</li>
                    <li>Para atualizar seu nome ou consultar matrícula, e-mail e perfil, abra o menu com seu nome e escolha <strong>Meu perfil</strong>.</li>
                    <li>Na página do perfil, edite o nome e selecione <strong>Salvar alterações</strong>. Matrícula, e-mail e perfil são apenas para consulta.</li>
                    <li>Para trocar a senha, escolha <strong>Alterar senha</strong>, informe sua senha atual, digite e confirme a nova senha e salve. A senha nova precisa ter ao menos 12 caracteres.</li>
                    <li>Para encerrar o acesso, abra o mesmo menu e escolha <strong>Sair</strong>.</li>
                </ol>
            </div>
            <div class="help-section">
                <h2>Usar o painel</h2>
                <ol>
                    <li>O painel mostra as atividades disponíveis conforme seus perfis e permissões.</li>
                    <li>Se houver abas no alto do painel, use-as para alternar entre suas tarefas e a fila de aprovações pendentes.</li>
                    <li>Abra uma solicitação na lista para consultar detalhes, andamento, etapa atual e histórico disponível.</li>
                    <li>Se você for solicitante, escolha <strong>Nova solicitação</strong> para iniciar um pedido. Selecione o Centro de Custo, categoria e produto, informe quantidade, prazo e justificativa e envie.</li>
                    <li>Depois do envio, acompanhe o status no painel. Se a solicitação voltar para ajuste ou pedir informação, abra o detalhe e siga a orientação registrada.</li>
                </ol>
            </div>
            <div class="help-section">
                <h2>Aprovar solicitações</h2>
                <ol>
                    <li>Se uma aprovação estiver pendente para você, abra a solicitação correspondente no painel.</li>
                    <li>Leia os dados e justificativas e, se houver cotação, confira as ofertas e os documentos anexados.</li>
                    <li>Na etapa pendente, registre a decisão disponível: aprovar, reprovar, devolver para ajuste ou pedir esclarecimentos, conforme seu perfil e a etapa.</li>
                    <li>Após a decisão, acompanhe a atualização do status no detalhe da solicitação.</li>
                </ol>
            </div>
            <div class="help-section">
                <h2>Trabalhar com compras e cotações</h2>
                <ol>
                    <li>Como comprador, localize no painel uma solicitação atribuída a você ou disponível no seu escopo.</li>
                    <li>Quando a etapa permitir, abra o mapa de cotação pelo link da solicitação.</li>
                    <li>Cadastre as ofertas dos fornecedores e anexe o comprovante de cada oferta. Informe preços e demais condições solicitadas.</li>
                    <li>O sistema destaca o menor preço. Se escolher outra oferta, informe a justificativa exigida; se houver menos de três fornecedores distintos, registre a justificativa da exceção antes de finalizar.</li>
                    <li>Salve rascunhos se ainda estiver preparando a cotação. Finalize o mapa quando estiver completo para encaminhá-lo ao fluxo de aprovação.</li>
                </ol>
            </div>
            <div class="help-section">
                <h2>Administrar configurações</h2>
                <ol>
                    <li>Se você tiver perfil de Administrador de Compras, abra <strong>Administração</strong> pelo painel.</li>
                    <li>Use o menu lateral para escolher <strong>Gerenciamento de usuários</strong>, <strong>Gerenciamento de produtos</strong>, <strong>Gerenciamento de categorias</strong>, <strong>Grupos e membros aprovadores</strong>, <strong>Calendários de trabalho e feriados</strong>, <strong>Trilha por Centro de Custo</strong> ou <strong>Logs do sistema</strong>.</li>
                    <li>Em usuários, cadastre contas, edite os dados administrativos, gerencie perfis e altere o status conforme as permissões da tela.</li>
                    <li>Em grupos, organize os aprovadores e sua ordem. Em calendários, ajuste dias e feriados usados pelos prazos. Na seção de trilhas, use <strong>Novo Centro de Custo</strong> para informar nome, código opcional e unidade opcional; o identificador OMIE não é necessário. Depois, selecione o centro criado para configurar etapas, aprovadores e SLAs.</li>
                    <li>Use o menu com seu nome para voltar ao perfil, consultar esta ajuda ou sair da conta.</li>
                </ol>
            </div>
            <div class="help-section">
                <h2>Esqueceu a senha?</h2>
                <p>Na tela de entrada, escolha a opção de recuperação de senha e informe o e-mail cadastrado. Se a conta puder receber recuperação, será enviado um link temporário para criar uma nova senha.</p>
            </div>
        </section>
    </main>
    <script src="assets/js/application-sidebar.js"></script>
</body>
</html>
