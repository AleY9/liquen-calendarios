# Liquen Calendarios

Plugin de WordPress: disponibilidad de cabañas, salones y otros recursos leída de calendarios iCal (Google Calendar, Booking, Airbnb). Detalles en `readme.txt`; especificación y hoja de ruta en el proyecto (`liquen-calendarios-spec.md`).

## Publicar una versión nueva

1. Subir los cambios con el número nuevo en `liquen-calendarios.php` (en `Version:` y en `LC_VERSION`) y una línea en «Cambios» de `readme.txt`.
2. Releases → **Draft a new release** → etiqueta `vX.Y.Z` (el mismo número) → **Publish release**.
3. GitHub arma solo `liquen-calendarios.zip` y lo adjunta (pestaña Actions, ~1 min). Los sitios ven la actualización en unas horas, o al instante con Escritorio → Actualizaciones → «Volver a comprobar».

Para instalar a mano, usar ese `liquen-calendarios.zip` de la release, nunca «Source code».
