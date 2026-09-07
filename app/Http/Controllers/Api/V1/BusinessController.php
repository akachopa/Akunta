<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\Models\Business;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBusinessRequest;
use App\Services\Business\BusinessProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * plan.md §29.2: GET/POST /businesses, GET/PATCH /businesses/{id}.
 */
class BusinessController extends Controller
{
    public function __construct(private readonly BusinessProvisioningService $provisioning) {}

    public function index(Request $request): JsonResponse
    {
        $businesses = $request->user()
            ->businesses()
            ->orderBy('name')
            ->get()
            ->map(fn (Business $business): array => $this->payload($business, $request));

        return response()->json(['data' => $businesses]);
    }

    public function store(StoreBusinessRequest $request): JsonResponse
    {
        $business = $this->provisioning->createBusiness($request->user(), $request->validated());

        return response()->json(['data' => $this->payload($business, $request)], 201);
    }

    public function show(Request $request, Business $business): JsonResponse
    {
        $this->authorize('view', $business);

        return response()->json(['data' => $this->payload($business->load('profile'), $request)]);
    }

    public function update(Request $request, Business $business): JsonResponse
    {
        $this->authorize('update', $business);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:32'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        $business = $this->provisioning->updateBusiness($business, $validated, $request->user());

        return response()->json(['data' => $this->payload($business, $request)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Business $business, Request $request): array
    {
        return [
            'id' => $business->getKey(),
            'organization_id' => $business->organization_id,
            'name' => $business->name,
            'legal_name' => $business->legal_name,
            'business_type' => $business->business_type->value,
            'accounting_basis' => $business->accounting_basis->value,
            'currency' => $business->currency,
            'opening_date' => $business->opening_date->toDateString(),
            'role' => $request->user()?->roleIn($business)?->slug->value,
        ];
    }
}
