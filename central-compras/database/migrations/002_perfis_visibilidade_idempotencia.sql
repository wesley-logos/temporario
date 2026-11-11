-- Execute uma única vez em bancos que já possuem o schema anterior.
USE central_compras;

ALTER TABLE solicitacoes
  ADD COLUMN comprador_id BIGINT UNSIGNED NULL AFTER solicitante_id,
  ADD COLUMN chave_idempotencia VARCHAR(64) NULL AFTER justificativa,
  ADD COLUMN payload_hash CHAR(64) NULL AFTER chave_idempotencia,
  ADD UNIQUE KEY uq_sol_idempotencia (solicitante_id, chave_idempotencia),
  ADD KEY ix_sol_comprador (comprador_id, criada_em),
  ADD CONSTRAINT fk_sol_comprador FOREIGN KEY (comprador_id) REFERENCES usuarios(id);

CREATE TABLE contratos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  solicitacao_id BIGINT UNSIGNED NOT NULL,
  centro_custo_id BIGINT UNSIGNED NOT NULL,
  numero_omie VARCHAR(100) NULL,
  descricao VARCHAR(240) NOT NULL,
  fornecedor_id BIGINT UNSIGNED NULL,
  valor_total DECIMAL(15,2) NULL,
  status VARCHAR(40) NOT NULL,
  inicio_vigencia DATE NULL,
  fim_vigencia DATE NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_contrato_sol FOREIGN KEY (solicitacao_id) REFERENCES solicitacoes(id),
  CONSTRAINT fk_contrato_cc FOREIGN KEY (centro_custo_id) REFERENCES centros_custo(id),
  CONSTRAINT fk_contrato_forn FOREIGN KEY (fornecedor_id) REFERENCES fornecedores(id),
  UNIQUE KEY uq_contrato_solicitacao (solicitacao_id),
  KEY ix_contrato_cc_status (centro_custo_id, status)
) ENGINE=InnoDB;
