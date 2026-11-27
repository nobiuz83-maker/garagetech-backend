<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InternalSessionValidationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->isMethod('POST')
            || (is_string($this->input('permission')) && str_ends_with($this->input('permission'), '.read'));
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'permission' => ['required', 'string', Rule::in(config('internal_auth.permissions'))],
        ];
    }
}
