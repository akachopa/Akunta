import { Head, Link, useForm } from '@inertiajs/react';

import Card from '@/components/Card';
import ConfidenceBadge from '@/components/ConfidenceBadge';
import StatusBadge from '@/components/StatusBadge';
import { formatDate, formatDateTime, formatMoney } from '@/lib/money';
import type { TransactionDetail } from '@/types';

interface Props {
    business: { id: string; name: string };
    transaction: TransactionDetail;
    can?: { classify: boolean; approve: boolean; post: boolean };
}

function Detail({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div>
            <dt className="text-xs uppercase tracking-wide text-slate-500">{label}</dt>
            <dd className="mt-1 text-sm text-slate-800">{children}</dd>
        </div>
    );
}

export default function TransactionShow({ business, transaction, can }: Props) {
    const inflow = transaction.direction === 'inflow';
    const documentSource = transaction.source?.document ?? null;
    const form = useForm({
        event_code: transaction.economic_event?.code ?? '',
        reason: '',
    });
    const approve = useForm({ reason: '' });
    const reject = useForm({ reason: '' });
    const post = useForm({ reason: '' });
    const reviewable = ['need_review', 'classified', 'ready'].includes(transaction.status);
    const awaitingPost = transaction.status === 'approved';
    const base = `/businesses/${business.id}/transactions/${transaction.id}`;

    return (
        <>
            <Head title={`${transaction.reference} — ${business.name}`} />

            <div className="space-y-6">
                <Card
                    title={transaction.reference}
                    description={transaction.description}
                    actions={
                        <div className="flex items-center gap-2">
                            <StatusBadge
                                status={transaction.status}
                                label={transaction.status_label}
                            />
                            <ConfidenceBadge confidence={transaction.overall_confidence} />
                        </div>
                    }
                >
                    <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <Detail label="Tanggal">{formatDate(transaction.transaction_date)}</Detail>

                        <Detail label={inflow ? 'Nilai Masuk' : 'Nilai Keluar'}>
                            <span
                                className={`font-mono ${inflow ? 'text-teal-700' : 'text-slate-800'}`}
                            >
                                {inflow ? '+' : '−'}{' '}
                                {formatMoney(transaction.amount, transaction.currency)}
                            </span>
                        </Detail>

                        <Detail label="Pihak Lawan">
                            {transaction.counterparty_entity ? (
                                <Link
                                    href={`/businesses/${business.id}/entities/${transaction.counterparty_entity.id}`}
                                    className="text-teal-700 hover:underline"
                                >
                                    {transaction.counterparty_entity.name}
                                </Link>
                            ) : (
                                (transaction.counterparty_name ?? 'Belum teridentifikasi')
                            )}
                        </Detail>

                        <Detail label="Peristiwa Ekonomi">
                            {transaction.economic_event?.name ?? 'Belum diklasifikasi'}
                        </Detail>

                        <Detail label="Jenis Sumber">{transaction.source_type_label}</Detail>

                        <Detail label="Dinormalisasi">
                            {formatDateTime(transaction.normalized_at)}
                        </Detail>

                        {transaction.review_reason && (
                            <div className="sm:col-span-2 lg:col-span-3">
                                <dt className="text-xs uppercase tracking-wide text-slate-500">
                                    Perlu Diperiksa
                                </dt>
                                <dd className="mt-1 text-sm text-amber-700">
                                    {transaction.review_reason}
                                </dd>
                            </div>
                        )}
                    </dl>

                    <p className="mt-4 rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-500">
                        Transaksi ini menggambarkan perpindahan nilai yang terbaca dari dokumen.
                        Jurnal di bawah adalah usulan draft; posting tetap menunggu akuntan.
                    </p>
                </Card>

                {can?.approve && reviewable && (
                    <Card
                        title="Keputusan Review"
                        description="Setujui agar jurnal menunggu posting, atau tolak dengan alasan. Jejaknya tetap tersimpan."
                    >
                        <div className="space-y-3">
                            <form
                                className="flex flex-wrap items-end gap-2"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    approve.post(`${base}/approve`);
                                }}
                            >
                                <input
                                    className="min-w-56 flex-1 rounded-md border border-slate-300 px-3 py-2 text-sm"
                                    placeholder="Catatan persetujuan (opsional)"
                                    value={approve.data.reason}
                                    onChange={(event) =>
                                        approve.setData('reason', event.target.value)
                                    }
                                />
                                <button
                                    type="submit"
                                    className="rounded-md bg-teal-700 px-3 py-2 text-sm text-white disabled:opacity-60"
                                    disabled={approve.processing}
                                >
                                    Setujui
                                </button>
                            </form>
                            <form
                                className="flex flex-wrap items-end gap-2"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    reject.post(`${base}/reject`);
                                }}
                            >
                                <input
                                    className="min-w-56 flex-1 rounded-md border border-slate-300 px-3 py-2 text-sm"
                                    placeholder="Alasan penolakan (wajib)"
                                    value={reject.data.reason}
                                    onChange={(event) =>
                                        reject.setData('reason', event.target.value)
                                    }
                                    required
                                />
                                <button
                                    type="submit"
                                    className="rounded-md border border-rose-300 bg-rose-50 px-3 py-2 text-sm text-rose-800 disabled:opacity-60"
                                    disabled={reject.processing || reject.data.reason.trim() === ''}
                                >
                                    Tolak
                                </button>
                            </form>
                        </div>
                    </Card>
                )}

                {can?.post && awaitingPost && (
                    <Card
                        title="Posting Jurnal"
                        description="Hanya akuntan yang memasukkan jurnal ke ledger."
                    >
                        <form
                            className="flex flex-wrap items-end gap-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                post.post(`${base}/post`);
                            }}
                        >
                            <input
                                className="min-w-56 flex-1 rounded-md border border-slate-300 px-3 py-2 text-sm"
                                placeholder="Catatan posting (opsional)"
                                value={post.data.reason}
                                onChange={(event) => post.setData('reason', event.target.value)}
                            />
                            <button
                                type="submit"
                                className="rounded-md bg-teal-700 px-3 py-2 text-sm text-white disabled:opacity-60"
                                disabled={post.processing}
                            >
                                Posting ke ledger
                            </button>
                        </form>
                    </Card>
                )}

                {can?.classify && (
                    <Card
                        title="Koreksi Peristiwa Ekonomi"
                        description="Koreksi reviewer tersimpan dan dipakai untuk usulan jurnal berikutnya."
                    >
                        <form
                            className="flex flex-wrap gap-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.post(
                                    `/businesses/${business.id}/transactions/${transaction.id}/classify`,
                                );
                            }}
                        >
                            <select
                                className="rounded-md border border-slate-300 px-3 py-2 text-sm"
                                value={form.data.event_code}
                                onChange={(event) => form.setData('event_code', event.target.value)}
                            >
                                <option value="">Pilih peristiwa</option>
                                {transaction.event_options.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                            <input
                                className="min-w-56 flex-1 rounded-md border border-slate-300 px-3 py-2 text-sm"
                                placeholder="Alasan (opsional)"
                                value={form.data.reason}
                                onChange={(event) => form.setData('reason', event.target.value)}
                            />
                            <button
                                type="submit"
                                className="rounded-md bg-teal-700 px-3 py-2 text-sm text-white"
                                disabled={form.processing || form.data.event_code === ''}
                            >
                                Simpan klasifikasi
                            </button>
                        </form>
                    </Card>
                )}

                <Card
                    title="Asal Transaksi"
                    description="Setiap transaksi dapat ditelusuri kembali ke berkas yang menghasilkannya."
                >
                    {transaction.source === null ? (
                        <p className="text-sm text-slate-500">Sumber transaksi tidak tercatat.</p>
                    ) : (
                        <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <Detail label="Dokumen">
                                {documentSource ? (
                                    <Link
                                        href={`/businesses/${business.id}/documents/${documentSource.id}`}
                                        className="text-teal-700 hover:underline"
                                    >
                                        {documentSource.reference}
                                    </Link>
                                ) : (
                                    '—'
                                )}
                            </Detail>

                            <Detail label="Berkas">
                                {documentSource?.original_filename ?? '—'}
                            </Detail>

                            <Detail label="Jenis Dokumen">
                                {documentSource?.document_type_label ?? '—'}
                            </Detail>

                            <Detail label="Unit Sumber">
                                {transaction.source.row_index === null
                                    ? 'Seluruh dokumen'
                                    : `Baris ${transaction.source.row_index + 1}`}
                                {transaction.source.page_number !== null &&
                                    ` · halaman ${transaction.source.page_number}`}
                            </Detail>
                        </dl>
                    )}
                </Card>

                <Card
                    title="Bukti"
                    description="Bagian dokumen yang menjadi dasar transaksi ini, termasuk angka pendukung yang bukan nominalnya."
                >
                    {transaction.evidence.length === 0 ? (
                        <p className="text-sm text-slate-500">Belum ada bukti yang tercatat.</p>
                    ) : (
                        <ul className="divide-y divide-slate-100">
                            {transaction.evidence.map((evidence) => (
                                <li key={evidence.id} className="flex flex-wrap gap-2 py-2 text-sm">
                                    <span className="w-40 shrink-0 text-slate-500">
                                        {evidence.field_label ?? evidence.type_label}
                                    </span>
                                    <span className="min-w-0 flex-1 text-slate-800">
                                        {evidence.note ?? '—'}
                                    </span>
                                    <span className="text-xs text-slate-400">
                                        {evidence.row_reference !== null &&
                                            `baris ${Number(evidence.row_reference) + 1}`}
                                        {evidence.page_number !== null &&
                                            ` · halaman ${evidence.page_number}`}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>

                <Card
                    title="Dokumen Terkait"
                    description="Duplikat adalah bukti yang sama; terkait adalah bukti berbeda untuk peristiwa yang sama."
                >
                    {transaction.relations.length === 0 ? (
                        <p className="text-sm text-slate-500">Belum ada hubungan yang tercatat.</p>
                    ) : (
                        <ul className="divide-y divide-slate-100">
                            {transaction.relations.map((relation, index) => (
                                <li key={`${relation.type}-${index}`} className="py-2 text-sm">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <StatusBadge
                                            status={relation.type}
                                            label={relation.type_label}
                                        />
                                        <ConfidenceBadge confidence={relation.confidence} />
                                        {relation.other && (
                                            <Link
                                                href={`/businesses/${business.id}/transactions/${relation.other.id}`}
                                                className="font-mono text-teal-700 hover:underline"
                                            >
                                                {relation.other.reference}
                                            </Link>
                                        )}
                                    </div>
                                    <p className="mt-1 text-xs text-slate-500">
                                        {(relation.reasons ?? []).join(' ')}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>

                <Card
                    title="Usulan Jurnal"
                    description="Rule engine menulis draft. LLM tidak menulis baris jurnal, dan posting menunggu akuntan."
                >
                    {transaction.journal === null ? (
                        <p className="text-sm text-slate-500">Belum ada usulan jurnal.</p>
                    ) : (
                        <div className="space-y-3">
                            <dl className="grid gap-4 sm:grid-cols-3">
                                <Detail label="Nomor">{transaction.journal.entry_number}</Detail>
                                <Detail label="Status">{transaction.journal.status_label}</Detail>
                                <Detail label="Seimbang">
                                    {transaction.journal.total_debit ===
                                    transaction.journal.total_credit
                                        ? 'Ya'
                                        : 'Tidak'}
                                </Detail>
                            </dl>
                            <table className="min-w-full text-sm">
                                <thead>
                                    <tr className="text-left text-xs uppercase tracking-wide text-slate-500">
                                        <th className="py-1">Akun</th>
                                        <th className="py-1 text-right">Debit</th>
                                        <th className="py-1 text-right">Kredit</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {transaction.journal.lines.map((line, index) => (
                                        <tr key={index} className="border-t border-slate-100">
                                            <td className="py-1">
                                                <span className="font-mono text-xs">
                                                    {line.account_code}
                                                </span>{' '}
                                                {line.account_name}
                                            </td>
                                            <td className="py-1 text-right font-mono">
                                                {line.debit}
                                            </td>
                                            <td className="py-1 text-right font-mono">
                                                {line.credit}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>
            </div>
        </>
    );
}
