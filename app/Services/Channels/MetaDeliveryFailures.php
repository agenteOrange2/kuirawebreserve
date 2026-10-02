<?php

namespace App\Services\Channels;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Property;
use App\Models\StaffNotification;
use App\Models\Tenant;
use App\Services\StaffNotifier;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Qué hacer cuando la Cloud API avisa —después, por webhook— que un mensaje
 * NO se entregó.
 *
 * Hasta el 2026-09-24 esto solo se escribía en la bitácora: 350 fallos en 12
 * días de cabañas y **cero** mensajes marcados en la bandeja. El hotel creía
 * que había contestado y el huésped nunca recibió nada.
 *
 * Los dos motivos reales, medidos en ese corpus:
 * - **131047** (262): pasaron más de 24 h desde el último mensaje del
 *   destinatario y WhatsApp solo deja escribir con plantilla aprobada. 90 de
 *   esos iban al teléfono del PROPIO HOTEL: sus avisos de traspaso morían sin
 *   que nadie lo supiera.
 * - **131026** (88): número no entregable — casi todos de El Paso, armados
 *   con lada mexicana.
 */
class MetaDeliveryFailures
{
    public function __construct(protected OutboundMessenger $messenger) {}

    /**
     * @param  array<int, array<string, mixed>>  $errors  tal como los manda Meta
     */
    public function handle(string $tenantId, ?string $wamid, ?string $to, array $errors): void
    {
        $tenant = Tenant::find($tenantId);

        if (! $tenant) {
            return;
        }

        try {
            $tenant->run(fn () => $this->apply($wamid, (string) $to, $errors));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Lo mismo, ya dentro del tenant (así se puede probar sin montar tenancy).
     *
     * @param  array<int, array<string, mixed>>  $errors
     */
    public function apply(?string $wamid, string $to, array $errors): void
    {
        $code = (int) ($errors[0]['code'] ?? 0);
        $motivo = $this->reason($code, $errors);

        // ¿Era un aviso al propio hotel? Ese no vive en ninguna conversación:
        // se le avisa por la campana del panel, que sí lee el personal.
        if ($this->isHotelNumber($to)) {
            $this->tellStaffTheirOwnAlertFailed($motivo, $code);

            return;
        }

        $message = $this->messageFor($wamid, $to);

        if ($message === null) {
            Log::info('Meta: entrega fallida sin mensaje que marcar', [
                'to' => $to,
                'wamid' => $wamid,
                'code' => $code,
            ]);

            return;
        }

        $message->forceFill([
            'meta' => array_merge($message->meta ?? [], [
                'undelivered' => true,
                'delivery_error' => $motivo,
                'delivery_error_code' => $code,
            ]),
        ])->saveQuietly();

        if ($message->conversation) {
            // Deja el hilo en "esperando al personal" y suena la campana con
            // el texto que no llegó, para que alguien lo mande por otra vía.
            $this->messenger->flagUndelivered($message->conversation, $message);
        }

        Log::warning('Meta: mensaje marcado como no entregado', [
            'conversation_id' => $message->conversation_id,
            'message_id' => $message->id,
            'code' => $code,
            'motivo' => $motivo,
        ]);
    }

    /**
     * El mensaje exacto por su id de Meta; si no lo tenemos guardado (los
     * envíos viejos no lo traen), el último que le mandamos a ese número en
     * las últimas 48 h.
     */
    protected function messageFor(?string $wamid, string $to): ?Message
    {
        if ($wamid !== null && $wamid !== '') {
            $exacto = Message::query()
                ->whereJsonContains('meta->wamids', $wamid)
                ->latest('id')
                ->first();

            if ($exacto) {
                return $exacto;
            }
        }

        $conversation = $this->conversationFor($to);

        if ($conversation === null) {
            return null;
        }

        return Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'out')
            ->where('created_at', '>=', now()->subHours(48))
            ->latest('id')
            ->first();
    }

    protected function conversationFor(string $to): ?Conversation
    {
        $ultimos = $this->last10($to);

        if ($ultimos === '') {
            return null;
        }

        return Conversation::query()
            ->where('contact_phone', 'like', '%'.$ultimos)
            ->latest('last_message_at')
            ->first();
    }

    /**
     * Un aviso al hotel que no llegó es peor que uno que no se mandó: el
     * personal está esperando que le vibre el teléfono. Se le dice por la
     * campana, y con el motivo en castellano.
     */
    protected function tellStaffTheirOwnAlertFailed(string $motivo, int $code): void
    {
        try {
            app(StaffNotifier::class)->notify(
                type: StaffNotification::TYPE_MESSAGE,
                title: 'Un aviso por WhatsApp no le llegó al hotel',
                body: 'WhatsApp rechazó el aviso que les mandamos a su número: '.$motivo
                    .' Revisen la bandeja: puede haber un huésped esperando.',
                url: '/bandeja?esperando=1',
            );
        } catch (Throwable $e) {
            report($e);
        }

        Log::warning('Meta: el aviso al hotel no se entregó', [
            'code' => $code,
            'motivo' => $motivo,
        ]);
    }

    protected function isHotelNumber(string $to): bool
    {
        $settings = Property::query()->first()?->settings ?? [];

        $numeros = collect($settings['transfer_whatsapps'] ?? [])
            ->map(fn ($w) => $this->last10((string) (($w['code'] ?? '').($w['number'] ?? ''))))
            ->push($this->last10((string) ($settings['phone'] ?? '')))
            ->push($this->last10((string) ($settings['support_whatsapp'] ?? '')))
            ->filter()
            ->unique();

        return $numeros->contains($this->last10($to));
    }

    /** Los últimos 10 dígitos: el mismo número se escribe 52…, 521… o pelón. */
    protected function last10(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return strlen($digits) >= 10 ? substr($digits, -10) : '';
    }

    /**
     * @param  array<int, array<string, mixed>>  $errors
     */
    protected function reason(int $code, array $errors): string
    {
        return match ($code) {
            131047 => 'pasaron más de 24 horas desde su último mensaje y WhatsApp ya no deja escribirle sin plantilla aprobada.',
            131026 => 'ese número no recibe WhatsApp (o está mal escrito).',
            131049, 131050 => 'WhatsApp limitó los mensajes a ese número.',
            default => (string) ($errors[0]['error_data']['details'] ?? $errors[0]['title'] ?? 'WhatsApp lo rechazó ('.$code.').'),
        };
    }
}
