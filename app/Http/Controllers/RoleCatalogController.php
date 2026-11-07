<?php

namespace App\Http\Controllers;

use App\Http\Resources\RoleCatalogResource;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RoleCatalogController extends Controller
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        abort_if(array_diff(array_keys($request->query()), ['page', 'per_page']) !== [], 422, 'Unsupported role catalog filter.');

        return RoleCatalogResource::collection(
            Role::where('is_protected', false)->orderBy('label')->orderBy('id')
                ->paginate((int) ($filters['per_page'] ?? 20))->withQueryString(),
        );
    }
}

