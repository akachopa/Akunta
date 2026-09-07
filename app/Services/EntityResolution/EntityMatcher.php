<?php

declare(strict_types=1);

namespace App\Services\EntityResolution;

use App\Domain\Business\Models\Business;
use App\Domain\Entities\Enums\EntityAliasSource;
use App\Domain\Entities\Enums\EntityIdentifierKind;
use App\Domain\Entities\Enums\EntityMatchMethod;
use App\Domain\Entities\Enums\EntityStatus;
use App\Domain\Entities\Models\Entity;
use App\Domain\Entities\Models\EntityAlias;
use App\Domain\Entities\Models\EntityIdentifier;

/**
 * Pencocokan entity (plan.md §9.3).
 *
 * Urutan: identitas unik, alias terkonfirmasi, nama persis, riwayat, lalu kemiripan
 * fuzzy. Urutan itu membuat NPWP yang sama tidak pernah kalah oleh nama yang mirip.
 */
final class EntityMatcher
{
    public function __construct(private readonly EntityNameNormalizer $names) {}

    public function byIdentifier(Business $business, EntityIdentifierKind $kind, string $value): ?Entity
    {
        $normalized = $this->names->normalizeIdentifier($value);

        if ($normalized === '') {
            return null;
        }

        /** @var EntityIdentifier|null $identifier */
        $identifier = EntityIdentifier::query()
            ->forBusiness($business)
            ->where('kind', $kind->value)
            ->where('normalized_value', $normalized)
            ->first();

        $entity = $identifier?->entity;

        return $entity instanceof Entity && $entity->isActive() ? $entity : null;
    }

    public function byNormalizedName(Business $business, string $name): ?MatchCandidate
    {
        $normalized = $this->names->normalize($name);

        if ($normalized === '') {
            return null;
        }

        /** @var EntityAlias|null $confirmed */
        $confirmed = EntityAlias::query()
            ->forBusiness($business)
            ->where('normalized_alias', $normalized)
            ->where('source', EntityAliasSource::UserConfirmed->value)
            ->first();

        if ($confirmed instanceof EntityAlias && $confirmed->entity?->isActive()) {
            return new MatchCandidate($confirmed->entity, EntityMatchMethod::ConfirmedAlias, '0.99');
        }

        /** @var Entity|null $exact */
        $exact = Entity::query()
            ->forBusiness($business)
            ->where('status', EntityStatus::Active->value)
            ->where('normalized_name', $normalized)
            ->first();

        if ($exact instanceof Entity) {
            return new MatchCandidate($exact, EntityMatchMethod::NormalizedName, '0.97');
        }

        /** @var EntityAlias|null $alias */
        $alias = EntityAlias::query()
            ->forBusiness($business)
            ->where('normalized_alias', $normalized)
            ->first();

        if ($alias instanceof EntityAlias && $alias->entity?->isActive()) {
            $method = $alias->source === EntityAliasSource::UserConfirmed
                ? EntityMatchMethod::ConfirmedAlias
                : EntityMatchMethod::NormalizedName;
            $confidence = $alias->source === EntityAliasSource::UserConfirmed ? '0.99' : '0.96';

            return new MatchCandidate($alias->entity, $method, $confidence);
        }

        return $this->fuzzy($business, $normalized);
    }

    private function fuzzy(Business $business, string $normalized): ?MatchCandidate
    {
        $candidates = Entity::query()
            ->forBusiness($business)
            ->where('status', EntityStatus::Active->value)
            ->limit(200)
            ->get();

        $best = null;
        $bestScore = 0.0;

        foreach ($candidates as $entity) {
            similar_text($normalized, $entity->normalized_name, $percent);

            if ($percent > $bestScore) {
                $bestScore = $percent;
                $best = $entity;
            }
        }

        if (! $best instanceof Entity) {
            return null;
        }

        if ($bestScore >= 92) {
            return new MatchCandidate($best, EntityMatchMethod::Fuzzy, '0.93');
        }

        if ($bestScore >= 80) {
            return new MatchCandidate($best, EntityMatchMethod::Fuzzy, '0.82');
        }

        return null;
    }
}
