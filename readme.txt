=== Liquen Calendarios ===
Versión: 0.1.0 · Requiere: WordPress 6.4+, PHP 8.0+

Disponibilidad de cabañas, salones y otros recursos leída de calendarios iCal
(Google Calendar, Booking, Airbnb). La web muestra días libres y ocupados y arma la consulta por WhatsApp.
Solo lectura: no toma reservas ni cobra.

== Instalar ==
1. Backup (JetBackup).
2. Plugins → Añadir nuevo → Subir plugin → liquen-calendarios.zip → Activar.
3. Ajustes → Liquen Calendarios → pestaña «Asistente»: 6 preguntas sobre cómo se llevan las reservas.
   Devuelve qué conectar en cada cabaña y qué conviene cambiar.
   Pestaña «Ajustes»: tipos con calendario (ej. Alojamientos), WhatsApp, meses a mostrar.
4. En cada ficha, caja «Calendario»: una fila por fuente.
   * Google Calendar: Configuración del calendario → «Dirección secreta en formato iCal».
   * Booking: Extranet → Tarifas y disponibilidad → Sincronizar calendarios → Exportar.
   * «Día de salida: también ocupa»: marcar en las fuentes que piensan en noches (Booking, Airbnb)
     cuando el día que se van no se puede alquilar. En Google, sin marcar.
5. Plantilla de la ficha (Bricks → elemento Shortcode): [liquen_calendario]
6. Página general: [liquen_disponibilidad] (opcional tipos="alojamiento" o ids="12,15").
7. cPanel → Tareas programadas, cada 30 min:
   wget -q -O - https://SITIO/wp-cron.php?doing_wp_cron >/dev/null 2>&1
8. Pestaña «Diagnóstico»: lista de chequeo con todo lo que está bien, lo que conviene revisar y lo que hay
   que arreglar, con la solución paso a paso. Los problemas de cada cabaña también aparecen en su caja «Calendario».

== Cómo funciona ==
* Lee las fuentes cada 30 min y al guardar la ficha (o con «Actualizar ahora»).
* Varias fuentes por ficha: un día está ocupado si alguna lo ocupa.
* Modo «Días completos»: ocupa cada día que toca una reserva. Un evento de día completo del 15 al 17 en Google
  ocupa 15, 16 y 17. Una reserva de Booking del 15 al 17 ocupa 15 y 16 (y el 17 si la fuente tiene marcado
  «Día de salida: también ocupa»). Un evento con hora ocupa los días que toca.
* Entiende eventos repetidos (diarios, semanales, mensuales, anuales), excepciones y cancelados.
* Guarda solo inicio y fin de cada evento: nunca títulos, nombres ni correos.
* Las direcciones iCal no salen nunca a la web.
* La disponibilidad llega a la página por la API (/wp-json/liquen-calendarios/v1/disponibilidad), así que las
  páginas se pueden guardar en caché (LiteSpeed) sin mostrar días viejos.
* Si una fuente no se puede leer, se conservan los últimos datos y aparece un aviso en wp-admin a las 6 h.

== Límites ==
* No es instantáneo: la web se actualiza cada 30 min; Booking y Airbnb también tardan en leer.
* Solo fechas: ni precios, ni datos de huéspedes, ni pagos.

== Cambios ==
0.1.0
* Primera versión: modo «Días completos», varias fuentes iCal por ficha, calendario en la ficha con consulta
  por WhatsApp, página general (tabla en computadora, lista en celular), actualizaciones desde GitHub.
* Asistente: preguntas sobre cómo se llevan las reservas → qué conectar, con qué casilla y qué cambiar.
* Diagnóstico: lista de chequeo automática (lecturas, direcciones públicas o repetidas, casilla del día de salida,
  shortcodes en la web, tarea programada), con ayudas paso a paso.
* Al pegar una dirección en la ficha, se detecta si es Google, Booking o Airbnb y se sugiere la casilla.
