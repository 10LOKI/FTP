<?php

namespace App\Http\Requests;

use App\Rules\ReferenceCode;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ChangeOriginalReferenceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('REFERENCE_MANAGE') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reference' => ['required', 'string', 'max:255', new ReferenceCode],
            'expected_reference' => ['required', 'string', 'max:255'],
        ];
    }

    /** @return array<Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['reference', 'expected_reference', '_token']) as $field) {
                $validator->errors()->add($field, 'Ce champ ne peut pas être défini pour une référence.');
            }
        }];
    }
}
