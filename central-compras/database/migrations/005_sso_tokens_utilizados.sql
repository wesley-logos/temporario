-- Aplicar uma unica vez em cada ambiente antes de habilitar o endpoint SSO.
USE central_compras;

CREATE TABLE sso_tokens_utilizados (
  jti VARCHAR(64) NOT NULL PRIMARY KEY,
  expira_em DATETIME NOT NULL,
  ip VARCHAR(45) NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_sso_token_expira (expira_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
