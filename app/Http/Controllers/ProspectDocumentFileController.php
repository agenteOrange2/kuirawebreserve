<?php

namespace App\Http\Controllers;

use App\Models\Central\ProspectDocument;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Sirve los documentos comerciales por su uuid (no enumerable): son
 * públicos a propósito para poder compartirlos por WhatsApp con wa.me,
 * que no permite adjuntar archivos.
 */
class ProspectDocumentFileController extends Controller
{
    public function __invoke(ProspectDocument $prospectDocument): BinaryFileResponse
    {
        $path = Storage::disk('local')->path($prospectDocument->path);

        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => $prospectDocument->mime,
            'Content-Disposition' => 'inline; filename="'.$prospectDocument->original_name.'"',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
