<?php
/*
Plugin Name: Poetica
Plugin URI: http://poetica.com/
Description: An alternative text editor that enables realtime collaborative writing and editing of posts within WordPress.
Version: 1.12
Author: Poetica
Author URI: http://poetica.com/
License: GPL
*/

class Plugin_PoeticaEditor {

  const ENV = 'production';

  private $hosts = array(
    'local' => 'local.poetica.com:3333',
    'staging' => 'staging.poetica.com',
    'production' => 'poetica.com',
  );

  private $protocols = array(
    'local' => 'http://',
    'staging' => 'https://',
    'production' => 'https://',
  );

  private $domains = array(
    'local' => 'http://local.poetica.com:3333',
    'staging' => 'https://staging.poetica.com',
    'production' => 'https://poetica.com',
  );

  function Plugin_PoeticaEditor() {
    add_action('add_meta_boxes', array($this, 'add_post_meta_box' ));
    add_action('admin_menu', array($this, 'add_settings_menu'));
    add_action('manage_posts_custom_column' , array($this, 'write_custom_columns'), 10, 2 );
    add_action('manage_pages_custom_column' , array($this, 'write_custom_columns'), 10, 2 );
    add_action('admin_notices', array($this, 'admin_notices'));
    add_action('init', array($this, 'init'));
    add_action('admin_init', array($this, 'admin_init'));
    add_action('admin_head', array($this, 'write_admin_head'));
    add_action('wp_insert_post', array($this, 'insert_post'), 10, 3);
    add_action('admin_enqueue_scripts', array($this, 'enqueue_and_register_scripts'));

    add_filter('load-post-new.php', array($this, 'check_settings'));
    add_filter('the_editor', array($this, 'write_editor'));
    add_filter('wp_editor_settings', array($this, 'update_editor_settings'));
    add_filter('manage_posts_columns', array($this, 'add_column')); 
    add_filter('manage_pages_columns', array($this, 'add_column'));
    add_filter('update_post_metadata', array($this, 'stop_locking'), 10, 5);
    add_action('admin_head-edit.php', array($this, 'write_css'));
    add_action('admin_head-post.php', array($this, 'write_css'));
    add_action('admin_head-post-new.php', array($this, 'write_css'));

    register_activation_hook( __FILE__, array($this, 'activate'));
    register_deactivation_hook( __FILE__, array($this, 'deactivate'));
  }

  function enqueue_and_register_scripts() {
    wp_enqueue_script('poetica', plugins_url('poetica.js', __FILE__), array('jquery'));
  }

  function get_post_target_origin($docUrl = null) {
    if (!$docUrl) {
        global $post;
        if (!$post) return;
        $docUrl = get_post_meta($post->ID, 'poeticaLocation', true);
    }
    if (!$docUrl) return;
    $parts = parse_url($docUrl);
    $docDomain = $parts['scheme'].'://'.$parts['host'];
    $port = null;
    if (isset($parts['port']))
        $port = $parts['port'];

    if ($port) {
        $docDomain .= ":$port";
    }
    return $docDomain;
  }

  function activate() {
    add_option('poetica_activation_redirect', true);
    wp_remote_get($this->domains[self::ENV].'/api/track.json?category=wpplugin&action=installed', array());
  }

  function admin_notices() {
    if (get_option('poetica_notice', false)) {
      $notice = get_option('poetica_notice');
      delete_option('poetica_notice');
      echo "<div class='updated'><p>$notice</p></div>";
    }
  }

  function init() {
    # If the url begins wp-admin/poetica/ then load response from relevant PHP file
    $admin_url = parse_url(admin_url(), PHP_URL_PATH);
    $poetica_admin_url = $admin_url.'poetica';
    $request_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

    if (strpos($request_path.'/', $poetica_admin_url) === 0) {
      $script_file = str_replace($poetica_admin_url, dirname(__FILE__), $request_path);
      require $script_file;
      exit();
    }
  }

  function admin_init() {
    if (get_option('poetica_activation_redirect', false)) {
      delete_option('poetica_activation_redirect');
      exit( wp_redirect( admin_url( 'options-general.php?page=poetica_plugin' ) ) );
    }
  }

  function deactivate() {
    delete_option('poetica_group_access_token');
    delete_option('poetica_verification_token');

    $users = get_users(
      array(
        'meta_query'  => array(
          'key'     => 'poetica_user_access_token',
          'value'   => '',
          'compare' => '>',
        )
      )
    );
    foreach ( $users as $user ) {
      delete_user_option($user->ID, 'poetica_user_access_token');
    }

    $posts_array = get_posts(
      array(
        'post_status' =>'any',
        'meta_key'   => 'poeticaLocation',
      )
    );
    foreach($posts_array as $post) {
      delete_post_meta($post->ID, 'poeticaLocation');
    }

    wp_remote_get($this->domains[self::ENV].'/api/track.json?category=wpplugin&action=uninstalled', array());
  }

  function stop_locking($null, $object_id, $meta_key, $meta_value, $prev_value) {
    if($meta_key == '_edit_lock')
      return false;
  }

  function write_admin_head() {
    global $post;

    if (!$post) {
      // Yes this is horrible but it creates an empty WP_Post instance
      $post = new WP_Post((object)array());
    }

    $group = get_option('poetica_group_access_token');
    $verification_token = get_option('poetica_verification_token');
    if((!isset($group) || trim($group)==='') && (!isset($verification_token) || trim($verification_token)==='')) {
      // Create uuid verification token
      $uuid = uniqid();
      update_option('poetica_verification_token', $uuid);
    }

    global $current_user;
    get_currentuserinfo();
    $poeticaLocation = get_post_meta($post->ID, 'poeticaLocation', true);

    $data = array(
      'docDomain'      => $this->get_post_target_origin(),
      'tinyMCEUrl'     => admin_url('poetica/poetica-tinymce.php')."?post=$post->ID",
      'poeticaDomain'  => $this->domains[self::ENV],
      'groupDomain'    => $this->protocols[self::ENV].get_option('poetica_group_subdomain').'.'.$this->hosts[self::ENV],
      'group_auth'     => array(
          'verification_token' => get_option('poetica_verification_token'),
          'verifyUrl' => admin_url('poetica/poetica-verify.php'),
          'saveUrl' => admin_url('poetica/poetica-save.php'),
      ),
      'user_auth'      => array(
            "group_access_token" => get_option('poetica_group_access_token'),
            "email" => $current_user->user_email,
            "name" => $current_user->display_name,
            "username" => $current_user->user_login,
            "userid" => $current_user->ID
      ),
      'poeticaLocation' => $poeticaLocation
    );
    ?>
    <script type="text/javascript">
        var poetica = new Poetica(<?= json_encode($data)?>);
    </script>
    <?php
    if(!$post)
      return;
    ?><style>
      <?php
        // Hide preview button on published posts
        if(get_post_status($post->ID) === 'publish') {
          ?>#minor-publishing-actions {display:none}<?php
        }
      ?></style>
    <?php
  }

  function write_css() {
    ?>
    <style type="text/css">
      .widefat .column-poetica {
        width: 1em;
      }
      .widefat .column-poetica img {
        margin-top: 2px;
      }
      img.poetica-logo {
        vertical-align: sub;
      }
      .poetica-iframe {
        width:100%;
        height: 600px;
        background-color: white;
        border: 1px solid #e5e5e5;
        margin-top: 1em;
      }
      body.focus-on .poetica-iframe {
      }
      #poetica-dfw:focus {
        box-shadow: none;
      }
      body.focus-on #poetica-dfw {
        background: #eee;
        border-color: #999;
        color: #32373c;
        -webkit-box-shadow: inset 0 2px 5px -3px rgba(0,0,0,.5);
        box-shadow: inset 0 2px 5px -3px rgba(0,0,0,.5);
      }
      #post-status-info {
        display: none;
      }
      #wp-pointer-0 {
        display: none !important;
      }
      #poetica_settings > .inside {
        padding-top: 5px;
      }

      .poetica-modal-background {
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.5);
      }

      .poetica-modal {
        position: absolute;
        background-color: white;
        width: 700px;
        height: 200px;
        top: 0;
        bottom: 0;
        left: 0;
        right: 0;
        margin: auto;
        padding: 1em;
        border: #0073aa solid 1px;
        box-shadow: 0 0 10px #0073aa;
      }
    </style>
    <?php
  }

  function poetica_not_reachable_notice() {
    ?>
      <div class="error">
          <p><?php _e( 'Error! Could not connect to Poetica If this error persists contact us on help@poeti.ca' ); ?></p>
      </div>
    <?php
  }

  function insert_post($post_ID, $post, $update) {
    if (!$update) {
      error_log('Creating poetica draft');
      if (!get_user_option('poetica_user_access_token', false)) {
        return;
      }
      $url = $this->domains[self::ENV].'/api/drafts/create.json';
      $data = array(
        'content' => $post->post_content,
        'webhook' => admin_url('poetica/poetica-api.php').'?post='.$post->ID,
        'meta' => array(
          'title' => 'WordPress post '.$post->ID,
          'group_access_token' => get_option('poetica_group_access_token'),
          'user_access_token' => get_user_option('poetica_user_access_token'),
        )
      );

      $options = array(
        'headers'  => array("Content-type" => "application/x-www-form-urlencoded"),
        'body' => http_build_query($data),
      );

      $response = wp_remote_post($url, $options);
      if (is_wp_error($response)) {
        error_log($response->get_error_message());
        add_action( 'admin_notices', array($this, 'poetica_not_reachable_notice'));
        return;
      }
      $headers = $response['headers'];
      $docUrl = $headers['location']; 
      error_log("Created poetica draft at location: $docUrl");
      update_post_meta($post->ID, 'poeticaLocation', $docUrl, true);
    }
  }

  function write_custom_columns($column, $post_id) {
    switch($column) {
      case 'poetica':
        $logo_url = plugins_url('logo_16x16.png', __FILE__);
        $loc = get_post_meta($post_id, 'poeticaLocation', true);
        if(isset($loc) && !empty($loc)) {
            echo "<img src='$logo_url'/>";
        }
        break;
    }
  }

  function add_column($columns) {
    $columns = array_slice( $columns, 0, 1, true) +
               array('poetica' => __('')) +
               array_slice( $columns, 1, count($columns), true);

    return $columns;
  }

  function write_group_link_button() {
    if(get_option('poetica_group_access_token') == '') { 
      submit_button('Connect to Poetica', 'primary', 'submit', true, array('id'=>'poetica-group-link'));
      ?>
      <div>The Poetica editor is loaded from poetica.com into your
      WordPress. This gives us permission to connect the two.</div>
      <?php
    } else if(get_user_option('poetica_user_access_token') == '') { 
      echo '<p><a id="poetica-user-link" href="#">Link to user</a></p>';
    } else if(get_option('poetica_group_subdomain', false)) {
      $siteUrlParts = parse_url(get_site_url());
      $siteUrl = $siteUrlParts['scheme'].'://'.$siteUrlParts['host'];
      if(!empty($siteUrlParts['port'])) {
        $siteUrl = $siteUrl.':'.$siteUrlParts['port'];
      }
      echo '<iframe class="poetica-iframe" id="slack" src="'.$this->protocols[self::ENV].get_option('poetica_group_subdomain').'.'.$this->hosts[self::ENV].'/slack?frame=wpplugin&access_token='.get_user_option('poetica_user_access_token').'&parentOrigin='.$siteUrl.'"></iframe>';
    } else {
      echo 'Poetica connected and ready to use';
    }
  }

  function write_settings_page() {
    ?>
        <div class="wrap">
            <h2>Poetica settings</h2>
            <?=$this->write_group_link_button();?>
        </div>
    <?php
  }

  function add_settings_menu() {
      $plugin_page = add_options_page( 'Poetica Settings', 'Poetica', 'manage_options', 'poetica_plugin', array($this, 'write_settings_page'));
      add_action('admin_head-'. $plugin_page, array($this, 'write_css'));
  }

  function write_meta_box_content() {
    // TODO Create when new. Open when existingadmin_url( 'admin-post.php' )
    ?>

    <button id='poetica-tinymce' class='button'>Switch to WordPress editor</button>

    <?php
  }

  function add_post_meta_box() {
    global $post;
    if ($post and get_post_meta($post->ID, 'poeticaLocation', true)) {
      $logo_url = plugins_url('logo_16x16.png', __FILE__);
      add_meta_box( 'poetica_settings', __( "<img class='poetica-logo' src='$logo_url'/> Poetica", 'textdomain' ), array($this, 'write_meta_box_content'), 'post', 'side', 'high' );
      add_meta_box( 'poetica_settings', __( "<img class='poetica-logo' src='$logo_url'/> Poetica", 'textdomain' ), array($this, 'write_meta_box_content'), 'page', 'side', 'high' );
    }
  }

  function check_settings() {
      if(!get_option('poetica_group_access_token', false)) {
        // Redirect to options
        exit( wp_redirect( admin_url( 'options-general.php?page=poetica_plugin' ) ) );
      }
  }

  function update_editor_settings($settings) {
    global $post;
    if ($post and get_post_meta($post->ID, 'poeticaLocation', true)) {
      $settings['media_buttons'] = false;
      $settings['quicktags'] = false;
    }
    return $settings;
  }

  function write_editor($editor) {
    if (!strpos($editor, "wp-content-editor-container")) {
      return $editor;
    }

    global $post;
    $accessToken = get_user_option('poetica_user_access_token', false);

    if (!$accessToken) {
      return $this->write_user_connect($editor);
    }

    if (!$post or !get_post_meta($post->ID, 'poeticaLocation', true)) {
      return $editor;
    }

    $docUrl = get_post_meta( $post->ID, 'poeticaLocation', true );

    if(!$docUrl) {
      return $editor;
    } 
    $docUrl = str_replace(".json",'',$docUrl);

    $docDomain = $this->get_post_target_origin($docUrl);
    ?>
    <a href="#" id="poetica-dfw" class="button button-small"><i class="mce-ico mce-i-dfw"></i> Distraction free mode</a><iframe class="poetica-iframe" src='<?="$docUrl?frame=wpplugin&access_token=$accessToken"?>'></iframe><?php
  }

  function write_user_connect($editor) {
    ?>
    <div class="poetica-modal-background">
    <div class="poetica-modal">
        <h2>Connect to Poetica</h2>
        <?= submit_button('Activate Poetica for me', 'primary', 'submit', true, array('id'=>'poetica-user-link')); ?>
        <div><p>We need your permission to load the editor, as we use your WordPress
        email address to let other users know what changes you've made or
        suggested to posts</p></div>
    </div>
    </div>
    <?php
    return $editor;
  }
}

$poetica_plugin = new Plugin_PoeticaEditor();
