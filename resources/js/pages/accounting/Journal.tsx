import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import Card from '@/components/Card';
import StatusBadge from '@/components/StatusBadge';
import { formatDate, formatMoney } from '@/lib/money';
import type { JournalEntryStatus } from '@/types';

interface EntryRow {
    id: string;
    entry_number: string;
    entry_date: string;
    description: string;
    status: JournalEntryStatus;
    period: string;
    total_debit: string;
    total_credit: string;
}

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
}

interface LineInput {
    account_id: string;
    description: string;
    debit: string;
    credit: string;
}

interface Props {
    business: { id: string; name: string };
    entries: Paginated<EntryRow>;
    filters: { status: string };
    accounts: { id: string; label: string }[];
    periods: { id: string; name: string; status: string }[];
}

const EMPTY_LINE: LineInput = { account_id: '', description: '', debit: '', credit: '' };

/**
 * Total dihitung ulang di sisi klien hanya sebagai bantuan visual. Keputusan
 * debit = credit tetap milik server (plan.md §11.7), jadi angka di sini tidak pernah
 * dipakai untuk melewati validasi.
 */
function sumSide(lines: LineInput[], side: 'debit' | 'credit'): number {
    return lines.reduce((total, line) => total + (Number(line[side]) || 0), 0);
}

export default function Journal({ business, entries, filters, accounts, periods }: Props) {
    const [lines, setLines] = useState<LineInput[]>([{ ...EMPTY_LINE }, { ...EMPTY_LINE }]);

    const form = useForm({
        entry_date: new Date().toISOString().slice(0, 10),
        description: '',
        post: false,
    });

    const totalDebit = sumSide(lines, 'debit');
    const totalCredit = sumSide(lines, 'credit');
    const balanced = totalDebit > 0 && totalDebit === totalCredit;

    const updateLine = (index: number, patch: Partial<LineInput>) => {
        setLines((current) => current.map((line, i) => (i === index ? { ...line, ...patch } : line)));
    };

    const submit = () => {
        router.post(
            `/businesses/${business.id}/journals`,
            {
                entry_date: form.data.entry_date,
                description: form.data.description,
                post: form.data.post,
                lines: lines.filter((line) => line.account_id !== ''),
            },
            { preserveScroll: true },
        );
    };

    const openPeriods = periods.filter((period) => period.status !== 'closed').length;

    return (
        <>
            <Head title={`Journal — ${business.name}`} />

            <div className="space-y-6">
                <Card
                    title="Journal Entry Manual"
                    description={`Debit dan kredit harus seimbang sebelum dapat diposting. ${openPeriods} periode terbuka.`}
                >
                    <form
                        className="space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            submit();
                        }}
                    >
                        <div className="flex flex-wrap items-end gap-3">
                            <label className="text-sm">
                                <span className="block text-slate-600">Tanggal</span>
                                <input
                                    type="date"
                                    value={form.data.entry_date}
                                    onChange={(event) => form.setData('entry_date', event.target.value)}
                                    className="mt-1 rounded-md border border-slate-300 px-3 py-2"
                                    required
                                />
                            </label>

                            <label className="grow text-sm">
                                <span className="block text-slate-600">Keterangan</span>
                                <input
                                    type="text"
                                    value={form.data.description}
                                    onChange={(event) => form.setData('description', event.target.value)}
                                    className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2"
                                    required
                                />
                            </label>
                        </div>

                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-slate-200 text-left text-slate-500">
                                    <th className="py-2 font-medium">Akun</th>
                                    <th className="py-2 font-medium">Keterangan Baris</th>
                                    <th className="py-2 font-medium">Debit</th>
                                    <th className="py-2 font-medium">Kredit</th>
                                </tr>
                            </thead>
                            <tbody>
                                {lines.map((line, index) => (
                                    <tr key={index} className="border-b border-slate-100">
                                        <td className="py-2 pr-2">
                                            <select
                                                value={line.account_id}
                                                onChange={(event) =>
                                                    updateLine(index, { account_id: event.target.value })
                                                }
                                                className="w-full rounded-md border border-slate-300 px-2 py-1.5"
                                            >
                                                <option value="">Pilih akun…</option>
                                                {accounts.map((account) => (
                                                    <option key={account.id} value={account.id}>
                                                        {account.label}
                                                    </option>
                                                ))}
                                            </select>
                                        </td>
                                        <td className="py-2 pr-2">
                                            <input
                                                type="text"
                                                value={line.description}
                                                onChange={(event) =>
                                                    updateLine(index, { description: event.target.value })
                                                }
                                                className="w-full rounded-md border border-slate-300 px-2 py-1.5"
                                            />
                                        </td>
                                        <td className="py-2 pr-2">
                                            <input
                                                type="text"
                                                inputMode="decimal"
                                                value={line.debit}
                                                onChange={(event) =>
                                                    updateLine(index, { debit: event.target.value, credit: '' })
                                                }
                                                className="w-32 rounded-md border border-slate-300 px-2 py-1.5 text-right tabular-nums"
                                            />
                                        </td>
                                        <td className="py-2">
                                            <input
                                                type="text"
                                                inputMode="decimal"
                                                value={line.credit}
                                                onChange={(event) =>
                                                    updateLine(index, { credit: event.target.value, debit: '' })
                                                }
                                                className="w-32 rounded-md border border-slate-300 px-2 py-1.5 text-right tabular-nums"
                                            />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot>
                                <tr className="font-medium">
                                    <td className="py-2" colSpan={2}>
                                        <button
                                            type="button"
                                            onClick={() => setLines((current) => [...current, { ...EMPTY_LINE }])}
                                            className="text-xs text-teal-700 hover:underline"
                                        >
                                            + Tambah baris
                                        </button>
                                    </td>
                                    <td className="py-2 text-right tabular-nums">{formatMoney(String(totalDebit))}</td>
                                    <td className="py-2 text-right tabular-nums">{formatMoney(String(totalCredit))}</td>
                                </tr>
                            </tfoot>
                        </table>

                        <div className="flex flex-wrap items-center gap-4">
                            <span
                                className={`text-sm ${balanced ? 'text-teal-700' : 'text-amber-700'}`}
                            >
                                {balanced ? 'Debit dan kredit seimbang.' : 'Debit dan kredit belum seimbang.'}
                            </span>

                            <label className="flex items-center gap-2 text-sm text-slate-600">
                                <input
                                    type="checkbox"
                                    checked={form.data.post}
                                    onChange={(event) => form.setData('post', event.target.checked)}
                                />
                                Langsung posting ke ledger
                            </label>

                            <button
                                type="submit"
                                className="rounded-md bg-teal-700 px-4 py-2 text-sm font-medium text-white hover:bg-teal-800"
                            >
                                Simpan Journal
                            </button>
                        </div>
                    </form>
                </Card>

                <Card
                    title={`Daftar Journal (${entries.total})`}
                    actions={
                        <select
                            value={filters.status}
                            onChange={(event) =>
                                router.get(
                                    `/businesses/${business.id}/journals`,
                                    event.target.value ? { status: event.target.value } : {},
                                    { preserveState: true },
                                )
                            }
                            className="rounded-md border border-slate-300 px-2 py-1 text-sm"
                        >
                            <option value="">Semua status</option>
                            <option value="draft">Draft</option>
                            <option value="pending_approval">Menunggu Persetujuan</option>
                            <option value="approved">Disetujui</option>
                            <option value="posted">Diposting</option>
                            <option value="reversed">Dibalik</option>
                        </select>
                    }
                >
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 text-left text-slate-500">
                                <th className="py-2 font-medium">Nomor</th>
                                <th className="py-2 font-medium">Tanggal</th>
                                <th className="py-2 font-medium">Keterangan</th>
                                <th className="py-2 font-medium">Periode</th>
                                <th className="py-2 font-medium">Status</th>
                                <th className="py-2 text-right font-medium">Debit</th>
                                <th className="py-2 text-right font-medium">Kredit</th>
                            </tr>
                        </thead>
                        <tbody>
                            {entries.data.map((entry) => (
                                <tr key={entry.id} className="border-b border-slate-100">
                                    <td className="py-2">
                                        <Link
                                            href={`/businesses/${business.id}/journals/${entry.id}`}
                                            className="font-mono text-teal-700 hover:underline"
                                        >
                                            {entry.entry_number}
                                        </Link>
                                    </td>
                                    <td className="py-2 text-slate-600">{formatDate(entry.entry_date)}</td>
                                    <td className="py-2">{entry.description}</td>
                                    <td className="py-2 text-slate-600">{entry.period}</td>
                                    <td className="py-2">
                                        <StatusBadge status={entry.status} />
                                    </td>
                                    <td className="py-2 text-right tabular-nums">{formatMoney(entry.total_debit)}</td>
                                    <td className="py-2 text-right tabular-nums">{formatMoney(entry.total_credit)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>

                    {entries.links.length > 3 && (
                        <div className="mt-4 flex flex-wrap gap-1 text-sm">
                            {entries.links.map((link) => (
                                <Link
                                    key={link.label}
                                    href={link.url ?? '#'}
                                    disabled={!link.url}
                                    className={`rounded-md border px-2 py-1 ${
                                        link.active
                                            ? 'border-teal-600 bg-teal-50 text-teal-800'
                                            : 'border-slate-200 text-slate-600'
                                    }`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ))}
                        </div>
                    )}
                </Card>
            </div>
        </>
    );
}
