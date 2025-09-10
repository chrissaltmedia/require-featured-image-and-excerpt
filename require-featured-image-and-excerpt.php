<?php
/**
 * Plugin Name: Require Featured Image & Excerpt Before Publish
 * Description: Blocks publishing/scheduling of public post types unless BOTH a featured image and an excerpt are set. Includes a Gutenberg UI lock to avoid spinner loops.
 * Author: Salt Media LTD - Christopher Sheppard
 * Version: 1.2.0
 */

if ( ! defined('ABSPATH') ) { exit; }

/* ----------------------- helpers ----------------------- */

function sm_enforce_requirements_for_type( $post_type ) : bool {
	if ( in_array( $post_type, array( 'revision', 'attachment', 'nav_menu_item' ), true ) ) { return false; }
	$obj = get_post_type_object( $post_type );
	return $obj && ! empty( $obj->public );
}

function sm_is_attempting_publish_status( $status ) : bool {
	return in_array( (string) $status, array( 'publish', 'future' ), true );
}

function sm_missing_requirements_from_ids( int $post_id, string $excerpt = '' ) : array {
	$missing = array();

	$excerpt = trim( (string) $excerpt );
	if ( $excerpt === '' ) {
		$excerpt = trim( (string) get_post_field( 'post_excerpt', $post_id ) );
	}
	if ( $excerpt === '' ) {
		$missing[] = 'excerpt';
	}

	if ( ! get_post_thumbnail_id( $post_id ) ) {
		$missing[] = 'thumbnail';
	}

	return $missing;
}

function sm_missing_requirements_from_payload( array $data, array $postarr ) : array {
	$missing = array();

	// excerpt
	$excerpt = '';
	if ( isset( $data['post_excerpt'] ) ) {
		$excerpt = trim( (string) $data['post_excerpt'] );
	} elseif ( isset( $postarr['post_excerpt'] ) ) {
		$excerpt = trim( (string) $postarr['post_excerpt'] );
	}
	if ( $excerpt === '' ) { $missing[] = 'excerpt'; }

	// thumbnail
	$has_thumb = false;
	$post_id   = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
	if ( $post_id > 0 && get_post_thumbnail_id( $post_id ) ) { $has_thumb = true; }
	if ( ! $has_thumb ) {
		if ( isset( $postarr['meta_input']['_thumbnail_id'] ) && (int) $postarr['meta_input']['_thumbnail_id'] > 0 ) {
			$has_thumb = true;
		} elseif ( isset( $_POST['_thumbnail_id'] ) && (int) $_POST['_thumbnail_id'] > 0 ) {
			$has_thumb = true;
		}
	}
	if ( ! $has_thumb ) { $missing[] = 'thumbnail'; }

	return $missing;
}

function sm_requirements_message( array $missing ) : string {
	$need = array();
	if ( in_array( 'thumbnail', $missing, true ) ) { $need[] = 'a featured image'; }
	if ( in_array( 'excerpt',   $missing, true ) ) { $need[] = 'an excerpt'; }
	return (count($need) === 2)
		? 'Publishing blocked: please add a featured image and an excerpt.'
		: 'Publishing blocked: please add ' . $need[0] . '.';
}

/* ----------------------- Classic editor & non-REST saves -----------------------
   IMPORTANT: Never touch draft/autosave. Only intercept a true publish/schedule.
----------------------------------------------------------------------------- */

add_filter( 'wp_insert_post_data', function( $data, $postarr ) {

	// Only when trying to publish/schedule AND public type
	if ( empty($data['post_type']) || ! sm_enforce_requirements_for_type( $data['post_type'] ) ) { return $data; }
	if ( ! sm_is_attempting_publish_status( $data['post_status'] ) ) { return $data; }

	$missing = sm_missing_requirements_from_payload( $data, $postarr );
	if ( empty( $missing ) ) { return $data; }

	// Do NOT change status for autosaves/AJAX to avoid UI spinner loops
	if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) { return $data; }
	if ( function_exists('wp_doing_ajax') && wp_doing_ajax() ) { return $data; }

	// Force back to draft only on regular form submit (classic/post.php)
	$data['post_status'] = 'draft';

	// Add a notice on redirect
	add_filter( 'redirect_post_location', function( $location ) use ( $missing ) {
		return add_query_arg(
			array( 'sm_require_feat_excerpt' => implode( ',', $missing ) ),
			$location
		);
	}, 999 );

	return $data;

}, 10, 2 );

/* ----------------------- Admin notice after classic redirect ----------------------- */

add_action( 'admin_notices', function() {
	if ( empty( $_GET['sm_require_feat_excerpt'] ) ) { return; }
	$missing = explode( ',', sanitize_text_field( wp_unslash( $_GET['sm_require_feat_excerpt'] ) ) );
	echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sm_requirements_message( $missing ) ) . '</p></div>';
});

/* ----------------------- REST guard (Gutenberg + API) -----------------------
   Return a 412 (precondition failed) instead of 400; Gutenberg handles it
   cleanly and won’t spin. Never block drafts/autosaves.
----------------------------------------------------------------------------- */

function sm_rest_guard_requirements( $prepared_post, $request, $creating = null ) {
	$post_type = isset( $prepared_post->post_type ) ? $prepared_post->post_type : 'post';
	if ( ! sm_enforce_requirements_for_type( $post_type ) ) { return $prepared_post; }

	$status = isset( $prepared_post->post_status ) ? $prepared_post->post_status : '';
	if ( ! sm_is_attempting_publish_status( $status ) ) { return $prepared_post; }

	$post_id = (int) ( $prepared_post->ID ?? 0 );

	// Resolve excerpt & featured_media from REST payload if present
	$excerpt_in  = isset( $prepared_post->post_excerpt ) ? (string) $prepared_post->post_excerpt : '';
	$thumb_in_id = 0;
	if ( $request instanceof WP_REST_Request ) {
		$thumb_in_id = (int) ( $request->get_param( 'featured_media' ) ?: ( $request->get_param('meta')['_thumbnail_id'] ?? 0 ) );
	}

	// If creating with featured_media provided, treat as present
	if ( $thumb_in_id > 0 && ! $post_id ) {
		// fake presence through payload path
		$missing = array();
		if ( trim( $excerpt_in ) === '' ) { $missing[] = 'excerpt'; }
	} else {
		$missing = sm_missing_requirements_from_ids( $post_id, $excerpt_in );
	}

	if ( ! empty( $missing ) ) {
		return new WP_Error(
			'sm_require_feat_excerpt',
			sm_requirements_message( $missing ),
			array( 'status' => 412 )
		);
	}

	return $prepared_post;
}

add_filter( 'rest_pre_insert_post', 'sm_rest_guard_requirements', 10, 3 );
add_filter( 'rest_pre_insert_page', 'sm_rest_guard_requirements', 10, 3 );
add_action( 'init', function() {
	foreach ( get_post_types( array( 'public' => true ) ) as $type ) {
		if ( in_array( $type, array( 'post','page','attachment' ), true ) ) { continue; }
		add_filter( "rest_pre_insert_{$type}", 'sm_rest_guard_requirements', 10, 3 );
	}
});

/* ----------------------- Gutenberg UI lock (no spinner) -----------------------
   Locks Save/Publish buttons until BOTH fields exist. Shows a clear notice and
   a small “Checklist” card in the sidebar. No <script> tags in your snippets;
   this is enqueued properly as admin-only for the block editor.
----------------------------------------------------------------------------- */

add_action( 'enqueue_block_editor_assets', function() {
	$handle = 'sm-requirements-guard';
	wp_register_script( $handle, false, array( 'wp-data', 'wp-edit-post', 'wp-plugins', 'wp-element', 'wp-components', 'wp-notices' ), '1.2.0', true );

	$js = <<<JS
( function( wp ) {
	const { plugins }   = wp;
	const { PanelBody, Notice, Card, CardBody } = wp.components;
	const { createElement: h, Fragment } = wp.element;
	const { select, subscribe, dispatch } = wp.data;
	const notices = wp.notices;

	const LOCK_KEY = 'sm-requirements-guard';

	function hasFeaturedImage() {
		const mediaId = select('core/editor').getEditedPostAttribute('featured_media');
		return !!mediaId;
	}
	function hasExcerpt() {
		const ex = select('core/editor').getEditedPostAttribute('excerpt');
		return (ex || '').trim().length > 0;
	}
	function shouldLock() {
		const status = select('core/editor').getEditedPostAttribute('status');
		// Only lock publish/schedule attempts; let drafts save normally.
		if (status !== 'publish' && status !== 'future') return false;
		return !( hasFeaturedImage() && hasExcerpt() );
	}

	function updateLockUI() {
		if ( shouldLock() ) {
			dispatch('core/editor').lockPostSaving(LOCK_KEY);
			// Show a persistent notice once
			const existing = select('core/notices').getNotices().find(n => n.id === LOCK_KEY);
			if ( !existing ) {
				notices.createNotice(
					'error',
					'Publishing blocked: please add a featured image and an excerpt.',
					{ id: LOCK_KEY, isDismissible: true }
				);
			}
		} else {
			dispatch('core/editor').unlockPostSaving(LOCK_KEY);
			notices.removeNotice(LOCK_KEY);
		}
	}

	// Sidebar checklist panel
	const Checklist = () => {
		const okThumb = hasFeaturedImage();
		const okEx    = hasExcerpt();
		return h(Card, {},
			h(CardBody, {},
				h('div', { style: { lineHeight: '1.6' } },
					h('div', {}, (okThumb ? '✅' : '❌') + ' Featured image'),
					h('div', {}, (okEx ? '✅' : '❌') + ' Excerpt')
				)
			)
		);
	};

	const PluginSidebar = () => {
		return h(PanelBody, { title: 'Publish Requirements', initialOpen: true },
			h(Notice, { status: (hasFeaturedImage() && hasExcerpt()) ? 'success' : 'warning', isDismissible: false },
				(hasFeaturedImage() && hasExcerpt())
					? 'All good — you can publish.'
					: 'To publish, add BOTH a featured image and an excerpt.'
			),
			h(Checklist, {})
		);
	};

	plugins.registerPlugin('sm-requirements-guard', {
		render: () => h(Fragment, {}, h(PluginSidebar, {})),
		icon: null,
	});

	// React to changes
	const unsubscribe = subscribe( updateLockUI );
	// Run once on load
	updateLockUI();

} )( window.wp );
JS;

	wp_add_inline_script( $handle, $js );
	wp_enqueue_script( $handle );
});
