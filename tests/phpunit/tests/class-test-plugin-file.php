<?php
/**
 * Test file for the plugin bootstrap and its file paths.
 *
 * @package Hum
 */

namespace Hum\Tests;

/**
 * Test class for the paths that hang off the main plugin file.
 *
 * The class lives in `includes/class-hum.php`, so `__FILE__` and `__DIR__`
 * inside it point at `includes/`, not at the plugin root. Everything that needs
 * the plugin root has to go through `HUM_PLUGIN_FILE`. These tests fail if any
 * of it drifts back.
 *
 * @coversDefaultClass \Hum
 */
class Test_Plugin_File extends \WP_UnitTestCase {

	/**
	 * Plugin instance under test.
	 *
	 * @var \Hum
	 */
	protected $hum;

	/**
	 * Set up the test.
	 */
	public function set_up() {
		parent::set_up();

		$this->hum = new \Hum();
	}

	/**
	 * HUM_PLUGIN_FILE points at the file carrying the plugin header.
	 */
	public function test_constant_points_at_the_main_plugin_file() {
		$this->assertTrue( defined( 'HUM_PLUGIN_FILE' ) );
		$this->assertSame( 'hum.php', basename( HUM_PLUGIN_FILE ) );
		$this->assertFileExists( HUM_PLUGIN_FILE );
		$this->assertStringContainsString( 'Plugin Name: Hum', file_get_contents( HUM_PLUGIN_FILE ) );
	}

	/**
	 * The activation hook hangs off the main plugin file, not off the class file.
	 *
	 * `register_activation_hook()` derives its action name from the file it is
	 * given. Pointed at `includes/class-hum.php` it would register an action
	 * WordPress never fires, and rewrite rules would stop being flushed on
	 * activation, silently.
	 */
	public function test_activation_hook_hangs_off_the_main_plugin_file() {
		$this->assertNotFalse(
			has_action( 'activate_' . plugin_basename( HUM_PLUGIN_FILE ), array( 'Hum', 'activate' ) ),
			'No activation hook registered for the main plugin file.'
		);

		$class_file = dirname( HUM_PLUGIN_FILE ) . '/includes/class-hum.php';
		$this->assertFalse(
			has_action( 'activate_' . plugin_basename( $class_file ) ),
			'The activation hook must not hang off the class file.'
		);
	}

	/**
	 * The deactivation hook hangs off the main plugin file too.
	 */
	public function test_deactivation_hook_hangs_off_the_main_plugin_file() {
		$this->assertNotFalse(
			has_action( 'deactivate_' . plugin_basename( HUM_PLUGIN_FILE ), array( 'Hum', 'deactivate' ) )
		);

		$class_file = dirname( HUM_PLUGIN_FILE ) . '/includes/class-hum.php';
		$this->assertFalse(
			has_action( 'deactivate_' . plugin_basename( $class_file ), array( 'Hum', 'deactivate' ) )
		);
	}

	/**
	 * The textdomain is registered against the plugin directory, not `includes/`.
	 *
	 * Since WP 6.7 `load_plugin_textdomain()` does not load anything itself, it
	 * only records a custom path on the textdomain registry, so that path is the
	 * thing worth asserting on.
	 */
	public function test_textdomain_path_is_the_plugin_directory() {
		global $wp_textdomain_registry;

		$this->hum->init();

		$custom_paths = new \ReflectionProperty( $wp_textdomain_registry, 'custom_paths' );
		$custom_paths->setAccessible( true );
		$paths = $custom_paths->getValue( $wp_textdomain_registry );

		$this->assertArrayHasKey( 'hum', $paths );
		$this->assertSame(
			basename( dirname( HUM_PLUGIN_FILE ) ),
			basename( $paths['hum'] ),
			'The textdomain path must point at the plugin directory.'
		);
	}

	/**
	 * The plugin boots through Hum::bootstrap(), not a bare `new Hum()`.
	 *
	 * Repeated calls must hand back the same instance, so the `init` hooks are
	 * only ever registered once.
	 */
	public function test_bootstrap_is_idempotent() {
		$first  = \Hum::bootstrap();
		$second = \Hum::bootstrap();

		$this->assertInstanceOf( \Hum::class, $first );
		$this->assertSame( $first, $second );

		$this->assertSame( 10, has_action( 'init', array( $first, 'init' ) ) );
		$this->assertSame( 15, has_action( 'init', array( $first, 'rewrite_rules' ) ) );
	}

	/**
	 * The editor script is enqueued from the plugin root.
	 *
	 * The asset file is `include`d by path, so a wrong root does not just build a
	 * broken URL, it fails to read `build/index.asset.php` at all.
	 */
	public function test_editor_script_is_enqueued_from_the_plugin_root() {
		$this->assertFileExists( plugin_dir_path( HUM_PLUGIN_FILE ) . 'build/index.asset.php' );

		$this->hum->enqueue_block_editor_script();

		$this->assertTrue( wp_script_is( 'hum-editor-script', 'enqueued' ) );

		$src = wp_scripts()->registered['hum-editor-script']->src;

		$this->assertStringEndsWith( '/build/index.js', $src );
		$this->assertStringNotContainsString( '/includes/', $src );
	}
}
