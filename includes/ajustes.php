<?php
/**
 * Ajustes generales del plugin (opción lc_ajustes) y ayudas para leerlos.
 */

defined( 'ABSPATH' ) || exit;

function lc_ajustes_por_defecto() {
	return array(
		'tipos'           => array(),
		'meses'           => 6,
		'whatsapp'        => '',
		'msj_fechas'      => 'Hola, quería consultar por {recurso} del {desde} al {hasta}.',
		'msj_general'     => 'Hola, quería consultar la disponibilidad de {recurso}.',
		'txt_libre'       => 'Libre',
		'txt_ocupado'     => 'Ocupado',
		'txt_boton'       => 'Consultar por WhatsApp',
		'color_acento'    => '#2f6b4f',
		'color_ocupado'   => '#d9d4cc',
	);
}

// WordPress ya guarda las opciones en memoria: no hace falta otra caché (y así un cambio se ve enseguida).
function lc_ajustes() {
	return wp_parse_args( (array) get_option( 'lc_ajustes', array() ), lc_ajustes_por_defecto() );
}

function lc_ajuste( $clave ) {
	$a = lc_ajustes();
	return $a[ $clave ] ?? null;
}

// Tipos de contenido que pueden tener calendario.
function lc_tipos_habilitados() {
	return array_values( array_filter( (array) lc_ajuste( 'tipos' ), 'post_type_exists' ) );
}

function lc_meses() {
	return max( 1, min( 12, (int) lc_ajuste( 'meses' ) ) );
}

// Modos de recurso. En la 0.1 hay uno solo; el resto llega en versiones siguientes.
function lc_modos() {
	return array(
		'dias' => 'Días completos',
	);
}

function lc_zona() {
	return wp_timezone();
}

// Hoy en la zona del sitio (Y-m-d).
function lc_hoy() {
	return ( new DateTimeImmutable( 'now', lc_zona() ) )->format( 'Y-m-d' );
}
