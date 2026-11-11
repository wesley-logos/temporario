-- Permite combinar um grupo hierarquizado com uma lista ordenada de aprovadores individuais.
-- Execute uma vez após as migrações anteriores.
USE central_compras;

ALTER TABLE regras_etapa
  MODIFY COLUMN responsavel_tipo ENUM('grupo','usuario','grupo_usuario') NOT NULL DEFAULT 'grupo';

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

-- Migra as regras existentes de usuário individual para o novo relacionamento ordenado.
INSERT INTO regras_etapa_usuarios (regra_etapa_id, usuario_id, ordem)
SELECT id, usuario_id, 1 FROM regras_etapa WHERE usuario_id IS NOT NULL;
