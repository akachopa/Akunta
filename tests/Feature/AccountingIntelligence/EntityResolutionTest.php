<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Entities\Enums\EntityAliasSource;
use App\Domain\Entities\Enums\EntityStatus;
use App\Domain\Entities\Models\Entity;
use App\Domain\Entities\Models\EntityAlias;
use App\Domain\Transactions\Models\Transaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->fakeDocumentDisk();
    Queue::fake();
});

it('memakai alias yang dikonfirmasi pada transaksi berikutnya', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $first = $this->normalizedPurchaseInvoice($business, $owner);

    /** @var Transaction $transaction */
    $transaction = Transaction::query()->withoutGlobalScopes()->fromDocument($first)->first();
    $entity = $transaction->counterpartyEntity;

    expect($entity)->toBeInstanceOf(Entity::class);
    expect($entity->name)->toBe('PT Sumber Kertas');

    $this->actingAs($owner)
        ->post("/businesses/{$business->getKey()}/entities/{$entity->getKey()}/confirm", [
            'alias' => 'SUMBER KERTAS',
        ])
        ->assertRedirect();

    expect(
        EntityAlias::query()->withoutGlobalScopes()
            ->where('entity_id', $entity->getKey())
            ->where('source', EntityAliasSource::UserConfirmed->value)
            ->exists()
    )->toBeTrue();

    $secondFile = UploadedFile::fake()->createWithContent(
        'invoice-kedua.pdf',
        "%PDF-1.4\n2 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 2 0 R >>\n%%EOF\n"
    );
    $second = $this->ingestDocument($business, $owner, $secondFile);
    $this->fakeParserSuccess();
    $this->fakeClassifierSuccess('purchase_invoice', 0.97);
    $this->fakeExtractorResponse(
        [
            $this->extractedField('document_number', 'INV/2026/0099', 0.96),
            $this->extractedField('document_date', '2026-03-01', 0.96),
            $this->extractedField('issuer_name', 'SUMBER KERTAS', 0.96),
            $this->extractedField('currency', 'IDR', 0.96),
            $this->extractedField('subtotal', '2000000.00', 0.96),
            $this->extractedField('tax', '220000.00', 0.96),
            $this->extractedField('total', '2220000.00', 0.96),
        ],
        confidence: 0.96,
    );
    $this->runIntelligencePipeline($second);

    /** @var Transaction $next */
    $next = Transaction::query()->withoutGlobalScopes()->fromDocument($second)->first();

    expect($next->counterparty_entity_id)->toBe($entity->getKey());
});

it('menyimpan jejak audit saat entity digabung', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $first = $this->normalizedPurchaseInvoice($business, $owner);

    $otherFile = UploadedFile::fake()->createWithContent(
        'invoice-lain.pdf',
        "%PDF-1.4\n3 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 3 0 R >>\n%%EOF\n"
    );
    $second = $this->ingestDocument($business, $owner, $otherFile);
    $this->fakeParserSuccess();
    $this->fakeClassifierSuccess('purchase_invoice', 0.97);
    $this->fakeExtractorResponse(
        [
            $this->extractedField('document_number', 'INV/2026/0088', 0.96),
            $this->extractedField('document_date', '2026-03-02', 0.96),
            $this->extractedField('issuer_name', 'CV Kertas Jaya', 0.96),
            $this->extractedField('currency', 'IDR', 0.96),
            $this->extractedField('subtotal', '800000.00', 0.96),
            $this->extractedField('tax', '88000.00', 0.96),
            $this->extractedField('total', '888000.00', 0.96),
        ],
        confidence: 0.96,
    );
    $this->runIntelligencePipeline($second);

    $source = Transaction::query()->withoutGlobalScopes()->fromDocument($second)->first()->counterpartyEntity;
    $target = Transaction::query()->withoutGlobalScopes()->fromDocument($first)->first()->counterpartyEntity;

    $this->actingAs($owner)
        ->post("/businesses/{$business->getKey()}/entities/{$source->getKey()}/merge", [
            'target_id' => $target->getKey(),
        ])
        ->assertRedirect();

    expect($source->refresh()->status)->toBe(EntityStatus::Merged);
    expect($source->merged_into_id)->toBe($target->getKey());

    $log = AuditLog::query()->where('action', 'entity.merged')->sole();
    expect($log->entity_id)->toBe($source->getKey());
    expect($log->after['merged_into_id'])->toBe($target->getKey());
    expect($log->actor_id)->toBe($owner->getKey());
});

it('mengisolasi entity per tenant', function (): void {
    [$ownerA, $businessA] = $this->provisionBusinessWithOwner();
    [$ownerB, $businessB] = $this->provisionBusinessWithOwner('Toko Lain');

    $this->normalizedPurchaseInvoice($businessA, $ownerA);
    $this->normalizedPurchaseInvoice($businessB, $ownerB);

    $entityA = Entity::query()->withoutGlobalScopes()->where('business_id', $businessA->getKey())->first();
    $entityB = Entity::query()->withoutGlobalScopes()->where('business_id', $businessB->getKey())->first();

    expect($entityA->getKey())->not->toBe($entityB->getKey());
    expect($entityA->normalized_name)->toBe($entityB->normalized_name);

    $this->actingAs($ownerA)
        ->get("/businesses/{$businessA->getKey()}/entities/{$entityB->getKey()}")
        ->assertNotFound();
});
