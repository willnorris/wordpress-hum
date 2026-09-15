<?php
/**
 * Test file for the plugin bootstrap.
 *
 * @package Hum
 */

namespace Hum\Tests;

/**
 * Test class for the hooks and paths that hang off the main plugin file.
 */
class Test_Plugin_File extends \WP_UnitTestCase {

	/**
	 * Path of the plugin directory.
	 *
	 * @var string
	 */
	protected $plugin_dir;

	/**
	 * Set up the test.
	 */
	public function set_up() {
		parent::set_up();

		$this->plugin_dir = \dirname( __DIR__, 3 );
	}

	/**
	 * The activation and deactivation hooks hang off the main plugin file.
	 */
	public function test_activation_hooks_hang_off_the_main_plugin_file() {
		$plugin = \plugin_basename( $this->plugin_dir . '/hum.php' );

		$this->assertNotFalse( \has_action( 'activate_' . $plugin, 'Hum\activate' ) );
		$this->assertNotFalse( \has_action( 'deactivate_' . $plugin, 'Hum\deactivate' ) );
	}

	/**
	 * The plugin hooks plain functions on `init`, not an object.
	 *
	 * Plain function callbacks are what makes the hooks removable for other
	 * plugins, so this guards against sliding back to `array( $obj, 'init' )`.
	 */
	public function test_init_hooks_are_registered_as_functions() {
		$this->assertSame( 10, \has_action( 'init', 'Hum\init' ) );
		$this->assertSame( 15, \has_action( 'init', 'Hum\rewrite_rules' ) );
	}

	/**
	 * The textdomain is registered against the plugin directory.
	 *
	 * Since WP 6.7 `load_plugin_textdomain()` does not load anything itself, it
	 * only records a custom path on the textdomain registry, so that path is the
	 * thing worth asserting on.
	 */
	public function test_textdomain_path_is_the_plugin_directory() {
		global $wp_textdomain_registry;

		\Hum\init();

		$custom_paths = new \ReflectionProperty( $wp_textdomain_registry, 'custom_paths' );
		$custom_paths->setAccessible( true );
		$paths = $custom_paths->getValue( $wp_textdomain_registry );

		$this->assertArrayHasKey( 'hum', $paths );
		$this->assertSame( \basename( $this->plugin_dir ), \basename( $paths['hum'] ) );
	}

	/**
	 * The editor script is enqueued from the plugin root.
	 *
	 * The asset file is `include`d by path, so a wrong root does not just build a
	 * broken URL, it fails to read `build/index.asset.php` at all.
	 */
	public function test_editor_script_is_enqueued_from_the_plugin_root() {
		\Hum\enqueue_block_editor_script();

		$this->assertTrue( \wp_script_is( 'hum-editor-script', 'enqueued' ) );
		$this->assertStringEndsWith( '/' . \basename( $this->plugin_dir ) . '/build/index.js', \wp_scripts()->registered['hum-editor-script']->src );
	}
}
