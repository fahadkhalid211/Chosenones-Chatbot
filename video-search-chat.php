<?php
/**
 * Plugin Name: Video Search Chat
 * Description: Full-page AI-style semantic search chatbot for a video library hosted on Google Drive. Search runs entirely in the visitor's browser — no API costs. Use the [video_search_chat] shortcode on any page.
 * Version: 1.0.0
 * Author: Fahad Khalid
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) {
    exit; // No direct access
}

define('VSC_VERSION', '1.0.0');
define('VSC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('VSC_PLUGIN_PATH', plugin_dir_path(__FILE__));

/**
 * Enqueue assets only on pages that actually use the shortcode.
 */
function vsc_enqueue_assets() {
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

    wp_localize_script('vsc-script', 'VSC_CONFIG', [
        'dataUrl' => VSC_PLUGIN_URL . 'assets/data.json?v=' . VSC_VERSION,
    ]);
}

/**
 * Shortcode: [video_search_chat]
 */
function vsc_shortcode($atts) {
    vsc_enqueue_assets();

    $atts = shortcode_atts([
        'placeholder' => 'Search videos... e.g. faith, forgiveness, prayer',
        'results' => 1,
    ], $atts);

    ob_start();
    ?>
    <div id="vsc-app" data-max-results="<?php echo esc_attr($atts['results']); ?>">
        <div id="vsc-messages"></div>
        <div id="vsc-status"></div>
        <div id="vsc-input-row">
            <input
                type="text"
                id="vsc-input"
                placeholder="<?php echo esc_attr($atts['placeholder']); ?>"
                autocomplete="off"
            />
            <button id="vsc-send" type="button">
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