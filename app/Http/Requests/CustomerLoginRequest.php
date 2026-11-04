<?php

namespace App\Http\Requests;

use App\Rules\PasswordHashInput;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CustomerLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:4096', new PasswordHashInput],
        ];
    }

    /** @return array<Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['identifier', 'password', '_token']) as $field) {
                $validator->errors()->add($field, 'This field is not allowed during customer login.');
            }
        }];
    }
}
