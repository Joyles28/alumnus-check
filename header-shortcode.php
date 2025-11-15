<?php
/**
 * Header Navigation Shortcode
 * Usage: [alumnus_header]
 * Static design only (no dynamic menu fetching yet) per request.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// Helper to resolve the login page URL (page that contains [coenect_login])
if ( ! function_exists( 'alumnus_resolve_login_page_url' ) ) {
    function alumnus_resolve_login_page_url() {
        // Allow override via filter/option first
        $opt_page_id = (int) get_option('alumnus_login_page_id');
        if ( $opt_page_id ) {
            $link = get_permalink( $opt_page_id );
            if ( $link ) { return apply_filters('alumnus_login_page_url', $link ); }
        }
        // Discover a page that contains the login shortcode
        $candidate = '';
        $pages = get_posts(array(
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => 50,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'suppress_filters' => true,
        ));
        if ( $pages ) {
            foreach ( $pages as $p ) {
                if ( is_object($p) && !empty($p->post_content) && function_exists('has_shortcode') && has_shortcode($p->post_content, 'coenect_login') ) {
                    $candidate = get_permalink( $p->ID );
                    break;
                }
            }
        }
        if ( ! $candidate ) { $candidate = home_url('/'); }
        return apply_filters('alumnus_login_page_url', $candidate );
    }
}

function alumnus_render_header_shortcode() {
    // Enqueue Font Awesome
    if ( ! wp_style_is( 'font-awesome', 'enqueued' ) ) {
        wp_enqueue_style( 'font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css', array(), '6.4.0' );
    }
    
    // Enqueue header JavaScript
    $js_rel_path = 'assets/js/header.js';
    $js_path     = plugin_dir_path( __FILE__ ) . $js_rel_path;
    $js_ver      = file_exists( $js_path ) ? filemtime( $js_path ) : '1.0.0';
    
    wp_enqueue_script(
        'alumnus-header',
        plugin_dir_url( __FILE__ ) . $js_rel_path,
        array('jquery'),
        $js_ver,
        true
    );

    // Compute dynamic URLs
    $community_feed_url = apply_filters( 'alumnus_community_feed_page_url', home_url( '/community-feed/' ) );
    $directory_url = apply_filters( 'alumnus_directory_page_url', home_url( '/directory/' ) );
    $profile_page  = function_exists('alumnus_resolve_profile_page_url') ? alumnus_resolve_profile_page_url() : home_url('/');
    $login_page    = function_exists('alumnus_resolve_login_page_url') ? alumnus_resolve_login_page_url() : home_url('/');

    $is_alumni_logged_in = function_exists('alumnus_is_logged_in') ? alumnus_is_logged_in() : false;
    $logout_url = '';
    if ( $is_alumni_logged_in && function_exists('alumnus_logout_url') ) {
        $logout_url = alumnus_logout_url();
    }

    // Profile link behavior: if logged in (alumni session), go to profile page; otherwise go to login page.
    $profile_link = $is_alumni_logged_in ? $profile_page : $login_page;

    ob_start();
    ?>
    <div class="alumnus-header-bar">
        <div class="alumnus-header-inner">
            <!-- Hamburger Menu Button (Mobile Only) -->
            <button class="ahb-hamburger" aria-label="Menu">
                <i class="fa-solid fa-bars"></i>
            </button>

            <div class="ahb-right">
                <a href="<?php echo esc_url( $community_feed_url ); ?>" class="ahb-community-feed-btn">Community Feed</a>
                <a href="<?php echo esc_url( $directory_url ); ?>" class="ahb-directory-btn">Directory</a>
                <a href="<?php echo esc_url( $profile_link ); ?>" class="ahb-profile-btn">Profile</a>
                <?php if ( $is_alumni_logged_in && $logout_url ) : ?>
                    <a href="<?php echo esc_url( $logout_url ); ?>" class="ahb-logout-btn">Logout</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Mobile Sidebar Menu -->
    <div class="ahb-sidebar-overlay"></div>
    <div class="ahb-sidebar">
        <button class="ahb-sidebar-close" aria-label="Close Menu">
            <i class="fa-solid fa-xmark"></i>
        </button>
        <div class="ahb-sidebar-content">
            <a href="<?php echo esc_url( $community_feed_url ); ?>" class="ahb-sidebar-item">Community Feed</a>
            <a href="<?php echo esc_url( $directory_url ); ?>" class="ahb-sidebar-item">Directory</a>
            <a href="<?php echo esc_url( $profile_link ); ?>" class="ahb-sidebar-item">Profile</a>
            <?php if ( $is_alumni_logged_in && $logout_url ) : ?>
                <a href="<?php echo esc_url( $logout_url ); ?>" class="ahb-sidebar-item ahb-sidebar-logout">Logout</a>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

add_shortcode( 'alumnus_header', 'alumnus_render_header_shortcode' );
