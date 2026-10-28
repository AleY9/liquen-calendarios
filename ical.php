<?php
/**
 * Lector de calendarios iCal (RFC 5545), lo justo para disponibilidad:
 * eventos, fechas con o sin hora y zona horaria, duración, eventos repetidos
 * (RRULE con los casos comunes), EXDATE, RECURRENCE-ID y cancelados.
 *
 * Nunca devuelve títulos, descripciones ni datos de personas: solo inicio, fin y un UID.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lee un texto iCal y devuelve las ocupaciones que tocan la ventana [desde, hasta).
 *
 * @return array{eventos: array, avisos: string[]}
 *         eventos: [ ['inicio' => 'Y-m-d H:i:s', 'fin' => 'Y-m-d H:i:s', 'todo_el_dia' => bool, 'uid' => string], ... ]
 *         Fechas en hora local del sitio; fin exclusivo.
 */
function lc_ical_leer( string $texto, DateTimeImmutable $desde, DateTimeImmutable $hasta ): array {
	$zona_sitio = lc_zona();
	$avisos     = array();
	$crudos     = lc_ical_vevents( $texto );

	// Primero, las excepciones: ocurrencias movidas o canceladas de un evento repetido.
	$excluir = array();
	foreach ( $crudos as $ev ) {
		if ( isset( $ev['RECURRENCE-ID'] ) ) {
			$rid = lc_ical_fecha( $ev['RECURRENCE-ID'][0], $zona_sitio );
			if ( $rid ) {
				$excluir[ lc_ical_uid( $ev ) ][ lc_ical_clave( $rid ) ] = true;
			}
		}
	}

	$eventos = array();
	foreach ( $crudos as $ev ) {
		if ( 'CANCELLED' === strtoupper( lc_ical_valor( $ev, 'STATUS' ) ) ) {
			continue;
		}
		if ( empty( $ev['DTSTART'] ) ) {
			continue;
		}
		$ini = lc_ical_fecha( $ev['DTSTART'][0], $zona_sitio );
		if ( ! $ini ) {
			$avisos[] = 'Un evento tiene una fecha que no se pudo leer y se saltó.';
			continue;
		}
		$es_dia = $ini['dia'];
		$uid    = lc_ical_uid( $ev );

		// Fin: DTEND, o DURATION, o la regla del RFC (un día si es fecha, nada si tiene hora).
		$fin = ! empty( $ev['DTEND'] ) ? lc_ical_fecha( $ev['DTEND'][0], $zona_sitio ) : null;
		if ( $fin ) {
			$fin = $fin['dt'];
		} elseif ( ! empty( $ev['DURATION'] ) ) {
			$fin = lc_ical_sumar_duracion( $ini['dt'], $ev['DURATION'][0]['valor'] );
		} else {
			$fin = $es_dia ? $ini['dt']->modify( '+1 day' ) : $ini['dt'];
		}
		if ( $fin < $ini['dt'] ) {
			$fin = $ini['dt'];
		}

		// Duración: en días para eventos de día completo (no se ve afectada por cambios de hora).
		$dur_dias = $es_dia ? (int) round( ( strtotime( $fin->format( 'Y-m-d' ) . ' UTC' ) - strtotime( $ini['dt']->format( 'Y-m-d' ) . ' UTC' ) ) / DAY_IN_SECONDS ) : 0;
		$dur_seg  = $es_dia ? 0 : $fin->getTimestamp() - $ini['dt']->getTimestamp();

		// Inicios: uno solo, o todos los de la regla de repetición.
		if ( ! empty( $ev['RRULE'] ) && ! isset( $ev['RECURRENCE-ID'] ) ) {
			$res    = lc_ical_repetir( $ini['dt'], $ev['RRULE'][0]['valor'], $hasta );
			$inicios = $res['inicios'];
			if ( $res['aviso'] ) {
				$avisos[] = $res['aviso'];
			}
			// EXDATE: fechas sacadas de la serie.
			$fuera = $excluir[ $uid ] ?? array();
			foreach ( $ev['EXDATE'] ?? array() as $ex ) {
				foreach ( explode( ',', $ex['valor'] ) as $parte ) {
					$f = lc_ical_fecha( array( 'params' => $ex['params'], 'valor' => trim( $parte ) ), $zona_sitio );
					if ( $f ) {
						$fuera[ lc_ical_clave( $f ) ] = true;
					}
				}
			}
			$inicios = array_values( array_filter( $inicios, function ( $dt ) use ( $fuera, $es_dia ) {
				return ! isset( $fuera[ lc_ical_clave( array( 'dt' => $dt, 'dia' => $es_dia ) ) ] );
			} ) );
		} else {
			$inicios = array( $ini['dt'] );
		}

		foreach ( $inicios as $i => $dt_ini ) {
			$dt_fin = $es_dia ? $dt_ini->modify( '+' . $dur_dias . ' days' ) : $dt_ini->modify( '+' . $dur_seg . ' seconds' );

			if ( $es_dia ) {
				// Las fechas no tienen zona: se toman tal cual en la zona del sitio.
				$l_ini = new DateTimeImmutable( $dt_ini->format( 'Y-m-d' ) . ' 00:00:00', $zona_sitio );
				$l_fin = new DateTimeImmutable( $dt_fin->format( 'Y-m-d' ) . ' 00:00:00', $zona_sitio );
			} else {
				$l_ini = $dt_ini->setTimezone( $zona_sitio );
				$l_fin = $dt_fin->setTimezone( $zona_sitio );
			}

			// Solo lo que toca la ventana. Un evento con hora y sin duración ocupa su instante.
			if ( $l_ini >= $hasta || ( $l_fin <= $desde && ! ( $l_fin == $l_ini && $l_ini >= $desde ) ) ) {
				continue;
			}

			$eventos[] = array(
				'inicio'      => $l_ini->format( 'Y-m-d H:i:s' ),
				'fin'         => $l_fin->format( 'Y-m-d H:i:s' ),
				'todo_el_dia' => $es_dia,
				'uid'         => $uid . '#' . $dt_ini->format( 'Ymd\THis' ),
			);
		}
	}

	return array(
		'eventos' => $eventos,
		'avisos'  => array_values( array_unique( $avisos ) ),
	);
}

/**
 * Separa los VEVENT en arrays de propiedades: NOMBRE => [ ['params' => [], 'valor' => ''], ... ].
 * Solo se guardan las propiedades que hacen falta (no SUMMARY, DESCRIPTION, ATTENDEE...).
 */
function lc_ical_vevents( string $texto ): array {
	$texto  = str_replace( array( "\r\n", "\r" ), "\n", $texto );
	$texto  = preg_replace( "/\n[ \t]/", '', $texto ); // Líneas plegadas.
	$utiles = array( 'UID', 'DTSTART', 'DTEND', 'DURATION', 'RRULE', 'EXDATE', 'RECURRENCE-ID', 'STATUS' );

	$eventos = array();
	$actual  = null;
	$nivel   = 0; // Para ignorar VALARM dentro del evento.

	foreach ( explode( "\n", $texto ) as $linea ) {
		$linea = rtrim( $linea );
		if ( '' === $linea ) {
			continue;
		}
		$may = strtoupper( $linea );
		if ( 'BEGIN:VEVENT' === $may ) {
			$actual = array();
			$nivel  = 0;
			continue;
		}
		if ( null === $actual ) {
			continue;
		}
		if ( 'END:VEVENT' === $may ) {
			$eventos[] = $actual;
			$actual    = null;
			continue;
		}
		if ( 0 === strpos( $may, 'BEGIN:' ) ) {
			$nivel++;
			continue;
		}
		if ( 0 === strpos( $may, 'END:' ) ) {
			$nivel = max( 0, $nivel - 1 );
			continue;
		}
		if ( $nivel > 0 ) {
			continue;
		}

		$pos = strpos( $linea, ':' );
		if ( false === $pos ) {
			continue;
		}
		$cabeza = substr( $linea, 0, $pos );
		$valor  = substr( $linea, $pos + 1 );
		$partes = explode( ';', $cabeza );
		$nombre = strtoupper( array_shift( $partes ) );
		if ( ! in_array( $nombre, $utiles, true ) ) {
			continue;
		}
		$params = array();
		foreach ( $partes as $p ) {
			$kv = explode( '=', $p, 2 );
			if ( 2 === count( $kv ) ) {
				$params[ strtoupper( $kv[0] ) ] = trim( $kv[1], '"' );
			}
		}
		$actual[ $nombre ][] = array(
			'params' => $params,
			'valor'  => trim( $valor ),
		);
	}
	return $eventos;
}

function lc_ical_valor( array $ev, string $nombre ): string {
	return (string) ( $ev[ $nombre ][0]['valor'] ?? '' );
}

function lc_ical_uid( array $ev ): string {
	$uid = lc_ical_valor( $ev, 'UID' );
	return '' !== $uid ? $uid : md5( wp_json_encode( $ev['DTSTART'] ?? array() ) );
}

/**
 * Fecha iCal → ['dt' => DateTimeImmutable en su zona original, 'dia' => bool].
 * Fecha sola (VALUE=DATE o 8 dígitos): día completo. Con Z: UTC. Con TZID: esa zona. Sin nada: zona del sitio.
 */
function lc_ical_fecha( array $prop, DateTimeZone $zona_sitio ): ?array {
	$valor = strtoupper( trim( $prop['valor'] ) );

	if ( preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $valor, $m ) ) {
		$dt = DateTimeImmutable::createFromFormat( '!Y-m-d', "$m[1]-$m[2]-$m[3]", $zona_sitio );
		return $dt ? array( 'dt' => $dt, 'dia' => true ) : null;
	}
	if ( ! preg_match( '/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})(Z?)$/', $valor, $m ) ) {
		return null;
	}

	if ( 'Z' === $m[7] ) {
		$zona = new DateTimeZone( 'UTC' );
	} elseif ( ! empty( $prop['params']['TZID'] ) ) {
		$zona = lc_ical_zona( $prop['params']['TZID'], $zona_sitio );
	} else {
		$zona = $zona_sitio;
	}
	$dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', "$m[1]-$m[2]-$m[3] $m[4]:$m[5]:$m[6]", $zona );
	return $dt ? array( 'dt' => $dt, 'dia' => false ) : null;
}

// TZID → zona. Acepta nombres IANA («America/Montevideo») y algunos de Windows/Outlook.
function lc_ical_zona( string $tzid, DateTimeZone $por_defecto ): DateTimeZone {
	$tzid    = trim( $tzid, " \"'/" );
	$windows = array(
		'Montevideo Standard Time'    => 'America/Montevideo',
		'Argentina Standard Time'     => 'America/Argentina/Buenos_Aires',
		'E. South America Standard Time' => 'America/Sao_Paulo',
		'Romance Standard Time'       => 'Europe/Madrid',
		'UTC'                         => 'UTC',
	);
	if ( isset( $windows[ $tzid ] ) ) {
		$tzid = $windows[ $tzid ];
	}
	try {
		return new DateTimeZone( $tzid );
	} catch ( Exception $e ) {
		return $por_defecto;
	}
}

// Clave para comparar ocurrencias (EXDATE / RECURRENCE-ID) sin importar la zona.
function lc_ical_clave( array $f ): string {
	return $f['dia'] ? $f['dt']->format( 'Ymd' ) : $f['dt']->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Ymd\THis' );
}

// DURATION (P1D, PT2H30M, P1W...) sumada a una fecha.
function lc_ical_sumar_duracion( DateTimeImmutable $dt, string $dur ): DateTimeImmutable {
	$dur = strtoupper( trim( $dur ) );
	if ( '-' === ( $dur[0] ?? '' ) ) {
		return $dt;
	}
	$dur = ltrim( $dur, '+' );
	try {
		return $dt->add( new DateInterval( $dur ) );
	} catch ( Exception $e ) {
		return $dt;
	}
}

/**
 * Inicios de un evento repetido hasta $hasta.
 * Soporta FREQ DAILY/WEEKLY/MONTHLY/YEARLY con INTERVAL, COUNT, UNTIL, BYDAY, BYMONTHDAY y BYMONTH.
 * Si la regla trae algo más, se toma solo la primera fecha y se avisa.
 *
 * @return array{inicios: DateTimeImmutable[], aviso: string}
 */
function lc_ical_repetir( DateTimeImmutable $inicio, string $rrule, DateTimeImmutable $hasta ): array {
	$r = array();
	foreach ( explode( ';', strtoupper( $rrule ) ) as $par ) {
		$kv = explode( '=', $par, 2 );
		if ( 2 === count( $kv ) ) {
			$r[ $kv[0] ] = $kv[1];
		}
	}

	$freq       = $r['FREQ'] ?? '';
	$soportadas = array( 'FREQ', 'INTERVAL', 'COUNT', 'UNTIL', 'BYDAY', 'BYMONTHDAY', 'BYMONTH', 'WKST' );
	$raras      = array_diff( array_keys( $r ), $soportadas );
	if ( ! in_array( $freq, array( 'DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY' ), true ) || $raras ) {
		return array(
			'inicios' => array( $inicio ),
			'aviso'   => 'Un evento repetido usa una regla que el plugin todavía no entiende (' . esc_html( $rrule ) . '): se tomó solo su primera fecha.',
		);
	}

	$intervalo = max( 1, (int) ( $r['INTERVAL'] ?? 1 ) );
	$cuenta    = isset( $r['COUNT'] ) ? max( 1, (int) $r['COUNT'] ) : null;
	$limite    = $hasta;
	if ( isset( $r['UNTIL'] ) ) {
		$u = lc_ical_fecha( array( 'params' => array(), 'valor' => $r['UNTIL'] ), $inicio->getTimezone() );
		if ( $u ) {
			// UNTIL es inclusivo.
			$tope   = $u['dia'] ? $u['dt']->modify( '+1 day' ) : $u['dt']->modify( '+1 second' );
			$limite = $tope < $limite ? $tope : $limite;
		}
	}

	$dias_semana = array( 'MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7 );
	$byday       = array(); // [ ['n' => 0|±k, 'd' => 1..7], ... ]
	if ( ! empty( $r['BYDAY'] ) ) {
		foreach ( explode( ',', $r['BYDAY'] ) as $b ) {
			if ( preg_match( '/^([+-]?\d{1,2})?(MO|TU|WE|TH|FR|SA|SU)$/', $b, $m ) ) {
				$byday[] = array( 'n' => (int) ( $m[1] ?? 0 ), 'd' => $dias_semana[ $m[2] ] );
			}
		}
	}
	$bymonthday = ! empty( $r['BYMONTHDAY'] ) ? array_map( 'intval', explode( ',', $r['BYMONTHDAY'] ) ) : array();
	$bymonth    = ! empty( $r['BYMONTH'] ) ? array_map( 'intval', explode( ',', $r['BYMONTH'] ) ) : array();
	$hora       = $inicio->format( 'H:i:s' );

	$inicios = array();
	$n       = 0;      // Ocurrencias contadas (para COUNT, incluye las anteriores a la ventana).
	$vueltas = 0;
	$periodo = $inicio;

	while ( $vueltas++ < 5000 ) {
		// Candidatos dentro del período actual.
		$candidatos = array();
		switch ( $freq ) {
			case 'DAILY':
				$candidatos[] = $periodo;
				break;

			case 'WEEKLY':
				$dias = $byday ? array_unique( array_column( $byday, 'd' ) ) : array( (int) $inicio->format( 'N' ) );
				sort( $dias );
				$lunes = $periodo->modify( '-' . ( (int) $periodo->format( 'N' ) - 1 ) . ' days' );
				foreach ( $dias as $d ) {
					$candidatos[] = $lunes->modify( '+' . ( $d - 1 ) . ' days' );
				}
				break;

			case 'MONTHLY':
				$candidatos = lc_ical_dias_del_mes( $periodo, $byday, $bymonthday, (int) $inicio->format( 'j' ), $hora );
				break;

			case 'YEARLY':
				$meses = $bymonth ?: array( (int) $inicio->format( 'n' ) );
				foreach ( $meses as $mes ) {
					$base = $periodo->setDate( (int) $periodo->format( 'Y' ), $mes, 1 );
					if ( $byday || $bymonthday ) {
						$candidatos = array_merge( $candidatos, lc_ical_dias_del_mes( $base, $byday, $bymonthday, (int) $inicio->format( 'j' ), $hora ) );
					} elseif ( checkdate( $mes, (int) $inicio->format( 'j' ), (int) $periodo->format( 'Y' ) ) ) {
						$candidatos[] = $base->setDate( (int) $periodo->format( 'Y' ), $mes, (int) $inicio->format( 'j' ) );
					}
				}
				break;
		}

		usort( $candidatos, function ( $a, $b ) {
			return $a <=> $b;
		} );

		foreach ( $candidatos as $c ) {
			$c = $c->setTime( ...array_map( 'intval', explode( ':', $hora ) ) );
			if ( $c < $inicio ) {
				continue;
			}
			if ( $bymonth && 'YEARLY' !== $freq && ! in_array( (int) $c->format( 'n' ), $bymonth, true ) ) {
				continue;
			}
			if ( $byday && 'DAILY' === $freq && ! in_array( (int) $c->format( 'N' ), array_column( $byday, 'd' ), true ) ) {
				continue;
			}
			if ( $c >= $limite ) {
				break 2;
			}
			$n++;
			$inicios[] = $c;
			if ( null !== $cuenta && $n >= $cuenta ) {
				break 2;
			}
		}

		// Siguiente período.
		switch ( $freq ) {
			case 'DAILY':
				$periodo = $periodo->modify( "+$intervalo days" );
				break;
			case 'WEEKLY':
				$periodo = $periodo->modify( "+$intervalo weeks" );
				break;
			case 'MONTHLY':
				$periodo = $periodo->setDate( (int) $periodo->format( 'Y' ), (int) $periodo->format( 'n' ), 1 )->modify( "+$intervalo months" );
				break;
			case 'YEARLY':
				$periodo = $periodo->setDate( (int) $periodo->format( 'Y' ) + $intervalo, 1, 1 );
				break;
		}
		if ( $periodo >= $limite && 'WEEKLY' !== $freq ) {
			break;
		}
		if ( 'WEEKLY' === $freq && $periodo->modify( '-7 days' ) >= $limite ) {
			break;
		}
	}

	return array(
		'inicios' => $inicios,
		'aviso'   => '',
	);
}

// Días de un mes según BYDAY (con o sin ordinal: 1SA, -1FR, MO) o BYMONTHDAY; si no hay, el mismo día del inicio.
function lc_ical_dias_del_mes( DateTimeImmutable $mes, array $byday, array $bymonthday, int $dia_inicio, string $hora ): array {
	$anio = (int) $mes->format( 'Y' );
	$m    = (int) $mes->format( 'n' );
	$ult  = (int) $mes->format( 't' );
	$base = $mes->setDate( $anio, $m, 1 );
	$out  = array();

	if ( $bymonthday ) {
		foreach ( $bymonthday as $d ) {
			$d = $d < 0 ? $ult + $d + 1 : $d;
			if ( $d >= 1 && $d <= $ult ) {
				$out[] = $base->setDate( $anio, $m, $d );
			}
		}
		return $out;
	}

	if ( $byday ) {
		foreach ( $byday as $b ) {
			$dias = array();
			for ( $d = 1; $d <= $ult; $d++ ) {
				if ( (int) $base->setDate( $anio, $m, $d )->format( 'N' ) === $b['d'] ) {
					$dias[] = $d;
				}
			}
			if ( 0 === $b['n'] ) {
				foreach ( $dias as $d ) {
					$out[] = $base->setDate( $anio, $m, $d );
				}
			} else {
				$idx = $b['n'] > 0 ? $b['n'] - 1 : count( $dias ) + $b['n'];
				if ( isset( $dias[ $idx ] ) ) {
					$out[] = $base->setDate( $anio, $m, $dias[ $idx ] );
				}
			}
		}
		return $out;
	}

	if ( $dia_inicio <= $ult ) {
		$out[] = $base->setDate( $anio, $m, $dia_inicio );
	}
	return $out;
}
