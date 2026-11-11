-- A tabela usuario_perfis passa a permitir somente um vínculo por usuário.
-- Antes de executar o ALTER, resolva os usuários retornados por esta consulta
-- em Administração > Gerenciamento de usuários: ao salvar cada cadastro,
-- será mantido um perfil e um escopo, e os demais vínculos serão removidos.
USE central_compras;

SELECT usuario_id, COUNT(*) AS quantidade_vinculos
FROM usuario_perfis
GROUP BY usuario_id
HAVING COUNT(*) > 1;

ALTER TABLE usuario_perfis
  DROP INDEX ix_up_usuario_perfil,
  ADD UNIQUE KEY uq_up_usuario (usuario_id);
