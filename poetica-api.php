<?php
$json = file_get_contents('php://input');
$obj = json_decode($json);

$post_id = intval($_GET['post']);
$post = get_post($post_id);
$post->post_content = $obj->content;

wp_update_post( $post );
error_log('updated '.$post->ID.' to '.$obj->content);

var_dump($post->post_content);
?>
