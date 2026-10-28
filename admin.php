<?php
/**
 * wp-admin: página de ajustes (con el estado de todos los calendarios),
 * caja «Calendario» en cada ficha y aviso si una fuente deja de leerse.
 */

defined( 'ABSPATH' ) || exit;

/* ------------------------------------------------------------------ */
/* Página de ajustes                                                   */
/* ------------------------------------------------------------------ */

add_action( 'admin_menu', function () {
	add_options_page( 'Liquen Calendarios', 'Liquen Calendarios', 'manage_options', 'liquen-calendarios', 'lc_pantalla_ajustes' );
} );

add_filter( 'plugin_action_links_' . plugin_basename( LC_FILE ), function ( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=liquen-calendarios' ) ) . '">Ajustes</a>' );
	return $links;
} );

add_action( 'admin_init', function () {
	register_setting(
		'lc_ajustes',
		'lc_ajustes',
		array(
			'type'              => 'array',
			'sanitize_callback' => 'lc_limpiar_ajustes',
			'default'           => lc_ajustes_por_defecto(),
		)
	);
} );

function lc_limpiar_ajustes( $in ) {
	$def = lc_ajustes_por_defecto();
	$in  = is_array( $in ) ? $in : array();
	$out = array();

	$out['tipos']    = array_values( array_filter( array_map( 'sanitize_key', (array) ( $in['tipos'] ?? array() ) ), 'post_type_exists' ) );
	$out['meses']    = max( 1, min( 12, (int) ( $in['meses'] ?? $def['meses'] ) ) );
	$out['whatsapp'] = preg_replace( '/\D+/', '', (string) ( $in['whatsapp'] ?? '' ) );
	foreach ( array( 'msj_fechas', 'msj_general', 'txt_libre', 'txt_ocupado', 'txt_boton' ) as $k ) {
		$v         = sanitize_text_field( (string) ( $in[ $k ] ?? '' ) );
		$out[ $k ] = '' !== $v ? $v : $def[ $k ];
	}
	foreach ( array( 'color_acento', 'color_ocupado' ) as $k ) {
		$out[ $k ] = sanitize_hex_color( (string) ( $in[ $k ] ?? '' ) ) ?: $def[ $k ];
	}
	lc_olvidar_cache();
	return $out;
}

function lc_pantalla_ajustes() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$a     = lc_ajustes();
	$tipos = get_post_types( array( 'public' => true ), 'objects' );
	unset( $tipos['attachment'] );
	?>
	<div class="wrap">
		<h1>Liquen Calendarios</h1>

		<?php if ( isset( $_GET['lc_leido'] ) ) : // phpcs:ignore ?>
			<div class="notice notice-success is-dismissible"><p>Calendarios leídos. Revisá el estado abajo.</p></div>
		<?php endif; ?>

		<form method="post" action="options.php">
			<?php settings_fields( 'lc_ajustes' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Tipos con calendario</th>
					<td>
						<?php foreach ( $tipos as $t ) : ?>
							<label style="display:block;margin-bottom:4px">
								<input type="checkbox" name="lc_ajustes[tipos][]" value="<?php echo esc_attr( $t->name ); ?>" <?php checked( in_array( $t->name, $a['tipos'], true ) ); ?>>
								<?php echo esc_html( $t->labels->name ); ?> <code><?php echo esc_html( $t->name ); ?></code>
							</label>
						<?php endforeach; ?>
						<p class="description">En estos tipos aparece la caja «Calendario» para cargar las direcciones iCal.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="lc-meses">Meses a mostrar</label></th>
					<td><input id="lc-meses" type="number" min="1" max="12" name="lc_ajustes[meses]" value="<?php echo esc_attr( $a['meses'] ); ?>" class="small-text"></td>
				</tr>
				<tr>
					<th scope="row"><label for="lc-wa">WhatsApp</label></th>
					<td>
						<input id="lc-wa" type="text" name="lc_ajustes[whatsapp]" value="<?php echo esc_attr( $a['whatsapp'] ); ?>" class="regular-text" placeholder="598 99 123 456">
						<p class="description">Con código de país, sin el 0 inicial. Si queda vacío, no se muestra el botón.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="lc-mf">Mensaje con fechas</label></th>
					<td>
						<input id="lc-mf" type="text" name="lc_ajustes[msj_fechas]" value="<?php echo esc_attr( $a['msj_fechas'] ); ?>" class="large-text">
						<p class="description">Se puede usar {recurso}, {desde} y {hasta}.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="lc-mg">Mensaje sin fechas</label></th>
					<td><input id="lc-mg" type="text" name="lc_ajustes[msj_general]" value="<?php echo esc_attr( $a['msj_general'] ); ?>" class="large-text"></td>
				</tr>
				<tr>
					<th scope="row">Textos</th>
					<td>
						<label>Libre <input type="text" name="lc_ajustes[txt_libre]" value="<?php echo esc_attr( $a['txt_libre'] ); ?>"></label>
						<label style="margin-left:12px">Ocupado <input type="text" name="lc_ajustes[txt_ocupado]" value="<?php echo esc_attr( $a['txt_ocupado'] ); ?>"></label>
						<label style="margin-left:12px">Botón <input type="text" name="lc_ajustes[txt_boton]" value="<?php echo esc_attr( $a['txt_boton'] ); ?>"></label>
					</td>
				</tr>
				<tr>
					<th scope="row">Colores</th>
					<td>
						<label>Acento <input type="color" name="lc_ajustes[color_acento]" value="<?php echo esc_attr( $a['color_acento'] ); ?>"></label>
						<label style="margin-left:12px">Ocupado <input type="color" name="lc_ajustes[color_ocupado]" value="<?php echo esc_attr( $a['color_ocupado'] ); ?>"></label>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<hr>
		<h2>En la web</h2>
		<p><strong>Ficha</strong> (plantilla de la cabaña o del salón, con el elemento Shortcode de Bricks): <code>[liquen_calendario]</code></p>
		<p><strong>Página general</strong>: <code>[liquen_disponibilidad]</code> — opcional <code>tipos="alojamiento"</code> o <code>ids="12,15"</code>.</p>

		<hr>
		<h2>Estado de los calendarios</h2>
		<?php lc_tabla_estado(); ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="lc_leer_todo">
			<?php wp_nonce_field( 'lc_leer_todo' ); ?>
			<?php submit_button( 'Actualizar todos ahora', 'secondary', 'submit', false ); ?>
		</form>
		<?php
		$ronda = (int) get_option( 'lc_ultima_ronda' );
		$prox  = wp_next_scheduled( 'lc_sincronizar_todo' );
		?>
		<p class="description">
			Lectura automática cada 30 min.
			<?php if ( $ronda ) : ?>Última: <?php echo esc_html( lc_hace( $ronda ) ); ?>.<?php endif; ?>
			<?php if ( $prox ) : ?>Próxima: <?php echo esc_html( wp_date( 'H:i', $prox ) ); ?>.<?php endif; ?>
			Para que corra aunque nadie visite el sitio, agregar en cPanel una tarea programada cada 30 min:
			<code>wget -q -O - <?php echo esc_html( site_url( 'wp-cron.php?doing_wp_cron' ) ); ?> &gt;/dev/null 2&gt;&amp;1</code>
		</p>
	</div>
	<?php
}

function lc_tabla_estado() {
	$recursos = lc_recursos( array( 'estado' => 'any' ) );
	if ( ! $recursos ) {
		echo '<p>Todavía no hay fichas con calendario. Habilitá un tipo arriba y cargá las direcciones en la caja «Calendario» de cada ficha.</p>';
		return;
	}
	echo '<table class="widefat striped"><thead><tr><th>Ficha</th><th>Fuente</th><th>Día de salida</th><th>Última lectura correcta</th><th>Eventos</th><th>Problema</th></tr></thead><tbody>';
	foreach ( $recursos as $id ) {
		$estado  = lc_estado( $id );
		$fuentes = lc_fuentes( $id );
		foreach ( $fuentes as $i => $f ) {
			$e = $estado[ $f['id'] ] ?? array();
			echo '<tr>';
			echo '<td>' . ( 0 === $i ? '<a href="' . esc_url( get_edit_post_link( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a>' : '' ) . '</td>';
			echo '<td>' . esc_html( $f['nombre'] ) . '</td>';
			echo '<td>' . ( $f['salida_ocupa'] ? 'Ocupa' : 'Según el calendario' ) . '</td>';
			echo '<td>' . ( ! empty( $e['ok'] ) ? esc_html( lc_hace( $e['ok'] ) ) : '—' ) . '</td>';
			echo '<td>' . esc_html( (string) ( $e['eventos'] ?? '—' ) ) . '</td>';
			$prob = array_filter( array_merge( array( $e['error'] ?? '' ), (array) ( $e['avisos'] ?? array() ) ) );
			echo '<td>' . ( $prob ? '<span style="color:#b32d2e">' . esc_html( implode( ' ', $prob ) ) . '</span>' : '—' ) . '</td>';
			echo '</tr>';
		}
	}
	echo '</tbody></table>';
}

add_action( 'admin_post_lc_leer_todo', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Sin permiso.' );
	}
	check_admin_referer( 'lc_leer_todo' );
	delete_transient( 'lc_leyendo' );
	lc_sincronizar_todo();
	wp_safe_redirect( admin_url( 'options-general.php?page=liquen-calendarios&lc_leido=1' ) );
	exit;
} );

add_action( 'admin_post_lc_leer_recurso', function () {
	$id = absint( $_GET['recurso'] ?? 0 ); // phpcs:ignore
	if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
		wp_die( 'Sin permiso.' );
	}
	check_admin_referer( 'lc_leer_recurso_' . $id );
	lc_sincronizar_recurso( $id );
	wp_safe_redirect( add_query_arg( 'lc_leido', 1, get_edit_post_link( $id, 'url' ) ) );
	exit;
} );

function lc_hace( int $ts ): string {
	return sprintf( 'hace %s (%s)', human_time_diff( $ts ), wp_date( 'd/m H:i', $ts ) );
}

/* ------------------------------------------------------------------ */
/* Caja «Calendario» en la ficha                                       */
/* ------------------------------------------------------------------ */

add_action( 'add_meta_boxes', function ( $tipo ) {
	if ( in_array( $tipo, lc_tipos_habilitados(), true ) ) {
		add_meta_box( 'lc-calendario', 'Calendario', 'lc_caja', $tipo, 'normal', 'default' );
	}
} );

// La vista previa usa el mismo script que la web. En el editor de bloques las cajas se dibujan
// después de los scripts, así que se encola antes.
add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && in_array( get_current_screen()->post_type ?? '', lc_tipos_habilitados(), true ) ) {
		lc_encolar();
	}
} );


function lc_caja( WP_Post $post ) {
	$fuentes = lc_fuentes( $post->ID );
	$estado  = lc_estado( $post->ID );
	$modo    = lc_modo( $post->ID );
	wp_nonce_field( 'lc_caja', 'lc_caja_nonce' );
	?>
	<p>
		<label><strong>Modo</strong>
			<select name="lc_modo">
				<?php foreach ( lc_modos() as $k => $txt ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $modo, $k ); ?>><?php echo esc_html( $txt ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<span class="description">Ocupa cada día que toca una reserva.</span>
	</p>

	<table class="widefat lc-fuentes" style="margin:8px 0">
		<thead><tr><th style="width:18%">Nombre</th><th>Dirección iCal</th><th style="width:16%">Día de salida</th><th style="width:22%">Estado</th><th style="width:6%">Quitar</th></tr></thead>
		<tbody>
		<?php
		foreach ( $fuentes as $i => $f ) {
			lc_fila_fuente( $i, $f, $estado[ $f['id'] ] ?? array() );
		}
		lc_fila_fuente( count( $fuentes ), null, array() );
		?>
		</tbody>
	</table>
	<p class="description">
		<strong>Google Calendar:</strong> Configuración del calendario → «Dirección secreta en formato iCal». Día de salida: sin marcar.<br>
		<strong>Booking:</strong> Extranet → Tarifas y disponibilidad → Sincronizar calendarios → Exportar. Día de salida: marcar si el día que se van no se puede alquilar.<br>
		Las direcciones son secretas: no se muestran en la web ni completas acá después de guardarlas. Para cambiar una, escribí la nueva encima.
	</p>
	<p>
		<button type="button" class="button" id="lc-otra">+ Otra fuente</button>
		<?php if ( $fuentes ) : ?>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lc_leer_recurso&recurso=' . $post->ID ), 'lc_leer_recurso_' . $post->ID ) ); ?>">Actualizar ahora</a>
			<span class="description">Lee los calendarios guardados. Si cambiaste algo, primero guardá la ficha (al guardar se leen solos).</span>
		<?php endif; ?>
	</p>

	<?php if ( $fuentes && 'publish' === $post->post_status ) : ?>
		<h4 style="margin-bottom:4px">Vista previa</h4>
		<?php echo lc_html_calendario( $post->ID, array( 'vista_previa' => true ) ); // phpcs:ignore ?>
	<?php elseif ( $fuentes ) : ?>
		<p class="description">La vista previa aparece cuando la ficha está publicada.</p>
	<?php endif; ?>

	<script>
	( function () {
		var btn = document.getElementById( 'lc-otra' );
		if ( ! btn ) { return; }
		btn.addEventListener( 'click', function () {
			var tbody = document.querySelector( '.lc-fuentes tbody' );
			var ultima = tbody.querySelector( 'tr:last-child' );
			var n = tbody.querySelectorAll( 'tr' ).length;
			var nueva = ultima.cloneNode( true );
			nueva.querySelectorAll( 'input' ).forEach( function ( el ) {
				el.name = el.name.replace( /\[\d+\]/, '[' + n + ']' );
				if ( el.type === 'checkbox' ) { el.checked = false; } else if ( el.type !== 'hidden' ) { el.value = ''; }
				if ( el.type === 'hidden' ) { el.value = ''; }
			} );
			tbody.appendChild( nueva );
		} );
	} )();
	</script>
	<?php
}

function lc_fila_fuente( int $i, ?array $f, array $e ) {
	$n = 'lc_fuentes[' . $i . ']';
	?>
	<tr>
		<td>
			<input type="hidden" name="<?php echo esc_attr( $n ); ?>[id]" value="<?php echo esc_attr( $f['id'] ?? '' ); ?>">
			<input type="text" name="<?php echo esc_attr( $n ); ?>[nombre]" value="<?php echo esc_attr( $f['nombre'] ?? '' ); ?>" placeholder="Booking, Google…" style="width:100%">
		</td>
		<td>
			<input type="url" name="<?php echo esc_attr( $n ); ?>[url]" value="" placeholder="<?php echo esc_attr( $f ? lc_enmascarar( $f['url'] ) : 'https://…' ); ?>" style="width:100%" autocomplete="off">
		</td>
		<td>
			<label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[salida_ocupa]" value="1" <?php checked( ! empty( $f['salida_ocupa'] ) ); ?>> También ocupa</label>
		</td>
		<td>
			<?php
			if ( ! $f ) {
				echo '<span class="description">Nueva</span>';
			} elseif ( ! empty( $e['error'] ) ) {
				echo '<span style="color:#b32d2e">' . esc_html( $e['error'] ) . '</span>';
			} elseif ( ! empty( $e['ok'] ) ) {
				echo esc_html( lc_hace( $e['ok'] ) ) . ' · ' . (int) ( $e['eventos'] ?? 0 ) . ' eventos';
				foreach ( (array) ( $e['avisos'] ?? array() ) as $av ) {
					echo '<br><span style="color:#996800">' . esc_html( $av ) . '</span>';
				}
			} else {
				echo '<span class="description">Sin leer todavía</span>';
			}
			?>
		</td>
		<td><?php if ( $f ) : ?><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[quitar]" value="1" aria-label="Quitar"><?php endif; ?></td>
	</tr>
	<?php
}

// https://www.booking.com/ical/xxx…abcd → «www.booking.com/…abcd».
function lc_enmascarar( string $url ): string {
	$p = wp_parse_url( $url );
	return ( $p['host'] ?? '' ) . '/…' . substr( $url, -4 );
}

add_action( 'save_post', function ( $post_id, $post ) {
	if ( ! isset( $_POST['lc_caja_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['lc_caja_nonce'] ), 'lc_caja' ) ) {
		return;
	}
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	if ( ! in_array( $post->post_type, lc_tipos_habilitados(), true ) ) {
		return;
	}

	$modo = sanitize_key( wp_unslash( $_POST['lc_modo'] ?? 'dias' ) );
	update_post_meta( $post_id, '_lc_modo', isset( lc_modos()[ $modo ] ) ? $modo : 'dias' );

	$antes   = array_column( lc_fuentes( $post_id ), null, 'id' );
	$nuevas  = array();
	$entrada = (array) wp_unslash( $_POST['lc_fuentes'] ?? array() ); // phpcs:ignore

	foreach ( $entrada as $fila ) {
		$id     = sanitize_key( $fila['id'] ?? '' );
		$previa = $id && isset( $antes[ $id ] ) ? $antes[ $id ] : null;
		if ( $previa && ! empty( $fila['quitar'] ) ) {
			continue;
		}
		$url = lc_limpiar_url( (string) ( $fila['url'] ?? '' ) );
		if ( ! $url && $previa ) {
			$url = $previa['url']; // Campo vacío = se conserva la dirección guardada.
		}
		if ( ! $url ) {
			continue;
		}
		$nombre   = sanitize_text_field( $fila['nombre'] ?? '' );
		$nuevas[] = array(
			'id'           => $previa ? $previa['id'] : strtolower( wp_generate_password( 8, false ) ),
			'nombre'       => '' !== $nombre ? $nombre : ( wp_parse_url( $url, PHP_URL_HOST ) ?: 'Calendario' ),
			'url'          => $url,
			'salida_ocupa' => ! empty( $fila['salida_ocupa'] ),
		);
	}

	if ( $nuevas ) {
		update_post_meta( $post_id, '_lc_fuentes', $nuevas );
	} else {
		delete_post_meta( $post_id, '_lc_fuentes' );
		delete_post_meta( $post_id, '_lc_estado' );
	}

	// Se vuelve a leer si cambió algo de las fuentes o si se pidió.
	$cambio = wp_json_encode( array_values( $antes ) ) !== wp_json_encode( $nuevas );
	if ( $cambio ) {
		lc_sincronizar_recurso( $post_id );
	}
	lc_olvidar_cache();
}, 10, 2 );

// webcal:// → https://; solo http(s).
function lc_limpiar_url( string $url ): string {
	$url = trim( $url );
	if ( '' === $url ) {
		return '';
	}
	$url = preg_replace( '#^webcals?://#i', 'https://', $url );
	$url = esc_url_raw( $url, array( 'http', 'https' ) );
	return $url ?: '';
}

/* ------------------------------------------------------------------ */
/* Aviso si una fuente lleva más de 6 h sin leerse                     */
/* ------------------------------------------------------------------ */

add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$pantalla = get_current_screen();
	if ( ! $pantalla || ! in_array( $pantalla->id, array( 'dashboard', 'plugins', 'settings_page_liquen-calendarios' ), true ) ) {
		return;
	}
	$malas = array();
	foreach ( lc_recursos() as $id ) {
		$estado = lc_estado( $id );
		foreach ( lc_fuentes( $id ) as $f ) {
			$e = $estado[ $f['id'] ] ?? array();
			if ( ! empty( $e['intento'] ) && ( empty( $e['ok'] ) || time() - $e['ok'] > 6 * HOUR_IN_SECONDS ) ) {
				$malas[] = get_the_title( $id ) . ' (' . $f['nombre'] . ')';
			}
		}
	}
	if ( $malas ) {
		printf(
			'<div class="notice notice-warning"><p><strong>Liquen Calendarios:</strong> hace más de 6 horas que no se pueden leer estos calendarios, así que la web puede mostrar días viejos: %s. <a href="%s">Ver el estado</a></p></div>',
			esc_html( implode( ', ', $malas ) ),
			esc_url( admin_url( 'options-general.php?page=liquen-calendarios' ) )
		);
	}
} );
