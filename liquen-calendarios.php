<?php
/**
 * Plugin Name:       Liquen Calendarios
 * Description:       Disponibilidad de cabañas, salones y otros recursos leída de calendarios iCal (Google Calendar, Booking, Airbnb). Muestra días libres y ocupados en la web y arma la consulta por WhatsApp.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Liquen Web
 * Text Domain:       liquen-calendarios
 * License:           GPL-2.0-or-later
 * Update URI:        https://github.com/AleY9/liquen-calendarios
 *
 * Ajustes: wp-admin → Ajustes → Liquen Calendarios.
 * Fuentes: caja «Calendario» en cada ficha de los tipos habilitados.
 * Web:     [liquen_calendario] en la ficha y [liquen_disponibilidad] en la página general.
 */

defined( 'ABSPATH' ) || exit;

define( 'LC_VERSION', '0.1.0' );
define( 'LC_DB_VERSION', '1' );
define( 'LC_FILE', __FILE__ );
define( 'LC_DIR', plugin_dir_path( __FILE__ ) );
define( 'LC_URL', plugin_dir_url( __FILE__ ) );

require_once LC_DIR . 'includes/ajustes.php';
require_once LC_DIR . 'includes/ical.php';
require_once LC_DIR . 'includes/datos.php';
require_once LC_DIR . 'includes/sincronizar.php';
require_once LC_DIR . 'includes/admin.php';
require_once LC_DIR . 'includes/asistente.php';
require_once LC_DIR . 'includes/rest.php';
require_once LC_DIR . 'includes/web.php';
require_once LC_DIR . 'includes/actualizaciones.php';

register_activation_hook( __FILE__, 'lc_activar' );
register_deactivation_hook( __FILE__, 'lc_desactivar' );

function lc_activar() {
	lc_crear_tabla();
	// Que WordPress vuelva a revisar actualizaciones ya: así aparece enseguida
	// «Activar las actualizaciones automáticas» en la lista de plugins.
	delete_site_transient( 'update_plugins' );
	if ( ! wp_next_scheduled( 'lc_sincronizar_todo' ) ) {
		wp_schedule_event( time() + 60, 'lc_media_hora', 'lc_sincronizar_todo' );
	}
}

function lc_desactivar() {
	wp_clear_scheduled_hook( 'lc_sincronizar_todo' );
}

// Si se actualizó el plugin y cambió la tabla, se ajusta sola.
add_action( 'plugins_loaded', function () {
	if ( get_option( 'lc_db_version' ) !== LC_DB_VERSION ) {
		lc_crear_tabla();
	}
} );
