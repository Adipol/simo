-- ============================================================
-- ESQUEMA PostgreSQL - Web Scraper v2.2.0
-- ============================================================
-- Equivalente al schema.sql (MySQL) + migracion_multipais.sql
-- Motor: PostgreSQL 12+
-- ============================================================

-- ============================================================
-- TABLA: sitios_web
-- ============================================================
CREATE TABLE IF NOT EXISTS sitios_web (
    id               SERIAL PRIMARY KEY,
    url              VARCHAR(500) NOT NULL UNIQUE,
    nombre           VARCHAR(200) NOT NULL,
    pais             CHAR(2) DEFAULT 'BO',
    selector_links   VARCHAR(200) DEFAULT NULL,
    selector_article VARCHAR(200) DEFAULT NULL,
    activo           SMALLINT DEFAULT 1,
    fecha_creacion   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_modificacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_sitios_activo ON sitios_web (activo);
CREATE INDEX IF NOT EXISTS idx_sitios_pais   ON sitios_web (pais);

-- Trigger para actualizar fecha_modificacion automáticamente
CREATE OR REPLACE FUNCTION _update_modified_col()
RETURNS TRIGGER AS $$
BEGIN
    NEW.fecha_modificacion = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_sitios_web_modified ON sitios_web;
CREATE TRIGGER trg_sitios_web_modified
    BEFORE UPDATE ON sitios_web
    FOR EACH ROW EXECUTE FUNCTION _update_modified_col();

-- ============================================================
-- TABLA: palabras_clave
-- ============================================================
CREATE TABLE IF NOT EXISTS palabras_clave (
    id             SERIAL PRIMARY KEY,
    keyword        VARCHAR(200) NOT NULL UNIQUE,
    categoria      VARCHAR(100) DEFAULT NULL,
    activo         SMALLINT DEFAULT 1,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_pc_activo    ON palabras_clave (activo);
CREATE INDEX IF NOT EXISTS idx_pc_categoria ON palabras_clave (categoria);

-- ============================================================
-- TABLA: resultados_scraping
-- ============================================================
CREATE TABLE IF NOT EXISTS resultados_scraping (
    id               SERIAL PRIMARY KEY,
    url              VARCHAR(2000) NOT NULL,
    keyword          VARCHAR(200)  NOT NULL,
    sitio_id         INT,
    pais             CHAR(2) DEFAULT 'BO',
    categoria        VARCHAR(20) DEFAULT NULL,
    titulo           VARCHAR(500) DEFAULT NULL,
    contexto         TEXT DEFAULT NULL,
    fecha_encontrado TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Campos de relevancia (v2.2)
    relevance_score  SMALLINT DEFAULT 0,
    found_in_title   SMALLINT DEFAULT 0,

    -- Campos de gestión
    leido            SMALLINT DEFAULT 0,
    relevante        SMALLINT DEFAULT NULL,
    notas            TEXT DEFAULT NULL,

    CONSTRAINT fk_resultado_sitio
        FOREIGN KEY (sitio_id) REFERENCES sitios_web (id) ON DELETE SET NULL,

    -- Unicidad por (url, keyword): evita duplicados igual que INSERT IGNORE en MySQL.
    -- NOTA: Si las URLs superan ~2700 bytes combinados con keyword, usar el índice
    -- funcional comentado al final de este archivo.
    CONSTRAINT idx_url_keyword_unique UNIQUE (url, keyword)
);

CREATE INDEX IF NOT EXISTS idx_rs_keyword       ON resultados_scraping (keyword);
CREATE INDEX IF NOT EXISTS idx_rs_sitio         ON resultados_scraping (sitio_id);
CREATE INDEX IF NOT EXISTS idx_rs_fecha         ON resultados_scraping (fecha_encontrado);
CREATE INDEX IF NOT EXISTS idx_rs_found_title   ON resultados_scraping (found_in_title);
CREATE INDEX IF NOT EXISTS idx_rs_relevance     ON resultados_scraping (relevance_score);
CREATE INDEX IF NOT EXISTS idx_rs_pais          ON resultados_scraping (pais);
CREATE INDEX IF NOT EXISTS idx_rs_categoria     ON resultados_scraping (categoria);

-- ============================================================
-- TABLA: log_ejecuciones
-- ============================================================
CREATE TABLE IF NOT EXISTS log_ejecuciones (
    id                    SERIAL PRIMARY KEY,
    fecha_ejecucion       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    sitios_procesados     INT DEFAULT 0,
    resultados_encontrados INT DEFAULT 0,
    errores               INT DEFAULT 0,
    duracion_segundos     DECIMAL(10,2) DEFAULT 0
);

CREATE INDEX IF NOT EXISTS idx_log_fecha ON log_ejecuciones (fecha_ejecucion);

-- ============================================================
-- TABLA: paises
-- ============================================================
CREATE TABLE IF NOT EXISTS paises (
    codigo         CHAR(2) PRIMARY KEY,
    nombre         VARCHAR(50) NOT NULL,
    activo         SMALLINT DEFAULT 1,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO paises (codigo, nombre) VALUES
    ('BO', 'Bolivia'),
    ('HN', 'Honduras'),
    ('SV', 'El Salvador'),
    ('NI', 'Nicaragua'),
    ('PY', 'Paraguay'),
    ('GT', 'Guatemala')
ON CONFLICT (codigo) DO UPDATE SET nombre = EXCLUDED.nombre;

-- ============================================================
-- TABLA: keyword_paises (many-to-many keywords ↔ países)
-- Si keyword NO tiene registros aquí = GLOBAL (todos los países)
-- ============================================================
CREATE TABLE IF NOT EXISTS keyword_paises (
    keyword_id INT  NOT NULL,
    pais       CHAR(2) NOT NULL,
    PRIMARY KEY (keyword_id, pais),
    FOREIGN KEY (keyword_id) REFERENCES palabras_clave (id) ON DELETE CASCADE,
    FOREIGN KEY (pais)       REFERENCES paises (codigo)     ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_kp_keyword ON keyword_paises (keyword_id);
CREATE INDEX IF NOT EXISTS idx_kp_pais    ON keyword_paises (pais);

-- ============================================================
-- VISTAS
-- ============================================================

CREATE OR REPLACE VIEW v_resultados_completos AS
SELECT
    r.id, r.url, r.keyword, r.titulo, r.contexto,
    r.fecha_encontrado, r.relevance_score, r.found_in_title,
    r.leido, r.relevante,
    s.nombre AS sitio_nombre,
    CASE
        WHEN r.found_in_title = 1 THEN 'Alta'
        WHEN r.relevance_score >= 50 THEN 'Media'
        ELSE 'Baja'
    END AS relevancia_texto
FROM resultados_scraping r
LEFT JOIN sitios_web s ON r.sitio_id = s.id
ORDER BY r.found_in_title DESC, r.relevance_score DESC, r.fecha_encontrado DESC;

CREATE OR REPLACE VIEW v_resultados_relevantes AS
SELECT
    r.id, r.url, r.keyword, r.titulo, r.contexto,
    r.fecha_encontrado, r.relevance_score,
    s.nombre AS sitio_nombre
FROM resultados_scraping r
LEFT JOIN sitios_web s ON r.sitio_id = s.id
WHERE r.found_in_title = 1
ORDER BY r.fecha_encontrado DESC;

CREATE OR REPLACE VIEW v_resumen_diario AS
SELECT
    DATE(fecha_encontrado)                                         AS fecha,
    COUNT(*)                                                       AS total_resultados,
    SUM(CASE WHEN found_in_title = 1 THEN 1 ELSE 0 END)           AS alta_relevancia,
    SUM(CASE WHEN found_in_title = 0 THEN 1 ELSE 0 END)           AS baja_relevancia,
    COUNT(DISTINCT keyword)                                        AS keywords_distintas,
    COUNT(DISTINCT sitio_id)                                       AS sitios_distintos,
    ROUND(AVG(relevance_score)::NUMERIC, 1)                       AS relevancia_promedio
FROM resultados_scraping
GROUP BY DATE(fecha_encontrado)
ORDER BY fecha DESC;

CREATE OR REPLACE VIEW v_resultados_recientes AS
SELECT
    r.pais, p.nombre AS pais_nombre, r.categoria,
    r.keyword, r.titulo, r.url, r.fecha_encontrado,
    s.nombre AS sitio
FROM resultados_scraping r
JOIN paises      p ON r.pais      = p.codigo
JOIN sitios_web  s ON r.sitio_id  = s.id
WHERE r.fecha_encontrado >= NOW() - INTERVAL '7 days'
ORDER BY r.fecha_encontrado DESC;

CREATE OR REPLACE VIEW v_resumen_por_pais AS
SELECT
    p.nombre AS pais,
    p.codigo,
    COUNT(DISTINCT s.id) AS sitios,
    (
        SELECT COUNT(*) FROM palabras_clave pc
        WHERE pc.activo = 1 AND (
            NOT EXISTS (SELECT 1 FROM keyword_paises kp WHERE kp.keyword_id = pc.id)
            OR EXISTS  (SELECT 1 FROM keyword_paises kp
                        WHERE kp.keyword_id = pc.id AND kp.pais = p.codigo)
        )
    ) AS keywords,
    COUNT(DISTINCT r.id) AS resultados_total
FROM paises p
LEFT JOIN sitios_web         s ON s.pais = p.codigo AND s.activo = 1
LEFT JOIN resultados_scraping r ON r.pais = p.codigo
WHERE p.activo = 1
GROUP BY p.codigo, p.nombre
ORDER BY p.nombre;

-- ============================================================
-- ÍNDICE ALTERNATIVO si las URLs son muy largas (>2700 bytes)
-- Descomenta esto y elimina la CONSTRAINT idx_url_keyword_unique
-- de la tabla resultados_scraping si obtienes errores de índice.
-- ============================================================
-- ALTER TABLE resultados_scraping DROP CONSTRAINT IF EXISTS idx_url_keyword_unique;
-- CREATE UNIQUE INDEX IF NOT EXISTS idx_url_keyword_md5
--     ON resultados_scraping (md5(url), keyword);
