<?php
/**
 * API pública de disponibilidad. Devuelve solo días ocupados: ni direcciones iCal ni datos de reservas.
 *
 * GET /wp-json/liquen-calendarios/v1/disponibilidad?recursos=12,15&desde=2026-11-01&meses=6
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', function () {
	register_rest_route(
		'liquen-calendarios/v1',
		'/disponibilidad',
		array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => 'lc_rest_disponibilidad',
			'args'                => array(
				'recursos' => array(
					'required'          => true,
					'sanitize_callback' => function ( $v ) {
						return array_slice( array_filter( array_map( 'absint', explode( ',', (string) $v ) ) ), 0, 50 );
					},
				),
				'desde'    => array(
					'sanitize_callback' => 'sanitize_text_field',
				),
				'meses'    => array(
					'sanitize_callback' => 'absint',
				),
			),
		)
	);
} );

function lc_rest_disponibilidad( WP_REST_Request $req ) {
	// Respaldo: si la lectura automática está atrasada más de una hora (sitio con pocas visitas o
	// WP-Cron trabado), se dispara en segundo plano. No demora la respuesta.
	$prox = wp_next_scheduled( 'lc_sincronizar_todo' );
	if ( $prox && $prox < time() - HOUR_IN_SECONDS && ! get_transient( 'lc_leyendo' ) && function_exists( 'spawn_cron' ) ) {
		spawn_cron();
	}

	$ids   = (array) $req->get_param( 'recursos' );
	$meses = min( lc_meses(), max( 1, (int) ( $req->get_param( 'meses' ) ?: lc_meses() ) ) );
	$hoy   = lc_hoy();
	$desde = (string) $req->get_param( 'desde' );
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $desde ) || $desde < $hoy ) {
		$desde = $hoy;
	}
	// Siempre desde el primer día del mes, y nunca más allá de la ventana configurada.
	$zona  = lc_zona();
	$d     = new DateTimeImmutable( substr( $desde, 0, 7 ) . '-01', $zona );
	$tope  = ( new DateTimeImmutable( substr( $hoy, 0, 7 ) . '-01', $zona ) )->modify( '+' . lc_meses() . ' months' );
	$hasta = $d->modify( "+$meses months" );
	if ( $hasta > $tope ) {
		$hasta = $tope;
	}

	$clave = 'lc_api_' . md5( implode( ',', $ids ) . '|' . $d->format( 'Y-m-d' ) . '|' . $hasta->format( 'Y-m-d' ) . '|' . $hoy . '|' . get_option( 'lc_cache_version' ) );
	$datos = get_transient( $clave );

	if ( false === $datos ) {
		$recursos = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			// Solo fichas publicadas de tipos habilitados y con calendario.
			if ( ! $post || 'publish' !== $post->post_status || ! lc_es_recurso( $post ) ) {
				continue;
			}
			$recursos[] = array(
				'id'       => $id,
				'titulo'   => html_entity_decode( get_the_title( $id ), ENT_QUOTES ),
				'url'      => get_permalink( $id ),
				'modo'     => lc_modo( $id ),
				'ocupados' => lc_dias_ocupados( $id, $d->format( 'Y-m-d' ), $hasta->format( 'Y-m-d' ) ),
			);
		}
		$datos = array(
			'hoy'      => $hoy,
			'desde'    => $d->format( 'Y-m-d' ),
			'hasta'    => $hasta->format( 'Y-m-d' ),
			'recursos' => $recursos,
		);
		set_transient( $clave, $datos, 15 * MINUTE_IN_SECONDS );
	}

	$resp = rest_ensure_response( $datos );
	$resp->header( 'Cache-Control', 'public, max-age=60' );
	return $resp;
}
