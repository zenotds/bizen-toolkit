( function () {
	var cfg  = window.bizenToolkit || {};
	var root = document.querySelector( '.bzn' );

	if ( ! root || ! cfg.ajaxUrl ) {
		return;
	}

	root.classList.add( 'bzn-js' );

	var modulesView = root.querySelector( '.bzn-view-modules' );
	var updatesView = root.querySelector( '.bzn-view-updates' );
	var title       = modulesView.querySelector( '.bzn-title' );
	var search      = root.querySelector( '.bzn-search-input' );
	var noMatch     = root.querySelector( '.bzn-no-match' );
	var status      = root.querySelector( '.bzn-status' );
	var navItems    = list( root.querySelectorAll( '.bzn-nav-item' ) );
	var filters     = list( root.querySelectorAll( '[data-filter]' ) );
	var groups      = list( root.querySelectorAll( '.bzn-group' ) );

	var state = { view: 'all', filter: 'all', query: '' };

	// Toggles are sent one at a time: each request rewrites the whole option.
	var queue = Promise.resolve();

	function list( nodes ) {
		return Array.prototype.slice.call( nodes );
	}

	function format( text, value ) {
		return text.replace( '%s', value );
	}

	function say( message, isError ) {
		status.textContent = message;
		status.classList.toggle( 'is-error', !! isError );
	}

	function post( data ) {
		var body = new FormData();
		body.append( 'nonce', cfg.nonce );
		Object.keys( data ).forEach( function ( key ) {
			body.append( key, data[ key ] );
		} );

		return fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				if ( ! json || ! json.success ) {
					throw new Error( 'request failed' );
				}
				return json.data;
			} );
	}

	// The hash names the view; ?tab=updates is the old URL of the Updates tab.
	function route() {
		var view = window.location.hash.slice( 1 );

		if ( ! view && /[?&]tab=updates\b/.test( window.location.search ) ) {
			view = 'updates';
		}

		var known = navItems.some( function ( item ) {
			return item.dataset.view === view;
		} );

		state.view = known ? view : 'all';
		render();
	}

	function render() {
		var isUpdates = state.view === 'updates';
		var query     = state.query.trim().toLowerCase();
		var visible   = 0;

		navItems.forEach( function ( item ) {
			if ( item.dataset.view === state.view ) {
				item.setAttribute( 'aria-current', 'page' );
				if ( ! isUpdates ) {
					title.textContent = item.querySelector( '.bzn-nav-label' ).textContent;
				}
			} else {
				item.removeAttribute( 'aria-current' );
			}
		} );

		// A search looks through every category.
		modulesView.hidden = isUpdates && ! query;
		updatesView.hidden = ! modulesView.hidden;
		modulesView.classList.toggle( 'bzn-is-category', state.view !== 'all' && ! query );

		groups.forEach( function ( group ) {
			var inView = query || state.view === 'all' || state.view === 'updates' || group.dataset.category === state.view;
			var shown  = 0;

			list( group.querySelectorAll( '.bzn-module' ) ).forEach( function ( row ) {
				var show = inView && matchesFilter( row ) && ( ! query || row.dataset.search.indexOf( query ) !== -1 );
				row.hidden = ! show;
				shown += show ? 1 : 0;
			} );

			group.hidden = ! shown;
			visible += shown;
		} );

		noMatch.hidden = visible > 0;

		filters.forEach( function ( button ) {
			button.setAttribute( 'aria-pressed', button.dataset.filter === state.filter ? 'true' : 'false' );
		} );
	}

	function matchesFilter( row ) {
		switch ( state.filter ) {
			case 'on':
				return row.dataset.enabled === '1';
			case 'off':
				return row.dataset.enabled !== '1';
			case 'issue':
				return row.dataset.issue === '1';
			default:
				return true;
		}
	}

	function updateSummary() {
		var rows   = list( root.querySelectorAll( '.bzn-module' ) );
		var active = rows.filter( function ( row ) {
			return row.dataset.enabled === '1';
		} );
		var issues = active.filter( function ( row ) {
			return row.dataset.issue === '1';
		} ).length;

		root.querySelector( '.bzn-active-count' ).textContent = active.length;
		root.querySelector( '.bzn-issue-count' ).textContent  = issues;
		root.querySelector( '.bzn-issues' ).hidden            = ! issues;
	}

	function toggle( input ) {
		var row     = input.closest( '.bzn-module' );
		var name    = row.querySelector( '.bzn-module-title label' ).textContent.trim();
		var enabled = input.checked;

		row.dataset.enabled = enabled ? '1' : '0';
		updateSummary();

		queue = queue.then( function () {
			return post( { action: 'bizen_toolkit_toggle', module: input.dataset.module, enabled: enabled ? '1' : '0' } )
				.then( function () {
					say( format( enabled ? cfg.i18n.enabled : cfg.i18n.disabled, name ) );
				} )
				.catch( function () {
					input.checked       = ! enabled;
					row.dataset.enabled = enabled ? '0' : '1';
					updateSummary();
					say( cfg.i18n.error, true );
				} );
		} );
	}

	function checkNow( button ) {
		button.disabled = true;
		say( cfg.i18n.checking );

		post( { action: 'bizen_toolkit_check' } )
			.then( function ( data ) {
				updatesView.innerHTML = data.html;
				say( cfg.i18n.checked );
			} )
			.catch( function () {
				button.disabled = false;
				say( cfg.i18n.error, true );
			} );
	}

	root.addEventListener( 'change', function ( event ) {
		if ( event.target.matches( 'input[data-module]' ) ) {
			toggle( event.target );
		}
	} );

	root.addEventListener( 'click', function ( event ) {
		var filter = event.target.closest( '[data-filter]' );
		if ( filter ) {
			state.filter = filter.dataset.filter;
			render();
			return;
		}

		var check = event.target.closest( '.bzn-check-now' );
		if ( check ) {
			event.preventDefault();
			checkNow( check );
		}
	} );

	// Enter in the search box would submit the fallback form.
	root.querySelector( '.bzn-layout' ).addEventListener( 'submit', function ( event ) {
		event.preventDefault();
	} );

	search.addEventListener( 'input', function () {
		state.query = search.value;
		render();
	} );

	window.addEventListener( 'hashchange', route );
	route();
} )();
