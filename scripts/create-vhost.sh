#!/bin/bash

# ===========================================
# Script para crear VirtualHost Apache
# ===========================================

set -e

echo "=========================================="
echo "Crear VirtualHost Apache"
echo "=========================================="

# Colores
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

# Pedir datos
echo -e "\n${YELLOW}Ingresa los datos del VirtualHost${NC}"

read -p "Nombre del proyecto (ej: mi-proyecto): " PROJECT_NAME
read -p "Dominio (ej: mi-proyecto.test): " DOMAIN
read -p "Ruta absoluta del proyecto (ej: /var/www/mi-proyecto): " DOCUMENT_ROOT

# Validar
if [ -z "$PROJECT_NAME" ] || [ -z "$DOMAIN" ] || [ -z "$DOCUMENT_ROOT" ]; then
    echo -e "${RED}Error: Todos los campos son requeridos${NC}"
    exit 1
fi

# Verificar si es sudo
if [ "$EUID" -ne 0 ]; then
    echo -e "${YELLOW}Nota: Se requieren permisos de sudo para escribir en /etc/apache2${NC}"
fi

echo -e "\n${YELLOW}Configuración:${NC}"
echo "  Proyecto: $PROJECT_NAME"
echo "  Dominio: $DOMAIN"
echo "  DocumentRoot: $DOCUMENT_ROOT"

# Crear archivo VirtualHost
VHOST_CONTENT="<VirtualHost *:80>
    ServerName $DOMAIN
    ServerAlias $DOMAIN
    DocumentRoot \"$DOCUMENT_ROOT/public\"

    <Directory \"$DOCUMENT_ROOT/public\">
        AllowOverride All
        Require all granted
        Options -Indexes +FollowSymLinks

        # Rewrites para Laravel
        RewriteEngine On
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteRule ^(.*)$ /index.php [QSA,L]
    </Directory>

    # Logs
    ErrorLog \${APACHE_LOG_DIR}/${PROJECT_NAME}_error.log
    CustomLog \${APACHE_LOG_DIR}/${PROJECT_NAME}_access.log combined

</VirtualHost>"

# Guardar archivo
VHOST_FILE="/etc/apache2/sites-available/${PROJECT_NAME}.conf"

echo -e "\n${YELLOW}Creando VirtualHost en: ${VHOST_FILE}${NC}"

# Crear usando sudo
if [ "$EUID" -eq 0 ]; then
    echo "$VHOST_CONTENT" > "$VHOST_FILE"
else
    echo "$VHOST_CONTENT" | sudo tee "$VHOST_FILE" > /dev/null
fi

echo -e "${GREEN}Archivo creado${NC}"

# Habilitar sitio
echo -e "\n${YELLOW}Habilitando sitio...${NC}"
if [ "$EUID" -eq 0 ]; then
    a2ensite "${PROJECT_NAME}.conf"
    a2enmod rewrite
else
    sudo a2ensite "${PROJECT_NAME}.conf"
    sudo a2enmod rewrite
fi

# Reiniciar Apache
echo -e "\n${YELLOW}Reiniciando Apache...${NC}"
if [ "$EUID" -eq 0 ]; then
    systemctl restart apache2
else
    sudo systemctl restart apache2
fi

# Agregar al hosts
HOSTS_LINE="127.0.0.1    $DOMAIN"
HOSTS_FILE="/etc/hosts"

echo -e "\n${YELLOW}Agregando al hosts file...${NC}"
if ! grep -q "$DOMAIN" "$HOSTS_FILE"; then
    if [ "$EUID" -eq 0 ]; then
        echo "$HOSTS_LINE" >> "$HOSTS_FILE"
    else
        echo "$HOSTS_LINE" | sudo tee -a "$HOSTS_FILE" > /dev/null
    fi
    echo -e "${GREEN}Hosts actualizado${NC}"
else
    echo -e "${YELLOW}El dominio ya existe en hosts${NC}"
fi

echo -e "\n=========================================="
echo -e "${GREEN}VirtualHost creado correctamente!${NC}"
echo -e "==========================================\n"
echo "  URL: http://$DOMAIN"
echo "  DocumentRoot: $DOCUMENT_ROOT/public"
echo -e "\n==========================================\n"