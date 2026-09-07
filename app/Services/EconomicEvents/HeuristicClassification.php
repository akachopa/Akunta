<?php

declare(strict_types=1);

namespace App\Services\EconomicEvents;

use App\Domain\Accounting\Enums\EconomicEventCode;

final class HeuristicClassification
{
    /**
     * @param  array<int, string>  $missingInformation
     */
    public function __construct(
        public readonly EconomicEventCode $event,
        public readonly string $confidence,
        public readonly string $reason,
        public readonly array $missingInformation = [],
    ) {}
}
