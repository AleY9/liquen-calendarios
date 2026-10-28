/**
 * Liquen Calendarios — calendario de la ficha (.lc-cal) y página general (.lc-disp).
 * Sin dependencias. Los datos llegan de la API (solo días ocupados).
 */
( function () {
	'use strict';

	var C = window.lcConfig || {};
	var T = C.textos || {};
	var IDIOMA = C.idioma || 'es';
	// wp_localize_script pasa todo como texto: se convierten los números.
	var SEMANA = parseInt( C.semana, 10 ) || 0;
	var MESES = parseInt( C.meses, 10 ) || 6;
	var pedidos = {};

	/* ---------- Fechas (siempre en UTC para no depender de la zona del navegador) ---------- */

	function fecha( s ) {
		var p = s.split( '-' );
		return new Date( Date.UTC( +p[ 0 ], +p[ 1 ] - 1, +p[ 2 ] ) );
	}
	function iso( d ) {
		return d.toISOString().slice( 0, 10 );
	}
	function masDias( s, n ) {
		var d = fecha( s );
		d.setUTCDate( d.getUTCDate() + n );
		return iso( d );
	}
	function masMeses( s, n ) {
		var d = fecha( s.slice( 0, 7 ) + '-01' );
		d.setUTCMonth( d.getUTCMonth() + n );
		return iso( d );
	}
	function diasDelMes( s ) {
		var d = fecha( s.slice( 0, 7 ) + '-01' );
		return new Date( Date.UTC( d.getUTCFullYear(), d.getUTCMonth() + 1, 0 ) ).getUTCDate();
	}
	function fmt( s, opciones ) {
		opciones.timeZone = 'UTC';
		try {
			return new Intl.DateTimeFormat( IDIOMA, opciones ).format( fecha( s ) );
		} catch ( e ) {
			return new Intl.DateTimeFormat( 'es', opciones ).format( fecha( s ) );
		}
	}
	function corta( s ) {
		return s.slice( 8, 10 ) + '/' + s.slice( 5, 7 ) + '/' + s.slice( 0, 4 );
	}
	function tituloMes( s ) {
		var t = fmt( s, { month: 'long', year: 'numeric' } );
		return t.charAt( 0 ).toUpperCase() + t.slice( 1 );
	}
	// Iniciales de los días según el primer día de la semana del sitio.
	function cabeceraSemana() {
		var out = [];
		// 2023-01-01 fue domingo.
		for ( var i = 0; i < 7; i++ ) {
			var dia = ( SEMANA + i ) % 7;
			out.push( fmt( '2023-01-0' + ( 1 + dia ), { weekday: 'narrow' } ) );
		}
		return out;
	}
	function columnaDia( s ) {
		return ( fecha( s ).getUTCDay() - SEMANA + 7 ) % 7;
	}

	/* ---------- Datos ---------- */

	function pedir( ids ) {
		var clave = ids.join( ',' );
		if ( ! pedidos[ clave ] ) {
			var url = C.api + ( C.api.indexOf( '?' ) > -1 ? '&' : '?' ) + 'recursos=' + encodeURIComponent( clave ) + '&meses=' + MESES;
			pedidos[ clave ] = fetch( url, { credentials: 'omit' } ).then( function ( r ) {
				if ( ! r.ok ) {
					throw new Error( r.status );
				}
				return r.json();
			} );
		}
		return pedidos[ clave ];
	}

	function el( tag, clase, texto ) {
		var e = document.createElement( tag );
		if ( clase ) {
			e.className = clase;
		}
		if ( texto != null ) {
			e.textContent = texto;
		}
		return e;
	}

	function mensaje( plantilla, datos ) {
		return plantilla.replace( /\{(\w+)\}/g, function ( m, k ) {
			return datos[ k ] != null ? datos[ k ] : m;
		} );
	}

	function enlaceWhatsApp( texto ) {
		return 'https://wa.me/' + C.whatsapp + '?text=' + encodeURIComponent( texto );
	}

	function leyenda() {
		var l = el( 'div', 'lc-leyenda' );
		[ [ 'lc-libre', T.libre ], [ 'lc-ocupado', T.ocupado ] ].forEach( function ( p ) {
			var i = el( 'span', 'lc-ley' );
			i.appendChild( el( 'span', 'lc-muestra ' + p[ 0 ] ) );
			i.appendChild( document.createTextNode( p[ 1 ] ) );
			l.appendChild( i );
		} );
		return l;
	}

	function navegacion( alAnterior, alSiguiente, puedeAnt, puedeSig, titulo ) {
		var nav = el( 'div', 'lc-nav' );
		var a = el( 'button', 'lc-flecha', '‹' );
		a.type = 'button';
		a.setAttribute( 'aria-label', T.anterior );
		a.disabled = ! puedeAnt;
		a.addEventListener( 'click', alAnterior );
		var s = el( 'button', 'lc-flecha', '›' );
		s.type = 'button';
		s.setAttribute( 'aria-label', T.siguiente );
		s.disabled = ! puedeSig;
		s.addEventListener( 'click', alSiguiente );
		nav.appendChild( a );
		nav.appendChild( el( 'span', 'lc-nav-titulo', titulo ) );
		nav.appendChild( s );
		return nav;
	}

	/* ---------- Calendario de una ficha ---------- */

	function Calendario( caja, recurso, datos, opciones ) {
		this.caja = caja;
		this.recurso = recurso;
		this.datos = datos;
		this.opc = opciones || {};
		this.ocupados = {};
		( recurso.ocupados || [] ).forEach( function ( d ) {
			this.ocupados[ d ] = true;
		}, this );
		this.inicioVentana = datos.desde;
		this.mes = datos.desde;
		this.desde = null;
		this.hasta = null;
		this.aviso = '';
		this.render();
		// Se redibuja si cambia cuántos meses entran (al girar el celular, o si la caja estaba oculta).
		var yo = this;
		var observar = function () {
			if ( yo.cuantosMeses() !== yo.verMeses ) {
				yo.render();
			}
		};
		if ( window.ResizeObserver ) {
			new ResizeObserver( observar ).observe( caja );
		} else {
			window.addEventListener( 'resize', observar );
		}
	}

	Calendario.prototype.cuantosMeses = function () {
		return this.caja.clientWidth >= 560 ? 2 : 1;
	};

	Calendario.prototype.estado = function ( s ) {
		if ( s < this.datos.hoy ) {
			return 'pasado';
		}
		if ( s >= this.datos.hasta ) {
			return 'fuera';
		}
		return this.ocupados[ s ] ? 'ocupado' : 'libre';
	};

	Calendario.prototype.rangoLibre = function ( a, b ) {
		for ( var s = a; s <= b; s = masDias( s, 1 ) ) {
			if ( this.estado( s ) !== 'libre' ) {
				return false;
			}
		}
		return true;
	};

	Calendario.prototype.tocar = function ( s ) {
		this.aviso = '';
		if ( ! this.desde || this.hasta || s < this.desde ) {
			this.desde = s;
			this.hasta = null;
		} else if ( this.rangoLibre( this.desde, s ) ) {
			this.hasta = s;
		} else {
			this.aviso = T.cruza;
			this.desde = s;
			this.hasta = null;
		}
		this.render();
	};

	Calendario.prototype.mesHtml = function ( mes ) {
		var yo = this;
		var caja = el( 'div', 'lc-mes' );
		caja.appendChild( el( 'div', 'lc-mes-titulo', tituloMes( mes ) ) );
		var grilla = el( 'div', 'lc-grilla' );
		cabeceraSemana().forEach( function ( d ) {
			grilla.appendChild( el( 'span', 'lc-dsem', d ) );
		} );
		var primero = mes.slice( 0, 7 ) + '-01';
		for ( var v = 0; v < columnaDia( primero ); v++ ) {
			grilla.appendChild( el( 'span', 'lc-vacio' ) );
		}
		var n = diasDelMes( mes );
		for ( var d = 1; d <= n; d++ ) {
			var s = mes.slice( 0, 7 ) + '-' + ( d < 10 ? '0' : '' ) + d;
			var est = this.estado( s );
			var b = el( 'button', 'lc-dia lc-' + est, String( d ) );
			b.type = 'button';
			b.setAttribute( 'data-dia', s );
			var etiqueta = fmt( s, { weekday: 'long', day: 'numeric', month: 'long' } );
			if ( est === 'libre' ) {
				b.setAttribute( 'aria-label', etiqueta + ': ' + T.libre );
				if ( this.opc.vistaPrevia || ! C.whatsapp ) {
					b.disabled = true;
				} else {
					b.addEventListener( 'click', ( function ( dia ) {
						return function () {
							yo.tocar( dia );
						};
					} )( s ) );
				}
			} else {
				b.disabled = true;
				b.setAttribute( 'aria-label', etiqueta + ( est === 'ocupado' ? ': ' + T.ocupado : '' ) );
			}
			if ( this.desde && ( s === this.desde || ( this.hasta && s >= this.desde && s <= this.hasta ) ) ) {
				b.className += ' lc-elegido';
				b.setAttribute( 'aria-pressed', 'true' );
			}
			if ( s === this.datos.hoy ) {
				b.className += ' lc-hoy';
			}
			grilla.appendChild( b );
		}
		caja.appendChild( grilla );
		return caja;
	};

	Calendario.prototype.render = function () {
		var yo = this;
		var verMeses = this.verMeses = this.cuantosMeses();
		var ultimoMes = masMeses( this.datos.hasta, -1 );
		if ( this.mes > ultimoMes ) {
			this.mes = ultimoMes;
		}

		this.caja.innerHTML = '';
		this.caja.classList.add( 'lc-listo' );

		this.caja.appendChild( navegacion(
			function () {
				yo.mes = masMeses( yo.mes, -1 );
				yo.render();
			},
			function () {
				yo.mes = masMeses( yo.mes, 1 );
				yo.render();
			},
			this.mes > this.inicioVentana,
			masMeses( this.mes, verMeses ) < this.datos.hasta,
			''
		) );

		var meses = el( 'div', 'lc-meses' );
		for ( var i = 0; i < verMeses; i++ ) {
			var m = masMeses( this.mes, i );
			if ( m < this.datos.hasta ) {
				meses.appendChild( this.mesHtml( m ) );
			}
		}
		this.caja.appendChild( meses );
		this.caja.appendChild( leyenda() );

		if ( this.opc.vistaPrevia || ! C.whatsapp ) {
			return;
		}

		// Selección y botón de WhatsApp.
		var pie = el( 'div', 'lc-pie' );
		var info = el( 'p', 'lc-info' );
		if ( this.desde && this.hasta ) {
			info.textContent = T.llegada + ': ' + corta( this.desde ) + ' · ' + T.salidaTxt + ': ' + corta( this.hasta ) + ' ';
			var borrar = el( 'button', 'lc-borrar', T.borrar );
			borrar.type = 'button';
			borrar.addEventListener( 'click', function () {
				yo.desde = yo.hasta = null;
				yo.render();
			} );
			info.appendChild( borrar );
		} else if ( this.desde ) {
			info.textContent = T.llegada + ': ' + corta( this.desde ) + '. ' + T.salida;
		} else {
			info.textContent = T.elegir;
		}
		if ( this.aviso ) {
			pie.appendChild( el( 'p', 'lc-aviso-sel', this.aviso ) );
		}
		pie.appendChild( info );

		var texto = this.desde && this.hasta ?
			mensaje( C.msjFechas, { recurso: this.recurso.titulo, desde: corta( this.desde ), hasta: corta( this.hasta ) } ) :
			mensaje( C.msjGeneral, { recurso: this.recurso.titulo } );
		var wa = el( 'a', 'lc-whatsapp', T.boton );
		wa.href = enlaceWhatsApp( texto );
		wa.target = '_blank';
		wa.rel = 'noopener';
		pie.appendChild( wa );
		this.caja.appendChild( pie );
	};

	function iniciarFicha( caja ) {
		var id = parseInt( caja.getAttribute( 'data-recurso' ), 10 );
		pedir( [ id ] ).then( function ( datos ) {
			var r = ( datos.recursos || [] )[ 0 ];
			if ( ! r ) {
				caja.innerHTML = '';
				return;
			}
			new Calendario( caja, r, datos, { vistaPrevia: caja.hasAttribute( 'data-vista-previa' ) } );
		} ).catch( function () {
			caja.innerHTML = '';
			caja.appendChild( el( 'p', 'lc-error', T.error ) );
		} );
	}

	/* ---------- Página general ---------- */

	function Disponibilidad( caja, lista, datos ) {
		this.caja = caja;
		this.lista = lista;
		this.datos = datos;
		this.mes = datos.desde;
		this.porId = {};
		( datos.recursos || [] ).forEach( function ( r ) {
			this.porId[ r.id ] = r;
		}, this );
		this.modo = null;
		this.render();
		var yo = this;
		window.addEventListener( 'resize', function () {
			var m = window.innerWidth >= 768 ? 'tabla' : 'lista';
			if ( m !== yo.modo ) {
				yo.render();
			}
		} );
	}

	Disponibilidad.prototype.render = function () {
		this.modo = window.innerWidth >= 768 ? 'tabla' : 'lista';
		this.caja.innerHTML = '';
		this.caja.classList.add( 'lc-listo' );
		if ( ! this.datos.recursos || ! this.datos.recursos.length ) {
			this.caja.appendChild( el( 'p', 'lc-aviso', T.nada ) );
			return;
		}
		if ( this.modo === 'tabla' ) {
			this.tabla();
		} else {
			this.listado();
		}
	};

	Disponibilidad.prototype.tabla = function () {
		var yo = this;
		var mes = this.mes;
		this.caja.appendChild( navegacion(
			function () {
				yo.mes = masMeses( yo.mes, -1 );
				yo.render();
			},
			function () {
				yo.mes = masMeses( yo.mes, 1 );
				yo.render();
			},
			mes > this.datos.desde,
			masMeses( mes, 1 ) < this.datos.hasta,
			tituloMes( mes )
		) );

		var n = diasDelMes( mes );
		var envoltura = el( 'div', 'lc-tabla-env' );
		var tabla = el( 'table', 'lc-tabla' );
		var cab = el( 'tr' );
		cab.appendChild( el( 'th', 'lc-t-nombre' ) );
		for ( var d = 1; d <= n; d++ ) {
			var s = mes.slice( 0, 7 ) + '-' + ( d < 10 ? '0' : '' ) + d;
			var th = el( 'th', 'lc-t-dia' + ( fecha( s ).getUTCDay() % 6 === 0 ? ' lc-finde' : '' ) );
			th.appendChild( el( 'span', 'lc-t-dsem', fmt( s, { weekday: 'narrow' } ) ) );
			th.appendChild( el( 'span', 'lc-t-num', String( d ) ) );
			cab.appendChild( th );
		}
		var thead = el( 'thead' );
		thead.appendChild( cab );
		tabla.appendChild( thead );

		var cuerpo = el( 'tbody' );
		this.lista.forEach( function ( item ) {
			var r = yo.porId[ item.id ];
			if ( ! r ) {
				return;
			}
			var cal = new Estado( r, yo.datos );
			var fila = el( 'tr' );
			var nombre = el( 'th', 'lc-t-nombre' );
			var a = el( 'a', '', r.titulo );
			a.href = r.url;
			nombre.appendChild( a );
			fila.appendChild( nombre );
			for ( var d = 1; d <= n; d++ ) {
				var s = mes.slice( 0, 7 ) + '-' + ( d < 10 ? '0' : '' ) + d;
				var est = cal.estado( s );
				var td = el( 'td', 'lc-t-celda lc-' + est );
				td.title = r.titulo + ' · ' + corta( s ) + ( est === 'libre' ? ' · ' + T.libre : est === 'ocupado' ? ' · ' + T.ocupado : '' );
				fila.appendChild( td );
			}
			cuerpo.appendChild( fila );
		} );
		tabla.appendChild( cuerpo );
		envoltura.appendChild( tabla );
		this.caja.appendChild( envoltura );
		this.caja.appendChild( leyenda() );
	};

	Disponibilidad.prototype.listado = function () {
		var yo = this;
		this.lista.forEach( function ( item ) {
			var r = yo.porId[ item.id ];
			if ( ! r ) {
				return;
			}
			var det = el( 'details', 'lc-item' );
			var sum = el( 'summary', 'lc-item-titulo' );
			sum.appendChild( el( 'span', '', r.titulo ) );
			sum.appendChild( el( 'span', 'lc-item-ver', T.verCal ) );
			det.appendChild( sum );
			var cuerpo = el( 'div', 'lc-cal' );
			det.appendChild( cuerpo );
			var link = el( 'a', 'lc-item-link', r.titulo + ' →' );
			link.href = r.url;
			det.appendChild( link );
			var creado = false;
			det.addEventListener( 'toggle', function () {
				if ( det.open && ! creado ) {
					creado = true;
					new Calendario( cuerpo, r, yo.datos, {} );
				}
			} );
			yo.caja.appendChild( det );
		} );
	};

	// Estado de un día sin dibujar (para la tabla).
	function Estado( r, datos ) {
		this.datos = datos;
		this.ocupados = {};
		( r.ocupados || [] ).forEach( function ( d ) {
			this.ocupados[ d ] = true;
		}, this );
	}
	Estado.prototype.estado = Calendario.prototype.estado;

	function iniciarDisp( caja ) {
		var lista;
		try {
			lista = JSON.parse( caja.getAttribute( 'data-recursos' ) || '[]' );
		} catch ( e ) {
			lista = [];
		}
		pedir( lista.map( function ( i ) {
			return i.id;
		} ) ).then( function ( datos ) {
			new Disponibilidad( caja, lista, datos );
		} ).catch( function () {
			caja.innerHTML = '';
			caja.appendChild( el( 'p', 'lc-error', T.error ) );
		} );
	}

	function iniciar() {
		document.querySelectorAll( '.lc-cal[data-recurso]' ).forEach( iniciarFicha );
		document.querySelectorAll( '.lc-disp[data-recursos]' ).forEach( iniciarDisp );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', iniciar );
	} else {
		iniciar();
	}
} )();
