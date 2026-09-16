<?php

namespace App\Services\Channels;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\PaymentRequest;
use App\Models\Reservation;
use App\Services\Payments\PaymentProofHoldExtender;
use App\Services\Payments\ReceiptCheck;
use App\Services\Payments\ReceiptReader;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Medios entrantes de los canales (spec-pendientes §4.4 P2, flujo-bot-pagos
 * §7.6): la imagen o PDF que manda el huésped se guarda como adjunto del
 * mensaje y, ANTES de decidir su destino, se lee (ReceiptReader).
 *
 * - No es comprobante (selfie, INE, foto de la cabaña): se queda en el hilo,
 *   no sostiene el apartado ni llega a /pagos, y el bot puede contestar
 *   porque sabe qué se ve. Caso real cabañas 2026-09-15: cualquier foto
 *   sostenía la habitación 24 h y el huésped recibía "Recibimos tu
 *   comprobante".
 * - Es comprobante: sostiene el apartado, se pega al cobro por transferencia
 *   (el del GRUPO si es un grupo), se acusa con el monto leído y el personal
 *   recibe los datos y las diferencias para verificar sin abrir la foto.
 * - Clave de rastreo ya usada en otro cobro: no sostiene nada y el personal
 *   lo revisa.
 * - No se pudo leer (sin key, PDF, falla): el flujo de siempre, todo archivo
 *   cuenta como posible comprobante.
 *
 * Ningún camino da el pago por recibido: eso lo hace el personal en /pagos.
 */
class InboundMediaService
{
    public const OUTCOME_RECEIPT = 'receipt';

    public const OUTCOME_STORED = 'stored';

    /** Se leyó y NO es comprobante: el bot puede contestar sobre la imagen. */
    public const OUTCOME_DESCRIBED = 'described';

    /**
     * Marca del adjunto mientras se lee: el extensor del apartado se engancha
     * al evento de Media Library y, sin esta marca, sostenía la habitación
     * antes de saber si la foto era un comprobante.
     */
    public const DEFERRED = 'deferred';

    public function __construct(
        protected OutboundMessenger $messenger,
        protected ReceiptReader $reader,
        protected ReceiptCheck $check,
    ) {}

    /**
     * Guarda el binario y encadena el destino del adjunto. Devuelve el
     * desenlace (receipt|stored|described) o null si el tipo no se soporta o
     * falló.
     */
    public function handle(Conversation $conversation, Message $message, string $contents, string $mime, ?string $filename = null): ?string
    {
        $media = $this->attach($message, $contents, $mime, $filename);

        if (! $media) {
            return null;
        }

        $reading = $this->reader->read($contents, $mime);
        $check = $reading !== null ? $this->check->evaluate($reading, $this->pendingTransferRequest($conversation)) : null;

        if ($check !== null && $check['verdict'] === ReceiptCheck::NOT_RECEIPT) {
            $this->remember($message, $reading, $check);

            return self::OUTCOME_DESCRIBED;
        }

        // Una clave de rastreo reciclada no sostiene la habitación de nadie.
        if ($conversation->reservation_id && ($check === null || $check['verdict'] !== ReceiptCheck::DUPLICATE)) {
            try {
                app(PaymentProofHoldExtender::class)->extendFor($message);
            } catch (Throwable $e) {
                report($e);
            }
        }

        // Con el cobro ya emitido (el extensor lo crea si no había), las
        // diferencias de monto se miden contra el cobro real.
        if ($reading !== null && ($request = $this->pendingTransferRequest($conversation)) !== null) {
            $check = $this->check->evaluate($reading, $request);
        }

        $this->remember($message, $reading, $check);

        if (($check['verdict'] ?? null) !== ReceiptCheck::DUPLICATE
            && $this->attachAsReceipt($conversation, $media, $reading, $check)) {
            $this->acknowledgeReceipt($conversation, $reading, $check);
            $this->notifyStaff($conversation, $check, receipt: true);

            return self::OUTCOME_RECEIPT;
        }

        // Comprobante que no se pudo pegar a ningún cobro (repetido, sin
        // reserva ligada o con uno ya adjunto): el personal tiene que verlo.
        if ($check !== null) {
            $this->notifyStaff($conversation, $check, receipt: false);
        }

        // Sin cobro que verificar, una foto requiere ojos humanos: la
        // conversación pasa a "espera humano".
        if ($conversation->status !== Conversation::STATUS_PENDING) {
            $conversation->update(['status' => Conversation::STATUS_PENDING]);
        }

        return self::OUTCOME_STORED;
    }

    protected function attach(Message $message, string $contents, string $mime, ?string $filename): ?Media
    {
        $extension = match (true) {
            str_contains($mime, 'jpeg'), str_contains($mime, 'jpg') => 'jpg',
            str_contains($mime, 'png') => 'png',
            str_contains($mime, 'webp') => 'webp',
            str_contains($mime, 'pdf') => 'pdf',
            default => null,
        };

        if ($extension === null || $contents === '') {
            return null;
        }

        try {
            return $message->addMediaFromString($contents)
                ->usingFileName($filename ?: 'whatsapp-'.now()->format('YmdHis').'.'.$extension)
                ->withCustomProperties(['proof_review' => self::DEFERRED])
                ->toMediaCollection('attachments');
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Lo leído viaja con el mensaje: la bandeja lo muestra junto a la foto y
     * el bot lo recibe en su historial en vez de "adjuntó una imagen".
     *
     * @param  array<string, mixed>|null  $reading
     * @param  array<string, mixed>|null  $check
     */
    protected function remember(Message $message, ?array $reading, ?array $check): void
    {
        if ($reading === null) {
            return;
        }

        $message->forceFill([
            'meta' => array_merge($message->meta ?? [], [
                'media_reading' => $reading + ['check' => $check],
            ]),
        ])->saveQuietly();
    }

    /**
     * El cobro por transferencia que espera comprobante: el de la reserva o,
     * si es un grupo, el consolidado del GRP- (el de un grupo no cuelga de
     * ninguna habitación: buscándolo solo por reservation_id, el comprobante
     * de GRP-2026-0149 no se pegaba a nada y nadie lo vio en /pagos).
     */
    protected function pendingTransferRequest(Conversation $conversation): ?PaymentRequest
    {
        if (! $conversation->reservation_id) {
            return null;
        }

        $groupId = $conversation->reservation?->reservation_group_id;

        return PaymentRequest::query()
            ->where(function ($query) use ($conversation, $groupId) {
                $query->where('reservation_id', $conversation->reservation_id);

                if ($groupId !== null) {
                    $query->orWhere('reservation_group_id', $groupId);
                }
            })
            ->where('method', PaymentRequest::METHOD_TRANSFER)
            ->where('status', PaymentRequest::STATUS_PENDING)
            // El consolidado del grupo gana sobre uno suelto que quedara vivo.
            ->orderByRaw('reservation_group_id IS NULL')
            ->latest('id')
            ->first();
    }

    /**
     * ¿Este adjunto es el comprobante que se estaba esperando? Solo si hay
     * un cobro por transferencia pendiente. El primero gana, salvo que el
     * nuevo cuadre y el anterior no (el huésped mandó primero la captura
     * equivocada).
     *
     * @param  array<string, mixed>|null  $reading
     * @param  array<string, mixed>|null  $check
     */
    protected function attachAsReceipt(Conversation $conversation, Media $media, ?array $reading = null, ?array $check = null): bool
    {
        $request = $this->pendingTransferRequest($conversation);

        if (! $request) {
            return false;
        }

        $existing = $request->getFirstMedia('receipt');

        if ($existing) {
            // Puede ser ESTE mismo comprobante, ya pegado: el extensor del
            // apartado lo rescata del hilo al emitir el cobro. Si es el mismo
            // archivo, el comprobante SÍ entró y hay que acusarlo y avisar.
            $same = $existing->file_name === $media->file_name
                && $existing->created_at >= $media->created_at;

            $better = ! $same
                && ($check['verdict'] ?? null) === ReceiptCheck::MATCH
                && ($request->meta['receipt_check']['verdict'] ?? null) !== ReceiptCheck::MATCH;

            if (! $same && ! $better) {
                return false;
            }

            if ($same) {
                $this->rememberOnRequest($request, $media, $reading, $check);

                return true;
            }
        }

        try {
            $request->addMedia($media->getPath())
                ->preservingOriginal()
                ->usingFileName($media->file_name)
                ->toMediaCollection('receipt');

            $this->rememberOnRequest($request, $media, $reading, $check);

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * /pagos muestra lo leído junto al cobro: el personal verifica monto y
     * clave de rastreo contra el banco sin abrir la foto.
     *
     * @param  array<string, mixed>|null  $reading
     * @param  array<string, mixed>|null  $check
     */
    protected function rememberOnRequest(PaymentRequest $request, Media $media, ?array $reading, ?array $check): void
    {
        $request->update([
            'meta' => array_merge($request->meta ?? [], [
                'receipt_message_id' => $media->model_id,
                'receipt_reading' => $reading,
                'receipt_check' => $check,
                // Para detectar la misma captura en otro cobro.
                'tracking_key' => $reading['tracking_key'] ?? null,
            ]),
        ]);
    }

    /**
     * Rescate post-rechazo: el huésped mandó el comprobante bueno por el
     * chat ANTES de que el staff reemitiera el cobro (la solicitud
     * rechazada ya no adjunta nada). Al reemitir, la última imagen/PDF
     * del hilo (72 h) se adjunta a la solicitud nueva — aprobar queda a
     * un clic, sin descargar ni resubir. Una imagen que se leyó y NO es
     * comprobante no se rescata.
     */
    public function rescueLatestAttachment(PaymentRequest $request): bool
    {
        if ($request->getFirstMedia('receipt')) {
            return false;
        }

        $reservationIds = match (true) {
            $request->reservation_id !== null => [$request->reservation_id],
            $request->reservation_group_id !== null => Reservation::query()
                ->where('reservation_group_id', $request->reservation_group_id)
                ->pluck('id')
                ->all(),
            default => [],
        };

        if ($reservationIds === []) {
            return false;
        }

        $conversation = Conversation::query()
            ->whereIn('reservation_id', $reservationIds)
            ->latest('id')
            ->first();

        if (! $conversation) {
            return false;
        }

        $message = $conversation->messages()
            ->where('direction', 'in')
            ->where('created_at', '>=', now()->subHours(72))
            ->whereHas('media')
            ->latest('id')
            ->get()
            ->first(fn (Message $candidate) => ($candidate->meta['media_reading']['check']['verdict'] ?? null) !== ReceiptCheck::NOT_RECEIPT
                && ($candidate->meta['media_reading']['check']['verdict'] ?? null) !== ReceiptCheck::DUPLICATE);

        $media = $message?->getFirstMedia('attachments');

        if (! $media) {
            return false;
        }

        try {
            $request->addMedia($media->getPath())
                ->preservingOriginal()
                ->usingFileName($media->file_name)
                ->toMediaCollection('receipt');

            $reading = $message->meta['media_reading'] ?? null;

            if (is_array($reading)) {
                $check = $reading['check'] ?? null;
                unset($reading['check']);
                $this->rememberOnRequest($request, $media, $reading, $check);
            }

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Acuse SIN dar el pago por recibido (regla dura de spec-pagos): solo
     * se confirma que el comprobante llegó y que un humano lo verificará.
     * Con el folio del GRUPO cuando lo hay: con el de una sola cabaña el
     * huésped creyó que tenía que reactivar "el 1747" (GRP-2026-0152).
     *
     * @param  array<string, mixed>|null  $reading
     * @param  array<string, mixed>|null  $check
     */
    protected function acknowledgeReceipt(Conversation $conversation, ?array $reading = null, ?array $check = null): void
    {
        $reservation = $conversation->reservation;
        $code = $reservation?->group?->displayCode() ?? $reservation?->displayCode();
        $amount = ($reading['amount'] ?? null) !== null ? ' por $'.number_format((float) $reading['amount'], 2) : '';

        $body = "Recibimos tu comprobante{$amount}".($code ? " de la reserva {$code}" : '').'. El hotel lo verificará y te confirmaremos por aquí en cuanto quede registrado.';

        $request = $this->pendingTransferRequest($conversation);

        // La única diferencia que conviene decirle al huésped: la que puede
        // resolver él mismo. Las demás las revisa el personal.
        if ($request !== null && ($reading['amount'] ?? null) !== null && (float) $reading['amount'] + 1 < (float) $request->amount) {
            $body .= ' El monto que vemos es menor al anticipo de $'.number_format((float) $request->amount, 2).'; el personal lo revisa contigo.';
        }

        $conversation->messages()->create([
            'direction' => 'out',
            'sender_type' => 'system',
            'body' => $body,
            'created_at' => now(),
        ]);
        $conversation->update(['last_message_at' => now()]);

        $this->messenger->pushToConversation($conversation, $body);
    }

    /**
     * Dinero esperando ojos humanos: es el aviso más urgente, porque hasta
     * que alguien lo aprueba la reserva no avanza. Con lo leído y las
     * diferencias en el mismo aviso.
     *
     * @param  array<string, mixed>|null  $check
     */
    protected function notifyStaff(Conversation $conversation, ?array $check, bool $receipt): void
    {
        $who = $conversation->guest?->full_name ?? $conversation->contact_name ?? 'Un huésped';
        $reservation = $conversation->reservation;
        $code = $reservation?->group?->displayCode() ?? $reservation?->displayCode();

        [$title, $lead] = match (true) {
            ($check['verdict'] ?? null) === ReceiptCheck::DUPLICATE => ['Comprobante repetido', "{$who} mandó un comprobante que ya se había usado"],
            ! $receipt && $code === null => ['Comprobante sin reserva', "{$who} mandó un comprobante y no tiene reserva ligada"],
            ! $receipt => ['Comprobante por revisar', "{$who} mandó otro comprobante".($code ? " de {$code}" : '')],
            ($check['verdict'] ?? null) === ReceiptCheck::REVIEW => ['Comprobante con diferencias', "{$who} mandó su comprobante".($code ? " de {$code}" : '')],
            default => ['Comprobante por verificar', "{$who} mandó su comprobante de transferencia".($code ? " de {$code}" : '')],
        };

        $body = implode('. ', array_filter([
            $lead,
            $check['summary'] ?? null,
            ! empty($check['warnings']) ? 'Revisar: '.implode(' ', $check['warnings']) : null,
        ]));

        try {
            app(\App\Services\StaffNotifier::class)->notify(
                type: \App\Models\StaffNotification::TYPE_PAYMENT,
                title: $title,
                body: $body,
                url: $receipt ? '/pagos' : '/bandeja',
                subject: $reservation ?? $conversation,
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}
