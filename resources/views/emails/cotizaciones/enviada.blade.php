<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <title>Cotización {{ $cotizacion->folio }}</title>
</head>
<body style="margin:0;padding:0;background-color:#F3F5F7;font-family:Arial,Helvetica,sans-serif;color:#223247;">
    <span style="display:none;max-height:0;overflow:hidden;">Consulta y autoriza la cotización {{ $cotizacion->folio }} de tu vehículo.</span>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#F3F5F7;">
        <tr>
            <td align="center" style="padding:24px 12px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;background-color:#FFFFFF;border:1px solid #DCE2E8;border-radius:12px;">
                    <tr>
                        <td style="background-color:#172B40;border-radius:12px 12px 0 0;padding:20px 24px;">
                            <p style="margin:0;font-size:18px;font-weight:bold;color:#FFFFFF;">{{ $workshopName }}</p>
                            <p style="margin:6px 0 0;font-size:13px;color:#C9D3DE;">Cotización de servicio para tu vehículo</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="height:4px;background-color:#C65317;font-size:0;line-height:0;">&nbsp;</td>
                    </tr>
                    <tr>
                        <td style="padding:24px;">
                            <p style="margin:0 0 12px;font-size:15px;line-height:22px;">Hola {{ $cliente->nombre }}:</p>
                            <p style="margin:0 0 20px;font-size:15px;line-height:22px;">Preparamos la cotización de los trabajos para tu vehículo. Puedes revisar cada concepto y elegir cuáles autorizas desde el siguiente enlace privado.</p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #DCE2E8;border-radius:8px;">
                                <tr>
                                    <td style="padding:12px 16px;border-bottom:1px solid #DCE2E8;font-size:13px;color:#64748B;">Orden de servicio</td>
                                    <td align="right" style="padding:12px 16px;border-bottom:1px solid #DCE2E8;font-size:14px;font-weight:bold;">{{ $ordenFolio }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:12px 16px;border-bottom:1px solid #DCE2E8;font-size:13px;color:#64748B;">Cotización</td>
                                    <td align="right" style="padding:12px 16px;border-bottom:1px solid #DCE2E8;font-size:14px;font-weight:bold;">{{ $cotizacion->folio }} · versión {{ $cotizacion->version }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:12px 16px;border-bottom:1px solid #DCE2E8;font-size:13px;color:#64748B;">Vehículo</td>
                                    <td align="right" style="padding:12px 16px;border-bottom:1px solid #DCE2E8;font-size:14px;">{{ $vehicleLabel }}@if($plates)<br><span style="color:#64748B;font-size:13px;">Placas {{ $plates }}</span>@endif</td>
                                </tr>
                                <tr>
                                    <td style="padding:12px 16px;border-bottom:1px solid #DCE2E8;font-size:13px;color:#64748B;">Importe total cotizado</td>
                                    <td align="right" style="padding:12px 16px;border-bottom:1px solid #DCE2E8;font-size:16px;font-weight:bold;">$ {{ $total }} MXN</td>
                                </tr>
                                <tr>
                                    <td style="padding:12px 16px;font-size:13px;color:#64748B;">Enlace válido hasta</td>
                                    <td align="right" style="padding:12px 16px;font-size:14px;">{{ $expiresLabel }} <span style="color:#64748B;font-size:12px;">(hora de Monterrey)</span></td>
                                </tr>
                            </table>

                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:24px auto;">
                                <tr>
                                    <td align="center" style="border-radius:8px;background-color:#C65317;">
                                        <a href="{{ $accessUrl }}" target="_blank" rel="noopener noreferrer" style="display:inline-block;padding:14px 28px;font-size:15px;font-weight:bold;color:#FFFFFF;text-decoration:none;border-radius:8px;">Revisar y autorizar cotización</a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:0 0 8px;font-size:13px;line-height:20px;color:#64748B;">El importe total incluye todos los conceptos propuestos. Tú decides cuáles autorizar; el importe final será la suma de los conceptos que aceptes.</p>
                            <p style="margin:0 0 8px;font-size:13px;line-height:20px;color:#64748B;">Si el botón no funciona, copia este enlace en tu navegador:<br><span style="word-break:break-all;color:#172B40;">{{ $accessUrl }}</span></p>
                            <p style="margin:0;font-size:13px;line-height:20px;color:#64748B;">Este enlace es personal; no lo compartas. Si tienes dudas, responde a este correo o comunícate con el taller.</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 24px;border-top:1px solid #DCE2E8;font-size:12px;line-height:18px;color:#94A3B8;">
                            {{ $workshopName }} · Mensaje generado automáticamente para el cliente de la orden {{ $ordenFolio }}.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
