<?php
/**
 * Datos: fuentes de cada recurso (post meta), tabla de ocupaciones y cálculo de días ocupados.
 *
 * Las ocupaciones se guardan como intervalos [inicio, fin) en hora local del sitio, no como días.
 * Así, cuando lleguen los modos por horas o por noches, la base no cambia: cambia el cálculo.
 */

defined( 'ABSPATH' ) || exit;

function lc_tabla() {
	global $wpdb;
	return $wpdb->prefix . 'lc_ocupaciones';
}

function lc_crear_tabla() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$tabla   = lc_tabla();
	$charset = $wpdb->get_charset_collate();
	dbDelta(
		"CREATE TABLE $tabla (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			recurso_id bigint(20) unsigned NOT NULL,
			fuente_id varchar(20) NOT NULL,
			inicio datetime NOT NULL,
			fin datetime NOT NULL,
			todo_el_dia tinyint(1) NOT NULL DEFAULT 0,
			uid_hash char(32) NOT NULL,
			PRIMARY KEY  (id),
			KEY recurso_fechas (recurso_id,inicio,fin),
			KEY fuente (recurso_id,fuente_id)
		) $charset;"
	);
	update_option( 'lc_db_version', LC_DB_VERSION, false );
}

/* ------------------------------------------------------------------ */
/* Recursos y fuentes                                                  */
/* ------------------------------------------------------------------ */

/**
 * Fuentes de un recurso: [ ['id', 'nombre', 'url', 'salida_ocupa' => bool], ... ].
 */
function lc_fuentes( int $recurso_id ): array {
	$f = get_post_meta( $recurso_id, '_lc_fuentes', true );
	return is_array( $f ) ? array_values( $f ) : array();
}

function lc_modo( int $recurso_id ): string {
	$m = (string) get_post_meta( $recurso_id, '_lc_modo', true );
	return isset( lc_modos()[ $m ] ) ? $m : 'dias';
}

// Estado de lectura por fuente: [fuente_id => ['ok' => ts, 'intento' => ts, 'error' => '', 'eventos' => n, 'avisos' => []]].
function lc_estado( int $recurso_id ): array {
	$e = get_post_meta( $recurso_id, '_lc_estado', true );
	return is_array( $e ) ? $e : array();
}

// Tiene calendario: fuentes iCal o actividades que usan este espacio.
function lc_tiene_calendario( int $id ): bool {
	return (bool) lc_fuentes( $id ) || lc_suma_actividades( $id );
}

function lc_es_recurso( $post ): bool {
	$post = get_post( $post );
	return $post && in_array( $post->post_type, lc_tipos_habilitados(), true ) && lc_tiene_calendario( $post->ID );
}

/**
 * IDs de los recursos con calendario.
 *
 * @param array $args 'tipos' => [], 'ids' => [], 'estado' => 'publish'|'any'
 */
function lc_recursos( array $args = array() ): array {
	$tipos = ! empty( $args['tipos'] ) ? array_intersect( (array) $args['tipos'], lc_tipos_habilitados() ) : lc_tipos_habilitados();
	if ( ! $tipos ) {
		return array();
	}
	$q = array(
		'post_type'      => array_values( $tipos ),
		'post_status'    => $args['estado'] ?? 'publish',
		'posts_per_page' => 200,
		'fields'         => 'ids',
		'orderby'        => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
		'meta_query'     => array(
			'relation' => 'OR',
			array(
				'key'     => '_lc_fuentes',
				'compare' => 'EXISTS',
			),
			array(
				'key'     => '_lc_actividades',
				'compare' => 'EXISTS',
			),
		),
		'no_found_rows'  => true,
	);
	if ( ! empty( $args['ids'] ) ) {
		$q['post__in'] = array_map( 'intval', (array) $args['ids'] );
		$q['orderby']  = 'post__in';
	}
	return array_values( array_filter( get_posts( $q ), 'lc_tiene_calendario' ) );
}

/* ------------------------------------------------------------------ */
/* Ocupaciones                                                         */
/* ------------------------------------------------------------------ */

// Reemplaza todas las ocupaciones de una fuente.
function lc_guardar_ocupaciones( int $recurso_id, string $fuente_id, array $eventos ): void {
	global $wpdb;
	$tabla = lc_tabla();

	$wpdb->query( 'START TRANSACTION' );
	$wpdb->delete( $tabla, array( 'recurso_id' => $recurso_id, 'fuente_id' => $fuente_id ), array( '%d', '%s' ) );
	foreach ( $eventos as $e ) {
		$wpdb->insert(
			$tabla,
			array(
				'recurso_id'  => $recurso_id,
				'fuente_id'   => $fuente_id,
				'inicio'      => $e['inicio'],
				'fin'         => $e['fin'],
				'todo_el_dia' => $e['todo_el_dia'] ? 1 : 0,
				'uid_hash'    => md5( $e['uid'] ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s' )
		);
	}
	$wpdb->query( 'COMMIT' );
	lc_olvidar_cache();
}

// Borra las ocupaciones de un recurso; si se pasan fuentes a conservar, borra solo las demás.
function lc_borrar_ocupaciones( int $recurso_id, ?array $conservar = null ): void {
	global $wpdb;
	$tabla = lc_tabla();
	if ( null === $conservar || ! $conservar ) {
		$wpdb->delete( $tabla, array( 'recurso_id' => $recurso_id ), array( '%d' ) );
	} else {
		$lugares = implode( ',', array_fill( 0, count( $conservar ), '%s' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM $tabla WHERE recurso_id = %d AND fuente_id NOT IN ($lugares)", array_merge( array( $recurso_id ), array_values( $conservar ) ) ) ); // phpcs:ignore
	}
	lc_olvidar_cache();
}

// Las respuestas de la API se guardan unos minutos; se olvidan cuando cambian los datos.
function lc_olvidar_cache(): void {
	update_option( 'lc_cache_version', (string) microtime( true ), false );
}

/**
 * Días ocupados de un recurso entre $desde y $hasta (Y-m-d, $hasta exclusivo), según su modo.
 *
 * @return string[] Fechas Y-m-d ordenadas.
 */
function lc_dias_ocupados( int $recurso_id, string $desde, string $hasta ): array {
	global $wpdb;
	$tabla = lc_tabla();
	$filas = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT inicio, fin, todo_el_dia FROM $tabla WHERE recurso_id = %d AND fin >= %s AND inicio < %s", // phpcs:ignore
			$recurso_id,
			$desde . ' 00:00:00',
			$hasta . ' 00:00:00'
		),
		ARRAY_A
	);

	$zona  = lc_zona();
	$d_ini = new DateTimeImmutable( $desde, $zona );
	$d_fin = new DateTimeImmutable( $hasta, $zona );
	$dias  = array();

	foreach ( $filas as $f ) {
		$ini = new DateTimeImmutable( $f['inicio'], $zona );
		$fin = new DateTimeImmutable( $f['fin'], $zona );

		// Modo «Días completos»: ocupa cada día que el intervalo toca.
		// Día completo: de inicio a fin exclusivo (fin ya incluye el día de salida si la fuente lo pide).
		// Con hora: el último día tocado es el del instante anterior al fin.
		if ( (int) $f['todo_el_dia'] ) {
			$ultimo = $fin->modify( '-1 day' );
		} else {
			$ultimo = $fin > $ini ? $fin->modify( '-1 second' ) : $ini;
		}
		$dia    = $ini->setTime( 0, 0 );
		$ultimo = $ultimo->setTime( 0, 0 );

		while ( $dia <= $ultimo ) {
			if ( $dia >= $d_ini && $dia < $d_fin ) {
				$dias[ $dia->format( 'Y-m-d' ) ] = true;
			}
			$dia = $dia->modify( '+1 day' );
		}
	}

	$dias = array_keys( $dias );
	sort( $dias );
	return $dias;
}
