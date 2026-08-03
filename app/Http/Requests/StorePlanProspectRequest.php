<?php

namespace App\Http\Requests;

use App\Models\Central\Plan;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePlanProspectRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'hotel_name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email:rfc', 'max:160'],
            'phone' => ['required', 'string', 'max:40'],
            'rooms' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'plan_key' => [
                'required',
                'string',
                Rule::exists(Plan::class, 'key')->where('active', true),
            ],
            'message' => ['nullable', 'string', 'max:1000'],
            'source' => ['nullable', 'string', 'max:80'],
            'privacy' => ['accepted'],
            'website' => ['nullable', 'prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Cuéntanos tu nombre.',
            'hotel_name.required' => 'Indica el nombre de tu hotel.',
            'email.required' => 'Necesitamos un correo para contactarte.',
            'email.email' => 'Escribe un correo válido.',
            'phone.required' => 'Necesitamos un teléfono o WhatsApp.',
            'plan_key.exists' => 'El plan seleccionado ya no está disponible.',
            'privacy.accepted' => 'Debes aceptar el aviso de privacidad.',
        ];
    }
}
