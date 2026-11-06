<?php

namespace App\Http\Requests;

class ConfirmRolePermissionsRequest extends PreviewRolePermissionsRequest
{
    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['page'], $rules['per_page']);
        $rules['confirmation_token'] = ['required', 'string', 'max:1048576'];

        return $rules;
    }
}
