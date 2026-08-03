<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\WaitlistEntry;
use Illuminate\Http\JsonResponse;

/**
 * Seguimiento de la lista de espera desde el panel: marcar que la persona
 * ya reservó (convertida) o depurar entradas. El alta viene del wizard
 * público (WaitlistPublicController) y los avisos del WaitlistNotifier.
 */
class WaitlistEntryController extends Controller
{
    public function convert(WaitlistEntry $entry): JsonResponse
    {
        $entry->update(['status' => WaitlistEntry::STATUS_CONVERTED]);

        return response()->json([
            'id' => $entry->id,
            'status' => $entry->status,
            'status_label' => $entry->statusLabel(),
        ]);
    }

    public function destroy(WaitlistEntry $entry): JsonResponse
    {
        $entry->delete();

        return response()->json(status: 204);
    }
}
