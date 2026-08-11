<?php

namespace App\Http\Requests;

use App\Models\Central\PlanProspect;
use App\Models\Central\ProspectDocument;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProspectDocumentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'service' => [
                'required',
                'string',
                Rule::in([...array_keys(PlanProspect::SERVICES), ProspectDocument::GENERAL_SERVICE]),
            ],
            'file' => ['required', 'file', 'mimes:pdf', 'max:10240'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Ponle un título al documento.',
            'service.in' => 'El servicio elegido no es válido.',
            'file.required' => 'Adjunta el archivo PDF.',
            'file.mimes' => 'Solo se aceptan archivos PDF.',
            'file.max' => 'El PDF no puede pesar más de 10 MB.',
        ];
    }
}
