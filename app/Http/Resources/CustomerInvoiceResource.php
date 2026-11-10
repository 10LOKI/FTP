<?php

namespace App\Http\Resources;

use App\Services\InvoiceIssuer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerInvoiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return app(InvoiceIssuer::class)->present($this->resource);
    }
}
