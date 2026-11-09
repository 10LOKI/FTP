<?php

namespace App\Http\Requests;

use App\Services\DeliveryPreparation;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DeliveryPreparationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permission = match ($this->route('action')) {
            'dispatch' => 'DELIVERY_DEPARTURE_CONFIRM',
            'transfer' => 'DELIVERY_TRANSFER',
            default => 'DELIVERY_PREPARE',
        };
        app(DeliveryPreparation::class)->authorize($this->user(), $permission);

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return match ($this->route('action')) {
            'claim' => ['order_id' => ['required', 'integer', 'exists:customer_orders,id'], 'warehouse_id' => ['required', 'uuid', 'exists:warehouses,id']],
            'scan' => ['code' => ['required', 'string', 'max:160'], 'mode' => ['required', Rule::in(['scanner', 'barcode', 'reference'])], 'line_id' => ['sometimes', 'nullable', 'uuid', 'exists:delivery_batch_lines,id']],
            'prepare' => ['confirm_partial' => ['required', 'boolean']],
            'transfer' => ['target_user_id' => ['required', 'integer', 'exists:users,id']],
            default => [],
        };
    }

    /** @return array<Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), [...array_keys($this->rules()), '_token']) as $field) {
                $validator->errors()->add($field, 'Champ non autorisé pour cette opération.');
            }
        }];
    }
}
