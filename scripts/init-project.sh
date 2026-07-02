#!/bin/bash

# ===========================================
# Script para inicializar nuevo proyecto
# desde la plantilla Laravel 12 + Inertia
# ===========================================

set -e

echo "=========================================="
echo "Inicializando nuevo proyecto Laravel"
echo "=========================================="

# Colores para output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Verificar requisitos
echo -e "\n${YELLOW}Verificando requisitos...${NC}"

command -v php >/dev/null 2>&1 || { echo -e "${RED}PHP no encontrado${NC}"; exit 1; }
command -v composer >/dev/null 2>&1 || { echo -e "${RED}Composer no encontrado${NC}"; exit 1; }
command -v node >/dev/null 2>&1 || { echo -e "${RED}Node.js no encontrado${NC}"; exit 1; }
command -v pnpm >/dev/null 2>&1 || { echo -e "${RED}pnpm no encontrado. Instalar: npm install -g pnpm${NC}"; exit 1; }

echo -e "${GREEN}Todos los requisitos OK${NC}"

# Carpeta actual
PROJECT_DIR=$(pwd)
PROJECT_NAME=$(basename "$PROJECT_DIR")

echo -e "\n${YELLOW}Proyecto: ${NC}$PROJECT_NAME"
echo -e "${YELLOW}Ubicacion: ${NC}$PROJECT_DIR"

# 1. Limpiar archivos del proyecto anterior
echo -e "\n${YELLOW}[1/6] Limpiando archivos del proyecto anterior...${NC}"

if [ -f ".env" ]; then
    echo "  - Eliminando .env"
    rm -f .env
fi

if [ -f "database/database.sqlite" ]; then
    echo "  - Eliminando database.sqlite"
    rm -f database/database.sqlite
fi

if [ -f "pnpm-lock.yaml" ]; then
    echo "  - Eliminando pnpm-lock.yaml"
    rm -f pnpm-lock.yaml
fi

if [ -d "node_modules" ]; then
    echo "  - Eliminando node_modules"
    rm -rf node_modules
fi

if [ -d ".git" ]; then
    echo "  - Eliminando .git (para iniciar limpio)"
    rm -rf .git
fi

echo -e "${GREEN}  Limpieza completada${NC}"

# 2. Instalar dependencias Composer
echo -e "\n${YELLOW}[2/6] Instalando dependencias Composer...${NC}"
composer install --no-interaction

echo -e "${GREEN}  Composer OK${NC}"

# 3. Instalar dependencias pnpm
echo -e "\n${YELLOW}[3/6] Instalando dependencias pnpm...${NC}"
pnpm install --no-frozen-lockfile

echo -e "${GREEN}  pnpm OK${NC}"

# 4. Configurar archivo .env
echo -e "\n${YELLOW}[4/6] Configurando archivo .env...${NC}"

if [ ! -f ".env" ]; then
    echo "  - Copiando .env.example a .env"
    cp .env.example .env

    echo "  - Generando APP_KEY"
    php artisan key:generate --no-interaction
else
    echo "  - .env ya existe, saltando..."
fi

# 5. Migraciones
echo -e "\n${YELLOW}[5/6] Ejecutando migraciones...${NC}"

# Crear database.sqlite si no existe
if [ ! -f "database/database.sqlite" ]; then
    echo "  - Creando database.sqlite"
    touch database/database.sqlite
fi

php artisan migrate --no-interaction --force

echo -e "${GREEN}  Migraciones completadas${NC}"

# 6. Compilar assets
echo -e "\n${YELLOW}[6/6] Compilando assets...${NC}"
pnpm run build

echo -e "${GREEN}  Build completado${NC}"

# Final
echo -e "\n=========================================="
echo -e "${GREEN}Proyecto inicializado correctamente!${NC}"
echo -e "==========================================\n"
echo "Próximos pasos:"
echo "  1. Editar .env con la configuracion de tu base de datos"
echo "  2. Ejecutar: composer run dev"
echo "  3. Abrir: http://localhost:8000"
echo -e "\n==========================================\n"