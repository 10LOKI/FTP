<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListCustomerRegistrationsRequest;
use App\Http\Resources\CustomerReviewResource;
use App\Models\CustomerProfile;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CustomerReviewController extends Controller
{
    public function index(ListCustomerRegistrationsRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $query = CustomerProfile::with('user:id,name,email')->where('approval', 'EN_ATTENTE');

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        return CustomerReviewResource::collection(
            $query->orderBy('created_at')->orderBy('id')
                ->paginate((int) ($filters['per_page'] ?? 20))->withQueryString(),
        );
    }

    public function show(CustomerProfile $customerProfile): CustomerReviewResource
    {
        return new CustomerReviewResource($customerProfile->load('user:id,name,email'));
    }
}
