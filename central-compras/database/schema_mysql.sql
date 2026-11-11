CREATE DATABASE IF NOT EXISTS central_compras
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE central_compras;
-- A aplicacao tambem define este fuso em cada conexao PDO.
SET time_zone = '-03:00';

CREATE TABLE usuarios (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  matricula VARCHAR(40) NOT NULL UNIQUE,
  email VARCHAR(254) NOT NULL,
  nome VARCHAR(180) NOT NULL,
  cpf CHAR(11) NULL,
  senha_hash VARCHAR(255) NOT NULL,
  senha_temporaria TINYINT(1) NOT NULL DEFAULT 0,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  ultimo_acesso_em DATETIME NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_usuario_email (email),
  UNIQUE KEY uq_usuario_cpf (cpf)
) ENGINE=InnoDB;

CREATE TABLE perfis (
  id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(40) NOT NULL UNIQUE,
  nome VARCHAR(100) NOT NULL,
  descricao VARCHAR(255) NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE unidades (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  omie_id VARCHAR(80) NULL UNIQUE,
  codigo VARCHAR(40) NULL,
  nome VARCHAR(160) NOT NULL,
  ativa TINYINT(1) NOT NULL DEFAULT 1,
  sincronizado_em DATETIME NULL
) ENGINE=InnoDB;

CREATE TABLE centros_custo (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  omie_id VARCHAR(80) NULL UNIQUE,
  unidade_id BIGINT UNSIGNED NULL,
  codigo VARCHAR(50) NULL,
  nome VARCHAR(180) NOT NULL,
  ativo_omie TINYINT(1) NOT NULL DEFAULT 1,
  sincronizado_em DATETIME NULL,
  CONSTRAINT fk_cc_unidade FOREIGN KEY (unidade_id) REFERENCES unidades(id),
  KEY ix_cc_nome (nome), KEY ix_cc_ativo (ativo_omie)
) ENGINE=InnoDB;

CREATE TABLE usuario_perfis (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id BIGINT UNSIGNED NOT NULL,
  perfil_id SMALLINT UNSIGNED NOT NULL,
  unidade_id BIGINT UNSIGNED NULL,
  centro_custo_id BIGINT UNSIGNED NULL,
  concedido_por BIGINT UNSIGNED NULL,
  valido_ate DATETIME NULL,
  CONSTRAINT fk_up_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  CONSTRAINT fk_up_perfil FOREIGN KEY (perfil_id) REFERENCES perfis(id),
  CONSTRAINT fk_up_unidade FOREIGN KEY (unidade_id) REFERENCES unidades(id),
  CONSTRAINT fk_up_cc FOREIGN KEY (centro_custo_id) REFERENCES centros_custo(id),
  CONSTRAINT fk_up_concedido FOREIGN KEY (concedido_por) REFERENCES usuarios(id),
  UNIQUE KEY uq_up_usuario (usuario_id),
  KEY ix_up_escopo (unidade_id, centro_custo_id)
) ENGINE=InnoDB;

CREATE TABLE categorias (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  omie_id VARCHAR(80) NOT NULL UNIQUE,
  codigo VARCHAR(50) NULL,
  nome VARCHAR(180) NOT NULL,
  ativo_omie TINYINT(1) NOT NULL DEFAULT 1,
  sincronizado_em DATETIME NULL
) ENGINE=InnoDB;

CREATE TABLE produtos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  omie_id VARCHAR(80) NOT NULL UNIQUE,
  categoria_id BIGINT UNSIGNED NULL,
  codigo VARCHAR(80) NULL,
  nome VARCHAR(240) NOT NULL,
  unidade_medida VARCHAR(30) NULL,
  ativo_omie TINYINT(1) NOT NULL DEFAULT 1,
  sincronizado_em DATETIME NULL,
  CONSTRAINT fk_prod_categoria FOREIGN KEY (categoria_id) REFERENCES categorias(id),
  KEY ix_prod_nome (nome), KEY ix_prod_ativo (ativo_omie)
) ENGINE=InnoDB;

CREATE TABLE fornecedores (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  omie_id VARCHAR(80) NULL UNIQUE,
  documento_normalizado VARCHAR(14) NOT NULL UNIQUE,
  razao_social VARCHAR(240) NOT NULL,
  tipo ENUM('homologado','candidato') NOT NULL DEFAULT 'candidato',
  ativo_omie TINYINT(1) NULL,
  dados_fiscais JSON NULL,
  criado_por BIGINT UNSIGNED NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sincronizado_em DATETIME NULL,
  CONSTRAINT fk_forn_usuario FOREIGN KEY (criado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB;

CREATE TABLE grupos_aprovacao (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(160) NOT NULL,
  descricao VARCHAR(255) NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE grupo_aprovadores (
  grupo_id BIGINT UNSIGNED NOT NULL,
  usuario_id BIGINT UNSIGNED NOT NULL,
  ordem_aprovacao SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  inicio_vigencia DATE NULL,
  fim_vigencia DATE NULL,
  PRIMARY KEY (grupo_id, usuario_id),
  CONSTRAINT fk_ga_grupo FOREIGN KEY (grupo_id) REFERENCES grupos_aprovacao(id),
  CONSTRAINT fk_ga_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

CREATE TABLE fluxos_aprovacao (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(160) NOT NULL,
  versao INT UNSIGNED NOT NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 0,
  vigente_desde DATETIME NULL,
  vigente_ate DATETIME NULL,
  limite_alcada_diretor DECIMAL(15,2) NULL,
  UNIQUE KEY uq_fluxo_versao (nome, versao)
) ENGINE=InnoDB;

CREATE TABLE fluxo_centro_custo (
  fluxo_id BIGINT UNSIGNED NOT NULL,
  centro_custo_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (fluxo_id, centro_custo_id),
  CONSTRAINT fk_fcc_fluxo FOREIGN KEY (fluxo_id) REFERENCES fluxos_aprovacao(id),
  CONSTRAINT fk_fcc_cc FOREIGN KEY (centro_custo_id) REFERENCES centros_custo(id)
) ENGINE=InnoDB;

CREATE TABLE regras_etapa (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fluxo_id BIGINT UNSIGNED NOT NULL,
  codigo_etapa ENUM('solicitacao','cotacao','aprovacao_valor','pedido') NOT NULL,
  ordem SMALLINT UNSIGNED NOT NULL,
  ativa TINYINT(1) NOT NULL DEFAULT 1,
  modo ENUM('E','OU') NOT NULL DEFAULT 'E',
  responsavel_tipo ENUM('grupo','usuario','grupo_usuario') NOT NULL DEFAULT 'grupo',
  grupo_id BIGINT UNSIGNED NULL,
  usuario_id BIGINT UNSIGNED NULL,
  limite_valor DECIMAL(15,2) NULL,
  condicao JSON NULL,
  CONSTRAINT fk_re_fluxo FOREIGN KEY (fluxo_id) REFERENCES fluxos_aprovacao(id),
  CONSTRAINT fk_re_grupo FOREIGN KEY (grupo_id) REFERENCES grupos_aprovacao(id),
  CONSTRAINT fk_re_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  UNIQUE KEY uq_regra_ordem (fluxo_id, ordem)
) ENGINE=InnoDB;

CREATE TABLE regras_etapa_usuarios (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  regra_etapa_id BIGINT UNSIGNED NOT NULL,
  usuario_id BIGINT UNSIGNED NOT NULL,
  ordem SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  CONSTRAINT fk_reu_regra FOREIGN KEY (regra_etapa_id) REFERENCES regras_etapa(id) ON DELETE CASCADE,
  CONSTRAINT fk_reu_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  UNIQUE KEY uq_reu_regra_usuario (regra_etapa_id, usuario_id),
  UNIQUE KEY uq_reu_regra_ordem (regra_etapa_id, ordem)
) ENGINE=InnoDB;

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
  codigo_etapa ENUM('solicitacao','cotacao','aprovacao_valor','pedido') NOT NULL,
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

CREATE TABLE solicitacoes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  numero VARCHAR(32) NOT NULL UNIQUE,
  solicitante_id BIGINT UNSIGNED NOT NULL,
  comprador_id BIGINT UNSIGNED NULL,
  centro_custo_id BIGINT UNSIGNED NOT NULL,
  categoria_id BIGINT UNSIGNED NULL,
  fluxo_id BIGINT UNSIGNED NULL,
  status VARCHAR(50) NOT NULL DEFAULT 'rascunho',
  etapa_atual VARCHAR(40) NULL,
  omie_integracao_status ENUM('pendente','enviado','confirmado','erro') NOT NULL DEFAULT 'pendente',
  omie_pedido_id VARCHAR(100) NULL,
  prazo_estimado DATE NULL,
  justificativa TEXT NULL,
  chave_idempotencia VARCHAR(64) NOT NULL,
  payload_hash CHAR(64) NOT NULL,
  versao INT UNSIGNED NOT NULL DEFAULT 1,
  criada_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizada_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  encerrada_em DATETIME NULL,
  CONSTRAINT fk_sol_usuario FOREIGN KEY (solicitante_id) REFERENCES usuarios(id),
  CONSTRAINT fk_sol_comprador FOREIGN KEY (comprador_id) REFERENCES usuarios(id),
  CONSTRAINT fk_sol_cc FOREIGN KEY (centro_custo_id) REFERENCES centros_custo(id),
  CONSTRAINT fk_sol_categoria FOREIGN KEY (categoria_id) REFERENCES categorias(id),
  CONSTRAINT fk_sol_fluxo FOREIGN KEY (fluxo_id) REFERENCES fluxos_aprovacao(id),
  UNIQUE KEY uq_sol_idempotencia (solicitante_id, chave_idempotencia),
  KEY ix_sol_status (status, criada_em), KEY ix_sol_solicitante (solicitante_id, criada_em),
  KEY ix_sol_comprador (comprador_id, criada_em)
) ENGINE=InnoDB;

CREATE TABLE contratos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  solicitacao_id BIGINT UNSIGNED NOT NULL,
  centro_custo_id BIGINT UNSIGNED NOT NULL,
  numero_omie VARCHAR(100) NULL,
  descricao VARCHAR(240) NOT NULL,
  fornecedor_id BIGINT UNSIGNED NULL,
  valor_total DECIMAL(15,2) NULL,
  status VARCHAR(40) NOT NULL,
  inicio_vigencia DATE NULL,
  fim_vigencia DATE NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_contrato_sol FOREIGN KEY (solicitacao_id) REFERENCES solicitacoes(id),
  CONSTRAINT fk_contrato_cc FOREIGN KEY (centro_custo_id) REFERENCES centros_custo(id),
  CONSTRAINT fk_contrato_forn FOREIGN KEY (fornecedor_id) REFERENCES fornecedores(id),
  UNIQUE KEY uq_contrato_solicitacao (solicitacao_id),
  KEY ix_contrato_cc_status (centro_custo_id, status)
) ENGINE=InnoDB;

CREATE TABLE solicitacao_itens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  solicitacao_id BIGINT UNSIGNED NOT NULL,
  produto_id BIGINT UNSIGNED NOT NULL,
  descricao_snapshot VARCHAR(240) NOT NULL,
  unidade_snapshot VARCHAR(30) NULL,
  quantidade DECIMAL(15,3) NOT NULL,
  observacao TEXT NULL,
  CONSTRAINT fk_si_sol FOREIGN KEY (solicitacao_id) REFERENCES solicitacoes(id),
  CONSTRAINT fk_si_prod FOREIGN KEY (produto_id) REFERENCES produtos(id),
  KEY ix_si_solicitacao (solicitacao_id)
) ENGINE=InnoDB;

CREATE TABLE etapas_solicitacao (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  solicitacao_id BIGINT UNSIGNED NOT NULL,
  codigo_etapa ENUM('solicitacao','cotacao','aprovacao_valor','pedido','diretor_alcada') NOT NULL,
  ordem_execucao SMALLINT UNSIGNED NOT NULL,
  status VARCHAR(40) NOT NULL,
  regra_snapshot JSON NOT NULL,
  responsavel_tipo VARCHAR(30) NULL,
  responsavel_id BIGINT UNSIGNED NULL,
  iniciada_em DATETIME NULL,
  concluida_em DATETIME NULL,
  CONSTRAINT fk_es_sol FOREIGN KEY (solicitacao_id) REFERENCES solicitacoes(id),
  UNIQUE KEY uq_es_ordem (solicitacao_id, ordem_execucao)
) ENGINE=InnoDB;

CREATE TABLE decisoes_aprovacao (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  etapa_solicitacao_id BIGINT UNSIGNED NOT NULL,
  aprovador_id BIGINT UNSIGNED NOT NULL,
  decisao ENUM('aprovado','reprovado','devolvido','informacao') NOT NULL,
  justificativa TEXT NULL,
  ip_origem VARCHAR(45) NULL,
  criada_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_da_etapa FOREIGN KEY (etapa_solicitacao_id) REFERENCES etapas_solicitacao(id),
  CONSTRAINT fk_da_usuario FOREIGN KEY (aprovador_id) REFERENCES usuarios(id),
  UNIQUE KEY uq_decisao_aprovador_etapa (etapa_solicitacao_id, aprovador_id),
  KEY ix_da_etapa (etapa_solicitacao_id, criada_em)
) ENGINE=InnoDB;

CREATE TABLE comentarios (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  solicitacao_id BIGINT UNSIGNED NOT NULL,
  autor_id BIGINT UNSIGNED NOT NULL,
  comentario_pai_id BIGINT UNSIGNED NULL,
  tipo VARCHAR(30) NOT NULL DEFAULT 'comentario',
  texto TEXT NOT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_com_sol FOREIGN KEY (solicitacao_id) REFERENCES solicitacoes(id),
  CONSTRAINT fk_com_autor FOREIGN KEY (autor_id) REFERENCES usuarios(id),
  CONSTRAINT fk_com_pai FOREIGN KEY (comentario_pai_id) REFERENCES comentarios(id),
  KEY ix_com_sol (solicitacao_id, criado_em)
) ENGINE=InnoDB;

CREATE TABLE mapas_cotacao (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  solicitacao_id BIGINT UNSIGNED NOT NULL,
  versao INT UNSIGNED NOT NULL DEFAULT 1,
  status VARCHAR(40) NOT NULL DEFAULT 'aberto',
  justificativa_menos_tres TEXT NULL,
  aberto_por BIGINT UNSIGNED NOT NULL,
  aprovado_em DATETIME NULL,
  congelado_em DATETIME NULL,
  CONSTRAINT fk_mc_sol FOREIGN KEY (solicitacao_id) REFERENCES solicitacoes(id),
  CONSTRAINT fk_mc_usuario FOREIGN KEY (aberto_por) REFERENCES usuarios(id),
  UNIQUE KEY uq_mc_versao (solicitacao_id, versao)
) ENGINE=InnoDB;

CREATE TABLE cotacoes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  mapa_id BIGINT UNSIGNED NOT NULL,
  fornecedor_id BIGINT UNSIGNED NOT NULL,
  valor_total DECIMAL(15,2) NOT NULL,
  moeda CHAR(3) NOT NULL DEFAULT 'BRL',
  prazo_entrega_dias INT UNSIGNED NULL,
  condicao_pagamento VARCHAR(180) NULL,
  frete_tipo ENUM('CIF','FOB','outro') NULL,
  vencedora TINYINT(1) NOT NULL DEFAULT 0,
  justificativa_escolha TEXT NULL,
  CONSTRAINT fk_cot_map FOREIGN KEY (mapa_id) REFERENCES mapas_cotacao(id),
  CONSTRAINT fk_cot_forn FOREIGN KEY (fornecedor_id) REFERENCES fornecedores(id),
  UNIQUE KEY uq_cot_fornecedor (mapa_id, fornecedor_id)
) ENGINE=InnoDB;

CREATE TABLE cotacao_itens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cotacao_id BIGINT UNSIGNED NOT NULL,
  solicitacao_item_id BIGINT UNSIGNED NOT NULL,
  valor_unitario DECIMAL(15,4) NOT NULL,
  quantidade DECIMAL(15,3) NOT NULL,
  valor_total DECIMAL(15,2) NOT NULL,
  CONSTRAINT fk_ci_cotacao FOREIGN KEY (cotacao_id) REFERENCES cotacoes(id) ON DELETE CASCADE,
  CONSTRAINT fk_ci_item FOREIGN KEY (solicitacao_item_id) REFERENCES solicitacao_itens(id),
  UNIQUE KEY uq_cotacao_item_solicitado (cotacao_id, solicitacao_item_id),
  KEY ix_ci_item (solicitacao_item_id)
) ENGINE=InnoDB;

CREATE TABLE anexos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  solicitacao_id BIGINT UNSIGNED NOT NULL,
  cotacao_id BIGINT UNSIGNED NULL,
  autor_id BIGINT UNSIGNED NOT NULL,
  nome_original VARCHAR(255) NOT NULL,
  mime_type VARCHAR(120) NOT NULL,
  tamanho BIGINT UNSIGNED NOT NULL,
  storage_key VARCHAR(500) NOT NULL,
  checksum CHAR(64) NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_an_sol FOREIGN KEY (solicitacao_id) REFERENCES solicitacoes(id),
  CONSTRAINT fk_an_cot FOREIGN KEY (cotacao_id) REFERENCES cotacoes(id),
  CONSTRAINT fk_an_usuario FOREIGN KEY (autor_id) REFERENCES usuarios(id),
  KEY ix_an_sol (solicitacao_id)
) ENGINE=InnoDB;

CREATE TABLE execucoes_sla (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  etapa_solicitacao_id BIGINT UNSIGNED NOT NULL,
  politica_snapshot JSON NOT NULL,
  iniciada_em DATETIME NOT NULL,
  vence_em DATETIME NULL,
  segundos_consumidos INT UNSIGNED NOT NULL DEFAULT 0,
  segundos_pausados INT UNSIGNED NOT NULL DEFAULT 0,
  pausada_em DATETIME NULL,
  encerrada_em DATETIME NULL,
  resultado VARCHAR(30) NULL,
  CONSTRAINT fk_sla_etapa FOREIGN KEY (etapa_solicitacao_id) REFERENCES etapas_solicitacao(id),
  UNIQUE KEY uq_sla_etapa_execucao (etapa_solicitacao_id),
  KEY ix_sla_vencimento (vence_em, encerrada_em)
) ENGINE=InnoDB;

CREATE TABLE integracao_eventos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  solicitacao_id BIGINT UNSIGNED NULL,
  direcao ENUM('entrada','saida') NOT NULL,
  tipo_evento VARCHAR(80) NOT NULL,
  chave_idempotencia VARCHAR(160) NOT NULL UNIQUE,
  referencia_externa VARCHAR(160) NULL,
  http_status SMALLINT UNSIGNED NULL,
  resposta_json JSON NULL,
  status VARCHAR(30) NOT NULL,
  mensagem_usuario VARCHAR(500) NULL,
  erro_tecnico TEXT NULL,
  tentativas SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  proxima_tentativa_em DATETIME NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ie_sol FOREIGN KEY (solicitacao_id) REFERENCES solicitacoes(id),
  KEY ix_ie_retry (status, proxima_tentativa_em)
) ENGINE=InnoDB;

CREATE TABLE auditoria_eventos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ator_id BIGINT UNSIGNED NULL,
  solicitacao_id BIGINT UNSIGNED NULL,
  acao VARCHAR(80) NOT NULL,
  entidade VARCHAR(80) NOT NULL,
  entidade_id VARCHAR(80) NULL,
  valor_anterior JSON NULL,
  valor_novo JSON NULL,
  ip_origem VARCHAR(45) NULL,
  correlation_id VARCHAR(100) NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ae_ator FOREIGN KEY (ator_id) REFERENCES usuarios(id),
  CONSTRAINT fk_ae_sol FOREIGN KEY (solicitacao_id) REFERENCES solicitacoes(id),
  KEY ix_ae_sol_data (solicitacao_id, criado_em),
  KEY ix_ae_entidade (entidade, entidade_id)
) ENGINE=InnoDB;

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

CREATE TABLE sso_tokens_utilizados (
  jti VARCHAR(64) NOT NULL PRIMARY KEY,
  expira_em DATETIME NOT NULL,
  ip VARCHAR(45) NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_sso_token_expira (expira_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

INSERT INTO perfis (codigo, nome, descricao) VALUES
 ('solicitante', 'Solicitante', 'Abre e acompanha solicitações'),
 ('comprador', 'Comprador', 'Gerencia cotações e pedidos'),
 ('gestor', 'Gestor', 'Aprova solicitações no escopo atribuído'),
 ('diretor', 'Diretor', 'Consulta e aprova conforme alçada'),
 ('administrador', 'Administrador', 'Configura fluxos, acessos e SLA');
