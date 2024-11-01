<?php
/*
	Plugin Name: TapSocial
	Plugin URI: http://tapsocial.net
	Description: TapSocial for Wordpress - Our mission has been to create a simple yet fully customizable Twitter and Facebook plugin for WordPress which allows anyone, no matter how tech or design savvy, to add a great looking feed right on their WordPress site.
	Author: TapSocial
	Version: 1.0.2
	Author URI: http://tapsocial.net
 */
define("TS_URL", "http://www.tapsocial.net/social-authorize/index.php");
define("TS_URL_TWITTER", "http://www.tapsocial.net/social-authorize/twitter.php");
define("TS_URL_FACEBOOK", "http://www.tapsocial.net/social-authorize/facebook.php");

class tapsocial {
	public $uuid;	//site unique ID
	public $secret;	//site unique ID
	public $update_twitter;		//twitter update interval
	public $update_facebook;	//facebook update interval
	
	public $license_key;
	
	public function __construct() {
		//$this->init();
		if ($this->is_session_started() === false) {
			session_start();
		}		
		if(is_admin()){
			$this->createDB();
			$this->save_settings();
		}
		add_action('admin_menu', array( $this, 'admin_menu_init' ) );
		add_action('admin_notices', array($this, 'admin_notice'));
		add_action('admin_init', array($this, 'admin_notice_hide'));
		add_action('init', array($this, 'init'));
		add_action('template_redirect', array($this, "callback")); //AJAX Handler
		
		add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
		
		add_shortcode('tapsocial', array($this, 'shortcode_tapsocial'));
		
		add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_action_links' ));
	}
	
	
	/*
	 * Check for session start in all versions of PHP
	 */
	private function is_session_started(){
		if ( php_sapi_name() !== 'cli' ) {
			if ( version_compare(phpversion(), '5.4.0', '>=') ) {
				return session_status() === PHP_SESSION_ACTIVE ? TRUE : FALSE;
			} else {
				return session_id() === '' ? FALSE : TRUE;
			}
		}
		return FALSE;
	}
	
	function add_action_links ( $links ) {
		$mylinks = array(
		'<a href="' . admin_url( 'options-general.php?page=tapsocial' ) . '">Settings &amp; Docs</a>',
		);
		return array_merge( $links, $mylinks );
	}
	
	public function init(){
		
		$this->uuid = get_option("_tapsocial_uuid");
		if(!$this->uuid){
			update_option("_tapsocial_uuid", $this->guid());
			$this->uuid = get_option("_tapsocial_uuid");
		}
		
		$this->secret = get_option("_tapsocial_secret");
		if(!$this->secret){
			$resp = json_decode(file_get_contents(TS_URL."?ts_uuid=".$this->uuid."&ts_register=1&callback_url=".$this->callback_url()));
			update_option("_tapsocial_secret", $resp->secret);
			$this->secret = get_option("_tapsocial_secret");
		}
		
		$this->license_key = get_option("_ts_license_key");
		
		$this->update_twitter = get_option("_tapsocial_twitter_update");
		if(!$this->update_twitter){
			update_option("_tapsocial_twitter_update", "2");
			$this->update_twitter = get_option("_tapsocial_twitter_update");
		}
		
		$this->update_facebook = get_option("_tapsocial_facebook_update");
		if(!$this->update_facebook){
			update_option("_tapsocial_facebook_update", "2");
			$this->update_feacbook = get_option("_tapsocial_facebook_update");
		}
		if(get_option("_ts_insert_location")){
			add_action('wp_footer', array($this, 'inject'));
		}
		
		add_image_size( "ts-thumb-150x150", 0, 150, false );
		
		$this->tapsocial_posts(); //create custom post type for TapSocial custom messages
	}
	
	public function tapsocial_posts() {
		register_post_type( 'tapsocial_message',
			array(
				'labels' => array(
					'name' => 'TapSocial Messages',
					'singular_name' => 'TapSocial Message'
				),
				'public' => true,
				'publicly_queryable' => false,
				'exclude_from_search' => true,
				'has_archive' => false,
				'menu_position' => 5,
				'supports' => array('editor', 'thumbnail', 'revisions', 'title')
			)
		);
	}
	
	public function enqueue_scripts(){
		wp_enqueue_style("ts-styles", plugins_url( '/style.css', __FILE__ ));
		wp_enqueue_style("font-awesome", "//maxcdn.bootstrapcdn.com/font-awesome/4.2.0/css/font-awesome.min.css");
		
		
		wp_enqueue_script("twitter-widgets", "//platform.twitter.com/widgets.js");
		wp_enqueue_script("ts-scroller", plugins_url( '/jquery.simplescroll.min.js', __FILE__ ), array("jquery"));
		
	}
	
	public function admin_menu_init(){
		add_options_page( "TapSocial", "TapSocial", "manage_options", "tapsocial", array($this, "admin_menu_page"));
	}
	
	public function createDB(){
		global $wpdb;
		$query = "CREATE TABLE IF NOT EXISTS `".$wpdb->prefix."tapsocial_cache` (
			`id` int(15) NOT NULL AUTO_INCREMENT,
			`key` varchar(100) NOT NULL,
			`data` mediumtext,
			`expires` datetime NOT NULL,
			PRIMARY KEY (`id`,`key`,`expires`)
		  )";
		$wpdb->query($query);
	}
	
	public static function callback_url(){
		return base64_encode(site_url());
	}
	
	
	public static function cache_get($query){ 
		global $wpdb;
		$key = md5($query);
		$sql = "SELECT
				`data`
			FROM
				`".$wpdb->prefix."tapsocial_cache`
			WHERE
				`key` = %s AND
				`expires` >= NOW()";
		$data = $wpdb->get_var($wpdb->prepare($sql, array($key)));
		error_log($wpdb->last_error);
		if($data == NULL){
			return false;
		}
		else{
			return $data;
		}
		
	}
	public static function cache_set($query, $data, $expires = 2) { //expires in minutes
		global $wpdb;
		$key = md5($query);
		$wpdb->query($wpdb->prepare("DELETE FROM `".$wpdb->prefix."tapsocial_cache` WHERE `key` = %s", array($key)));
		return $wpdb->query($wpdb->prepare("INSERT
							INTO
								`".$wpdb->prefix."tapsocial_cache`
							SET
								`key` = %s, 
								`data` = %s,
								`expires` = NOW() + INTERVAL ".(int)$expires." MINUTE", array($key, $data)));
	}
	static public function get_the_post_thumbnail_src($img){
		return (preg_match('~\bsrc="([^"]++)"~', $img, $matches)) ? $matches[1] : '';
	}
	private function check_license($return = false) {
		error_log("Check License");
		$store_url = 'http://tapsocial.net';
		$item_name = 'TapSocial for WordPress';
		$license = get_option('_ts_license_key');

		$api_params = array( 
			'edd_action' => 'check_license', 
			'license' => $license, 
			'item_name' => urlencode( $item_name ),
			'url' => $this->uuid
		);

		$response = wp_remote_get( add_query_arg( $api_params, $store_url ), array( 'timeout' => 15, 'sslverify' => false ) );
		
		if ( is_wp_error( $response ) )
			return false;
		
		$license_data = json_decode( wp_remote_retrieve_body( $response ) );
		
		if($license_data->license == "inactive" || $license_data->license == "site_inactive"){ //Try to activate license
			$api_params = array( 
				'edd_action' => 'activate_license', 
				'license' => $license, 
				'item_name' => urlencode( $item_name ),
				'url' => $this->uuid
			);
			$activation_response = wp_remote_get( add_query_arg( $api_params, $store_url ), array( 'timeout' => 15, 'sslverify' => false ) );
			//print_r($activation_response);
			if ( is_wp_error( $activation_response ) )
				return false;
			$license_data = json_decode( wp_remote_retrieve_body( $activation_response ) );
		}
		
		if($return) {
			return $license_data;
		}
		
		if( $license_data->license == 'valid' ) {
			error_log("Valid License");
			return true;
		} else {
			error_log("Invalid License");
			return false;
		}
	}
	
	private function add_license(){
		$url = TS_URL."?ts_add_license=1&ts_secret=".get_option("_tapsocial_secret")."&ts_uuid=".get_option("_tapsocial_uuid")."&ts_license_key=".get_option('_ts_license_key');
		$ch = curl_init($url);
		
		curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
		$resp=curl_exec($ch);

		curl_close($ch);
		$resp=json_decode($resp);
		
		return $resp;
		
	}
	
	
	public function admin_menu_page(){
		if ( !current_user_can( 'manage_options' ) )  {
			wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
		}
		?>
		<script>
			jQuery(function($){
				$(".ts-tab").hide();
				$(".ts-tab:first").show();
				$(".nav-tab-wrapper a").on("click", function(e){
					e.preventDefault();
					$(".ts-tab").hide();
					$($(this).attr("href")).show();
					$(".nav-tab").removeClass("nav-tab-active");
					$(this).addClass("nav-tab-active");
				});
			});
		</script>
		<div class="wrap">
			<form method="post">
				<input type="hidden" name="ts-save" value="save" />
				<h1>TapSocial for Wordpress: Settings</h1>
				<p>TapSocial for Wordpress is the easiest way to put a scrolling feed for Twitter and Facebook on your Wordpress web site. Simply connect your Twitter or Facebook account and a few simple settings and you're ready to go. No need to setup an application as a developer on Twitter - we've taken care of all of that for you.</p>
				
				<h2 class="nav-tab-wrapper">
					<a href="#ts-tab-settings" class="nav-tab nav-tab-active">Settings</a>
					<a href="#ts-tab-custom" class="nav-tab">Custom Messages</a>
					<a href="#ts-tab-insert" class="nav-tab">Insert into Theme</a>
					<a href="#ts-tab-shortcode" class="nav-tab">Shortcode Help</a>
					<a href="#ts-tab-function" class="nav-tab">Function Help</a>
				</h2>
				<div id="ts-tab-settings" class="ts-tab">
					<div class="postbox">
						<div class="inside">
							<h3>Plugin Licensing Details</h3>
							<div class="ts-row uuid">
								<label>Site ID:</label><br/>
								<input class="ts-uuid" value="<?php echo $this->uuid; ?>" disabled="disabled" />
							</div>
							<div class="ts-row uuid">
								<label>Site License Key:</label><br/>
								<input class="ts-uuid" value="<?php echo get_option("_ts_license_key"); ?>" name="ts_license_key" /> Enter your license key to activate plugin<br/>
								(<a href="http://tapsocial.net/wordpress-plugin/?ts_uuid=<?php echo $this->uuid; ?>">Purchase a License</a>)
							</div>
							<div class="ts-row">
								<?php 
								$license = $this->check_license(true);
								//print_r($license); 
								?>
								<label>License Status: </label><?php echo $license->license; ?><br/>
								<label>Item Name: </label><?php echo $license->item_name; ?><br/>
								<label>Expires: </label><?php echo $license->expires; ?><br/>
								<label>Payment ID: </label><?php echo $license->payment_id; ?><br/>
								<label>Customer Name: </label><?php echo $license->customer_name; ?><br/>
								<label>Customer Email: </label><?php echo $license->customer_email; ?><br/>
							</div>
							<hr />
							<h3>Social Connection Details</h3>
							<div class="ts-row twitter">
								<div class="ts-button">
									<a href="<?php echo TS_URL; ?>?ts_uuid=<?php echo $this->uuid; ?>&callback=<?php $this->callback_url(); ?>&ts_twitter=twitter_login">
										<?php
										$request = TS_URL_TWITTER."?ts_uuid=".get_option("_tapsocial_uuid")."&callback=".$this->callback_url()."&action=account/verify_credentials";
										$data = file_get_contents($request);
										$data = json_decode($data);
										$data = $data->data;
										//print_r($data);
										if($data->id){
											?>
										Twitter connected to <strong><?php echo $data->screen_name; ?></strong>, click to reconnect.
											<?php	
										}
										else{
										?>
										<img src="<?php echo  plugins_url( 'images/twitter.gif' , __FILE__ ); ?>" title="Connect to Twitter"/>
										<?php } ?>
									</a>
								</div>
								<div class="">
									How often do you want to try to get the latest tweets? 
									<select name="ts_twitter_refresh">
										<option value="1" <?php if($this->update_twitter == 1) echo "SELECTED"; ?> >1 minute</option>
										<option value="2" <?php if($this->update_twitter == 2) echo "SELECTED"; ?> >2 minutes (Default)</option>
										<option value="5" <?php if($this->update_twitter == 5) echo "SELECTED"; ?> >5 minutes</option>
										<option value="10" <?php if($this->update_twitter == 10) echo "SELECTED"; ?> >10 minutes</option>
										<option value="30" <?php if($this->update_twitter == 30) echo "SELECTED"; ?> >30 minutes</option>
										<option value="60" <?php if($this->update_twitter == 60) echo "SELECTED"; ?> >60 minutes</option>
									</select>
								</div>
							</div>
							<div class="ts-row facebook">
								<div class="ts-button">
									<?php
									//echo get_option("_ts_facebook_oauth_token");
									//echo get_option("_ts_facebook_user_id");
									?>
									<a href="<?php echo TS_URL; ?>?ts_uuid=<?php echo $this->uuid; ?>&callback=<?php echo $this->callback_url(); ?>&ts_facebook=facebook_login">

										<?php
										$request = TS_URL_FACEBOOK."?ts_uuid=".get_option("_tapsocial_uuid")."&callback=".$this->callback_url()."&action=verify";
										$data = file_get_contents($request);
										$data = json_decode($data);
										//print_r($data);
										if($data->verify->is_valid){
											update_option("_ts_facebook_user_id", $data->user->id);

											?>
										Facebook connected to <strong><?php echo $data->user->first_name; ?> <?php echo $data->user->last_name; ?></strong>, click to reconnect.
											<?php	
										}
										else{
										?>
										<img src="<?php echo  plugins_url( 'images/facebook.png' , __FILE__ ); ?>" title="Connect to Twitter" style="height: 23px;"/>
										<?php } ?>
									</a>
								</div>
								<div class="">
									How often do you want to try to get the latest posts? 
									<select name="ts_facebook_refresh">
										<option value="1" <?php if($this->update_facebook == 1) echo "SELECTED"; ?> >1 minute</option>
										<option value="2" <?php if($this->update_facebook  == 2) echo "SELECTED"; ?> >2 minutes (Default)</option>
										<option value="5" <?php if($this->update_facebook  == 5) echo "SELECTED"; ?> >5 minutes</option>
										<option value="10" <?php if($this->update_facebook  == 10) echo "SELECTED"; ?> >10 minutes</option>
										<option value="30" <?php if($this->update_facebook  == 30) echo "SELECTED"; ?> >30 minutes</option>
										<option value="60" <?php if($this->update_facebook  == 60) echo "SELECTED"; ?> >60 minutes</option>
									</select>
								</div>
							</div>
							<div class="ts-row">
								Once you've connected to Twitter/Facebook, go to the <a href="<?php echo get_admin_url("", "widgets.php"); ?>">Appearance->Widgets</a> to select where you want TapSocial for Wordpress to display, as well as customize. You may also use the settings below to insert the TapSocial bar into your theme.  This may not be supported by some themes.  You can also use theme functions or shortcodes as described below.

							</div>
							<div class="ts-row">
								<p style="text-align: right"><input type="submit" class="button-primary" value="Save All Settings" /></p>
							</div>
						</div>
					</div>
				</div>
				<div id="ts-tab-custom" class="ts-tab">
					<div class="postbox">
						<div class="inside">
							<h3>Custom Messages</h3>
							<p>Custom messages can be added to your TapSocial ticker. Custom messages are created as posts under the "TapSocial Messages" tab in the left hand menu. <a href="<?php echo get_admin_url("", "edit.php?post_type=tapsocial_message"); ?>">Go there now!</a></p>
							
						</div>
					</div>
				</div>
							
				<div id="ts-tab-insert" class="ts-tab">
					<div class="postbox">
						<div class="inside">
							<div class="ts-row">	
								<?php $insert_location = get_option("_ts_insert_location"); ?>
								<label for="ts_insert_location">Location:</label> 
								<select name="ts_insert_location">
									<option value="">Do Not Show</option>
									<option value="top" <?php if($insert_location == "top") echo "SELECTED"; ?>>Top of page</option>
									<option value="bottom" <?php if($insert_location == "bottom") echo "SELECTED"; ?>>Bottom of page</option>
									<option value="header" <?php if($insert_location == "header") echo "SELECTED"; ?>>In Header</option>
									<option value="footer" <?php if($insert_location == "footer") echo "SELECTED"; ?>>In Footer</option>
								</select>
								(some options may not work with all themes)
							</div>
							<div class="ts-row">
								<?php $insert_hide_twitter = get_option("_ts_insert_hide_twitter"); ?>
								<input type="checkbox" value="1" name="ts_insert_hide_twitter" <?php if($insert_hide_twitter) echo "CHECKED"; ?> /> Hide Twitter Feed
							</div>
							<div class="ts-row">
								<?php $insert_hide_facebook = get_option("_ts_insert_hide_facebook"); ?>
								<input type="checkbox" value="1" name="ts_insert_hide_facebook" <?php if($insert_hide_facebook) echo "CHECKED"; ?> /> Hide Facebook Feed
							</div>
							<div class="ts-row">
								<?php $insert_hide_custom = get_option("_ts_insert_hide_custom"); ?>
								<input type="checkbox" value="1" name="ts_insert_hide_custom" <?php if($insert_hide_custom) echo "CHECKED"; ?> /> Hide Custom Feed
							</div>
							<div class="ts-row">
								<?php $insert_speed = get_option("_ts_insert_speed"); ?>
								<label for="ts_insert_speed">Speed:</label> 
								<select id="ts_insert_speed" name="ts_insert_speed" >
									<option value="1" <?php if($insert_speed == 1) echo "SELECTED"; ?>>Slow</option>
									<option value="2" <?php if($insert_speed == 2) echo "SELECTED"; ?>>Normal (default)</option>
									<option value="3" <?php if($insert_speed == 3) echo "SELECTED"; ?>>Fast</option>
								</select>
							</div>

							<div class="ts-row">
								<?php $insert_style = get_option("_ts_insert_style"); ?>
								<label for="ts_insert_style">Style:</label> 
								<select id="ts_insert_style" name="ts_insert_style" >
									<option value="inherit" <?php if($insert_style == "inherit") echo "SELECTED"; ?>>Inherit</option>
									<option value="dark" <?php if($insert_style == "dark") echo "SELECTED"; ?>>Dark on light</option>
									<option value="light" <?php if($insert_style == "light") echo "SELECTED"; ?>>Light on dark</option>
								</select>
							</div>

							<div class="ts-row">
								<?php 
									$insert_size = get_option("_ts_insert_size"); 
									if(!$insert_size) $insert_size = 10;
								?>
								<label for="ts_insert_size">Overall Size (default 10):</label> 
								<input name="ts_insert_size" id="ts_insert_size" value="<?php echo $insert_size; ?>"/>
							</div>

							<div class="ts-row">
								<?php 
									$insert_height = get_option("_ts_insert_height"); 
									if(!$insert_height) $insert_height = 200;
								?>
								<label for="ts_insert_height">Vertical Height (in px):</label> 
								<input name="ts_insert_height" id="ts_insert_height" value="<?php echo $insert_height; ?>"/>
							</div>

							<div class="ts-row">
								<?php 
									$insert_width = get_option("_ts_insert_width"); 
									if(!$insert_width) $insert_width = "auto";
								?>
								<label for="ts_insert_width">Vertical Width (in px):</label> 
								<input name="ts_insert_width" id="ts_insert_width" value="<?php echo $insert_width; ?>"/>
							</div>

							<hr/>
							<h4>Twitter</h4>
							<div style='margin-left: 1em;'>
								<?php $twitter_retweet = get_option("_ts_insert_twitter_retweet"); ?>
								<p>
									<label for="insert_twitter_retweet"><input class="widefat" id="insert_twitter_retweet" name="insert_twitter_retweet" type="checkbox" value="show" <?php if($twitter_retweet == "show") echo "CHECKED"; ?>> Show Only My Tweets</label> 	
								</p>
								<?php $twitter_exclude_replies = get_option("_ts_insert_twitter_exclude_replies"); ?>
								<p>
									<label for="insert_twitter_exclude_replies"><input class="widefat" id="insert_twitter_exclude_replies" name="insert_twitter_exclude_replies" type="checkbox" value="exclude" <?php if($twitter_exclude_replies == "exclude") echo "CHECKED"; ?>> Exclude replies</label> 	
								</p>
								<?php $twitter_links = get_option("_ts_insert_twitter_links"); ?>
								<p>
									<label for="insert_twitter_links"><input class="widefat" id="insert_twitter_links" name="insert_twitter_links" type="checkbox" value="new" <?php if($twitter_links == "new") echo "CHECKED"; ?>> Open links in new window/tab</label> 	
								</p>
							</div>
							<h4>Facebook</h4>
							<div style='margin-left: 1em;'>

								<?php 
								$facebook_page_id = get_option("_ts_facebook_page_id");
								$fb_pages = $this->facebook_get_pages(); 

								?>
								<label>Select a Stream</label>
								<select name="ts_facebook_page_id">
									<option value="">Don't Show</option>
									<option value="<?php //echo $data->user->id; ?>me" <?php if($facebook_page_id == "me") echo "SELECTED"; ?> >(Me) <?php echo $data->user->first_name; ?> <?php echo $data->user->last_name; ?></option>
									<?php
									foreach($fb_pages->data as $fb_page){
										?>
									<option value="<?php echo $fb_page->id; ?>" <?php if($fb_page->id== $facebook_page_id ) echo "SELECTED"; ?> ><?php echo $fb_page->name; ?> (<?php echo $fb_page->id; ?>)</option>
										<?php
									}
									?>
								</select>
							</div>
							<div class="ts-row">
								<p style="text-align: right"><input type="submit" class="button-primary" value="Save All Settings" /></p>
							</div>
						</div>
					</div>
				</div>
				
				<div id="ts-tab-shortcode" class="ts-tab">
					<div class="postbox">
						<div class="inside">

							
							<p>The TapSocial ticker can be used in any of your pages or posts.</p>
							<p>
								To use the default TapSocial settings, simply insert the [tapsocial] shortcode into your page or post.  The default settings are
							</p>
							<ul class="ul-disc">
								<li>[hide_twitter] Hide Twitter Feed = 0 (not hidden) </li>
								<li>[hide_facebook] Hide Facebook Feed = 0 (not hidden) </li>
								<li>[hide_custom] Hide Custom Feed = 0 (not hiden) </li>
								<li>[speed] Speed = 2 (normal) </li>
								<li>[orientation] Orientation = horizontal</li>
								<li>[style] Style = Inherited from Theme</li>
								<li>[size] Size = 10</li>
								<li>[height] Height = 200 (only applies to vertical orientation)</li>
								<li>[width] Width = auto (only applies to vertical orientation)</li>
								<li>[twitter_search] Search string or Hashtag</li>
								<li>[twitter_retweet] Twitter Show Only My Tweets = 0 (0 = yes, 1 = no)</li>
								<li>[twitter_exclude_replies] Twitter Exclude Replies = 0 (0 = include, 1 = exclude)</li>
								<li>[twitter_links] Twitter Links = 0 (0 = same window, 1 = new window/tab)</li>
								<li>[facebook_page_id] Facebook Page ID = me (me = personal page, use page ID for a different page)</li>
								<li>[id] HTML Element ID = 0 (0 = automatic, any other value will be custom element ID)</li>
							</ul>
							<hr/>
							<h3>Shortcode Generator</h3>
							<p>Use the TapSocial Shortcode Generator to create a shortcode you can copy and paste into any page or post</p>
							<div>
								<div class="ts-row">
									<label for="ts_insert_hide_twitter"><input type="checkbox" id="ts_insert_hide_twitter_gen" name="ts_insert_hide_twitter_gen" value="1" /> Hide Twitter Feed</label> 
								</div>
								<div class="ts-row">
									<label for="ts_insert_hide_facebook"><input type="checkbox" id="ts_insert_hide_facebook_gen" name="ts_insert_hide_facebook_gen" value="1" /> Hide Facebook Feed</label> 
								</div>
								<div class="ts-row">
									<label for="ts_insert_hide_custom"><input type="checkbox" id="ts_insert_hide_custom_gen" name="ts_insert_hide_custom_gen" value="1" /> Hide Custom Feed</label> 
								</div>
								<div class="ts-row">
									<label for="ts_insert_speed">Speed:</label> 
									<select id="ts_insert_speed_gen" name="ts_insert_speed_gen" >
										<option value="1" >Slow</option>
										<option value="2" selected >Normal (default)</option>
										<option value="3" >Fast</option>
									</select>
								</div>

								<div class="ts-row">
									<label for="ts_insert_style">Style:</label> 
									<select id="ts_insert_style_gen" name="ts_insert_style_gen" >
										<option value="inherit">Inherit</option>
										<option value="dark">Dark on light</option>
										<option value="light">Light on dark</option>
									</select>
								</div>
								
								<div class="ts-row">
									<label for="ts_insert_orientation">Orientation:</label> 
									<select id="ts_insert_orientation_gen" name="ts_insert_orientation_gen" >
										<option value="horizontal">Horizontal</option>
										<option value="vertical">Vertical</option>
									</select>
								</div>
								
								<div class="ts-row">
									<label for="ts_insert_size">Overall Size (default 10):</label> 
									<input name="ts_insert_size_gen" id="ts_insert_size_gen" value="10"/>
								</div>

								<div class="ts-row">
									<label for="ts_insert_height">Vertical Height (in px):</label> 
									<input name="ts_insert_height_gen" id="ts_insert_height_gen" value="200"/>
								</div>

								<div class="ts-row">
									<label for="ts_insert_width">Vertical Width (in px):</label> 
									<input name="ts_insert_width_gen" id="ts_insert_width_gen" value="auto"/>
								</div>

								<hr/>
								<h4>Twitter</h4>
								<div style='margin-left: 1em;'>
									<p>
										<label for="insert_twitter_search">Search String or Hashtag</label>
										<input class="widefat" id="ts_insert_twitter_search_gen" name="ts_insert_twitter_search_gen" type="text" value="">
									</p>
									<p>
										<label for="insert_twitter_retweet"><input class="widefat" id="ts_insert_twitter_retweet_gen" name="ts_insert_twitter_retweet_gen" type="checkbox" value="1"> Show Only My Tweets</label> 	
									</p>
									<p>
										<label for="insert_twitter_exclude_replies"><input class="widefat" id="ts_insert_twitter_exclude_replies_gen" name="ts_insert_twitter_exclude_replies_gen" type="checkbox" value="1"> Exclude replies</label> 	
									</p>
									<p>
										<label for="insert_twitter_links"><input class="widefat" id="ts_insert_twitter_links_gen" name="ts_insert_twitter_links_gen" type="checkbox" value="1"> Open links in new window/tab</label> 	
									</p>
								</div>
								<h4>Facebook</h4>
								<div style='margin-left: 1em;'>

									<?php 
									$fb_pages = $this->facebook_get_pages(); 

									?>
									<label>Select a Stream</label>
									<select name="ts_facebook_page_id_gen" id="ts_facebook_page_id_gen">
										<option value="">Don't Show</option>
										<option value="me"  >(Me) <?php echo $data->user->first_name; ?> <?php echo $data->user->last_name; ?></option>
										<?php
										foreach($fb_pages->data as $fb_page){
											?>
										<option value="<?php echo $fb_page->id; ?>"  ><?php echo $fb_page->name; ?> (<?php echo $fb_page->id; ?>)</option>
											<?php
										}
										?>
									</select>
								</div>
								<br/>
								<h4 style="color: green;">Your TapSocial Shortcode is below</h4>
								<code id="ts-shortcode-gen">
									[tapsocial hide_twitter=0 hide_facebook=0 hide_custom=0 speed=2 orientation='horizontal' style='inherit' size=10 height=200 width="auto" twitter_search="" twitter_retweet=0 twitter_exclude_replies=0 twitter_links=0 facebook_page_id="me" id=0]
								</code>
								
								<script>
								jQuery(function($){
									
									function ts_gen(){
										data = "[tapsocial ";
										data+= "hide_twitter="; data+= $("#ts_insert_hide_twitter_gen:checked").val() == 1 ? "1":"0"; data+=" ";
										data+= "hide_facebook="; data+= $("#ts_insert_hide_facebook_gen:checked").val() == 1 ? "1":"0"; data+=" ";
										data+= "hide_custom="; data+= $("#ts_insert_hide_custom_gen:checked").val() == 1 ? "1":"0"; data+=" ";
										data+= "speed="+$("#ts_insert_speed_gen").val()+" ";
										data+= "orientation='"+$("#ts_insert_orientation_gen").val()+"' ";
										data+= "style='"+$("#ts_insert_style_gen").val()+"' ";
										data+= "size="+$("#ts_insert_size_gen").val()+" ";
										data+= "height="+$("#ts_insert_height_gen").val()+" ";
										data+= "width='"+$("#ts_insert_width_gen").val()+"' ";
										data+= "twitter_search='"+$("#ts_insert_twitter_search_gen").val()+"' ";
										data+= "twitter_retweet="; data+= $("#ts_insert_twitter_retweet_gen:checked").val() == 1 ? "1":"0"; data+=" ";
										data+= "twitter_exclude_replies="; data+= $("#ts_insert_twitter_exclude_replies_gen:checked").val() == 1 ? "1":"0"; data+=" ";
										data+= "twitter_links="; data+= $("#ts_insert_twitter_links_gen:checked").val() == 1 ? "1":"0"; data+=" ";
										data+= "facebook_page_id='"+$("#ts_facebook_page_id_gen").val()+"'";
										data+= "]";
										$("#ts-shortcode-gen").html(data);
									}
									$("#ts-shortcode-gen").on("click", function(){
										ts_gen();
									});
									$("#ts_insert_speed_gen").on("focusout", function(){
										ts_gen();
									});
									$("#ts_insert_style_gen").on("focusout", function(){
										ts_gen();
									});
									$("#ts_insert_orientation_gen").on("focusout", function(){
										ts_gen();
									});
									$("#ts_insert_size_gen").on("focusout", function(){
										ts_gen();
									});
									$("#ts_insert_height_gen").on("focusout", function(){
										ts_gen();
									});
									$("#ts_insert_width_gen").on("focusout", function(){
										ts_gen();
									});
									$("#ts_insert_twitter_search_gen").on("focusout", function(){
										ts_gen();
									});
									$("#ts_insert_twitter_retweet_gen").on("focusout", function(){
										ts_gen();
									});
									$("#ts_insert_twitter_exclude_replies_gen").on("focusout", function(){
										ts_gen();
									});
									$("#ts_insert_twitter_links_gen").on("focusout", function(){
										ts_gen();
									});
									$("#ts_facebook_page_id_gen").on("focusout", function(){
										ts_gen();
									});
									
									
								})
								</script>
								
							</div>
							
							<br/>
							<br/>
							<hr/>
							<h3>Advanced Usage</h3>
							<p>
								To modify the content and appearance of the TapSocial ticker in a page or post, include the relevant shortcode variables.  For example
								<br/>
								<br/>
								<code>
									[tapsocial hide_twitter=0 hide_facebook=0 hide_custom=0 speed=2 orientation='horizontal' style='inherit' size=10 height=200 width="auto" twitter_search="" twitter_retweet=0 twitter_exclude_replies=0 twitter_links=0 facebook_page_id="me" id=0]
								</code>
							</p>
							<p>
								Shortcode options include:
							</p>
							<ul class="ul-disc">
								<li>[hide_twitter] Determines if Twitter feed should be hidden, 0 for no, 1 for yes</li>
								<li>[hide_facebook] Determines if Facebook feed should be hidden, 0 for no, 1 for yes</li>
								<li>[hide_custom] Determines if Custom feed should be hidden, 0 for no, 1 for yes</li>
								<li>[speed] Numeric value, 2 is default, 1 is slow, higher than 2 is faster</li>
								<li>[orientation] Can be 'horizontal' or 'vertical'</li>
								<li>[style] Default is 'inherit' which will take on theme styles, other options include 'light' and 'dark'</li>
								<li>[size] Numeric value, 10 is default. Higher or lower values determine overall font and image sizes.</li>
								<li>[height] Numeric value, default is 200.  This changes the height of the vertical orientation only, value is in pixels</li>
								<li>[width] Numeric value, default is 'auto'.  This changed the width of the vertical orientation only, value is in pixels</li>
								<li>[twitter_search] A Twitter search string or hash tag, but be surrounded by quotes, separate multiple hashtags with spaces</li>
								<li>[twitter_retweet] Determines if Twitter Shows Only My Tweets, 0 for yes, 1 for no</li>
								<li>[twitter_exclude_replies] Determines if Twitter replies should be excluded, 0 to include, 1 to exclude</li>
								<li>[twitter_links] Determines if links should open in a new window, 0 for same window, 1 for new window or tab</li>
								<li>[facebook_page_id] The Facebook page ID to pull the Facebook stream from.  Default is 'me' which is the personal stream</li>
								<li>[id] The HTML element ID of the ticker. Default is auto generated, set this to any value for a custom ID if you need to apply custom CSS</li>
							</ul>



							</p>
						</div>
					</div>
				</div>
				<div id="ts-tab-function" class="ts-tab">
					<div class="postbox">
						<div class="inside">
							<p>To add to your theme files, you can use the PHP function tapsocial()	</p>
							<code>&lt;?php tapsocial(); ?&gt;</code>
							<p>You can pass options as an array. The defaults are... </p>
							<br/>
							<code>
							array(<br/>
							&nbsp;&nbsp;&nbsp;&nbsp;	'hide_twitter' => 0, //default is 0, use 1 for hidden<br/>
							&nbsp;&nbsp;&nbsp;&nbsp;	'hide_facebook' => 0, //default is 0, use 1 for hidden<br/>
							&nbsp;&nbsp;&nbsp;&nbsp;	'hide_custom' => 0, //default is 0, use 1 for hidden<br/>
							&nbsp;&nbsp;&nbsp;&nbsp;	'speed' => "2", //default is 2<br/>
							&nbsp;&nbsp;&nbsp;&nbsp;	'orientation' => "horizontal", //vertical or horizontal<br/>
							&nbsp;&nbsp;&nbsp;&nbsp;	'style' => "inherit", //light, dark or inherit<br/>
							&nbsp;&nbsp;&nbsp;&nbsp;	'height' => 200, //height of vertical widget<br/>
							&nbsp;&nbsp;&nbsp;&nbsp;	'width' => "auto", //width of vertical widget<br/>
							&nbsp;&nbsp;&nbsp;&nbsp;	'size' => "10", //overall size, default is 10<br/>
							&nbsp;&nbsp;&nbsp;&nbsp;	'twitter_search' => false, //Can be a search string or hashtag<br/>
							&nbsp;&nbsp;&nbsp;&nbsp;	'twitter_retweet' => false, //true or false<br/>
							&nbsp;&nbsp;&nbsp;&nbsp;	'twitter_exclude_replies' => false, //true or false<br/>
							&nbsp;&nbsp;&nbsp;&nbsp;	'twitter_links' => false, //true to open in new tab<br/>
							&nbsp;&nbsp;&nbsp;&nbsp;	'facebook_page_id' => "me", //your faceboook page ID, "me" for personal page<br/>
							&nbsp;&nbsp;&nbsp;&nbsp;	'id' => 'tapsocial-function'.$ts_count++ //wrapper div ID<br/>
							);
							</code>
						</div>
					</div>
				</div>
			</form>
		</div>
		<?php
	}
	
	public function save_settings(){
		if(isset($_REQUEST['ts-save']) && $_REQUEST['ts-save'] == "save"){
			error_log("Save admin form");
			
			update_option("_ts_license_key", $_REQUEST['ts_license_key']);
			if($this->license_key != $_REQUEST['ts_license_key']){
				$this->license_key = $_REQUEST['ts_license_key'];
			}
			$this->add_license();
			
			update_option("_ts_insert_location", $_REQUEST['ts_insert_location']);
			
			update_option("_ts_insert_hide_twitter", $_REQUEST['ts_insert_hide_twitter']);
			update_option("_ts_insert_hide_facebook", $_REQUEST['ts_insert_hide_facebook']);
			update_option("_ts_insert_hide_custom", $_REQUEST['ts_insert_hide_custom']);
			
			update_option("_ts_insert_speed", $_REQUEST['ts_insert_speed']);
			update_option("_ts_insert_style", $_REQUEST['ts_insert_style']);
			update_option("_ts_insert_size", $_REQUEST['ts_insert_size']);
			update_option("_ts_insert_height", $_REQUEST['ts_insert_height']);
			update_option("_ts_insert_width", $_REQUEST['ts_insert_width']);
			
			update_option("_ts_insert_twitter_retweet", $_REQUEST['insert_twitter_retweet']);
			update_option("_ts_insert_twitter_exclude_replies", $_REQUEST['insert_twitter_exclude_replies']);
			update_option("_ts_insert_twitter_links", $_REQUEST['insert_twitter_links']);
			
			update_option("_tapsocial_twitter_update", $_REQUEST['ts_twitter_refresh']);
			$this->update_twitter = get_option("_tapsocial_twitter_update");
			
			update_option("_tapsocial_facebook_update", $_REQUEST['ts_facebook_refresh']);
			$this->update_twitter = get_option("_tapsocial_facebook_update");
			
			update_option("_ts_facebook_page_id", $_REQUEST['ts_facebook_page_id']);
		
		}
	}
	
	/* Display a notice that can be dismissed */
	public function admin_notice() {
		global $current_user ;
			$user_id = $current_user->ID;
			/* Check that the user hasn't already clicked to ignore the message */
		if(isset($_REQUEST['tapsocial_nag_ignore']) && $_REQUEST['tapsocial_nag_ignore'] == "ignore"){
			update_user_meta($user_id, "tapsocial_ignore_notice", 1);
		}
		if ( ! get_user_meta($user_id, 'tapsocial_ignore_notice') ) {
			?>
			<div class="error">
				<p>
					Please <a href="<?php echo get_admin_url("", "options-general.php?page=tapsocial"); ?>">configure your settings</a> for TapSocial</a> You will not be able to use TapSocial until it is configured. <br/><a href="?tapsocial_nag_ignore=ignore">Hide Notice</a>
				</p>
			</div>
			<?php
		}
	}
	public function admin_notice_hide() {
		global $current_user;
			$user_id = $current_user->ID;
			/* If user clicks to ignore the notice, add that to their user meta */
			if ( isset($_GET['tapsocial_nag_ignore']) && '0' == $_GET['tapsocial_nag_ignore'] ) {
				 add_user_meta($user_id, 'tapsocial_ignore_notice', 'true', true);
		}
	}
	
	
	
	public static function facebook_get_pages(){

		$ch = curl_init("https://graph.facebook.com/".get_option("_ts_facebook_user_id")."/accounts?access_token=".get_option("_ts_facebook_oauth_token"));
		curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
		$resp=curl_exec($ch);

		curl_close($ch);
		$resp=json_decode($resp);
		
		return $resp;
		
	}
	
	public function widget(){
		//facebook
		$fb_pages = $this->facebook_get_pages();
		foreach($fb_pages as $page){
			$page->name;
			$page->access_token;
			$page->id;
		}
	}
	
	public function callback(){
		global $wpdb;
		if(isset($_REQUEST['tapsocial_callback'])){
			switch($_REQUEST['tapsocial_callback']){
				
				
				
				case "twitter":
					//verify uuid here
					update_option("_ts_twitter_oauth_token", $_REQUEST['oauth_token']);
					update_option("_ts_twitter_oauth_secret", $_REQUEST['oauth_secret']);
					update_option("_ts_twitter_screenname", $_REQUEST['oauth_username']);
					update_option("_ts_twitter_user_id", $_REQUEST['oauth_user_id']);
					
					header("Location: ".admin_url()."/options-general.php?page=tapsocial");
					die();
				break;
			
				case "facebook":
					//verify uuid here
					update_option("_ts_facebook_oauth_token", $_REQUEST['oauth_token']);
					update_option("_ts_facebook_user_id", $_REQUEST['oauth_user_id']);
					
					header("Location: ".admin_url()."/options-general.php?page=tapsocial");
					die();
				break;
				
			}
		}
	}
	
	private function guid(){
		if (function_exists('com_create_guid')){
			return com_create_guid();
		}else{
			mt_srand((double)microtime()*10000);//optional for php 4.2.0 and up.
			$charid = strtoupper(md5(uniqid(rand(), true)));
			$hyphen = chr(45);// "-"
			$uuid = chr(123)// "{"
					.substr($charid, 0, 8).$hyphen
					.substr($charid, 8, 4).$hyphen
					.substr($charid,12, 4).$hyphen
					.substr($charid,16, 4).$hyphen
					.substr($charid,20,12)
					.chr(125);// "}"
			return $uuid;
		}
	}
	
	public static function show($instance = false, $args = false){
		$options = array();
		
		if(isset($instance['size']) && $instance['size']){
			$size = $instance['size'];
		}
		else{
			$size = 10;
		}
		
		if(isset($instance['twitter_search']) && strlen($instance['twitter_search']) > 2){
			$search_action = "search/tweets";	
			$search_request = TS_URL_TWITTER."?ts_uuid=".get_option("_tapsocial_uuid")."&action=".$search_action."&options=result_type:popular|q:".urlencode($instance['twitter_search']);
			error_log("twitter search request: ".$search_request);
			if($search_data = tapsocial::cache_get($search_request) && isset($search_data->ACK) && $search_data->ACK == "success"){
				error_log("Got from cache");
			}
			else{
				$search_data = file_get_contents($search_request);
				error_log("Add to cache");
				tapsocial::cache_set($search_request, $search_data, get_option("_tapsocial_twitter_update"));	
			}
			$search_tweets = json_decode($search_data);
			if(isset($search_tweets->ACK) && $search_tweets->ACK == "success"){
				$search_tweets = $search_tweets->data->statuses;
			}
			else{
				$search_tweets = false;
			}
		}
		else{
			$search_tweets = false;
		}
		
		
		if($instance['twitter_retweet'] == "show" || $instance['twitter_retweet'] == 1){
			$action = "statuses/user_timeline";
		}
		else{
			$action = "statuses/home_timeline";	
		}
		if($instance['twitter_exclude_replies'] == "exclude" || $instance['twitter_exclude_replies'] == 1){
			$options[] = "exclude_replies:true";
		}
		$request = TS_URL_TWITTER."?ts_uuid=".get_option("_tapsocial_uuid")."&action=".$action."&options=".implode("|", $options);
		error_log("request: ".$request);
		if($data = tapsocial::cache_get($request) && isset($data->ACK) && $data->ACK == "success"){
			error_log("Got from cache");
		}
		else{
			$data = file_get_contents($request);
			error_log("Add to cache");
			tapsocial::cache_set($request, $data, get_option("_tapsocial_twitter_update"));
			
		}
		
		if(isset($instance['facebook_page_id']) && $instance['facebook_page_id']){
			$fb_request = "https://graph.facebook.com/v2.1/".$instance['facebook_page_id']."/feed?access_token=".get_option("_ts_facebook_oauth_token");
			if($fb_data = tapsocial::cache_get($fb_request)){
				error_log("Got FB from cache");
			}
			else{
				$fb_data = file_get_contents($fb_request);
				error_log("Add to cache");
				tapsocial::cache_set($fb_request, $fb_data, get_option("_tapsocial_facebook_update"));
			}
			$fb_data = json_decode($fb_data);
			//echo "<!-- FACEBOOK ".print_r($fb_data, true)." -->";
		}
		$tweets = json_decode($data);
		
		
		//Get Custom Messages
		$custom_messages = array();
		$loop = new WP_Query( array( 'post_type' => 'tapsocial_message', 'posts_per_page' => -1 ) );
		while ( $loop->have_posts() ) : $loop->the_post();
			$custom_message = array();
			$custom_message['content'] = get_the_content();
			$custom_message['avatar']  = '<img src="'.tapsocial::get_the_post_thumbnail_src(get_the_post_thumbnail(null, "ts-thumb-150x150")).'" />';
			$custom_message['time'] = get_the_date("m/d/Y g:ia");
			$custom_message['name'] = get_the_author();
			$custom_messages[] = $custom_message;
		endwhile;
		
		
		if(isset($tweets->ACK) && $tweets->ACK == "success"){
			if(!$instance['hide_twitter']){
				$tweets = $tweets->data;
			}
			else{
				$tweets = array();
			}
			
			if($search_tweets){
				$tweets = tapsocial::array_merge_alternating($tweets, $search_tweets);
			}
			//print_r($tweets);
			ob_start();

			?>
			<div class="ts-container <?php echo $instance['orientation']; ?> <?php echo $instance['style']; ?>" style="font-size: <?php echo $size; ?>px; display: none;">
				<div class="ts-viewport">
					<ul class="ts-ul">
					<?php
					if(isset($instance['twitter_links']) && $instance['twitter_links']){
						$target = 'target="_blank"';
					}
					else{
						$target = "";
					}
					for($x = 0; $x < 20; $x++){
					//foreach($tweets as $tweet){

						//TWITTER
						if(isset($tweets[$x])){
							$tweet = $tweets[$x];
							if(isset($tweet->entities->urls)){
								foreach($tweet->entities->urls as $url){
									$tweet->text = str_replace($url->url, '<a href="'.$url->url.'" '.$target.'>'.$url->display_url."</a>", $tweet->text);
								}
							}
							if(isset($tweet->entities->media)){
								foreach($tweet->entities->media as $media){
									$tweet->text = str_replace($media->url, '<a href="'.$media->url.'" '.$target.'>'.$media->display_url."</a>", $tweet->text);
								}
							}
							$icon = '<i class="fa fa-twitter-square"></i>';
							$avatar = '<img src="'.$tweet->user->profile_image_url_https.'" title="'.$tweet->user->name.'" alt="'.$tweet->user->name.'"/>';
							$user_link = 'https://twitter.com/intent/user?screen_name='.$tweet->user->screen_name;
							$name = $tweet->user->name;
							$screenname = $tweet->user->screen_name;
							$content = $tweet->text;
							$reply = '<a href="https://twitter.com/intent/tweet?in_reply_to='.$tweet->id_str.'"><i class="fa fa-reply"></i></a>';
							$share = '<a href="https://twitter.com/intent/retweet?tweet_id='.$tweet->id_str.'"><i class="fa fa-retweet"></i></a>';
							$like = '<a href="https://twitter.com/intent/favorite?tweet_id='.$tweet->id_str.'"><i class="fa fa-star"></i></a>';
							$time = '<a href="//twitter.com/'.$tweet->user->screen_name.'/status/'.$tweet->id_str.'">'.date("m/d/Y g:ia", strtotime($tweet->created_at)).'</a>';

							$template = array(
								'avatar' => $avatar,
								'icon' => $icon,
								'user_link'=> $user_link,
								'name' => $name,
								'screenname' => $screenname,
								'content' => $content,
								'reply'=> $reply,
								'share' => $share,
								'like' => $like,
								'time' => $time,
								'orientation' => $instance['orientation']
							);
							tapsocial::template($template);
						}

						//FACEBOOK
						if(!$instance['hide_facebook'] && isset($fb_data) && isset($fb_data->data[$x])){
							$fb_post = $fb_data->data[$x];

							//set actions
							$fb_comment_link = "#";
							$fb_like_link = "#";
							if(isset($fb_post->actions)){
								foreach($fb_post->actions as $action){
									switch($action->name){
										case "Comment":
											$fb_comment_link = $action->link;
										break;
										case "Like":
											$fb_like_link = $action->link;
										break;
									}
								}
							}
							else{
								$fb_comment_link = "#";
								$fb_like_link = "#";
							}
							$post_id = explode("_", $fb_post->id);
							$post_link = "https://www.facebook.com/".$post_id[0]."/posts/".$post_id[1];

							$icon = '<i class="fa fa-facebook-square"></i>';
							$avatar = '<img src="'.tapsocial::fb_get_picture($fb_post->from->id).'" />';
							$user_link = "https://www.facebook.com/".$fb_post->from->id;
							$name = $fb_post->from->name;
							$screenname = "";

							$reply = '<a href="'.$fb_comment_link.'"><i class="fa fa-reply"></i></a>';
							$share = "";
							$like = '<a href="'.$fb_like_link.'"><i class="fa fa-star"></i></a>';
							$time = '<a href="'.$post_link.'">'.date("m/d/Y g:ia", strtotime($fb_post->created_time)).'</a>';

							switch($fb_post->type){
								case "status" :
									$content = "";
									if(isset($fb_post->message)){
										$content.= $fb_post->message." ";
									}
									if(isset($fb_post->story)){
										$content.= $fb_post->story;
									}
								break;
								case "link" :
									$content = "";
									if(!isset($fb_post->name)) $fb_post->name = "";
									if(isset($fb_post->icon)){
										$content.= '<img class="ts_fb_icon" src="'.$fb_post->icon.'" /> ';
									}
									$content.= $fb_post->name.' <a href="'.$fb_post->link.'">'.$fb_post->link.'</a>';
								break;
								case "photo" :
									if(!isset($fb_post->name)) $fb_post->name = "";
									if(!isset($fb_post->message)) $fb_post->message = "";
									$content = '<img class="ts_fb_icon" src="'.$fb_post->icon.'" /> '.$fb_post->name.' '.$fb_post->message.' <a href="'.$fb_post->link.'">View image in post.</a>';
								break;
							}
							$template = array(
								'avatar' => $avatar,
								'icon' => $icon,
								'user_link'=> $user_link,
								'name' => $name,
								'screenname' => $screenname,
								'content' => $content,
								'reply'=> $reply,
								'share' => $share,
								'like' => $like,
								'time' => $time,
								'orientation' => $instance['orientation']
							);
							tapsocial::template($template);
						}
						
						//Custom Messages
						$custom_message = current($custom_messages);
						if($custom_message === false){
							reset($custom_messages);
							$custom_message = current($custom_messages);
						}
						if(!$instance['hide_custom'] && $custom_message !== false){
							error_log("CUSTOM MESSAGES : ".print_r($custom_message, true));
							$template = array(
								'avatar' => $custom_message['avatar'],
								'icon' => '<i class="fa fa-bullhorn"></i>',
								'user_link'=> false,
								'name' => $custom_message['name'],
								'screenname' => false,
								'content' => $custom_message['content'],
								'reply'=> false,
								'share' => false,
								'like' => false,
								'time' => $custom_message['time'],
								'orientation' => $instance['orientation']
							);
							tapsocial::template($template);
							next($custom_messages);
						}
						
						
					}
					?>	
					</ul>
				</div>
			</div>
			<script>
				jQuery(function($){
					<?php
					if(isset($instance['location'])){
						switch($instance['location']){
							case "top" :
								?>
								$("html").prepend($("#<?php echo $args['widget_id']; ?>"));
								<?php
							break;
							case "bottom" :
								?>
								$("html").append($("#<?php echo $args['widget_id']; ?>"));
								<?php
							break;
							case "header" :
								?>
								$("header :first").prepend($("#<?php echo $args['widget_id']; ?>"));
								<?php
							break;
							case "footer" :
								?>
								$("footer").prepend($("#<?php echo $args['widget_id']; ?>"));
								<?php
							break;
						}
					}
					?>
					
					
					
					$("#<?php echo $args['widget_id']; ?> .ts-container").show();
					width = $("#<?php echo $args['widget_id']; ?> .simply-scroll .simply-scroll-list li").parent().width();
					$("#<?php echo $args['widget_id']; ?> .simply-scroll .simply-scroll-list li").width(width);

					$("#<?php echo $args['widget_id']; ?> .ts-ul").simplyScroll({
						orientation: "<?php echo $instance['orientation']; ?>",
						speed: <?php echo $instance['speed']; ?>
					});
					
					
				});
			</script>
			<?php
			//set height for vertical
			if($instance['orientation'] == "vertical"){
				if($instance['height']){
					$height = $instance['height']."px";
				}
				else{
					$height = "200px";
				}
				if($instance['width'] && strtolower($instance['width']) != "auto"){
					$width = $instance['width']."px";
				}
				else{
					$width = "auto";
				}
				?>				
				<style>
					#<?php echo $args['widget_id'];?> .vertical .simply-scroll .simply-scroll-clip,
					#<?php echo $args['widget_id'];?> .vertical .simply-scroll{
						height: <?php echo $height; ?>;
						width: <?php echo $width; ?>
					}
				</style>
				<?php
			}
			$html = ob_get_clean();
		}
		else{
			$html = '<div style="position: relative; z-index: 10000000; color: red; font-weight: bold; background-color: #ffffff; padding: 1em; border: 1px dotted #000000; height: 1em; width: auto;">Please enter your license key in the TapSocial Wordpress Plugin settings. &nbsp; <a style="color: blue; text-decoration: underline;" href="' . admin_url( 'options-general.php?page=tapsocial' ) . '">Update Settings</a></div>';
		}
		echo preg_replace('/^\s+|\n|\r|\s+$/m', '', $html);
	}
	
	
	public static function template($template){
		if($template['orientation'] == "vertical"){
			?>
			<li class="ts-widget-content-wrapper">
				<div class="ts-avatar">
					<?php echo $template['avatar']; ?>
					<div class="ts-tweet-author">
							<?php echo $template['icon']; ?> <a href="<?php echo $template['user_link']; ?>"><span class="ts-tweet-name"><?php echo $template['name']; ?></span>
							<?php if($template['screenname']){ ?> <br/> <span class="ts-tweet-screenname">@<?php echo $template['screenname']; ?></span><?php } ?></a>
							<br/><?php echo $template['time']; ?>
					</div>
					<div style="clear: both;"></div>
				</div>	
				<div class="ts-content-right">
					<table class="ts-table">
						<tr>
							<td>
								<div class="ts-tweet-content"><?php echo $template['content']; ?></div>
							</td>
						</tr>
						<tr>
							<td>
								<div class="ts-tweet-date">
									<?php if(isset($template['reply']) && $template['reply']){ echo $template['reply']; ?> &nbsp; <?php } ?> 
									<?php if(isset($template['share']) && $template['share']){ echo $template['share']; ?> &nbsp;  <?php } ?>
									<?php if(isset($template['like']) && $template['like']){ echo $template['like']; ?> &nbsp; <?php } ?>
									
								</div>
							</td>
						</tr>
					</table>
				</div>
				<div style="clear: both " ></div>
			</li>
			<?php
		}
		else{
			?>				
			<li class="ts-widget-content-wrapper">
				<div class="ts-avatar">
					<?php echo $template['avatar']; ?>
				</div>	
				<div class="ts-content-right">
					<table class="ts-table">
						<tr>
							<td>
								<div class="ts-tweet-author">
									<?php echo $template['icon']; ?> <a href="<?php echo $template['user_link']; ?>"><span class="ts-tweet-name"><?php echo $template['name']; ?></span><?php if($template['screenname']){ ?> | <span class="ts-tweet-screenname">@<?php echo $template['screenname']; ?></span><?php } ?></a>
								</div>
							</td>
						</tr>
						<tr>
							<td>
								<div class="ts-tweet-content"><?php echo $template['content']; ?></div>
							</td>
						</tr>
						<tr>
							<td>
								<div class="ts-tweet-date">
									<?php if(isset($template['reply']) && $template['reply']){ echo $template['reply']; ?> &nbsp; <?php } ?> 
									<?php if(isset($template['share']) && $template['share']){ echo $template['share']; ?> &nbsp;  <?php } ?>
									<?php if(isset($template['like']) && $template['like']){ echo $template['like']; ?> &nbsp; <?php } ?>
									<?php if(isset($template['time']) && $template['time']){ echo $template['time']; } ?>
								</div>
							</td>
						</tr>
					</table>
				</div>
				<div style="clear: both " ></div>
			</li>
			<?php
		}
	}
	
	
	function inject() {
		$instance['location'] = get_option("_ts_insert_location");
		if($instance['location']){
			$instance['orientation'] = "horizontal";
			$instance['twitter_search'] = false;
			
			$instance['hide_facebook'] = get_option("_ts_insert_hide_facebook");
			$instance['hide_twitter'] = get_option("_ts_insert_hide_twitter");
			$instance['hide_custom'] = get_option("_ts_insert_hide_custom");

			$instance['speed'] = get_option("_ts_insert_speed");
			$instance['style'] = get_option("_ts_insert_style");
			$instance['size'] = get_option("_ts_insert_size");
			$instance['height'] = get_option("_ts_insert_height");
			$instance['width'] = get_option("_ts_insert_width");

			$instance['twitter_retweet'] = get_option("_ts_insert_twitter_retweet");
			$instance['twitter_exclude_replies'] = get_option("_ts_insert_twitter_exclude_replies");
			$instance['twitter_exclude_links'] = get_option("_ts_insert_twitter_links");
			
			$instance['facebook_page_id'] = get_option("_ts_facebook_page_id");

			$args['widget_id'] = "ts-inject-".$instance['location'];
			?>
			
			<div id="<?php echo $args['widget_id']; ?>" >
				<?php
				$this->show($instance, $args);
				?>
			</div>
			<?php
		}
	}
	
	function shortcode_tapsocial( $atts ) {
      $atts = shortcode_atts( array(
		'hide_twitter' => 0,
		'hide_facebook' => 0,
		'hide_custom' => 0,
		'speed' => "2", //default is 2
		'orientation' => "horizontal", //vertical or horizontal		
		'style' => "inherit", //light, dark or inherit
		'height' => "200", //vertical height in pixels
		'width' => "auto", //vertical width in pixels
		'size' => "10", //overall size, default is 10
		'twitter_search' => false, //vertical or horizontal
		'twitter_retweet' => false, //true or false
		'twitter_exclude_replies' => false, //true or false
		'twitter_links' => false, //true to open in new tab
		'facebook_page_id' => "me", //set to page ID or false, set to "me" for personal page
		'id' => '' //wrapper div ID
		), $atts );
	  ob_start();
	  tapsocial($atts);
	  $html = ob_get_clean();
      return preg_replace('/^\s+|\n|\r|\s+$/m', '', $html);
	}
	
	public static function fb_get_picture($id = "me"){
		$fb_request = "https://graph.facebook.com/v2.1/".$id."/picture?access_token=".get_option("_ts_facebook_oauth_token")."&redirect=false";
		error_log($fb_request);
		if($fb_data = tapsocial::cache_get($fb_request)){
			error_log("Got FB picture from cache");
		}
		else{
			$fb_data = file_get_contents($fb_request);
			error_log("Add FB picture to cache");
			tapsocial::cache_set($fb_request, $fb_data, get_option("_tapsocial_facebook_update"));
		}
		$fb_data = json_decode($fb_data);
		return $fb_data->data->url;
	}
	
	public static function array_merge_alternating($array1, $array2){
		$mergedArray = array();
		while( count($array1) > 0 || count($array2) > 0 ){
			if ( count($array1) > 0 )
				$mergedArray[] = array_shift($array1);
			if ( count($array2) > 0 )
				$mergedArray[] = array_shift($array2);
			
		}
		return $mergedArray;
	}
	
}


class TSTwitterWidget extends WP_Widget {

	function __construct() {
		// Instantiate the parent object
		parent::__construct( 
				'ts_widget_twitter', //base id
				'TapSocial for Wordpress' //widget title
		);
	}

	function widget( $args, $instance ) {
		error_log(print_r($args, true));
		$title = apply_filters( 'widget_title', $instance['title'] );
		echo $args['before_widget'];
		if ( ! empty( $title ) ) {
			echo $args['before_title'] . $title . $args['after_title'];
		}
		
		tapsocial::show($instance, $args);
		
		
		echo $args['after_widget'];
	}

	function update( $new_instance, $old_instance ) {
		// Save widget options
		$instance = array();
		$instance['title'] = ( ! empty( $new_instance['title'] ) ) ? strip_tags( $new_instance['title'] ) : '';
		
		$instance['hide_twitter'] = $new_instance['hide_twitter'];
		$instance['hide_facebook'] = $new_instance['hide_facebook'];
		$instance['hide_custom'] = $new_instance['hide_custom'];
		
		$instance['speed'] = $new_instance['speed'];
		$instance['orientation'] = $new_instance['orientation'];
		$instance['style'] = $new_instance['style'];
		
		if(isset($new_instance['twitter_search'])){
			$instance['twitter_search'] = $new_instance['twitter_search'];
		}
		else{
			$instance['twitter_search'] = false;
		}
		
		if(isset($new_instance['twitter_retweet'])){
			$instance['twitter_retweet'] = $new_instance['twitter_retweet'];
		}
		else{
			$instance['twitter_retweet'] = " ";
		}
		if(isset($new_instance['twitter_exclude_replies'])){
			$instance['twitter_exclude_replies'] = $new_instance['twitter_exclude_replies'];
		}
		else{
			$instance['twitter_exclude_replies'] = "";
		}
		if(isset($new_instance['twitter_links'])){
			$instance['twitter_links'] = $new_instance['twitter_links'];
		}
		else{
			$instance['twitter_links'] = " ";
		}
		
		if(isset($new_instance['facebook_page_id'])){
			$instance['facebook_page_id'] = $new_instance['facebook_page_id'];
		}
		else{
			$instance['facebook_page_id'] = false;
		}
		
		if(isset($new_instance['height'])){
			$instance['height'] = $new_instance['height'];
		}
		else{
			$instance['height'] = "200";
		}
		
		if(isset($new_instance['width'])){
			$instance['width'] = $new_instance['width'];
		}
		else{
			$instance['width'] = "auto";
		}
		
		if(isset($new_instance['size'])){
			$instance['size'] = $new_instance['size'];
		}
		else{
			$instance['size'] = "10";
		}
		

		return $instance;
	}

	function form( $instance ) {
		if ( isset( $instance['title' ] ) ) {
			$title = $instance['title'];
		}
		else {
			$title = "New Title";
		}
		if ( isset( $instance['hide_twitter'] ) ) {
			$hide_twitter = $instance['hide_twitter'];
		}
		else {
			$hide_twitter = 0;
		}
		if ( isset( $instance['hide_facebook'] ) ) {
			$hide_facebook = $instance['hide_facebook'];
		}
		else {
			$hide_facebook = 0;
		}
		if ( isset( $instance['hide_custom'] ) ) {
			$hide_custom = $instance['hide_custom'];
		}
		else {
			$hide_custom = 0;
		}
		if ( isset( $instance['speed'] ) ) {
			$speed = $instance['speed'];
		}
		else {
			$speed = 2;
		}
		if ( isset( $instance['orientation'] ) ) {
			$orientation = $instance['orientation'];
		}
		else {
			$orientation = "vertical";
		}
		
		if ( isset( $instance['style'] ) ) {
			$style = $instance['style'];
		}
		else {
			$style = "inherit";
		}
		
		if ( isset( $instance['twitter_search'] ) ) {
			$twitter_search= $instance['twitter_search'];
		}
		else {
			$twitter_search = false;
		}
		
		if ( isset( $instance['twitter_retweet'] ) ) {
			$twitter_retweet = $instance['twitter_retweet'];
		}
		else {
			$twitter_retweet = " ";
		}
		if ( isset( $instance['twitter_exclude_replies'] ) ) {
			$twitter_exclude_replies = $instance['twitter_exclude_replies'];
		}
		else {
			$twitter_exclude_replies = " ";
		}
		if ( isset( $instance['twitter_links'] ) ) {
			$twitter_links = $instance['twitter_links'];
		}
		else {
			$twitter_links = " ";
		}
		
		if ( isset( $instance['facebook_page_id'] ) ) {
			$facebook_page_id = $instance['facebook_page_id'];
		}
		else {
			$facebook_page_id = false;
		}
		
		if ( isset( $instance['height'] ) ) {
			$height = $instance['height'];
		}
		else {
			$height = "200";
		}
		if ( isset( $instance['width'] ) ) {
			$width = $instance['width'];
		}
		else {
			$width = "auto";
		}
		if ( isset( $instance['size'] ) ) {
			$size = $instance['size'];
		}
		else {
			$size = "10";
		}
		
		?>
		<p>
			<label for="<?php echo $this->get_field_id( 'title' ); ?>">Title:</label> 
			<input class="widefat" id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>">
		</p>
		
		<p>
			<input type="checkbox" class="widefat" id="<?php echo $this->get_field_id( 'hide_twitter' ); ?>" name="<?php echo $this->get_field_name( 'hide_twitter' ); ?>" value="1" <?php if($hide_twitter) echo "CHECKED"; ?> /> <label for="<?php echo $this->get_field_id( 'hide_twitter' ); ?>">Hide Twitter Feed</label> 
			
		</p>
		<p>
			<input type="checkbox" class="widefat" id="<?php echo $this->get_field_id( 'hide_facebook' ); ?>" name="<?php echo $this->get_field_name( 'hide_facebook' ); ?>" value="1" <?php if($hide_facebook) echo "CHECKED"; ?> /> <label for="<?php echo $this->get_field_id( 'hide_facebook' ); ?>">Hide Facebook Feed</label> 
			
		</p>
		<p>
			<input type="checkbox" class="widefat" id="<?php echo $this->get_field_id( 'hide_custom' ); ?>" name="<?php echo $this->get_field_name( 'hide_custom' ); ?>" value="1" <?php if($hide_custom) echo "CHECKED"; ?> /> <label for="<?php echo $this->get_field_id( 'hide_custom' ); ?>">Hide Custom Feed</label> 
			
		</p>
		
		<p>
			<label for="<?php echo $this->get_field_id( 'speed' ); ?>">Speed:</label> 
			<select class="widefat" id="<?php echo $this->get_field_id( 'speed' ); ?>" name="<?php echo $this->get_field_name( 'speed' ); ?>" >
				<option value="1" <?php if($speed == 1) echo "SELECTED"; ?>>Slow</option>
				<option value="2" <?php if($speed == 2) echo "SELECTED"; ?>>Normal (default)</option>
				<option value="3" <?php if($speed == 3) echo "SELECTED"; ?>>Fast</option>
			</select>
		</p>
		<p>
			<label for="<?php echo $this->get_field_id( 'orientation' ); ?>">Orientation:</label> 
			<select class="widefat" id="<?php echo $this->get_field_id( 'orientation' ); ?>" name="<?php echo $this->get_field_name( 'orientation' ); ?>" >
				<option value="vertical" <?php if($orientation == "vertical") echo "SELECTED"; ?>>Vertical</option>
				<option value="horizontal" <?php if($orientation == "horizontal") echo "SELECTED"; ?>>Horizontal</option>
			</select>
		</p>
		<p>
			<label for="<?php echo $this->get_field_id( 'style' ); ?>">Style:</label> 
			<select class="widefat" id="<?php echo $this->get_field_id( 'style' ); ?>" name="<?php echo $this->get_field_name( 'style' ); ?>" >
				<option value="inherit" <?php if($style == "inherit") echo "SELECTED"; ?>>Inherit</option>
				<option value="dark" <?php if($style == "dark") echo "SELECTED"; ?>>Dark on light</option>
				<option value="light" <?php if($style == "light") echo "SELECTED"; ?>>Light on dark</option>
			</select>
		</p>
		<p>
			<label for="<?php echo $this->get_field_id( 'size' ); ?>">Size (default is 10):</label> 
			<input type="text" class="widefat" id="<?php echo $this->get_field_id( 'size' ); ?>" name="<?php echo $this->get_field_name( 'size' ); ?>" value="<?php echo $size; ?>"/>
		</p>
		<p>
			<label for="<?php echo $this->get_field_id( 'height' ); ?>">Vertical Height (in px):</label> 
			<input type="text" class="widefat" id="<?php echo $this->get_field_id( 'height' ); ?>" name="<?php echo $this->get_field_name( 'height' ); ?>" value="<?php echo $height; ?>"/>
		</p>
		<p>
			<label for="<?php echo $this->get_field_id( 'width' ); ?>">Vertical Width (in px):</label> 
			<input type="text" class="widefat" id="<?php echo $this->get_field_id( 'width' ); ?>" name="<?php echo $this->get_field_name( 'width' ); ?>" value="<?php echo $width; ?>"/>
		</p>
		<hr/>
		<h4>Twitter</h4>
		<div style='margin-left: 1em;'>
			<p>
				<label for="<?php echo $this->get_field_id( 'twitter_search' ); ?>">Hashtag or Search</label>
				<input class="widefat" id="<?php echo $this->get_field_id( 'twitter_search' ); ?>" name="<?php echo $this->get_field_name( 'twitter_search' ); ?>" type="text" value="<?php echo $twitter_search; ?>" >
			</p>
			<p>
				<label for="<?php echo $this->get_field_id( 'twitter_retweet' ); ?>"><input class="widefat" id="<?php echo $this->get_field_id( 'twitter_retweet' ); ?>" name="<?php echo $this->get_field_name( 'twitter_retweet' ); ?>" type="checkbox" value="show" <?php if($twitter_retweet == "show") echo "CHECKED"; ?>> Show Only My Tweets</label> 	
			</p>
			<p>
				<label for="<?php echo $this->get_field_id( 'twitter_exclude_replies' ); ?>"><input class="widefat" id="<?php echo $this->get_field_id( 'twitter_exclude_replies' ); ?>" name="<?php echo $this->get_field_name( 'twitter_exclude_replies' ); ?>" type="checkbox" value="exclude" <?php if($twitter_exclude_replies == "exclude") echo "CHECKED"; ?>> Exclude replies</label> 	
			</p>
			<p>
				<label for="<?php echo $this->get_field_id( 'twitter_links' ); ?>"><input class="widefat" id="<?php echo $this->get_field_id( 'twitter_links' ); ?>" name="<?php echo $this->get_field_name( 'twitter_links' ); ?>" type="checkbox" value="new" <?php if($twitter_links == "new") echo "CHECKED"; ?>> Open links in new window/tab</label> 	
			</p>
		</div>
		<hr/>
		<h4>Facebook</h4>
		<div style='margin-left: 1em;'>
			<?php 
			$fb_pages = tapsocial::facebook_get_pages(); 
			?>
			<label>Select a Stream</label>
			<select name="<?php echo $this->get_field_name( 'facebook_page_id' ); ?>" id="<?php echo $this->get_field_id( 'facebook_page_id' ); ?>">
				<option value="">Don't Show</option>
				<option value="me" <?php if($facebook_page_id == "me") echo "SELECTED"; ?> >Me</option>
				<?php
				foreach($fb_pages->data as $fb_page){
					?>
				<option value="<?php echo $fb_page->id; ?>" <?php if($facebook_page_id == $fb_page->id) echo "SELECTED"; ?> ><?php echo $fb_page->name; ?> (<?php echo $fb_page->id; ?>)</option>
					<?php
				}
				?>
			</select>
		</div>
		<?php 
	}
	
	
}

function ts_register_widgets() {
	register_widget( 'TSTwitterWidget' );
}
add_action( 'widgets_init', 'ts_register_widgets' );


$tapsocial = new tapsocial();

$ts_count = 1;
function tapsocial($data = array()){
	global $ts_count;
	if(isset($data['id']) && $data['id'] == ""){
		$data['id'] = 'tapsocial-function-'.$ts_count++ ;
	}
	$defaults = array(
		'hide_twitter' => 0,
		'hide_facebook' => 0,
		'hide_custom' => 0,
		'speed' => "2", //default is 2
		'orientation' => "horizontal", //vertical or horizontal
		'style' => "inherit", //light, dark or inherit
		'size' => "10", //overall size, default is 10
		'height' => "200", //vertical height
		'width' => "auto", //vertical width
		'twitter_search' => false, //search string or hashtag
		'twitter_retweet' => false, //true or false
		'twitter_exclude_replies' => false, //true or false
		'twitter_links' => false, //true to open in new tab
		'facebook_page_id' => "me", //facebook page ID "me" is personal page
		'id' => 'tapsocial-function-'.$ts_count++ //wrapper div ID
	);
	$data = array_merge($defaults, $data);
	$args['widget_id'] = $data['id'];
	?>
	<div id="<?php echo $data['id']; ?>">
	<?php
	tapsocial::show($data, $args);
	?>
	</div>
	<?php
}