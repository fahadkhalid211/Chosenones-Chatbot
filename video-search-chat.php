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
        vsc_sync_media_library_videos();
    }
    return trailingslashit($upload_dir['baseurl']) . 'vsc-videos/media-library-videos.json?v=' . (file_exists($path) ? filemtime($path) : time());
}

/**
 * Scan WordPress Media Library for all video attachments and sync catalog.
 * Preserves existing embeddings while indexing new or updated videos.
 *
 * @return array Array of indexed video items.
 */
function vsc_sync_media_library_videos() {
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

    $args = [
        'post_type'      => 'attachment',
        'post_mime_type' => 'video',
        'post_status'    => 'inherit',
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ];
    $attachments = get_posts($args);

    $updated_list = [];

    foreach ($attachments as $att) {
        $wp_id = $att->ID;
        $url   = wp_get_attachment_url($wp_id);
        if (!$url) continue;

        $thumb = wp_get_attachment_image_url($wp_id, 'medium');
        if (!$thumb) {
            $thumb = wp_get_attachment_thumb_url($wp_id);
        }

        $caption     = trim($att->post_excerpt);
        $description = trim($att->post_content);
        $custom_desc = trim(get_post_meta($wp_id, '_vsc_transcript', true));
        if (!$custom_desc) {
            $custom_desc = trim(get_post_meta($wp_id, 'transcript', true));
        }

        // Build rich excerpt for semantic search
        $excerpt_parts = array_filter([$caption, $description, $custom_desc]);
        $excerpt = !empty($excerpt_parts) ? implode(" \n", $excerpt_parts) : $att->post_title;
        $clean_excerpt = wp_strip_all_tags($excerpt);
        $title = !empty($att->post_title) ? $att->post_title : basename($url);

        $existing  = isset($existing_data[$wp_id]) ? $existing_data[$wp_id] : null;
        $embedding = null;

        // If title and text haven't changed, reuse existing embedding
        if ($existing && !empty($existing['embedding']) && $existing['title'] === $title && $existing['excerpt'] === $clean_excerpt) {
            $embedding = $existing['embedding'];
        } else {
            // Check postmeta fallback
            $meta_embed = get_post_meta($wp_id, '_vsc_embedding', true);
            if (is_array($meta_embed) && count($meta_embed) === 384) {
                $embedding = $meta_embed;
            }
        }

        $updated_list[] = [
            'id'        => 'wp_' . $wp_id,
            'wpId'      => $wp_id,
            'title'     => $title,
            'excerpt'   => $clean_excerpt,
            'videoUrl'  => $url,
            'thumbUrl'  => $thumb ?: '',
            'source'    => 'media_library',
            'date'      => $att->post_date,
            'mime'      => $att->post_mime_type,
            'embedding' => $embedding,
        ];
    }

    file_put_contents($path, json_encode($updated_list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return $updated_list;
}

/**
 * Automatically update video catalog whenever an attachment is added or edited.
 */
function vsc_handle_attachment_change($attachment_id) {
    if (wp_attachment_is('video', $attachment_id)) {
        vsc_sync_media_library_videos();
    }
}
add_action('add_attachment', 'vsc_handle_attachment_change');
add_action('edit_attachment', 'vsc_handle_attachment_change');

/**
 * Automatically remove video from catalog when deleted.
 */
function vsc_handle_attachment_delete($attachment_id) {
    $path = vsc_get_media_json_path();
    if (file_exists($path)) {
        $data = json_decode(file_get_contents($path), true);
        if (is_array($data)) {
            $filtered = array_filter($data, function ($v) use ($attachment_id) {
                return isset($v['wpId']) && intval($v['wpId']) !== intval($attachment_id);
            });
            file_put_contents($path, json_encode(array_values($filtered), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }
}
add_action('delete_attachment', 'vsc_handle_attachment_delete');

/**
 * AJAX endpoint to save embeddings generated by the browser for Media Library videos.
 */
function vsc_ajax_save_media_embeddings() {
    check_ajax_referer('vsc_search_nonce', 'nonce');

    $raw = isset($_POST['items']) ? wp_unslash($_POST['items']) : '';
    $items = json_decode($raw, true);

    if (!is_array($items) || empty($items)) {
        wp_send_json_error(['message' => 'No items provided']);
    }

    $path = vsc_get_media_json_path();
    $current = file_exists($path) ? json_decode(file_get_contents($path), true) : [];
    if (!is_array($current)) {
        $current = [];
    }

    $map = [];
    foreach ($items as $it) {
        if (!empty($it['wpId']) && !empty($it['embedding']) && is_array($it['embedding'])) {
            $wp_id = intval($it['wpId']);
            $map[$wp_id] = $it['embedding'];
            update_post_meta($wp_id, '_vsc_embedding', $it['embedding']);
        }
    }

    foreach ($current as &$v) {
        if (!empty($v['wpId']) && isset($map[intval($v['wpId'])])) {
            $v['embedding'] = $map[intval($v['wpId'])];
        }
    }

    file_put_contents($path, json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

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

    $videos = vsc_sync_media_library_videos();
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
    $videos = vsc_sync_media_library_videos();
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
            <span class="dashicons dashicons-format-video"></span> Video Search Chat Knowledgebase
        </h1>
        <p class="vsc-admin-sub">
            The chatbot automatically scans all videos in your WordPress Media Library, generates AI embeddings (training), and includes them in search answers.
        </p>

        <!-- Stats Cards -->
        <div class="vsc-stats-grid">
            <div class="vsc-stat-card">
                <div class="vsc-stat-num"><?php echo esc_html($total_media); ?></div>
                <div class="vsc-stat-label">Media Library Videos</div>
            </div>
            <div class="vsc-stat-card">
                <div class="vsc-stat-num vsc-text-green"><?php echo esc_html($trained_count); ?></div>
                <div class="vsc-stat-label">Trained &amp; Searchable</div>
            </div>
            <div class="vsc-stat-card">
                <div class="vsc-stat-num <?php echo $untrained_count > 0 ? 'vsc-text-orange' : ''; ?>">
                    <?php echo esc_html($untrained_count); ?>
                </div>
                <div class="vsc-stat-label">Needing Training</div>
            </div>
            <div class="vsc-stat-card">
                <div class="vsc-stat-num">665</div>
                <div class="vsc-stat-label">Google Drive Library</div>
            </div>
        </div>

        <!-- Sync & Training Actions -->
        <div class="vsc-action-box">
            <h2>⚡ Video Knowledgebase &amp; AI Training</h2>
            <p>
                Whenever you upload a video in <strong>Media &rarr; Add New</strong>, Video Search Chat automatically registers it. 
                Click below to index and generate AI embeddings for all Media Library videos right now.
            </p>
            <div class="vsc-btn-row">
                <button type="button" id="vsc-start-training" class="button button-primary button-hero">
                    ⚡ Scan &amp; Train on Media Library Videos
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
                        <th>Video Title</th>
                        <th>Excerpt / Caption</th>
                        <th>Date Uploaded</th>
                        <th>Training Status</th>
                    </tr>
                </thead>
                <tbody id="vsc-video-tbody">
                    <?php if (empty($videos)) : ?>
                        <tr><td colspan="5">No video attachments found in your Media Library yet. Upload an MP4 video in Media &rarr; Add New!</td></tr>
                    <?php else : ?>
                        <?php foreach ($videos as $v) : ?>
                            <tr>
                                <td>
                                    <?php if (!empty($v['thumbUrl'])) : ?>
                                        <img src="<?php echo esc_url($v['thumbUrl']); ?>" style="width: 70px; height: 45px; object-fit: cover; border-radius: 4px;" alt="">
                                    <?php else : ?>
                                        <div style="width: 70px; height: 45px; background: #eee; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 20px;">🎬</div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo esc_html($v['title']); ?></strong><br>
                                    <small><a href="<?php echo esc_url($v['videoUrl']); ?>" target="_blank">View File</a> | <a href="<?php echo esc_url(get_edit_post_link($v['wpId'])); ?>">Edit Details</a></small>
                                </td>
                                <td><?php echo esc_html(mb_strimwidth($v['excerpt'], 0, 100, '...')); ?></td>
                                <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($v['date']))); ?></td>
                                <td>
                                    <?php if (!empty($v['embedding'])) : ?>
                                        <span class="vsc-badge vsc-badge-trained">✓ Trained &amp; Ready</span>
                                    <?php else : ?>
                                        <span class="vsc-badge vsc-badge-untrained">⚡ Needs Training</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
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

        trainBtn.addEventListener("click", function() {
            trainBtn.disabled = true;
            progWrap.style.display = "block";
            progBar.style.width = "5%";
            progStatus.textContent = "Scanning Media Library videos...";

            // 1. Fetch latest videos from Media Library
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

                return import("https://cdn.jsdelivr.net/npm/@xenova/transformers@2.17.2")
                    .then(function(mod) {
                        return mod.pipeline("feature-extraction", "Xenova/all-MiniLM-L6-v2");
                    })
                    .then(function(extractor) {
                        var total = unindexed.length;
                        var current = 0;
                        var results = [];

                        function processNext() {
                            if (current >= total) {
                                // Save all embeddings to WordPress
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
                                    progStatus.textContent = "✓ Training Complete! Successfully trained and saved " + total + " videos. Reloading...";
                                    setTimeout(function() { location.reload(); }, 1200);
                                });
                            }

                            var item = unindexed[current];
                            var text = (item.title || "") + ". " + (item.excerpt || "");
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