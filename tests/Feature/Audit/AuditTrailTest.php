<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Business\Enums\RoleSlug;
use App\Models\User;

/*
 * plan.md §31 dan §44.7: setiap keputusan penting tercatat, dan audit history tidak
 * boleh dihapus atau ditimpa.
 */

it('mencatat pembuatan bisnis beserta aktornya', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner('Toko Tercatat');

    $log = AuditLog::query()
        ->where('action', 'business.created')
        ->where('entity_id', $business->getKey())
        ->sole();

    expect($log->business_id)->toBe($business->getKey());
    expect($log->actor_id)->toBe($owner->getKey());
    expect($log->actor_label)->toBe($owner->name);
    expect($log->after)->toMatchArray(['name' => 'Toko Tercatat']);
});

it('mencatat penerapan template COA', function (): void {
    [, $business] = $this->provisionBusinessWithOwner();

    $log = AuditLog::query()
        ->where('business_id', $business->getKey())
        ->where('action', 'chart_of_accounts.template_applied')
        ->sole();

    expect($log->after['accounts_created'])->toBeGreaterThan(0);
});

it('mencatat penambahan dan perubahan role member', function (): void {
    [, $business] = $this->provisionBusinessWithOwner();

    $user = User::factory()->create();
    $this->addMember($business, RoleSlug::BusinessStaff, user: $user);
    $this->addMember($business, RoleSlug::Accountant, user: $user);

    expect(AuditLog::query()
        ->where('business_id', $business->getKey())
        ->where('action', 'business_member.attached')
        ->where('after->user_id', $user->getKey())
        ->exists())->toBeTrue();

    $roleChange = AuditLog::query()
        ->where('business_id', $business->getKey())
        ->where('action', 'business_member.role_changed')
        ->sole();

    expect($roleChange->before)->toMatchArray(['role' => RoleSlug::BusinessStaff->value]);
    expect($roleChange->after)->toMatchArray(['role' => RoleSlug::Accountant->value]);
});

it('mencatat posting journal beserta perubahan statusnya', function (): void {
    [, $business] = $this->provisionBusinessWithOwner();
    $accountant = $this->addMember($business, RoleSlug::Accountant);

    $entry = $this->postSimpleJournal($business, $accountant, '1101', '4100', '250000');

    expect(AuditLog::query()
        ->where('action', 'journal_entry.created')
        ->where('entity_id', $entry->getKey())
        ->exists())->toBeTrue();

    $posted = AuditLog::query()
        ->where('action', 'journal_entry.posted')
        ->where('entity_id', $entry->getKey())
        ->sole();

    expect($posted->before)->toMatchArray(['status' => 'draft']);
    expect($posted->after)->toMatchArray(['status' => 'posted']);
    expect($posted->actor_id)->toBe($accountant->getKey());
});

it('mencatat penutupan dan pembukaan kembali periode beserta alasannya', function (): void {
    [, $business] = $this->provisionBusinessWithOwner();
    $accountant = $this->addMember($business, RoleSlug::Accountant);

    $period = $this->periodFor($business, '2026-01-15');
    $service = app(App\Services\Accounting\AccountingPeriodService::class);

    $service->close($period, $accountant, 'Tutup buku Januari');
    $service->reopen($period->refresh(), $accountant, 'Ada koreksi faktur terlambat');

    $closed = AuditLog::query()
        ->where('action', 'accounting_period.closed')
        ->where('entity_id', $period->getKey())
        ->sole();

    expect($closed->reason)->toBe('Tutup buku Januari');
    expect($closed->after)->toMatchArray(['status' => 'closed']);

    $reopened = AuditLog::query()
        ->where('action', 'accounting_period.reopened')
        ->where('entity_id', $period->getKey())
        ->sole();

    expect($reopened->reason)->toBe('Ada koreksi faktur terlambat');
    expect($reopened->after)->toMatchArray(['status' => 'reopened']);
});

it('menolak mengubah audit log', function (): void {
    [, $business] = $this->provisionBusinessWithOwner();

    $log = AuditLog::query()->where('business_id', $business->getKey())->firstOrFail();

    expect(fn (): bool => $log->update(['action' => 'diubah']))
        ->toThrow(RuntimeException::class);

    expect($log->fresh()->action)->not->toBe('diubah');
});

it('menolak menghapus audit log', function (): void {
    [, $business] = $this->provisionBusinessWithOwner();

    $log = AuditLog::query()->where('business_id', $business->getKey())->firstOrFail();

    expect(fn (): ?bool => $log->delete())->toThrow(RuntimeException::class);

    expect(AuditLog::query()->whereKey($log->getKey())->exists())->toBeTrue();
});
