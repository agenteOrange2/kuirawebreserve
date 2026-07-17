<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Extra;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Página del módulo Extras de reserva: catálogo de add-ons que se ofrecen
 * en el paso Extras del wizard y suman al total de la reserva.
 */
class ExtrasPageController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('tenant/extras/Index', [
            'extras' => Extra::query()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (Extra $extra) => [
                    'id' => $extra->id,
                    'name' => $extra->name,
                    'description' => $extra->description,
                    'price' => (float) $extra->price,
                    'active' => $extra->active,
                    'sort_order' => $extra->sort_order,
                ]),
            'canManage' => $request->user()->can('properties.manage'),
        ]);
    }
}
