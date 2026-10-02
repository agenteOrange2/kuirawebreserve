<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class FilterPlanProspectsRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'in:all,new,contacted,qualified,won,lost,open'],
            'plan' => ['nullable', 'string', 'max:40'],
            'source' => ['nullable', 'string', 'in:landing,evento'],
            'docs' => ['nullable', 'string', 'in:pending,sent'],
        ];
    }
}
