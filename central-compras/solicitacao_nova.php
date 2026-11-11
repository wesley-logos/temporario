<?php
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/database.php';
require_once __DIR__ . '/app/authorization.php';
startApplicationSession();
requireLocalUser();

$pdo = databaseConnection();
$profiles = loadActiveProfiles($pdo, $_SESSION['user_id']);
if (!userHasProfile($profiles, 'solicitante')) {
    http_response_code(403);
    exit('Seu perfil não permite abrir solicitações.');
}

$centers = $pdo->query('SELECT id, codigo, nome FROM centros_custo WHERE ativo_omie = 1 ORDER BY nome')->fetchAll();
$categories = $pdo->query('SELECT id, codigo, nome FROM categorias WHERE ativo_omie = 1 ORDER BY nome')->fetchAll();
$products = $pdo->query('SELECT id, categoria_id, codigo, nome, unidade_medida FROM produtos WHERE ativo_omie = 1 AND categoria_id IS NOT NULL ORDER BY nome LIMIT 500')->fetchAll();
$token = htmlspecialchars(applicationCsrfToken(), ENT_QUOTES, 'UTF-8');
$idempotencyKey = bin2hex(random_bytes(32));
?>
<!doctype html>
<html lang="pt-BR">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Nova solicitação · Central de Compras</title>
    <link rel="shortcut icon" href="https://centraldeservicos.logos-ma.com.br/plugins/trademark/front/picture.send.php?path=8d/66be65b142d8d.png" type="image/x-icon">
    <link rel="stylesheet" href="assets/css/solicitacao_nova.css">
</head>

<body>
    <main class="wrap">
        <section class="panel">
            <div class="top"><a href="painel.php?perfil=solicitante">← Voltar ao meu painel</a><span class="eyebrow">Nova solicitação</span></div>
            <h1>O que você precisa comprar?</h1>
            <p>Preencha os dados para iniciar o fluxo de compras.</p>
            <?php if (!$centers || !$categories || !$products) { ?><div class="info">Centros de custo, categorias e produtos são sincronizados do OMIE. Cadastre ou sincronize esses dados antes de abrir uma solicitação.</div><?php } ?>
            <form method="post" action="solicitacao_criar.php" id="requestForm"><input type="hidden" name="csrf_token" value="<?php echo $token; ?>"><input type="hidden" name="idempotency_key" value="<?php echo htmlspecialchars($idempotencyKey, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="grid">
                    <div class="field"><label for="center">Centro de custo</label><select id="center" name="centro_custo_id" required>
                            <option value="">Selecione</option><?php foreach ($centers as $center) { ?><option value="<?php echo (int) $center['id']; ?>"><?php echo htmlspecialchars(trim($center['codigo'] . ' · ' . $center['nome'], ' ·'), ENT_QUOTES, 'UTF-8'); ?></option><?php } ?>
                        </select></div>
                    <div class="field"><label for="category">Categoria</label><select id="category" name="categoria_id" required>
                            <option value="">Selecione</option><?php foreach ($categories as $category) { ?><option value="<?php echo (int) $category['id']; ?>"><?php echo htmlspecialchars(trim(($category['codigo'] ? $category['codigo'] . ' · ' : '') . $category['nome']), ENT_QUOTES, 'UTF-8'); ?></option><?php } ?>
                        </select></div>
                    <div class="field"><label for="product">Produto</label><select id="product" name="produto_id" required>
                            <option value="">Selecione uma categoria primeiro</option><?php foreach ($products as $product) { ?><option value="<?php echo (int) $product['id']; ?>" data-category="<?php echo (int) $product['categoria_id']; ?>"><?php echo htmlspecialchars(($product['codigo'] ? $product['codigo'] . ' · ' : '') . $product['nome'], ENT_QUOTES, 'UTF-8'); ?></option><?php } ?>
                        </select></div>
                    <div class="field"><label for="quantity">Quantidade</label><input id="quantity" name="quantidade" type="number" min="0.001" step="0.001" required></div>
                    <div class="field"><label for="date">Prazo estimado</label><input id="date" name="prazo_estimado" type="date" required></div>
                    <div class="field full"><label for="justification">Justificativa / necessidade</label><textarea id="justification" name="justificativa" maxlength="4000" required></textarea></div>
                </div>
                <div class="info">A confirmação é segura contra envio repetido: atualizar ou reenviar este formulário com a mesma chave não cria outra solicitação.</div>
                <div class="actions"><a class="btn" href="painel.php?perfil=solicitante">Cancelar</a><button class="btn primary" id="submitButton" type="submit" <?php echo (!$centers || !$categories || !$products) ? ' disabled' : ''; ?>>Enviar solicitação</button></div>
            </form>
        </section>
    </main>
    <script>
        var categorySelect = document.getElementById('category');
        var productSelect = document.getElementById('product');
        categorySelect.addEventListener('change', function() {
            var categoryId = this.value;
            productSelect.value = '';
            productSelect.options[0].textContent = categoryId ? 'Selecione' : 'Selecione uma categoria primeiro';
            Array.prototype.forEach.call(productSelect.options, function(option, index) {
                if (index > 0) option.hidden = !categoryId || option.getAttribute('data-category') !== categoryId;
            });
        });
        document.getElementById('requestForm').addEventListener('submit', function() {
            document.getElementById('submitButton').disabled = true;
            document.getElementById('submitButton').textContent = 'Enviando…'
        });
    </script>
</body>

</html>
