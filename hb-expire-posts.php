<?php
/**
 * Plugin Name: Hoverboard Expire Posts
 * Description: Automatically expire posts after a specified time.
 * Author: Hoverboard
 * Author URI: https://hoverboardstudios.com
 * Version: 1.0.0
 *
 * @package ExpirePosts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';

$hb_expire_posts_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://github.com/hoverboard88/hb-expire-posts/',
	__FILE__,
	'hb-expire-posts'
);
$hb_expire_posts_update_checker->setBranch( 'main' );

/**
 * Function to check if a post is expired.
 *
 * @param int $post_id The ID of the post to check.
 * @return bool True if the post is expired, false otherwise.
 */
function is_post_expired( $post_id ) {
	$expiration_time = get_post_meta( $post_id, '_expiration_time', true );
	if ( ! $expiration_time ) {
		return false; // No expiration time set, so not expired.
	}

	$expiration_time = strtotime( $expiration_time );
	$current_time    = strtotime( current_time( 'mysql' ) );

	return $current_time > $expiration_time;
}

/**
 * Function to expire a post.
 *
 * @param int $post_id The ID of the post to expire.
 */
function expire_post( $post_id ) {
	if ( is_post_expired( $post_id ) ) {
		// Set the post status to 'draft' if it is expired.
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			)
		);
	}
}

/**
 * Function to schedule the expiration check for all posts.
 */
function schedule_expiration_check() {
	if ( ! wp_next_scheduled( 'expire_posts_check' ) ) {
		wp_schedule_event( time(), 'hourly', 'expire_posts_check' );
	}
}
add_action( 'wp', 'schedule_expiration_check' );

/**
 * Function to check all posts for expiration.
 */
function check_all_posts_for_expiration() {
	$enabled_post_types = (array) get_option( 'expire_posts_enabled_post_types', array() );

	/*
	 * Drop post types that are no longer registered instead of abandoning the
	 * whole run. A single stale entry - a post type whose plugin has since been
	 * removed - would otherwise stop expiration for every other post type, with
	 * nothing logged to say why.
	 */
	$post_types = array_values( array_filter( $enabled_post_types, 'post_type_exists' ) );

	/*
	 * Bail when nothing is left. Passing an empty array as `post_type` would let
	 * WP_Query fall back to its default of `post`, which would expire blog posts.
	 */
	if ( ! $post_types ) {
		return;
	}

	// Query all posts of the specified post types.
	$args = array(
		'post_type'      => $post_types,
		'post_status'    => 'publish',
		'posts_per_page' => -1, // Get all posts.
		'meta_query'     => array( // phpcs:ignore
			array(
				'key'     => '_expiration_time',
				'compare' => 'EXISTS',
			),
		),
		'fields'         => 'ids', // Return only post IDs.
	);

	$expiration_posts = get_posts( $args );

	foreach ( $expiration_posts as $expiration_post_id ) {
		expire_post( $expiration_post_id );
	}
}
add_action( 'expire_posts_check', 'check_all_posts_for_expiration' );

/**
 * Function to deactivate the scheduled event on plugin deactivation.
 */
function expire_posts_deactivate() {
	$timestamp = wp_next_scheduled( 'expire_posts_check' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'expire_posts_check' );
	}
}
register_deactivation_hook( __FILE__, 'expire_posts_deactivate' );

/**
 * Function to add a meta box for setting expiration time.
 */
function add_expiration_meta_box() {
	add_meta_box(
		'expiration_meta_box',
		__( 'Expiration Time', 'expire-posts' ),
		'render_expiration_meta_box',
		get_option( 'expire_posts_enabled_post_types', array() ),
		'side',
		'default'
	);
}
add_action( 'add_meta_boxes', 'add_expiration_meta_box' );

/**
 * Function to render the expiration meta box.
 *
 * @param WP_Post $post The post object.
 */
function render_expiration_meta_box( $post ) {
	wp_nonce_field( 'save_expiration_meta_box', 'expiration_meta_box_nonce' );
	$expiration_time = get_post_meta( $post->ID, '_expiration_time', true );
	?>

	<label for="expiration_time"><?php esc_html_e( 'Expiration Time:', 'expire-posts' ); ?></label>
	<input type="datetime-local" id="expiration_time" name="expiration_time" value="<?php echo esc_attr( $expiration_time ); ?>" />
	<p class="description">
		<?php echo wp_kses_post( 'Enter the expiration date and time in the <strong>' . get_option( 'timezone_string' ) . '</strong> time zone. The post will be expired after this time.' ); ?>
	</p>
	<?php
}
/**
 * Function to save the expiration time when the post is saved.
 *
 * @param int $post_id The ID of the post being saved.
 */
function save_expiration_meta_box( $post_id ) {
	if ( ! isset( $_POST['expiration_meta_box_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['expiration_meta_box_nonce'] ) ), 'save_expiration_meta_box' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$expiration_time = isset( $_POST['expiration_time'] ) ? sanitize_text_field( wp_unslash( $_POST['expiration_time'] ) ) : 0;
	update_post_meta( $post_id, '_expiration_time', $expiration_time );
}
add_action( 'save_post', 'save_expiration_meta_box' );

/**
 * Function to add settings for enabling expiration on post types.
 */
function expire_posts_settings_page() {
	add_settings_section(
		'expire_posts_settings_section',
		__( 'Expire Posts Settings', 'expire-posts' ),
		'expire_posts_settings_section_callback',
		'reading'
	);

	add_settings_field(
		'expire_posts_enabled_post_types',
		__( 'Enable Expiration for Post Types', 'expire-posts' ),
		'expire_posts_enabled_post_types_callback',
		'reading',
		'expire_posts_settings_section'
	);

	register_setting( 'reading', 'expire_posts_enabled_post_types', array( 'sanitize_callback' => 'expire_posts_sanitize_post_types' ) );
}
add_action( 'admin_init', 'expire_posts_settings_page' );

/**
 * Callback for the settings section.
 */
function expire_posts_settings_section_callback() {
	echo '<p>' . esc_html__( 'Select the post types for which expiration functionality should be enabled.', 'expire-posts' ) . '</p>';
}

/**
 * Callback for the settings field.
 */
function expire_posts_enabled_post_types_callback() {
	$post_types         = get_post_types( array( 'public' => true ), 'objects' );
	$enabled_post_types = get_option( 'expire_posts_enabled_post_types', array() );

	if ( isset( $post_types['attachment'] ) ) {
		unset( $post_types['attachment'] );
	}

	foreach ( $post_types as $post_type ) {
		?>
		<label>
			<input type="checkbox" name="expire_posts_enabled_post_types[]" value="<?php echo esc_attr( $post_type->name ); ?>" <?php checked( in_array( $post_type->name, $enabled_post_types, true ) ); ?> />
			<?php echo esc_html( $post_type->label ); ?>
		</label><br />
		<?php
	}
}

/**
 * Sanitize the selected post types.
 *
 * @param array $input The input value.
 * @return array Sanitized post types.
 */
function expire_posts_sanitize_post_types( $input ) {
	$post_types = get_post_types( array( 'public' => true ), 'names' );
	return array_filter(
		(array) $input,
		function ( $post_type ) use ( $post_types ) {
			return in_array( $post_type, $post_types, true );
		}
	);
}

/**
 * Add settings link to the plugin list.
 *
 * @param array $links The existing plugin action links.
 * @return array Modified plugin action links.
 */
function expire_posts_settings_link( $links ) {
	$settings_link = '<a href="' . esc_url( admin_url( 'options-reading.php' ) ) . '">' . esc_html__( 'Settings', 'expire-posts' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'expire_posts_settings_link' );
