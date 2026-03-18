# Notas de arquitectura

## Lo que se respetó
- Tablas originales: `tickets`, `actividades`, `oficios`, `cotizaciones_facturas`, `users`, `direcciones`, `departamentos`.
- Estados originales de tickets: `Pendiente`, `En camino`, `Atendido`.
- Flujo existente de actividades, POA, analytics, oficios y cotizaciones.

## Mejoras aplicadas en este paquete
- Separación de configuración (`config/database.php`).
- Estilos centralizados (`assets/css/styles.css`).
- Directorios de upload ya creados.
- `ticket_view.php` nuevo.
- `index.php` sin redirección obligatoria para visitantes.
- Captcha aritmético para el formulario público.
- Recursos gráficos básicos ya incluidos para evitar referencias rotas.

## Recomendaciones siguientes
1. Blindar todo delete/update con token CSRF.
2. Pasar oficios y tickets a paginación server-side completa con filtros persistentes.
3. Registrar auditoría de cambios en una tabla `auditoria`.
4. Respaldar automáticamente `uploads/` y la BD.
