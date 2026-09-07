<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Business\Models\Business;
use App\Domain\Documents\Models\Document;
use App\Domain\Transactions\Enums\TransactionFilter;
use App\Domain\Transactions\Models\Transaction;
use App\Http\Presenters\TransactionPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Daftar transaksi canonical hasil normalisasi (plan.md §8.2, §37 Phase 5).
 *
 * Halaman ini menampilkan apa yang terbaca dari dokumen, bukan penafsiran akuntansinya.
 * Tidak ada kolom akun maupun debit/kredit di sini: pemetaannya adalah pekerjaan Phase 8
 * dan Phase 9, dan menampilkan kolom kosong untuknya akan membuat user mengira sistem gagal
 * mengisinya (plan.md §44.3, §44.4).
 */
class TransactionController extends Controller
{
    public function __construct(private readonly TransactionPresenter $presenter) {}

    public function index(Request $request, Business $business): Response
    {
        $this->authorize('viewAny', [Transaction::class, $business]);

        $filter = TransactionFilter::tryFrom((string) $request->query('filter')) ?? TransactionFilter::All;

        $query = $filter->apply(Transaction::query()->forBusiness($business));

        /*
         * Penyaringan per dokumen adalah jalan masuk dari halaman dokumen: user menekan
         * "lihat transaksi" pada satu rekening koran dan ingin melihat justru transaksi
         * yang dihasilkannya, bukan seluruh transaksi bisnis (plan.md §45.12).
         */
        $documentId = $request->string('document')->value();
        $document = null;

        if ($documentId !== '') {
            $document = Document::query()->forBusiness($business)->find($documentId);

            $query = $document instanceof Document
                ? $query->fromDocument($document)
                : $query->whereRaw('1 = 0');
        }

        $transactions = $query
            ->with(['source.document'])
            ->orderByDesc('transaction_date')
            ->orderBy('reference')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('transactions/Index', [
            'business' => ['id' => $business->getKey(), 'name' => $business->name],
            'filter' => $filter->value,
            'filters' => array_map(
                static fn (TransactionFilter $case): array => ['value' => $case->value, 'label' => $case->label()],
                TransactionFilter::cases()
            ),
            'counts' => $this->counts($business),
            'document' => $document instanceof Document ? [
                'id' => $document->getKey(),
                'reference' => $document->reference,
                'original_filename' => $document->original_filename,
            ] : null,
            'transactions' => [
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
            ],
        ]);
    }

    public function show(Business $business, Transaction $transaction): Response
    {
        $this->authorize('view', $transaction);

        $transaction->load(['source.document', 'evidence']);

        return Inertia::render('transactions/Show', [
            'business' => ['id' => $business->getKey(), 'name' => $business->name],
            'transaction' => $this->presenter->detail($transaction),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function counts(Business $business): array
    {
        $counts = [];

        foreach (TransactionFilter::cases() as $case) {
            $counts[$case->value] = $case->apply(Transaction::query()->forBusiness($business))->count();
        }

        return $counts;
    }
}
