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
        $this->client = get_option('woo_kommo_client');
        $this->client_id = get_option('woo_kommo_client_id');
        $this->client_secret = get_option('woo_kommo_client_secret');
        $this->redirect_uri = get_option('woo_kommo_redirect_uri');

        $this->base_url = "https://{$this->subdomain}.kommo.com";
        $this->client = new \GuzzleHttp\Client();

        add_action('admin_init', [$this, 'get_access_token']);
        // add_action('init', [$this, 'retrieve_all_fields']);
        // add_action('init', [$this, 'retrieve_lead_fields']);        
        add_action('woocommerce_created_customer', [$this, 'handle_new_customer']);
        add_action('woocommerce_save_account_details', [$this, 'handle_customer_update']);
        add_action('woocommerce_checkout_order_processed', [$this, 'handle_new_order'], 10, 3);
        add_action('woocommerce_order_status_changed', [$this, 'update_lead_on_order_status_change'], 10, 3);
    }

    /**
     * Get access token
     */
    public function get_access_token() {
        
        $stored_token = get_option('woo_kommo_access_token');
        $token_expires = get_option('woo_kommo_token_expires');
       
        // Return stored token if it's still valid
        if ($stored_token && !$this->is_token_expired($token_expires)) {
            error_log('Kommo API Access Token: ' . $stored_token);
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
        try {
            // Create/update contact and get response
            $contact_response = $this->create_kommo_contact_from_order($order_id);
            
            if ($contact_response && isset($contact_response['_embedded']['contacts'][0]['id'])) {
                $contact_id = $contact_response['_embedded']['contacts'][0]['id'];
                $this->create_lead_from_order($order_id, $contact_id);
            }
        } catch (Exception $e) {
            error_log('Kommo Integration Error: ' . $e->getMessage());
        }
    }

    /**
     * Handle new customer account creation
     */
    public function handle_new_customer($customer_id) {
        try {
            // Create or update a contact in Kommo from the customer data
           create_or_update_kommo_contact_from_customer($customer_id);
        } catch (Exception $e) {
            error_log('Kommo Integration Error: ' . $e->getMessage());
        }
    }

    /**
     * Handle customer account update
     */
    public function handle_customer_update($customer_id) {
        try {
            create_or_update_kommo_contact_from_customer($customer_id);
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
     * Create or update a contact in Kommo from a WooCommerce order with affiliate/referral information
     */
    private function create_kommo_contact_from_order($order_id) {
        error_log('Creating Kommo contact from order ID: ' . $order_id);
        global $order, $post;
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
            $customer_id = $order->get_customer_id();

            // Get affiliate information
            $affiliate_data = $this->get_affiliate_info_for_kommo($order_id);

            // Check if a contact with the same email already exists
            $existing_contact = $this->get_contact_by_email($customer_email);
            
            if ($existing_contact) {
                // Update the existing contact with affiliate info
                return $this->update_kommo_contact_from_order($order_id, $existing_contact['id'], $affiliate_data);
            } else {
                // Create a new contact with affiliate info
                return $this->create_new_kommo_contact_from_order($order_id, $affiliate_data);
            }
        } catch (Exception $e) {
            error_log('Kommo API Create/Update Contact Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get affiliate information for KOMMO integration
     */
    private function get_affiliate_info_for_kommo($order_id) {
        global $wpdb;
    
        $affiliate_data = [
            'ib_code' => '0',       // Customer's affiliate ID (referral_id from wp_affiliate_wp_referrals, 0 if not found)
            'affiliate_of' => '0'   // Payment email of the referrer (from wp_affiliate_wp_affiliates, 0 if not found)
        ];
    
        // Validate inputs
        if (empty($order_id) || !is_numeric($order_id)) {
            return $affiliate_data;
        }
    
        // Query wp_affiliate_wp_referrals to get referral_id and affiliate_id
        $referrals_table = $wpdb->prefix . 'affiliate_wp_referrals';
        $query = $wpdb->prepare(
            "SELECT referral_id, affiliate_id 
             FROM $referrals_table 
             WHERE reference = %s 
             LIMIT 1",
            $order_id
        );
    
        $referral_data = $wpdb->get_row($query);
    
        // If no referral data found, return default affiliate data with 0s
        if (!$referral_data) {
            return $affiliate_data;
        }
    
        // Set ib_code to referral_id if found
        if (!empty($referral_data->referral_id)) {
            $affiliate_data['ib_code'] = $referral_data->referral_id;
            error_log('IB CODE:'. $affiliate_data['ib_code']);
        }        
    
        // Get affiliate_id for the referrer
        $affiliate_id = $referral_data->affiliate_id;
        error_log('Affiliate ID: ' . $affiliate_id);
        
        if ($affiliate_id && is_numeric($affiliate_id)) {
            // Query wp_affiliate_wp_affiliates to get payment_email
            $affiliates_table = $wpdb->prefix . 'affiliate_wp_affiliates';
            $affiliate_query = $wpdb->prepare(
                "SELECT payment_email 
                 FROM $affiliates_table 
                 WHERE affiliate_id = %d 
                 LIMIT 1",
                $affiliate_id
            );
    
            $payment_email = $wpdb->get_var($affiliate_query);

            error_log('Payment Email: ' . $payment_email);
    
            // Set affiliate_of to payment_email if found, otherwise keep as 0
            if (!empty($payment_email)) {
                $affiliate_data['affiliate_of'] = $payment_email;
            }
        }
    
        return $affiliate_data;
    }

    /**
     * Create a new contact in Kommo from a WooCommerce order
     */
    private function create_new_kommo_contact_from_order($order_id, $affiliate_data = []) {
        error_log('Creating new Kommo contact from order ID: ' . $order_id);
        error_log('Affiliate Data: ' . print_r($affiliate_data, true));
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
                        'field_id' => 2098967, // City field ID
                        'values' => [
                            [
                                'value' => $customer_city,
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


            // Add affiliate fields if data exists
            if (!empty($affiliate_data['ib_code'])) {
                $contact_data['custom_fields_values'][] = [
                    'field_id' => 2083336, // Replace with actual field ID
                    'values' => [['value' => $affiliate_data['ib_code']]]
                ];
            }

            if (!empty($affiliate_data['affiliate_of'])) {
                $contact_data['custom_fields_values'][] = [
                    'field_id' => 2102113, // Replace with actual field ID
                    'values' => [['value' => $affiliate_data['affiliate_of']]]
                ];
            }

            // Log the data being sent
            error_log('Contact Data Being Sent: ' . print_r($contact_data, true));

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
            
            error_log('Created Contact:: ' . print_r($response_data, true));
            return $response_data;
        } catch (Exception $e) {
            error_log('Kommo API Create Contact Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update an existing contact in Kommo from a WooCommerce order with affiliate info
     */
    private function update_kommo_contact_from_order($order_id, $contact_id, $affiliate_data = []) {
        error_log('Updating Kommo contact from order ID: ' . $order_id);
        error_log('Contact ID: ' . $contact_id);
        error_log('Affiliate Data: ' . print_r($affiliate_data, true));

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
                        'field_id' => 2098967, // City field ID
                        'values' => [
                            [
                                'value' => $customer_city,
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
                    [
                        'field_id' => 2083336, // IB CODE ID
                        'values' => [
                            [
                                'value' => $affiliate_data['ib_code'], // Use the order creation date
                            ],
                        ],
                    ],
                    [
                        'field_id' => 2102113, // AFFILIATE OF ID
                        'values' => [
                            [
                                'value' => $affiliate_data['affiliate_of'], // Use the order creation date
                            ],
                        ],
                    ],
                    // Add more custom fields as needed
                ],
            ];          

            // Log the data being sent
            error_log('Contact Data Being Sent: ' . print_r($contact_data, true));

            // Send the request to update the contact
            $response = $this->client->request('PATCH', $this->base_url . '/api/v4/contacts', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'accept' => 'application/json',
                    'content-type' => 'application/json',
                ],
                'json' => [$contact_data],
            ]);

            $response_data = json_decode($response->getBody(), true);
            error_log('Updated Contact from Order: ' . print_r($response_data, true));
            return $response_data;
        } catch (Exception $e) {
            error_log('Kommo API Update Contact from Order Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Create or update a contact in Kommo from a WooCommerce customer with affiliate info
     */
    public function create_or_update_kommo_contact_from_customer($customer_id) {
        error_log('Creating or updating Kommo contact from customer ID: ' . $customer_id);
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

            // Get affiliate information
            $affiliate_data = $this->get_affiliate_info_for_kommo($order_id);

            // Check if a contact with the same email already exists
            $existing_contact = $this->get_contact_by_email($customer_email);
            if ($existing_contact) {
                // Update the existing contact with affiliate info
                return $this->update_kommo_contact_from_customer($customer_id, $existing_contact['id'], $affiliate_data);
            } else {
                // Create a new contact with affiliate info
                return $this->create_kommo_contact_from_customer($customer_id, $affiliate_data);
            }
        } catch (Exception $e) {
            error_log('Kommo API Create/Update Contact from Customer Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Create a new contact in Kommo from a WooCommerce customer with affiliate info
     */
    public function create_kommo_contact_from_customer($customer_id, $affiliate_data = []) {
        error_log('Creating contact from customer ID: ' . $customer_id);
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
                        'values' => [['value' => $customer_phone]],
                    ],
                    [
                        'field_id' => 1841204, // Email field ID
                        'values' => [['value' => $customer_email]],
                    ],
                    [
                        'field_id' => 2075892, // Country field ID
                        'values' => [['value' => $customer_country]],
                    ],
                    [
                        'field_id' => 2098967, // City field ID
                        'values' => [['value' => $customer_city]],
                    ],
                ],
            ];

            // Add affiliate fields if data exists
            if (!empty($affiliate_data['ib_code'])) {
                $contact_data['custom_fields_values'][] = [
                    'field_id' => 2083336,
                    'values' => [['value' => $affiliate_data['ib_code']]]
                ];
            }

            if (!empty($affiliate_data['affiliate_of'])) {
                $contact_data['custom_fields_values'][] = [
                    'field_id' => 2102113,
                    'values' => [['value' => $affiliate_data['affiliate_of']]]
                ];
            }

            // Send the request to create the contact
            $response = $this->client->request('POST', $this->base_url . '/api/v4/contacts', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'accept' => 'application/json',
                    'content-type' => 'application/json',
                ],
                'json' => [$contact_data],
            ]);

            // Log the data being sent
            error_log('Contact Data Being Sent: ' . print_r($contact_data, true));

            $response_data = json_decode($response->getBody(), true);
            error_log('Created Contact: ' . print_r($response_data, true));
            return $response_data;
        } catch (Exception $e) {
            error_log('Kommo API Create Contact Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update an existing contact in Kommo from a WooCommerce customer with affiliate info
     */
    public function update_kommo_contact_from_customer($customer_id, $contact_id, $affiliate_data = []) {
        error_log('Updating contact from customer ID: ' . $customer_id);
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
                'id' => $contact_id,
                'name' => $customer_name,
                'custom_fields_values' => [
                    [
                        'field_id' => 1841202, // Phone field ID
                        'values' => [['value' => $customer_phone]],
                    ],
                    [
                        'field_id' => 1841204, // Email field ID
                        'values' => [['value' => $customer_email]],
                    ],
                    [
                        'field_id' => 2075892, // Country field ID
                        'values' => [['value' => $customer_country]],
                    ],
                    [
                        'field_id' => 2098967, // City field ID
                        'values' => [['value' => $customer_city]],
                    ],
                ],
            ];

            // Add affiliate fields if data exists
            if (!empty($affiliate_data['ib_code'])) {
                $contact_data['custom_fields_values'][] = [
                    'field_id' => 2083336,
                    'values' => [['value' => $affiliate_data['ib_code']]]
                ];
            }

            if (!empty($affiliate_data['affiliate_of'])) {
                $contact_data['custom_fields_values'][] = [
                    'field_id' => 2102113,
                    'values' => [['value' => $affiliate_data['affiliate_of']]]
                ];
            }

            // Log the data being sent
            error_log('Contact Data Being Sent: ' . print_r($contact_data, true));

            // Send the request to update the contact
            $response = $this->client->request('PATCH', $this->base_url . '/api/v4/contacts', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'accept' => 'application/json',
                    'content-type' => 'application/json',
                ],
                'json' => [$contact_data],
            ]);

            $response_data = json_decode($response->getBody(), true);
            error_log('Updated Contact: ' . print_r($response_data, true));
            return $response_data;
        } catch (Exception $e) {
            error_log('Kommo API Update Contact Error: ' . $e->getMessage());
            return false;
        }
    }

    // LEADS SECTION

    /**
     * Retrieve all lead fields from Kommo API
     */
    public function retrieve_lead_fields() {
        try {
            $access_token = $this->get_access_token();
            if (!$access_token) {
                error_log('No Access Token!');
            }

            $response = $this->client->request('GET', $this->base_url . '/api/v4/leads/custom_fields', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'accept' => 'application/json',
                ],
            ]);

            $fields = json_decode($response->getBody(), true);
            error_log('Retrieved Lead Fields: ' . print_r($fields, true));
            return $fields;
        } catch (Exception $e) {
            error_log('Kommo API Retrieve Lead Fields Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Retrieve all leads from Kommo API
     */
    public function retrieve_all_leads() {
        try {
            $access_token = $this->get_access_token();
            if (!$access_token) {
                error_log('No Access Token!');
            }

            $response = $this->client->request('GET', $this->base_url . '/api/v4/leads', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'accept' => 'application/json',
                ],
            ]);

            $leads = json_decode($response->getBody(), true);
            error_log('Retrieved Leads: ' . print_r($leads, true));
            return $leads;
        } catch (Exception $e) {
            error_log('Kommo API Retrieve Leads Error: ' . $e->getMessage());
            return [];
        }
    }


    /**
     * Create a lead from WooCommerce order
     */
    public function create_lead_from_order($order_id, $contact_id) {
        try {
            // First check if we already created a lead for this order
            $existing_lead_id = get_post_meta($order_id, 'woo_kommo_lead_id', true);
            if ($existing_lead_id) {
                error_log("Lead already exists for order {$order_id} with Kommo ID {$existing_lead_id}");
                return false;
            }
        
            $access_token = $this->get_access_token();
            if (!$access_token) {
                throw new Exception('No access token available.');
            }
    
            $order = wc_get_order($order_id);
            if (!$order) {
                throw new Exception('Order not found.');
            }
    
            // Get order data           
            $order_timestamp = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time();

            // Initialize variables to store variations
            $step = '';
            $account_size = '';
            $trading_platform = '';

            // Get the first (and only) item in the order
            $items = $order->get_items();
            if (!empty($items)) {
                $item = reset($items); // Get the first item
                $product = $item->get_product();

                // Get the variations (attributes) for the product
                $variations = $product->get_attributes();

                // Check if the product has variations
                if (!empty($variations)) {
                    // Convert variations to an array
                    $variation_array = array_values($variations);

                    // Map variations to their respective fields
                    if (!empty($variation_array[0])) {
                        $step = $variation_array[0]; // First variation → Steps
                    }
                    if (!empty($variation_array[1])) {
                        $account_size = $variation_array[1]; // Second variation → Account size
                    }
                    if (!empty($variation_array[2])) {
                        $trading_platform = $variation_array[2]; // Third variation → Trading platform
                    }
                }
            }

            $product_names = [];
            foreach ($order->get_items() as $item) {
                $product_names[] = $item->get_name();
            }
            $payment_method = $order->get_payment_method_title(); // Payment gateway title
            $coupons = $order->get_coupon_codes();
            $total = $order->get_total();
            $discount = $order->get_total_discount();
            $order_status = $order->get_status();
    
            // Prepare the lead data with all available fields
            $lead_data = [
                'name' => 'Order #' . $order_id,
                'pipeline_id' => 9564319,
                '_embedded' => [
                    'contacts' => [
                        ['id' => $contact_id]
                    ]
                ],
                'custom_fields_values' => [
                    // Date of purchase (1973834) - date field
                    [
                        'field_id' => 1973834,
                        'values' => [['value' => $order_timestamp]]
                    ],
                    // NEW Date of purchase (2099105) - date_time field
                    [
                        'field_id' => 2099105,
                        'values' => [['value' => $order_timestamp]]
                    ],
                    // NEW TOTAL AMOUNT (2098971) - monetary field
                    [
                        'field_id' => 2098971,
                        'values' => [['value' => $total]]
                    ],         
                    // Payment method (use Challenge field 2085492)
                    [
                        'field_id' => 2085492,
                        'values' => [['value' => implode(", ", $product_names)]]
                    ],
                    // Coupons (2086584)
                    [
                        'field_id' => 2086584,
                        'values' => [['value' => implode(", ", $coupons)]]
                    ],
                    // Total amount (2083352)
                    [
                        'field_id' => 2083352,
                        'values' => [['value' => $total]]
                    ],
                    // Discount (2083350)
                    [
                        'field_id' => 2083350,
                        'values' => [['value' => $discount]]
                    ],
                    // Order status (2099073)
                    [
                        'field_id' => 2099073,
                        'values' => [['value' => $order_status]]
                    ],
                    // Description (2086632) - Include payment gateway
                    [
                        'field_id' => 2086632,
                        'values' => [['value' => $payment_method]]
                    ],
                    // Steps (2099107) - First variation
                    [
                        'field_id' => 2099107,
                        'values' => [['value' => $step]]
                    ],
                    // Account size (2099109) - Second variation
                    [
                        'field_id' => 2099109,
                        'values' => [['value' => $account_size]]
                    ],
                    // Trading platform (2099111) - Third variation
                    [
                        'field_id' => 2099111,
                        'values' => [['value' => $trading_platform]]
                    ],
                ]
            ];
    
            // Log the data being sent
            error_log('Lead Data Being Sent: ' . print_r($lead_data, true));
    
            $response = $this->client->request('POST', $this->base_url . '/api/v4/leads', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'accept' => 'application/json',
                    'content-type' => 'application/json',
                ],
                'json' => [$lead_data]
            ]);
    
            $response_data = json_decode($response->getBody(), true);
            if (!empty($response_data['_embedded']['leads'][0]['id'])) {
                $lead_id = $response_data['_embedded']['leads'][0]['id'];
                update_post_meta($order_id, 'woo_kommo_lead_id', $lead_id);
            }
    
            error_log('Created Lead: ' . print_r($response_data, true));
            return $response_data;
        } catch (Exception $e) {
            error_log('Kommo API Create Lead Error: ' . print_r($e->getMessage(), true));
            return false;
        }
    }


    /**
     * Update lead when order status changes
     */
    public function update_lead_on_order_status_change($order_id, $old_status, $new_status) {
        try {
            // Get the lead ID from post meta
            $lead_id = get_post_meta($order_id, 'woo_kommo_lead_id', true);
    
            // Log the lead ID for debugging
            error_log('Lead ID being used: ' . print_r($lead_id, true));
    
            // Check if the lead ID is valid
            if (!$lead_id) {
                throw new Exception('No lead ID found for order #' . $order_id);
            }
    
            // Cast the lead ID to an integer
            $lead_id = (int) $lead_id;
    
            // Get the access token
            $access_token = $this->get_access_token();
            if (!$access_token) {
                throw new Exception('No access token available.');
            }
    
            // Prepare the lead data
            $lead_data = [
                'id' => $lead_id, // Ensure this is a valid integer
                'custom_fields_values' => [
                    [
                        'field_id' => 2099073, // WC Status field
                        'values' => [['value' => $new_status]]
                    ]
                ]
            ];
    
            // Log the data being sent
            error_log('Lead Data Being Sent: ' . print_r($lead_data, true));
    
            // Send the PATCH request
            $response = $this->client->request('PATCH', $this->base_url . '/api/v4/leads', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'accept' => 'application/json',
                    'content-type' => 'application/json',
                ],
                'json' => [$lead_data]
            ]);
    
            // Log the response
            $response_data = json_decode($response->getBody(), true);
            error_log('Updated Lead Response: ' . print_r($response_data, true));
    
            return $response_data;
        } catch (Exception $e) {
            // Log the full error message
            error_log('Kommo API Update Lead Status Error: ' . print_r($e->getMessage(), true));
    
            // Optionally log to a file
            file_put_contents(__DIR__ . '/kommo_error.log', 'Kommo API Update Lead Status Error: ' . print_r($e, true) . PHP_EOL, FILE_APPEND);
    
            return false;
        }
    }
}