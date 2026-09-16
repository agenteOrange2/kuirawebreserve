<?php
/**
 * Compara MiniMax-M2 (el de hoy) contra MiniMax-M2.7 (el que sí cachea)
 * sobre conversaciones REALES, por el camino de SOLO LECTURA (suggest):
 * no crea apartados ni transfiere nada.
 */
$plat = App\Models\Central\PlatformAiProvider::where('provider', 'minimax')->first();
tenancy()->initialize(App\Models\Tenant::find('cabanasrealdelasierra'));

$brain = app(App\Services\Agent\AgentBrain::class);
$rs = new ReflectionClass($brain);
$sysM = $rs->getMethod('systemPrompt'); $sysM->setAccessible(true);
$histM = $rs->getMethod('history'); $histM->setAccessible(true);
$toolM = $rs->getMethod('toolset'); $toolM->setAccessible(true);
$addM = $rs->getMethod('copilotAddendum'); $addM->setAccessible(true);

$modelos = ['MiniMax-M2', 'MiniMax-M2.7'];
$proveedor = function (string $modelo) use ($plat) {
    $p = $plat->asRuntimeProvider();
    $p->model = $modelo;
    return $p;
};

// Conversaciones con conversación real: al menos 4 mensajes y que el último
// sea del huésped (es justo lo que el bot tendría que contestar).
$convs = App\Models\Conversation::query()
    ->withCount('messages')
    ->having('messages_count', '>=', 4)
    ->latest('id')
    ->take((int) ($argv[1] ?? 12))
    ->get();

$salida = [];
foreach ($convs as $conv) {
    $ultimo = $conv->messages()->latest('id')->first();
    $pregunta = $conv->messages()->where('direction', 'in')->latest('id')->first();
    if (! $pregunta) { continue; }

    $fila = ['conv' => $conv->id, 'pregunta' => mb_substr($pregunta->body ?? '', 0, 200)];

    // El historial DEBE terminar en el mensaje del huesped: si termina en
    // la respuesta que el bot ya dio, el modelo continua la charla en vez
    // de contestar la pregunta, y la comparacion mide otra cosa.
    $hist = $histM->invoke($brain, $conv);
    $userClass = 'Prism\\Prism\\ValueObjects\\Messages\\UserMessage';
    while ($hist !== [] && ! (end($hist) instanceof $userClass)) { array_pop($hist); }
    if ($hist === []) { continue; }

    foreach ($modelos as $modelo) {
        $handoff = false;
        try {
            $t0 = microtime(true);
            $resp = $brain->run($proveedor($modelo), fn ($r) => $r
                ->withSystemPrompt($sysM->invoke($brain, $conv).$addM->invoke($brain))
                ->withMessages($hist)
                ->withTools($toolM->invokeArgs($brain, [&$handoff, $conv, true]))
                ->withMaxSteps(4));
            $fila[$modelo] = [
                'texto' => trim($resp->text),
                'in' => $resp->usage->promptTokens,
                'cache' => $resp->usage->cacheReadInputTokens,
                'out' => $resp->usage->completionTokens,
                'ms' => (int) round((microtime(true) - $t0) * 1000),
            ];
        } catch (Throwable $e) {
            $fila[$modelo] = ['texto' => 'ERROR: '.$e->getMessage(), 'in' => 0, 'cache' => 0, 'out' => 0, 'ms' => 0];
        }
    }
    $salida[] = $fila;
    echo '.';
}
file_put_contents(getenv('SALIDA') ?: '/tmp/comparacion.json', json_encode($salida, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo PHP_EOL.'listo: '.count($salida).' conversaciones'.PHP_EOL;
