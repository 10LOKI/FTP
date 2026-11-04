<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterCustomerRequest;
use App\Http\Resources\CustomerRegistrationResource;
use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerRegistrationController extends Controller
{
    public function __invoke(RegisterCustomerRequest $request): CustomerRegistrationResource
    {
        $data = $request->validated();

        try {
            $profile = DB::transaction(function () use ($data, $request): CustomerProfile {
                $user = User::create(Arr::only($data, ['name', 'email', 'password']));
                $profile = new CustomerProfile(Arr::except($data, ['name', 'email', 'password']));
                $profile->user()->associate($user);
                $profile->approval = 'EN_ATTENTE';
                $profile->save();
                AuditEvent::recordRegistration($request->attributes->get('registration_request_id'), 'SUCCESS', $profile);

                return $profile;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! User::where('email', $data['email'])->exists()) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'email' => [__('validation.unique', ['attribute' => 'email'])],
            ]);
        }

        return new CustomerRegistrationResource($profile);
    }
}
