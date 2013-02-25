<?php
/*
 Plugin Name: Hum
 Plugin URI: http://github.com/willnorris/wordpress-hum
 Description: Personal URL shortener for WordPress
 Author: Will Norris
 Author URI: http://willnorris.com/
 Version: 1.2-alpha
 License: MIT (http://opensource.org/licenses/MIT)
 Text Domain: hum
 */

class Hum {

  public function __construct() {
    add_action('init', array( $this, 'init' ));

    register_activation_hook(__FILE__, 'flush_rewrite_rules');
    register_deactivation_hook(__FILE__, 'flush_rewrite_rules');
  }

  /**
   * Initialize the plugin, registering WordPess hooks.
   */
  public function init() {
    load_plugin_textdomain( 'hum', null, basename( dirname( __FILE__ ) ) );

    // if you have hum installed, then you probably actually care about short
    // links, so we'll add it to the admin menu bar.
    add_action('admin_bar_menu', 'wp_admin_bar_shortlink_menu', 90);

    add_action('query_vars', array( $this, 'query_vars' ));
    add_action('parse_request', array( $this, 'parse_request' ));
    add_filter('hum_request', array( $this, 'redirect_local' ), 20, 2);
    add_action('hum_request', array( $this, 'redirect_request' ), 30, 2);
    add_filter('hum_request_i', array( $this, 'redirect_request_i' ), 20);
    add_action('generate_rewrite_rules', array( $this, 'rewrite_rules' ));
    add_filter('pre_option_hum_shortlink_base', array( $this, 'config_shortlink_base' ));
    add_filter('pre_get_shortlink', array( $this, 'get_shortlink' ), 10, 4);
    add_filter('template_redirect', array( $this, 'legacy_redirect' ));
    add_filter('hum_legacy_id', array( $this, 'legacy_ftl_id' ), 10, 2);
    add_action('atom_entry', array( $this, 'shortlink_atom_entry' ));

    // Admin Settings
    add_action('admin_init', array( $this, 'admin_init' ));
    add_action('admin_menu', array( $this, 'admin_menu' ));
  }

  /**
   * Accept hum query variables.
   */
  function query_vars( $vars ) {
    $vars[] = 'hum';
    return $vars;
  }

  /**
   * Parse request for shortlink.
   *
   * @uses do_action() Calls 'hum_request_{$type}' action
   * @uses do_action() Calls 'hum_request' action
   *
   * @param WP $wp the WordPress environment for the request
   */
  function parse_request( $wp ) {
    if ( array_key_exists( 'hum', $wp->query_vars ) ) {
      $hum_path = $wp->query_vars['hum'];
      if ( strpos($hum_path, '/') !== false ) {
        list($type, $id) = explode('/', $hum_path, 2);
      } else {
        $type = $hum_path;
        $id = null;
      }
      do_action("hum_request_{$type}", $id);
      do_action('hum_request', $type, $id);

      // hum hasn't handled the request yet, so try again but strip common
      // punctuation that might appear after a URL in written text: . , )
      $clean_id = preg_replace('/[\.,\)]+$/', '', $id);
      if ($id != $clean_id) {
        do_action("hum_request_{$type}", $clean_id);
        do_action('hum_request', $type, $clean_id);
      }

      // hum didn't handle request, so issue 404.
      // manually setting query vars like this feels very fragile, but
      // $wp_query->set_404() doesn't do what we need here.
      $wp->query_vars['error'] = '404';
    }
  }

  /**
   * Redirect shortlinks that are for content hosted directly within WordPress.
   * The 'id' portion of these URLs is expected to be the sexagesimal post ID.
   *
   * @uses apply_filters() Calls 'hum_local_types' filter on prefixes for local
   *     WordPress hosted content
   *
   * @param string $type the content-type prefix
   * @param string $id the requested post ID
   */
  function redirect_local( $type, $id ) {
    $local_types = array('b', 't', 'a', 'p');
    $local_types = apply_filters('hum_local_types', $local_types);

    if ( in_array($type, $local_types) ) {
      $p = sxg_to_num( $id );
      $permalink = get_permalink($p);

      if ( $permalink ) {
        wp_redirect( $permalink, 301 );
        exit;
      }
    }
  }

  /**
   * Handles /i/ URLs that have ISBN or ASIN subpaths by redirecting to Amazon.
   *
   * @uses do_action() Calls 'hum_request_i_{$subtype}' action
   * @uses apply_filters() Calls 'amazon_affiliate_id' filter
   *
   * @param string $path subpath of URL (after /i/)
   */
  function redirect_request_i( $path ) {
    list($subtype, $id) = preg_split('|/|', $path, 2);
    do_action("hum_request_i_{$subtype}", $id);
    switch ($subtype) {
      case 'a':
      case 'asin':
      case 'i':
      case 'isbn':
        $amazon_id = apply_filters('amazon_affiliate_id', false);
        if ($amazon_id) {
          wp_redirect('http://www.amazon.com/gp/redirect.html?ie=UTF8&location=' .
              'http%3A%2F%2Fwww.amazon.com%2Fdp%2F' . $id . '&tag=' . $amazon_id .
              '&linkCode=ur2&camp=1789&creative=9325');
        } else {
          wp_redirect('http://www.amazon.com/dp/' . $id );
        }
        exit;
        break;
    }
  }

  /**
   * Allow for simple redirect rules for shortlink prefixes.  Users can provide a
   * filter to perform simple URL redirect for a given type prefix.  For example,
   * to redirect all /w/ shortlinks to your personal PBworks wiki, you could use:
   *
   *   add_filter('hum_redirect_base_w',
   *     create_function('', 'return "http://willnorris.pbworks.com/";'));
   *
   * @uses apply_filters() Calls 'hum_redirect_base_{$type}' filter on redirect base URL
   *
   * @param string $type the content-type prefix
   * @param string $id the requested post ID
   */
  function redirect_request( $type, $id ) {
    $url = apply_filters("hum_redirect_base_{$type}", false);
    if ( $url ) {
      $url = trailingslashit($url) . $id;
      wp_redirect( $url );
      exit;
    }
  }

  /**
   * Add rewrite rules for hum shortlinks.
   *
   * @param WP_Rewrite $wp_rewrite WordPress rewrite component.
   */
  function rewrite_rules( $wp_rewrite ) {
    $hum_rules = array(
      '([a-z](/.*)?$)' => 'index.php?hum=$matches[1]',
    );

    $wp_rewrite->rules = $hum_rules + $wp_rewrite->rules;
  }

  /**
   * Get the base URL for hum shortlinks.  Defaults to the WordPress home url.
   * Users can define HUM_SHORTLINK_BASE or provide a filter to use a custom
   * domain for shortlinks.
   *
   * @uses apply_filters() Calls 'hum_shortlink_base' filter on base URL
   *
   * @return string
   */
  function shortlink_base() {
    $base = get_option('hum_shortlink_base');
    if ( empty( $base ) ) {
      $base = home_url();
    }
    return apply_filters( 'hum_shortlink_base', $base );
  }

  /**
   * Allow the constant named 'HUM_SHORTLINK_BASE' to override the base URL for shortlinks.
   */
  function config_shortlink_base( $url = '' ) {
    if ( defined( 'HUM_SHORTLINK_BASE') ) {
      return untrailingslashit( HUM_SHORTLINK_BASE );
    }
    return $url;
  }

  /**
   * Get the shortlink for a post, page, attachment, or blog.
   *
   * @param string $link the current shortlink for the post
   * @param int $id post ID
   * @param string $context
   * @param boolean $allow_slugs
   * @return string
   */
  function get_shortlink($link, $id, $context, $allow_slugs) {
    $post_id = 0;
    if ( 'query' == $context ) {
      if ( is_front_page() ) {
        $link = trailingslashit( $this->shortlink_base() );
      } elseif ( is_singular() ) {
        $post_id = get_queried_object_id();
      }
    } elseif ( 'post' == $context ) {
      $post = get_post($id);
      $post_id = $post->ID;
    }

    if ( !empty($post_id) ) {
      $type = $this->type_prefix($post_id);
      $sxg_id = num_to_sxg($post_id);
      $link = trailingslashit( $this->shortlink_base() ) . $type . '/' . $sxg_id;
    }

    return $link;
  }

  /**
   * Get the content-type prefix for the specified post.
   *
   * @see http://ttk.me/w/Whistle#design
   * @uses apply_filters() Calls 'hum_type_prefix' on the content type prefix
   *
   * @param int|object $post A post
   * @return string the content type prefix for the post
   */
  function type_prefix( $post ) {
    $prefix = 'b';

    $post_format = get_post_format( $post );
    switch($post_format) {
        case 'aside':
        case 'status':
        case 'link':
          $prefix = 't'; break;
        case 'audio':
        case 'video':
          $prefix = 'a'; break;
        case 'photo':
        case 'gallery':
        case 'image':
          $prefix = 'p'; break;
    }

    return apply_filters('hum_type_prefix', $prefix, $post);
  }

  /**
   * Support redirects from legacy short URL schemes.  This allows users to migrate from other
   * shortlink generaters, but still have hum support the old URLs.
   *
   * @uses do_action() Calls 'hum_legacy_id' with the post ID and shortlink path.
   */
  function legacy_redirect() {
    if ( is_404() ) {
      global $wp;
      $post_id = apply_filters('hum_legacy_id', 0, $wp->request);
      if ( $post_id ) {
        $permalink = get_permalink($post_id);
        if ( $permalink ) {
          wp_redirect($permalink, 301);
          exit;
        }
      }
    }
  }

  /**
   * Handle shortlinks generated by Friendly Twitter Links, which take the form
   * /{id}, where {id} can be the base10 or base32 post ID.
   *
   * @param int $id post ID to filter on
   * @param string $path URL path (without preceding slash) of the request
   *
   * @return string ID of post to redirect to
   */
  function legacy_ftl_id($id, $path) {
    if ( is_numeric($path) ) {
      $post = get_post($path);
    } else {
      $post_id = base_convert($path, 32, 10);
      $post = get_post($post_id);
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
    register_setting('general', 'hum_shortlink_base');
  }

  /**
   * Add admin settings fields for Hum.
   */
  function admin_menu() {
    add_settings_field('hum_shortlink_base', __('Shortlink Base (URL)', 'hum'),
        array( $this, 'admin_shortlink_base'), 'general');
  }

  /**
   * Admin UI for setting the shortlink base URL.
   */
  function admin_shortlink_base() {
  ?>
    <input name="hum_shortlink_base" type="text" id="hum_shortlink_base"
        value="<?php form_option('hum_shortlink_base'); ?>"
        <?php disabled( defined( 'HUM_SHORTLINK_BASE') ); ?>
        class="regular-text code<?php if ( defined( 'HUM_SHORTLINK_BASE') ) echo ' disabled' ?>" />
    <p class="description">
      <?php _e('If you have a custom domain you want to use for shortlinks, enter the address here.', 'hum'); ?>
    </p>

    <script>
      // move adjacent to other URL properties
      jQuery('input#hum_shortlink_base').parents('tr')
          .insertAfter( jQuery('input#home').parents('tr') );
    </script>
  <?php
  }
  
  /**
   * Add shortlink <link /> to Atom-Entry
   */
  function shortlink_atom_entry() {
    $shortlink = wp_get_shortlink();

    if ( empty( $shortlink ) )
      return;
    
    echo "<link rel='shortlink' href='" . esc_url( $shortlink ) . "' />\n";
  }
}

new Hum;


// New Base 60 - see http://ttk.me/w/NewBase60
//
// slightly modified from Cassis Project (http://cassisproject.com/)
// Copyright 2010 Tantek Çelik, used with permission under CC0 license (http://git.io/tZ8fjw)

if ( !function_exists( 'num_to_sxg' ) ):
/**
 * Convert base-10 number to sexagesimal.
 */
function num_to_sxg($n) {
  $s = "";
  $m = "0123456789ABCDEFGHJKLMNPQRSTUVWXYZ_abcdefghijkmnopqrstuvwxyz";
  if ($n===null || $n===0) { return 0; }
  while ($n>0) {
    $d = $n % 60;
    $s = $m[$d] . $s;
    $n = ($n-$d)/60;
  }
  return $s;
}
endif;


if ( !function_exists( 'sxg_to_num' ) ):
/**
 * Convert sexagesimal to base-10 number.
 */
function sxg_to_num($s) {
  $n = 0;
  $j = strlen($s);
  for ($i=0;$i<$j;$i++) { // iterate from first to last char of $s
    $c = ord($s[$i]); //  put current ASCII of char into $c
    if ($c>=48 && $c<=57) { $c=$c-48; }
    else if ($c>=65 && $c<=72) { $c-=55; }
    else if ($c==73 || $c==108) { $c=1; } // typo capital I, lowercase l to 1
    else if ($c>=74 && $c<=78) { $c-=56; }
    else if ($c==79) { $c=0; } // error correct typo capital O to 0
    else if ($c>=80 && $c<=90) { $c-=57; }
    else if ($c==95) { $c=34; } // underscore
    else if ($c>=97 && $c<=107) { $c-=62; }
    else if ($c>=109 && $c<=122) { $c-=63; }
    else { $c = 0; } // treat all other noise as 0
    $n = 60*$n + $c;
  }
  return $n;
}
endif;

