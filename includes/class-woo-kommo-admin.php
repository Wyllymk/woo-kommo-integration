<?php

/**
 * Class WooKommoAdmin
 * Handles all Admin settings and functionality
 * 
 * @package WyllyMk\WooKommoCRM
 * @since 1.0.0
 */
class WooKommoAdmin {
    /**
     * Singleton instance
     */
    private static $instance = null;
    private static $initialized = false; // Prevent multiple initializations

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize admin functionality
     */
    public function init() {
        if (self::$initialized) {
            return; // Prevent duplicate execution
        }
        self::$initialized = true;
        
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_filter('plugin_action_links_' . WOO_KOMMO_PLUGIN_BASENAME, array($this, 'add_settings_link'));
    }

    /**
     * Add settings link to the plugins page
     * 
     * @param array $links Array of plugin action links
     * @return array Modified array of plugin action links
     */
    public function add_settings_link($links) {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url('admin.php?page=woo-kommo-settings'),
            esc_html__('Settings', 'woo-kommo')
        );
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __('Woo-Kommo', 'woo-kommo'),
            __('Woo-Kommo', 'woo-kommo'),
            'manage_options',
            'woo-kommo-settings',
            [$this, 'render_settings_page'],
            'dashicons-admin-generic'
        );
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        $settings = [
            'woo_kommo_subdomain',
            'woo_kommo_client_id',
            'woo_kommo_client_secret',
            'woo_kommo_auth_code',
            'woo_kommo_redirect_uri',
        ];

        foreach ($settings as $setting) {
            register_setting('woo_kommo_settings', $setting);
        }
    }

    /**
     * Render settings page
     */
/**
 * Render settings page
 */
public function render_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'woo-kommo'));
    }
    ?>
<div class="wrap">
    <h1><?php _e('Kommo Integration Settings', 'woo-kommo'); ?></h1>

    <!-- Instructions Section -->
    <div class="woo-kommo-instructions">
        <h2><?php _e('Instructions', 'woo-kommo'); ?></h2>
        <p><?php _e('To integrate WooCommerce with Kommo, follow these steps:', 'woo-kommo'); ?></p>
        <ol>
            <li>
                <strong><?php _e('Obtain API Credentials:', 'woo-kommo'); ?></strong>
                <ul>
                    <li><?php _e('Log in to your Kommo account.', 'woo-kommo'); ?></li>
                    <li><?php _e('Navigate to the API settings section.', 'woo-kommo'); ?></li>
                    <li><?php _e('Create a new API application and note down the <strong>Client ID</strong>, <strong>Client Secret</strong>, and <strong>Redirect URI</strong>.', 'woo-kommo'); ?>
                    </li>
                </ul>
            </li>
            <li>
                <strong><?php _e('Enter API Credentials:', 'woo-kommo'); ?></strong>
                <ul>
                    <li><?php _e('Fill in the <strong>Subdomain</strong> (e.g., "yourcompany" if your Kommo URL is "yourcompany.kommo.com").', 'woo-kommo'); ?>
                    </li>
                    <li><?php _e('Enter the <strong>Client ID</strong> and <strong>Client Secret</strong> obtained from Kommo.', 'woo-kommo'); ?>
                    </li>
                    <li><?php _e('Set the <strong>Redirect URI</strong> to:', 'woo-kommo'); ?>
                        <code><?php echo admin_url('admin.php?page=woo-kommo-settings'); ?></code>
                    </li>
                    <li><?php _e('Click "Save Changes" to store your settings.', 'woo-kommo'); ?></li>
                </ul>
            </li>
            <li>
                <strong><?php _e('Authorize the Application:', 'woo-kommo'); ?></strong>
                <ul>
                    <li><?php _e('After saving the settings, click the "Authorize" button to connect your WooCommerce store with Kommo.', 'woo-kommo'); ?>
                    </li>
                    <li><?php _e('You will be redirected to Kommo to grant permissions. Once authorized, you will receive an <strong>Auth Code</strong>.', 'woo-kommo'); ?>
                    </li>
                    <li><?php _e('Enter the <strong>Auth Code</strong> in the field below and save the settings again.', 'woo-kommo'); ?>
                    </li>
                </ul>
            </li>
        </ol>
        <p><?php _e('Once the integration is complete, the following data will be sent to Kommo:', 'woo-kommo'); ?></p>
        <ul>
            <li>
                <?php _e('Customer contact information (contact_id, names (firstname + lastname), email, phone, country).', 'woo-kommo'); ?>
            </li>
            <!-- <li><?php _e('Order details (order ID, products purchased, total amount).', 'woo-kommo'); ?></li> -->
            <!-- <li><?php _e('Custom notes or tags associated with the order.', 'woo-kommo'); ?></li> -->
        </ul>
    </div>

    <!-- Settings Form -->
    <form method="post" action="options.php">
        <?php
            settings_fields('woo_kommo_settings');
            do_settings_sections('woo_kommo_settings');
            $this->render_settings_fields();
            submit_button();
            ?>
    </form>
</div>
<?php
}

    /**
     * Render settings fields
     */
    private function render_settings_fields() {
        $fields = [
            'woo_kommo_subdomain' => [
                'label' => __('Subdomain', 'woo-kommo'),
                'type'  => 'text',
            ],            
            'woo_kommo_client_id' => [
                'label' => __('Client ID', 'woo-kommo'),
                'type'  => 'text',
            ],
            'woo_kommo_client_secret' => [
                'label' => __('Client Secret', 'woo-kommo'),
                'type'  => 'password',
            ],
            'woo_kommo_auth_code' => [
                'label' => __('Auth Code', 'woo-kommo'),
                'type'  => 'text',
            ],
            'woo_kommo_redirect_uri' => [
                'label' => __('Redirect URI', 'woo-kommo'),
                'type'  => 'text',
            ],
        ];

        echo '<table class="form-table">';
        foreach ($fields as $field_name => $field_data) {
            ?>
<tr>
    <th scope="row">
        <label for="<?php echo esc_attr($field_name); ?>">
            <?php echo esc_html($field_data['label']); ?>
        </label>
    </th>
    <td>
        <input type="<?php echo esc_attr($field_data['type']); ?>" name="<?php echo esc_attr($field_name); ?>"
            id="<?php echo esc_attr($field_name); ?>" value="<?php echo esc_attr(get_option($field_name)); ?>"
            class="regular-text">
    </td>
</tr>
<?php
        }
        echo '</table>';
    }
}