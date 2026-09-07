<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Enums;

/**
 * State accounting period (plan.md §20.1).
 *
 * OPEN / REVIEWING / READY_TO_CLOSE / CLOSED / REOPENED
 */
enum AccountingPeriodStatus: string
{
    case Open = 'open';
    case Reviewing = 'reviewing';
    case ReadyToClose = 'ready_to_close';
    case Closed = 'closed';
    case Reopened = 'reopened';

    /**
     * plan.md §20.4: setelah CLOSED, journal tidak boleh diubah dan perubahan
     * membutuhkan reopen.
     */
    public function acceptsJournalActivity(): bool
    {
        return match ($this) {
            self::Closed => false,
            default => true,
        };
    }

    public function isClosed(): bool
    {
        return $this === self::Closed;
    }

    /**
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::Reviewing, self::ReadyToClose, self::Closed],
            self::Reviewing => [self::Open, self::ReadyToClose, self::Closed],
            self::ReadyToClose => [self::Reviewing, self::Closed],
            self::Closed => [self::Reopened],
            self::Reopened => [self::Reviewing, self::ReadyToClose, self::Closed],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
