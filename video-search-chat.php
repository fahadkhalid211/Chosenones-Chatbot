<?php
/**
 * Plugin Name: Video Search Chat
 * Description: Full-page AI-style semantic search chatbot for a video library hosted on Google Drive. Search runs entirely in the visitor's browser — no API costs. Integrates with Paid Memberships Pro: 1 free search every 24 hours for logged-in non-members with a live countdown timer and membership CTA, and unlimited searches for active members. Use the [video_search_chat] shortcode on any page.
 * Version: 1.2.0
 * Author: Fahad Khalid
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) {
    exit; // No direct access
}

define('VSC_VERSION', '1.2.0');
define('VSC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('VSC_PLUGIN_PATH', plugin_dir_path(__FILE__));

/**
 * Check if a user has an active membership level in Paid Memberships Pro.
 * Administrators are also treated as members with unlimited searches.
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

    // Site administrators always have unlimited access
    if (user_can($user_id, 'manage_options')) {
        return apply_filters('vsc_is_active_member', true, $user_id);
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
 * Calculate search status for a user with a rolling 24-hour reset window.
 *
 * @param int $user_id User ID.
 * @param int $free_limit Searches allowed per period (default: 1).
 * @param int $period_hours Hours before the free search resets (default: 24).
 * @return array
 */
function vsc_get_user_search_status($user_id, $free_limit = 1, $period_hours = 24) {
    $now = time();
    $window_seconds = max(1, $period_hours) * HOUR_IN_SECONDS;
    $last_search_time = (int) get_user_meta($user_id, 'vsc_last_search_time', true);

    if ($last_search_time > 0 && ($now - $last_search_time) < $window_seconds) {
        $seconds_left = $window_seconds - ($now - $last_search_time);
        $reset_ts     = $last_search_time + $window_seconds;

        return [
            'searches_left'        => 0,
            'seconds_until_reset'  => $seconds_left,
            'reset_timestamp'      => $reset_ts,
            'reset_time_formatted' => wp_date(get_option('time_format'), $reset_ts),
        ];
    }

    return [
        'searches_left'        => max(1, $free_limit),
        'seconds_until_reset'  => 0,
        'reset_timestamp'      => 0,
        'reset_time_formatted' => '',
    ];
}

/**
 * AJAX endpoint to record a search execution for logged-in non-members.
 */
function vsc_ajax_record_search() {
    check_ajax_referer('vsc_search_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'User is not logged in.'], 403);
    }

    $user_id = get_current_user_id();

    // Active members have unlimited searches; no need to increment or limit
    if (vsc_is_user_active_member($user_id)) {
        wp_send_json_success([
            'unlimited'            => true,
            'searches_left'        => -1,
            'reset_timestamp'      => 0,
            'seconds_until_reset'  => 0,
            'reset_time_formatted' => '',
        ]);
    }

    $period_hours = (int) apply_filters('vsc_reset_period_hours', 24, $user_id);
    $status = vsc_get_user_search_status($user_id, 1, $period_hours);

    // If within 24h cooldown, reject further searches
    if ($status['searches_left'] <= 0) {
        wp_send_json_error([
            'message'              => '24-hour search limit reached.',
            'unlimited'            => false,
            'searches_left'        => 0,
            'seconds_until_reset'  => $status['seconds_until_reset'],
            'reset_timestamp'      => $status['reset_timestamp'],
            'reset_time_formatted' => $status['reset_time_formatted'],
        ], 429);
    }

    $now = time();
    $window_seconds = max(1, $period_hours) * HOUR_IN_SECONDS;
    $reset_ts = $now + $window_seconds;

    // Record search timestamp and increment lifetime search count
    update_user_meta($user_id, 'vsc_last_search_time', $now);
    $current_count = (int) get_user_meta($user_id, 'vsc_search_count', true);
    update_user_meta($user_id, 'vsc_search_count', $current_count + 1);

    wp_send_json_success([
        'unlimited'            => false,
        'search_count'         => $current_count + 1,
        'searches_left'        => 0,
        'seconds_until_reset'  => $window_seconds,
        'reset_timestamp'      => $reset_ts,
        'reset_time_formatted' => wp_date(get_option('time_format'), $reset_ts),
    ]);
}
add_action('wp_ajax_vsc_record_search', 'vsc_ajax_record_search');

/**
 * Enqueue scripts and styles with membership configuration.
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
        'ajaxUrl'             => admin_url('admin-ajax.php'),
        'nonce'               => wp_create_nonce('vsc_search_nonce'),
        'isLoggedIn'          => false,
        'isMember'            => false,
        'searchesLeft'        => 0,
        'freeLimit'           => 1,
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
    $period_hours = max(1, intval($atts['period_hours']));

    $searches_left        = 0;
    $seconds_until_reset  = 0;
    $reset_timestamp      = 0;
    $reset_time_formatted = '';

    if ($is_member) {
        $searches_left = -1; // unlimited searches
    } elseif ($is_logged_in) {
        $status               = vsc_get_user_search_status($user_id, $free_limit, $period_hours);
        $searches_left        = $status['searches_left'];
        $seconds_until_reset  = $status['seconds_until_reset'];
        $reset_timestamp      = $status['reset_timestamp'];
        $reset_time_formatted = $status['reset_time_formatted'];
    } else {
        // Guest / non-logged in visitor
        $searches_left = 0;
    }

    vsc_enqueue_assets([
        'dataUrl'             => VSC_PLUGIN_URL . 'assets/data.json?v=' . VSC_VERSION,
        'ajaxUrl'             => admin_url('admin-ajax.php'),
        'nonce'               => wp_create_nonce('vsc_search_nonce'),
        'isLoggedIn'          => $is_logged_in,
        'isMember'            => $is_member,
        'searchesLeft'        => $searches_left,
        'freeLimit'           => $free_limit,
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
         data-searches-left="<?php echo esc_attr($searches_left); ?>"
         data-free-limit="<?php echo esc_attr($free_limit); ?>"
         data-period-hours="<?php echo esc_attr($period_hours); ?>"
         data-seconds-until-reset="<?php echo esc_attr($seconds_until_reset); ?>"
         data-reset-timestamp="<?php echo esc_attr($reset_timestamp); ?>"
         data-reset-formatted="<?php echo esc_attr($reset_time_formatted); ?>"
         data-membership-url="<?php echo esc_url($membership_url); ?>"
         data-login-url="<?php echo esc_url($login_url); ?>"
         data-popup-title="<?php echo esc_attr($atts['popup_title']); ?>"
         data-popup-message="<?php echo esc_attr($atts['popup_message']); ?>"
         data-popup-button="<?php echo esc_attr($atts['popup_button_text']); ?>">

        <?php if ($is_logged_in && !$is_member && $searches_left <= 0) : ?>
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
        <?php elseif ($is_logged_in && !$is_member && $searches_left > 0) : ?>
            <div class="vsc-banner-bar vsc-banner-info">
                <span>You have <strong>1 free search</strong> available today (resets every <?php echo esc_html($period_hours); ?> hours).</span>
                <a href="<?php echo esc_url($membership_url); ?>" class="vsc-banner-link">Join Membership for Unlimited &rarr;</a>
            </div>
        <?php elseif (!$is_logged_in) : ?>
            <div class="vsc-banner-bar vsc-banner-guest">
                <span>Have an account? <a href="<?php echo esc_url($login_url); ?>" class="vsc-banner-link">Log in</a> to use your free daily search, or <a href="<?php echo esc_url($membership_url); ?>" class="vsc-banner-link">Join Membership</a>.</span>
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
        echo 'assets/data.json still contains placeholder/sample data. Run the pipeline script ';
        echo 'and replace that file with your real video data before going live.</p></div>';
    }
}
add_action('admin_notices', 'vsc_admin_notice');