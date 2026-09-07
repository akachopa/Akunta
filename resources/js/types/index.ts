import type { ComponentType, ReactNode } from 'react';

export type PageComponent = ComponentType<Record<string, unknown>> & {
    layout?: (children: ReactNode) => ReactNode;
};

export interface AuthUser {
    id: string;
    name: string;
    email: string;
    is_platform_admin: boolean;
}

export interface BusinessSummary {
    id: string;
    name: string;
}

export interface CurrentBusiness extends BusinessSummary {
    currency: string;
    business_type: string;
}

/**
 * Prop yang dibagikan HandleInertiaRequests ke setiap halaman.
 */
export interface SharedPageProps {
    auth: { user: AuthUser | null };
    currentBusiness: CurrentBusiness | null;
    businesses: BusinessSummary[];
    permissions: string[];
    flash: { success: string | null; error: string | null };
    [key: string]: unknown;
}

export type AccountType =
    | 'asset'
    | 'liability'
    | 'equity'
    | 'revenue'
    | 'cost_of_sales'
    | 'operating_expense'
    | 'other_income'
    | 'other_expense';

export type JournalEntryStatus = 'draft' | 'pending_approval' | 'approved' | 'posted' | 'reversed';

export type AccountingPeriodStatus =
    'open' | 'reviewing' | 'ready_to_close' | 'closed' | 'reopened';

/**
 * plan.md §25.1 ditambah UNSUPPORTED dari plan.md §5.3.
 */
export type DocumentStatus =
    | 'uploaded'
    | 'queued'
    | 'parsing'
    | 'classifying'
    | 'extracting'
    | 'normalizing'
    | 'matching'
    | 'ready'
    | 'need_review'
    | 'unsupported'
    | 'failed'
    | 'archived';

export type DocumentTypeSource = 'user' | 'ai' | 'review';

/**
 * plan.md §15.2.
 */
export type ConfidenceBand = 'ready' | 'review_recommended' | 'human_confirmation_required';

export interface ConfidenceAssessment {
    score: string;
    band: ConfidenceBand;
    band_label: string;
    components: Record<string, string | null>;
    auto_ready_threshold: string;
    review_threshold: string;
}

export interface DocumentSummary {
    id: string;
    reference: string;
    original_filename: string;
    file_extension: string;
    mime_type: string;
    byte_size: number;
    source_type: string;
    processing_status: DocumentStatus;
    processing_status_label: string;
    is_processing: boolean;
    needs_attention: boolean;
    is_retryable: boolean;
    document_type: string | null;
    document_type_label: string | null;
    document_type_source: DocumentTypeSource | null;
    document_type_source_label: string | null;
    page_count: number | null;
    content_kind: string | null;
    needs_ocr: boolean;

    /*
     * Proyeksi canonical plan.md §8.1. Nilai uang berupa string: plan.md §44.14 melarang
     * float untuk uang, dan number JavaScript adalah float.
     */
    document_date: string | null;
    currency: string | null;
    subtotal: string | null;
    tax: string | null;
    total: string | null;

    classification_confidence: string | null;
    extraction_confidence: string | null;
    confidence: ConfidenceAssessment | null;
    review_reason: string | null;

    uploaded_at: string;
    parsed_at: string | null;
    classified_at: string | null;
    extracted_at: string | null;
    reviewed_at: string | null;
    failure_reason: string | null;
    archived_at: string | null;
}

export interface DocumentPage {
    id: string;
    page_number: number;
    kind: 'pdf_page' | 'sheet' | 'table' | 'image';
    label: string | null;
    is_tabular: boolean;
    text: string | null;
    char_count: number;
    rows: (string | null)[][] | null;
    row_count: number | null;
    needs_ocr: boolean;
    metadata: Record<string, unknown> | null;
}

export interface DocumentProcessingJob {
    id: string;
    stage: string;
    stage_label: string;
    stage_phase: number;
    stage_implemented: boolean;
    status: string;
    status_label: string;
    attempt: number;
    queued_at: string | null;
    started_at: string | null;
    finished_at: string | null;
    duration_ms: number | null;
    error_message: string | null;
    result: Record<string, unknown> | null;
}

export type DocumentFieldKind = 'text' | 'money' | 'date' | 'integer';

/**
 * Satu nilai hasil ekstraksi beserta buktinya (plan.md §45.10, §45.12).
 */
export interface DocumentField {
    id: string;
    key: string;
    label: string;
    kind: DocumentFieldKind;
    value: string | null;
    confidence: string;
    source: 'ai' | 'review';
    source_label: string;
    page_number: number | null;
    source_text: string | null;
    is_confirmed: boolean;
    confirmed_by: string | null;
    confirmed_at: string | null;
}

export interface DocumentStatementRow {
    row_index: number;
    page_number: number | null;
    values: Record<string, string | null>;
}

export interface DocumentExtractionAttempt {
    id: string;
    attempt: number;
    document_type: string;
    document_type_label: string;
    extractor: string;
    schema_version: string;
    status: 'accepted' | 'rejected';
    status_label: string;
    confidence: string | null;
    validation_errors: string[];
    created_at: string;
}

export interface DocumentClassification {
    predicted_value: string;
    predicted_label: string;
    confidence: string;
    reason: string | null;
    status: string;
    status_label: string;
    created_at: string;
    candidates: { value: string; label: string; confidence: string; rank: number }[];
}

export interface DocumentTypeOption {
    value: string;
    label: string;
}

export interface DocumentDetail extends DocumentSummary {
    checksum_sha256: string;
    uploaded_by: string | null;
    archived_by: string | null;
    reviewed_by: string | null;
    pages: DocumentPage[];
    processing_jobs: DocumentProcessingJob[];
    fields: DocumentField[];
    rows: DocumentStatementRow[];
    extractions: DocumentExtractionAttempt[];
    classification: DocumentClassification | null;
    documentTypes: DocumentTypeOption[];
    transactions: TransactionSummary[];
}

export type TransactionDirection = 'inflow' | 'outflow';

export type TransactionSourceType =
    'bank_statement' | 'invoice' | 'receipt' | 'settlement' | 'spreadsheet';

/**
 * Transaksi canonical hasil normalisasi (plan.md §8.2).
 *
 * `amount` selalu positif dan bertanda lewat `direction`. Nilainya string karena plan.md
 * §44.14 melarang float untuk uang, dan number JavaScript adalah float.
 */
export interface TransactionSummary {
    id: string;
    reference: string;
    transaction_date: string;
    posting_date: string | null;
    description: string;
    amount: string;
    direction: TransactionDirection;
    direction_label: string;
    currency: string;
    counterparty_name: string | null;
    counterparty_entity: {
        id: string;
        name: string;
        type: string;
        type_label: string;
        confirmed: boolean;
    } | null;
    economic_event: {
        id: string;
        code: string;
        name: string;
    } | null;
    source_type: TransactionSourceType;
    source_type_label: string;
    status: string;
    status_label: string;
    needs_attention: boolean;
    overall_confidence: string | null;
    review_reason: string | null;
    normalized_at: string | null;
    source?: TransactionSource | null;
}

export interface TransactionSource {
    source_type: TransactionSourceType;
    source_type_label: string;
    source_reference: string;
    row_index: number | null;
    page_number: number | null;
    document: {
        id: string;
        reference: string;
        original_filename: string;
        document_type: string | null;
        document_type_label: string | null;
    } | null;
}

export interface TransactionEvidence {
    id: string;
    type: 'document' | 'bank_row' | 'spreadsheet_row' | 'document_field';
    type_label: string;
    document_id: string;
    row_reference: string | null;
    page_number: number | null;
    field_key: string | null;
    field_label: string | null;
    note: string | null;
}

export interface TransactionRelationView {
    type: 'duplicate' | 'related';
    type_label: string;
    confidence: string;
    reasons: string[];
    other: {
        id: string;
        reference: string;
        description: string;
        amount: string;
    } | null;
}

export interface TransactionJournalLine {
    account_code: string | null;
    account_name: string | null;
    account_type: AccountType | null;
    debit: string;
    credit: string;
    description: string | null;
}

export interface TransactionJournal {
    id: string;
    entry_number: string;
    status: JournalEntryStatus;
    status_label: string;
    total_debit: string;
    total_credit: string;
    lines: TransactionJournalLine[];
}

export interface TransactionDetail extends TransactionSummary {
    source: TransactionSource | null;
    evidence: TransactionEvidence[];
    relations: TransactionRelationView[];
    journal: TransactionJournal | null;
    tags: string[];
    event_options: { value: string; label: string }[];
}

export interface ReviewTaskSummary {
    id: string;
    subject_type: 'transaction' | 'document';
    subject_type_label: string;
    kind: string;
    kind_label: string;
    status: string;
    status_label: string;
    reason: string | null;
    opened_at: string | null;
    completed_at: string | null;
    transaction: TransactionSummary | null;
    document: {
        id: string;
        reference: string;
        original_filename: string;
        processing_status: string;
        processing_status_label: string;
        review_reason: string | null;
    } | null;
}

export interface ReviewTaskDetail extends ReviewTaskSummary {
    transaction_detail: TransactionDetail | null;
    actions: {
        id: string;
        action: string;
        action_label: string;
        reason: string | null;
        actor_name: string | null;
        created_at: string | null;
    }[];
    comments: {
        id: string;
        body: string;
        author_name: string | null;
        created_at: string | null;
    }[];
}

export interface TrialBalanceRow {
    account_id: string;
    code: string;
    name: string;
    account_type: AccountType;
    normal_balance: 'debit' | 'credit';
    account_role: string | null;
    movement_debit: string;
    movement_credit: string;
    debit_balance: string;
    credit_balance: string;
    signed_balance: string;
}

export interface TrialBalanceReport {
    business_id: string;
    from: string;
    to: string;
    rows: TrialBalanceRow[];
    total_debit: string;
    total_credit: string;
    is_balanced: boolean;
}
