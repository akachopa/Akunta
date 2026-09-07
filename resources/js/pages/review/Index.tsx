import { Head, Link } from '@inertiajs/react';

import Card from '@/components/Card';
import ConfidenceBadge from '@/components/ConfidenceBadge';
import StatusBadge from '@/components/StatusBadge';
import { formatDate, formatMoney } from '@/lib/money';
import type { ReviewTaskSummary } from '@/types';

interface Props {
    business: { id: string; name: string };
    filter: string;
    filters: { value: string; label: string }[];
    counts: Record<string, number>;
    tasks: {
        data: ReviewTaskSummary[];
        meta: { current_page: number; last_page: number; per_page: number; total: number };
    };
    can?: { approve: boolean };
}

export default function ReviewIndex({ business, filter, filters, counts, tasks }: Props) {
    const query = (params: Record<string, string | number>) => {
        const search = new URLSearchParams({ filter });

        Object.entries(params).forEach(([key, value]) => search.set(key, String(value)));

        return `/businesses/${business.id}/review?${search.toString()}`;
    };

    return (
        <>
            <Head title={`Review Center — ${business.name}`} />

            <div className="space-y-6">
                <Card
                    title="Review Center"
                    description="Antrean kerja exception-based. Yang tampil adalah item yang perlu manusia. Menyetujui transaksi tidak memposting jurnal."
                >
                    <nav className="mb-4 flex flex-wrap gap-2">
                        {filters.map((tab) => (
                            <Link
                                key={tab.value}
                                href={query({ filter: tab.value, page: 1 })}
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

                    {tasks.data.length === 0 ? (
                        <p className="py-6 text-sm text-slate-500">
                            Tidak ada item pada tampilan ini.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-slate-200 text-sm">
                                <thead>
                                    <tr className="text-left text-xs uppercase tracking-wide text-slate-500">
                                        <th className="py-2 pr-4">Subjek</th>
                                        <th className="py-2 pr-4">Alasan</th>
                                        <th className="py-2 pr-4">Status</th>
                                        <th className="py-2 pr-4">Nilai</th>
                                        <th className="py-2 pr-4">Pihak Lawan</th>
                                        <th className="py-2 pr-4">Keyakinan</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {tasks.data.map((task) => {
                                        const transaction = task.transaction;
                                        const inflow = transaction?.direction === 'inflow';

                                        return (
                                            <tr key={task.id}>
                                                <td className="py-3 pr-4">
                                                    <Link
                                                        href={`/businesses/${business.id}/review/${task.id}`}
                                                        className="font-medium text-teal-700 hover:underline"
                                                    >
                                                        {transaction?.reference ??
                                                            task.document?.reference ??
                                                            'Tugas review'}
                                                    </Link>
                                                    <p className="text-xs text-slate-500">
                                                        {transaction?.description ??
                                                            task.document?.original_filename ??
                                                            task.subject_type_label}
                                                    </p>
                                                </td>
                                                <td className="py-3 pr-4">
                                                    <StatusBadge
                                                        status={task.kind}
                                                        label={task.kind_label}
                                                    />
                                                    {task.reason && (
                                                        <p className="mt-1 max-w-xs text-xs text-amber-700">
                                                            {task.reason}
                                                        </p>
                                                    )}
                                                </td>
                                                <td className="py-3 pr-4">
                                                    <StatusBadge
                                                        status={task.status}
                                                        label={task.status_label}
                                                    />
                                                </td>
                                                <td className="py-3 pr-4 font-mono">
                                                    {transaction ? (
                                                        <span
                                                            className={
                                                                inflow
                                                                    ? 'text-teal-700'
                                                                    : 'text-slate-800'
                                                            }
                                                        >
                                                            {inflow ? '+' : '−'}{' '}
                                                            {formatMoney(
                                                                transaction.amount,
                                                                transaction.currency,
                                                            )}
                                                        </span>
                                                    ) : (
                                                        '—'
                                                    )}
                                                    {transaction && (
                                                        <p className="text-xs text-slate-400">
                                                            {formatDate(
                                                                transaction.transaction_date,
                                                            )}
                                                        </p>
                                                    )}
                                                </td>
                                                <td className="py-3 pr-4">
                                                    {transaction?.counterparty_entity?.name ??
                                                        transaction?.counterparty_name ??
                                                        '—'}
                                                </td>
                                                <td className="py-3 pr-4">
                                                    {transaction ? (
                                                        <ConfidenceBadge
                                                            confidence={
                                                                transaction.overall_confidence
                                                            }
                                                        />
                                                    ) : (
                                                        '—'
                                                    )}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}

                    {tasks.meta.last_page > 1 && (
                        <div className="mt-4 flex items-center justify-between text-sm">
                            <Link
                                href={query({ page: Math.max(1, tasks.meta.current_page - 1) })}
                                className={`rounded-md border px-3 py-1 ${
                                    tasks.meta.current_page === 1
                                        ? 'pointer-events-none border-slate-200 text-slate-400'
                                        : 'border-slate-300 text-slate-700 hover:bg-slate-50'
                                }`}
                            >
                                Sebelumnya
                            </Link>
                            <span className="text-slate-500">
                                Halaman {tasks.meta.current_page} dari {tasks.meta.last_page}
                            </span>
                            <Link
                                href={query({
                                    page: Math.min(
                                        tasks.meta.last_page,
                                        tasks.meta.current_page + 1,
                                    ),
                                })}
                                className={`rounded-md border px-3 py-1 ${
                                    tasks.meta.current_page === tasks.meta.last_page
                                        ? 'pointer-events-none border-slate-200 text-slate-400'
                                        : 'border-slate-300 text-slate-700 hover:bg-slate-50'
                                }`}
                            >
                                Berikutnya
                            </Link>
                        </div>
                    )}
                </Card>
            </div>
        </>
    );
}
