<?php
/**
 * Lectura de las fuentes iCal: cada 30 min (WP-Cron) y con «Actualizar ahora».
 * Si una lectura falla, se conservan los datos anteriores de esa fuente.
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'cron_schedules', function ( $s ) {
	$s['lc_media_hora'] = array(
		'interval' => 30 * MINUTE_IN_SECONDS,
		'display'  => 'Cada 30 minutos (Liquen Calendarios)',
	);
	return $s;
} );

add_action( 'lc_sincronizar_todo', 'lc_sincronizar_todo' );

// Si la tarea programada se perdió (p. ej. migración), se vuelve a agendar.
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'lc_sincronizar_todo' ) ) {
		wp_schedule_event( time() + 60, 'lc_media_hora', 'lc_sincronizar_todo' );
	}
} );

function lc_sincronizar_todo(): void {
	// Evita dos lecturas a la vez (cron del cPanel + WP-Cron).
	if ( get_transient( 'lc_leyendo' ) ) {
		return;
	}
	set_transient( 'lc_leyendo', 1, 10 * MINUTE_IN_SECONDS );
	foreach ( lc_recursos( array( 'estado' => array( 'publish', 'private', 'draft', 'future', 'pending' ) ) ) as $id ) {
		lc_sincronizar_recurso( $id );
	}
	delete_transient( 'lc_leyendo' );
	update_option( 'lc_ultima_ronda', time(), false );
}

/**
 * Lee todas las fuentes de un recurso.
 *
 * @return array Estado por fuente (el mismo que se guarda en _lc_estado).
 */
function lc_sincronizar_recurso( int $recurso_id ): array {
	$fuentes = lc_fuentes( $recurso_id );
	$estado  = lc_estado( $recurso_id );
	$ids     = array_column( $fuentes, 'id' );
	$ids[]   = 'actividades'; // Días de las actividades que usan este espacio (se arman aparte, sin descargar nada).

	// Lo que quedó de fuentes borradas, fuera.
	lc_borrar_ocupaciones( $recurso_id, $ids );
	$estado = array_intersect_key( $estado, array_flip( $ids ) );

	$zona  = lc_zona();
	$desde = ( new DateTimeImmutable( 'today', $zona ) )->modify( '-1 day' );
	$hasta = $desde->modify( '+13 months' );

	foreach ( $fuentes as $f ) {
		$e            = $estado[ $f['id'] ] ?? array();
		$e['intento'] = time();
		$res          = lc_leer_fuente( $f['url'], $desde, $hasta );

		if ( is_wp_error( $res ) ) {
			$e['error'] = $res->get_error_message();
		} else {
			$eventos = $res['eventos'];
			// «El día de salida también ocupa»: a los eventos de día completo se les suma el día de salida.
			if ( ! empty( $f['salida_ocupa'] ) ) {
				foreach ( $eventos as &$ev ) {
					if ( $ev['todo_el_dia'] ) {
						$ev['fin'] = ( new DateTimeImmutable( $ev['fin'], $zona ) )->modify( '+1 day' )->format( 'Y-m-d H:i:s' );
					}
				}
				unset( $ev );
			}
			lc_guardar_ocupaciones( $recurso_id, $f['id'], $eventos );
			$e['ok']      = time();
			$e['error']   = '';
			$e['eventos'] = count( $eventos );
			$e['avisos']  = $res['avisos'];
		}
		$estado[ $f['id'] ] = $e;
	}

	lc_sincronizar_actividades_recurso( $recurso_id );

	update_post_meta( $recurso_id, '_lc_estado', $estado );
	return $estado;
}

/**
 * Descarga y lee una fuente.
 *
 * @return array|WP_Error ['eventos' => [], 'avisos' => []]
 */
function lc_leer_fuente( string $url, DateTimeImmutable $desde, DateTimeImmutable $hasta ) {
	if ( ! $url ) {
		return new WP_Error( 'lc_sin_url', 'Falta la dirección del calendario.' );
	}

	/**
	 * Permite reemplazar la descarga (para pruebas o fuentes que no son HTTP).
	 * Devolver un string con el iCal o un WP_Error.
	 */
	$texto = apply_filters( 'lc_descargar_ical', null, $url );

	if ( null === $texto ) {
		$resp = wp_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 3,
				'headers'     => array(
					'Accept'     => 'text/calendar, */*',
					'User-Agent' => 'Liquen-Calendarios/' . LC_VERSION . '; ' . home_url(),
				),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return new WP_Error( 'lc_red', 'No se pudo conectar: ' . $resp->get_error_message() );
		}
		$codigo = wp_remote_retrieve_response_code( $resp );
		if ( 200 !== $codigo ) {
			$pista = in_array( $codigo, array( 401, 403, 404 ), true ) ? ' Revisá que la dirección esté completa y siga vigente.' : '';
			return new WP_Error( 'lc_http', 'El calendario respondió con el código ' . $codigo . '.' . $pista );
		}
		$texto = wp_remote_retrieve_body( $resp );
	}

	if ( is_wp_error( $texto ) ) {
		return $texto;
	}
	if ( false === stripos( (string) $texto, 'BEGIN:VCALENDAR' ) ) {
		return new WP_Error( 'lc_formato', 'La dirección no devolvió un calendario iCal. ¿Es la dirección secreta en formato iCal (Google) o el enlace de exportación (Booking)?' );
	}

	return lc_ical_leer( (string) $texto, $desde, $hasta );
}

// Al borrar un recurso, se borran sus ocupaciones.
add_action( 'before_delete_post', function ( $post_id ) {
	lc_borrar_ocupaciones( (int) $post_id );
} );
add_action( 'trashed_post', 'lc_olvidar_cache' );
add_action( 'untrashed_post', 'lc_olvidar_cache' );
