<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RenameRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('ROLE_MANAGE') ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:255'],
            'version' => ['required', 'integer', 'min:1'],
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
