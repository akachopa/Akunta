import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';

import Card from '@/components/Card';
import StatusBadge from '@/components/StatusBadge';
import { formatDate, formatMoney } from '@/lib/money';
import type { AccountingPeriodStatus, JournalEntryStatus } from '@/types';

interface LineRow {
    id: string;
    line_number: number;
    account_code: string;
    account_name: string;
    description: string | null;
    debit: string;
    credit: string;
}

interface EntryDetail {
    id: string;
    entry_number: string;
    entry_date: string;
    description: string;
    status: JournalEntryStatus;
    source_type: string;
    period: string;
    period_status: AccountingPeriodStatus;
    total_debit: string;
    total_credit: string;
    is_balanced: boolean;
    reversal_of_id: string | null;
    reversed_by_entry_id: string | null;
    reversal_reason: string | null;
    lines: LineRow[];
}

interface Props {
    business: { id: string; name: string };
    entry: EntryDetail;
}

export default function JournalEntryDetail({ business, entry }: Props) {
    const [showReversal, setShowReversal] = useState(false);

    const approve = useForm({});
    const post = useForm({});
    const reverse = useForm({ reason: '', reversal_date: entry.entry_date });

    const base = `/businesses/${business.id}/journals/${entry.id}`;

    return (
        <>
            <Head title={`${entry.entry_number} — ${business.name}`} />

            <div className="space-y-6">
                <Card
                    title={entry.entry_number}
                    description={entry.description}
                    actions={<StatusBadge status={entry.status} />}
                >
                    <dl className="grid grid-cols-2 gap-4 text-sm md:grid-cols-4">
                        <div>
                            <dt className="text-slate-500">Tanggal</dt>
                            <dd className="font-medium text-slate-900">
                                {formatDate(entry.entry_date)}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">Periode</dt>
                            <dd className="font-medium text-slate-900">
                                {entry.period} <StatusBadge status={entry.period_status} />
                            </dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">Sumber</dt>
                            <dd className="font-medium text-slate-900">{entry.source_type}</dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">Seimbang</dt>
                            <dd
                                className={`font-medium ${entry.is_balanced ? 'text-teal-700' : 'text-rose-700'}`}
                            >
                                {entry.is_balanced ? 'Ya' : 'Tidak'}
                            </dd>
                        </div>
                    </dl>

                    <table className="mt-5 w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 text-left text-slate-500">
                                <th className="py-2 font-medium">#</th>
                                <th className="py-2 font-medium">Akun</th>
                                <th className="py-2 font-medium">Keterangan</th>
                                <th className="py-2 text-right font-medium">Debit</th>
                                <th className="py-2 text-right font-medium">Kredit</th>
                            </tr>
                        </thead>
                        <tbody>
                            {entry.lines.map((line) => (
                                <tr key={line.id} className="border-b border-slate-100">
                                    <td className="py-2 text-slate-500">{line.line_number}</td>
                                    <td className="py-2">
                                        <span className="font-mono">{line.account_code}</span> —{' '}
                                        {line.account_name}
                                    </td>
                                    <td className="py-2 text-slate-600">
                                        {line.description ?? '—'}
                                    </td>
                                    <td className="py-2 text-right tabular-nums">
                                        {Number(line.debit) === 0 ? '' : formatMoney(line.debit)}
                                    </td>
                                    <td className="py-2 text-right tabular-nums">
                                        {Number(line.credit) === 0 ? '' : formatMoney(line.credit)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot>
                            <tr className="font-semibold">
                                <td className="py-2" colSpan={3}>
                                    Total
                                </td>
                                <td className="py-2 text-right tabular-nums">
                                    {formatMoney(entry.total_debit)}
                                </td>
                                <td className="py-2 text-right tabular-nums">
                                    {formatMoney(entry.total_credit)}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </Card>

                {(entry.reversal_of_id || entry.reversed_by_entry_id) && (
                    <Card title="Jejak Koreksi">
                        <ul className="space-y-1 text-sm">
                            {entry.reversal_of_id && (
                                <li>
                                    Entry ini membalik{' '}
                                    <Link
                                        href={`/businesses/${business.id}/journals/${entry.reversal_of_id}`}
                                        className="text-teal-700 hover:underline"
                                    >
                                        journal asal
                                    </Link>
                                    .
                                </li>
                            )}
                            {entry.reversed_by_entry_id && (
                                <li>
                                    Entry ini sudah dibalik oleh{' '}
                                    <Link
                                        href={`/businesses/${business.id}/journals/${entry.reversed_by_entry_id}`}
                                        className="text-teal-700 hover:underline"
                                    >
                                        reversal entry
                                    </Link>
                                    .
                                </li>
                            )}
                            {entry.reversal_reason && (
                                <li className="text-slate-600">Alasan: {entry.reversal_reason}</li>
                            )}
                        </ul>
                    </Card>
                )}

                <Card
                    title="Tindakan"
                    description="Posted journal tidak dapat diubah; koreksi hanya melalui reversal."
                >
                    <div className="flex flex-wrap items-center gap-3">
                        {(entry.status === 'draft' || entry.status === 'pending_approval') && (
                            <button
                                type="button"
                                onClick={() =>
                                    approve.post(`${base}/approve`, { preserveScroll: true })
                                }
                                className="rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-100"
                            >
                                Setujui
                            </button>
                        )}

                        {entry.status !== 'posted' && entry.status !== 'reversed' && (
                            <button
                                type="button"
                                onClick={() => post.post(`${base}/post`, { preserveScroll: true })}
                                className="rounded-md bg-teal-700 px-4 py-2 text-sm font-medium text-white hover:bg-teal-800"
                            >
                                Posting ke Ledger
                            </button>
                        )}

                        {entry.status === 'posted' && (
                            <button
                                type="button"
                                onClick={() => setShowReversal((current) => !current)}
                                className="rounded-md border border-rose-300 px-4 py-2 text-sm text-rose-700 hover:bg-rose-50"
                            >
                                Buat Reversal
                            </button>
                        )}
                    </div>

                    {showReversal && (
                        <form
                            className="mt-4 flex flex-wrap items-end gap-3"
                            onSubmit={(event) => {
                                event.preventDefault();
                                reverse.post(`${base}/reverse`);
                            }}
                        >
                            <label className="grow text-sm">
                                <span className="block text-slate-600">Alasan Reversal</span>
                                <input
                                    type="text"
                                    value={reverse.data.reason}
                                    onChange={(event) =>
                                        reverse.setData('reason', event.target.value)
                                    }
                                    className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2"
                                    required
                                />
                                {reverse.errors.reason && (
                                    <span className="mt-1 block text-xs text-rose-600">
                                        {reverse.errors.reason}
                                    </span>
                                )}
                            </label>

                            <label className="text-sm">
                                <span className="block text-slate-600">Tanggal Reversal</span>
                                <input
                                    type="date"
                                    value={reverse.data.reversal_date}
                                    onChange={(event) =>
                                        reverse.setData('reversal_date', event.target.value)
                                    }
                                    className="mt-1 rounded-md border border-slate-300 px-3 py-2"
                                />
                            </label>

                            <button
                                type="submit"
                                className="rounded-md bg-rose-700 px-4 py-2 text-sm font-medium text-white hover:bg-rose-800"
                            >
                                Simpan Reversal
                            </button>
                        </form>
                    )}
                </Card>
            </div>
        </>
    );
}
