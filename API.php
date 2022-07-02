<?php

class ESI_API {

	public $esi_name = '';
	public $api_base_url = false;
	public $api_version = false;
	public $default_headers = array('Content-Type' => 'application/json; charset=utf-8');
	public $default_body = null;

	public $ChannelAdvisor = null;

	public function __construct() {
		// order actions
		add_action('wp_ajax_esi_download_orders', array($this, 'load_orders'));
		add_action('wp_ajax_nopriv_esi_download_orders', array($this, 'load_orders'));

		// add_filter('update_post_metadata', array($this, 'add_tracking_number'), 10, 5);
		// add_action('woocommerce_refund_created', array($this, 'handle_order_refunded'), 10, 2);
		// product actions
		// add_filter('acf/update_value', array($this, 'update_marketplace'), 10, 3);
		// add_action('save_post_product', array($this, 'update_product'), 10, 3);
		// add_action('woocommerce_variation_set_stock', array($this, 'update_product_stock'));
		add_action('wp_ajax_esi_upload_file', array($this, 'upload_file_sftp'));
		add_action('wp_ajax_nopriv_esi_upload_file', array($this, 'upload_file_sftp'));
		add_action('wp_ajax_esi_download_file', array($this, 'download_file_csv'));
		add_action('wp_ajax_nopriv_esi_download_file', array($this, 'download_file_csv'));
		add_action('wp_ajax_esi_sync_products', array($this, 'sync_products'));
		add_action('wp_ajax_nopriv_esi_sync_products', array($this, 'sync_products'));

		// add_action('http_api_debug', array($this, 'api_debug_call'), 10, 5);

		add_action('wp_ajax_anatwine_xml', array($this, 'handle_anatwine_xml'));
		add_action('wp_ajax_nopriv_anatwine_xml', array($this, 'handle_anatwine_xml'));

		//TODO remove bellow actions
		add_action('wp_ajax_anatwine_trigger', array($this, 'trigger_actions'));
		add_action('wp_ajax_nopriv_anatwine_trigger', array($this, 'trigger_actions'));
	}

	function api_debug_call($response, $bar = false, $foo = false, $request, $url){
	    echo "<pre>";
	    echo "Url:".print_r($url,true)."\n";
	    echo "</pre>";

	    echo "<pre>";
	    echo "Request:".print_r($request,true)."\n";
	    echo "</pre>";

		echo "<pre>";
	    echo "Response:".print_r($response,true)."\n";
	    echo "</pre>";
	    die;
	}

	/**
	 * Send an HTTP request to a URI
	 *
	 * @param  string $url 		The full request URL including http://
	 * @param  array $args
	 * @return array|WP_Error 	Array containing 'headers', 'body', 'response', 'cookies', 'filename'.
     *                        	A WP_Error instance upon error.
	 */
	function api_call($url = false, $args = null){
		if(false === $url)
			return false;

		// merge values with defaults
		$headers = wp_parse_args($args['headers'], $this->default_headers);
		$body 	 = $args['body'];
		$method	 = $args['method'];

		// only add the base url if the passed url is a partial link
		if(strpos($url, 'http') === false){
			$url = $this->api_base_url . $url;
		}

		if(empty($body)){
			$headers['content-length'] = 0;
		}

		$args = array(
			'headers' 	=> $headers,
			'body' 		=> $body,
			'method'    => $method
		);

		// change the call based on the request method
		switch (strtolower($args['method'])) {
			case 'post':
				$response = wp_remote_post( $url, $args );
				break;
			case 'put':
			case 'delete':
				$response = wp_remote_request($url, $args);
				break;
			case 'get':
			default:
				$response = wp_remote_get( $url, $args );
				break;
		}

		// mail('raulxanda@mailinator.com', 'api_call $args', print_r($args, true));
		// mail('raulxanda@mailinator.com', 'api_call $response', print_r($response, true));

		if ( is_wp_error( $response ) ) {
			return ['success' => false, 'message' => $response->get_error_message()];
		}

		$response_code = wp_remote_retrieve_response_code( $response );

		$header = $response['headers'];
		$body = $response['body'];

		// check the response contained a success response code
		$success = false;
		if(in_array($response_code, array(200, 204))){
			$success = true;
		}

		$error_message = '';
		if(false === $success){
			$error_message = $this->catch_api_errors($header, $body, $response_code);
		}

		return array('success' => $success, 'header' => $header, 'body' => $body, 'response_code' => $response_code, 'error_message' => $error_message);
	}

	/**
	 * Create a woocommerce order
	 *
	 * @param  array $args
	 * @return WC_Order|WP_Error
	 */
	function create_order($order = false){
		global $woocommerce;

		// check if the order has already been downloaded
		if(isset($order['source']) && !empty($order['source']['marketplace_order_id'])){
			$args = array(
				'post_type' => 'shop_order',
				'meta_query' => array(
					array(
						'key'     => '_esi_marketplace_order_id',
						'value'   => $order['source']['marketplace_order_id'],
					)
				),
			);
			$existing_order = get_posts( $args );
			if(!empty($existing_order)){
				$subject = sprintf('Ariella CA Download Error: Order Exists - %s', $order['source']['marketplace_order_id']);
				$body = sprintf('<pre>%s</pre>', $order['json']);
				$headers = array('Content-Type: text/html; charset=UTF-8');
				wp_mail('support@xanda.net', $subject, $body, $headers);

				return false;
			}
		}

		$wc_order = wc_create_order($order['order']);
		if(!is_wp_error($order)){


			// save third-party's order refs
			if(isset($order['source'])){
				if(!empty($order['source']['name'])){
					$source_name = $order['source']['name'];
					add_post_meta($wc_order->id, '_esi_source_name', $source_name);
				}
				if(!empty($order['source']['id'])){
					$source_id = $order['source']['id'];
					add_post_meta($wc_order->id, '_esi_source_id', $source_id);
				}

				if(!empty($order['source']['store_id'])){
					add_post_meta($wc_order->id, '_esi_store_id', $order['source']['store_id']);
				}

				if(!empty($order['source']['marketplace_order_id'])){
					add_post_meta($wc_order->id, '_esi_marketplace_order_id', $order['source']['marketplace_order_id']);
				}
			}

			// save the raw response in case we need it later
			if(isset($order['json'])){
				add_post_meta($wc_order->id, '_esi_json_order', $order['json']);
			}

			if(!empty($order['items'])){
				foreach ($order['items'] as $item) {
					// override the prices in case they were sold for a different price to ariella.com
					$args = array(
						'totals' => array(
							'subtotal' => $item['subtotal'],
							'subtotal_tax' => $item['subtotal_tax'],
							'total' => $item['total'],
							'tax' => $item['total_tax'],
						)
					);
					$wc_product = wc_get_product(wc_get_product_id_by_sku($item['product']));
					// only if the product if it exists in our database
					if($wc_product){
						$item_id = $wc_order->add_product($wc_product, $item['quantity'], $args);
						if(isset($item['esi_id'])){
							add_post_meta($item_id, '_esi_item_id', $item['esi_id']);
						}
					}
				}
			}

			// set addresses
		    $wc_order->set_address($order['billing_address'], 'billing');
			$wc_order->set_address($order['shipping_address'], 'shipping');
		    // set payment gateway
		    $payment_gateways = WC()->payment_gateways->payment_gateways();
		    $wc_order->set_payment_method($payment_gateways['bacs']);

		    // set totals
		    if($order['totals']){
			    $wc_order->set_total($order['totals']['shipping'], 'shipping');
			    $wc_order->set_total($order['totals']['tax'], 'tax');
			    $wc_order->set_total($order['totals']['shipping_tax'], 'shipping_tax');
			    $wc_order->set_total($order['totals']['total'], 'total');
		    }

			// confirm the order was received
			if($source_id){
				$this->mark_order_as_exported($wc_order, $source_name, $source_id);
			}
		}

		return $wc_order;
	}

	function mark_order_as_exported($wc_order, $source_name, $source_id){
		if('channeladvisor' === $source_name){
			$channeladvisor = new ESI_ChannelAdvisor();
			$exported = $channeladvisor->send_order_received($source_id);
		}

		if(true === $exported){
			$note = "Order marked as exported from $source_name";
		} else {
			$note = "Error marking order as exported from $source_name";
		}
		$wc_order->add_order_note($note, false, false);
	}

	/**
	 * Detect when an orders been marked as dispatched and if its an ESI order let them know
	 * @param integer $meta_id
	 * @param integer $post_id Woocommerce order id
	 * @param string $meta_key
	 * @param string $tracking_number
	 */
	function add_tracking_number($check = null, $post_id, $meta_key, $meta_value, $prev_value){
		if('_wc_shipment_tracking_items' !== $meta_key)
			return $check;

		// Because the shipment tracking plugin saves all the values together in an serialized array
		// get the previous value before the update so we can compare the before/after.
		if(empty($prev_value)){
			$prev_value = get_post_meta($post_id, $meta_key, true);
		}

		// if a tracking number hasn't been added do nothing
		if(count($prev_value) >= count($meta_value))
			return $check;

		$tracking_number = end($meta_value)['tracking_id'];

		// check if this is an esi order
		$source_name = get_post_meta($post_id, '_esi_source_name', true);
		if(!empty($source_name)){
			// check we have the third-party's order ref
			$order_id = get_post_meta($post_id, '_esi_source_id', true);
			if(!empty($order_id)){
				if('channeladvisor' === $source_name){
					$channeladvisor = new ESI_ChannelAdvisor();
					$success = $channeladvisor->send_order_tracking($order_id, $tracking_number);
				}
				$wc_order = wc_get_order($post_id);
				if($wc_order){
					// record whether or not the tracking number was sent to the third-party
					if(true === $success){
						$note = "Tracking number sent to $source_name";
					} else {
						$note = "Error sending tracking number to $source_name";
					}
					$wc_order->add_order_note($note, false, false);
				}
			}
		}

		return $check;
	}

	/**
	 * Handle full order refunds.
	 *
	 * @param  integer $order_id post_id
	 * @param  integer $refund_id post_id
	 */
	public function handle_order_refunded($refund_id = 0, $args = 0){
		$order_id = $args['order_id'];
		$wc_order = wc_get_order($order_id);
		$wc_refund = new WC_Order_Refund($refund_id);
		$success = false;

		// partial refund
		if ( $wc_order->get_remaining_refund_amount() > 0 || ( $wc_order->has_free_item() && $wc_order->get_remaining_refund_items() > 0 ) ) {
			// check if this is an esi order
			$source_name = get_post_meta($order_id, '_esi_source_name', true);
			if(!empty($source_name)){
				// check we have the third-party's order ref
				$esi_order_id = get_post_meta($order_id, '_esi_source_id', true);
				if(!empty($esi_order_id)){
					$wc_refund_items = $wc_refund->get_items();
					if('channeladvisor' === $source_name){
						$channeladvisor = new ESI_ChannelAdvisor();
						$response = $this->send_order_partially_refunded($esi_order_id, $wc_refund, $wc_refund_items);
					}
					// record whether or not the tracking number was sent to the third-party
					if(true === $response['success']){
						$note = "Order partially refunded on $source_name";
					} else {
						$note = "Error partially refunding order on $source_name";
						// add an error message if we have it
						if(!empty($response['error_message'])){
							$note .= '. Reason: '. $response['error_message'];
						}
					}
					$wc_order->add_order_note($note, false, false);
				}
			}
		// full refund
		} else {
			// check if this is an esi order
			$source_name = get_post_meta($order_id, '_esi_source_name', true);
			if(!empty($source_name)){
				// check we have the third-party's order ref
				$esi_order_id = get_post_meta($order_id, '_esi_source_id', true);
				if(!empty($esi_order_id)){
					$wc_refund = new WC_Order_Refund($refund_id);
					if('channeladvisor' === $source_name){
						$channeladvisor = new ESI_ChannelAdvisor();
						$response = $this->send_order_fully_refunded($esi_order_id, $wc_refund);
					}

					// record whether or not the tracking number was sent to the third-party
					if(true === $response['success']){
						$note = "Order refunded on $source_name";
					} else {
						$note = "Error refunding order on $source_name";
						// add an error message if we have it
						if(!empty($response['error_message'])){
							$note .= '. Reason: '. $response['error_message'];
						}
					}
					$wc_order->add_order_note($note, false, false);
				}
			}
		}
	}

	function order_completed($order_id = 0){
		return false;
	}

	function get_order($order_id = 0){
		return array();
	}

	function send_order_received($order_id = 0){
		return false;
	}

	function send_order_tracking($order_id = 0){
		return false;
	}

	function send_order_cancellation($order_id = 0){
		return false;
	}

	// function parse_response($response = false){
	// 	return $response;
	// }


// ================================= Products start here ==========================

	public function prepare_update_product_data($product_id) {
		$reg_price  = get_post_meta( $product_id, '_regular_price', true);
		$sale_price = get_post_meta( $product_id, '_sale_price', true);
		$price 		= !empty($sale_price) ? $sale_price : $reg_price;
		$parent_id  = wp_get_post_parent_id($product_id);
		$content 	= get_post($parent_id)->post_content;
		$brand      = esi_get_field_value('ari_brand', $parent_id);

		$product = array(
			'brand' 		=> $brand,
			'description' 	=> $content,
			'ean' 			=> get_post_meta($product_id, '_ean', true),
			'manufacturer' 	=> $brand,
			'excerpt' 		=> get_the_excerpt($parent_id),
			'sku' 			=> get_post_meta($product_id, '_sku', true ),
			'title' 		=> get_the_title($parent_id),
			'price' 		=> (float)$price,
		);
		if( is_channel_advisor_product($product_id) ){
			$channeladvisor = new ESI_ChannelAdvisor;
			$success = $channeladvisor->update_product_data($product, $product_id);
		}
	}

	public function prepare_update_attributes($product_id) {
		if( is_channel_advisor_product($product_id) ){
			$channeladvisor = new ESI_ChannelAdvisor();
			$success = $channeladvisor->update_attributes($product_id, $parent_id);
		}
	}

	public function prepare_update_images($product_id){

		$parent_id 	= wp_get_post_parent_id($product_id);
		$images 	= get_image_urls($parent_id);

		if( is_channel_advisor_product($product_id) ){
			$channeladvisor = new ESI_ChannelAdvisor();
			$success = $channeladvisor->update_images($product_id, $images);
		}
	}

	public function prepare_update_labels($product_id, $added_values, $removed_values, $channel) {

		//check if external shops were added
		if( !empty($added_values) ){
			foreach($added_values as $val){
				if( $channel == 'channel_advisor' ){
					$channeladvisor = new ESI_ChannelAdvisor();
					$success = $channeladvisor->add_product_label($product_id, $val);
				}
			}
		}

		//check if external shops were removed
		if( !empty($removed_values) ) {
			foreach( $removed_values as $val ) {
				if( $channel == 'channel_advisor' ){
					$channeladvisor = new ESI_ChannelAdvisor();
					$success = $channeladvisor->remove_product_label($product_id, $val);
				}
			}
		}
	}

	/**
	* Detect when a product has been updated and if its an ESI product let them know
	* @param int 		$post_ID
	* @param WP_Post  	$post
	* @param bool  		$update
	* @return array
	*/
	function update_product($post_ID, $post, $update) {
		//only run for updated products
		if( $post->post_type == 'product' && $update == 1 ) {
			$args = array(
				'post_parent' => $post_ID,
				'post_type'   => 'product_variation',
				'numberposts' => -1,
				'post_status' => 'any'
			);
			$children = get_children( $args );

			foreach($children as $product_id => $product) {
				$this->prepare_update_product_data($product_id);
				$this->prepare_update_attributes($product_id);
				$this->prepare_update_images($product_id);
			}
		}
	}

	/**
	* Detect when a product external marketplace was updated and let them know
	* @param 	string		$value		[the value of the field as found in the $_POST object]
	* @param 	int  		$post_id	the post id to save against
	* @param 	array  		$update 	[the field object (actually an array, not object)]
	* @return array
	*/
	public function update_marketplace($value, $post_id, $field) {
		if( $field['name'] == 'ca_external_marketplaces' || $field['name'] == 'anatwine_external_marketplaces' ){
			$args = array(
				'post_parent' => $post_id,
				'post_type'   => 'product_variation',
				'numberposts' => -1,
				'post_status' => 'any'
			);
			$children = get_children( $args );

			$orig_values = array();
			$orig_values = get_field($field['name'], $post_id);
			$removed_values = array_diff($orig_values, $value);
			$added_values 	= array_diff($value, $orig_values);
			$channel = ($field['name'] == 'ca_external_marketplaces') ? 'channel_advisor' : 'anatwine';

			foreach($children as $product_id => $product) {
				$this->prepare_update_labels($product_id, $added_values, $removed_values, $channel);
			}
		}
		return $value;
	}

	/**
	* Detect when a product stock has been updated and if its an ESI product let them know
	*
	* @param object $product
	*
	* @return bool
	*/
	function update_product_stock($product){
		$product_id = $product->get_id();
		$qty = get_post_meta($product_id, '_stock', true );
		if( is_channel_advisor_product($product_id) ){
			$channeladvisor = new ESI_ChannelAdvisor();
			$success = $channeladvisor->update_qty($product_id, (int)$qty);
		}

		if( is_anatwine_product($product_id) ){
			$anatwine = new ESI_Anatwine;
			$success = $anatwine->update_qty($product_id);
		}
		return $success;
	}

	/**
	* Set ESI ID to Ariella products
	*
	*/
	function sync_products(){
		if( isset($_REQUEST['channel']) && !empty($_REQUEST['channel']) ) {
			switch ($_REQUEST['channel']) {
				case 'channel_advisor':
					$channeladvisor = new ESI_ChannelAdvisor;
					$success = $channeladvisor->set_ca_product_id();
					break;

				default:
					# code...
					break;
			}
		}
		$this->record_activity("{$this->esi_name}_sync_prod");
		return $success;
	}

	/**
	* Detect when a request was made to upload the products csv
	* @param object $product
	* @return bool
	*/
	function upload_file_sftp(){
		$token = '';
		if( isset($_REQUEST['channel']) && $_REQUEST['channel'] == 'channeladvisor' && $token == $_REQUEST['token']) {
			$type = !empty($_REQUEST['file_type']) ? $_REQUEST['file_type'] : 'parent';
			$ca = new ESI_ChannelAdvisor;
			$success = $ca->upload_products_csv($type);
		}
		return $success;
	}

	/**
	* Detect when a request was made to download the products csv
	* @param object $product
	* @return bool
	*/
	function download_file_csv(){
		$token = '';

		if( isset($_REQUEST['channel']) && $_REQUEST['channel'] == 'channeladvisor' && $token === $_REQUEST['token']) {
			$products = get_channel_advisor();
			$create_csv = write_products_csv('cadvisor', $products);

			if( !empty($create_csv['success']) ){
				$wp_upload = wp_upload_dir();
				$upload_path = $wp_upload['basedir'] . '/csv';
				$file_url = $upload_path . '/'.$create_csv['file_name'];
				header("Content-type: text/csv");
				header("Content-disposition: attachment; filename=\"" . basename($file_url) . "\"");
			  readfile($file_url);
			}
	    exit;
		}

		if( isset($_REQUEST['channel']) && $_REQUEST['channel'] == 'ariella' && $token === $_REQUEST['token']) {
			$products = $this->get_ariella();
			$create_csv = write_products_csv('products', $products);

			if( !empty($create_csv['success']) ){
				$wp_upload = wp_upload_dir();
				$upload_path = $wp_upload['basedir'] . '/csv';
				$file_url = $upload_path . '/'.$create_csv['file_name'];
				header("Content-type: text/csv");
				header("Content-disposition: attachment; filename=\"" . basename($file_url) . "\"");
			  readfile($file_url);
			}
	    exit;
		}

		if( isset($_REQUEST['channel']) && $_REQUEST['channel'] == 'anatwine' && $token === $_REQUEST['token']) {
			$products = get_anatwine_products();
			$create_csv = write_products_csv('anatwine', $products);

			if( !empty($create_csv['success']) ){
				$wp_upload = wp_upload_dir();
				$upload_path = $wp_upload['basedir'] . '/csv';
				$file_url = $upload_path . '/'.$create_csv['file_name'];
				header("Content-type: text/csv");
				header("Content-disposition: attachment; filename=\"" . basename($file_url) . "\"");
			  readfile($file_url);
			}
	    exit;
		}

		return false;
	}

	/**
	* Set option value today
	* @param 	string 	$option
	*
	* @return 	bool
	*/
	function record_activity($option = '') {
		$today = date("Y-m-d H:i:s");
		$updated = update_option( $option, $today );
		return $updated;
	}

	function handle_anatwine_xml(){
		$success = false;
		$token = '';
		if( isset($_REQUEST['channel']) && $_REQUEST['channel'] == 'anatwine' && $token == $_REQUEST['token'] && !empty($_REQUEST['type']) ){
			$anatwine = new ESI_Anatwine;
			switch ($_REQUEST['type']) {
				case 'products':
					$success = $anatwine->generate_products_xml();
					break;
				case 'stock':
					$success = $anatwine->generate_stock_xml();
					break;
				case 'price':
					$success = $anatwine->generate_price_xml();
					break;
				default:
					$success = false;
					break;
			}
		}
		return $success;
	}

	//TODO remove this function only used to trigger actions in the test phase
	function trigger_actions(){
		if( isset($_REQUEST['type']) && isset($_REQUEST['product_id']) && !empty($_REQUEST['product_id']) ) {
			$type = $_REQUEST['type'];
			$product_id = $_REQUEST['product_id'];
			if( is_anatwine_product($product_id) ){
				switch ($type) {
					case 'update_stock':
						$anatwine = new ESI_Anatwine;
						$success = $anatwine->update_qty($product_id);
						break;
					case 'update_product':
						$anatwine = new ESI_Anatwine;
						$success = $anatwine->update_products($product_id);
						break;
					case 'update_price':
						$anatwine = new ESI_Anatwine;
						$success = $anatwine->update_price($product_id);
						break;
					default:
						print_r("Don't know how to handle type ");
						break;
				}
			}
		}
	}


	/**
	 * Get all products
	 *
	 * @return array
	 */
	function get_ariella() {
	    global $wp_query;
	    $product_list = array();
	    $loop = new WP_Query(
	        array(
	            'post_type' => array('product_variation'),
	            'posts_per_page' => -1
	        )
	    );

	    while ( $loop->have_posts() ) : $loop->the_post();
	        $product_id = get_the_ID();

	        // its a variable product
	        if( get_post_type() == 'product_variation' ){
	            $marketplaces = array();
	            $parent_id    = wp_get_post_parent_id($product_id);
	            $marketplaces = get_field('ca_external_marketplaces', $parent_id);

	            $sku                            = get_post_meta($product_id, '_sku', true );
	            $parent_sku                     = get_post_meta($parent_id, '_sku', true );
	            $images                         = get_image_urls($parent_id);
	            $color                          = get_field('stock_color', $parent_id);
	            $brand                          = get_field('ari_brand', $parent_id);
	            $selling_ponts                  = get_field('ari_selling_points', $parent_id);
	            $formated_sp                    = !empty($selling_ponts) ? implode('|', array_column($selling_ponts, 'selling_point')) : '';
	            $type                           = get_field('ari_type', $parent_id);
	            $reg_price                      = get_post_meta( $product_id, '_regular_price', true);
	            $sale_price                     = get_post_meta( $product_id, '_sale_price', true);
	            $otto_fabre                     = get_field('otto_farbe', $parent_id);
	            $zalando_fabric                 = get_field('zalando_fabric', $parent_id);
	            $zalando_occaision              = get_field('zalando_occaision', $parent_id);
	            $zalando_cut                    = get_field('zalando_cut', $parent_id);
	            $zalando_details                = get_field('zalando_details', $parent_id);
	            $zalando_sleeve_length          = get_field('zalando_sleeve_length', $parent_id);
	            $zalando_washing_instructions   = get_field('zalando_washing_instructions', $parent_id);
	            $eu_size                        = get_post_meta($product_id, '_eu_size', true);
	            $size                           = get_post_meta($product_id, 'attribute_pa_size', true);

	            if ( !empty($sku) ){
	                $product_list[] = array(
	                    //common fields
	                    'age_group'             => 'ADULT',
	                    'category'              => 'CLOTHING',
	                    'colour'                => $color,
	                    'condition'             => 'NEW',
	                    'description'           => get_post($parent_id)->post_content,
	                    'ean'                   => get_post_meta($product_id, '_ean', true),
	                    'eu_size'               => $eu_size,
	                    'gender'                => 'WOMEN',
	                    'manufacturer'          => !empty($brand) ? $brand : 'Ariella London',
	                    'material_composition'  => get_field('composition', $parent_id),
	                    'material'              => get_field('ari_main_fabric', $parent_id),
	                    'parent'                => $parent_sku,
	                    'price'                 => !empty($sale_price) ? $sale_price : $reg_price,
	                    'product_image1'        => implode('|', $images),
	                    'relationship_name'     => 'SIZE',
	                    'season'                => get_field('ari_season', $parent_id),
	                    'seller_cost'           => get_post_meta($product_id, '_seller_cost', true),
	                    'selling_points'        => $formated_sp,
	                    'selling_price_old'     => $reg_price,
	                    'size'                  => $size,
	                    'sku'                   => $sku,
	                    'stock'                 => get_post_meta($product_id, '_stock', true ),
	                    'title'                 => get_the_title($parent_id),
	                    'type'                  => $type,

	                    //add the product labels
	                    'send_to_amazon'        => in_array('Send to Amazon', $marketplaces),
	                    'send_to_ebay'          => in_array('Send to eBay', $marketplaces),
	                    'send_to_laredoute'     => in_array('Send to La Redoute', $marketplaces),
	                    'send_to_otto'          => in_array('Send to Otto', $marketplaces),
	                    'send_to_zalando'       => in_array('Send to Zalando', $marketplaces),
	                    'send_to_fruugo'        => 0, //TODO 07.02.2018 Ariella asked for this maybe remove this in future

	                    //otto specific fields
	                    'otto_category'                 => get_field('otto_category', $parent_id),
	                    'otto_depth'                    => get_field('otto_depth', $parent_id),
	                    'otto_height'                   => get_field('otto_height', $parent_id),
	                    'otto_no_of_parcel_units'       => get_field('otto_no_of_parcel_units', $parent_id),
	                    'otto_buying_price'             => get_field('otto_buying_price', $parent_id),
	                    'otto_weight'                   => get_field('otto_weight', $parent_id),
	                    'otto_width'                    => get_field('otto_width', $parent_id),
	                    'otto_zielgruppe'               => 'Erwachsene',
	                    'otto_geschlecht'               => 'Weiblich',
	                    'otto_Produkttyp'               => get_field('otto_Produkttyp', $parent_id),
	                    'otto_farbe'                    => $otto_fabre,
	                    'otto_grundfarbe'               => $otto_fabre,
	                    'otto_materialzusammensetzung'  => get_field('otto_materialzusammensetzung', $parent_id),
	                    'otto_pflegehinweise'           => get_field('otto_pflegehinweise', $parent_id),
	                    'otto_ausgabe_großentyp'        => get_field('otto_ausgabe_großentyp', $parent_id),
	                    'otto_selling_point_1'          => get_field('otto_selling_point_1', $parent_id),
	                    'otto_selling_point_2'          => get_field('otto_selling_point_2', $parent_id),
	                    'otto_selling_point_3'          => get_field('otto_selling_point_3', $parent_id),

	                    //laredoute specific fields
	                    'laredoute_brand_code'          => 'TBC',
	                    'laredoute_age'                 => 'ADULTE',
	                    'laredoute_gender'              => 'FEMME',
	                    'laredoute_description'         => get_field('laredoute_description', $parent_id),
	                    'laredoute_motif'               => get_field('laredoute_motif_PAP', $parent_id),
	                    'laredoute_amincissant'         => get_field('laredoute_amincissant', $parent_id),
	                    'laredoute_forme_col'           => get_field('laredoute_forme_col', $parent_id),
	                    'laredoute_forme_robe_jupe'     => get_field('laredoute_forme_robe_jupe', $parent_id),
	                    'laredoute_grande_taille'       => get_field('laredoute_grande_taille', $parent_id),
	                    'laredoute_hauteur_taille'      => get_field('laredoute_hauteur_taille', $parent_id),
	                    'laredoute_longueur'            => get_field('laredoute_longueur_jupe_robe', $parent_id),
	                    'laredoute_manches'             => get_field('laredoute_longueur_manches', $parent_id),
	                    'laredoute_maintien'            => get_field('laredoute_maintien2', $parent_id) ?: 'Non',
	                    'laredoute_maternite'           => get_field('laredoute_maternite2', $parent_id) ?: 'Non',
	                    'laredoute_vetements'           => get_field('laredoute_matiere_vetements', $parent_id),
	                    'laredoute_saisonnalite'        => get_field('laredoute_saisonnalite2', $parent_id),
	                    'laredoute_sexe'                => get_field('laredoute_sexe', $parent_id),
	                    'laredoute_style_mode'          => get_field('laredoute_style_mode', $parent_id),
	                    'laredoute_fermeture'           => get_field('laredoute_type_fermeture', $parent_id),
	                    'laredoute_tailleur'            => get_field('laredoute_type_tailleur', $parent_id),
	                    'laredoute_gilet'               => get_field('laredoute_forme_gilet', $parent_id),
	                    'laredoute_veste'               => get_field('laredoute_coupe_chemise_veste', $parent_id),
	                    'laredoute_teinte'              => get_field('laredoute_teinte2', $parent_id),
	                    'laredoute_maille'              => get_field('laredoute_epaisseur_maille', $parent_id),

	                    //zalando specific fields
	                    'zalando_product_category'      => get_field('zalando_product_category', $parent_id),
	                    'zalando_description'           => nl2br(strip_tags(get_field('zalando_description', $parent_id))),
	                    'zalando_lining'                => get_field('zalando_lining', $parent_id),
	                    'zalando_breathable'            => get_field('zalando_breathable', $parent_id),
	                    'zalando_length'                => get_field('zalando_length', $parent_id),
	                    'zalando_fabric'                => !empty($zalando_fabric) ? implode('|', $zalando_fabric) : '',
	                    'zalando_neckline'              => get_field('zalando_neckline', $parent_id),
	                    'zalando_occaision'             => !empty($zalando_occaision) ? implode('|', $zalando_occaision) : '',
	                    'zalando_fastening'             => get_field('zalando_fastening', $parent_id),
	                    'zalando_pattern'               => get_field('zalando_pattern', $parent_id),
	                    'zalando_collar'                => get_field('zalando_collar', $parent_id),
	                    'zalando_cut'                   => !empty($zalando_cut) ? implode('|', $zalando_cut) : '',
	                    'zalando_sheer'                 => get_field('zalando_sheer', $parent_id),
	                    'zalando_correct_fit'           => get_field('zalando_correct_fit', $parent_id),
	                    'zalando_details'               => !empty($zalando_details) ? implode('|', $zalando_details) : '',
	                    'zalando_sleeve_length'         => !empty($zalando_sleeve_length) ? implode('|', $zalando_sleeve_length) : '',
	                    'zalando_washing_instructions'  => !empty($zalando_washing_instructions) ? implode('|', $zalando_washing_instructions) : '',
	                );
	            }
	        }
	    endwhile;
	    wp_reset_query();
			$this->record_activity("ariella_file_dld");
	    return $product_list;
	}

}
