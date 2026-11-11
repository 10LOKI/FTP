<?php

namespace App\Http\Requests;

use App\Services\InvoicePreview;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class InvoicePreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(InvoicePreview::class)->authorize($this->user());

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', 'min:1'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*' => ['required', 'array:delivery_line_id,quantity,availability_token'],
            'lines.*.delivery_line_id' => ['required', 'uuid', 'distinct:ignore_case'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'lines.*.availability_token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['customer_id', 'lines']) as $field) {
                $validator->errors()->add($field, 'Champ non autorisé.');
            }
        }];
    }
}
