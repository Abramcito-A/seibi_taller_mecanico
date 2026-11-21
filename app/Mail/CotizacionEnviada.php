<?php

namespace App\Mail;

use App\Models\Cotizacion;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Correo con el enlace privado para consultar y autorizar una cotización. Se envía de forma síncrona,
 * después de confirmar la transacción, para poder registrar de inmediato si el servidor de correo falla.
 */
class CotizacionEnviada extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Cotizacion $cotizacion,
        public string $accessUrl,
        public DateTimeInterface $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        $replyTo = config('seibi.mail.reply_to.address');

        return new Envelope(
            subject: 'Cotización '.$this->cotizacion->folio.' de su vehículo · '.config('seibi.workshop_name'),
            replyTo: filled($replyTo) ? [new Address((string) $replyTo, (string) config('seibi.mail.reply_to.name'))] : [],
        );
    }

    public function content(): Content
    {
        $vehiculo = $this->cotizacion->orden->vehiculo;

        return new Content(
            view: 'emails.cotizaciones.enviada',
            text: 'emails.cotizaciones.enviada-texto',
            with: [
                'workshopName' => (string) config('seibi.workshop_name'),
                'cliente' => $vehiculo->cliente,
                'ordenFolio' => $this->cotizacion->orden->folio,
                'vehicleLabel' => trim($vehiculo->marca.' '.$vehiculo->modelo.' '.$vehiculo->anio),
                'plates' => $vehiculo->placas,
                'total' => number_format((float) $this->cotizacion->total, 2, '.', ','),
                'expiresLabel' => Cotizacion::formatForDisplay($this->expiresAt, 'j \\d\\e F \\d\\e Y, H:i'),
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
