<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * WC_Shipping_Sendle class.
 *
 * @extends WC_Shipping_Method
 */
class WC_Shipping_Sendle extends WC_Shipping_Method {
	private $default_api_key = '';
	private $max_weight;
	private $services;
	private $extra_cover;
	private $delivery_confirmation;

	private $endpoints = array(
		'calculation' => 'https://digitalapi.auspost.com.au/api/postage/{type}/{doi}/calculate.json',
		'services'    => 'https://digitalapi.auspost.com.au/api/postage/{type}/{doi}/service.json',
	);

	private $sod_cost = 2.95;
	private $int_sod_cost = 5.49;
	private $found_rates;
	private $rate_cache;
	private $is_international = false;

	/**
	 * __construct function.
	 *
	 * @access public
	 * @return void
	 */
	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'sendle';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'Sendle', 'poc-shipping-sendle' );
		$this->method_description = __( 'The Sendle calculates the rates of sending packages via Sendle. Pricing effective as of 15 November 2017.', 'poc-shipping-sendle' );
		$this->supports           = array(
			'shipping-zones',
			'instance-settings',
			'settings',
		);
		$this->init();
	}

	/**
	 * is_available function.
	 *
	 * @param array $package
	 * @return bool
	 */
	public function is_available( $package ) {
		// Country must be set and it must be set to AU
		if ( empty( $package['destination']['country'] ) || $package['destination']['country'] != 'AU' ) {
			return false;
		}

		return apply_filters( 'woocommerce_shipping_' . $this->id . '_is_available', true, $package );
	}

	/**
	 * Initialize settings
	 *
	 * @version 2.4.0
	 * @since 2.4.0
	 * @return void
	 */
	private function set_settings() {
		// Define user set variables
		$this->title            = $this->get_option( 'title', $this->method_title );
		$this->excluding_tax    = $this->get_option( 'excluding_tax', 'no' );
		$this->origin           = $this->get_option( 'origin', '' );
		$this->origin_suburb    = $this->get_option( 'origin_suburb', '' );
		$this->plan             = $this->get_option( 'plan', '' );
		$this->api_key          = $this->get_option( 'api_key', $this->default_api_key );
		$this->packing_method   = $this->get_option( 'packing_method', 'per_item' );
		$this->boxes            = $this->get_option( 'boxes', array() );
		$this->custom_services  = $this->get_option( 'services', array() );
		$this->offer_rates      = $this->get_option( 'offer_rates', 'all' );
		$this->debug            = ( ( $bool = $this->get_option( 'debug_mode' ) ) && $bool === 'yes' );
		$this->satchel_priority = $this->get_option( 'satchel_priority', 'no' );
		$this->satchel_rates    = $this->get_option( 'satchel_rates', '' );
		$this->parcel_protection= $this->get_option( 'parcel_protection', 'no' );
		$this->handling_charge  = $this->get_option( 'handling_charge', 0 );
		
		// Used for weight based packing only
		$this->max_weight = $this->get_option( 'max_weight', '20' );
	}

	/**
	 * init function.
	 *
	 * @access public
	 * @return void
	 */
	private function init() {
		// Load the settings.
		$this->init_form_fields();
		$this->set_settings();

		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
		// add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'test_api_key' ), -10 );
		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'clear_transients' ) );
	}

	/**
	 * Process settings on save
	 *
	 * @access public
	 * @since 2.4.0
	 * @version 2.4.0
	 * @return void
	 */
	public function process_admin_options() {
		parent::process_admin_options();

		$this->set_settings();
	}

	/**
	 * Output a debug message.
	 *
	 * @since 1.0.0
	 * @version 2.4.4
	 *
	 * @param string $message Debug message.
	 * @param string $type    Debug type ('notice', 'error', etc).
	 */
	public function debug( $message, $type = 'notice' ) {
		// Probably called in wp-admin page.
		if ( ! function_exists( 'wc_add_notice' ) ) {
			return;
		}

		// Debug setting is disabled.
		if ( ! $this->debug ) {
			return;
		}

		// Only store owner or adminstrator can see the debug notice.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wc_add_notice( $message, $type );
	}

	/**
	 * generate_box_packing_html function.
	 *
	 * @access public
	 * @return void
	 */
	public function generate_box_packing_html() {
		ob_start();
		include( dirname( __FILE__ ) . '/views/html-box-packing.php' );
		return ob_get_clean();
	}

	/**
	 * validate_box_packing_field function.
	 *
	 * @access public
	 *
	 * @param mixed $key
	 *
	 * @return void
	 */
	public function validate_box_packing_field( $key ) {
		$boxes = array();

		if ( isset( $_POST['boxes_outer_length'] ) ) {
			$boxes_outer_length = isset( $_POST['boxes_outer_length'] ) ? $_POST['boxes_outer_length'] : array();
			$boxes_outer_width  = isset( $_POST['boxes_outer_width'] ) ? $_POST['boxes_outer_width'] : array();
			$boxes_outer_height = isset( $_POST['boxes_outer_height'] ) ? $_POST['boxes_outer_height'] : array();
			$boxes_inner_length = isset( $_POST['boxes_inner_length'] ) ? $_POST['boxes_inner_length'] : array();
			$boxes_inner_width  = isset( $_POST['boxes_inner_width'] ) ? $_POST['boxes_inner_width'] : array();
			$boxes_inner_height = isset( $_POST['boxes_inner_height'] ) ? $_POST['boxes_inner_height'] : array();
			$boxes_box_weight   = isset( $_POST['boxes_box_weight'] ) ? $_POST['boxes_box_weight'] : array();
			$boxes_max_weight   = isset( $_POST['boxes_max_weight'] ) ? $_POST['boxes_max_weight'] : array();
			$boxes_is_letter    = isset( $_POST['boxes_is_letter'] ) ? $_POST['boxes_is_letter'] : array();

			for ( $i = 0; $i < sizeof( $boxes_outer_length ); $i++ ) {

				if ( $boxes_outer_length[ $i ] && $boxes_outer_width[ $i ] && $boxes_outer_height[ $i ] && $boxes_inner_length[ $i ] && $boxes_inner_width[ $i ] && $boxes_inner_height[ $i ] ) {

					$boxes[] = array(
						'outer_length' => floatval( $boxes_outer_length[ $i ] ),
						'outer_width'  => floatval( $boxes_outer_width[ $i ] ),
						'outer_height' => floatval( $boxes_outer_height[ $i ] ),
						'inner_length' => floatval( $boxes_inner_length[ $i ] ),
						'inner_width'  => floatval( $boxes_inner_width[ $i ] ),
						'inner_height' => floatval( $boxes_inner_height[ $i ] ),
						'box_weight'   => floatval( $boxes_box_weight[ $i ] ),
						'max_weight'   => floatval( $boxes_max_weight[ $i ] ),
						'is_letter'    => isset( $boxes_is_letter[ $i ] ) ? true : false
					);

				}

			}
		}

		return $boxes;
	}

	/**
	 * clear_transients function.
	 *
	 * @access public
	 * @return void
	 */
	public function clear_transients() {
		delete_transient( 'wc_sendle_quotes' );
	}

	/**
	 * init_form_fields function.
	 *
	 * @access public
	 * @return void
	 */
	public function init_form_fields() {
		$this->instance_form_fields = array(
			'title'          => array(
				'title'       => __( 'Method Title', 'poc-shipping-sendle' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'poc-shipping-sendle' ),
				'default'     => __( 'Sendle', 'poc-shipping-sendle' ),
			),
			'origin_suburb'         => array(
				'title'       => __( 'Origin Suburb', 'poc-shipping-sendle' ),
				'type'        => 'text',
				'description' => __( 'Enter the suburb for the <strong>sender</strong>.', 'poc-shipping-sendle' ),
				'default'     => '',
			),
			'origin'         => array(
				'title'       => __( 'Origin Postcode', 'poc-shipping-sendle' ),
				'type'        => 'text',
				'description' => __( 'Enter the postcode for the <strong>sender</strong>.', 'poc-shipping-sendle' ),
				'default'     => '',
			),
			'handling_charge'         => array(
				'title'       => __( 'Handling Charge', 'poc-shipping-sendle' ),
				'type'        => 'text',
				'description' => __( 'Enter a global handling charge, if desired.', 'poc-shipping-sendle' ),
				'default'     => '0.00',
			),
			'excluding_tax'  => array(
				'title'       => __( 'Tax', 'poc-shipping-sendle' ),
				'label'       => __( 'Calculate Rates Excluding Tax', 'poc-shipping-sendle' ),
				'type'        => 'checkbox',
				'description' => __( "Calculate shipping rates excluding tax (if you plan to add tax via WooCommerce's tax system). By default rates returned by the Sendle API include tax.", 'poc-shipping-sendle' ),
				'default'     => 'no',
			),
			'rates'          => array(
				'title'       => __( 'Rates and Services', 'poc-shipping-sendle' ),
				'type'        => 'title',
				'description' => __( 'The following settings determine the rates you offer your customers.', 'poc-shipping-sendle' ),
			),
			'packing_method' => array(
				'title'   => __( 'Parcel Packing Method', 'poc-shipping-sendle' ),
				'type'    => 'select',
				'default' => '',
				'class'   => 'packing_method',
				'options' => array(
					'per_item'    => __( 'Default: Pack items individually', 'poc-shipping-sendle' ),
					'weight'      => __( 'Weight of all items', 'poc-shipping-sendle' ),
					'box_packing' => __( 'Recommended: Pack into boxes with weights and dimensions', 'poc-shipping-sendle' ),
				),
			),
			'max_weight'     => array(
				'title'       => __( 'Maximum weight (kg)', 'poc-shipping-sendle' ),
				'type'        => 'text',
				'default'     => '20',
				'description' => __( 'Maximum weight per package in kg.', 'poc-shipping-sendle' ),
			),
			'boxes'          => array(
				'type' => 'box_packing',
			),
			// 'satchel_rates'  => array(
			// 	'title'   => __( 'Satchel Rates', 'poc-shipping-sendle' ),
			// 	'type'    => 'select',
			// 	'options' => array(
			// 		'on'       => __( 'Enable Satchel Rates', 'poc-shipping-sendle' ),
			// 		'priority' => __( 'Prioritze Satchel Rates', 'poc-shipping-sendle' ),
			// 		'off'      => __( 'Disable Satchel Rates', 'poc-shipping-sendle' ),
			// 	),
			// 	'default' => ( isset( $this->settings['satchel_priority'] ) && 'yes' === $this->settings['satchel_priority'] ) ? 'priority' : 'on',
			// ),
			// 'offer_rates'    => array(
			// 	'title'       => __( 'Offer Rates', 'poc-shipping-sendle' ),
			// 	'type'        => 'select',
			// 	'description' => '',
			// 	'default'     => 'all',
			// 	'options'     => array(
			// 		'all'      => __( 'Offer the customer all returned rates', 'poc-shipping-sendle' ),
			// 		'cheapest' => __( 'Offer the customer the cheapest rate only, anonymously', 'poc-shipping-sendle' ),
			// 	),
			// ),
			// 'services'       => array(
			// 	'type' => 'services',
			// ),
		);

		$this->form_fields = array(
			'api'            => array(
				'title'       => __( 'API Settings', 'poc-shipping-sendle' ),
				'type'        => 'title',
				'description' => __( 'Your API access details are obtained from the Sendle website. You can obtain your <a href="https://app.sendle.com/dashboard/api_settings">own key here</a>.', 'poc-shipping-sendle' ),
			),
			'api_sender_id'        => array(
				'title'       => __( 'Sender ID', 'poc-shipping-sendle' ),
				'type'        => 'text',
				'description' => __( '', 'poc-shipping-sendle' ),
				'default'     => '',
				// 'placeholder' => $this->default_api_key,
			),
			'api_key'        => array(
				'title'       => __( 'API Key', 'poc-shipping-sendle' ),
				'type'        => 'text',
				'description' => __( '', 'poc-shipping-sendle' ),
				'default'     => '',
				// 'placeholder' => $this->default_api_key,
			),
			'plan'        => array(
				'title'       => __( 'Plan', 'poc-shipping-sendle' ),
				'type'    => 'select',
				'default' => '',
				'class'   => '',
				'options' => array(
					'Easy'    => __( 'Easy', 'poc-shipping-sendle' ),
					'Premium' => __( 'Premium', 'poc-shipping-sendle' ),
					'Pro'     => __( 'Pro', 'poc-shipping-sendle' ),
				),
			),
			'debug_mode'     => array(
				'title'       => __( 'Debug Mode', 'poc-shipping-sendle' ),
				'label'       => __( 'Enable debug mode', 'poc-shipping-sendle' ),
				'type'        => 'checkbox',
				'default'     => 'no',
				'description' => __( 'Enable debug mode to show debugging information on your cart/checkout.', 'poc-shipping-sendle' ),
			),
		);
	}

	/**
	 * Tests the entered API key against the service to see if a forbidden error is returned.
	 * If it is, the key is rejected and an error message is displayed.
	 */
	public function test_api_key() {
		if ( empty ( $_POST['woocommerce_sendle_api_key'] ) ) {
			return;
		}

		$test_endpoint = str_replace( array( '{type}', '{doi}' ), array(
			'parcel',
			'domestic',
		), $this->endpoints['calculation'] );
		$test_request  = "weight=5&height=5&width=5&length=5&from_postcode=3149&to_postcode=3149&service_code=AUS_PARCEL_REGULAR";
		$test_headers  = array( 'AUTH-KEY' => $_POST['woocommerce_sendle_api_key'] );

		// We don't want to use $this->get_response here because we don't want the result cached,
		// we want to avoid the front end debug notices, and we want to get back the actual status code
		$response      = wp_remote_get( $test_endpoint . '?' . $test_request, array(
			'headers' => $test_headers,
		) );
		$response_code = wp_remote_retrieve_response_code( $response );

		if ( 403 !== $response_code ) {
			return;
		}

		echo '<div class="error">
			<p>' . esc_html__( 'The Sendle API key you entered is invalid. Please make sure you entered a valid key (<a href="https://auspost.com.au/devcentre/pacpcs-registration.asp">which can be obtained here</a>) and not your WooCommerce license key. Our API key will be used instead.', 'poc-shipping-sendle' ) . '</p>
		</div>';

		$_POST['woocommerce_sendle_api_key'] = '';
	}
	
	/**
	 * cubic_weight function.
	 *
	 * @access private
	 *
	 * @param mixed $length
	 * @param mixed $width
	 * @param mixed $height
	 * @param mixed $weight
	 *
	 * @return void
	 */
	private function cubic_weight( $length = 0, $width = 0, $height = 0, $weight = 0 ) {
		if ($length && $width && $height && $weight)
		{
			$cubic_weight = $length * $width * $height / (100 * 100 * 100) * 250;
			if ($cubic_weight > $weight)
			{
				return $cubic_weight;
			}
			else
			{
				return $weight;
			}
		}
		return 0;
	}
	
	

	/**
	 * calculate_shipping function.
	 *
	 * @access public
	 *
	 * @param array $package
	 *
	 * @return void
	 */
	public function calculate_shipping( $package = array() ) {
		$this->found_rates      = array();
		$this->rate_cache       = get_transient( 'wc_sendle_quotes' );
		$this->is_international = $this->is_international( $package );
		// $headers                = $this->get_request_header();
		$headers                = NULL;
		$package_requests       = $this->get_package_requests( $package );
		
		if ( empty( $this->rate_cache ) ) {
			$this->rate_cache = array();
		}

		$this->debug( __( 'Sendle debug mode is on - to hide these messages, turn debug mode off in the settings.', 'poc-shipping-sendle' ) );

		if ( $package_requests ) {
			
			$rate_cost = 0;
			$messages = '';
			
			foreach ( $package_requests as $key => $package_request ) {
				// Get the cubic weight
				// $this->debug( '<p>package_request:</p><pre>' . print_r($package_request, TRUE) . '</pre>' );
				// $this->debug( '<p>package:</p><pre>' . print_r($package, TRUE) . '</pre>' );
				// echo_array($this->get_request( $package ));
				$request = http_build_query( array_merge( $package_request, $this->get_request( $package ) ), '', '&' );
				// $this->debug(print_r($headers, TRUE));
				$this->debug($request);

				// $this->origin_suburb;
	
				// if ( isset( $package_request['thickness'] ) ) {
				// 	$response = $this->get_response( $letter_services_endpoint, $request, $headers );
				// } else {
					$response = $this->get_response( 'https://api.sendle.com/api/quote', $request, $headers );
				// }
				
				if ($response)
				{
					// I don't know how to make this not stay visible after correcting the problem, so disabling for now.
					// is_cart was an attempt as I thought it worked there but the AJAX breaks it there too.
					if ( is_cart() )
					{
						if (!empty($response->error))
						{
							if (!empty($response->error_description))
							{
								$messages .= '<p>We were unable to lookup that address with our courier service (often our cheapest option!).</p>';
								// $messages .= '<p>' . $response->error_description . '</p>';
							}
							if (!empty($response->messages))
							{
								$messages .= '<p>It could be one of these issues:</p>';
								$messages .= '<ul>';
								foreach ($response->messages as $subject => $message) {
									$messages .= '<li>' . ucwords(str_replace('_', ' ', $subject)) . ' ' . implode(', ', $message) . '</li>';
								}
								$messages .= '</ul>';
							}
							
							// $messages .= '<p>Alternatively, you can use any available shipping service below.</p>';
							if (!empty($messages))
							{
								wc_add_notice( $messages, 'notice');
							}
							return;
						}
					}
					
					foreach ($response as $response_object_id => $response_object)
					{
						// Do we have a quoted amount?
						if (!empty($response_object->quote->gross->amount))
						{
							$rate = $response_object->quote->gross->amount;
							if (!empty($this->handling_charge))
							{
								$rate += $this->handling_charge;
							}
							$rate_cost += $rate;
						}
					}
				}
				
				// if ( isset( $response->services->service ) && is_array( $response->services->service ) ) {
				//
				// 	// Loop our known services
				// 	foreach ( $this->services as $service => $values ) {
				//
				// 		$rate_code = (string) $service;
				// 		$rate_id   = $this->id . ':' . $rate_code;
				// 		$rate_name = (string) $values['name'];
				// 		$rate_cost = null;
				// 		$optional_extras_cost = 0;
				//
				// 		// Main service code
				// 		foreach ( $response->services->service as $quote ) {
				// 			if ( ( isset( $values['alternate_services'] ) && in_array( $quote->code, $values['alternate_services'] ) ) || $service == $quote->code ) {
				//
				// 				$delivery_confirmation = false;
				// 				$rate_set              = false;
				//
				// 				if ( $this->is_satchel( $quote->code ) && 'off' === $this->satchel_rates ) {
				// 					continue;
				// 				}
				//
				// 				if ( $this->is_satchel( $quote->code ) ) {
				// 					switch ( $quote->code ) {
				// 						case 'AUS_PARCEL_REGULAR_SATCHEL_500G' :
				// 						case 'AUS_PARCEL_EXPRESS_SATCHEL_500G' :
				// 							if ( $package_request['length'] > 35 || $package_request['width'] > 22 || ! $this->girth_fits_in_satchel( $package_request['length'], $package_request['width'], $package_request['height'], 35, 22 ) ) {
				// 								continue;
				// 							}
				// 							break;
				// 						case 'AUS_PARCEL_REGULAR_SATCHEL_3KG' :
				// 						case 'AUS_PARCEL_EXPRESS_SATCHEL_3KG' :
				// 							if ( $package_request['length'] > 40 || $package_request['width'] > 31 || ! $this->girth_fits_in_satchel( $package_request['length'], $package_request['width'], $package_request['height'], 40, 31 ) ) {
				// 								continue;
				// 							}
				// 							break;
				// 						case 'AUS_PARCEL_REGULAR_SATCHEL_5KG' :
				// 						case 'AUS_PARCEL_EXPRESS_SATCHEL_5KG' :
				// 							if ( $package_request['length'] > 51 || $package_request['width'] > 43 || ! $this->girth_fits_in_satchel( $package_request['length'], $package_request['width'], $package_request['height'], 51, 43 ) ) {
				// 								continue;
				// 							}
				// 							break;
				// 					}
				// 					if ( 'priority' === $this->satchel_rates ) {
				// 						$rate_cost = $quote->price;
				// 						$rate_set  = true;
				// 					}
				// 					if ( ! empty( $this->custom_services[ $rate_code ]['delivery_confirmation'] ) ) {
				// 						$delivery_confirmation = true;
				// 					}
				// 				} elseif ( ! empty( $this->custom_services[ $rate_code ]['delivery_confirmation'] ) ) {
				// 					$delivery_confirmation = true;
				// 				}
				//
				// 				/**
				// 				 * You must add $2.95 for Signature on Delivery
				// 				 * if your item is valued above $300.
				// 				 *
				// 				 * Please note that this doesn't apply to Courier
				// 				 * Post.
				// 				 *
				// 				 * Don't be confused why we're checking `$package_request['extra_cover']`,
				// 				 * because it's actually product's price.
				// 				 *
				// 				 * @see https://auspost.com.au/parcels-mail/sending-in-australia/domestic-parcels/optional-extras-domestic
				// 				 * @see https://github.com/woocommerce/woocommerce-shipping-australia-post/issues/84
				// 				 */
				// 				// if ( ! $this->is_courier_post( $quote->code ) && $package_request['extra_cover'] >= 300 ) {
				// 				// 	$delivery_confirmation = true;
				// 				// }
				// 				//
				// 				if ( is_null( $rate_cost ) ) {
				// 					$rate_cost = $quote->price;
				// 					$rate_set  = true;
				// 				} elseif ( $quote->price < $rate_cost ) {
				// 					$rate_cost = $quote->price;
				// 					$rate_set  = true;
				// 				}
				//
				// 				if ( $rate_set ) {
				// 					Reset extras cost to 0 since we do not want to duplicate costs for each service
				// 					$optional_extras_cost = 0;
				//
				// 					User wants extra cover
				// 					if ( ! empty( $this->custom_services[ $rate_code ]['extra_cover'] ) && isset( $package_request['extra_cover'] ) ) {
				// 						$max_extra_cover = $this->_get_max_extra_cover_from_quote( $package_request['extra_cover'], $quote );
				// 						$optional_extras_cost += $this->calculate_extra_cover_cost( $package_request['extra_cover'], $max_extra_cover );
				//
				// 						Moved from line 686:
				// 						if ( ! $this->is_courier_post( $quote->code ) && $package_request['extra_cover'] >= 300 ) {
				// 							$delivery_confirmation = true;
				// 						}
				// 					}
				//
				// 					User wants SOD or an item is valued above $300.
				// 					if ( $delivery_confirmation ) {
				// 						if ( $this->is_international ) {
				// 							$optional_extras_cost += $this->int_sod_cost;
				// 						} else {
				// 							$optional_extras_cost += $this->sod_cost;
				// 						}
				// 					}
				// 				}
				// 			}
				// 		}
				//
				// 		if ( $rate_cost ) {
				// 			$rate_cost += $optional_extras_cost;
				// 			$this->prepare_rate( $rate_code, $rate_id, $rate_name, $rate_cost, $package_request, $package );
				// 		}
				// 	}
				// }
			}
		}
		
		// If the total is zero, exit. Unsure if this works. Copying it from elsewhere.
		if ( $rate_cost == 0 ) {
			return;
		}
		
		// Build the rate
		$rate = array(
			'id' => 'sendle',
			'label' => $this->title,
			'cost' => $rate_cost,
			'sort' => NULL,
			'packages' => count($package_requests)
		);
		
		// Set transient
		set_transient( 'wc_sendle_quotes', $this->rate_cache, YEAR_IN_SECONDS );
		
		// Add it and quit.
		$this->add_rate( $rate );
		return;
		
		
		//
		// // Ensure rates were found for all packages
		// if ( $this->found_rates ) {
		// 	foreach ( $this->found_rates as $key => $value ) {
		// 		if ( $value['packages'] < sizeof( $package_requests ) ) {
		// 			unset( $this->found_rates[ $key ] );
		// 		}
		// 	}
		// }
		//
		// // Add rates
		// if ( $this->found_rates ) {
		// 	if ( 'all' === $this->offer_rates ) {
		//
		// 		uasort( $this->found_rates, array( $this, 'sort_rates' ) );
		//
		// 		foreach ( $this->found_rates as $key => $rate ) {
		// 			echo_array($rate);
		// 			$this->add_rate( $rate );
		// 		}
		// 	} else {
		//
		// 		$cheapest_rate = '';
		//
		// 		foreach ( $this->found_rates as $key => $rate ) {
		// 			if ( ! $cheapest_rate || $cheapest_rate['cost'] > $rate['cost'] ) {
		// 				$cheapest_rate = $rate;
		// 			}
		// 		}
		//
		// 		$cheapest_rate['label'] = $this->title; // will use generic rate label defined by user
		// 		$this->add_rate( $cheapest_rate );
		//
		// 	}
		// }
	}

	/**
	 * Checks if destination is international
	 *
	 * @since 2.3.12
	 * @version 2.3.12
	 * @param array $package
	 * @return bool
	 */
	public function is_international( $package ) {
		if ( 'AU' !== $package['destination']['country'] ) {
			return true;
		}

		return false;
	}

	/**
	 * prepare_rate function.
	 *
	 * @access private
	 *
	 * @param mixed $rate_code
	 * @param mixed $rate_id
	 * @param mixed $rate_name
	 * @param mixed $rate_cost
	 */
	private function prepare_rate( $rate_code, $rate_id, $rate_name, $rate_cost, $package_request = '', $package = array() ) {
		// Name adjustment
		if ( ! empty( $this->custom_services[ $rate_code ]['name'] ) ) {
			$rate_name = $this->custom_services[ $rate_code ]['name'];
		}

		// Cost adjustment %
		if ( ! empty( $this->custom_services[ $rate_code ]['adjustment_percent'] ) ) {
			$rate_cost = $rate_cost + ( $rate_cost * ( floatval( $this->custom_services[ $rate_code ]['adjustment_percent'] ) / 100 ) );
		}

		// Cost adjustment
		if ( ! empty( $this->custom_services[ $rate_code ]['adjustment'] ) ) {
			$rate_cost = $rate_cost + floatval( $this->custom_services[ $rate_code ]['adjustment'] );
		}

		// Exclude Tax?
		if ( 'yes' === $this->excluding_tax && ! $this->is_international ) {
			$tax_rate  = apply_filters( 'woocommerce_shipping_sendle_tax_rate', 0.10 );
			$rate_cost = $rate_cost / ( $tax_rate + 1 );
		}

		// Enabled check
		if ( isset( $this->custom_services[ $rate_code ] ) && empty( $this->custom_services[ $rate_code ]['enabled'] ) ) {
			return;
		}

		// Merging
		if ( isset( $this->found_rates[ $rate_id ] ) ) {
			$rate_cost = $rate_cost + $this->found_rates[ $rate_id ]['cost'];
			$packages  = 1 + $this->found_rates[ $rate_id ]['packages'];
		} else {
			$packages = 1;
		}

		// Sort
		if ( isset( $this->custom_services[ $rate_code ]['order'] ) ) {
			$sort = $this->custom_services[ $rate_code ]['order'];
		} else {
			$sort = 999;
		}

		$this->found_rates[ $rate_id ] = array(
			'id'       => $rate_id,
			'label'    => $rate_name,
			'cost'     => $rate_cost,
			'sort'     => $sort,
			'packages' => $packages,
		);
	}

	/**
	 * Perform remote request to AU Post API and returns the response if succeed.
	 *
	 * @since 1.0.0
	 * @version 2.4.4
	 *
	 * @param string $endpoint Endpoint URL where the request is made into.
	 * @param string $request  Request args.
	 * @param array  $headers  Request headers.
	 *
	 * @return mixed Response.
	 */
	private function get_response( $endpoint, $request, $headers ) {
		// If response exists in the cache, returns it.
		if ( is_array( $this->rate_cache ) && isset( $this->rate_cache[ md5( $request ) ] ) ) {
			$response = $this->rate_cache[ md5( $request ) ];

			$this->debug( 'Using cached Sendle REQUEST and RESPONSE.' );
			$this->debug_request_response( $request, $response );

			return $response;
		}

		$response = wp_remote_get( $endpoint . '?' . $request,
			array(
				'timeout' => 70,
				// 'headers' => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->debug( sprintf( 'Sendle request error (%1$s): %2$s', $response->get_error_code(), $response->get_error_message() ), 'error' );
			return false;
		}

		$response = json_decode( $response['body'] );
		if ( is_null( $response ) ) {
			$this->debug( 'Unable to decode JSON body from Sendle response.', 'error' );
			return false;
		}

		// Cache the result in case the same request is made again.
		if ( ! is_array( $this->rate_cache ) ) {
			$this->rate_cache = array();
		}
		$this->rate_cache[ md5( $request ) ] = $response;

		$this->debug_request_response( $request, $response );

		return $response;
	}

	/**
	 * Debug request and response.
	 *
	 * @since 2.4.4
	 * @version 2.4.4
	 *
	 * @param array $request  HTTP request to the Sendle API.
	 * @param mixed $response HTTP response from the Sendle API.
	 */
	private function debug_request_response( $request, $response ) {
		$this->debug( 'Sendle REQUEST: <pre>' . print_r( htmlspecialchars( $request ), true ) . '</pre>' );
		$this->debug( 'Sendle RESPONSE: <pre>' . print_r( $response, true ) . '</pre>' );
	}

	/**
	 * sort_rates function.
	 *
	 * @access public
	 *
	 * @param mixed $a
	 * @param mixed $b
	 *
	 * @return void
	 */
	public function sort_rates( $a, $b ) {
		if ( $a['sort'] == $b['sort'] ) {
			return 0;
		}

		return ( $a['sort'] < $b['sort'] ) ? -1 : 1;
	}

	/**
	 * get_request_header function.
	 *
	 * @access private
	 * @return array
	 */
	private function get_request_header() {
		return array(
			'AUTH-KEY' => $this->api_key,
		);
	}

	/**
	 * get_request function.
	 *
	 * @access private
	 *
	 * @param mixed $package
	 *
	 * @return void
	 */

	private function get_request( $package ) {
		$request = array();

		$request['pickup_postcode'] = str_replace( ' ', '', strtoupper( $this->origin ) );
		$request['pickup_suburb']   = $this->origin_suburb;
		$request['plan_name']       = $this->plan;
		
		switch ( $package['destination']['country'] ) {
			case "AU" :
				$request['delivery_postcode'] = str_replace( ' ', '', strtoupper( $package['destination']['postcode'] ) );
				$request['delivery_suburb']   = $package['destination']['city'];
				break;
			default :
				$request['country_code'] = $package['destination']['country'];
				break;
		}

		return $request;
	}

	/**
	 * get_request function.
	 *
	 * @access private
	 * @return void
	 */
	public function get_package_requests( $package ) {
		$requests = array();

		// Choose selected packing
		switch ( $this->packing_method ) {
			case 'weight' :
				$requests = $this->weight_only_shipping( $package );
				break;
			case 'box_packing' :
				$requests = $this->box_shipping( $package );
				break;
			case 'per_item' :
			default :
				$requests = $this->per_item_shipping( $package );
				break;
		}

		return $requests;
	}




	/**
	 * For letter boxes convert the metrics to match it as users have set on on the product.
	 *
	 * Example:
	 * the letter height is entered as `mm` but the product value is entered in `cm`.
	 *
	 * @since 1.9.0
	 * @param array $boxes saved settings.
	 * @return array $boxes
	 */
	public function convert_letter_boxes_to_match_product_metrics( $boxes ) {
		foreach ( $boxes as $index => $box ) {
			if ( $box['is_letter'] ) {

				$updated_box = array();

				$updated_box['is_letter']    = $box['is_letter'];
				$updated_box['outer_length'] = wc_get_dimension( $box['outer_length'], 'cm', 'mm' );
				$updated_box['outer_width']  = wc_get_dimension( $box['outer_width'],  'cm', 'mm' );
				$updated_box['outer_height'] = wc_get_dimension( $box['outer_height'], 'cm', 'mm' );
				$updated_box['inner_length'] = wc_get_dimension( $box['inner_length'], 'cm', 'mm' );
				$updated_box['inner_width']  = wc_get_dimension( $box['inner_width'],  'cm', 'mm' );
				$updated_box['inner_height'] = wc_get_dimension( $box['inner_height'], 'cm', 'mm' );
				$updated_box['box_weight']   = wc_get_weight( $box['box_weight'], 'kg', 'g' );
				$updated_box['max_weight']   = wc_get_weight( $box['max_weight'], 'kg', 'g' );

				$boxes[ $index ] = $updated_box;

			}
		}
		return $boxes;
	}

	/**
	 * weight_only_shipping function.
	 *
	 * @access private
	 *
	 * @param mixed $package
	 *
	 * @return array
	 */
	private function weight_only_shipping( $package ) {
		if ( ! class_exists( 'WC_Boxpack' ) ) {
			include_once 'box-packer/class-wc-boxpack.php';
		}

		$packer = new WC_Boxpack();

		// Get weight of order
		foreach ( $package['contents'] as $item_id => $values ) {

			if ( ! $values['data']->needs_shipping() ) {
				$this->debug( sprintf( __( 'Product #%d is missing virtual. Aborting.', 'poc-shipping-sendle' ), $values['data']->get_id() ), 'error' );
				continue;
			}

			if ( ! $values['data']->get_weight() ) {
				$this->debug( sprintf( __( 'Product #%d is missing weight. Aborting.', 'poc-shipping-sendle' ), $values['data']->get_id() ), 'error' );

				return null;
			}

			$weight = wc_get_weight( $values['data']->get_weight(), 'kg' );
			$price  = $values['data']->get_price();
			for ( $i = 0; $i < $values['quantity']; $i++ ) {
				$packer->add_item( 0, 0, 0, $weight, $price );
			}
		}

		$box = $packer->add_box( 1, 1, 1, 0 );
		$box->set_max_weight( $this->max_weight );
		$packer->pack();
		$packages = $packer->get_packages();

		if ( sizeof( $packages ) > 1 ) {
			$this->debug( __( 'Package is too heavy. Splitting.', 'poc-shipping-sendle' ), 'error' );
			$this->debug( "Splitting into " . sizeof( $packages ) . ' packages.' );
		}

		$requests = array();
		foreach ( $packages as $p ) {
			$parcel                = array();
			$parcel['weight']      = str_replace( ',', '.', round( $p->weight, 2 ) );
			$parcel['extra_cover'] = ceil( $p->value );

			// Domestic parcels require dimensions
			if ( ! $this->is_international ) {
				$dimension        = 1;
				$parcel['height'] = $dimension;
				$parcel['width']  = $dimension;
				$parcel['length'] = $dimension;
			}

			$requests[] = $parcel;
		}

		return $requests;
	}

	/**
	 * per_item_shipping function.
	 *
	 * @access private
	 *
	 * @param mixed $package
	 *
	 * @return void
	 */
	private function per_item_shipping( $package ) {
		$requests = array();

		// Get weight of order
		foreach ( $package['contents'] as $item_id => $values ) {

			if ( ! $values['data']->needs_shipping() ) {
				$this->debug( sprintf( __( 'Product #%d is virtual. Skipping.', 'poc-shipping-sendle' ), $values['data']->get_id() ) );
				continue;
			}

			if ( ! $values['data']->get_weight() || ! $values['data']->get_length() || ! $values['data']->get_height() || ! $values['data']->get_width() ) {
				$this->debug( sprintf( __( 'Product #%d is missing weight/dimensions. Aborting.', 'poc-shipping-sendle' ), $values['data']->get_id() ) );

				return;
			}

			$parcel = array();

			$parcel['weight'] = wc_get_weight( $values['data']->get_weight(), 'kg' );

			$dimensions = array(
				wc_get_dimension( $values['data']->get_length(), 'cm' ),
				wc_get_dimension( $values['data']->get_height(), 'cm' ),
				wc_get_dimension( $values['data']->get_width(), 'cm' ),
			);

			sort( $dimensions );

			// Min sizes - girth minimum is 16
			$girth = $dimensions[0] + $dimensions[0] + $dimensions[1] + $dimensions[1];

			if ( $girth < 16 ) {
				if ( $dimensions[0] < 4 ) {
					$dimensions[0] = 4;
				}
				if ( $dimensions[1] < 5 ) {
					$dimensions[1] = 5;
				}

				$girth = $dimensions[0] + $dimensions[0] + $dimensions[1] + $dimensions[1];
			}

			if ( $parcel['weight'] > 22 || $dimensions[2] > 105 ) {
				$this->debug( sprintf( __( 'Product %d has invalid weight/dimensions. Aborting. See <a href="http://auspost.com.au/personal/parcel-dimensions.html">http://auspost.com.au/personal/parcel-dimensions.html</a>', 'poc-shipping-sendle' ), $values['data']->get_id() ), 'error' );

				return;
			}

			$parcel['height'] = $dimensions[0];
			$parcel['width']  = $dimensions[1];
			$parcel['length'] = $dimensions[2];

			$parcel['extra_cover'] = ceil( $values['data']->get_price() );

			for ( $i = 0; $i < $values['quantity']; $i++ ) {
				$requests[] = $parcel;
			}
		}

		return $requests;
	}

	/**
	 * box_shipping function.
	 *
	 * @access private
	 *
	 * @param mixed $package
	 *
	 * @return void
	 */
	private function box_shipping( $package ) {
		$requests = array();

		if ( ! class_exists( 'WC_Boxpack' ) ) {
			include_once 'box-packer/class-wc-boxpack.php';
		}

		$boxpack = new WC_Boxpack();

		// Needed to ensure box packer works correctly.
		$boxes = $this->convert_letter_boxes_to_match_product_metrics( $this->boxes );
		// Define boxes
		if ( $boxes ) {
			foreach ( $boxes as $key => $box ) {

				$newbox = $boxpack->add_box( $box['outer_length'], $box['outer_width'], $box['outer_height'], $box['box_weight'] );

				$newbox->set_id( $key );
				$newbox->set_inner_dimensions( $box['inner_length'], $box['inner_width'], $box['inner_height'] );

				if ( $box['max_weight'] ) {
					$newbox->set_max_weight( $box['max_weight'] );
				}
			}
		}

		// Add items.
		foreach ( $package['contents'] as $item_id => $values ) {

			if ( ! $values['data']->needs_shipping() ) {
				$this->debug( sprintf( __( 'Product #%d is virtual. Skipping.', 'poc-shipping-sendle' ), $values['data']->get_id() ) );
				continue;
			}

			if ( $values['data']->get_length() && $values['data']->get_height() && $values['data']->get_width() && $values['data']->get_weight() ) {

				$dimensions = array( $values['data']->get_length(), $values['data']->get_height(), $values['data']->get_width() );

				for ( $i = 0; $i < $values['quantity']; $i++ ) {

					$boxpack->add_item(
						wc_get_dimension( $dimensions[2], 'cm' ),
						wc_get_dimension( $dimensions[1], 'cm' ),
						wc_get_dimension( $dimensions[0], 'cm' ),
						wc_get_weight( $values['data']->get_weight(), 'kg' ),
						$values['data']->get_price()
					);
				}

			} else {
				$this->debug( sprintf( __( 'Product #%d is missing dimensions. Aborting.', 'poc-shipping-sendle' ), $values['data']->get_id() ), 'error' );

				return;
			}
		}

		// Pack it
		$boxpack->pack();

		// Get packages
		$packages = $boxpack->get_packages();

		foreach ( $packages as $package ) {

			$dimensions = array( $package->length, $package->width, $package->height );

			sort( $dimensions );

			if ( empty( $this->boxes[ $package->id ]['is_letter'] ) ) {
				// $request['height'] = $dimensions[0];
				$request['kilogram_weight'] = $package->weight;
				// $request['width']  = $dimensions[1];
				// $request['length'] = $dimensions[2];
				$request['cubic_metre_volume'] = ($dimensions[0] * $dimensions[1] * $dimensions[2])/(100 * 100 * 100);
			} else {
				// convert values back to what the API expects
				// Changed in $this->convert_letter_boxes_to_match_product_metrics()
				$request['thickness'] = wc_get_dimension( $dimensions[0], 'mm', 'cm' );
				$request['width']     = wc_get_dimension( $dimensions[1], 'mm', 'cm' );
				$request['length']    = wc_get_dimension( $dimensions[2], 'mm', 'cm' );
				$request['weight']    = wc_get_weight( $package->weight, 'g', 'kg' );
			}

			// $request['extra_cover'] = ceil( $package->value );

			$requests[] = $request;
		}

		return $requests;
	}
}
