# Plantilla Laravel 12 + Inertia + Vue

Plantilla base para proyectos Laravel 12 con frontend Vue 3, Inertia, Tailwind CSS y pnpm.

## Requisitos

- PHP 8.2+
- Composer 2.x
- Node.js 20+
- pnpm 8+
- Git

## Estructura del Proyecto

```
├── app/                    # Aplicación Laravel
├── bootstrap/              # Configuración de la app
├── config/                 # Archivos de configuración
├── database/               # Migraciones, factories, seeders
├── resources/
│   └── js/
│       ├── components/    # Componentes Vue
│       ├── layouts/        # Layouts de página
│       ├── pages/          # Páginas Inertia
│       └── composables/    # Composables Vue
├── routes/                 # Rutas de la aplicación
└── vendor/                 # Dependencias Composer
```

## Paquetes Instalados

### Backend (Composer)
- `laravel/framework` ^12.0
- `inertiajs/inertia-laravel` ^2.0
- `laravel/fortify` ^1.30
- `laravel/wayfinder` ^0.1.9
- `spatie/laravel-permission` ^6.25
- `spatie/laravel-medialibrary` ^11.23
- `maatwebsite/excel` ^3.1

### Frontend (pnpm)
- Vue 3.5+
- Inertia ^2.3
- Tailwind CSS v4
- Pinia ^3.0
- Lucide Icons
- Headless UI

## Primeros Pasos

### 1. Clonar el repositorio

```bash
git clone <url-del-repo> nombre-proyecto
cd nombre-proyecto
```

### 2. Limpiar archivos del proyecto anterior

```bash
# En Windows (PowerShell)
Remove-Item -Force .env -ErrorAction SilentlyContinue
Remove-Item -Force database/database.sqlite -ErrorAction SilentlyContinue
Remove-Item -Force pnpm-lock.yaml -ErrorAction SilentlyContinue
Remove-Item -Recurse -Force node_modules -ErrorAction SilentlyContinue

# En Linux/Mac
rm -f .env database/database.sqlite pnpm-lock.yaml
rm -rf node_modules
```

### 3. Instalar dependencias

```bash
composer install
pnpm install
```

### 4. Configurar entorno

```bash
# Copiar archivo de entorno
copy .env.example .env   # Windows
# cp .env.example .env  # Linux/Mac

# Generar clave de aplicación
php artisan key:generate

# Configurar base de datos en .env
```

### 5. Migraciones y seeders

```bash
php artisan migrate
php artisan db:seed  # Opcional
```

### 6. Compilar assets

```bash
pnpm run build
```

### 7. Iniciar servidor de desarrollo

```bash
composer run dev
```

## Scripts Disponibles

| Script | Descripción |
|--------|-------------|
| `composer run dev` | Inicia servidor Laravel + Queue + Vite |
| `composer run dev:ssr` | Igual + SSR + Logs |
| `composer run setup` | Instala todo desde cero |
| `composer run test` | Ejecuta tests Pest |
| `pnpm run lint` | Linting ESLint |
| `pnpm run format` | Formatea con Prettier |

## Estructura de Rutas

### Rutas Web
- `/` - Página de inicio
- `/login`, `/register` - Autenticación (Fortify)
- `/dashboard` - Dashboard principal
- `/admin/*` - Rutas de administrador

### Rutas Admin
- `/admin/dashboard` - Dashboard admin
- `/admin/settings/*` - Configuración de cuenta

## Paquetes Adicionales

### spatie/laravel-permission
Gestión de roles y permisos.

```bash
# Publicar configuración
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"

# Migraciones ya incluidas
php artisan migrate
```

### spatie/laravel-medialibrary
Gestión de archivos multimedia.

```bash
php artisan vendor:publish --provider="Spatie\MediaLibrary\MediaLibraryServiceProvider"
php artisan migrate
```

### maatwebsite/excel
Exportación/importación de archivos Excel.

```bash
php artisan vendor:publish --provider="Maatwebsite\Excel\ExcelServiceProvider"
```

## Agregar Nuevos Paquetes

```bash
# Composer
composer require vendor/package

# pnpm
pnpm add vendor/package
```

## Gestión de Configuración

### Agregar paquete a producción
```bash
composer install --no-dev
pnpm run build
```

### Actualizar dependencias
```bash
composer update
pnpm update
```

## Troubleshooting

### Error: Vite manifest not found
```bash
pnpm run build
```

### Error: Class not found
```bash
composer dump-autoload
php artisan config:clear
```

### Error de permisos (Linux)
```bash
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data .
```

## Notas Importantes

1. **No subir archivos sensibles**: Asegúrate de que `.env` esté en `.gitignore`
2. **node_modules no se sube**: Ejecutar `pnpm install` después de clonar
3. **Base de datos**: Cada proyecto debe tener su propia BD configurada en `.env`
4. **pnpm workspace**: Si agregas paquetes, verificar `pnpm-workspace.yaml`