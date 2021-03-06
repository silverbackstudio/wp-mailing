<?php

/**
 * @package Silverback Email Services
 * @version 1.1
 */

/**
* Plugin Name: Silverback Email Services
* Plugin URI: https://github.com/silverbackstudio/wp-email
* Description: Send Wordpress emails through Email Services API with templates
* Author: Silverback Studio
* Version: 2.2.1
* Author URI: http://www.silverbackstudio.it/
* Text Domain: svbk-email
*/

use Svbk\WP\Email;

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

function svbk_email_init() {
	load_plugin_textdomain( 'svbk-email', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	Email\Wordpress::trackMessages();
}
add_action( 'muplugins_loaded', 'svbk_email_init' );

$providerInstance = svbk_email_get_provider();

if ( $providerInstance && !function_exists( 'wp_mail' ) ) {

	function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
		$options = get_option( 'svbk_email_options' );

	    $wp_email = new Email\Wordpress();
		$message = $wp_email->message( $to, $subject, $message, $headers = '', $attachments = array() );

		$email_id = Email\Wordpress::get_last_email_id();

        $providerInstance = svbk_email_get_provider();

        $template_key = 'template_' . $providerInstance::SERVICE_NAME . '_' . $email_id;
        $template = empty( $options[$template_key] ) ? false : $options[$template_key];
		$template = apply_filters( 'svbk_email_template', $template );

		$message_id = false;

		try {
			if ( $template ) {
				$message->subject = '';
				$message_id = $providerInstance->sendTemplate( $template, $message, Email\Wordpress::$last_email_data ) ;
			} else {
				$message_id = $providerInstance->send( $message );
			}
		} catch( Exception $e ) {
			do_action(
				'log', 'error', 'SVBK wp-email send error',
				array(
					'error' => $e->getMessage(),
				)
			);
			$message_id = false;
		}
		
		return $message_id ? true : false;
	}
}

function svbk_email_get_templates(){
	
    $result = wp_cache_get( 'svbk_email_templates' );

    if ( false === $result ) {
		$provider = svbk_email_get_provider();
		try {
    		$result = $provider->getTemplates();
    		wp_cache_set( 'svbk_email_templates', $result, null, 5 * MINUTE_IN_SECONDS );			
		} catch( \Exception $e) {
			return false;
		}

    } 

	return $result;
}

/**
 * top level menu
 */
function svbk_email_options_page() {
	// add top level menu page
	add_submenu_page(
	    //parent_slug
	    'options-general.php',
	    //page_title
		'Email Settings',
		//menu title
		'Email Settings',
		//capability
		'manage_options',
		//menu page slug
		'svbk-email',
		//function
		'svbk_email_options_page_html'
	);
}

/**
 * register our wporg_options_page to the admin_menu action hook
 */
add_action( 'admin_menu', 'svbk_email_options_page' );

/**
 * @internal never define functions inside callbacks.
 * these functions could be run multiple times; this would result in a fatal error.
 */

/**
 * Return selected provider 
 * 
 * @return Email\Transactional\ServiceInterface|null 
 */
function svbk_email_get_provider() {
	$options = get_option( 'svbk_email_options' );
	$provider = empty($options['provider']) ? '' : $options['provider'];

	$providerInstance  = null;
	try {

		switch( $provider ){
			default:
				$providerClass = null;
				break;		
			case Email\Transactional\SendInBlue::SERVICE_NAME:
				$providerInstance = new Email\Transactional\SendInBlue( !empty($options['provider_apikey']) ? $options['provider_apikey'] : null );
				break;
			case Email\Transactional\Mandrill::SERVICE_NAME:
				$providerInstance = new  Email\Transactional\Mandrill( $options['provider_apikey'] );
				break;      
		}

	} catch ( Exception $e ) {
		$providerInstance = null;
		add_action( 'admin_notices', 'svbk_email_admin_notice__invalid_apikey' );
	}	

	if ( !$providerInstance ) {
		add_action( 'admin_notices', 'svbk_email_admin_notice__core_email' );
	}		

	return $providerInstance;
}

function svbk_email_admin_notice__invalid_apikey() {
    ?>
    <div class="notice notice-warning">
        <p>
			<?php _e( 'No email keys configured or invalid configuration. No email will be sent.', 'svbk-email' ); ?>
			<a href="<?php esc_attr(menu_page_url('svbk-email')); ?>" ><?php _e('Settings', 'svbk-email') ?></a>
		</p>
    </div>
    <?php
}

function svbk_email_admin_notice__core_email() {
    ?>
    <div class="notice notice-warning is-dismissible">
        <p>
			<?php _e( 'There is no email provider configured, using server "mail()" function. Please check if email are working.', 'svbk-email' ); ?>
			<a href="<?php esc_attr(menu_page_url('svbk-email')); ?>" ><?php _e('Settings', 'svbk-email') ?></a>
		</p>
    </div>
    <?php
}


/**
 * custom option and settings
 */
function svbk_email_settings_init() {

    $options = get_option( 'svbk_email_options' );

	 // register a new setting for "svbk-email" page
	 register_setting( 
	     //option group
	     'svbk-email',
	     //option name
	     'svbk_email_options' 
	 );

	 // register a new section in the "svbk-email" page
	add_settings_section(
	    // id
		'svbk_email_section_general', 
		//title
		__( 'General Settings', 'svbk-email' ),
		 //callback
		'svbk_email_section_general_cb',
		 //page
		'svbk-email'
	);
	

	add_settings_field(
	    //id. As of WP 4.6 this value is used only internally, use $args' label_for to populate the id inside the callback
		'svbk_email_from', 
		//title
		 __( 'From', 'svbk-email' ),
		 //callback
		'svbk_email_field_from_cb',
		//page
		'svbk-email',
		//section
		'svbk_email_section_general',
		[
			'label_for' => 'from',
			'class' => 'svbk_email_row',
		]
	);

	add_settings_field(
	    //id. As of WP 4.6 this value is used only internally, use $args' label_for to populate the id inside the callback
		'svbk_email_provider', 
		//title
		 __( 'Email Provider', 'svbk-email' ),
		 //callback
		'svbk_email_field_provider_cb',
		//page
		'svbk-email',
		//section
		'svbk_email_section_general',
		[
			'label_for' => 'provider',
			'class' => 'svbk_email_row',
		]
	);

    $trackedMessages = Email\Wordpress::trackedMessages();
	
	$provider = svbk_email_get_provider();

	add_settings_field(
		//id. As of WP 4.6 this value is used only internally, use $args' label_for to populate the id inside the callback
		'svbk_email_provider_apikey', 
		//title
		__( 'Provider Api Key', 'svbk-email' ),
		//callback
		'svbk_email_field_provider_apikey_cb',
		//page
		'svbk-email',
		//section
		'svbk_email_section_general',
		[
			'label_for' => 'provider_apikey',
			'class' => 'svbk_email_row',
		]
	);	
	

	if ( $provider ) {
	  
		// register a new section in the "svbk-email" page
    	add_settings_section(
    	    // id
    		'svbk_email_section_templates', 
    		//title
    		__( 'Templates', 'svbk-email' ),
    		 //callback
    		'svbk_email_section_templates_cb',
    		 //page
    		'svbk-email'
    	);		    
	  
	
    	add_settings_field(
    	    //id. As of WP 4.6 this value is used only internally, use $args' label_for to populate the id inside the callback
    		'svbk_email_default_template', 
    		//title
    		 __( 'Default Template', 'svbk-email' ),
    		 //callback
    		'svbk_email_field_select_template_cb',
    		//page
    		'svbk-email',
    		//section
    		'svbk_email_section_templates',
    		[
    			'label_for' => 'default_' . $provider::SERVICE_NAME . '_template',
    			'class' => 'svbk_email_row',
    		]
    	);		  
	    
    	foreach( $trackedMessages as $trackedMessage => $trackedMessageLabel ) { 
        	add_settings_field(
        	    //id. As of WP 4.6 this value is used only internally, use $args' label_for to populate the id inside the callback
        		'svbk_email_template_' . $trackedMessage, 
        		//title
        		 $trackedMessageLabel,
        		 //callback
        		'svbk_email_field_select_template_cb',
        		//page
        		'svbk-email',
        		//section
        		'svbk_email_section_templates',
        		[
        			'label_for' => 'template_' . $provider::SERVICE_NAME . '_'. $trackedMessage,
        			'class' => 'svbk_email_row',
        		]
        	);		
    	}
	}
}

/**
 * register our wporg_settings_init to the admin_init action hook
 */
add_action( 'admin_init', 'svbk_email_settings_init' );

/**
 * custom option and settings:
 * callback functions
 */

function svbk_email_section_general_cb( $args ) { ?>
     <p id="<?php echo esc_attr( $args['id'] ); ?>"><?php esc_html_e( 'Choose wich provider you want to use to send emails', 'svbk-email' ); ?></p>
	<?php
}

function svbk_email_section_templates_cb( $args ) { ?>
     <p id="<?php echo esc_attr( $args['id'] ); ?>"><?php esc_html_e( 'Choose which template should be used', 'svbk-email' ); ?></p>
	<?php
}


function svbk_email_field_provider_cb( $args ) {
	// get the value of the setting we've registered with register_setting()
	$options = get_option( 'svbk_email_options' );

	// output the field
	?>
     <select id="<?php echo esc_attr( $args['label_for'] ); ?>"
     name="svbk_email_options[<?php echo esc_attr( $args['label_for'] ); ?>]"
     >
         <option value="" <?php echo isset( $options[ $args['label_for'] ] ) ? ( selected( $options[ $args['label_for'] ], '', false ) ) : ( '' ); ?>>
        	<?php esc_html_e( '-- Core --', 'svbk-email' ); ?>
         </option>
         <option value="sendinblue" <?php echo isset( $options[ $args['label_for'] ] ) ? ( selected( $options[ $args['label_for'] ], 'sendinblue', false ) ) : ( '' ); ?>>
        	<?php esc_html_e( 'Sendinblue', 'svbk-email' ); ?>
         </option>
         <option value="mandrill" <?php echo isset( $options[ $args['label_for'] ] ) ? ( selected( $options[ $args['label_for'] ], 'mandrill', false ) ) : ( '' ); ?>>
        	<?php esc_html_e( 'Mandrill', 'svbk-email' ); ?>
         </option>
     </select>
	<?php
}

function svbk_email_field_provider_apikey_cb( $args ) {
	// get the value of the setting we've registered with register_setting()
	$options = get_option( 'svbk_email_options' );
	$provider = svbk_email_get_provider();
	$value =  !empty($options[ $args['label_for'] ]) ? $options[ $args['label_for'] ] : '';
	// output the field
	?>
     <input id="<?php echo esc_attr( $args['label_for'] ); ?>"
		name="svbk_email_options[<?php echo esc_attr( $args['label_for'] ); ?>]"
		type="password"
		value="<?php esc_attr_e($value); ?>"
		size="100"
     />
	 <p><?php _e('Warning: This api key will be used if no system-wide api-key is defined') ?></p>
	<?php
}

function svbk_email_field_from_cb( $args ) {
	// get the value of the setting we've registered with register_setting()
	$options = get_option( 'svbk_email_options' );
	$value =  !empty($options[ $args['label_for'] ]) ? $options[ $args['label_for'] ] : '';
	$value_name =  !empty($options[ $args['label_for'] . '_name' ]) ? $options[ $args['label_for'] . '_name' ] : '';
	// output the field
	?>
     <input id="<?php echo esc_attr( $args['label_for'] ); ?>_name"
		name="svbk_email_options[<?php echo esc_attr( $args['label_for'] ); ?>_name]"
		placeholder="<?php _e('Enter from name..', 'svbk-email') ?>"
		type="text"
		value="<?php esc_attr_e($value_name); ?>"
     />	 	
     <input id="<?php echo esc_attr( $args['label_for'] ); ?>"
		name="svbk_email_options[<?php echo esc_attr( $args['label_for'] ); ?>]"
		placeholder="<?php _e('Enter from email..', 'svbk-email') ?>"
		type="text"
		value="<?php esc_attr_e($value); ?>"
     />
	<?php
}

function svbk_email_field_select_template_cb( $args ) {
	// get the value of the setting we've registered with register_setting()
	$options = get_option( 'svbk_email_options' );
	// output the field

    $templates = svbk_email_get_templates();

	if ($templates === false){
		echo '<p>Invalid API key</p>';
		return;
	}

	?>
     <select id="<?php echo esc_attr( $args['label_for'] ); ?>"
     name="svbk_email_options[<?php echo esc_attr( $args['label_for'] ); ?>]"
     >
         <option value="" <?php echo isset( $options[ $args['label_for'] ] ) ? ( selected( $options[ $args['label_for'] ], '', false ) ) : ( '' ); ?>>
        	<?php esc_html_e( '-- No Template --', 'svbk-email' ); ?>
         </option>         
         <?php foreach( $templates as $template_id => $template_name ) : ?>
         <option value="<?php esc_attr_e( $template_id ) ?>" <?php echo isset( $options[ $args['label_for'] ] ) ? ( selected( $options[ $args['label_for'] ], $template_id, false ) ) : ( '' ); ?>>
        	<?php echo esc_html( $template_name ); ?>
         </option>
         <?php endforeach; ?>
     </select>
	<?php
}

function svbk_email_options_page_html() {
	// check user capabilities
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// add error/update messages
	// check if the user have submitted the settings
	// wordpress will add the "settings-updated" $_GET parameter to the url
	if ( isset( $_GET['settings-updated'] ) ) {
		// add settings saved message with the class of "updated"
		add_settings_error( 'svbk_email_messages', 'svbk_email_message', __( 'Settings Saved', 'svbk-email' ), 'updated' );
	}

	// show error/update messages
	settings_errors( 'svbk_email_messages' ); ?>
     <div class="wrap">
         <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
         <form action="options.php" method="post">
        	<?php
        	// output security fields for the registered setting "wporg"
        	settings_fields( 'svbk-email' );
        	// output setting sections and their fields
        	// (sections are registered for "wporg", each field is registered to a specific section)
        	do_settings_sections( 'svbk-email' );
        	// output save settings button
        	submit_button( 'Save Settings' );
        	?>
         </form>
     </div>
	<?php
}

function svbk_email_from_address( $from = '' ){
	$options = get_option( 'svbk_email_options' );

	if ( empty( $options['from'] ) ) {
		return $from;
	}

	return $options['from'];
}

add_filter('wp_mail_from', 'svbk_email_from_address');


function svbk_email_from_name( $from = '' ){
	$options = get_option( 'svbk_email_options' );

	if ( empty( $options['from_name'] ) ) {
		return $from;
	}

	return $options['from_name'];
}

add_filter('wp_mail_from_name', 'svbk_email_from_name');