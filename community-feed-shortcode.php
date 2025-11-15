<?php
/**
 * Community Feed Shortcode (static UI only – no functionality yet)
 * Usage: [community_feed]
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Render the community feed markup (dynamic posts; initial minimal implementation).
 * Pulls recent posts from custom `posts` table and aggregates counts from
 * `likes` and `shares` tables if they exist. Comments not yet implemented.
 *
 * @return string
 */
function alumnus_render_community_feed_shortcode() {
	global $wpdb;

	// Enqueue Font Awesome icons
	wp_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css', array(), '6.5.1');

	// Enqueue interactive JS for likes/shares/comments
	$js_rel = 'assets/js/community-feed.js';
	$js_abs = plugin_dir_path(__FILE__) . $js_rel;
	$js_ver = file_exists($js_abs) ? filemtime($js_abs) : '1.0.0';
	wp_enqueue_script('alumnus-community-feed', plugin_dir_url(__FILE__) . $js_rel, array(), $js_ver, true);
	wp_localize_script('alumnus-community-feed', 'AlumnusFeed', array(
		'ajaxUrl' => admin_url('admin-ajax.php'),
		'nonceLike' => wp_create_nonce('alumnus_like_toggle'),
		'nonceShare' => wp_create_nonce('alumnus_share'),
		'nonceComment' => wp_create_nonce('alumnus_add_comment'),
		'noncePost' => wp_create_nonce('alumnus_add_post'),
	));

	// Detect tables existence (allow deploying before migrations run without fatal errors)
	$has_posts  = $wpdb->get_var("SHOW TABLES LIKE 'posts'");
	$has_likes  = $wpdb->get_var("SHOW TABLES LIKE 'likes'");
	$has_shares = $wpdb->get_var("SHOW TABLES LIKE 'shares'");
	$has_alumni = $wpdb->get_var("SHOW TABLES LIKE 'alumni'");
	$has_comments = $wpdb->get_var("SHOW TABLES LIKE 'comments'");

	// Determine current alumni identity (prefer custom alumni session over WP account)
	$current_alumni_id = '';
	if ( function_exists('alumnus_is_logged_in') && function_exists('alumnus_current_username') && alumnus_is_logged_in() ) {
		$current_alumni_id = (string) alumnus_current_username();
	} elseif ( function_exists('alumnus_get_current_alumni_id') ) {
		$current_alumni_id = (string) alumnus_get_current_alumni_id();
	}

	// Resolve current user's display name and initials for avatars
	$sidebar_name = '';
	$sidebar_initials = '';
	if ( $current_alumni_id !== '' && $has_alumni ) {
		$row = $wpdb->get_row( $wpdb->prepare("SELECT firstname, lastname FROM alumni WHERE user_id = %s LIMIT 1", $current_alumni_id) );
		if ( $row ) {
			$sidebar_name = trim( (string) $row->firstname . ' ' . (string) $row->lastname );
			$fi = ! empty( $row->firstname ) ? strtoupper( substr( (string) $row->firstname, 0, 1 ) ) : '';
			$li = ! empty( $row->lastname )  ? strtoupper( substr( (string) $row->lastname, 0, 1 ) )  : '';
			$sidebar_initials = $fi . $li;
		}
		if ( $sidebar_name === '' ) { $sidebar_name = $current_alumni_id; }
	}
	if ( $sidebar_name === '' && function_exists('is_user_logged_in') && is_user_logged_in() ) {
		$wpuser = wp_get_current_user();
		if ( $wpuser && $wpuser->display_name ) { $sidebar_name = $wpuser->display_name; }
	}
	if ( $sidebar_initials === '' && $sidebar_name !== '' ) {
		$parts = preg_split('/\s+/', (string) $sidebar_name);
		$first = isset($parts[0]) ? strtoupper(substr($parts[0],0,1)) : '';
		$second = isset($parts[1]) ? strtoupper(substr($parts[1],0,1)) : '';
		$sidebar_initials = $first . $second;
	}

    // Legacy non-AJAX post composer removed; posting now handled via AJAX modal.
    $notice_msg = '';
    $notice_class = '';

	// Fetch posts after potential insertion
	$posts = array();
	if ( $has_posts ) {
		// Build dynamic SELECT with optional subqueries for counts (only include if tables exist)
		$like_count_sql  = $has_likes  ? "(SELECT COUNT(*) FROM likes  l WHERE l.post_id = p.post_id) AS like_count," : "0 AS like_count,";
		$share_count_sql = $has_shares ? "(SELECT COUNT(*) FROM shares s WHERE s.post_id = p.post_id) AS share_count," : "0 AS share_count,";
		$comment_count_sql = $has_comments ? "(SELECT COUNT(*) FROM comments c WHERE c.post_id = p.post_id) AS comment_count" : "0 AS comment_count";

		// Per-user liked/shared state
		$liked_by_me_sql = ($has_likes && $current_alumni_id !== '') ? "(SELECT COUNT(*) FROM likes l2 WHERE l2.post_id=p.post_id AND l2.user_id=%s) AS liked_by_me," : "0 AS liked_by_me,";
		$shared_by_me_sql = ($has_shares && $current_alumni_id !== '') ? "(SELECT COUNT(*) FROM shares s2 WHERE s2.post_id=p.post_id AND s2.user_id=%s) AS shared_by_me," : "0 AS shared_by_me,";

		$name_join = $has_alumni ? "LEFT JOIN alumni a ON a.user_id = p.user_id" : "";
		$name_fields = $has_alumni ? "a.firstname, a.lastname," : "";

		$sql = "SELECT p.post_id, p.user_id, $name_fields p.content, p.post_date,
				$like_count_sql $share_count_sql $liked_by_me_sql $shared_by_me_sql $comment_count_sql
				FROM posts p $name_join
				ORDER BY p.post_date DESC
				LIMIT 20"; // Hard cap for initial feed performance.
		$params = array();
		if ($has_likes && $current_alumni_id !== '') { $params[] = $current_alumni_id; }
		if ($has_shares && $current_alumni_id !== '') { $params[] = $current_alumni_id; }
		if (!empty($params)) {
			$posts = $wpdb->get_results( $wpdb->prepare($sql, $params) );
		} else {
			$posts = $wpdb->get_results( $sql );
		}
	}
	ob_start();
	?>
	<div class="alumnus-community-feed-wrapper">
				<div class="alumnus-feed-layout">
			<!-- Left Sidebar -->
			<aside class="alumnus-feed-sidebar-left">
				<div class="alumnus-profile-card">
					<div class="apc-header">
								<div class="apc-avatar apc-avatar--lg"><span class="apc-initials"><?php echo esc_html( $sidebar_initials !== '' ? $sidebar_initials : 'A' ); ?></span></div>
						<div class="apc-meta">
							<h3 class="apc-name">
										<?php echo esc_html( $sidebar_name !== '' ? $sidebar_name : __( 'Guest', 'alumnus' ) ); ?>
							</h3>
						</div>
					</div>
				</div>
			</aside>

			<!-- Main Feed Column -->
			<main class="alumnus-feed-main">
				<?php if ( ! empty( $notice_msg ) ) : ?>
					<div class="<?php echo esc_attr( $notice_class ); ?>"><?php echo esc_html( $notice_msg ); ?></div>
				<?php endif; ?>
				<div class="alumnus-welcome-message">
					<h2><?php esc_html_e( 'Welcome to the Community Feed', 'alumnus' ); ?></h2>
				</div>

				<?php if ( empty( $posts ) ) : ?>
					<article class="alumnus-post-card">
						<div class="post-text"><?php esc_html_e( 'No posts yet. Be the first to share!', 'alumnus' ); ?></div>
						<div class="post-actions compact">
							<button class="btn-light" disabled><i class="fa-solid fa-thumbs-up"></i> <?php esc_html_e( 'Like', 'alumnus' ); ?></button>
							<button class="btn-light" disabled><i class="fa-solid fa-comment"></i> <?php esc_html_e( 'Comment', 'alumnus' ); ?></button>
							<button class="btn-light" disabled><i class="fa-solid fa-share"></i> <?php esc_html_e( 'Share', 'alumnus' ); ?></button>
						</div>
					</article>
				<?php else : ?>
					<?php foreach ( $posts as $post_row ) :
						$full_name = '';
						if ( isset( $post_row->firstname ) || isset( $post_row->lastname ) ) {
							$full_name = trim( (string) $post_row->firstname . ' ' . (string) $post_row->lastname );
						}
						$display_name = $full_name !== '' ? $full_name : $post_row->user_id;
						?>
						<article class="alumnus-post-card" data-post-id="<?php echo (int) $post_row->post_id; ?>">
							<header class="post-header">
								<?php
									$ai1 = '';
									$ai2 = '';
									if ( ! empty( $post_row->firstname ) || ! empty( $post_row->lastname ) ) {
										$ai1 = ! empty( $post_row->firstname ) ? strtoupper( substr( (string) $post_row->firstname, 0, 1 ) ) : '';
										$ai2 = ! empty( $post_row->lastname )  ? strtoupper( substr( (string) $post_row->lastname, 0, 1 ) )  : '';
									} else {
										$ai1 = strtoupper( substr( (string) $post_row->user_id, 0, 1 ) );
									}
									$author_initials = $ai1 . $ai2;
								?>
								<div class="apc-avatar apc-avatar--sm"><span class="apc-initials"><?php echo esc_html( $author_initials !== '' ? $author_initials : 'U' ); ?></span></div>
								<div class="ph-meta">
									<h5 class="ph-name"><?php echo esc_html( $display_name ); ?></h5>
									<div class="ph-date">
										<?php echo esc_html( date_i18n( 'M j, Y', strtotime( $post_row->post_date ) ) ); ?>
									</div>
								</div>
							</header>
							<div class="post-text"><?php echo esc_html( $post_row->content ); ?></div>
							<div class="post-engagement-bar">
								<div class="pe-stats">
									<span class="pe-icon pe-like-count" data-post-id="<?php echo (int) $post_row->post_id; ?>" title="<?php esc_attr_e( 'Likes', 'alumnus' ); ?>"><i class="fa-solid fa-thumbs-up"></i> <?php echo (int) $post_row->like_count; ?></span>
									<span class="pe-icon pe-comment-count" data-post-id="<?php echo (int) $post_row->post_id; ?>" title="<?php esc_attr_e( 'Comments', 'alumnus' ); ?>"><i class="fa-solid fa-comment"></i> <?php echo (int) $post_row->comment_count; ?></span>
									<span class="pe-icon pe-share-count" data-post-id="<?php echo (int) $post_row->post_id; ?>" title="<?php esc_attr_e( 'Shares', 'alumnus' ); ?>"><i class="fa-solid fa-share"></i> <?php echo (int) $post_row->share_count; ?></span>
								</div>
							</div>
							<div class="post-actions compact">
								<button class="btn-light btn-like <?php echo (!empty($post_row->liked_by_me) ? 'is-active' : ''); ?>" data-post-id="<?php echo (int) $post_row->post_id; ?>"><i class="fa-solid fa-thumbs-up"></i> <?php echo !empty($post_row->liked_by_me) ? esc_html__('Liked','alumnus') : esc_html__('Like','alumnus'); ?></button>
								<button class="btn-light btn-comment" data-post-id="<?php echo (int) $post_row->post_id; ?>"><i class="fa-solid fa-comment"></i> <?php esc_html_e( 'Comment', 'alumnus' ); ?></button>
								<button class="btn-light btn-share <?php echo (!empty($post_row->shared_by_me) ? 'is-active' : ''); ?>" data-post-id="<?php echo (int) $post_row->post_id; ?>"><i class="fa-solid fa-share"></i> <?php echo !empty($post_row->shared_by_me) ? esc_html__('Shared','alumnus') : esc_html__('Share','alumnus'); ?></button>
							</div>

							<?php if ( $has_comments ): ?>
								<div class="post-comments" id="comments-<?php echo (int) $post_row->post_id; ?>">
									<?php
									// Render latest 3 comments
									$comments = $wpdb->get_results( $wpdb->prepare(
										"SELECT c.comment_id, c.user_id, c.content, c.comment_date, a.firstname, a.lastname
										 FROM comments c LEFT JOIN alumni a ON a.user_id=c.user_id
										 WHERE c.post_id=%d ORDER BY c.comment_id DESC LIMIT 3",
										 (int)$post_row->post_id
									));
									if ( ! empty( $comments ) ) {
										echo '<ul class="comments-list">';
										foreach ( $comments as $cm ) {
											$cn = trim( (string)$cm->firstname . ' ' . (string)$cm->lastname );
											if ($cn === '') { $cn = (string)$cm->user_id; }
											echo '<li class="comment-item"><strong>' . esc_html($cn) . ':</strong> ' . esc_html($cm->content) . '</li>';
										}
										echo '</ul>';
									} else {
										echo '<div class="apc-placeholder">' . esc_html__('No comments yet.','alumnus') . '</div>';
									}
									// Comment form removed – now handled by modal.
									?>
								</div>
							<?php endif; ?>
						</article>
					<?php endforeach; ?>
				<?php endif; ?>
			</main>

			<!-- Right Sidebar -->
			<aside class="alumnus-feed-sidebar-right">
				<div class="alumnus-post-composer">
					<div class="composer-input">
						<?php if ( $current_alumni_id !== '' && $has_posts ) : ?>
							<button type="button" class="btn-secondary btn-open-post-modal" aria-haspopup="dialog" aria-controls="alumnus-post-modal"><?php esc_html_e( 'Make a post', 'alumnus' ); ?></button>
						<?php else : ?>
							<button type="button" class="btn-secondary" disabled><?php esc_html_e( 'Sign in to post', 'alumnus' ); ?></button>
						<?php endif; ?>
					</div>
				</div>
			</aside>
		</div>

		<!-- Post Modal -->
		<div class="alumnus-modal-overlay" id="alumnus-post-modal" aria-hidden="true">
			<div class="alumnus-modal" role="dialog" aria-modal="true" aria-labelledby="alumnus-post-modal-title">
				<button type="button" class="alumnus-modal-close" data-close-modal>&times;</button>
				<h3 id="alumnus-post-modal-title" class="alumnus-modal-title"><?php esc_html_e('Create Post','alumnus'); ?></h3>
				<?php if ( $current_alumni_id !== '' && $has_posts ): ?>
				<form id="alumnus-post-modal-form">
					<textarea name="content" maxlength="500" placeholder="<?php esc_attr_e('What do you want to say? (max 500 chars)','alumnus'); ?>" required></textarea>
					<div class="alumnus-modal-actions">
						<button type="submit" class="btn-primary"><?php esc_html_e('Post','alumnus'); ?></button>
					</div>
				</form>
				<?php else: ?>
					<p><?php esc_html_e('Sign in to create a post.','alumnus'); ?></p>
				<?php endif; ?>
			</div>
		</div>

		<!-- Comment Modal -->
		<div class="alumnus-modal-overlay" id="alumnus-comment-modal" aria-hidden="true">
			<div class="alumnus-modal alumnus-modal--comment" role="dialog" aria-modal="true" aria-labelledby="alumnus-comment-modal-title">
				<header class="alumnus-modal-header">
					<h3 id="alumnus-comment-modal-title" class="alumnus-modal-title"><?php esc_html_e("Anonymous participant's Post",'alumnus'); ?></h3>
					<button type="button" class="alumnus-modal-close" data-close-modal aria-label="<?php esc_attr_e('Close','alumnus'); ?>">&times;</button>
				</header>
				<div class="alumnus-modal-content">
					<div class="alumnus-comment-modal-post" id="alumnus-comment-modal-post"><!-- cloned post card inserted here --></div>
					<div class="alumnus-modal-comments" id="alumnus-comment-list-wrapper">
						<div class="alumnus-modal-comments-empty">
							<div class="alumnus-modal-comments-empty-icon"><i class="fa-solid fa-comments"></i></div>
							<p class="alumnus-modal-comments-empty-text"><?php esc_html_e('No comments yet','alumnus'); ?></p>
							<p class="alumnus-modal-comments-empty-sub"><?php esc_html_e('Be the first to comment.','alumnus'); ?></p>
						</div>
					</div>
				</div>
				<?php if ( $current_alumni_id !== '' && $has_comments ): ?>
				<form id="alumnus-comment-modal-form" class="alumnus-modal-composer">
					<input type="hidden" name="postId" value="" />
					<div class="alumnus-modal-composer-inner">
						<div class="amc-avatar-wrap"><div class="apc-avatar apc-avatar--sm"><span class="apc-initials"><?php echo esc_html( $sidebar_initials !== '' ? $sidebar_initials : 'A' ); ?></span></div></div>
						<div class="amc-input-wrap"><input type="text" name="comment" maxlength="200" placeholder="<?php echo esc_attr( sprintf( __('Comment as %s','alumnus'), $sidebar_name !== '' ? $sidebar_name : __('Anonymous participant','alumnus') ) ); ?>" required /></div>
						<div class="amc-actions-wrap">
							<button type="submit" class="btn-primary amc-submit" aria-label="<?php esc_attr_e('Submit comment','alumnus'); ?>">➤</button>
						</div>
					</div>
				</form>
				<?php else: ?>
					<p style="margin:12px 18px 24px; font-size:14px; opacity:.8; text-align:center; "><?php esc_html_e('Sign in to comment.','alumnus'); ?></p>
				<?php endif; ?>
			</div>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

add_shortcode( 'community_feed', 'alumnus_render_community_feed_shortcode' );

// Helper: Resolve the current WordPress user's mapped alumni.user_id
if ( ! function_exists( 'alumnus_get_current_alumni_id' ) ) {
	function alumnus_get_current_alumni_id() {
		if ( ! is_user_logged_in() ) { return ''; }
		global $wpdb;
		$u = wp_get_current_user();
		if ( ! $u || ! $u->ID ) { return ''; }

		// 0) Explicit user meta link takes precedence
		$meta_link = get_user_meta( $u->ID, 'alumnus_user_id', true );
		if ( ! empty( $meta_link ) ) { return (string) $meta_link; }

		// Try via `user` table mapping by username -> user.user -> alumni.user_id
		$alumni_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT u.user FROM `user` u WHERE u.username = %s LIMIT 1",
			$u->user_login
		) );
		if ( ! empty( $alumni_id ) ) { return (string) $alumni_id; }

		// Try direct match where alumni.user_id equals WP username
		$alumni_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT a.user_id FROM alumni a WHERE a.user_id = %s LIMIT 1",
			$u->user_login
		) );
		if ( ! empty( $alumni_id ) ) { return (string) $alumni_id; }

		// Fallback: match by email
		$alumni_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT a.user_id FROM alumni a WHERE a.email = %s LIMIT 1",
			$u->user_email
		) );
		if ( ! empty( $alumni_id ) ) { return (string) $alumni_id; }

		return '';
	}
}

// =============================
// AJAX: Like toggle
// =============================
function alumnus_ajax_like_toggle() {
	check_ajax_referer('alumnus_like_toggle', 'nonce');
	global $wpdb;
	$post_id = isset($_POST['postId']) ? absint($_POST['postId']) : 0;
	$uid = ( function_exists('alumnus_is_logged_in') && alumnus_is_logged_in() && function_exists('alumnus_current_username') ) ? (string) alumnus_current_username() : '';
	if ( $post_id <= 0 || $uid === '' ) { wp_send_json_error(array('message'=>'forbidden'), 403); }

	// Toggle like (unique key on (post_id,user_id))
	$liked = (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM likes WHERE post_id=%d AND user_id=%s", $post_id, $uid) );
	if ( $liked > 0 ) {
		$wpdb->delete('likes', array('post_id'=>$post_id, 'user_id'=>$uid), array('%d','%s'));
		$new_state = false;
	} else {
		$wpdb->insert('likes', array('post_id'=>$post_id, 'user_id'=>$uid), array('%d','%s'));
		$new_state = true;
	}
	$count = (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM likes WHERE post_id=%d", $post_id) );
	wp_send_json_success(array('liked'=>$new_state, 'count'=>$count));
}
add_action('wp_ajax_alumnus_like_toggle', 'alumnus_ajax_like_toggle');
add_action('wp_ajax_nopriv_alumnus_like_toggle', 'alumnus_ajax_like_toggle');

// =============================
// AJAX: Share (idempotent per user)
// =============================
function alumnus_ajax_share_post() {
	check_ajax_referer('alumnus_share', 'nonce');
	global $wpdb;
	$post_id = isset($_POST['postId']) ? absint($_POST['postId']) : 0;
	$uid = ( function_exists('alumnus_is_logged_in') && alumnus_is_logged_in() && function_exists('alumnus_current_username') ) ? (string) alumnus_current_username() : '';
	if ( $post_id <= 0 || $uid === '' ) { wp_send_json_error(array('message'=>'forbidden'), 403); }

	$exists = (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM shares WHERE post_id=%d AND user_id=%s", $post_id, $uid) );
	if ( $exists === 0 ) {
		$wpdb->insert('shares', array('post_id'=>$post_id, 'user_id'=>$uid), array('%d','%s'));
		// Repost logic: duplicate original post content as a new post credited to sharing user.
		$orig = $wpdb->get_row( $wpdb->prepare(
			"SELECT p.content, p.user_id, a.firstname, a.lastname 
			 FROM posts p 
			 LEFT JOIN alumni a ON a.user_id = p.user_id 
			 WHERE p.post_id=%d", 
			$post_id
		) );
		if ( $orig && isset($orig->content) ) {
			// Get original poster's name
			$poster_name = '';
			if ( isset($orig->firstname) || isset($orig->lastname) ) {
				$poster_name = trim( (string) $orig->firstname . ' ' . (string) $orig->lastname );
			}
			if ( $poster_name === '' ) {
				$poster_name = (string) $orig->user_id;
			}
			
			// Format: Shared Poster Name:\nPost text
			$new_content = 'Shared ' . $poster_name . '\'s post: ' . "\n" . (string) $orig->content;
			// Enforce 500-char limit of posts.content
			if ( function_exists('mb_substr') ) { $new_content = mb_substr($new_content, 0, 500, 'UTF-8'); } else { $new_content = substr($new_content, 0, 500); }
			$wpdb->insert( 'posts', array(
				'user_id'   => $uid,
				'content'   => $new_content,
				'post_date' => current_time('Y-m-d'),
			), array('%s','%s','%s') );
		}
	}
	$count = (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM shares WHERE post_id=%d", $post_id) );
	wp_send_json_success(array('count'=>$count));
}
add_action('wp_ajax_alumnus_share_post', 'alumnus_ajax_share_post');
add_action('wp_ajax_nopriv_alumnus_share_post', 'alumnus_ajax_share_post');

// =============================
// AJAX: Add comment
// =============================
function alumnus_ajax_add_comment() {
	check_ajax_referer('alumnus_add_comment', 'nonce');
	global $wpdb;
	$post_id = isset($_POST['postId']) ? absint($_POST['postId']) : 0;
	$raw = isset($_POST['content']) ? wp_unslash($_POST['content']) : '';
	$content = trim( wp_strip_all_tags( (string) $raw ) );
	$uid = ( function_exists('alumnus_is_logged_in') && alumnus_is_logged_in() && function_exists('alumnus_current_username') ) ? (string) alumnus_current_username() : '';
	if ( $post_id <= 0 || $uid === '' || $content === '' ) { wp_send_json_error(array('message'=>'forbidden'), 403); }
	if ( function_exists('mb_substr') ) { $content = mb_substr($content, 0, 200, 'UTF-8'); } else { $content = substr($content, 0, 200); }

	$res = $wpdb->insert('comments', array(
		'post_id' => $post_id,
		'user_id' => $uid,
		'content' => $content,
		'comment_date' => current_time('Y-m-d'),
	), array('%d','%s','%s','%s'));
	if ( false === $res ) { wp_send_json_error(array('message'=>'db-error'), 500); }

	// Build small HTML snippet for the new comment
	$row = $wpdb->get_row( $wpdb->prepare("SELECT a.firstname, a.lastname FROM alumni a WHERE a.user_id=%s", $uid) );
	$name = '';
	if ($row) { $name = trim( (string)$row->firstname . ' ' . (string)$row->lastname ); }
	if ($name === '') { $name = $uid; }
	$html = '<li class="comment-item"><strong>' . esc_html($name) . ':</strong> ' . esc_html($content) . '</li>';

	$count = (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM comments WHERE post_id=%d", $post_id) );
	wp_send_json_success(array('count'=>$count, 'html'=>$html));
}
add_action('wp_ajax_alumnus_add_comment', 'alumnus_ajax_add_comment');
add_action('wp_ajax_nopriv_alumnus_add_comment', 'alumnus_ajax_add_comment');

// =============================
// AJAX: Add post (modal composer)
// =============================
function alumnus_ajax_add_post() {
	check_ajax_referer('alumnus_add_post', 'nonce');
	global $wpdb;
	$uid = ( function_exists('alumnus_is_logged_in') && alumnus_is_logged_in() && function_exists('alumnus_current_username') ) ? (string) alumnus_current_username() : '';
	if ( $uid === '' ) { wp_send_json_error(array('message'=>'forbidden'), 403); }
	$raw = isset($_POST['content']) ? wp_unslash($_POST['content']) : '';
	$content = trim( wp_strip_all_tags( (string) $raw ) );
	if ( $content === '' ) { wp_send_json_error(array('message'=>'empty'), 400); }
	if ( function_exists('mb_substr') ) { $content = mb_substr($content, 0, 500, 'UTF-8'); } else { $content = substr($content, 0, 500); }

	// Insert post
	$res = $wpdb->insert( 'posts', array(
		'user_id'   => $uid,
		'content'   => $content,
		'post_date' => current_time('Y-m-d'),
	), array('%s','%s','%s') );
	if ( false === $res ) { wp_send_json_error(array('message'=>'db-error'), 500); }
	$post_id = (int) $wpdb->insert_id;

	// Fetch author name for display
	$row = $wpdb->get_row( $wpdb->prepare("SELECT firstname, lastname FROM alumni WHERE user_id=%s", $uid) );
	$full_name = '';
	if ( $row ) { $full_name = trim( (string)$row->firstname . ' ' . (string)$row->lastname ); }
	if ( $full_name === '' ) { $full_name = $uid; }

	// Initials
	$ai1 = '';
	$ai2 = '';
	if ( $row ) {
		$ai1 = ! empty( $row->firstname ) ? strtoupper( substr( (string) $row->firstname, 0, 1 ) ) : '';
		$ai2 = ! empty( $row->lastname )  ? strtoupper( substr( (string) $row->lastname, 0, 1 ) )  : '';
	} else {
		$ai1 = strtoupper( substr( $uid, 0, 1 ) );
	}
	$author_initials = $ai1 . $ai2;

	// Build HTML (counts all start at 0; liked/shared state false)
	$date_display = esc_html( date_i18n( 'M j, Y', strtotime( current_time('Y-m-d') ) ) );
	ob_start();
	?>
	<article class="alumnus-post-card" data-post-id="<?php echo (int) $post_id; ?>">
		<header class="post-header">
			<div class="apc-avatar apc-avatar--sm"><span class="apc-initials"><?php echo esc_html( $author_initials !== '' ? $author_initials : 'U' ); ?></span></div>
			<div class="ph-meta">
				<h5 class="ph-name"><?php echo esc_html( $full_name ); ?></h5>
				<div class="ph-date"><?php echo $date_display; ?> • <span class="ph-visibility" title="<?php esc_attr_e( 'Public', 'alumnus' ); ?>">🌐</span></div>
			</div>
		</header>
		<div class="post-text"><?php echo esc_html( $content ); ?></div>
		<div class="post-engagement-bar">
			<div class="pe-stats">
				<span class="pe-icon pe-like-count" data-post-id="<?php echo (int) $post_id; ?>" title="<?php esc_attr_e( 'Likes', 'alumnus' ); ?>"><i class="fa-solid fa-thumbs-up"></i> 0</span>
				<span class="pe-icon pe-comment-count" data-post-id="<?php echo (int) $post_id; ?>" title="<?php esc_attr_e( 'Comments', 'alumnus' ); ?>"><i class="fa-solid fa-comment"></i> 0</span>
				<span class="pe-icon pe-share-count" data-post-id="<?php echo (int) $post_id; ?>" title="<?php esc_attr_e( 'Shares', 'alumnus' ); ?>"><i class="fa-solid fa-share"></i> 0</span>
			</div>
		</div>
		<div class="post-actions compact">
			<button class="btn-light btn-like" data-post-id="<?php echo (int) $post_id; ?>"><i class="fa-solid fa-thumbs-up"></i> <?php esc_html_e( 'Like', 'alumnus' ); ?></button>
			<button class="btn-light btn-comment" data-post-id="<?php echo (int) $post_id; ?>"><i class="fa-solid fa-comment"></i> <?php esc_html_e( 'Comment', 'alumnus' ); ?></button>
			<button class="btn-light btn-share" data-post-id="<?php echo (int) $post_id; ?>"><i class="fa-solid fa-share"></i> <?php esc_html_e( 'Share', 'alumnus' ); ?></button>
		</div>
		<div class="post-comments" id="comments-<?php echo (int) $post_id; ?>">
			<div class="apc-placeholder"><?php esc_html_e( 'No comments yet.', 'alumnus' ); ?></div>
		</div>
	</article>
	<?php
	$html = ob_get_clean();
	wp_send_json_success(array('html'=>$html));
}
add_action('wp_ajax_alumnus_add_post', 'alumnus_ajax_add_post');
add_action('wp_ajax_nopriv_alumnus_add_post', 'alumnus_ajax_add_post');
