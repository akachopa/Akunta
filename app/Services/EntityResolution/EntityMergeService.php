<?php

declare(strict_types=1);

namespace App\Services\EntityResolution;

use App\Domain\Entities\Enums\EntityAliasSource;
use App\Domain\Entities\Enums\EntityStatus;
use App\Domain\Entities\Models\Entity;
use App\Domain\Entities\Models\EntityAlias;
use App\Domain\Entities\Models\EntityIdentifier;
use App\Domain\Entities\Models\EntityRelationship;
use App\Domain\Transactions\Models\Transaction;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Penggabungan entity (plan.md §37 Phase 6 "merge entity mempunyai audit trail").
 *
 * Entity yang diserap tidak dihapus: statusnya `merged`, alias dan identitasnya pindah
 * ke entity yang bertahan, dan transaksinya mengikuti.
 */
class EntityMergeService
{
    public function __construct(
        private readonly EntityNameNormalizer $names,
        private readonly AuditLogger $audit,
    ) {}

    public function confirm(Entity $entity, User $actor, ?string $alias = null): Entity
    {
        if (! $entity->isActive()) {
            throw new RuntimeException('Entity yang sudah digabung tidak dapat dikonfirmasi.');
        }

        $before = [
            'confirmed_at' => $entity->confirmed_at?->toIso8601String(),
            'name' => $entity->name,
        ];

        $entity->forceFill([
            'confirmed_at' => Carbon::now(),
            'confirmed_by' => $actor->getKey(),
        ])->save();

        $confirmedAlias = $alias !== null && trim($alias) !== '' ? trim($alias) : $entity->name;
        $this->rememberConfirmedAlias($entity, $confirmedAlias);

        $this->audit->log(
            action: 'entity.confirmed',
            entity: $entity,
            before: $before,
            after: [
                'confirmed_at' => $entity->confirmed_at?->toIso8601String(),
                'alias' => $confirmedAlias,
            ],
            business: $entity->business,
            actor: $actor,
        );

        return $entity->refresh();
    }

    public function merge(Entity $source, Entity $target, User $actor): Entity
    {
        if ($source->getKey() === $target->getKey()) {
            throw new RuntimeException('Entity tidak dapat digabung ke dirinya sendiri.');
        }

        if ($source->business_id !== $target->business_id) {
            throw new RuntimeException('Entity hanya dapat digabung dalam bisnis yang sama.');
        }

        if (! $source->isActive() || ! $target->isActive()) {
            throw new RuntimeException('Hanya entity aktif yang dapat digabung.');
        }

        DB::transaction(function () use ($source, $target, $actor): void {
            Transaction::query()
                ->where('counterparty_entity_id', $source->getKey())
                ->update(['counterparty_entity_id' => $target->getKey()]);

            foreach ($source->aliases as $alias) {
                $this->moveAlias($alias, $target);
            }

            foreach ($source->identifiers as $identifier) {
                $this->moveIdentifier($identifier, $target);
            }

            $this->rememberConfirmedAlias($target, $source->name);

            EntityRelationship::query()->create([
                'business_id' => $source->business_id,
                'from_entity_id' => $source->getKey(),
                'to_entity_id' => $target->getKey(),
                'type' => 'merged_into',
                'note' => sprintf('%s digabung ke %s.', $source->name, $target->name),
                'created_at' => Carbon::now(),
            ]);

            $source->forceFill([
                'status' => EntityStatus::Merged,
                'merged_into_id' => $target->getKey(),
            ])->save();

            $this->audit->log(
                action: 'entity.merged',
                entity: $source,
                before: [
                    'status' => EntityStatus::Active->value,
                    'name' => $source->name,
                ],
                after: [
                    'status' => EntityStatus::Merged->value,
                    'merged_into_id' => $target->getKey(),
                    'surviving_name' => $target->name,
                ],
                business: $source->business,
                actor: $actor,
            );
        });

        return $target->refresh();
    }

    private function rememberConfirmedAlias(Entity $entity, string $alias): void
    {
        $normalized = $this->names->normalize($alias);

        if ($normalized === '') {
            return;
        }

        /** @var EntityAlias|null $existing */
        $existing = EntityAlias::query()
            ->withoutGlobalScopes()
            ->where('business_id', $entity->business_id)
            ->where('normalized_alias', $normalized)
            ->first();

        if ($existing instanceof EntityAlias) {
            if ($existing->entity_id === $entity->getKey() && $existing->source !== EntityAliasSource::UserConfirmed) {
                $existing->source = EntityAliasSource::UserConfirmed;
                $existing->save();
            }

            return;
        }

        EntityAlias::query()->create([
            'business_id' => $entity->business_id,
            'entity_id' => $entity->getKey(),
            'alias' => $alias,
            'normalized_alias' => $normalized,
            'source' => EntityAliasSource::UserConfirmed,
            'created_at' => Carbon::now(),
        ]);
    }

    private function moveAlias(EntityAlias $alias, Entity $target): void
    {
        $clash = EntityAlias::query()
            ->withoutGlobalScopes()
            ->where('business_id', $target->business_id)
            ->where('normalized_alias', $alias->normalized_alias)
            ->where('entity_id', '!=', $alias->entity_id)
            ->exists();

        if ($clash) {
            $alias->delete();

            return;
        }

        $alias->forceFill([
            'entity_id' => $target->getKey(),
            'source' => EntityAliasSource::Merged,
        ])->save();
    }

    private function moveIdentifier(EntityIdentifier $identifier, Entity $target): void
    {
        $clash = EntityIdentifier::query()
            ->withoutGlobalScopes()
            ->where('business_id', $target->business_id)
            ->where('kind', $identifier->kind->value)
            ->where('normalized_value', $identifier->normalized_value)
            ->where('entity_id', '!=', $identifier->entity_id)
            ->exists();

        if ($clash) {
            $identifier->delete();

            return;
        }

        $identifier->forceFill(['entity_id' => $target->getKey()])->save();
    }
}
