-- ============================================
-- ESQUEMA DE BASE DE DATOS PARA WEB SCRAPER v2.2.0
-- ============================================
-- Con soporte para puntaje de relevancia y detección en título

CREATE DATABASE IF NOT EXISTS scraping_db2
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE scraping_db2;

-- ============================================
-- TABLA: sitios_web
-- ============================================
CREATE TABLE IF NOT EXISTS sitios_web (
    id INT AUTO_INCREMENT PRIMARY KEY,
    url VARCHAR(500) NOT NULL UNIQUE,
    nombre VARCHAR(200) NOT NULL,
    selector_links VARCHAR(200) DEFAULT NULL COMMENT 'Selector CSS para enlaces',
    selector_article VARCHAR(200) DEFAULT NULL COMMENT 'Selector CSS para contenido de artículo',
    activo TINYINT(1) DEFAULT 1,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_modificacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_activo (activo)
) ENGINE=InnoDB;

-- ============================================
-- TABLA: palabras_clave
-- ============================================
CREATE TABLE IF NOT EXISTS palabras_clave (
    id INT AUTO_INCREMENT PRIMARY KEY,
    keyword VARCHAR(200) NOT NULL UNIQUE,
    categoria VARCHAR(100) DEFAULT NULL,
    activo TINYINT(1) DEFAULT 1,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_activo (activo),
    INDEX idx_categoria (categoria)
) ENGINE=InnoDB;

-- ============================================
-- TABLA: resultados_scraping
-- Con campos de relevancia
-- ============================================
CREATE TABLE IF NOT EXISTS resultados_scraping (
    id INT AUTO_INCREMENT PRIMARY KEY,
    url VARCHAR(2000) NOT NULL,
    keyword VARCHAR(200) NOT NULL,
    sitio_id INT,
    titulo VARCHAR(500) DEFAULT NULL,
    contexto TEXT DEFAULT NULL,
    fecha_encontrado TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- NUEVOS CAMPOS v2.2
    relevance_score TINYINT UNSIGNED DEFAULT 0 COMMENT 'Puntaje de relevancia 0-100',
    found_in_title TINYINT(1) DEFAULT 0 COMMENT 'Keyword encontrada en título',
    
    -- Campos de gestión
    leido TINYINT(1) DEFAULT 0,
    relevante TINYINT(1) DEFAULT NULL COMMENT 'Marcado manualmente',
    notas TEXT DEFAULT NULL,
    
    -- Índices
    INDEX idx_keyword (keyword),
    INDEX idx_sitio (sitio_id),
    INDEX idx_fecha (fecha_encontrado),
    INDEX idx_found_in_title (found_in_title),
    INDEX idx_relevance (relevance_score),
    UNIQUE INDEX idx_url_keyword_unique (url(255), keyword),
    
    FOREIGN KEY (sitio_id) REFERENCES sitios_web(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================
-- TABLA: log_ejecuciones
-- ============================================
CREATE TABLE IF NOT EXISTS log_ejecuciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fecha_ejecucion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    sitios_procesados INT DEFAULT 0,
    resultados_encontrados INT DEFAULT 0,
    errores INT DEFAULT 0,
    duracion_segundos DECIMAL(10,2) DEFAULT 0,
    INDEX idx_fecha (fecha_ejecucion)
) ENGINE=InnoDB;

-- ============================================
-- VISTAS ACTUALIZADAS
-- ============================================

-- Vista: Resultados completos con relevancia
CREATE OR REPLACE VIEW v_resultados_completos AS
SELECT 
    r.id,
    r.url,
    r.keyword,
    r.titulo,
    r.contexto,
    r.fecha_encontrado,
    r.relevance_score,
    r.found_in_title,
    r.leido,
    r.relevante,
    s.nombre AS sitio_nombre,
    CASE 
        WHEN r.found_in_title = 1 THEN '🎯 Alta'
        WHEN r.relevance_score >= 50 THEN '📄 Media'
        ELSE '📋 Baja'
    END AS relevancia_texto
FROM resultados_scraping r
LEFT JOIN sitios_web s ON r.sitio_id = s.id
ORDER BY r.found_in_title DESC, r.relevance_score DESC, r.fecha_encontrado DESC;

-- Vista: Solo resultados de ALTA relevancia (keyword en título)
CREATE OR REPLACE VIEW v_resultados_relevantes AS
SELECT 
    r.id,
    r.url,
    r.keyword,
    r.titulo,
    r.contexto,
    r.fecha_encontrado,
    r.relevance_score,
    s.nombre AS sitio_nombre
FROM resultados_scraping r
LEFT JOIN sitios_web s ON r.sitio_id = s.id
WHERE r.found_in_title = 1
ORDER BY r.fecha_encontrado DESC;

-- Vista: Resumen diario con métricas de relevancia
CREATE OR REPLACE VIEW v_resumen_diario AS
SELECT 
    DATE(fecha_encontrado) AS fecha,
    COUNT(*) AS total_resultados,
    SUM(CASE WHEN found_in_title = 1 THEN 1 ELSE 0 END) AS alta_relevancia,
    SUM(CASE WHEN found_in_title = 0 THEN 1 ELSE 0 END) AS baja_relevancia,
    COUNT(DISTINCT keyword) AS keywords_distintas,
    COUNT(DISTINCT sitio_id) AS sitios_distintos,
    ROUND(AVG(relevance_score), 1) AS relevancia_promedio
FROM resultados_scraping
GROUP BY DATE(fecha_encontrado)
ORDER BY fecha DESC;

-- Vista: Resultados pendientes (priorizando alta relevancia)
CREATE OR REPLACE VIEW v_resultados_pendientes AS
SELECT 
    r.*,
    s.nombre AS sitio_nombre,
    CASE WHEN r.found_in_title = 1 THEN '⭐' ELSE '' END AS prioridad
FROM resultados_scraping r
LEFT JOIN sitios_web s ON r.sitio_id = s.id
WHERE r.leido = 0
ORDER BY r.found_in_title DESC, r.relevance_score DESC, r.fecha_encontrado DESC;

-- ============================================
-- PROCEDIMIENTOS ALMACENADOS
-- ============================================

DELIMITER //

-- Obtener estadísticas con métricas de relevancia
CREATE PROCEDURE IF NOT EXISTS sp_estadisticas(IN dias INT)
BEGIN
    SELECT 
        COUNT(*) AS total_resultados,
        SUM(CASE WHEN found_in_title = 1 THEN 1 ELSE 0 END) AS alta_relevancia,
        SUM(CASE WHEN found_in_title = 0 THEN 1 ELSE 0 END) AS otra_relevancia,
        ROUND(100.0 * SUM(CASE WHEN found_in_title = 1 THEN 1 ELSE 0 END) / COUNT(*), 1) AS porcentaje_alta,
        COUNT(DISTINCT keyword) AS keywords_encontradas,
        COUNT(DISTINCT sitio_id) AS sitios_con_resultados,
        SUM(CASE WHEN leido = 0 THEN 1 ELSE 0 END) AS pendientes_leer,
        ROUND(AVG(relevance_score), 1) AS relevancia_promedio
    FROM resultados_scraping
    WHERE fecha_encontrado >= DATE_SUB(NOW(), INTERVAL dias DAY);
END //

-- Marcar como leídos (priorizando baja relevancia primero)
CREATE PROCEDURE IF NOT EXISTS sp_marcar_leidos_antiguos(IN dias INT)
BEGIN
    UPDATE resultados_scraping 
    SET leido = 1 
    WHERE leido = 0 
    AND found_in_title = 0
    AND fecha_encontrado < DATE_SUB(NOW(), INTERVAL dias DAY);
    
    SELECT ROW_COUNT() AS registros_actualizados;
END //

-- Limpiar resultados de baja relevancia antiguos
CREATE PROCEDURE IF NOT EXISTS sp_limpiar_baja_relevancia(IN dias INT)
BEGIN
    DELETE FROM resultados_scraping 
    WHERE fecha_encontrado < DATE_SUB(NOW(), INTERVAL dias DAY)
    AND found_in_title = 0
    AND relevante IS NULL;
    
    SELECT ROW_COUNT() AS registros_eliminados;
END //

DELIMITER ;

-- ============================================
-- MIGRACIÓN: Si ya tienes datos de v2.1
-- ============================================
/*
-- Agregar nuevos campos si no existen
ALTER TABLE resultados_scraping 
ADD COLUMN IF NOT EXISTS relevance_score TINYINT UNSIGNED DEFAULT 0,
ADD COLUMN IF NOT EXISTS found_in_title TINYINT(1) DEFAULT 0;

-- Agregar índices
ALTER TABLE resultados_scraping
ADD INDEX IF NOT EXISTS idx_found_in_title (found_in_title),
ADD INDEX IF NOT EXISTS idx_relevance (relevance_score);

-- Agregar campo de selector de artículo a sitios
ALTER TABLE sitios_web
ADD COLUMN IF NOT EXISTS selector_article VARCHAR(200) DEFAULT NULL;
*/

-- ============================================
-- DATOS DE EJEMPLO
-- ============================================
/*
INSERT INTO sitios_web (url, nombre, selector_links, selector_article) VALUES
('https://www.eldeber.com.bo', 'El Deber', 'a[href*="/"]', 'article, .article-content'),
('https://eju.tv', 'EJU TV', NULL, '.entry-content');

INSERT INTO palabras_clave (keyword, categoria) VALUES
('candidatos', 'política'),
('elecciones', 'política'),
('aprehensión', 'judicial');
*/

-- ============================================
-- CONSULTAS ÚTILES v2.2
-- ============================================
/*
-- Ver solo resultados donde keyword está en el título
SELECT * FROM v_resultados_relevantes;

-- Ver estadísticas con porcentaje de alta relevancia
CALL sp_estadisticas(7);

-- Ver resultados de hoy ordenados por relevancia
SELECT * FROM v_resultados_completos 
WHERE DATE(fecha_encontrado) = CURDATE();

-- Limpiar resultados de baja relevancia de hace más de 30 días
CALL sp_limpiar_baja_relevancia(30);

-- Contar por nivel de relevancia
SELECT 
    CASE 
        WHEN found_in_title = 1 THEN 'En título'
        WHEN relevance_score >= 50 THEN 'En contenido'
        ELSE 'En página'
    END AS nivel,
    COUNT(*) as cantidad
FROM resultados_scraping
WHERE fecha_encontrado >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY nivel;
*/
