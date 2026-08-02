( function ( $ ) {
	'use strict';

	console.log( 'FBAS admin.js betöltve (verzió: ' + ( typeof FBAS !== 'undefined' && FBAS.version ? FBAS.version : '?' ) + ')' );

	$( function () {

	let pollTimer = null;

	function ajax( action, data ) {
		const req = $.post( FBAS.ajaxUrl, Object.assign( { action: action, nonce: FBAS.nonce }, data ) );

		req.fail( function ( jqXHR, textStatus ) {
			// Ha idáig eljutunk, a szerver HTTP hibát adott vissza (pl. 403 rossz nonce miatt,
			// 500 PHP hiba, vagy a hoszting blokkolta a kérést) - ezt eddig semmi nem jelezte.
			console.error( 'FBAS AJAX hiba [' + action + ']:', jqXHR.status, textStatus, jqXHR.responseText );
		} );

		return req;
	}

	/* ---------------- Allegro fiók összekötése (Device Code Flow) ---------------- */

	$( '#fbas-connect-btn' ).on( 'click', function () {
		const $btn = $( this );
		$btn.prop( 'disabled', true ).text( FBAS.i18n.connecting );

		ajax( 'fbas_start_device_auth', {} ).done( function ( res ) {
			if ( ! res.success ) {
				alert( res.data.message || FBAS.i18n.error );
				$btn.prop( 'disabled', false ).text( 'Összekötés az Allegro-val' );
				return;
			}

			const data = res.data;
			$( '#fbas-user-code' ).text( data.user_code );
			$( '#fbas-verification-link' ).attr( 'href', data.verification_uri_complete || data.verification_uri );
			$( '#fbas-device-code' ).show();
			$( '#fbas-poll-status' ).text( FBAS.i18n.waiting );

			const intervalMs = Math.max( 3, data.interval || 5 ) * 1000;
			const expiresAt = Date.now() + ( data.expires_in || 600 ) * 1000;

			pollTimer = setInterval( function () {
				if ( Date.now() > expiresAt ) {
					clearInterval( pollTimer );
					$( '#fbas-poll-status' ).text( FBAS.i18n.error + ' (lejárt az idő, próbáld újra)' );
					return;
				}

				ajax( 'fbas_poll_device_auth', {} ).done( function ( pollRes ) {
					if ( ! pollRes.success ) {
						clearInterval( pollTimer );
						$( '#fbas-poll-status' ).text( pollRes.data.message || FBAS.i18n.error );
						return;
					}

					if ( 'connected' === pollRes.data.status ) {
						clearInterval( pollTimer );
						$( '#fbas-poll-status' ).text( FBAS.i18n.connected );
						setTimeout( function () {
							location.reload();
						}, 800 );
					}
					// 'authorization_pending' vagy 'slow_down' esetén tovább várunk.
				} );
			}, intervalMs );
		} );
	} );

	$( '#fbas-disconnect-btn' ).on( 'click', function () {
		if ( ! confirm( 'Biztosan bontod a kapcsolatot az Allegro fiókkal?' ) ) {
			return;
		}
		ajax( 'fbas_disconnect', {} ).done( function () {
			location.reload();
		} );
	} );

	/* ---------------- Manuális teljes szinkron ---------------- */

	$( '#fbas-run-sync-btn' ).on( 'click', function () {
		const $btn = $( this );
		const $status = $( '#fbas-run-sync-status' );
		$btn.prop( 'disabled', true );
		$status.text( FBAS.i18n.syncing );

		ajax( 'fbas_run_sync_now', {} ).done( function ( res ) {
			$btn.prop( 'disabled', false );
			$status.text( res.success ? ( res.data.message || FBAS.i18n.synced ) : ( res.data.message || FBAS.i18n.error ) );
		} );
	} );

	/* ---------------- Kategória keresés / szállítás-garancia-visszaküldés lekérdezés ---------------- */

	function renderLookupResults( $container, items, onPick ) {
		const $list = $container.find( '.fbas-lookup-results' );
		$list.empty();

		if ( ! items.length ) {
			$list.append( '<li class="fbas-lookup-empty">Nincs találat.</li>' );
			return;
		}

		items.forEach( function ( item ) {
			const label = item.path ? ( item.name + ' — ' + item.path ) : item.name;
			const $li = $( '<li class="fbas-lookup-item" />' )
				.text( label + ' (ID: ' + item.id + ')' )
				.attr( 'data-id', item.id );
			$li.on( 'click', function () {
				onPick( item.id );
				$list.empty();
			} );
			$list.append( $li );
		} );
	}

	// Kategória keresés (szövegbevitel alapján, Allegro matching-categories végpont).
	$( document ).on( 'click', '.fbas-lookup-search-btn', function () {
		const $container = $( this ).closest( '.fbas-lookup' );
		const $target = $( $container.data( 'target' ) );
		const query = $container.find( '.fbas-lookup-query' ).val();

		$container.find( '.fbas-lookup-results' ).html( '<li>' + FBAS.i18n.syncing + '</li>' );

		ajax( 'fbas_search_categories', { query: query } ).done( function ( res ) {
			if ( ! res.success ) {
				$container.find( '.fbas-lookup-results' ).html( '<li class="fbas-lookup-empty">' + ( res.data.message || FBAS.i18n.error ) + '</li>' );
				return;
			}
			renderLookupResults( $container, res.data.items, function ( id ) {
				$target.val( id );
			} );
		} ).fail( function ( jqXHR ) {
			$container.find( '.fbas-lookup-results' ).html(
				'<li class="fbas-lookup-empty">' + FBAS.i18n.error + ' (HTTP ' + jqXHR.status + ') - nézd meg a böngésző konzolját (F12) a részletekért.</li>'
			);
		} );
	} );

	// Allow Enter key inside the category search field.
	$( document ).on( 'keydown', '.fbas-lookup-query', function ( e ) {
		if ( 13 === e.which ) {
			e.preventDefault();
			$( this ).closest( '.fbas-lookup' ).find( '.fbas-lookup-search-btn' ).trigger( 'click' );
		}
	} );

	// Fiók-erőforrás listázás (szállítás / garancia / visszaküldés - nincs szövegbevitel, egyből lista).
	$( document ).on( 'click', '.fbas-lookup-list-btn', function () {
		const $container = $( this ).closest( '.fbas-lookup' );
		const $target = $( $container.data( 'target' ) );
		const type = $container.data( 'lookup' );

		$container.find( '.fbas-lookup-results' ).html( '<li>' + FBAS.i18n.syncing + '</li>' );

		ajax( 'fbas_list_account_resource', { type: type } ).done( function ( res ) {
			if ( ! res.success ) {
				$container.find( '.fbas-lookup-results' ).html( '<li class="fbas-lookup-empty">' + ( res.data.message || FBAS.i18n.error ) + '</li>' );
				return;
			}
			renderLookupResults( $container, res.data.items, function ( id ) {
				$target.val( id );
			} );
		} ).fail( function ( jqXHR ) {
			$container.find( '.fbas-lookup-results' ).html(
				'<li class="fbas-lookup-empty">' + FBAS.i18n.error + ' (HTTP ' + jqXHR.status + ') - nézd meg a böngésző konzolját (F12) a részletekért.</li>'
			);
		} );
	} );

	/* ---------------- Kötelező kategória-paraméterek ---------------- */

	function renderParamsForm( items ) {
		const $container = $( '#fbas-params-form' );

		if ( ! items.length ) {
			$container.html( '<p class="description">Ehhez a kategóriához nem talált paramétert az Allegro.</p>' );
			return;
		}

		let html = '<table class="fbas-params-table"><thead><tr>' +
			'<th>Paraméter</th><th>Érték</th>' +
			'</tr></thead><tbody>';

		items.forEach( function ( item ) {
			const tag = item.required
				? '<span class="fbas-param-required-tag">kötelező</span>'
				: '<span class="fbas-param-optional-tag">opcionális</span>';

			let field = '';

			if ( item.dictionary && item.dictionary.length ) {
				const savedIds = ( item.saved && item.saved.valuesIds ) ? item.saved.valuesIds : [];
				field += '<select class="fbas-param-input" data-param-id="' + item.id + '" data-param-name="' + item.name + '" data-mode="dictionary">';
				field += '<option value="">— nincs kiválasztva —</option>';
				item.dictionary.forEach( function ( opt ) {
					const selected = savedIds.indexOf( opt.id ) !== -1 ? ' selected' : '';
					field += '<option value="' + opt.id + '"' + selected + '>' + opt.value + '</option>';
				} );
				field += '</select>';
			} else {
				const savedValue = ( item.saved && item.saved.values && item.saved.values[0] ) ? item.saved.values[0] : '';
				field += '<input type="text" class="fbas-param-input" data-param-id="' + item.id + '" data-param-name="' + item.name + '" data-mode="text" value="' + $( '<div>' ).text( savedValue ).html() + '" placeholder="Minden termékre azonos érték..." />';
			}

			html += '<tr class="' + ( item.required ? 'fbas-param--required' : '' ) + '">' +
				'<td>' + item.name + ' <small>(ID: ' + item.id + ')</small>' + tag + '</td>' +
				'<td>' + field + '</td>' +
				'</tr>';
		} );

		html += '</tbody></table>';
		html += '<button type="button" class="button button-primary" id="fbas-save-params-btn">Paraméterek mentése</button> ';
		html += '<span id="fbas-save-params-status" class="description"></span>';

		$container.html( html );
	}

	$( '#fbas-load-params-btn' ).on( 'click', function () {
		console.log( 'FBAS: "Kötelező paraméterek lekérdezése" gomb megnyomva.' );
		const $btn = $( this );
		$btn.prop( 'disabled', true );
		$( '#fbas-params-form' ).html( '<p>' + FBAS.i18n.syncing + '</p>' );

		ajax( 'fbas_get_category_parameters', {} ).done( function ( res ) {
			$btn.prop( 'disabled', false );
			if ( ! res.success ) {
				$( '#fbas-params-form' ).html( '<p class="fbas-error-text">' + ( res.data.message || FBAS.i18n.error ) + '</p>' );
				return;
			}
			renderParamsForm( res.data.items );
		} ).fail( function ( jqXHR ) {
			$btn.prop( 'disabled', false );
			$( '#fbas-params-form' ).html( '<p class="fbas-error-text">' + FBAS.i18n.error + ' (HTTP ' + jqXHR.status + ')</p>' );
		} );
	} );

	$( document ).on( 'click', '#fbas-save-params-btn', function () {
		const $btn = $( this );
		const $status = $( '#fbas-save-params-status' );
		const values = {};

		$( '.fbas-param-input' ).each( function () {
			const $el = $( this );
			const id = $el.data( 'param-id' );
			const name = $el.data( 'param-name' );
			const mode = $el.data( 'mode' );
			const val = $el.val();

			if ( ! val ) {
				return;
			}

			values[ id ] = { name: name };
			if ( 'dictionary' === mode ) {
				values[ id ].valuesIds = [ val ];
			} else {
				values[ id ].values = [ val ];
			}
		} );

		$btn.prop( 'disabled', true );
		$status.text( FBAS.i18n.syncing );

		ajax( 'fbas_save_category_parameter_values', { values_json: JSON.stringify( values ) } ).done( function ( res ) {
			$btn.prop( 'disabled', false );
			$status.text( res.success ? ( res.data.message || FBAS.i18n.synced ) : ( res.data.message || FBAS.i18n.error ) );
		} ).fail( function () {
			$btn.prop( 'disabled', false );
			$status.text( FBAS.i18n.error );
		} );
	} );

	/* ---------------- Termék lista: kapcsoló, szinkron most, törlés ---------------- */

	$( document ).on( 'change', '.fbas-toggle-sync', function () {
		const $row = $( this ).closest( 'tr' );
		const productId = $row.data( 'product-id' );
		const enabled = $( this ).is( ':checked' );

		ajax( 'fbas_toggle_product_sync', { product_id: productId, enabled: enabled ? 1 : 0 } );
	} );

	$( document ).on( 'click', '.fbas-sync-now-btn', function () {
		const $btn = $( this );
		const $row = $btn.closest( 'tr' );
		const productId = $row.data( 'product-id' );

		$btn.prop( 'disabled', true ).text( FBAS.i18n.syncing );

		ajax( 'fbas_sync_single_product', { product_id: productId } ).done( function ( res ) {
			$btn.prop( 'disabled', false ).text( 'Szinkron most' );
			if ( res.success ) {
				location.reload();
			} else {
				alert( res.data.message || FBAS.i18n.error );
			}
		} );
	} );

	$( document ).on( 'click', '.fbas-remove-offer-btn', function () {
		if ( ! confirm( FBAS.i18n.confirmRemove ) ) {
			return;
		}
		const $row = $( this ).closest( 'tr' );
		const productId = $row.data( 'product-id' );

		ajax( 'fbas_remove_offer', { product_id: productId } ).done( function ( res ) {
			if ( res.success ) {
				location.reload();
			} else {
				alert( res.data.message || FBAS.i18n.error );
			}
		} );
	} );

	} ); // document ready vége

} )( jQuery );
