<?php
/**
 * Sección «Disponibilidad» en Liquen Panel (necesita Liquen Panel 1.1.0 o más nuevo).
 *
 * Qué hace: muestra desde el celular qué días están ocupados en cada cabaña o salón,
 * si cada calendario se está leyendo bien, y permite pedir una lectura en el momento.
 * Qué no hace: no marca reservas ni bloquea fechas. Eso se hace en Booking (app Pulse en el
 * celular) o en Google Calendar, y la web lo toma en la siguiente lectura.
 *
 * Sin Liquen Panel no se carga nada de esto y el resto del plugin funciona igual.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'lp_registrar_modulos', function () {
	if ( ! function_exists( 'lp_registrar_modulo' ) || ! lc_tipos_habilitados() ) {
		return;
	}
	lp_registrar_modulo( 'disponibilidad', array(
		'titulo' => 'Disponibilidad',
		// Junto a los alojamientos (orden 2 en el estándar). Se cambia con el filtro lc_panel_orden.
		'orden'  => (int) apply_filters( 'lc_panel_orden', 2 ),
		'puede'  => 'edit_posts',
		'inicio' => 'lc_panel_inicio',
		'vista'  => 'lc_panel_vista',
		'post'   => 'lc_panel_post',
		'css'    => array( LC_URL . 'assets/panel.css?v=' . LC_VERSION ),
	) );
} );

// Recursos con calendario que el usuario puede ver (los que puede editar).
function lc_panel_recursos(): array {
	return array_values( array_filter( lc_recursos( array( 'estado' => array( 'publish', 'private', 'draft', 'future', 'pending' ) ) ), function ( $id ) {
		return current_user_can( 'edit_post', $id );
	} ) );
}

/**
 * Estado de un recurso en palabras: ['ok' => bool, 'texto' => '…'].
 * Mal si alguna fuente nunca se leyó o lleva más de 6 h sin leerse bien.
 */
function lc_panel_estado( int $id ): array {
	$estado  = lc_estado( $id );
	$ultimo  = 0;
	$errores = array();
	foreach ( lc_fuentes( $id ) as $f ) {
		$e  = $estado[ $f['id'] ] ?? array();
		$ok = (int) ( $e['ok'] ?? 0 );
		if ( ! $ok || $ok < time() - 6 * HOUR_IN_SECONDS ) {
			$errores[] = $f['nombre'] . ': ' . ( ! empty( $e['error'] ) ? $e['error'] : ( $ok ? 'no se lee bien desde ' . lc_hace( $ok ) : 'todavía no se leyó' ) );
		}
		$ultimo = $ultimo ? min( $ultimo, $ok ) : $ok;
	}
	if ( $errores ) {
		return array( 'ok' => false, 'texto' => implode( ' · ', $errores ) );
	}
	if ( ! lc_fuentes( $id ) ) {
		return array( 'ok' => true, 'texto' => 'Según las actividades de la web' );
	}
	return array( 'ok' => true, 'texto' => 'Al día · leído hace ' . human_time_diff( $ultimo ) );
}

/* ---------- Tarjeta en el inicio ---------- */

function lc_panel_inicio(): string {
	$ids  = lc_panel_recursos();
	$mal  = 0;
	foreach ( $ids as $id ) {
		$mal += lc_panel_estado( $id )['ok'] ? 0 : 1;
	}
	$res = count( $ids ) . ' ' . ( 1 === count( $ids ) ? 'calendario' : 'calendarios' ) . ( $mal ? ' · ' . $mal . ' con problemas' : ' · al día' );
	return '<a class="lp-btn lp-btn-sec" href="' . esc_url( lp_modulo_url( 'disponibilidad' ) ) . '">Ver disponibilidad <span class="lp-s">' . lp_e( $res ) . '</span></a>';
}

/* ---------- Pantallas ---------- */

function lc_panel_vista( string $v ) {
	if ( 'recurso' === $v ) {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security
		if ( $id && in_array( $id, lc_panel_recursos(), true ) ) {
			return array(
				'titulo' => get_the_title( $id ),
				'html'   => lc_panel_vista_recurso( $id ),
			);
		}
	}
	return array(
		'titulo' => 'Disponibilidad',
		'html'   => lc_panel_vista_lista(),
	);
}

// Recordatorio de cómo se cargan las reservas (qué hace y qué no hace esta sección).
function lc_panel_recordatorio(): string {
	return '<section class="lp-ayuda lc-p-ayuda">'
		. '<strong>Cómo se marcan las reservas</strong>'
		. '<p>Acá ves lo que está ocupado en Booking y en Google Calendar. <strong>Desde el panel no se marcan fechas.</strong></p>'
		. '<p>Cuando cerrás una reserva (por WhatsApp o por teléfono), cerrá esas fechas en <strong>Booking</strong>: en el celular, con la app <strong>Pulse</strong> de Booking. Las del salón, en Google Calendar.</p>'
		. '<p>La web lo toma sola en menos de media hora. Si no querés esperar, tocá «Actualizar ahora».</p>'
		. '</section>';
}

function lc_panel_vista_lista(): string {
	$ids = lc_panel_recursos();
	$h   = '<h1 class="lp-h1">Disponibilidad</h1>';
	if ( ! $ids ) {
		return $h . '<div class="lp-card">Todavía no hay cabañas ni salones con calendario. Se cargan en wp-admin, en la caja «Calendario» de cada ficha.</div>';
	}
	$h .= lc_panel_boton_actualizar( 0 );
	foreach ( $ids as $id ) {
		$est = lc_panel_estado( $id );
		$h  .= '<section class="lp-card lc-p-recurso">';
		$h  .= '<h2>' . lp_e( get_the_title( $id ) ) . '</h2>';
		$h  .= '<p class="lc-p-estado ' . ( $est['ok'] ? 'lc-p-ok' : 'lc-p-mal' ) . '">' . lp_e( $est['texto'] ) . '</p>';
		$h  .= lc_panel_mes( $id, lc_hoy() );
		$h  .= '<a class="lp-btn lp-btn-sec" href="' . esc_url( lp_modulo_url( 'disponibilidad', array( 'v' => 'recurso', 'id' => $id ) ) ) . '">Ver los próximos meses</a>';
		$h  .= '</section>';
	}
	return $h . lc_panel_agenda() . lc_panel_recordatorio();
}

// Calendario de actividades: enlace para Google Calendar y cómo funciona (solo si está prendido).
function lc_panel_agenda(): string {
	if ( ! lc_act_activa() ) {
		return '';
	}
	$h  = '<section class="lp-card lc-p-agenda">';
	$h .= '<h2>Calendario de actividades</h2>';
	$h .= '<p>Las actividades publicadas aparecen solas en el calendario de la web, en todos sus días.';
	if ( '' !== (string) lc_ajuste( 'act_ocultar' ) ) {
		$h .= ' Para que una no aparezca, marcá «No mostrar en el calendario» en la actividad.';
	}
	if ( lc_recursos_con_actividades() ) {
		$h .= ' Si marcás «Usa el salón», el salón figura ocupado esos días.';
	}
	$h .= '</p>';
	$h .= '<details class="lc-p-google"><summary><strong>Verlas en Google Calendar del celular</strong></summary>';
	$h .= '<p>Enlace secreto (no lo publiques):</p>';
	$h .= '<input type="text" readonly class="lc-p-enlace" value="' . esc_attr( lc_ical_url() ) . '" onclick="this.select()">';
	$h .= '<ol>';
	foreach ( lc_act_pasos_google() as $p ) {
		$h .= '<li>' . lp_e( $p ) . '</li>';
	}
	$h .= '</ol>';
	$h .= '<p class="lp-s">' . lp_e( lc_act_nota_demora() ) . '</p>';
	$h .= '</details>';
	return $h . '</section>';
}

function lc_panel_vista_recurso( int $id ): string {
	$est = lc_panel_estado( $id );
	$h   = '<p><a href="' . esc_url( lp_modulo_url( 'disponibilidad' ) ) . '">← Disponibilidad</a></p>';
	$h  .= '<h1 class="lp-h1">' . lp_e( get_the_title( $id ) ) . '</h1>';
	$h  .= '<p class="lc-p-estado ' . ( $est['ok'] ? 'lc-p-ok' : 'lc-p-mal' ) . '">' . lp_e( $est['texto'] ) . '</p>';
	$h  .= lc_panel_boton_actualizar( $id );

	// Fuentes de este recurso (sin las direcciones: son secretas).
	$estado = lc_estado( $id );
	$h     .= '<section class="lp-card lc-p-fuentes-card"><h2>Calendarios que se leen</h2><ul class="lc-p-fuentes">';
	foreach ( lc_fuentes( $id ) as $f ) {
		$e   = $estado[ $f['id'] ] ?? array();
		$txt = ! empty( $e['ok'] ) ? 'leído ' . lc_hace( (int) $e['ok'] ) . ' · ' . (int) ( $e['eventos'] ?? 0 ) . ' reservas o bloqueos' : 'todavía no se leyó';
		if ( ! empty( $e['error'] ) ) {
			$txt .= ' · problema: ' . $e['error'];
		}
		$h .= '<li><strong>' . lp_e( $f['nombre'] ) . '</strong> — ' . lp_e( $txt ) . '</li>';
	}
	$h .= '</ul></section>';

	$mes = new DateTimeImmutable( substr( lc_hoy(), 0, 7 ) . '-01', lc_zona() );
	for ( $i = 0; $i < lc_meses(); $i++ ) {
		$h .= '<section class="lp-card">' . lc_panel_mes( $id, $mes->modify( '+' . $i . ' month' )->format( 'Y-m-d' ) ) . '</section>';
	}
	return $h . lc_panel_recordatorio();
}

function lc_panel_boton_actualizar( int $id ): string {
	return '<form method="post" class="lc-p-actualizar">'
		. lp_modulo_campos( 'disponibilidad', 'leer' )
		. '<input type="hidden" name="lc_recurso" value="' . (int) $id . '">'
		. '<button type="submit" class="lp-btn lp-btn-sec">Actualizar ahora</button>'
		. '</form>';
}

// Un mes en cuadrícula (lunes a domingo) con los días ocupados tachados.
function lc_panel_mes( int $id, string $fecha ): string {
	$zona    = lc_zona();
	$primero = new DateTimeImmutable( substr( $fecha, 0, 7 ) . '-01', $zona );
	$sig     = $primero->modify( '+1 month' );
	$ocup    = array_flip( lc_dias_ocupados( $id, $primero->format( 'Y-m-d' ), $sig->format( 'Y-m-d' ) ) );
	$hoy     = lc_hoy();
	$meses   = array( 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'setiembre', 'octubre', 'noviembre', 'diciembre' );

	$h  = '<div class="lc-p-mes">';
	$h .= '<div class="lc-p-mes-titulo">' . lp_e( ucfirst( $meses[ (int) $primero->format( 'n' ) - 1 ] ) . ' ' . $primero->format( 'Y' ) ) . '</div>';
	$h .= '<div class="lc-p-grilla">';
	foreach ( array( 'L', 'M', 'M', 'J', 'V', 'S', 'D' ) as $d ) {
		$h .= '<span class="lc-p-dsem">' . $d . '</span>';
	}
	for ( $i = 1; $i < (int) $primero->format( 'N' ); $i++ ) {
		$h .= '<span></span>';
	}
	for ( $d = $primero; $d < $sig; $d = $d->modify( '+1 day' ) ) {
		$f   = $d->format( 'Y-m-d' );
		$cls = isset( $ocup[ $f ] ) ? 'lc-p-ocupado' : 'lc-p-libre';
		if ( $f < $hoy ) {
			$cls .= ' lc-p-pasado';
		}
		if ( $f === $hoy ) {
			$cls .= ' lc-p-hoy';
		}
		$h .= '<span class="lc-p-dia ' . $cls . '"' . ( isset( $ocup[ $f ] ) ? ' title="Ocupado"' : '' ) . '>' . (int) $d->format( 'j' ) . '</span>';
	}
	$h .= '</div>';
	$h .= '<div class="lc-p-leyenda"><span><i class="lc-p-muestra lc-p-libre"></i> Libre</span><span><i class="lc-p-muestra lc-p-ocupado"></i> Ocupado</span></div>';
	return $h . '</div>';
}

/* ---------- Envíos ---------- */

function lc_panel_post( string $accion ): void {
	if ( 'leer' !== $accion ) {
		return;
	}
	$id    = isset( $_POST['lc_recurso'] ) ? absint( $_POST['lc_recurso'] ) : 0; // phpcs:ignore WordPress.Security -- el nonce lo revisó Liquen Panel
	$ids   = lc_panel_recursos();
	$args  = $id ? array( 'v' => 'recurso', 'id' => $id ) : array();
	if ( $id && ! in_array( $id, $ids, true ) ) {
		lp_modulo_ir( 'disponibilidad', array(), 'No tenés permiso para hacer eso.', false );
	}

	// Una lectura por minuto alcanza: los calendarios de Booking y Google no cambian más rápido.
	$clave = 'lc_panel_leido_' . ( $id ? $id : 'todo' );
	if ( get_transient( $clave ) ) {
		lp_modulo_ir( 'disponibilidad', $args, 'Recién se actualizó. Probá de nuevo en un minuto.', true );
	}
	set_transient( $clave, 1, MINUTE_IN_SECONDS );

	foreach ( $id ? array( $id ) : $ids as $r ) {
		lc_sincronizar_recurso( $r );
	}
	$mal = 0;
	foreach ( $id ? array( $id ) : $ids as $r ) {
		$mal += lc_panel_estado( $r )['ok'] ? 0 : 1;
	}
	lp_modulo_ir(
		'disponibilidad',
		$args,
		$mal ? 'Se actualizó, pero hay calendarios con problemas (los ves en rojo).' : 'Actualizado ✓',
		! $mal
	);
}
