<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DtrRequest extends FormRequest
{
    public function authorize(): bool
    {
        return filled(session('emp_data.emp_id'));
    }

    public function rules(): array
    {
        return [
            'month' => ['nullable', 'date_format:Y-m'],
        ];
    }

    public function month(): string
    {
        return $this->validated('month') ?? now()->format('Y-m');
    }
}