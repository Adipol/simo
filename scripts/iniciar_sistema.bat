@echo off
REM ============================================
REM Script de inicio SIMO - Ejecute este archivo al prender la PC
REM ============================================

echo ============================================
echo     INICIANDO SISTEMA SIMO
echo ============================================
echo.

REM Cambiar al directorio del proyecto
cd /d D:\proyectos\simo

echo [1/4] Verificando base de datos...
php artisan tinker --execute="echo DB OK" >nul 2>&1
if %errorlevel% neq 0 (
    echo ERROR: No se puede conectar a la base de datos
    echo Asegurese que PostgreSQL este corriendo
    pause
    exit /b 1
)
echo   Base de datos: OK

echo [2/4] Iniciando servidor Laravel...
start "LARAVEL" cmd /k "cd /d D:\proyectos\simo && php artisan serve"

timeout /t 3 /nobreak >nul

echo [3/4] Iniciando cola de trabajos...
start "QUEUE" cmd /k "cd /d D:\proyectos\simo && php artisan queue:work --queue=gemini"

echo [4/4] Ejecutando scraper...
start "SCRAPER" cmd /k "cd /d D:\proyectos\simo\scripts\scraper_v2.2 && python main.py --once"

echo.
echo ============================================
echo     SISTEMA INICIADO
echo ============================================
echo.
echo Accesos:
echo   - Dashboard:      http://localhost:8000/dashboard
echo   - Resultados:     http://localhost:8000/scraper/resultados
echo   - Cambios PEP:    http://localhost:8000/pep/cambios
echo   - Estado Scripts: http://localhost:8000/scripts/estado
echo.
echo Ventanas abiertas:
echo   - LARAVEL: Servidor web
echo   - QUEUE:   Proceso de cola Gemini
echo   - SCRAPER: Ejecucion de scraper
echo.
echo Presione cualquier tecla para cerrar esta ventana...
pause >nul