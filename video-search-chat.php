<?php
/**
 * Plugin Name: Video Search Chat
 * Description: Full-page AI-style semantic search chatbot for a video library hosted on Google Drive. Search runs entirely in the visitor's browser — no API costs. Integrates with Paid Memberships Pro to provide 1 free search for logged-in non-members and unlimited searches for active members. Use the [video_search_chat] shortcode on any page.
 * Version: 1.1.0
 * Author: Fahad Khalid
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) {
    exit; // No direct access
}

define('VSC_VERSION', '1.1.0');
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
 * AJAX endpoint to record a search execution for logged-in non-members.
 */
function vsc_ajax_record_search() {
    check_ajax_referer('vsc_search_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'User is not logged in.'], 403);
    }

    $user_id = get_current_user_id();

    // Active members have unlimited searches; no need to increment count
    if (vsc_is_user_active_member($user_id)) {
        wp_send_json_success([
            'unlimited'     => true,
            'searches_left' => -1,
        ]);
    }

    $current_count = (int) get_user_meta($user_id, 'vsc_search_count', true);
    $new_count     = $current_count + 1;
    update_user_meta($user_id, 'vsc_search_count', $new_count);

    $free_limit    = (int) apply_filters('vsc_free_searches_limit', 1, $user_id);
    $searches_left = max(0, $free_limit - $new_count);

    wp_send_json_success([
        'unlimited'     => false,
        'search_count'  => $new_count,
        'searches_left' => $searches_left,
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
        'dataUrl'         => VSC_PLUGIN_URL . 'assets/data.json?v=' . VSC_VERSION,
        'ajaxUrl'         => admin_url('admin-ajax.php'),
        'nonce'           => wp_create_nonce('vsc_search_nonce'),
        'isLoggedIn'      => false,
        'isMember'        => false,
        'searchesLeft'    => 0,
        'freeLimit'       => 1,
        'membershipUrl'   => home_url('/membership-levels/'),
        'loginUrl'        => wp_login_url(),
        'popupTitle'      => "Unlock Unlimited Searches",
        'popupMessage'    => "You've used your 1 free search. Join our membership today for unlimited access to all video teachings and search features.",
        'popupButtonText' => "Join Membership Now",
    ];

    $merged_config = wp_parse_args($config, $default_config);

    wp_localize_script('vsc-script', 'VSC_CONFIG', $merged_config);
}

/**
 * Shortcode: [video_search_chat]
 */
function vsc_shortcode($atts) {
    $atts = shortcode_atts([
        'placeholder'        => 'Search videos... e.g. faith, forgiveness, prayer',
        'results'            => 1,
        'free_searches'      => 1,
        'levels'             => '',
        'membership_url'     => '',
        'login_url'          => '',
        'popup_title'        => "Unlock Unlimited Searches",
        'popup_message'      => "You've used your 1 free search. Join our membership today for unlimited access to all video teachings and search features.",
        'popup_button_text'  => "Join Membership Now",
    ], $atts, 'video_search_chat');

    $is_logged_in = is_user_logged_in();
    $user_id      = $is_logged_in ? get_current_user_id() : 0;
    $is_member    = vsc_is_user_active_member($user_id, $atts['levels']);

    $membership_url = vsc_get_membership_url($atts['membership_url']);
    $login_url      = !empty($atts['login_url']) ? esc_url_raw($atts['login_url']) : wp_login_url(get_permalink());

    $free_limit = max(1, intval($atts['free_searches']));
    if ($is_member) {
        $searches_left = -1; // unlimited searches
    } elseif ($is_logged_in) {
        $used_count    = (int) get_user_meta($user_id, 'vsc_search_count', true);
        $searches_left = max(0, $free_limit - $used_count);
    } else {
        // Guest / non-logged in visitor
        $searches_left = 0;
    }

    vsc_enqueue_assets([
        'dataUrl'         => VSC_PLUGIN_URL . 'assets/data.json?v=' . VSC_VERSION,
        'ajaxUrl'         => admin_url('admin-ajax.php'),
        'nonce'           => wp_create_nonce('vsc_search_nonce'),
        'isLoggedIn'      => $is_logged_in,
        'isMember'        => $is_member,
        'searchesLeft'    => $searches_left,
        'freeLimit'       => $free_limit,
        'membershipUrl'   => $membership_url,
        'loginUrl'        => $login_url,
        'popupTitle'      => $atts['popup_title'],
        'popupMessage'    => $atts['popup_message'],
        'popupButtonText' => $atts['popup_button_text'],
    ]);

    ob_start();
    ?>
    <div id="vsc-app"
         data-max-results="<?php echo esc_attr($atts['results']); ?>"
         data-logged-in="<?php echo $is_logged_in ? '1' : '0'; ?>"
         data-is-member="<?php echo $is_member ? '1' : '0'; ?>"
         data-searches-left="<?php echo esc_attr($searches_left); ?>"
         data-free-limit="<?php echo esc_attr($free_limit); ?>"
         data-membership-url="<?php echo esc_url($membership_url); ?>"
         data-login-url="<?php echo esc_url($login_url); ?>"
         data-popup-title="<?php echo esc_attr($atts['popup_title']); ?>"
         data-popup-message="<?php echo esc_attr($atts['popup_message']); ?>"
         data-popup-button="<?php echo esc_attr($atts['popup_button_text']); ?>">

        <?php if ($is_logged_in && !$is_member && $searches_left <= 0) : ?>
            <div class="vsc-banner-bar vsc-banner-warning">
                <span>Free search limit reached (1/1).</span>
                <a href="<?php echo esc_url($membership_url); ?>" class="vsc-banner-link">Join Membership for Unlimited Searches &rarr;</a>
            </div>
        <?php elseif ($is_logged_in && !$is_member && $searches_left > 0) : ?>
            <div class="vsc-banner-bar vsc-banner-info">
                <span>You have <strong>1 free search</strong> available.</span>
                <a href="<?php echo esc_url($membership_url); ?>" class="vsc-banner-link">Join Membership for Unlimited &rarr;</a>
            </div>
        <?php elseif (!$is_logged_in) : ?>
            <div class="vsc-banner-bar vsc-banner-guest">
                <span>Have an account? <a href="<?php echo esc_url($login_url); ?>" class="vsc-banner-link">Log in</a> to use your free search, or <a href="<?php echo esc_url($membership_url); ?>" class="vsc-banner-link">Join Membership</a>.</span>
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