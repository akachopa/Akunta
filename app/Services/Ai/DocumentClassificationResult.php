<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Domain\Documents\Enums\DocumentType;

/**
 * Hasil klasifikasi dokumen dari AI worker (plan.md §14.1).
 *
 * Jenis dokumen dan alternatifnya sudah dipetakan ke enum `DocumentType` di sini. Nilai di
 * luar taksonomi plan.md §7.1 tidak sampai ke domain: worker sudah menolaknya, dan
 * pemetaan ini adalah lapisan kedua yang memastikan Laravel tidak menyimpan jenis dokumen
 * yang tidak dikenalnya.
 */
final class DocumentClassificationResult
{
    /**
     * @param  array<int, array{document_type: DocumentType, confidence: string}>  $alternatives
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly DocumentType $documentType,
        public readonly string $confidence,
        public readonly ?string $reason,
        public readonly array $alternatives,
        public readonly string $provider,
        public readonly string $model,
        public readonly string $promptVersion,
        public readonly int $latencyMs,
        public readonly AiProviderUsage $usage,
        public readonly array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $documentType = DocumentType::tryFrom((string) ($payload['document_type'] ?? ''))
            ?? DocumentType::Unknown;

        return new self(
            documentType: $documentType,
            confidence: self::confidence($payload['confidence'] ?? 0),
            reason: is_string($payload['reason'] ?? null) ? $payload['reason'] : null,
            alternatives: self::alternatives($payload['alternatives'] ?? null),
            provider: (string) ($payload['provider'] ?? 'unknown'),
            model: (string) ($payload['model'] ?? 'unknown'),
            promptVersion: (string) ($payload['prompt_version'] ?? '0'),
            latencyMs: (int) ($payload['latency_ms'] ?? 0),
            usage: AiProviderUsage::fromArray(
                is_array($payload['usage'] ?? null) ? $payload['usage'] : null
            ),
            raw: is_array($payload['raw'] ?? null) ? $payload['raw'] : [],
        );
    }

    /**
     * @return array<int, array{document_type: DocumentType, confidence: string}>
     */
    private static function alternatives(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $alternatives = [];

        foreach ($payload as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $documentType = DocumentType::tryFrom((string) ($candidate['document_type'] ?? ''));

            if ($documentType === null) {
                continue;
            }

            $alternatives[] = [
                'document_type' => $documentType,
                'confidence' => self::confidence($candidate['confidence'] ?? 0),
            ];
        }

        return $alternatives;
    }

    private static function confidence(mixed $value): string
    {
        return sprintf('%.4F', is_numeric($value) ? (float) $value : 0.0);
    }
}
