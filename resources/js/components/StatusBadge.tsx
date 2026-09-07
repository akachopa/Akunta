const TONE: Record<string, string> = {
    draft: 'bg-slate-100 text-slate-700',
    pending_approval: 'bg-amber-100 text-amber-800',
    approved: 'bg-sky-100 text-sky-800',
    posted: 'bg-teal-100 text-teal-800',
    reversed: 'bg-rose-100 text-rose-800',

    open: 'bg-teal-100 text-teal-800',
    reviewing: 'bg-sky-100 text-sky-800',
    ready_to_close: 'bg-amber-100 text-amber-800',
    closed: 'bg-slate-200 text-slate-800',
    reopened: 'bg-violet-100 text-violet-800',

    // Status dokumen (plan.md §25.1).
    uploaded: 'bg-slate-100 text-slate-700',
    queued: 'bg-slate-100 text-slate-700',
    parsing: 'bg-sky-100 text-sky-800',
    classifying: 'bg-sky-100 text-sky-800',
    extracting: 'bg-sky-100 text-sky-800',
    normalizing: 'bg-sky-100 text-sky-800',
    matching: 'bg-sky-100 text-sky-800',
    ready: 'bg-teal-100 text-teal-800',
    need_review: 'bg-amber-100 text-amber-800',
    unsupported: 'bg-amber-100 text-amber-800',
    failed: 'bg-rose-100 text-rose-800',
    archived: 'bg-slate-200 text-slate-800',

    // Status transaksi (plan.md §25.2) yang belum dipakai status dokumen.
    detected: 'bg-slate-100 text-slate-700',
    normalized: 'bg-sky-100 text-sky-800',
    classified: 'bg-violet-100 text-violet-800',
    rejected: 'bg-rose-100 text-rose-800',

    // Status percobaan tahap pipeline (document_processing_jobs).
    pending: 'bg-slate-100 text-slate-600',
    running: 'bg-sky-100 text-sky-800',
    succeeded: 'bg-teal-100 text-teal-800',
    skipped: 'bg-slate-200 text-slate-700',
};

const LABEL: Record<string, string> = {
    draft: 'Draft',
    pending_approval: 'Menunggu Persetujuan',
    approved: 'Disetujui',
    posted: 'Diposting',
    reversed: 'Dibalik',

    open: 'Terbuka',
    reviewing: 'Direview',
    ready_to_close: 'Siap Ditutup',
    closed: 'Ditutup',
    reopened: 'Dibuka Kembali',
};

/*
 * `label` mengizinkan pemanggil memakai label yang sudah dikirim backend, sehingga label
 * status dokumen tidak perlu ditulis ulang di frontend dan tidak bisa menyimpang dari
 * enum PHP-nya.
 */
export default function StatusBadge({ status, label }: { status: string; label?: string }) {
    return (
        <span
            className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${
                TONE[status] ?? 'bg-slate-100 text-slate-700'
            }`}
        >
            {label ?? LABEL[status] ?? status}
        </span>
    );
}
