<?php

namespace App\Services\Guests;

use App\Models\Property;
use App\Models\Reservation;
use Barryvdh\DomPDF\Facade\Pdf;
use Throwable;

/**
 * El contrato de hospedaje del huésped, en PDF y con SUS datos.
 *
 * El bot y las FAQs llevaban semanas prometiendo "te enviamos el contrato
 * digital" y el sistema no mandaba ninguno: el hotel lo hacía a mano o no lo
 * hacía (cabañas 2026-09-13, Montserrat: "no recibí el contrato al correo";
 * 2026-09-15, Damaris preguntó dos veces por el suyo).
 *
 * El texto es del hotel (`settings['contract_text']`, se edita en el panel);
 * aquí solo se le pega arriba la reserva concreta. Sin texto configurado no
 * se adjunta nada: ningún hotel manda un contrato que no escribió.
 */
class ReservationContract
{
    /** @param array<string, mixed>|null $settings */
    public function text(?array $settings = null): ?string
    {
        $settings ??= Property::query()->first()?->settings ?? [];
        $text = trim((string) ($settings['contract_text'] ?? ''));

        return $text !== '' ? $text : null;
    }

    public function available(): bool
    {
        return $this->text() !== null;
    }

    public function filename(Reservation $reservation): string
    {
        return 'contrato-'.strtolower($reservation->displayCode()).'.pdf';
    }

    /** El PDF en bytes, o null si el hotel no tiene contrato capturado. */
    public function pdf(Reservation $reservation): ?string
    {
        $property = Property::query()->first();
        $text = $this->text($property?->settings ?? []);

        if ($text === null) {
            return null;
        }

        $reservation->loadMissing(['guest', 'room', 'roomType']);

        try {
            return Pdf::loadView('pdf.reservation-contract', [
                'hotel' => [
                    'name' => $property?->name ?? config('app.name'),
                    'address' => $property?->address,
                    'phone' => $property?->settings['phones'][0]['number'] ?? $property?->settings['phone'] ?? null,
                    'email' => $property?->settings['email'] ?? null,
                ],
                'reservation' => $reservation,
                'guest' => [
                    'name' => $reservation->guest?->full_name ?: $reservation->guest_name,
                    'phone' => $reservation->guest?->phone,
                    'email' => $reservation->guest?->email,
                ],
                'money' => [
                    'total' => (float) $reservation->total_amount,
                    'paid' => $reservation->paidTotal(),
                    'pending' => $reservation->pendingBalance(),
                    'deposit' => (float) $reservation->deposit_amount,
                ],
                'blocks' => $this->blocks($text),
                'generatedAt' => now()->locale('es')->isoFormat('D [de] MMMM [de] YYYY, HH:mm'),
            ])->output();
        } catch (Throwable $e) {
            // Un contrato que no se pudo armar nunca detiene el aviso al
            // huésped: se reporta y el correo sale sin adjunto.
            report($e);

            return null;
        }
    }

    /**
     * El texto del hotel partido en bloques para la plantilla: "## " abre un
     * apartado, "- " es una regla de la lista y lo demás es un párrafo.
     *
     * @return list<array{type: string, text: string, items: list<string>}>
     */
    protected function blocks(string $text): array
    {
        $blocks = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, '## ')) {
                $blocks[] = ['type' => 'heading', 'text' => trim(substr($line, 3)), 'items' => []];

                continue;
            }

            if (str_starts_with($line, '- ')) {
                $item = trim(substr($line, 2));
                $last = array_key_last($blocks);

                if ($last !== null && $blocks[$last]['type'] === 'list') {
                    $blocks[$last]['items'][] = $item;

                    continue;
                }

                $blocks[] = ['type' => 'list', 'text' => '', 'items' => [$item]];

                continue;
            }

            $blocks[] = ['type' => 'paragraph', 'text' => $line, 'items' => []];
        }

        return $blocks;
    }
}
