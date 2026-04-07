@echo off
REM ============================================
REM Script para ejecutar PEP Monitor
REM Ejecutar este archivo para verificar cambios en fuentes
REM ============================================

echo ============================================
echo     PEP MONITOR - Verificacion de fuentes
echo ============================================
echo.

cd /d D:\proyectos\simo\scripts\website_monitor_pro

echo Ejecutando verificacion de fuentes...
echo.

python pep_monitor.py check

echo.
echo ============================================
echo     VERIFICACION COMPLETADA
echo ============================================
echo.
echo Revise los resultados en:
echo   http://localhost:8000/pep/cambios
echo.
pause