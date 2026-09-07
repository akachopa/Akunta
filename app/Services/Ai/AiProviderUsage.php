<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Pemakaian sumber daya satu panggilan provider (plan.md §32.1).
 *
 * `cost` berupa string desimal. plan.md §44.14 melarang float untuk uang, dan biaya AI
 * tetap uang meski satuannya dolar; presisinya berada pada orde seperseribu sen sehingga
 * konversi float akan menghilangkan seluruh biaya per dokumen menjadi nol.
 */
final class AiProviderUsage
{
    public function __construct(
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly string $cost,
        public readonly string $currency,
    ) {}

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public static function fromArray(?array $payload): self
    {
        $payload ??= [];

        return new self(
            inputTokens: (int) ($payload['input_tokens'] ?? 0),
            outputTokens: (int) ($payload['output_tokens'] ?? 0),
            cost: is_scalar($payload['cost'] ?? null) ? (string) $payload['cost'] : '0',
            currency: is_string($payload['currency'] ?? null) ? $payload['currency'] : 'USD',
        );
    }
}
