<?php
/**
 * Plugin Name: Require Featured Image & Excerpt Before Publish
 * Description: Prevent publishing/scheduling any public post type unless BOTH a featured image and an excerpt are set. Includes a stable Gutenberg UI lock.
 * Author: Salt Media LTD - Christopher Sheppard
 * Version: 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ---------------- Helpers ---------------- */

function sm_enforce_requirements_for_type( $post_type ) : bool {
	if ( in_array( $post_type, array( 'revision', 'attachment', 'nav_menu_item' ), true ) ) return false;
	$obj = get_post_type_object( $post_type );
	return $obj && ! empty( $obj->public );
}
function sm_is_attempting_publish_status( $status ) : bool {
	return in_array( (string) $status, array( 'publish', 'future' ), true );
}
function sm_missing_requirements_from_payload( array $data, array $postarr ) : array {
	$missing = array();

	$excerpt = '';
	if ( isset( $data['post_excerpt'] ) ) { $excerpt = trim( (string) $data['post_excerpt'] ); }
	elseif ( isset( $postarr['post_excerpt'] ) ) { $excerpt = trim( (string) $postarr['post_excerpt'] ); }
	if ( $excerpt === '' ) $missing[] = 'excerpt';

	$has_thumb = false;
	$post_id   = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
	if ( $post_id > 0 && get_post_thumbnail_id( $post_id ) ) $has_thumb = true;
	if ( ! $has_thumb ) {
		if ( isset( $postarr['meta_input']['_thumbnail_id'] ) && (int) $postarr['meta_input']['_thumbnail_id'] > 0 ) $has_thumb = true;
		elseif ( isset( $_POST['_thumbnail_id'] ) && (int) $_POST['_thumbnail_id'] > 0 ) $has_thumb = true;
	}
	if ( ! $has_thumb ) $missing[] = 'thumbnail';

	return $missing;
}
function sm_missing_requirements_from_ids( int $post_id, string $excerpt = '' ) : array {
	$missing = array();
	$ex = trim( $excerpt !== '' ? $excerpt : (string) get_post_field( 'post_excerpt', $post_id ) );
	if ( $ex === '' ) $missing[] = 'excerpt';
	if ( ! get_post_thumbnail_id( $post_id ) ) $missing[] = 'thumbnail';
	return $missing;
}
function sm_requirements_message( array $missing ) : string {
	$need = array();
	if ( in_array( 'thumbnail', $missing, true ) ) $need[] = 'a featured image';
	if ( in_array( 'excerpt',   $missing, true ) ) $need[] = 'an excerpt';
	return count( $need ) === 2
		? 'Publishing blocked: please add a featured image and an excerpt.'
		: 'Publishing blocked: please add ' . $need[0] . '.';
}

/* -------- Classic / non-REST (don’t touch drafts/autosaves) -------- */

add_filter( 'wp_insert_post_data', function( $data, $postarr ) {

	if ( empty( $data['post_type'] ) || ! sm_enforce_requirements_for_type( $data['post_type'] ) ) return $data;
	if ( ! sm_is_attempting_publish_status( $data['post_status'] ) ) return $data;

	$missing = sm_missing_requirements_from_payload( $data, $postarr );
	if ( empty( $missing ) ) return $data;

	// Avoid UI hangs during autosave/AJAX
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return $data;
	if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) return $data;

	$data['post_status'] = 'draft';
	add_filter( 'redirect_post_location', function( $location ) use ( $missing ) {
		return add_query_arg( array( 'sm_require_feat_excerpt' => implode( ',', $missing ) ), $location );
	}, 999 );

	return $data;
}, 10, 2 );

/* -------- Admin notice after classic redirect -------- */

add_action( 'admin_notices', function() {
	if ( empty( $_GET['sm_require_feat_excerpt'] ) ) return;
	$missing = explode( ',', sanitize_text_field( wp_unslash( $_GET['sm_require_feat_excerpt'] ) ) );
	echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sm_requirements_message( $missing ) ) . '</p></div>';
});

/* ---------------- REST guard (Gutenberg/API) ----------------
   Return 412 (precondition failed) only on publish/schedule.
-------------------------------------------------------------- */

function sm_rest_guard_requirements( $prepared_post, $request, $creating = null ) {
	$post_type = isset( $prepared_post->post_type ) ? $prepared_post->post_type : 'post';
	if ( ! sm_enforce_requirements_for_type( $post_type ) ) return $prepared_post;

	$status = isset( $prepared_post->post_status ) ? $prepared_post->post_status : '';
	if ( ! sm_is_attempting_publish_status( $status ) ) return $prepared_post;

	$post_id = (int) ( $prepared_post->ID ?? 0 );
	$excerpt_in  = isset( $prepared_post->post_excerpt ) ? (string) $prepared_post->post_excerpt : '';

	$thumb_in_id = 0;
	if ( $request instanceof WP_REST_Request ) {
		$meta = (array) $request->get_param( 'meta' );
		$thumb_in_id = (int) ( $request->get_param( 'featured_media' ) ?: ( $meta['_thumbnail_id'] ?? 0 ) );
	}

	$missing = ( $thumb_in_id > 0 && ! $post_id )
		? ( trim( $excerpt_in ) === '' ? array( 'excerpt' ) : array() )
		: sm_missing_requirements_from_ids( $post_id, $excerpt_in );

	if ( ! empty( $missing ) ) {
		return new WP_Error( 'sm_require_feat_excerpt', sm_requirements_message( $missing ), array( 'status' => 412 ) );
	}
	return $prepared_post;
}
add_filter( 'rest_pre_insert_post', 'sm_rest_guard_requirements', 10, 3 );
add_filter( 'rest_pre_insert_page', 'sm_rest_guard_requirements', 10, 3 );
add_action( 'init', function() {
	foreach ( get_post_types( array( 'public' => true ) ) as $t ) {
		if ( in_array( $t, array( 'post','page','attachment' ), true ) ) continue;
		add_filter( "rest_pre_insert_{$t}", 'sm_rest_guard_requirements', 10, 3 );
	}
});

/* ---------------- Gutenberg UI lock (stable) ----------------
   No <script> tags; properly enqueued admin script. Uses
   PluginDocumentSettingPanel and core/notices store only.
-------------------------------------------------------------- */

add_action( 'enqueue_block_editor_assets', function() {
	$handle = 'sm-requirements-guard';
	wp_register_script(
		$handle,
		false,
		array( 'wp-data', 'wp-edit-post', 'wp-plugins', 'wp-element', 'wp-components' ),
		'1.3.0',
		true
	);

	$js = <<<JS
( function( wp ) {
	// Guards for robustness
	if ( !wp || !wp.data || !wp.plugins || !wp.element || !wp.components || !wp.editPost ) { return; }

	const { registerPlugin } = wp.plugins;
	const { PluginDocumentSettingPanel } = wp.editPost;
	const { createElement: h, Fragment } = wp.element;
	const { Notice, Card, CardBody } = wp.components;
	const { select, subscribe, dispatch } = wp.data;

	const LOCK_KEY = 'sm-requirements-guard';
	const noticesDispatch = () => dispatch('core/notices');
	const noticesSelect   = () => select('core/notices');
	const editorSelect    = () => select('core/editor');
	const editorDispatch  = () => dispatch('core/editor');

	function safeGetEdited( key ) {
		const store = editorSelect();
		if ( !store ) return null;
		return store.getEditedPostAttribute ? store.getEditedPostAttribute( key ) : null;
	}

	function hasFeaturedImage() {
		const mediaId = safeGetEdited('featured_media');
		return !!mediaId;
	}
	function hasExcerpt() {
		const ex = safeGetEdited('excerpt') || '';
		return ex.trim().length > 0;
	}
	function isPublishingStatus() {
		const st = safeGetEdited('status');
		return st === 'publish' || st === 'future';
	}

	function shouldLock() {
		if ( !isPublishingStatus() ) return false; // never lock drafts
		return !( hasFeaturedImage() && hasExcerpt() );
	}

	function updateLockUI() {
		const ed = editorDispatch();
		if ( !ed ) return;

		if ( shouldLock() ) {
			ed.lockPostSaving(LOCK_KEY);
			// Show one persistent notice
			const exists = noticesSelect() && noticesSelect().getNotices
				? noticesSelect().getNotices().some( n => n.id === LOCK_KEY )
				: false;
			if ( !exists ) {
				noticesDispatch() && noticesDispatch().createNotice(
					'error',
					'Publishing blocked: please add a featured image and an excerpt.',
					{ id: LOCK_KEY, isDismissible: true }
				);
			}
		} else {
			ed.unlockPostSaving(LOCK_KEY);
			noticesDispatch() && noticesDispatch().removeNotice(LOCK_KEY);
		}
	}

	const ChecklistPanel = () => {
		const okThumb = hasFeaturedImage();
		const okEx    = hasExcerpt();
		const allGood = okThumb && okEx;
		return h(PluginDocumentSettingPanel, { name: 'sm-publish-reqs', title: 'Publish Requirements', initialOpen: true },
			h(Notice, { status: allGood ? 'success' : 'warning', isDismissible: false },
				allGood ? 'All good — you can publish.' : 'To publish, add BOTH a featured image and an excerpt.'
			),
			h(Card, {}, h(CardBody, {},
				h('div', { style: { lineHeight: '1.6' } },
					h('div', {}, (okThumb ? '✅' : '❌') + ' Featured image'),
					h('div', {}, (okEx ? '✅' : '❌') + ' Excerpt')
				)
			))
		);
	};

	registerPlugin('sm-requirements-guard', { render: () => h(Fragment, {}, h(ChecklistPanel)) });

	// React to editor changes
	const unsubscribe = subscribe( updateLockUI );
	updateLockUI();
} )( window.wp );
JS;

	wp_add_inline_script( $handle, $js );
	wp_enqueue_script( $handle );
});
