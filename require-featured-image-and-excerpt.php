<?php
/**
 * Plugin Name: Require Featured Image & Excerpt (No-UI, No-REST)
 * Description: Blocks publish/schedule for public post types unless BOTH featured image and excerpt are set. Never interferes with drafts/autosaves/AJAX.
 * Version: 1.5.0
 */

if (!defined('ABSPATH')) exit;

/** Emergency kill switch (set define('SM_REQ_OFF', true) in wp-config.php to bypass). */
if (defined('SM_REQ_OFF') && SM_REQ_OFF) return;

function sm_req_is_public_type($type){
	// Keep this tiny and predictable.
	if (!$type) return false;
	if (in_array($type, ['revision','attachment','nav_menu_item'], true)) return false;
	$obj = get_post_type_object($type);
	return $obj && !empty($obj->public);
}
function sm_req_is_publish_like($status){
	return in_array((string)$status, ['publish','future'], true);
}
function sm_req_missing(array $data, array $postarr){
	$missing = [];

	// excerpt
	$ex = '';
	if (isset($data['post_excerpt'])) {
		$ex = trim((string)$data['post_excerpt']);
	} elseif (isset($postarr['post_excerpt'])) {
		$ex = trim((string)$postarr['post_excerpt']);
	} elseif (!empty($postarr['ID'])) {
		$ex = trim((string)get_post_field('post_excerpt', (int)$postarr['ID']));
	}
	if ($ex === '') $missing[] = 'excerpt';

	// featured image
	$has_thumb = false;
	$id = isset($postarr['ID']) ? (int)$postarr['ID'] : 0;
	if ($id && get_post_thumbnail_id($id)) $has_thumb = true;
	if (!$has_thumb) {
		if (!empty($postarr['meta_input']['_thumbnail_id']) && (int)$postarr['meta_input']['_thumbnail_id'] > 0) $has_thumb = true;
		elseif (!empty($_POST['_thumbnail_id']) && (int)$_POST['_thumbnail_id'] > 0) $has_thumb = true;
	}
	if (!$has_thumb) $missing[] = 'thumbnail';

	return $missing;
}
function sm_req_msg(array $missing){
	$need = [];
	if (in_array('thumbnail', $missing, true)) $need[] = 'a featured image';
	if (in_array('excerpt',   $missing, true)) $need[] = 'an excerpt';
	return count($need) === 2
		? 'Publishing blocked: please add a featured image and an excerpt.'
		: 'Publishing blocked: please add ' . $need[0] . '.';
}

add_filter('wp_insert_post_data', function($data, $postarr){

	// Never interfere with autosave/AJAX/background.
	if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || (function_exists('wp_doing_ajax') && wp_doing_ajax())) {
		return $data;
	}

	// Only act when really trying to publish/schedule a public type.
	if (empty($data['post_type']) || !sm_req_is_public_type($data['post_type'])) return $data;
	if (!sm_req_is_publish_like($data['post_status'])) return $data;

	$missing = sm_req_missing($data, $postarr);
	if (empty($missing)) return $data;

	// Flip back to draft (server-side).
	$data['post_status'] = 'draft';

	// Show a plain admin notice after redirect.
	add_filter('redirect_post_location', function($loc) use ($missing){
		return add_query_arg(['sm_req_miss' => implode(',', $missing)], $loc);
	}, 999);

	return $data;

}, 10, 2);

add_action('admin_notices', function(){
	if (empty($_GET['sm_req_miss'])) return;
	$missing = explode(',', sanitize_text_field(wp_unslash($_GET['sm_req_miss'])));
	echo '<div class="notice notice-error is-dismissible"><p>' . esc_html(sm_req_msg($missing)) . '</p></div>';
});
