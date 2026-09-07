<?php

declare(strict_types=1);

namespace App\Services\EntityResolution;

use App\Domain\Documents\Enums\DocumentFieldKey;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentField;
use App\Domain\Entities\Enums\EntityAliasSource;
use App\Domain\Entities\Enums\EntityIdentifierKind;
use App\Domain\Entities\Enums\EntityMatchMethod;
use App\Domain\Entities\Enums\EntityStatus;
use App\Domain\Entities\Enums\EntityType;
use App\Domain\Entities\Models\Entity;
use App\Domain\Entities\Models\EntityAlias;
use App\Domain\Entities\Models\EntityIdentifier;
use App\Domain\Transactions\Models\Transaction;
use Illuminate\Support\Carbon;

/**
 * Resolusi pihak lawan transaksi menjadi entity master (plan.md §9, §37 Phase 6).
 */
class EntityResolutionService
{
    public function __construct(
        private readonly EntityNameNormalizer $names,
        private readonly EntityMatcher $matcher,
    ) {}

    /**
     * @return array{entity_id: string|null, method: string|null, confidence: string|null}
     */
    public function resolve(Transaction $transaction): array
    {
        if ($transaction->counterparty_entity_id !== null) {
            $entity = $transaction->counterpartyEntity;

            return [
                'entity_id' => $transaction->counterparty_entity_id,
                'method' => EntityMatchMethod::Historical->value,
                'confidence' => $entity instanceof Entity && $entity->isConfirmed() ? '0.99' : '0.90',
            ];
        }

        $document = $transaction->sourceDocument;
        $name = $this->nameFor($transaction, $document);

        if ($name === null) {
            return ['entity_id' => null, 'method' => null, 'confidence' => null];
        }

        $type = $this->typeFor($document);
        $identifier = $this->merchantIdentifier($document);

        $match = $identifier !== null
            ? $this->matcher->byIdentifier($transaction->business, EntityIdentifierKind::MerchantId, $identifier)
            : null;

        if ($match instanceof Entity) {
            $this->attach($transaction, $match, $name, $identifier);

            return [
                'entity_id' => $match->getKey(),
                'method' => EntityMatchMethod::Identifier->value,
                'confidence' => '0.99',
            ];
        }

        $candidate = $this->matcher->byNormalizedName($transaction->business, $name);

        if ($candidate instanceof MatchCandidate) {
            $this->attach($transaction, $candidate->entity, $name, $identifier);

            return [
                'entity_id' => $candidate->entity->getKey(),
                'method' => $candidate->method->value,
                'confidence' => $candidate->confidence,
            ];
        }

        $entity = $this->create($transaction, $type, $name, $identifier);
        $this->attach($transaction, $entity, $name, $identifier);

        return [
            'entity_id' => $entity->getKey(),
            'method' => EntityMatchMethod::Created->value,
            'confidence' => null,
        ];
    }

    private function nameFor(Transaction $transaction, ?Document $document): ?string
    {
        if ($transaction->counterparty_name !== null && trim($transaction->counterparty_name) !== '') {
            return trim($transaction->counterparty_name);
        }

        if ($document?->document_type === DocumentType::BankStatement) {
            return $this->names->fromBankDescription($transaction->description);
        }

        return null;
    }

    private function typeFor(?Document $document): EntityType
    {
        return match ($document?->document_type) {
            DocumentType::SalesInvoice, DocumentType::CashReceipt, DocumentType::ReceivablePaymentReceipt => EntityType::Customer,
            DocumentType::PurchaseInvoice, DocumentType::Receipt, DocumentType::GoodsReceipt => EntityType::Supplier,
            DocumentType::Payroll => EntityType::Employee,
            DocumentType::CapitalDeposit => EntityType::Owner,
            DocumentType::MarketplaceReport => EntityType::Marketplace,
            DocumentType::QrisSettlement, DocumentType::EwalletSettlement => EntityType::PaymentGateway,
            DocumentType::UtilityBill, DocumentType::TaxDocument => EntityType::Government,
            DocumentType::AdvertisingInvoice => EntityType::Platform,
            default => EntityType::Other,
        };
    }

    private function merchantIdentifier(?Document $document): ?string
    {
        if (! $document instanceof Document) {
            return null;
        }

        /** @var DocumentField|null $field */
        $field = $document->fields()
            ->where('field_key', DocumentFieldKey::MerchantId->value)
            ->whereNull('row_index')
            ->first();

        $value = $field?->displayValue();

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function attach(Transaction $transaction, Entity $entity, string $name, ?string $identifier): void
    {
        $transaction->forceFill([
            'counterparty_entity_id' => $entity->getKey(),
            'counterparty_name' => $transaction->counterparty_name ?: $name,
        ])->save();

        $this->rememberAlias($entity, $name, EntityAliasSource::Extracted);

        if ($identifier !== null) {
            $this->rememberIdentifier($entity, EntityIdentifierKind::MerchantId, $identifier);
        }
    }

    private function create(Transaction $transaction, EntityType $type, string $name, ?string $identifier): Entity
    {
        /** @var Entity $entity */
        $entity = Entity::query()->create([
            'business_id' => $transaction->business_id,
            'type' => $type,
            'name' => $name,
            'normalized_name' => $this->names->normalize($name),
            'status' => EntityStatus::Active,
        ]);

        $this->rememberAlias($entity, $name, EntityAliasSource::Extracted);

        if ($identifier !== null) {
            $this->rememberIdentifier($entity, EntityIdentifierKind::MerchantId, $identifier);
        }

        return $entity;
    }

    private function rememberAlias(Entity $entity, string $alias, EntityAliasSource $source): void
    {
        $normalized = $this->names->normalize($alias);

        if ($normalized === '') {
            return;
        }

        $existing = EntityAlias::query()
            ->withoutGlobalScopes()
            ->where('business_id', $entity->business_id)
            ->where('normalized_alias', $normalized)
            ->first();

        if ($existing instanceof EntityAlias) {
            return;
        }

        EntityAlias::query()->create([
            'business_id' => $entity->business_id,
            'entity_id' => $entity->getKey(),
            'alias' => $alias,
            'normalized_alias' => $normalized,
            'source' => $source,
            'created_at' => Carbon::now(),
        ]);
    }

    private function rememberIdentifier(Entity $entity, EntityIdentifierKind $kind, string $value): void
    {
        $normalized = $this->names->normalizeIdentifier($value);

        if ($normalized === '') {
            return;
        }

        $existing = EntityIdentifier::query()
            ->withoutGlobalScopes()
            ->where('business_id', $entity->business_id)
            ->where('kind', $kind->value)
            ->where('normalized_value', $normalized)
            ->first();

        if ($existing instanceof EntityIdentifier) {
            return;
        }

        EntityIdentifier::query()->create([
            'business_id' => $entity->business_id,
            'entity_id' => $entity->getKey(),
            'kind' => $kind,
            'value' => $value,
            'normalized_value' => $normalized,
            'created_at' => Carbon::now(),
        ]);
    }
}
