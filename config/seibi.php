<?php

/*
|--------------------------------------------------------------------------
| Configuración propia de SEIBI
|--------------------------------------------------------------------------
|
| Identidad del taller, vigencia de los enlaces privados de cotización y los
| datos del correo que no forman parte de config/mail.php.
|
| Pasos pendientes para activar el envío real con Brevo (cuando exista dominio):
|  1. Registrar el dominio del taller y publicarlo con HTTPS (APP_URL=https://...).
|  2. En Brevo, agregar el dominio y publicar en el DNS los registros que Brevo indique:
|     SPF (TXT), DKIM (TXT/CNAME) y DMARC (TXT, recomendado). Esperar a que Brevo los
|     marque como verificados.
|  3. Crear y verificar en Brevo el remitente (MAIL_FROM_ADDRESS) con ese dominio.
|  4. Generar una clave SMTP en Brevo (no una API key) y colocarla solo en el .env del
|     servidor: MAIL_USERNAME (login SMTP de Brevo) y MAIL_PASSWORD (clave SMTP).
|  5. Cambiar MAIL_MAILER=smtp, MAIL_FROM_ADDRESS y SEIBI_MAIL_REPLY_TO a direcciones
|     reales del dominio, y SEIBI_MAIL_DOMAIN_VERIFIED=true.
|
| Mientras cualquiera de esos datos falte, App\Services\Mail\MailDeliveryGuard
| bloquea el envío real: en desarrollo el correo se escribe en el log (simulación).
|
*/

return [

    'workshop_name' => env('SEIBI_WORKSHOP_NAME', 'SEIBI'),

    'quotation_link_ttl_days' => (int) env('SEIBI_QUOTATION_LINK_TTL_DAYS', 7),

    /*
     * Días que el cliente puede seguir consultando el resultado de la reparación (solo lectura) después de que la
     * orden se entrega, se cierra o se cancela. Mientras la orden está activa, un enlace autorizado no vence.
     */
    'tracking_link_days_after_close' => (int) env('SEIBI_TRACKING_LINK_DAYS_AFTER_CLOSE', 30),

    /*
     * Zona horaria en la que se presentan fechas al cliente y en el correo. Las fechas se guardan en la
     * zona horaria de la aplicación (config('app.timezone')) y solo se convierten al mostrarse.
     */
    'display_timezone' => 'America/Monterrey',

    'mail' => [
        'reply_to' => [
            'address' => env('SEIBI_MAIL_REPLY_TO'),
            'name' => env('SEIBI_WORKSHOP_NAME', 'SEIBI'),
        ],

        /*
         * Solo debe ponerse en true cuando Brevo confirme SPF y DKIM del dominio del remitente.
         * La aplicación no puede comprobar el DNS por sí misma; este indicador es la constancia del propietario.
         */
        'domain_verified' => (bool) env('SEIBI_MAIL_DOMAIN_VERIFIED', false),

        /*
         * Modo de prueba local: permite un envío SMTP real con un remitente verificado individualmente en
         * Brevo aunque el dominio aún no tenga SPF/DKIM. Solo se activa con APP_ENV=local, MAIL_MAILER=smtp,
         * APP_URL público con HTTPS y credenciales SMTP; y únicamente entrega a SEIBI_TEST_RECIPIENT.
         * Nunca sustituye las comprobaciones de producción (ver App\Services\Mail\MailDeliveryGuard).
         */
        'local_test' => [
            'allow_unverified_sender' => (bool) env('SEIBI_ALLOW_UNVERIFIED_SENDER_FOR_LOCAL', false),
            'recipient' => env('SEIBI_TEST_RECIPIENT'),
        ],
    ],

];
