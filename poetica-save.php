<?php
global $current_user;
get_currentuserinfo();

if (isset($_GET['group_access_token']))
    $group_access_token = sanitize_text_field($_GET['group_access_token']);
    update_option('poetica_group_access_token', $group_access_token );

if (isset($_GET['group_subdomain']))
    $group_subdomain = sanitize_text_field($_GET['group_subdomain']);
    update_option('poetica_group_subdomain', $group_subdomain );

if (isset($_GET['user_access_token']))
    $user_access_token = sanitize_text_field($_GET['user_access_token']);
    update_user_option($current_user->ID, 'poetica_user_access_token', $user_access_token);

delete_option('poetica_verification_token');

add_option('poetica_notice', 'Poetica has successfully been connected.');

// Send them where they were trying to go
if (isset($_GET['redirect'])) {
    exit(wp_redirect($_GET['redirect']));
} else {
    exit( wp_redirect( admin_url() ) );
}
?>
