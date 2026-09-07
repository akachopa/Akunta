import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

import Card from '@/components/Card';
import StatusBadge from '@/components/StatusBadge';
import { formatDate } from '@/lib/money';
import type { AccountingPeriodStatus } from '@/types';

interface PeriodRow {
    id: string;
    name: string;
    start_date: string;
    end_date: string;
    status: AccountingPeriodStatus;
    journal_entries_count: number;
    closed_at: string | null;
    reopened_at: string | null;
}

interface Props {
    business: { id: string; name: string };
    periods: PeriodRow[];
}

export default function Periods({ business, periods }: Props) {
    const [reopening, setReopening] = useState<string | null>(null);
    const [reason, setReason] = useState('');

    return (
        <>
            <Head title={`Periode Akuntansi — ${business.name}`} />

            <Card
                title="Periode Akuntansi"
                description="Periode yang ditutup mengunci journal di dalamnya. Membuka kembali periode wajib menyertakan alasan dan tercatat di audit log."
            >
                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-b border-slate-200 text-left text-slate-500">
                            <th className="py-2 font-medium">Periode</th>
                            <th className="py-2 font-medium">Rentang</th>
                            <th className="py-2 font-medium">Status</th>
                            <th className="py-2 text-right font-medium">Journal</th>
                            <th className="py-2 font-medium">Ditutup</th>
                            <th className="py-2" />
                        </tr>
                    </thead>
                    <tbody>
                        {periods.map((period) => (
                            <tr key={period.id} className="border-b border-slate-100">
                                <td className="py-2 font-medium text-slate-900">{period.name}</td>
                                <td className="py-2 text-slate-600">
                                    {formatDate(period.start_date)} – {formatDate(period.end_date)}
                                </td>
                                <td className="py-2">
                                    <StatusBadge status={period.status} />
                                </td>
                                <td className="py-2 text-right tabular-nums text-slate-600">
                                    {period.journal_entries_count}
                                </td>
                                <td className="py-2 text-slate-600">
                                    {formatDate(period.closed_at)}
                                </td>
                                <td className="py-2 text-right">
                                    {period.status === 'closed' ? (
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setReopening(period.id);
                                                setReason('');
                                            }}
                                            className="text-xs text-violet-700 hover:underline"
                                        >
                                            Buka kembali
                                        </button>
                                    ) : (
                                        <button
                                            type="button"
                                            onClick={() =>
                                                router.post(
                                                    `/businesses/${business.id}/periods/${period.id}/close`,
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                            className="text-xs text-slate-700 hover:underline"
                                        >
                                            Tutup periode
                                        </button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>

                {reopening !== null && (
                    <form
                        className="mt-4 flex flex-wrap items-end gap-3 border-t border-slate-200 pt-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            router.post(
                                `/businesses/${business.id}/periods/${reopening}/reopen`,
                                { reason },
                                { preserveScroll: true, onSuccess: () => setReopening(null) },
                            );
                        }}
                    >
                        <label className="grow text-sm">
                            <span className="block text-slate-600">
                                Alasan membuka kembali periode
                            </span>
                            <input
                                type="text"
                                value={reason}
                                onChange={(event) => setReason(event.target.value)}
                                className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2"
                                required
                            />
                        </label>

                        <button
                            type="submit"
                            className="rounded-md bg-violet-700 px-4 py-2 text-sm font-medium text-white hover:bg-violet-800"
                        >
                            Buka Kembali
                        </button>

                        <button
                            type="button"
                            onClick={() => setReopening(null)}
                            className="rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-100"
                        >
                            Batal
                        </button>
                    </form>
                )}
            </Card>
        </>
    );
}
