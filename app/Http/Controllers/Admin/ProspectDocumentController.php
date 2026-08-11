<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProspectDocumentRequest;
use App\Http\Requests\UpdateProspectDocumentRequest;
use App\Models\Central\PlanProspect;
use App\Models\Central\ProspectDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Documentos comerciales por servicio: son los PDF que se adjuntan al
 * correo del registro por QR y se comparten por WhatsApp. Reemplazar el
 * archivo conserva el uuid, así los links ya compartidos siguen vivos.
 */
class ProspectDocumentController extends Controller
{
    public function index(): Response
    {
        $services = [
            ...PlanProspect::SERVICES,
            ProspectDocument::GENERAL_SERVICE => 'General (todos los servicios)',
        ];

        return Inertia::render('admin/prospects/Documents', [
            'documents' => ProspectDocument::query()
                ->ordered()
                ->get()
                ->map(fn (ProspectDocument $document) => [
                    'uuid' => $document->uuid,
                    'title' => $document->title,
                    'service' => $document->service,
                    'service_label' => $services[$document->service] ?? $document->service,
                    'original_name' => $document->original_name,
                    'size' => $document->size,
                    'sort' => $document->sort,
                    'url' => $document->publicUrl(),
                    'updated_at' => $document->updated_at?->format('d/m/Y H:i'),
                ]),
            'services' => collect($services)
                ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
                ->values(),
        ]);
    }

    public function store(StoreProspectDocumentRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $file = $request->file('file');

        ProspectDocument::query()->create([
            'title' => $data['title'],
            'service' => $data['service'],
            'path' => $file->store('prospect-docs', 'local'),
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType() ?? 'application/pdf',
            'size' => $file->getSize(),
            'sort' => $data['sort'] ?? 0,
        ]);

        return back()->with('success', 'Documento subido.');
    }

    public function update(UpdateProspectDocumentRequest $request, ProspectDocument $prospectDocument): RedirectResponse
    {
        $data = $request->validated();

        $payload = [
            'title' => $data['title'],
            'service' => $data['service'],
            'sort' => $data['sort'] ?? $prospectDocument->sort,
        ];

        if ($file = $request->file('file')) {
            $oldPath = $prospectDocument->path;
            $payload += [
                'path' => $file->store('prospect-docs', 'local'),
                'original_name' => $file->getClientOriginalName(),
                'mime' => $file->getMimeType() ?? 'application/pdf',
                'size' => $file->getSize(),
            ];
            Storage::disk('local')->delete($oldPath);
        }

        $prospectDocument->update($payload);

        return back()->with('success', 'Documento actualizado.');
    }

    public function destroy(ProspectDocument $prospectDocument): RedirectResponse
    {
        Storage::disk('local')->delete($prospectDocument->path);
        $prospectDocument->delete();

        return back()->with('success', 'Documento eliminado.');
    }
}
