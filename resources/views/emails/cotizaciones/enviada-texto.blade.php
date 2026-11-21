{!! $workshopName !!}

Hola {!! $cliente->nombre !!}:

Preparamos la cotización de los trabajos para tu vehículo. Puedes revisar cada concepto y elegir cuáles autorizas desde el enlace privado.

Orden de servicio: {!! $ordenFolio !!}
Cotización: {!! $cotizacion->folio !!} (versión {!! $cotizacion->version !!})
Vehículo: {!! $vehicleLabel !!}@if($plates) · Placas {!! $plates !!}@endif

Importe total cotizado: $ {!! $total !!} MXN
Enlace válido hasta: {!! $expiresLabel !!} (hora de Monterrey)

Revisar y autorizar la cotización:
{!! $accessUrl !!}

El importe final será la suma de los conceptos que aceptes.
Este enlace es personal; no lo compartas. Si tienes dudas, responde a este correo o comunícate con el taller.
