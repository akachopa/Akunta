<?php

declare(strict_types=1);

use App\Domain\Review\Enums\ReviewActionType;
use App\Domain\Review\Enums\ReviewQueueFilter;
use App\Domain\Review\Enums\ReviewSubjectType;
use App\Domain\Review\Enums\ReviewTaskKind;
use App\Domain\Review\Enums\ReviewTaskStatus;

it('mengunci nilai enum Review Center sesuai plan.md §16.2', function (): void {
    expect(ReviewTaskStatus::values())->toEqualCanonicalizing([
        'open',
        'awaiting_post',
        'completed',
        'cancelled',
    ]);

    expect(ReviewTaskKind::values())->toEqualCanonicalizing([
        'need_information',
        'ready_for_approval',
        'duplicate',
    ]);

    expect(ReviewSubjectType::values())->toEqualCanonicalizing([
        'transaction',
        'document',
    ]);

    expect(ReviewActionType::values())->toEqualCanonicalizing([
        'approve',
        'reject',
        'correct',
        'comment',
        'tag',
        'post',
        'reopen',
    ]);

    expect(ReviewQueueFilter::values())->toEqualCanonicalizing([
        'need-information',
        'ready',
        'approved',
        'completed',
    ]);
});

it('menandai open dan awaiting_post sebagai tugas yang masih hidup', function (): void {
    expect(ReviewTaskStatus::Open->isOpen())->toBeTrue();
    expect(ReviewTaskStatus::AwaitingPost->isOpen())->toBeTrue();
    expect(ReviewTaskStatus::Completed->isOpen())->toBeFalse();
    expect(ReviewTaskStatus::Cancelled->isOpen())->toBeFalse();
});
