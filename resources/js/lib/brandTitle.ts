/**
 * Título de la pestaña: "<página> - <marca>". La marca es la guardada en
 * /admin/settings/brand (viaja en el share 'branding'); el nombre del .env
 * solo cuenta mientras no haya marca.
 */
type BrandingProps = { branding?: { app_name?: string | null } };

let brandName: string = import.meta.env.VITE_APP_NAME || 'KuiraReserve';

export function syncBrandName(props: unknown): void {
    const name = (props as BrandingProps | undefined)?.branding?.app_name;
    if (name) brandName = name;
}

export function brandTitle(title?: string): string {
    return title ? `${title} - ${brandName}` : brandName;
}
