<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Domain\Documents\Enums\DocumentFieldKey;

/**
 * Hasil ekstraksi terstruktur dari AI worker (plan.md §14.2).
 *
 * `valid` adalah verdict worker, bukan verdict akhir. Laravel memvalidasi ulang isinya
 * sebelum menyimpan apa pun: worker berada di luar batas kepercayaan domain akuntansi, dan
 * plan.md §44.2 melarang output model masuk ke pembukuan tanpa lapisan aturan di
 * antaranya. Verdict worker dipakai sebagai alasan penolakan yang dapat dibaca reviewer,
 * bukan sebagai izin masuk.
 */
final class DocumentExtractionResult
{
    /**
     * @param  array<int, ExtractedFieldValue>  $fields
     * @param  array<int, array<int, ExtractedFieldValue>>  $rows
     * @param  array<int, string>  $validationErrors
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $extractor,
        public readonly string $schemaVersion,
        public readonly bool $valid,
        public readonly string $confidence,
        public readonly array $fields,
        public readonly array $rows,
        public readonly array $validationErrors,
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
        return new self(
            extractor: (string) ($payload['extractor'] ?? 'unknown'),
            schemaVersion: (string) ($payload['schema_version'] ?? '0'),
            valid: (bool) ($payload['valid'] ?? false),
            confidence: sprintf('%.4F', is_numeric($payload['confidence'] ?? null) ? (float) $payload['confidence'] : 0.0),
            fields: self::fields($payload['fields'] ?? null),
            rows: self::rows($payload['rows'] ?? null),
            validationErrors: self::errors($payload['validation_errors'] ?? null),
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
     * @return array<int, ExtractedFieldValue>
     */
    private static function fields(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $fields = [];

        foreach ($payload as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $key = DocumentFieldKey::tryFrom((string) ($entry['key'] ?? ''));

            /*
             * Kunci di luar enum dibuang, bukan disimpan sebagai teks bebas. Field
             * karangan yang tersimpan akan terbaca sebagai data akuntansi oleh phase
             * berikutnya, dan tidak ada satu pun tempat yang dapat memeriksa artinya.
             */
            if ($key === null) {
                continue;
            }

            $fields[] = self::value($key, $entry);
        }

        return $fields;
    }

    /**
     * Baris mutasi rekening koran (plan.md §8.4 `row_reference`).
     *
     * @return array<int, array<int, ExtractedFieldValue>>
     */
    private static function rows(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $rows = [];
        $index = 0;

        foreach ($payload as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $values = [];

            foreach (DocumentFieldKey::rowFields() as $key) {
                $raw = $entry[$key->value] ?? null;

                $values[] = new ExtractedFieldValue(
                    key: $key,
                    value: is_scalar($raw) ? (string) $raw : null,
                    confidence: sprintf('%.4F', $raw === null ? 0.0 : 1.0),
                    pageNumber: is_int($entry['page_number'] ?? null) ? $entry['page_number'] : null,
                    sourceText: is_string($entry['source_text'] ?? null) ? $entry['source_text'] : null,
                    rowIndex: $index,
                );
            }

            $rows[] = $values;
            $index++;
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private static function value(DocumentFieldKey $key, array $entry): ExtractedFieldValue
    {
        $raw = $entry['value'] ?? null;

        return new ExtractedFieldValue(
            key: $key,
            value: is_scalar($raw) ? (string) $raw : null,
            confidence: sprintf('%.4F', is_numeric($entry['confidence'] ?? null) ? (float) $entry['confidence'] : 0.0),
            pageNumber: is_int($entry['page_number'] ?? null) ? $entry['page_number'] : null,
            sourceText: is_string($entry['source_text'] ?? null) ? $entry['source_text'] : null,
        );
    }

    /**
     * @return array<int, string>
     */
    private static function errors(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $error): ?string => is_string($error) ? $error : null, $payload),
            static fn (?string $error): bool => $error !== null,
        ));
    }
}
