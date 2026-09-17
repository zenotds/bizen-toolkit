( function () {
	var cfg     = window.bizenAiTriage || {};
	var grid    = document.getElementById( 'bizen-ai-grid' );
	var toolbar = document.getElementById( 'bizen-ai-toolbar' );

	if ( ! grid || ! toolbar || ! cfg.ajaxUrl ) {
		return;
	}

	var selectAll = document.getElementById( 'bizen-ai-select-all' );
	var countNode = toolbar.querySelector( '[data-selected-count]' );
	var feedback  = toolbar.querySelector( '.bizen-ai-toolbar__feedback' );
	var buttons   = list( toolbar.querySelectorAll( '[data-bulk-status]' ) );

	// Anchor for shift-click ranges: the last box the user touched directly.
	var anchor = null;

	function list( nodes ) {
		return Array.prototype.slice.call( nodes );
	}

	function boxes() {
		return list( grid.querySelectorAll( '.bizen-ai-item__select' ) );
	}

	function itemOf( box ) {
		return box.closest( '.bizen-ai-item' );
	}

	function checked() {
		return boxes().filter( function ( box ) {
			return box.checked;
		} );
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

	function sync() {
		var all = boxes();
		var n   = 0;

		all.forEach( function ( box ) {
			var item = itemOf( box );
			if ( box.checked ) {
				n++;
			}
			if ( item ) {
				item.classList.toggle( 'is-selected', box.checked );
			}
		} );

		if ( countNode ) {
			countNode.textContent = n ? String( cfg.selected ).replace( '%d', n ) : '';
		}

		buttons.forEach( function ( button ) {
			button.disabled = 0 === n;
		} );

		if ( selectAll ) {
			selectAll.checked       = n > 0 && n === all.length;
			selectAll.indeterminate = n > 0 && n < all.length;
		}
	}

	if ( selectAll ) {
		selectAll.addEventListener( 'change', function () {
			boxes().forEach( function ( box ) {
				box.checked = selectAll.checked;
			} );
			anchor = null;
			sync();
		} );
	}

	// Selection, with shift-click extending from the last box touched.
	grid.addEventListener( 'click', function ( event ) {
		var box = event.target.closest ? event.target.closest( '.bizen-ai-item__select' ) : null;

		if ( ! box ) {
			return;
		}

		var all   = boxes();
		var index = all.indexOf( box );

		if ( event.shiftKey && null !== anchor && index > -1 ) {
			var from = Math.min( anchor, index );
			var to   = Math.max( anchor, index );

			for ( var i = from; i <= to; i++ ) {
				all[ i ].checked = box.checked;
			}
		}

		anchor = index;
		say( feedback, '' );
		sync();
	} );

	// A radio on a single card is the one-off correction: save it on the spot.
	grid.addEventListener( 'change', function ( event ) {
		var input = event.target;

		if ( ! input.matches || ! input.matches( 'input[type="radio"]' ) ) {
			return;
		}

		var item = itemOf( input );
		if ( ! item ) {
			return;
		}

		var note = item.querySelector( '.bizen-ai-item__feedback' );
		say( note, cfg.saving );

		save( [ item.dataset.id ], input.value ).then( function ( result ) {
			if ( result && result.success ) {
				say( note, cfg.saved );
				dropAutoFlag( item );
				refreshCounts( result.data.counts );
			} else {
				say( note, cfg.failed, true );
			}
		} ).catch( function () {
			say( note, cfg.failed, true );
		} );
	} );

	buttons.forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var chosen = checked();

			if ( ! chosen.length ) {
				say( feedback, cfg.none, true );
				return;
			}

			if ( ! window.confirm( String( cfg.confirm ).replace( '%d', chosen.length ) ) ) {
				return;
			}

			var status = button.dataset.bulkStatus;
			var items  = chosen.map( itemOf );

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

				// The batch is done: clear it so the next one starts empty.
				chosen.forEach( function ( box ) {
					box.checked = false;
				} );
				anchor = null;

				say( feedback, cfg.saved );
				refreshCounts( result.data.counts );
				sync();
			} ).catch( function () {
				say( feedback, cfg.failed, true );
			} );
		} );
	} );

	sync();
} )();
