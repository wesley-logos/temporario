<?php

/**
 * Valida os dados obrigatórios antes de uma aprovação liberar a próxima etapa.
 * Reprovações ficam fora desta validação porque encerram o fluxo e não liberam
 * dados para a etapa seguinte.
 */
function requestWorkflowIntegrityIssues(PDO $pdo, $requestId, $stageCode)
{
    $issues = array();
    $requestQuery = $pdo->prepare('SELECT s.id, s.centro_custo_id, s.categoria_id, s.prazo_estimado, s.justificativa, cc.nome AS centro_nome, cat.nome AS categoria_nome FROM solicitacoes s LEFT JOIN centros_custo cc ON cc.id = s.centro_custo_id LEFT JOIN categorias cat ON cat.id = s.categoria_id WHERE s.id = :id');
    $requestQuery->execute(array('id' => $requestId));
    $request = $requestQuery->fetch();

    if (!$request) {
        return array('Solicitação não encontrada para validar os dados obrigatórios.');
    }

    if (empty($request['centro_custo_id']) || $request['centro_nome'] === null) {
        $issues[] = 'Centro de Custo não informado ou inexistente.';
    }

    if (empty($request['categoria_id']) || $request['categoria_nome'] === null) {
        $issues[] = 'Categoria não informada ou inexistente.';
    }

    if (empty($request['prazo_estimado'])) {
        $issues[] = 'Prazo estimado não informado.';
    }

    if (trim((string) $request['justificativa']) === '') {
        $issues[] = 'Justificativa da solicitação não informada.';
    }

    $itemsQuery = $pdo->prepare('SELECT si.id, si.produto_id, si.descricao_snapshot, si.quantidade, p.categoria_id AS produto_categoria_id FROM solicitacao_itens si LEFT JOIN produtos p ON p.id = si.produto_id WHERE si.solicitacao_id = :request_id ORDER BY si.id');
    $itemsQuery->execute(array('request_id' => $requestId));
    $items = $itemsQuery->fetchAll();

    if (!$items) {
        $issues[] = 'A solicitação precisa ter ao menos um produto.';
    }

    foreach ($items as $item) {
        if (empty($item['produto_id']) || trim((string) $item['descricao_snapshot']) === '' || (float) $item['quantidade'] <= 0) {
            $issues[] = 'Há um produto sem descrição, vínculo válido ou quantidade positiva.';
            break;
        }
        if ($item['produto_categoria_id'] === null || (int) $item['produto_categoria_id'] !== (int) $request['categoria_id']) {
            $issues[] = 'Todos os produtos da solicitação devem pertencer à categoria selecionada.';
            break;
        }
    }

    if (in_array($stageCode, array('cotacao', 'aprovacao_valor', 'pedido', 'diretor_alcada'), true)) {
        $mapQuery = $pdo->prepare('SELECT id, status, justificativa_menos_tres FROM mapas_cotacao WHERE solicitacao_id = :request_id ORDER BY versao DESC LIMIT 1');
        $mapQuery->execute(array('request_id' => $requestId));
        $map = $mapQuery->fetch();

        if (!$map) {
            $issues[] = 'O mapa de cotação ainda não foi cadastrado.';
        } else {
            $allowedStatuses = $stageCode === 'cotacao' || $stageCode === 'aprovacao_valor' ? array('aguardando_aprovacao') : array('aprovado', 'congelado');
            if (!in_array($map['status'], $allowedStatuses, true)) {
                $issues[] = 'O mapa de cotação ainda não foi finalizado ou aprovado para esta etapa.';
            }
            $quotesQuery = $pdo->prepare('SELECT c.id, c.fornecedor_id, c.valor_total, c.vencedora, c.justificativa_escolha, COALESCE(f.razao_social, CONCAT(\'Fornecedor #\', c.fornecedor_id)) AS fornecedor, (SELECT COUNT(*) FROM anexos a WHERE a.cotacao_id = c.id) AS anexos FROM cotacoes c LEFT JOIN fornecedores f ON f.id = c.fornecedor_id WHERE c.mapa_id = :map_id ORDER BY c.id');
            $quotesQuery->execute(array('map_id' => $map['id']));
            $quotes = $quotesQuery->fetchAll();
            $distinctSuppliers = array();
            foreach ($quotes as $quote) {
                $distinctSuppliers[(int) $quote['fornecedor_id']] = true;
                if ((int) $quote['anexos'] < 1) {
                    $issues[] = 'Anexe o comprovante da cotação de ' . $quote['fornecedor'] . ' antes de avançar.';
                }
            }
            if (count($distinctSuppliers) < 3 && trim((string) $map['justificativa_menos_tres']) === '') {
                $issues[] = 'O mapa precisa de pelo menos três fornecedores distintos ou de uma justificativa para a exceção.';
            }
            if (!$quotes) {
                $issues[] = 'O mapa de cotação precisa conter ao menos uma oferta.';
            }
            $winners = array_values(array_filter($quotes, function ($quote) { return (int) $quote['vencedora'] === 1; }));
            if (count($winners) !== 1) {
                $issues[] = 'O mapa precisa ter exatamente uma oferta vencedora.';
            } elseif ($quotes) {
                $lowestTotalQuery = $pdo->prepare('SELECT MIN(valor_total) FROM cotacoes WHERE mapa_id = :map_id');
                $lowestTotalQuery->execute(array('map_id' => $map['id']));
                if ((float) $winners[0]['valor_total'] > (float) $lowestTotalQuery->fetchColumn() && trim((string) $winners[0]['justificativa_escolha']) === '') {
                    $issues[] = 'Justifique a escolha da oferta vencedora quando ela não for a de menor valor.';
                }
            }
        }
    }

    return array_values(array_unique($issues));
}

class WorkflowIntegrityException extends RuntimeException {}
