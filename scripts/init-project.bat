@echo off
REM ===========================================
REM Script para inicializar nuevo proyecto
REM desde la plantilla Laravel 12 + Inertia
REM ===========================================

echo ==========================================
echo Inicializando nuevo proyecto Laravel
echo ==========================================

REM Verificar requisitos
echo.
echo Verificando requisitos...

where php >nul 2>&1
if %ERRORLEVEL% NEQ 0 (
    echo ERROR: PHP no encontrado
    exit /b 1
)

where composer >nul 2>&1
if %ERRORLEVEL% NEQ 0 (
    echo ERROR: Composer no encontrado
    exit /b 1
)

where node >nul 2>&1
if %ERRORLEVEL% NEQ 0 (
    echo ERROR: Node.js no encontrado
    exit /b 1
)

where pnpm >nul 2>&1
if %ERRORLEVEL% NEQ 0 (
    echo ERROR: pnpm no encontrado. Instalar: npm install -g pnpm
    exit /b 1
)

echo Todos los requisitos OK

REM Obtener nombre del proyecto
for %%i in ("%CD%") do set "PROJECT_NAME=%%~nxi"
echo.
echo Proyecto: %PROJECT_NAME%
echo Ubicacion: %CD%

REM 1. Limpiar archivos del proyecto anterior
echo.
echo [1/6] Limpiando archivos del proyecto anterior...

if exist ".env" (
    echo   - Eliminando .env
    del /f /q ".env" 2>nul
)

if exist "database\database.sqlite" (
    echo   - Eliminando database.sqlite
    del /f /q "database\database.sqlite" 2>nul
)

if exist "pnpm-lock.yaml" (
    echo   - Eliminando pnpm-lock.yaml
    del /f /q "pnpm-lock.yaml" 2>nul
)

if exist "node_modules" (
    echo   - Eliminando node_modules
    rmdir /s /q "node_modules" 2>nul
)

if exist ".git" (
    echo   - Eliminando .git (para iniciar limpio)
    rmdir /s /q ".git" 2>nul
)

echo   Limpieza completada

REM 2. Instalar dependencias Composer
echo.
echo [2/6] Instalando dependencias Composer...
call composer install --no-interaction
if %ERRORLEVEL% NEQ 0 (
    echo ERROR: Fallo composer install
    exit /b 1
)
echo   Composer OK

REM 3. Instalar dependencias pnpm
echo.
echo [3/6] Instalando dependencias pnpm...
call pnpm install --no-frozen-lockfile
if %ERRORLEVEL% NEQ 0 (
    echo ERROR: Fallo pnpm install
    exit /b 1
)
echo   pnpm OK

REM 4. Configurar archivo .env
echo.
echo [4/6] Configurando archivo .env...

if not exist ".env" (
    echo   - Copiando .env.example a .env
    copy .env.example .env >nul

    echo   - Generando APP_KEY
    php artisan key:generate --no-interaction
) else (
    echo   - .env ya existe, saltando...
)

REM 5. Migraciones
echo.
echo [5/6] Ejecutando migraciones...

if not exist "database\database.sqlite" (
    echo   - Creando database.sqlite
    type nul > database\database.sqlite
)

php artisan migrate --no-interaction --force
echo   Migraciones completadas

REM 6. Compilar assets
echo.
echo [6/6] Compilando assets...
call pnpm run build
if %ERRORLEVEL% NEQ 0 (
    echo ERROR: Fallo pnpm run build
    exit /b 1
)
echo   Build completado

REM Final
echo.
echo ==========================================
echo Proyecto inicializado correctamente!
echo ==========================================
echo.
echo Proximos pasos:
echo   1. Editar .env con la configuracion de tu base de datos
echo   2. Ejecutar: composer run dev
echo   3. Abrir: http://localhost:8000
echo.
echo ==========================================

pause