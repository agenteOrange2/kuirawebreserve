<?php

/*
|--------------------------------------------------------------------------
| Cortesías (módulo cortesias)
|--------------------------------------------------------------------------
|
| Categorías fijas para que el reporte pueda agrupar: el motivo libre dice
| el porqué de cada una, la categoría dice de qué tipo fue. El orden es el
| de los chips en el diálogo.
|
*/

return [
    'categories' => [
        'cliente_importante' => 'Cliente importante',
        'amistad' => 'Amistad o familia',
        'compensacion' => 'Compensación por una queja',
        'promocion' => 'Promoción o convenio',
        'otro' => 'Otro',
    ],

    'kinds' => [
        'full' => 'Gratis',
        'percent' => 'Porcentaje',
        'amount' => 'Monto',
    ],
];
