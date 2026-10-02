// Tipos compartidos de /admin/agentes-ia y sus modales.

export interface ProviderRow {
    id: number;
    provider: string;
    label: string;
    model: string;
    masked_key: string;
    active: boolean;
}

export interface CatalogModel {
    id: string;
    tier: 'new' | 'mid' | 'cheap';
}

export interface CatalogEntry {
    key: string;
    label: string;
    placeholder_model: string;
    key_hint: string;
    models: CatalogModel[];
}

export interface TenantChannel {
    type: string;
    label: string;
    active: boolean;
    last_event_at: string | null;
}

export interface TenantAiRow {
    id: string;
    name: string;
    domain: string | null;
    plan: string;
    plan_label: string;
    /** El módulo agente-ia está encendido (plan, servicio o ajuste). */
    ai_available: boolean;
    /** La IA llega por un servicio adicional, no por el plan. */
    ai_from_addon: boolean;
    /** Cuota que le toca sin ajuste a mano; null = sin límite. */
    default_limit: number | null;
    enabled: boolean;
    provider_id: number | null;
    monthly_reply_limit: number | null;
    byok_allowed: boolean;
    api_allowed: boolean;
    used_replies: number;
    used_tokens: number;
    suspended: boolean;
    channels: TenantChannel[];
}

export const providerTone: Record<string, string> = {
    anthropic: 'border-pending/10 bg-pending/10 text-pending',
    openai: 'border-success/10 bg-success/10 text-success',
    deepseek: 'border-info/10 bg-info/10 text-info',
    kimi: 'border-primary/10 bg-primary/10 text-primary',
    minimax: 'border-warning/10 bg-warning/10 text-warning',
};

export const channelIcon: Record<string, string> = {
    whatsapp: 'MessageCircle',
    whatsapp_evo: 'MessageCircle',
    messenger: 'Facebook',
    instagram: 'Instagram',
    telegram: 'Send',
    tiktok: 'Music2',
};

export function effectiveLimit(t: TenantAiRow): number | null {
    return t.monthly_reply_limit ?? t.default_limit;
}

export function usagePercent(t: TenantAiRow): number {
    const limit = effectiveLimit(t);
    if (!limit) return 0;
    return Math.min(100, Math.round((t.used_replies / limit) * 100));
}

/** Primer mensaje legible de un error de axios (validación o mensaje). */
export function axiosMessage(e: unknown, fallback: string): string {
    const data = (e as { response?: { data?: Record<string, unknown> } })
        ?.response?.data;
    const errors = data?.errors as Record<string, string[]> | undefined;
    return (
        (errors ? Object.values(errors)[0]?.[0] : undefined) ??
        (data?.message as string | undefined) ??
        fallback
    );
}
