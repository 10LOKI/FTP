<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DecideCustomerIdentityChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('CUSTOMER_APPROVE') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('request_id'))) {
            $this->merge(['request_id' => strtolower($this->input('request_id'))]);
        }
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['APPROUVE', 'REFUSE'])],
            'version' => ['required', 'integer', 'min:1'],
            'profile_version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }

    /** @return array<Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), [...array_keys($this->rules()), '_token']) as $field) {
                $validator->errors()->add($field, 'This field is not allowed for an identity-change operation.');
            }
        }];
    }
}
