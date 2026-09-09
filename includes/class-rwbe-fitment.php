<?php
/**
 * Vehicle fitment storage (make + model + year range per product).
 *
 * The importer records vehicle applications as three independent product
 * attributes: pa_make, pa_model and pa_vehicle_year. That loses the pairing. A part
 * that fits a Yamaha YZ250 (2003-2010) and a Honda CRF250 (2011-2015) ends up
 * tagged with both makes, both models and every year from 2003 to 2015, so the
 * catalogue happily answers "Yamaha + CRF250 + 2013" — a vehicle that does not
 * exist — and the model dropdown for Yamaha lists Honda models.
 *
 * This table keeps each application row intact, exactly as the API sends it:
 *
 *     product_id | make_slug | model_slug | year_from | year_to
 *
 * One row per application entry (a range, not one row per year), so the table
 * stays roughly the size of the API's application payload instead of exploding
 * into millions of per-year rows.
 *
 * Nothing switches over until the table actually covers the catalogue: until then
 * every reader falls back to the taxonomy behaviour that shipped before. See
 * is_ready().
 *
 * @since 1.2.0
 */

if (!defined('WPINC')) {
    die;
}

class RWBE_Fitment {

    /** Bumped whenever the schema changes, so upgrades run dbDelta again. */
    const SCHEMA_VERSION = 1;

    /** Option holding the installed schema version */
    const OPT_SCHEMA = 'rwbe_fitment_schema_version';

    /** Transient caching the coverage figures */
    const CACHE_COVERAGE = 'rwbe_fitment_coverage';

    /** Post meta marking a product whose application data has been read */
    const META_CHECKED = '_rwbe_fitment_checked';

    /**
     * Fraction of make-tagged products that must have fitment rows before the
     * table is trusted as the source of truth. Filterable.
     */
    const DEFAULT_MIN_COVERAGE = 0.9;

    /**
     * Full table name.
     *
     * @return string
     */
    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'rwbe_fitment';
    }

    /**
     * Create or update the table. Safe to call on every request (cheap option read).
     *
     * @return void
     */
    public static function maybe_install() {
        if ((int) get_option(self::OPT_SCHEMA, 0) === self::SCHEMA_VERSION) {
            return;
        }
        self::install();
    }

    /**
     * Create the table via dbDelta.
     *
     * @return void
     */
    public static function install() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = self::table();
        $collate = $wpdb->get_charset_collate();

        // Slug columns are kept short so the lookup index stays inside InnoDB's
        // key length limit even on utf8mb4.
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            make_slug VARCHAR(96) NOT NULL DEFAULT '',
            make_name VARCHAR(191) NOT NULL DEFAULT '',
            model_slug VARCHAR(96) NOT NULL DEFAULT '',
            model_name VARCHAR(191) NOT NULL DEFAULT '',
            year_from SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            year_to SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY product_id (product_id),
            KEY lookup (make_slug, model_slug, year_from, year_to),
            KEY make_model (make_slug, model_slug)
        ) {$collate};";

        dbDelta($sql);

        update_option(self::OPT_SCHEMA, self::SCHEMA_VERSION, false);
    }

    /**
     * Replace every fitment row for one product.
     *
     * @param int   $product_id
     * @param array $rows List of ['make','model','year_from','year_to'] as returned
     *                    by RWBE_Product_Importer::extract_application_data().
     * @return int Number of rows written.
     */
    public static function replace_for_product($product_id, $rows) {
        global $wpdb;

        $product_id = (int) $product_id;
        if ($product_id <= 0) {
            return 0;
        }

        $table = self::table();
        $wpdb->delete($table, array('product_id' => $product_id), array('%d'));

        if (empty($rows) || !is_array($rows)) {
            return 0;
        }

        // Deduplicate before writing: the API repeats the same application row for
        // different sub-variants often enough to matter on a 35k-product catalogue.
        $seen   = array();
        $values = array();
        $params = array();
        foreach ($rows as $row) {
            $make  = isset($row['make']) ? (string) $row['make'] : '';
            $model = isset($row['model']) ? (string) $row['model'] : '';
            if ($make === '' && $model === '') {
                continue;
            }
            $make_slug  = $make === '' ? '' : sanitize_title($make);
            $model_slug = $model === '' ? '' : sanitize_title($model);
            $from = isset($row['year_from']) ? max(0, (int) $row['year_from']) : 0;
            $to   = isset($row['year_to']) ? max(0, (int) $row['year_to']) : 0;
            if ($from > 0 && $to > 0 && $to < $from) {
                $tmp = $from; $from = $to; $to = $tmp;
            }
            // The API uses open-ended sentinels such as 1950-9999 for "fits anything".
            // Those carry no usable year information, so store them as 0-0 (matches
            // the rule extract_application_data() already applies to the year
            // attribute, which skips ranges wider than a century).
            if ($from === 0 || $to === 0 || ($to - $from) > 100) {
                $from = 0;
                $to   = 0;
            }

            $key = $make_slug . '|' . $model_slug . '|' . $from . '|' . $to;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $values[] = '(%d, %s, %s, %s, %s, %d, %d)';
            array_push(
                $params,
                $product_id,
                $make_slug,
                self::truncate($make, 191),
                $model_slug,
                self::truncate($model, 191),
                $from,
                $to
            );
        }

        if (empty($values)) {
            return 0;
        }

        // Insert in chunks so a part with hundreds of applications never builds a
        // statement larger than max_allowed_packet.
        $written = 0;
        $per_row = 7;
        $chunks  = array_chunk($values, 200);
        $offset  = 0;
        foreach ($chunks as $chunk) {
            $slice = array_slice($params, $offset, count($chunk) * $per_row);
            $offset += count($chunk) * $per_row;
            $sql = "INSERT INTO {$table}
                    (product_id, make_slug, make_name, model_slug, model_name, year_from, year_to)
                    VALUES " . implode(', ', $chunk);
            $result = $wpdb->query($wpdb->prepare($sql, $slice));
            if ($result !== false) {
                $written += (int) $result;
            }
        }

        return $written;
    }

    /**
     * Truncate to a column width without splitting a multi-byte character.
     *
     * mbstring is not guaranteed to be present, so fall back to the byte-safe
     * regular expression WordPress itself uses in that situation.
     *
     * @param string $text
     * @param int    $length
     * @return string
     */
    private static function truncate($text, $length) {
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $length, 'UTF-8');
        }
        // Cut on a byte boundary, then walk back until the result is valid UTF-8.
        // Byte length <= character length, so the column limit is never exceeded.
        $cut = substr($text, 0, $length);
        while ($cut !== '' && !preg_match('//u', $cut)) {
            $cut = substr($cut, 0, -1);
        }
        return $cut;
    }

    /**
     * Remove a product's rows (used when a product is deleted).
     *
     * @param int $product_id
     * @return void
     */
    public static function delete_for_product($product_id) {
        global $wpdb;
        $wpdb->delete(self::table(), array('product_id' => (int) $product_id), array('%d'));
        delete_post_meta((int) $product_id, self::META_CHECKED);
    }

    /**
     * Record that a product's application data has been read, whether or not it
     * produced any rows. Without this, parts with no applications would be fetched
     * again on every backfill pass and coverage could never reach 100%.
     *
     * @param int $product_id
     * @return void
     */
    public static function mark_checked($product_id) {
        update_post_meta((int) $product_id, self::META_CHECKED, time());
    }

    /**
     * Does the table exist?
     *
     * @return bool
     */
    public static function table_exists() {
        global $wpdb;
        $table = self::table();
        return (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    }

    /**
     * How much of the catalogue the table covers.
     *
     * @param bool $fresh Skip the cached value.
     * @return array {
     *     @type int   $products  Published products that have fitment rows.
     *     @type int   $expected  Published products tagged with a vehicle make.
     *     @type int   $rows      Total rows in the table.
     *     @type float $ratio     products / expected (1.0 when nothing is expected).
     * }
     */
    public static function coverage($fresh = false) {
        global $wpdb;

        if (!$fresh) {
            $cached = get_transient(self::CACHE_COVERAGE);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $empty = array('products' => 0, 'expected' => 0, 'rows' => 0, 'ratio' => 0.0);

        if (!self::table_exists()) {
            set_transient(self::CACHE_COVERAGE, $empty, HOUR_IN_SECONDS);
            return $empty;
        }

        $table = self::table();

        $rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        // Count products that were *checked*, not just those that ended up with rows:
        // plenty of parts are universal and legitimately have no application data.
        $products = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
             WHERE p.post_type = 'product' AND p.post_status = 'publish'",
            self::META_CHECKED
        ));

        // The yardstick is "products the importer gave a vehicle make to", not the
        // whole catalogue: plenty of parts are universal and have no application.
        $expected = 0;
        if (taxonomy_exists('pa_make')) {
            $expected = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID)
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s
                 WHERE p.post_type = 'product' AND p.post_status = 'publish'",
                'pa_make'
            ));
        }

        $out = array(
            'products' => $products,
            'expected' => $expected,
            'rows'     => $rows,
            'ratio'    => $expected > 0 ? min(1.0, $products / $expected) : ($products > 0 ? 1.0 : 0.0),
        );

        set_transient(self::CACHE_COVERAGE, $out, HOUR_IN_SECONDS);
        return $out;
    }

    /**
     * Forget the cached coverage (called after an import or a backfill batch).
     *
     * @return void
     */
    public static function flush_coverage_cache() {
        delete_transient(self::CACHE_COVERAGE);
    }

    /**
     * Is the table complete enough to be used instead of the taxonomies?
     *
     * Until it is, every reader keeps the pre-1.2.0 behaviour, so shipping this
     * table changes nothing until an import (or the backfill) has populated it.
     *
     * @return bool
     */
    public static function is_ready() {
        if (!self::table_exists()) {
            return false;
        }

        // While a backfill is walking the catalogue the table is complete for the
        // products it has reached and empty for the rest. Crossing the coverage
        // threshold mid-run would start filtering by fitment before the remaining
        // products have rows, quietly hiding them. Stay on the taxonomy path until
        // the run finishes.
        $backfill = get_option('rwbe_fitment_backfill_state', array());
        if (!empty($backfill['running'])) {
            return false;
        }

        $coverage = self::coverage();
        if ($coverage['products'] < 1 || $coverage['rows'] < 1) {
            return false;
        }
        /**
         * Filter the coverage ratio required before fitment data is trusted.
         *
         * @param float $min Between 0 and 1.
         */
        $min = (float) apply_filters('rwbe_fitment_min_coverage', self::DEFAULT_MIN_COVERAGE);
        return $coverage['ratio'] >= $min;
    }

    /**
     * Distinct makes that have at least one published product.
     *
     * @return array List of ['slug' => ..., 'name' => ...]
     */
    public static function get_makes() {
        global $wpdb;
        $table = self::table();
        $rows = $wpdb->get_results(
            "SELECT f.make_slug AS slug, MIN(f.make_name) AS name
             FROM {$table} f
             INNER JOIN {$wpdb->posts} p ON p.ID = f.product_id
                    AND p.post_type = 'product' AND p.post_status = 'publish'
             WHERE f.make_slug <> ''
             GROUP BY f.make_slug
             ORDER BY name ASC"
        );
        return self::rows_to_list($rows);
    }

    /**
     * Every make → model pair, keyed by make slug.
     *
     * @return array Map of make slug => list of ['slug' => ..., 'name' => ...]
     */
    public static function get_model_map() {
        global $wpdb;
        $table = self::table();
        $rows = $wpdb->get_results(
            "SELECT f.make_slug, f.model_slug AS slug, MIN(f.model_name) AS name
             FROM {$table} f
             INNER JOIN {$wpdb->posts} p ON p.ID = f.product_id
                    AND p.post_type = 'product' AND p.post_status = 'publish'
             WHERE f.make_slug <> '' AND f.model_slug <> ''
             GROUP BY f.make_slug, f.model_slug
             ORDER BY f.make_slug ASC, name ASC"
        );

        $out = array();
        if ($rows) {
            foreach ($rows as $row) {
                $out[$row->make_slug][] = array('slug' => $row->slug, 'name' => $row->name);
            }
        }
        return $out;
    }

    /**
     * Every make|model → years list, expanded from the stored ranges.
     *
     * @return array Map of "make|model" => list of year strings, newest first.
     */
    public static function get_year_map() {
        global $wpdb;
        $table = self::table();
        $rows = $wpdb->get_results(
            "SELECT DISTINCT f.make_slug, f.model_slug, f.year_from, f.year_to
             FROM {$table} f
             INNER JOIN {$wpdb->posts} p ON p.ID = f.product_id
                    AND p.post_type = 'product' AND p.post_status = 'publish'
             WHERE f.make_slug <> '' AND f.model_slug <> '' AND f.year_from > 0"
        );

        $sets = array();
        if ($rows) {
            foreach ($rows as $row) {
                $key  = $row->make_slug . '|' . $row->model_slug;
                $from = (int) $row->year_from;
                $to   = (int) $row->year_to;
                if ($to < $from) {
                    $to = $from;
                }
                // Guard against a bad range turning into a huge loop.
                if ($to - $from > 100) {
                    $to = $from + 100;
                }
                for ($y = $from; $y <= $to; $y++) {
                    $sets[$key][$y] = true;
                }
            }
        }

        $out = array();
        foreach ($sets as $key => $years) {
            $list = array_keys($years);
            rsort($list, SORT_NUMERIC);
            $out[$key] = array_map('strval', $list);
        }
        return $out;
    }

    /**
     * Published product IDs matching an exact vehicle selection.
     *
     * Unlike three independent tax_query clauses, this only matches products whose
     * *same* application row carries the make, the model and the year.
     *
     * @param string $make_slug
     * @param string $model_slug Optional.
     * @param int    $year       Optional.
     * @param int    $limit      Safety cap on the number of IDs returned.
     * @return array List of product IDs.
     */
    public static function product_ids($make_slug, $model_slug = '', $year = 0, $limit = 20000) {
        global $wpdb;

        $make_slug = sanitize_title($make_slug);
        if ($make_slug === '') {
            return array();
        }

        $table = self::table();
        $where  = array('f.make_slug = %s');
        $params = array($make_slug);

        $model_slug = $model_slug === '' ? '' : sanitize_title($model_slug);
        if ($model_slug !== '') {
            $where[]  = 'f.model_slug = %s';
            $params[] = $model_slug;
        }

        $year = (int) $year;
        if ($year > 0) {
            $where[]  = 'f.year_from <= %d AND f.year_to >= %d';
            $params[] = $year;
            $params[] = $year;
        }

        $params[] = max(1, (int) $limit);

        $sql = "SELECT DISTINCT f.product_id
                FROM {$table} f
                INNER JOIN {$wpdb->posts} p ON p.ID = f.product_id
                       AND p.post_type = 'product' AND p.post_status = 'publish'
                WHERE " . implode(' AND ', $where) . "
                LIMIT %d";

        return array_map('intval', (array) $wpdb->get_col($wpdb->prepare($sql, $params)));
    }

    /**
     * Turn a result set of slug/name rows into the array shape the front-end uses.
     *
     * @param array $rows
     * @return array
     */
    private static function rows_to_list($rows) {
        $out = array();
        if ($rows) {
            foreach ($rows as $row) {
                $out[] = array('slug' => $row->slug, 'name' => $row->name);
            }
        }
        return $out;
    }
}
