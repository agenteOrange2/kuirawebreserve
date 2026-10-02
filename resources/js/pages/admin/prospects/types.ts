export type ProspectStatus = 'new' | 'contacted' | 'qualified' | 'won' | 'lost';

export interface ProspectHistoryItem {
    id: number;
    label: string;
    icon: string;
    tone: string;
    details: string[];
    user: string | null;
    at: string | null;
    ago: string | null;
}

export interface ProspectRow {
    id: number;
    name: string;
    hotel_name: string;
    email: string;
    phone: string;
    has_whatsapp: boolean;
    rooms: number | null;
    plan_key: string | null;
    plan_label: string | null;
    services_labels: string[];
    message: string | null;
    status: ProspectStatus;
    notes: string | null;
    source: string;
    source_label: string;
    contacted_at: string | null;
    created_at: string | null;
    created_ago: string | null;
    docs_email_sent_at: string | null;
    docs_whatsapp_sent_at: string | null;
    docs_available: number;
    wa_phone: string | null;
    wa_text: string | null;
    wa_greeting: string | null;
    history: ProspectHistoryItem[];
}

export interface DocumentRow {
    uuid: string;
    title: string;
    service: string;
    service_label: string;
    original_name: string;
    size: number;
    sort: number;
    url: string;
    updated_at: string | null;
}

export const statusOptions: Array<{ value: ProspectStatus; label: string }> = [
    { value: 'new', label: 'Nuevo' },
    { value: 'contacted', label: 'Contactado' },
    { value: 'qualified', label: 'Calificado' },
    { value: 'won', label: 'Ganado' },
    { value: 'lost', label: 'Descartado' },
];

export const statusMeta: Record<
    ProspectStatus,
    { label: string; class: string; dot: string; icon: string }
> = {
    new: {
        label: 'Nuevo',
        class: 'bg-info/10 text-info',
        dot: 'bg-info',
        icon: 'BellRing',
    },
    contacted: {
        label: 'Contactado',
        class: 'bg-warning/10 text-warning',
        dot: 'bg-warning',
        icon: 'PhoneCall',
    },
    qualified: {
        label: 'Calificado',
        class: 'bg-primary/10 text-primary',
        dot: 'bg-primary',
        icon: 'BadgeCheck',
    },
    won: {
        label: 'Ganado',
        class: 'bg-success/10 text-success',
        dot: 'bg-success',
        icon: 'Trophy',
    },
    lost: {
        label: 'Descartado',
        class: 'bg-slate-100 text-slate-500 dark:bg-darkmode-400',
        dot: 'bg-slate-400',
        icon: 'CircleSlash',
    },
};

export function initials(name: string): string {
    return (
        name
            .trim()
            .split(/\s+/)
            .slice(0, 2)
            .map((part) => part.charAt(0).toUpperCase())
            .join('') || '?'
    );
}

export function whatsappHref(phone: string, text: string | null): string {
    return `https://wa.me/${phone}?text=${encodeURIComponent(text ?? '')}`;
}
