<?php

namespace App\Http\Requests;

use App\Rules\PasswordHashInput;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class ReplaceTemporaryPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:4096', new PasswordHashInput],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', Password::defaults(), new PasswordHashInput],
            'password_confirmation' => ['required', 'string'],
        ];
    }

    /** @return array<Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), [...array_keys($this->rules()), '_token']) as $field) {
                $validator->errors()->add($field, 'This field is not allowed during password replacement.');
            }
        }];
    }
}
