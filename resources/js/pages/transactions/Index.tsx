import { Head, Link } from '@inertiajs/react';

import Card from '@/components/Card';
import ConfidenceBadge from '@/components/ConfidenceBadge';
import StatusBadge from '@/components/StatusBadge';
import { formatDate, formatMoney } from '@/lib/money';
import type { TransactionSummary } from '@/types';

interface Props {
    business: { id: string; name: string };
    filter: string;
    filters: { value: string; label: string }[];
    counts: Record<string, number>;
    document: { id: string; reference: string; original_filename: string } | null;
    transactions: {
        data: TransactionSummary[];
        meta: { current_page: number; last_page: number; per_page: number; total: number };
    };
}

/*
 * Arah aliran nilai ditampilkan sebagai tanda pada nominal, bukan sebagai kolom debit dan
 * kredit. plan.md §44.3 dan §44.4 melarang menyamakan kas masuk dengan pendapatan, dan
 * kolom debit/kredit di halaman ini akan menyiratkan pemetaan akun yang baru dikerjakan
 * Phase 8 dan Phase 9.
 */
function AmountCell({ transaction }: { transaction: TransactionSummary }) {
    const inflow = transaction.direction === 'inflow';

    return (
        <span className={`font-mono text-sm ${inflow ? 'text-teal-700' : 'text-slate-800'}`}>
            {inflow ? '+' : '−'} {formatMoney(transaction.amount, transaction.currency)}
        </span>
    );
}

export default function TransactionIndex({
    business,
    filter,
    filters,
    counts,
    document,
    transactions,
}: Props) {
    const query = (params: Record<string, string | number>) => {
        const search = new URLSearchParams({
            filter,
            ...(document ? { document: document.id } : {}),
        });

        Object.entries(params).forEach(([key, value]) => search.set(key, String(value)));

        return `/businesses/${business.id}/transactions?${search.toString()}`;
    };

    return (
        <>
            <Head title={`Transaksi — ${business.name}`} />

            <div className="space-y-6">
                <Card
                    title="Transaksi"
                    description="Peristiwa keuangan yang terbaca dari dokumen. Klasifikasi akun dan jurnalnya dikerjakan pada tahap berikutnya."
                >
                    {document && (
                        <div className="mb-4 flex flex-wrap items-center gap-2 rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-900">
                            <span>
                                Menampilkan transaksi dari dokumen{' '}
                                <Link
                                    href={`/businesses/${business.id}/documents/${document.id}`}
                                    className="font-medium underline"
                                >
                                    {document.reference}
                                </Link>{' '}
                                ({document.original_filename}).
                            </span>
                            <Link
                                href={`/businesses/${business.id}/transactions?filter=${filter}`}
                                className="ml-auto rounded-md border border-sky-300 px-2 py-1 text-xs hover:bg-sky-100"
                            >
                                Tampilkan semua
                            </Link>
                        </div>
                    )}

                    <nav className="mb-4 flex flex-wrap gap-2">
                        {filters.map((tab) => (
                            <Link
                                key={tab.value}
                                href={`/businesses/${business.id}/transactions?filter=${tab.value}`}
                                preserveScroll
                                className={`rounded-full px-3 py-1 text-sm ${
                                    tab.value === filter
                                        ? 'bg-teal-600 text-white'
                                        : 'bg-slate-100 text-slate-700 hover:bg-slate-200'
                                }`}
                            >
                                {tab.label}
                                <span className="ml-2 text-xs opacity-80">
                                    {counts[tab.value] ?? 0}
                                </span>
                            </Link>
                        ))}
                    </nav>

                    {transactions.data.length === 0 ? (
                        <p className="py-6 text-sm text-slate-500">
                            Belum ada transaksi pada tampilan ini. Transaksi terbentuk otomatis
                            setelah dokumen selesai dibaca.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-slate-200 text-sm">
                                <thead>
                                    <tr className="text-left text-xs uppercase tracking-wide text-slate-500">
                                        <th className="py-2 pr-4">Referensi</th>
                                        <th className="py-2 pr-4">Tanggal</th>
                                        <th className="py-2 pr-4">Keterangan</th>
                                        <th className="py-2 pr-4 text-right">Nominal</th>
                                        <th className="py-2 pr-4">Sumber</th>
                                        <th className="py-2 pr-4">Status</th>
                                        <th className="py-2 pr-4">Keyakinan</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {transactions.data.map((transaction) => (
                                        <tr key={transaction.id} className="align-top">
                                            <td className="py-2 pr-4 font-mono text-xs">
                                                <Link
                                                    href={`/businesses/${business.id}/transactions/${transaction.id}`}
                                                    className="text-teal-700 hover:underline"
                                                >
                                                    {transaction.reference}
                                                </Link>
                                            </td>
                                            <td className="py-2 pr-4 whitespace-nowrap text-slate-600">
                                                {formatDate(transaction.transaction_date)}
                                            </td>
                                            <td className="max-w-md py-2 pr-4">
                                                <span className="block text-slate-800">
                                                    {transaction.description}
                                                </span>
                                                {transaction.counterparty_name && (
                                                    <span className="mt-0.5 block text-xs text-slate-500">
                                                        {transaction.counterparty_name}
                                                    </span>
                                                )}
                                                {transaction.review_reason && (
                                                    <span className="mt-1 block text-xs text-amber-700">
                                                        {transaction.review_reason}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="py-2 pr-4 text-right whitespace-nowrap">
                                                <AmountCell transaction={transaction} />
                                            </td>
                                            <td className="py-2 pr-4 text-slate-600">
                                                {transaction.source?.document ? (
                                                    <Link
                                                        href={`/businesses/${business.id}/documents/${transaction.source.document.id}`}
                                                        className="text-teal-700 hover:underline"
                                                    >
                                                        {transaction.source.document.reference}
                                                    </Link>
                                                ) : (
                                                    transaction.source_type_label
                                                )}
                                                <span className="mt-0.5 block text-xs text-slate-500">
                                                    {transaction.source_type_label}
                                                    {transaction.source?.row_index !== null &&
                                                        transaction.source?.row_index !==
                                                            undefined &&
                                                        ` · baris ${transaction.source.row_index + 1}`}
                                                </span>
                                            </td>
                                            <td className="py-2 pr-4">
                                                <StatusBadge
                                                    status={transaction.status}
                                                    label={transaction.status_label}
                                                />
                                            </td>
                                            <td className="py-2 pr-4">
                                                <ConfidenceBadge
                                                    confidence={transaction.overall_confidence}
                                                />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    {transactions.meta.last_page > 1 && (
                        <div className="mt-4 flex items-center justify-between text-sm text-slate-600">
                            <span>
                                Halaman {transactions.meta.current_page} dari{' '}
                                {transactions.meta.last_page} · {transactions.meta.total} transaksi
                            </span>
                            <div className="flex gap-2">
                                <Link
                                    href={query({ page: transactions.meta.current_page - 1 })}
                                    preserveScroll
                                    className={`rounded-md border border-slate-300 px-3 py-1 ${
                                        transactions.meta.current_page <= 1
                                            ? 'pointer-events-none opacity-40'
                                            : 'hover:bg-slate-100'
                                    }`}
                                >
                                    Sebelumnya
                                </Link>
                                <Link
                                    href={query({ page: transactions.meta.current_page + 1 })}
                                    preserveScroll
                                    className={`rounded-md border border-slate-300 px-3 py-1 ${
                                        transactions.meta.current_page >=
                                        transactions.meta.last_page
                                            ? 'pointer-events-none opacity-40'
                                            : 'hover:bg-slate-100'
                                    }`}
                                >
                                    Berikutnya
                                </Link>
                            </div>
                        </div>
                    )}
                </Card>
            </div>
        </>
    );
}
