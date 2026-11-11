<?php
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/database.php';
require_once __DIR__ . '/app/authorization.php';
startApplicationSession();
requireLocalUser();

$contractId = filter_var(isset($_GET['id']) ? $_GET['id'] : null, FILTER_VALIDATE_INT);
if (!$contractId) {
    http_response_code(404);
    exit('Contrato não encontrado.');
}
$pdo = databaseConnection();
$profiles = loadActiveProfiles($pdo, $_SESSION['user_id']);
$isAdministrator = userHasProfile($profiles, 'administrador');
$canSeeAll = userHasProfile($profiles, 'diretor') || $isAdministrator;
$query = 'SELECT c.id, c.descricao, c.numero_omie, c.status, c.valor_total, c.inicio_vigencia, c.fim_vigencia, cc.nome AS centro, f.razao_social AS fornecedor FROM contratos c INNER JOIN centros_custo cc ON cc.id = c.centro_custo_id LEFT JOIN fornecedores f ON f.id = c.fornecedor_id WHERE c.id = :id';
$statement = $pdo->prepare($query);
$statement->execute(array('id' => $contractId));
$contract = $statement->fetch();
if (!$contract) {
    http_response_code(404);
    exit('Contrato não encontrado.');
}
$visible = $canSeeAll;
if (!$visible && userHasProfile($profiles, 'gestor')) {
    $scope = $pdo->prepare('SELECT 1 FROM contratos c INNER JOIN centros_custo cc ON cc.id = c.centro_custo_id WHERE c.id = :contract_id AND EXISTS (SELECT 1 FROM usuario_perfis up INNER JOIN perfis p ON p.id = up.perfil_id WHERE up.usuario_id = :user_id AND p.codigo = \'gestor\' AND p.ativo = 1 AND (up.valido_ate IS NULL OR up.valido_ate >= CURDATE()) AND ((up.centro_custo_id IS NOT NULL AND up.centro_custo_id = c.centro_custo_id) OR (up.unidade_id IS NOT NULL AND up.unidade_id = cc.unidade_id)))');
    $scope->execute(array('contract_id' => $contractId, 'user_id' => $_SESSION['user_id']));
    $visible = (bool) $scope->fetchColumn();
}
if (!$visible) {
    http_response_code(403);
    exit('Você não tem acesso a este contrato.');
}
$contractsUrl = $isAdministrator
    ? 'painel.php?perfil=administrador&secao=contratos'
    : ($canSeeAll ? 'painel.php?perfil=diretor&secao=contratos' : 'painel.php?perfil=gestor&secao=contratos');
function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="pt-BR">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Contrato · Central de Compras</title>
    <link rel="shortcut icon" href="https://centraldeservicos.logos-ma.com.br/plugins/trademark/front/picture.send.php?path=8d/66be65b142d8d.png" type="image/x-icon">
    <link rel="stylesheet" href="assets/css/contrato_ver.css">
</head>

<body>
    <main class="wrap"><a class="back" href="<?php echo h($contractsUrl); ?>">← Voltar aos contratos</a>
        <section class="panel">
            <div class="eyebrow">Contrato <?php echo h($contract['numero_omie']); ?></div>
            <h1><?php echo h($contract['descricao']); ?></h1><span class="status"><?php echo h($contract['status']); ?></span>
            <div class="grid">
                <div><span class="label">Fornecedor</span>
                    <div class="value"><?php echo h($contract['fornecedor']); ?></div>
                </div>
                <div><span class="label">Centro de custo</span>
                    <div class="value"><?php echo h($contract['centro']); ?></div>
                </div>
                <div><span class="label">Valor</span>
                    <div class="value"><?php echo h($contract['valor_total']); ?></div>
                </div>
                <div><span class="label">Vigência</span>
                    <div class="value"><?php echo h($contract['inicio_vigencia'] . ' — ' . $contract['fim_vigencia']); ?></div>
                </div>
            </div>
        </section>
    </main>
</body>

</html>