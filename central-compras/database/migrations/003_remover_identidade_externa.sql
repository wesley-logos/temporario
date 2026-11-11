-- Execute uma única vez após as migrações anteriores.
-- Antes de rodar, preencha matrícula e senha_hash para todos os usuários existentes.
USE central_compras;

ALTER TABLE usuarios
  MODIFY COLUMN matricula VARCHAR(40) NOT NULL,
  MODIFY COLUMN senha_hash VARCHAR(255) NOT NULL,
  DROP INDEX uq_usuario_idp,
  DROP COLUMN idp_issuer,
  DROP COLUMN idp_subject;
