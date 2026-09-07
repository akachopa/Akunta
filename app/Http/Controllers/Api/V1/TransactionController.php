<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\Models\Business;
use App\Domain\Documents\Models\Document;
use App\Domain\Transactions\Enums\TransactionFilter;
use App\Domain\Transactions\Models\Transaction;
use App\Http\Controllers\Controller;
use App\Http\Presenters\TransactionPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * plan.md §29.4, sebatas yang sudah ada pada Phase 5:
 *
 * GET /businesses/{id}/transactions
 * GET /transactions/{id}
 *
 * Endpoint approve, reject, dan koreksi transaksi pada plan.md §29.4 belum dibuat: ketiganya
 * menetapkan makna ekonomi yang baru ditafsirkan Phase 8 dan disetujui lewat review center
 * Phase 10. Menyediakannya sekarang berarti menerima keputusan yang tidak dapat dipakai
 * sistem mana pun.
 */
class TransactionController extends Controller
{
    public function __construct(private readonly TransactionPresenter $presenter) {}

    public function index(Request $request, Business $business): JsonResponse
    {
        $this->authorize('viewAny', [Transaction::class, $business]);

        $filter = TransactionFilter::tryFrom((string) $request->query('filter')) ?? TransactionFilter::All;

        $query = $filter->apply(Transaction::query()->forBusiness($business));

        // Penelusuran balik dari dokumen ke transaksinya (plan.md §45.12).
        $documentId = $request->string('document')->value();

        if ($documentId !== '') {
            $document = Document::query()->forBusiness($business)->find($documentId);

            $query = $document instanceof Document
                ? $query->fromDocument($document)
                : $query->whereRaw('1 = 0');
        }

        // plan.md §40: list yang dipaginasi dan filter di sisi server.
        $transactions = $query
            ->with(['source.document'])
            ->orderByDesc('transaction_date')
            ->orderBy('reference')
            ->paginate(min((int) $request->integer('per_page', 50) ?: 50, 200));

        return response()->json([
            'data' => collect($transactions->items())
                ->map(fn (Transaction $transaction): array => $this->presenter->summary($transaction) + [
                    'source' => $transaction->source === null ? null : $this->presenter->source($transaction->source),
                ])
                ->all(),
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
            ],
        ]);
    }

    public function show(Transaction $transaction): JsonResponse
    {
        $this->authorize('view', $transaction);

        $transaction->load(['source.document', 'evidence']);

        return response()->json(['data' => $this->presenter->detail($transaction)]);
    }
}
