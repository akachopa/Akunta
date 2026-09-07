<?php

declare(strict_types=1);

namespace App\Domain\Audit\Concerns;

/**
 * Menyimpan nilai atribut sebelum sebuah update disimpan.
 *
 * Eloquent men-sinkronkan `original` sesaat setelah save, sehingga `getOriginal()` yang
 * dibaca dari luar sudah berisi nilai baru. plan.md §31 mewajibkan audit log memuat
 * before dan after, jadi nilai lama harus ditangkap saat event `updating` masih berjalan.
 *
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 */
trait RecordsAuditTrail
{
    /**
     * @var array<string, mixed>
     */
    protected array $auditOriginal = [];

    public static function bootRecordsAuditTrail(): void
    {
        static::updating(function (self $model): void {
            $model->auditOriginal = $model->getRawOriginal();
        });
    }

    /**
     * Nilai atribut mentah sebelum update terakhir disimpan.
     *
     * @return array<string, mixed>
     */
    public function auditOriginal(): array
    {
        return $this->auditOriginal;
    }
}
