<?php
// ===== Enqueue Login Styles =====
function coenect_login_enqueue_styles() {
    // Ensure color-variables.css is loaded
    wp_enqueue_style(
        'wordpress-plugin-template-colors',
        plugin_dir_url(__FILE__) . 'assets/css/color-variables.css',
        array(),
        '1.0.0'
    );
    
    wp_enqueue_style(
        'coenect-login-styles',
        plugin_dir_url(__FILE__) . 'assets/css/login.css',
        array( 'wordpress-plugin-template-colors' ),
        '1.0.0'
    );
}
add_action('wp_enqueue_scripts', 'coenect_login_enqueue_styles');

// ===== Helper: Resolve Profile Page URL =====
if (!function_exists('alumnus_resolve_profile_page_url')) {
    function alumnus_resolve_profile_page_url() {
        // 1) Allow override via saved option
        $opt_page_id = (int) get_option('alumnus_profile_page_id');
        if ($opt_page_id) {
            $link = get_permalink($opt_page_id);
            if ($link) {
                return apply_filters('alumnus_profile_page_url', $link);
            }
        }

        // 2) Discover first published page that contains [alumni_profile] shortcode
        $candidate = '';
        $pages = get_posts([
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => 50,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'suppress_filters' => true,
        ]);
        
        if ($pages) {
            foreach ($pages as $p) {
                if (is_object($p) && !empty($p->post_content) && function_exists('has_shortcode') && has_shortcode($p->post_content, 'alumni_profile')) {
                    $candidate = get_permalink($p->ID);
                    break;
                }
            }
        }

        if (!$candidate) {
            $candidate = home_url('/');
        }

        return apply_filters('alumnus_profile_page_url', $candidate);
    }
}

// ===== Helper: Get Profile URL with alumni_id =====
if (!function_exists('alumnus_get_profile_url')) {
    function alumnus_get_profile_url($alumni_id, $profile_page_url = '') {
        if (!$profile_page_url) {
            $profile_page_url = alumnus_resolve_profile_page_url();
        }
        return add_query_arg('alumni_id', rawurlencode($alumni_id), $profile_page_url);
    }
}

// ===== Shortcode: User Login with WordPress Authentication =====
function coenect_login_form_shortcode() {
    ob_start();

    global $wpdb;
    $db = $wpdb;

    // Helper: detect table names (prefixed or plain)
    $detect_tables = function() use ($db) {
        $tables = ['user' => 'user', 'alumni' => 'alumni'];
        foreach ([$db->prefix . 'user', 'user'] as $cand) {
            $exists = $db->get_var($db->prepare('SHOW TABLES LIKE %s', $cand));
            if (!empty($exists)) {
                $tables['user'] = $cand;
                break;
            }
        }
        foreach ([$db->prefix . 'alumni', 'alumni'] as $cand) {
            $exists = $db->get_var($db->prepare('SHOW TABLES LIKE %s', $cand));
            if (!empty($exists)) {
                $tables['alumni'] = $cand;
                break;
            }
        }
        return $tables;
    };

    $tables = $detect_tables();

    // Helper: check if username column exists in user table
    $user_table_has_username = function($table_name) use ($db) {
        $col = $db->get_var($db->prepare("SHOW COLUMNS FROM `{$table_name}` LIKE %s", 'username'));
        return !empty($col);
    };

    // Helper: fetch user row by identifier (username or user_id)
    $get_user_by_identifier = function($identifier) use ($db, $tables, $user_table_has_username) {
        $has_username = $user_table_has_username($tables['user']);
        if ($has_username) {
            // Try username, then user id in one query
            return $db->get_row($db->prepare(
                "SELECT * FROM `{$tables['user']}` WHERE `username` = %s OR `user` = %s LIMIT 1",
                $identifier,
                $identifier
            ));
        } else {
            return $db->get_row($db->prepare(
                "SELECT * FROM `{$tables['user']}` WHERE `user` = %s LIMIT 1",
                $identifier
            ));
        }
    };

    // Ensure wp_check_password is available for verifying WP-style hashes ($P$/portable)
    if (!function_exists('wp_check_password') && defined('ABSPATH')) {
        @require_once ABSPATH . WPINC . '/pluggable.php';
    }

    $errors = [];
    $username_echo = '';

    // --- Handle login submission ---
    if (isset($_POST['login_submit'])) {
        $nonce = isset($_POST['coenect_login_nonce']) ? $_POST['coenect_login_nonce'] : '';
        if (!wp_verify_nonce($nonce, 'coenect_login_action')) {
            $errors[] = __('Security check failed. Please try again.', 'alumnus');
        } else {
            $username = sanitize_text_field(wp_unslash($_POST['username'] ?? ''));
            $username_echo = $username;
            $password = isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : '';

            // Query user data from custom table (by username or user id)
            $user = $get_user_by_identifier($username);

            if ($user) {
                // Check password (supports plain, bcrypt/argon2, and WordPress portable hashes)
                $stored = (string) $user->password;
                $verified = false;
                if ($stored === $password) {
                    $verified = true;
                } elseif (password_verify($password, $stored)) {
                    $verified = true;
                } elseif (function_exists('wp_check_password') && wp_check_password($password, $stored)) {
                    $verified = true;
                }

                if ($verified) {
                    // Set custom session state for the logged in alumni
                    $remember_me = !empty($_POST['remember_me']);
                    if (function_exists('alumnus_set_login_state')) {
                        alumnus_set_login_state($user, (bool) $remember_me);
                    }

                    // If password is default 123456 -> show reset modal
                    if ($password === '123456') {
                        ?>
                        <script>
                            document.addEventListener("DOMContentLoaded", function() {
                                var modal = document.getElementById("resetModal");
                                if (modal) { modal.classList.add("active"); }
                            });
                        </script>
                        <?php
                    } else {
                        // Prefer redirect to provided safe URL; fallback to profile page
                        $profile_page_url = function_exists('alumnus_resolve_profile_page_url') ? alumnus_resolve_profile_page_url() : home_url('/');
                        $redir_post      = isset($_POST['redirect_to']) ? (string) wp_unslash($_POST['redirect_to']) : '';
                        $safe_redirect   = $redir_post !== '' ? wp_validate_redirect($redir_post, '') : '';
                        $target_url      = $safe_redirect !== '' ? $safe_redirect : $profile_page_url;
                        wp_safe_redirect( $target_url );
                        exit;
                    }
                } else {
                    $errors[] = __('Incorrect password.', 'alumnus');
                }
            } else {
                $errors[] = __('User not found.', 'alumnus');
            }
        }
    }

    // --- Handle password reset ---
    if (isset($_POST['reset_submit'])) {
        $nonce = isset($_POST['coenect_reset_nonce']) ? $_POST['coenect_reset_nonce'] : '';
        if (!wp_verify_nonce($nonce, 'coenect_reset_action')) {
            $errors[] = __('Security check failed on password reset.', 'alumnus');
        } else {
            $username = sanitize_text_field(wp_unslash($_POST['reset_username'] ?? ''));
            $new_password = isset($_POST['new_password']) ? (string) wp_unslash($_POST['new_password']) : '';

            if (strlen($new_password) < 6) {
                $errors[] = __('Password must be at least 6 characters.', 'alumnus');
            } else {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

                // Find the account by username or user id first
                $user_row = $get_user_by_identifier($username);
                if ($user_row) {
                    // Update using primary key column `user`
                    $updated = $db->update(
                        $tables['user'],
                        ['password' => $hashed_password],
                        ['user' => $user_row->user],
                        ['%s'],
                        ['%s']
                    );

                    if ($updated !== false) {
                        $profile_page_url = function_exists('alumnus_resolve_profile_page_url') ? alumnus_resolve_profile_page_url() : home_url('/');
                        // On successful reset, redirect to profile page
                        wp_safe_redirect( $profile_page_url );
                        exit;
                    } else {
                        $errors[] = __('Failed to reset password.', 'alumnus');
                    }
                } else {
                    $errors[] = __('User not found for password reset.', 'alumnus');
                }
            }
        }
    }
    ?>

    <div class="coenect-login-wrapper">
        <!-- Home Button -->
        <a href="<?php echo esc_url(home_url('/landing-page/')); ?>" class="coenect-login-home-btn">
            <div class="coenect-login-home-icon">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                    <polyline points="9 22 9 12 15 12 15 22"></polyline>
                </svg>
            </div>
            <span class="coenect-login-home-text"><?php echo esc_html__('Home Page', 'alumnus'); ?></span>
        </a>

        <!-- Main Container -->
        <div class="coenect-login-container">
            <div class="coenect-login-split">
                <!-- Left Side - Logo -->
                <div class="coenect-login-left">
                    <img src="<?php echo plugin_dir_url(__FILE__) . 'assets/images/logo.png'; ?>" alt="XU Engineering Logo" class="coenect-logo-image">
                </div>

                <!-- Vertical Divider -->
                <div class="coenect-login-divider"></div>

                <!-- Right Side - Login Form -->
                <div class="coenect-login-right">
                    <form method="post" class="coenect-login-form">
                        <?php wp_nonce_field('coenect_login_action', 'coenect_login_nonce'); ?>
                        
                        <?php if (!empty($errors)) : ?>
                            <div class="coenect-error-message">
                                <?php echo esc_html(implode(' ', $errors)); ?>
                            </div>
                        <?php endif; ?>
                        
                        <input 
                            type="text" 
                            name="username" 
                            class="coenect-form-input" 
                            placeholder="<?php echo esc_attr__('Username / User ID', 'alumnus'); ?>" 
                            value="<?php echo esc_attr($username_echo); ?>"
                            required
                        >
                        
                        <input 
                            type="password" 
                            name="password" 
                            class="coenect-form-input" 
                            placeholder="<?php echo esc_attr__('Password', 'alumnus'); ?>" 
                            required
                        >

                        <div class="coenect-form-options">
                            <label class="coenect-remember-me">
                                <input type="checkbox" name="remember_me" value="1" class="coenect-remember-checkbox-input" style="display:none;">
                                <div class="coenect-remember-checkbox"></div>
                                <span class="coenect-remember-label"><?php echo esc_html__('Remember me', 'alumnus'); ?></span>
                            </label>
                            
                            <a href="#" class="coenect-forgot-link"><?php echo esc_html__('Forgot password', 'alumnus'); ?></a>
                        </div>

                        <?php 
                        $redirect_raw   = isset($_GET['redirect_to']) ? (string) wp_unslash($_GET['redirect_to']) : '';
                        $redirect_safe  = $redirect_raw !== '' ? wp_validate_redirect($redirect_raw, '') : '';
                        ?>
                        <input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect_safe ); ?>">

                        <button type="submit" name="login_submit" class="coenect-login-btn">
                            <?php echo esc_html__('Log in', 'alumnus'); ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Password Reset Modal -->
        <div id="resetModal" class="coenect-modal-overlay">
            <div class="coenect-modal">
                <button type="button" class="coenect-modal-close" onclick="document.getElementById('resetModal').classList.remove('active')">
                    ✖
                </button>
                <h3><?php echo esc_html__('Reset Password', 'alumnus'); ?></h3>
                <form method="post" class="coenect-modal-form">
                    <?php wp_nonce_field('coenect_reset_action', 'coenect_reset_nonce'); ?>
                    <input type="hidden" name="reset_username" value="<?php echo isset($username_echo) ? esc_attr($username_echo) : ''; ?>">
                    
                    <label class="coenect-modal-label"><?php echo esc_html__('New Password', 'alumnus'); ?></label>
                    <input 
                        type="password" 
                        name="new_password" 
                        class="coenect-form-input" 
                        placeholder="<?php echo esc_attr__('Enter new password (min. 6 characters)', 'alumnus'); ?>" 
                        required
                    >

                    <button type="submit" name="reset_submit" class="coenect-login-btn">
                        <?php echo esc_html__('Update Password', 'alumnus'); ?>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
    // Remember me checkbox toggle
    document.addEventListener('DOMContentLoaded', function() {
        const checkbox = document.querySelector('.coenect-remember-checkbox');
        const hiddenInput = document.querySelector('.coenect-remember-checkbox-input');
        if (checkbox && hiddenInput) {
            checkbox.addEventListener('click', function() {
                this.classList.toggle('checked');
                hiddenInput.checked = this.classList.contains('checked');
            });
        }
    });
    </script>

    <?php
    return ob_get_clean();
}
add_shortcode('coenect_login', 'coenect_login_form_shortcode');
