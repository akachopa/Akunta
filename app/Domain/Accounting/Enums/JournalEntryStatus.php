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
     * Hanya entry berstatus posted yang boleh masuk laporan (plan.md §45.11).
     */
    public function affectsLedger(): bool
    {
        return match ($this) {
            self::Posted, self::Reversed => true,
            default => false,
        };
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
