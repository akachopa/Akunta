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
    document_type_source: string | null;
    page_count: number | null;
    content_kind: string | null;
    needs_ocr: boolean;
    uploaded_at: string;
    parsed_at: string | null;
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

export interface DocumentDetail extends DocumentSummary {
    checksum_sha256: string;
    uploaded_by: string | null;
    archived_by: string | null;
    pages: DocumentPage[];
    processing_jobs: DocumentProcessingJob[];
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
