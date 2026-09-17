( function () {
	var cfg  = window.bizenAiTriage || {};
	var grid = document.getElementById( 'bizen-ai-grid' );

	if ( ! grid || ! cfg.ajaxUrl ) {
		return;
	}

	function save( ids, status ) {
		var body = new FormData();

		body.append( 'action', 'bizen_ai_set_status' );
		body.append( 'nonce', cfg.nonce );
		body.append( 'status', status );
		ids.forEach( function ( id ) {
			body.append( 'ids[]', id );
		} );

		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	function say( node, text, failed ) {
		if ( ! node ) {
			return;
		}
		node.textContent = text;
		node.classList.toggle( 'is-error', !! failed );
	}

	function refreshCounts( counts ) {
		Object.keys( counts || {} ).forEach( function ( key ) {
			var node = document.querySelector( '[data-count="' + key + '"]' );
			if ( node ) {
				node.textContent = Number( counts[ key ] ).toLocaleString();
			}
		} );
	}

	// A status set by hand is no longer the uploader's guess.
	function dropAutoFlag( item ) {
		var flag = item.querySelector( '.bizen-ai-item__auto' );
		if ( flag ) {
			flag.remove();
		}
	}

	grid.addEventListener( 'change', function ( event ) {
		var input = event.target;

		if ( ! input.matches || ! input.matches( 'input[type="radio"]' ) ) {
			return;
		}

		var item = input.closest( '.bizen-ai-item' );
		if ( ! item ) {
			return;
		}

		var feedback = item.querySelector( '.bizen-ai-item__feedback' );
		say( feedback, cfg.saving );

		save( [ item.dataset.id ], input.value ).then( function ( result ) {
			if ( result && result.success ) {
				say( feedback, cfg.saved );
				dropAutoFlag( item );
				refreshCounts( result.data.counts );
			} else {
				say( feedback, cfg.failed, true );
			}
		} ).catch( function () {
			say( feedback, cfg.failed, true );
		} );
	} );

	var bulk = document.querySelector( '.bizen-ai-bulk' );

	if ( bulk ) {
		bulk.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '[data-bulk-status]' );
			if ( ! button ) {
				return;
			}

			var status   = button.dataset.bulkStatus;
			var items    = Array.prototype.slice.call( grid.querySelectorAll( '.bizen-ai-item' ) );
			var feedback = bulk.querySelector( '.bizen-ai-bulk__feedback' );

			if ( ! items.length || ! window.confirm( cfg.confirm ) ) {
				return;
			}

			say( feedback, cfg.saving );

			save( items.map( function ( item ) {
				return item.dataset.id;
			} ), status ).then( function ( result ) {
				if ( ! result || ! result.success ) {
					say( feedback, cfg.failed, true );
					return;
				}

				items.forEach( function ( item ) {
					var radio = item.querySelector( 'input[value="' + status + '"]' );
					if ( radio ) {
						radio.checked = true;
					}
					dropAutoFlag( item );
					say( item.querySelector( '.bizen-ai-item__feedback' ), cfg.saved );
				} );

				say( feedback, cfg.saved );
				refreshCounts( result.data.counts );
			} ).catch( function () {
				say( feedback, cfg.failed, true );
			} );
		} );
	}
} )();
