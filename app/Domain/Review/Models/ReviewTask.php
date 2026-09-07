<?php

declare(strict_types=1);

namespace App\Domain\Review\Models;

use App\Domain\Documents\Models\Document;
use App\Domain\Review\Enums\ReviewSubjectType;
use App\Domain\Review\Enums\ReviewTaskKind;
use App\Domain\Review\Enums\ReviewTaskStatus;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Domain\Transactions\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * plan.md §23: review_tasks.
 *
 * @property string $id
 * @property string $business_id
 * @property ReviewSubjectType $subject_type
 * @property string|null $transaction_id
 * @property string|null $document_id
 * @property ReviewTaskKind $kind
 * @property ReviewTaskStatus $status
 * @property string|null $reason
 * @property string|null $assigned_to
 * @property Carbon $opened_at
 * @property string|null $completed_by
 * @property Carbon|null $completed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ReviewTask extends Model
{
    use BelongsToBusiness, HasUuids;

    protected $fillable = [
        'business_id',
        'subject_type',
        'transaction_id',
        'document_id',
        'kind',
        'status',
        'reason',
        'assigned_to',
        'opened_at',
        'completed_by',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subject_type' => ReviewSubjectType::class,
            'kind' => ReviewTaskKind::class,
            'status' => ReviewTaskStatus::class,
            'opened_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * @return HasMany<ReviewAction, $this>
     */
    public function actions(): HasMany
    {
        return $this->hasMany(ReviewAction::class)->orderBy('created_at');
    }

    /**
     * @return HasMany<ReviewComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(ReviewComment::class)->orderBy('created_at');
    }
}
