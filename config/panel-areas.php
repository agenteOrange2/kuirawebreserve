<?php

/*
|--------------------------------------------------------------------------
| Áreas del panel del hotel y permisos de cada rol
|--------------------------------------------------------------------------
|
| Dos capas, editables por rol desde /usuarios/roles (propietario) y desde
| /admin/tenants/{id}/roles (plataforma):
|
| - `areas`: QUÉ VE. Una por renglón del menú. Un rol puede tener el permiso
|   y aun así no ver el área (el gerente cobra reservas pero no lee la
|   bandeja). Se guardan como ocultas en roles.hidden_areas, así un área
|   nueva aparece sola para quien ya tenga el permiso que pide, igual que
|   antes de que existiera esta capa.
| - `capabilities`: QUÉ PUEDE HACER. Los permisos de spatie que ya protegen
|   rutas y botones (`can:`), con su nombre en español.
|
| `paths` son patrones de Str::is sobre $request->path() — páginas y,
| cuando el área es la única que los usa, su API (la bandeja). Gana el
| patrón más largo: 'reservas/reportes*' le gana a 'reservas/*'. Las API que
| comparten varias pantallas (api/reservations la usa el plano) NO van aquí:
| ahí manda el permiso.
|
| `permission`, `module` y `mode` son los mismos gates que ya tiene la ruta:
| el editor los usa para no ofrecer áreas que el hotel no tiene y para
| encender el permiso que hace falta al mostrar un área.
|
*/

return [

    'groups' => [
        'operacion' => 'Operación',
        'reservas' => 'Reservas',
        'propiedad' => 'Hotel',
        'ventas' => 'Ventas',
        'caja' => 'Caja y pagos',
        'administracion' => 'Administración',
    ],

    'areas' => [
        // OPERACIÓN
        'plano' => [
            'group' => 'operacion',
            'label' => 'Plano',
            'description' => 'El tablero de habitaciones: entradas, salidas y estados.',
            'icon' => 'Map',
            'permission' => 'rooms.view',
            'paths' => ['plano'],
        ],
        'bandeja' => [
            'group' => 'operacion',
            'label' => 'Bandeja',
            'description' => 'Conversaciones con huéspedes por WhatsApp, redes y web.',
            'icon' => 'MessagesSquare',
            'permission' => 'reservations.view',
            'module' => 'mensajeria',
            // /bandeja/avisos es la campana del personal, no los chats: se
            // queda abierta para todos.
            'paths' => ['bandeja', 'api/inbox', 'api/inbox/*'],
        ],
        'redes' => [
            'group' => 'operacion',
            'label' => 'Redes sociales',
            'description' => 'Comentarios y publicaciones de Facebook e Instagram.',
            'icon' => 'Share2',
            'permission' => 'reservations.view',
            'module' => 'redes-sociales',
            'paths' => ['redes', 'redes/*'],
        ],

        // RESERVAS
        'reservas' => [
            'group' => 'reservas',
            'label' => 'Reservas',
            'description' => 'Tablero, operación del día, pendientes, calendario, historial y la ficha.',
            'icon' => 'CalendarDays',
            'permission' => 'reservations.view',
            'paths' => ['reservas', 'reservas/*'],
        ],
        'reservas-reportes' => [
            'group' => 'reservas',
            'label' => 'Reportes de reservas',
            'description' => 'Ingresos, ocupación y origen de las reservas por periodo.',
            'icon' => 'ChartColumn',
            'permission' => 'reservations.view',
            'paths' => ['reservas/reportes', 'reservas/reportes/*'],
        ],
        'grupos' => [
            'group' => 'reservas',
            'label' => 'Reservas grupales',
            'description' => 'Grupos con varias habitaciones bajo un mismo folio.',
            'icon' => 'UsersRound',
            'permission' => 'reservations.view',
            'module' => 'grupos',
            'paths' => ['grupos', 'grupos/*'],
        ],
        'experiencias' => [
            'group' => 'reservas',
            'label' => 'Experiencias',
            'description' => 'Tours y actividades con cupo y horario.',
            'icon' => 'Compass',
            'permission' => 'reservations.view',
            'module' => 'experiencias',
            'paths' => ['experiencias'],
        ],
        'extras' => [
            'group' => 'reservas',
            'label' => 'Extras de reserva',
            'description' => 'Lo que se ofrece al reservar: desayuno, decoración, traslados.',
            'icon' => 'Gift',
            'permission' => 'properties.manage',
            'module' => 'extras',
            'paths' => ['extras'],
        ],
        'lista-espera' => [
            'group' => 'reservas',
            'label' => 'Lista de espera',
            'description' => 'Huéspedes que esperan a que se libere una fecha.',
            'icon' => 'BellRing',
            'permission' => 'reservations.manage',
            'module' => 'lista-espera',
            'paths' => ['lista-espera'],
        ],
        'cortesias' => [
            'group' => 'reservas',
            'label' => 'Cortesías',
            'description' => 'Hospedaje gratis o con descuento: solicitudes por aprobar, historial y reporte.',
            'icon' => 'HandHeart',
            'permission' => 'courtesies.request',
            'module' => 'cortesias',
            'paths' => ['reservas/cortesias', 'reservas/cortesias/*'],
        ],
        'cupones' => [
            'group' => 'reservas',
            'label' => 'Cupones',
            'description' => 'Códigos de descuento y sus condiciones.',
            'icon' => 'TicketPercent',
            'permission' => 'properties.manage',
            'module' => 'cupones',
            'paths' => ['cupones'],
        ],

        // HOTEL
        'huespedes' => [
            'group' => 'propiedad',
            'label' => 'Huéspedes',
            'description' => 'Directorio de huéspedes, su historial y exportación.',
            'icon' => 'Users',
            'permission' => 'guests.view',
            'paths' => ['huespedes', 'huespedes/*'],
        ],
        'vehiculos' => [
            'group' => 'propiedad',
            'label' => 'Vehículos',
            'description' => 'Registro de placas de la caseta.',
            'icon' => 'Car',
            'permission' => 'guests.view',
            'mode' => ['motel', 'both'],
            'paths' => ['vehiculos', 'vehiculos/*'],
        ],
        'habitaciones' => [
            'group' => 'propiedad',
            'label' => 'Habitaciones',
            'description' => 'Listado de habitaciones, su ficha e historial.',
            'icon' => 'BedDouble',
            'permission' => 'rooms.view',
            'paths' => ['habitaciones', 'habitaciones/*'],
        ],
        'habitaciones-reportes' => [
            'group' => 'propiedad',
            'label' => 'Reportes de habitaciones',
            'description' => 'Rotaciones, noches y dinero por habitación.',
            'icon' => 'ChartColumn',
            'permission' => 'rooms.view',
            'paths' => ['habitaciones/reportes', 'habitaciones/reportes/*'],
        ],
        'limpieza' => [
            'group' => 'propiedad',
            'label' => 'Limpieza',
            'description' => 'Habitaciones por limpiar, cronómetro y personal.',
            'icon' => 'Brush',
            'permission' => 'rooms.update-status',
            'module' => 'limpieza',
            'paths' => ['limpieza', 'limpieza/personal'],
        ],
        'limpieza-reportes' => [
            'group' => 'propiedad',
            'label' => 'Reportes de limpieza',
            'description' => 'Tiempos y limpiezas por persona y por periodo.',
            'icon' => 'ChartColumn',
            'permission' => 'reports.view',
            'module' => 'limpieza',
            'paths' => ['limpieza/reportes', 'limpieza/reportes/*'],
        ],
        'incidencias' => [
            'group' => 'propiedad',
            'label' => 'Incidencias',
            'description' => 'Reportes de mantenimiento y su seguimiento.',
            'icon' => 'Wrench',
            'permission' => 'rooms.view',
            'module' => 'incidencias',
            'paths' => ['incidencias', 'incidencias/*'],
        ],
        'encuestas' => [
            'group' => 'propiedad',
            'label' => 'Encuestas',
            'description' => 'Opiniones de los huéspedes y quejas por atender.',
            'icon' => 'Smile',
            'permission' => 'reports.view',
            'module' => 'encuestas',
            'paths' => ['encuestas', 'encuestas/*'],
        ],
        'catalogo' => [
            'group' => 'propiedad',
            'label' => 'Zonas y tipos',
            'description' => 'Zonas, tipos de habitación, fotos y tarifas.',
            'icon' => 'Shapes',
            'permission' => 'rooms.view',
            'paths' => ['catalogo'],
        ],

        // VENTAS
        'pos' => [
            'group' => 'ventas',
            'label' => 'POS',
            'description' => 'Punto de venta, historial de ventas y tickets.',
            'icon' => 'ShoppingCart',
            'permission' => 'orders.manage',
            'module' => 'pos',
            'paths' => ['pos', 'pos/*'],
        ],
        'inventario' => [
            'group' => 'ventas',
            'label' => 'Inventario',
            'description' => 'Productos, insumos y existencias.',
            'icon' => 'Package',
            'permission' => 'inventory.manage',
            'module' => 'pos',
            'paths' => ['inventario'],
        ],
        'menu-digital' => [
            'group' => 'ventas',
            'label' => 'Menú digital',
            'description' => 'La carta que el huésped abre con el QR.',
            'icon' => 'UtensilsCrossed',
            'permission' => 'orders.manage',
            'module' => 'menu-digital',
            'paths' => ['menu-digital'],
        ],
        'cocina' => [
            'group' => 'ventas',
            'label' => 'Cocina',
            'description' => 'Pedidos del menú digital por preparar y comandas.',
            'icon' => 'ChefHat',
            'permission' => 'orders.manage',
            'module' => 'menu-digital',
            'paths' => ['menu-digital/solicitudes', 'menu-digital/comanda/*'],
        ],

        // CAJA Y PAGOS
        'pagos' => [
            'group' => 'caja',
            'label' => 'Resumen de pagos',
            'description' => 'Lo que entró y lo que falta, de un vistazo.',
            'icon' => 'LayoutGrid',
            'permission' => 'reservations.view',
            'paths' => ['pagos'],
        ],
        'pagos-verificar' => [
            'group' => 'caja',
            'label' => 'Pagos por verificar',
            'description' => 'Comprobantes de transferencia esperando revisión.',
            'icon' => 'Landmark',
            'permission' => 'reservations.manage',
            'paths' => ['pagos/verificar'],
        ],
        'pagos-cobrar' => [
            'group' => 'caja',
            'label' => 'Por cobrar',
            'description' => 'Saldos pendientes de las reservas.',
            'icon' => 'TriangleAlert',
            'permission' => 'reservations.view',
            'paths' => ['pagos/cobrar'],
        ],
        'pagos-movimientos' => [
            'group' => 'caja',
            'label' => 'Movimientos',
            'description' => 'Cada entrada y salida de dinero.',
            'icon' => 'ArrowLeftRight',
            'permission' => 'reservations.view',
            'paths' => ['pagos/movimientos'],
        ],
        'cuentas' => [
            'group' => 'caja',
            'label' => 'Cuentas por cerrar',
            'description' => 'Estancias con saldo o consumos sin liquidar.',
            'icon' => 'ReceiptText',
            'permission' => 'reservations.view',
            'paths' => ['reservas/cuentas', 'reservas/cuentas/*'],
        ],
        'turnos' => [
            'group' => 'caja',
            'label' => 'Turnos',
            'description' => 'Abrir y cerrar el turno de caja.',
            'icon' => 'Clock',
            'permission' => 'orders.manage',
            'module' => 'corte-caja',
            'paths' => ['turnos'],
        ],
        'cortes' => [
            'group' => 'caja',
            'label' => 'Cortes de caja',
            'description' => 'Historial de cortes con su PDF y movimientos.',
            'icon' => 'Calculator',
            'permission' => 'orders.manage',
            'module' => 'corte-caja',
            'paths' => ['cortes', 'cortes/*'],
        ],
        'cobros-en-linea' => [
            'group' => 'caja',
            'label' => 'Cobros en línea',
            'description' => 'Ligas de pago y lo cobrado con tarjeta.',
            'icon' => 'Globe',
            'permission' => 'reservations.view',
            'paths' => ['cobros-en-linea'],
        ],

        // ADMINISTRACIÓN
        'usuarios' => [
            'group' => 'administracion',
            'label' => 'Usuarios',
            'description' => 'Altas, bajas y contraseñas del personal.',
            'icon' => 'UserCog',
            'permission' => 'users.manage',
            'paths' => ['usuarios', 'usuarios/*'],
        ],
        'actividad' => [
            'group' => 'administracion',
            'label' => 'Actividad',
            'description' => 'Bitácora de quién hizo qué y cuándo.',
            'icon' => 'History',
            'permission' => 'reports.view',
            'module' => 'bitacora',
            'paths' => ['actividad'],
        ],
        'asistente' => [
            'group' => 'administracion',
            'label' => 'Asistente IA',
            'description' => 'Canales, contexto y aprendizajes del bot.',
            'icon' => 'Bot',
            'permission' => 'properties.manage',
            'module' => 'mensajeria',
            'paths' => ['asistente', 'asistente/*'],
        ],
        'integracion' => [
            'group' => 'administracion',
            'label' => 'Integración',
            'description' => 'Conectar el sitio web con el motor de reservas.',
            'icon' => 'Plug',
            'permission' => 'properties.manage',
            'module' => 'motor-web',
            'paths' => ['integracion'],
        ],
        'ajustes' => [
            'group' => 'administracion',
            'label' => 'Ajustes',
            'description' => 'Datos del hotel, métodos de pago, correos y avisos.',
            'icon' => 'Settings',
            'permission' => 'properties.manage',
            'paths' => ['ajustes', 'ajustes/*', 'reservas/ajustes'],
        ],
    ],

    /*
    | Permisos (spatie) con su nombre para el dueño. El orden y el grupo son
    | los del editor. `agent` y su identidad no salen aquí.
    */
    'capability_groups' => [
        'habitaciones' => 'Habitaciones',
        'reservas' => 'Reservas y huéspedes',
        'ventas' => 'Ventas y caja',
        'administracion' => 'Administración',
    ],

    'capabilities' => [
        'rooms.view' => ['group' => 'habitaciones', 'label' => 'Ver habitaciones y el plano'],
        'rooms.update-status' => ['group' => 'habitaciones', 'label' => 'Cambiar el estado de una habitación', 'description' => 'Marcar limpia, sucia o en mantenimiento.'],
        'housekeeping.manage' => ['group' => 'habitaciones', 'label' => 'Registrar limpiezas y administrar camaristas'],
        'rooms.manage' => ['group' => 'habitaciones', 'label' => 'Dar de alta y editar habitaciones', 'description' => 'También zonas, tipos, fotos y tarifas.'],
        'reservations.view' => ['group' => 'reservas', 'label' => 'Ver reservas y pagos'],
        'reservations.manage' => ['group' => 'reservas', 'label' => 'Crear, cobrar, modificar y cancelar reservas'],
        'guests.view' => ['group' => 'reservas', 'label' => 'Ver huéspedes'],
        'guests.manage' => ['group' => 'reservas', 'label' => 'Registrar y editar huéspedes'],
        'courtesies.request' => ['group' => 'reservas', 'label' => 'Solicitar cortesías', 'description' => 'Pedir hospedaje gratis o con descuento; solo el propietario lo aprueba.'],
        'guests.view-documents' => ['group' => 'reservas', 'label' => 'Ver identificaciones de los huéspedes', 'description' => 'INE y documentos capturados en el check-in.'],
        'orders.manage' => ['group' => 'ventas', 'label' => 'Vender en el POS y hacer corte de caja'],
        'inventory.manage' => ['group' => 'ventas', 'label' => 'Administrar productos e insumos'],
        'shifts.manage' => ['group' => 'ventas', 'label' => 'Armar el rol de turnos del personal'],
        'reports.view' => ['group' => 'administracion', 'label' => 'Ver reportes, encuestas y bitácora'],
        'properties.manage' => ['group' => 'administracion', 'label' => 'Cambiar los ajustes del hotel', 'description' => 'Cupones, extras, asistente, integración y métodos de pago.'],
        'users.manage' => ['group' => 'administracion', 'label' => 'Administrar usuarios', 'description' => 'Dar de alta personal y cambiarle el rol. Nadie más que el propietario puede nombrar propietarios.'],
    ],

];
