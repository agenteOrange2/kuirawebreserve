// Tipos compartidos por el listado y la ficha de usuarios del admin.

export interface AdminUser {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    is_admin: boolean;
    two_factor: boolean;
    created_at: string | null;
    last_activity_ago?: string | null;
    last_activity_at?: string | null;
    actions_30d?: number;
}
