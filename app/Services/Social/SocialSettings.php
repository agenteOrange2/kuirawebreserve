<?php

namespace App\Services\Social;

use App\Models\Property;
use App\Models\SocialComment;

/**
 * Ajustes del módulo de redes sociales, guardados en
 * `properties.settings['social']` — mismo patrón que agent_instructions, sin
 * tabla propia porque son una decena de banderas por hotel.
 *
 * La regla dura NO es configurable: en una queja el bot jamás inicia el
 * mensaje privado ni deja que la IA redacte (spec §4.6). Lo único que el
 * hotel puede activar es una respuesta pública de PLANTILLA fija (texto
 * pre-aprobado, estilo "lamentamos tu experiencia, escríbenos por privado")
 * — y aun así el comentario queda pendiente para que conteste una persona.
 */
class SocialSettings
{
    /** @var array<string, array{responder_publico: bool, mandar_privado: bool, plantilla: string}> */
    public const DEFAULTS_BY_CLASS = [
        SocialComment::CLASS_PURCHASE => [
            'responder_publico' => true,
            'mandar_privado' => true,
            'plantilla' => 'Con gusto te mandamos la información por mensaje privado.',
        ],
        SocialComment::CLASS_QUESTION => [
            'responder_publico' => true,
            'mandar_privado' => true,
            'plantilla' => 'Gracias por escribirnos, te respondemos por mensaje privado.',
        ],
        SocialComment::CLASS_COMPLAINT => [
            // Apagado por default; al activarlo solo se publica la plantilla
            // (la IA nunca redacta una queja) y el privado sigue vetado.
            'responder_publico' => false,
            'mandar_privado' => false,
            'plantilla' => 'Hola [Nombre], lamentamos que tu experiencia no haya sido la esperada. Nos gustaría escucharte por mensaje privado para revisar lo sucedido y mejorar. Gracias por decírnoslo.',
        ],
        SocialComment::CLASS_PRAISE => [
            'responder_publico' => true,
            'mandar_privado' => false,
            'plantilla' => 'Gracias por tu comentario, te esperamos pronto.',
        ],
        SocialComment::CLASS_SPAM => [
            'responder_publico' => false,
            'mandar_privado' => false,
            'plantilla' => '',
        ],
    ];

    public function __construct(protected ?Property $property = null) {}

    /**
     * @return array{
     *     activo: bool,
     *     moderacion_automatica: bool,
     *     palabras_bloqueadas: array<int, string>,
     *     avisar_quejas: bool,
     *     clasificaciones: array<string, array{responder_publico: bool, mandar_privado: bool, plantilla: string}>
     * }
     */
    public function all(): array
    {
        $stored = $this->property()?->settings['social'] ?? [];

        $classes = [];
        foreach (self::DEFAULTS_BY_CLASS as $key => $defaults) {
            $saved = $stored['clasificaciones'][$key] ?? [];

            $classes[$key] = [
                'responder_publico' => (bool) ($saved['responder_publico'] ?? $defaults['responder_publico']),
                'mandar_privado' => (bool) ($saved['mandar_privado'] ?? $defaults['mandar_privado']),
                'plantilla' => (string) ($saved['plantilla'] ?? $defaults['plantilla']),
            ];
        }

        // El privado en quejas no se abre solo pase lo que pase (ni con
        // ajustes viejos): si el huésped está molesto, escribirle por
        // privado sin que él lo pida empeora. La respuesta pública SÍ es
        // configurable, pero solo con plantilla (SocialResponder no deja
        // que la IA redacte en quejas).
        $classes[SocialComment::CLASS_COMPLAINT]['mandar_privado'] = false;

        return [
            'activo' => (bool) ($stored['activo'] ?? true),
            'moderacion_automatica' => (bool) ($stored['moderacion_automatica'] ?? false),
            'palabras_bloqueadas' => array_values(array_filter(
                (array) ($stored['palabras_bloqueadas'] ?? []),
                fn ($word) => is_string($word) && trim($word) !== '',
            )),
            'avisar_quejas' => (bool) ($stored['avisar_quejas'] ?? true),
            'clasificaciones' => $classes,
        ];
    }

    /**
     * ¿La IA puede responder en público a esta clasificación?
     */
    public function repliesPublicly(string $classification): bool
    {
        $all = $this->all();

        return $all['activo']
            && ($all['clasificaciones'][$classification]['responder_publico'] ?? false);
    }

    /** ¿La IA puede abrir el mensaje privado con esta clasificación? */
    public function sendsPrivate(string $classification): bool
    {
        $all = $this->all();

        return $all['activo']
            && ($all['clasificaciones'][$classification]['mandar_privado'] ?? false);
    }

    public function template(string $classification): string
    {
        return (string) ($this->all()['clasificaciones'][$classification]['plantilla'] ?? '');
    }

    /**
     * Sustituye el placeholder [Nombre] de una plantilla por el nombre de
     * quien comenta. Sin nombre (Instagram a veces no lo da), el hueco se
     * limpia para que no quede "Hola ," publicado.
     */
    public static function personalize(string $text, ?string $name): string
    {
        $out = str_ireplace('[nombre]', trim((string) $name), $text);
        $out = preg_replace('/\s+([,;:.!?])/u', '$1', $out) ?? $out;
        $out = preg_replace('/ {2,}/', ' ', $out) ?? $out;

        return trim($out);
    }

    /**
     * Filtro barato ANTES de gastar una llamada de IA: si el comentario trae
     * una palabra de la lista del hotel, se oculta sin clasificar.
     */
    public function matchesBlockedWord(?string $body): ?string
    {
        $text = mb_strtolower(trim((string) $body));

        if ($text === '') {
            return null;
        }

        foreach ($this->all()['palabras_bloqueadas'] as $word) {
            if (str_contains($text, mb_strtolower(trim($word)))) {
                return $word;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function save(array $input): void
    {
        $property = $this->property();

        if (! $property) {
            return;
        }

        $classes = [];
        foreach (array_keys(self::DEFAULTS_BY_CLASS) as $key) {
            $given = $input['clasificaciones'][$key] ?? [];

            $classes[$key] = [
                'responder_publico' => (bool) ($given['responder_publico'] ?? false),
                'mandar_privado' => (bool) ($given['mandar_privado'] ?? false),
                'plantilla' => trim((string) ($given['plantilla'] ?? '')),
            ];
        }

        $property->update([
            'settings' => array_merge($property->settings ?? [], [
                'social' => [
                    'activo' => (bool) ($input['activo'] ?? true),
                    'moderacion_automatica' => (bool) ($input['moderacion_automatica'] ?? false),
                    'palabras_bloqueadas' => array_values(array_filter(array_map(
                        fn ($word) => trim((string) $word),
                        (array) ($input['palabras_bloqueadas'] ?? []),
                    ), fn (string $word) => $word !== '')),
                    'avisar_quejas' => (bool) ($input['avisar_quejas'] ?? true),
                    'clasificaciones' => $classes,
                ],
            ]),
        ]);

        $this->property = $property->fresh();
    }

    protected function property(): ?Property
    {
        return $this->property ??= Property::query()->first();
    }
}
