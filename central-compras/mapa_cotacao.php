<?php
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/database.php';
require_once __DIR__ . '/app/authorization.php';
require_once __DIR__ . '/app/system_log.php';
require_once __DIR__ . '/app/quote_files.php';
require_once __DIR__ . '/app/user_menu.php';
startApplicationSession();
requireLocalUser();

function quotePageError($message)
{
    throw new RuntimeException($message);
}

function quoteDecimal($value)
{
    return str_replace(',', '.', trim((string) $value));
}

$pdo = databaseConnection();
$userId = (int) $_SESSION['user_id'];
$profiles = loadActiveProfiles($pdo, $userId);
if (!userHasProfile($profiles, 'comprador')) {
    http_response_code(403);
    exit('Apenas usuários do perfil Comprador podem gerenciar mapas de cotação.');
}

$requestId = filter_var(isset($_GET['id']) ? $_GET['id'] : (isset($_POST['solicitacao_id']) ? $_POST['solicitacao_id'] : null), FILTER_VALIDATE_INT);
if (!$requestId) {
    http_response_code(404);
    exit('Solicitação não encontrada.');
}
$requestQuery = $pdo->prepare('SELECT s.id, s.numero, s.solicitante_id, s.comprador_id, s.centro_custo_id, s.status, s.etapa_atual, s.prazo_estimado, u.nome AS solicitante, cc.nome AS centro, cc.unidade_id FROM solicitacoes s INNER JOIN usuarios u ON u.id = s.solicitante_id INNER JOIN centros_custo cc ON cc.id = s.centro_custo_id WHERE s.id = :id');
$requestQuery->execute(array('id' => $requestId));
$request = $requestQuery->fetch();
if (!$request) {
    http_response_code(404);
    exit('Solicitação não encontrada.');
}

$hasBuyerScope = false;
$allowedBuyerScope = false;
$hasGlobalBuyerScope = false;
foreach ($profiles as $profile) {
    if ($profile['codigo'] !== 'comprador') {
        continue;
    }
    if ($profile['centro_custo_id'] !== null || $profile['unidade_id'] !== null) {
        $hasBuyerScope = true;
        if (($profile['centro_custo_id'] !== null && (int) $profile['centro_custo_id'] === (int) $request['centro_custo_id'])
            || ($profile['unidade_id'] !== null && $request['unidade_id'] !== null && (int) $profile['unidade_id'] === (int) $request['unidade_id'])
        ) {
            $allowedBuyerScope = true;
        }
    } else {
        $hasGlobalBuyerScope = true;
    }
}
$buyerInScope = $hasGlobalBuyerScope || !$hasBuyerScope || $allowedBuyerScope;
$canWorkRequest = $buyerInScope
    && $request['status'] === 'em_aprovacao'
    && $request['etapa_atual'] === 'cotacao'
    && ($request['comprador_id'] === null || (int) $request['comprador_id'] === $userId);
if (!$canWorkRequest) {
    http_response_code(403);
    exit('Esta solicitação não está disponível para cotação no seu escopo ou está atribuída a outro comprador.');
}

$message = '';
$messageType = 'success';
if (isset($_SESSION['quote_map_message'])) {
    $message = (string) $_SESSION['quote_map_message'];
    unset($_SESSION['quote_map_message']);
}
$stageQuery = $pdo->prepare('SELECT id FROM etapas_solicitacao WHERE solicitacao_id = :request_id AND codigo_etapa = \'cotacao\' AND status = \'pendente\' ORDER BY ordem_execucao LIMIT 1');
$stageQuery->execute(array('request_id' => $requestId));
$quotationStage = $stageQuery->fetchColumn();
if (!$quotationStage) {
    http_response_code(409);
    exit('A etapa de cotação desta solicitação não está pendente.');
}

$newFiles = array();
$filesToDelete = array();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validApplicationCsrfToken(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '')) {
        http_response_code(400);
        exit('O formulário expirou. Atualize a página e tente novamente.');
    }
    $action = isset($_POST['acao']) ? $_POST['acao'] : '';
    if (!in_array($action, array('salvar_rascunho', 'nova_rodada', 'finalizar_mapa'), true)) {
        http_response_code(400);
        exit('Ação inválida.');
    }

    try {
        $pdo->beginTransaction();
        $lockedRequestQuery = $pdo->prepare('SELECT id, comprador_id, status, etapa_atual FROM solicitacoes WHERE id = :id FOR UPDATE');
        $lockedRequestQuery->execute(array('id' => $requestId));
        $lockedRequest = $lockedRequestQuery->fetch();
        if (!$lockedRequest || $lockedRequest['status'] !== 'em_aprovacao' || $lockedRequest['etapa_atual'] !== 'cotacao') {
            quotePageError('A solicitação deixou de estar na etapa de cotação. Atualize a página.');
        }
        if ($lockedRequest['comprador_id'] !== null && (int) $lockedRequest['comprador_id'] !== $userId) {
            quotePageError('Outro comprador já assumiu esta solicitação.');
        }
        if ($lockedRequest['comprador_id'] === null) {
            $claim = $pdo->prepare('UPDATE solicitacoes SET comprador_id = :user_id WHERE id = :request_id AND comprador_id IS NULL');
            $claim->execute(array('user_id' => $userId, 'request_id' => $requestId));
            if ($claim->rowCount() !== 1) {
                quotePageError('Outro comprador assumiu esta solicitação ao mesmo tempo. Atualize a página.');
            }
        }

        $itemsQuery = $pdo->prepare('SELECT id, produto_id, descricao_snapshot, unidade_snapshot, quantidade FROM solicitacao_itens WHERE solicitacao_id = :request_id ORDER BY id');
        $itemsQuery->execute(array('request_id' => $requestId));
        $requestItems = $itemsQuery->fetchAll();
        if (!$requestItems) {
            quotePageError('A solicitação não possui itens para cotar.');
        }

        $mapQuery = $pdo->prepare('SELECT id, versao, status FROM mapas_cotacao WHERE solicitacao_id = :request_id ORDER BY versao DESC LIMIT 1 FOR UPDATE');
        $mapQuery->execute(array('request_id' => $requestId));
        $latestMap = $mapQuery->fetch();
        $mapId = null;
        $mapVersion = 1;
        if ($action === 'nova_rodada') {
            if ($latestMap && $latestMap['status'] !== 'aberto') {
                quotePageError('Não é possível abrir outra rodada depois de enviar o mapa para aprovação.');
            }
            $mapVersion = $latestMap ? (int) $latestMap['versao'] + 1 : 1;
            if ($latestMap) {
                $pdo->prepare('UPDATE mapas_cotacao SET status = \'substituido\' WHERE id = :id AND status = \'aberto\'')->execute(array('id' => $latestMap['id']));
            }
            $newMap = $pdo->prepare('INSERT INTO mapas_cotacao (solicitacao_id, versao, status, aberto_por) VALUES (:request_id, :version, \'aberto\', :user_id)');
            $newMap->execute(array('request_id' => $requestId, 'version' => $mapVersion, 'user_id' => $userId));
            $mapId = (int) $pdo->lastInsertId();
            systemLogEvent('abriu_rodada_cotacao', 'mapa_cotacao', $mapId, 'sucesso', array('solicitacao_id' => $requestId, 'versao' => $mapVersion), null, $userId, $pdo);
            $pdo->commit();
            $_SESSION['quote_map_message'] = 'Rodada ' . $mapVersion . ' criada. A rodada anterior foi preservada no histórico.';
            header('Location: mapa_cotacao.php?id=' . $requestId, true, 303);
            exit;
        }

        if ($latestMap && $latestMap['status'] === 'aberto') {
            $mapId = (int) $latestMap['id'];
            $mapVersion = (int) $latestMap['versao'];
        } elseif ($latestMap) {
            quotePageError('O mapa já foi enviado para aprovação e não pode mais ser alterado.');
        } else {
            $newMap = $pdo->prepare('INSERT INTO mapas_cotacao (solicitacao_id, versao, status, aberto_por) VALUES (:request_id, 1, \'aberto\', :user_id)');
            $newMap->execute(array('request_id' => $requestId, 'user_id' => $userId));
            $mapId = (int) $pdo->lastInsertId();
        }

        $justification = trim(isset($_POST['justificativa_menos_tres']) ? $_POST['justificativa_menos_tres'] : '');
        $existingQuery = $pdo->prepare('SELECT id FROM cotacoes WHERE mapa_id = :map_id');
        $existingQuery->execute(array('map_id' => $mapId));
        $existingQuoteIds = array_map('intval', $existingQuery->fetchAll(PDO::FETCH_COLUMN));
        $submittedQuotes = isset($_POST['quotes']) && is_array($_POST['quotes']) ? $_POST['quotes'] : array();
        $processedIds = array();
        $deletedIds = array();
        $supplierIds = array();
        $savedQuotes = array();
        $quoteInsert = $pdo->prepare('INSERT INTO cotacoes (mapa_id, fornecedor_id, valor_total, moeda, prazo_entrega_dias, condicao_pagamento, frete_tipo, vencedora, justificativa_escolha) VALUES (:map_id, :supplier_id, :total, \'BRL\', :delivery_days, :payment_terms, :freight, 0, :winner_reason)');
        $quoteUpdate = $pdo->prepare('UPDATE cotacoes SET fornecedor_id = :supplier_id, valor_total = :total, prazo_entrega_dias = :delivery_days, condicao_pagamento = :payment_terms, frete_tipo = :freight, vencedora = 0, justificativa_escolha = :winner_reason WHERE id = :id AND mapa_id = :map_id');
        $itemDelete = $pdo->prepare('DELETE FROM cotacao_itens WHERE cotacao_id = :quote_id');
        $itemInsert = $pdo->prepare('INSERT INTO cotacao_itens (cotacao_id, solicitacao_item_id, valor_unitario, quantidade, valor_total) VALUES (:quote_id, :request_item_id, :unit_price, :quantity, :line_total)');
        $supplierCheck = $pdo->prepare('SELECT id FROM fornecedores WHERE id = :id AND (ativo_omie = 1 OR tipo = \'candidato\')');

        foreach ($submittedQuotes as $index => $quoteInput) {
            if (!is_array($quoteInput)) {
                continue;
            }
            $quoteId = filter_var(isset($quoteInput['id']) ? $quoteInput['id'] : null, FILTER_VALIDATE_INT);
            if (!empty($quoteInput['remover']) && $quoteId) {
                if (!in_array((int) $quoteId, $existingQuoteIds, true)) {
                    quotePageError('Uma cotação removida não pertence à rodada atual.');
                }
                $attachmentQuery = $pdo->prepare('SELECT storage_key FROM anexos WHERE cotacao_id = :quote_id');
                $attachmentQuery->execute(array('quote_id' => $quoteId));
                foreach ($attachmentQuery->fetchAll(PDO::FETCH_COLUMN) as $storageKey) {
                    $path = quoteEvidencePath($storageKey);
                    if ($path) {
                        $filesToDelete[] = $path;
                    }
                }
                $pdo->prepare('DELETE FROM anexos WHERE cotacao_id = :quote_id')->execute(array('quote_id' => $quoteId));
                $pdo->prepare('DELETE FROM cotacoes WHERE id = :quote_id AND mapa_id = :map_id')->execute(array('quote_id' => $quoteId, 'map_id' => $mapId));
                $deletedIds[] = (int) $quoteId;
                continue;
            }

            $supplierId = filter_var(isset($quoteInput['fornecedor_id']) ? $quoteInput['fornecedor_id'] : null, FILTER_VALIDATE_INT);
            $unitPrices = isset($quoteInput['unit_prices']) && is_array($quoteInput['unit_prices']) ? $quoteInput['unit_prices'] : array();
            $hasData = $supplierId || trim(isset($quoteInput['prazo_entrega_dias']) ? $quoteInput['prazo_entrega_dias'] : '') !== '' || trim(isset($quoteInput['condicao_pagamento']) ? $quoteInput['condicao_pagamento'] : '') !== '' || !empty($quoteInput['frete_tipo']);
            foreach ($unitPrices as $unitPrice) {
                $hasData = $hasData || trim((string) $unitPrice) !== '';
            }
            if (!$quoteId && !$hasData) {
                continue;
            }
            if (!$supplierId || in_array((int) $supplierId, $supplierIds, true)) {
                quotePageError('Cada fornecedor só pode aparecer uma vez por rodada e precisa ser selecionado.');
            }
            $supplierCheck->execute(array('id' => $supplierId));
            if (!$supplierCheck->fetchColumn()) {
                quotePageError('Selecione um fornecedor ativo ou um fornecedor candidato cadastrado.');
            }
            $supplierIds[] = (int) $supplierId;

            $delivery = isset($quoteInput['prazo_entrega_dias']) ? trim($quoteInput['prazo_entrega_dias']) : '';
            $payment = trim(isset($quoteInput['condicao_pagamento']) ? $quoteInput['condicao_pagamento'] : '');
            $freight = isset($quoteInput['frete_tipo']) ? $quoteInput['frete_tipo'] : '';
            if ($delivery === '' || !ctype_digit((string) $delivery) || $payment === '' || strlen($payment) > 180 || !in_array($freight, array('CIF', 'FOB'), true)) {
                quotePageError('Informe prazo de entrega, condição de pagamento e frete CIF ou FOB em todas as ofertas.');
            }

            $lineItems = array();
            $quoteTotal = 0.0;
            foreach ($requestItems as $requestItem) {
                $rawPrice = isset($unitPrices[$requestItem['id']]) ? quoteDecimal($unitPrices[$requestItem['id']]) : '';
                if (!is_numeric($rawPrice) || (float) $rawPrice <= 0) {
                    quotePageError('Informe um valor unitário positivo para cada item de todas as ofertas.');
                }
                $lineTotal = round((float) $rawPrice * (float) $requestItem['quantidade'], 2);
                $quoteTotal += $lineTotal;
                $lineItems[] = array('request_item_id' => (int) $requestItem['id'], 'unit_price' => number_format((float) $rawPrice, 4, '.', ''), 'quantity' => $requestItem['quantidade'], 'line_total' => number_format($lineTotal, 2, '.', ''));
            }
            $winnerReason = trim(isset($quoteInput['justificativa_escolha']) ? $quoteInput['justificativa_escolha'] : '');
            if (strlen($winnerReason) > 4000) {
                quotePageError('A justificativa de escolha deve ter até 4.000 caracteres.');
            }

            $parameters = array('supplier_id' => $supplierId, 'total' => number_format($quoteTotal, 2, '.', ''), 'delivery_days' => (int) $delivery, 'payment_terms' => $payment, 'freight' => $freight, 'winner_reason' => $winnerReason !== '' ? $winnerReason : null);
            if ($quoteId) {
                if (!in_array((int) $quoteId, $existingQuoteIds, true) || in_array((int) $quoteId, $processedIds, true)) {
                    quotePageError('Uma cotação informada não pertence à rodada atual.');
                }
                $parameters['id'] = $quoteId;
                $parameters['map_id'] = $mapId;
                $quoteUpdate->execute($parameters);
                $savedQuoteId = (int) $quoteId;
                $processedIds[] = $savedQuoteId;
                $itemDelete->execute(array('quote_id' => $savedQuoteId));
            } else {
                $quoteInsert->execute(array_merge(array('map_id' => $mapId), $parameters));
                $savedQuoteId = (int) $pdo->lastInsertId();
            }
            foreach ($lineItems as $lineItem) {
                $itemInsert->execute(array_merge(array('quote_id' => $savedQuoteId), $lineItem));
            }

            $quoteFile = isset($_FILES['quote_files']['name'][$index]) ? array(
                'name' => $_FILES['quote_files']['name'][$index],
                'type' => $_FILES['quote_files']['type'][$index],
                'tmp_name' => $_FILES['quote_files']['tmp_name'][$index],
                'error' => $_FILES['quote_files']['error'][$index],
                'size' => $_FILES['quote_files']['size'][$index]
            ) : array('error' => UPLOAD_ERR_NO_FILE);
            $storedFile = storeQuoteEvidence($pdo, $quoteFile, $requestId, $savedQuoteId, $userId);
            if ($storedFile) {
                $newFiles[] = $storedFile['path'];
            }
            $savedQuotes[$index] = array('id' => $savedQuoteId, 'total' => $quoteTotal, 'winner' => isset($_POST['vencedora']) && (string) $_POST['vencedora'] === (string) $index, 'justification' => $winnerReason);
        }

        if ($action === 'finalizar_mapa' && count($existingQuoteIds) !== count($processedIds) + count($deletedIds)) {
            quotePageError('Atualize todas as ofertas da rodada e tente finalizar novamente.');
        }
        $selectedWinners = array_values(array_filter($savedQuotes, function ($quote) {
            return $quote['winner'];
        }));
        if (count($selectedWinners) > 1) {
            quotePageError('Selecione somente uma oferta vencedora.');
        }
        $pdo->prepare('UPDATE cotacoes SET vencedora = 0 WHERE mapa_id = :map_id')->execute(array('map_id' => $mapId));
        if ($selectedWinners) {
            $pdo->prepare('UPDATE cotacoes SET vencedora = 1 WHERE id = :quote_id AND mapa_id = :map_id')->execute(array('quote_id' => $selectedWinners[0]['id'], 'map_id' => $mapId));
        }

        $mapJustification = $pdo->prepare('UPDATE mapas_cotacao SET justificativa_menos_tres = :justification WHERE id = :map_id');
        $mapJustification->execute(array('justification' => $justification !== '' ? $justification : null, 'map_id' => $mapId));

        if ($action === 'finalizar_mapa') {
            $distinctSupplierCount = count($supplierIds);
            if (!$distinctSupplierCount) {
                quotePageError('Inclua ao menos uma oferta antes de finalizar o mapa.');
            }
            if ($distinctSupplierCount < 3 && $justification === '') {
                quotePageError('Para menos de três fornecedores, informe a justificativa de fornecedor único/insuficiente.');
            }
            if (count($savedQuotes) !== $distinctSupplierCount) {
                quotePageError('Revise as ofertas removidas e salve novamente antes de finalizar.');
            }

            $winner = $selectedWinners ? $selectedWinners[0] : null;
            if ($winner === null) {
                quotePageError('Selecione a oferta vencedora antes de enviar o mapa.');
            }
            $lowestTotal = min(array_map(function ($quote) {
                return $quote['total'];
            }, $savedQuotes));
            if ($winner['total'] > $lowestTotal && trim($winner['justification']) === '') {
                quotePageError('Justifique a escolha da oferta vencedora quando ela não for a de menor valor.');
            }

            $evidenceQuery = $pdo->prepare('SELECT COUNT(*) FROM anexos WHERE cotacao_id = :quote_id');
            foreach ($savedQuotes as $savedQuote) {
                $evidenceQuery->execute(array('quote_id' => $savedQuote['id']));
                if ((int) $evidenceQuery->fetchColumn() < 1) {
                    quotePageError('Anexe o comprovante de cada fornecedor antes de finalizar o mapa.');
                }
            }
            $pdo->prepare('UPDATE mapas_cotacao SET status = \'aguardando_aprovacao\' WHERE id = :map_id AND status = \'aberto\'')->execute(array('map_id' => $mapId));
            systemLogEvent('finalizou_mapa_cotacao', 'mapa_cotacao', $mapId, 'sucesso', array('solicitacao_id' => $requestId, 'versao' => $mapVersion, 'fornecedores' => $distinctSupplierCount, 'cotacao_vencedora_id' => $winner['id']), null, $userId, $pdo);
            $successMessage = 'Mapa de cotação enviado para aprovação.';
        } else {
            systemLogEvent('salvou_mapa_cotacao', 'mapa_cotacao', $mapId, 'sucesso', array('solicitacao_id' => $requestId, 'versao' => $mapVersion, 'ofertas' => count($savedQuotes)), null, $userId, $pdo);
            $successMessage = 'Rascunho do mapa salvo.';
        }

        $pdo->commit();
        foreach ($filesToDelete as $pathToDelete) {
            if (is_file($pathToDelete)) {
                @unlink($pathToDelete);
            }
        }
        $_SESSION['quote_map_message'] = $successMessage;
        header('Location: mapa_cotacao.php?id=' . $requestId, true, 303);
        exit;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        foreach ($newFiles as $newFile) {
            if (is_file($newFile)) {
                @unlink($newFile);
            }
        }
        $message = $exception instanceof PDOException ? 'Não foi possível salvar o mapa. Verifique os dados e tente novamente.' : $exception->getMessage();
        $messageType = 'error';
        error_log('Falha no mapa de cotação da solicitação ' . (int) $requestId . ': ' . $exception->getMessage());
        systemLogEvent('erro_sistema', 'mapa_cotacao', isset($mapId) ? $mapId : null, 'falha', array('operacao' => $action, 'solicitacao_id' => $requestId), $exception->getMessage(), $userId, $pdo);
    }
}

$itemQuery = $pdo->prepare('SELECT id, descricao_snapshot, unidade_snapshot, quantidade FROM solicitacao_itens WHERE solicitacao_id = :request_id ORDER BY id');
$itemQuery->execute(array('request_id' => $requestId));
$requestItems = $itemQuery->fetchAll();
$supplierQuery = $pdo->query('SELECT id, razao_social, tipo, documento_normalizado FROM fornecedores WHERE ativo_omie = 1 OR tipo = \'candidato\' ORDER BY razao_social');
$suppliers = $supplierQuery->fetchAll();
$roundQuery = $pdo->prepare('SELECT id, versao, status, justificativa_menos_tres, aberto_por FROM mapas_cotacao WHERE solicitacao_id = :request_id ORDER BY versao DESC');
$roundQuery->execute(array('request_id' => $requestId));
$rounds = $roundQuery->fetchAll();
$activeMap = $rounds && $rounds[0]['status'] === 'aberto' ? $rounds[0] : null;
$quotes = array();
$attachmentsByQuote = array();
if ($activeMap) {
    $quoteQuery = $pdo->prepare('SELECT c.id, c.fornecedor_id, c.valor_total, c.prazo_entrega_dias, c.condicao_pagamento, c.frete_tipo, c.vencedora, c.justificativa_escolha, f.razao_social AS fornecedor FROM cotacoes c INNER JOIN fornecedores f ON f.id = c.fornecedor_id WHERE c.mapa_id = :map_id ORDER BY f.razao_social');
    $quoteQuery->execute(array('map_id' => $activeMap['id']));
    $quotes = $quoteQuery->fetchAll();
    $quoteItemQuery = $pdo->prepare('SELECT solicitacao_item_id, valor_unitario, quantidade, valor_total FROM cotacao_itens WHERE cotacao_id = :quote_id');
    $attachmentQuery = $pdo->prepare('SELECT id, nome_original, mime_type, tamanho, criado_em FROM anexos WHERE cotacao_id = :quote_id ORDER BY id');
    foreach ($quotes as $index => $quote) {
        $quoteItemQuery->execute(array('quote_id' => $quote['id']));
        $priceRows = array();
        foreach ($quoteItemQuery->fetchAll() as $quoteItem) {
            $priceRows[(int) $quoteItem['solicitacao_item_id']] = $quoteItem;
        }
        $quotes[$index]['items'] = $priceRows;
        $attachmentQuery->execute(array('quote_id' => $quote['id']));
        $attachmentsByQuote[(int) $quote['id']] = $attachmentQuery->fetchAll();
    }
}
$latestMapStatus = $rounds ? $rounds[0]['status'] : null;
$csrfToken = htmlspecialchars(applicationCsrfToken(), ENT_QUOTES, 'UTF-8');
function quoteH($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
function quoteMoney($value)
{
    return number_format((float) $value, 2, ',', '.');
}
?>
<!doctype html>
<html lang="pt-BR">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Mapa de cotação · <?php echo quoteH($request['numero']); ?></title>
    <link rel="shortcut icon" href="https://centraldeservicos.logos-ma.com.br/plugins/trademark/front/picture.send.php?path=8d/66be65b142d8d.png" type="image/x-icon">
    <link rel="stylesheet" href="assets/css/mapa_cotacao.css">
    <link rel="stylesheet" href="assets/css/account.css">
</head>

<body>
    <header class="top"><a href="painel.php?perfil=comprador">← Painel de Compras</a><?php renderApplicationUserMenu(); ?></header>
    <main class="wrap">
        <div class="page-head">
            <div><span class="eyebrow">Painel do comprador · <?php echo quoteH($request['centro']); ?></span>
                <h1>Mapa de cotação</h1>
                <p><?php echo quoteH($request['numero']); ?> · Solicitante: <?php echo quoteH($request['solicitante']); ?></p>
            </div>
            <a class="button" href="solicitacao_ver.php?id=<?php echo (int) $requestId; ?>">Ver solicitação</a>
        </div>
        <?php if ($message !== '') { ?><div class="notice <?php echo quoteH($messageType); ?>" role="status"><?php echo quoteH($message); ?></div><?php } ?>

        <section class="panel">
            <h2>Itens solicitados</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Produto</th>
                            <th>Quantidade</th>
                        </tr>
                    </thead>
                    <tbody><?php foreach ($requestItems as $item) { ?><tr>
                                <td><?php echo quoteH($item['descricao_snapshot']); ?></td>
                                <td><?php echo quoteH(str_replace('.', ',', $item['quantidade']) . ' ' . $item['unidade_snapshot']); ?></td>
                            </tr><?php } ?></tbody>
                </table>
            </div>
        </section>

        <section class="panel">
            <div class="section-heading">
                <div>
                    <h2>Rodadas registradas</h2>
                    <p class="hint">Cada rodada mantém seu histórico. Um fornecedor pode aparecer novamente em uma rodada posterior.</p>
                </div>
                <form method="post" action="mapa_cotacao.php?id=<?php echo (int) $requestId; ?>"><input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>"><input type="hidden" name="solicitacao_id" value="<?php echo (int) $requestId; ?>"><button class="button" type="submit" name="acao" value="nova_rodada" <?php echo $latestMapStatus && $latestMapStatus !== 'aberto' ? 'disabled title="O mapa já foi enviado para aprovação"' : ''; ?>><?php echo $rounds ? 'Criar nova rodada' : 'Iniciar cotação'; ?></button></form>
            </div>
            <?php if ($rounds) { ?><div class="round-list"><?php foreach ($rounds as $round) { ?><div class="round-chip"><strong>Rodada <?php echo (int) $round['versao']; ?></strong><span><?php echo quoteH($round['status']); ?></span></div><?php } ?></div><?php } else { ?><p class="empty">Nenhuma rodada cadastrada.</p><?php } ?>
        </section>

        <?php if ($activeMap) { ?>
            <form method="post" action="mapa_cotacao.php?id=<?php echo (int) $requestId; ?>" enctype="multipart/form-data" id="quoteMapForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>"><input type="hidden" name="solicitacao_id" value="<?php echo (int) $requestId; ?>">
                <section class="panel">
                    <div class="section-heading">
                        <div>
                            <h2>Rodada <?php echo (int) $activeMap['versao']; ?></h2>
                            <p class="hint">Inclua ofertas sem limite de fornecedores. Para finalizar, cada oferta precisa de um comprovante PDF, imagem ou e-mail EML.</p>
                        </div><button type="button" class="button" id="addQuote">Adicionar fornecedor</button>
                    </div>
                    <div id="quoteRows">
                        <?php foreach ($quotes as $quoteIndex => $quote) { ?>
                            <article class="quote-card" data-quote-row>
                                <input type="hidden" name="quotes[<?php echo (int) $quoteIndex; ?>][id]" value="<?php echo (int) $quote['id']; ?>">
                                <input type="hidden" name="quotes[<?php echo (int) $quoteIndex; ?>][remover]" value="0" data-remove-value>
                                <div class="quote-head">
                                    <h3>Oferta <?php echo (int) ($quoteIndex + 1); ?></h3><span class="price-badge" data-lowest-badge hidden>Menor valor</span><strong class="quote-total" data-quote-total>Total: R$ <?php echo quoteMoney($quote['valor_total']); ?></strong><button type="button" class="remove-quote" data-remove-quote>Remover oferta</button>
                                </div>
                                <div class="quote-fields">
                                    <div class="field"><label>Fornecedor</label><select name="quotes[<?php echo (int) $quoteIndex; ?>][fornecedor_id]" required>
                                            <option value="">Selecione</option><?php foreach ($suppliers as $supplier) { ?><option value="<?php echo (int) $supplier['id']; ?>" <?php echo (int) $supplier['id'] === (int) $quote['fornecedor_id'] ? 'selected' : ''; ?>><?php echo quoteH($supplier['razao_social'] . ' · ' . ($supplier['tipo'] === 'candidato' ? 'candidato' : 'homologado')); ?></option><?php } ?>
                                        </select></div>
                                    <div class="field"><label>Prazo de entrega (dias)</label><input type="number" min="0" step="1" name="quotes[<?php echo (int) $quoteIndex; ?>][prazo_entrega_dias]" value="<?php echo (int) $quote['prazo_entrega_dias']; ?>" required></div>
                                    <div class="field"><label>Condição de pagamento</label><input type="text" maxlength="180" name="quotes[<?php echo (int) $quoteIndex; ?>][condicao_pagamento]" value="<?php echo quoteH($quote['condicao_pagamento']); ?>" required></div>
                                    <div class="field"><label>Frete</label><select name="quotes[<?php echo (int) $quoteIndex; ?>][frete_tipo]" required>
                                            <option value="">Selecione</option>
                                            <option value="CIF" <?php echo $quote['frete_tipo'] === 'CIF' ? 'selected' : ''; ?>>CIF</option>
                                            <option value="FOB" <?php echo $quote['frete_tipo'] === 'FOB' ? 'selected' : ''; ?>>FOB</option>
                                        </select></div>
                                </div>
                                <div class="table-wrap">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>Item</th>
                                                <th>Quantidade</th>
                                                <th>Valor unitário (R$)</th>
                                            </tr>
                                        </thead>
                                        <tbody><?php foreach ($requestItems as $requestItem) {
                                                    $price = isset($quote['items'][(int) $requestItem['id']]) ? $quote['items'][(int) $requestItem['id']]['valor_unitario'] : ''; ?><tr>
                                                    <td><?php echo quoteH($requestItem['descricao_snapshot']); ?></td>
                                                    <td><?php echo quoteH(str_replace('.', ',', $requestItem['quantidade']) . ' ' . $requestItem['unidade_snapshot']); ?></td>
                                                    <td><input class="unit-price" type="number" min="0.0001" step="0.0001" data-quantity="<?php echo quoteH($requestItem['quantidade']); ?>" name="quotes[<?php echo (int) $quoteIndex; ?>][unit_prices][<?php echo (int) $requestItem['id']; ?>]" value="<?php echo quoteH($price); ?>" required></td>
                                                </tr><?php } ?></tbody>
                                    </table>
                                </div>
                                <div class="quote-fields quote-bottom">
                                    <div class="field"><label>Comprovante da oferta</label><input type="file" name="quote_files[<?php echo (int) $quoteIndex; ?>]" accept=".pdf,.jpg,.jpeg,.png,.eml,application/pdf,image/jpeg,image/png,message/rfc822"><small>PDF, JPG, PNG ou EML · máximo 20 MB</small><?php if (!empty($attachmentsByQuote[(int) $quote['id']])) { ?><div class="file-list"><?php foreach ($attachmentsByQuote[(int) $quote['id']] as $attachment) { ?><a href="anexo_baixar.php?id=<?php echo (int) $attachment['id']; ?>"><?php echo quoteH($attachment['nome_original']); ?></a><?php } ?></div><?php } ?></div>
                                    <div class="field winner-choice"><label><input type="radio" name="vencedora" value="<?php echo (int) $quoteIndex; ?>" <?php echo $quote['vencedora'] ? 'checked' : ''; ?>> Selecionar como vencedora</label>
                                        <div class="winner-reason" data-winner-reason hidden><label>Justificativa técnica (obrigatória por não ser o menor preço)</label><textarea name="quotes[<?php echo (int) $quoteIndex; ?>][justificativa_escolha]" maxlength="4000"><?php echo quoteH($quote['justificativa_escolha']); ?></textarea></div>
                                    </div>
                                </div>
                            </article>
                        <?php } ?>
                    </div>
                    <p class="hint" id="quoteValidationMessage" role="alert" hidden></p>
                    <template id="quoteTemplate">
                        <article class="quote-card" data-quote-row>
                            <input type="hidden" name="quotes[__INDEX__][id]" value=""><input type="hidden" name="quotes[__INDEX__][remover]" value="0" data-remove-value>
                            <div class="quote-head">
                                <h3>Nova oferta</h3><button type="button" class="remove-quote" data-remove-quote>Remover oferta</button>
                            </div>
                            <div class="quote-fields">
                                <div class="field"><label>Fornecedor</label><select name="quotes[__INDEX__][fornecedor_id]" required>
                                        <option value="">Selecione</option><?php foreach ($suppliers as $supplier) { ?><option value="<?php echo (int) $supplier['id']; ?>"><?php echo quoteH($supplier['razao_social'] . ' · ' . ($supplier['tipo'] === 'candidato' ? 'candidato' : 'homologado')); ?></option><?php } ?>
                                    </select></div>
                                <div class="field"><label>Prazo de entrega (dias)</label><input type="number" min="0" step="1" name="quotes[__INDEX__][prazo_entrega_dias]" required></div>
                                <div class="field"><label>Condição de pagamento</label><input type="text" maxlength="180" name="quotes[__INDEX__][condicao_pagamento]" required></div>
                                <div class="field"><label>Frete</label><select name="quotes[__INDEX__][frete_tipo]" required>
                                        <option value="">Selecione</option>
                                        <option value="CIF">CIF</option>
                                        <option value="FOB">FOB</option>
                                    </select></div>
                            </div>
                            <div class="table-wrap">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Item</th>
                                            <th>Quantidade</th>
                                            <th>Valor unitário (R$)</th>
                                        </tr>
                                    </thead>
                                    <tbody><?php foreach ($requestItems as $requestItem) { ?><tr>
                                                <td><?php echo quoteH($requestItem['descricao_snapshot']); ?></td>
                                                <td><?php echo quoteH(str_replace('.', ',', $requestItem['quantidade']) . ' ' . $requestItem['unidade_snapshot']); ?></td>
                                                <td><input class="unit-price" type="number" min="0.0001" step="0.0001" data-quantity="<?php echo quoteH($requestItem['quantidade']); ?>" name="quotes[__INDEX__][unit_prices][<?php echo (int) $requestItem['id']; ?>]" required></td>
                                            </tr><?php } ?></tbody>
                                </table>
                            </div>
                            <div class="quote-fields quote-bottom">
                                <div class="field"><label>Comprovante da oferta</label><input type="file" name="quote_files[__INDEX__]" accept=".pdf,.jpg,.jpeg,.png,.eml,application/pdf,image/jpeg,image/png,message/rfc822"><small>PDF, JPG, PNG ou EML · máximo 20 MB</small></div>
                                <div class="field winner-choice"><label><input type="radio" name="vencedora" value="__INDEX__"> Selecionar como vencedora</label>
                                    <div class="winner-reason" data-winner-reason hidden><label>Justificativa técnica (obrigatória por não ser o menor preço)</label><textarea name="quotes[__INDEX__][justificativa_escolha]" maxlength="4000"></textarea></div>
                                </div>
                            </div>
                        </article>
                    </template>
                </section>
                <section class="panel finalize-panel">
                    <h2>Finalizar mapa</h2>
                    <p class="hint">São exigidos três fornecedores distintos. Com menos de três, justifique fornecedor único/insuficiente. A finalização exige comprovante individual e uma oferta vencedora.</p>
                    <div class="field" id="exceptionJustificationField" hidden><label for="exceptionReason">Justificativa de fornecedor único/insuficiente <span>(obrigatória para finalizar com menos de três fornecedores)</span></label><textarea id="exceptionReason" name="justificativa_menos_tres" maxlength="4000"><?php echo quoteH($activeMap['justificativa_menos_tres']); ?></textarea></div>
                    <div class="actions"><button class="button" type="submit" name="acao" value="salvar_rascunho" formnovalidate>Salvar rascunho</button><button class="button primary" type="submit" name="acao" value="finalizar_mapa">Finalizar e enviar para aprovação</button></div>
                </section>
            </form>
        <?php } elseif ($latestMapStatus) { ?>
            <section class="panel">
                <div class="notice">A rodada mais recente está com status “<?php echo quoteH($latestMapStatus); ?>” e não pode ser editada. Aguardando análise do aprovador.</div>
            </section>
        <?php } ?>
    </main>
    <script>
        (function() {
            var container = document.getElementById('quoteRows');
            var template = document.getElementById('quoteTemplate');
            var nextIndex = <?php echo count($quotes); ?>;
            var addButton = document.getElementById('addQuote');
            var form = document.getElementById('quoteMapForm');
            var exceptionField = document.getElementById('exceptionJustificationField');
            var exceptionReason = document.getElementById('exceptionReason');
            var lowestTotal = null;

            function quoteTotal(card) {
                var inputs = card.querySelectorAll('.unit-price');
                if (!inputs.length) return null;
                var totalCents = 0;
                for (var i = 0; i < inputs.length; i++) {
                    var price = parseFloat(inputs[i].value);
                    var quantity = parseFloat(inputs[i].getAttribute('data-quantity'));
                    if (!isFinite(price) || price <= 0 || !isFinite(quantity) || quantity <= 0) return null;
                    totalCents += Math.round(price * quantity * 100);
                }
                return totalCents;
            }

            function money(cents) {
                return 'R$ ' + (cents / 100).toLocaleString('pt-BR', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
            }

            function activeQuoteCards() {
                if (!container) return [];
                return Array.prototype.slice.call(container.querySelectorAll('[data-quote-row]')).filter(function(card) {
                    var remove = card.querySelector('[data-remove-value]');
                    return !card.hidden && (!remove || remove.value !== '1');
                });
            }

            function refresh() {
                if (!container) return;
                var allCards = activeQuoteCards();
                allCards.forEach(function(card) {
                    var evidenceInput = card.querySelector('input[type="file"]');
                    var existingEvidence = card.querySelector('.file-list a');
                    if (evidenceInput) evidenceInput.required = !existingEvidence;
                });
                var cards = allCards.filter(function(card) {
                    var supplier = card.querySelector('select[name$="[fornecedor_id]"]');
                    return supplier && supplier.value !== '';
                });
                var totals = cards.map(function(card) {
                    return {
                        card: card,
                        total: quoteTotal(card)
                    };
                }).filter(function(item) {
                    return item.total !== null;
                });
                lowestTotal = totals.length ? Math.min.apply(null, totals.map(function(item) {
                    return item.total;
                })) : null;
                cards.forEach(function(card) {
                    var total = quoteTotal(card);
                    var isLowest = total !== null && lowestTotal !== null && total === lowestTotal;
                    card.classList.toggle('lowest-price', isLowest);
                    var badge = card.querySelector('[data-lowest-badge]');
                    if (badge) badge.hidden = !isLowest;
                    var totalLabel = card.querySelector('[data-quote-total]');
                    if (totalLabel) totalLabel.textContent = total === null ? 'Total: complete os preços' : 'Total: ' + money(total);
                    var radio = card.querySelector('input[type="radio"][name="vencedora"]');
                    var reason = card.querySelector('[data-winner-reason]');
                    var textarea = reason ? reason.querySelector('textarea') : null;
                    var needsReason = !!(radio && radio.checked && total !== null && lowestTotal !== null && total > lowestTotal);
                    if (reason) reason.hidden = !needsReason;
                    if (textarea) textarea.required = needsReason;
                });
                var distinct = {};
                cards.forEach(function(card) {
                    var supplier = card.querySelector('select[name$="[fornecedor_id]"]');
                    if (supplier && supplier.value) distinct[supplier.value] = true;
                });
                var belowMinimum = Object.keys(distinct).length < 3;
                if (exceptionField) exceptionField.hidden = !belowMinimum;
                if (exceptionReason) exceptionReason.required = belowMinimum;
            }
            if (addButton && container && template) {
                addButton.addEventListener('click', function() {
                    var html = template.innerHTML.replace(/__INDEX__/g, String(nextIndex++));
                    var holder = document.createElement('div');
                    holder.innerHTML = html;
                    container.appendChild(holder.firstElementChild);
                    refresh();
                });
                container.addEventListener('input', refresh);
                container.addEventListener('change', refresh);
                container.addEventListener('click', function(event) {
                    var button = event.target.closest('[data-remove-quote]');
                    if (!button) {
                        if (event.target.matches('input[type="radio"][name="vencedora"]')) refresh();
                        return;
                    }
                    var card = button.closest('[data-quote-row]');
                    var idField = card.querySelector('input[name$="[id]"]');
                    if (idField && idField.value) {
                        var removeValue = card.querySelector('[data-remove-value]');
                        removeValue.value = '1';
                        card.hidden = true;
                        card.querySelectorAll('input,select,textarea').forEach(function(field) {
                            if (field !== idField && field !== removeValue) field.disabled = true;
                        });
                    } else {
                        card.remove();
                    }
                    refresh();
                });
            }
            if (form) form.addEventListener('input', function() {
                form.querySelectorAll('input, select, textarea').forEach(function(field) {
                    field.setCustomValidity('');
                });
                var validationMessage = document.getElementById('quoteValidationMessage');
                if (validationMessage) validationMessage.hidden = true;
            });
            if (form) form.addEventListener('change', function() {
                form.querySelectorAll('input, select, textarea').forEach(function(field) {
                    field.setCustomValidity('');
                });
                var validationMessage = document.getElementById('quoteValidationMessage');
                if (validationMessage) validationMessage.hidden = true;
            });
            if (form) form.addEventListener('submit', function(event) {
                refresh();
                if (event.submitter && event.submitter.value === 'salvar_rascunho') return;
                var validationMessage = document.getElementById('quoteValidationMessage');
                var cards = activeQuoteCards();
                if (!cards.length) {
                    event.preventDefault();
                    if (validationMessage) {
                        validationMessage.textContent = 'Inclua ao menos uma oferta antes de finalizar o mapa.';
                        validationMessage.hidden = false;
                        validationMessage.scrollIntoView({behavior: 'smooth', block: 'center'});
                    }
                    return;
                }
                for (var cardIndex = 0; cardIndex < cards.length; cardIndex++) {
                    var requiredFields = cards[cardIndex].querySelectorAll('input[required], select[required], textarea[required]');
                    for (var fieldIndex = 0; fieldIndex < requiredFields.length; fieldIndex++) {
                        var field = requiredFields[fieldIndex];
                        field.setCustomValidity('');
                        if ((field.type === 'text' || field.tagName === 'TEXTAREA') && !field.value.trim()) {
                            field.setCustomValidity('Preencha este campo antes de finalizar o mapa.');
                        }
                        if (!field.checkValidity()) {
                            event.preventDefault();
                            field.reportValidity();
                            return;
                        }
                    }
                }
                var selectedWinner = cards.some(function(card) {
                    var radio = card.querySelector('input[type="radio"][name="vencedora"]');
                    return radio && radio.checked;
                });
                if (!selectedWinner) {
                    event.preventDefault();
                    var winnerRadio = cards[0].querySelector('input[type="radio"][name="vencedora"]');
                    if (winnerRadio) {
                        winnerRadio.setCustomValidity('Selecione uma oferta vencedora antes de finalizar o mapa.');
                        winnerRadio.reportValidity();
                    } else if (validationMessage) {
                        validationMessage.textContent = 'Selecione uma oferta vencedora antes de finalizar o mapa.';
                        validationMessage.hidden = false;
                        validationMessage.scrollIntoView({behavior: 'smooth', block: 'center'});
                    }
                    return;
                }
                cards.forEach(function(card) {
                    var radio = card.querySelector('input[type="radio"][name="vencedora"]');
                    if (radio) radio.setCustomValidity('');
                });
                if (exceptionReason && exceptionReason.required && !exceptionReason.value.trim()) {
                    event.preventDefault();
                    exceptionReason.setCustomValidity('Informe a justificativa para prosseguir com menos de três fornecedores.');
                    exceptionReason.reportValidity();
                }
            });
            if (container) container.addEventListener('change', function(event) {
                if (event.target.matches('input[type="radio"][name="vencedora"]')) {
                    container.querySelectorAll('input[type="radio"][name="vencedora"]').forEach(function(radio) {
                        radio.setCustomValidity('');
                    });
                }
            });
            if (exceptionReason) exceptionReason.addEventListener('input', function() {
                exceptionReason.setCustomValidity('');
            });
            refresh();
        }());
    </script>
</body>

</html>