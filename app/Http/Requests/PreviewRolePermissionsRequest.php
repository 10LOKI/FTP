<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PreviewRolePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('ROLE_MANAGE') ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'permission_codes' => ['present', 'array', 'list', 'max:1000'],
            'permission_codes.*' => ['required', 'string', 'max:255', 'regex:/^[A-Z][A-Z0-9_]*$/', 'distinct:ignore_case', Rule::exists('permissions', 'code')],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** @return array<Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), [...array_keys($this->rules()), '_token']) as $field) {
                $validator->errors()->add($field, 'This field is not allowed for this role operation.');
            }
        }];
    }
}
