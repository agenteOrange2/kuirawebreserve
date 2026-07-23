// crypto.randomUUID SOLO existe en contextos seguros (https) y navegadores
// recientes. El wizard corre incrustado en sitios de hoteles (WordPress por
// http, WebViews viejos): si falta, se degrada a getRandomValues o
// Math.random en vez de tronar con la pantalla en blanco (bug real del
// widget en realdelasierra, 2026-07-20).
export function randomUuid(): string {
    if (
        typeof crypto !== 'undefined' &&
        typeof crypto.randomUUID === 'function'
    ) {
        return crypto.randomUUID();
    }

    const bytes = new Uint8Array(16);

    if (
        typeof crypto !== 'undefined' &&
        typeof crypto.getRandomValues === 'function'
    ) {
        crypto.getRandomValues(bytes);
    } else {
        for (let i = 0; i < 16; i++) {
            bytes[i] = Math.floor(Math.random() * 256);
        }
    }

    // Marcas de version (4) y variante (10xx) del UUID v4.
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;

    const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0'));

    return `${hex.slice(0, 4).join('')}-${hex.slice(4, 6).join('')}-${hex.slice(6, 8).join('')}-${hex.slice(8, 10).join('')}-${hex.slice(10).join('')}`;
}
