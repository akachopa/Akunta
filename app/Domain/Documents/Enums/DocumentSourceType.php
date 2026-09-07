<?php

declare(strict_types=1);

namespace App\Domain\Documents\Enums;

/**
 * Asal dokumen (plan.md §8.1 `source_type`).
 *
 * Phase 3 hanya mendukung `upload` (plan.md §3.1 Document Inbox). Email intake dan
 * integrasi pihak ketiga ada di plan.md §42 Future Integrations, jadi nilainya
 * disediakan tetapi belum ada jalur masuk yang membuatnya.
 */
enum DocumentSourceType: string
{
    case Upload = 'upload';
    case Email = 'email';
    case Api = 'api';

    public function label(): string
    {
        return match ($this) {
            self::Upload => 'Unggah Manual',
            self::Email => 'Email',
            self::Api => 'Integrasi API',
        };
    }

    public function isAvailable(): bool
    {
        return $this === self::Upload;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
