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

define( 'HUM_PLUGIN_FILE', __FILE__ );

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/class-hum.php';

register_activation_hook( HUM_PLUGIN_FILE, array( Hum::class, 'activate' ) );
register_deactivation_hook( HUM_PLUGIN_FILE, array( Hum::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( Hum::class, 'bootstrap' ) );
