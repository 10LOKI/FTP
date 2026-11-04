<?php

namespace App\Http\Requests;

use App\Rules\PasswordHashInput;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class RegisterCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['PARTICULIER', 'ENTREPRISE'])],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::defaults(), new PasswordHashInput],
            'address' => ['required', 'string', 'max:1000'],
            'city' => ['required', 'string', 'max:255'],
            'country' => ['required', 'string', 'max:255'],
            'company_name' => ['required_if:type,ENTREPRISE', 'missing_unless:type,ENTREPRISE', 'string', 'max:255'],
            'responsible_name' => ['required_if:type,ENTREPRISE', 'missing_unless:type,ENTREPRISE', 'string', 'max:255'],
        ];
    }

    /** @return array<Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowedFields = [...array_keys($this->rules()), '_token'];

            foreach (array_diff(array_keys($this->all()), $allowedFields) as $field) {
                $validator->errors()->add($field, 'This field is not allowed during customer registration.');
            }
        }];
    }
}
