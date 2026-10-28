<?php
/**
 * Web: shortcodes [liquen_calendario] y [liquen_disponibilidad], y sus archivos.
 * El HTML sale vacío y el calendario lo arma calendario.js con los datos de la API,
 * así la página puede estar en caché sin mostrar disponibilidad vieja.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', function () {
	add_shortcode( 'liquen_calendario', 'lc_sc_calendario' );
	add_shortcode( 'liquen_disponibilidad', 'lc_sc_disponibilidad' );
} );

function lc_encolar() {
	// Se pregunta a WordPress y no a una variable propia: el editor de bloques dibuja las cajas
	// una vez antes de preparar los scripts y lo encolado ahí se pierde.
	if ( wp_script_is( 'liquen-calendarios', 'enqueued' ) || wp_script_is( 'liquen-calendarios', 'done' ) ) {
		return;
	}
	$a = lc_ajustes();

	wp_enqueue_style( 'liquen-calendarios', LC_URL . 'assets/calendario.css', array(), LC_VERSION );
	wp_add_inline_style( 'liquen-calendarios', sprintf( ':root{--lc-acento:%s;--lc-ocupado:%s}', $a['color_acento'], $a['color_ocupado'] ) );

	wp_enqueue_script( 'liquen-calendarios', LC_URL . 'assets/calendario.js', array(), LC_VERSION, true );
	wp_localize_script(
		'liquen-calendarios',
		'lcConfig',
		array(
			'api'       => esc_url_raw( rest_url( 'liquen-calendarios/v1/disponibilidad' ) ),
			'idioma'    => str_replace( '_', '-', get_locale() ),
			'semana'    => (int) get_option( 'start_of_week', 1 ),
			'meses'     => lc_meses(),
			'whatsapp'  => $a['whatsapp'],
			'msjFechas' => $a['msj_fechas'],
			'msjGeneral'=> $a['msj_general'],
			'textos'    => array(
				'libre'     => $a['txt_libre'],
				'ocupado'   => $a['txt_ocupado'],
				'boton'     => $a['txt_boton'],
				'elegir'    => $a['txt_elegir'],
				'salida'    => 'Ahora tocá el día de salida.',
				'listo'     => 'Listo: tocá «' . $a['txt_boton'] . '» para enviar las fechas.',
				'llegada'   => 'Llegada',
				'salidaTxt' => 'Salida',
				'borrar'    => 'Borrar',
				'anterior'  => 'Mes anterior',
				'siguiente' => 'Mes siguiente',
				'cargando'  => 'Cargando disponibilidad…',
				'error'     => 'No se pudo cargar la disponibilidad. Consultanos por WhatsApp.',
				'verCal'    => 'Ver calendario',
				'nada'      => 'No hay calendarios para mostrar.',
				'cruza'     => 'Hay días ocupados en el medio: elegí otras fechas.',
			),
		)
	);
}

/**
 * HTML del calendario de una ficha.
 *
 * @param array $opts 'vista_previa' => bool (sin botón de WhatsApp).
 */
function lc_html_calendario( int $id, array $opts = array() ): string {
	lc_encolar();
	return sprintf(
		'<div class="lc-cal" data-recurso="%d" data-titulo="%s"%s><p class="lc-cargando">%s</p></div>',
		$id,
		esc_attr( html_entity_decode( get_the_title( $id ), ENT_QUOTES ) ),
		! empty( $opts['vista_previa'] ) ? ' data-vista-previa="1"' : '',
		esc_html__( 'Cargando disponibilidad…', 'liquen-calendarios' )
	);
}

function lc_sc_calendario( $atts ) {
	$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'liquen_calendario' );
	$id   = $atts['id'] ? absint( $atts['id'] ) : (int) get_the_ID();
	if ( ! $id || ! lc_es_recurso( $id ) ) {
		return current_user_can( 'edit_posts' ) ? '<p class="lc-aviso">Liquen Calendarios: esta ficha no tiene calendario (revisá el tipo habilitado y las fuentes).</p>' : '';
	}
	return lc_html_calendario( $id );
}

function lc_sc_disponibilidad( $atts ) {
	$atts = shortcode_atts( array( 'tipos' => '', 'ids' => '' ), $atts, 'liquen_disponibilidad' );
	$ids  = lc_recursos(
		array(
			'tipos' => array_filter( array_map( 'sanitize_key', explode( ',', $atts['tipos'] ) ) ),
			'ids'   => array_filter( array_map( 'absint', explode( ',', $atts['ids'] ) ) ),
		)
	);
	if ( ! $ids ) {
		return current_user_can( 'edit_posts' ) ? '<p class="lc-aviso">Liquen Calendarios: no hay fichas publicadas con calendario.</p>' : '';
	}
	lc_encolar();
	$lista = array();
	foreach ( $ids as $id ) {
		$lista[] = array(
			'id'     => $id,
			'titulo' => html_entity_decode( get_the_title( $id ), ENT_QUOTES ),
			'url'    => get_permalink( $id ),
		);
	}
	return sprintf(
		'<div class="lc-disp" data-recursos="%s"><p class="lc-cargando">%s</p></div>',
		esc_attr( wp_json_encode( $lista, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ),
		esc_html__( 'Cargando disponibilidad…', 'liquen-calendarios' )
	);
}
