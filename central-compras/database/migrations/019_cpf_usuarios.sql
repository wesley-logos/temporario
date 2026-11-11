-- Armazena o CPF informado na importação de usuários.
-- Execute uma vez após as migrações anteriores.
USE central_compras;

ALTER TABLE usuarios
  ADD COLUMN cpf CHAR(11) NULL AFTER nome,
  ADD UNIQUE KEY uq_usuario_cpf (cpf);
