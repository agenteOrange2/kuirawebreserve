<?php

namespace App\Services\Admin;

/**
 * Cómo se lee cada acción del panel en la bitácora: frase, icono, tono y
 * área. La llave es el nombre de la ruta (o el evento de acceso). Una ruta
 * nueva de /admin que no esté aquí igual se registra, con una frase
 * genérica: no se pierde nada por olvidar agregarla.
 */
class AdminActivityCatalog
{
    public const CATEGORIES = [
        'access' => 'Accesos',
        'tenants' => 'Hoteles',
        'users' => 'Usuarios',
        'plans' => 'Planes y servicios',
        'prospects' => 'Prospectos',
        'ai' => 'Asistente IA',
        'channels' => 'Canales',
        'payments' => 'Pagos',
        'platform' => 'Plataforma',
    ];

    /** @var array<string, array{0: string, 1: string, 2: string, 3?: string}> frase, icono, área, tono */
    public const ACTIONS = [
        'auth.login' => ['Inició sesión', 'LogIn', 'access', 'success'],
        'auth.logout' => ['Cerró sesión', 'LogOut', 'access'],
        'auth.failed' => ['Intento de acceso fallido', 'ShieldAlert', 'access', 'danger'],

        'admin.tenants.store' => ['Dio de alta el hotel', 'Building2', 'tenants', 'success'],
        'admin.tenants.update' => ['Editó el hotel', 'Pencil', 'tenants'],
        'admin.tenants.destroy' => ['Eliminó el hotel', 'Trash2', 'tenants', 'danger'],
        'admin.tenants.suspend' => ['Suspendió o reactivó el hotel', 'Pause', 'tenants', 'warning'],
        'admin.tenants.impersonate' => ['Entró como el hotel', 'LogIn', 'tenants', 'warning'],
        'admin.tenants.modules' => ['Cambió un módulo del hotel', 'ToggleRight', 'tenants'],
        'admin.tenants.module-requests.dismiss' => ['Descartó una solicitud de módulo', 'X', 'tenants'],
        'admin.tenants.users.store' => ['Agregó personal al hotel', 'UserPlus', 'tenants', 'success'],
        'admin.tenants.users.update' => ['Editó personal del hotel', 'UserCog', 'tenants'],
        'admin.tenants.users.destroy' => ['Quitó personal del hotel', 'UserMinus', 'tenants', 'danger'],
        'admin.tenants.addon-services' => ['Cambió un servicio adicional del hotel', 'PackagePlus', 'tenants'],
        'admin.tenants.meta-app.update' => ['Configuró la app de Meta del hotel', 'Settings', 'channels'],
        'admin.tenants.meta-app.destroy' => ['Quitó la app de Meta del hotel', 'Trash2', 'channels', 'danger'],

        'admin.users.store' => ['Creó el usuario', 'UserPlus', 'users', 'success'],
        'admin.users.update' => ['Editó el usuario', 'UserCog', 'users'],
        'admin.users.destroy' => ['Eliminó el usuario', 'UserX', 'users', 'danger'],

        'admin.plans.store' => ['Creó el plan', 'Layers', 'plans', 'success'],
        'admin.plans.update' => ['Editó el plan', 'Layers', 'plans'],
        'admin.plans.destroy' => ['Eliminó el plan', 'Trash2', 'plans', 'danger'],
        'admin.services.update' => ['Editó el servicio adicional', 'PackagePlus', 'plans'],

        'admin.prospects.update' => ['Actualizó el prospecto', 'Contact', 'prospects'],
        'admin.prospects.destroyBulk' => ['Eliminó prospectos', 'Trash2', 'prospects', 'danger'],
        'admin.prospects.sendDocuments' => ['Envió documentos al prospecto', 'Send', 'prospects'],
        'admin.prospects.markWhatsapp' => ['Marcó WhatsApp enviado al prospecto', 'MessageCircle', 'prospects'],
        'admin.prospects.documents.store' => ['Subió un documento para prospectos', 'FileUp', 'prospects', 'success'],
        'admin.prospects.documents.update' => ['Editó un documento para prospectos', 'FileText', 'prospects'],
        'admin.prospects.documents.destroy' => ['Eliminó un documento para prospectos', 'Trash2', 'prospects', 'danger'],

        'admin.ai.providers.store' => ['Agregó un proveedor de IA', 'Bot', 'ai', 'success'],
        'admin.ai.providers.update' => ['Editó el proveedor de IA', 'Bot', 'ai'],
        'admin.ai.providers.destroy' => ['Eliminó el proveedor de IA', 'Trash2', 'ai', 'danger'],
        'admin.ai.providers.test' => ['Probó el proveedor de IA', 'FlaskConical', 'ai'],
        'admin.ai.providers.reorder' => ['Cambió el orden de las keys de IA', 'ArrowUpDown', 'ai'],
        'admin.ai.tenants.update' => ['Ajustó el asistente del hotel', 'Bot', 'ai'],

        'admin.meta.store' => ['Conectó un canal de Meta', 'Share2', 'channels', 'success'],
        'admin.meta.update' => ['Editó el canal de Meta', 'Share2', 'channels'],
        'admin.meta.destroy' => ['Quitó el canal de Meta', 'Trash2', 'channels', 'danger'],
        'admin.meta.diagnose' => ['Diagnosticó el canal de Meta', 'Stethoscope', 'channels'],
        'admin.meta.resubscribe' => ['Resuscribió el canal de Meta', 'RefreshCw', 'channels'],
        'admin.telegram.store' => ['Conectó un canal de Telegram', 'Send', 'channels', 'success'],
        'admin.telegram.update' => ['Editó el canal de Telegram', 'Send', 'channels'],
        'admin.telegram.destroy' => ['Quitó el canal de Telegram', 'Trash2', 'channels', 'danger'],
        'admin.telegram.test' => ['Probó el canal de Telegram', 'FlaskConical', 'channels'],
        'admin.tiktok.store' => ['Conectó un canal de TikTok', 'Music', 'channels', 'success'],
        'admin.tiktok.update' => ['Editó el canal de TikTok', 'Music', 'channels'],
        'admin.tiktok.destroy' => ['Quitó el canal de TikTok', 'Trash2', 'channels', 'danger'],
        'admin.tiktok.test' => ['Probó el canal de TikTok', 'FlaskConical', 'channels'],

        'admin.payments.methods' => ['Cambió los métodos de pago de la plataforma', 'CreditCard', 'payments'],
        'admin.payments.tenant' => ['Cambió los métodos de pago del hotel', 'CreditCard', 'payments'],
        'admin.payments.gateways.destroy' => ['Quitó una pasarela huérfana', 'Trash2', 'payments', 'danger'],
        'admin.branding.update' => ['Cambió la apariencia de la plataforma', 'Palette', 'platform'],
    ];

    /**
     * @return array{label: string, icon: string, category: string, category_label: string, tone: string}
     */
    public static function describe(string $action): array
    {
        [$label, $icon, $category, $tone] = (self::ACTIONS[$action] ?? ['Hizo un cambio en el panel', 'Activity', 'platform']) + [3 => 'primary'];

        return [
            'label' => $label,
            'icon' => $icon,
            'category' => $category,
            'category_label' => self::CATEGORIES[$category] ?? $category,
            'tone' => $tone,
        ];
    }

    /**
     * Acciones de un área, para filtrar la bitácora por categoría.
     *
     * @return list<string>
     */
    public static function actionsIn(string $category): array
    {
        return array_keys(array_filter(self::ACTIONS, fn (array $a) => $a[2] === $category));
    }
}
