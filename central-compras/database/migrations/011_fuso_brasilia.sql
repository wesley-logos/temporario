-- Aplicar uma unica vez para converter os campos que eram gravados explicitamente em UTC.
-- Os dados DATETIME antigos nao carregam metadados de fuso; nao converta outras colunas
-- sem confirmar o fuso do servidor que as gravou.
USE central_compras;
SET time_zone = '-03:00';

UPDATE log_sistema
SET ocorrido_em = DATE_ADD(ocorrido_em, INTERVAL 3 HOUR);

UPDATE usuarios
SET ultimo_acesso_em = DATE_ADD(ultimo_acesso_em, INTERVAL 3 HOUR)
WHERE ultimo_acesso_em IS NOT NULL;

UPDATE sso_tokens_utilizados
SET expira_em = DATE_ADD(expira_em, INTERVAL 3 HOUR);
