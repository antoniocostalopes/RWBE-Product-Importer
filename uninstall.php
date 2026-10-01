<?php
/**
 * Uninstall routine for RWBE Product Importer.
 *
 * Runs only when the plugin is deleted from the Plugins screen, never on
 * deactivation. It removes what the plugin created about itself:
 *
 *   - its options (including the API token) and transients
 *   - the wp_rwbe_fitment table
 *   - its scheduled events
 *   - the two directories it writes under uploads/ (debug log, vehicle map)
 *
 * It deliberately does NOT touch the catalogue: products, attachments, categories,
 * brands and the pa_* attribute taxonomies are the site's content, not the
 * plugin's bookkeeping, and deleting tens of thousands of products because
 * somebody removed an importer would be indefensible. Re-installing and running
 * an import picks the catalogue back up.
 *
 * @since 1.2.4
 */

// Only ever reached through WordPress's uninstall mechanism.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    die;
}

/**
 * Remove every trace of the plugin from the current site.
 *
 * @return void
 */
function rwbe_uninstall_current_site() {
    global $wpdb;

    // Options are matched by the plugin's own prefix rather than listed one by one,
    // so an option added later is not left behind by an uninstall routine nobody
    // remembered to update.
    $like = $wpdb->esc_like('rwbe_') . '%';
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE %s
                OR option_name LIKE %s
                OR option_name LIKE %s",
            $like,
            '_transient_' . $like,
            '_transient_timeout_' . $like
        )
    );

    // The fitment table is per-site, like the prefix it is built from.
    $table = $wpdb->prefix . 'rwbe_fitment';
    $wpdb->query("DROP TABLE IF EXISTS `{$table}`");

    // Scheduled work must go, or WordPress keeps firing hooks nothing listens to.
    $hooks = array(
        'rwbe_product_import_cron',
        'rwbe_check_interrupted_imports',
        'rwbe_ph_cleanup_cron',
        'rwbe_fitment_backfill',
        'rwbe_vehicle_map_rebuild',
    );
    foreach ($hooks as $hook) {
        wp_clear_scheduled_hook($hook);
    }

    // Generated files: the debug log and the static vehicle map. Both are caches the
    // plugin rebuilds, so there is nothing here worth keeping.
    rwbe_uninstall_delete_upload_dir('rwbe-logs');
    rwbe_uninstall_delete_upload_dir('rwbe-vehicle-map');

    wp_cache_flush();
}

/**
 * Recursively delete one of the plugin's own directories inside wp-content/uploads.
 *
 * WP_Filesystem is not used on purpose: an uninstall has no UI in which to answer a
 * credentials prompt, so a filesystem method other than 'direct' would simply fail.
 * Instead the path is resolved and then checked to be inside the uploads base and to
 * be exactly the expected folder, so a surprising uploads configuration cannot turn
 * this into a delete of something else.
 *
 * @param string $name Directory name directly under the uploads base.
 * @return void
 */
function rwbe_uninstall_delete_upload_dir($name) {
    $uploads = wp_get_upload_dir();
    if (!empty($uploads['error']) || empty($uploads['basedir'])) {
        return;
    }

    $base = realpath($uploads['basedir']);
    $dir  = realpath(trailingslashit($uploads['basedir']) . $name);

    if ($base === false || $dir === false) {
        return;
    }

    // Containment: $dir must sit directly under the uploads base and carry the name
    // we expect. Anything else is not ours to delete.
    if ($dir === $base || dirname($dir) !== $base || basename($dir) !== $name) {
        return;
    }

    rwbe_uninstall_rmdir($dir);
}

/**
 * Delete a directory and everything inside it.
 *
 * @param string $dir Absolute path, already validated by the caller.
 * @return void
 */
function rwbe_uninstall_rmdir($dir) {
    if (!is_dir($dir)) {
        return;
    }

    $items = scandir($dir);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        // Never follow a symlink out of the directory being removed.
        if (is_link($path)) {
            @unlink($path);
        } elseif (is_dir($path)) {
            rwbe_uninstall_rmdir($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($dir);
}

// Every site on a network keeps its own options, table and files.
if (is_multisite()) {
    $sites = get_sites(array('fields' => 'ids', 'number' => 0));
    foreach ($sites as $site_id) {
        switch_to_blog($site_id);
        rwbe_uninstall_current_site();
        restore_current_blog();
    }
} else {
    rwbe_uninstall_current_site();
}
