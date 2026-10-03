<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // controller policies decide
    }

    public function rules(): array
    {
        return [
            'student_number' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9\-\/]+$/'],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before:today'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s()]+$/'],
            'program_id' => ['nullable', 'exists:programs,id'],
            'cohort' => ['nullable', 'string', 'max:30'],
        ];
    }

    public function messages(): array
    {
        return [
            'student_number.regex' => 'Use letters, numbers, hyphens or slashes only.',
            'contact_number.regex' => 'Use digits, spaces, +, - or brackets only.',
        ];
    }
}
