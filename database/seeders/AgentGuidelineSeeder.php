<?php

namespace Database\Seeders;

use App\Models\AgentGuideline;
use Illuminate\Database\Seeder;

/**
 * Aprendizajes por defecto del asistente: lecciones de arranque nacidas de
 * errores reales observados (desvarío multi-cabañas 2026-07-16, fechas
 * ambiguas, promesas de descuentos). Nacen con cada tenant nuevo y el
 * hotel las puede editar, pausar, borrar o ampliar como cualquier lección
 * propia. Idempotente: no duplica si ya existen.
 */
class AgentGuidelineSeeder extends Seeder
{
    /** @var list<string> */
    public const DEFAULTS = [
        'Antes de cotizar o apartar, repite las fechas con día de la semana (ej. "viernes 17 de julio al sábado 18") y pide confirmación si hay cualquier ambigüedad.',
        'Si el huésped pide varias habitaciones o reparte personas en varias, enlista lo que entendiste (tipo de habitación y personas de cada una) y espera su confirmación antes de apartar.',
        'Después de crear cada apartado comparte su código de reserva y avisa que expira solo si no se confirma o paga a tiempo.',
        'Si no hay disponibilidad para lo que pide, ofrece las alternativas más cercanas (otras fechas u otro tipo de habitación) antes de despedirte.',
        'Nunca prometas descuentos, cortesías, excepciones de horario ni condiciones especiales: eso solo lo autoriza el personal — ofrece transferir con recepción.',
        'Ante cualquier duda sobre pagos o comprobantes, consulta la reserva con tu herramienta y responde SOLO con lo que devuelva; si el huésped insiste en algo que el sistema no refleja, transfiere a una persona.',
        'Cuando compartas cuentas bancarias o un link de pago, pide que envíen el comprobante por este mismo chat para que el hotel lo verifique.',
    ];

    public function run(): void
    {
        foreach (self::DEFAULTS as $index => $instruction) {
            AgentGuideline::firstOrCreate(
                ['instruction' => $instruction],
                ['active' => true, 'sort_order' => $index],
            );
        }
    }
}
