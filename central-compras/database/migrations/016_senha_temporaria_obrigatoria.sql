-- Execute uma vez em bancos que já possuem o schema anterior.
USE central_compras;

ALTER TABLE usuarios
  ADD COLUMN senha_temporaria TINYINT(1) NOT NULL DEFAULT 0 AFTER senha_hash;
