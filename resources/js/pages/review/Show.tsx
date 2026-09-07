import { Head, Link, useForm } from '@inertiajs/react';

import Card from '@/components/Card';
import ConfidenceBadge from '@/components/ConfidenceBadge';
import StatusBadge from '@/components/StatusBadge';
import { formatDate, formatDateTime, formatMoney } from '@/lib/money';
import type { ReviewTaskDetail, TransactionDetail } from '@/types';

interface Props {
    business: { id: string; name: string };
    task: ReviewTaskDetail;
    can?: { review: boolean; approve: boolean; post: boolean };
}

function Detail({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div>
            <dt className="text-xs uppercase tracking-wide text-slate-500">{label}</dt>
            <dd className="mt-1 text-sm text-slate-800">{children}</dd>
        </div>
    );
}

function TransactionJournalCard({ transaction }: { transaction: TransactionDetail }) {
    if (transaction.journal === null) {
        return <p className="text-sm text-slate-500">Belum ada usulan jurnal.</p>;
    }

    return (
        <div className="space-y-3">
            <dl className="grid gap-4 sm:grid-cols-3">
                <Detail label="Nomor">{transaction.journal.entry_number}</Detail>
                <Detail label="Status">{transaction.journal.status_label}</Detail>
                <Detail label="Seimbang">
                    {transaction.journal.total_debit === transaction.journal.total_credit
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
                                <span className="font-mono text-xs">{line.account_code}</span>{' '}
                                {line.account_name}
                            </td>
                            <td className="py-1 text-right font-mono">{line.debit}</td>
                            <td className="py-1 text-right font-mono">{line.credit}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

export default function ReviewShow({ business, task, can }: Props) {
    const transaction = task.transaction_detail;
    const inflow = transaction?.direction === 'inflow';
    const documentSource = transaction?.source?.document ?? null;
    const base = `/businesses/${business.id}/review/${task.id}`;

    const reviewable =
        transaction !== null && ['need_review', 'classified', 'ready'].includes(transaction.status);
    const awaitingPost = transaction?.status === 'approved' || task.status === 'awaiting_post';
    const completed = task.status === 'completed' || task.status === 'cancelled';

    const classify = useForm({
        event_code: transaction?.economic_event?.code ?? '',
        reason: '',
    });
    const approve = useForm({ reason: '' });
    const reject = useForm({ reason: '' });
    const post = useForm({ reason: '' });
    const comment = useForm({ body: '' });
    const tag = useForm({ tag: '' });

    return (
        <>
            <Head title={`Review — ${transaction?.reference ?? task.kind_label}`} />

            <div className="space-y-6">
                <p className="text-sm">
                    <Link
                        href={`/businesses/${business.id}/review`}
                        className="text-teal-700 hover:underline"
                    >
                        ← Review Center
                    </Link>
                </p>

                <Card
                    title={transaction?.reference ?? task.document?.reference ?? 'Tugas review'}
                    description={
                        transaction?.description ??
                        task.document?.original_filename ??
                        task.reason ??
                        task.kind_label
                    }
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <StatusBadge status={task.kind} label={task.kind_label} />
                            <StatusBadge status={task.status} label={task.status_label} />
                            {transaction && (
                                <ConfidenceBadge confidence={transaction.overall_confidence} />
                            )}
                        </div>
                    }
                >
                    {transaction ? (
                        <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <Detail label="Tanggal">
                                {formatDate(transaction.transaction_date)}
                            </Detail>
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
                            <Detail label="Status Transaksi">{transaction.status_label}</Detail>
                            <Detail label="Tanggal Posting">
                                {transaction.posting_date
                                    ? formatDate(transaction.posting_date)
                                    : 'Belum diposting'}
                            </Detail>
                            {transaction.tags.length > 0 && (
                                <div className="sm:col-span-2">
                                    <dt className="text-xs uppercase tracking-wide text-slate-500">
                                        Tag
                                    </dt>
                                    <dd className="mt-1 flex flex-wrap gap-1">
                                        {transaction.tags.map((item) => (
                                            <span
                                                key={item}
                                                className="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-700"
                                            >
                                                {item}
                                            </span>
                                        ))}
                                    </dd>
                                </div>
                            )}
                            {(task.reason || transaction.review_reason) && (
                                <div className="sm:col-span-2 lg:col-span-4">
                                    <dt className="text-xs uppercase tracking-wide text-slate-500">
                                        Perlu Diperiksa
                                    </dt>
                                    <dd className="mt-1 text-sm text-amber-700">
                                        {task.reason ?? transaction.review_reason}
                                    </dd>
                                </div>
                            )}
                        </dl>
                    ) : (
                        <div className="space-y-3 text-sm">
                            <p className="text-slate-600">
                                Tugas ini menunjuk dokumen yang masih perlu manusia. Review field
                                dokumen tetap dikerjakan di inbox.
                            </p>
                            {task.document && (
                                <Link
                                    href={`/businesses/${business.id}/documents/${task.document.id}`}
                                    className="text-teal-700 hover:underline"
                                >
                                    Buka {task.document.reference}
                                </Link>
                            )}
                        </div>
                    )}

                    <p className="mt-4 rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-500">
                        Menyetujui tidak memposting jurnal. Posting tetap menunggu akuntan.
                    </p>
                </Card>

                {can?.approve && reviewable && transaction && (
                    <Card
                        title="Keputusan"
                        description="Setujui agar jurnal menunggu posting, atau tolak dengan alasan. Jejak keputusan tidak dihapus."
                    >
                        <div className="flex flex-col gap-4">
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
                            {Object.values(reject.errors).map((message) => (
                                <p key={message} className="text-sm text-rose-600">
                                    {message}
                                </p>
                            ))}
                        </div>
                    </Card>
                )}

                {can?.post && awaitingPost && !completed && transaction && (
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

                {can?.review && transaction && !completed && (
                    <Card
                        title="Koreksi Peristiwa Ekonomi"
                        description="Koreksi reviewer tersimpan dan dipakai untuk usulan jurnal berikutnya."
                    >
                        <form
                            className="flex flex-wrap gap-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                classify.post(`${base}/classify`);
                            }}
                        >
                            <select
                                className="rounded-md border border-slate-300 px-3 py-2 text-sm"
                                value={classify.data.event_code}
                                onChange={(event) =>
                                    classify.setData('event_code', event.target.value)
                                }
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
                                value={classify.data.reason}
                                onChange={(event) => classify.setData('reason', event.target.value)}
                            />
                            <button
                                type="submit"
                                className="rounded-md bg-teal-700 px-3 py-2 text-sm text-white"
                                disabled={classify.processing || classify.data.event_code === ''}
                            >
                                Simpan klasifikasi
                            </button>
                        </form>
                    </Card>
                )}

                {transaction && (
                    <Card
                        title="Asal Transaksi"
                        description="Setiap transaksi dapat ditelusuri kembali ke berkas yang menghasilkannya."
                    >
                        {transaction.source === null ? (
                            <p className="text-sm text-slate-500">
                                Sumber transaksi tidak tercatat.
                            </p>
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
                                <Detail label="Transaksi">
                                    <Link
                                        href={`/businesses/${business.id}/transactions/${transaction.id}`}
                                        className="text-teal-700 hover:underline"
                                    >
                                        {transaction.reference}
                                    </Link>
                                </Detail>
                            </dl>
                        )}
                    </Card>
                )}

                {transaction && (
                    <Card
                        title="Usulan Jurnal"
                        description="Rule engine menulis draft. LLM tidak menulis baris jurnal, dan posting menunggu akuntan."
                    >
                        <TransactionJournalCard transaction={transaction} />
                    </Card>
                )}

                {can?.review && transaction && (
                    <Card
                        title="Tag"
                        description="Penanda untuk menyaring antrean, bukan klasifikasi akuntansi."
                    >
                        <form
                            className="flex flex-wrap gap-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                tag.post(`${base}/tags`, {
                                    preserveScroll: true,
                                    onSuccess: () => tag.reset('tag'),
                                });
                            }}
                        >
                            <input
                                className="min-w-40 flex-1 rounded-md border border-slate-300 px-3 py-2 text-sm"
                                placeholder="mis. cek-pajak"
                                value={tag.data.tag}
                                onChange={(event) => tag.setData('tag', event.target.value)}
                            />
                            <button
                                type="submit"
                                className="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50"
                                disabled={tag.processing || tag.data.tag.trim() === ''}
                            >
                                Tambah tag
                            </button>
                        </form>
                    </Card>
                )}

                <Card
                    title="Komentar"
                    description="Percakapan pada tugas ini bersifat append-only."
                >
                    {task.comments.length === 0 ? (
                        <p className="text-sm text-slate-500">Belum ada komentar.</p>
                    ) : (
                        <ul className="mb-4 divide-y divide-slate-100">
                            {task.comments.map((item) => (
                                <li key={item.id} className="py-2 text-sm">
                                    <p className="text-slate-800">{item.body}</p>
                                    <p className="mt-1 text-xs text-slate-400">
                                        {item.author_name ?? 'Sistem'}
                                        {item.created_at && ` · ${formatDateTime(item.created_at)}`}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}

                    {can?.review && (
                        <form
                            className="flex flex-wrap gap-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                comment.post(`${base}/comments`, {
                                    preserveScroll: true,
                                    onSuccess: () => comment.reset('body'),
                                });
                            }}
                        >
                            <textarea
                                className="min-h-20 min-w-56 flex-1 rounded-md border border-slate-300 px-3 py-2 text-sm"
                                placeholder="Tulis komentar…"
                                value={comment.data.body}
                                onChange={(event) => comment.setData('body', event.target.value)}
                            />
                            <button
                                type="submit"
                                className="self-end rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50"
                                disabled={comment.processing || comment.data.body.trim() === ''}
                            >
                                Kirim
                            </button>
                        </form>
                    )}
                </Card>

                <Card
                    title="Jejak Keputusan"
                    description="Setiap tindakan manusia tercatat dan tidak dapat dihapus."
                >
                    {task.actions.length === 0 ? (
                        <p className="text-sm text-slate-500">Belum ada tindakan.</p>
                    ) : (
                        <ul className="divide-y divide-slate-100">
                            {task.actions.map((action) => (
                                <li key={action.id} className="py-2 text-sm">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <StatusBadge
                                            status={action.action}
                                            label={action.action_label}
                                        />
                                        <span className="text-slate-700">
                                            {action.actor_name ?? 'Sistem'}
                                        </span>
                                        {action.created_at && (
                                            <span className="text-xs text-slate-400">
                                                {formatDateTime(action.created_at)}
                                            </span>
                                        )}
                                    </div>
                                    {action.reason && (
                                        <p className="mt-1 text-xs text-slate-500">
                                            {action.reason}
                                        </p>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>
        </>
    );
}
