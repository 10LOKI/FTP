<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class SubmitCustomerOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('ORDER_SUBMIT_OWN');
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $rules = [
            'idempotency_key' => ['required', 'uuid'],
            'expected_version' => ['required', 'integer', 'min:0', 'max:4294967294'],
            'pricing_token' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            'contact' => ['required', 'array:name,email,phone'],
            'contact.name' => ['required', 'string', 'max:160'],
            'contact.email' => ['required', 'email', 'max:254'],
            'contact.phone' => ['required', 'string', 'max:40'],
            'customer_note' => ['nullable', 'string', 'max:2000'],
        ];
        foreach (['delivery_address', 'billing_address'] as $address) {
            $rules[$address] = ['required', 'array:address,city,postal_code,country'];
            $rules[$address.'.address'] = ['required', 'string', 'max:500'];
            $rules[$address.'.city'] = ['required', 'string', 'max:120'];
            $rules[$address.'.country'] = ['required', 'string', 'max:120'];
            $rules[$address.'.postal_code'] = ['nullable', 'string', 'max:30'];
        }
        foreach (['user_id', 'customer_id', 'status', 'number', 'lines', 'total', 'sale_price', 'net_purchase_price'] as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }
}
