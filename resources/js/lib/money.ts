/**
 * Format uang untuk tampilan.
 *
 * Nilai uang dari backend selalu berupa string desimal (plan.md §44.14). Fungsi ini
 * hanya memformat untuk tampilan dan tidak pernah dipakai untuk menghitung, sehingga
 * konversi ke Number di sini tidak memengaruhi angka yang tersimpan.
 */
export function formatMoney(value: string | null | undefined, currency = 'IDR'): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const numeric = Number(value);

    if (Number.isNaN(numeric)) {
        return value;
    }

    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency,
        minimumFractionDigits: 0,
        maximumFractionDigits: 2,
    }).format(numeric);
}

export function isZeroAmount(value: string | null | undefined): boolean {
    return value === null || value === undefined || value === '' || Number(value) === 0;
}

export function formatDate(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat('id-ID', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(new Date(value));
}
