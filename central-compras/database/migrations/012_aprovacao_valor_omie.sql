-- Adiciona a aprovação explícita da oferta vencedora e prepara o retorno do pedido OMIE.
USE central_compras;

ALTER TABLE regras_etapa
  MODIFY COLUMN codigo_etapa ENUM('solicitacao','cotacao','aprovacao_valor','pedido') NOT NULL;

ALTER TABLE politicas_sla
  MODIFY COLUMN codigo_etapa ENUM('solicitacao','cotacao','aprovacao_valor','pedido') NOT NULL;

ALTER TABLE etapas_solicitacao
  MODIFY COLUMN codigo_etapa ENUM('solicitacao','cotacao','aprovacao_valor','pedido','diretor_alcada') NOT NULL;

ALTER TABLE solicitacoes
  ADD COLUMN omie_integracao_status ENUM('pendente','enviado','confirmado','erro') NOT NULL DEFAULT 'pendente' AFTER etapa_atual,
  ADD COLUMN omie_pedido_id VARCHAR(100) NULL AFTER omie_integracao_status;

ALTER TABLE integracao_eventos
  ADD COLUMN resposta_json JSON NULL AFTER http_status;
