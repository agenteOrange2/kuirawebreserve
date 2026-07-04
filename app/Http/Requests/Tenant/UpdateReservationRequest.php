<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rate_plan_id' => ['required', 'exists:rate_plans,id'],
            'room_id' => ['nullable', 'exists:rooms,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'guest_id' => ['nullable', 'exists:guests,id'],
            'guest_name' => ['nullable', 'string', 'max:255'],
            'guest_phone' => ['nullable', 'string', 'max:30'],
            'guest_email' => ['nullable', 'email', 'max:255'],
            'num_people' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'adults' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'children' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'vehicle_plate' => ['nullable', 'string', 'max:20'],
            'vehicle_desc' => ['nullable', 'string', 'max:100'],
            'eta' => ['nullable', 'date_format:H:i'],
            'source_channel' => ['sometimes', Rule::in(['front_desk', 'phone', 'web', 'whatsapp', 'walk_in'])],
            'deposit_amount' => ['sometimes', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'guest_notes' => ['nullable', 'string'],
        ];
    }
}
