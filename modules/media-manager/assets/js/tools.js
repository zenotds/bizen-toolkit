/**
 * Media Manager — maintenance tools in the folder sidebar's "⋮" menu.
 *
 * The sidebar is a compiled bundle: its menu handler forwards the Tools submenu
 * keys here together with the bundle's own toast, so the feedback looks like the
 * rest of the sidebar. Also carries the fbv_data global the bundle reads.
 */
( function () {
	function post( path, body ) {
		return fetch( window.fbv_data.json_url + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': window.fbv_data.rest_nonce,
			},
			body: JSON.stringify( body || {} ),
		} ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( response.statusText );
			}
			return response.json();
		} );
	}

	/** The endpoint works through the library 50 attachments a page; walk it to the end. */
	function attachmentSizes( page ) {
		return post( '/generate-attachment-size', { page: page } ).then( function ( result ) {
			return result && result.next === '1' ? attachmentSizes( page + 1 ) : result;
		} );
	}

	var running = {};

	function run( key, task, messages, toast ) {
		if ( running[ key ] ) {
			return;
		}
		running[ key ] = true;

		var done = function () {
			running[ key ] = false;
		};
		var promise = task().then(
			function ( result ) {
				done();
				return result;
			},
			function ( error ) {
				done();
				throw error;
			}
		);

		if ( toast && typeof toast.promise === 'function' ) {
			toast.promise( promise, messages );
		}
		return promise;
	}

	window.bizenMediaManager = {
		tool: function ( key, toast ) {
			var i18n = window.fbv_data.i18n;

			if ( key === 'bizen_attachment_size' ) {
				run(
					key,
					function () {
						return attachmentSizes( 1 );
					},
					{
						loading: i18n.attachment_size_running,
						success: i18n.attachment_size_done,
						error: i18n.please_try_again,
					},
					toast
				);
			}

			if ( key === 'bizen_wpml_sync' ) {
				run(
					key,
					function () {
						return post( '/wpml-folder-sync' );
					},
					{
						loading: i18n.wpml_folder_sync_running,
						success: function ( result ) {
							return result.message;
						},
						error: i18n.please_try_again,
					},
					toast
				);
			}

			if ( key === 'bizen_clear_all' ) {
				// eslint-disable-next-line no-alert
				if ( ! window.confirm( i18n.clear_all_data_confirm ) ) {
					return;
				}
				run(
					key,
					function () {
						return post( '/fb-wipe-clear-all-data' );
					},
					{
						loading: i18n.loading,
						success: i18n.clear_all_data_done,
						error: i18n.please_try_again,
					},
					toast
				).then( function () {
					window.location.reload();
				} );
			}
		},
	};
} )();
