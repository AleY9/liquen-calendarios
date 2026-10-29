<?php
/**
 * Asistente (preguntas → recomendación) y Diagnóstico (lista de chequeo automática).
 *
 * La idea: la persona del emprendimiento no tiene por qué saber qué es un iCal ni cómo piensan
 * Booking o Google. El asistente le pregunta cómo trabaja y le dice qué conectar; el diagnóstico
 * revisa lo que ya está configurado y le dice qué falta o qué está raro, con la solución al lado.
 */

defined( 'ABSPATH' ) || exit;

/* ------------------------------------------------------------------ */
/* Respuestas guardadas                                                */
/* ------------------------------------------------------------------ */

function lc_asistente(): array {
	$r = get_option( 'lc_asistente', array() );
	return is_array( $r ) ? $r : array();
}

function lc_asistente_respondido(): bool {
	return ! empty( lc_asistente()['salida'] );
}

/**
 * Preguntas. 'si' => condición para mostrarla (clave => valores; alcanza con que coincida uno).
 */
function lc_asistente_preguntas(): array {
	return array(
		'canales'      => array(
			'titulo'   => '¿Por dónde te llegan las reservas?',
			'ayuda'    => 'Marcá todas las que correspondan.',
			'multiple' => true,
			'opciones' => array(
				'booking'  => 'Booking',
				'airbnb'   => 'Airbnb',
				'otra'     => 'Otra plataforma (Expedia, Hostelworld, etc.)',
				'directo'  => 'Directo: WhatsApp, teléfono, correo, en persona',
			),
		),
		'anota'        => array(
			'titulo'   => 'Las reservas directas, ¿dónde las anotás?',
			'si'       => array( 'canales' => array( 'directo' ) ),
			'opciones' => array(
				'google'     => 'En Google Calendar',
				'plataforma' => 'Cierro esos días en Booking o Airbnb',
				'ambos'      => 'Las anoto en Google y además cierro esos días en Booking o Airbnb',
				'papel'      => 'En un cuaderno, una planilla o de memoria',
			),
		),
		'google'       => array(
			'titulo'   => 'En Google Calendar, ¿cómo están tus calendarios?',
			'ayuda'    => 'En calendar.google.com, mirá la lista de la izquierda.',
			'si'       => array( 'anota' => array( 'google', 'ambos' ) ),
			'opciones' => array(
				'uno_por_unidad' => 'Uno por cada cabaña (o salón), en «Mis calendarios»',
				'uno_para_todo'  => 'Un solo calendario para todo',
				'mixto'          => 'Algunos en «Mis calendarios» (ej. el salón) y otros en «Otros calendarios» (ej. cabañas que vienen de Booking)',
				'otros'          => 'Los de las cabañas están en «Otros calendarios»',
				'no_se'          => 'No sé',
			),
		),
		'sincro'       => array(
			'titulo'   => 'Booking y Airbnb, ¿se leen entre sí? (cada una importa el calendario de la otra)',
			'si'       => array( 'canales' => array( 'airbnb' ) ),
			'si_todas' => array( 'canales' => array( 'booking', 'airbnb' ) ),
			'opciones' => array(
				'si'    => 'Sí',
				'no'    => 'No',
				'no_se' => 'No sé',
			),
		),
		'salida'       => array(
			'titulo'   => 'El día que se va un huésped, ¿puede entrar otro ese mismo día?',
			'opciones' => array(
				'ocupa'      => 'No: ese día no se vuelve a alquilar (salen sin hora fija, o se limpia con calma)',
				'se_alquila' => 'Sí: hay hora de salida y de entrada (ej. sale 11 h, entra 14 h)',
			),
		),
		'horas'        => array(
			'titulo'   => '¿Alquilás algo por horas? (ej. salón para un evento de 18 a 23 h)',
			'opciones' => array(
				'no' => 'No, todo por días o noches',
				'si' => 'Sí',
			),
		),
	);
}

function lc_asistente_visible( array $p, array $r ): bool {
	foreach ( array( 'si' => 'alguna', 'si_todas' => 'todas' ) as $clave => $modo ) {
		foreach ( $p[ $clave ] ?? array() as $preg => $valores ) {
			$dado = (array) ( $r[ $preg ] ?? array() );
			$hay  = 'alguna' === $modo ? array_intersect( $valores, $dado ) : ! array_diff( $valores, $dado );
			if ( ! $hay ) {
				return false;
			}
		}
	}
	return true;
}

add_action( 'admin_post_lc_asistente', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Sin permiso.' );
	}
	check_admin_referer( 'lc_asistente' );
	$entrada = (array) wp_unslash( $_POST['lc_r'] ?? array() ); // phpcs:ignore
	$r       = array();
	foreach ( lc_asistente_preguntas() as $k => $p ) {
		if ( ! isset( $entrada[ $k ] ) ) {
			continue;
		}
		$v = array_map( 'sanitize_key', (array) $entrada[ $k ] );
		$v = array_values( array_intersect( $v, array_keys( $p['opciones'] ) ) );
		if ( $v ) {
			$r[ $k ] = ! empty( $p['multiple'] ) ? $v : $v[0];
		}
	}
	// Se borran respuestas de preguntas que ya no corresponden.
	foreach ( lc_asistente_preguntas() as $k => $p ) {
		if ( isset( $r[ $k ] ) && ! lc_asistente_visible( $p, $r ) ) {
			unset( $r[ $k ] );
		}
	}
	update_option( 'lc_asistente', $r, false );
	wp_safe_redirect( admin_url( 'options-general.php?page=liquen-calendarios&tab=asistente#lc-recomendacion' ) );
	exit;
} );

/* ------------------------------------------------------------------ */
/* Recomendación                                                       */
/* ------------------------------------------------------------------ */

/**
 * @return array{conectar: array, hacer: array, ojo: array, ok: array}
 *   conectar: [ ['fuente', 'como', 'casilla' => bool, 'ayuda' => clave], ... ]
 *   hacer / ojo / ok: [ ['texto', 'ayuda' => clave|null], ... ]
 */
function lc_recomendacion( array $r ): array {
	$canales = (array) ( $r['canales'] ?? array() );
	$anota   = $r['anota'] ?? '';
	$google  = $r['google'] ?? '';
	$ocupa   = 'ocupa' === ( $r['salida'] ?? 'ocupa' );
	$out     = array( 'conectar' => array(), 'hacer' => array(), 'ojo' => array(), 'ok' => array() );

	$usa_booking = in_array( 'booking', $canales, true );
	$usa_airbnb  = in_array( 'airbnb', $canales, true );
	$hay_plat    = $usa_booking || $usa_airbnb;

	if ( $usa_booking ) {
		$out['conectar'][] = array(
			'fuente'  => 'Booking',
			'como'    => 'Enlace de exportación de cada unidad (uno por cabaña).',
			'casilla' => $ocupa,
			'ayuda'   => 'booking',
		);
	}
	if ( $usa_airbnb ) {
		$out['conectar'][] = array(
			'fuente'  => 'Airbnb',
			'como'    => 'Enlace de exportación de cada alojamiento.',
			'casilla' => $ocupa,
			'ayuda'   => 'airbnb',
		);
	}
	if ( in_array( 'otra', $canales, true ) ) {
		$out['ojo'][] = array(
			'texto' => 'Otras plataformas: si en su panel hay «Exportar calendario» o «iCal», se conecta igual que Booking (con la misma casilla). Si no lo tienen, cerrá esas fechas también en Google o en Booking para que la web las vea.',
			'ayuda' => null,
		);
	}

	// Reservas directas.
	$google_conecta = false;
	if ( in_array( 'directo', $canales, true ) ) {
		switch ( $anota ) {
			case 'plataforma':
				$out['ok'][]  = array( 'texto' => 'Como cerrás las reservas directas en la plataforma, ya llegan a la web por el enlace de esa plataforma. No hace falta conectar nada más.', 'ayuda' => null );
				$out['ojo'][] = array( 'texto' => 'Al cerrar días a mano en Booking o Airbnb, cerrá solo las noches: si llegan el viernes y se van el domingo, cerrá viernes y sábado.' . ( $ocupa ? ' La web agrega sola el domingo.' : '' ), 'ayuda' => 'noches' );
				break;

			case 'ambos':
				$out['ok'][]  = array( 'texto' => 'Como además cerrás los días en la plataforma, con su enlace alcanza.', 'ayuda' => null );
				$out['ojo'][] = array( 'texto' => 'Al cerrar días a mano en Booking o Airbnb, cerrá solo las noches: si llegan el viernes y se van el domingo, cerrá viernes y sábado.' . ( $ocupa ? ' La web agrega sola el domingo.' : '' ), 'ayuda' => 'noches' );
				$google_conecta = 'respaldo';
				break;

			case 'google':
				$google_conecta = 'principal';
				if ( $hay_plat ) {
					$out['ojo'][] = array( 'texto' => 'Las reservas que anotás solo en Google no llegan a Booking ni a Airbnb: alguien podría reservar ahí esos mismos días. Cerralos también en la plataforma (o esperá la versión que publica un calendario propio para que las plataformas lo lean).', 'ayuda' => 'noches' );
				}
				break;

			case 'papel':
				if ( $hay_plat ) {
					$out['hacer'][] = array( 'texto' => 'Lo que está en un cuaderno o planilla la web no lo ve. La forma más simple: cerrar cada reserva directa en Booking o Airbnb (solo las noches).', 'ayuda' => 'noches' );
				} else {
					$out['hacer'][] = array( 'texto' => 'Lo que está en un cuaderno o planilla la web no lo ve. Pasá las reservas a Google Calendar, con un calendario por cabaña.', 'ayuda' => 'crear' );
					$google_conecta = 'principal';
				}
				break;

			default:
				$out['hacer'][] = array( 'texto' => 'Respondé dónde anotás las reservas directas.', 'ayuda' => null );
		}
	}

	if ( $google_conecta ) {
		switch ( $google ) {
			case 'uno_para_todo':
				$out['hacer'][] = array( 'texto' => 'Con un solo calendario para todo, la web no puede saber qué cabaña está ocupada. Creá un calendario de Google por cabaña (y uno para el salón) y anotá cada reserva en el suyo.', 'ayuda' => 'crear' );
				break;
			case 'otros':
				$out['hacer'][] = array(
					'texto' => $hay_plat
						? 'Los calendarios de «Otros calendarios» no son tuyos: casi siempre son una copia de Booking o Airbnb. No se conectan: ya se conecta la plataforma directo (y es más rápido que la copia de Google). Para las reservas directas, creá calendarios propios por cabaña o cerralas en la plataforma.'
						: 'Los calendarios de «Otros calendarios» no son tuyos (te los compartieron o son una copia). No tienen dirección secreta: pedísela a quien los creó, o creá calendarios propios por cabaña.',
					'ayuda' => 'mis_otros',
				);
				break;
			case 'mixto':
				$out['ok'][] = array( 'texto' => 'Cada unidad lleva su propia fuente: las que están en «Mis calendarios» (ej. el salón) se conectan con su dirección secreta de Google; las de «Otros calendarios» no se conectan: para esas unidades va el enlace de la plataforma (Booking, Airbnb).', 'ayuda' => 'mis_otros' );
				break;
			case 'no_se':
				$out['hacer'][] = array( 'texto' => 'Fijate en Google Calendar si los calendarios de las cabañas están en «Mis calendarios» o en «Otros calendarios», y volvé a responder.', 'ayuda' => 'mis_otros' );
				break;
		}
		// Se recomienda conectar Google salvo que sus calendarios no sean propios o no se sepa cómo están.
		if ( ! in_array( $google, array( 'otros', 'no_se' ), true ) ) {
			$out['conectar'][] = array(
				'fuente'  => 'mixto' === $google ? 'Google Calendar (solo las unidades que están en «Mis calendarios», ej. el salón)' : 'Google Calendar' . ( 'respaldo' === $google_conecta ? ' (opcional, como respaldo)' : '' ),
				'como'    => 'Dirección secreta en formato iCal de cada calendario (uno por cabaña).',
				'casilla' => false,
				'ayuda'   => 'google',
			);
			$out['ojo'][] = array(
				'texto' => $ocupa
					? 'En Google, cada estadía es un evento de día completo desde el día de llegada hasta el día de salida, los dos incluidos.'
					: 'En Google, cada estadía es un evento de día completo desde el día de llegada hasta la última noche (sin el día de salida, que se vuelve a alquilar).',
				'ayuda' => 'anotar',
			);
		}
	}

	if ( ! $out['conectar'] && ! $out['hacer'] ) {
		$out['hacer'][] = array( 'texto' => 'Con estas respuestas no hay ningún calendario que conectar. La web necesita al menos uno: Google Calendar por cabaña, o el enlace de Booking o Airbnb.', 'ayuda' => 'crear' );
	}

	// Booking + Airbnb.
	if ( $usa_booking && $usa_airbnb ) {
		$s = $r['sincro'] ?? '';
		if ( 'si' === $s ) {
			$out['ok'][] = array( 'texto' => 'Booking y Airbnb se leen entre sí: bien. En la web se conectan los dos en cada cabaña.', 'ayuda' => null );
		} else {
			$out['hacer'][] = array( 'texto' => 'Si Booking y Airbnb no se leen entre sí, alguien puede reservar en una lo que ya se reservó en la otra. Importá el calendario de Airbnb en Booking y el de Booking en Airbnb. (Esto es entre las plataformas; la web no lo hace.)', 'ayuda' => 'sincro' );
		}
	}

	// Día de salida.
	if ( $ocupa ) {
		if ( $hay_plat ) {
			$out['ok'][] = array( 'texto' => 'Booking y Airbnb piensan en noches y dejan libre el día de salida. Por eso en esas fuentes va marcada «Día de salida: También ocupa». En Google no se marca.', 'ayuda' => null );
		}
	} else {
		$out['ojo'][] = array( 'texto' => 'Por ahora la web muestra días enteros: el día de cambio figura libre para que entre otro. Los horarios de entrada y salida (y el late check-out) llegan en una versión próxima.', 'ayuda' => null );
	}

	if ( 'si' === ( $r['horas'] ?? '' ) ) {
		$out['ojo'][] = array( 'texto' => 'Lo que se alquila por horas (ej. el salón): por ahora una reserva con hora ocupa el día entero en la web. El modo por horas llega en una versión próxima.', 'ayuda' => null );
	}

	$out['ojo'][] = array( 'texto' => 'Las direcciones de calendario son secretas: pasalas solo a quien configura la web, nunca por redes ni grupos.', 'ayuda' => 'filtro' );

	return $out;
}

/* ------------------------------------------------------------------ */
/* Ayudas paso a paso                                                  */
/* ------------------------------------------------------------------ */

function lc_ayudas(): array {
	return array(
		'google'    => array(
			'titulo' => 'Cómo sacar la dirección secreta de Google Calendar',
			'pasos'  => array(
				'Desde la computadora (la app del celular no la muestra), entrá a calendar.google.com.',
				'A la izquierda, en «Mis calendarios», pasá el mouse sobre el calendario de la cabaña → ⋮ → «Configuración y uso compartido».',
				'Bajá hasta «Integrar el calendario».',
				'Copiá «Dirección secreta en formato iCal» (termina en basic.ics). No uses la «Dirección pública».',
			),
		),
		'booking'   => array(
			'titulo' => 'Cómo sacar el enlace de exportación de Booking',
			'pasos'  => array(
				'Entrá al Extranet de Booking.',
				'Tarifas y disponibilidad → Sincronizar calendarios.',
				'Elegí la cabaña: cada una tiene su enlace.',
				'«Exportar calendario» → copiá el enlace.',
			),
		),
		'airbnb'    => array(
			'titulo' => 'Cómo sacar el enlace de exportación de Airbnb',
			'pasos'  => array(
				'En Airbnb, entrá a Calendario y elegí el alojamiento.',
				'Disponibilidad → Conectar calendarios (o «Sincronizar calendarios»).',
				'«Exportar calendario» → copiá el enlace.',
			),
		),
		'crear'     => array(
			'titulo' => 'Cómo crear un calendario de Google por cabaña',
			'pasos'  => array(
				'En calendar.google.com, a la izquierda: «Otros calendarios» → + → «Crear un calendario».',
				'Nombre: el de la cabaña (ej. «Cabaña Ceibo») → «Crear calendario».',
				'Repetí por cada cabaña y para el salón.',
				'Desde ahora, cada reserva va en el calendario de su cabaña. Las reservas futuras que estén en otro calendario: abrí el evento → ⋮ → «Cambiar propietario» y elegí el nuevo.',
			),
		),
		'mis_otros' => array(
			'titulo' => '«Mis calendarios» u «Otros calendarios»: cómo saberlo',
			'pasos'  => array(
				'En calendar.google.com, mirá la columna de la izquierda: hay dos listas.',
				'«Mis calendarios»: los creaste vos. Tienen dirección secreta y se pueden conectar.',
				'«Otros calendarios»: te los compartieron o los agregaste desde una dirección (por ejemplo, el de Booking). No se pueden conectar desde tu cuenta.',
				'Para saber de dónde viene uno de «Otros»: ⋮ → «Configuración». Si muestra una dirección de booking.com o airbnb, es una copia de esa plataforma: conectá la plataforma directo.',
			),
		),
		'noches'    => array(
			'titulo' => 'Cerrar días a mano en Booking o Airbnb: solo las noches',
			'pasos'  => array(
				'Booking y Airbnb cuentan noches: llegan el viernes, se van el domingo = noches del viernes y del sábado.',
				'Al cerrar una reserva directa, cerrá solo esas noches (viernes y sábado).',
				'Si en la web la fuente tiene «Día de salida: También ocupa», el domingo se agrega solo.',
				'Si cerrás también el domingo, la web va a mostrar ocupado el lunes.',
			),
		),
		'anotar'    => array(
			'titulo' => 'Cómo anotar en Google para que la web lo entienda',
			'pasos'  => array(
				'Cada estadía: un evento de «Todo el día» desde el día de llegada hasta el día de salida (según tu respuesta sobre el día de salida).',
				'El título puede decir lo que quieras (nombre, teléfono): no se publica ni se guarda.',
				'Si se cancela, borrá el evento.',
				'Un evento con hora (ej. 18 a 23 h) ocupa ese día entero.',
			),
		),
		'sincro'    => array(
			'titulo' => 'Cómo hacer que Booking y Airbnb se lean entre sí',
			'pasos'  => array(
				'En Airbnb: Calendario → Disponibilidad → Conectar calendarios → «Exportar calendario»: copiá el enlace.',
				'En Booking: Tarifas y disponibilidad → Sincronizar calendarios → «Importar calendario»: pegá el enlace de Airbnb.',
				'Al revés: en Booking, «Exportar calendario»; en Airbnb, «Importar calendario».',
				'Las plataformas leen cada algunas horas: el riesgo de doble reserva baja mucho, pero no desaparece.',
			),
		),
		'filtro'    => array(
			'titulo' => 'Si una dirección se filtró',
			'pasos'  => array(
				'Google: Configuración del calendario → Integrar el calendario → «Restablecer» junto a la dirección secreta. Pegá la nueva en la ficha.',
				'Booking / Airbnb: generá un enlace de exportación nuevo, si la plataforma lo permite, y reemplazalo en la ficha.',
			),
		),
		'cron'      => array(
			'titulo' => 'Cómo agregar la tarea programada en cPanel',
			'pasos'  => array(
				'cPanel → «Tareas Cron» (Cron Jobs).',
				'Configuración común: «Dos veces por hora» (*/30).',
				'Comando: ' . lc_comando_cron(),
				'Si el hosting no tiene PHP en esa ruta, usá: wget -q -O - ' . site_url( 'wp-cron.php?doing_wp_cron' ) . ' >/dev/null 2>&1',
				'«Añadir nueva tarea cron».',
			),
		),
		'shortcode' => array(
			'titulo' => 'Cómo poner el calendario en la web (Bricks)',
			'pasos'  => array(
				'Bricks → Plantillas → la plantilla de la ficha de alojamiento → agregá un elemento «Shortcode» con [liquen_calendario].',
				'Para la página con todas las cabañas: creá una página «Disponibilidad» y agregá un elemento «Shortcode» con [liquen_disponibilidad].',
				'Guardá y mirá la ficha de una cabaña en el celular.',
			),
		),
	);
}

function lc_html_ayuda( ?string $clave ): string {
	if ( ! $clave ) {
		return '';
	}
	$a = lc_ayudas()[ $clave ] ?? null;
	if ( ! $a ) {
		return '';
	}
	$html = '<details class="lc-ayuda"><summary>' . esc_html( $a['titulo'] ) . '</summary><ol>';
	foreach ( $a['pasos'] as $p ) {
		$html .= '<li>' . esc_html( $p ) . '</li>';
	}
	return $html . '</ol></details>';
}

/* ------------------------------------------------------------------ */
/* Diagnóstico                                                         */
/* ------------------------------------------------------------------ */

// Qué es una fuente, por su dirección.
function lc_tipo_fuente( string $url ): string {
	$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	if ( false !== strpos( $host, 'google.com' ) ) {
		return 'google';
	}
	if ( false !== strpos( $host, 'booking.com' ) ) {
		return 'booking';
	}
	if ( false !== strpos( $host, 'airbnb.' ) ) {
		return 'airbnb';
	}
	return 'otra';
}

/**
 * Lista de chequeo.
 *
 * @return array [ ['grupo', 'estado' => ok|ojo|mal, 'texto', 'ayuda' => clave|null, 'enlace' => url|null], ... ]
 */
function lc_diagnostico(): array {
	global $wpdb;
	$items = array();
	$add   = function ( $grupo, $estado, $texto, $ayuda = null, $enlace = null ) use ( &$items ) {
		$recurso = 0;
		$items[] = compact( 'grupo', 'estado', 'texto', 'ayuda', 'enlace', 'recurso' );
	};
	$G       = 'General';
	$ajustes = admin_url( 'options-general.php?page=liquen-calendarios' );
	$resp    = lc_asistente();
	$ocupa   = 'se_alquila' !== ( $resp['salida'] ?? 'ocupa' );

	// Asistente.
	if ( lc_asistente_respondido() ) {
		$add( $G, 'ok', 'Asistente respondido.' );
	} else {
		$add( $G, 'ojo', 'Todavía no respondiste el asistente: son 6 preguntas y te dice qué conectar.', null, $ajustes . '&tab=asistente' );
	}

	// Tipos.
	$tipos = lc_tipos_habilitados();
	if ( $tipos ) {
		$add( $G, 'ok', 'Tipos con calendario: ' . implode( ', ', array_map( function ( $t ) {
			return get_post_type_object( $t )->labels->name;
		}, $tipos ) ) . '.' );
	} else {
		$add( $G, 'mal', 'No hay ningún tipo de contenido habilitado: la caja «Calendario» no aparece en ninguna ficha.', null, $ajustes . '&tab=ajustes' );
	}

	// WhatsApp.
	if ( lc_ajuste( 'whatsapp' ) ) {
		$add( $G, 'ok', 'WhatsApp configurado: +' . lc_ajuste( 'whatsapp' ) . '.' );
	} else {
		$add( $G, 'ojo', 'Falta el número de WhatsApp: la web muestra la disponibilidad pero sin el botón para consultar.', null, $ajustes . '&tab=ajustes' );
	}

	// Lectura automática.
	$ronda = (int) get_option( 'lc_ultima_ronda' );
	if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON && ( ! $ronda || time() - $ronda > HOUR_IN_SECONDS ) ) {
		$add( $G, 'mal', 'Las tareas programadas de WordPress están desactivadas en este sitio y no hay una del cPanel corriendo: los calendarios no se actualizan solos.', 'cron' );
	} elseif ( ! $ronda ) {
		$add( $G, 'ojo', 'Los calendarios todavía no se leyeron solos. Si en una hora sigue igual, agregá la tarea programada del cPanel.', 'cron' );
	} elseif ( time() - $ronda > 2 * HOUR_IN_SECONDS ) {
		$add( $G, 'ojo', 'La última lectura automática fue ' . lc_hace( $ronda ) . '. Pasa cuando el sitio tiene pocas visitas: agregá la tarea programada del cPanel.', 'cron' );
	} else {
		$add( $G, 'ok', 'Lectura automática funcionando (última ' . lc_hace( $ronda ) . ').' );
	}

	// Shortcodes en la web (en el contenido o en los datos de Bricks).
	$buscar = function ( $sc ) use ( $wpdb ) {
		$like = '%' . $wpdb->esc_like( '[' . $sc ) . '%';
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM $wpdb->posts WHERE post_status IN ('publish','private') AND post_content LIKE %s LIMIT 1", $like ) )
			|| (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM $wpdb->postmeta WHERE meta_key LIKE %s AND meta_value LIKE %s LIMIT 1", '_bricks_%', $like ) );
	};
	if ( $buscar( 'liquen_calendario' ) ) {
		$add( $G, 'ok', 'El calendario está puesto en la web ([liquen_calendario]).' );
	} else {
		$add( $G, 'ojo', 'No encontré [liquen_calendario] en ninguna página ni plantilla: las fichas no muestran el calendario.', 'shortcode' );
	}
	if ( $buscar( 'liquen_disponibilidad' ) ) {
		$add( $G, 'ok', 'Hay una página general de disponibilidad ([liquen_disponibilidad]).' );
	} else {
		$add( $G, 'ojo', 'No hay página general con todas las cabañas (opcional): [liquen_disponibilidad].', 'shortcode' );
	}

	if ( ! $tipos ) {
		return $items;
	}

	// Fichas sin calendario.
	$todas = get_posts( array( 'post_type' => $tipos, 'post_status' => 'publish', 'posts_per_page' => 200, 'fields' => 'ids', 'no_found_rows' => true ) );
	$con   = lc_recursos( array( 'estado' => 'any' ) );
	$sin   = array_diff( $todas, $con );
	if ( ! $con ) {
		$add( $G, 'mal', 'Ninguna ficha tiene calendario todavía. Abrí una cabaña y cargá su dirección en la caja «Calendario».', 'google' );
	}
	if ( $sin ) {
		$add( $G, 'ojo', 'Fichas publicadas sin calendario: ' . implode( ', ', array_map( 'get_the_title', array_slice( $sin, 0, 10 ) ) ) . ( count( $sin ) > 10 ? '…' : '' ) . '. Si no se alquilan, ignoralo.' );
	}

	// Direcciones repetidas entre fichas.
	$por_url = array();
	foreach ( $con as $id ) {
		foreach ( lc_fuentes( $id ) as $f ) {
			$por_url[ $f['url'] ][] = $id;
		}
	}

	// Cada ficha y cada fuente.
	foreach ( $con as $id ) {
		$desde_item = count( $items );
		$grupo  = get_the_title( $id );
		$edit   = get_edit_post_link( $id, 'url' );
		$estado = lc_estado( $id );
		$tipos_f = array();

		foreach ( lc_fuentes( $id ) as $f ) {
			$e      = $estado[ $f['id'] ] ?? array();
			$tipo   = lc_tipo_fuente( $f['url'] );
			$tipos_f[] = $tipo;
			$nombre = '«' . $f['nombre'] . '»';

			// Lectura.
			if ( ! empty( $e['error'] ) && empty( $e['ok'] ) ) {
				$add( $grupo, 'mal', $nombre . ': no se pudo leer nunca. ' . $e['error'], $tipo, $edit );
			} elseif ( ! empty( $e['error'] ) ) {
				$add( $grupo, 'mal', $nombre . ': la última lectura falló (' . $e['error'] . '). La web muestra los datos de ' . lc_hace( (int) $e['ok'] ) . '.', $tipo, $edit );
			} elseif ( empty( $e['ok'] ) ) {
				$add( $grupo, 'ojo', $nombre . ': todavía no se leyó. Tocá «Actualizar ahora» en la ficha.', null, $edit );
			} else {
				$n = (int) ( $e['eventos'] ?? 0 );
				$add( $grupo, 'ok', $nombre . ': leído ' . lc_hace( (int) $e['ok'] ) . ', ' . $n . ( 1 === $n ? ' reserva o bloqueo.' : ' reservas o bloqueos.' ) );
				if ( 0 === (int) ( $e['eventos'] ?? 0 ) ) {
					$add( $grupo, 'ojo', $nombre . ': no tiene ninguna reserva en los próximos meses. Si eso no es así, revisá que la dirección sea la de esta cabaña.', $tipo, $edit );
				}
			}
			foreach ( (array) ( $e['avisos'] ?? array() ) as $av ) {
				$add( $grupo, 'ojo', $nombre . ': ' . $av );
			}

			// Dirección.
			if ( 'google' === $tipo && false !== strpos( $f['url'], '/public/' ) ) {
				$add( $grupo, 'mal', $nombre . ': es la dirección pública de Google. Solo funciona si el calendario es público (y no conviene). Usá la «Dirección secreta en formato iCal».', 'google', $edit );
			}
			if ( count( $por_url[ $f['url'] ] ?? array() ) > 1 ) {
				$otras = array_diff( $por_url[ $f['url'] ], array( $id ) );
				$add( $grupo, 'mal', $nombre . ': es la misma dirección que en ' . implode( ', ', array_map( 'get_the_title', $otras ) ) . '. Cada cabaña necesita su propio calendario o enlace.', 'crear', $edit );
			}

			// Casilla del día de salida.
			if ( 'google' === $tipo && $f['salida_ocupa'] ) {
				$add( $grupo, 'ojo', $nombre . ': tiene marcada «Día de salida: También ocupa». En Google normalmente no va: el evento ya incluye el día de salida y se sumaría un día de más.', 'anotar', $edit );
			}
			if ( in_array( $tipo, array( 'booking', 'airbnb' ), true ) && $ocupa && ! $f['salida_ocupa'] ) {
				$add( $grupo, 'mal', $nombre . ': falta marcar «Día de salida: También ocupa». ' . ucfirst( $tipo ) . ' deja libre el día que se van y, según el asistente, ese día no se vuelve a alquilar.', 'noches', $edit );
			}
			if ( in_array( $tipo, array( 'booking', 'airbnb' ), true ) && ! $ocupa && $f['salida_ocupa'] ) {
				$add( $grupo, 'ojo', $nombre . ': tiene marcada «Día de salida: También ocupa», pero según el asistente ese día se vuelve a alquilar.', null, $edit );
			}
		}

		if ( in_array( 'booking', $tipos_f, true ) && in_array( 'airbnb', $tipos_f, true ) && 'si' !== ( $resp['sincro'] ?? '' ) ) {
			$add( $grupo, 'ojo', 'Tiene Booking y Airbnb: asegurate de que se lean entre sí, para que no se reserve en una lo ocupado en la otra.', 'sincro' );
		}
		// Se marca a qué ficha pertenece cada punto (para mostrarlos en su caja).
		for ( $i = $desde_item; $i < count( $items ); $i++ ) {
			$items[ $i ]['recurso'] = $id;
		}
	}

	return $items;
}

/* ------------------------------------------------------------------ */
/* Pantallas                                                           */
/* ------------------------------------------------------------------ */

function lc_estilos_admin() {
	?>
	<style>
		.lc-preg { background:#fff; border:1px solid #dcdcde; border-radius:6px; padding:12px 16px; margin:0 0 12px; max-width:760px; }
		.lc-preg h3 { margin:0 0 4px; font-size:14px; }
		.lc-preg .description { margin:0 0 8px; }
		.lc-preg label { display:block; margin:4px 0; }
		.lc-preg[hidden] { display:none; }
		.lc-reco { max-width:900px; }
		.lc-reco h3 { margin-top:20px; }
		.lc-fuente-reco { background:#fff; border-left:4px solid #2271b1; padding:10px 14px; margin:8px 0; }
		.lc-item { display:flex; gap:10px; align-items:flex-start; background:#fff; border:1px solid #dcdcde; border-radius:6px; padding:10px 14px; margin:6px 0; }
		.lc-item .lc-ico { font-weight:700; width:22px; text-align:center; flex:none; }
		.lc-item.lc-ok .lc-ico { color:#00a32a; }
		.lc-item.lc-ojo .lc-ico { color:#dba617; }
		.lc-item.lc-mal .lc-ico, .lc-item.lc-hacer .lc-ico { color:#d63638; }
		.lc-item p { margin:0; }
		.lc-ayuda { margin-top:6px; }
		.lc-ayuda summary { cursor:pointer; color:#2271b1; }
		.lc-ayuda ol { margin:6px 0 0 20px; }
		.lc-resumen { display:flex; gap:16px; margin:12px 0; font-size:14px; }
		.lc-resumen span { background:#fff; border:1px solid #dcdcde; border-radius:999px; padding:4px 12px; }
		.lc-grupo { margin-top:18px; }
	</style>
	<?php
}

function lc_tab_asistente() {
	$r = lc_asistente();
	lc_estilos_admin();
	?>
	<p>Contestá cómo trabajás hoy. Con eso te decimos qué calendario conectar en cada cabaña y si conviene cambiar algo. Se puede volver a responder cuando quieras.</p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="lc-asistente">
		<input type="hidden" name="action" value="lc_asistente">
		<?php wp_nonce_field( 'lc_asistente' ); ?>
		<?php foreach ( lc_asistente_preguntas() as $k => $p ) : ?>
			<div class="lc-preg" data-preg="<?php echo esc_attr( $k ); ?>" data-si="<?php echo esc_attr( wp_json_encode( $p['si'] ?? new stdClass() ) ); ?>" data-si-todas="<?php echo esc_attr( wp_json_encode( $p['si_todas'] ?? new stdClass() ) ); ?>" <?php echo lc_asistente_visible( $p, $r ) ? '' : 'hidden'; ?>>
				<h3><?php echo esc_html( $p['titulo'] ); ?></h3>
				<?php if ( ! empty( $p['ayuda'] ) ) : ?><p class="description"><?php echo esc_html( $p['ayuda'] ); ?></p><?php endif; ?>
				<?php foreach ( $p['opciones'] as $v => $txt ) : ?>
					<?php
					$multi   = ! empty( $p['multiple'] );
					$marcado = $multi ? in_array( $v, (array) ( $r[ $k ] ?? array() ), true ) : ( ( $r[ $k ] ?? '' ) === $v );
					?>
					<label>
						<input type="<?php echo $multi ? 'checkbox' : 'radio'; ?>" name="lc_r[<?php echo esc_attr( $k ); ?>]<?php echo $multi ? '[]' : ''; ?>" value="<?php echo esc_attr( $v ); ?>" <?php checked( $marcado ); ?>>
						<?php echo esc_html( $txt ); ?>
					</label>
				<?php endforeach; ?>
			</div>
		<?php endforeach; ?>
		<?php submit_button( lc_asistente_respondido() ? 'Actualizar la recomendación' : 'Ver la recomendación' ); ?>
	</form>
	<script>
	( function () {
		var form = document.getElementById( 'lc-asistente' );
		function valores( preg ) {
			return Array.prototype.map.call( form.querySelectorAll( '[name^="lc_r[' + preg + ']"]:checked' ), function ( i ) { return i.value; } );
		}
		function actualizar() {
			form.querySelectorAll( '.lc-preg' ).forEach( function ( caja ) {
				var si = JSON.parse( caja.getAttribute( 'data-si' ) || '{}' );
				var todas = JSON.parse( caja.getAttribute( 'data-si-todas' ) || '{}' );
				var ver = true;
				Object.keys( si ).forEach( function ( p ) {
					var dado = valores( p );
					if ( ! si[ p ].some( function ( v ) { return dado.indexOf( v ) > -1; } ) || form.querySelector( '[data-preg="' + p + '"]' ).hidden ) { ver = false; }
				} );
				Object.keys( todas ).forEach( function ( p ) {
					var dado = valores( p );
					if ( ! todas[ p ].every( function ( v ) { return dado.indexOf( v ) > -1; } ) ) { ver = false; }
				} );
				caja.hidden = ! ver;
			} );
		}
		form.addEventListener( 'change', function () { actualizar(); actualizar(); } );
	} )();
	</script>

	<?php
	if ( ! lc_asistente_respondido() ) {
		return;
	}
	$reco = lc_recomendacion( $r );
	?>
	<div class="lc-reco" id="lc-recomendacion">
		<h2>Tu recomendación</h2>

		<?php if ( $reco['conectar'] ) : ?>
			<h3>Qué conectar en la caja «Calendario» de cada cabaña</h3>
			<p class="description">Las fuentes se cargan por unidad: cada cabaña o salón lleva solo las que le corresponden (ej. las cabañas el enlace de Booking y el salón su calendario de Google).</p>
			<?php foreach ( $reco['conectar'] as $c ) : ?>
				<div class="lc-fuente-reco">
					<strong><?php echo esc_html( $c['fuente'] ); ?></strong> — <?php echo esc_html( $c['como'] ); ?><br>
					«Día de salida: También ocupa»: <strong><?php echo $c['casilla'] ? 'marcada' : 'sin marcar'; ?></strong>
					<?php echo lc_html_ayuda( $c['ayuda'] ); // phpcs:ignore ?>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>

		<?php
		$bloques = array(
			'hacer' => array( 'Para hacer', '!' ),
			'ojo'   => array( 'A tener en cuenta', 'i' ),
			'ok'    => array( 'Está bien así', '✓' ),
		);
		foreach ( $bloques as $k => $b ) :
			if ( ! $reco[ $k ] ) {
				continue;
			}
			?>
			<h3><?php echo esc_html( $b[0] ); ?></h3>
			<?php foreach ( $reco[ $k ] as $it ) : ?>
				<div class="lc-item lc-<?php echo esc_attr( $k ); ?>">
					<span class="lc-ico"><?php echo esc_html( $b[1] ); ?></span>
					<div><p><?php echo esc_html( $it['texto'] ); ?></p><?php echo lc_html_ayuda( $it['ayuda'] ); // phpcs:ignore ?></div>
				</div>
			<?php endforeach; ?>
		<?php endforeach; ?>

		<p style="margin-top:16px">
			Cuando lo tengas cargado, mirá la pestaña
			<a href="<?php echo esc_url( admin_url( 'options-general.php?page=liquen-calendarios&tab=diagnostico' ) ); ?>">Diagnóstico</a>:
			revisa que todo esté bien.
		</p>
	</div>
	<?php
}

function lc_tab_diagnostico() {
	lc_estilos_admin();
	$items = lc_diagnostico();
	$cuenta = array_count_values( array_column( $items, 'estado' ) );
	$iconos = array( 'ok' => '✓', 'ojo' => '!', 'mal' => '✕' );
	?>
	<div class="lc-resumen">
		<span style="color:#00a32a">✓ <?php echo (int) ( $cuenta['ok'] ?? 0 ); ?> bien</span>
		<span style="color:#996800">! <?php echo (int) ( $cuenta['ojo'] ?? 0 ); ?> para revisar</span>
		<span style="color:#d63638">✕ <?php echo (int) ( $cuenta['mal'] ?? 0 ); ?> para arreglar</span>
	</div>
	<?php
	$grupos = array();
	foreach ( $items as $it ) {
		$grupos[ $it['grupo'] ][] = $it;
	}
	foreach ( $grupos as $g => $lista ) :
		// Primero lo que hay que arreglar.
		usort( $lista, function ( $a, $b ) {
			$o = array( 'mal' => 0, 'ojo' => 1, 'ok' => 2 );
			return $o[ $a['estado'] ] <=> $o[ $b['estado'] ];
		} );
		?>
		<div class="lc-grupo">
			<h3><?php echo esc_html( $g ); ?></h3>
			<?php foreach ( $lista as $it ) : ?>
				<div class="lc-item lc-<?php echo esc_attr( $it['estado'] ); ?>">
					<span class="lc-ico"><?php echo esc_html( $iconos[ $it['estado'] ] ); ?></span>
					<div>
						<p>
							<?php echo esc_html( $it['texto'] ); ?>
							<?php if ( $it['enlace'] && 'ok' !== $it['estado'] ) : ?>
								<a href="<?php echo esc_url( $it['enlace'] ); ?>">Ir →</a>
							<?php endif; ?>
						</p>
						<?php echo 'ok' !== $it['estado'] ? lc_html_ayuda( $it['ayuda'] ) : ''; // phpcs:ignore ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endforeach; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:20px">
		<input type="hidden" name="action" value="lc_leer_todo">
		<?php wp_nonce_field( 'lc_leer_todo' ); ?>
		<?php submit_button( 'Leer todos los calendarios ahora', 'secondary', 'submit', false ); ?>
	</form>

	<h2 style="margin-top:28px">Detalle de las fuentes</h2>
	<?php
	lc_tabla_estado();
}
