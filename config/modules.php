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
    ],

    'cobros' => [
        'label' => 'Cobros en línea',
        'description' => 'Pasarelas de pago (Stripe, Mercado Pago) y links de cobro. Las transferencias con verificación van en todos los planes.',
        'available' => true,
    ],

    'mensajeria' => [
        'label' => 'Bandeja de mensajes',
        'description' => 'Bandeja unificada de conversaciones y canales conectados (WhatsApp, Messenger, Instagram, webchat); el número de canales lo limita el plan.',
        'available' => true,
    ],

    'agente-ia' => [
        'label' => 'Asistente IA',
        'description' => 'Bot que responde y aparta por chat con las llaves de la plataforma; la cuota mensual la define el plan. Requiere la Bandeja de mensajes.',
        'available' => true,
    ],

    'motor-web' => [
        'label' => 'Motor de reservas web',
        'description' => 'Integración con sitios (WordPress): catálogo con precios en vivo, tokens e importador; el wizard público de reservas viene en camino.',
        'available' => true,
    ],

    'redes-sociales' => [
        'label' => 'IA en redes sociales',
        'description' => 'Amplía el asistente para responder comentarios en publicaciones de Facebook, Instagram y TikTok y convertirlos en solicitudes de reserva. Requiere el Asistente IA.',
        'available' => false,
    ],

    'menu-digital' => [
        'label' => 'Menú digital',
        'description' => 'Carta pública en /menu con los productos del POS que el hotel elija: el huésped pide por liga o QR y la solicitud llega a la campana del staff.',
        'available' => true,
    ],

    'extras' => [
        'label' => 'Extras de reserva',
        'description' => 'Add-ons que suman al total: decoraciones, desayuno, late checkout.',
        'available' => true,
    ],

    'experiencias' => [
        'label' => 'Experiencias',
        'description' => 'Tours y recorridos con horario y cupo propios.',
        'available' => true,
    ],

    'grupos' => [
        'label' => 'Reservas grupales',
        'description' => 'Varias habitaciones en una sola reserva: un folio de grupo, todo-o-nada.',
        'available' => true,
    ],

    'lista-espera' => [
        'label' => 'Lista de espera',
        'description' => 'Sin disponibilidad, el wizard captura al interesado y se le avisa solo cuando una cancelación libera sus fechas.',
        'available' => true,
    ],

    'cupones' => [
        'label' => 'Cupones',
        'description' => 'Códigos de descuento (porcentaje o monto) con condiciones: noches mínimas, tipo de habitación, cliente frecuente o cumpleaños.',
        'available' => true,
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
    ],

    'anticipos' => [
        'label' => 'Anticipos y saldos',
        'description' => 'Cobro anticipado configurable, control de saldos pendientes y alertas de pagos vencidos.',
        'available' => true,
    ],

    'corte-caja' => [
        'label' => 'Corte de caja por turno',
        'description' => 'Turnos de recepción con corte por método de pago, arqueo con diferencias, movimientos y pagos pendientes.',
        'available' => true,
    ],

    'bitacora' => [
        'label' => 'Bitácora de acciones',
        'description' => 'Quién hizo qué y cuándo en todo el hotel, filtrable por usuario, tipo y fecha.',
        'available' => true,
    ],

    'crm-avanzado' => [
        'label' => 'CRM de huéspedes completo',
        'description' => 'Historial de visitas, lista negra, identificación (INE) y registro de vehículo. El directorio básico va en todos los planes.',
        'available' => true,
    ],

    'incidencias' => [
        'label' => 'Incidencias de mantenimiento',
        'description' => 'Tickets de fallas por habitación o área con tipo de falla, prioridad y fotos. El bloqueo básico por mantenimiento va en todos los planes.',
        'available' => true,
    ],

    'incidencias-avanzado' => [
        'label' => 'Incidencias avanzadas',
        'description' => 'Responsables asignados por incidencia y reportes por periodo con PDF.',
        'available' => true,
    ],

    'encuestas' => [
        'label' => 'Cuestionario de experiencia',
        'description' => 'Encuesta post-estancia por liga o QR en la habitación, con aspectos personalizables y resultados.',
        'available' => true,
    ],

    'encuestas-avanzado' => [
        'label' => 'Satisfacción avanzada',
        'description' => 'Alertas al staff por malas evaluaciones y reporte de satisfacción con PDF.',
        'available' => true,
    ],

    'promos' => [
        'label' => 'Promociones',
        'description' => 'Temporadas y promos de precio: por fechas, días de la semana o estancia larga.',
        'available' => true,
    ],

    'tablero-avanzado' => [
        'label' => 'Tablero personalizado',
        'description' => 'Edición del plano visual: acomodo drag-and-drop según la distribución real del hotel.',
        'available' => true,
    ],
];
