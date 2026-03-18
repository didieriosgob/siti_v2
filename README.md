# SITI / SIGEI – paquete base organizado para XAMPP

Este paquete toma la lógica actual del sistema y la conserva, sin cambiar la base de datos `tickets`.
Se reorganizó la estructura para que sea más mantenible y más segura para evolucionar.

## Estructura

- `config/` conexión a base de datos
- `assets/` estilos, JS e imágenes
- `database/` respaldo SQL de referencia
- `uploads/` archivos públicos de oficios y cotizaciones
- `storage/backups/` carpeta sugerida para respaldos
- `docs/` notas técnicas
- `*.php` puntos de entrada del sistema compatibles con XAMPP

## Instalación rápida

1. Copia la carpeta a `htdocs`.
2. Crea la base de datos `tickets` en phpMyAdmin.
3. Importa `database/tickets.sql`.
4. Ajusta credenciales en `config/database.php` si tu MySQL no usa `root` sin contraseña.
5. Abre `http://localhost/siti-secoed-v2/login.php`

## Usuario admin

Si necesitas crear uno, usa `seed.php` o `add_user.php` después de configurar la conexión.

## Notas

- `index.php` quedó preparado para permitir consulta/alta pública sin forzar login.
- Se agregó captcha aritmético para el alta pública.
- Se incluye `ticket_view.php` para ver el detalle completo del ticket.
- El diseño visual se concentró en `assets/css/styles.css`.
