<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PropertyController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Property::withCount('rooms')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        // Decisión (docs/spec-pendientes-y-agentes.md §3.1): el panel opera
        // UNA propiedad por tenant hoy; multipropiedad = selector + scoping
        // en fase futura. Evita estados a medias con Property::firstOrFail().
        if (Property::query()->exists()) {
            return response()->json([
                'message' => 'Por ahora el panel maneja una propiedad por hotel; la multipropiedad llegará en una fase futura.',
            ], 422);
        }

        $max = tenant()->planLimit('max_properties');
        if ($max !== null && Property::count() >= $max) {
            return response()->json([
                'message' => "Límite del plan alcanzado: máximo {$max} propiedad(es). Actualiza el plan para agregar más.",
            ], 422);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['sometimes', 'timezone'],
            'address' => ['nullable', 'string', 'max:255'],
            'settings' => ['sometimes', 'array'],
        ]);

        return response()->json(Property::create($data), 201);
    }

    public function show(Property $property): JsonResponse
    {
        return response()->json(
            $property->load(['zones', 'roomTypes'])->loadCount('rooms')
        );
    }

    public function update(Request $request, Property $property): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'timezone' => ['sometimes', 'timezone'],
            'address' => ['nullable', 'string', 'max:255'],
            'settings' => ['sometimes', 'array'],
            // Ajustes del hotel (los consume el panel y el get_policies() de agentes).
            'settings.check_in_time' => ['nullable', 'date_format:H:i'],
            'settings.check_out_time' => ['nullable', 'date_format:H:i'],
            'settings.currency' => ['nullable', 'string', 'size:3'],
            'settings.phone' => ['nullable', 'string', 'max:30'],
            'settings.email' => ['nullable', 'email', 'max:255'],
            // Wizard público (spec-motor-reservas-web E0): hotel vs motel
            // decide si se piden/permiten niños; el nombre de la modalidad
            // por bloque es libre (rato, periodo, horas… cada quien le
            // llama distinto).
            'settings.guest_policy' => ['nullable', \Illuminate\Validation\Rule::in(['family', 'adults_only'])],
            'settings.block_mode_label' => ['nullable', 'string', 'max:60'],
            // Paso opcional de extras (POS/inventario) dentro del wizard —
            // se administra en el área aislada /ajustes/wizard.
            'settings.wizard_extras_enabled' => ['sometimes', 'boolean'],
            // Control explícito de si el wizard pide pago en línea al
            // reservar (spec-wizard-precios-y-pasos §5.2): por default lo
            // decide cada tarifa; el hotel puede forzarlo en ambos sentidos.
            'settings.payment_mode' => ['nullable', \Illuminate\Validation\Rule::in(['automatic', 'always', 'never'])],
            'settings.policies' => ['nullable', 'string', 'max:5000'],
            // Instrucciones libres para el asistente IA (tono, reglas propias,
            // contexto del negocio) — se inyectan en su system prompt.
            'settings.agent_instructions' => ['nullable', 'string', 'max:4000'],
            // Cobros: cuentas para transferencia (las entrega el bot al
            // solicitar un pago) y confirmación automática al cubrir anticipo.
            'settings.bank_accounts' => ['sometimes', 'array', 'max:10'],
            'settings.bank_accounts.*.bank' => ['required', 'string', 'max:80'],
            'settings.bank_accounts.*.holder' => ['required', 'string', 'max:120'],
            'settings.bank_accounts.*.clabe' => ['required', 'string', 'max:30'],
            'settings.bank_accounts.*.active' => ['sometimes', 'boolean'],
            'settings.auto_confirm_on_payment' => ['sometimes', 'boolean'],
            // Saldos automáticos: con cuánta anticipación pedirlos y si el
            // impago cancela solo (default: solo alerta, spec-pagos §7.2).
            'settings.balance_request_days' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'settings.cancel_on_balance_overdue' => ['sometimes', 'boolean'],
            // Plazos configurables (Métodos de pago → Plazos): duración del
            // apartado, vigencia de transferencias y fecha límite de pago
            // total con su interruptor. Valor + unidad; ReservationPolicy
            // los traduce y aplica los defaults de siempre si faltan.
            'settings.hold_value' => ['sometimes', 'integer', 'min:1', 'max:999'],
            'settings.hold_unit' => ['sometimes', \Illuminate\Validation\Rule::in(['minute', 'hour', 'day', 'week'])],
            'settings.transfer_valid_value' => ['sometimes', 'integer', 'min:1', 'max:999'],
            'settings.transfer_valid_unit' => ['sometimes', \Illuminate\Validation\Rule::in(['hour', 'day', 'week'])],
            'settings.balance_due_enabled' => ['sometimes', 'boolean'],
            'settings.balance_due_value' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'settings.balance_due_unit' => ['sometimes', \Illuminate\Validation\Rule::in(['day', 'week'])],
            'settings.phone_country_code' => ['sometimes', 'string', 'max:4'],
            // Canal para avisos directos al huésped (sin conversación):
            // Meta oficial, Evolution, o automático con respaldo.
            'settings.direct_notify_channel' => ['sometimes', \Illuminate\Validation\Rule::in(['auto', 'meta', 'evolution'])],
            'settings.arrival_reminder_enabled' => ['sometimes', 'boolean'],
            // Widgets públicos incrustables (/integracion): el toggle apaga
            // también la página pública correspondiente.
            'settings.widget_reservas_enabled' => ['sometimes', 'boolean'],
            'settings.widget_experiencias_enabled' => ['sometimes', 'boolean'],
            'settings.widget_grupos_enabled' => ['sometimes', 'boolean'],
            // SMTP propio del hotel (avisos por correo, /ajustes): la
            // contraseña se cifra abajo; vacía = conservar la guardada.
            'settings.smtp_host' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings.smtp_port' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'settings.smtp_username' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings.smtp_password' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings.smtp_from_address' => ['sometimes', 'nullable', 'email', 'max:255'],
            'settings.smtp_from_name' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        if (isset($data['settings']) && array_key_exists('smtp_password', $data['settings'])) {
            if ((string) $data['settings']['smtp_password'] === '') {
                unset($data['settings']['smtp_password']); // vacía = conservar la actual
            } else {
                $data['settings']['smtp_password'] = \Illuminate\Support\Facades\Crypt::encryptString($data['settings']['smtp_password']);
            }
        }

        // Merge para no pisar llaves de settings que esta pantalla no maneja.
        if (isset($data['settings'])) {
            $data['settings'] = array_merge($property->settings ?? [], $data['settings']);
        }

        $property->update($data);

        return response()->json($property);
    }

    public function destroy(Property $property): JsonResponse
    {
        $property->delete();

        return response()->json(status: 204);
    }
}
