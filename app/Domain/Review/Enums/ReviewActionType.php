<?php

declare(strict_types=1);

namespace App\Domain\Review\Enums;

/**
 * Tindakan pada review_tasks (plan.md §23 review_actions, §31 jejak keputusan).
 *
 * Append-only: setiap keputusan manusia tercatat, tidak ditimpa.
 */
enum ReviewActionType: string
{
    case Approve = 'approve';
    case Reject = 'reject';
    case Correct = 'correct';
    case Comment = 'comment';
    case Tag = 'tag';
    case Post = 'post';
    case Reopen = 'reopen';

    public function label(): string
    {
        return match ($this) {
            self::Approve => 'Menyetujui',
            self::Reject => 'Menolak',
            self::Correct => 'Mengoreksi',
            self::Comment => 'Berkomentar',
            self::Tag => 'Menandai',
            self::Post => 'Memposting',
            self::Reopen => 'Membuka kembali',
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
