=== Hum ===
Contributors: willnorris, pfefferle
Tags: shortlink, whistle, diso
Requires at least: 3.0
Tested up to: 4.7.4
Stable tag: 1.2.2
License: MIT
License URI: http://opensource.org/licenses/MIT

Personal URL shortener for WordPress


== Description ==

Hum is a personal URL shortener for WordPress, designed to provide short URLs to your personal content, both hosted on WordPress and elsewhere.  For example, rather than a long URL for a WordPress post such as <http://willnorris.com/2011/01/hum-personal-url-shortener-wordpress>, you could have a short URL like <http://willnorris.com/b/FJ>.  Additional, if you have a custom domain for short URLs, you can shorten things further like <http://wjn.me/b/FJ>.

WordPress post IDs are shortened using the [NewBase60][] encoding scheme which is specifically optimized for brevity and readability, with built-in error correction for commonly confused characters like '1', 'l', and 'I'.

Hum is not designed as a general purpose URL shortener along the lines of <http://bit.ly> or <http://goo.gl>.  Rather, it is specifically intended as a personal shortener for your own content.

Read more about the reasoning for a personal URL shortener at [Tantek Celik][]'s page for [Whistle][], which served as the inspiration for Hum.

[NewBase60]: http://ttk.me/w/NewBase60
[Tantek Celik]: http://tantek.com/
[Whistle]: http://ttk.me/w/Whistle


== Installation ==

Follow the normal instructions for [installing WordPress plugins][install].

[install]: http://codex.wordpress.org/Managing_Plugins#Installing_Plugins

= Using a custom domain =

If you have a custom domain you'd like to use with Hum, add it as the 'Shortlink Base (URL)' on the 'General Settings' WordPress admin page or define the `HUM_SHORTLINK_BASE` constant in your `wp-config.php`:

    define('HUM_SHORTLINK_BASE', 'http://wjn.me');

You will also need to setup your short domain to redirect to your normal domain.  Many domain registrars provide free redirection services that work well for this, so you don't need to setup a new domain with your web host. Just make sure that you are **not** using an iframe style redirect.


== Frequently Asked Questions ==

= What types of content does Hum support? =

Out of the box, Hum will provide shortlinks for any content locally hosted on WordPress.  Most shortlinks will use the `b` type prefix, with the exception of posts with a 'status' [post format][], which have shortlinks using the `t` type prefix.  For example:

 - <http://wjn.me/b/FJ>
 - <http://wjn.me/t/FR>

Additionally, the `i` type prefix, along with one of four subtypes, is supported as follows:

 - `asin` or `a` for Amazon ASIN numbers
 - `isbn` or `i` for ISBN numbers

All `i` URLs are redirected to Amazon.com.  For example:

 - <http://wjn.me/i/a/B003QP4NPE>

Additional type prefixes can be registered to serve WordPress hosted content or to redirect to an external service.  See more in the developer documentation.

[post format]: http://codex.wordpress.org/Post_Formats


== Developer Documentation ==

= Adding your Amazon Affiliate ID =

If you'd like to include your Amazone Affiliate ID in the `/i/` redirect URLs, implement the `amazon_affiliate_id` filter.  For example:

    add_filter('amazon_affiliate_id', create_function('', 'return "willnorris-20";'));

= Additional Local Types =

Out of the box, Hum only registers the `b` and `t` prefix to be served locally by WordPress.  If you would like to register additional prefixes, implement the `hum_local_types` filter.  For example, to include 'p' as well for photos:

    function myplugin_hum_local_types( $types ) {
      $types[] = 'p';
      return $types;
    }
    add_filter('hum_local_types', 'myplugin_hum_local_types');

This will tell Hum to serve any `/p/{id}` URLs from WordPress.  Additionally, you'll want to instruct Hum to use your prefix for that particular content type.  Here, we're registering 'p' which is normally used for photos.

    function myplugin_hum_type_prefix( $prefix, $post_id ) {
      $post = get_post( $post_id );

      if ( $post->post_type == 'attachment' &&
           strpos($post->post_mime_type, 'image') === 0 ) {
        $prefix = 'p';
      }

      return $prefix;
    }
    add_filter('hum_type_prefix', 'myplugin_hum_type_prefix', 10, 2);

= Simple Redirect =

You can redirect all traffic for a prefix using a single line of PHP my implementing the `hum_redirect_base_{type}` filter where `{type}` is the prefix to redirect.  For example, I redirect all `/w/` URLs to wiki.willnorris.com using:

    add_filter('hum_redirect_base_w',
      create_function('', 'return "http://wiki.willnorris.com/";'));


== Changelog ==

Project maintined on github at [willnorris/wordpress-hum](https://github.com/willnorris/wordpress-hum).

= 1.2.2 =

 - version bump

= 1.2.1 =

 - add `amazon_domain` filter, to support different countries
 - add `hum_process_redirect` action, to overwrite default rewrite method (see [#17][])

[full changelog](https://github.com/willnorris/wordpress-hum/compare/1.2...1.2.1)

= 1.2 =

 - move link post format to use 't' prefix instead of 'h' and add support for
   image post format
 - add support for WordPress media attachments
 - add shortlinks to Atom feeds
 - add support for legacy short url schemes (see [#6][])
 - switch to using WordPress filters instead of actions for hum extensions (see
   [#3][])

[full changelog](https://github.com/willnorris/wordpress-hum/compare/1.1...1.2)

= 1.1 =
 - allow custom domain to be configured using `HUM_SHORTLINK_BASE` constant or
   via the General Settings admin page.
 - strip some punctuation at the end of URLs (see [#4][])
 - smarter URL matching (see [#1][] and [#2][])
 - add support for `/i/` URLs (redirects to Amazon for ASIN or ISBN
   identifiers, optionally including an Amazon affiliate ID)
 - standard 404 handling if hum can't find a proper redirect
 - add new `hum_local_types` filter for registering other prefixes thatt are
   served locally by WordPress
 - reduce extra redirect for local content

[full changelog](https://github.com/willnorris/wordpress-hum/compare/1.0...1.1)

[#1]: https://github.com/willnorris/wordpress-hum/issues/1
[#2]: https://github.com/willnorris/wordpress-hum/issues/2
[#3]: https://github.com/willnorris/wordpress-hum/issues/3
[#4]: https://github.com/willnorris/wordpress-hum/issues/4
[#6]: https://github.com/willnorris/wordpress-hum/issues/6
[#17]: https://github.com/willnorris/wordpress-hum/pull/17

= 1.0 =
 - initial public release


== Upgrade Notice ==

= 1.1 =
Adds a new admin UI for setting a custom domain for shortlinks, includes
smarter URL matching, and adds various small improvements and bug fixes.
