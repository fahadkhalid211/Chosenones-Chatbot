<?php
/**
 * Snippet Title: ChosenOnes.Faith Member Dashboard & Integrations
 * Snippet Type: PHP Snippet (WPCode compatible)
 * Shortcode: [chosenones_dashboard]
 *
 * Integrates:
 *  - Paid Memberships Pro (Free, Premium, Expired, Canceled states)
 *  - Tutor LMS / LearnDash Course Library & Real Progress Tracking
 *  - Video Search Chat (1 search/day Free, 10 searches/day Premium, up to 3 video recommendations)
 *  - Live 24-Hour Quota Countdown Timer
 *  - Community Forum, Exclusive Interviews, Upcoming Live Events, Saved Videos, and Account Management
 *  - ChosenOnes.Faith Branding & Approved Color Palette
 *  - Senior-Friendly (Ages 45-75) High-Contrast Readable Responsive UI
 *  - Built-in Admin State Switcher (Free, Premium, Expired, Canceled) for instant testing
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ─── 1. DEFAULT CONFIGURATION & URL RESOLUTION ──────────────────────── */

if (!function_exists('cof_dash_defaults')) {
    function cof_dash_defaults() {
        return [
            'logo'                 => '',
            'courses'              => '',
            'resume'               => '',
            'course_title'         => 'The Kingdom of God Lives Within',
            'course_id'            => 0,
            'videos'               => '',
            'saved'                => '',
            'community'            => '',
            'events'               => '',
            'interviews'           => '',
            'reflections'          => '',
            'account'              => '',
            'support'              => '',
            'upgrade'              => '',
            'renew_url'            => '',
            'premium_level_ids'    => '',
            'event_title'          => 'Live Spiritual Gathering & Q&A with Ralph Amato',
            'event_date'           => 'Every Thursday at 7:00 PM EST',
        ];
    }
}

if (!function_exists('cof_dash_options')) {
    function cof_dash_options() {
        $saved = (array) get_option('cof_dash_settings', []);
        $defaults = cof_dash_defaults();
        $opts = wp_parse_args($saved, $defaults);

        // Smart fallbacks to existing WordPress pages if URLs are empty
        if (empty($opts['account'])) {
            if (function_exists('pmpro_url')) {
                $opts['account'] = pmpro_url('account');
            } else {
                $opts['account'] = home_url('/membership-account/');
            }
        }
        if (empty($opts['upgrade'])) {
            if (function_exists('pmpro_url')) {
                $opts['upgrade'] = pmpro_url('levels');
            } else {
                $opts['upgrade'] = home_url('/membership-levels/');
            }
        }
        if (empty($opts['renew_url'])) {
            $opts['renew_url'] = $opts['upgrade'];
        }
        if (empty($opts['courses'])) {
            $opts['courses'] = home_url('/courses/');
        }
        if (empty($opts['videos'])) {
            $opts['videos'] = home_url('/video-search/');
        }
        if (empty($opts['community'])) {
            $opts['community'] = home_url('/community/');
        }
        if (empty($opts['events'])) {
            $opts['events'] = home_url('/events/');
        }
        if (empty($opts['interviews'])) {
            $opts['interviews'] = home_url('/interviews/');
        }
        if (empty($opts['saved'])) {
            $opts['saved'] = home_url('/saved-videos/');
        }
        if (empty($opts['support'])) {
            $opts['support'] = home_url('/contact/');
        }

        return $opts;
    }
}

/* ─── 2. MEMBERSHIP STATUS DETECTION (PAID MEMBERSHIPS PRO) ──────────── */

/**
 * Returns user membership status: 'premium', 'free', 'expired', 'cancelled', or 'guest'.
 * Fully integrated with Paid Memberships Pro and supports Admin Testing Switcher.
 */
if (!function_exists('cof_get_user_membership_state')) {
    function cof_get_user_membership_state($user_id = null) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        if (!$user_id) {
            return 'guest';
        }

        // Admin Testing Switcher: allows site administrators to preview any membership state instantly
        if (current_user_can('manage_options') && !empty($_GET['cof_preview_state'])) {
            $test_state = sanitize_key($_GET['cof_preview_state']);
            if (in_array($test_state, ['premium', 'free', 'expired', 'cancelled'], true)) {
                return $test_state;
            }
        }

        // Allow custom filter override if used elsewhere
        $filtered = apply_filters('cof_dashboard_is_premium', null, $user_id);
        if ($filtered === true) {
            return 'premium';
        } elseif ($filtered === false) {
            return 'free';
        }

        // Check Paid Memberships Pro
        if (function_exists('pmpro_getMembershipLevelForUser')) {
            $level = pmpro_getMembershipLevelForUser($user_id);

            if (!empty($level) && !empty($level->id)) {
                // Check if level has expired based on enddate
                if (!empty($level->enddate) && $level->enddate < time()) {
                    return 'expired';
                }

                $opts = cof_dash_options();
                if (!empty($opts['premium_level_ids'])) {
                    $prem_ids = array_map('intval', explode(',', $opts['premium_level_ids']));
                    if (in_array((int)$level->id, $prem_ids, true)) {
                        return 'premium';
                    }
                }

                // If level name contains "free", treat as free tier
                if (!empty($level->name) && stripos($level->name, 'free') !== false) {
                    return 'free';
                }

                // If recurring billing or paid level, treat as premium
                if (!empty($level->billing_amount) && (float)$level->billing_amount > 0) {
                    return 'premium';
                }
                if (!empty($level->initial_payment) && (float)$level->initial_payment > 0) {
                    return 'premium';
                }

                return 'premium';
            }

            // No active level found. Check PMPro database history for expired or canceled status
            global $wpdb;
            if (!empty($wpdb->pmpro_memberships_users)) {
                $last_sub = $wpdb->get_row($wpdb->prepare(
                    "SELECT status, enddate FROM {$wpdb->pmpro_memberships_users}
                     WHERE user_id = %d ORDER BY id DESC LIMIT 1",
                    $user_id
                ));

                if ($last_sub) {
                    $st = strtolower(trim($last_sub->status));
                    if (in_array($st, ['cancelled', 'canceled', 'cancel', 'inactive'], true)) {
                        return 'cancelled';
                    }
                    if ($st === 'expired' || (!empty($last_sub->enddate) && $last_sub->enddate < time())) {
                        return 'expired';
                    }
                }
            }
        }

        // Check WordPress role fallback
        $user = get_userdata($user_id);
        if ($user && in_array('cof_premium_member', (array)$user->roles, true)) {
            return 'premium';
        }

        return 'free';
    }
}

/* ─── 3. COURSE LIBRARY & REAL PROGRESS TRACKING ENGINE ──────────────── */

/**
 * Retrieves the user's active course, completed percentage, next lesson URL, and lesson counts.
 * Seamlessly integrates with Tutor LMS, LearnDash, and standard post types with graceful fallback.
 */
if (!function_exists('cof_get_user_course_progress')) {
    function cof_get_user_course_progress($user_id = null) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        $opts = cof_dash_options();

        $course_data = [
            'course_id'       => (int) $opts['course_id'],
            'course_title'    => $opts['course_title'] ?: 'The Kingdom of God Lives Within',
            'course_url'      => $opts['courses'],
            'resume_url'      => $opts['resume'] ?: $opts['courses'],
            'progress_pct'    => 0,
            'completed_count' => 0,
            'total_lessons'   => 0,
            'lesson_title'    => 'Introduction: Awakening to Truth',
            'is_enrolled'     => false,
        ];

        if (!$user_id) {
            return $course_data;
        }

        // Integration with Tutor LMS
        if (function_exists('tutor_utils')) {
            $enrolled = tutor_utils()->get_enrolled_courses_by_user($user_id);
            if (!empty($enrolled) && !empty($enrolled->posts)) {
                $course_post = $enrolled->posts[0];
                $cid = $course_post->ID;

                $pct = (int) tutor_utils()->get_course_completed_percent($cid, $user_id);
                $course_data['course_id']    = $cid;
                $course_data['course_title'] = get_the_title($cid);
                $course_data['course_url']   = get_permalink($cid);
                $course_data['progress_pct'] = max(0, min(100, $pct));
                $course_data['is_enrolled']  = true;

                // Try to get next lesson to resume
                if (method_exists(tutor_utils(), 'get_next_lesson')) {
                    $next_lesson = tutor_utils()->get_next_lesson($cid, $user_id);
                    if ($next_lesson && !empty($next_lesson->ID)) {
                        $course_data['resume_url']   = get_permalink($next_lesson->ID);
                        $course_data['lesson_title'] = get_the_title($next_lesson->ID);
                    }
                }
                return $course_data;
            }
        }

        // Integration with LearnDash
        if (function_exists('learndash_user_get_enrolled_courses')) {
            $ld_courses = learndash_user_get_enrolled_courses($user_id);
            if (!empty($ld_courses)) {
                $cid = (int) $ld_courses[0];
                if (function_exists('learndash_course_progress')) {
                    $ld_prog = learndash_course_progress(['user_id' => $user_id, 'course_id' => $cid]);
                    if (!empty($ld_prog['percentage'])) {
                        $course_data['progress_pct'] = (int) $ld_prog['percentage'];
                    }
                }
                $course_data['course_id']    = $cid;
                $course_data['course_title'] = get_the_title($cid);
                $course_data['course_url']   = get_permalink($cid);
                $course_data['resume_url']   = get_permalink($cid);
                $course_data['is_enrolled']  = true;
                return $course_data;
            }
        }

        // Stored user meta progress fallback (for custom LMS or manual updates)
        $meta_pct = get_user_meta($user_id, 'cof_course_progress_pct', true);
        if ($meta_pct !== '') {
            $course_data['progress_pct'] = max(0, min(100, (int)$meta_pct));
            $course_data['is_enrolled']  = true;
        }

        return $course_data;
    }
}

/* ─── 4. VIDEO SEARCH ENGINE & QUOTA CONNECTOR ───────────────────────── */

/**
 * Calculates current search quota, usage, and remaining time until reset.
 * 1 search/day for Free, 10 searches/day for Premium, up to 3 video recommendations.
 * Fully synchronized with video-search-chat.php.
 */
if (!function_exists('cof_get_user_search_quota')) {
    function cof_get_user_search_quota($user_id = null, $state = 'free') {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }

        $is_admin = $user_id && user_can($user_id, 'manage_options');
        $is_prem  = ($state === 'premium');

        $allowed_limit = $is_prem ? 10 : 1;
        $period_hours  = 24;
        $window_sec    = $period_hours * HOUR_IN_SECONDS;
        $now           = time();

        if (!$user_id) {
            return [
                'allowed_limit'       => $allowed_limit,
                'used_in_cycle'       => 0,
                'searches_left'       => 0,
                'seconds_until_reset' => 0,
                'is_unlimited'        => false,
                'reset_time_text'     => '',
                'max_recommendations' => 3,
            ];
        }

        if ($is_admin) {
            return [
                'allowed_limit'       => 10,
                'used_in_cycle'       => 0,
                'searches_left'       => 10,
                'seconds_until_reset' => 0,
                'is_unlimited'        => true,
                'reset_time_text'     => '',
                'max_recommendations' => 3,
            ];
        }

        // Read cycle tracking meta from video-search-chat
        $cycle_start = (int) get_user_meta($user_id, 'vsc_cycle_start_time', true);
        if (!$cycle_start) {
            $cycle_start = (int) get_user_meta($user_id, 'vsc_last_search_time', true);
        }
        $used_in_cycle = (int) get_user_meta($user_id, 'vsc_cycle_search_count', true);
        if (!$used_in_cycle && $cycle_start > 0) {
            $used_in_cycle = 1;
        }

        if ($cycle_start > 0 && ($now - $cycle_start) < $window_sec) {
            $sec_left      = $window_sec - ($now - $cycle_start);
            $searches_left = max(0, $allowed_limit - $used_in_cycle);
            $reset_ts      = $cycle_start + $window_sec;
            $time_text     = wp_date(get_option('time_format'), $reset_ts);

            return [
                'allowed_limit'       => $allowed_limit,
                'used_in_cycle'       => $used_in_cycle,
                'searches_left'       => $searches_left,
                'seconds_until_reset' => $sec_left,
                'reset_timestamp'     => $reset_ts,
                'is_unlimited'        => false,
                'reset_time_text'     => $time_text,
                'max_recommendations' => 3,
            ];
        }

        return [
            'allowed_limit'       => $allowed_limit,
            'used_in_cycle'       => 0,
            'searches_left'       => $allowed_limit,
            'seconds_until_reset' => 0,
            'reset_timestamp'     => 0,
            'is_unlimited'        => false,
            'reset_time_text'     => '',
            'max_recommendations' => 3,
        ];
    }
}

/**
 * Count saved videos for user.
 */
if (!function_exists('cof_get_saved_videos_count')) {
    function cof_get_saved_videos_count($user_id) {
        if (!$user_id) return 0;
        $saved = get_user_meta($user_id, 'vsc_saved_videos', true);
        if (is_array($saved)) {
            return count($saved);
        }
        $legacy = get_user_meta($user_id, 'cof_saved_videos', true);
        return is_array($legacy) ? count($legacy) : 0;
    }
}

/* ─── 5. ADMIN SETTINGS PAGE ─────────────────────────────────────────── */

add_action('admin_menu', function () {
    add_options_page(
        'ChosenOnes Dashboard',
        'ChosenOnes Dashboard',
        'manage_options',
        'cof-dashboard',
        'cof_dash_settings_page'
    );
});

add_action('admin_init', function () {
    register_setting('cof_dash_group', 'cof_dash_settings', [
        'type'              => 'array',
        'sanitize_callback' => 'cof_dash_sanitize',
        'default'           => cof_dash_defaults(),
    ]);
});

if (!function_exists('cof_dash_sanitize')) {
    function cof_dash_sanitize($values) {
        $out = cof_dash_defaults();
        foreach ($out as $key => $default) {
            if (!isset($values[$key])) continue;
            if (in_array($key, ['course_title', 'event_title', 'event_date', 'premium_level_ids'], true)) {
                $out[$key] = sanitize_text_field($values[$key]);
            } elseif ($key === 'course_id') {
                $out[$key] = absint($values[$key]);
            } else {
                $out[$key] = esc_url_raw($values[$key]);
            }
        }
        return $out;
    }
}

if (!function_exists('cof_dash_settings_page')) {
    function cof_dash_settings_page() {
        if (!current_user_can('manage_options')) return;
        $o = cof_dash_options();
        ?>
        <div class="wrap" style="max-width:900px;font-size:15px;">
            <h1 style="color:#211637;font-weight:700;">ChosenOnes.Faith Member Dashboard Settings</h1>
            <p style="color:#554d60;line-height:1.6;">
                Configure destination links, branding, courses, and integration options for the Member Dashboard (Shortcode: <code>[chosenones_dashboard]</code>).
            </p>
            <form method="post" action="options.php">
                <?php settings_fields('cof_dash_group'); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="cof-logo">Logo Image URL</label></th>
                            <td>
                                <input class="large-text" id="cof-logo" type="url" name="cof_dash_settings[logo]" value="<?php echo esc_attr($o['logo']); ?>" placeholder="https://chosenones.faith/wp-content/uploads/logo.png" />
                                <p class="description">Upload or paste the approved ChosenOnes.Faith logo URL. Leave empty to use the official gold SVG typography branding.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="cof-premium_level_ids">PMPro Premium Level IDs</label></th>
                            <td>
                                <input class="regular-text" id="cof-premium_level_ids" type="text" name="cof_dash_settings[premium_level_ids]" value="<?php echo esc_attr($o['premium_level_ids']); ?>" placeholder="e.g. 2, 3" />
                                <p class="description">Comma-separated Paid Memberships Pro level IDs that count as Premium Members (e.g., Monthly Subscriber). Leave blank to auto-detect any paid recurring level.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="cof-course_title">Featured Course Title</label></th>
                            <td>
                                <input class="large-text" id="cof-course_title" type="text" name="cof_dash_settings[course_title]" value="<?php echo esc_attr($o['course_title']); ?>" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="cof-courses">Courses Library URL</label></th>
                            <td>
                                <input class="large-text" id="cof-courses" type="url" name="cof_dash_settings[courses]" value="<?php echo esc_attr($o['courses']); ?>" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="cof-resume">Resume Course / Next Lesson URL</label></th>
                            <td>
                                <input class="large-text" id="cof-resume" type="url" name="cof_dash_settings[resume]" value="<?php echo esc_attr($o['resume']); ?>" placeholder="Leave blank to auto-detect via Tutor LMS / LearnDash" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="cof-videos">Video Search Page URL</label></th>
                            <td>
                                <input class="large-text" id="cof-videos" type="url" name="cof_dash_settings[videos]" value="<?php echo esc_attr($o['videos']); ?>" placeholder="<?php echo esc_attr(home_url('/video-search/')); ?>" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="cof-community">Community Forum URL</label></th>
                            <td>
                                <input class="large-text" id="cof-community" type="url" name="cof_dash_settings[community]" value="<?php echo esc_attr($o['community']); ?>" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="cof-events">Events & Live Sessions URL</label></th>
                            <td>
                                <input class="large-text" id="cof-events" type="url" name="cof_dash_settings[events]" value="<?php echo esc_attr($o['events']); ?>" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="cof-event_title">Next Event Announcement</label></th>
                            <td>
                                <input class="large-text" id="cof-event_title" type="text" name="cof_dash_settings[event_title]" value="<?php echo esc_attr($o['event_title']); ?>" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="cof-interviews">Exclusive Interviews URL</label></th>
                            <td>
                                <input class="large-text" id="cof-interviews" type="url" name="cof_dash_settings[interviews]" value="<?php echo esc_attr($o['interviews']); ?>" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="cof-saved">Saved Videos URL</label></th>
                            <td>
                                <input class="large-text" id="cof-saved" type="url" name="cof_dash_settings[saved]" value="<?php echo esc_attr($o['saved']); ?>" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="cof-account">Account & Billing URL</label></th>
                            <td>
                                <input class="large-text" id="cof-account" type="url" name="cof_dash_settings[account]" value="<?php echo esc_attr($o['account']); ?>" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="cof-upgrade">Membership Upgrade URL</label></th>
                            <td>
                                <input class="large-text" id="cof-upgrade" type="url" name="cof_dash_settings[upgrade]" value="<?php echo esc_attr($o['upgrade']); ?>" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="cof-support">Help & Support URL</label></th>
                            <td>
                                <input class="large-text" id="cof-support" type="url" name="cof_dash_settings[support]" value="<?php echo esc_attr($o['support']); ?>" />
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button('Save Dashboard Settings'); ?>
            </form>
        </div>
        <?php
    }
}

/* ─── 6. SHORTCODE RENDERER [chosenones_dashboard] ───────────────────── */

add_action('init', function () {
    add_shortcode('chosenones_dashboard', 'cof_dash_render');
});

if (!function_exists('cof_dash_render')) {
    function cof_dash_render($atts = []) {
        if (!is_user_logged_in()) {
            $login_url = wp_login_url(get_permalink());
            $reg_url   = function_exists('pmpro_url') ? pmpro_url('levels') : wp_registration_url();
            return '
            <div class="cof-login-box" style="max-width:600px;margin:50px auto;padding:40px;text-align:center;background:#fffaf3;border:1px solid #ded6e5;border-radius:16px;box-shadow:0 4px 20px rgba(33,22,55,0.08);font-family:system-ui,-apple-system,sans-serif;">
                <div style="font-size:36px;margin-bottom:12px;">🔒</div>
                <h2 style="color:#211637;margin:0 0 14px;font-size:28px;">Welcome to ChosenOnes.Faith</h2>
                <p style="color:#554d60;font-size:18px;line-height:1.6;margin-bottom:24px;">Please sign in to access your courses, video teachings, and member community.</p>
                <div style="display:flex;gap:14px;justify-content:center;flex-wrap:wrap;">
                    <a href="' . esc_url($login_url) . '" style="background:#211637;color:#fff!important;padding:14px 28px;border-radius:10px;text-decoration:none;font-weight:700;font-size:17px;min-height:48px;display:inline-flex;align-items:center;">Sign In to Your Account</a>
                    <a href="' . esc_url($reg_url) . '" style="background:#e9bd6b;color:#211637!important;padding:14px 28px;border-radius:10px;text-decoration:none;font-weight:700;font-size:17px;min-height:48px;display:inline-flex;align-items:center;">Explore Membership</a>
                </div>
            </div>';
        }

        $user_id   = get_current_user_id();
        $user      = wp_get_current_user();
        $state     = cof_get_user_membership_state($user_id);
        $is_prem   = ($state === 'premium');
        $is_exp    = ($state === 'expired');
        $is_canc   = ($state === 'cancelled');
        $is_free   = ($state === 'free');

        $o         = cof_dash_options();
        $name      = $user->first_name ?: $user->display_name;
        $course    = cof_get_user_course_progress($user_id);
        $quota     = cof_get_user_search_quota($user_id, $state);
        $saved_cnt = cof_get_saved_videos_count($user_id);

        ob_start();
        ?>
        <div class="cof-wrap" id="cof-dashboard-root" aria-label="ChosenOnes Member Dashboard">

            <?php /* Admin Member State Switcher Bar */ ?>
            <?php if (current_user_can('manage_options')): ?>
                <aside class="cof-admin-bar" aria-label="Administrator Testing Bar">
                    <span class="cof-admin-label">⚙️ Admin QA Switcher:</span>
                    <a href="?cof_preview_state=real" class="cof-admin-pill <?php echo (!isset($_GET['cof_preview_state']) || $_GET['cof_preview_state']==='real')?'active':'';?>">Actual Account (<?php echo esc_html(strtoupper($state));?>)</a>
                    <a href="?cof_preview_state=premium" class="cof-admin-pill <?php echo (isset($_GET['cof_preview_state']) && $_GET['cof_preview_state']==='premium')?'active':'';?>">Premium Member</a>
                    <a href="?cof_preview_state=free" class="cof-admin-pill <?php echo (isset($_GET['cof_preview_state']) && $_GET['cof_preview_state']==='free')?'active':'';?>">Free Member</a>
                    <a href="?cof_preview_state=expired" class="cof-admin-pill <?php echo (isset($_GET['cof_preview_state']) && $_GET['cof_preview_state']==='expired')?'active':'';?>">Expired Member</a>
                    <a href="?cof_preview_state=cancelled" class="cof-admin-pill <?php echo (isset($_GET['cof_preview_state']) && $_GET['cof_preview_state']==='cancelled')?'active':'';?>">Canceled Member</a>
                </aside>
            <?php endif; ?>

            <?php /* Header & Branding */ ?>
            <header class="cof-header">
                <div class="cof-brand">
                    <?php if (!empty($o['logo'])): ?>
                        <img src="<?php echo esc_url($o['logo']); ?>" alt="ChosenOnes.Faith" class="cof-logo" />
                    <?php else: ?>
                        <div class="cof-brand-text">
                            <span class="cof-logo-symbol">✦</span>
                            <span class="cof-logo-name">ChosenOnes.Faith</span>
                        </div>
                    <?php endif; ?>
                    <span class="cof-tagline">UNCOVER · AWAKEN · TRANSFORM</span>
                </div>

                <div class="cof-member-badge cof-badge-<?php echo esc_attr($state); ?>">
                    <?php
                    if ($is_prem) {
                        echo '✦ Chosen Ones Premium Member';
                    } elseif ($is_exp) {
                        echo '⚠️ Membership Expired';
                    } elseif ($is_canc) {
                        echo 'Membership Inactive';
                    } else {
                        echo 'Explorer Member';
                    }
                    ?>
                </div>
            </header>

            <?php /* Status Notice for Expired or Canceled accounts */ ?>
            <?php if ($is_exp): ?>
                <div class="cof-notice cof-notice-warning" role="alert">
                    <div class="cof-notice-content">
                        <strong>Your Premium Membership has expired.</strong>
                        <p>Renew today to regain full course access, 10 daily video searches, and exclusive interviews.</p>
                    </div>
                    <a href="<?php echo esc_url($o['renew_url']); ?>" class="cof-btn cof-btn-gold">Renew Membership</a>
                </div>
            <?php elseif ($is_canc): ?>
                <div class="cof-notice cof-notice-info" role="alert">
                    <div class="cof-notice-content">
                        <strong>Your Premium subscription was canceled.</strong>
                        <p>You currently have Explorer access (1 free daily video search). Reactivate anytime to resume your courses.</p>
                    </div>
                    <a href="<?php echo esc_url($o['upgrade']); ?>" class="cof-btn cof-btn-gold">Reactivate Premium</a>
                </div>
            <?php endif; ?>

            <?php /* Welcome Section */ ?>
            <section class="cof-welcome">
                <p class="cof-eyebrow">MY SPIRITUAL JOURNEY</p>
                <h1>Welcome back, <?php echo esc_html($name); ?>.</h1>
                <p class="cof-welcome-sub">Your personal sanctuary for learning, inquiry, and connection.</p>
            </section>

            <?php /* Hero Banner: Continue Learning (Premium) vs Explore Premium (Free/Expired/Canceled) */ ?>
            <?php if ($is_prem): ?>
                <section class="cof-hero cof-hero-premium" aria-labelledby="cof-current-course">
                    <div class="cof-hero-body">
                        <p class="cof-hero-tag">CONTINUE LEARNING</p>
                        <h2 id="cof-current-course"><?php echo esc_html($course['course_title']); ?></h2>
                        <p class="cof-hero-lesson"><?php echo esc_html($course['lesson_title']); ?></p>

                        <?php /* Real Course Progress Bar */ ?>
                        <div class="cof-progress-container" aria-label="Course completion progress">
                            <div class="cof-progress-bar-bg">
                                <div class="cof-progress-bar-fill" style="width: <?php echo esc_attr($course['progress_pct']); ?>%;"></div>
                            </div>
                            <span class="cof-progress-text"><?php echo esc_html($course['progress_pct']); ?>% Completed</span>
                        </div>
                    </div>
                    <div class="cof-hero-action">
                        <a href="<?php echo esc_url($course['resume_url']); ?>" class="cof-btn cof-btn-hero">
                            <?php echo ($course['progress_pct'] > 0) ? 'Continue My Course →' : 'Start My Course →'; ?>
                        </a>
                    </div>
                </section>
            <?php else: ?>
                <section class="cof-hero cof-hero-free" aria-labelledby="cof-free-title">
                    <div class="cof-hero-body">
                        <p class="cof-hero-tag">CHOSEN ONES PREMIUM</p>
                        <h2 id="cof-free-title">Go deeper into your spiritual awakening</h2>
                        <p>Unlock our complete course library (all courses under $100 included), 10 daily video searches, and exclusive interviews with Ralph Amato for $29.95/month.</p>
                    </div>
                    <div class="cof-hero-action">
                        <a href="<?php echo esc_url($o['upgrade']); ?>" class="cof-btn cof-btn-hero">Explore Premium Membership →</a>
                    </div>
                </section>
            <?php endif; ?>

            <?php /* Video Search Engine Status & Live Daily Quota Widget */ ?>
            <section class="cof-search-banner" aria-label="Video Search Engine Status">
                <div class="cof-search-header">
                    <div class="cof-search-icon" aria-hidden="true">🔎</div>
                    <div class="cof-search-meta">
                        <h2>Spiritual Video Discovery Engine</h2>
                        <p>Search Ralph Amato's 700+ video teachings with deep context and receive up to <strong>3 video recommendations</strong> per search.</p>
                    </div>
                </div>

                <div class="cof-search-quota-card">
                    <div class="cof-quota-details">
                        <span class="cof-quota-badge">
                            <?php if ($quota['is_unlimited']): ?>
                                Unlimited Searches (Administrator)
                            <?php else: ?>
                                <?php echo esc_html($quota['searches_left']); ?> of <?php echo esc_html($quota['allowed_limit']); ?> daily searches available
                            <?php endif; ?>
                        </span>

                        <?php if (!$quota['is_unlimited'] && $quota['searches_left'] <= 0): ?>
                            <div class="cof-countdown-wrap" data-seconds="<?php echo esc_attr($quota['seconds_until_reset']); ?>">
                                <span class="cof-timer-icon">⏳</span>
                                <span class="cof-timer-text">Daily limit reached. Resets in: <strong class="cof-countdown-display">Calculating...</strong></span>
                            </div>
                        <?php else: ?>
                            <span class="cof-quota-subtext">
                                <?php echo $is_prem ? 'Premium Plan: 10 daily searches refreshed every 24 hours.' : 'Free Explorer: 1 daily search refreshed every 24 hours.'; ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="cof-quota-action">
                        <a href="<?php echo esc_url($o['videos']); ?>" class="cof-btn cof-btn-search">
                            Open Video Search Chat →
                        </a>
                    </div>
                </div>
            </section>

            <?php /* Main 6-Resource Grid */ ?>
            <section class="cof-section" aria-labelledby="cof-actions">
                <h2 id="cof-actions" class="cof-section-title">What would you like to explore today?</h2>

                <div class="cof-grid">
                    <?php /* Card 1: Courses */ ?>
                    <article class="cof-card">
                        <div class="cof-card-icon" aria-hidden="true">📚</div>
                        <h3>My Courses & Lessons</h3>
                        <p>Browse the course library, study scripture in historical context, and track your progress.</p>
                        <?php if ($is_prem): ?>
                            <a class="cof-card-link" href="<?php echo esc_url($o['courses']); ?>">Browse All Courses →</a>
                        <?php else: ?>
                            <div class="cof-card-footer-split">
                                <a class="cof-card-link" href="<?php echo esc_url($o['courses']); ?>">Preview Courses</a>
                                <span class="cof-lock-tag">✦ Premium</span>
                            </div>
                        <?php endif; ?>
                    </article>

                    <?php /* Card 2: Video Search */ ?>
                    <article class="cof-card">
                        <div class="cof-card-icon" aria-hidden="true">🎬</div>
                        <h3>Video Teachings</h3>
                        <p>Search over 700 teachings by topic, scripture, or question to find the exact clips you need.</p>
                        <a class="cof-card-link" href="<?php echo esc_url($o['videos']); ?>">Search Videos (3 Results) →</a>
                    </article>

                    <?php /* Card 3: Community Forum */ ?>
                    <article class="cof-card">
                        <div class="cof-card-icon" aria-hidden="true">💬</div>
                        <h3>Community Forum</h3>
                        <p>Share reflections, ask thoughtful questions, and connect with fellow spiritual seekers.</p>
                        <a class="cof-card-link" href="<?php echo esc_url($o['community']); ?>">Enter Community Circles →</a>
                    </article>

                    <?php /* Card 4: Upcoming Events */ ?>
                    <article class="cof-card">
                        <div class="cof-card-icon" aria-hidden="true">📅</div>
                        <h3>Live Gatherings & Events</h3>
                        <p><?php echo esc_html($o['event_title']); ?> — <?php echo esc_html($o['event_date']); ?></p>
                        <a class="cof-card-link" href="<?php echo esc_url($o['events']); ?>">View Event Schedule →</a>
                    </article>

                    <?php /* Card 5: Exclusive Interviews */ ?>
                    <article class="cof-card">
                        <div class="cof-card-icon" aria-hidden="true">🎙️</div>
                        <h3>Exclusive Interviews</h3>
                        <p>In-depth conversations with guest teachers, historians, and Ralph Amato.</p>
                        <?php if ($is_prem): ?>
                            <a class="cof-card-link" href="<?php echo esc_url($o['interviews']); ?>">Watch Interviews →</a>
                        <?php else: ?>
                            <div class="cof-card-footer-split">
                                <a class="cof-card-link" href="<?php echo esc_url($o['upgrade']); ?>">Unlock with Premium</a>
                                <span class="cof-lock-tag">✦ Premium</span>
                            </div>
                        <?php endif; ?>
                    </article>

                    <?php /* Card 6: Saved Videos & Bookmarks */ ?>
                    <article class="cof-card">
                        <div class="cof-card-icon" aria-hidden="true">🔖</div>
                        <h3>Saved Videos</h3>
                        <p>
                            <?php if ($saved_cnt > 0): ?>
                                You have <strong><?php echo (int)$saved_cnt; ?> bookmarked teachings</strong> saved for re-watching.
                            <?php else: ?>
                                Bookmark and save teachings to easily revisit meaningful moments anytime.
                            <?php endif; ?>
                        </p>
                        <a class="cof-card-link" href="<?php echo esc_url($o['saved']); ?>">View My Saved Videos →</a>
                    </article>
                </div>
            </section>

            <?php /* Footer Management Links */ ?>
            <footer class="cof-footer">
                <div class="cof-footer-links">
                    <a class="cof-footer-link" href="<?php echo esc_url($o['account']); ?>">👤 Manage My Account & Billing</a>
                    <a class="cof-footer-link" href="<?php echo esc_url($o['support']); ?>">❓ Help & Member Support</a>
                    <a class="cof-footer-link" href="<?php echo esc_url(wp_logout_url(home_url())); ?>">Sign Out</a>
                </div>
                <div class="cof-footer-copy">
                    ChosenOnes.Faith — Dedicated to seekers, spiritual warriors, and honest inquiry.
                </div>
            </footer>

        </div>

        <?php /* Inline Scoped CSS & JavaScript for WPCode compatibility */ ?>
        <style>
        .cof-wrap {
            --cof-purple: #211637;
            --cof-purple-dark: #19102b;
            --cof-purple-light: #322350;
            --cof-gold: #b78029;
            --cof-gold-bright: #e9bd6b;
            --cof-peach: #F2926F;
            --cof-ink: #201b29;
            --cof-sub: #554d60;
            --cof-paper: #fffaf3;
            --cof-card-bg: #ffffff;
            --cof-border: #e2d9e8;

            max-width: 1200px;
            margin: 0 auto;
            padding: 10px 20px 50px;
            background: var(--cof-paper);
            color: var(--cof-ink);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            font-size: 18px;
            line-height: 1.6;
            box-sizing: border-box;
        }
        .cof-wrap * { box-sizing: border-box; }
        .cof-wrap a:focus-visible { outline: 3px solid var(--cof-gold-bright); outline-offset: 3px; }

        /* Admin Switcher Bar */
        .cof-admin-bar {
            background: #2b1f42;
            color: #f7e6c4;
            padding: 10px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            font-size: 14px;
        }
        .cof-admin-label { font-weight: 700; color: #fff; }
        .cof-admin-pill {
            background: rgba(255,255,255,0.12);
            color: #fff!important;
            padding: 5px 12px;
            border-radius: 99px;
            text-decoration: none!important;
            font-size: 13px;
            transition: all 0.2s;
        }
        .cof-admin-pill:hover, .cof-admin-pill.active {
            background: var(--cof-gold-bright);
            color: #211637!important;
            font-weight: 700;
        }

        /* Header */
        .cof-header {
            background: linear-gradient(135deg, var(--cof-purple-dark) 0%, var(--cof-purple) 60%, var(--cof-purple-light) 100%);
            color: #ffffff;
            padding: 24px 30px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
            box-shadow: 0 4px 18px rgba(33, 22, 55, 0.15);
        }
        .cof-brand { display: flex; flex-direction: column; gap: 4px; }
        .cof-logo { max-width: 240px; max-height: 70px; object-fit: contain; }
        .cof-brand-text { display: flex; align-items: center; gap: 8px; font-family: Georgia, serif; font-size: 28px; font-weight: 700; }
        .cof-logo-symbol { color: var(--cof-gold-bright); font-size: 24px; }
        .cof-tagline { font-size: 12px; letter-spacing: 0.14em; color: #f5dcaf; font-weight: 600; }

        .cof-member-badge {
            border: 1px solid var(--cof-gold-bright);
            padding: 8px 16px;
            border-radius: 99px;
            font-size: 15px;
            font-weight: 700;
            background: rgba(233, 189, 107, 0.12);
            color: #ffe8b8;
        }
        .cof-badge-expired, .cof-badge-cancelled {
            border-color: #fca5a5;
            color: #fee2e2;
            background: rgba(239, 68, 68, 0.15);
        }

        /* Notices */
        .cof-notice {
            margin-top: 18px;
            padding: 18px 24px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            flex-wrap: wrap;
        }
        .cof-notice-warning { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .cof-notice-info { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; }
        .cof-notice-content strong { display: block; font-size: 17px; margin-bottom: 4px; }
        .cof-notice-content p { margin: 0; font-size: 15px; }

        /* Welcome */
        .cof-welcome { padding: 28px 0 16px; }
        .cof-eyebrow {
            font-size: 13px;
            letter-spacing: 0.12em;
            font-weight: 800;
            color: var(--cof-gold);
            margin: 0 0 6px;
            text-transform: uppercase;
        }
        .cof-welcome h1 {
            color: var(--cof-purple);
            font-size: clamp(30px, 4vw, 42px);
            font-weight: 800;
            margin: 0 0 8px;
            line-height: 1.2;
        }
        .cof-welcome-sub { color: var(--cof-sub); font-size: 19px; margin: 0; }

        /* Hero */
        .cof-hero {
            border-radius: 18px;
            padding: 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 28px;
            flex-wrap: wrap;
            box-shadow: 0 8px 24px rgba(33, 22, 55, 0.12);
            margin-bottom: 26px;
        }
        .cof-hero-premium {
            background: linear-gradient(120deg, #24143a 0%, #3a225c 50%, #4b2d74 100%);
            color: #ffffff;
        }
        .cof-hero-free {
            background: linear-gradient(120deg, #2c1a45 0%, #3f2761 100%);
            color: #ffffff;
            border-left: 6px solid var(--cof-gold-bright);
        }
        .cof-hero-body { max-width: 680px; }
        .cof-hero-tag {
            font-size: 13px;
            letter-spacing: 0.12em;
            font-weight: 800;
            color: var(--cof-gold-bright);
            margin: 0 0 8px;
        }
        .cof-hero h2 {
            font-size: clamp(24px, 3.2vw, 34px);
            font-weight: 700;
            margin: 0 0 8px;
            line-height: 1.25;
            color: #ffffff;
        }
        .cof-hero p { margin: 0 0 14px; font-size: 18px; line-height: 1.5; color: #f3ebfa; }
        .cof-hero-lesson { font-style: italic; color: #fbe6c4!important; }

        /* Progress Bar */
        .cof-progress-container {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-top: 14px;
        }
        .cof-progress-bar-bg {
            flex: 1;
            max-width: 320px;
            height: 12px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 99px;
            overflow: hidden;
        }
        .cof-progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #e9bd6b, #f2926f);
            border-radius: 99px;
            transition: width 0.5s ease-out;
        }
        .cof-progress-text { font-size: 15px; font-weight: 700; color: #ffe2a0; white-space: nowrap; }

        /* Buttons */
        .cof-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 14px 26px;
            border-radius: 10px;
            font-weight: 800;
            text-decoration: none!important;
            font-size: 17px;
            min-height: 52px;
            cursor: pointer;
            transition: transform 0.15s, box-shadow 0.15s;
        }
        .cof-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 14px rgba(0,0,0,0.15); }
        .cof-btn-hero {
            background: var(--cof-gold-bright);
            color: #211637!important;
        }
        .cof-btn-gold {
            background: var(--cof-gold-bright);
            color: #211637!important;
            font-size: 15px;
            padding: 10px 18px;
            min-height: 44px;
        }
        .cof-btn-search {
            background: #211637;
            color: #ffffff!important;
        }
        .cof-btn-search:hover { background: #322350; }

        /* Video Search Engine Card */
        .cof-search-banner {
            background: #ffffff;
            border: 2px solid var(--cof-border);
            border-radius: 18px;
            padding: 26px;
            margin-bottom: 30px;
            box-shadow: 0 4px 16px rgba(33, 22, 55, 0.05);
        }
        .cof-search-header {
            display: flex;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 18px;
        }
        .cof-search-icon { font-size: 34px; line-height: 1; }
        .cof-search-meta h2 {
            font-size: 24px;
            color: var(--cof-purple);
            margin: 0 0 6px;
            font-weight: 700;
        }
        .cof-search-meta p { margin: 0; color: var(--cof-sub); font-size: 17px; line-height: 1.5; }

        .cof-search-quota-card {
            background: #fbf7f1;
            border: 1px solid #ebd9c5;
            border-radius: 12px;
            padding: 18px 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
        }
        .cof-quota-badge {
            display: inline-block;
            background: #211637;
            color: #fff;
            padding: 6px 14px;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 700;
            margin-bottom: 6px;
        }
        .cof-quota-subtext { display: block; font-size: 15px; color: var(--cof-sub); }

        .cof-countdown-wrap {
            margin-top: 6px;
            display: flex;
            align-items: center;
            gap: 8px;
            color: #b91c1c;
            font-size: 15px;
        }
        .cof-countdown-display { font-size: 16px; color: #991b1b; }

        /* 6-Resource Grid */
        .cof-section-title {
            color: var(--cof-purple);
            font-size: 26px;
            font-weight: 700;
            margin: 0 0 20px;
        }
        .cof-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 20px;
        }
        .cof-card {
            background: #ffffff;
            border: 1px solid var(--cof-border);
            border-radius: 16px;
            padding: 24px;
            display: flex;
            flex-direction: column;
            box-shadow: 0 4px 14px rgba(33, 22, 55, 0.04);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .cof-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 22px rgba(33, 22, 55, 0.08);
            border-color: #d1c5d9;
        }
        .cof-card-icon { font-size: 32px; margin-bottom: 14px; }
        .cof-card h3 {
            font-size: 21px;
            font-weight: 700;
            color: var(--cof-purple);
            margin: 0 0 10px;
        }
        .cof-card p {
            color: var(--cof-sub);
            font-size: 16px;
            line-height: 1.5;
            margin: 0 0 18px;
            flex: 1;
        }
        .cof-card-link {
            font-weight: 800;
            color: var(--cof-gold)!important;
            text-decoration: underline!important;
            font-size: 17px;
            display: inline-block;
            min-height: 44px;
            line-height: 44px;
        }
        .cof-card-link:hover { color: var(--cof-purple)!important; }

        .cof-card-footer-split {
            display: flex;
            align-items: center;
            justify-content: space-between;
            min-height: 44px;
        }
        .cof-lock-tag {
            background: #fdf5e6;
            color: var(--cof-gold);
            border: 1px solid #f3d59e;
            padding: 4px 10px;
            border-radius: 99px;
            font-size: 13px;
            font-weight: 700;
        }

        /* Footer */
        .cof-footer {
            border-top: 2px solid var(--cof-border);
            margin-top: 40px;
            padding-top: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        .cof-footer-links { display: flex; gap: 24px; flex-wrap: wrap; }
        .cof-footer-link {
            color: var(--cof-sub)!important;
            font-weight: 700;
            text-decoration: none!important;
            font-size: 16px;
        }
        .cof-footer-link:hover { color: var(--cof-purple)!important; text-decoration: underline!important; }
        .cof-footer-copy { font-size: 14px; color: #887d95; }

        /* Mobile & Responsive (Ages 45-75 Senior Friendly) */
        @media (max-width: 900px) {
            .cof-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .cof-wrap { padding: 10px 16px 40px; font-size: 17px; }
        }
        @media (max-width: 640px) {
            .cof-grid { grid-template-columns: 1fr; }
            .cof-header { padding: 18px; }
            .cof-hero { padding: 22px; }
            .cof-btn { width: 100%; }
            .cof-search-quota-card { flex-direction: column; align-items: stretch; }
            .cof-quota-action { width: 100%; }
            .cof-footer { flex-direction: column; align-items: flex-start; }
        }
        </style>

        <script>
        (function() {
            var wraps = document.querySelectorAll('.cof-countdown-wrap');
            wraps.forEach(function(wrap) {
                var seconds = parseInt(wrap.getAttribute('data-seconds'), 10);
                if (isNaN(seconds) || seconds <= 0) return;

                var display = wrap.querySelector('.cof-countdown-display');
                if (!display) return;

                function update() {
                    if (seconds <= 0) {
                        display.textContent = 'Ready! Refresh to search.';
                        return;
                    }
                    var h = Math.floor(seconds / 3600);
                    var m = Math.floor((seconds % 3600) / 60);
                    var s = seconds % 60;
                    display.textContent = (h > 0 ? h + 'h ' : '') + m + 'm ' + (s < 10 ? '0' : '') + s + 's';
                    seconds--;
                    setTimeout(update, 1000);
                }
                update();
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }
}
