/**
 * Liquen Calendarios — calendario de actividades [liquen_agenda] (0.2.0).
 * Un mes por vez: cuadrícula con los días que tienen actividades y, al lado (o abajo en el celular),
 * la lista por día. Las actividades de varios días aparecen en todos sus días.
 * Los datos llegan de la API (/liquen-calendarios/v1/actividades), así la página puede estar en caché.
 */
( function () {
	'use strict';

	var C = window.lcAgenda || {};
	var T = C.textos || {};
	var IDIOMA = C.idioma || 'es';
	var SEMANA = parseInt( C.semana, 10 ) || 0;
	var cache = {};

	function fecha( s ) {
		var p = s.split( '-' );
		return new Date( Date.UTC( +p[ 0 ], +p[ 1 ] - 1, +( p[ 2 ] || 1 ) ) );
	}
	function iso( d ) {
		return d.toISOString().slice( 0, 10 );
	}
	function masDias( s, n ) {
		var d = fecha( s );
		d.setUTCDate( d.getUTCDate() + n );
		return iso( d );
	}
	function fmt( s, op ) {
		op.timeZone = 'UTC';
		try {
			return new Intl.DateTimeFormat( IDIOMA, op ).format( fecha( s ) );
		} catch ( e ) {
			return new Intl.DateTimeFormat( 'es', op ).format( fecha( s ) );
		}
	}
	function may( t ) {
		return t.charAt( 0 ).toUpperCase() + t.slice( 1 );
	}
	function el( tag, cls, txt ) {
		var e = document.createElement( tag );
		if ( cls ) { e.className = cls; }
		if ( txt !== undefined ) { e.textContent = txt; }
		return e;
	}
	function flecha( lado ) {
		var d = lado === 'izq' ? 'M15 18l-6-6 6-6' : 'M9 18l6-6-6-6';
		return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' + d + '"/></svg>';
	}

	function pedir( mes ) {
		if ( cache[ mes || 'hoy' ] ) {
			return Promise.resolve( cache[ mes || 'hoy' ] );
		}
		var url = C.api + ( C.api.indexOf( '?' ) > -1 ? '&' : '?' ) + ( mes ? 'desde=' + mes + '-01' : '' );
		return fetch( url, { headers: { Accept: 'application/json' } } ).then( function ( r ) {
			if ( ! r.ok ) { throw new Error( r.status ); }
			return r.json();
		} ).then( function ( d ) {
			cache[ mes || 'hoy' ] = d;
			cache[ d.mes ] = d;
			return d;
		} );
	}

	// Día → actividades de ese día (las de varios días, en cada uno).
	function porDia( d ) {
		var mapa = {};
		var ini = d.mes + '-01';
		var fin = iso( new Date( Date.UTC( +d.mes.slice( 0, 4 ), +d.mes.slice( 5, 7 ), 1 ) ) );
		d.actividades.forEach( function ( a ) {
			for ( var x = a.inicio; x <= a.fin; x = masDias( x, 1 ) ) {
				if ( x >= ini && x < fin ) {
					( mapa[ x ] = mapa[ x ] || [] ).push( a );
				}
			}
		} );
		return mapa;
	}

	function rango( a ) {
		if ( a.inicio === a.fin ) { return ''; }
		return fmt( a.inicio, { day: 'numeric', month: 'short' } ) + ' – ' + fmt( a.fin, { day: 'numeric', month: 'short' } );
	}

	function dibujar( caja, d ) {
		caja.innerHTML = '';
		var id = 'lc-ag-' + Math.random().toString( 36 ).slice( 2, 7 );
		var dias = porDia( d );

		// Navegación.
		var nav = el( 'div', 'lc-nav' );
		var ant = el( 'button', 'lc-flecha' );
		ant.type = 'button';
		ant.innerHTML = flecha( 'izq' );
		ant.setAttribute( 'aria-label', T.anterior || 'Mes anterior' );
		ant.disabled = ! d.anterior;
		ant.addEventListener( 'click', function () { cargar( caja, d.anterior ); } );
		var sig = el( 'button', 'lc-flecha' );
		sig.type = 'button';
		sig.innerHTML = flecha( 'der' );
		sig.setAttribute( 'aria-label', T.siguiente || 'Mes siguiente' );
		sig.disabled = ! d.siguiente;
		sig.addEventListener( 'click', function () { cargar( caja, d.siguiente ); } );
		var tit = el( 'div', 'lc-nav-titulo', may( fmt( d.mes + '-01', { month: 'long', year: 'numeric' } ) ) );
		tit.setAttribute( 'aria-live', 'polite' );
		nav.appendChild( ant );
		nav.appendChild( tit );
		nav.appendChild( sig );
		caja.appendChild( nav );

		var cuerpo = el( 'div', 'lc-ag-cuerpo' );

		// Cuadrícula.
		var grilla = el( 'div', 'lc-ag-grilla' );
		for ( var i = 0; i < 7; i++ ) {
			grilla.appendChild( el( 'span', 'lc-ag-dsem', fmt( '2023-01-0' + ( 1 + ( SEMANA + i ) % 7 ), { weekday: 'narrow' } ) ) );
		}
		var primero = fecha( d.mes + '-01' );
		var vacios = ( primero.getUTCDay() - SEMANA + 7 ) % 7;
		for ( i = 0; i < vacios; i++ ) {
			grilla.appendChild( el( 'span' ) );
		}
		var total = new Date( Date.UTC( primero.getUTCFullYear(), primero.getUTCMonth() + 1, 0 ) ).getUTCDate();
		for ( var n = 1; n <= total; n++ ) {
			var f = d.mes + '-' + ( n < 10 ? '0' : '' ) + n;
			var hay = dias[ f ];
			var celda = el( hay ? 'button' : 'span', 'lc-ag-dia', String( n ) );
			if ( hay ) {
				celda.type = 'button';
				celda.classList.add( 'lc-ag-con' );
				celda.setAttribute( 'aria-label', fmt( f, { weekday: 'long', day: 'numeric', month: 'long' } ) + ': ' + hay.map( function ( a ) { return a.titulo; } ).join( ', ' ) );
				( function ( destino ) {
					celda.addEventListener( 'click', function () {
						var li = document.getElementById( destino );
						if ( ! li ) { return; }
						caja.querySelectorAll( '.lc-ag-marcado' ).forEach( function ( x ) { x.classList.remove( 'lc-ag-marcado' ); } );
						li.classList.add( 'lc-ag-marcado' );
						li.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
					} );
				} )( id + '-' + f );
			}
			if ( f < d.hoy ) { celda.classList.add( 'lc-ag-pasado' ); }
			if ( f === d.hoy ) { celda.classList.add( 'lc-ag-hoy' ); }
			grilla.appendChild( celda );
		}
		cuerpo.appendChild( grilla );

		// Lista por día (desde hoy si es el mes actual).
		var lista = el( 'ul', 'lc-ag-lista' );
		var claves = Object.keys( dias ).sort().filter( function ( f ) { return f >= d.hoy; } );
		if ( ! claves.length ) {
			lista.appendChild( el( 'li', 'lc-ag-nada', T.nada || '' ) );
		}
		claves.forEach( function ( f ) {
			var li = el( 'li', 'lc-ag-dia-lista' );
			li.id = id + '-' + f;
			li.appendChild( el( 'p', 'lc-ag-fecha', fmt( f, { weekday: 'long', day: 'numeric', month: 'long' } ) ) );
			dias[ f ].forEach( function ( a ) {
				var it = el( 'a', 'lc-ag-item' );
				it.href = a.url;
				if ( a.tipo ) { it.appendChild( el( 'span', 'lc-ag-tipo', a.tipo ) ); }
				it.appendChild( el( 'span', 'lc-ag-titulo', a.titulo ) );
				var r = rango( a );
				if ( r ) { it.appendChild( el( 'span', 'lc-ag-rango', r ) ); }
				li.appendChild( it );
			} );
			lista.appendChild( li );
		} );
		cuerpo.appendChild( lista );
		caja.appendChild( cuerpo );
	}

	function cargar( caja, mes ) {
		caja.setAttribute( 'aria-busy', 'true' );
		pedir( mes ).then( function ( d ) {
			dibujar( caja, d );
			caja.removeAttribute( 'aria-busy' );
		} ).catch( function () {
			caja.innerHTML = '';
			caja.appendChild( el( 'p', 'lc-error', T.error || '' ) );
			caja.removeAttribute( 'aria-busy' );
		} );
	}

	function iniciar() {
		document.querySelectorAll( '.lc-agenda' ).forEach( function ( caja ) {
			if ( caja.dataset.lcListo ) { return; }
			caja.dataset.lcListo = '1';
			cargar( caja, '' );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', iniciar );
	} else {
		iniciar();
	}
} )();
