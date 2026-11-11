-- Execute uma vez após as migrações anteriores.
USE central_compras;

ALTER TABLE grupo_aprovadores
  ADD COLUMN ordem_aprovacao SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER usuario_id;
