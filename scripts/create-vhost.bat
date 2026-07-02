@echo off
REM ===========================================
REM Script para crear VirtualHost Apache (XAMPP)
REM ===========================================

echo ==========================================
echo Crear VirtualHost Apache
echo ==========================================

set /p PROJECT_NAME="Nombre del proyecto (ej: mi-proyecto): "
set /p DOMAIN="Dominio (ej: mi-proyecto.test): "
set /p DOCUMENT_ROOT="Ruta absoluta del proyecto (ej: C:\xampp\htdocs\mi-proyecto): "

if "%PROJECT_NAME%"=="" goto error
if "%DOMAIN%"=="" goto error
if "%DOCUMENT_ROOT%"=="" goto error

echo.
echo Configuracion:
echo   Proyecto: %PROJECT_NAME%
echo   Dominio: %DOMAIN%
echo   DocumentRoot: %DOCUMENT_ROOT%

REM Detectar Apache config segun XAMPP o WAMP
if exist "C:\xampp\apache\conf\extra\httpd-vhosts.conf" (
    set VHOST_CONF=C:\xampp\apache\conf\extra\httpd-vhosts.conf
    set APACHE_BIN=C:\xampp\apache\bin
) else if exist "C:\wamp64\bin\apache\apache2.4.*\conf\extra\httpd-vhosts.conf" (
    for /f "delims=" %%i in ('dir /b /o-n "C:\wamp64\bin\apache\apache2.4.*" 2^>nul') do set APACHE_VERSION=%%i
    set VHOST_CONF=C:\wamp64\bin\apache\%APACHE_VERSION%\conf\extra\httpd-vhosts.conf
    set APACHE_BIN=C:\wamp64\bin\apache\%APACHE_VERSION%\bin
)

if "%VHOST_CONF%"=="" (
    echo ERROR: No se encontro archivo de VirtualHost de Apache
    echo Verifica que XAMPP o WAMP este instalado
    exit /b 1
)

echo.
echo [1/4] Creando entrada VirtualHost...

REM Agregar VirtualHost al archivo
echo. >> "%VHOST_CONF%"
echo # VirtualHost %PROJECT_NAME% >> "%VHOST_CONF%"
echo ^<VirtualHost *:80^> >> "%VHOST_CONF%"
echo     ServerName %DOMAIN% >> "%VHOST_CONF%"
echo     DocumentRoot "%DOCUMENT_ROOT%\public" >> "%VHOST_CONF%"
echo     ^<Directory "%DOCUMENT_ROOT%\public"^> >> "%VHOST_CONF%"
echo         AllowOverride All >> "%VHOST_CONF%"
echo         Require all granted >> "%VHOST_CONF%"
echo     ^</Directory^> >> "%VHOST_CONF%"
echo ^</VirtualHost^> >> "%VHOST_CONF%"

echo   VirtualHost agregado a: %VHOST_CONF%

echo.
echo [2/4] Agregando al archivo hosts de Windows...

REM Agregar al hosts (requiere permisos de admin)
findstr /C:"%DOMAIN%" C:\Windows\System32\drivers\etc\hosts >nul
if %ERRORLEVEL% NEQ 0 (
    echo 127.0.0.1    %DOMAIN% >> C:\Windows\System32\drivers\etc\hosts
    echo   Hosts actualizado
) else (
    echo   El dominio ya existe en hosts
)

echo.
echo [3/4] Reiniciando Apache...
"%APACHE_BIN%\httpd.exe" -k restart

echo.
echo [4/4] Verificando configuracion...
"%APACHE_BIN%\httpd.exe" -t

echo.
echo ==========================================
echo VirtualHost creado correctamente!
echo ==========================================
echo.
echo   URL: http://%DOMAIN%
echo   DocumentRoot: %DOCUMENT_ROOT%\public
echo.
echo ==========================================

goto end

:error
echo.
echo ERROR: Todos los campos son requeridos
echo.

:end
pause