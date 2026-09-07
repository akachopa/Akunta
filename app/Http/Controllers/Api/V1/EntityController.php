<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\Models\Business;
use App\Domain\Entities\Enums\EntityStatus;
use App\Domain\Entities\Models\Entity;
use App\Http\Controllers\Controller;
use App\Http\Presenters\EntityPresenter;
use Illuminate\Http\JsonResponse;

class EntityController extends Controller
{
    public function __construct(private readonly EntityPresenter $presenter) {}

    public function index(Business $business): JsonResponse
    {
        $this->authorize('viewAny', [Entity::class, $business]);

        $entities = Entity::query()
            ->forBusiness($business)
            ->where('status', EntityStatus::Active)
            ->withCount('transactions')
            ->orderBy('name')
            ->paginate(50);

        return response()->json([
            'data' => collect($entities->items())
                ->map(fn (Entity $entity): array => $this->presenter->summary($entity))
                ->all(),
            'meta' => [
                'current_page' => $entities->currentPage(),
                'last_page' => $entities->lastPage(),
                'per_page' => $entities->perPage(),
                'total' => $entities->total(),
            ],
        ]);
    }

    public function show(Entity $entity): JsonResponse
    {
        $this->authorize('view', $entity);

        $entity->load(['aliases', 'identifiers']);

        return response()->json(['data' => $this->presenter->detail($entity)]);
    }
}
