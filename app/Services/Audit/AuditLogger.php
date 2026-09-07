<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Domain\Audit\Contracts\KeepsAuditSnapshot;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Business\Models\Business;
use App\Models\User;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Satu-satunya jalur penulisan audit trail (plan.md §31, §45.6).
 *
 * Field yang dicatat mengikuti plan.md §31: actor, action, entity_type, entity_id,
 * before, after, reason, IP/device metadata, timestamp.
 */
class AuditLogger
{
    public function __construct(private readonly ?Request $request = null) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function log(
        string $action,
        Model $entity,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        ?Business $business = null,
        ?User $actor = null,
    ): AuditLog {
        $actor ??= $this->resolveActor();
        $business ??= $this->resolveBusinessFrom($entity);

        return AuditLog::create([
            'business_id' => $business?->getKey(),
            'organization_id' => $business?->organization_id,
            'actor_id' => $actor?->getKey(),
            'actor_label' => $actor?->name,
            'action' => $action,
            'entity_type' => $entity::class,
            'entity_id' => $entity->getKey(),
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'ip_address' => $this->request?->ip(),
            'user_agent' => $this->truncateUserAgent($this->request?->userAgent()),
        ]);
    }

    /**
     * Mencatat perubahan model berdasarkan atribut yang benar-benar berubah.
     *
     * Dipakai untuk update di mana "before/after penuh" akan membuat audit log sulit
     * dibaca (plan.md §31 mencontohkan pencatatan perubahan, bukan snapshot penuh).
     */
    public function logChanges(
        string $action,
        Model $entity,
        ?string $reason = null,
        ?Business $business = null,
        ?User $actor = null,
    ): AuditLog {
        $changed = array_keys($entity->getChanges());
        $changed = array_values(array_diff($changed, ['updated_at']));

        /*
         * Nilai lama diambil dari snapshot RecordsAuditTrail karena `original` milik
         * Eloquent sudah tersinkron dengan nilai baru begitu save selesai.
         */
        $original = $entity instanceof KeepsAuditSnapshot ? $entity->auditOriginal() : [];

        $before = [];
        $after = [];

        foreach ($changed as $attribute) {
            $before[$attribute] = $this->stringify(
                array_key_exists($attribute, $original)
                    ? $original[$attribute]
                    : $entity->getOriginal($attribute)
            );
            $after[$attribute] = $this->stringify($entity->getAttribute($attribute));
        }

        return $this->log($action, $entity, $before, $after, $reason, $business, $actor);
    }

    private function resolveActor(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    private function resolveBusinessFrom(Model $entity): ?Business
    {
        if ($entity instanceof Business) {
            return $entity;
        }

        $businessId = $entity->getAttribute('business_id');

        if (! is_string($businessId)) {
            return null;
        }

        return Business::query()->withTrashed()->find($businessId);
    }

    private function truncateUserAgent(?string $userAgent): ?string
    {
        if ($userAgent === null) {
            return null;
        }

        return mb_substr($userAgent, 0, 512);
    }

    private function stringify(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
            is_scalar($value), $value === null, is_array($value) => $value,
            default => (string) $value,
        };
    }
}
