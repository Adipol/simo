-- ============================================================
-- MIGRACIÓN: Soporte Multi-País
-- ============================================================
-- Países soportados:
--   BO = Bolivia
--   HN = Honduras
--   SV = El Salvador
--   NI = Nicaragua
--   PY = Paraguay
--   GT = Guatemala
-- ============================================================

-- 1. Agregar columna 'pais' a sitios_web
ALTER TABLE sitios_web 
ADD COLUMN pais CHAR(2) DEFAULT 'BO' AFTER nombre;

-- Índice para búsqueda por país
CREATE INDEX idx_sitios_pais ON sitios_web(pais);

-- 2. Agregar columna 'pais' a resultados_scraping
ALTER TABLE resultados_scraping 
ADD COLUMN pais CHAR(2) DEFAULT 'BO' AFTER sitio_id;

-- 2b. Agregar columna 'categoria' a resultados_scraping
ALTER TABLE resultados_scraping 
ADD COLUMN categoria VARCHAR(20) DEFAULT NULL AFTER pais;

-- Índice para búsqueda y reportes por país
CREATE INDEX idx_resultados_pais ON resultados_scraping(pais);

-- Índice para búsqueda por categoría
CREATE INDEX idx_resultados_categoria ON resultados_scraping(categoria);

-- 3. Tabla de referencia de países
CREATE TABLE IF NOT EXISTS paises (
    codigo CHAR(2) PRIMARY KEY,
    nombre VARCHAR(50) NOT NULL,
    activo TINYINT(1) DEFAULT 1,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO paises (codigo, nombre) VALUES
    ('BO', 'Bolivia'),
    ('HN', 'Honduras'),
    ('SV', 'El Salvador'),
    ('NI', 'Nicaragua'),
    ('PY', 'Paraguay'),
    ('GT', 'Guatemala')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

-- 4. Tabla intermedia: keywords ↔ países (relación muchos a muchos)
-- Si una keyword NO está en esta tabla = es GLOBAL (aplica a todos)
CREATE TABLE IF NOT EXISTS keyword_paises (
    keyword_id INT NOT NULL,
    pais CHAR(2) NOT NULL,
    PRIMARY KEY (keyword_id, pais),
    FOREIGN KEY (keyword_id) REFERENCES palabras_clave(id) ON DELETE CASCADE,
    FOREIGN KEY (pais) REFERENCES paises(codigo) ON DELETE CASCADE
);

-- Índices para búsquedas eficientes
CREATE INDEX idx_kp_keyword ON keyword_paises(keyword_id);
CREATE INDEX idx_kp_pais ON keyword_paises(pais);

-- ============================================================
-- VISTAS ÚTILES
-- ============================================================

-- Vista: Keywords con sus países asignados
CREATE OR REPLACE VIEW v_keywords_por_pais AS
SELECT 
    pc.id,
    pc.keyword,
    pc.categoria,
    CASE 
        WHEN NOT EXISTS (SELECT 1 FROM keyword_paises kp WHERE kp.keyword_id = pc.id)
        THEN 'GLOBAL'
        ELSE GROUP_CONCAT(kp.pais ORDER BY kp.pais SEPARATOR ', ')
    END as paises,
    pc.activo
FROM palabras_clave pc
LEFT JOIN keyword_paises kp ON pc.id = kp.keyword_id
WHERE pc.activo = 1
GROUP BY pc.id, pc.keyword, pc.categoria, pc.activo
ORDER BY pc.keyword;

-- Vista: Keywords efectivas para un país (para usar en queries)
-- Incluye: globales (sin registros en keyword_paises) + específicas del país
CREATE OR REPLACE VIEW v_keywords_bolivia AS
SELECT pc.* FROM palabras_clave pc
WHERE pc.activo = 1 AND (
    NOT EXISTS (SELECT 1 FROM keyword_paises kp WHERE kp.keyword_id = pc.id)
    OR EXISTS (SELECT 1 FROM keyword_paises kp WHERE kp.keyword_id = pc.id AND kp.pais = 'BO')
);

CREATE OR REPLACE VIEW v_keywords_honduras AS
SELECT pc.* FROM palabras_clave pc
WHERE pc.activo = 1 AND (
    NOT EXISTS (SELECT 1 FROM keyword_paises kp WHERE kp.keyword_id = pc.id)
    OR EXISTS (SELECT 1 FROM keyword_paises kp WHERE kp.keyword_id = pc.id AND kp.pais = 'HN')
);

CREATE OR REPLACE VIEW v_keywords_elsalvador AS
SELECT pc.* FROM palabras_clave pc
WHERE pc.activo = 1 AND (
    NOT EXISTS (SELECT 1 FROM keyword_paises kp WHERE kp.keyword_id = pc.id)
    OR EXISTS (SELECT 1 FROM keyword_paises kp WHERE kp.keyword_id = pc.id AND kp.pais = 'SV')
);

CREATE OR REPLACE VIEW v_keywords_nicaragua AS
SELECT pc.* FROM palabras_clave pc
WHERE pc.activo = 1 AND (
    NOT EXISTS (SELECT 1 FROM keyword_paises kp WHERE kp.keyword_id = pc.id)
    OR EXISTS (SELECT 1 FROM keyword_paises kp WHERE kp.keyword_id = pc.id AND kp.pais = 'NI')
);

CREATE OR REPLACE VIEW v_keywords_paraguay AS
SELECT pc.* FROM palabras_clave pc
WHERE pc.activo = 1 AND (
    NOT EXISTS (SELECT 1 FROM keyword_paises kp WHERE kp.keyword_id = pc.id)
    OR EXISTS (SELECT 1 FROM keyword_paises kp WHERE kp.keyword_id = pc.id AND kp.pais = 'PY')
);

CREATE OR REPLACE VIEW v_keywords_guatemala AS
SELECT pc.* FROM palabras_clave pc
WHERE pc.activo = 1 AND (
    NOT EXISTS (SELECT 1 FROM keyword_paises kp WHERE kp.keyword_id = pc.id)
    OR EXISTS (SELECT 1 FROM keyword_paises kp WHERE kp.keyword_id = pc.id AND kp.pais = 'GT')
);

-- Vista: Resumen por país
CREATE OR REPLACE VIEW v_resumen_por_pais AS
SELECT 
    p.nombre as pais,
    p.codigo,
    COUNT(DISTINCT s.id) as sitios,
    (SELECT COUNT(*) FROM palabras_clave pc 
     WHERE pc.activo = 1 AND (
         NOT EXISTS (SELECT 1 FROM keyword_paises kp WHERE kp.keyword_id = pc.id)
         OR EXISTS (SELECT 1 FROM keyword_paises kp WHERE kp.keyword_id = pc.id AND kp.pais = p.codigo)
     )) as keywords,
    COUNT(DISTINCT r.id) as resultados_total
FROM paises p
LEFT JOIN sitios_web s ON s.pais = p.codigo AND s.activo = 1
LEFT JOIN resultados_scraping r ON r.pais = p.codigo
WHERE p.activo = 1
GROUP BY p.codigo, p.nombre
ORDER BY p.nombre;

-- Vista: Resumen por país y categoría
CREATE OR REPLACE VIEW v_resumen_por_pais_categoria AS
SELECT 
    p.nombre as pais,
    p.codigo,
    r.categoria,
    COUNT(DISTINCT r.id) as resultados
FROM paises p
LEFT JOIN resultados_scraping r ON r.pais = p.codigo
WHERE p.activo = 1
GROUP BY p.codigo, p.nombre, r.categoria
ORDER BY p.nombre, r.categoria;

-- Vista: Resultados recientes por país y categoría
CREATE OR REPLACE VIEW v_resultados_recientes AS
SELECT 
    r.pais,
    p.nombre as pais_nombre,
    r.categoria,
    r.keyword,
    r.titulo,
    r.url,
    r.fecha_encontrado,
    s.nombre as sitio
FROM resultados_scraping r
JOIN paises p ON r.pais = p.codigo
JOIN sitios_web s ON r.sitio_id = s.id
WHERE r.fecha_encontrado >= DATE_SUB(NOW(), INTERVAL 7 DAY)
ORDER BY r.fecha_encontrado DESC;

-- ============================================================
-- EJEMPLOS DE USO
-- ============================================================

-- KEYWORDS GLOBALES (no insertar en keyword_paises)
-- Simplemente crear la keyword sin agregar a keyword_paises:
-- INSERT INTO palabras_clave (keyword, categoria, activo) VALUES ('ministro', 'PEP', 1);
-- (No agregar nada a keyword_paises = aplica a TODOS los países)

-- KEYWORD ESPECÍFICA PARA ALGUNOS PAÍSES
-- Paso 1: Crear la keyword
-- INSERT INTO palabras_clave (keyword, categoria, activo) VALUES ('comandante', 'PEP', 1);
-- Paso 2: Asignar países (supongamos que el ID es 30)
-- INSERT INTO keyword_paises (keyword_id, pais) VALUES (30, 'BO'), (30, 'HN');

-- Ver qué keywords aplican para Bolivia
-- SELECT * FROM v_keywords_bolivia;

-- Ver resumen de keywords con sus países
-- SELECT * FROM v_keywords_por_pais;

-- Ver resumen general
-- SELECT * FROM v_resumen_por_pais;
