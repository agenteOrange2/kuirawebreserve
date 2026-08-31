<?php

/*
|--------------------------------------------------------------------------
| Familias del catálogo de módulos
|--------------------------------------------------------------------------
|
| Solo agrupan para la pantalla: quien arma un plan (o revisa los de un
| hotel) ve 25 interruptores y sin familias se leen revueltos. La verdad de
| qué hace cada módulo sigue en config/modules.php, con su llave `group`
| apuntando aquí; el orden de este archivo es el orden en pantalla.
|
| Un módulo sin `group` (o con un grupo que ya no exista) cae en "otros" —
| nunca se pierde de la lista.
|
*/

return [
    'reservas' => [
        'label' => 'Reservas y tarifas',
        'description' => 'Cómo se vende la estancia: canales, precios y variantes de reserva.',
        'icon' => 'CalendarCheck2',
    ],

    'cobros' => [
        'label' => 'Cobros y caja',
        'description' => 'Cómo entra el dinero y cómo se cuadra al cerrar el turno.',
        'icon' => 'CreditCard',
    ],

    'consumos' => [
        'label' => 'Consumos del huésped',
        'description' => 'Lo que se vende dentro del hotel además del hospedaje.',
        'icon' => 'ShoppingCart',
    ],

    'atencion' => [
        'label' => 'Atención y mensajería',
        'description' => 'Por dónde llegan los mensajes y quién los contesta.',
        'icon' => 'MessagesSquare',
    ],

    'operacion' => [
        'label' => 'Operación diaria',
        'description' => 'El trabajo de piso: plano, limpieza, mantenimiento y control.',
        'icon' => 'ClipboardList',
    ],

    'huespedes' => [
        'label' => 'Huéspedes y calidad',
        'description' => 'A quién se le vendió y qué tan bien le fue.',
        'icon' => 'Users',
    ],

    'otros' => [
        'label' => 'Otros',
        'description' => 'Módulos sin familia asignada.',
        'icon' => 'Blocks',
    ],
];
