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

export function formatDateTime(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat('id-ID', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value));
}

export function formatBytes(bytes: number | null | undefined): string {
    if (bytes === null || bytes === undefined) {
        return '—';
    }

    if (bytes < 1024) {
        return `${bytes} B`;
    }

    const units = ['KB', 'MB', 'GB'];
    let value = bytes / 1024;
    let unitIndex = 0;

    while (value >= 1024 && unitIndex < units.length - 1) {
        value /= 1024;
        unitIndex += 1;
    }

    return `${value.toFixed(value < 10 ? 1 : 0)} ${units[unitIndex]}`;
}

export function formatDuration(milliseconds: number | null | undefined): string {
    if (milliseconds === null || milliseconds === undefined) {
        return '—';
    }

    if (milliseconds < 1000) {
        return `${milliseconds} ms`;
    }

    return `${(milliseconds / 1000).toFixed(1)} s`;
}
