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
