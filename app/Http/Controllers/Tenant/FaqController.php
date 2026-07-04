<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Faq;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD de preguntas frecuentes (se administran en /ajustes). Las FAQs
 * activas alimentan el contexto del asistente IA vía get_policies().
 */
class FaqController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['sort_order'] = (int) (Faq::max('sort_order') + 1);

        return response()->json($this->serialize(Faq::create($data)), 201);
    }

    public function update(Request $request, Faq $faq): JsonResponse
    {
        $faq->update($this->validated($request));

        return response()->json($this->serialize($faq->refresh()));
    }

    public function destroy(Faq $faq): JsonResponse
    {
        $faq->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * @return array{question: string, answer: string, active: bool}
     */
    protected function validated(Request $request): array
    {
        return $request->validate([
            'question' => ['required', 'string', 'max:255'],
            'answer' => ['required', 'string', 'max:2000'],
            'active' => ['boolean'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function serialize(Faq $faq): array
    {
        return $faq->only(['id', 'question', 'answer', 'active', 'sort_order']);
    }
}
