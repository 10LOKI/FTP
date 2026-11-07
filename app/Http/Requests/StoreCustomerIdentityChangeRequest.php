<?php

namespace App\Http\Requests;

use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCustomerIdentityChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
        if (is_string($this->input('request_id'))) {
            $this->merge(['request_id' => strtolower($this->input('request_id'))]);
        }
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'request_id' => ['required', 'uuid'],
            'version' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user()->id)],
            'type' => ['required', Rule::in(['PARTICULIER', 'ENTREPRISE'])],
            'company_name' => ['required_if:type,ENTREPRISE', 'missing_unless:type,ENTREPRISE', 'string', 'max:255'],
            'responsible_name' => ['required_if:type,ENTREPRISE', 'missing_unless:type,ENTREPRISE', 'string', 'max:255'],
        ];
    }

    /** @return array<Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (is_string($this->input('email')) && User::whereRaw('LOWER(email) = ?', [$this->input('email')])
                ->whereKeyNot($this->user()->id)->exists()) {
                $validator->errors()->add('email', 'This email is already in use.');
            }
            foreach (array_diff(array_keys($this->all()), [...array_keys($this->rules()), '_token']) as $field) {
                $validator->errors()->add($field, 'This field is not allowed for an identity-change operation.');
            }
        }];
    }
}
