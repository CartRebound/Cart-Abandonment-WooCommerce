<?php

/*
Plugin Name: Cart Rebound for WooCommerce
Plugin URI: https://www.cartrebound.com
Description: Cart Rebound.
Version: 0.0.1
Author: Rhys W
Author URI: http://www.cartrebound.com

Copyright: © 2017 Cart Rebound
*/


// if an item has been added to cart, keep track of it with a session identifier.

add_action("plugins_loaded", "wooc_abandon_init", 0);
register_activation_hook(__FILE__, 'woocabandon_create_plugin_database_table');

add_filter( "amr_about_to_run_tests", "add_cartrebound_tests", 10, 1 );


function add_cartrebound_tests( $suite ) {
	$suite->addTestFile( plugin_dir_path( __FILE__ ) . "tests/cartrebound_tests.php" );

	return $suite;
}

function woocabandon_create_plugin_database_table()
{
    global $table_prefix, $wpdb;

    $tblname = 'woocabandon_carts';
    $wp_track_table = $table_prefix . "$tblname";

    #Check to see if the table exists already, if not, then create it

    if ($wpdb->get_var("show tables like '$wp_track_table'") != $wp_track_table) {

        $sql = "CREATE TABLE `" . $wp_track_table . "` ( ";
        $sql .= "  `cookie`  varchar(255)  NOT NULL,  `unique_key` varchar(255) not null, `cart_contents` text,created_at datetime default null,
	`finalised_at` DATETIME NULL DEFAULT NULL, synced_at datetime default null, `sync_attempted_at` DATETIME NULL DEFAULT NULL, modified_at datetime default null, sync_key varchar(255) default null, email varchar(255) not null,";
        $sql .= "  PRIMARY KEY `cookie_hash` (`cookie`) ";
        $sql .= ") ENGINE=MyISAM DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ; ";
        require_once(ABSPATH . '/wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}


function wooc_abandon_init(){
    class WC_Abandon{
        private static $_instance = null;

        public static $send_orders_older_than_minutes = 0;

        public static $settings = [
            'store_id'=>false,
            'secret_key'=>false
        ];


        public static function instance(){
            if (is_null(self::$_instance)) {
                self::$_instance = new self();
            }

            return self::$_instance;
        }

        public function load_settings(){
            self::$settings['store_id'] = get_option('WC_settings_cartrebound_site_id');
            self::$settings['secret_key'] = get_option('WC_settings_cartrebound_secret_key');
            self::$settings['log'] = get_option('WC_settings_cartrebound_logging_enabled');
            self::$settings['live'] = get_option('WC_settings_cartrebound_livemode_enabled');
            self::$settings['endpoint'] = self::$settings['live'] === 'yes' ? 'https://app.cartrebound.com' : 'http://app.cartrebound.app';
           // error_log("live mode is " . self::$settings['live']);
        }

        public function __construct(){

            add_filter('woocommerce_settings_tabs_array',array($this, 'add_settings_tab'), 50);
            add_action('woocommerce_settings_tabs_settings_cartrebound', array($this, 'settings_tab'));
            add_action('woocommerce_update_options_settings_cartrebound', array($this, 'update_settings'));

            add_action("woocommerce_add_to_cart", array($this, 'get_vital_info'));

            add_action('wp_ajax_capture_woocabandon_email', array($this, 'capture_woocabandon_email_callback'));
            add_action('wp_ajax_nopriv_capture_woocabandon_email', array($this, 'capture_woocabandon_email_callback'));

            add_action('woocommerce_thankyou', array($this, 'user_placed_order'), 10, 1);
            add_action("woocommerce_order_status_processing", array($this, 'user_placed_order_by_email'), 10, 1);

            add_action('wp_login', array($this, 'get_vital_info'));


            add_filter('query_vars', array($this, 'abandon_query_vars_filter'));


            add_action('pre_get_posts', array($this, 'abandon_url_handler'));


            add_action("woocommerce_init", array($this, 'ping_server'));

            $this->load_settings();

            $this->queue_assets();

        }


	    public function abandon_query_vars_filter( $vars ) {
		    $vars[] = "resume_cart_with_cookie";

		    return $vars;
	    }
        public function add_settings_tab($settings_tabs){
            $settings_tabs['settings_cartrebound'] = __('Cart Rebound', 'woocommerce-settings-tab-cartrebound');

            return $settings_tabs;
        }

        public function settings_tab(){
            woocommerce_admin_fields(self::get_settings());
        }

        public static function update_settings()
        {
            woocommerce_update_options(self::get_settings());
        }

        public static function get_settings()
        {

            $settings = array(
                'wc_cart_rebound_section_title' => array(
                    'name' => __('Settings', 'woocommerce-settings-tab-cartrebound'),
                    'type' => 'title',
                    'desc' => '',
                    'id' => 'WC_settings_cartrebound_section_title'
                ),
                'wc_cart_rebound_site_id' => array(
                    'name' => __('Enter your Site ID', 'woocommerce-settings-tab-cartrebound'),
                    'type' => 'text',
                    'desc' => __('This will be on your intro email.',
                        'woocommerce-settings-tab-cartrebound'),
                    'desc_tip' => true,
                    'id' => 'WC_settings_cartrebound_site_id'
                ),
                'wc_cart_rebound_secret_key' => array(
                    'name' => __('Enter your Secret Key', 'woocommerce-settings-tab-cartrebound'),
                    'type' => 'text',
                    'css' => 'min-width:350px;',
                    'desc' => __('This will be on your intro email.',
                        'woocommerce-settings-tab-cartrebound'),
                    'desc_tip' => true,
                    'id' => 'WC_settings_cartrebound_secret_key'
                ),
                'wc_cart_rebound_logging_enabled' => array(
                    'name' => __('Enable Logging?', 'woocommerce-settings-tab-cartrebound'),
                    'type' => 'checkbox',
                    'id' => 'WC_settings_cartrebound_logging_enabled'
                ),
                'wc_cart_rebound_livemode_enabled' => array(
                    'name' => __('Enable Live Mode? (YES if unsure)', 'woocommerce-settings-tab-cartrebound'),
                    'type' => 'checkbox',
                    'id' => 'WC_settings_cartrebound_livemode_enabled'
                ),
                'wc_cart_rebound_section_end' => array(
                    'type' => 'sectionend',
                    'id' => 'WC_settings_cartrebound_section_end'
                )
            );

            return apply_filters('WC_settings_cartrebound_settings', $settings);
        }

        public static function log($message)
        {
            if (empty(self::$log)) {
                self::$log = new WC_Logger();
            }

            if(get_option("WC_settings_cartrebound_livemode_enabled") === "yes"){
                self::$log->add('CartRebound', $message);
            }
            //
        }



        function abandon_url_handler($query)
        {
            if ($query->is_main_query()) {
                $cookie = get_query_var('resume_cart_with_cookie');
                if ($cookie) {

                    global $wpdb;



                    $results = $wpdb->get_results($wpdb->prepare("select * from {$wpdb->prefix}woocabandon_carts where cookie = %s", $cookie));

                    if(count($results) > 0){


                        global $woocommerce;

                        $woocommerce->cart->empty_cart();


                        $result = $results[0];

                        $contents = json_decode($result->cart_contents);

                        foreach($contents->items as $item){
                            $woocommerce->cart->add_to_cart($item->product_id, $item->quantity);
                        }
                    }

                }
            }
        }

        public function get_transient_key_for_cookie_element(){
            return "wa_cookie_" . WC()->session->get_session_cookie()[3];
        }

        public function capture_current_user_email(){
            $email = wp_get_current_user()->user_email;
        }

        public function capture_woocabandon_email_callback(){

            $email = $_POST['email'];
            $time = $_POST['time'];

            $payload = array('email'=>$email, 'time'=>$time);
            $transient_key = $this->get_transient_key_for_cookie_element();

            if ($old_payload = get_transient($transient_key)) {
                $old_time = $old_payload['time'];

                if($time > $old_time){
                    // more recent keystroke.
                    set_transient($transient_key, $payload);
                }
            }
            else{
                set_transient($transient_key, $payload);
            }

            $this->get_vital_info(null, $email);


            echo "ok";
            die();


        }

        public function get_authorization_header()
        {

            $token_id = self::$settings['store_id'];
            $secret_key = self::$settings['secret_key'];

            return 'Basic ' . base64_encode($token_id . ':' . $secret_key);
        }

        public function ping_server(){

            global $woocommerce;
            $session = WC()->session;

            if($session){
                $cookie = WC()->session->get_session_cookie()[3];

                $email = false;

                $email = wp_get_current_user()->user_email;

                if (!$email) {

                    $transient_key = $this->get_transient_key_for_cookie_element();

                    if ($payload = get_transient($transient_key)) {
                        $email = $payload['email'];
                    }
                }

                $body = compact('cookie', 'email');

                $args = array(
                    'headers' => array(
                        'Authorization' => $this->get_authorization_header(),
                        'Content-Type' => 'application/json'
                    ),
                    'body' => json_encode($body)
                );

                $response = wp_remote_post(self::$settings['endpoint'] . "/api/ping", $args);
            }

        }

        public function user_placed_order_by_email($order_id){
            $order = new WC_Order($order_id);

            $email = $order->get_billing_email();

            $this->get_vital_info("completed", $email, $order);
        }

        public function user_placed_order($order_id){

            $cookie = WC()->session->get_session_cookie()[3];
            $order = new WC_Order($order_id);

            $this->get_vital_info("completed", null, $order);
        }

	    /**
	     * @param null $status
	     * @param null $email
	     * @param WC_Order $order
	     */
        public function get_vital_info($status=null, $email = null, $order = null)
        {
           // 7cbbc409ec990f19c78c75bd1e06f215
            $cookie = WC()->session->get_session_cookie()[3];

            // at this stage, we need a cookie.
            if(!$cookie){
            	return;
            }

	        $contents['items'] = array();
	        $contents['meta'] = array();
            if($status != "completed"){
	            $cart_hash = md5( json_encode( WC()->cart->get_cart_for_session() ) );


	            $cart = WC()->cart->cart_contents;

	            $contents = $this->contentsFromCart( $cart );

	            WC()->cart->calculate_totals();
	            $contents['meta']['checkout_url'] = wc_get_checkout_url();
	            $contents['meta']['total']        = WC()->cart->subtotal;
            }
            else{
            	$contents = $this->contentsFromOrder($order);
	            $contents['meta']['total'] = $order->get_total();
            }


	        $contents['meta']['currency']        = get_woocommerce_currency();
	        $contents['meta']['currency_symbol'] = get_woocommerce_currency_symbol();

            if($status){
	            $contents['meta']['current_status'] = $status;
            }

			if(!$email){
				$email = wp_get_current_user()->user_email;

			}

			if(!$email && $order){
				$email =$order->get_billing_email();
			}

            if(!$email){

                $transient_key = $this->get_transient_key_for_cookie_element();

                if ($payload = get_transient($transient_key)) {
                $email = $payload['email'];
                }
            }
            error_log($cookie . " " . $cart_hash . " " . $email);


	            global $wpdb;

	            $sql = "insert into {$wpdb->prefix}woocabandon_carts (cookie, unique_key, cart_contents, created_at, modified_at, email, synced_at, finalised_at) VALUES (%s,%s, %s, %s, %s, %s, null, %s) ON DUPLICATE KEY UPDATE cart_contents = %s, modified_at = %s, synced_at=null";

	            $json_contents = json_encode( $contents );

	            $date = date( "Y-m-d H:i:s" );
	            $sql  = $wpdb->prepare( $sql, $cookie, $cookie . time(), $json_contents, $date, $date, $email, ( $order ? $date : null ), $json_contents, $date );
	            $wpdb->query( $sql );


	            if($wpdb->last_error){
	            	error_log($wpdb->last_error);
	            }


	        if ( $email ) {
		        $sql = "update {$wpdb->prefix}woocabandon_carts set email = %s where cookie = %s";

		        $sql = $wpdb->prepare( $sql, $email, $cookie );
		        $wpdb->query( $sql );
	        }


	            if($order){
	            	$sql = "update {$wpdb->prefix}woocabandon_carts set cookie = concat(cookie,'_completed".time()."') where cookie = %s";

	            	$sql = $wpdb->prepare($sql, $cookie);
	            	$wpdb->query($sql);
	            }


            /*
            $wpdb->update($wpdb->prefix . 'woocabandon_carts', array(
                'cookie'=>$cookie,
                'cart_contents'=>$contents),
            array('cookie'=>$cookie));*/

            // if there's 10 entries, or if an order was modified > 10 mins ago.
	        // force a sync if it's a completed order.
	        error_log("checking if should sync.");
	        if($this->should_sync() || $status == "completed"){
	        	error_log("entering sync routine");
		        $this->sync_to_server();
	        }



           // $response = wp_remote_post(self::$settings['endpoint'] . "/api/vitals?XDEBUG_SESSION_START=1", $args);
        }

        public function should_sync(){
        	global $wpdb;
        	//$sql = "insert into {$wpdb->prefix}woocabandon_carts (cookie, cart_contents, created_at, email, synced_at) VALUES (%s,%s, %s, %s, null) ON DUPLICATE KEY UPDATE cart_contents = %s, modified_at = %s";
			$sql = "select count(*) as count from {$wpdb->prefix}woocabandon_carts where synced_at = null;";

			$row = $wpdb->get_row($sql);

			if( (int)$row->count > 10){
				return true;
			}
			error_log("there's less than 10 rows.");

			$sql = "select modified_at as first_modified_not_synced from {$wpdb->prefix}woocabandon_carts where synced_at is null order by modified_at asc;";

			$row = $wpdb->get_row($sql);
			$minute = 60;

			$compare_lower = strtotime( $row->first_modified_not_synced );
			if(!$compare_lower){
				$compare_lower = 0;
			}

	        error_log( "fmns is " . $compare_lower);


	        error_log("time - delay is " . (time() - ( self::$send_orders_older_than_minutes * $minute )));

			if( $compare_lower < time() - (self::$send_orders_older_than_minutes * $minute)){
				return true;
			}

			error_log("forcing true.");
			return true;

			return false;
        }

        public function sync_to_server(){
        	global $wpdb;
        	$sync_key = wp_generate_password( 24 );
	        $date = date( "Y-m-d H:i:s" );
        	$wpdb->update( "{$wpdb->prefix}woocabandon_carts", ['sync_key'=> $sync_key, 'sync_attempted_at'=> $date], ['synced_at'=>null]);

        	$records = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}woocabandon_carts WHERE sync_key = %s",
		        $sync_key ) );


        	$body = compact( 'records' );

	        $args = array(
		        'headers' => array(
			        'Authorization' => $this->get_authorization_header(),
			        'Content-Type'  => 'application/json'
		        ),
		        'timeout' => 45,
		        'body'    => json_encode( $body )
	        );


	        $url = self::$settings['endpoint'] . "/api/store_sync?XDEBUG_SESSION_START=1";

	        error_log("calling" . $url );

        	$response = wp_remote_post($url, $args);
			error_log("response is");
			error_log(json_encode($response));


        	if($response && gettype($response) !== "WP_Error"){
        		$response_object = json_decode($response['body']);

        		if( $response_object->result === "success"){
			        $wpdb->update( "{$wpdb->prefix}woocabandon_carts", [ 'synced_at' => date( "Y-m-d H:i:s" )],
				        [ 'sync_key' => $sync_key ] );
		        }
		        else{

			        $wpdb->update( "{$wpdb->prefix}woocabandon_carts", [ 'sync_key' => null ],
				        [ 'sync_key' => $sync_key ] );
		        }
	        }

        }

        public function queue_assets()
        {


             wp_register_script("wooc-abandon", plugins_url('js/wooc-abandon.js', __FILE__),
                 array('jquery'));
             wp_enqueue_script("wooc-abandon");

        }


        private function contentsLineItem($product_id, $product_title, $quantity, $variation_id, $variation, $product_image, $product_url, $product_price, $line_total){
			return array(
				'product_id'        => $product_id,
				    'product_title' => $product_title,
				    'quantity'      => $quantity,
				    'variation_id'  => $variation_id,
				    'variation'     => $variation,
				    'product_image' => $product_image,
				    'product_url'   => $product_url,
				    'product_price' => $product_price,
				    'line_total'    => $line_total
			    );
        }

	    /**
	     * @param WC_Order $order
	     *
	     * @return array
	     */
        public function contentsFromOrder($order){
        	$contents = array();
        	$items = $order->get_items();

			foreach($items as $item){
				$product = $item->get_product();

				$image_ids = $product->get_gallery_image_ids();
				$image_url = false;

				if ( $image_ids && count( $image_ids ) > 0 ) {
					$image_url = wp_get_attachment_url( $image_ids[0] );
				}
				$line = $this->contentsLineItem(
					$item->get_product_id(),
					$product->get_name(),
					$item->get_quantity(),
					$item->get_variation_id(),
					null,
					$image_url,
					get_permalink($product->get_id()),
					$product->get_price(),
					$item->get_total() + $item->get_total_tax()

						);
				$contents['items'][] = $line;
			}

			return $contents;
        }
	    /**
	     * @param $cart
	     *
	     * @return array;
	     */
	    public function contentsFromCart( $cart ) {

	    	$contents = array();
		    foreach ( $cart as $ck => $cc ) {
			    if(version_compare(WC()->version, 3.0, ">=")){
				    $image_ids = $cc['data']->get_gallery_attachment_ids();
			    }
			    else{
				    $image_ids = [$cc['data']->get_image_id()];
			    }


			    $image_url = false;

			    if ( $image_ids && count( $image_ids ) > 0 ) {
				    $image_url = wp_get_attachment_url( $image_ids[0] );
			    }

			    $name = false;
			    if ( version_compare( WC()->version, 3.0, ">=" ) ) {
				    $name = $cc['data']->get_name();
			    } else {
				    $name = $cc['data']->get_title();
			    }


			    $contents['items'][] = $this->contentsLineItem(
				    $cc['product_id'],
				    $name,
				    $cc['quantity'],
				    $cc['variation_id'],
				    $cc['variation'],
				    $image_url,
				    get_permalink( $cc['product_id'] ),
				    $cc['data']->get_price(),
				    $cc['line_total'] + $cc['line_tax']
			    );


		    }

		    return $contents;
	    }

    }

    $wc_abandon = new WC_Abandon();
}