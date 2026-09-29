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
        // Las cuentas llegan con el número en el campo que le toca. Los
        // registros viejos traen "cualquier número" en `clabe`: se reclasifican
        // aquí, así que reenviarlos tal cual no los rechaza la validación y, de
        // paso, quedan ya separados en disco. Es la migración sin migración.
        if (is_array($request->input('settings.bank_accounts'))) {
            $request->merge(['settings' => array_replace($request->input('settings', []), [
                'bank_accounts' => array_values(array_map(
                    fn ($account) => is_array($account) ? \App\Support\BankAccountNumber::formFields($account) : $account,
                    $request->input('settings.bank_accounts'),
                )),
            ])]);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'timezone' => ['sometimes', 'timezone'],
            'address' => ['nullable', 'string', 'max:255'],
            'settings' => ['sometimes', 'array'],
            // Ajustes del hotel (los consume el panel y el get_policies() de agentes).
            'settings.check_in_time' => ['nullable', 'date_format:H:i'],
            'settings.check_out_time' => ['nullable', 'date_format:H:i'],
            'settings.currency' => ['nullable', 'string', 'size:3'],
            // Doble moneda opcional (Datos generales → Horarios y moneda): una
            // segunda moneda + tipo de cambio para mostrar el "aprox" al
            // huésped. currency_secondary null = una sola moneda.
            'settings.currency_secondary' => ['sometimes', 'nullable', 'string', 'size:3'],
            'settings.exchange_rate' => ['sometimes', 'nullable', 'numeric', 'min:0.0001', 'max:1000000'],
            'settings.phone' => ['nullable', 'string', 'max:30'],
            'settings.email' => ['nullable', 'email', 'max:255'],
            // Contacto enriquecido (Datos Generales): varios teléfonos con
            // lada, varios emails, sitio web, link de Google Maps y redes.
            // El teléfono/email principal se DERIVA de estas listas para los
            // 7 lectores legacy que los leen como string.
            'settings.phones' => ['sometimes', 'nullable', 'array', 'max:5'],
            'settings.phones.*.code' => ['required_with:settings.phones', 'string', 'max:4'],
            'settings.phones.*.number' => ['required_with:settings.phones', 'string', 'max:15'],
            'settings.emails' => ['sometimes', 'nullable', 'array', 'max:5'],
            'settings.emails.*' => ['email', 'max:255'],
            'settings.website' => ['sometimes', 'nullable', 'url', 'max:255'],
            'settings.maps_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'settings.socials' => ['sometimes', 'nullable', 'array', 'max:10'],
            'settings.socials.*.type' => ['required_with:settings.socials', \Illuminate\Validation\Rule::in(['facebook', 'instagram', 'tiktok', 'youtube', 'x', 'whatsapp', 'other'])],
            'settings.socials.*.url' => ['required_with:settings.socials', 'url', 'max:255'],
            // Enlaces útiles del sitio, con nombre para que el asistente
            // sepa cuál mandar ("Recorridos", "Galería", "Cómo llegar").
            'settings.links' => ['sometimes', 'nullable', 'array', 'max:6'],
            'settings.links.*.label' => ['required_with:settings.links', 'string', 'max:60'],
            'settings.links.*.url' => ['required_with:settings.links', 'url', 'max:500'],
            // Wizard público (spec-motor-reservas-web E0): hotel vs motel
            // decide si se piden/permiten niños; el nombre de la modalidad
            // por bloque es libre (rato, periodo, horas… cada quien le
            // llama distinto).
            'settings.guest_policy' => ['nullable', \Illuminate\Validation\Rule::in(['family', 'adults_only'])],
            'settings.block_mode_label' => ['nullable', 'string', 'max:60'],
            // OJO: settings.property_mode NO se valida aquí a propósito — el
            // modo hotel|motel es decisión de plataforma y se administra SOLO
            // desde /admin (Admin\TenantController); si el tenant lo manda,
            // se descarta en silencio (spec-modo-motel).
            // Paso opcional de extras (POS/inventario) dentro del wizard —
            // se administra en el área aislada /ajustes/wizard.
            'settings.wizard_extras_enabled' => ['sometimes', 'boolean'],
            // Apariencia del wizard público (/reservas/ajustes): colores en
            // hex completo y modo de la tarjeta; null = default del theme.
            'settings.wizard_bg_from' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'settings.wizard_bg_to' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'settings.wizard_accent' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            // Tema del PANEL por hotel (/ajustes/general/apariencia): acento
            // de botones y degradado del menú lateral; null = tema Kuira.
            'settings.panel_primary' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'settings.panel_menu_from' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'settings.panel_menu_to' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'settings.wizard_theme' => ['sometimes', \Illuminate\Validation\Rule::in(['light', 'dark', 'auto'])],
            // Control explícito de si el wizard pide pago en línea al
            // reservar (spec-wizard-precios-y-pasos §5.2): por default lo
            // decide cada tarifa; el hotel puede forzarlo en ambos sentidos.
            // 'optional' (viejo modo "ambos") se sigue aceptando por
            // compatibilidad, pero la UI ya no lo ofrece: hoy ese caso es
            // 'always' + método efectivo activo (cash_payment_enabled).
            'settings.payment_mode' => ['nullable', \Illuminate\Validation\Rule::in(['automatic', 'always', 'optional', 'never'])],
            // "Pago en el hotel (efectivo)": el huésped puede apartar sin
            // pagar en línea y pagar al llegar. Solo surte efecto si la
            // plataforma permite el método (PaymentMethodGate).
            'settings.cash_payment_enabled' => ['sometimes', 'boolean'],
            // Horario de ATENCIÓN del personal (Datos generales → Horarios).
            // No confundir con check_in_time: esto es cuándo hay gente para
            // contestar el chat. Con esto el asistente deja de prometer
            // atención inmediata de madrugada y el hotel recibe el aviso de
            // las cotizaciones que entran fuera de turno.
            'settings.support_hours_enabled' => ['sometimes', 'boolean'],
            'settings.support_hours_open' => ['sometimes', 'nullable', 'date_format:H:i'],
            'settings.support_hours_close' => ['sometimes', 'nullable', 'date_format:H:i'],
            'settings.support_hours_days' => ['sometimes', 'nullable', 'array', 'max:7'],
            'settings.support_hours_days.*' => ['integer', 'between:1,7'],
            // WhatsApp al que llegan esos avisos; vacío = el teléfono
            // principal del hotel.
            'settings.support_alert_phone' => ['sometimes', 'nullable', 'array'],
            'settings.support_alert_phone.code' => ['nullable', 'string', 'max:4'],
            'settings.support_alert_phone.number' => ['nullable', 'string', 'max:15'],
            'settings.policies' => ['nullable', 'string', 'max:5000'],
            // Instrucciones libres para el asistente IA (tono, reglas propias,
            // contexto del negocio) — se inyectan en su system prompt.
            'settings.agent_instructions' => ['nullable', 'string', 'max:4000'],
            // Cobros: cuentas para transferencia (las entrega el bot al
            // solicitar un pago) y confirmación automática al cubrir anticipo.
            // Catálogo de daños (/ajustes/danos): concepto y precio sugerido
            // de lo que se cobra al revisar la habitación antes de la salida.
            'settings.damage_catalog' => ['sometimes', 'array', 'max:40'],
            'settings.damage_catalog.*.concept' => ['required', 'string', 'max:80'],
            'settings.damage_catalog.*.amount' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'settings.bank_accounts' => ['sometimes', 'array', 'max:10'],
            // Cada número se valida por lo que ES. Un número mal capturado es
            // dinero del huésped que se va a otra parte.
            'settings.bank_accounts.*' => ['array', function (string $attribute, mixed $value, \Closure $fail) {
                $numeros = array_filter(array_map(
                    fn (string $campo) => \App\Support\BankAccountNumber::digits(is_array($value) ? (string) ($value[$campo] ?? '') : ''),
                    ['clabe', 'card', 'account'],
                ));

                if ($numeros === []) {
                    $fail('Captura al menos un número de esta cuenta: la CLABE, la tarjeta o el número de cuenta.');
                }
            }],
            'settings.bank_accounts.*.bank' => ['required', 'string', 'max:80'],
            'settings.bank_accounts.*.holder' => ['required', 'string', 'max:120'],
            'settings.bank_accounts.*.clabe' => ['nullable', 'string', 'max:30', function (string $attribute, mixed $value, \Closure $fail) {
                if (\App\Support\BankAccountNumber::digits((string) $value) !== '' && ! \App\Support\BankAccountNumber::isClabe((string) $value)) {
                    $fail('La CLABE debe tener 18 dígitos y su dígito verificador no cuadra. Si la copiaste, revisa que no le falte, sobre ni se haya cambiado un dígito.');
                }
            }],
            'settings.bank_accounts.*.card' => ['nullable', 'string', 'max:30', function (string $attribute, mixed $value, \Closure $fail) {
                if (\App\Support\BankAccountNumber::digits((string) $value) !== '' && ! \App\Support\BankAccountNumber::isCard((string) $value)) {
                    $fail('La tarjeta debe tener 16 dígitos y el número no pasa la verificación. Revísalo dígito por dígito: uno mal capturado manda el dinero del huésped a otra parte.');
                }
            }],
            'settings.bank_accounts.*.account' => ['nullable', 'string', 'max:30', function (string $attribute, mixed $value, \Closure $fail) {
                if (\App\Support\BankAccountNumber::digits((string) $value) !== '' && ! \App\Support\BankAccountNumber::isAccountNumber((string) $value)) {
                    $fail('El número de cuenta debe tener 10 u 11 dígitos. Si tiene 18 es una CLABE y va en su campo; si tiene 16 es una tarjeta.');
                }
            }],
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
            // WhatsApps a los que el huésped manda el comprobante de su
            // transferencia — varios números, cada uno con su lada (hay
            // hoteles con línea de México y de EE. UU.).
            'settings.transfer_whatsapps' => ['sometimes', 'nullable', 'array', 'max:5'],
            'settings.transfer_whatsapps.*.code' => ['required_with:settings.transfer_whatsapps', 'string', 'max:4'],
            'settings.transfer_whatsapps.*.number' => ['required_with:settings.transfer_whatsapps', 'string', 'max:15'],
            'settings.hold_value' => ['sometimes', 'integer', 'min:1', 'max:999'],
            'settings.hold_unit' => ['sometimes', \Illuminate\Validation\Rule::in(['minute', 'hour', 'day', 'week'])],
            'settings.transfer_valid_value' => ['sometimes', 'integer', 'min:1', 'max:999'],
            // Minutos incluidos: hay hoteles que la tenían en minutos desde
            // el levantamiento y la pantalla no podía ni mostrarla ni
            // guardarla — el plazo real no era editable por nadie.
            'settings.transfer_valid_unit' => ['sometimes', \Illuminate\Validation\Rule::in(['minute', 'hour', 'day', 'week'])],
            // Plazo para pagar en el hotel (efectivo): reloj propio,
            // independiente del hold y de la transferencia (default 24 h).
            'settings.cash_deadline_value' => ['sometimes', 'integer', 'min:1', 'max:999'],
            'settings.cash_deadline_unit' => ['sometimes', \Illuminate\Validation\Rule::in(['minute', 'hour', 'day', 'week'])],
            'settings.balance_due_enabled' => ['sometimes', 'boolean'],
            'settings.balance_due_value' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'settings.balance_due_unit' => ['sometimes', \Illuminate\Validation\Rule::in(['day', 'week'])],
            // Política de cancelación default del hotel (Métodos de pago →
            // Cancelaciones): ventana sin costo antes de la llegada y % que
            // se retiene después. Una tarifa con política propia manda.
            'settings.cancel_policy_enabled' => ['sometimes', 'boolean'],
            'settings.cancel_free_value' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'settings.cancel_free_unit' => ['sometimes', \Illuminate\Validation\Rule::in(['hour', 'day', 'week'])],
            'settings.cancel_penalty_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'settings.cancel_policy_text' => ['sometimes', 'nullable', 'string', 'max:2000'],
            // Contrato de hospedaje del hotel: se adjunta en PDF al correo de
            // confirmación con los datos de la reserva. "## " abre un
            // apartado y "- " es una regla de la lista.
            'settings.contract_text' => ['sometimes', 'nullable', 'string', 'max:20000'],
            // Reenganche del asistente ("¿sigues por ahí?"): cuánto silencio
            // del huésped se espera, y desde cuántos mensajes suyos vale la
            // pena escribirle. 0 mensajes = sin filtro (comportamiento de
            // siempre). Cabañas 2026-09-12: con 4 mensajes o menos contestaba
            // el 14% y de ahí para arriba el 36% — perseguir al primer grupo
            // es escribirle a quien solo preguntó el precio y se fue.
            'settings.nudge_silence_minutes' => ['sometimes', 'integer', 'min:5', 'max:1440'],
            'settings.nudge_min_messages' => ['sometimes', 'integer', 'min:0', 'max:20'],
            // Cierre automático de la bandeja: días de silencio tras los que
            // una consulta suelta se da por terminada. 0 = apagado. Quien
            // llegó a una cotización real nunca se cierra solo (ver
            // ReservationPolicy::inboxAutoCloseDays).
            'settings.inbox_auto_close_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            // Walk-ins de mostrador: cobrar al registrar la llegada o la
            // cuenta final al registrar la salida (default histórico).
            'settings.walkin_charge' => ['sometimes', \Illuminate\Validation\Rule::in(['checkout', 'checkin'])],
            // Formas de cobro que acepta la recepción (ReservationPolicy::
            // counterMethods). NO es lo mismo que los métodos en línea de
            // /admin (PaymentMethodGate): esto es la terminal y la caja del
            // mostrador. Al menos una, o el mostrador no podría cobrar nada.
            'settings.counter_methods' => ['sometimes', 'array', 'min:1'],
            'settings.counter_methods.*' => [\Illuminate\Validation\Rule::in(\App\Models\Payment::METHODS)],
            // Fianza (depósito en garantía): monto fijo por estancia que se
            // cobra al registrar la llegada y se devuelve al registrar la
            // salida — ver ReservationPolicy::guaranteeEnabled().
            'settings.guarantee_enabled' => ['sometimes', 'boolean'],
            'settings.guarantee_amount' => ['sometimes', 'numeric', 'min:0', 'max:999999'],
            // Escalones por volumen: "desde N habitaciones, $X cada una"
            // (hay hoteles que bajan la fianza cuando el mismo grupo aparta
            // varias). `from` arranca en 2 porque desde 1 sería el monto
            // base con otro nombre.
            'settings.guarantee_tiers' => ['sometimes', 'array', 'max:5'],
            'settings.guarantee_tiers.*.from' => ['required', 'integer', 'min:2', 'max:100'],
            'settings.guarantee_tiers.*.amount' => ['required', 'numeric', 'min:0', 'max:999999'],
            // Operación del día (/ajustes/limpieza): check-in automático a
            // la hora de llegada, cómo avanza el semáforo sucia → limpieza →
            // disponible y qué pasa con una reservada cuya salida venció
            // sin check-in registrado.
            'settings.checkin_mode' => ['sometimes', \Illuminate\Validation\Rule::in(['manual', 'auto', 'both'])],
            'settings.hk_mode' => ['sometimes', \Illuminate\Validation\Rule::in(['manual', 'auto', 'both'])],
            'settings.hk_dirty_value' => ['sometimes', 'integer', 'min:1', 'max:999'],
            'settings.hk_dirty_unit' => ['sometimes', \Illuminate\Validation\Rule::in(['minute', 'hour'])],
            'settings.hk_cleaning_value' => ['sometimes', 'integer', 'min:1', 'max:999'],
            'settings.hk_cleaning_unit' => ['sometimes', \Illuminate\Validation\Rule::in(['minute', 'hour'])],
            'settings.day_close_no_checkin' => ['sometimes', \Illuminate\Validation\Rule::in(['dirty', 'available', 'none'])],
            // Ventana de llegada: el cierre de día mira la SALIDA, así que
            // una reserva de tres noches que nadie ocupó tenía el cuarto
            // apartado las tres. Esto mira la ENTRADA. Mínimo una hora: por
            // debajo se pelearía con el check-in automático, que corre cada
            // minuto y necesita margen para ganar cuando el cuarto se libera
            // tarde.
            'settings.arrival_no_show_enabled' => ['sometimes', 'boolean'],
            'settings.arrival_no_show_value' => ['sometimes', 'integer', 'min:1', 'max:99'],
            'settings.arrival_no_show_unit' => ['sometimes', \Illuminate\Validation\Rule::in(['hour'])],
            'settings.phone_country_code' => ['sometimes', 'string', 'max:4'],
            // Canal para avisos directos al huésped (sin conversación):
            // Meta oficial, Evolution, o automático con respaldo.
            'settings.direct_notify_channel' => ['sometimes', \Illuminate\Validation\Rule::in(['auto', 'meta', 'evolution'])],
            'settings.arrival_reminder_enabled' => ['sometimes', 'boolean'],
            // Avisos al HOTEL por correo (/ajustes/avisos-hotel, StaffAlerts):
            // a quién y de qué.
            'settings.staff_notice_emails' => ['sometimes', 'nullable', 'array', 'max:10'],
            'settings.staff_notice_emails.*' => ['email', 'max:255'],
            'settings.staff_notice_events' => ['sometimes', 'array'],
            'settings.staff_notice_events.reservation_new' => ['sometimes', 'boolean'],
            'settings.staff_notice_events.payment' => ['sometimes', 'boolean'],
            'settings.staff_notice_events.cancellation' => ['sometimes', 'boolean'],
            'settings.staff_notice_events.checkout' => ['sometimes', 'boolean'],
            // Aviso el día de la llegada: segundo recordatorio cuando la
            // entrada está a N horas (default 2).
            'settings.arrival_soon_enabled' => ['sometimes', 'boolean'],
            'settings.arrival_soon_hours' => ['sometimes', 'integer', 'min:1', 'max:24'],
            // Agradecimiento al salir: mensaje al completar la estancia,
            // con el link de reseñas del hotel (Google/Tripadvisor) si lo
            // capturó — ver ReservationPolicy::postStayThanksEnabled.
            'settings.post_stay_thanks_enabled' => ['sometimes', 'boolean'],
            'settings.post_stay_survey_enabled' => ['sometimes', 'boolean'],
            // Aspectos del cuestionario de experiencia (/ajustes/encuestas):
            // cada uno es una pregunta de 1 a 5 estrellas. La calificación
            // general y el comentario son fijos y no se configuran.
            'settings.survey_aspects' => ['sometimes', 'array', 'max:8'],
            'settings.survey_aspects.*.key' => ['nullable', 'string', 'max:40'],
            'settings.survey_aspects.*.label' => ['required', 'string', 'max:60'],
            'settings.review_url' => ['sometimes', 'nullable', 'url', 'max:500'],
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

        // El teléfono/email principal (los leen 7 puntos como string) se
        // DERIVA de las listas nuevas: el primero manda. Así el hotelero
        // gestiona una sola lista limpia y nada legacy se rompe.
        if (isset($data['settings']) && array_key_exists('phones', $data['settings'])) {
            $first = collect($data['settings']['phones'] ?? [])->first();
            $code = preg_replace('/\D+/', '', (string) ($first['code'] ?? ''));
            $number = preg_replace('/\D+/', '', (string) ($first['number'] ?? ''));
            $data['settings']['phone'] = $number !== '' ? '+'.$code.$number : null;
            $data['settings']['phone_country_code'] = $code !== '' ? $code : ($property->settings['phone_country_code'] ?? '52');
        }
        if (isset($data['settings']) && array_key_exists('staff_notice_emails', $data['settings'])) {
            $data['settings']['staff_notice_emails'] = collect($data['settings']['staff_notice_emails'] ?? [])
                ->map(fn ($email) => strtolower(trim((string) $email)))
                ->filter()
                ->unique()
                ->values()
                ->all();
        }
        if (isset($data['settings']) && array_key_exists('emails', $data['settings'])) {
            $data['settings']['email'] = collect($data['settings']['emails'] ?? [])
                ->filter()->first() ?: null;
        }

        // Escalones de la fianza: ordenados y sin `from` repetido, para que
        // ReservationPolicy::guaranteeAmountFor recorra una lista limpia y
        // el hotel no pueda guardar dos reglas que se contradigan.
        if (isset($data['settings']) && array_key_exists('guarantee_tiers', $data['settings'])) {
            $data['settings']['guarantee_tiers'] = collect($data['settings']['guarantee_tiers'] ?? [])
                ->map(fn (array $tier) => [
                    'from' => (int) $tier['from'],
                    'amount' => round((float) $tier['amount'], 2),
                ])
                ->unique('from')
                ->sortBy('from')
                ->values()
                ->all();
        }

        // Aspectos del cuestionario: la llave es el identificador ESTABLE
        // de cada pregunta (agrupa respuestas históricas aunque se renombre
        // el texto). A los nuevos se les genera del label, sin repetirse.
        if (isset($data['settings']) && array_key_exists('survey_aspects', $data['settings'])) {
            $seen = [];
            $data['settings']['survey_aspects'] = collect($data['settings']['survey_aspects'] ?? [])
                ->map(function (array $aspect) use (&$seen) {
                    $key = trim((string) ($aspect['key'] ?? ''));
                    if ($key === '') {
                        $key = \Illuminate\Support\Str::slug((string) $aspect['label']) ?: 'aspecto';
                    }
                    $base = $key;
                    for ($i = 2; in_array($key, $seen, true); $i++) {
                        $key = "{$base}-{$i}";
                    }
                    $seen[] = $key;

                    return ['key' => $key, 'label' => (string) $aspect['label']];
                })
                ->values()
                ->all();
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
