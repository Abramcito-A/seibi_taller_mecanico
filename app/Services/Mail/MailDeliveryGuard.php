<?php

namespace App\Services\Mail;

use Illuminate\Validation\ValidationException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Determina si el correo saliente se entrega realmente, si solo se simula (driver log/array en desarrollo)
 * o si debe bloquearse porque la configuración no es apta para envíos reales (remitente provisional,
 * URL local o sin HTTPS, credenciales SMTP ausentes o dominio sin autenticar).
 *
 * Existe además un modo de prueba local (MODE_LOCAL_TEST) que permite un envío SMTP real con un remitente
 * verificado individualmente en Brevo cuando el dominio todavía no tiene SPF/DKIM. Solo se activa con
 * APP_ENV=local y únicamente entrega a SEIBI_TEST_RECIPIENT; en cualquier otro entorno se ignora.
 *
 * Los controladores y servicios solo consultan este resultado, por lo que cambiar a Brevo (o a otro
 * proveedor) se hace únicamente desde el .env.
 */
class MailDeliveryGuard
{
    public const MODE_SIMULATED = 'simulado';

    public const MODE_LIVE = 'real';

    public const MODE_LOCAL_TEST = 'prueba_local';

    public const MODE_BLOCKED = 'bloqueado';

    private const SIMULATED_TRANSPORTS = ['log', 'array'];

    private const RESERVED_DOMAIN_SUFFIXES = ['.invalid', '.test', '.example', '.localhost', '.local'];

    private const RESERVED_DOMAINS = ['example.com', 'example.net', 'example.org', 'localhost'];

    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1', '[::1]', '0.0.0.0'];

    /**
     * @return array{mode: string, transport: string, problems: list<string>, test_recipient: string|null}
     */
    public function status(): array
    {
        $mailer = (string) config('mail.default');
        $transport = $this->transport();

        if ($this->isSimulatedTransport()) {
            if (app()->isProduction()) {
                return $this->result(self::MODE_BLOCKED, $transport, ['El driver de correo "'.$transport.'" solo guarda el mensaje en el servidor y no puede usarse como envío real en producción.']);
            }

            return $this->result(self::MODE_SIMULATED, $transport, []);
        }

        $problems = [];

        $from = (string) config('mail.from.address');
        if (! $this->isDeliverableAddress($from)) {
            $problems[] = 'El remitente (MAIL_FROM_ADDRESS) es provisional o no es válido; usa una dirección verificada del dominio del taller.';
        }

        $replyTo = config('seibi.mail.reply_to.address');
        if (filled($replyTo) && ! $this->isDeliverableAddress((string) $replyTo)) {
            $problems[] = 'La dirección de respuesta (SEIBI_MAIL_REPLY_TO) es provisional o no es válida.';
        }

        $appUrl = (string) config('app.url');
        $host = strtolower((string) parse_url($appUrl, PHP_URL_HOST));
        if (strtolower((string) parse_url($appUrl, PHP_URL_SCHEME)) !== 'https' || $host === '' || in_array($host, self::LOCAL_HOSTS, true) || $this->isReservedDomain($host) || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $problems[] = 'APP_URL debe ser la dirección pública del sistema con HTTPS; los enlaces del correo se generan con ese valor.';
        }

        if ($transport === 'smtp') {
            $config = (array) config("mail.mailers.$mailer");
            if (blank($config['host'] ?? null) || blank($config['username'] ?? null) || blank($config['password'] ?? null)) {
                $problems[] = 'Faltan los datos SMTP (MAIL_HOST, MAIL_USERNAME y MAIL_PASSWORD) en el .env del servidor.';
            }
        }

        if (config('seibi.mail.domain_verified')) {
            return $this->result($problems === [] ? self::MODE_LIVE : self::MODE_BLOCKED, $transport, $problems);
        }

        // Dominio sin autenticar: solo puede continuar el modo de prueba local, y únicamente si se pidió expresamente.
        if (! config('seibi.mail.local_test.allow_unverified_sender')) {
            $problems[] = 'El dominio del remitente no está marcado como autenticado (SPF y DKIM en Brevo). Activa SEIBI_MAIL_DOMAIN_VERIFIED cuando Brevo lo confirme.';

            return $this->result(self::MODE_BLOCKED, $transport, $problems);
        }

        $problems = array_merge($problems, $this->localTestProblems($mailer, $transport));

        return $this->result($problems === [] ? self::MODE_LOCAL_TEST : self::MODE_BLOCKED, $transport, $problems);
    }

    public function mode(): string
    {
        return $this->status()['mode'];
    }

    public function isSimulated(): bool
    {
        return $this->mode() === self::MODE_SIMULATED;
    }

    public function isLocalTest(): bool
    {
        return $this->mode() === self::MODE_LOCAL_TEST;
    }

    /**
     * Dirección única autorizada para recibir correos en desarrollo local, o null si no está configurada o no es válida.
     */
    public function testRecipient(): ?string
    {
        $recipient = strtolower(trim((string) config('seibi.mail.local_test.recipient')));

        return $recipient !== '' && filter_var($recipient, FILTER_VALIDATE_EMAIL) !== false ? $recipient : null;
    }

    /**
     * En modo de prueba local rechaza cualquier destinatario distinto de SEIBI_TEST_RECIPIENT. En los demás modos no hace nada.
     *
     * @throws ValidationException
     */
    public function assertRecipientAllowed(string $recipient): void
    {
        if (! $this->isLocalTest()) {
            return;
        }

        if (! $this->isTestRecipient($recipient)) {
            throw ValidationException::withMessages([
                'envio' => 'Modo de prueba local: solo se permite enviar a la dirección de pruebas ('.$this->testRecipient().'). El cliente de esta orden tiene registrado otro correo; para probar, registra esa dirección en la ficha de un cliente de prueba. No se envió nada.',
            ]);
        }
    }

    /**
     * Última barrera antes de entregar cualquier correo (se usa desde el evento MessageSending). En desarrollo local con un
     * transporte real solo se permite entregar a SEIBI_TEST_RECIPIENT y con el remitente de MAIL_FROM_ADDRESS, sin importar
     * qué parte del sistema genere el correo. Devuelve los motivos de bloqueo (lista vacía = se permite).
     *
     * @return list<string>
     */
    public function messageViolations(Email $message): array
    {
        if (! app()->environment('local') || $this->isSimulatedTransport()) {
            return [];
        }

        $violations = [];
        $testRecipient = $this->testRecipient();
        $recipients = array_merge($message->getTo(), $message->getCc(), $message->getBcc());

        if ($testRecipient === null) {
            $violations[] = 'sin_destinatario_de_pruebas';
        } elseif ($recipients === [] || array_filter($recipients, fn (Address $address): bool => ! $this->isTestRecipient($address->getAddress())) !== []) {
            $violations[] = 'destinatario_no_autorizado';
        }

        $from = $message->getFrom();
        $expectedFrom = strtolower(trim((string) config('mail.from.address')));
        if (count($from) !== 1 || strtolower($from[0]->getAddress()) !== $expectedFrom) {
            $violations[] = 'remitente_distinto';
        }

        return $violations;
    }

    private function isTestRecipient(string $address): bool
    {
        $testRecipient = $this->testRecipient();

        return $testRecipient !== null && strtolower(trim($address)) === $testRecipient;
    }

    /**
     * @return list<string>
     */
    private function localTestProblems(string $mailer, string $transport): array
    {
        $problems = [];

        if (! app()->environment('local')) {
            // En producción o cualquier otro entorno la excepción no existe: se exige el dominio autenticado.
            return ['El dominio del remitente no está marcado como autenticado (SPF y DKIM en Brevo). SEIBI_ALLOW_UNVERIFIED_SENDER_FOR_LOCAL solo tiene efecto con APP_ENV=local.'];
        }

        if ($mailer !== 'smtp' || $transport !== 'smtp') {
            $problems[] = 'El modo de prueba local requiere MAIL_MAILER=smtp.';
        }

        if ($this->testRecipient() === null) {
            $problems[] = 'El modo de prueba local requiere una dirección válida en SEIBI_TEST_RECIPIENT.';
        }

        return $problems;
    }

    private function transport(): string
    {
        $mailer = (string) config('mail.default');

        return (string) config("mail.mailers.$mailer.transport", $mailer);
    }

    private function isSimulatedTransport(): bool
    {
        return in_array($this->transport(), self::SIMULATED_TRANSPORTS, true);
    }

    /**
     * @param  list<string>  $problems
     * @return array{mode: string, transport: string, problems: list<string>, test_recipient: string|null}
     */
    private function result(string $mode, string $transport, array $problems): array
    {
        return [
            'mode' => $mode,
            'transport' => $transport,
            'problems' => $problems,
            'test_recipient' => $mode === self::MODE_LOCAL_TEST ? $this->testRecipient() : null,
        ];
    }

    private function isDeliverableAddress(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        return ! $this->isReservedDomain(strtolower((string) substr(strrchr($address, '@'), 1)));
    }

    private function isReservedDomain(string $domain): bool
    {
        if (in_array($domain, self::RESERVED_DOMAINS, true)) {
            return true;
        }

        foreach (self::RESERVED_DOMAIN_SUFFIXES as $suffix) {
            if (str_ends_with($domain, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
