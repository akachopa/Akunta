<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Hasil parsing deterministik dari AI worker (plan.md §13.1 PARSER ROUTER).
 *
 * DTO ini menutup respons HTTP worker dari domain: service dokumen tidak pernah membaca
 * array mentah, sehingga perubahan bentuk respons worker hanya berdampak di satu tempat.
 *
 * Tidak ada field klasifikasi maupun confidence di sini. Keduanya keluaran Phase 4, dan
 * parser deterministik memang tidak menghasilkannya (plan.md §13.2).
 */
final class DocumentParseResult
{
    /**
     * @param  array<int, array<string, mixed>>  $pages
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $parser,
        public readonly string $parserVersion,
        public readonly string $contentKind,
        public readonly int $pageCount,
        public readonly bool $needsOcr,
        public readonly array $pages,
        public readonly array $metadata = [],
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        /** @var array<int, array<string, mixed>> $pages */
        $pages = is_array($payload['pages'] ?? null) ? $payload['pages'] : [];

        /** @var array<string, mixed> $metadata */
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];

        return new self(
            parser: (string) ($payload['parser'] ?? 'unknown'),
            parserVersion: (string) ($payload['parser_version'] ?? '0'),
            contentKind: (string) ($payload['content_kind'] ?? 'unknown'),
            pageCount: (int) ($payload['page_count'] ?? count($pages)),
            needsOcr: (bool) ($payload['needs_ocr'] ?? false),
            pages: $pages,
            metadata: $metadata,
        );
    }

    /**
     * Metadata teknis yang dicatat pada document_processing_jobs.result.
     *
     * plan.md §45.9 meminta versi model/prompt disimpan; untuk tahap deterministik yang
     * setara adalah nama dan versi parser.
     *
     * @return array<string, mixed>
     */
    public function toJobResult(): array
    {
        return [
            'parser' => $this->parser,
            'parser_version' => $this->parserVersion,
            'content_kind' => $this->contentKind,
            'page_count' => $this->pageCount,
            'needs_ocr' => $this->needsOcr,
            'metadata' => $this->metadata,
        ];
    }
}
