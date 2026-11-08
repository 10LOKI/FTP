<?php

namespace App\Http\Requests;

use App\Services\WarehouseStock;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StockMutationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permission = WarehouseStock::PERMISSIONS[$this->route('action')] ?? null;
        if ($permission === null) {
            return false;
        }
        app(WarehouseStock::class)->authorize($this->user(), $permission);

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $version = ['required', 'integer', 'min:0', 'max:2147483646'];
        $warehouse = ['required', 'uuid', 'exists:warehouses,id'];
        $text = ['required', 'string', 'max:255'];
        $base = ['warehouse_id' => $warehouse];
        $action = $this->route('action');
        if ($action === 'warehouse') {
            return ['warehouse_id' => ['sometimes', 'uuid', 'exists:warehouses,id'], 'expected_version' => ['required_with:warehouse_id', 'integer', 'min:1', 'max:2147483646'], 'code' => ['required', 'string', 'max:40', 'regex:/\A[A-Za-z0-9_.-]+\z/'], 'name' => ['required', 'string', 'max:120'], 'active' => ['required', 'boolean']];
        }
        if ($action === 'location') {
            return [...$base, 'code' => ['required', 'string', 'max:40'], 'aisle' => ['nullable', 'string', 'max:60'], 'shelf' => ['nullable', 'string', 'max:60'], 'level' => ['nullable', 'string', 'max:60']];
        }
        $base += ['product_id' => ['required', 'integer', 'exists:products,id'], 'expected_version' => $version];
        if ($action === 'configure') {
            return [...$base, 'location_id' => ['present', 'nullable', 'uuid', 'exists:warehouse_locations,id'], 'threshold_quantity' => ['present', 'nullable', 'integer', 'min:0', 'max:2147483647'], 'threshold_basis' => ['present', 'nullable', Rule::in(['available'])]];
        }
        $base += ['reason' => $text, 'source_reference' => ['required', 'string', 'max:120'], 'quantity' => ['required', 'integer', $action === 'adjust' ? 'min:-2147483647' : 'min:1', 'max:2147483647', 'not_in:0']];

        return match ($action) {
            'receive' => [...$base, 'bucket' => ['required', Rule::in(['physical', 'control'])]],
            'adjust' => [...$base, 'bucket' => ['required', Rule::in(['physical', 'control', 'rejected'])]],
            'inspect' => [...$base, 'destination' => ['required', Rule::in(['physical', 'rejected'])]],
            'transfer' => [...$base, 'destination_warehouse_id' => [...$warehouse, 'different:warehouse_id'], 'destination_expected_version' => $version],
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
            if (! Str::isUuid((string) $this->header('Idempotency-Key'))) {
                $validator->errors()->add('idempotency_key', 'Un en-tête Idempotency-Key UUID est requis.');
            }
            if ($this->route('action') === 'configure' && ($this->input('threshold_quantity') === null) !== ($this->input('threshold_basis') === null)) {
                $validator->errors()->add('threshold_basis', 'Précisez ensemble le seuil et sa base, ou désactivez-les ensemble.');
            }
        }];
    }
}
