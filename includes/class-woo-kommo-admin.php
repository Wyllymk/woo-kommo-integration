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
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'woo-kommo'));
        }
        ?>
<div class="wrap">
    <h1><?php _e('Kommo Integration Settings', 'woo-kommo'); ?></h1>
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