<?php

/*
Plugin Name: Wooc Abandon
Plugin URI: https://www.abcdef.com.au/
Description: Wooc Abandon.
Version: 0.0.1
Author: Rhys W
Author URI: https://www.abcdef.com.au/

Copyright: © 2017 Rhys W
*/


// if an item has been added to cart, keep track of it with a session identifier.

add_action("plugins_loaded", "wooc_abandon_init", 0);
register_activation_hook(__FILE__, 'woocabandon_create_plugin_database_table');

function woocabandon_create_plugin_database_table()
{
    global $table_prefix, $wpdb;

    $tblname = 'woocabandon_carts';
    $wp_track_table = $table_prefix . "$tblname";

    #Check to see if the table exists already, if not, then create it

    if ($wpdb->get_var("show tables like '$wp_track_table'") != $wp_track_table) {

        $sql = "CREATE TABLE `" . $wp_track_table . "` ( ";
        $sql .= "  `cookie`  varchar(255)  NOT NULL,  `cart_contents` text, ";
        $sql .= "  PRIMARY KEY `cookie_hash` (`cookie`) ";
        $sql .= ") ENGINE=MyISAM DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ; ";
        require_once(ABSPATH . '/wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}


function wooc_abandon_init(){
    class WC_Abandon{
        private static $_instance = null;

        protected static $settings = [
            'store_id'=>1,
            'secret_key'=>'fr6JoXJcDmMLcJqqGV5zido0FUXLfmyRDnDNx3Vw'
        ];

        protected static $endpoint = "http://www.cr-server.dev";

        public static function instance(){
            if (is_null(self::$_instance)) {
                self::$_instance = new self();
            }

            return self::$_instance;
        }

        public function __construct(){

            add_action("woocommerce_add_to_cart", array($this, 'get_vital_info'));

            add_action('wp_ajax_capture_woocabandon_email', array($this, 'capture_woocabandon_email_callback'));
            add_action('wp_ajax_nopriv_capture_woocabandon_email', array($this, 'capture_woocabandon_email_callback'));

            add_action('woocommerce_thankyou', array($this, 'user_placed_order'), 10, 1);
            add_action("woocommerce_order_status_processing", array($this, 'user_placed_order_by_email'), 10, 1);

            add_action('wp_login', array($this, 'get_vital_info'));

            function add_query_vars_filter($vars)
            {
                $vars[] = "resume_cart_with_cookie";

                return $vars;
            }

            add_filter('query_vars', 'add_query_vars_filter');


            add_action('pre_get_posts', array($this, 'abandon_url_handler'));


            add_action("woocommerce_init", array($this, 'ping_server'));
            $this->ping_server();


            $this->queue_assets();

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

            $this->get_vital_info();


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

                $response = wp_remote_post(self::$endpoint . "/api/ping", $args);
            }

        }

        public function user_placed_order_by_email($order_id){
            $order = new WC_Order($order_id);

            $email = $order->get_billing_email();

            $status = "converted";


            $body = compact("status", "email");


            $args = array(
                'headers' => array(
                    'Authorization' => $this->get_authorization_header(),
                    'Content-Type' => 'application/json'
                ),
                'body' => json_encode($body)
            );

            $response = wp_remote_post(self::$endpoint . "/api/vitals", $args);
        }

        public function user_placed_order(){

            $cookie = WC()->session->get_session_cookie()[3];
            $status = "converted";

            $email = false;

            $email = wp_get_current_user()->user_email;

            if (!$email) {

                $transient_key = $this->get_transient_key_for_cookie_element();

                if ($payload = get_transient($transient_key)) {
                    $email = $payload['email'];
                }
            }


            $body = compact("cookie", "status", "email");


            $args = array(
                'headers' => array(
                    'Authorization' => $this->get_authorization_header(),
                    'Content-Type' => 'application/json'
                ),
                'body' => json_encode($body)
            );

            $response = wp_remote_post(self::$endpoint . "/api/vitals", $args);
        }

        public function get_vital_info($hash)
        {
           // 7cbbc409ec990f19c78c75bd1e06f215
            $cookie = WC()->session->get_session_cookie()[3];

            $cart_hash = md5(json_encode(WC()->cart->get_cart_for_session()));

            $contents = array();

            $contents['items'] = array();
            foreach(WC()->cart->cart_contents as $ck=>$cc){

                $image_ids = $cc['data']->get_gallery_attachment_ids();

                $image_url = false;

                if($image_ids && count($image_ids) > 0){
                    $image_url = wp_get_attachment_url($image_ids[0]);
                }
                $contents['items'][] = array(
                    'product_id'=>$cc['product_id'],
                    'product_title'=> method_exists($cc['data'], "get_name") ? $cc['data']->get_name() : $cc['data']->name,
                    'quantity'=>$cc['quantity'],
                    'variation_id'=>$cc['variation_id'],
                    'variation'=>$cc['variation'],
                    'product_image'=> $image_url,
                    'product_url'=>get_permalink($cc['product_id']),
                    'product_price'=> $cc['data']->get_price(),
                    'line_total'=> $cc['line_total'] + $cc['line_tax']
                    );
            }

            $contents['meta'] = array();
            $contents['meta']['checkout_url'] = wc_get_checkout_url();

            $email = false;

            $email = wp_get_current_user()->user_email;

            if(!$email){

                $transient_key = $this->get_transient_key_for_cookie_element();

                if ($payload = get_transient($transient_key)) {
                $email = $payload['email'];
                }
            }
            error_log($cookie . " " . $cart_hash . " " . $email);

            $body = compact('cookie', 'cart_hash', 'email', 'contents');

            $args = array(
                'headers' => array(
                    'Authorization' => $this->get_authorization_header(),
                    'Content-Type' => 'application/json'
                ),
                'body' => json_encode($body)
            );

            global $wpdb;

            $sql = "insert into {$wpdb->prefix}woocabandon_carts (cookie, cart_contents) VALUES (%s,%s) ON DUPLICATE KEY UPDATE cart_contents = %s";

            $json_contents = json_encode($contents);

            $sql = $wpdb->prepare($sql, $cookie, $json_contents, $json_contents);
            $wpdb->query($sql);

            /*
            $wpdb->update($wpdb->prefix . 'woocabandon_carts', array(
                'cookie'=>$cookie,
                'cart_contents'=>$contents),
            array('cookie'=>$cookie));*/



            $response = wp_remote_post(self::$endpoint . "/api/vitals?XDEBUG_SESSION_START=1", $args);
        }


        public function queue_assets()
        {


             wp_register_script("wooc-abandon", plugins_url('js/wooc-abandon.js', __FILE__),
                 array('jquery'));
             wp_enqueue_script("wooc-abandon");

        }

    }

    $wc_abandon = new WC_Abandon();
}