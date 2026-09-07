<?php

declare(strict_types=1);

namespace App\Http\Presenters;

use App\Domain\Entities\Models\Entity;
use App\Domain\Entities\Models\EntityAlias;
use App\Domain\Entities\Models\EntityIdentifier;

class EntityPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function summary(Entity $entity): array
    {
        return [
            'id' => $entity->getKey(),
            'name' => $entity->name,
            'type' => $entity->type->value,
            'type_label' => $entity->type->label(),
            'status' => $entity->status->value,
            'status_label' => $entity->status->label(),
            'confirmed' => $entity->isConfirmed(),
            'transaction_count' => $entity->transactions_count ?? $entity->transactions()->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Entity $entity): array
    {
        return $this->summary($entity) + [
            'normalized_name' => $entity->normalized_name,
            'confirmed_at' => $entity->confirmed_at?->toIso8601String(),
            'merged_into_id' => $entity->merged_into_id,
            'aliases' => $entity->aliases->map(static fn (EntityAlias $alias): array => [
                'alias' => $alias->alias,
                'source' => $alias->source->value,
                'source_label' => $alias->source->label(),
            ])->all(),
            'identifiers' => $entity->identifiers->map(static fn (EntityIdentifier $identifier): array => [
                'kind' => $identifier->kind->value,
                'kind_label' => $identifier->kind->label(),
                'value' => $identifier->value,
            ])->all(),
        ];
    }
}
