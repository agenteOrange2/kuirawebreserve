<?php

/*
|--------------------------------------------------------------------------
| Catálogo de módulos de la plataforma
|--------------------------------------------------------------------------
|
| Módulo = capacidad que se enciende o apagada por plan (los límites
| contables siguen en config/plans.php). El catálogo vive en código porque
| un módulo implica rutas, menú y tools; el admin solo decide quién lo
| tiene: `plans.modules` (JSON) define el plan y `tenant_modules` (DB
| central) fuerza excepciones por hotel. La verdad efectiva la resuelve
| Tenant::hasModule().
|
| `available` = false: módulo aún en desarrollo — se puede incluir en
| planes desde ya (aparece "En desarrollo" en el admin), su área aparece
| cuando exista. Spec: docs/spec-plan-maestro.md §3.
|
*/

return [
    'pos' => [
        'label' => 'Punto de venta',
        'description' => 'Punto de venta e inventario.',
        'available' => true,
        'group' => 'consumos',
    ],

    'cobros' => [
        'label' => 'Cobros en línea',
        'description' => 'Pasarelas de pago (Stripe, Mercado Pago) y links de cobro. Las transferencias con verificación van en todos los planes.',
        'available' => true,
        'group' => 'cobros',
    ],

    'mensajeria' => [
        'label' => 'Bandeja de mensajes',
        'description' => 'Bandeja unificada de conversaciones y canales conectados (WhatsApp, Messenger, Instagram, webchat); el número de canales lo limita el plan.',
        'available' => true,
        'group' => 'atencion',
    ],

    'agente-ia' => [
        'label' => 'Asistente IA',
        'description' => 'Bot que responde y aparta por chat con las llaves de la plataforma; la cuota mensual la define el plan. Requiere la Bandeja de mensajes.',
        'available' => true,
        'group' => 'atencion',
    ],

    'motor-web' => [
        'label' => 'Motor de reservas web',
        'description' => 'Integración con sitios (WordPress): catálogo con precios en vivo, tokens e importador; el wizard público de reservas viene en camino.',
        'available' => true,
        'group' => 'reservas',
    ],

    'redes-sociales' => [
        'label' => 'IA en redes sociales',
        'description' => 'Amplía el asistente para responder comentarios en publicaciones de Facebook, Instagram y TikTok y convertirlos en solicitudes de reserva. Requiere el Asistente IA.',
        'available' => true,
        'group' => 'atencion',
    ],

    'menu-digital' => [
        'label' => 'Menú digital',
        'description' => 'Carta pública en /menu con los productos que el hotel elija: el huésped pide por liga o QR y la solicitud llega a la campana del staff. Requiere el Punto de venta (de ahí salen los productos).',
        'available' => true,
        'group' => 'consumos',
    ],

    'extras' => [
        'label' => 'Extras de reserva',
        'description' => 'Cargos que suman al total de la reserva: decoración, desayuno, late checkout. No es el Punto de venta: no llevan inventario ni pasan por caja.',
        'available' => true,
        'group' => 'reservas',
    ],

    'experiencias' => [
        'label' => 'Experiencias',
        'description' => 'Tours y recorridos con horario y cupo propios.',
        'available' => true,
        'group' => 'reservas',
    ],

    'grupos' => [
        'label' => 'Reservas grupales',
        'description' => 'Varias habitaciones en una sola reserva: un folio de grupo, todo-o-nada.',
        'available' => true,
        'group' => 'reservas',
    ],

    'lista-espera' => [
        'label' => 'Lista de espera',
        'description' => 'Sin disponibilidad, el wizard captura al interesado y se le avisa solo cuando una cancelación libera sus fechas.',
        'available' => true,
        'group' => 'reservas',
    ],

    'cupones' => [
        'label' => 'Cupones',
        'description' => 'Códigos de descuento (porcentaje o monto) con condiciones: noches mínimas, tipo de habitación, cliente frecuente o cumpleaños.',
        'available' => true,
        'group' => 'reservas',
    ],

    /*
    |----------------------------------------------------------------------
    | Toggles de los planes Esencial / Profesional / Empresarial
    |----------------------------------------------------------------------
    | Nacieron como core y se separaron para el escalonado del documento
    | comercial: el Esencial opera reservas básicas; estos se encienden
    | desde Profesional (o Empresarial los "avanzado").
    */

    'tarifas-flexibles' => [
        'label' => 'Tarifas flexibles',
        'description' => 'Tarifas por hora, día, semana o mes (bloques de tiempo). Sin el módulo solo hay tarifas por noche.',
        'available' => true,
        'group' => 'reservas',
    ],

    'anticipos' => [
        'label' => 'Anticipos y saldos',
        'description' => 'Cobro anticipado configurable, control de saldos pendientes y alertas de pagos vencidos.',
        'available' => true,
        'group' => 'cobros',
    ],

    'corte-caja' => [
        'label' => 'Corte de caja por turno',
        'description' => 'Turnos de recepción con corte por método de pago, arqueo con diferencias, movimientos y pagos pendientes.',
        'available' => true,
        'group' => 'cobros',
    ],

    'bitacora' => [
        'label' => 'Bitácora de acciones',
        'description' => 'Quién hizo qué y cuándo en todo el hotel, filtrable por usuario, tipo y fecha.',
        'available' => true,
        'group' => 'operacion',
    ],

    'crm-avanzado' => [
        'label' => 'CRM de huéspedes completo',
        'description' => 'Historial de visitas, lista negra, identificación (INE) y registro de vehículo. El directorio básico va en todos los planes.',
        'available' => true,
        'group' => 'huespedes',
    ],

    'limpieza' => [
        'label' => 'Limpieza con personal',
        'description' => 'Registro del trabajo de las camaristas: quién limpió cada habitación, cuánto tardó, qué hizo y cuánta ropa usó, con reporte de rendimiento. El semáforo de limpieza va en todos los planes.',
        'available' => true,
        'group' => 'operacion',
    ],

    'incidencias' => [
        'label' => 'Incidencias de mantenimiento',
        'description' => 'Tickets de fallas por habitación o área con tipo de falla, prioridad y fotos. El bloqueo básico por mantenimiento va en todos los planes.',
        'available' => true,
        'group' => 'operacion',
    ],

    'incidencias-avanzado' => [
        'label' => 'Incidencias avanzadas',
        'description' => 'Responsables asignados por incidencia, costo de la reparación con catálogo de técnicos y proveedores, y reportes por periodo con PDF.',
        'available' => true,
        'group' => 'operacion',
    ],

    'encuestas' => [
        'label' => 'Cuestionario de experiencia',
        'description' => 'Encuesta post-estancia por liga o QR en la habitación, con aspectos personalizables y resultados.',
        'available' => true,
        'group' => 'huespedes',
    ],

    'encuestas-avanzado' => [
        'label' => 'Satisfacción avanzada',
        'description' => 'Alertas al staff por malas evaluaciones y reporte de satisfacción con PDF.',
        'available' => true,
        'group' => 'huespedes',
    ],

    'promos' => [
        'label' => 'Promociones',
        'description' => 'Temporadas y promos de precio: por fechas, días de la semana o estancia larga.',
        'available' => true,
        'group' => 'reservas',
    ],

    'tablero-avanzado' => [
        'label' => 'Tablero personalizado',
        'description' => 'Edición del plano visual: acomodo drag-and-drop según la distribución real del hotel.',
        'available' => true,
        'group' => 'operacion',
    ],

    // OJO: "widget" en este proyecto es el embebido del sitio web
    // (/widget.js). Estas piezas del plano se llaman paneles a propósito.
    'plano-operativo' => [
        'label' => 'Plano operativo',
        'description' => 'Paneles dentro del plano para trabajar sin salir de ahí: estado de la casa, alta y edición de habitaciones, consumos y cobro, caja del turno.',
        'available' => true,
        'group' => 'operacion',
    ],
];
