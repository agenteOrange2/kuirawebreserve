import axios from 'axios';
import { ref } from 'vue';

export interface GuestHit {
    id: number;
    full_name: string | null;
    phone: string | null;
    email: string | null;
    visits: number;
    is_blacklisted: boolean;
    blacklist_reason: string | null;
    id_document_type: string | null;
}

/**
 * Buscar al huésped que ya vino mientras se teclea (nombre o teléfono), contra
 * /api/guests/search — el mismo del modal de reservas. Sin permiso de ver
 * huéspedes (o sin red) la lista queda vacía y el formulario sigue siendo
 * captura libre.
 */
export function useGuestSearch(delay = 300) {
    const hits = ref<GuestHit[]>([]);
    const searching = ref(false);
    let timer: ReturnType<typeof setTimeout> | null = null;
    // Respuestas que llegan tarde (de una búsqueda ya superada) no pisan
    // la lista de la búsqueda vigente.
    let ticket = 0;

    function search(term: string) {
        if (timer) clearTimeout(timer);
        const q = term.trim();
        const mine = ++ticket;
        if (q.length < 2) {
            hits.value = [];
            searching.value = false;
            return;
        }
        searching.value = true;
        timer = setTimeout(async () => {
            try {
                const { data } = await axios.get<GuestHit[]>(
                    '/api/guests/search',
                    { params: { q } },
                );
                if (mine === ticket) hits.value = data;
            } catch {
                if (mine === ticket) hits.value = [];
            } finally {
                if (mine === ticket) searching.value = false;
            }
        }, delay);
    }

    function reset() {
        if (timer) clearTimeout(timer);
        ticket++;
        hits.value = [];
        searching.value = false;
    }

    return { hits, searching, search, reset };
}
