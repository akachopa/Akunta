<?php

declare(strict_types=1);

namespace App\Domain\Review\Enums;

use Illuminate\Database\Eloquent\Builder;

/**
 * Tab Review Center (plan.md §6, §16.2, §40 server-side filter).
 */
enum ReviewQueueFilter: string
{
    case NeedInformation = 'need-information';
    case Ready = 'ready';
    case Approved = 'approved';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::NeedInformation => 'Perlu Informasi',
            self::Ready => 'Siap Disetujui',
            self::Approved => 'Menunggu Posting',
            self::Completed => 'Selesai',
        };
    }

    /**
     * @param  Builder<\App\Domain\Review\Models\ReviewTask>  $query
     * @return Builder<\App\Domain\Review\Models\ReviewTask>
     */
    public function apply(Builder $query): Builder
    {
        return match ($this) {
            self::NeedInformation => $query
                ->where('status', ReviewTaskStatus::Open->value)
                ->whereIn('kind', [
                    ReviewTaskKind::NeedInformation->value,
                    ReviewTaskKind::Duplicate->value,
                ]),
            self::Ready => $query
                ->where('status', ReviewTaskStatus::Open->value)
                ->where('kind', ReviewTaskKind::ReadyForApproval->value),
            self::Approved => $query->where('status', ReviewTaskStatus::AwaitingPost->value),
            self::Completed => $query->whereIn('status', [
                ReviewTaskStatus::Completed->value,
                ReviewTaskStatus::Cancelled->value,
            ]),
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
