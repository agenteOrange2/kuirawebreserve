<?php

namespace App\Jobs;

use App\Mail\StaffNoticeMail;
use App\Models\Courtesy;
use App\Services\TenantMailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * Correo a los propietarios cuando alguien del personal pide una cortesía:
 * la campana solo se ve con el panel abierto y la decisión les toca a
 * ellos. Va a los correos de los propietarios, no a los avisos del hotel
 * (/ajustes/avisos-hotel), que puede leer cualquiera del mostrador.
 *
 * Corre bajo el tenant que lo despachó (stancl serializa el contexto).
 */
class SendCourtesyNoticeMail implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** @param  array<int, string>  $emails */
    public function __construct(
        public int $courtesyId,
        public array $emails,
        public string $subjectLine,
        public string $intro,
    ) {}

    public function handle(TenantMailer $tenantMailer): void
    {
        $courtesy = Courtesy::with(['reservation', 'requester'])->find($this->courtesyId);

        if ($courtesy === null || ! $courtesy->isPending() || $this->emails === []) {
            return;
        }

        $mail = new StaffNoticeMail(
            subjectLine: $this->subjectLine,
            intro: $this->intro,
            lines: [
                'Cuenta' => $courtesy->targetLabel(),
                'Huésped' => $courtesy->guest_name ?: 'Sin nombre',
                'Cortesía' => $courtesy->kindLabel(),
                'Descuento' => '$'.number_format((float) $courtesy->amount, 2),
                'Categoría' => $courtesy->categoryLabel(),
                'Motivo' => $courtesy->reason,
                'La pide' => $courtesy->requester?->name ?? '',
            ],
            url: url('/reservas/cortesias?ver='.$courtesy->id),
        );

        ($tenantMailer->mailer() ?? Mail::mailer())->to($this->emails)->send($mail);
    }
}
