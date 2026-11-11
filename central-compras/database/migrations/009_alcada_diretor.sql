-- Limite de valor versionado por fluxo e etapa adicional de aprovação executada.
ALTER TABLE fluxos_aprovacao
  ADD COLUMN limite_alcada_diretor DECIMAL(15,2) NULL AFTER vigente_ate;

ALTER TABLE etapas_solicitacao
  MODIFY COLUMN codigo_etapa ENUM('solicitacao','cotacao','pedido','diretor_alcada') NOT NULL;
