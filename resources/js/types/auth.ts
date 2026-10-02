export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
};

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};

/** Marca del hotel en las pantallas de acceso de su dominio (TenantLoginBrand). */
export type TenantBrand = {
    name: string;
    logo_url: string | null;
    hint: string | null;
    title: string | null;
    subtitle: string | null;
    background_url: string | null;
    colors: {
        primary: string | null;
        menu_from: string | null;
        menu_to: string | null;
    };
};
