<?php

namespace App\Http\Requests;

use App\Rules\DecimalPrice;
use App\Services\PriceAmount;
use App\Services\PromotionWindow;
use Closure;
use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class SavePromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('PROMOTION_MANAGE') ?? false;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::in(['fixed_price', 'percentage'])],
            'value' => ['required', new DecimalPrice],
            'starts_at' => ['required', 'string', 'max:25'], 'ends_at' => ['required', 'string', 'max:25'],
            'timezone' => ['required', Rule::in(DateTimeZone::listIdentifiers())],
            'state' => ['required', Rule::in(['draft', 'enabled', 'disabled'])],
            ...($this->isMethod('POST') ? ['product_id' => ['required', 'integer', 'exists:products,id']]
                : ['expected_version' => ['required', 'integer', 'min:1', 'max:2147483647']]),
        ];
    }

    /** @return array<Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), [...array_keys($this->rules()), '_token']) as $field) {
                $validator->errors()->add($field, 'Ce champ ne peut pas être défini pour une promotion.');
            }
            if ($this->isMethod('POST') && ! Str::isUuid((string) $this->header('Idempotency-Key'))) {
                $validator->errors()->add('idempotency_key', 'Un en-tête Idempotency-Key UUID est requis.');
            }
            if (! $validator->errors()->has('value') && $this->input('kind') === 'percentage') {
                $points = PriceAmount::minorUnits($this->input('value'));
                if ($points < 1 || $points > 10000) {
                    $validator->errors()->add('value', 'La remise doit être comprise entre 0.01 et 100.00 %.');
                }
            }
        }];
    }

    /** @return array<string, mixed> */
    public function promotionValues(): array
    {
        $values = $this->safe()->except(['product_id', 'expected_version']);
        $values['starts_at'] = PromotionWindow::parse($values['starts_at'], $values['timezone'], 'starts_at');
        $values['ends_at'] = PromotionWindow::parse($values['ends_at'], $values['timezone'], 'ends_at');
        if ($values['ends_at']->lessThanOrEqualTo($values['starts_at'])) {
            throw ValidationException::withMessages(['ends_at' => 'La fin doit être postérieure au début.']);
        }
        $values['value'] = PriceAmount::decimal(PriceAmount::minorUnits($values['value']));

        return $values;
    }
}
