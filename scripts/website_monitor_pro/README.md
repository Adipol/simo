# Website Monitor Pro 🔍

Monitor de cambios en sitios web, listo para producción.

## ✨ Características

- ✅ **Configuración por variables de entorno** (seguro, sin credenciales en código)
- ✅ **Logging profesional** con rotación de archivos
- ✅ **Reconexión automática** a la base de datos
- ✅ **Reintentos HTTP** con backoff exponencial
- ✅ **Notificaciones** por webhook (Slack/Discord) y email
- ✅ **CLI completo** para gestionar URLs
- ✅ **Type hints** y código documentado

## 🚀 Instalación

```bash
# 1. Clonar/copiar los archivos
# 2. Crear entorno virtual
python -m venv venv
source venv/bin/activate  # Linux/Mac
# venv\Scripts\activate   # Windows

# 3. Instalar dependencias
pip install -r requirements.txt

# 4. Configurar
cp .env.example .env
# Editar .env con tus credenciales
```

## 📦 Configuración de MySQL

```sql
-- Crear usuario y base de datos
CREATE DATABASE website_monitor CHARACTER SET utf8mb4;
CREATE USER 'monitor_user'@'localhost' IDENTIFIED BY 'tu_password_seguro';
GRANT ALL PRIVILEGES ON website_monitor.* TO 'monitor_user'@'localhost';
FLUSH PRIVILEGES;
```

## 🎮 Uso

```bash
# Iniciar el monitor (loop continuo)
python website_monitor_pro.py run

# Añadir una URL
python website_monitor_pro.py add https://ejemplo.com

# Listar URLs registradas
python website_monitor_pro.py list

# Desactivar una URL
python website_monitor_pro.py remove https://ejemplo.com

# Ejecutar una verificación única (útil para cron)
python website_monitor_pro.py check
```

## 🐳 Uso con Docker (Opcional)

```dockerfile
FROM python:3.11-slim
WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY website_monitor_pro.py .
CMD ["python", "website_monitor_pro.py", "run"]
```

## 📡 Configurar Notificaciones

### Slack
1. Crear Incoming Webhook en Slack
2. Añadir a `.env`: `WEBHOOK_URL=https://hooks.slack.com/services/xxx`

### Discord
1. Crear Webhook en el canal de Discord
2. Añadir a `.env`: `WEBHOOK_URL=https://discord.com/api/webhooks/xxx`

### Email (Gmail)
1. Habilitar "App Passwords" en tu cuenta Google
2. Configurar en `.env`:
```
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USER=tu_email@gmail.com
SMTP_PASSWORD=tu_app_password
NOTIFY_EMAIL=destinatario@ejemplo.com
```

## 🔧 Systemd (Linux)

Para ejecutar como servicio:

```ini
# /etc/systemd/system/website-monitor.service
[Unit]
Description=Website Monitor Pro
After=network.target mysql.service

[Service]
Type=simple
User=tu_usuario
WorkingDirectory=/ruta/al/proyecto
Environment=PATH=/ruta/al/venv/bin
ExecStart=/ruta/al/venv/bin/python website_monitor_pro.py run
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable website-monitor
sudo systemctl start website-monitor
```

## 📊 Estructura de Tablas

```
urls
├── id (PK)
├── url
├── hash
├── activo
├── created_at
└── updated_at

cambios
├── id (PK)
├── url_id (FK)
├── url
├── fecha
├── estado (enum)
├── detalle
├── hash_anterior
└── hash_nuevo
```
