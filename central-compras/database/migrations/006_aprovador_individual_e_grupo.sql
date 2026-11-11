-- Execute uma vez após as migrações anteriores.
USE central_compras;

ALTER TABLE regras_etapa
  ADD COLUMN responsavel_tipo ENUM('grupo','usuario') NOT NULL DEFAULT 'grupo' AFTER modo,
  ADD COLUMN usuario_id BIGINT UNSIGNED NULL AFTER grupo_id,
  ADD CONSTRAINT fk_re_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id);

-- responsavel_id armazena o ID de um usuário ou grupo conforme responsavel_tipo.
-- A chave estrangeira antiga para usuarios impedia gravar IDs de grupo.
ALTER TABLE etapas_solicitacao
  DROP FOREIGN KEY fk_es_responsavel;

ALTER TABLE decisoes_aprovacao
  ADD UNIQUE KEY uq_decisao_aprovador_etapa (etapa_solicitacao_id, aprovador_id);
