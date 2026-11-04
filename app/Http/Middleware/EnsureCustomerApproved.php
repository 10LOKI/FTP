<?php

namespace App\Http\Middleware;

use App\Models\CustomerProfile;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCustomerApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $profile = $user === null ? null : CustomerProfile::where('user_id', $user->getAuthIdentifier())->first();

        if ($profile !== null && $profile->approval !== 'APPROUVE') {
            return response()->json(match ($profile->approval) {
                'EN_ATTENTE' => ['code' => 'ACCOUNT_PENDING', 'message' => 'Votre demande est en attente d’approbation.'],
                'REFUSE' => ['code' => 'ACCOUNT_REFUSED', 'message' => 'Votre demande de compte a été refusée.'],
                default => ['code' => 'ACCOUNT_RESTRICTED', 'message' => 'L’accès à ce compte est indisponible.'],
            }, 403);
        }

        return $next($request);
    }
}
