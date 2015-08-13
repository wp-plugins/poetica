<?php
    global $poetica_plugin;
    $post_id = intval($_GET['post']);
    $post = get_post($post_id);

    unset($post->ID);
    unset($post->guid);
    unset($post->post_status);

    // This stops a poetica draft being created and poeticaLocation being set.
    // It ain't pretty but it works.
    remove_action('wp_insert_post', array($poetica_plugin, 'insert_post'), 10, 3);

    $id = wp_insert_post($post);

    $location = get_edit_post_link($id, '');
    exit(wp_safe_redirect($location));
?>
