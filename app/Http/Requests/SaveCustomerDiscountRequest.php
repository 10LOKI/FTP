<?php

namespace App\Http\Requests;

use App\Rules\DecimalPrice;
use App\Services\PriceAmount;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveCustomerDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('CUSTOMER_TERMS_MANAGE') ?? false;
    }

    public function rules(): array
    {
        return ['percentage' => ['required', new DecimalPrice], 'expected_version' => ['required', 'integer', 'min:0', 'max:2147483647']];
    }

    /** @return array<Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['percentage', 'expected_version', '_token']) as $field) {
                $validator->errors()->add($field, 'Ce champ ne peut pas être défini pour une remise.');
            }
            if (! $validator->errors()->has('percentage') && PriceAmount::minorUnits($this->input('percentage')) > 10000) {
                $validator->errors()->add('percentage', 'La remise ne peut pas dépasser 100.00 %.');
            }
        }];
    }
}
