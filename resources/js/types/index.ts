export interface AuthUser {
    id: number;
    name: string;
    email: string;
    phone: string;
    company: string;
    role: 'owner' | 'member';
    initials: string;
    two_factor_enabled: boolean;
    last_login_at: string | null;
}

export interface FlashMessages {
    success: string | null;
    error: string | null;
}

export interface SharedProps {
    appName: string;
    auth: { user: AuthUser | null };
    flash: FlashMessages;
    [key: string]: unknown;
}

export interface Metric {
    label: string;
    value: number | string;
    hint: string;
}
