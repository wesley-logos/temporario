-- Permite comparar o preço unitário e o total cotado para cada item da solicitação.
-- Execute uma vez após as migrações anteriores.
USE central_compras;

CREATE TABLE cotacao_itens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cotacao_id BIGINT UNSIGNED NOT NULL,
  solicitacao_item_id BIGINT UNSIGNED NOT NULL,
  valor_unitario DECIMAL(15,4) NOT NULL,
  quantidade DECIMAL(15,3) NOT NULL,
  valor_total DECIMAL(15,2) NOT NULL,
  CONSTRAINT fk_ci_cotacao FOREIGN KEY (cotacao_id) REFERENCES cotacoes(id) ON DELETE CASCADE,
  CONSTRAINT fk_ci_item FOREIGN KEY (solicitacao_item_id) REFERENCES solicitacao_itens(id),
  UNIQUE KEY uq_cotacao_item_solicitado (cotacao_id, solicitacao_item_id),
  KEY ix_ci_item (solicitacao_item_id)
) ENGINE=InnoDB;
