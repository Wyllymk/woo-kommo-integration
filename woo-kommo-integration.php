<?php
/**
 * Plugin Name: WooCommerce Kommo Integration
 * Plugin URI: https://github.com/Wyllymk/woo-kommo-integration
 * Description: Integrate WordPress/WooCommerce site with Kommo CRM
 * Version: 1.0.0
 * Author: Trading Tech Solutions
 * Author URI: #
 * Text Domain: woo-kommo
 * Requires PHP: 7.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Autoload Composer dependencies
require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';

// Define plugin constants
define('WOO_KOMMO_VERSION', '1.0.0');
define('WOO_KOMMO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WOO_KOMMO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WOO_KOMMO_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main plugin class
 */
class WooKommoIntegration {
    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Track initialization
     */
    private static $initialized = false;

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init();
    }

    private function init() {
        if (self::$initialized) {
            return; // Prevent duplicate execution
        }
        self::$initialized = true;

        // Check if WooCommerce is active
        if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
            add_action('admin_notices', [$this, 'woocommerce_missing_notice']);
            return;
        }

        // Include required files
        require_once WOO_KOMMO_PLUGIN_DIR . 'includes/class-woo-kommo-admin.php';
        require_once WOO_KOMMO_PLUGIN_DIR . 'includes/class-woo-kommo-api.php';

        // Initialize admin and API classes
        WooKommoAdmin::get_instance()->init();
        WooKommoAPI::get_instance()->init();
    }

    /**
     * Display notice if WooCommerce is not active
     */
    public function woocommerce_missing_notice() {
        ?>
<div class="error">
    <p><?php _e('WooCommerce Kommo Integration requires WooCommerce to be installed and active.', 'woo-kommo'); ?></p>
</div>
<?php
    }

}

// Initialize the plugin
add_action('plugins_loaded', function() {
    static $plugin_loaded = false;
    if (!$plugin_loaded) {
        WooKommoIntegration::get_instance();
        $plugin_loaded = true;
    }
});