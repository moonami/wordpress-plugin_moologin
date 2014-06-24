<?php
/*
Plugin Name: Moologin
Description: A plugin that sends the authenticated users details to a moodle site for authentication, enrols them in the specified cohort or course by courseid
Requires: Moodle 2.x site with the MooLogin (Moodle) auth plugin enabled
Version: 1.0
Author: Tõnis Tartes
Author URI: http://t6nis.com
License: GPL2
*/

define( 'MOOLOGIN_PUGIN_NAME', 'MooLogin - Wordpress to Moodle authentication');
define( 'MOOLOGIN_PLUGIN_DIRECTORY', 'moologin');
define( 'MOOLOGIN_CURRENT_VERSION', '1.0' );
define( 'MOOLOGIN_CURRENT_BUILD', '1' );
define( 'EMU2_I18N_DOMAIN', 'moologin' );
define( 'MOOLOGIN_MOODLE_PLUGIN_URL', '/auth/moologin/login.php?data=');

function moologin_set_lang_file() {
    $currentLocale = get_locale();
    if(!empty($currentLocale)) {
        $moFile = dirname(__FILE__) . "/lang/" . $currentLocale . ".mo";
        if (@file_exists($moFile) && is_readable($moFile)) {
            load_textdomain(EMU2_I18N_DOMAIN, $moFile);
        }
    }
}
moologin_set_lang_file();

//shortcodes - register the shortcode this plugin uses and the handler to insert it
add_shortcode('moologin', 'moologin_handler');
// actions - register the plugin itself, it's settings pages and its wordpress hooks
add_action( 'admin_menu', 'moologin_create_menu' );
add_action( 'admin_init', 'moologin_register_settings' );
register_activation_hook(__FILE__, 'moologin_activate');
register_deactivation_hook(__FILE__, 'moologin_deactivate');
register_uninstall_hook(__FILE__, 'moologin_uninstall');
// on page load, init the handlers for the editor to insert the shortcodes (javascript)
add_action('init', 'moologin_add_button');

/**
 * Activating the default values
 */
function moologin_activate() {
    add_option('moologin_moodle_url', 'http://localhost/moodle');
    add_option('moologin_shared_secret', 'enter a random sequence of letters, numbers and symbols here');
}

/**
 * Deactivating requires deleting any options set
 */
function moologin_deactivate() {
    delete_option('moologin_moodle_url');
    delete_option('moologin_shared_secret');
}

/**
 * Uninstall routine
 */
function moologin_uninstall() {
    delete_option('moologin_moodle_url');
    delete_option('moologin_shared_secret');
}

/**
 * Creates a sub menu in the settings menu for the Link2Moodle settings
 */
function moologin_create_menu() {
    add_menu_page( 
    __('moologin', EMU2_I18N_DOMAIN),
    __('Moologin', EMU2_I18N_DOMAIN),
    'administrator',
    MOOLOGIN_PLUGIN_DIRECTORY.'/moologin_settings_page.php',
    '',
    plugins_url('icon.png', __FILE__));
}

/**
 * Registers the settings that this plugin will read and write
 */
function moologin_register_settings() {
    //register settings against a grouping (how wp-admin/options.php works)
    register_setting('moologin-settings-group', 'moologin_moodle_url');
    register_setting('moologin-settings-group', 'moologin_shared_secret');
}

/**
 * Given a string and key, return the encrypted version (hard coded to use rijndael because it's tough)
 */
function encrypt_string($value, $key) { 
    if (!$value) {
        return "";        
    }
    $text = $value;
    $iv_size = mcrypt_get_iv_size(MCRYPT_RIJNDAEL_256, MCRYPT_MODE_ECB);
    $iv = mcrypt_create_iv($iv_size, MCRYPT_RAND);
    $crypttext = mcrypt_encrypt(MCRYPT_RIJNDAEL_256, md5($key.$key), $text, MCRYPT_MODE_ECB, $iv);

    // encode data so that $_GET won't urldecode it and mess up some characters
    $data = base64_encode($crypttext);
    $data = str_replace(array('+','/','='),array('-','_',''),$data);
    return trim($data);
}

/**
 * Handler for decrypting incoming data (specially handled base-64) in which is encoded a string of key=value pairs
 */
function decrypt_string($base64, $key) {
    
    if (!$base64) { 
        return "";
    }
    
    $data = str_replace(array('-','_'),array('+','/'),$base64);
    $mod4 = strlen($data) % 4;
    
    if ($mod4) {
        $data .= substr('====', $mod4);
    }
    
    $crypttext = base64_decode($data);
    $iv_size = mcrypt_get_iv_size(MCRYPT_RIJNDAEL_256, MCRYPT_MODE_ECB);
    $iv = mcrypt_create_iv($iv_size, MCRYPT_RAND);
    $decrypttext = mcrypt_decrypt(MCRYPT_RIJNDAEL_256, md5($key.$key), $crypttext, MCRYPT_MODE_ECB, $iv);
    
    return trim($decrypttext);
}

/**
 * Querystring helper, returns the value of a key in a string formatted in key=value&key=value&key=value pairs, e.g. saved querystrings
 */
function get_key_value($string, $key) {
    $list = explode('&amp;', $string);
    foreach ($list as $pair) {
    	$item = explode( '=', $pair);
            if (strtolower($key) == strtolower($item[0])) {
                return urldecode($item[1]); // Not for use in $_GET etc, which is already decoded, however our encoder uses http_build_query() before encrypting
            }
    }
    return "";
}

/**
 * Handler for the plugins shortcode (e.g. [moologin courseid='test']my link text[/moologin])
 * note: applies do_shortcode() to content to allow other plugins to be handled on links
 * when unauthenticated just returns the inner content (e.g. my link text) without a link
 */
function moologin_handler($atts, $content = null) {
    
    // needs authentication; ensure userinfo globals are populated
    global $current_user;
    get_currentuserinfo();

    // clone attribs over any default values, builds variables out of them so we can use them below
    // $class => css class to put on link we build
    // $cohort => text id of the moodle cohort in which to enrol this user
    // $courseid => course id
    extract(shortcode_atts(array(
        "courseid" => '',
        "cohort" => '',
        "class" => 'moologin',
        "target" => '_self'
    ), $atts));

    if ($content == null || !is_user_logged_in() ) {
        // return just the content when the user is unauthenticated or the tag wasn't set properly
        $url = do_shortcode($content);
    } else {
        // url = moodle_url + "?data=" + <encrypted-value>
        $details = http_build_query(array(
            "a", rand(1, 1500),                             // set first to randomise the encryption when this string is encoded
            "stamp" => time(),                              // unix timestamp so we can check that the link isn't expired
            "firstname" => $current_user->user_firstname,   // first name
            "lastname" => $current_user->user_lastname,     // last name
            "email" => $current_user->user_email,           // email
            "username" => $current_user->user_login,        // username
            "passwordhash" => $current_user->user_pass,     // hash of password (we don't know/care about the raw password)
            "idnumber" => $current_user->ID,                // int id of user in this db (for user matching on services, etc)
            "cohort" => $cohort,			    // string containing cohort to enrol this user into
            "courseid" => $courseid,                        // manual enrol
            "z" => rand(1, 1500),                           // extra randomiser for when this string is encrypted (for variance)
        ));
        $url = '<a target="'.esc_attr($target).'" class="'.esc_attr($class).'" href="'.get_option('moologin_moodle_url').MOOLOGIN_MOODLE_PLUGIN_URL.encrypt_string($details, get_option('moologin_shared_secret')).'">'.do_shortcode($content).'</a>';
    }		
    return $url;
}

/**
 * Initialiser for registering scripts to the rich editor
 */
function moologin_add_button() {
    if (current_user_can('edit_posts') && current_user_can('edit_pages')) {
        add_filter('mce_external_plugins', 'moologin_add_plugin');
        add_filter('mce_buttons', 'moologin_register_button');
    }
}

/*
 * Register editor button
 */
function moologin_register_button($buttons) {
   array_push($buttons,"|","moologin"); // pipe = break on toolbar
   return $buttons;
}

/*
 * Include JS file
 */
function moologin_add_plugin($plugin_array) {
   $plugin_array['moologin'] = plugins_url( 'moologin.js', __FILE__ );
   return $plugin_array;
}

/*
 * XML method init
 */
function xml_add_method($methods) {
    $methods['moologinClient.validateUser'] = 'validate_user_callback';
    $methods['moologinClient.checkUser'] = 'check_user_callback';
    $methods['moologinClient.userInfo'] = 'user_info_callback';
    return $methods;
}
add_filter('xmlrpc_methods', 'xml_add_method');

/*
 * Validate user XML callback
 */
function validate_user_callback($args) {
    global $wpdb;
    
    $wpxmlrpc = new wp_xmlrpc_server();
    $wpxmlrpc->escape($args);
    
    if (count($args) > 1) {
        return false;
    }
    
    // get the data that was passed in
    $userdata = decrypt_string($args, get_option('moologin_shared_secret'));
    
    $username = trim(strtolower(get_key_value($userdata, "wpusername")));
    $password = get_key_value($userdata, "wppassword");
    $musername = trim(strtolower(get_key_value($userdata, "username")));
    $mpassword = get_key_value($userdata, "password");

    if (!$user = $wpxmlrpc->login($username, $password)) {
        return false;
    }
    if (!current_user_can('edit_users')) {
        return new IXR_Error(403,__('You are not allowed access to this function.'));
    }

    if (isset($musername) && isset($mpassword)) {        
        $hashedpassword = $wpdb->get_row('SELECT ID, user_pass FROM '.$wpdb->users.' WHERE user_login = "'.$musername.'" AND user_status = 0');    
        return wp_check_password($mpassword, $hashedpassword->user_pass, $hashedpassword->ID);
    } else {
        return false;
    }  
}

/*
 * Check user XML callback
 */
function check_user_callback($args) {    
    global $wpdb;
    
    $wpxmlrpc = new wp_xmlrpc_server();
    $wpxmlrpc->escape($args);

    if (count($args) > 1) {
        return false;
    }
    
    // get the data that was passed in
    $userdata = decrypt_string($args, get_option('moologin_shared_secret'));
    
    $username = trim(strtolower(get_key_value($userdata, "wpusername")));
    $password = get_key_value($userdata, "wppassword");
    $musername = trim(strtolower(get_key_value($userdata, "username")));
    
    if (!$user = $wpxmlrpc->login($username, $password)) {
        return false;
    }
    if (!current_user_can('edit_users')) {
        return new IXR_Error(403,__('You are not allowed access to this function.'));
    }
  
    if (isset($musername)) {
        $hashedpassword = $wpdb->get_row('SELECT user_pass FROM '.$wpdb->users.' WHERE user_login = "'.$musername.'" AND user_status = 0');
        if (!empty($hashedpassword->user_pass)) {
            return true;
        } else {
            return false;
        }
    } else {
        return false;
    }
}

/*
 * Get user info XML callback
 */
function user_info_callback($args) {    
    global $wpdb;
    
    $wpxmlrpc = new wp_xmlrpc_server();
    $wpxmlrpc->escape($args);

    if (count($args) > 1) {
        return false;
    }
    
    // get the data that was passed in
    $userdata = decrypt_string($args, get_option('moologin_shared_secret'));
    
    $username = trim(strtolower(get_key_value($userdata, "wpusername")));
    $password = get_key_value($userdata, "wppassword");
    $musername = trim(strtolower(get_key_value($userdata, "username")));
    
    if (!$user = $wpxmlrpc->login($username, $password)) {
        return false;
    }
    if (!current_user_can('edit_users')) {
        return new IXR_Error(403,__('You are not allowed access to this function.'));
    }
    
    if (isset($musername)) {
        $userinfo = $wpdb->get_row('SELECT ID FROM '.$wpdb->users.' WHERE user_login = "'.$musername.'" AND user_status = 0');
        if (!empty($userinfo->ID)) {
            $user_data = get_userdata($userinfo->ID);                
            if (!$user_data) {
                return new IXR_Error(404,__('Invalid user ID'));
            }
            $user_data = array(
                $user_data->ID,
                $user_data-> user_firstname, 
                $user_data-> user_lastname, 
                $user_data->user_email
            );                
            return $user_data;                
        } else {
            return false;
        }
    } else {
        return false;
    }
}