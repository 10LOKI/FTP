<?php

namespace App\Http\Requests;

use App\Services\CustomerFinance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CustomerFinanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $money = ['required', 'string', 'regex:/\A[0-9]{1,22}\.[0-9]{2}\z/D', function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value) || preg_match('/\A[0-9]{1,22}\.[0-9]{2}\z/D', $value) !== 1 || bccomp($value, '0', 2) <= 0) {
                $fail('Le montant doit être positif avec deux décimales.');
            }
        }];
        $allocations = ['allocations' => ['required', 'array', 'min:1', 'max:100'],
            'allocations.*' => ['required', 'array:receivable_id,amount'],
            'allocations.*.receivable_id' => ['required', 'uuid', 'distinct:ignore_case'], 'allocations.*.amount' => $money];
        $reason = ['required', 'string', 'max:2000', 'regex:/\S/u'];
        $specific = match ($this->route('action')) {
            'terms' => ['version' => ['required', 'integer', 'min:1'], 'mode' => ['required', Rule::in(CustomerFinance::MODES)],
                'label' => ['required', 'string', 'max:255', 'regex:/\S/u'], 'reason' => $reason],
            'payment' => ['amount' => $money, 'payment_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
                'note' => ['nullable', 'string', 'max:2000'], 'mode' => ['required', Rule::in(CustomerFinance::MODES)],
                'reference' => ['nullable', 'string', 'max:255'], 'maturity_date' => ['nullable', 'date_format:Y-m-d'], ...$allocations],
            'collect' => ['payment_id' => ['required', 'uuid'], 'reference' => ['required', 'string', 'max:255', 'regex:/\S/u']],
            'reverse' => ['payment_id' => ['required', 'uuid'], 'reason' => $reason],
            'credit_apply' => ['credit_id' => ['required', 'uuid'], 'amount' => $money, 'reason' => $reason, ...$allocations],
            'refund' => ['credit_id' => ['required', 'uuid'], 'amount' => $money, 'reason' => $reason,
                'reference' => ['required', 'string', 'max:255', 'regex:/\S/u']],
            'unblock' => ['version' => ['required', 'integer', 'min:1'], 'reason' => $reason],
            default => [],
        };

        return ['request_key' => ['required', 'uuid'], ...$specific];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), [...array_keys($this->rules()), '_token']) as $field) {
                $validator->errors()->add($field, 'Champ non autorisé.');
            }
        }];
    }
}
