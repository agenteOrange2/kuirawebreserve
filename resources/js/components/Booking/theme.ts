import type { ComputedRef, InjectionKey } from 'vue';

// Lo que PublicShell reparte a lo que se pinta FUERA de su árbol (los
// modales van a <body> por portal): sin esto un modal perdía el color del
// hotel y la piel oscura.
export interface BookingTheme {
    isDark: ComputedRef<boolean>;
    accent: string;
    // Variables de la raíz (acento, texto de botones, tipografía y color de
    // títulos): los modales viven en <body> y las vuelven a poner.
    vars: ComputedRef<Record<string, string>>;
    titleColor: ComputedRef<boolean>;
}

export const bookingThemeKey: InjectionKey<BookingTheme> =
    Symbol('bookingTheme');

export interface PublicContact {
    website: string | null;
    maps_url: string | null;
    socials: { type: string; url: string; icon: string }[];
}

export interface StepItem {
    key: string;
    label: string;
}
