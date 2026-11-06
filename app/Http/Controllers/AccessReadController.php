<?php

namespace App\Http\Controllers;

use App\Http\Resources\AccessRoleResource;
use App\Models\Permission;
use App\Models\Role;
use App\Services\EffectivePermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AccessReadController extends Controller
{
    public function roles(Request $request): AnonymousResourceCollection
    {
        return AccessRoleResource::collection(
            Role::with('permissions')
                ->orderBy('label')->orderBy('id')->paginate($this->pageSize($request))->withQueryString(),
        );
    }

    public function permissions(Request $request): JsonResponse
    {
        return response()->json(Permission::query()->select(['id', 'code', 'module', 'label', 'is_protected'])
            ->orderBy('code')->paginate($this->pageSize($request))->withQueryString());
    }

    public function ownPermissions(Request $request, EffectivePermissions $permissions): JsonResponse
    {
        abort_if($request->query() !== [], 422, 'Unsupported effective permissions filter.');

        return response()->json(['data' => ['permissions' => $permissions->codes($request->user())]]);
    }

    private function pageSize(Request $request): int
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        abort_if(array_diff(array_keys($request->query()), ['page', 'per_page']) !== [], 422, 'Unsupported access catalog filter.');

        return (int) ($filters['per_page'] ?? 20);
    }
}
