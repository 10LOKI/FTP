<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateCustomerPhoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return ['phone' => ['required', 'string', 'max:50'], 'version' => ['required', 'integer', 'min:1']];
    }

    /** @return array<Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['phone', 'version', '_token']) as $field) {
                $validator->errors()->add($field, 'Only a phone change is allowed through this endpoint.');
            }
        }];
    }
}
