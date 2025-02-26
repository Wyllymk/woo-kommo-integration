<?php

/**
 * Class WooKommoAPI
 * Handles all Kommo API interactions
 * 
 * @package WyllyMk\WooKommoCRM
 * @since 1.0.0
 */
class WooKommoAPI {
    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Guzzle client instance
     */
    private $client;

    /**
     * API base URL
     */
    private $base_url;

    /**
     * Subdomain for Kommo API
     */
    private $subdomain;

    /**
     * Client ID for Kommo API
     */
    private $client_id;

    /**
     * Client secret for Kommo API
     */
    private $client_secret;

    /**
     * Redirect URI for Kommo API
     */
    private $redirect_uri;

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
     * Initialize API functionality
     */
    public function init() {
        $this->subdomain = get_option('woo_kommo_subdomain');
        $this->client_id = get_option('woo_kommo_client_id');
        $this->client_secret = get_option('woo_kommo_client_secret');
        $this->redirect_uri = get_option('woo_kommo_redirect_uri');

        $this->base_url = "https://{$this->subdomain}.kommo.com";
        $this->client = new \GuzzleHttp\Client();

        add_action('admin_init', [$this, 'get_access_token']);
        add_action('woocommerce_created_customer', [$this, 'handle_new_customer']);
        add_action('woocommerce_save_account_details', [$this, 'handle_customer_update']);
        add_action('woocommerce_checkout_order_processed', [$this, 'handle_new_order'], 10, 3);
    }

    /**
     * Get access token
     */
    public function get_access_token() {
        $stored_token = get_option('woo_kommo_access_token');
        $token_expires = get_option('woo_kommo_token_expires');

        error_log('Stored Token: ' . $stored_token);

        // Return stored token if it's still valid
        if ($stored_token && !$this->is_token_expired($token_expires)) {
            return $stored_token;
        }

        // Attempt to refresh the token
        $refresh_token = get_option('woo_kommo_refresh_token');
        if ($refresh_token) {
            try {
                $new_token = $this->refresh_access_token($refresh_token);
                if ($new_token) {
                    return $new_token;
                }
            } catch (Exception $e) {
                error_log('Kommo API Refresh Token Error: ' . $e->getMessage());
                // If refresh fails, clear tokens and attempt reauthorization
                $this->clear_tokens();
            }
        }

        // If no refresh token is available or refresh fails, attempt reauthorization
        $authorization_code = get_option('woo_kommo_auth_code');
        if ($authorization_code) {
            try {
                return $this->handle_authorization_code($authorization_code);
            } catch (Exception $e) {
                error_log('Kommo API Reauthorization Error: ' . $e->getMessage());
                return false;
            }
        }

        // If no authorization code is available, log an error
        error_log('No refresh token or authorization code available. Please reauthorize the application.');
        return false;
    }

    /**
     * Check if the token is expired
     */
    private function is_token_expired($token_expires) {
        return !$token_expires || $token_expires <= time();
    }

    /**
     * Refresh the access token using the refresh token
     */
    private function refresh_access_token($refresh_token) {
        try {
            $response = $this->client->request('POST', $this->base_url . '/oauth2/access_token', [
                'headers' => [
                    'accept' => 'application/json',
                    'content-type' => 'application/json',
                ],
                'json' => [
                    'client_id' => $this->client_id,
                    'client_secret' => $this->client_secret,
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refresh_token,
                    'redirect_uri' => $this->redirect_uri,
                ],
            ]);

            return $this->save_token_data($response);
        } catch (Exception $e) {
            throw new Exception('Failed to refresh access token: ' . $e->getMessage());
        }
    }

    /**
     * Save token data from the API response
     */
    private function save_token_data($response) {
        try {
            $data = json_decode($response->getBody(), true);

            if (empty($data['access_token']) || empty($data['refresh_token']) || empty($data['expires_in'])) {
                throw new Exception('Invalid token data received from Kommo API.');
            }

            $expires_in = time() + $data['expires_in'];
            update_option('woo_kommo_access_token', $data['access_token']);
            update_option('woo_kommo_refresh_token', $data['refresh_token']);
            update_option('woo_kommo_token_expires', $expires_in);

            return $data['access_token'];
        } catch (Exception $e) {
            throw new Exception('Failed to save token data: ' . $e->getMessage());
        }
    }

    /**
     * Handle initial authorization code exchange
     */
    public function handle_authorization_code($authorization_code) {
        try {
            $response = $this->client->request('POST', $this->base_url . '/oauth2/access_token', [
                'headers' => [
                    'accept' => 'application/json',
                    'content-type' => 'application/json',
                ],
                'json' => [
                    'client_id' => $this->client_id,
                    'client_secret' => $this->client_secret,
                    'grant_type' => 'authorization_code',
                    'code' => $authorization_code,
                    'redirect_uri' => $this->redirect_uri,
                ],
            ]);

            return $this->save_token_data($response);
        } catch (Exception $e) {
            throw new Exception('Failed to handle authorization code: ' . $e->getMessage());
        }
    }

    /**
     * Clear stored tokens
     */
    private function clear_tokens() {
        delete_option('woo_kommo_access_token');
        delete_option('woo_kommo_refresh_token');
        delete_option('woo_kommo_token_expires');
    }

    /**
     * Retrieve all contacts from Kommo API and log them
     */
    public function retrieve_all_contacts() {
        try {
            $access_token = $this->get_access_token();
            if (!$access_token) {
                throw new Exception('No access token available.');
            }

            $response = $this->client->request('GET', $this->base_url . '/api/v4/contacts', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'accept' => 'application/json',
                ],
            ]);

            $contacts = json_decode($response->getBody(), true);
            error_log('Retrieved Contacts: ' . print_r($contacts, true));
            return $contacts;
        } catch (Exception $e) {
            error_log('Kommo API Retrieve Contacts Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Retrieve all fields (including custom fields) from Kommo API and log them
     */
    public function retrieve_all_fields() {
        try {
            $access_token = $this->get_access_token();
            if (!$access_token) {
                throw new Exception('No access token available.');
            }

            $response = $this->client->request('GET', $this->base_url . '/api/v4/contacts/custom_fields', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'accept' => 'application/json',
                ],
            ]);

            $fields = json_decode($response->getBody(), true);
            error_log('Retrieved Fields: ' . print_r($fields, true));
            return $fields;
        } catch (Exception $e) {
            error_log('Kommo API Retrieve Fields Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Handle new WooCommerce order
     */
    public function handle_new_order($order_id, $posted_data, $order) {
        error_log('Kommo Integration: New order processed');
        try {
            // Initialize the API
            $api = WooKommoAPI::get_instance();
            $api->init();

            // Create or update a contact in Kommo from the order
            $api->create_kommo_contact_from_order($order_id);
        } catch (Exception $e) {
            error_log('Kommo Integration Error: ' . $e->getMessage());
        }
    }

    /**
     * Handle new customer account creation
     */
    public function handle_new_customer($customer_id) {
        error_log('Kommo Integration: New customer account created');
        try {
            // Initialize the API
            $api = WooKommoAPI::get_instance();
            $api->init();

            // Create or update a contact in Kommo from the customer data
            $api->create_or_update_kommo_contact_from_customer($customer_id);
        } catch (Exception $e) {
            error_log('Kommo Integration Error: ' . $e->getMessage());
        }
    }

    /**
     * Handle customer account update
     */
    public function handle_customer_update($customer_id) {
        error_log('Kommo Integration: Customer account updated');
        try {
            // Initialize the API
            $api = WooKommoAPI::get_instance();
            $api->init();

            // Create or update a contact in Kommo from the customer data
            $api->create_or_update_kommo_contact_from_customer($customer_id);
        } catch (Exception $e) {
            error_log('Kommo Integration Error: ' . $e->getMessage());
        }
    }

    /**
     * Check if a contact with the given email already exists in Kommo
     */
    private function get_contact_by_email($email) {
        try {
            $access_token = $this->get_access_token();
            if (!$access_token) {
                throw new Exception('No access token available.');
            }

            $response = $this->client->request('GET', $this->base_url . '/api/v4/contacts', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'accept' => 'application/json',
                ],
                'query' => [
                    'query' => $email,
                ],
            ]);

            $contacts = json_decode($response->getBody(), true);
            if (!empty($contacts['_embedded']['contacts'])) {
                return $contacts['_embedded']['contacts'][0];
            }
            return null;
        } catch (Exception $e) {
            error_log('Kommo API Get Contact Error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Create or update a contact in Kommo from a WooCommerce order
     */
    public function create_kommo_contact_from_order($order_id) {
        try {
            $access_token = $this->get_access_token();
            if (!$access_token) {
                throw new Exception('No access token available.');
            }

            // Get the WooCommerce order
            $order = wc_get_order($order_id);
            if (!$order) {
                throw new Exception('Order not found.');
            }

            // Extract customer details
            $customer_email = $order->get_billing_email();

            // Check if a contact with the same email already exists
            $existing_contact = $this->get_contact_by_email($customer_email);
            if ($existing_contact) {
                // Update the existing contact
                return $this->update_kommo_contact_from_order($order_id, $existing_contact['id']);
            } else {
                // Create a new contact
                return $this->create_new_kommo_contact_from_order($order_id);
            }
        } catch (Exception $e) {
            error_log('Kommo API Create/Update Contact Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Create a new contact in Kommo from a WooCommerce order
     */
    private function create_new_kommo_contact_from_order($order_id) {
        try {
            $access_token = $this->get_access_token();
            if (!$access_token) {
                throw new Exception('No access token available.');
            }

            // Get the WooCommerce order
            $order = wc_get_order($order_id);
            if (!$order) {
                throw new Exception('Order not found.');
            }

            // Extract customer details
            $customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
            $customer_email = $order->get_billing_email();
            $customer_phone = $order->get_billing_phone();
            $customer_address = $order->get_billing_address_1();
            $customer_city = $order->get_billing_city();
            $customer_country = $order->get_billing_country();

            // Get the order creation date
            $order_created_date = $order->get_date_created();
            $creation_date = $order_created_date ? $order_created_date->date('Y-m-d H:i:s') : current_time('mysql');

            // Prepare the contact data
            $contact_data = [
                'name' => $customer_name,
                'custom_fields_values' => [
                    [
                        'field_id' => 1841202, // Phone field ID
                        'values' => [
                            [
                                'value' => $customer_phone                                
                            ],
                        ],
                    ],
                    [
                        'field_id' => 1841204, // Email field ID
                        'values' => [
                            [
                                'value' => $customer_email
                            ],
                        ],
                    ],
                    [
                        'field_id' => 2075892, // Country field ID
                        'values' => [
                            [
                                'value' => $customer_country,
                            ],
                        ],
                    ],
                    [
                        'field_id' => 2075894, // Creation date field ID
                        'values' => [
                            [
                                'value' => $creation_date, // Use the order creation date
                            ],
                        ],
                    ],
                    // Add more custom fields as needed
                ],
            ];

            // Send the request to create the contact
            $response = $this->client->request('POST', $this->base_url . '/api/v4/contacts', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'accept' => 'application/json',
                    'content-type' => 'application/json',
                ],
                'json' => [$contact_data], // Kommo API expects an array of contacts
            ]);

            $response_data = json_decode($response->getBody(), true);
            error_log('Created Contact: ' . print_r($response_data, true));
            return $response_data;
        } catch (Exception $e) {
            error_log('Kommo API Create Contact Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update an existing contact in Kommo from a WooCommerce order
     */
    private function update_kommo_contact_from_order($order_id, $contact_id) {
        try {
            $access_token = $this->get_access_token();
            if (!$access_token) {
                throw new Exception('No access token available.');
            }

            // Get the WooCommerce order
            $order = wc_get_order($order_id);
            if (!$order) {
                throw new Exception('Order not found.');
            }

            // Extract customer details
            $customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
            $customer_email = $order->get_billing_email();
            $customer_phone = $order->get_billing_phone();
            $customer_address = $order->get_billing_address_1();
            $customer_city = $order->get_billing_city();
            $customer_country = $order->get_billing_country();

            // Get the order creation date
            $order_created_date = $order->get_date_created();
            $creation_date = $order_created_date ? $order_created_date->date('Y-m-d H:i:s') : current_time('mysql');

            // Prepare the contact data
            $contact_data = [
                'id' => $contact_id, // Include the contact ID to update the existing contact
                'name' => $customer_name,
                'custom_fields_values' => [
                    [
                        'field_id' => 1841202, // Phone field ID
                        'values' => [
                            [
                                'value' => $customer_phone                                
                            ],
                        ],
                    ],
                    [
                        'field_id' => 1841204, // Email field ID
                        'values' => [
                            [
                                'value' => $customer_email
                            ],
                        ],
                    ],
                    [
                        'field_id' => 2075892, // Country field ID
                        'values' => [
                            [
                                'value' => $customer_country,
                            ],
                        ],
                    ],
                    [
                        'field_id' => 2075894, // Creation date field ID
                        'values' => [
                            [
                                'value' => $creation_date, // Use the order creation date
                            ],
                        ],
                    ],
                    // Add more custom fields as needed
                ],
            ];

            // Send the request to update the contact
            $response = $this->client->request('PATCH', $this->base_url . '/api/v4/contacts', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'accept' => 'application/json',
                    'content-type' => 'application/json',
                ],
                'json' => [$contact_data], // Kommo API expects an array of contacts
            ]);

            $response_data = json_decode($response->getBody(), true);
            error_log('Updated Contact: ' . print_r($response_data, true));
            return $response_data;
        } catch (Exception $e) {
            error_log('Kommo API Update Contact Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Create or update a contact in Kommo from a WooCommerce customer
     */
    public function create_or_update_kommo_contact_from_customer($customer_id) {
        try {
            $access_token = $this->get_access_token();
            if (!$access_token) {
                throw new Exception('No access token available.');
            }

            // Get the WooCommerce customer
            $customer = new WC_Customer($customer_id);
            if (!$customer) {
                throw new Exception('Customer not found.');
            }

            // Extract customer details
            $customer_email = $customer->get_email();

            // Check if a contact with the same email already exists
            $existing_contact = $this->get_contact_by_email($customer_email);
            if ($existing_contact) {
                // Update the existing contact
                return $this->update_kommo_contact_from_customer($customer_id, $existing_contact['id']);
            } else {
                // Create a new contact
                return $this->create_kommo_contact_from_customer($customer_id);
            }
        } catch (Exception $e) {
            error_log('Kommo API Create/Update Contact Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Create a new contact in Kommo from a WooCommerce customer
     */
    public function create_kommo_contact_from_customer($customer_id) {
        try {
            $access_token = $this->get_access_token();
            if (!$access_token) {
                throw new Exception('No access token available.');
            }

            // Get the WooCommerce customer
            $customer = new WC_Customer($customer_id);
            if (!$customer) {
                throw new Exception('Customer not found.');
            }

            // Extract customer details
            $customer_name = $customer->get_first_name() . ' ' . $customer->get_last_name();
            $customer_email = $customer->get_email();
            $customer_phone = $customer->get_billing_phone();
            $customer_address = $customer->get_billing_address_1();
            $customer_city = $customer->get_billing_city();
            $customer_country = $customer->get_billing_country();

            // Prepare the contact data
            $contact_data = [
                'name' => $customer_name,
                'custom_fields_values' => [
                    [
                        'field_id' => 1841202, // Phone field ID
                        'values' => [
                            [
                                'value' => $customer_phone                                
                            ],
                        ],
                    ],
                    [
                        'field_id' => 1841204, // Email field ID
                        'values' => [
                            [
                                'value' => $customer_email
                            ],
                        ],
                    ],
                    [
                        'field_id' => 2075892, // Country field ID
                        'values' => [
                            [
                                'value' => $customer_country,
                            ],
                        ],
                    ],
                    // Add more custom fields as needed
                ],
            ];

            // Send the request to create the contact
            $response = $this->client->request('POST', $this->base_url . '/api/v4/contacts', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'accept' => 'application/json',
                    'content-type' => 'application/json',
                ],
                'json' => [$contact_data], // Kommo API expects an array of contacts
            ]);

            $response_data = json_decode($response->getBody(), true);
            error_log('Created Contact: ' . print_r($response_data, true));
            return $response_data;
        } catch (Exception $e) {
            error_log('Kommo API Create Contact Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update an existing contact in Kommo from a WooCommerce customer
     */
    public function update_kommo_contact_from_customer($customer_id, $contact_id) {
        try {
            $access_token = $this->get_access_token();
            if (!$access_token) {
                throw new Exception('No access token available.');
            }

            // Get the WooCommerce customer
            $customer = new WC_Customer($customer_id);
            if (!$customer) {
                throw new Exception('Customer not found.');
            }

            // Extract customer details
            $customer_name = $customer->get_first_name() . ' ' . $customer->get_last_name();
            $customer_email = $customer->get_email();
            $customer_phone = $customer->get_billing_phone();
            $customer_address = $customer->get_billing_address_1();
            $customer_city = $customer->get_billing_city();
            $customer_country = $customer->get_billing_country();

            // Prepare the contact data
            $contact_data = [
                'id' => $contact_id, // Include the contact ID to update the existing contact
                'name' => $customer_name,
                'custom_fields_values' => [
                    [
                        'field_id' => 1841202, // Phone field ID
                        'values' => [
                            [
                                'value' => $customer_phone                                
                            ],
                        ],
                    ],
                    [
                        'field_id' => 1841204, // Email field ID
                        'values' => [
                            [
                                'value' => $customer_email
                            ],
                        ],
                    ],
                    [
                        'field_id' => 2075892, // Country field ID
                        'values' => [
                            [
                                'value' => $customer_country,
                            ],
                        ],
                    ],
                    // Add more custom fields as needed
                ],
            ];

            // Send the request to update the contact
            $response = $this->client->request('PATCH', $this->base_url . '/api/v4/contacts', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'accept' => 'application/json',
                    'content-type' => 'application/json',
                ],
                'json' => [$contact_data], // Kommo API expects an array of contacts
            ]);

            $response_data = json_decode($response->getBody(), true);
            error_log('Updated Contact: ' . print_r($response_data, true));
            return $response_data;
        } catch (Exception $e) {
            error_log('Kommo API Update Contact Error: ' . $e->getMessage());
            return false;
        }
    }
}