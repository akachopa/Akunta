<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\Models\Business;
use App\Domain\Review\Enums\ReviewQueueFilter;
use App\Domain\Review\Models\ReviewTask;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ReviewPresenter;
use App\Services\Review\ReviewTaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * plan.md §29.8: GET /businesses/{id}/review-queue.
 *
 * Antrean Review Center. Filter dan paginasi di sisi server (plan.md §40).
 */
class ReviewController extends Controller
{
    public function __construct(
        private readonly ReviewPresenter $presenter,
        private readonly ReviewTaskService $tasks,
    ) {}

    public function index(Request $request, Business $business): JsonResponse
    {
        $this->authorize('viewAny', [ReviewTask::class, $business]);

        $this->tasks->ensureDocumentTasks($business);

        $filter = ReviewQueueFilter::tryFrom((string) $request->query('filter'))
            ?? ReviewQueueFilter::NeedInformation;

        $query = $filter->apply(ReviewTask::query()->forBusiness($business));

        $tasks = $query
            ->with(['transaction.counterpartyEntity', 'transaction.economicEvent', 'document'])
            ->orderByDesc('opened_at')
            ->paginate(min((int) $request->integer('per_page', 50) ?: 50, 200));

        return response()->json([
            'data' => collect($tasks->items())
                ->map(fn (ReviewTask $task): array => $this->presenter->summary($task))
                ->all(),
            'meta' => [
                'current_page' => $tasks->currentPage(),
                'last_page' => $tasks->lastPage(),
                'per_page' => $tasks->perPage(),
                'total' => $tasks->total(),
            ],
            'filter' => $filter->value,
            'counts' => $this->counts($business),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function counts(Business $business): array
    {
        $counts = [];

        foreach (ReviewQueueFilter::cases() as $case) {
            $counts[$case->value] = $case->apply(ReviewTask::query()->forBusiness($business))->count();
        }

        return $counts;
    }
}
