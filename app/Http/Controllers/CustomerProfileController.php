<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateCustomerPhoneRequest;
use App\Http\Resources\CustomerProfileResource;
use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CustomerProfileController extends Controller
{
    public function updatePhone(UpdateCustomerPhoneRequest $request): CustomerProfileResource
    {
        $data = $request->validated();
        $profile = DB::transaction(function () use ($request, $data): CustomerProfile {
            $user = User::whereKey($request->user()->getAuthIdentifier())->lockForUpdate()->firstOrFail();
            abort_if($user->must_change_password, 403, 'Change your temporary password first.');
            $profile = CustomerProfile::where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            abort_unless($profile->approval === 'APPROUVE', 403, 'Customer approval is required.');
            abort_if($profile->version !== (int) $data['version'], 409, 'Your profile has changed. Refresh before editing.');
            if ($profile->phone !== $data['phone']) {
                $previousVersion = $profile->version;
                $profile->phone = $data['phone'];
                $profile->version++;
                $profile->save();
                AuditEvent::create([
                    'actor_id' => $user->id, 'request_id' => (string) Str::uuid(),
                    'action' => 'customer.profile.phone_updated', 'object_type' => 'customer_profile', 'object_id' => $profile->id,
                    'result' => 'SUCCESS', 'reason' => null,
                    'changes' => ['before' => ['version' => $previousVersion], 'after' => ['version' => $profile->version]],
                    'occurred_at' => now('UTC'),
                ]);
            }

            return $profile->load('user:id,name,email');
        });

        return new CustomerProfileResource($profile);
    }

    public function show(Request $request): CustomerProfileResource
    {
        abort_if($request->query() !== [], 422, 'Profile lookup does not accept filters.');
        $profile = CustomerProfile::with('user:id,name,email')
            ->where('user_id', $request->user()->getAuthIdentifier())->firstOrFail();

        return new CustomerProfileResource($profile);
    }
}
