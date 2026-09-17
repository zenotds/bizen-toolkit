( function () {
	var cfg     = window.bizenAiTriage || {};
	var grid    = document.getElementById( 'bizen-ai-grid' );
	var toolbar = document.getElementById( 'bizen-ai-toolbar' );

	if ( ! grid || ! toolbar || ! cfg.ajaxUrl ) {
		return;
	}

	// Not a status: the name the endpoint understands as "remove the status".
	var UNREVIEWED = 'unreviewed';

	var selectAll = document.getElementById( 'bizen-ai-select-all' );
	var countNode = toolbar.querySelector( '[data-selected-count]' );
	var feedback  = toolbar.querySelector( '.bizen-ai-toolbar__feedback' );
	var undoBtn   = toolbar.querySelector( '.bizen-ai-toolbar__undo' );
	var buttons   = list( toolbar.querySelectorAll( '[data-bulk-status]' ) );

	var anchor   = null; // shift-click range anchor
	var pending  = null; // timeout that finishes a fade-out
	var undoable = null; // the one action that can still be taken back

	function list( nodes ) {
		return Array.prototype.slice.call( nodes );
	}

	function items() {
		return list( grid.querySelectorAll( '.bizen-ai-item' ) );
	}

	function boxes() {
		return list( grid.querySelectorAll( '.bizen-ai-item__select' ) );
	}

	function itemOf( node ) {
		return node.closest( '.bizen-ai-item' );
	}

	function checked() {
		return boxes().filter( function ( box ) {
			return box.checked;
		} );
	}

	function statusOf( item ) {
		var radio = item.querySelector( 'input[type="radio"]:checked' );
		return radio ? radio.value : UNREVIEWED;
	}

	function applyStatus( item, status ) {
		list( item.querySelectorAll( 'input[type="radio"]' ) ).forEach( function ( radio ) {
			radio.checked = radio.value === status;
		} );
		item.__bizenStatus = status;
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

	// One level deep on purpose: the next action replaces what came before it.
	function remember( entries ) {
		undoable = entries;
		if ( undoBtn ) {
			undoBtn.hidden = false;
		}
	}

	function forget() {
		undoable = null;
		if ( undoBtn ) {
			undoBtn.hidden = true;
		}
	}

	function belongsHere( status ) {
		if ( 'all' === cfg.filter ) {
			return true;
		}
		if ( UNREVIEWED === status ) {
			return UNREVIEWED === cfg.filter;
		}
		// Anything saved from the review queue now has a status, so it is done here.
		if ( UNREVIEWED === cfg.filter ) {
			return false;
		}
		return cfg.filter === status;
	}

	/*
	 * A card that no longer matches the view it is sitting in. It keeps the saved
	 * state visible for a beat before fading, so the change is seen rather than
	 * guessed at; emptying the grid means this batch is finished, so go back for
	 * the next one — page 1 of the same view, since clearing rows shifts the rest
	 * forward and a stale offset would skip over them.
	 */
	function retire( list_, status ) {
		if ( belongsHere( status ) ) {
			return;
		}

		list_.forEach( function ( item ) {
			var box = item.querySelector( '.bizen-ai-item__select' );
			if ( box ) {
				box.checked = false;
			}
			// Where it stood, so undo can put it back in order.
			item.__bizenNext = item.nextElementSibling;
			item.classList.add( 'is-leaving' );
		} );

		pending = window.setTimeout( function () {
			pending = null;

			list_.forEach( function ( item ) {
				item.remove();
			} );

			anchor = null;
			sync();

			if ( cfg.viewUrl && ! grid.querySelector( '.bizen-ai-item' ) ) {
				forget(); // nothing survives the navigation
				window.location.href = cfg.viewUrl;
			}
		}, 750 );
	}

	function putBack( entries ) {
		// The fade may still be running; stop it before anything gets detached.
		if ( pending ) {
			window.clearTimeout( pending );
			pending = null;
		}

		// Reverse order, so each card finds the neighbour it used to sit in front of.
		entries.slice().reverse().forEach( function ( entry ) {
			var item = entry.item;

			item.classList.remove( 'is-leaving' );
			applyStatus( item, entry.previous );
			say( item.querySelector( '.bizen-ai-item__feedback' ), '' );

			if ( ! item.isConnected ) {
				var next = item.__bizenNext;

				if ( next && next.isConnected && next.parentNode === grid ) {
					grid.insertBefore( item, next );
				} else {
					grid.appendChild( item );
				}
			}
		} );
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

		var previous = item.__bizenStatus;
		var note     = item.querySelector( '.bizen-ai-item__feedback' );

		say( note, cfg.saving );

		save( [ item.dataset.id ], input.value ).then( function ( result ) {
			if ( ! result || ! result.success ) {
				say( note, cfg.failed, true );
				return;
			}

			say( note, cfg.saved );
			dropAutoFlag( item );
			item.__bizenStatus = input.value;
			refreshCounts( result.data.counts );
			remember( [ { item: item, previous: previous } ] );
			retire( [ item ], input.value );
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

			var status  = button.dataset.bulkStatus;
			var message = UNREVIEWED === status ? cfg.reset : cfg.confirm;

			if ( ! window.confirm( String( message ).replace( '%d', chosen.length ) ) ) {
				return;
			}

			var touched = chosen.map( itemOf );
			var entries = touched.map( function ( item ) {
				return { item: item, previous: item.__bizenStatus };
			} );

			say( feedback, cfg.saving );

			save( touched.map( function ( item ) {
				return item.dataset.id;
			} ), status ).then( function ( result ) {
				if ( ! result || ! result.success ) {
					say( feedback, cfg.failed, true );
					return;
				}

				touched.forEach( function ( item ) {
					applyStatus( item, status );
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
				remember( entries );
				retire( touched, status );
			} ).catch( function () {
				say( feedback, cfg.failed, true );
			} );
		} );
	} );

	if ( undoBtn ) {
		undoBtn.addEventListener( 'click', function () {
			if ( ! undoable ) {
				return;
			}

			var entries = undoable;
			var groups  = {};

			// Each card goes back to its own previous status, so one call per group.
			entries.forEach( function ( entry ) {
				groups[ entry.previous ] = groups[ entry.previous ] || [];
				groups[ entry.previous ].push( entry.item.dataset.id );
			} );

			forget();
			say( feedback, cfg.saving );

			Promise.all( Object.keys( groups ).map( function ( status ) {
				return save( groups[ status ], status );
			} ) ).then( function ( results ) {
				var ok = results.every( function ( result ) {
					return result && result.success;
				} );

				if ( ! ok ) {
					say( feedback, cfg.failed, true );
					return;
				}

				putBack( entries );
				sync();
				say( feedback, cfg.undone );
				refreshCounts( results[ results.length - 1 ].data.counts );
			} ).catch( function () {
				say( feedback, cfg.failed, true );
			} );
		} );
	}

	items().forEach( function ( item ) {
		item.__bizenStatus = statusOf( item );
	} );

	sync();
} )();
