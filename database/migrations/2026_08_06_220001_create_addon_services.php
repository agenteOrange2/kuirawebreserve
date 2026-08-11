<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Servicios adicionales (documento "Resumen de Planes, Servicios
 * Adicionales e Inversión", 2026-08): catálogo central de add-ons que se
 * contratan POR ENCIMA del plan base y se cobran aparte. Cada servicio
 * enciende módulos del catálogo (config/modules.php) vía
 * Tenant::hasModule(), igual que el plan pero sumando.
 *
 * También asienta los precios de la terna comercial que el documento ya
 * define (antes pendientes en /admin/planes) y la cuota única de
 * activación por plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addon_services', function (Blueprint $table) {
            $table->string('key', 40)->primary();
            $table->string('name');
            // Breve resumen (tabla de inversión) + objetivo y recomendación
            // (resumen de servicios) del documento comercial.
            $table->string('summary', 500)->nullable();
            $table->text('objective')->nullable();
            $table->text('recommendation')->nullable();
            $table->unsignedInteger('price_monthly')->default(0);
            $table->unsignedInteger('activation_fee')->default(0);
            // Módulos que el servicio enciende para el hotel contratante.
            $table->json('modules')->nullable();
            // Cuota de respuestas IA/mes que aporta (solo asistentes).
            $table->unsignedInteger('ai_monthly_replies')->nullable();
            // Prerrequisito: key de otro servicio (Modalidad 3 amplía la 2).
            $table->string('requires', 40)->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('tenant_addon_services', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('addon_service_key', 40);
            $table->timestamps();

            $table->unique(['tenant_id', 'addon_service_key']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('addon_service_key')->references('key')->on('addon_services')->cascadeOnDelete();
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('activation_fee')->default(0)->after('price_monthly');
        });

        $this->seedAddonServices();
        $this->seedPlanPricing();
    }

    protected function seedAddonServices(): void
    {
        $services = [
            [
                'key' => 'reservas-m1-motor',
                'name' => 'Servicios Digitales de Reserva y Atención con IA – Modalidad 1: Motor de Reservas Online',
                'summary' => 'Permite que el cliente final solicite una reserva desde el sitio web, redes sociales, campañas, código QR o enlace directo, registrando la solicitud en el sistema para validación del hotel.',
                'objective' => 'Permitir que el cliente final solicite una reserva desde sitio web, redes sociales, campañas, QR o enlace directo.',
                'recommendation' => 'Hoteles o moteles que quieren recibir reservas en línea sin implementar IA desde el inicio. Primera etapa recomendada para fortalecer la captación digital.',
                'price_monthly' => 800,
                'activation_fee' => 1000,
                'modules' => ['motor-web'],
                'sort_order' => 1,
            ],
            [
                'key' => 'reservas-m2-ia-mensajes',
                'name' => 'Servicios Digitales de Reserva y Atención con IA – Modalidad 2: Asistente IA para Mensajes Digitales',
                'summary' => 'Automatiza respuestas en WhatsApp, Messenger, Instagram, TikTok, Telegram o sitio web, resolviendo preguntas frecuentes y guiando al cliente hacia una solicitud de reserva.',
                'objective' => 'Responder mensajes privados en WhatsApp, Messenger, Instagram, TikTok, Telegram o sitio web, guiando al cliente hacia una solicitud de reserva.',
                'recommendation' => 'Negocios que reciben muchas preguntas repetitivas y desean reducir carga operativa.',
                'price_monthly' => 600,
                'activation_fee' => 850,
                'modules' => ['mensajeria', 'agente-ia'],
                'ai_monthly_replies' => 500,
                'sort_order' => 2,
            ],
            [
                'key' => 'reservas-m3-ia-redes',
                'name' => 'Servicios Digitales de Reserva y Atención con IA – Modalidad 3: Asistente IA Avanzado para Redes Sociales',
                'summary' => 'Amplía la IA (Modalidad 2) para responder comentarios en publicaciones de Facebook, Instagram y TikTok, buscando convertir la interacción pública en oportunidades de reserva.',
                'objective' => 'Responder comentarios en publicaciones de redes sociales y dirigir a los usuarios hacia una solicitud de reserva.',
                'recommendation' => 'Negocios con alta actividad en redes sociales o campañas publicitarias activas. Los precios son adicionales a la Modalidad 2.',
                'price_monthly' => 500,
                'activation_fee' => 850,
                'modules' => ['redes-sociales'],
                'requires' => 'reservas-m2-ia-mensajes',
                'sort_order' => 3,
            ],
            [
                'key' => 'menu-digital',
                'name' => 'Menú Digital y Solicitud de Productos',
                'summary' => 'Agrega un catálogo digital para ofrecer alimentos, bebidas, productos, amenidades o servicios adicionales mediante liga, QR o sección web conectada a la plataforma.',
                'objective' => 'Agregar un catálogo digital para que el hotel o motel pueda ofrecer alimentos, bebidas, productos o servicios adicionales desde una liga, código QR o sección web.',
                'recommendation' => 'Hoteles o moteles que venden productos internos o desean incrementar ingresos adicionales durante la estancia del huésped.',
                'price_monthly' => 1500,
                'activation_fee' => 2000,
                'modules' => ['menu-digital'],
                'sort_order' => 4,
            ],
            [
                'key' => 'inventario-costos',
                'name' => 'Inventario y Control de Costos por Producto',
                'summary' => 'Permite controlar insumos, existencias, recetas, entradas, salidas, mermas y costos de los productos vendidos por el hotel o motel.',
                'objective' => 'Controlar insumos, productos, entradas, salidas, existencias, recetas y costos relacionados con los productos que vende el hotel o motel.',
                'recommendation' => 'Únicamente para clientes que ya venden alimentos, bebidas o productos adicionales y desean mayor control sobre costos, mermas y rentabilidad.',
                'price_monthly' => 1300,
                'activation_fee' => 2000,
                'modules' => ['pos'],
                'sort_order' => 5,
            ],
            [
                'key' => 'crm-frecuentes',
                'name' => 'CRM y Clientes Frecuentes',
                'summary' => 'Permite identificar clientes recurrentes, consultar historial de visitas, registrar preferencias y detectar oportunidades de recompra mediante promociones o seguimiento personalizado.',
                'objective' => 'Identificar clientes recurrentes, consultar historial de visitas, registrar preferencias y detectar oportunidades de recompra mediante promociones o seguimiento personalizado.',
                'recommendation' => 'Hoteles o moteles que desean fortalecer la recompra, fidelizar clientes y aprovechar mejor la información generada por sus reservas.',
                'price_monthly' => 1100,
                'activation_fee' => 2000,
                'modules' => ['crm-avanzado', 'promos'],
                'sort_order' => 6,
            ],
        ];

        foreach ($services as $service) {
            DB::table('addon_services')->updateOrInsert(
                ['key' => $service['key']],
                [
                    ...$service,
                    'modules' => json_encode($service['modules']),
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    protected function seedPlanPricing(): void
    {
        // Tabla "Resumen de Planes e Inversión". Solo pisa el precio si el
        // admin no capturó uno todavía (seguía en 0).
        $pricing = [
            'esencial' => ['price_monthly' => 2500, 'activation_fee' => 4000],
            'profesional' => ['price_monthly' => 3500, 'activation_fee' => 6000],
            'empresarial' => ['price_monthly' => 4300, 'activation_fee' => 8000],
        ];

        foreach ($pricing as $key => $prices) {
            DB::table('plans')->where('key', $key)->where('price_monthly', 0)->update([
                'price_monthly' => $prices['price_monthly'],
                'updated_at' => now(),
            ]);
            DB::table('plans')->where('key', $key)->update([
                'activation_fee' => $prices['activation_fee'],
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('activation_fee');
        });
        Schema::dropIfExists('tenant_addon_services');
        Schema::dropIfExists('addon_services');
    }
};
