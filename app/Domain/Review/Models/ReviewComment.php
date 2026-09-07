<?php

declare(strict_types=1);

namespace App\Domain\Review\Models;

use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * plan.md §23: review_comments. Append-only (plan.md §31).
 *
 * @property string $id
 * @property string $business_id
 * @property string $review_task_id
 * @property string|null $author_id
 * @property string $body
 * @property Carbon $created_at
 */
class ReviewComment extends Model
{
    use BelongsToBusiness, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'business_id',
        'review_task_id',
        'author_id',
        'body',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new RuntimeException('Komentar review tidak dapat diubah (plan.md §31).');
        });

        static::deleting(static function (): void {
            throw new RuntimeException('Komentar review tidak dapat dihapus (plan.md §31).');
        });
    }

    /**
     * @return BelongsTo<ReviewTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(ReviewTask::class, 'review_task_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
