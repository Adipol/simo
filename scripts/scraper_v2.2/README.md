# 🕷️ Web Scraper Profesional v2.2.0

Sistema de scraping con **filtrado inteligente**, **MODO RÁPIDO** y **notificaciones Discord**.

## 🚀 Características

| Característica | Descripción |
|----------------|-------------|
| 🎯 **Filtro de título** | Solo guarda si keyword está en el TÍTULO |
| 🚀 **Modo rápido** | ~1-2 seg/página con requests |
| 📱 **Discord** | Notificaciones en tiempo real |
| 🔄 **Refresh automático** | Para sitios con carga dinámica |

## 📱 Notificaciones Discord

Recibe alertas cuando:
- ✅ Se encuentra un artículo con keyword
- 📊 Termina un ciclo de scraping
- 🚨 Ocurre un error

### Configurar Discord

1. En tu servidor Discord: **Configuración > Integraciones > Webhooks**
2. Crear nuevo webhook y copiar URL
3. Agregar al `.env`:

```env
DISCORD_WEBHOOK_URL=https://discord.com/api/webhooks/xxx/yyy
DISCORD_NOTIFICATIONS=true
```

| Característica | Descripción |
|----------------|-------------|
| 🎯 **Filtro de título** | Solo guarda resultados donde la keyword está en el TÍTULO |
| 🧹 **Limpieza de contenido** | Extrae solo el contenido principal, ignora sidebars y ads |
| 🔗 **Filtro de URLs** | Excluye páginas de navegación (/category/, /tag/, etc.) |
| 📊 **Sistema de relevancia** | Puntaje 0-100 según dónde se encontró la keyword |

## 🎯 El Problema que Resuelve

Antes (v2.1):
```
URL: /logout                    ❌ Página de navegación
URL: /category/politica         ❌ Página de categoría  
Keyword en: sidebar de noticias ❌ No es el artículo principal
```

Ahora (v2.2):
```
URL: /2024/01/candidatos-elecciones  ✅ Es un artículo
Keyword en: TÍTULO del artículo      ✅ Alta relevancia
```

## 🚀 Instalación

```bash
# 1. Instalar dependencias
pip install -r requirements.txt

# 2. Configurar base de datos
mysql -u root -p < database/schema.sql

# 3. Configurar variables de entorno
cp .env.example .env
# Editar .env con tus credenciales
```

## 📖 Uso

### Modo estándar (keyword en título)
```bash
python main.py --once
```

### Modo permisivo (keyword en cualquier parte)
```bash
python main.py --once --no-title-filter
```

### Ver estadísticas de relevancia
```bash
python main.py --status
```

### Ejecución continua
```bash
python main.py --parallel --workers 3
```

## ⚙️ Configuración de Filtros

En `.env`:

```env
# Solo guardar si keyword está en título (recomendado)
REQUIRE_KEYWORD_IN_TITLE=true

# Puntaje mínimo de relevancia
# 80 = en título, 50 = en contenido, 20 = en página
MIN_RELEVANCE_SCORE=30

# URLs a ignorar
EXCLUDE_URL_PATTERNS=/tag/,/category/,/logout,/page/

# Selectores para contenido principal
ARTICLE_SELECTORS=article,.post-content,.entry-content
```

## 📊 Sistema de Relevancia

| Puntaje | Nivel | Descripción |
|---------|-------|-------------|
| 100 | 🎯 Máxima | Keyword en título Y contenido |
| 80 | ⭐ Alta | Keyword en título |
| 50 | 📄 Media | Keyword en contenido del artículo |
| 20 | 📋 Baja | Keyword en cualquier parte de la página |

## 📁 Estructura

```
scraper_project/
├── config/
│   └── settings.py      # Incluye FilterConfig
├── core/
│   ├── scraper.py       # URLFilter, ContentExtractor, KeywordMatcher
│   ├── database.py      # Soporte para relevance_score
│   └── webdriver_manager.py
├── database/
│   └── schema.sql       # Campos found_in_title, relevance_score
├── main.py              # Flag --no-title-filter
└── scheduler.py
```

## 🗄️ Base de Datos

### Nuevos campos
```sql
relevance_score TINYINT    -- Puntaje 0-100
found_in_title TINYINT(1)  -- 1 si keyword está en título
```

### Consultas útiles
```sql
-- Solo resultados de ALTA relevancia
SELECT * FROM v_resultados_relevantes;

-- Estadísticas con porcentaje de alta relevancia
CALL sp_estadisticas(7);

-- Limpiar resultados de baja relevancia antiguos
CALL sp_limpiar_baja_relevancia(30);
```

## 🔄 Migración desde v2.1

```sql
-- Agregar nuevos campos
ALTER TABLE resultados_scraping 
ADD COLUMN relevance_score TINYINT UNSIGNED DEFAULT 0,
ADD COLUMN found_in_title TINYINT(1) DEFAULT 0;

-- Agregar índices
ALTER TABLE resultados_scraping
ADD INDEX idx_found_in_title (found_in_title),
ADD INDEX idx_relevance (relevance_score);
```

## 📋 Changelog

### v2.2.0
- 🆕 **Filtro de título**: `REQUIRE_KEYWORD_IN_TITLE`
- 🆕 **Extractor de contenido**: Ignora sidebars, ads, menús
- 🆕 **Filtro de URLs**: Excluye /tag/, /category/, /logout, etc.
- 🆕 **Sistema de relevancia**: Puntaje 0-100
- 🆕 **Vista `v_resultados_relevantes`**: Solo alta relevancia
- 🆕 **Flag `--no-title-filter`**: Desactivar filtro

### v2.1.0
- Rate limiting
- Modo paralelo
- Health check BD

## 📄 Licencia

MIT License
