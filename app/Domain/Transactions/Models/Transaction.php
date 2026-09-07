<?php

declare(strict_types=1);

namespace App\Domain\Transactions\Models;

use App\Domain\Accounting\Models\EconomicEventPrediction;
use App\Domain\Accounting\Models\EconomicEventType;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Ai\Models\AiPrediction;
use App\Domain\Audit\Concerns\RecordsAuditTrail;
use App\Domain\Audit\Contracts\KeepsAuditSnapshot;
use App\Domain\Documents\Models\Document;
use App\Domain\Entities\Models\Entity;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Domain\Transactions\Enums\TransactionDirection;
use App\Domain\Transactions\Enums\TransactionSourceType;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Exceptions\InvalidTransactionTransition;
use App\Services\Accounting\JournalProposalService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * plan.md §23.5, §24.1, dan §8.2: transactions.
 *
 * Satu peristiwa keuangan yang terbaca dari dokumen, belum ditafsirkan maknanya. Nilai uang
 * dibaca sebagai string desimal, bukan float (plan.md §44.14).
 *
 * @property string $id
 * @property string $business_id
 * @property string $reference
 * @property Carbon $transaction_date
 * @property Carbon|null $posting_date
 * @property string $description
 * @property string $amount
 * @property TransactionDirection $direction
 * @property string $currency
 * @property string|null $counterparty_name
 * @property string|null $counterparty_entity_id
 * @property TransactionSourceType $source_type
 * @property TransactionStatus $status
 * @property string|null $economic_event_id
 * @property string|null $overall_confidence
 * @property string|null $review_reason
 * @property Carbon|null $normalized_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read int $evidence_count
 */
class Transaction extends Model implements KeepsAuditSnapshot
{
    use BelongsToBusiness, HasUuids, RecordsAuditTrail;

    protected $fillable = [
        'business_id',
        'reference',
        'transaction_date',
        'posting_date',
        'description',
        'amount',
        'direction',
        'currency',
        'counterparty_name',
        'counterparty_entity_id',
        'source_type',
        'status',
        'economic_event_id',
        'overall_confidence',
        'review_reason',
        'normalized_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'posting_date' => 'date',
            'direction' => TransactionDirection::class,
            'source_type' => TransactionSourceType::class,
            'status' => TransactionStatus::class,
            'normalized_at' => 'datetime',
        ];
    }

    /**
     * Sumber pembentuk transaksi, selalu tepat satu (plan.md §37 Phase 5).
     *
     * @return HasOne<TransactionSource, $this>
     */
    public function source(): HasOne
    {
        return $this->hasOne(TransactionSource::class);
    }

    /**
     * @return HasMany<TransactionEvidence, $this>
     */
    public function evidence(): HasMany
    {
        return $this->hasMany(TransactionEvidence::class);
    }

    /**
     * @return BelongsTo<Entity, $this>
     */
    public function counterpartyEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'counterparty_entity_id');
    }

    /**
     * @return BelongsTo<EconomicEventType, $this>
     */
    public function economicEvent(): BelongsTo
    {
        return $this->belongsTo(EconomicEventType::class, 'economic_event_id');
    }

    /**
     * @return HasMany<EconomicEventPrediction, $this>
     */
    public function eventPredictions(): HasMany
    {
        return $this->hasMany(EconomicEventPrediction::class)->latest();
    }

    /**
     * @return HasMany<TransactionRelation, $this>
     */
    public function outgoingRelations(): HasMany
    {
        return $this->hasMany(TransactionRelation::class, 'from_transaction_id');
    }

    /**
     * @return HasMany<TransactionRelation, $this>
     */
    public function incomingRelations(): HasMany
    {
        return $this->hasMany(TransactionRelation::class, 'to_transaction_id');
    }

    /**
     * @return MorphMany<AiPrediction, $this>
     */
    public function predictions(): MorphMany
    {
        return $this->morphMany(AiPrediction::class, 'subject')->latest();
    }

    /**
     * Journal yang diusulkan dari transaksi ini (plan.md §11).
     *
     * @return HasMany<JournalEntry, $this>
     */
    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class, 'source_id')
            ->where('source_type', JournalProposalService::SOURCE_TRANSACTION);
    }

    /**
     * Dokumen yang membentuk transaksi ini.
     *
     * Melalui `transaction_sources`, bukan foreign key langsung, karena Phase 7 akan
     * mengaitkan satu transaksi dengan beberapa dokumen — invoice beserta bukti
     * pembayarannya — dan hubungan itu tidak dapat diwakili satu kolom.
     *
     * @return HasOneThrough<Document, TransactionSource, $this>
     */
    public function sourceDocument(): HasOneThrough
    {
        return $this->hasOneThrough(
            Document::class,
            TransactionSource::class,
            'transaction_id',
            'id',
            'id',
            'document_id'
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function transitionTo(TransactionStatus $status, array $attributes = []): void
    {
        if ($this->status !== $status && ! $this->status->canTransitionTo($status)) {
            throw InvalidTransactionTransition::between($this->status, $status);
        }

        $this->fill($attributes);
        $this->status = $status;
        $this->save();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithStatus(Builder $query, TransactionStatus ...$statuses): Builder
    {
        return $query->whereIn(
            'status',
            array_map(static fn (TransactionStatus $status): string => $status->value, $statuses)
        );
    }

    /**
     * Transaksi yang berasal dari satu dokumen tertentu.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeFromDocument(Builder $query, Document $document): Builder
    {
        return $query->whereHas('source', function (Builder $source) use ($document): void {
            $source->where('document_id', $document->getKey());
        });
    }
}
