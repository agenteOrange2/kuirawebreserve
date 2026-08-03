<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Semáforo de habitaciones en vivo (spec §10). El nombre del canal lleva el
// tenant para que dos hoteles nunca compartan canal en Reverb; se verifica
// que el tenant de la sesión coincida y que el usuario pueda ver habitaciones.
Broadcast::channel('tenant.{tenantId}.property.{propertyId}.rooms', function ($user, string $tenantId, int $propertyId) {
    return tenant('id') === $tenantId && $user->can('rooms.view');
});

// Actividad de la bandeja (mensaje nuevo en cualquier canal). Mismo criterio
// que el semáforo: el tenant va en el nombre del canal para que dos hoteles
// nunca lo compartan, y solo entra quien puede ver la bandeja.
Broadcast::channel('tenant.{tenantId}.inbox', function ($user, string $tenantId) {
    return tenant('id') === $tenantId && $user->can('reservations.view');
});
