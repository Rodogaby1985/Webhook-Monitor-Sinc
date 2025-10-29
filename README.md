# Webhook Monitor & Sinc

Plugin para WooCommerce que automatiza el monitoreo y la reactivación de webhooks clave, envía alertas por correo electrónico y permite la resincronización manual de pedidos desde el admin.

## Funcionalidad

- **Monitoreo automático** de los webhooks "Ninox - Orden creada" y "Ninox - Orden actualizada".
- **Reactivación automática** de webhooks si se detectan desactivados.
- **Alertas por email** cuando un webhook es reactivado o el plugin se desactiva.
- **Botón de resincronización** en la lista de pedidos del admin, con indicador verde/rojo según estado de los campos `nxsync` y `nxsync_status`.
- **Sincronización manual** forzada de pedidos al hacer clic en el botón.
- **Configuración de horario** y correos electrónicos desde el backend (Ajustes de WordPress).
- **Cron diario** configurable para revisión fuera de horario laboral.

## Instalación

1. Sube el archivo `webhook-monitor-sinc.php` a la carpeta `/wp-content/plugins/` de tu WordPress.
2. Activa el plugin desde el menú de Plugins en el admin de WordPress.
3. Ve a **Ajustes > Webhook Monitor & Sinc** para configurar:
    - Horario del cron.
    - Activar/desactivar cron.
    - Correos de alerta (separados por coma).

## Uso

- El plugin revisa los webhooks clave al entrar al admin y en el horario configurado por cron.
- Si detecta un webhook desactivado, lo activa automáticamente y envía una alerta a los correos configurados.
- Si el plugin se desactiva, se envía una alerta.
- En la lista de pedidos, el botón verde indica que el pedido está sincronizado; el rojo permite forzar la resincronización.
- Puedes forzar la sincronización de un pedido haciendo clic en el ícono de la columna "Sinc." en la lista de pedidos.

## Personalización

- Los nombres de los webhooks monitoreados están definidos en el código (`Ninox - Orden creada`, `Ninox - Orden actualizada`). Para cambiar estos, edita la constante `WMS_WEBHOOKS_CLAVE` en el archivo principal del plugin.

## Soporte

Para consultas, sugerencias o mejoras, puedes contactar a Rodogaby1985 o Copilot por GitHub.
