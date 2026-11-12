<?php

namespace App\Http\Requests;

use App\Services\CustomerFinance;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DecideCustomerApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'payment_terms' => [Rule::requiredIf(fn (): bool => config('receivables.enabled') === true && $this->input('decision') === 'APPROUVE'), 'array:mode,label', 'prohibited_unless:decision,APPROUVE'],
            'payment_terms.mode' => ['required_with:payment_terms', Rule::in(CustomerFinance::MODES)],
            'payment_terms.label' => ['required_with:payment_terms', 'string', 'max:255', 'regex:/\S/u'],
            'decision' => ['required', Rule::in(['APPROUVE', 'REFUSE'])],
            'version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:2000'],
            'customer_code' => ['sometimes', 'required', 'string', 'max:255', 'prohibited_unless:decision,APPROUVE'],
        ];
    }

    /** @return array<Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), [...array_keys($this->rules()), '_token']) as $field) {
                $validator->errors()->add($field, 'This field is not allowed in an approval decision.');
            }
        }];
    }
}
