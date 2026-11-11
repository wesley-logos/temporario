-- Tokens de uso único para redefinição de senha.
-- Execute uma vez após as migrações anteriores.
USE central_compras;

CREATE TABLE password_reset_tokens (
  token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  usuario_id BIGINT UNSIGNED NOT NULL,
  expira_em DATETIME NOT NULL,
  solicitado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  consumido_em DATETIME NULL,
  ip_origem VARCHAR(45) NULL,
  CONSTRAINT fk_prt_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  KEY ix_prt_usuario_data (usuario_id, solicitado_em),
  KEY ix_prt_usuario_status (usuario_id, consumido_em, expira_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
