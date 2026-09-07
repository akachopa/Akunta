<?php

declare(strict_types=1);

namespace App\Domain\Audit\Contracts;

/**
 * Model yang menyimpan nilai atribut sebelum update terakhir, sehingga AuditLogger
 * dapat mencatat pasangan before/after sesuai plan.md §31.
 */
interface KeepsAuditSnapshot
{
    /**
     * @return array<string, mixed>
     */
    public function auditOriginal(): array;
}
