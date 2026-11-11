-- Permite cadastrar Centros de Custo locais antes da sincronização com o OMIE.
USE central_compras;

ALTER TABLE centros_custo
  MODIFY COLUMN omie_id VARCHAR(80) NULL;
