<?php
/*
Plugin Name: Auto Image to Post
Description: Automatically assigns images to posts based on naming conventions and updates alt text using the ChatGPT API.
Version: 1.0
Author: Preston Gregg
*/

// Register activation hook to set default options
function aitp_activate() {
    if (get_option('aitp_logging_enabled') === false) {
        add_option('aitp_logging_enabled', '0');
    }
    if (get_option('aitp_post_limit') === false) {
        add_option('aitp_post_limit', '');
    }
    if (get_option('aitp_post_select') === false) {
        add_option('aitp_post_select', '');
    }
}
register_activation_hook(__FILE__, 'aitp_activate');

// Add a settings page to manage the post limit and logging
function aitp_settings_page() {
    add_options_page(
        'Auto Image to Post Settings',
        'Auto Image to Post',
        'manage_options',
        'auto-image-to-post',
        'aitp_settings_page_html'
    );
}
add_action('admin_menu', 'aitp_settings_page');

// Settings page HTML
function aitp_settings_page_html() {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (isset($_POST['aitp_logging_enabled']) || isset($_POST['aitp_post_limit']) || isset($_POST['aitp_post_select']) || isset($_POST['clear_log']) || isset($_POST['download_log']) || isset($_POST['sync_images'])) {
        check_admin_referer('aitp_settings');
        if (isset($_POST['aitp_logging_enabled'])) {
            update_option('aitp_logging_enabled', isset($_POST['aitp_logging_enabled']) ? '1' : '0');
            echo '<div class="updated"><p>Logging settings saved.</p></div>';
        }
        if (isset($_POST['aitp_post_limit'])) {
            update_option('aitp_post_limit', sanitize_text_field($_POST['aitp_post_limit']));
            echo '<div class="updated"><p>Post limit saved.</p></div>';
        }
        if (isset($_POST['aitp_post_select'])) {
            update_option('aitp_post_select', sanitize_text_field($_POST['aitp_post_select']));
            echo '<div class="updated"><p>Post selection saved.</p></div>';
        }
        if (isset($_POST['clear_log'])) {
            $upload_dir = wp_upload_dir();
            $log_file = $upload_dir['basedir'] . '/aitp_log.txt';
            file_put_contents($log_file, '');
            echo '<div class="updated"><p>Log file cleared.</p></div>';
        }
        if (isset($_POST['download_log'])) {
            $upload_dir = wp_upload_dir();
            $log_file = $upload_dir['basedir'] . '/aitp_log.txt';
            if (file_exists($log_file)) {
                header('Content-Description: File Transfer');
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . basename($log_file) . '"');
                header('Expires: 0');
                header('Cache-Control: must-revalidate');
                header('Pragma: public');
                header('Content-Length: ' . filesize($log_file));
                readfile($log_file);
                exit;
            }
        }
        if (isset($_POST['sync_images'])) {
            aitp_sync_images();
            echo '<div class="updated"><p>Tile and featured images synchronized.</p></div>';
        }
    }

    $logging_enabled = get_option('aitp_logging_enabled', false);
    $post_limit = get_option('aitp_post_limit', '');
    $selected_post_id = get_option('aitp_post_select', '');

    $posts = get_posts([
        'post_type' => 'consulting-services',
        'numberposts' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
    ]);

    $selected_post_title = 'All posts';
    if ($selected_post_id) {
        $selected_post = get_post($selected_post_id);
        if ($selected_post) {
            $selected_post_title = $selected_post->post_title;
        }
    }

    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/aitp_log.txt';
    $log_content = file_exists($log_file) ? file_get_contents($log_file) : 'Log file is empty or does not exist.';

    ?>
    <div class="wrap">
        <h1>Auto Image to Post Settings</h1>
        <form method="post" action="">
            <?php wp_nonce_field('aitp_settings'); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><label for="aitp_logging_enabled">Enable Logging</label></th>
                    <td>
                        <input type="checkbox" id="aitp_logging_enabled" name="aitp_logging_enabled" value="1" <?php checked($logging_enabled, true); ?>>
                        <p class="description">Enable or disable logging of events.</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="aitp_post_limit">Post ID Limit</label></th>
                    <td>
                        <input type="text" id="aitp_post_limit" name="aitp_post_limit" value="<?php echo esc_attr($post_limit); ?>" class="regular-text">
                        <p class="description">Enter a post ID or a comma-separated list of post IDs (e.g., 1,2,3) or a range of post IDs (e.g., 1-10).</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="aitp_post_select">Select Post</label></th>
                    <td>
                        <select id="aitp_post_select" name="aitp_post_select">
                            <option value="">-- All Posts --</option>
                            <?php foreach ($posts as $post): ?>
                                <option value="<?php echo esc_attr($post->ID); ?>" <?php selected($selected_post_id, $post->ID); ?>><?php echo esc_html($post->post_title); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Optionally select a post to limit functionality.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>

        <form method="post" action="">
            <?php wp_nonce_field('aitp_settings'); ?>
            <input type="hidden" name="sync_images" value="1">
            <?php submit_button('Sync Tile and Featured Images'); ?>
        </form>

        <h2>Selected Post: <?php echo esc_html($selected_post_title); ?></h2>

        <h2>Log File</h2>
        <textarea readonly rows="20" cols="100"><?php echo esc_textarea($log_content); ?></textarea>
        <form method="post" action="">
            <?php wp_nonce_field('aitp_settings'); ?>
            <input type="hidden" name="clear_log" value="1">
            <?php submit_button('Clear Log'); ?>
        </form>
        <form method="post" action="">
            <?php wp_nonce_field('aitp_settings'); ?>
            <input type="hidden" name="download_log" value="1">
            <?php submit_button('Download Log'); ?>
        </form>
        <button id="refresh-log">Refresh Log</button>
    </div>

    <script>
        document.getElementById('refresh-log').addEventListener('click', function() {
            location.reload();
        });
    </script>
    <?php
}

// Function to sync tile_image and featured image
function aitp_sync_images() {
    $post_limit = get_option('aitp_post_limit', '');
    $selected_post_id = get_option('aitp_post_select', '');

    $args = [
        'post_type' => 'consulting-services',
        'posts_per_page' => -1,
    ];

    if (!empty($post_limit)) {
        $args['post__in'] = explode(',', $post_limit);
    }

    if (!empty($selected_post_id)) {
        $args['post__in'] = [$selected_post_id];
    }

    $posts = get_posts($args);

    foreach ($posts as $post) {
        $post_id = $post->ID;

        // Handle tile_image and featured image synchronization
        $tile_image = get_post_meta($post_id, 'tile_image', true);
        $featured_image = get_post_meta($post_id, '_thumbnail_id', true);

        if (empty($tile_image) && !empty($featured_image)) {
            // If tile_image is empty but a featured image exists, set tile_image to the featured image
            update_post_meta($post_id, 'tile_image', $featured_image);
            aitp_log("Set tile_image for post {$post_id} to the featured image {$featured_image}");
        } elseif (!empty($tile_image)) {
            // If tile_image exists and is different from the featured image, update the featured image
            if ($tile_image != $featured_image) {
                update_post_meta($post_id, '_thumbnail_id', $tile_image);
                aitp_log("Updated featured image for post {$post_id} to the tile_image {$tile_image}");
            }
        }
    }
}

// Function to log events
function aitp_log($message) {
    $logging_enabled = get_option('aitp_logging_enabled', false);
    if (!$logging_enabled) {
        return;
    }

    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/aitp_log.txt';

    $time = current_time('Y-m-d H:i:s');
    $log_entry = "{$time} - {$message}\n";

    file_put_contents($log_file, $log_entry, FILE_APPEND);
}

// Helper function to check if a post ID is within a range
function in_range($post_id, $range) {
    if (preg_match('/^(\d+)-(\d+)$/', $range, $matches)) {
        return $post_id >= $matches[1] && $post_id <= $matches[2];
    }
    return false;
}
?>
