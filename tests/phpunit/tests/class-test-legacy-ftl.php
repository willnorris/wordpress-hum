<?php
/**
 * Test file for the legacy Friendly Twitter Links redirect handler.
 *
 * @package Hum
 */

namespace Hum\Tests;

/**
 * Test class for Hum::legacy_ftl_id().
 *
 * @coversDefaultClass \Hum
 */
class Test_Legacy_Ftl extends \WP_UnitTestCase {

	/**
	 * Plugin instance under test.
	 *
	 * @var \Hum
	 */
	protected $hum;

	/**
	 * Post ID whose base-32 representation ("fba") survives the hex filter.
	 *
	 * @var int
	 */
	const KNOWN_POST_ID = 15722;

	/**
	 * Set up the test.
	 */
	public function set_up() {
		parent::set_up();

		$this->hum = new \Hum();
	}

	/**
	 * Tear down the test.
	 */
	public function tear_down() {
		remove_all_filters( 'hum_enable_legacy_ftl' );

		parent::tear_down();
	}

	/**
	 * Create a post with a predictable ID.
	 *
	 * @return int Post ID.
	 */
	protected function create_known_post() {
		return self::factory()->post->create( array( 'import_id' => self::KNOWN_POST_ID ) );
	}

	/**
	 * A numeric path resolves to the post with that ID.
	 *
	 * @covers ::legacy_ftl_id
	 */
	public function test_numeric_path_resolves_to_post() {
		$post_id = $this->create_known_post();

		$this->assertSame( $post_id, $this->hum->legacy_ftl_id( 0, (string) $post_id ) );
	}

	/**
	 * A base-32 path resolves to the post with the decoded ID.
	 *
	 * @covers ::legacy_ftl_id
	 */
	public function test_base32_path_resolves_to_post() {
		$post_id = $this->create_known_post();

		// base_convert( 'fba', 32, 10 ) === 15722.
		$this->assertSame( $post_id, $this->hum->legacy_ftl_id( 0, 'fba' ) );
	}

	/**
	 * A path that decodes to a non-existent post leaves the incoming ID untouched.
	 *
	 * @covers ::legacy_ftl_id
	 */
	public function test_unknown_post_returns_incoming_id() {
		$this->assertSame( 0, $this->hum->legacy_ftl_id( 0, 'ffffff' ) );
	}

	/**
	 * A long request path must not blow up base_convert().
	 *
	 * A path this long overflows the intermediate value to INF, which throws
	 * `ValueError: An infinite value cannot be converted to base 10` on PHP 8.
	 *
	 * @link https://github.com/pfefferle/wordpress-hum/issues/41
	 *
	 * @covers ::legacy_ftl_id
	 */
	public function test_very_long_path_does_not_throw() {
		$this->assertSame( 0, $this->hum->legacy_ftl_id( 0, str_repeat( 'f', 400 ) ) );
	}

	/**
	 * A stripped path wider than PHP_INT_MAX in base 32 is not converted at all.
	 *
	 * `7vvvvvvvvvvvv` (13 characters) is PHP_INT_MAX in base 32, so anything
	 * longer can never be a valid post ID and should bail before hitting the
	 * database.
	 *
	 * @covers ::legacy_ftl_id
	 */
	public function test_overlong_path_bails_without_querying() {
		$queries = get_num_queries();

		$this->assertSame( 0, $this->hum->legacy_ftl_id( 0, str_repeat( 'f', 14 ) ) );
		$this->assertSame( $queries, get_num_queries(), 'An over-length path should not hit the database.' );
	}

	/**
	 * A path at the 13-character boundary is still converted and looked up.
	 *
	 * Guards against the length check being off by one.
	 *
	 * @covers ::legacy_ftl_id
	 */
	public function test_boundary_length_path_is_still_looked_up() {
		$queries = get_num_queries();

		$this->assertSame( 0, $this->hum->legacy_ftl_id( 0, str_repeat( 'f', 13 ) ) );
		$this->assertGreaterThan( $queries, get_num_queries(), 'A 13-character path should still be looked up.' );
	}

	/**
	 * A path with no hex characters left after filtering is not converted.
	 *
	 * `base_convert( '', 32, 10 )` returns "0", and `get_post( 0 )` falls back to
	 * the global `$post` — which would redirect the request to whatever post
	 * happened to be in the global.
	 *
	 * @covers ::legacy_ftl_id
	 */
	public function test_path_without_hex_characters_ignores_global_post() {
		$GLOBALS['post'] = self::factory()->post->create_and_get();

		$this->assertSame( 0, $this->hum->legacy_ftl_id( 0, 'wxyz' ) );

		unset( $GLOBALS['post'] );
	}

	/**
	 * The decoder is enabled by default.
	 *
	 * @covers ::legacy_ftl_id
	 */
	public function test_decoder_is_enabled_by_default() {
		$post_id = $this->create_known_post();

		$this->assertTrue( apply_filters( 'hum_enable_legacy_ftl', true ) );
		$this->assertSame( $post_id, $this->hum->legacy_ftl_id( 0, 'fba' ) );
	}

	/**
	 * `hum_enable_legacy_ftl` turns the decoder off.
	 *
	 * @covers ::legacy_ftl_id
	 */
	public function test_filter_disables_decoder() {
		$this->create_known_post();

		add_filter( 'hum_enable_legacy_ftl', '__return_false' );

		$this->assertSame( 0, $this->hum->legacy_ftl_id( 0, 'fba' ) );
		$this->assertSame( 0, $this->hum->legacy_ftl_id( 0, (string) self::KNOWN_POST_ID ) );
	}

	/**
	 * Characterises the current behaviour: an ordinary 404 path is decoded too.
	 *
	 * `legacy_ftl_id()` strips every non-hex character before decoding, so
	 * `/foo/bar/` becomes "fba" and resolves to post 15722. This is surprising,
	 * but it is the documented pre-existing behaviour and `hum_enable_legacy_ftl`
	 * is the way out of it.
	 *
	 * @covers ::legacy_ftl_id
	 */
	public function test_ordinary_path_is_decoded_when_enabled() {
		$post_id = $this->create_known_post();

		$this->assertSame( $post_id, $this->hum->legacy_ftl_id( 0, 'foo/bar' ) );

		add_filter( 'hum_enable_legacy_ftl', '__return_false' );

		$this->assertSame( 0, $this->hum->legacy_ftl_id( 0, 'foo/bar' ) );
	}
}
