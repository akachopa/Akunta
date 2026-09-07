<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Data\JournalEntryData;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalEntryLine;
use App\Domain\Business\Models\Business;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreJournalEntryRequest;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * plan.md §29.6: GET /businesses/{id}/journals, GET /journals/{id},
 * POST /journals/{id}/approve, POST /journals/{id}/post, POST /journals/{id}/reverse.
 */
class JournalController extends Controller
{
    public function __construct(private readonly JournalPostingService $posting) {}

    public function index(Request $request, Business $business): JsonResponse
    {
        $this->authorize('viewAny', [JournalEntry::class, $business]);

        $entries = JournalEntry::query()
            ->forBusiness($business)
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', $request->string('status')->toString())
            )
            ->orderByDesc('entry_date')
            ->orderByDesc('entry_number')
            ->paginate((int) $request->integer('per_page', 25));

        return response()->json([
            'data' => $entries->through(fn (JournalEntry $entry): array => $this->payload($entry))->items(),
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'per_page' => $entries->perPage(),
                'total' => $entries->total(),
            ],
        ]);
    }

    public function store(StoreJournalEntryRequest $request, Business $business): JsonResponse
    {
        $this->authorize('create', [JournalEntry::class, $business]);

        $data = JournalEntryData::fromArray($request->toDomainPayload());
        $entry = $this->posting->createDraft($business, $data, $request->user());

        if ($request->boolean('post')) {
            $this->authorize('post', $entry);
            $entry = $this->posting->post($entry, $request->user());
        }

        return response()->json(['data' => $this->payload($entry->load('lines.account'))], 201);
    }

    public function show(JournalEntry $journal): JsonResponse
    {
        $this->authorize('view', $journal);

        return response()->json(['data' => $this->payload($journal->load('lines.account'))]);
    }

    public function approve(Request $request, JournalEntry $journal): JsonResponse
    {
        $this->authorize('approve', $journal);

        $entry = $this->posting->approve($journal, $request->user());

        return response()->json(['data' => $this->payload($entry)]);
    }

    public function post(Request $request, JournalEntry $journal): JsonResponse
    {
        $this->authorize('post', $journal);

        $entry = $this->posting->post($journal, $request->user());

        return response()->json(['data' => $this->payload($entry->load('lines.account'))]);
    }

    public function reverse(Request $request, JournalEntry $journal): JsonResponse
    {
        $this->authorize('reverse', $journal);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'reversal_date' => ['nullable', 'date'],
        ]);

        $reversal = $this->posting->reverse(
            $journal,
            $request->user(),
            $validated['reason'],
            isset($validated['reversal_date'])
                ? Carbon::parse($validated['reversal_date'])->startOfDay()
                : null
        );

        return response()->json(['data' => $this->payload($reversal->load('lines.account'))], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(JournalEntry $entry): array
    {
        $payload = [
            'id' => $entry->getKey(),
            'business_id' => $entry->business_id,
            'accounting_period_id' => $entry->accounting_period_id,
            'entry_number' => $entry->entry_number,
            'entry_date' => $entry->entry_date->toDateString(),
            'description' => $entry->description,
            'source_type' => $entry->source_type,
            'status' => $entry->status->value,
            'currency' => $entry->currency,
            'total_debit' => $entry->total_debit,
            'total_credit' => $entry->total_credit,
            'is_balanced' => $entry->isBalanced(),
            'reversal_of_id' => $entry->reversal_of_id,
            'reversed_by_entry_id' => $entry->reversed_by_entry_id,
        ];

        if ($entry->relationLoaded('lines')) {
            $payload['lines'] = $entry->lines->map(static fn (JournalEntryLine $line): array => [
                'id' => $line->getKey(),
                'line_number' => $line->line_number,
                'account_id' => $line->account_id,
                'account_code' => $line->relationLoaded('account') ? $line->account->code : null,
                'description' => $line->description,
                'debit' => $line->debit,
                'credit' => $line->credit,
                'entity_id' => $line->entity_id,
            ]);
        }

        return $payload;
    }
}
