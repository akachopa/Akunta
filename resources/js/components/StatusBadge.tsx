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

export default function StatusBadge({ status }: { status: string }) {
    return (
        <span
            className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${
                TONE[status] ?? 'bg-slate-100 text-slate-700'
            }`}
        >
            {LABEL[status] ?? status}
        </span>
    );
}
