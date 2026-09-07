<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Enums;

/**
 * State machine journal (plan.md §25.4).
 *
 * DRAFT → PENDING_APPROVAL → APPROVED → POSTED → REVERSED
 */
enum JournalEntryStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Posted = 'posted';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingApproval => 'Menunggu Persetujuan',
            self::Approved => 'Disetujui',
            self::Posted => 'Diposting',
            self::Reversed => 'Dibalik',
        };
    }

    /**
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::PendingApproval, self::Approved],
            self::PendingApproval => [self::Approved, self::Draft],
            self::Approved => [self::Posted, self::Draft],
            self::Posted => [self::Reversed],
            self::Reversed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * plan.md §40 "immutable posted journal data" dan §44.6 "jangan mengubah posted
     * journal tanpa reversal".
     */
    public function isImmutable(): bool
    {
        return match ($this) {
            self::Posted, self::Reversed => true,
            default => false,
        };
    }

    /**
     * Apakah baris entry ini sudah masuk ledger.
     *
     * plan.md §45.11 mewajibkan laporan hanya mengambil journal yang sudah diposting.
     * Entry berstatus `reversed` termasuk di dalamnya: barisnya pernah diposting dan
     * tetap berada di ledger. Yang menghapus pengaruhnya adalah reversal entry
     * pasangannya, bukan penghapusan entry aslinya (plan.md §44.6). Mengeluarkan entry
     * `reversed` dari laporan justru menyisakan sisi reversal tanpa sisi aslinya dan
     * membalik saldo akun.
     */
    public function affectsLedger(): bool
    {
        return match ($this) {
            self::Posted, self::Reversed => true,
            default => false,
        };
    }

    /**
     * Status yang barisnya sudah berada di ledger.
     *
     * @return array<int, string>
     */
    public static function ledgerStatuses(): array
    {
        return array_values(array_map(
            static fn (self $case): string => $case->value,
            array_filter(self::cases(), static fn (self $case): bool => $case->affectsLedger())
        ));
    }

    public function isEditable(): bool
    {
        return ! $this->isImmutable();
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
