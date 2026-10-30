<?php
/**
 * Calendario de actividades (0.2.0).
 *
 * Las fechas salen de las actividades publicadas en la web (las que se cargan en Liquen Panel o en wp-admin):
 * no hay que armar ningún calendario aparte en Google.
 *
 * - Web: [liquen_agenda] muestra el calendario del mes con las actividades (las de varios días, en todos sus días).
 * - «No mostrar en el calendario» (casilla de la actividad): no aparece en la agenda de la web.
 * - «Usa el salón» (casilla de la actividad): el salón figura ocupado esos días, si en su caja «Calendario»
 *   está marcado «Sumar las actividades que usan este espacio».
 * - Enlace iCal secreto para suscribirse desde Google Calendar (se actualiza cuando Google lo vuelve a leer).
 *
 * Los nombres de los campos (fecha de inicio, fin, casillas) se eligen en Ajustes; vienen con los del estándar Liquen.
 */

defined( 'ABSPATH' ) || exit;

/* ------------------------------------------------------------------ */
/* Configuración y lectura de actividades                              */
/* ------------------------------------------------------------------ */

function lc_act_tipo(): string {
	$t = (string) lc_ajuste( 'act_tipo' );
	return ( '' !== $t && post_type_exists( $t ) ) ? $t : '';
}

function lc_act_activa(): bool {
	return '' !== lc_act_tipo();
}

// Fecha de ACF (Ymd) o Y-m-d → Y-m-d. Vacío si no es una fecha.
function lc_act_fecha( $v ): string {
	$v = trim( (string) $v );
	if ( preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $v, $m ) || preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $v, $m ) ) {
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? "$m[1]-$m[2]-$m[3]" : '';
	}
	return '';
}

// Casilla de ACF (verdadero/falso) marcada.
function lc_act_marcada( int $id, string $campo ): bool {
	return '' !== $campo && in_array( (string) get_post_meta( $id, $campo, true ), array( '1', 'true', 'yes', 'si', 'sí' ), true );
}

// Texto del tipo de actividad (Senderismo, Taller…), si hay campo para eso.
function lc_act_etiqueta( int $id ): string {
	$campo = (string) lc_ajuste( 'act_etiqueta' );
	if ( '' === $campo ) {
		return '';
	}
	$v = function_exists( 'get_field' ) ? get_field( $campo, $id ) : get_post_meta( $id, $campo, true );
	if ( is_array( $v ) ) {
		$v = $v['label'] ?? ( $v[0] ?? '' );
	}
	return is_scalar( $v ) ? (string) $v : '';
}

/**
 * Actividades publicadas que tocan el período [$desde, $hasta) (Y-m-d).
 *
 * @param array $args 'ocultas' => incluir las marcadas «No mostrar en el calendario»; 'salon' => solo las que usan el salón.
 * @return array [ ['id','titulo','url','tipo','inicio','fin','oculta','salon'], ... ] ordenadas por fecha.
 */
function lc_actividades( string $desde, string $hasta, array $args = array() ): array {
	$tipo = lc_act_tipo();
	$cini = (string) lc_ajuste( 'act_inicio' );
	if ( '' === $tipo || '' === $cini ) {
		return array();
	}
	$cfin = (string) lc_ajuste( 'act_fin' );
	$ids  = get_posts(
		array(
			'post_type'      => $tipo,
			'post_status'    => 'publish',
			'posts_per_page' => 500,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'key'     => $cini,
					'value'   => str_replace( '-', '', $hasta ),
					'compare' => '<',
				),
			),
		)
	);
	$out = array();
	foreach ( $ids as $id ) {
		$ini = lc_act_fecha( get_post_meta( $id, $cini, true ) );
		if ( '' === $ini ) {
			continue;
		}
		$fin = '' !== $cfin ? lc_act_fecha( get_post_meta( $id, $cfin, true ) ) : '';
		if ( '' === $fin || $fin < $ini ) {
			$fin = $ini;
		}
		if ( $fin < $desde || $ini >= $hasta ) {
			continue;
		}
		$oculta = lc_act_marcada( $id, (string) lc_ajuste( 'act_ocultar' ) );
		$salon  = lc_act_marcada( $id, (string) lc_ajuste( 'act_salon' ) );
		if ( ( $oculta && empty( $args['ocultas'] ) ) || ( ! $salon && ! empty( $args['salon'] ) ) ) {
			continue;
		}
		$out[] = array(
			'id'     => $id,
			'titulo' => html_entity_decode( get_the_title( $id ), ENT_QUOTES ),
			'url'    => get_permalink( $id ),
			'tipo'   => lc_act_etiqueta( $id ),
			'inicio' => $ini,
			'fin'    => $fin,
			'oculta' => $oculta,
			'salon'  => $salon,
		);
	}
	usort( $out, function ( $a, $b ) {
		return array( $a['inicio'], $a['titulo'] ) <=> array( $b['inicio'], $b['titulo'] );
	} );
	return $out;
}

/* ------------------------------------------------------------------ */
/* Salón ocupado por las actividades                                   */
/* ------------------------------------------------------------------ */

// ¿Este recurso suma como ocupados los días de las actividades que usan el salón?
function lc_suma_actividades( int $recurso_id ): bool {
	return lc_act_activa() && (bool) get_post_meta( $recurso_id, '_lc_actividades', true );
}

// Recursos que suman las actividades (cualquier estado).
function lc_recursos_con_actividades(): array {
	if ( ! lc_act_activa() || ! lc_tipos_habilitados() ) {
		return array();
	}
	return get_posts(
		array(
			'post_type'      => lc_tipos_habilitados(),
			'post_status'    => array( 'publish', 'private', 'draft', 'future', 'pending' ),
			'posts_per_page' => 50,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_key'       => '_lc_actividades', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);
}

// Guarda como ocupaciones del recurso (fuente «actividades») los días de las actividades que usan el salón.
function lc_sincronizar_actividades_recurso( int $recurso_id ): void {
	if ( ! lc_suma_actividades( $recurso_id ) ) {
		lc_guardar_ocupaciones( $recurso_id, 'actividades', array() );
		return;
	}
	$zona    = lc_zona();
	$desde   = ( new DateTimeImmutable( 'today', $zona ) )->modify( '-1 day' );
	$hasta   = $desde->modify( '+13 months' );
	$eventos = array();
	foreach ( lc_actividades( $desde->format( 'Y-m-d' ), $hasta->format( 'Y-m-d' ), array( 'ocultas' => true, 'salon' => true ) ) as $a ) {
		$eventos[] = array(
			'inicio'      => $a['inicio'] . ' 00:00:00',
			'fin'         => ( new DateTimeImmutable( $a['fin'], $zona ) )->modify( '+1 day' )->format( 'Y-m-d' ) . ' 00:00:00',
			'todo_el_dia' => true,
			'uid'         => 'actividad-' . $a['id'],
		);
	}
	lc_guardar_ocupaciones( $recurso_id, 'actividades', $eventos );
}

// Al guardar, publicar, borrar o cambiar las fechas de una actividad: el salón se actualiza al instante.
function lc_act_marcar_cambio( $post_id ): void {
	if ( ! lc_act_activa() || get_post_type( $post_id ) !== lc_act_tipo() ) {
		return;
	}
	if ( empty( $GLOBALS['lc_act_cambio'] ) ) {
		$GLOBALS['lc_act_cambio'] = true;
		add_action( 'shutdown', 'lc_act_aplicar_cambio' );
	}
}

function lc_act_aplicar_cambio(): void {
	foreach ( lc_recursos_con_actividades() as $r ) {
		lc_sincronizar_actividades_recurso( (int) $r );
	}
	lc_olvidar_cache();
}

add_action( 'save_post', 'lc_act_marcar_cambio', 99 );
add_action( 'trashed_post', 'lc_act_marcar_cambio' );
add_action( 'untrashed_post', 'lc_act_marcar_cambio' );
add_action( 'before_delete_post', 'lc_act_marcar_cambio' );
add_action( 'transition_post_status', function ( $nuevo, $viejo, $post ) {
	lc_act_marcar_cambio( $post->ID );
}, 10, 3 );
foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $lc_h ) {
	add_action( $lc_h, function ( $mid, $post_id, $clave ) {
		$campos = array_filter( array( lc_ajuste( 'act_inicio' ), lc_ajuste( 'act_fin' ), lc_ajuste( 'act_salon' ), lc_ajuste( 'act_ocultar' ) ) );
		if ( in_array( $clave, $campos, true ) ) {
			lc_act_marcar_cambio( (int) $post_id );
		}
	}, 10, 3 );
}
unset( $lc_h );

/* ------------------------------------------------------------------ */
/* API para la agenda de la web                                        */
/* ------------------------------------------------------------------ */

add_action( 'rest_api_init', function () {
	register_rest_route(
		'liquen-calendarios/v1',
		'/actividades',
		array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => 'lc_rest_actividades',
			'args'                => array(
				'desde' => array( 'sanitize_callback' => 'sanitize_text_field' ),
			),
		)
	);
} );

// Devuelve un mes de actividades (las que se muestran en la web), desde el mes actual hasta el tope de meses.
function lc_rest_actividades( WP_REST_Request $req ) {
	$zona  = lc_zona();
	$hoy   = lc_hoy();
	$este  = new DateTimeImmutable( substr( $hoy, 0, 7 ) . '-01', $zona );
	$tope  = $este->modify( '+' . lc_meses() . ' months' );
	$desde = (string) $req->get_param( 'desde' );
	$mes   = preg_match( '/^\d{4}-\d{2}/', $desde ) ? new DateTimeImmutable( substr( $desde, 0, 7 ) . '-01', $zona ) : $este;
	if ( $mes < $este ) {
		$mes = $este;
	}
	if ( $mes >= $tope ) {
		$mes = $tope->modify( '-1 month' );
	}
	$sig   = $mes->modify( '+1 month' );
	$clave = 'lc_agenda_' . md5( $mes->format( 'Y-m' ) . '|' . $hoy . '|' . get_option( 'lc_cache_version' ) );
	$datos = get_transient( $clave );
	if ( false === $datos ) {
		$lista = array();
		foreach ( lc_actividades( $mes->format( 'Y-m-d' ), $sig->format( 'Y-m-d' ) ) as $a ) {
			unset( $a['oculta'], $a['salon'] );
			$lista[] = $a;
		}
		$datos = array(
			'hoy'         => $hoy,
			'mes'         => $mes->format( 'Y-m' ),
			'anterior'    => $mes > $este ? $mes->modify( '-1 month' )->format( 'Y-m' ) : '',
			'siguiente'   => $sig < $tope ? $sig->format( 'Y-m' ) : '',
			'actividades' => $lista,
		);
		set_transient( $clave, $datos, 15 * MINUTE_IN_SECONDS );
	}
	$resp = rest_ensure_response( $datos );
	$resp->header( 'Cache-Control', 'public, max-age=300' );
	return $resp;
}

/* ------------------------------------------------------------------ */
/* Shortcode [liquen_agenda]                                           */
/* ------------------------------------------------------------------ */

add_action( 'init', function () {
	add_shortcode( 'liquen_agenda', 'lc_sc_agenda' );
} );

function lc_sc_agenda() {
	if ( ! lc_act_activa() ) {
		return current_user_can( 'edit_posts' ) ? '<p class="lc-aviso">Liquen Calendarios: el calendario de actividades está apagado (Ajustes → Liquen Calendarios → Ajustes → «Calendario de actividades»).</p>' : '';
	}
	lc_encolar();
	$a = lc_ajustes();
	wp_enqueue_script( 'liquen-agenda', LC_URL . 'assets/agenda.js', array(), LC_VERSION, true );
	wp_localize_script(
		'liquen-agenda',
		'lcAgenda',
		array(
			'api'    => esc_url_raw( rest_url( 'liquen-calendarios/v1/actividades' ) ),
			'idioma' => str_replace( '_', '-', get_locale() ),
			'semana' => (int) get_option( 'start_of_week', 1 ),
			'textos' => array(
				'nada'      => $a['txt_agenda_nada'],
				'anterior'  => 'Mes anterior',
				'siguiente' => 'Mes siguiente',
				'cargando'  => 'Cargando actividades…',
				'error'     => 'No se pudieron cargar las actividades.',
				'hasta'     => 'hasta el',
			),
		)
	);
	return '<div class="lc-agenda"><p class="lc-cargando">Cargando actividades…</p></div>';
}

/* ------------------------------------------------------------------ */
/* iCal de suscripción (Google Calendar del celular)                   */
/* ------------------------------------------------------------------ */

function lc_ical_clave( bool $nueva = false ): string {
	$c = (string) get_option( 'lc_ical_clave', '' );
	if ( '' === $c || $nueva ) {
		$c = strtolower( wp_generate_password( 24, false ) );
		update_option( 'lc_ical_clave', $c, false );
	}
	return $c;
}

function lc_ical_url(): string {
	return add_query_arg( 'lc_agenda_ical', lc_ical_clave(), home_url( '/' ) );
}

// Escapa un texto para iCal y corta las líneas largas (75 bytes).
function lc_ical_linea( string $clave, string $valor, bool $escapar = true ): string {
	if ( $escapar ) {
		$valor = str_replace( array( '\\', ';', ',', "\r\n", "\n" ), array( '\\\\', '\;', '\,', '\n', '\n' ), $valor );
	}
	$linea = $clave . ':' . $valor;
	$out   = '';
	while ( strlen( $linea ) > 75 ) {
		$corte = 75;
		// No cortar en medio de una letra con tilde.
		while ( $corte > 0 && ( ord( $linea[ $corte ] ) & 0xC0 ) === 0x80 ) {
			$corte--;
		}
		$out  .= substr( $linea, 0, $corte ) . "\r\n";
		$linea = ' ' . substr( $linea, $corte );
	}
	return $out . $linea . "\r\n";
}

function lc_ical_actividades(): string {
	$zona  = lc_zona();
	$hoy   = new DateTimeImmutable( 'today', $zona );
	$host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$ahora = gmdate( 'Ymd\THis\Z' );
	$h     = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Liquen Web//Liquen Calendarios " . LC_VERSION . "//ES\r\nCALSCALE:GREGORIAN\r\nMETHOD:PUBLISH\r\n";
	$h    .= lc_ical_linea( 'X-WR-CALNAME', 'Actividades · ' . html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
	$h    .= "X-PUBLISHED-TTL:PT1H\r\nREFRESH-INTERVAL;VALUE=DURATION:PT1H\r\n";
	$lista = lc_actividades( $hoy->modify( '-3 months' )->format( 'Y-m-d' ), $hoy->modify( '+13 months' )->format( 'Y-m-d' ), array( 'ocultas' => true ) );
	foreach ( $lista as $a ) {
		$fin    = ( new DateTimeImmutable( $a['fin'], $zona ) )->modify( '+1 day' );
		$titulo = $a['titulo'] . ( $a['tipo'] ? ' (' . $a['tipo'] . ')' : '' );
		$notas  = array();
		if ( $a['salon'] ) {
			$notas[] = 'Usa el salón.';
		}
		if ( $a['oculta'] ) {
			$notas[] = 'No se muestra en el calendario de la web.';
		}
		$notas[] = 'Se edita en el panel de la web, no en Google Calendar.';
		$h      .= "BEGIN:VEVENT\r\n";
		$h      .= 'UID:actividad-' . (int) $a['id'] . '@' . $host . "\r\n";
		$h      .= 'DTSTAMP:' . $ahora . "\r\n";
		$h      .= 'DTSTART;VALUE=DATE:' . str_replace( '-', '', $a['inicio'] ) . "\r\n";
		$h      .= 'DTEND;VALUE=DATE:' . $fin->format( 'Ymd' ) . "\r\n";
		$h      .= lc_ical_linea( 'SUMMARY', $titulo );
		$h      .= lc_ical_linea( 'DESCRIPTION', implode( "\n", $notas ) . "\n" . $a['url'] );
		$h      .= lc_ical_linea( 'URL', $a['url'], false );
		$h      .= "TRANSP:TRANSPARENT\r\nEND:VEVENT\r\n";
	}
	return $h . "END:VCALENDAR\r\n";
}

add_action( 'init', function () {
	if ( ! isset( $_GET['lc_agenda_ical'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$k = sanitize_key( wp_unslash( $_GET['lc_agenda_ical'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! lc_act_activa() || ! hash_equals( lc_ical_clave(), $k ) ) {
		status_header( 404 );
		exit;
	}
	nocache_headers();
	header( 'X-LiteSpeed-Cache-Control: no-cache' );
	header( 'Content-Type: text/calendar; charset=utf-8' );
	header( 'Content-Disposition: inline; filename="actividades.ics"' );
	echo lc_ical_actividades(); // phpcs:ignore WordPress.Security.EscapeOutput
	exit;
}, 1 );

/* ------------------------------------------------------------------ */
/* Textos compartidos (Ajustes y panel)                                */
/* ------------------------------------------------------------------ */

// Pasos para agregar el enlace en Google Calendar + aviso de demoras.
function lc_act_pasos_google(): array {
	return array(
		'Copiá el enlace (es secreto: no lo publiques).',
		'En una computadora, abrí Google Calendar (calendar.google.com). Desde el celular no se puede agregar.',
		'A la izquierda, junto a «Otros calendarios», tocá «+» → «Desde URL».',
		'Pegá el enlace y tocá «Agregar calendario». En unos minutos aparece en el celular, en la app de Google Calendar.',
	);
}

function lc_act_nota_demora(): string {
	return 'Google Calendar puede tardar varias horas (hasta un día) en mostrar los cambios: no se puede apurar. La que vale es la web: ante una diferencia, mirá el panel o la web. Las actividades se cargan solo en el panel; lo que se agregue a mano en ese calendario de Google no vuelve a la web.';
}

/* ------------------------------------------------------------------ */
/* Diagnóstico                                                         */
/* ------------------------------------------------------------------ */

// ¿Existe en ACF un campo con ese nombre para el tipo?
function lc_act_campo_existe( string $tipo, string $nombre ): ?bool {
	if ( ! function_exists( 'acf_get_field_groups' ) ) {
		return null;
	}
	foreach ( acf_get_field_groups( array( 'post_type' => $tipo ) ) as $g ) {
		foreach ( (array) acf_get_fields( $g ) as $f ) {
			if ( ( $f['name'] ?? '' ) === $nombre ) {
				return true;
			}
		}
	}
	return false;
}

/**
 * Puntos del diagnóstico del calendario de actividades.
 *
 * @param callable $add function( $grupo, $estado, $texto, $ayuda = null, $enlace = null ).
 */
function lc_act_diagnostico( callable $add, callable $buscar ): void {
	$G       = 'Calendario de actividades';
	$ajustes = admin_url( 'options-general.php?page=liquen-calendarios&tab=ajustes#lc-actividades' );
	if ( ! lc_act_activa() ) {
		$add( $G, 'ok', 'Apagado (opcional). Se prende en Ajustes → «Calendario de actividades».' );
		return;
	}
	$tipo = lc_act_tipo();
	$add( $G, 'ok', 'Lee las ' . mb_strtolower( get_post_type_object( $tipo )->labels->name ) . ' publicadas.' );

	$campos = array(
		'act_inicio'  => array( 'Fecha de inicio', 'mal' ),
		'act_fin'     => array( 'Fecha de fin', 'ojo' ),
		'act_salon'   => array( '«Usa el salón»', 'ojo' ),
		'act_ocultar' => array( '«No mostrar en el calendario»', 'ojo' ),
	);
	foreach ( $campos as $k => $c ) {
		$nombre = (string) lc_ajuste( $k );
		if ( '' === $nombre ) {
			continue;
		}
		$existe = lc_act_campo_existe( $tipo, $nombre );
		if ( false === $existe ) {
			$add( $G, $c[1], $c[0] . ': no hay un campo «' . $nombre . '» en ACF para este tipo. Crealo en el grupo de campos (o corregí el nombre en Ajustes).', null, $ajustes );
		}
	}

	$hoy   = lc_hoy();
	$hasta = ( new DateTimeImmutable( $hoy, lc_zona() ) )->modify( '+' . lc_meses() . ' months' )->format( 'Y-m-d' );
	$prox  = lc_actividades( $hoy, $hasta, array( 'ocultas' => true ) );
	$ocul  = count( array_filter( array_column( $prox, 'oculta' ) ) );
	$add( $G, $prox ? 'ok' : 'ojo', $prox ? count( $prox ) . ' actividades con fecha en los próximos ' . lc_meses() . ' meses' . ( $ocul ? ' (' . $ocul . ' sin mostrar en la web)' : '' ) . '.' : 'No hay actividades publicadas con fecha en los próximos ' . lc_meses() . ' meses.' );

	if ( $buscar( 'liquen_agenda' ) ) {
		$add( $G, 'ok', 'La agenda está puesta en la web ([liquen_agenda]).' );
	} else {
		$add( $G, 'ojo', 'No encontré [liquen_agenda] en ninguna página: el calendario de actividades no se ve en la web.', null, $ajustes );
	}

	if ( '' !== (string) lc_ajuste( 'act_salon' ) && ! lc_recursos_con_actividades() ) {
		$add( $G, 'ojo', 'Ninguna ficha suma las actividades que usan el salón: en la caja «Calendario» del salón, marcá «Sumar las actividades que usan este espacio».' );
	}
}
