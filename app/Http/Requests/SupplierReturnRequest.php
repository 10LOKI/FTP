<?php

namespace App\Http\Requests;

use App\Services\SupplierReturns;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SupplierReturnRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $permission = match ($this->route('action')) {
            'supplier', 'purchase' => 'SUPPLIER_MANAGE',
            'credit', 'adjustment' => 'SUPPLIER_CREDIT_MANAGE',
            default => 'SUPPLIER_RETURN_MANAGE',
        };
        app(SupplierReturns::class)->authorize($this->user(), $permission);

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $money = ['required', 'decimal:0,2', 'min:0', 'max:9999999999999999999999.99'];

        return match ($this->route('action')) {
            'supplier' => ['code' => 'required|string|max:40|unique:suppliers,code', 'name' => 'required|string|max:160', 'opening_balance' => $money, 'justification' => 'required|string|max:255'],
            'purchase' => ['supplier_id' => 'required|uuid|exists:suppliers,id', 'warehouse_id' => 'required|uuid|exists:warehouses,id', 'reference' => 'required|string|max:120', 'received_on' => 'required|date', 'lines' => 'required|array|min:1|max:100', 'lines.*.product_id' => 'required|integer|exists:products,id', 'lines.*.quantity' => 'required|integer|min:1', 'lines.*.net_unit_cost' => $money, 'lines.*.lot_reference' => 'nullable|string|max:120'],
            'request' => ['supplier_id' => 'required|uuid|exists:suppliers,id', 'purchase_id' => 'required|uuid|exists:supplier_purchases,id', 'warehouse_id' => 'required|uuid|exists:warehouses,id', 'reason' => 'required|string|max:1000', 'lines' => 'required|array|min:1|max:100', 'lines.*.purchase_line_id' => 'required|uuid|exists:supplier_purchase_lines,id', 'lines.*.quantity' => 'required|integer|min:1'],
            'credit' => ['decision' => ['required', Rule::in(['accepted', 'refused'])], 'amount' => $money, 'evidence' => 'required|string|max:1000'],
            'adjustment' => ['supplier_id' => 'required|uuid|exists:suppliers,id', 'amount_delta' => ['required', 'decimal:0,2', 'not_in:0'], 'justification' => 'required|string|max:255'],
            default => [],
        };
    }
}
