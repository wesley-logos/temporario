-- Execute uma única vez após as migrações anteriores.
USE central_compras;

CREATE TABLE calendarios (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(120) NOT NULL,
  fuso_horario VARCHAR(80) NOT NULL DEFAULT 'America/Sao_Paulo',
  dias_semana_json JSON NOT NULL,
  hora_inicio TIME NOT NULL DEFAULT '08:00:00',
  hora_fim TIME NOT NULL DEFAULT '18:00:00',
  criado_por BIGINT UNSIGNED NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_calendario_usuario FOREIGN KEY (criado_por) REFERENCES usuarios(id),
  UNIQUE KEY uq_calendario_nome (nome)
) ENGINE=InnoDB;

CREATE TABLE feriados (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  calendario_id BIGINT UNSIGNED NOT NULL,
  data DATE NOT NULL,
  descricao VARCHAR(160) NOT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_feriado_calendario FOREIGN KEY (calendario_id) REFERENCES calendarios(id),
  UNIQUE KEY uq_feriado_data (calendario_id, data),
  KEY ix_feriado_data (data)
) ENGINE=InnoDB;

CREATE TABLE politicas_sla (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fluxo_id BIGINT UNSIGNED NOT NULL,
  codigo_etapa ENUM('solicitacao','cotacao','pedido') NOT NULL,
  duracao DECIMAL(10,2) NOT NULL,
  unidade_tempo ENUM('horas','dias') NOT NULL,
  regime_contagem ENUM('corridos','uteis') NOT NULL,
  calendario_id BIGINT UNSIGNED NULL,
  janela_inicio TIME NULL,
  janela_fim TIME NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sla_fluxo FOREIGN KEY (fluxo_id) REFERENCES fluxos_aprovacao(id),
  CONSTRAINT fk_sla_calendario FOREIGN KEY (calendario_id) REFERENCES calendarios(id),
  UNIQUE KEY uq_sla_etapa (fluxo_id, codigo_etapa)
) ENGINE=InnoDB;
