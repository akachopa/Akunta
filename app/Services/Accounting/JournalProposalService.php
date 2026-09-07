<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Domain\Accounting\Data\JournalEntryData;
use App\Domain\Accounting\Data\JournalLineData;
use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\EconomicEventCode;
use App\Domain\Accounting\Enums\JournalEntryStatus;
use App\Domain\Accounting\Enums\JournalLineSide;
use App\Domain\Accounting\Enums\RuleAmountSource;
use App\Domain\Accounting\Models\AccountingRule;
use App\Domain\Accounting\Models\AccountingRuleLine;
use App\Domain\Accounting\Models\ChartOfAccount;
use App\Domain\Accounting\Models\EconomicEventType;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Documents\Enums\DocumentFieldKey;
use App\Domain\Documents\Models\Document;
use App\Domain\Transactions\Models\Transaction;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Mengusulkan journal draft dari economic event (plan.md §11, §37 Phase 9).
 *
 * LLM tidak menulis baris jurnal. Rule merujuk account role, role di-resolve ke COA
 * bisnis, dan hasilnya selalu draft: posting tetap menunggu akuntan (plan.md §15.2).
 */
class JournalProposalService
{
    public const SOURCE_TRANSACTION = 'transaction';

    public function __construct(
        private readonly ChartOfAccountsService $accounts,
        private readonly JournalPostingService $journals,
    ) {}

    /**
     * @return array{journal_id: string|null, confidence: string|null, reason: string|null}
     */
    public function propose(Transaction $transaction): array
    {
        $this->discardDrafts($transaction);

        if ($transaction->economic_event_id === null) {
            return ['journal_id' => null, 'confidence' => null, 'reason' => 'Peristiwa ekonomi belum ditetapkan.'];
        }

        /** @var EconomicEventType $eventType */
        $eventType = $transaction->economicEvent;
        $rule = AccountingRule::query()
            ->where('event_type_id', $eventType->getKey())
            ->where('is_active', true)
            ->with('lines')
            ->first();

        if (! $rule instanceof AccountingRule) {
            return ['journal_id' => null, 'confidence' => '0.0000', 'reason' => 'Belum ada accounting rule untuk peristiwa ini.'];
        }

        $lines = $this->buildLines($transaction, $rule);

        if ($lines === []) {
            return ['journal_id' => null, 'confidence' => '0.5000', 'reason' => 'Rule tidak menghasilkan baris jurnal yang seimbang.'];
        }

        $entry = $this->journals->createDraft(
            $transaction->business,
            new JournalEntryData(
                entryDate: Carbon::parse($transaction->transaction_date->toDateString()),
                description: sprintf('%s · %s', $transaction->reference, $eventType->name),
                lines: $lines,
                sourceType: self::SOURCE_TRANSACTION,
                sourceId: $transaction->getKey(),
                currency: $transaction->currency,
                sources: [[
                    'source_type' => self::SOURCE_TRANSACTION,
                    'source_id' => $transaction->getKey(),
                    'reference' => $transaction->reference,
                ]],
            ),
        );

        return [
            'journal_id' => $entry->getKey(),
            'confidence' => '1.0000',
            'reason' => null,
        ];
    }

    /**
     * @return array<int, JournalLineData>
     */
    private function buildLines(Transaction $transaction, AccountingRule $rule): array
    {
        $prepared = [];

        foreach ($rule->lines as $line) {
            $amount = $this->amountFor($transaction, $line->amount_source);

            if ($amount === null) {
                if ($line->amount_source->isOptional()) {
                    continue;
                }

                return [];
            }

            if (Money::isZero($amount) && $line->amount_source->isOptional()) {
                continue;
            }

            $account = $this->accounts->resolveByRole($transaction->business, $line->account_role);

            if (! $account instanceof ChartOfAccount) {
                return [];
            }

            $prepared[] = [
                'line' => $line,
                'account' => $account,
                'amount' => Money::normalize($amount),
            ];
        }

        if (count($prepared) < 2) {
            return $this->fallbackTwoSided($transaction, $rule);
        }

        $data = [];

        foreach ($prepared as $item) {
            /** @var AccountingRuleLine $ruleLine */
            $ruleLine = $item['line'];
            /** @var ChartOfAccount $account */
            $account = $item['account'];

            $data[] = new JournalLineData(
                accountId: $account->getKey(),
                debit: $ruleLine->side === JournalLineSide::Debit ? $item['amount'] : '0',
                credit: $ruleLine->side === JournalLineSide::Credit ? $item['amount'] : '0',
                description: $ruleLine->description,
                entityId: $transaction->counterparty_entity_id,
            );
        }

        $draft = new JournalEntryData(
            entryDate: Carbon::parse($transaction->transaction_date->toDateString()),
            description: $transaction->description,
            lines: $data,
        );

        if ($draft->isBalanced()) {
            return $data;
        }

        return $this->fallbackTwoSided($transaction, $rule);
    }

    /**
     * @return array<int, JournalLineData>
     */
    private function fallbackTwoSided(Transaction $transaction, AccountingRule $rule): array
    {
        $debit = $rule->lines->first(
            static fn (AccountingRuleLine $line): bool => $line->side === JournalLineSide::Debit
                && $line->amount_source === RuleAmountSource::Transaction
        ) ?? $rule->lines->first(
            static fn (AccountingRuleLine $line): bool => $line->side === JournalLineSide::Debit
        );

        $credit = $rule->lines->first(
            static fn (AccountingRuleLine $line): bool => $line->side === JournalLineSide::Credit
                && $line->amount_source === RuleAmountSource::Transaction
        ) ?? $rule->lines->first(
            static fn (AccountingRuleLine $line): bool => $line->side === JournalLineSide::Credit
        );

        if (! $debit instanceof AccountingRuleLine || ! $credit instanceof AccountingRuleLine) {
            return [];
        }

        $debitAccount = $this->accounts->resolveByRole($transaction->business, $debit->account_role);
        $creditAccount = $this->accounts->resolveByRole($transaction->business, $credit->account_role);

        if (! $debitAccount instanceof ChartOfAccount || ! $creditAccount instanceof ChartOfAccount) {
            return [];
        }

        return [
            new JournalLineData(
                accountId: $debitAccount->getKey(),
                debit: $transaction->amount,
                description: $debit->description,
                entityId: $transaction->counterparty_entity_id,
            ),
            new JournalLineData(
                accountId: $creditAccount->getKey(),
                credit: $transaction->amount,
                description: $credit->description,
                entityId: $transaction->counterparty_entity_id,
            ),
        ];
    }

    private function amountFor(Transaction $transaction, RuleAmountSource $source): ?string
    {
        if ($source === RuleAmountSource::Transaction) {
            return $transaction->amount;
        }

        $field = match ($source) {
            RuleAmountSource::GrossAmount => DocumentFieldKey::GrossAmount,
            RuleAmountSource::MdrFee => DocumentFieldKey::MdrFee,
            RuleAmountSource::NetAmount => DocumentFieldKey::NetAmount,
            RuleAmountSource::Tax => DocumentFieldKey::Tax,
            RuleAmountSource::Subtotal => DocumentFieldKey::Subtotal,
        };

        $document = $transaction->sourceDocument;

        if (! $document instanceof Document) {
            return $source === RuleAmountSource::NetAmount || $source === RuleAmountSource::GrossAmount
                ? $transaction->amount
                : null;
        }

        $value = $document->fields()
            ->where('field_key', $field->value)
            ->whereNull('row_index')
            ->value('value_number');

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return match ($source) {
            RuleAmountSource::NetAmount, RuleAmountSource::GrossAmount => $transaction->amount,
            default => null,
        };
    }

    private function discardDrafts(Transaction $transaction): void
    {
        $drafts = JournalEntry::query()
            ->where('source_type', self::SOURCE_TRANSACTION)
            ->where('source_id', $transaction->getKey())
            ->where('status', JournalEntryStatus::Draft->value)
            ->get();

        foreach ($drafts as $draft) {
            $draft->delete();
        }
    }

    /**
     * Apakah rule untuk event ini menyentuh akun pendapatan atau beban.
     *
     * Dipakai test Phase 9: transfer internal, modal, dan pokok pinjaman tidak boleh
     * masuk P&L (plan.md §37 Phase 9, §44.3, §44.4).
     *
     * @return array<int, AccountType>
     */
    public function accountTypesFor(EconomicEventCode $event): array
    {
        $type = EconomicEventType::byCode($event);
        $rule = AccountingRule::query()->where('event_type_id', $type->getKey())->with('lines')->first();

        if (! $rule instanceof AccountingRule) {
            return [];
        }

        return $rule->lines
            ->map(static fn (AccountingRuleLine $line): AccountType => $line->account_role->expectedAccountType())
            ->unique()
            ->values()
            ->all();
    }
}
