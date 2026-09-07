<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Documents\Models\Document;
use App\Domain\Tenancy\TenantContext;
use App\Services\DocumentProcessing\DocumentProcessingService;
use Illuminate\Support\Facades\Queue;

/**
 * plan.md §31 dan §45.6: operasi sensitif pada dokumen finansial harus tercatat.
 */
beforeEach(function (): void {
    $this->fakeDocumentDisk();
    Queue::fake();
});

/**
 * @return Illuminate\Database\Eloquent\Collection<int, AuditLog>
 */
function documentLogs(Document $document, string $action)
{
    return AuditLog::query()
        ->where('entity_type', Document::class)
        ->where('entity_id', $document->getKey())
        ->where('action', $action)
        ->get();
}

it('mencatat upload dokumen tanpa menyimpan isi berkasnya', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();

    $this->actingAs($owner)->post("/businesses/{$business->getKey()}/documents", [
        'files' => [$this->csvFile('mutasi.csv')],
    ])->assertRedirect();

    $document = Document::query()->withoutGlobalScopes()->sole();
    $log = documentLogs($document, 'document.uploaded')->sole();

    expect($log->business_id)->toBe($business->getKey());
    expect($log->actor_id)->toBe($owner->getKey());
    expect($log->after['reference'])->toBe($document->reference);
    expect($log->after['checksum_sha256'])->toBe($document->checksum_sha256);

    /*
     * plan.md §30 mewajibkan redaksi data sensitif: yang dicatat hanya identitas dan
     * checksum berkas, tidak isinya.
     */
    expect(json_encode($log->after))->not->toContain('SETORAN TUNAI');
});

it('mencatat setiap berkas pada upload multi-file secara terpisah', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();

    $this->actingAs($owner)->post("/businesses/{$business->getKey()}/documents", [
        'files' => [$this->csvFile('a.csv'), $this->csvFile('b.csv')],
    ])->assertRedirect();

    $logs = AuditLog::query()->where('action', 'document.uploaded')->get();

    expect($logs)->toHaveCount(2);
    expect($logs->pluck('entity_id')->unique())->toHaveCount(2);
});

it('mencatat proses ulang beserta status sebelumnya dan alasannya', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserSuccess();

    app(TenantContext::class)->withBusiness(
        $business,
        fn () => app(DocumentProcessingService::class)->process($document)
    );

    $this->actingAs($owner)->post(
        "/businesses/{$business->getKey()}/documents/{$document->getKey()}/reprocess",
        ['reason' => 'Parser diperbarui']
    )->assertRedirect();

    $log = documentLogs($document, 'document.reprocessed')->sole();

    expect($log->before['processing_status'])->toBe('classifying');
    expect($log->after['processing_status'])->toBe('queued');
    expect($log->reason)->toBe('Parser diperbarui');
    expect($log->actor_id)->toBe($owner->getKey());
});

it('mencatat pengarsipan dokumen', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->actingAs($owner)->post(
        "/businesses/{$business->getKey()}/documents/{$document->getKey()}/archive",
        ['reason' => 'Duplikat kiriman email']
    )->assertRedirect();

    $log = documentLogs($document, 'document.archived')->sole();

    expect($log->before['processing_status'])->toBe('queued');
    expect($log->after['processing_status'])->toBe('archived');
    expect($log->reason)->toBe('Duplikat kiriman email');
});

it('mencatat akses unduh berkas asli', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->actingAs($owner)
        ->get("/businesses/{$business->getKey()}/documents/{$document->getKey()}/download")
        ->assertRedirect();

    // Siapa mengakses dokumen finansial adalah bagian dari audit trail (plan.md §31).
    $log = documentLogs($document, 'document.downloaded')->sole();

    expect($log->actor_id)->toBe($owner->getKey());
    expect($log->after['checksum_sha256'])->toBe($document->checksum_sha256);
});

it('mencatat metadata request pada audit dokumen', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();

    $this->actingAs($owner)
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.7', 'HTTP_USER_AGENT' => 'Akunta-Test/1.0'])
        ->post("/businesses/{$business->getKey()}/documents", ['files' => [$this->csvFile()]])
        ->assertRedirect();

    // plan.md §31: IP/device metadata bila diperlukan.
    $log = AuditLog::query()->where('action', 'document.uploaded')->sole();

    expect($log->ip_address)->toBe('203.0.113.7');
    expect($log->user_agent)->toBe('Akunta-Test/1.0');
});

it('tidak mengizinkan audit log dokumen diubah maupun dihapus', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $this->ingestDocument($business, $owner);

    $log = AuditLog::query()->where('action', 'document.uploaded')->sole();

    // plan.md §44.7: audit history tidak boleh dihapus.
    expect(fn () => $log->update(['action' => 'diubah']))->toThrow(RuntimeException::class);
    expect(fn () => $log->delete())->toThrow(RuntimeException::class);
});
