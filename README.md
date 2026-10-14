# SEIBI — Gestión de taller mecánico

SEIBI es una aplicación web orientada a centralizar la información operativa de un taller mecánico y agilizar la elaboración de cotizaciones. El sistema contempla el registro de clientes y vehículos, órdenes de servicio, cotizaciones y un buscador multifuente de autopartes.

## Problema que resuelve

Durante la elaboración de una cotización, el personal puede invertir tiempo buscando refacciones en diferentes sitios y transcribiendo datos manualmente. SEIBI propone consultar distintas ofertas comerciales desde un único apartado y permitir que el personal seleccione una opción para incorporarla a la cotización.

## Funcionalidades previstas

- Autenticación del personal del taller.
- Registro de clientes y vehículos.
- Órdenes de servicio y consulta del estado mediante un enlace de solo lectura.
- Cotizaciones con refacciones y mano de obra.
- Buscador multifuente conectado inicialmente a una sola API agregadora, sujeto a validar su acceso, costo y calidad de resultados.
- Captura manual de piezas como alternativa cuando la consulta externa no esté disponible.
- Evidencia fotográfica como función complementaria si el tiempo de implementación lo permite.

> **Compatibilidad de autopartes:** los resultados son ofertas comerciales; no certifican por sí mismos que una pieza sea adecuada para un vehículo. El personal del taller debe validar el número de parte y la compatibilidad con información confiable antes de aprobarla.

## Tecnologías previstas

- **Backend:** PHP y Laravel.
- **Interfaz:** Blade y Tailwind CSS; Alpine.js solo cuando haga falta interactividad ligera.
- **Base de datos:** MySQL.
- **Integración externa:** cliente HTTP de Laravel encapsulado en un servicio de búsqueda.
- **Control de versiones:** Git y GitHub.

Las tecnologías indicadas son la arquitectura propuesta y se deben actualizar si cambian durante la implementación.

## Estructura de la documentación

- [`docs/UNIDAD_2_CONTROL_VERSIONES_DIAGRAMAS.md`](docs/UNIDAD_2_CONTROL_VERSIONES_DIAGRAMAS.md): justificación de herramientas, workflow, configuración, evidencias y diagramas.
- [`docs/diccionario-de-datos.md`](docs/diccionario-de-datos.md): entidades, campos, tipos, restricciones y reglas de integridad.
- [`docs/diagramas/`](docs/diagramas/README.md): arquitectura, casos de uso y diagrama entidad-relación, junto con sus fuentes.
- [`docs/SEIBI_Unidad2_ControlVersiones_Diagramas_Diccionario.docx`](docs/SEIBI_Unidad2_ControlVersiones_Diagramas_Diccionario.docx): versión editable de la documentación.
- [`plantillas/evidencias.md`](plantillas/evidencias.md): lista de evidencias que deben completarse después de realizar las acciones reales en GitHub.

## Instalación y ejecución

Los comandos de instalación se completarán cuando el proyecto Laravel esté configurado. Nunca se debe publicar el archivo `.env`, claves de API, contraseñas ni datos reales de clientes.

## Estado del repositorio

La URL de GitHub, los commits, las ramas y las pull requests deben registrarse después de crearlos y publicarlos realmente. No se deben presentar capturas simuladas ni enlaces de ejemplo como si fueran evidencia.
