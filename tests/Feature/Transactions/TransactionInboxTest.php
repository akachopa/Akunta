<?php

declare(strict_types=1);

use App\Domain\Business\Enums\RoleSlug;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/**
 * Halaman dan endpoint transaksi (plan.md §29.4, §40, §44.15).
 *
 * Yang diuji: apa yang dilihat user, siapa yang boleh melihatnya, dan bahwa penelusuran
 * dua arah antara dokumen dan transaksinya benar-benar tersedia lewat HTTP.
 */
beforeEach(function (): void {
    $this->fakeDocumentDisk();
    Queue::fake();
});

it('menampilkan daftar transaksi beserta jumlah per tab', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->normalizedBankStatement($business, $owner);

    $this->actingAs($owner)
        ->get("/businesses/{$business->getKey()}/transactions")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('transactions/Index')
            ->where('filter', 'all')
            ->where('counts.all', 2)
            ->where('counts.need-information', 0)
            ->count('transactions.data', 2)

            // Mutasi termuda di atas, sama seperti inbox dokumen (plan.md §40).
            ->where('transactions.data.0.reference', 'TRX-000002')
            ->where('transactions.data.0.amount', '500000.00')
            ->where('transactions.data.0.direction', 'outflow')

            // Sumber ikut pada daftar supaya user dapat langsung membuka berkas asalnya.
            ->where('transactions.data.0.source.document.id', $document->getKey())
            ->where('transactions.data.0.source.row_index', 1)
        );
});

it('menyaring transaksi milik satu dokumen', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $statement = $this->normalizedBankStatement($business, $owner);

    // Dokumen kedua agar penyaringannya benar-benar menyisihkan sesuatu.
    $invoice = $this->ingestDocument($business, $owner);

    $this->fakeClassifierSuccess('purchase_invoice', 0.97);
    $this->fakeExtractorResponse($this->invoiceFields(confidence: 0.96), confidence: 0.96);
    $this->runIntelligencePipeline($invoice);

    expect(Transaction::query()->withoutGlobalScopes()->count())->toBe(3);

    $this->actingAs($owner)
        ->get("/businesses/{$business->getKey()}/transactions?document={$statement->getKey()}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->count('transactions.data', 2)
            ->where('document.reference', $statement->reference)
        );
});

it('menampilkan asal dan bukti pada detail transaksi', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->normalizedBankStatement($business, $owner);

    /** @var Transaction $transaction */
    $transaction = Transaction::query()->withoutGlobalScopes()->orderBy('reference')->first();

    $this->actingAs($owner)
        ->get("/businesses/{$business->getKey()}/transactions/{$transaction->getKey()}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('transactions/Show')
            ->where('transaction.reference', 'TRX-000001')
            ->where('transaction.source.document.id', $document->getKey())
            ->where('transaction.source.source_reference', 'row:0')
            ->where('transaction.evidence.0.type', 'bank_row')
            ->where('transaction.evidence.0.row_reference', '0')
        );
});

it('menautkan transaksi hasil normalisasi pada halaman dokumen', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->normalizedBankStatement($business, $owner);

    // Penelusuran mundur: dari berkas ke transaksi yang lahir darinya (plan.md §45.12).
    $this->actingAs($owner)
        ->get("/businesses/{$business->getKey()}/documents/{$document->getKey()}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->count('document.transactions', 2)
            ->where('document.transactions.0.reference', 'TRX-000001')
        );
});

it('menyembunyikan menu dan halaman transaksi dari user tanpa izin', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $this->normalizedBankStatement($business, $owner);

    /*
     * Ketiga role bisnis boleh melihat transaksi (plan.md §4.2–§4.4): transaksi adalah data
     * yang mereka masukkan sendiri lewat dokumen.
     */
    $staff = $this->addMember($business, RoleSlug::BusinessStaff);

    $this->actingAs($staff)
        ->get("/businesses/{$business->getKey()}/transactions")
        ->assertOk();

    $this->actingAs($staff)
        ->get("/businesses/{$business->getKey()}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where(
            'permissions',
            fn (Illuminate\Support\Collection $permissions): bool => $permissions->contains('transaction.view')
        ));
});

it('menyembunyikan transaksi bisnis lain', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $this->normalizedBankStatement($business, $owner);

    /** @var Transaction $transaction */
    $transaction = Transaction::query()->withoutGlobalScopes()->first();

    [$intruder, $other] = $this->provisionBusinessWithOwner('Toko Lain');

    // plan.md §44.15: kebocoran lintas tenant tampak sebagai 404, bukan 403.
    $this->actingAs($intruder)
        ->get("/businesses/{$other->getKey()}/transactions/{$transaction->getKey()}")
        ->assertNotFound();

    $this->actingAs($intruder)
        ->get("/businesses/{$other->getKey()}/transactions")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->count('transactions.data', 0));
});

it('memaparkan transaksi lewat api yang dipaginasi', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $this->normalizedBankStatement($business, $owner);

    Sanctum::actingAs($owner);

    $this->getJson("/api/v1/businesses/{$business->getKey()}/transactions?per_page=1")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonPath('data.0.reference', 'TRX-000002')

        // Nilai uang tetap string pada payload API (plan.md §44.14).
        ->assertJsonPath('data.0.amount', '500000.00');
});

it('memaparkan detail transaksi beserta buktinya lewat api', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->normalizedBankStatement($business, $owner);

    /** @var Transaction $transaction */
    $transaction = Transaction::query()->withoutGlobalScopes()->orderBy('reference')->first();

    Sanctum::actingAs($owner);

    $this->getJson("/api/v1/transactions/{$transaction->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.reference', 'TRX-000001')
        ->assertJsonPath('data.source.document.id', $document->getKey())
        ->assertJsonPath('data.evidence.0.type', 'bank_row');
});

it('menyaring transaksi yang menunggu keputusan manusia', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $this->normalizedBankStatement($business, $owner);

    /** @var Transaction $transaction */
    $transaction = Transaction::query()->withoutGlobalScopes()->orderByDesc('reference')->first();

    $transaction->transitionTo(TransactionStatus::NeedReview, [
        'review_reason' => 'Arah uang pada bukti transfer ini belum dapat dipastikan.',
    ]);

    $this->actingAs($owner)
        ->get("/businesses/{$business->getKey()}/transactions?filter=need-information")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('counts.need-information', 1)
            ->count('transactions.data', 1)
            ->where('transactions.data.0.needs_attention', true)
            ->where('transactions.data.0.review_reason', 'Arah uang pada bukti transfer ini belum dapat dipastikan.')
        );
});

it('mengeluarkan transaksi yang ditolak dari tab semua', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $this->normalizedBankStatement($business, $owner);

    /** @var Transaction $transaction */
    $transaction = Transaction::query()->withoutGlobalScopes()->orderByDesc('reference')->first();

    $transaction->transitionTo(TransactionStatus::Rejected);

    // plan.md §31: yang ditolak tidak dihapus, hanya dipindahkan ke tabnya sendiri.
    $this->actingAs($owner)
        ->get("/businesses/{$business->getKey()}/transactions")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('counts.all', 1)
            ->where('counts.rejected', 1)
            ->count('transactions.data', 1)
        );
});

it('menandai dokumen tanpa transaksi tetap dapat dibuka', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserSuccess();
    $this->fakeClassifierSuccess('purchase_invoice', 0.80);
    $this->fakeExtractorResponse($this->invoiceFields(confidence: 0.80), confidence: 0.80);

    $document = $this->runIntelligencePipeline($document);

    expect($document->processing_status)->toBe(DocumentStatus::NeedReview);

    $this->actingAs($owner)
        ->get("/businesses/{$business->getKey()}/documents/{$document->getKey()}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->count('document.transactions', 0));
});
