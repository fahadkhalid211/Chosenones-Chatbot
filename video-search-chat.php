<?php
/**
 * Plugin Name: Video Search Chat
 * Description: Full-page AI-style semantic search chatbot for video libraries on Google Drive and WordPress Media Library. Search runs entirely in the visitor's browser — no API costs. Automatically indexes new Media Library uploads, trains on them, and answers questions. Integrates with Paid Memberships Pro: 1 free search every 24 hours for logged-in non-members, and 10 searches per day for monthly members with live countdown timers and subscription CTAs. Use the [video_search_chat] shortcode on any page.
 * Version: 1.4.0
 * Author: Fahad Khalid
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) {
    exit; // No direct access
}

define('VSC_VERSION', '1.4.0');
define('VSC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('VSC_PLUGIN_PATH', plugin_dir_path(__FILE__));

/* ─── Paid Memberships Pro Integration ───────────────────────────── */

/**
 * Check if a user has an active membership level in Paid Memberships Pro.
 *
 * @param int|null $user_id User ID or null for current user.
 * @param string|array|null $levels Specific PMPro level IDs/names (optional).
 * @return bool
 */
function vsc_is_user_active_member($user_id = null, $levels = null) {
    if (!$user_id) {
        $user_id = get_current_user_id();
    }

    if (!$user_id) {
        return false;
    }

    $is_member = false;

    // Check Paid Memberships Pro
    if (function_exists('pmpro_hasMembershipLevel')) {
        if (!empty($levels)) {
            if (is_string($levels)) {
                $levels = array_map('trim', explode(',', $levels));
            }
            $is_member = pmpro_hasMembershipLevel($levels, $user_id);
        } else {
            // NULL checks if user has ANY active PMPro level
            $is_member = pmpro_hasMembershipLevel(null, $user_id);
        }
    }

    return (bool) apply_filters('vsc_is_active_member', $is_member, $user_id);
}

/**
 * Retrieve the membership CTA URL (Paid Memberships Pro Levels page or custom fallback).
 *
 * @param string $custom_url Optional URL override from shortcode attribute.
 * @return string
 */
function vsc_get_membership_url($custom_url = '') {
    if (!empty($custom_url)) {
        return esc_url_raw($custom_url);
    }

    // Paid Memberships Pro levels page URL
    if (function_exists('pmpro_url')) {
        $levels_url = pmpro_url('levels');
        if (!empty($levels_url)) {
            return apply_filters('vsc_membership_url', $levels_url);
        }
    }

    // Default fallback
    $fallback = home_url('/membership-levels/');
    return apply_filters('vsc_membership_url', $fallback);
}

/**
 * Calculate search status for a user with a rolling 24-hour cycle.
 * Handles both 1 free search/day (non-members) and 10 searches/day (monthly members).
 *
 * @param int $user_id User ID.
 * @param int $allowed_limit Searches allowed per cycle (1 for non-members, 10 for members).
 * @param int $period_hours Hours before the daily search cycle resets (default: 24).
 * @return array
 */
function vsc_get_user_search_status($user_id, $allowed_limit = 1, $period_hours = 24) {
    $now = time();
    $window_seconds = max(1, $period_hours) * HOUR_IN_SECONDS;
    $cycle_start    = (int) get_user_meta($user_id, 'vsc_cycle_start_time', true);

    // Backward compatibility with previous version
    if (!$cycle_start) {
        $cycle_start = (int) get_user_meta($user_id, 'vsc_last_search_time', true);
    }

    $used_in_cycle = (int) get_user_meta($user_id, 'vsc_cycle_search_count', true);
    if (!$used_in_cycle && $cycle_start > 0) {
        $used_in_cycle = 1;
    }

    if ($cycle_start > 0 && ($now - $cycle_start) < $window_seconds) {
        $seconds_left  = $window_seconds - ($now - $cycle_start);
        $reset_ts      = $cycle_start + $window_seconds;
        $searches_left = max(0, $allowed_limit - $used_in_cycle);

        return [
            'cycle_active'         => true,
            'used_in_cycle'        => $used_in_cycle,
            'allowed_limit'        => $allowed_limit,
            'searches_left'        => $searches_left,
            'seconds_until_reset'  => $seconds_left,
            'reset_timestamp'      => $reset_ts,
            'reset_time_formatted' => wp_date(get_option('time_format'), $reset_ts),
        ];
    }

    // Cycle has expired or no cycle started yet
    return [
        'cycle_active'         => false,
        'used_in_cycle'        => 0,
        'allowed_limit'        => $allowed_limit,
        'searches_left'        => max(1, $allowed_limit),
        'seconds_until_reset'  => 0,
        'reset_timestamp'      => 0,
        'reset_time_formatted' => '',
    ];
}

/**
 * AJAX endpoint to record a search execution.
 * Enforces 1 search/day for non-members, 10 searches/day for monthly members.
 */
function vsc_ajax_record_search() {
    check_ajax_referer('vsc_search_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'User is not logged in.'], 403);
    }

    $user_id = get_current_user_id();

    // Site administrators can be granted unlimited searches via filter
    $is_admin = user_can($user_id, 'manage_options');
    if ($is_admin && apply_filters('vsc_admin_unlimited', true, $user_id)) {
        wp_send_json_success([
            'unlimited'            => true,
            'searches_left'        => -1,
            'used_in_cycle'        => 0,
            'allowed_limit'        => -1,
            'reset_timestamp'      => 0,
            'seconds_until_reset'  => 0,
            'reset_time_formatted' => '',
        ]);
    }

    $is_member     = vsc_is_user_active_member($user_id);
    $period_hours  = (int) apply_filters('vsc_reset_period_hours', 24, $user_id);
    $member_limit  = (int) apply_filters('vsc_member_searches_limit', 10, $user_id);
    $free_limit    = (int) apply_filters('vsc_free_searches_limit', 1, $user_id);
    $allowed_limit = $is_member ? $member_limit : $free_limit;

    $now            = time();
    $window_seconds = max(1, $period_hours) * HOUR_IN_SECONDS;
    $cycle_start    = (int) get_user_meta($user_id, 'vsc_cycle_start_time', true);
    if (!$cycle_start) {
        $cycle_start = (int) get_user_meta($user_id, 'vsc_last_search_time', true);
    }
    $used_in_cycle  = (int) get_user_meta($user_id, 'vsc_cycle_search_count', true);
    if (!$used_in_cycle && $cycle_start > 0) {
        $used_in_cycle = 1;
    }

    // Check if within existing cycle
    if ($cycle_start > 0 && ($now - $cycle_start) < $window_seconds) {
        if ($used_in_cycle >= $allowed_limit) {
            $remaining = $window_seconds - ($now - $cycle_start);
            $reset_ts  = $cycle_start + $window_seconds;
            wp_send_json_error([
                'message'              => 'Daily search limit reached.',
                'unlimited'            => false,
                'is_member'            => $is_member,
                'searches_left'        => 0,
                'used_in_cycle'        => $used_in_cycle,
                'allowed_limit'        => $allowed_limit,
                'seconds_until_reset'  => $remaining,
                'reset_timestamp'      => $reset_ts * 1000,
                'reset_time_formatted' => wp_date(get_option('time_format'), $reset_ts),
            ], 429);
        }
        $used_in_cycle++;
    } else {
        // Start new cycle
        $cycle_start   = $now;
        $used_in_cycle = 1;
        update_user_meta($user_id, 'vsc_cycle_start_time', $cycle_start);
    }

    update_user_meta($user_id, 'vsc_cycle_search_count', $used_in_cycle);
    update_user_meta($user_id, 'vsc_last_search_time', $now);

    $total_count = (int) get_user_meta($user_id, 'vsc_search_count', true);
    update_user_meta($user_id, 'vsc_search_count', $total_count + 1);

    $searches_left = max(0, $allowed_limit - $used_in_cycle);
    $reset_ts      = $cycle_start + $window_seconds;
    $remaining_sec = max(0, $reset_ts - $now);

    wp_send_json_success([
        'unlimited'            => false,
        'is_member'            => $is_member,
        'search_count'         => $total_count + 1,
        'used_in_cycle'        => $used_in_cycle,
        'allowed_limit'        => $allowed_limit,
        'searches_left'        => $searches_left,
        'seconds_until_reset'  => $remaining_sec,
        'reset_timestamp'      => $reset_ts * 1000,
        'reset_time_formatted' => wp_date(get_option('time_format'), $reset_ts),
    ]);
}
add_action('wp_ajax_vsc_record_search', 'vsc_ajax_record_search');

/* ─── Media Library Video Indexing & Auto-Training ───────────────── */

/**
 * Get filesystem path for media library videos JSON in uploads directory.
 */
function vsc_get_media_json_path() {
    $upload_dir = wp_upload_dir();
    $dir = trailingslashit($upload_dir['basedir']) . 'vsc-videos';
    if (!file_exists($dir)) {
        wp_mkdir_p($dir);
    }
    return trailingslashit($dir) . 'media-library-videos.json';
}

/**
 * Get public URL for media library videos JSON.
 */
function vsc_get_media_json_url() {
    $upload_dir = wp_upload_dir();
    $path = vsc_get_media_json_path();
    if (!file_exists($path)) {
        vsc_get_all_media_videos(true);
    }
    return trailingslashit($upload_dir['baseurl']) . 'vsc-videos/media-library-videos.json?v=' . (file_exists($path) ? filemtime($path) : time());
}

/**
 * Clean a video title for human readability and semantic relevance.
 */
function vsc_clean_video_title($title, $url = '') {
    $raw = !empty($title) ? trim($title) : basename($url);
    // Strip file extensions
    $clean = preg_replace('/\.(mp4|mov|webm|mkv|m4v|avi|ogv)$/i', '', $raw);

    // If machine filename like amatothementor_1735593678_7454318071726050606 or IMG_1234
    if (preg_match('/^([a-zA-Z]+)[-_]\d+[-_]\d+/i', $clean, $m)) {
        return ucwords($m[1]) . ' Video Lesson';
    }

    // Replace underscores and hyphens with spaces
    $clean = preg_replace('/[-_]+/', ' ', $clean);
    $clean = trim($clean);

    return !empty($clean) ? ucwords($clean) : 'Video';
}

/**
 * Retrieve all video attachments from WordPress Media Library.
 * Compiles Title, Context, Topics, Description, Caption, and Transcript into a rich knowledgebase.
 * Uses persistent transient caching for speed, updated automatically on changes.
 *
 * @param bool $force_refresh Whether to bypass transient and force a full database rescan.
 * @return array Array of indexed video items.
 */
function vsc_get_all_media_videos($force_refresh = false) {
    $transient_key = 'vsc_media_videos_catalog';
    if (!$force_refresh) {
        $cached = get_transient($transient_key);
        if (is_array($cached) && !empty($cached)) {
            return $cached;
        }
    }

    $path = vsc_get_media_json_path();
    $existing_data = [];

    if (file_exists($path)) {
        $decoded = json_decode(file_get_contents($path), true);
        if (is_array($decoded)) {
            foreach ($decoded as $item) {
                if (!empty($item['wpId'])) {
                    $existing_data[intval($item['wpId'])] = $item;
                }
            }
        }
    }

    // Query all video attachments regardless of status
    $args = [
        'post_type'      => 'attachment',
        'post_mime_type' => 'video',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ];
    $attachments = get_posts($args);

    // Fallback: Also look for attachments with video file extensions
    $video_exts = ['mp4', 'mov', 'webm', 'mkv', 'm4v'];
    $extra_args = [
        'post_type'      => 'attachment',
        'post_status'    => 'any',
        'posts_per_page' => 100,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ];
    $all_atts = get_posts($extra_args);
    $seen_ids = [];
    foreach ($attachments as $att) {
        $seen_ids[$att->ID] = true;
    }
    foreach ($all_atts as $att) {
        if (!isset($seen_ids[$att->ID])) {
            $att_url = wp_get_attachment_url($att->ID);
            $ext = strtolower(pathinfo($att_url, PATHINFO_EXTENSION));
            if (in_array($ext, $video_exts, true)) {
                $attachments[] = $att;
                $seen_ids[$att->ID] = true;
            }
        }
    }

    $updated_list = [];

    foreach ($attachments as $att) {
        $wp_id = $att->ID;
        $url   = wp_get_attachment_url($wp_id);
        if (!$url) continue;

        $thumb = '';
        $thumb_id = get_post_meta($wp_id, '_thumbnail_id', true);
        if ($thumb_id) {
            $thumb = wp_get_attachment_image_url($thumb_id, 'medium');
        }
        if (!$thumb) {
            $custom_thumb = get_post_meta($wp_id, '_vsc_thumb_url', true);
            if (!empty($custom_thumb)) {
                $thumb = $custom_thumb;
            }
        }
        if (!$thumb) {
            $img = wp_get_attachment_image_url($wp_id, 'medium');
            if (!$img) {
                $img = wp_get_attachment_thumb_url($wp_id);
            }
            if ($img && strpos($img, 'images/media/video.') === false) {
                $thumb = $img;
            }
        }

        // Clean, readable title
        $title = vsc_clean_video_title($att->post_title, $url);

        // Core context fields
        $caption     = trim($att->post_excerpt);
        $description = trim($att->post_content);
        $context     = trim(get_post_meta($wp_id, '_vsc_context', true));
        $topics      = trim(get_post_meta($wp_id, '_vsc_topics', true));
        $transcript  = trim(get_post_meta($wp_id, '_vsc_transcript', true));
        if (!$transcript) {
            $transcript = trim(get_post_meta($wp_id, 'transcript', true));
        }

        // Build rich semantic excerpt for AI understanding
        $excerpt_parts = [];
        if (!empty($title)) {
            $excerpt_parts[] = $title;
        }
        if (!empty($topics)) {
            $excerpt_parts[] = 'Topics: ' . $topics;
        }
        if (!empty($context)) {
            $excerpt_parts[] = 'Context: ' . $context;
        }
        if (!empty($transcript)) {
            $excerpt_parts[] = 'Transcript: ' . $transcript;
        }
        if (!empty($description)) {
            $excerpt_parts[] = $description;
        }
        if (!empty($caption)) {
            $excerpt_parts[] = $caption;
        }

        $clean_excerpt = !empty($excerpt_parts) ? wp_strip_all_tags(implode('. ', $excerpt_parts)) : $title;

        // Retrieve existing embedding if valid 384-dimensional vector
        $embedding = null;
        $meta_embed = get_post_meta($wp_id, '_vsc_embedding', true);
        if (is_array($meta_embed) && count($meta_embed) === 384) {
            $embedding = $meta_embed;
        } elseif (isset($existing_data[$wp_id]) && !empty($existing_data[$wp_id]['embedding']) && is_array($existing_data[$wp_id]['embedding']) && count($existing_data[$wp_id]['embedding']) === 384) {
            $embedding = $existing_data[$wp_id]['embedding'];
            update_post_meta($wp_id, '_vsc_embedding', $embedding);
        }

        $updated_list[] = [
            'id'         => 'wp_' . $wp_id,
            'wpId'       => $wp_id,
            'title'      => $title,
            'context'    => $context,
            'topics'     => $topics,
            'transcript' => $transcript,
            'excerpt'    => $clean_excerpt,
            'videoUrl'   => $url,
            'thumbUrl'   => $thumb ?: '',
            'source'     => 'media_library',
            'date'       => $att->post_date,
            'mime'       => $att->post_mime_type ?: 'video/mp4',
            'embedding'  => $embedding,
        ];
    }

    // Cache in transient for fast subsequent loads (12 hours)
    set_transient($transient_key, $updated_list, 12 * HOUR_IN_SECONDS);

    // Also update uploads JSON file as fallback
    file_put_contents($path, json_encode($updated_list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return $updated_list;
}

/**
 * Resync media library videos and refresh catalog cache.
 */
function vsc_sync_media_library_videos() {
    return vsc_get_all_media_videos(true);
}

/**
 * Invalidate cached media catalog when attachments change.
 */
function vsc_invalidate_media_catalog($attachment_id = 0) {
    delete_transient('vsc_media_videos_catalog');
    vsc_get_all_media_videos(true);
}
add_action('add_attachment', 'vsc_invalidate_media_catalog');
add_action('edit_attachment', 'vsc_invalidate_media_catalog');
add_action('attachment_updated', 'vsc_invalidate_media_catalog');
add_action('wp_update_attachment_metadata', 'vsc_invalidate_media_catalog');
add_action('delete_attachment', 'vsc_invalidate_media_catalog');

/**
 * Add custom fields to standard WordPress Media Library Edit screen.
 */
function vsc_attachment_fields_to_edit($form_fields, $post) {
    if (!wp_attachment_is('video', $post)) {
        return $form_fields;
    }

    $topics     = get_post_meta($post->ID, '_vsc_topics', true);
    $context    = get_post_meta($post->ID, '_vsc_context', true);
    $transcript = get_post_meta($post->ID, '_vsc_transcript', true);

    $form_fields['vsc_topics'] = [
        'label' => 'Chatbot Topics',
        'input' => 'html',
        'html'  => '<input type="text" class="widefat" name="attachments[' . $post->ID . '][vsc_topics]" value="' . esc_attr($topics) . '" placeholder="e.g. startup, seed funding, majority equity, failure">',
        'helps' => 'Keywords and topics the AI chatbot uses to understand what this video teaches.',
    ];

    $form_fields['vsc_context'] = [
        'label' => 'Chatbot Context & Summary',
        'input' => 'html',
        'html'  => '<textarea class="widefat" rows="3" name="attachments[' . $post->ID . '][vsc_context]" placeholder="What is discussed in this video? Provide key takeaways, questions answered, etc.">' . esc_textarea($context) . '</textarea>',
        'helps' => 'Summary of what Amato explains in this video so the chatbot can match contextual questions.',
    ];

    $form_fields['vsc_transcript'] = [
        'label' => 'Video Transcript',
        'input' => 'html',
        'html'  => '<textarea class="widefat" rows="4" name="attachments[' . $post->ID . '][vsc_transcript]" placeholder="Full or partial spoken transcript...">' . esc_textarea($transcript) . '</textarea>',
        'helps' => 'Spoken words for deep AI semantic matching.',
    ];

    return $form_fields;
}
add_filter('attachment_fields_to_edit', 'vsc_attachment_fields_to_edit', 10, 2);

function vsc_attachment_fields_to_save($post, $attachment) {
    if (isset($attachment['vsc_topics'])) {
        update_post_meta($post['ID'], '_vsc_topics', sanitize_text_field($attachment['vsc_topics']));
    }
    if (isset($attachment['vsc_context'])) {
        update_post_meta($post['ID'], '_vsc_context', wp_kses_post($attachment['vsc_context']));
    }
    if (isset($attachment['vsc_transcript'])) {
        update_post_meta($post['ID'], '_vsc_transcript', wp_kses_post($attachment['vsc_transcript']));
    }
    vsc_invalidate_media_catalog($post['ID']);
    return $post;
}
add_filter('attachment_fields_to_save', 'vsc_attachment_fields_to_save', 10, 2);

/**
 * AJAX endpoint: Return all Media Library videos as JSON.
 */
function vsc_ajax_get_media_videos() {
    $force = !empty($_GET['force']) || !empty($_POST['force']);
    $videos = vsc_get_all_media_videos($force);
    wp_send_json_success([
        'videos' => $videos,
        'count'  => count($videos),
    ]);
}
add_action('wp_ajax_vsc_get_media_videos', 'vsc_ajax_get_media_videos');
add_action('wp_ajax_nopriv_vsc_get_media_videos', 'vsc_ajax_get_media_videos');

/**
 * AJAX endpoint: Save Title, Context, Topics, and Transcript for a single video.
 */
function vsc_ajax_save_video_context() {
    check_ajax_referer('vsc_admin_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied'], 403);
    }

    $wp_id = isset($_POST['wp_id']) ? intval($_POST['wp_id']) : 0;
    if (!$wp_id) {
        wp_send_json_error(['message' => 'Invalid video ID']);
    }

    $title      = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
    $topics     = isset($_POST['topics']) ? sanitize_text_field(wp_unslash($_POST['topics'])) : '';
    $context    = isset($_POST['context']) ? wp_kses_post(wp_unslash($_POST['context'])) : '';
    $transcript = isset($_POST['transcript']) ? wp_kses_post(wp_unslash($_POST['transcript'])) : '';
    $thumb_url  = isset($_POST['thumb_url']) ? esc_url_raw(wp_unslash($_POST['thumb_url'])) : '';

    if (!empty($title)) {
        wp_update_post([
            'ID'         => $wp_id,
            'post_title' => $title,
        ]);
    }

    update_post_meta($wp_id, '_vsc_topics', $topics);
    update_post_meta($wp_id, '_vsc_context', $context);
    update_post_meta($wp_id, '_vsc_transcript', $transcript);
    update_post_meta($wp_id, '_vsc_thumb_url', $thumb_url);

    // Save embedding if provided
    if (!empty($_POST['embedding'])) {
        $embedding = json_decode(wp_unslash($_POST['embedding']), true);
        if (is_array($embedding) && count($embedding) === 384) {
            update_post_meta($wp_id, '_vsc_embedding', $embedding);
        }
    }

    vsc_invalidate_media_catalog($wp_id);
    $videos = vsc_get_all_media_videos(true);

    $updated_item = null;
    foreach ($videos as $v) {
        if ($v['wpId'] === $wp_id) {
            $updated_item = $v;
            break;
        }
    }

    wp_send_json_success([
        'video'   => $updated_item,
        'message' => 'Video context and knowledge saved successfully.',
    ]);
}
add_action('wp_ajax_vsc_save_video_context', 'vsc_ajax_save_video_context');

/**
 * AJAX endpoint to save embeddings generated by the browser for Media Library videos.
 */
function vsc_ajax_save_media_embeddings() {
    $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : '';
    $valid_search = wp_verify_nonce($nonce, 'vsc_search_nonce');
    $valid_admin  = wp_verify_nonce($nonce, 'vsc_admin_nonce');

    if (!$valid_search && !$valid_admin) {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Invalid nonce or permission denied'], 403);
        }
    }

    $raw = isset($_POST['items']) ? wp_unslash($_POST['items']) : '';
    $items = json_decode($raw, true);

    if (!is_array($items) || empty($items)) {
        wp_send_json_error(['message' => 'No items provided']);
    }

    $map = [];
    foreach ($items as $it) {
        if (!empty($it['wpId']) && !empty($it['embedding']) && is_array($it['embedding']) && count($it['embedding']) === 384) {
            $wp_id = intval($it['wpId']);
            $map[$wp_id] = $it['embedding'];
            update_post_meta($wp_id, '_vsc_embedding', $it['embedding']);
        }
    }

    vsc_invalidate_media_catalog();
    wp_send_json_success(['saved_count' => count($map)]);
}
add_action('wp_ajax_vsc_save_media_embeddings', 'vsc_ajax_save_media_embeddings');
add_action('wp_ajax_nopriv_vsc_save_media_embeddings', 'vsc_ajax_save_media_embeddings');

/**
 * AJAX endpoint to trigger a full Media Library resync from the Admin Dashboard.
 */
function vsc_ajax_sync_media_videos() {
    check_ajax_referer('vsc_admin_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied'], 403);
    }

    $videos = vsc_get_all_media_videos(true);
    wp_send_json_success([
        'videos' => $videos,
        'count'  => count($videos),
    ]);
}
add_action('wp_ajax_vsc_sync_media_videos', 'vsc_ajax_sync_media_videos');

/* ─── Admin Dashboard Page: Media Library Video Knowledgebase ─────── */

/**
 * Add submenu under Media in WordPress Admin.
 */
function vsc_admin_menu() {
    add_submenu_page(
        'upload.php',
        'Video Search Chat — Knowledgebase',
        'Video Search Chat',
        'manage_options',
        'video-search-chat',
        'vsc_admin_dashboard_page'
    );
}
add_action('admin_menu', 'vsc_admin_menu');

/**
 * Render Admin Dashboard Page for Media Library Video Management.
 */
function vsc_admin_dashboard_page() {
    $videos = vsc_get_all_media_videos(true);
    $total_media = count($videos);
    $trained_count = 0;
    foreach ($videos as $v) {
        if (!empty($v['embedding'])) {
            $trained_count++;
        }
    }
    $untrained_count = $total_media - $trained_count;
    $nonce = wp_create_nonce('vsc_admin_nonce');
    $search_nonce = wp_create_nonce('vsc_search_nonce');
    ?>
    <div class="wrap vsc-admin-wrap">
        <h1 class="vsc-admin-heading">
            <span class="dashicons dashicons-format-video"></span> Video Search Chat Knowledgebase &amp; Training
        </h1>
        <p class="vsc-admin-sub">
            Manage your video knowledgebase. The AI chatbot automatically learns from video titles, topics, context, and transcripts to provide intelligent answers to user questions.
        </p>

        <!-- Stats Cards -->
        <div class="vsc-stats-grid">
            <div class="vsc-stat-card">
                <div class="vsc-stat-num"><?php echo esc_html($total_media); ?></div>
                <div class="vsc-stat-label">Media Library Videos</div>
            </div>
            <div class="vsc-stat-card">
                <div class="vsc-stat-num vsc-text-green" id="vsc-stat-trained"><?php echo esc_html($trained_count); ?></div>
                <div class="vsc-stat-label">Trained &amp; Searchable</div>
            </div>
            <div class="vsc-stat-card">
                <div class="vsc-stat-num <?php echo $untrained_count > 0 ? 'vsc-text-orange' : ''; ?>" id="vsc-stat-untrained">
                    <?php echo esc_html($untrained_count); ?>
                </div>
                <div class="vsc-stat-label">Needing Training</div>
            </div>
            <div class="vsc-stat-card">
                <div class="vsc-stat-num">665</div>
                <div class="vsc-stat-label">Google Drive Archive</div>
            </div>
        </div>

        <!-- Sync & Training Actions -->
        <div class="vsc-action-box">
            <h2>⚡ AI Training &amp; Video Knowledgebase</h2>
            <p>
                When you add new videos in <strong>Media &rarr; Add New</strong>, they are automatically detected here. 
                You can add context and topics to each video below, or click <strong>Scan &amp; Train on All Videos</strong> to generate 384-dimensional vector embeddings so the chatbot understands them immediately.
            </p>
            <div class="vsc-btn-row">
                <button type="button" id="vsc-start-training" class="button button-primary button-hero">
                    ⚡ Scan &amp; Train on All Media Library Videos
                </button>
            </div>
            <div id="vsc-progress-wrap" style="display: none; margin-top: 15px;">
                <div class="vsc-progress-bar-bg">
                    <div id="vsc-progress-bar" style="width: 0%;"></div>
                </div>
                <p id="vsc-progress-status" style="margin-top: 8px; font-weight: 600; color: #580758;"></p>
            </div>
        </div>

        <!-- Video Catalog Table -->
        <div class="vsc-table-wrap">
            <h2>Videos in WordPress Media Library (<?php echo esc_html($total_media); ?>)</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 80px;">Thumbnail</th>
                        <th style="width: 28%;">Video Title</th>
                        <th>Context &amp; Key Topics</th>
                        <th style="width: 140px;">Training Status</th>
                        <th style="width: 170px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="vsc-video-tbody">
                    <?php if (empty($videos)) : ?>
                        <tr><td colspan="5">No video attachments found in your Media Library yet. Upload an MP4 video in Media &rarr; Add New!</td></tr>
                    <?php else : ?>
                        <?php foreach ($videos as $v) : ?>
                            <tr id="vsc-row-<?php echo esc_attr($v['wpId']); ?>"
                                data-id="<?php echo esc_attr($v['wpId']); ?>"
                                data-title="<?php echo esc_attr($v['title']); ?>"
                                data-thumb="<?php echo esc_attr($v['thumbUrl'] ?? ''); ?>"
                                data-topics="<?php echo esc_attr($v['topics'] ?? ''); ?>"
                                data-context="<?php echo esc_attr($v['context'] ?? ''); ?>"
                                data-transcript="<?php echo esc_attr($v['transcript'] ?? ''); ?>">
                                <td>
                                    <?php if (!empty($v['thumbUrl'])) : ?>
                                        <img src="<?php echo esc_url($v['thumbUrl']); ?>" style="width: 70px; height: 45px; object-fit: cover; border-radius: 4px;" alt="">
                                    <?php elseif (!empty($v['videoUrl'])) : ?>
                                        <video src="<?php echo esc_url($v['videoUrl']); ?>#t=0.5" style="width: 70px; height: 45px; object-fit: cover; border-radius: 4px; pointer-events: none;" preload="metadata" muted playsinline></video>
                                    <?php else : ?>
                                        <div style="width: 70px; height: 45px; background: #eee; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 20px;">🎬</div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong class="vsc-title-display"><?php echo esc_html($v['title']); ?></strong><br>
                                    <small><a href="<?php echo esc_url($v['videoUrl']); ?>" target="_blank">View Video File</a> | <a href="<?php echo esc_url(get_edit_post_link($v['wpId'])); ?>">WP Edit Screen</a></small>
                                </td>
                                <td>
                                    <?php if (!empty($v['topics'])) : ?>
                                        <div style="margin-bottom: 4px;">
                                            <span style="font-size: 11px; background: #f3e8f3; color: #720971; padding: 2px 7px; border-radius: 10px; font-weight: 600;">
                                                🏷️ <?php echo esc_html($v['topics']); ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                    <div class="vsc-excerpt-display" style="font-size: 13px; color: #444; line-height: 1.4;">
                                        <?php echo esc_html(mb_strimwidth($v['excerpt'], 0, 140, '...')); ?>
                                    </div>
                                </td>
                                <td class="vsc-status-cell">
                                    <?php if (!empty($v['embedding'])) : ?>
                                        <span class="vsc-badge vsc-badge-trained">✓ Trained &amp; Ready</span>
                                    <?php else : ?>
                                        <span class="vsc-badge vsc-badge-untrained">⚡ Needs Training</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button type="button" class="button button-secondary vsc-btn-edit-context" data-id="<?php echo esc_attr($v['wpId']); ?>">
                                        ✏️ Edit Context
                                    </button>
                                    <button type="button" class="button button-small vsc-btn-single-train" data-id="<?php echo esc_attr($v['wpId']); ?>" style="margin-top: 4px;">
                                        ⚡ Train
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Edit Context Modal -->
    <div id="vsc-edit-modal" style="display: none; position: fixed; inset: 0; z-index: 100000; background: rgba(0,0,0,0.6); align-items: center; justify-content: center; padding: 20px;">
        <div style="background: #fff; width: 100%; max-width: 580px; border-radius: 12px; padding: 26px; box-shadow: 0 20px 40px rgba(0,0,0,0.25); position: relative; max-height: 90vh; overflow-y: auto;">
            <button type="button" id="vsc-modal-close-btn" style="position: absolute; top: 16px; right: 16px; border: none; background: #eee; border-radius: 50%; width: 32px; height: 32px; font-size: 18px; cursor: pointer; display: flex; align-items: center; justify-content: center;">&times;</button>
            <h2 style="margin-top: 0; color: #580758; font-size: 20px;">✏️ Edit Video Context &amp; Knowledge</h2>
            <p style="font-size: 13px; color: #666; margin-bottom: 18px;">
                Tell the chatbot what this video is about. Adding topics and key context helps the AI accurately understand and return this video when visitors ask questions.
            </p>
            <input type="hidden" id="vsc-modal-wpid" value="">

            <div style="margin-bottom: 14px;">
            <div style="margin-bottom: 14px;">
                <label style="display: block; font-weight: 600; margin-bottom: 5px; font-size: 13px;">Video Title</label>
                <input type="text" id="vsc-modal-title" class="widefat" style="padding: 8px 12px; border-radius: 6px; font-size: 14px;">
            </div>

            <div style="margin-bottom: 14px;">
                <label style="display: block; font-weight: 600; margin-bottom: 5px; font-size: 13px;">Thumbnail / Poster Image URL (optional)</label>
                <input type="url" id="vsc-modal-thumb" class="widefat" placeholder="https://... (defaults to automatic video frame)" style="padding: 8px 12px; border-radius: 6px; font-size: 13px;">
                <small style="color: #777;">Leave empty to automatically display the first frame of the video.</small>
            </div>

            <div style="margin-bottom: 14px;">
                <label style="display: block; font-weight: 600; margin-bottom: 5px; font-size: 13px;">Topics &amp; Keywords (comma-separated)</label>
                <input type="text" id="vsc-modal-topics" class="widefat" placeholder="e.g. startup, seed capital, majority ownership, investor negotiation" style="padding: 8px 12px; border-radius: 6px; font-size: 13px;">
                <small style="color: #777;">Keywords that users might ask about this video.</small>
            </div>

            <div style="margin-bottom: 14px;">
                <label style="display: block; font-weight: 600; margin-bottom: 5px; font-size: 13px;">Context &amp; Summary (What Amato explains)</label>
                <textarea id="vsc-modal-context" class="widefat" rows="3" placeholder="Provide a summary of the advice, questions answered, and core message in this video..." style="padding: 8px 12px; border-radius: 6px; font-size: 13px;"></textarea>
            </div>

            <div style="margin-bottom: 18px;">
                <label style="display: block; font-weight: 600; margin-bottom: 5px; font-size: 13px;">Spoken Transcript (optional)</label>
                <textarea id="vsc-modal-transcript" class="widefat" rows="3" placeholder="Full or partial spoken transcript for deep word-for-word matching..." style="padding: 8px 12px; border-radius: 6px; font-size: 13px;"></textarea>
            </div>

            <div style="display: flex; gap: 10px; align-items: center; justify-content: flex-end;">
                <button type="button" id="vsc-modal-cancel" class="button">Cancel</button>
                <button type="button" id="vsc-modal-save" class="button button-primary" style="background: #720971; border-color: #580758; font-weight: 600; padding: 6px 18px;">
                    ⚡ Save &amp; Train Video
                </button>
            </div>
            <p id="vsc-modal-status" style="margin-top: 10px; font-size: 13px; font-weight: 600; text-align: right; display: none;"></p>
        </div>
    </div>

    <!-- Admin Training Script using Transformers.js -->
    <script>
    (function() {
        var ajaxUrl = "<?php echo esc_url(admin_url('admin-ajax.php')); ?>";
        var adminNonce = "<?php echo esc_js($nonce); ?>";
        var searchNonce = "<?php echo esc_js($search_nonce); ?>";
        var trainBtn = document.getElementById("vsc-start-training");
        var progWrap = document.getElementById("vsc-progress-wrap");
        var progBar = document.getElementById("vsc-progress-bar");
        var progStatus = document.getElementById("vsc-progress-status");
        var editModal = document.getElementById("vsc-edit-modal");
        var globalExtractor = null;

        function getExtractor() {
            if (globalExtractor) return Promise.resolve(globalExtractor);
            return import("https://cdn.jsdelivr.net/npm/@xenova/transformers@2.17.2")
                .then(function(mod) {
                    return mod.pipeline("feature-extraction", "Xenova/all-MiniLM-L6-v2");
                })
                .then(function(ext) {
                    globalExtractor = ext;
                    return ext;
                });
        }

        // Modal Open / Close
        document.querySelectorAll(".vsc-btn-edit-context").forEach(function(btn) {
            btn.addEventListener("click", function() {
                var wpId = this.getAttribute("data-id");
                var row = document.getElementById("vsc-row-" + wpId);
                if (!row) return;

                document.getElementById("vsc-modal-wpid").value = wpId;
                document.getElementById("vsc-modal-title").value = row.getAttribute("data-title") || "";
                document.getElementById("vsc-modal-thumb").value = row.getAttribute("data-thumb") || "";
                document.getElementById("vsc-modal-topics").value = row.getAttribute("data-topics") || "";
                document.getElementById("vsc-modal-context").value = row.getAttribute("data-context") || "";
                document.getElementById("vsc-modal-transcript").value = row.getAttribute("data-transcript") || "";
                document.getElementById("vsc-modal-status").style.display = "none";

                editModal.style.display = "flex";
            });
        });

        function closeModal() {
            editModal.style.display = "none";
        }
        document.getElementById("vsc-modal-close-btn").addEventListener("click", closeModal);
        document.getElementById("vsc-modal-cancel").addEventListener("click", closeModal);

        // Modal Save & Train
        document.getElementById("vsc-modal-save").addEventListener("click", function() {
            var saveBtn = this;
            var wpId = document.getElementById("vsc-modal-wpid").value;
            var title = document.getElementById("vsc-modal-title").value.trim();
            var thumbUrl = document.getElementById("vsc-modal-thumb").value.trim();
            var topics = document.getElementById("vsc-modal-topics").value.trim();
            var context = document.getElementById("vsc-modal-context").value.trim();
            var transcript = document.getElementById("vsc-modal-transcript").value.trim();
            var statusEl = document.getElementById("vsc-modal-status");

            saveBtn.disabled = true;
            statusEl.style.display = "block";
            statusEl.style.color = "#720971";
            statusEl.textContent = "Generating AI Embedding...";

            // Rich text for embedding
            var fullText = [title, topics ? "Topics: " + topics : "", context ? "Context: " + context : "", transcript ? "Transcript: " + transcript : ""]
                .filter(Boolean)
                .join(". ");

            getExtractor().then(function(extractor) {
                return extractor(fullText, { pooling: "mean", normalize: true });
            }).then(function(out) {
                var embedding = Array.from(out.data);
                statusEl.textContent = "Saving to WordPress...";

                var params = new URLSearchParams();
                params.append("action", "vsc_save_video_context");
                params.append("nonce", adminNonce);
                params.append("wp_id", wpId);
                params.append("title", title);
                params.append("thumb_url", thumbUrl);
                params.append("topics", topics);
                params.append("context", context);
                params.append("transcript", transcript);
                params.append("embedding", JSON.stringify(embedding));

                return fetch(ajaxUrl, {
                    method: "POST",
                    headers: { "Content-Type": "application/x-www-form-urlencoded" },
                    body: params.toString()
                }).then(function(res) { return res.json(); });
            }).then(function(data) {
                if (data && data.success) {
                    statusEl.style.color = "#108a38";
                    statusEl.textContent = "✓ Successfully saved & trained!";
                    setTimeout(function() {
                        closeModal();
                        location.reload();
                    }, 800);
                } else {
                    throw new Error(data && data.data && data.data.message ? data.data.message : "Save failed");
                }
            }).catch(function(err) {
                statusEl.style.color = "#dc2626";
                statusEl.textContent = "Error: " + err.message;
                saveBtn.disabled = false;
            });
        });

        // Single Train Button
        document.querySelectorAll(".vsc-btn-single-train").forEach(function(btn) {
            btn.addEventListener("click", function() {
                var trainSingleBtn = this;
                var wpId = trainSingleBtn.getAttribute("data-id");
                var row = document.getElementById("vsc-row-" + wpId);
                if (!row) return;

                trainSingleBtn.disabled = true;
                trainSingleBtn.textContent = "Training...";

                var title = row.getAttribute("data-title") || "";
                var topics = row.getAttribute("data-topics") || "";
                var context = row.getAttribute("data-context") || "";
                var transcript = row.getAttribute("data-transcript") || "";

                var fullText = [title, topics ? "Topics: " + topics : "", context ? "Context: " + context : "", transcript ? "Transcript: " + transcript : ""]
                    .filter(Boolean)
                    .join(". ");

                getExtractor().then(function(extractor) {
                    return extractor(fullText, { pooling: "mean", normalize: true });
                }).then(function(out) {
                    var embedding = Array.from(out.data);
                    var saveParams = new URLSearchParams();
                    saveParams.append("action", "vsc_save_media_embeddings");
                    saveParams.append("nonce", searchNonce);
                    saveParams.append("items", JSON.stringify([{ wpId: parseInt(wpId, 10), embedding: embedding }]));

                    return fetch(ajaxUrl, {
                        method: "POST",
                        headers: { "Content-Type": "application/x-www-form-urlencoded" },
                        body: saveParams.toString()
                    }).then(function(res) { return res.json(); });
                }).then(function() {
                    trainSingleBtn.textContent = "✓ Done";
                    var statusCell = row.querySelector(".vsc-status-cell");
                    if (statusCell) {
                        statusCell.innerHTML = '<span class="vsc-badge vsc-badge-trained">✓ Trained &amp; Ready</span>';
                    }
                }).catch(function(err) {
                    alert("Training error: " + err.message);
                    trainSingleBtn.disabled = false;
                    trainSingleBtn.textContent = "⚡ Train";
                });
            });
        });

        // Bulk Scan & Train All Videos
        trainBtn.addEventListener("click", function() {
            trainBtn.disabled = true;
            progWrap.style.display = "block";
            progBar.style.width = "5%";
            progStatus.textContent = "Scanning Media Library videos...";

            var params = new URLSearchParams();
            params.append("action", "vsc_sync_media_videos");
            params.append("nonce", adminNonce);

            fetch(ajaxUrl, {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: params.toString()
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!data.success || !data.data.videos) {
                    throw new Error("Failed to scan Media Library");
                }
                var videos = data.data.videos;
                var unindexed = videos.filter(function(v) { return !v.embedding || !v.embedding.length; });

                if (unindexed.length === 0) {
                    progBar.style.width = "100%";
                    progStatus.textContent = "✓ All " + videos.length + " Media Library videos are already fully trained and indexed!";
                    trainBtn.disabled = false;
                    return;
                }

                progStatus.textContent = "Loading AI Search Model (Transformers.js)...";
                progBar.style.width = "15%";

                return getExtractor().then(function(extractor) {
                    var total = unindexed.length;
                    var current = 0;
                    var results = [];

                    function processNext() {
                        if (current >= total) {
                            progStatus.textContent = "Saving trained embeddings to WordPress...";
                            var saveParams = new URLSearchParams();
                            saveParams.append("action", "vsc_save_media_embeddings");
                            saveParams.append("nonce", searchNonce);
                            saveParams.append("items", JSON.stringify(results));

                            return fetch(ajaxUrl, {
                                method: "POST",
                                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                                body: saveParams.toString()
                            })
                            .then(function(res) { return res.json(); })
                            .then(function() {
                                progBar.style.width = "100%";
                                progStatus.textContent = "✓ Training Complete! Successfully trained " + total + " videos. Reloading...";
                                setTimeout(function() { location.reload(); }, 1200);
                            });
                        }

                        var item = unindexed[current];
                        var text = [item.title, item.topics ? "Topics: " + item.topics : "", item.context ? "Context: " + item.context : "", item.excerpt ? item.excerpt : ""]
                            .filter(Boolean)
                            .join(". ");

                        progStatus.textContent = "Training video " + (current + 1) + " of " + total + ": \"" + item.title + "\"...";
                        progBar.style.width = Math.round(15 + ((current / total) * 80)) + "%";

                        return extractor(text, { pooling: "mean", normalize: true }).then(function(out) {
                            results.push({
                                wpId: item.wpId,
                                id: item.id,
                                embedding: Array.from(out.data)
                            });
                            current++;
                            return processNext();
                        });
                    }

                    return processNext();
                });
            })
            .catch(function(err) {
                progStatus.textContent = "Error during training: " + err.message;
                trainBtn.disabled = false;
            });
        });
    })();
    </script>
    <style>
    .vsc-admin-wrap { max-width: 1100px; margin-top: 20px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
    .vsc-admin-heading { display: flex; align-items: center; gap: 8px; font-size: 24px; color: #580758; }
    .vsc-admin-sub { font-size: 14px; color: #555; margin-bottom: 20px; }
    .vsc-stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 25px; }
    .vsc-stat-card { background: #fff; border: 1px solid #e0d0e0; border-radius: 10px; padding: 18px 20px; text-align: center; box-shadow: 0 2px 6px rgba(114,9,113,0.04); }
    .vsc-stat-num { font-size: 32px; font-weight: 800; color: #720971; line-height: 1.1; margin-bottom: 5px; }
    .vsc-stat-num.vsc-text-green { color: #108a38; }
    .vsc-stat-num.vsc-text-orange { color: #d97706; }
    .vsc-stat-label { font-size: 13px; font-weight: 600; color: #666; text-transform: uppercase; letter-spacing: 0.5px; }
    .vsc-action-box { background: #fff; border: 1px solid #e0d0e0; border-radius: 12px; padding: 24px; margin-bottom: 25px; box-shadow: 0 2px 8px rgba(114,9,113,0.06); }
    .vsc-action-box h2 { margin-top: 0; color: #1a1a1a; font-size: 18px; }
    .vsc-progress-bar-bg { width: 100%; height: 14px; background: #eedcee; border-radius: 8px; overflow: hidden; }
    #vsc-progress-bar { height: 100%; background: linear-gradient(90deg, #720971, #a319a2); transition: width 0.3s ease; }
    .vsc-badge { display: inline-block; padding: 3px 8px; font-size: 11px; font-weight: 700; border-radius: 12px; }
    .vsc-badge-trained { background: #dcfce7; color: #15803d; }
    .vsc-badge-untrained { background: #fef3c7; color: #b45309; }
    .vsc-table-wrap { background: #fff; border: 1px solid #e0d0e0; border-radius: 12px; padding: 20px; }
    @media(max-width: 768px) { .vsc-stats-grid { grid-template-columns: 1fr 1fr; } }
    </style>
    <?php
}

/* ─── Shortcode & Asset Enqueue ──────────────────────────────────── */

/**
 * Enqueue scripts and styles with membership and media library configuration.
 */
function vsc_enqueue_assets($config = []) {
    wp_enqueue_style(
        'vsc-style',
        VSC_PLUGIN_URL . 'assets/chatbot.css',
        [],
        VSC_VERSION
    );

    wp_enqueue_script(
        'vsc-script',
        VSC_PLUGIN_URL . 'assets/chatbot.js',
        [],
        VSC_VERSION,
        true
    );

    $default_config = [
        'dataUrl'             => VSC_PLUGIN_URL . 'assets/data.json?v=' . VSC_VERSION,
        'mediaVideosUrl'      => vsc_get_media_json_url(),
        'mediaVideos'         => vsc_get_all_media_videos(),
        'ajaxUrl'             => admin_url('admin-ajax.php'),
        'nonce'               => wp_create_nonce('vsc_search_nonce'),
        'isLoggedIn'          => false,
        'isMember'            => false,
        'isUnlimited'         => false,
        'searchesLeft'        => 0,
        'allowedLimit'        => 1,
        'usedInCycle'         => 0,
        'freeLimit'           => 1,
        'memberLimit'         => 10,
        'periodHours'         => 24,
        'secondsUntilReset'   => 0,
        'resetTimestamp'      => 0,
        'resetTimeFormatted'  => '',
        'membershipUrl'       => home_url('/membership-levels/'),
        'loginUrl'            => wp_login_url(),
        'popupTitle'          => "Daily Free Search Received",
        'popupMessage'        => "You have received your daily free video search. Monthly Subscribers receive multiple daily searches.",
        'popupButtonText'     => "Subscribe for More Searches",
    ];

    $merged_config = wp_parse_args($config, $default_config);

    wp_localize_script('vsc-script', 'VSC_CONFIG', $merged_config);
}

/**
 * Shortcode: [video_search_chat]
 */
function vsc_shortcode($atts) {
    $atts = shortcode_atts([
        'placeholder'         => 'Search videos... e.g. faith, forgiveness, prayer',
        'results'             => 1,
        'free_searches'       => 1,
        'member_searches'     => 10,
        'period_hours'        => 24,
        'levels'              => '',
        'membership_url'      => '',
        'login_url'           => '',
        'popup_title'         => "Daily Free Search Received",
        'popup_message'       => "You have received your daily free video search. Monthly Subscribers receive multiple daily searches.",
        'popup_button_text'   => "Subscribe for More Searches",
    ], $atts, 'video_search_chat');

    $is_logged_in = is_user_logged_in();
    $user_id      = $is_logged_in ? get_current_user_id() : 0;
    $is_member    = vsc_is_user_active_member($user_id, $atts['levels']);

    $membership_url = vsc_get_membership_url($atts['membership_url']);
    $login_url      = !empty($atts['login_url']) ? esc_url_raw($atts['login_url']) : wp_login_url(get_permalink());

    $free_limit   = max(1, intval($atts['free_searches']));
    $member_limit = max(1, intval($atts['member_searches']));
    $period_hours = max(1, intval($atts['period_hours']));

    $is_unlimited         = false;
    $searches_left        = 0;
    $allowed_limit        = 1;
    $used_in_cycle        = 0;
    $seconds_until_reset  = 0;
    $reset_timestamp      = 0;
    $reset_time_formatted = '';

    if ($is_logged_in && user_can($user_id, 'manage_options') && apply_filters('vsc_admin_unlimited', true, $user_id)) {
        // Admin with unlimited testing access
        $is_unlimited  = true;
        $searches_left = -1;
        $allowed_limit = -1;
    } elseif ($is_member) {
        // Monthly subscriber: gets 10 searches per 24 hours
        $allowed_limit        = $member_limit;
        $status               = vsc_get_user_search_status($user_id, $member_limit, $period_hours);
        $searches_left        = $status['searches_left'];
        $used_in_cycle        = $status['used_in_cycle'];
        $seconds_until_reset  = $status['seconds_until_reset'];
        $reset_timestamp      = $status['reset_timestamp'];
        $reset_time_formatted = $status['reset_time_formatted'];
    } elseif ($is_logged_in) {
        // Logged-in non-member: gets 1 free search per 24 hours
        $allowed_limit        = $free_limit;
        $status               = vsc_get_user_search_status($user_id, $free_limit, $period_hours);
        $searches_left        = $status['searches_left'];
        $used_in_cycle        = $status['used_in_cycle'];
        $seconds_until_reset  = $status['seconds_until_reset'];
        $reset_timestamp      = $status['reset_timestamp'];
        $reset_time_formatted = $status['reset_time_formatted'];
    } else {
        // Guest / non-logged in visitor
        $searches_left = 0;
    }

    vsc_enqueue_assets([
        'dataUrl'             => VSC_PLUGIN_URL . 'assets/data.json?v=' . VSC_VERSION,
        'mediaVideosUrl'      => vsc_get_media_json_url(),
        'mediaVideos'         => vsc_get_all_media_videos(),
        'ajaxUrl'             => admin_url('admin-ajax.php'),
        'nonce'               => wp_create_nonce('vsc_search_nonce'),
        'isLoggedIn'          => $is_logged_in,
        'isMember'            => $is_member,
        'isUnlimited'         => $is_unlimited,
        'searchesLeft'        => $searches_left,
        'allowedLimit'        => $allowed_limit,
        'usedInCycle'         => $used_in_cycle,
        'freeLimit'           => $free_limit,
        'memberLimit'         => $member_limit,
        'periodHours'         => $period_hours,
        'secondsUntilReset'   => $seconds_until_reset,
        'resetTimestamp'      => $reset_timestamp,
        'resetTimeFormatted'  => $reset_time_formatted,
        'membershipUrl'       => $membership_url,
        'loginUrl'            => $login_url,
        'popupTitle'          => $atts['popup_title'],
        'popupMessage'        => $atts['popup_message'],
        'popupButtonText'     => $atts['popup_button_text'],
    ]);

    ob_start();
    ?>
    <div id="vsc-app"
         data-max-results="<?php echo esc_attr($atts['results']); ?>"
         data-logged-in="<?php echo $is_logged_in ? '1' : '0'; ?>"
         data-is-member="<?php echo $is_member ? '1' : '0'; ?>"
         data-is-unlimited="<?php echo $is_unlimited ? '1' : '0'; ?>"
         data-searches-left="<?php echo esc_attr($searches_left); ?>"
         data-allowed-limit="<?php echo esc_attr($allowed_limit); ?>"
         data-used-in-cycle="<?php echo esc_attr($used_in_cycle); ?>"
         data-free-limit="<?php echo esc_attr($free_limit); ?>"
         data-member-limit="<?php echo esc_attr($member_limit); ?>"
         data-period-hours="<?php echo esc_attr($period_hours); ?>"
         data-seconds-until-reset="<?php echo esc_attr($seconds_until_reset); ?>"
         data-reset-timestamp="<?php echo esc_attr($reset_timestamp); ?>"
         data-reset-formatted="<?php echo esc_attr($reset_time_formatted); ?>"
         data-membership-url="<?php echo esc_url($membership_url); ?>"
         data-login-url="<?php echo esc_url($login_url); ?>"
         data-popup-title="<?php echo esc_attr($atts['popup_title']); ?>"
         data-popup-message="<?php echo esc_attr($atts['popup_message']); ?>"
         data-popup-button="<?php echo esc_attr($atts['popup_button_text']); ?>">

        <?php if ($is_logged_in && $is_member && !$is_unlimited) : ?>
            <?php if ($searches_left <= 0) : ?>
                <div class="vsc-banner-bar vsc-banner-warning">
                    <span>
                        You have used all <strong><?php echo esc_html($member_limit); ?> daily searches</strong> for today.
                        Resets in <strong class="vsc-timer-display" data-until="<?php echo esc_attr($seconds_until_reset); ?>">--:--:--</strong>
                        <?php if (!empty($reset_time_formatted)) : ?>
                            <span class="vsc-reset-exact">(at <?php echo esc_html($reset_time_formatted); ?>)</span>
                        <?php endif; ?>.
                    </span>
                </div>
            <?php elseif ($used_in_cycle > 0) : ?>
                <div class="vsc-banner-bar vsc-banner-member">
                    <span>
                        🌟 <strong>Monthly Subscriber:</strong> You have <strong><?php echo esc_html($searches_left); ?> of <?php echo esc_html($member_limit); ?> daily searches</strong> remaining today.
                        Resets in <strong class="vsc-timer-display" data-until="<?php echo esc_attr($seconds_until_reset); ?>">--:--:--</strong>
                        <?php if (!empty($reset_time_formatted)) : ?>
                            <span class="vsc-reset-exact">(at <?php echo esc_html($reset_time_formatted); ?>)</span>
                        <?php endif; ?>.
                    </span>
                </div>
            <?php else : ?>
                <div class="vsc-banner-bar vsc-banner-member">
                    <span>🌟 <strong>Monthly Subscriber:</strong> You have <strong><?php echo esc_html($member_limit); ?> daily searches</strong> available today.</span>
                </div>
            <?php endif; ?>
        <?php elseif ($is_logged_in && !$is_member) : ?>
            <?php if ($searches_left <= 0) : ?>
                <div class="vsc-banner-bar vsc-banner-warning">
                    <span>
                        You have received your daily free video search.
                        Resets in <strong class="vsc-timer-display" data-until="<?php echo esc_attr($seconds_until_reset); ?>">--:--:--</strong>
                        <?php if (!empty($reset_time_formatted)) : ?>
                            <span class="vsc-reset-exact">(at <?php echo esc_html($reset_time_formatted); ?>)</span>
                        <?php endif; ?>. Monthly Subscribers receive multiple daily searches.
                    </span>
                    <a href="<?php echo esc_url($membership_url); ?>" class="vsc-banner-link">Subscribe Now &rarr;</a>
                </div>
            <?php else : ?>
                <div class="vsc-banner-bar vsc-banner-info">
                    <span>You have <strong>1 free search</strong> available today (resets every <?php echo esc_html($period_hours); ?> hours).</span>
                    <a href="<?php echo esc_url($membership_url); ?>" class="vsc-banner-link">Subscribe for 10 Daily Searches &rarr;</a>
                </div>
            <?php endif; ?>
        <?php elseif (!$is_logged_in) : ?>
            <div class="vsc-banner-bar vsc-banner-guest">
                <span>Have an account? <a href="<?php echo esc_url($login_url); ?>" class="vsc-banner-link">Log in</a> to use your free daily search, or <a href="<?php echo esc_url($membership_url); ?>" class="vsc-banner-link">Subscribe for 10 Daily Searches</a>.</span>
            </div>
        <?php endif; ?>

        <div id="vsc-messages"></div>
        <div id="vsc-status"></div>
        <div id="vsc-input-row">
            <input
                type="text"
                id="vsc-input"
                placeholder="<?php echo esc_attr($atts['placeholder']); ?>"
                autocomplete="off"
            />
            <button id="vsc-send" type="button" aria-label="Search">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
                     xmlns="http://www.w3.org/2000/svg">
                    <circle cx="11" cy="11" r="7" stroke="white" stroke-width="2"/>
                    <path d="M20 20L17 17" stroke="white" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </button>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('video_search_chat', 'vsc_shortcode');

/**
 * Admin notice if data.json is still the placeholder / empty.
 */
function vsc_admin_notice() {
    $data_path = VSC_PLUGIN_PATH . 'assets/data.json';
    $is_placeholder = true;

    if (file_exists($data_path)) {
        $content = json_decode(file_get_contents($data_path), true);
        if (is_array($content) && count($content) > 3) {
            $is_placeholder = false;
        }
    }

    if ($is_placeholder) {
        echo '<div class="notice notice-warning"><p><strong>Video Search Chat:</strong> ';
        echo 'assets/data.json still contains placeholder/sample data. You can index and train your WordPress Media Library videos in <strong>Media &rarr; Video Search Chat</strong>.</p></div>';
    }
}
add_action('admin_notices', 'vsc_admin_notice');