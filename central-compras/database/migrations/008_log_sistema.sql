-- Registro de ações de usuários e erros técnicos da aplicação.
-- Não cria chave estrangeira para usuarios para preservar o histórico após remoções.
CREATE TABLE log_sistema (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id BIGINT UNSIGNED NULL,
  item_id BIGINT UNSIGNED NULL,
  entidade VARCHAR(80) NULL,
  acao VARCHAR(120) NOT NULL,
  status ENUM('sucesso','falha','negado') NOT NULL DEFAULT 'sucesso',
  ip_usuario VARCHAR(45) NULL,
  ocorrido_em DATETIME(6) NOT NULL,
  detalhes TEXT NULL,
  erro TEXT NULL,
  KEY ix_log_usuario_data (usuario_id, ocorrido_em),
  KEY ix_log_item (entidade, item_id),
  KEY ix_log_acao_data (acao, ocorrido_em),
  KEY ix_log_status_data (status, ocorrido_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
