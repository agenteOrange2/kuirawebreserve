<?php

namespace App\Services\Integration;

use App\Models\RoomType;
use App\Models\SiteImportSuggestion;
use App\Services\Agent\AgentBrain;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Agente importador (spec-integracion-sitios §4): descarga la página de
 * habitaciones del hotel, un LLM (la misma cadena de proveedores del bot)
 * extrae la ficha de cada habitación y TODO cae como sugerencias
 * pendientes de validación humana. Nunca extrae precios: el precio nace
 * en las tarifas (precio único, E2).
 */
class SiteImporter
{
    public function __construct(protected AgentBrain $brain) {}

    /**
     * Analiza la URL y crea sugerencias pendientes. Devuelve cuántas.
     */
    public function import(string $url, ?int $userId = null): int
    {
        $text = $this->fetchText($url);
        $rooms = $this->extractRooms($text);

        if ($rooms === []) {
            return 0;
        }

        // Re-analizar la misma URL reemplaza lo pendiente de esa fuente
        // (no se acumulan duplicados entre corridas).
        SiteImportSuggestion::query()
            ->where('source_url', $url)
            ->where('status', SiteImportSuggestion::STATUS_PENDING)
            ->delete();

        $created = 0;

        foreach ($rooms as $room) {
            $match = $this->matchType($room['name']);

            SiteImportSuggestion::create([
                'source_url' => $url,
                'room_type_id' => $match?->id,
                'action' => $match ? 'update' : 'create',
                'payload' => $room,
                'created_by' => $userId,
            ]);

            $created++;
        }

        return $created;
    }

    /**
     * Descarga la página y la reduce a texto plano acotado (el LLM no
     * necesita el HTML completo).
     */
    public function fetchText(string $url): string
    {
        $response = Http::timeout(15)
            ->withHeaders(['User-Agent' => 'KuiraWebReserve-Importer/1.0'])
            ->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException("No se pudo leer la página (HTTP {$response->status()}).");
        }

        return self::htmlToText((string) $response->body());
    }

    public static function htmlToText(string $html): string
    {
        // Fuera scripts/estilos; alt de imágenes suele traer nombres útiles.
        $html = preg_replace('/<(script|style|noscript|svg)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? '';
        $html = preg_replace('/<img[^>]*alt="([^"]*)"[^>]*>/i', ' $1 ', $html) ?? '';
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? '';
        $text = preg_replace('/\n{3,}/', "\n\n", str_replace(["\r\n", "\r"], "\n", $text)) ?? '';

        return Str::limit(trim($text), 15000, '');
    }

    /**
     * Extrae la ficha de habitaciones vía LLM. Prueba la cadena de
     * proveedores (BYOK → plataforma) igual que el bot.
     *
     * @return list<array{name: string, description: ?string, capacity: ?int, amenities: list<string>, price: ?float}>
     */
    public function extractRooms(string $text): array
    {
        $prompt = <<<PROMPT
        Analiza el siguiente contenido de la página web de un hotel/motel y extrae la ficha de CADA tipo de habitación que se ofrezca.

        Responde ÚNICAMENTE un arreglo JSON válido (sin explicación, sin markdown) donde cada elemento tenga exactamente estas claves:
        - "name": nombre del tipo de habitación (string)
        - "description": descripción corta comercial en español, máximo 300 caracteres (string o null)
        - "capacity": número de personas que caben (entero o null si no se menciona)
        - "amenities": lista de amenidades mencionadas (strings cortos en español, ej. "Wifi", "Aire acondicionado", "Jacuzzi")
        - "price": SOLO si el contenido muestra un precio claro y específico para ESA habitación (número, sin símbolos de moneda ni comas; null si no aparece, es ambiguo, o es un rango)

        Reglas estrictas:
        - No inventes datos: si algo no aparece en el contenido, usa null o lista vacía.
        - El precio es solo una SUGERENCIA que un humano revisará y podrá corregir antes de usarse — mejor null que adivinar.
        - Si no hay habitaciones en el contenido, responde [].

        Contenido de la página:
        ---
        {$text}
        PROMPT;

        foreach ($this->brain->providers() as $provider) {
            try {
                $response = $this->brain->run($provider, fn ($request) => $request
                    ->withSystemPrompt('Eres un extractor de datos meticuloso. Respondes solo JSON válido.')
                    ->withPrompt($prompt)
                    ->withMaxTokens(4000));

                $rooms = self::parseRoomsJson($response->text);

                if ($rooms !== null) {
                    return $rooms;
                }
            } catch (\Throwable) {
                continue; // siguiente proveedor de la cadena
            }
        }

        throw new \RuntimeException('Ningún proveedor de IA pudo analizar la página. Revisa el Asistente IA.');
    }

    /**
     * Parsea y sanea la respuesta del LLM. Null = respuesta inservible
     * (se intenta el siguiente proveedor); [] = página sin habitaciones.
     *
     * @return list<array{name: string, description: ?string, capacity: ?int, amenities: list<string>, price: ?float}>|null
     */
    public static function parseRoomsJson(string $raw): ?array
    {
        // Tolerar cercos de código y texto alrededor del arreglo.
        $raw = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($raw)) ?? '');

        if (! str_starts_with($raw, '[')) {
            $start = strpos($raw, '[');
            $end = strrpos($raw, ']');
            if ($start === false || $end === false || $end < $start) {
                return null;
            }
            $raw = substr($raw, $start, $end - $start + 1);
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return null;
        }

        $rooms = [];

        foreach ($decoded as $item) {
            if (! is_array($item) || empty($item['name']) || ! is_string($item['name'])) {
                continue;
            }

            $rooms[] = [
                'name' => Str::limit(trim($item['name']), 120, ''),
                'description' => isset($item['description']) && is_string($item['description'])
                    ? Str::limit(trim($item['description']), 500, '')
                    : null,
                'capacity' => isset($item['capacity']) && is_numeric($item['capacity'])
                    ? max(1, min(20, (int) $item['capacity']))
                    : null,
                'amenities' => collect(is_array($item['amenities'] ?? null) ? $item['amenities'] : [])
                    ->filter(fn ($a) => is_string($a) && trim($a) !== '')
                    ->map(fn (string $a) => Str::limit(trim($a), 50, ''))
                    ->unique(fn (string $a) => mb_strtolower($a))
                    ->values()
                    ->all(),
                // Solo sugerencia: el humano la confirma o corrige antes de
                // que se cree ninguna tarifa (spec-integracion-sitios §4).
                'price' => isset($item['price']) && is_numeric($item['price']) && (float) $item['price'] > 0
                    ? round((float) $item['price'], 2)
                    : null,
            ];
        }

        return $rooms;
    }

    /**
     * Match tolerante por nombre contra los tipos existentes (acentos y
     * mayúsculas no cuentan). Primero igualdad exacta; si no, el candidato
     * MÁS ESPECÍFICO (nombre normalizado más largo) entre los que se
     * contienen — así "Master Junior VIP" empata al tipo VIP y no al
     * "Master Junior" a secas (bug real de la primera corrida).
     */
    public function matchType(string $name): ?RoomType
    {
        $normalized = self::normalizeName($name);
        $types = RoomType::query()->get();

        $exact = $types->first(fn (RoomType $type) => self::normalizeName($type->name) === $normalized);

        if ($exact) {
            return $exact;
        }

        return $types
            ->filter(function (RoomType $type) use ($normalized) {
                $existing = self::normalizeName($type->name);

                return str_contains($existing, $normalized) || str_contains($normalized, $existing);
            })
            ->sortByDesc(fn (RoomType $type) => mb_strlen(self::normalizeName($type->name)))
            ->first();
    }

    public static function normalizeName(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = strtr($name, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);

        // Palabras genéricas fuera para comparar lo distintivo.
        return trim(preg_replace('/\b(habitacion|habitaciones|cuarto|suite de|room)\b|\s+/', ' ', $name) ?? '');
    }
}
