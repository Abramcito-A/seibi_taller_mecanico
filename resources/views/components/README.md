# Sistema de diseño SEIBI

## Fundamentos

Los tokens de marca viven en `resources/css/app.css` dentro de `@theme`. Los seis colores oficiales no se redefinen en vistas: usa las utilidades `seibi-navy`, `seibi-ink`, `seibi-orange`, `seibi-surface`, `seibi-workspace` y `seibi-border`. Inter Variable se importa desde Fontsource y viaja en el bundle de Vite.

Usa el naranja solo para la acción principal y sus interacciones. Los estados deben llevar un tono semántico y texto visible; las insignias aceptan `neutral`, `success`, `error`, `info` y `pending`.

## Componentes

- `<x-ui.button variant="primary|secondary|neutral|destructive" :href="..." icon="plus">` sirve para acciones y enlaces. El naranja queda reservado a `primary`.
- `<x-ui.input name="correo" label="Correo" type="email" :value="$cliente->correo" />`, `<x-ui.select name="id_cliente" label="Cliente">...</x-ui.select>` y `<x-ui.textarea name="notas" label="Notas" :value="$vehiculo->notas" />` conservan valores anteriores y muestran errores Laravel asociados.
- `<x-ui.page-header title="Clientes" description="...">` acepta el slot nombrado `actions`.
- `<x-ui.card>` ofrece una superficie simple; `<x-ui.panel title="..." description="...">` añade cabecera y acciones opcionales.
- `<x-ui.badge tone="success|error|info|pending|neutral">` mantiene texto visible. `<x-ui.alert variant="success|error|warning|info">` comunica notificaciones.
- `<x-ui.confirm-trigger form-id="delete-client-1" title="Eliminar cliente" message="Esta acción no se puede deshacer.">Eliminar</x-ui.confirm-trigger>` abre el diálogo global accesible. El formulario asociado debe tener el mismo `id` y mantener su protección CSRF. Para una acción compacta en tablas, usa `variant="destructive-link" size="compact"`.
- `<x-ui.empty-state title="Sin resultados" description="..." icon="users">` admite el slot `action`.
- `<x-ui.nav-link href="..." icon="users" :active="request()->routeIs('clientes.*')">Clientes</x-ui.nav-link>` marca la ruta actual; `<x-ui.pagination :paginator="$clientes" />` envuelve la paginación Laravel.

## Convenciones

Todas las pantallas administrativas extienden `layouts.app` y colocan sus vistas en `@section('content')`. Mantén rutas, validaciones y lógica de dominio en sus capas actuales. Usa los componentes para formularios, acciones, estados y superficies en vez de copiar clases. Alpine y sus plugins se inicializan una sola vez en `resources/js/app.js`; Lucide importa solo los iconos usados. Motion queda reservado a entradas puntuales y respeta `prefers-reduced-motion`.