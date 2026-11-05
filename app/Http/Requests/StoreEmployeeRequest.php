<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('USER_CREATE') && $user->can('USER_ASSIGN_ROLE');
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'role_ids' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'role_ids.*' => ['bail', 'required', 'uuid', 'distinct:ignore_case', Rule::exists('roles', 'id')->where('is_protected', false)],
        ];
    }

    /** @return array<Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['email', 'role_ids', '_token']) as $field) {
                $validator->errors()->add($field, 'This field is not allowed when creating an employee account.');
            }
        }];
    }
}
