<?php

declare(strict_types=1);

namespace App\Services\EntityResolution;

use App\Domain\Entities\Enums\EntityMatchMethod;
use App\Domain\Entities\Models\Entity;

/**
 * Hasil pencocokan satu nama ke entity yang sudah ada.
 */
final class MatchCandidate
{
    public function __construct(
        public readonly Entity $entity,
        public readonly EntityMatchMethod $method,
        public readonly string $confidence,
    ) {}
}
