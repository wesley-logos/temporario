-- Execute uma vez em bancos que ainda nao possuem a tabela de execucoes de SLA.
USE central_compras;

CREATE TABLE IF NOT EXISTS execucoes_sla (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  etapa_solicitacao_id BIGINT UNSIGNED NOT NULL,
  politica_snapshot JSON NOT NULL,
  iniciada_em DATETIME NOT NULL,
  vence_em DATETIME NULL,
  segundos_consumidos INT UNSIGNED NOT NULL DEFAULT 0,
  segundos_pausados INT UNSIGNED NOT NULL DEFAULT 0,
  pausada_em DATETIME NULL,
  encerrada_em DATETIME NULL,
  resultado VARCHAR(30) NULL,
  CONSTRAINT fk_sla_etapa FOREIGN KEY (etapa_solicitacao_id) REFERENCES etapas_solicitacao(id),
  UNIQUE KEY uq_sla_etapa_execucao (etapa_solicitacao_id),
  KEY ix_sla_vencimento (vence_em, encerrada_em)
) ENGINE=InnoDB;
