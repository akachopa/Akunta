<?php

declare(strict_types=1);

use App\Domain\Accounting\Enums\JournalEntryStatus;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Business\Enums\RoleSlug;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Review\Enums\ReviewActionType;
use App\Domain\Review\Enums\ReviewTaskKind;
use App\Domain\Review\Enums\ReviewTaskStatus;
use App\Domain\Review\Models\ReviewAction;
use App\Domain\Review\Models\ReviewTask;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use App\Domain\Transactions\Models\TransactionTag;
use App\Services\Accounting\JournalProposalService;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/**
 * Review Center (plan.md §16, §29.4, §29.8, §37 Phase 10).
 *
 * Approve tidak memposting. Reject bukan delete. Staff tidak boleh memutuskan.
 */
beforeEach(function (): void {
    $this->fakeDocumentDisk();
    Queue::fake();
});

it('membuka tugas review setelah matching dan menampilkannya di antrean', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $this->normalizedBankStatement($business, $owner);

    $tasks = ReviewTask::query()->withoutGlobalScopes()->where('business_id', $business->getKey())->get();

    expect($tasks)->toHaveCount(2);
    expect($tasks->every(fn (ReviewTask $task): bool => $task->kind === ReviewTaskKind::ReadyForApproval))->toBeTrue();
    expect($tasks->every(fn (ReviewTask $task): bool => $task->status === ReviewTaskStatus::Open))->toBeTrue();

    $this->actingAs($owner)
        ->get("/businesses/{$business->getKey()}/review?filter=ready")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('review/Index')
            ->where('filter', 'ready')
            ->where('counts.ready', 2)
            ->count('tasks.data', 2)
        );
});

it('memasukkan dokumen need_review ke antrean Perlu Informasi', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserSuccess();
    $this->fakeClassifierSuccess('purchase_invoice', 0.93);
    $this->fakeExtractorResponse($this->invoiceFields(confidence: 0.84), confidence: 0.84);
    $this->runIntelligencePipeline($document);

    expect($document->refresh()->processing_status)->toBe(DocumentStatus::NeedReview);

    $this->actingAs($owner)
        ->get("/businesses/{$business->getKey()}/review")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filter', 'need-information')
            ->where('counts.need-information', 1)
            ->where('tasks.data.0.subject_type', 'document')
            ->where('tasks.data.0.document.id', $document->getKey())
        );
});

it('owner menyetujui transaksi tanpa memposting jurnal', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $this->normalizedBankStatement($business, $owner);

    /** @var Transaction $transaction */
    $transaction = Transaction::query()
        ->withoutGlobalScopes()
        ->where('description', 'like', '%SETORAN TUNAI%')
        ->firstOrFail();

    /** @var ReviewTask $task */
    $task = ReviewTask::query()->withoutGlobalScopes()->where('transaction_id', $transaction->getKey())->sole();

    $this->actingAs($owner)
        ->post("/businesses/{$business->getKey()}/review/{$task->getKey()}/approve")
        ->assertRedirect();

    $transaction->refresh();
    $task->refresh();
    $journal = $transaction->journalEntries()->withoutGlobalScopes()->first();

    expect($transaction->status)->toBe(TransactionStatus::Approved);
    expect($transaction->posting_date)->toBeNull();
    expect($journal)->not->toBeNull();
    expect($journal->status)->toBe(JournalEntryStatus::Approved);
    expect($journal->posted_at)->toBeNull();
    expect($task->status)->toBe(ReviewTaskStatus::AwaitingPost);

    expect(
        ReviewAction::query()
            ->withoutGlobalScopes()
            ->where('review_task_id', $task->getKey())
            ->where('action', ReviewActionType::Approve->value)
            ->exists()
    )->toBeTrue();

    expect(
        AuditLog::query()
            ->where('action', 'transaction.approved')
            ->where('entity_id', $transaction->getKey())
            ->exists()
    )->toBeTrue();

    $this->actingAs($owner)
        ->get("/businesses/{$business->getKey()}/review?filter=approved")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('counts.approved', 1)->count('tasks.data', 1));
});

it('akuntan memposting setelah approve dan mengisi posting_date', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $accountant = $this->addMember($business, RoleSlug::Accountant);
    $this->normalizedBankStatement($business, $owner);

    /** @var Transaction $transaction */
    $transaction = Transaction::query()
        ->withoutGlobalScopes()
        ->where('description', 'like', '%SETORAN TUNAI%')
        ->firstOrFail();

    /** @var ReviewTask $task */
    $task = ReviewTask::query()->withoutGlobalScopes()->where('transaction_id', $transaction->getKey())->sole();

    $this->actingAs($owner)
        ->post("/businesses/{$business->getKey()}/review/{$task->getKey()}/approve")
        ->assertRedirect();

    $this->actingAs($owner)
        ->post("/businesses/{$business->getKey()}/review/{$task->getKey()}/post")
        ->assertForbidden();

    $this->actingAs($accountant)
        ->post("/businesses/{$business->getKey()}/review/{$task->getKey()}/post")
        ->assertRedirect();

    $transaction->refresh();
    $task->refresh();
    $journal = $transaction->journalEntries()->withoutGlobalScopes()->first();

    expect($transaction->status)->toBe(TransactionStatus::Posted);
    expect($transaction->posting_date?->toDateString())->toBe($journal->entry_date->toDateString());
    expect($journal->status)->toBe(JournalEntryStatus::Posted);
    expect($task->status)->toBe(ReviewTaskStatus::Completed);

    $this->actingAs($accountant)
        ->get("/businesses/{$business->getKey()}/review?filter=completed")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('counts.completed', 1));
});

it('menolak transaksi hanya dengan alasan dan menghapus jurnal draft tanpa menghapus jejak', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $this->normalizedBankStatement($business, $owner);

    /** @var Transaction $transaction */
    $transaction = Transaction::query()
        ->withoutGlobalScopes()
        ->where('description', 'like', '%BIAYA ADMIN%')
        ->firstOrFail();

    /** @var ReviewTask $task */
    $task = ReviewTask::query()->withoutGlobalScopes()->where('transaction_id', $transaction->getKey())->sole();
    $journalId = $transaction->journalEntries()->withoutGlobalScopes()->value('id');

    expect($journalId)->not->toBeNull();

    $this->actingAs($owner)
        ->post("/businesses/{$business->getKey()}/review/{$task->getKey()}/reject")
        ->assertSessionHasErrors('reason');

    $this->actingAs($owner)
        ->from("/businesses/{$business->getKey()}/review/{$task->getKey()}")
        ->post("/businesses/{$business->getKey()}/review/{$task->getKey()}/reject", [
            'reason' => 'Bukan beban usaha, transfer pribadi.',
        ])
        ->assertRedirect();

    $transaction->refresh();
    $task->refresh();

    expect($transaction->status)->toBe(TransactionStatus::Rejected);
    expect($transaction->review_reason)->toBe('Bukan beban usaha, transfer pribadi.');
    expect(Transaction::query()->withoutGlobalScopes()->whereKey($transaction->getKey())->exists())->toBeTrue();
    expect(JournalEntry::query()->withoutGlobalScopes()->whereKey($journalId)->exists())->toBeFalse();
    expect($task->status)->toBe(ReviewTaskStatus::Completed);

    expect(
        ReviewAction::query()
            ->withoutGlobalScopes()
            ->where('review_task_id', $task->getKey())
            ->where('action', ReviewActionType::Reject->value)
            ->exists()
    )->toBeTrue();
});

it('staff tidak boleh menyetujui, menolak, atau memposting', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $staff = $this->addMember($business, RoleSlug::BusinessStaff);
    $this->normalizedBankStatement($business, $owner);

    /** @var Transaction $transaction */
    $transaction = Transaction::query()->withoutGlobalScopes()->firstOrFail();
    /** @var ReviewTask $task */
    $task = ReviewTask::query()->withoutGlobalScopes()->where('transaction_id', $transaction->getKey())->sole();

    $this->actingAs($staff)
        ->get("/businesses/{$business->getKey()}/review?filter=ready")
        ->assertOk();

    $this->actingAs($staff)
        ->post("/businesses/{$business->getKey()}/review/{$task->getKey()}/approve")
        ->assertForbidden();

    $this->actingAs($staff)
        ->post("/businesses/{$business->getKey()}/review/{$task->getKey()}/reject", [
            'reason' => 'Tidak boleh.',
        ])
        ->assertForbidden();

    $this->actingAs($staff)
        ->post("/businesses/{$business->getKey()}/transactions/{$transaction->getKey()}/post")
        ->assertForbidden();
});

it('menyembunyikan antrean bisnis lain', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $this->normalizedBankStatement($business, $owner);

    /** @var ReviewTask $task */
    $task = ReviewTask::query()->withoutGlobalScopes()->firstOrFail();

    [$intruder, $other] = $this->provisionBusinessWithOwner('Toko Lain');

    $this->actingAs($intruder)
        ->get("/businesses/{$other->getKey()}/review/{$task->getKey()}")
        ->assertNotFound();

    $this->actingAs($intruder)
        ->get("/businesses/{$other->getKey()}/review?filter=ready")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->count('tasks.data', 0));
});

it('tidak membuat jurnal kedua untuk duplikat yang disetujui', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $this->normalizedBankStatement($business, $owner);

    $firstJournals = JournalEntry::query()
        ->withoutGlobalScopes()
        ->where('source_type', JournalProposalService::SOURCE_TRANSACTION)
        ->count();

    $duplicate = $this->ingestDocument($business, $owner, $this->csvFile());
    $this->fakeParserSuccess();
    $this->fakeClassifierSuccess('bank_statement', 0.97);
    $this->fakeExtractorResponse(
        [
            $this->extractedField('bank_name', 'Bank Mandiri', 0.96),
            $this->extractedField('account_number', '1230004567', 0.96),
            $this->extractedField('period_end', '2026-01-31', 0.96),
            $this->extractedField('opening_balance', '5000000.00', 0.96),
            $this->extractedField('closing_balance', '6500000.00', 0.96),
        ],
        rows: [
            [
                'date' => '2026-01-15',
                'description' => 'SETORAN TUNAI',
                'debit' => null,
                'credit' => '2000000.00',
                'balance' => '7000000.00',
                'page_number' => 1,
                'source_text' => '15/01 SETORAN TUNAI 2.000.000',
            ],
            [
                'date' => '2026-01-20',
                'description' => 'BIAYA ADMIN',
                'debit' => '500000.00',
                'credit' => null,
                'balance' => '6500000.00',
                'page_number' => 1,
                'source_text' => '20/01 BIAYA ADMIN 500.000',
            ],
        ],
        confidence: 0.96,
        extractor: 'bank_statement',
    );
    $this->runIntelligencePipeline($duplicate);

    $dupes = Transaction::query()->withoutGlobalScopes()->fromDocument($duplicate)->get();

    expect($dupes->every(fn (Transaction $transaction): bool => $transaction->status === TransactionStatus::NeedReview))->toBeTrue();

    /** @var Transaction $dupe */
    $dupe = $dupes->firstOrFail();
    /** @var ReviewTask $task */
    $task = ReviewTask::query()->withoutGlobalScopes()->where('transaction_id', $dupe->getKey())->sole();

    expect($task->kind)->toBe(ReviewTaskKind::Duplicate);

    $this->actingAs($owner)
        ->get("/businesses/{$business->getKey()}/review")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('counts.need-information', 2));

    $this->actingAs($owner)
        ->post("/businesses/{$business->getKey()}/review/{$task->getKey()}/approve")
        ->assertRedirect();

    expect($dupe->refresh()->status)->toBe(TransactionStatus::Approved);
    expect(
        JournalEntry::query()
            ->withoutGlobalScopes()
            ->where('source_type', JournalProposalService::SOURCE_TRANSACTION)
            ->count()
    )->toBe($firstJournals);
});

it('menyimpan komentar dan tag secara append-only', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $this->normalizedBankStatement($business, $owner);

    /** @var ReviewTask $task */
    $task = ReviewTask::query()->withoutGlobalScopes()->firstOrFail();

    $this->actingAs($owner)
        ->post("/businesses/{$business->getKey()}/review/{$task->getKey()}/comments", [
            'body' => 'Cek bukti transfer asli.',
        ])
        ->assertRedirect();

    $this->actingAs($owner)
        ->post("/businesses/{$business->getKey()}/review/{$task->getKey()}/tags", [
            'tag' => 'Cek Pajak',
        ])
        ->assertRedirect();

    $this->actingAs($owner)
        ->get("/businesses/{$business->getKey()}/review/{$task->getKey()}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('review/Show')
            ->where('task.comments.0.body', 'Cek bukti transfer asli.')
            ->where('task.transaction_detail.tags.0', 'cek pajak')
        );

    expect(
        TransactionTag::query()
            ->withoutGlobalScopes()
            ->where('transaction_id', $task->transaction_id)
            ->where('tag', 'cek pajak')
            ->exists()
    )->toBeTrue();
});

it('menyajikan review-queue dan approve/reject/post lewat API v1', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $accountant = $this->addMember($business, RoleSlug::Accountant);
    $this->normalizedBankStatement($business, $owner);

    /** @var Transaction $transaction */
    $transaction = Transaction::query()
        ->withoutGlobalScopes()
        ->where('description', 'like', '%SETORAN TUNAI%')
        ->firstOrFail();

    Sanctum::actingAs($owner);

    $this->getJson("/api/v1/businesses/{$business->getKey()}/review-queue?filter=ready")
        ->assertOk()
        ->assertJsonPath('filter', 'ready')
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('counts.ready', 2);

    $this->postJson("/api/v1/transactions/{$transaction->getKey()}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', TransactionStatus::Approved->value)
        ->assertJsonPath('data.review_task_status', ReviewTaskStatus::AwaitingPost->value);

    expect($transaction->refresh()->journalEntries()->withoutGlobalScopes()->first()?->status)
        ->toBe(JournalEntryStatus::Approved);

    Sanctum::actingAs($accountant);

    $this->postJson("/api/v1/transactions/{$transaction->getKey()}/post")
        ->assertOk()
        ->assertJsonPath('data.status', TransactionStatus::Posted->value);

    expect($transaction->refresh()->posting_date)->not->toBeNull();
});
