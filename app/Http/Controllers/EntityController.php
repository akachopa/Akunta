<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Business\Models\Business;
use App\Domain\Entities\Enums\EntityStatus;
use App\Domain\Entities\Models\Entity;
use App\Http\Presenters\EntityPresenter;
use App\Models\User;
use App\Services\EntityResolution\EntityMergeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EntityController extends Controller
{
    public function __construct(
        private readonly EntityPresenter $presenter,
        private readonly EntityMergeService $merge,
    ) {}

    public function index(Business $business): Response
    {
        $this->authorize('viewAny', [Entity::class, $business]);

        $entities = Entity::query()
            ->forBusiness($business)
            ->where('status', EntityStatus::Active)
            ->withCount('transactions')
            ->orderBy('name')
            ->paginate(50);

        return Inertia::render('entities/Index', [
            'business' => ['id' => $business->getKey(), 'name' => $business->name],
            'entities' => [
                'data' => collect($entities->items())
                    ->map(fn (Entity $entity): array => $this->presenter->summary($entity))
                    ->all(),
                'meta' => [
                    'current_page' => $entities->currentPage(),
                    'last_page' => $entities->lastPage(),
                    'per_page' => $entities->perPage(),
                    'total' => $entities->total(),
                ],
            ],
        ]);
    }

    public function show(Request $request, Business $business, Entity $entity): Response
    {
        $this->authorize('view', $entity);

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $entity->load(['aliases', 'identifiers']);

        $candidates = Entity::query()
            ->forBusiness($business)
            ->where('status', EntityStatus::Active)
            ->where('id', '!=', $entity->getKey())
            ->orderBy('name')
            ->limit(50)
            ->get()
            ->map(fn (Entity $candidate): array => $this->presenter->summary($candidate))
            ->all();

        return Inertia::render('entities/Show', [
            'business' => ['id' => $business->getKey(), 'name' => $business->name],
            'entity' => $this->presenter->detail($entity),
            'merge_candidates' => $candidates,
            'can' => [
                'manage' => $user->can('manage', $entity),
            ],
        ]);
    }

    public function confirm(Request $request, Business $business, Entity $entity): RedirectResponse
    {
        $this->authorize('manage', $entity);

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $validated = $request->validate([
            'alias' => ['nullable', 'string', 'max:255'],
        ]);

        $this->merge->confirm($entity, $user, $validated['alias'] ?? null);

        return back()->with('success', 'Alias entity dikonfirmasi dan akan dipakai pada transaksi berikutnya.');
    }

    public function mergeInto(Request $request, Business $business, Entity $entity): RedirectResponse
    {
        $this->authorize('manage', $entity);

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $validated = $request->validate([
            'target_id' => ['required', 'uuid'],
        ]);

        $target = Entity::query()->forBusiness($business)->findOrFail($validated['target_id']);
        $this->authorize('manage', $target);

        $this->merge->merge($entity, $target, $user);

        return redirect()
            ->route('businesses.entities.show', [$business, $target])
            ->with('success', sprintf('%s digabung ke %s.', $entity->name, $target->name));
    }
}
