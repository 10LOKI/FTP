<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ResetEmployeeAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('USER_RESET_ACCESS') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('request_id'))) {
            $this->merge(['request_id' => strtolower($this->input('request_id'))]);
        }
    }

    public function rules(): array
    {
        return [
            'request_id' => ['required', 'uuid'],
            'expected_access_version' => ['required', 'integer', 'min:0'],
        ];
    }

    /** @return array<Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['request_id', 'expected_access_version', '_token']) as $field) {
                $validator->errors()->add($field, 'This field is not allowed when reissuing employee access.');
            }
        }];
    }
}
