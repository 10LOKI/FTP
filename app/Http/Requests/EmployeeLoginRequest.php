<?php

namespace App\Http\Requests;

use App\Rules\PasswordHashInput;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class EmployeeLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:4096', new PasswordHashInput],
        ];
    }

    /** @return array<Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['email', 'password', '_token']) as $field) {
                $validator->errors()->add($field, 'This field is not allowed during employee login.');
            }
        }];
    }
}
