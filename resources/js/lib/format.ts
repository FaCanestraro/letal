/**
 * Máscara de telefone brasileiro: (11) 91234-5678 / (11) 1234-5678.
 */
export function maskPhone(value: string): string {
    const digits = value.replace(/\D/g, '').slice(0, 11);

    if (digits.length <= 2) {
        return digits.replace(/^(\d{0,2})/, '($1');
    }

    if (digits.length <= 6) {
        return digits.replace(/^(\d{2})(\d{0,4})/, '($1) $2');
    }

    if (digits.length <= 10) {
        return digits.replace(/^(\d{2})(\d{4})(\d{0,4})/, '($1) $2-$3');
    }

    return digits.replace(/^(\d{2})(\d{5})(\d{0,4})/, '($1) $2-$3');
}

const dateTime = new Intl.DateTimeFormat('pt-BR', {
    dateStyle: 'short',
    timeStyle: 'short',
});

export function formatDateTime(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    return dateTime.format(new Date(value));
}

export function formatSeconds(total: number): string {
    const minutes = Math.floor(total / 60);
    const seconds = total % 60;

    return minutes > 0
        ? `${minutes}:${String(seconds).padStart(2, '0')}`
        : `${seconds}s`;
}

export function formatBytes(bytes: number | null | undefined): string {
    if (!bytes) {
        return '—';
    }

    const units = ['B', 'KB', 'MB', 'GB'];
    const exponent = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
    const value = bytes / 1024 ** exponent;

    return `${value.toFixed(exponent === 0 ? 0 : 1).replace('.', ',')} ${units[exponent]}`;
}

export function formatNumber(value: number): string {
    return new Intl.NumberFormat('pt-BR').format(value);
}

/** Duração aproximada em português: "45s", "12 min", "1h20". */
export function formatDuration(seconds: number): string {
    if (seconds < 60) {
        return `${Math.max(1, Math.round(seconds))}s`;
    }

    const minutes = Math.round(seconds / 60);

    if (minutes < 60) {
        return `${minutes} min`;
    }

    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    return rest === 0 ? `${hours}h` : `${hours}h${String(rest).padStart(2, '0')}`;
}
