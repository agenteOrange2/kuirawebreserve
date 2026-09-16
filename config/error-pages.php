<?php

/*
|--------------------------------------------------------------------------
| Pantallas de error (404, 500, 403…)
|--------------------------------------------------------------------------
|
| Una sola fuente para lo que dice cada error. De aquí lee tanto la página
| Inertia (resources/js/pages/Error.vue) como los respaldos Blade
| (resources/views/errors/*.blade.php), que son los que salen cuando ni
| siquiera se puede armar una respuesta de Inertia — dominio de hotel
| desconocido, base de datos caída o mantenimiento.
|
| El tono es el mismo del resto del panel: español, sin emojis, diciendo
| qué pasó y qué hacer, sin jerga de servidor.
|
| - icon:  nombre de icono de Lucide (verificar que existe antes de cambiarlo).
| - tone:  token del theme (primary, info, success, warning, pending, danger, dark).
| - hints: pasos concretos; se muestran como lista. Opcional.
| - hints_panel: consejos que SOLO tienen sentido con sesión abierta. Un
|   huésped en el wizard no tiene menú, ni buscador, ni gerencia a quien
|   pedirle permisos: decírselo lo deja más perdido que el propio error.
|
*/

return [

    'default' => [
        'icon' => 'TriangleAlert',
        'tone' => 'dark',
        'badge' => 'Error',
        'title' => 'No pudimos mostrar esta página',
        'body' => 'Algo se interpuso en el camino y la página no llegó a cargar. Vuelve a intentarlo en un momento.',
        'hints' => [],
        'hints_panel' => [],
    ],

    'statuses' => [

        401 => [
            'icon' => 'KeyRound',
            'tone' => 'info',
            'badge' => 'Sesión requerida',
            'title' => 'Necesitas iniciar sesión',
            'body' => 'Esta página es del panel del hotel y tu sesión ya no está activa. Entra de nuevo con tu cuenta y podrás continuar donde estabas.',
            'hints' => [],
        ],

        403 => [
            'icon' => 'ShieldAlert',
            'tone' => 'pending',
            'badge' => 'Acceso restringido',
            'title' => 'Esta sección no está abierta para tu cuenta',
            'body' => 'Tu usuario no tiene permiso para entrar aquí. No es un error: así quedó configurado tu perfil.',
            'hints' => [],
            'hints_panel' => [
                'Si necesitas esta sección, pídele a la gerencia que te la habilite desde Usuarios.',
                'Si crees que sí deberías tener acceso, cierra sesión y vuelve a entrar: los permisos se leen al iniciar.',
            ],
        ],

        404 => [
            'icon' => 'Compass',
            'tone' => 'primary',
            'badge' => 'Página no encontrada',
            'title' => 'Aquí no hay nada',
            'body' => 'La dirección no existe, o el registro que buscabas ya no está. Puede que lo hayan eliminado o que el enlace venga incompleto.',
            'hints' => [
                'Revisa que la dirección esté completa, sin espacios ni caracteres de más.',
                'Si llegaste desde un enlace guardado, es probable que el registro se haya eliminado.',
            ],
            'hints_panel' => [
                'Usa el buscador del panel para encontrarlo por nombre, código o teléfono.',
            ],
        ],

        405 => [
            'icon' => 'Ban',
            'tone' => 'pending',
            'badge' => 'Acción no permitida',
            'title' => 'Esa acción no va por aquí',
            'body' => 'La página existe, pero no acepta la operación que se intentó. Normalmente pasa al recargar una pantalla después de guardar.',
            'hints' => [],
            'hints_panel' => [
                'Vuelve a la sección desde el menú en vez de recargar esta dirección.',
            ],
        ],

        419 => [
            'icon' => 'Clock',
            'tone' => 'warning',
            'badge' => 'Sesión expirada',
            'title' => 'La página estuvo abierta demasiado tiempo',
            'body' => 'Por seguridad la sesión caduca tras un rato sin actividad. Recarga la página y continúa; lo que ya habías guardado sigue en su lugar.',
            'hints' => [
                'Si estabas capturando algo largo, copia el texto antes de recargar.',
            ],
        ],

        429 => [
            'icon' => 'Gauge',
            'tone' => 'warning',
            'badge' => 'Demasiados intentos',
            'title' => 'Vamos demasiado rápido',
            'body' => 'Se hicieron muchos intentos seguidos desde este equipo y el sistema los está conteniendo. Espera un minuto y vuelve a intentarlo.',
            'hints' => [
                'Si estabas iniciando sesión, espera antes de probar otra contraseña.',
            ],
        ],

        500 => [
            'icon' => 'TriangleAlert',
            'tone' => 'danger',
            'badge' => 'Error del servidor',
            'title' => 'Algo falló de nuestro lado',
            'body' => 'No es culpa tuya ni se perdió tu trabajo previo: esta pantalla en concreto no se pudo terminar de armar. El error ya quedó registrado.',
            'hints' => [
                'Vuelve a intentarlo en un momento; muchas veces es algo pasajero.',
                'Si se repite, pasa el folio del error a soporte: con él ubicamos exactamente qué ocurrió.',
            ],
        ],

        503 => [
            'icon' => 'Wrench',
            'tone' => 'info',
            'badge' => 'En mantenimiento',
            'title' => 'Volvemos en unos minutos',
            'body' => 'Estamos aplicando una actualización al sistema. El servicio se restablece solo, no hay nada que necesites hacer.',
            'hints' => [
                'Las reservas y los cobros que ya estaban registrados no se ven afectados.',
            ],
        ],

    ],

];
