<?php
/**
 * Front-end vehicle search (Make → Model) for RWBE Product Importer.
 *
 * Provides the [rwbe_ymm_search] shortcode: two chained dropdowns (vehicle make
 * and model) that filter the WooCommerce shop by the pa_make / pa_model product
 * attributes populated by the importer.
 *
 * @since 1.1.0
 */

// Exit when accessed directly: these files only make sense inside WordPress.
if (!defined('WPINC')) {
    die;
}

class RWBE_Product_Importer_Search {

    /** Shortcode tag */
    const SHORTCODE = 'rwbe_ymm_search';

    /** Query var names used on the shop results page */
    const QV_MAKE  = 'rwbe_make';
    const QV_MODEL = 'rwbe_model';
    const QV_YEAR  = 'rwbe_year';

    /**
     * The one WP_Query that inject_fitment_clauses() is allowed to touch.
     *
     * @var WP_Query|null
     */
    private $fitment_query = null;

    /**
     * Prepared SQL condition for the selected vehicle combination.
     *
     * @var string
     */
    private $fitment_clause = '';

    /**
     * Register hooks.
     */
    public function init() {
        add_shortcode(self::SHORTCODE, array($this, 'render_shortcode'));
        add_action('wp_enqueue_scripts', array($this, 'register_assets'));

        // AJAX: load models for a selected make (logged-in and guests)
        add_action('wp_ajax_rwbe_ymm_get_models', array($this, 'ajax_get_models'));
        add_action('wp_ajax_nopriv_rwbe_ymm_get_models', array($this, 'ajax_get_models'));

        // AJAX: load years for a selected make + model
        add_action('wp_ajax_rwbe_ymm_get_years', array($this, 'ajax_get_years'));
        add_action('wp_ajax_nopriv_rwbe_ymm_get_years', array($this, 'ajax_get_years'));

        // Apply the make/model filter to the shop/catalog query
        add_action('woocommerce_product_query', array($this, 'filter_product_query'));

        // Admin sub-menu that documents the shortcode
        add_action('admin_menu', array($this, 'add_admin_menu'), 20);

        // Appearance setting (search button colour)
        add_action('admin_init', array($this, 'register_settings'));

        // Invalidate the cached make→model lists after an import completes
        add_action('rwbe_product_import_cron', array($this, 'flush_model_cache'), 99);
    }

    /**
     * Register (and conditionally enqueue) the front-end assets.
     */
    public function register_assets() {
        wp_register_style(
            'rwbe-ymm-search',
            RWBE_PRODUCT_IMPORTER_PLUGIN_URL . 'assets/css/search.css',
            array(),
            RWBE_PRODUCT_IMPORTER_VERSION
        );
        wp_register_script(
            'rwbe-ymm-search',
            RWBE_PRODUCT_IMPORTER_PLUGIN_URL . 'assets/js/search.js',
            array('jquery'),
            RWBE_PRODUCT_IMPORTER_VERSION,
            true
        );
        // Enqueue early when the shortcode is present in the singular content
        if (is_singular()) {
            $post = get_post();
            if ($post && has_shortcode($post->post_content, self::SHORTCODE)) {
                self::enqueue_assets();
            }
        }
    }

    /**
     * Enqueue the search assets, localising them on the way.
     *
     * The localised data used to be built inside register_assets(), on every single
     * front-end request — including the overwhelming majority of pages that never
     * render the form. That cost a nonce plus RWBE_Vehicle_Map::get_urls() (an option
     * read and two file_exists()) for nothing. It is now built once, and only when
     * the script is actually going out.
     */
    private static function enqueue_assets() {
        static $localized = false;

        if (!$localized) {
            $localized = true;

            // Static make/model/year map. When present the dropdowns read these two
            // JSON files straight from the web server; the AJAX endpoints stay
            // registered and are used as the fallback whenever the files are missing.
            $map = class_exists('RWBE_Vehicle_Map')
                ? RWBE_Vehicle_Map::get_urls()
                : array('models' => '', 'years' => '');

            wp_localize_script('rwbe-ymm-search', 'rwbeYmm', array(
                'ajaxUrl'   => admin_url('admin-ajax.php'),
                'nonce'     => wp_create_nonce('rwbe_ymm_nonce'),
                'modelsUrl' => $map['models'],
                'yearsUrl'  => $map['years'],
                'i18n'    => array(
                    'selectModel' => __('Selecione o modelo', 'rwbe-product-importer'),
                    'selectYear'  => __('Selecione o ano', 'rwbe-product-importer'),
                    'loading'     => __('A carregar…', 'rwbe-product-importer'),
                    'noModels'    => __('Sem modelos disponíveis', 'rwbe-product-importer'),
                    'noYears'     => __('Sem anos disponíveis', 'rwbe-product-importer'),
                ),
            ));
        }

        wp_enqueue_style('rwbe-ymm-search');
        wp_enqueue_script('rwbe-ymm-search');
    }

    /**
     * Render the search form.
     *
     * @param array $atts Shortcode attributes.
     * @return string
     */
    public function render_shortcode($atts) {
        $atts = shortcode_atts(array(
            'shop_url'    => '',
            'make_label'  => __('Marca', 'rwbe-product-importer'),
            'model_label' => __('Modelo', 'rwbe-product-importer'),
            'year_label'  => __('Ano', 'rwbe-product-importer'),
            'button'      => __('Pesquisar', 'rwbe-product-importer'),
            'show_year'   => 'yes',
        ), $atts, self::SHORTCODE);

        $show_year = !in_array(strtolower((string) $atts['show_year']), array('no', 'false', '0', ''), true);

        // Ensure assets are loaded (covers page builders / widgets too)
        self::enqueue_assets();

        // Build the static map in the background if it is not there yet.
        if (class_exists('RWBE_Vehicle_Map')) {
            RWBE_Vehicle_Map::maybe_schedule_rebuild();
        }

        if (!taxonomy_exists('pa_make')) {
            return '<p class="rwbe-ymm-empty">' . esc_html__('A pesquisa por veículo ainda não está disponível.', 'rwbe-product-importer') . '</p>';
        }

        $makes = $this->get_terms_safe('pa_make');
        if (empty($makes)) {
            return '<p class="rwbe-ymm-empty">' . esc_html__('Ainda não existem marcas de veículo importadas.', 'rwbe-product-importer') . '</p>';
        }

        $shop_url = $atts['shop_url'] !== ''
            ? $atts['shop_url']
            : (function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/shop/'));

        // Pre-select values when the form is shown on the results page
        $sel_make  = isset($_GET[self::QV_MAKE]) ? sanitize_title(wp_unslash($_GET[self::QV_MAKE])) : '';
        $sel_model = isset($_GET[self::QV_MODEL]) ? sanitize_title(wp_unslash($_GET[self::QV_MODEL])) : '';
        $sel_year  = isset($_GET[self::QV_YEAR]) ? sanitize_title(wp_unslash($_GET[self::QV_YEAR])) : '';

        ob_start();
        ?>
        <form class="rwbe-ymm-search" method="get" action="<?php echo esc_url($shop_url); ?>" role="search" aria-label="<?php esc_attr_e('Pesquisa de peças por veículo', 'rwbe-product-importer'); ?>" style="--rwbe-ym-accent: <?php echo esc_attr($this->get_button_color()); ?>;">
            <div class="rwbe-ymm-field">
                <label class="rwbe-ymm-label" for="rwbe-ymm-make"><?php echo esc_html($atts['make_label']); ?></label>
                <select id="rwbe-ymm-make" class="rwbe-ymm-make" name="<?php echo esc_attr(self::QV_MAKE); ?>" aria-label="<?php echo esc_attr($atts['make_label']); ?>">
                    <option value=""><?php echo esc_html($atts['make_label']); ?></option>
                    <?php foreach ($makes as $make): ?>
                        <option value="<?php echo esc_attr($make->slug); ?>" <?php selected($sel_make, $make->slug); ?>><?php echo esc_html($make->name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="rwbe-ymm-field">
                <label class="rwbe-ymm-label" for="rwbe-ymm-model"><?php echo esc_html($atts['model_label']); ?></label>
                <select id="rwbe-ymm-model" class="rwbe-ymm-model" name="<?php echo esc_attr(self::QV_MODEL); ?>" data-selected="<?php echo esc_attr($sel_model); ?>" aria-label="<?php echo esc_attr($atts['model_label']); ?>" <?php disabled($sel_make, ''); ?>>
                    <option value=""><?php echo esc_html($atts['model_label']); ?></option>
                </select>
            </div>
            <?php if ($show_year): ?>
            <div class="rwbe-ymm-field">
                <label class="rwbe-ymm-label" for="rwbe-ymm-year"><?php echo esc_html($atts['year_label']); ?></label>
                <select id="rwbe-ymm-year" class="rwbe-ymm-year" name="<?php echo esc_attr(self::QV_YEAR); ?>" data-selected="<?php echo esc_attr($sel_year); ?>" aria-label="<?php echo esc_attr($atts['year_label']); ?>" <?php disabled($sel_model, ''); ?>>
                    <option value=""><?php echo esc_html($atts['year_label']); ?></option>
                </select>
            </div>
            <?php endif; ?>
            <div class="rwbe-ymm-field rwbe-ymm-actions">
                <button type="submit" class="rwbe-ymm-submit button"><?php echo esc_html($atts['button']); ?></button>
            </div>
        </form>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX handler: return the models available for a given make.
     */
    public function ajax_get_models() {
        check_ajax_referer('rwbe_ymm_nonce', 'nonce');

        $make = isset($_POST['make']) ? sanitize_title(wp_unslash($_POST['make'])) : '';
        if ($make === '') {
            wp_send_json_success(array('models' => array()));
        }

        wp_send_json_success(array('models' => $this->get_models_for_make($make)));
    }

    /**
     * AJAX handler: return the years available for a given make + model.
     */
    public function ajax_get_years() {
        check_ajax_referer('rwbe_ymm_nonce', 'nonce');

        $make  = isset($_POST['make']) ? sanitize_title(wp_unslash($_POST['make'])) : '';
        $model = isset($_POST['model']) ? sanitize_title(wp_unslash($_POST['model'])) : '';
        if ($make === '' || $model === '') {
            wp_send_json_success(array('years' => array()));
        }

        wp_send_json_success(array('years' => $this->get_years_for($make, $model)));
    }

    /**
     * Distinct models available for a given make. Cached 12h.
     *
     * @param string $make_slug
     * @return array List of ['slug' => ..., 'name' => ...]
     */
    private function get_models_for_make($make_slug) {
        $cache_key = 'rwbe_ymm_models_' . self::cache_version() . '_' . md5($make_slug);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }
        $models = self::fitment_models($make_slug);
        if ($models === null) {
            $models = $this->get_related_terms('pa_model', array('pa_make' => $make_slug));
        }
        set_transient($cache_key, $models, 12 * HOUR_IN_SECONDS);
        return $models;
    }

    /**
     * Distinct years available for a given make + model. Cached 12h.
     *
     * @param string $make_slug
     * @param string $model_slug
     * @return array List of ['slug' => ..., 'name' => ...]
     */
    private function get_years_for($make_slug, $model_slug) {
        $cache_key = 'rwbe_ymm_years_' . self::cache_version() . '_' . md5($make_slug . '|' . $model_slug);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }
        $years = self::fitment_years($make_slug, $model_slug);
        if ($years === null) {
            $years = $this->get_related_terms(
                'pa_vehicle_year',
                array('pa_make' => $make_slug, 'pa_model' => $model_slug),
                true // newest first
            );
        }
        set_transient($cache_key, $years, 12 * HOUR_IN_SECONDS);
        return $years;
    }

    /**
     * Models for a make, taken from the fitment table.
     *
     * @param string $make_slug
     * @return array|null Null when the table is not usable yet (caller falls back).
     */
    private static function fitment_models($make_slug) {
        if (!class_exists('RWBE_Fitment') || !RWBE_Fitment::is_ready()) {
            return null;
        }
        $map = RWBE_Fitment::get_model_map($make_slug);
        return isset($map[$make_slug]) ? $map[$make_slug] : array();
    }

    /**
     * Years for a make + model, taken from the fitment table.
     *
     * @param string $make_slug
     * @param string $model_slug
     * @return array|null Null when the table is not usable yet (caller falls back).
     */
    private static function fitment_years($make_slug, $model_slug) {
        if (!class_exists('RWBE_Fitment') || !RWBE_Fitment::is_ready()) {
            return null;
        }
        $map  = RWBE_Fitment::get_year_map($make_slug, $model_slug);
        $key  = $make_slug . '|' . $model_slug;
        $list = isset($map[$key]) ? $map[$key] : array();

        $out = array();
        foreach ($list as $year) {
            $out[] = array('slug' => (string) $year, 'name' => (string) $year);
        }
        return $out;
    }

    /**
     * Return the distinct terms of $target_tax that co-occur on published products
     * which also have every (taxonomy => slug) pair in $filters.
     *
     * Uses a single indexed SQL query (no loading of all product IDs into PHP).
     *
     * @param string $target_tax        Taxonomy whose terms to return (e.g. 'pa_model')
     * @param array  $filters           Map of taxonomy => term slug the product must have
     * @param bool   $order_numeric_desc Sort by numeric value descending (for years)
     * @return array List of ['slug' => ..., 'name' => ...]
     */
    private function get_related_terms($target_tax, $filters, $order_numeric_desc = false) {
        global $wpdb;

        if (!taxonomy_exists($target_tax)) {
            return array();
        }
        foreach ($filters as $tax => $slug) {
            if (!taxonomy_exists($tax) || $slug === '') {
                return array();
            }
        }

        $joins  = '';
        $params = array($target_tax);
        $i = 0;
        foreach ($filters as $tax => $slug) {
            $i++;
            $joins .= " INNER JOIN {$wpdb->term_relationships} trf{$i} ON trf{$i}.object_id = tr.object_id";
            $joins .= " INNER JOIN {$wpdb->term_taxonomy} ttf{$i} ON ttf{$i}.term_taxonomy_id = trf{$i}.term_taxonomy_id AND ttf{$i}.taxonomy = %s";
            $joins .= " INNER JOIN {$wpdb->terms} tf{$i} ON tf{$i}.term_id = ttf{$i}.term_id AND tf{$i}.slug = %s";
            $params[] = $tax;
            $params[] = $slug;
        }

        $order = $order_numeric_desc ? '(t.name + 0) DESC' : 't.name ASC';

        $sql = "SELECT DISTINCT t.slug, t.name
                FROM {$wpdb->terms} t
                INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id AND tt.taxonomy = %s
                INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
                {$joins}
                INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id AND p.post_type = 'product' AND p.post_status = 'publish'
                ORDER BY {$order}";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $params));

        $out = array();
        if ($rows) {
            foreach ($rows as $row) {
                $out[] = array('slug' => $row->slug, 'name' => $row->name);
            }
        }
        return $out;
    }

    /**
     * Apply the make/model selection to the main shop/catalog query.
     *
     * @param WP_Query $query
     */
    public function filter_product_query($query) {
        $make  = isset($_GET[self::QV_MAKE]) ? sanitize_title(wp_unslash($_GET[self::QV_MAKE])) : '';
        $model = isset($_GET[self::QV_MODEL]) ? sanitize_title(wp_unslash($_GET[self::QV_MODEL])) : '';
        $year  = isset($_GET[self::QV_YEAR]) ? sanitize_title(wp_unslash($_GET[self::QV_YEAR])) : '';

        if ($make === '' && $model === '' && $year === '') {
            return;
        }

        // Exact-combination filtering, when the fitment table is populated.
        if ($this->apply_fitment_filter($query, $make, $model, $year)) {
            return;
        }

        // Fallback: three independent taxonomy clauses. This is the pre-1.2.0
        // behaviour and it over-matches — a part fitting a Yamaha YZ250 and a Honda
        // CRF250 also answers "Yamaha + CRF250" — but it is what the taxonomies can
        // express on their own.
        $tax_query = $query->get('tax_query');
        $tax_query = is_array($tax_query) ? $tax_query : array();

        if ($make !== '') {
            $tax_query[] = array(
                'taxonomy' => 'pa_make',
                'field'    => 'slug',
                'terms'    => $make,
            );
        }
        if ($model !== '') {
            $tax_query[] = array(
                'taxonomy' => 'pa_model',
                'field'    => 'slug',
                'terms'    => $model,
            );
        }
        if ($year !== '') {
            $tax_query[] = array(
                'taxonomy' => 'pa_vehicle_year',
                'field'    => 'slug',
                'terms'    => $year,
            );
        }

        $tax_query['relation'] = 'AND';
        $query->set('tax_query', $tax_query);
    }

    /**
     * Narrow the query to the products whose *same* application row matches the
     * selected make + model (+ year), using the fitment table.
     *
     * Only takes over when a model or a year is selected: a make on its own has no
     * pairing to get wrong, and a plain taxonomy clause is cheaper.
     *
     * The table is JOINed onto the query rather than resolved to a list of ids. The
     * previous version ran the lookup itself and handed the result to post__in, which
     * put an IN() of up to 20000 integers on the main shop query — and needed a cap
     * above which it gave up and fell back to the over-inclusive taxonomy path,
     * silently widening the results for exactly the busiest makes. A JOIN has no such
     * ceiling, so the exact-combination filter now always applies.
     *
     * @param WP_Query $query
     * @param string   $make
     * @param string   $model
     * @param string   $year
     * @return bool True when the query was filtered here (caller should stop).
     */
    private function apply_fitment_filter($query, $make, $model, $year) {
        if (!class_exists('RWBE_Fitment')) {
            return false;
        }
        // Needs a make to anchor on, plus something the taxonomies cannot pair up.
        if ($make === '' || ($model === '' && $year === '')) {
            return false;
        }
        if (!RWBE_Fitment::is_ready()) {
            return false;
        }

        $clause = RWBE_Fitment::where_clause($make, $model, (int) $year);
        if ($clause === null) {
            return false;
        }

        $this->fitment_clause = $clause;
        $this->fitment_query  = $query;
        add_filter('posts_clauses', array($this, 'inject_fitment_clauses'), 10, 2);

        return true;
    }

    /**
     * Append the fitment JOIN + condition to the one query apply_fitment_filter()
     * marked. Every other query passes through untouched.
     *
     * Any post__in another plugin set stays in place: the JOIN narrows on top of it
     * rather than replacing it, which is what the old intersection did by hand.
     *
     * @param array    $clauses
     * @param WP_Query $query
     * @return array
     */
    public function inject_fitment_clauses($clauses, $query) {
        if ($this->fitment_query === null || $query !== $this->fitment_query) {
            return $clauses;
        }
        // Idempotent: never append the same JOIN twice if the query is run again.
        if (isset($clauses['join']) && strpos($clauses['join'], RWBE_Fitment::QUERY_ALIAS) !== false) {
            return $clauses;
        }

        // A product has one fitment row per application, so several can match the
        // same selection; without this the catalogue would list it repeatedly.
        $clauses['distinct'] = 'DISTINCT';
        $clauses['join']     = (isset($clauses['join']) ? $clauses['join'] : '') . RWBE_Fitment::query_join();
        $clauses['where']    = (isset($clauses['where']) ? $clauses['where'] : '') . ' AND ' . $this->fitment_clause;

        return $clauses;
    }

    /**
     * Refresh the cached make→model lists (called after an import).
     *
     * Only drops the stale transients. The static JSON map is rebuilt right after
     * this, by RWBE_Vehicle_Map::rebuild_after_import() on the same cron hook, so
     * the cache comes back warm and no visitor pays the cold cost.
     *
     * The previous implementation also called wp_cache_flush(), which threw away
     * the whole site's object cache for the sake of a handful of transients.
     */
    public function flush_model_cache() {
        global $wpdb;

        // Bump the key namespace. This invalidates every cached list at once and,
        // unlike deleting rows from wp_options, it also works when the transients
        // live in a persistent object cache (Redis/Memcached).
        update_option('rwbe_ymm_cache_version', self::cache_version() + 1, false);

        // Housekeeping for the DB-backed case: drop the now-unreachable rows so
        // wp_options does not grow an orphan per make on every import.
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '\_transient\_rwbe_ymm\_%'
                OR option_name LIKE '\_transient\_timeout\_rwbe_ymm\_%'"
        );
    }

    /**
     * Current cache-key namespace. Bumped by flush_model_cache() after an import.
     *
     * @return int
     */
    private static function cache_version() {
        return (int) get_option('rwbe_ymm_cache_version', 1);
    }

    /**
     * Get terms for a taxonomy, never a WP_Error.
     *
     * @param string $taxonomy
     * @return array
     */
    private function get_terms_safe($taxonomy) {
        if (!taxonomy_exists($taxonomy)) {
            return array();
        }
        $terms = get_terms(array(
            'taxonomy'   => $taxonomy,
            'hide_empty' => true,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ));
        return is_wp_error($terms) ? array() : $terms;
    }

    /**
     * Register the search appearance settings.
     */
    public function register_settings() {
        register_setting('rwbe_search_settings', 'rwbe_ymm_button_color', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_hex_color',
            'default'           => '#d6201f',
        ));
    }

    /**
     * Get the configured search button colour (falls back to the brand red).
     *
     * @return string Hex colour
     */
    private function get_button_color() {
        $color = get_option('rwbe_ymm_button_color', '#d6201f');
        $color = sanitize_hex_color($color);
        return $color ? $color : '#d6201f';
    }

    /**
     * Register the admin sub-menu that documents the shortcode.
     */
    public function add_admin_menu() {
        add_submenu_page(
            'rwbe-product-importer',
            __('Barra de Pesquisa', 'rwbe-product-importer'),
            __('Barra de Pesquisa', 'rwbe-product-importer'),
            'manage_options',
            'rwbe-search-shortcode',
            array($this, 'render_admin_page')
        );
    }

    /**
     * Admin page: show the shortcode and usage instructions.
     */
    public function render_admin_page() {
        $make_count  = taxonomy_exists('pa_make') ? intval(wp_count_terms(array('taxonomy' => 'pa_make', 'hide_empty' => false))) : 0;
        $model_count = taxonomy_exists('pa_model') ? intval(wp_count_terms(array('taxonomy' => 'pa_model', 'hide_empty' => false))) : 0;
        $shortcode   = '[' . self::SHORTCODE . ']';

        // Manual rebuild of the static map.
        $rebuild_done = null;
        if (isset($_GET['rwbe_rebuild_map']) && class_exists('RWBE_Vehicle_Map')) {
            check_admin_referer('rwbe_rebuild_map');
            $rebuild_done = RWBE_Vehicle_Map::rebuild(true);
        }

        // Fitment backfill controls.
        if (isset($_GET['rwbe_fitment_backfill'])) {
            check_admin_referer('rwbe_fitment_backfill');
            $action = sanitize_key(wp_unslash($_GET['rwbe_fitment_backfill']));
            $state  = get_option('rwbe_fitment_backfill_state', array());
            if ($action === 'start') {
                $state = array('running' => true, 'processed' => 0, 'batch_size' => 200, 'updated_at' => time());
                update_option('rwbe_fitment_backfill_state', $state, false);
                if (!wp_next_scheduled('rwbe_fitment_backfill')) {
                    wp_schedule_single_event(time(), 'rwbe_fitment_backfill');
                }
            } elseif ($action === 'stop') {
                $state['running'] = false;
                update_option('rwbe_fitment_backfill_state', $state, false);
                wp_clear_scheduled_hook('rwbe_fitment_backfill');
            }
        }

        $fitment_ready    = class_exists('RWBE_Fitment') && RWBE_Fitment::is_ready();
        $fitment_coverage = class_exists('RWBE_Fitment') ? RWBE_Fitment::coverage(true) : array('products' => 0, 'expected' => 0, 'rows' => 0, 'ratio' => 0.0);
        $backfill_state   = get_option('rwbe_fitment_backfill_state', array());
        $backfill_running = !empty($backfill_state['running']);
        $backfill_url     = function ($action) {
            return wp_nonce_url(
                add_query_arg(array('page' => 'rwbe-search-shortcode', 'rwbe_fitment_backfill' => $action), admin_url('admin.php')),
                'rwbe_fitment_backfill'
            );
        };

        $map_urls    = class_exists('RWBE_Vehicle_Map') ? RWBE_Vehicle_Map::get_urls() : array('models' => '', 'years' => '', 'version' => 0);
        $map_source  = get_option('rwbe_vehicle_map_source', '');
        $map_ready   = $map_urls['models'] !== '' && $map_urls['years'] !== '';
        $rebuild_url = wp_nonce_url(
            add_query_arg(array('page' => 'rwbe-search-shortcode', 'rwbe_rebuild_map' => 1), admin_url('admin.php')),
            'rwbe_rebuild_map'
        );
        ?>
        <div class="wrap rwbe-product-importer">
            <h1><?php esc_html_e('Barra de Pesquisa por Veículo', 'rwbe-product-importer'); ?></h1>

            <div class="rwbe-card">
                <div class="rwbe-card-header">
                    <span class="dashicons dashicons-search"></span>
                    <h2><?php esc_html_e('Shortcode', 'rwbe-product-importer'); ?></h2>
                </div>
                <p><?php esc_html_e('Copie este shortcode e cole-o na homepage (página, bloco de shortcode ou widget) para mostrar a barra de pesquisa Marca → Modelo:', 'rwbe-product-importer'); ?></p>
                <p>
                    <input type="text" readonly class="regular-text code" value="<?php echo esc_attr($shortcode); ?>" onclick="this.select();" style="font-size:16px;padding:8px;width:320px;" />
                </p>
                <p class="description"><?php esc_html_e('Ao submeter, o cliente é levado para a loja já filtrada pela marca e modelo de veículo escolhidos.', 'rwbe-product-importer'); ?></p>

                <h3><?php esc_html_e('Atributos opcionais', 'rwbe-product-importer'); ?></h3>
                <table class="widefat striped" style="max-width:760px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Atributo', 'rwbe-product-importer'); ?></th>
                            <th><?php esc_html_e('Descrição', 'rwbe-product-importer'); ?></th>
                            <th><?php esc_html_e('Exemplo', 'rwbe-product-importer'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td><code>make_label</code></td><td><?php esc_html_e('Texto do campo da marca', 'rwbe-product-importer'); ?></td><td><code>make_label="Marca"</code></td></tr>
                        <tr><td><code>model_label</code></td><td><?php esc_html_e('Texto do campo do modelo', 'rwbe-product-importer'); ?></td><td><code>model_label="Modelo"</code></td></tr>
                        <tr><td><code>year_label</code></td><td><?php esc_html_e('Texto do campo do ano', 'rwbe-product-importer'); ?></td><td><code>year_label="Ano"</code></td></tr>
                        <tr><td><code>show_year</code></td><td><?php esc_html_e('Mostrar o 3º nível (Ano). Use "no" para esconder', 'rwbe-product-importer'); ?></td><td><code>show_year="no"</code></td></tr>
                        <tr><td><code>button</code></td><td><?php esc_html_e('Texto do botão', 'rwbe-product-importer'); ?></td><td><code>button="Procurar peças"</code></td></tr>
                        <tr><td><code>shop_url</code></td><td><?php esc_html_e('URL de resultados (por defeito: página da Loja)', 'rwbe-product-importer'); ?></td><td><code>shop_url="/loja/"</code></td></tr>
                    </tbody>
                </table>

                <h3><?php esc_html_e('Estado dos dados', 'rwbe-product-importer'); ?></h3>
                <p>
                    <span class="dashicons dashicons-car"></span>
                    <?php
                    printf(
                        /* translators: 1: make count, 2: model count */
                        esc_html__('Marcas de veículo: %1$d · Modelos: %2$d', 'rwbe-product-importer'),
                        $make_count,
                        $model_count
                    );
                    ?>
                </p>
                <?php if ($make_count === 0 || $model_count === 0): ?>
                    <div class="rwbe-notice rwbe-notice-warning">
                        <span class="dashicons dashicons-warning"></span>
                        <?php esc_html_e('Ainda não há marcas/modelos suficientes. Execute uma importação completa para que a barra de pesquisa tenha dados.', 'rwbe-product-importer'); ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Combinações reais de veículo -->
            <div class="rwbe-card">
                <div class="rwbe-card-header">
                    <span class="dashicons dashicons-car"></span>
                    <h2><?php esc_html_e('Combinações de veículo (marca + modelo + anos)', 'rwbe-product-importer'); ?></h2>
                </div>
                <p class="description">
                    <?php esc_html_e('Marca, modelo e ano são atributos independentes do produto, por isso não guardam qual modelo pertence a qual marca. A tabela de combinações guarda cada aplicação da API intacta, o que corrige os modelos listados na marca errada e os resultados de combinações que não existem.', 'rwbe-product-importer'); ?>
                </p>

                <p>
                    <span class="dashicons <?php echo $fitment_ready ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
                    <?php
                    printf(
                        /* translators: 1: products with fitment rows, 2: products expected, 3: total rows */
                        esc_html__('Produtos com combinações: %1$s de %2$s · %3$s linhas', 'rwbe-product-importer'),
                        esc_html(number_format_i18n($fitment_coverage['products'])),
                        esc_html(number_format_i18n($fitment_coverage['expected'])),
                        esc_html(number_format_i18n($fitment_coverage['rows']))
                    );
                    ?>
                </p>

                <?php if ($fitment_ready): ?>
                    <div class="rwbe-notice rwbe-notice-success">
                        <span class="dashicons dashicons-yes"></span>
                        <?php esc_html_e('Os menus e o filtro da loja estão a usar as combinações reais.', 'rwbe-product-importer'); ?>
                    </div>
                <?php else: ?>
                    <div class="rwbe-notice rwbe-notice-warning">
                        <span class="dashicons dashicons-warning"></span>
                        <?php esc_html_e('Ainda sem dados suficientes — os menus continuam a usar os atributos, como antes. Uma importação completa preenche a tabela; o preenchimento abaixo faz o mesmo sem reimportar os produtos.', 'rwbe-product-importer'); ?>
                    </div>
                <?php endif; ?>

                <?php if ($backfill_running): ?>
                    <p>
                        <span class="dashicons dashicons-update"></span>
                        <?php
                        printf(
                            /* translators: 1: products processed, 2: products remaining */
                            esc_html__('Preenchimento a decorrer: %1$s processados, %2$s em falta. Corre um lote por minuto em segundo plano.', 'rwbe-product-importer'),
                            esc_html(number_format_i18n(isset($backfill_state['processed']) ? $backfill_state['processed'] : 0)),
                            esc_html(number_format_i18n(isset($backfill_state['remaining']) ? $backfill_state['remaining'] : 0))
                        );
                        ?>
                    </p>
                    <p><a href="<?php echo esc_url($backfill_url('stop')); ?>" class="button"><?php esc_html_e('Parar preenchimento', 'rwbe-product-importer'); ?></a></p>
                <?php else: ?>
                    <p class="description"><?php esc_html_e('O preenchimento consulta a API produto a produto (o mesmo pedido que a importação já faz) e escreve apenas as combinações — não altera os produtos.', 'rwbe-product-importer'); ?></p>
                    <p><a href="<?php echo esc_url($backfill_url('start')); ?>" class="button button-secondary"><?php esc_html_e('Preencher a partir da API', 'rwbe-product-importer'); ?></a></p>
                <?php endif; ?>
            </div>

            <!-- Mapa estático (performance) -->
            <div class="rwbe-card">
                <div class="rwbe-card-header">
                    <span class="dashicons dashicons-performance"></span>
                    <h2><?php esc_html_e('Mapa de veículos (desempenho)', 'rwbe-product-importer'); ?></h2>
                </div>
                <p class="description">
                    <?php esc_html_e('As listas de modelos e anos são pré-calculadas em dois ficheiros JSON servidos diretamente pelo servidor web. Assim os menus não precisam de chamar o WordPress a cada escolha. É reconstruído automaticamente no fim de cada importação.', 'rwbe-product-importer'); ?>
                </p>

                <?php if ($rebuild_done === true): ?>
                    <div class="rwbe-notice rwbe-notice-success">
                        <span class="dashicons dashicons-yes"></span>
                        <?php esc_html_e('Mapa reconstruído com sucesso.', 'rwbe-product-importer'); ?>
                    </div>
                <?php elseif ($rebuild_done === false): ?>
                    <div class="rwbe-notice rwbe-notice-warning">
                        <span class="dashicons dashicons-warning"></span>
                        <?php esc_html_e('Não foi possível reconstruir o mapa. Verifique as permissões da pasta uploads e o registo de depuração.', 'rwbe-product-importer'); ?>
                    </div>
                <?php endif; ?>

                <p>
                    <span class="dashicons <?php echo $map_ready ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
                    <?php
                    if ($map_ready) {
                        printf(
                            /* translators: 1: date and time of the last build, 2: data source */
                            esc_html__('Mapa gerado em %1$s (origem: %2$s).', 'rwbe-product-importer'),
                            esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int) $map_urls['version'])),
                            esc_html($map_source === 'fitment'
                                ? __('combinações reais', 'rwbe-product-importer')
                                : __('atributos', 'rwbe-product-importer'))
                        );
                    } else {
                        esc_html_e('Mapa ainda não gerado — os menus estão a usar o método antigo (AJAX), mais lento mas funcional.', 'rwbe-product-importer');
                    }
                    ?>
                </p>
                <p>
                    <a href="<?php echo esc_url($rebuild_url); ?>" class="button button-secondary">
                        <?php esc_html_e('Reconstruir mapa agora', 'rwbe-product-importer'); ?>
                    </a>
                </p>
            </div>

            <!-- Aparência -->
            <div class="rwbe-card">
                <div class="rwbe-card-header">
                    <span class="dashicons dashicons-admin-customizer"></span>
                    <h2><?php esc_html_e('Aparência', 'rwbe-product-importer'); ?></h2>
                </div>
                <form method="post" action="options.php">
                    <?php settings_fields('rwbe_search_settings'); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="rwbe_ymm_button_color"><?php esc_html_e('Cor do botão Pesquisar', 'rwbe-product-importer'); ?></label></th>
                            <td>
                                <input type="color" id="rwbe_ymm_button_color" name="rwbe_ymm_button_color" value="<?php echo esc_attr($this->get_button_color()); ?>" />
                                <p class="description"><?php esc_html_e('Cor de fundo do botão de pesquisa na barra do site.', 'rwbe-product-importer'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button(__('Guardar', 'rwbe-product-importer')); ?>
                </form>
            </div>
        </div>
        <?php
    }
}
