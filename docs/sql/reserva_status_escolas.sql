-- ==========================================================
-- Novas colunas para controle de reserva e status
-- Tabela: escola_inep
-- ==========================================================

ALTER TABLE escola_inep
    ADD COLUMN status VARCHAR(20) NULL AFTER updated_at,
    ADD COLUMN reservado_para VARCHAR(100) NULL AFTER status;

CREATE INDEX idx_ei_status ON escola_inep (status);
CREATE INDEX idx_ei_reservado_para ON escola_inep (reservado_para);

-- ==========================================================
-- Consultas úteis
-- ==========================================================

-- Escolas disponíveis (nunca reservadas)
SELECT id, codigo_inep, escola, uf, municipio, status, reservado_para
FROM escola_inep
WHERE uf = 'MG'
  AND status IS NULL
ORDER BY escola ASC
LIMIT 100;

-- Escolas reservadas para um username
SELECT id, codigo_inep, escola, uf, municipio, status, reservado_para
FROM escola_inep
WHERE uf = 'MG'
  AND reservado_para = 'joao.silva'
ORDER BY escola ASC;

-- Reservar as próximas 100 escolas disponíveis para um username
UPDATE escola_inep
SET status = 'reservado',
    reservado_para = 'joao.silva',
    updated_at = NOW()
WHERE id IN (
    SELECT id FROM (
        SELECT id
        FROM escola_inep
        WHERE uf = 'MG'
          AND status IS NULL
        ORDER BY escola ASC
        LIMIT 100
    ) AS disponiveis
);

-- Confirmar uma escola
UPDATE escola_inep
SET status = 'confirmado',
    reservado_para = 'joao.silva',
    updated_at = NOW()
WHERE id = 1;

-- Marcar como não localizado
UPDATE escola_inep
SET status = 'nao_localizado',
    reservado_para = 'joao.silva',
    updated_at = NOW()
WHERE id = 1;

-- Marcar como pulado
UPDATE escola_inep
SET status = 'pulado',
    reservado_para = 'joao.silva',
    updated_at = NOW()
WHERE id = 1;

-- Liberar escolas reservadas por um username
UPDATE escola_inep
SET status = NULL,
    reservado_para = NULL,
    updated_at = NOW()
WHERE uf = 'MG'
  AND status = 'reservado'
  AND reservado_para = 'joao.silva';

-- Resumo por status
SELECT status, COUNT(*) AS total
FROM escola_inep
WHERE uf = 'MG'
GROUP BY status;

-- Resumo por username
SELECT reservado_para, status, COUNT(*) AS total
FROM escola_inep
WHERE uf = 'MG'
  AND reservado_para IS NOT NULL
GROUP BY reservado_para, status
ORDER BY reservado_para, status;
