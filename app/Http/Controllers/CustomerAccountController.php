<?php

namespace App\Http\Controllers;

use App\Http\Resources\CustomerAccountResource;
use App\Models\CustomerProfile;
use Illuminate\Http\Request;

class CustomerAccountController extends Controller
{
    public function __invoke(Request $request): CustomerAccountResource
    {
        abort_if($request->query() !== [], 422, 'Customer account lookup does not accept identity filters.');
        $profile = CustomerProfile::with('user:id,name,email')
            ->where('user_id', $request->user()->getAuthIdentifier())->firstOrFail();

        return new CustomerAccountResource($profile);
    }
}
