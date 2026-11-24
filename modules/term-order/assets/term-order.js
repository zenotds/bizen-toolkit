/* Drag and drop on the term list: rows move among their siblings, children travel with them. */
jQuery( function ( $ ) {
	var $list = $( '#the-list' );
	var children = {};
	var before = [];

	function level( $row ) {
		var match = /level-(\d+)/.exec( $row.attr( 'class' ) || '' );
		return match ? parseInt( match[ 1 ], 10 ) : 0;
	}

	function termId( $row ) {
		return parseInt( ( $row.attr( 'id' ) || '' ).replace( 'tag-', '' ), 10 );
	}

	// Delegated handlers run before the sortable's own mousedown: limit it to the grabbed row's siblings.
	$list.on( 'mousedown', '.bizen-term-order', function () {
		var parent = $( this ).data( 'parent' );

		$list.children( 'tr' ).each( function () {
			var $row = $( this );
			$row.toggleClass( 'bizen-term-order-sibling', $row.find( '.bizen-term-order' ).data( 'parent' ) === parent );
		} );
	} );

	$list.sortable( {
		items: '> tr.bizen-term-order-sibling',
		handle: '.bizen-term-order',
		axis: 'y',
		placeholder: 'bizen-term-order-placeholder',
		helper: function ( event, $row ) {
			$row.children().each( function () {
				$( this ).width( $( this ).width() );
			} );
			return $row;
		},
		start: function ( event, ui ) {
			ui.placeholder.html( '<td colspan="' + ui.item.children().length + '">&nbsp;</td>' );

			// Lift each sibling's subtree out, so the siblings sit next to each other while dragging.
			children = {};
			before = [];
			$list.children( 'tr.bizen-term-order-sibling' ).each( function () {
				var $row = $( this );
				var depth = level( $row );
				var sub = [];
				var $next = $row.next();

				while ( $next.length && ( $next.is( ui.placeholder ) || ( level( $next ) > depth && ! $next.hasClass( 'bizen-term-order-sibling' ) ) ) ) {
					if ( ! $next.is( ui.placeholder ) ) {
						sub.push( $next[ 0 ] );
					}
					$next = $next.next();
				}

				children[ termId( $row ) ] = $( sub ).detach();
				before.push( termId( $row ) );
			} );
			$list.sortable( 'refreshPositions' );
		},
		stop: function () {
			var $siblings = $list.children( 'tr.bizen-term-order-sibling' );
			var ids = [];

			$siblings.each( function () {
				var id = termId( $( this ) );
				$( this ).after( children[ id ] );
				ids.push( id );
			} );

			if ( ids.join() === before.join() ) {
				return;
			}

			$list.addClass( 'bizen-term-order-saving' );

			$.post( bizenTermOrder.ajaxUrl, {
				action: 'bizen_term_order',
				nonce: bizenTermOrder.nonce,
				taxonomy: bizenTermOrder.taxonomy,
				parent: $siblings.first().find( '.bizen-term-order' ).data( 'parent' ),
				ids: ids
			} ).done( function ( response ) {
				if ( ! response || ! response.success ) {
					window.location.reload();
					return;
				}
				$.each( response.data, function ( id, order ) {
					$( '#tag-' + id + ' .bizen-term-order-value' ).text( order );
				} );
				$list.removeClass( 'bizen-term-order-saving' );
			} ).fail( function () {
				window.location.reload();
			} );
		}
	} );
} );
