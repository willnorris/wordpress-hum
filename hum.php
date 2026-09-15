<?php
/**
 * Plugin Name: Hum
 * Plugin URI: https://github.com/pfefferle/wordpress-hum
 * Description: Personal URL shortener for WordPress
 * Author: Will Norris & Matthias Pfefferle
 * Author URI: https://github.com/pfefferle/wordpress-hum
 * Version: 1.3.6
 * License: MIT
 * License URI: http://opensource.org/licenses/MIT
 * Text Domain: hum
 */

namespace Hum;

require_once __DIR__ . '/includes/newbase60.php';

\register_activation_hook( __FILE__, __NAMESPACE__ . '\activate' );
\register_deactivation_hook( __FILE__, __NAMESPACE__ . '\deactivate' );

\add_action( 'init', __NAMESPACE__ . '\init' );
\add_action( 'init', __NAMESPACE__ . '\rewrite_rules', 15 );

/**
 * Flush the rewrite rules on activation, so hum shortlinks resolve right away.
 */
function activate() {
	rewrite_rules();
	\flush_rewrite_rules();
}

/**
 * Flush the rewrite rules on deactivation, to drop the hum rules again.
 */
function deactivate() {
	\flush_rewrite_rules();
}

/**
 * Initialize the plugin, registering WordPress hooks.
 */
function init() {
	\load_plugin_textdomain( 'hum', null, \basename( __DIR__ ) );

	// if you have hum installed, then you probably actually care about short
	// links, so we'll add it to the admin menu bar.
	\add_action( 'admin_bar_menu', 'wp_admin_bar_shortlink_menu', 90 );

	\add_action( 'query_vars', __NAMESPACE__ . '\query_vars' );
	\add_action( 'parse_request', __NAMESPACE__ . '\parse_request' );
	\add_filter( 'hum_redirect', __NAMESPACE__ . '\redirect_request', 10, 3 );
	\add_filter( 'hum_redirect_i', __NAMESPACE__ . '\redirect_request_i', 10, 2 );
	\add_filter( 'hum_process_redirect', __NAMESPACE__ . '\process_redirect', 10, 2 );
	\add_filter( 'pre_option_hum_shortlink_base', __NAMESPACE__ . '\config_shortlink_base' );
	\add_filter( 'pre_get_shortlink', __NAMESPACE__ . '\get_shortlink', 10, 4 );
	\add_filter( 'template_redirect', __NAMESPACE__ . '\legacy_redirect' );
	\add_filter( 'hum_legacy_id', __NAMESPACE__ . '\legacy_ftl_id', 10, 2 );
	\add_action( 'atom_entry', __NAMESPACE__ . '\shortlink_atom_entry' );
	\add_action( 'enqueue_block_editor_assets', __NAMESPACE__ . '\enqueue_block_editor_script' );

	// Admin Settings
	\add_action( 'admin_init', __NAMESPACE__ . '\admin_init' );
	\add_action( 'admin_menu', __NAMESPACE__ . '\admin_menu' );
	\add_filter( 'manage_edit-post_columns', __NAMESPACE__ . '\add_post_column', 10, 1 );
	\add_filter( 'manage_edit-page_columns', __NAMESPACE__ . '\add_post_column', 10, 1 );
	\add_action( 'manage_posts_custom_column', __NAMESPACE__ . '\add_posts_custom_column', 10, 2 );
	\add_action( 'manage_pages_custom_column', __NAMESPACE__ . '\add_posts_custom_column', 10, 2 );
}

/**
 * Enqueue editor script.
 */
function enqueue_block_editor_script() {
	// Load dependencies and version info.
	$asset_info = include \plugin_dir_path( __FILE__ ) . 'build/index.asset.php';

	\wp_enqueue_script(
		'hum-editor-script',
		\plugins_url( 'build/index.js', __FILE__ ),
		$asset_info['dependencies'],
		$asset_info['version'],
		true
	);

	\wp_localize_script(
		'hum-editor-script',
		'_humEditorObject',
		array(
			'shortlink' => \wp_get_shortlink(),
		)
	);
}

/**
 * Accept hum query variables.
 */
function query_vars( $vars ) {
	$vars[] = 'hum';
	return $vars;
}

/**
 * Parse request for shortlink. This is the main entry point for handling
 * short URLs.
 *
 * @uses apply_filters() Calls 'hum_redirect' filter
 * @uses apply_filters() Calls 'hum_process_redirect' filter
 *
 * @param WP $wp the WordPress environment for the request
 */
function parse_request( $wp ) {
	if ( \array_key_exists( 'hum', $wp->query_vars ) ) {
		$hum_path = $wp->query_vars['hum'];
		if ( \strpos( $hum_path, '/' ) !== false ) {
			list($type, $id) = \explode( '/', $hum_path, 2 );
		} else {
			$type = $hum_path;
			$id   = null;
		}

		$url = \apply_filters( 'hum_redirect', null, $type, $id );

		// hum hasn't handled the request yet, so try again but strip common
		// punctuation that might appear after a URL in written text: . , )
		if ( ! $url ) {
			$clean_id = \preg_replace( '/[\.,\)]+$/', '', $id );
			if ( $id !== $clean_id ) {
				$url = \apply_filters( 'hum_redirect', null, $type, $clean_id );
			}
		}

		if ( $url ) {
			\do_action( 'hum_process_redirect', $url, $id );
		}

		// hum didn't handle request, so issue 404.
		// manually setting query vars like this feels very fragile, but
		// $wp_query->set_404() doesn't do what we need here.
		$wp->query_vars['error'] = '404';
	}
}

/**
 * Process the redirect.
 *
 * @param string $url the permalink of the post
 * @param string $id the requested post ID
 */
function process_redirect( $url, $id ) {
	\wp_redirect( $url, 301 );
	exit;
}

/**
 * Get the short URL types that are handled locally by WordPress.
 *
 * @uses apply_filters() Calls 'hum_local_types' with array of local types
 *
 * @return array local types
 */
function local_types() {
	$local_types = array( 'b', 't', 'a', 'p' );
	return \apply_filters( 'hum_local_types', $local_types );
}

/**
 * Get the short URL types that shoud be redirected (types can be the same as local types).
 *
 * @uses apply_filters() Calls 'hum_redirect_types' with array of redirect types
 *
 * @return array redirect types
 */
function redirect_types() {
	$redirect_types = array( 'i' );
	return \apply_filters( 'hum_redirect_types', $redirect_types );
}

/**
 * Attempt to handle redirect for the current shortlink.
 *
 * This redirects shortlinks that are for content hosted directly within
 * WordPress. The 'id' portion of these URLs is expected to be the
 * sexagesimal post ID.
 *
 * This also allows for simple redirect rules for shortlink prefixes. Users
 * can provide a filter to perform simple URL redirect for a given type
 * prefix. For example, to redirect all /w/ shortlinks to your personal
 * PBworks wiki, you could use:
 *
 *     add_filter('hum_redirect_base_w', fn() => "http://willnorris.pbworks.com/");
 *
 * @uses apply_filters() Calls 'hum_redirect_{$type}' action
 * @uses apply_filters() Calls 'hum_redirect_base_{$type}' filter on redirect base URL
 *
 * @param string $url the short URL
 * @param string $type the content-type prefix
 * @param string $id the requested post ID
 */
function redirect_request( $url, $type, $id ) {
	// locally hosted content
	$local_types = local_types();
	if ( \in_array( $type, $local_types, true ) ) {
		$p = \sxg_to_num( $id );
		if ( $p ) {
			$url = \get_permalink( $p );
		}
	}

	// simple redirects for entire base type
	if ( ! $url ) {
		$url = \apply_filters( "hum_redirect_base_{$type}", false );
		if ( $url ) {
			$url = \trailingslashit( $url ) . $id;
		}
	}

	$url = \apply_filters( "hum_redirect_{$type}", $url, $id );
	return $url;
}

/**
 * Handles /i/ URLs that have ISBN or ASIN subpaths by redirecting to Amazon.
 *
 * @uses apply_filters() Calls 'hum_redirect_i_{$subtype}' action
 * @uses apply_filters() Calls 'amazon_domain' filter
 * @uses apply_filters() Calls 'amazon_affiliate_id' filter
 *
 * @param string $url the short URL
 * @param string $path subpath of URL (after /i/)
 */
function redirect_request_i( $url, $path ) {
	list( $subtype, $id ) = \explode( '/', $path, 2 );

	if ( $subtype ) {
		switch ( $subtype ) {
			case 'a':
			case 'asin':
			case 'i':
			case 'isbn':
				$amazon_domain = \apply_filters( 'amazon_domain', 'www.amazon.com' );
				$amazon_id     = \apply_filters( 'amazon_affiliate_id', false );
				if ( $amazon_id ) {
					// valid partner shortlink, checked by
					// https://partnernet.amazon.de/gp/associates/network/tools/link-checker/main.html
					$url = 'http://' . $amazon_domain . '/dp/product/' . $id . '?tag=' . $amazon_id;
				} else {
					$url = 'http://' . $amazon_domain . '/dp/product/' . $id;
				}
				break;
		}
		$url = \apply_filters( "hum_redirect_i_{$subtype}", $url, $id );
	}
	return $url;
}

/**
 * Add rewrite rules for hum shortlinks.
 */
function rewrite_rules() {
	$local_types    = local_types();
	$redirect_types = redirect_types();

	$types = \array_merge( $local_types, $redirect_types );
	$types = \implode( '', \array_unique( $types ) );

	\add_rewrite_rule( "([{$types}](\/.*)?$)", 'index.php?hum=$matches[1]', 'top' );
}

/**
 * Get the base URL for hum shortlinks. Defaults to the WordPress home url.
 * Users can define HUM_SHORTLINK_BASE or provide a filter to use a custom
 * domain for shortlinks.
 *
 * @uses apply_filters() Calls 'hum_shortlink_base' filter on base URL.
 *
 * @return string
 */
function shortlink_base() {
	$base = \get_option( 'hum_shortlink_base' );
	if ( empty( $base ) ) {
		$base = \home_url();
	}
	return \apply_filters( 'hum_shortlink_base', $base );
}

/**
 * Allow the constant named 'HUM_SHORTLINK_BASE' to override the base URL for shortlinks.
 *
 * @param string $url The short URL.
 */
function config_shortlink_base( $url = '' ) {
	if ( \defined( 'HUM_SHORTLINK_BASE' ) ) {
		return \untrailingslashit( HUM_SHORTLINK_BASE );
	}
	return $url;
}

/**
 * Get the shortlink for a post, page, attachment, or blog.
 *
 * @param int    $id          A post or site ID. Default is 0, which means the current post or site.
 * @param string $context     Whether the ID is a 'site' ID, 'post' ID, or 'media' ID. If 'post',
 *                            the post_type of the post is consulted. If 'query', the current query is consulted
 *                            to determine the ID and context. Default 'post'.
 * @param bool   $allow_slugs Whether to allow post slugs in the shortlink. It is up to the plugin how
 *                            and whether to honor this. Default true.
 * @return string
 */
function get_shortlink( $link, $id, $context, $allow_slugs ) {
	$post_id = 0;
	if ( 'query' === $context && \is_singular() ) {
		$post_id = \get_queried_object_id();
		$post    = \get_post( $post_id );
	} elseif ( 'post' === $context ) {
		$post = \get_post( $id );
		if ( ! empty( $post->ID ) ) {
			$post_id = $post->ID;
		}
	}

	if ( ! empty( $post_id ) ) {
		$type   = type_prefix( $post_id );
		$sxg_id = \num_to_sxg( $post_id );
		$link   = \trailingslashit( shortlink_base() ) . $type . '/' . $sxg_id;
	}

	return $link;
}

/**
 * Get the content-type prefix for the specified post.
 *
 * @see http://ttk.me/w/Whistle#design
 * @uses apply_filters() Calls 'hum_type_prefix' on the content type prefix.
 *
 * @param int|object $post A post
 * @return string The content type prefix for the post.
 */
function type_prefix( $post ) {
	$prefix = 'b';

	$post_type = \get_post_type( $post );

	if ( 'attachment' === $post_type ) {
		// check if $post is a WP_Post or an ID
		if ( \is_numeric( $post ) ) {
			$post_id = $post;
		} else {
			$post_id = $post->ID;
		}

		$mime_type  = \get_post_mime_type( $post_id );
		$media_type = \preg_replace( '/(\/[a-zA-Z]+)/i', '', $mime_type );

		switch ( $media_type ) {
			case 'audio':
			case 'video':
				$prefix = 'a';
				break;
			case 'image':
				$prefix = 'p';
				break;
		}

		// @todo add support for slides
	} else {
		$post_format = \get_post_format( $post );
		switch ( $post_format ) {
			case 'aside':
			case 'status':
			case 'link':
				$prefix = 't';
				break;
			case 'audio':
			case 'video':
				$prefix = 'a';
				break;
			case 'photo':
			case 'gallery':
			case 'image':
				$prefix = 'p';
				break;
		}
	}

	return \apply_filters( 'hum_type_prefix', $prefix, $post );
}

/**
 * Support redirects from legacy short URL schemes. This allows users to migrate from other
 * shortlink generaters, but still have hum support the old URLs.
 *
 * @uses do_action() Calls 'hum_legacy_id' with the post ID and shortlink path.
 */
function legacy_redirect() {
	if ( \is_404() ) {
		global $wp;
		$post_id = \apply_filters( 'hum_legacy_id', 0, $wp->request );
		if ( $post_id ) {
			$url = \get_permalink( $post_id );
			if ( $url ) {
				$url = \apply_filters( 'hum_legacy_redirect', $url );
				\wp_redirect( $url, 301 );
				exit;
			}
		}
	}
}

/**
 * Handle shortlinks generated by Friendly Twitter Links, which take the form
 * /{id}, where {id} can be the base10 or base32 post ID.
 *
 * @param int $id post ID to filter on.
 * @param string $path URL path (without preceding slash) of the request.
 *
 * @return string ID of post to redirect to.
 */
function legacy_ftl_id( $id, $path ) {
	/**
	 * Filters whether Hum resolves legacy Friendly Twitter Links shortlinks.
	 *
	 * These are bare base-10 or base-32 post IDs used as the request path.
	 * Sites that never used FTL shortlinks can disable this to stop Hum from
	 * decoding arbitrary 404 URLs into post IDs and redirecting to them.
	 *
	 * @param bool $enabled Whether to resolve legacy FTL shortlinks. Default true.
	 */
	if ( ! \apply_filters( 'hum_enable_legacy_ftl', true ) ) {
		return $id;
	}

	if ( \is_numeric( $path ) ) {
		$post = \get_post( $path );
	} else {
		$base32 = \preg_replace( '/[^0-9a-fA-F]/', '', $path );

		// A base-32 post ID is never wider than PHP_INT_MAX (13 characters).
		// Anything longer cannot be a valid ID and overflows base_convert() to
		// INF, which throws a ValueError on PHP 8, so bail before converting.
		if ( '' === $base32 || \strlen( $base32 ) > 13 ) {
			return $id;
		}

		$post_id = \base_convert( $base32, 32, 10 );
		$post    = \get_post( $post_id );
	}

	if ( $post ) {
		$id = $post->ID;
	}

	return $id;
}


// Admin Settings

/**
 * Register admin settings for Hum.
 */
function admin_init() {
	\register_setting( 'general', 'hum_shortlink_base' );
}

/**
 * Add admin settings fields for Hum.
 */
function admin_menu() {
	\add_settings_field( 'hum_shortlink_base', \__( 'Shortlink Base (URL)', 'hum' ), __NAMESPACE__ . '\admin_shortlink_base', 'general' );
}

/**
 * Admin UI for setting the shortlink base URL.
 */
function admin_shortlink_base() {
	?>
	<input name="hum_shortlink_base" type="text" id="hum_shortlink_base"
			value="<?php \form_option( 'hum_shortlink_base' ); ?>"
			<?php \disabled( \defined( 'HUM_SHORTLINK_BASE' ) ); ?>
			class="regular-text code<?php if ( \defined( 'HUM_SHORTLINK_BASE' ) ) { echo ' disabled'; } // phpcs:ignore Squiz.ControlStructures.ControlSignature.NewlineAfterOpenBrace ?>" />
	<p class="description">
		<?php \_e( 'If you have a custom domain you want to use for shortlinks, enter the address here.', 'hum' ); ?>
	</p>

	<script type="text/javascript">
		// move adjacent to other URL properties
		jQuery('input#hum_shortlink_base').parents('tr').insertAfter( jQuery('input#home').parents('tr') );
	</script>
	<?php
}

/**
 * Add shortlink <link /> to Atom-Entry.
 */
function shortlink_atom_entry() {
	$shortlink = \wp_get_shortlink();
	if ( $shortlink ) {
		echo "\t\t" . '<link rel="shortlink" href="' . \esc_attr( $shortlink ) . '" />' . PHP_EOL;
	}
}

/**
 * Show shortlink column.
 *
 * @param array $columns The list of columns.
 */
function add_post_column( $columns ) {
	$reorderes_columns = array();
	foreach ( $columns as $key => $value ) {
		if ( 'date' === $key ) {
			$reorderes_columns['shortlink'] = \esc_html__( 'Shortlink', 'hum' );
		}
		$reorderes_columns[ $key ] = $value;
	}

	return $reorderes_columns;
}

/**
 * Generate shortlink column.
 *
 * @param string $column_name The culumn name.
 * @param string $post_id The post id.
 */
function add_posts_custom_column( $column_name, $post_id ) {
	if ( 'shortlink' === $column_name ) {
		\printf( '<small>%s</small>', \wp_get_shortlink( $post_id ) );
	}
}
