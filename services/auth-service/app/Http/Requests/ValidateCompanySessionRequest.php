<?php

namespace App\Http\Requests;

use App\Auth\SecurityFailure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ValidateCompanySessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $input = $this->isMethod('GET') ? $this->query() : $this->json()->all();
        if (count($input) !== 2 || array_diff(['requiredPermissions', 'purpose'], array_keys($input)) !== []) {
            throw new SecurityFailure('VALIDATION_FAILED', 422);
        }
        if ($this->isMethod('GET') && is_string($input['requiredPermissions'])) {
            $this->merge(['requiredPermissions' => explode(',', $input['requiredPermissions'])]);
        }
    }

    public function rules(): array
    {
        return [
            'requiredPermissions' => ['required', 'array', 'list', 'min:1', 'max:8'],
            'requiredPermissions.*' => ['required', 'string', 'distinct:strict', Rule::in(array_keys(config('company_permissions.permissions')))],
            'purpose' => ['required', 'string', Rule::in(['resource.read', 'resource.write', 'relationship.registration', 'stock.command', 'fiscal.policy.use'])],
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new SecurityFailure('VALIDATION_FAILED', 422);
    }
}
