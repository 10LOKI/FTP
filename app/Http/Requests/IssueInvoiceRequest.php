<?php

namespace App\Http\Requests;

use App\Services\InvoiceIssuer;
use Illuminate\Validation\Validator;

class IssueInvoiceRequest extends InvoicePreviewRequest
{
    public function authorize(): bool
    {
        app(InvoiceIssuer::class)->authorize($this->user());

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'request_key' => ['required', 'uuid'],
            'issuance_token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
        ]);
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['customer_id', 'lines', 'request_key', 'issuance_token']) as $field) {
                $validator->errors()->add($field, 'Champ non autorisé.');
            }
        }];
    }
}
