<?php
/**
 * Static vehicle map (make → model → year) for the front-end filters.
 *
 * The chained dropdowns used to ask admin-ajax.php for every step. Each of those
 * requests boots WordPress + WooCommerce + the theme (~310 ms measured) to run a
 * ~30 ms query, and competes with cron/imports for the same PHP-FPM workers.
 *
 * This class pre-computes the whole relationship map once per import and writes it
 * to two JSON files under uploads/. The browser then reads them straight from the
 * web server — no PHP, no database, no worker contention.
 *
 * Measured payloads on the production dataset (35k products, 67 makes, 1789 models):
 *   makes-models.json  108 KB raw /  22 KB gzip
 *   model-years.json   576 KB raw /  24 KB gzip
 *
 * The files are an optimisation, never a requirement: when they are missing or
 * stale the JavaScript falls back to the existing AJAX endpoints, so the filters
 * keep working on a fresh install, on an unwritable uploads dir, or before the
 * first import has finished.
 *
 * @since 1.3.0
 */

if (!defined('WPINC')) {
    die;
}

class RWBE_Vehicle_Map {

    /** Sub-directory created inside wp-content/uploads */
    const DIR = 'rwbe-vehicle-map';

    /** File names */
    const FILE_MODELS = 'makes-models.json';
    const FILE_YEARS  = 'model-years.json';

    /** Option holding the build timestamp (used for cache busting) */
    const OPT_VERSION = 'rwbe_vehicle_map_version';

    /** Option recording which data source the last build used ('fitment'|'taxonomy') */
    const OPT_SOURCE = 'rwbe_vehicle_map_source';

    /** Transient guarding against concurrent/looping rebuilds */
    const LOCK = 'rwbe_vehicle_map_building';

    /** Taxonomies the map is built from */
    const TAX_MAKE  = 'pa_make';
    const TAX_MODEL = 'pa_model';
    const TAX_YEAR  = 'pa_vehicle_year';

    /**
     * Absolute path of the map directory, or '' when uploads are unavailable.
     *
     * @return string Path with a trailing slash, or ''.
     */
    public static function dir_path() {
        $uploads = wp_get_upload_dir();
        if (!empty($uploads['error']) || empty($uploads['basedir'])) {
            return '';
        }
        return trailingslashit($uploads['basedir']) . self::DIR . '/';
    }

    /**
     * Public base URL of the map directory, or '' when uploads are unavailable.
     *
     * @return string URL with a trailing slash, or ''.
     */
    public static function dir_url() {
        $uploads = wp_get_upload_dir();
        if (!empty($uploads['error']) || empty($uploads['baseurl'])) {
            return '';
        }
        return trailingslashit($uploads['baseurl']) . self::DIR . '/';
    }

    /**
     * URLs of the two map files for wp_localize_script().
     *
     * Returns empty strings for files that do not exist yet, which is the signal
     * the front-end uses to fall back to the AJAX endpoints.
     *
     * @return array {
     *     @type string $models  URL of makes-models.json, or ''.
     *     @type string $years   URL of model-years.json, or ''.
     *     @type int    $version Build timestamp (0 when never built).
     * }
     */
    public static function get_urls() {
        $path = self::dir_path();
        $url  = self::dir_url();
        $ver  = (int) get_option(self::OPT_VERSION, 0);

        if ($path === '' || $url === '') {
            return array('models' => '', 'years' => '', 'version' => 0);
        }

        $suffix = $ver > 0 ? '?v=' . $ver : '';

        return array(
            'models'  => file_exists($path . self::FILE_MODELS) ? $url . self::FILE_MODELS . $suffix : '',
            'years'   => file_exists($path . self::FILE_YEARS) ? $url . self::FILE_YEARS . $suffix : '',
            'version' => $ver,
        );
    }

    /**
     * True when both map files are on disk.
     *
     * @return bool
     */
    public static function exists() {
        $urls = self::get_urls();
        return $urls['models'] !== '' && $urls['years'] !== '';
    }

    /**
     * Queue a background rebuild when the files are missing.
     *
     * Called from the front-end renderers. It never builds inline — that would put
     * the cost back into a visitor's request, which is exactly what this class
     * exists to avoid. Until the files appear the JavaScript uses the AJAX fallback.
     *
     * @return void
     */
    public static function maybe_schedule_rebuild() {
        if (self::exists() || get_transient(self::LOCK)) {
            return;
        }
        if (!wp_next_scheduled('rwbe_vehicle_map_rebuild')) {
            wp_schedule_single_event(time(), 'rwbe_vehicle_map_rebuild');
        }
    }

    /**
     * Rebuild both map files from the current product data.
     *
     * Safe to call at any time: it takes a short lock, writes to temporary files
     * and renames them into place, so readers never see a half-written file. On
     * any failure the previous files are left untouched and the front-end simply
     * keeps using them (or the AJAX fallback).
     *
     * @param bool $force Ignore the lock (used by the manual admin action).
     * @return bool True when both files were written.
     */
    public static function rebuild($force = false) {
        if (!$force && get_transient(self::LOCK)) {
            return false; // Another rebuild is already running.
        }
        set_transient(self::LOCK, 1, 5 * MINUTE_IN_SECONDS);

        $ok = false;
        try {
            $dir = self::dir_path();
            if ($dir === '' || !wp_mkdir_p($dir)) {
                self::log('Cannot create the vehicle map directory', array('dir' => $dir));
                delete_transient(self::LOCK);
                return false;
            }

            // Prefer the fitment table: it keeps make, model and year together, so
            // the dropdowns stop mixing models from other makes. Until it covers
            // the catalogue, fall back to the taxonomy-derived lists.
            $use_fitment = class_exists('RWBE_Fitment') && RWBE_Fitment::is_ready();

            if ($use_fitment) {
                $models = RWBE_Fitment::get_model_map();
                $years  = RWBE_Fitment::get_year_map();
            } else {
                $models = self::query_models();
                $years  = self::query_years();
            }

            // Never overwrite a good map with an empty one (e.g. mid-import, or a
            // taxonomy that momentarily has no terms).
            if (empty($models)) {
                self::log('Vehicle map rebuild skipped: no make/model pairs found');
                delete_transient(self::LOCK);
                return false;
            }

            $source = $use_fitment ? 'fitment' : 'taxonomy';

            $wrote_models = self::write_json($dir . self::FILE_MODELS, array(
                'generated' => time(),
                'source'    => $source,
                'models'    => $models,
            ));
            $wrote_years = self::write_json($dir . self::FILE_YEARS, array(
                'generated' => time(),
                'source'    => $source,
                'years'     => $years,
            ));

            $ok = $wrote_models && $wrote_years;
            if ($ok) {
                update_option(self::OPT_VERSION, time(), false);
                update_option(self::OPT_SOURCE, $source, false);
                self::log('Vehicle map rebuilt', array(
                    'source' => $source,
                    'makes' => count($models),
                    'pairs' => count($years),
                ));
            } else {
                self::log('Vehicle map rebuild failed while writing files');
            }
        } catch (Exception $e) {
            self::log('Vehicle map rebuild threw', array('error' => $e->getMessage()));
        }

        delete_transient(self::LOCK);
        return $ok;
    }

    /**
     * Rebuild the map at the end of an import.
     *
     * Hooked after the transient flushers so the freshly imported makes, models and
     * years are the ones written to disk.
     *
     * @return void
     */
    public static function rebuild_after_import() {
        self::rebuild(true);
    }

    /**
     * Delete the generated files (used when uninstalling / resetting).
     *
     * @return void
     */
    public static function delete_files() {
        $dir = self::dir_path();
        if ($dir === '') {
            return;
        }
        foreach (array(self::FILE_MODELS, self::FILE_YEARS) as $file) {
            if (file_exists($dir . $file)) {
                @unlink($dir . $file);
            }
        }
        delete_option(self::OPT_VERSION);
        delete_option(self::OPT_SOURCE);
    }

    /**
     * Every distinct make → model pair on a published product.
     *
     * @return array Map of make slug => list of ['slug' => ..., 'name' => ...]
     */
    private static function query_models() {
        global $wpdb;

        if (!taxonomy_exists(self::TAX_MAKE) || !taxonomy_exists(self::TAX_MODEL)) {
            return array();
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT tmk.slug AS make_slug, tmd.slug AS model_slug, tmd.name AS model_name
             FROM {$wpdb->term_relationships} r1
             INNER JOIN {$wpdb->term_taxonomy} x1 ON x1.term_taxonomy_id = r1.term_taxonomy_id AND x1.taxonomy = %s
             INNER JOIN {$wpdb->terms} tmk ON tmk.term_id = x1.term_id
             INNER JOIN {$wpdb->term_relationships} r2 ON r2.object_id = r1.object_id
             INNER JOIN {$wpdb->term_taxonomy} x2 ON x2.term_taxonomy_id = r2.term_taxonomy_id AND x2.taxonomy = %s
             INNER JOIN {$wpdb->terms} tmd ON tmd.term_id = x2.term_id
             INNER JOIN {$wpdb->posts} p ON p.ID = r1.object_id AND p.post_type = 'product' AND p.post_status = 'publish'
             GROUP BY tmk.slug, tmd.slug, tmd.name
             ORDER BY tmk.slug ASC, tmd.name ASC",
            self::TAX_MAKE,
            self::TAX_MODEL
        ));

        $out = array();
        if ($rows) {
            foreach ($rows as $row) {
                $out[$row->make_slug][] = array('slug' => $row->model_slug, 'name' => $row->model_name);
            }
        }
        return $out;
    }

    /**
     * Every distinct make + model → year combination on a published product.
     *
     * Keyed by "make-slug|model-slug" so the browser can look a list up directly.
     * Years are ordered newest first, matching the previous AJAX behaviour.
     *
     * @return array Map of "make|model" => list of ['slug' => ..., 'name' => ...]
     */
    private static function query_years() {
        global $wpdb;

        if (!taxonomy_exists(self::TAX_MAKE) || !taxonomy_exists(self::TAX_MODEL) || !taxonomy_exists(self::TAX_YEAR)) {
            return array();
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT tmk.slug AS make_slug, tmd.slug AS model_slug, tyr.slug AS year_slug, tyr.name AS year_name
             FROM {$wpdb->term_relationships} r1
             INNER JOIN {$wpdb->term_taxonomy} x1 ON x1.term_taxonomy_id = r1.term_taxonomy_id AND x1.taxonomy = %s
             INNER JOIN {$wpdb->terms} tmk ON tmk.term_id = x1.term_id
             INNER JOIN {$wpdb->term_relationships} r2 ON r2.object_id = r1.object_id
             INNER JOIN {$wpdb->term_taxonomy} x2 ON x2.term_taxonomy_id = r2.term_taxonomy_id AND x2.taxonomy = %s
             INNER JOIN {$wpdb->terms} tmd ON tmd.term_id = x2.term_id
             INNER JOIN {$wpdb->term_relationships} r3 ON r3.object_id = r1.object_id
             INNER JOIN {$wpdb->term_taxonomy} x3 ON x3.term_taxonomy_id = r3.term_taxonomy_id AND x3.taxonomy = %s
             INNER JOIN {$wpdb->terms} tyr ON tyr.term_id = x3.term_id
             INNER JOIN {$wpdb->posts} p ON p.ID = r1.object_id AND p.post_type = 'product' AND p.post_status = 'publish'
             GROUP BY tmk.slug, tmd.slug, tyr.slug, tyr.name
             ORDER BY tmk.slug ASC, tmd.slug ASC, (tyr.name + 0) DESC",
            self::TAX_MAKE,
            self::TAX_MODEL,
            self::TAX_YEAR
        ));

        // Year slugs and names are normally identical ("2010"), so those entries are
        // written as bare strings — that alone takes this file from ~2.3 MB down to
        // ~0.7 MB. Entries where they differ keep the {slug, name} shape, and the
        // JavaScript accepts both.
        $out = array();
        if ($rows) {
            foreach ($rows as $row) {
                $key = $row->make_slug . '|' . $row->model_slug;
                $out[$key][] = ($row->year_slug === $row->year_name)
                    ? $row->year_slug
                    : array('slug' => $row->year_slug, 'name' => $row->year_name);
            }
        }
        return $out;
    }

    /**
     * Write JSON atomically (temp file + rename) so readers never see a partial file.
     *
     * @param string $path Destination path.
     * @param array  $data Payload.
     * @return bool
     */
    private static function write_json($path, $data) {
        // Unescaped unicode/slashes keep the files roughly a third smaller than the
        // default \uXXXX encoding; both are valid UTF-8 JSON for the browser.
        $json = wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            return false;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        @chmod($path, 0644);
        return true;
    }

    /**
     * Log through the plugin logger when it is available.
     *
     * @param string $message
     * @param array  $context
     * @return void
     */
    private static function log($message, $context = array()) {
        if (class_exists('RWBE_Debug_Logger')) {
            RWBE_Debug_Logger::log($message, $context);
        }
    }
}
