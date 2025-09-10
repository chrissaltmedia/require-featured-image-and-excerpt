<?php
/**
 * Plugin Name: Require Featured Image & Excerpt + Unspinner (Merged)
 * Description: Blocks publish/schedule unless BOTH a featured image and an excerpt exist (shows proper WP error messages) and removes stuck LoadingOverlay spinners on post editor screens (e.g., from Permalink Manager Pro).
 * Version: 2.0.0
 */

if ( ! defined('ABSPATH') ) exit;

/** Optional emergency kill switch. Add in wp-config.php: define('SM_REQ_OFF', true); */
if ( defined('SM_REQ_OFF') && SM_REQ_OFF ) return;

/* ----------------------- Helpers ----------------------- */

function sm_is_public_type( $type ) : bool {
	if ( ! $type ) return false;
	if ( in_array( $type, ['revision','attachment','nav_menu_item'], true ) ) return false;
	$o = get_post_type_object( $type );
	return $o && ! empty( $o->public );
}
function sm_is_publish_like( $status ) : bool {
	return in_array( (string) $status, ['publish','future'], true );
}
function sm_missing_from_ids( int $post_id, string $excerpt_in = '' ) : array {
	$missing = [];
	$ex = trim( $excerpt_in !== '' ? $excerpt_in : (string) get_post_field( 'post_excerpt', $post_id ) );
	if ( $ex === '' ) $missing[] = 'excerpt';
	if ( ! get_post_thumbnail_id( $post_id ) ) $missing[] = 'thumbnail';
	return $missing;
}
function sm_missing_from_payload( array $data, array $postarr ) : array {
	$missing = [];

	// Excerpt
	$ex = '';
	if ( isset( $data['post_excerpt'] ) )        $ex = trim( (string) $data['post_excerpt'] );
	elseif ( isset( $postarr['post_excerpt'] ) ) $ex = trim( (string) $postarr['post_excerpt'] );
	elseif ( ! empty( $postarr['ID'] ) )         $ex = trim( (string) get_post_field( 'post_excerpt', (int) $postarr['ID'] ) );
	if ( $ex === '' ) $missing[] = 'excerpt';

	// Featured image
	$has_thumb = false;
	$id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
	if ( $id && get_post_thumbnail_id( $id ) ) $has_thumb = true;
	if ( ! $has_thumb ) {
		if ( ! empty( $postarr['meta_input']['_thumbnail_id'] ) && (int) $postarr['meta_input']['_thumbnail_id'] > 0 ) $has_thumb = true;
		elseif ( ! empty( $_POST['_thumbnail_id'] ) && (int) $_POST['_thumbnail_id'] > 0 ) $has_thumb = true;
	}
	if ( ! $has_thumb ) $missing[] = 'thumbnail';

	return $missing;
}
function sm_msg( array $missing ) : string {
	if ( in_array( 'thumbnail', $missing, true ) && in_array( 'excerpt', $missing, true ) ) {
		return 'Publishing blocked: please add a featured image and an excerpt.';
	}
	if ( in_array( 'thumbnail', $missing, true ) ) return 'Publishing blocked: please add a featured image.';
	if ( in_array( 'excerpt',   $missing, true ) ) return 'Publishing blocked: please add an excerpt.';
	return 'Publishing blocked: required fields are missing.';
}

/* ---------------- Classic / Quick Edit path (no JS) ----------------
   - Only intercepts real publish/schedule.
   - Never interferes with drafts/autosaves/AJAX.
------------------------------------------------------------------- */

add_filter( 'wp_insert_post_data', function( $data, $postarr ) {

	// Skip autosaves/AJAX to avoid UI lockups.
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ) {
		return $data;
	}

	if ( empty( $data['post_type'] ) || ! sm_is_public_type( $data['post_type'] ) ) return $data;
	if ( ! sm_is_publish_like( $data['post_status'] ) ) return $data;

	$missing = sm_missing_from_payload( $data, $postarr );
	if ( empty( $missing ) ) return $data;

	// Flip back to draft and surface a notice after redirect.
	$data['post_status'] = 'draft';
	add_filter( 'redirect_post_location', function( $loc ) use ( $missing ) {
		return add_query_arg( [ 'sm_req_miss' => implode( ',', $missing ) ], $loc );
	}, 999 );

	return $data;

}, 10, 2 );

add_action( 'admin_notices', function() {
	if ( empty( $_GET['sm_req_miss'] ) ) return;
	$missing = explode( ',', sanitize_text_field( wp_unslash( $_GET['sm_req_miss'] ) ) );
	echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sm_msg( $missing ) ) . '</p></div>';
});

/* ---------------- Gutenberg / REST path (shows red error) ----------------
   - Return WP_Error (HTTP 412) on publish/schedule only.
   - Draft saves/autosaves are unaffected.
-------------------------------------------------------------------------- */

function sm_rest_guard( $prepared_post, $request, $creating = null ) {
	$post_type = isset( $prepared_post->post_type ) ? $prepared_post->post_type : 'post';
	if ( ! sm_is_public_type( $post_type ) ) return $prepared_post;

	$status = isset( $prepared_post->post_status ) ? $prepared_post->post_status : '';
	if ( ! sm_is_publish_like( $status ) ) return $prepared_post;

	$post_id    = (int) ( $prepared_post->ID ?? 0 );
	$excerpt_in = isset( $prepared_post->post_excerpt ) ? (string) $prepared_post->post_excerpt : '';

	$thumb_in_id = 0;
	if ( $request instanceof WP_REST_Request ) {
		$meta = (array) $request->get_param( 'meta' );
		$thumb_in_id = (int) ( $request->get_param( 'featured_media' ) ?: ( $meta['_thumbnail_id'] ?? 0 ) );
	}

	// If creating and a featured_media id is sent, treat thumbnail as present.
	$missing = ( $post_id === 0 && $thumb_in_id > 0 )
		? ( trim( $excerpt_in ) === '' ? [ 'excerpt' ] : [] )
		: sm_missing_from_ids( $post_id, $excerpt_in );

	if ( ! empty( $missing ) ) {
		return new WP_Error( 'sm_require_feat_excerpt', sm_msg( $missing ), [ 'status' => 412 ] );
	}
	return $prepared_post;
}
add_filter( 'rest_pre_insert_post', 'sm_rest_guard', 10, 3 );
add_filter( 'rest_pre_insert_page', 'sm_rest_guard', 10, 3 );
add_action( 'init', function() {
	foreach ( get_post_types( [ 'public' => true ] ) as $t ) {
		if ( in_array( $t, [ 'post','page','attachment' ], true ) ) continue;
		add_filter( "rest_pre_insert_{$t}", 'sm_rest_guard', 10, 3 );
	}
});

/* ---------------- Admin Unspinner (Permalink Manager Pro overlays) ----------------
   - Editor screens only (post.php / post-new.php).
   - Removes stuck .loadingoverlay and restores scrolling.
   - No effect on front-end.
----------------------------------------------------------------------------------- */

add_action( 'admin_enqueue_scripts', function( $hook ) {
	if ( $hook !== 'post.php' && $hook !== 'post-new.php' ) return;

	// Safety CSS: hide any overlay immediately.
	wp_register_style( 'sm-unspinner', false, [], '2.0.0' );
	wp_enqueue_style( 'sm-unspinner' );
	wp_add_inline_style( 'sm-unspinner', '.loadingoverlay{display:none!important;opacity:0!important;pointer-events:none!important;}' );

	// JS cleaner (uses jQuery if present; guards if not).
	wp_register_script( 'sm-unspinner', false, [ 'jquery' ], '2.0.0', true );
	wp_enqueue_script( 'sm-unspinner' );

	wp_add_inline_script( 'sm-unspinner', <<<JS
(function(w,d,$){
	'use strict';

	function unlockScroll(){
		try {
			['html','body'].forEach(function(sel){
				var el = d.querySelector(sel);
				if (!el) return;
				// Only reset if obviously locked
				if (el.style && (el.style.overflow === 'hidden' || el.style.overflowY === 'hidden')) {
					el.style.overflow = '';
					el.style.overflowY = '';
				}
			});
		} catch(e){}
	}

	function killOverlays(){
		var removed = false;
		try {
			var nodes = d.querySelectorAll('.loadingoverlay');
			for (var i=0; i<nodes.length; i++){
				var n = nodes[i];
				if (n && n.parentNode) { n.parentNode.removeChild(n); removed = true; }
			}
		} catch(e){}
		// Try library API if present
		try {
			if ($ && $.LoadingOverlay) { $.LoadingOverlay('hide', true); }
			if ($ && $('body').LoadingOverlay) { $('body').LoadingOverlay('hide', true); }
		} catch(e){}
		unlockScroll();
		return removed;
	}

	// Clean on ajax/fetch completion (handles REST 4xx/5xx).
	if ($ && $.ajaxSetup) {
		$(d).on('ajaxComplete ajaxError', function(){ killOverlays(); });
	}

	if (w.fetch && !w.fetch.__sm_unspinner__) {
		var _f = w.fetch;
		w.fetch = function(){
			return _f.apply(this, arguments)
				.then(function(r){ setTimeout(killOverlays, 0); return r; })
				.catch(function(e){ setTimeout(killOverlays, 0); throw e; });
		};
		w.fetch.__sm_unspinner__ = true;
	}

	// Mutation observer: nuke overlays if (re)added
	try {
		var mo = new MutationObserver(function(muts){
			for (var i=0;i<muts.length;i++){
				var m = muts[i];
				if (!m.addedNodes) continue;
				for (var j=0;j<m.addedNodes.length;j++){
					var n = m.addedNodes[j];
					if (!n) continue;
					if ((n.classList && n.classList.contains('loadingoverlay')) ||
					    (n.querySelector && n.querySelector('.loadingoverlay'))) {
						killOverlays();
						return;
					}
				}
			}
		});
		mo.observe(d.body || d.documentElement, { childList:true, subtree:true });
	} catch(e){}

	// Belt & braces
	var iv = setInterval(killOverlays, 1000);
	w.addEventListener('beforeunload', function(){ clearInterval(iv); }, { passive:true });

	// First pass
	killOverlays();

})(window, document, window.jQuery || null);
JS
	);
});
