<?php

namespace App\Http\Requests;

use App\Models\Central\PlanProspect;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEventProspectRequest extends FormRequest
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
            'phone_code' => ['required', 'string', 'regex:/^\+?\d{1,4}$/'],
            'phone' => ['required', 'string', 'max:40'],
            'has_whatsapp' => ['boolean'],
            'services' => ['required', 'array', 'min:1'],
            'services.*' => ['string', Rule::in(array_keys(PlanProspect::SERVICES))],
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
            'hotel_name.required' => 'Indica el nombre de tu hotel o empresa.',
            'email.required' => 'Necesitamos un correo para enviarte la información.',
            'email.email' => 'Escribe un correo válido.',
            'phone_code.required' => 'Elige la lada de tu país.',
            'phone_code.regex' => 'La lada debe ser un código como +52.',
            'phone.required' => 'Necesitamos un teléfono para contactarte.',
            'services.required' => 'Elige al menos un servicio.',
            'services.min' => 'Elige al menos un servicio.',
            'services.*.in' => 'Alguno de los servicios elegidos no está disponible.',
            'privacy.accepted' => 'Debes aceptar el aviso de privacidad.',
        ];
    }
}
