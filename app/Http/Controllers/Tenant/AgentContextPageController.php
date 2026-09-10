<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Admin\AiAgentsController;
use App\Http\Controllers\Agent\AgentToolsController;
use App\Http\Controllers\Controller;
use App\Models\Central\TenantAgentSetting;
use App\Models\Property;
use App\Services\Agent\AgentBrain;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Contexto del bot para el HOTEL: edita sus propias instrucciones
 * (settings.agent_instructions) y ve el prompt efectivo. Solo disponible si
 * el super-admin habilitó la palanca context_editable para este tenant.
 *
 * Además del prompt crudo se manda un RESUMEN legible de lo que el bot sabe:
 * el JSON del prompt es correcto pero ilegible, y el hotel no puede saber si
 * sus ligas y sus datos ya llegaron. El resumen sale del MISMO payload que
 * recibe el modelo, así que nunca puede decir algo distinto.
 */
class AgentContextPageController extends Controller
{
    public function __invoke(AgentBrain $brain): Response
    {
        abort_unless(
            (bool) TenantAgentSetting::for((string) tenant('id'))->context_editable,
            403,
            'El contexto del bot lo gestiona la plataforma para este hotel.',
        );

        $property = Property::firstOrFail();

        return Inertia::render('tenant/agent/Context', [
            'property' => $property->only(['id', 'name']),
            'agentInstructions' => $property->settings['agent_instructions'] ?? '',
            'template' => AiAgentsController::INSTRUCTIONS_TEMPLATE,
            'prompt' => $brain->promptPreview(),
            'knows' => $this->knows(),
        ]);
    }

    /**
     * Qué sabe el bot, por área, con lo que de verdad va en su contexto.
     *
     * @return array<string, mixed>
     */
    protected function knows(): array
    {
        $data = json_decode(
            AgentBrain::readable(app(AgentToolsController::class)->policies()),
            true,
        ) ?: [];

        $hotel = $data['hotel'] ?? [];
        $links = $hotel['links'] ?? [];

        $fact = fn (string $label, $value, ?string $hint = null) => [
            'label' => $label,
            'value' => is_array($value)
                ? (count($value) > 0 ? count($value).' liga(s)' : null)
                : (filled($value) ? (string) $value : null),
            'hint' => $hint,
        ];

        return [
            'contacto' => [
                $fact('Nombre', $hotel['name'] ?? null),
                $fact('Dirección', $hotel['address'] ?? null, 'Sin ella el bot no sabe dónde están'),
                $fact('Teléfono', $hotel['phone'] ?? null),
                $fact('Correo', $hotel['email'] ?? null),
                $fact('Sitio web', $hotel['website'] ?? null),
                $fact('Mapa', $hotel['maps_url'] ?? null, 'La liga que manda cuando preguntan cómo llegar'),
                $fact('Otras ligas', $links, 'Recorridos, términos, aviso legal'),
            ],
            'links' => collect($links)->map(fn (array $l) => [
                'label' => $l['label'] ?? '',
                'url' => $l['url'] ?? '',
            ])->values()->all(),
            'operacion' => [
                $fact('Entrada', $data['check_in_time'] ?? null),
                $fact('Salida', $data['check_out_time'] ?? null),
                $fact('Moneda', $data['currency'] ?? null),
                $fact('Políticas', filled($data['policies'] ?? null) ? 'Escritas' : null, 'El bot responde con ellas tal cual'),
                $fact('Cancelación', $data['cancellation_policy'] ?? null),
                $fact('Depósito en garantía', $data['guarantee']['label'] ?? null),
                $fact('Preguntas frecuentes', count($data['faqs'] ?? []) > 0 ? count($data['faqs']).' respuestas' : null),
                $fact('Experiencias', count($data['experiences'] ?? []) > 0 ? count($data['experiences']).' recorridos' : null),
            ],
            // Lo que más se pregunta: si el bot ya tiene la liga de fotos de
            // cada tipo y con qué capacidad cotiza.
            'room_types' => collect($data['room_types'] ?? [])->map(fn (array $type) => [
                'name' => $type['name'] ?? '',
                'units' => $type['units'] ?? null,
                'has_description' => filled($type['description'] ?? null),
                'photos_url' => $type['photos_url'] ?? null,
                'occupancy' => isset($type['occupancy']['included_guests'])
                    ? $type['occupancy']['included_guests'].' personas'
                        .(isset($type['occupancy']['max_guests']) ? ' (máx. '.$type['occupancy']['max_guests'].')' : '')
                    : null,
            ])->values()->all(),
        ];
    }
}
