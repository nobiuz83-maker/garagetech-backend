<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListClientVehiclesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'perPage' => ['sometimes', 'integer', 'between:1,100'],
            'sort' => ['sometimes', 'in:createdAt,firstName,lastName,make,model,year'],
            'direction' => ['sometimes', 'in:asc,desc'],
            'clientId' => ['sometimes', 'uuid'],
            'make' => ['sometimes', 'string', 'max:255'],
            'year' => ['sometimes', 'integer', 'between:1886,'.(now()->year + 1)],
        ];
    }
}
