<?php
/**
 * Admin class for RWBE Product Importer
 *
 * @since 1.0.0
 */

// Exit when accessed directly: these files only make sense inside WordPress.
if (!defined('WPINC')) {
    die;
}

class RWBE_Product_Importer_Admin {

    /** Transient holding the dashboard's catalogue counters */
    const CACHE_COUNTS = 'rwbe_dashboard_counts';

    /** How long those counters stay cached, in seconds */
    const CACHE_COUNTS_TTL = 120;

    /**
     * Initialize the class
     */
    public function init() {
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Register admin scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'register_admin_assets'));

        // Register plugin settings (API token, debug log toggle)
        add_action('admin_init', array($this, 'register_settings'));

        // Warn when no API token has been configured yet
        add_action('admin_notices', array($this, 'maybe_show_missing_token_notice'));
        
        // Handle AJAX import request
        add_action('wp_ajax_rwbe_import_products', array($this, 'ajax_import_products'));

        // Lightweight import status check (keeps the import button locked while running)
        add_action('wp_ajax_rwbe_import_status', array($this, 'ajax_import_status'));
        
        // Handle AJAX API test request
        add_action('wp_ajax_rwbe_test_api_connection', array($this, 'ajax_test_api_connection'));
        
        // Handle AJAX debug log actions
        add_action('wp_ajax_rwbe_view_debug_log', array($this, 'ajax_view_debug_log'));
        add_action('wp_ajax_rwbe_clear_debug_log', array($this, 'ajax_clear_debug_log'));

        // Handle AJAX placeholder-cleanup batches
        add_action('wp_ajax_rwbe_cleanup_placeholders', array($this, 'ajax_cleanup_placeholders'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __('RWBE Importer', 'rwbe-product-importer'),
            __('RWBE Importer', 'rwbe-product-importer'),
            'manage_options',
            'rwbe-product-importer',
            array($this, 'render_admin_page'),
            'dashicons-car',
            56
        );
    }

    /**
     * Register admin scripts and styles
     */
    public function register_admin_assets($hook) {
        // Load on the main importer page and on the plugin's sub-menu pages
        $is_main   = ($hook === 'toplevel_page_rwbe-product-importer');
        $is_search = (strpos($hook, 'rwbe-search-shortcode') !== false);

        if (!$is_main && !$is_search) {
            return;
        }

        // Shared admin styles (used by all plugin admin pages)
        wp_register_style(
            'rwbe-product-importer-admin',
            RWBE_PRODUCT_IMPORTER_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            RWBE_PRODUCT_IMPORTER_VERSION
        );
        wp_enqueue_style('rwbe-product-importer-admin');

        // The import-specific script is only needed on the main page
        if (!$is_main) {
            return;
        }

        // Register and enqueue JS
        wp_register_script(
            'rwbe-product-importer-admin',
            RWBE_PRODUCT_IMPORTER_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            RWBE_PRODUCT_IMPORTER_VERSION,
            true
        );
        
        // Localize script with AJAX URL and nonce
        wp_localize_script(
            'rwbe-product-importer-admin',
            'rwbe_importer',
            array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('rwbe_import_nonce'),
                'api_test_nonce' => wp_create_nonce('rwbe_api_test_nonce'),
                'debug_log_nonce' => wp_create_nonce('rwbe_debug_log_nonce'),
                'cleanup_nonce' => wp_create_nonce('rwbe_cleanup_nonce'),
                'confirm_cleanup' => __('Apagar as imagens placeholder duplicadas? Mantém uma cópia partilhada e reaponta todos os produtos para ela. Esta operação apaga ficheiros do disco e não é reversível.', 'rwbe-product-importer'),
                'confirm_cleanup_resume' => __('Retomar a limpeza a partir do ponto onde parou? Esta operação apaga ficheiros do disco e não é reversível.', 'rwbe-product-importer'),
                'confirm_cleanup_stop' => __('Parar a limpeza? O progresso é guardado e pode retomar depois.', 'rwbe-product-importer'),
                'cleanup_stopped' => __('Limpeza parada. O progresso foi guardado.', 'rwbe-product-importer'),
                'cleanup_state' => (new RWBE_Product_Importer())->get_placeholder_cleanup_state(),
                'cleanup_running' => __('A limpar duplicados…', 'rwbe-product-importer'),
                'cleanup_done' => __('Limpeza concluída.', 'rwbe-product-importer'),
                'cleanup_error' => __('Erro durante a limpeza. Consulte os logs.', 'rwbe-product-importer'),
                'importing_text' => __('Importing products, please wait...', 'rwbe-product-importer'),
                'import_complete' => __('Import complete!', 'rwbe-product-importer'),
                'import_error' => __('Error during import. Please check the logs.', 'rwbe-product-importer'),
                'testing_api' => __('Testing API connection, please wait...', 'rwbe-product-importer'),
                'test_complete' => __('API test complete!', 'rwbe-product-importer'),
                'test_error' => __('Error testing API connection.', 'rwbe-product-importer'),
                'viewing_log' => __('Loading debug log...', 'rwbe-product-importer'),
                'clearing_log' => __('Clearing debug log...', 'rwbe-product-importer'),
                'log_cleared' => __('Debug log cleared!', 'rwbe-product-importer'),
                'import_running' => (bool) $this->get_current_import_status()['is_running'],
                'in_progress_text' => __('Importação em curso…', 'rwbe-product-importer'),
                'start_label' => __('Iniciar Importação Completa', 'rwbe-product-importer'),
                'status_poll_interval' => 5000,
                'confirm_import' => __('Iniciar uma importação completa? Esta operação percorre todo o catálogo do fornecedor e pode demorar bastante.', 'rwbe-product-importer')
            )
        );
        
        wp_enqueue_script('rwbe-product-importer-admin');
    }

    /**
     * Show an admin notice while no API token has been configured.
     *
     * The plugin ships without any credential, so this is the expected state on
     * a fresh install until the site owner enters their own token.
     */
    public function maybe_show_missing_token_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (function_exists('rwbe_has_api_token') && rwbe_has_api_token()) {
            return;
        }

        $url = admin_url('admin.php?page=rwbe-product-importer');
        echo '<div class="notice notice-warning"><p>'
            . sprintf(
                /* translators: %s: settings page URL */
                esc_html__('RWBE Product Importer: nenhum API Token configurado. Introduza o seu token em %s antes de importar.', 'rwbe-product-importer'),
                '<a href="' . esc_url($url) . '">' . esc_html__('Configurações do plugin', 'rwbe-product-importer') . '</a>'
            )
            . '</p></div>';
    }

    /**
     * Register plugin settings via the WordPress Settings API.
     */
    public function register_settings() {
        register_setting('rwbe_settings', 'rwbe_api_token', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => ''
        ));
        register_setting('rwbe_settings', 'rwbe_enable_debug_log', array(
            'type'              => 'integer',
            'sanitize_callback' => array($this, 'sanitize_checkbox'),
            'default'           => 1
        ));
    }

    /**
     * Sanitize a checkbox value to 0/1.
     *
     * @param mixed $value
     * @return int
     */
    public function sanitize_checkbox($value) {
        return !empty($value) ? 1 : 0;
    }

    /**
     * AJAX: report whether an import is currently running, so the UI can keep the
     * import button locked until the whole import finishes.
     */
    public function ajax_import_status() {
        check_ajax_referer('rwbe_import_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'rwbe-product-importer')));
        }

        $status = $this->get_current_import_status();
        wp_send_json_success(array(
            'running'   => (bool) $status['is_running'],
            'status'    => isset($status['status']) ? $status['status'] : '',
            'can_resume' => !empty($status['can_resume']),
        ));
    }

    /**
     * The four catalogue counters shown on the dashboard, cached briefly.
     *
     * Each one is a COUNT over wp_postmeta, which on a 35k-product catalogue means
     * scanning hundreds of thousands of rows — and they ran uncached on every single
     * admin page load of the plugin. A short TTL keeps the panel current (the live
     * import page is where real-time progress is read) at a fraction of the cost.
     *
     * @return array
     */
    private function get_catalogue_counts() {
        $cached = get_transient(self::CACHE_COUNTS);
        if (is_array($cached)) {
            return $cached;
        }

        global $wpdb;

        $stats = array();

        // Total de produtos importados (com meta _rwbe_product_id)
        $stats['total_imported'] = $wpdb->get_var(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = '_rwbe_product_id'"
        );

        // Total de produtos WooCommerce
        $stats['total_products'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status IN ('publish', 'draft', 'private')"
        );

        // Produtos em stock
        $stats['in_stock'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE pm.meta_key = '_stock_status' AND pm.meta_value = 'instock'
            AND p.post_type = 'product' AND p.post_status = 'publish'"
        );

        // Produtos sem stock
        $stats['out_of_stock'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE pm.meta_key = '_stock_status' AND pm.meta_value = 'outofstock'
            AND p.post_type = 'product' AND p.post_status = 'publish'"
        );

        set_transient(self::CACHE_COUNTS, $stats, self::CACHE_COUNTS_TTL);

        return $stats;
    }

    /**
     * Get import statistics
     */
    private function get_import_statistics() {
        $stats = $this->get_catalogue_counts();

        // Última importação
        $last_import = get_option('rwbe_last_import_time');
        $stats['last_import'] = $last_import ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_import) : __('Nunca', 'rwbe-product-importer');
        
        // Última importação via cron
        $last_cron = get_option('rwbe_last_cron_import_time');
        $stats['last_cron'] = $last_cron ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_cron) : __('Nunca', 'rwbe-product-importer');
        
        return $stats;
    }
    
    /**
     * Get taxonomy statistics
     */
    private function get_taxonomy_statistics() {
        $taxonomies = array(
            'pa_brands' => 'Brands',
            'pa_grupo' => 'Grupo',
            'pa_make' => 'Marca Veículo',
            'pa_model' => 'Modelo Veículo',
            'pa_vehicle_year' => 'Ano'
        );
        
        $stats = array();
        
        foreach ($taxonomies as $taxonomy => $label) {
            $exists = taxonomy_exists($taxonomy);
            $count = 0;
            
            if ($exists) {
                $count = wp_count_terms(array('taxonomy' => $taxonomy, 'hide_empty' => false));
                if (is_wp_error($count)) {
                    $count = 0;
                }
            }
            
            $stats[$taxonomy] = array(
                'label' => $label,
                'exists' => $exists,
                'count' => $count
            );
        }
        
        return $stats;
    }
    
    /**
     * Get cron job status
     */
    private function get_cron_status() {
        $cron_jobs = array();
        
        // Import cron
        $next_import = wp_next_scheduled('rwbe_product_import_cron');
        $cron_jobs['import'] = array(
            'name' => __('Importação Automática', 'rwbe-product-importer'),
            'hook' => 'rwbe_product_import_cron',
            'next_run' => $next_import ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_import) : __('Não agendado', 'rwbe-product-importer'),
            'scheduled' => (bool) $next_import,
            'frequency' => __('2x por dia', 'rwbe-product-importer')
        );
        
        // Check interrupted imports cron
        $next_check = wp_next_scheduled('rwbe_check_interrupted_imports');
        $cron_jobs['check_interrupted'] = array(
            'name' => __('Verificar Importações Interrompidas', 'rwbe-product-importer'),
            'hook' => 'rwbe_check_interrupted_imports',
            'next_run' => $next_check ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_check) : __('Não agendado', 'rwbe-product-importer'),
            'scheduled' => (bool) $next_check,
            'frequency' => __('A cada 5 minutos', 'rwbe-product-importer')
        );
        
        return $cron_jobs;
    }
    
    /**
     * Get current import status
     */
    private function get_current_import_status() {
        $progress = get_option('rwbe_import_progress', array());
        
        $status = array(
            'is_running' => false,
            'status' => __('Inativo', 'rwbe-product-importer'),
            'status_class' => 'inactive',
            'progress_percent' => 0,
            'products_processed' => 0,
            'last_activity' => '',
            'can_resume' => false,
            'resume_info' => ''
        );
        
        if (empty($progress)) {
            return $status;
        }
        
        $timestamp = isset($progress['timestamp']) ? intval($progress['timestamp']) : 0;
        $time_since = time() - $timestamp;
        
        // Only genuinely active statuses count as "running". Paused/interrupted
        // states (paused_by_user, paused_time_limit, connection_failed) must NOT,
        // otherwise a manual "Parar" keeps the UI locked and blocks the placeholder
        // cleanup for 5 minutes.
        $active_statuses = array('starting', 'in_progress', 'processing_products', 'retrying_connection', 'resuming');
        $current_status = isset($progress['status']) ? $progress['status'] : '';

        if ($time_since < 300 && in_array($current_status, $active_statuses, true)) {
            $status['is_running'] = true;
            $status['status'] = __('Em execução', 'rwbe-product-importer');
            $status['status_class'] = 'running';
        } elseif ($current_status === 'completed') {
            $status['status'] = __('Concluída', 'rwbe-product-importer');
            $status['status_class'] = 'completed';
        } elseif ($timestamp > 0 && isset($progress['skip']) && $progress['skip'] > 0) {
            $status['status'] = __('Interrompida', 'rwbe-product-importer');
            $status['status_class'] = 'interrupted';
            $status['can_resume'] = true;
            $status['resume_info'] = sprintf(
                __('Interrompida há %s (%d produtos processados)', 'rwbe-product-importer'),
                human_time_diff($timestamp),
                isset($progress['results']['total']) ? intval($progress['results']['total']) : 0
            );
        }
        
        if (isset($progress['results']['total'])) {
            $status['products_processed'] = intval($progress['results']['total']);
        }
        
        if (isset($progress['last_activity'])) {
            $status['last_activity'] = $progress['last_activity'];
        }
        
        // Calcular percentagem se tivermos total esperado
        if (isset($progress['total_expected']) && $progress['total_expected'] > 0) {
            $status['progress_percent'] = min(100, round(($status['products_processed'] / $progress['total_expected']) * 100));
        }
        
        $status['results'] = isset($progress['results']) ? $progress['results'] : array();
        
        return $status;
    }

    /**
     * Render admin page
     */
    public function render_admin_page() {
        $stats = $this->get_import_statistics();
        $taxonomy_stats = $this->get_taxonomy_statistics();
        $cron_status = $this->get_cron_status();
        $import_status = $this->get_current_import_status();
        ?>
        <div class="wrap rwbe-product-importer">
            <h1><?php _e('RWBE Product Importer', 'rwbe-product-importer'); ?></h1>

            <!-- Configurações -->
            <div class="rwbe-card">
                <div class="rwbe-card-header">
                    <span class="dashicons dashicons-admin-generic"></span>
                    <h2><?php _e('Configurações', 'rwbe-product-importer'); ?></h2>
                </div>
                <form method="post" action="options.php">
                    <?php settings_fields('rwbe_settings'); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="rwbe_api_token"><?php _e('API Token', 'rwbe-product-importer'); ?></label></th>
                            <td>
                                <div class="rwbe-token-row">
                                    <input type="password" id="rwbe_api_token" name="rwbe_api_token" value="<?php echo esc_attr( get_option('rwbe_api_token', '') ); ?>" class="regular-text code" autocomplete="off" spellcheck="false" />
                                    <button type="button" id="rwbe-settings-test-api" class="button button-secondary">
                                        <span class="dashicons dashicons-admin-site-alt3"></span>
                                        <?php _e('Testar ligação', 'rwbe-product-importer'); ?>
                                    </button>
                                    <span id="rwbe-settings-test-result" class="rwbe-test-result" aria-live="polite"></span>
                                </div>
                                <p class="description"><?php _e('Token de autenticação da API RWBE. Tem de ser introduzido manualmente — o plugin não inclui nenhum token por defeito. O botão testa a ligação com o token introduzido (não precisa de guardar primeiro).', 'rwbe-product-importer'); ?></p>
                                <?php if (defined('RWBE_API_AUTH_TOKEN') && trim((string) RWBE_API_AUTH_TOKEN) !== '') : ?>
                                    <p class="description"><strong><?php _e('A constante RWBE_API_AUTH_TOKEN está definida no wp-config.php e tem prioridade sobre este campo.', 'rwbe-product-importer'); ?></strong></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Debug Log', 'rwbe-product-importer'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="rwbe_enable_debug_log" value="1" <?php checked(1, intval(get_option('rwbe_enable_debug_log', 1))); ?> />
                                    <?php _e('Ativar registo de depuração (recomendado desligar em produção)', 'rwbe-product-importer'); ?>
                                </label>
                                <p class="description"><?php _e('O log é rotativo (máx. ~5 MB) e guardado em uploads/rwbe-logs/.', 'rwbe-product-importer'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button(__('Guardar Configurações', 'rwbe-product-importer')); ?>
                </form>
            </div>

            <!-- Dashboard Overview -->
            <div class="rwbe-dashboard-grid">
                <!-- Estado da Importação -->
                <div class="rwbe-card rwbe-status-card">
                    <div class="rwbe-card-header">
                        <span class="dashicons dashicons-update"></span>
                        <h2><?php _e('Estado da Importação', 'rwbe-product-importer'); ?></h2>
                    </div>
                    <div class="rwbe-status-indicator <?php echo esc_attr($import_status['status_class']); ?>">
                        <span class="rwbe-status-dot"></span>
                        <span class="rwbe-status-text"><?php echo esc_html($import_status['status']); ?></span>
                    </div>
                    <?php if ($import_status['is_running'] && $import_status['progress_percent'] > 0): ?>
                    <div class="rwbe-progress-bar">
                        <div class="rwbe-progress-fill" style="width: <?php echo intval($import_status['progress_percent']); ?>%"></div>
                        <span class="rwbe-progress-text"><?php echo intval($import_status['progress_percent']); ?>%</span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($import_status['last_activity'])): ?>
                    <p class="rwbe-last-activity"><strong><?php _e('Última atividade:', 'rwbe-product-importer'); ?></strong> <?php echo esc_html($import_status['last_activity']); ?></p>
                    <?php endif; ?>
                    <?php if (isset($import_status['results']) && !empty($import_status['results'])): ?>
                    <div class="rwbe-mini-stats">
                        <span class="rwbe-mini-stat created"><span class="dashicons dashicons-plus-alt"></span> <?php echo intval($import_status['results']['created'] ?? 0); ?> <?php _e('criados', 'rwbe-product-importer'); ?></span>
                        <span class="rwbe-mini-stat updated"><span class="dashicons dashicons-update"></span> <?php echo intval($import_status['results']['updated'] ?? 0); ?> <?php _e('atualizados', 'rwbe-product-importer'); ?></span>
                        <span class="rwbe-mini-stat errors"><span class="dashicons dashicons-warning"></span> <?php echo intval($import_status['results']['errors'] ?? 0); ?> <?php _e('erros', 'rwbe-product-importer'); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Estatísticas de Produtos -->
                <div class="rwbe-card rwbe-stats-card">
                    <div class="rwbe-card-header">
                        <span class="dashicons dashicons-chart-bar"></span>
                        <h2><?php _e('Estatísticas de Produtos', 'rwbe-product-importer'); ?></h2>
                    </div>
                    <div class="rwbe-stats-grid">
                        <div class="rwbe-stat-item">
                            <span class="rwbe-stat-number"><?php echo intval($stats['total_imported']); ?></span>
                            <span class="rwbe-stat-label"><?php _e('Produtos RWBE', 'rwbe-product-importer'); ?></span>
                        </div>
                        <div class="rwbe-stat-item">
                            <span class="rwbe-stat-number"><?php echo intval($stats['total_products']); ?></span>
                            <span class="rwbe-stat-label"><?php _e('Total WooCommerce', 'rwbe-product-importer'); ?></span>
                        </div>
                        <div class="rwbe-stat-item in-stock">
                            <span class="rwbe-stat-number"><?php echo intval($stats['in_stock']); ?></span>
                            <span class="rwbe-stat-label"><?php _e('Em Stock', 'rwbe-product-importer'); ?></span>
                        </div>
                        <div class="rwbe-stat-item out-of-stock">
                            <span class="rwbe-stat-number"><?php echo intval($stats['out_of_stock']); ?></span>
                            <span class="rwbe-stat-label"><?php _e('Sem Stock', 'rwbe-product-importer'); ?></span>
                        </div>
                    </div>
                    <div class="rwbe-last-imports">
                        <p><span class="dashicons dashicons-calendar-alt"></span> <strong><?php _e('Última importação manual:', 'rwbe-product-importer'); ?></strong> <?php echo esc_html($stats['last_import']); ?></p>
                        <p><span class="dashicons dashicons-clock"></span> <strong><?php _e('Última importação cron:', 'rwbe-product-importer'); ?></strong> <?php echo esc_html($stats['last_cron']); ?></p>
                    </div>
                </div>
            </div>
            
            <!-- Taxonomias e Cron Jobs -->
            <div class="rwbe-dashboard-grid">
                <!-- Taxonomias -->
                <div class="rwbe-card">
                    <div class="rwbe-card-header">
                        <span class="dashicons dashicons-tag"></span>
                        <h2><?php _e('Taxonomias / Atributos', 'rwbe-product-importer'); ?></h2>
                    </div>
                    <table class="widefat striped rwbe-taxonomy-table">
                        <thead>
                            <tr>
                                <th><?php _e('Atributo', 'rwbe-product-importer'); ?></th>
                                <th><?php _e('Slug', 'rwbe-product-importer'); ?></th>
                                <th><?php _e('Estado', 'rwbe-product-importer'); ?></th>
                                <th><?php _e('Termos', 'rwbe-product-importer'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($taxonomy_stats as $slug => $tax): ?>
                            <tr>
                                <td><strong><?php echo esc_html($tax['label']); ?></strong></td>
                                <td><code><?php echo esc_html($slug); ?></code></td>
                                <td>
                                    <?php if ($tax['exists']): ?>
                                        <span class="rwbe-badge rwbe-badge-success"><span class="dashicons dashicons-yes"></span> <?php _e('Ativo', 'rwbe-product-importer'); ?></span>
                                    <?php else: ?>
                                        <span class="rwbe-badge rwbe-badge-error"><span class="dashicons dashicons-no"></span> <?php _e('Não existe', 'rwbe-product-importer'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo intval($tax['count']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Cron Jobs -->
                <div class="rwbe-card">
                    <div class="rwbe-card-header">
                        <span class="dashicons dashicons-backup"></span>
                        <h2><?php _e('Tarefas Agendadas (Cron)', 'rwbe-product-importer'); ?></h2>
                    </div>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php _e('Tarefa', 'rwbe-product-importer'); ?></th>
                                <th><?php _e('Frequência', 'rwbe-product-importer'); ?></th>
                                <th><?php _e('Próxima Execução', 'rwbe-product-importer'); ?></th>
                                <th><?php _e('Estado', 'rwbe-product-importer'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cron_status as $cron): ?>
                            <tr>
                                <td><strong><?php echo esc_html($cron['name']); ?></strong></td>
                                <td><?php echo esc_html($cron['frequency']); ?></td>
                                <td><?php echo esc_html($cron['next_run']); ?></td>
                                <td>
                                    <?php if ($cron['scheduled']): ?>
                                        <span class="rwbe-badge rwbe-badge-success"><span class="dashicons dashicons-yes"></span> <?php _e('Agendado', 'rwbe-product-importer'); ?></span>
                                    <?php else: ?>
                                        <span class="rwbe-badge rwbe-badge-warning"><span class="dashicons dashicons-warning"></span> <?php _e('Não agendado', 'rwbe-product-importer'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Ações de Importação -->
            <div class="rwbe-card">
                <div class="rwbe-card-header">
                    <span class="dashicons dashicons-download"></span>
                    <h2><?php _e('Importar Produtos', 'rwbe-product-importer'); ?></h2>
                </div>
                <p><?php _e('Inicie uma importação manual para criar novos produtos ou atualizar os existentes com base no SKU (itemCode).', 'rwbe-product-importer'); ?></p>
                <p class="description"><?php _e('A importação manual atualiza todas as informações do produto, incluindo taxonomias. A importação via cron apenas atualiza stock e preço.', 'rwbe-product-importer'); ?></p>
                
                <div class="rwbe-import-actions">
                    <button id="rwbe-import-button" class="button button-primary button-hero rwbe-cta" <?php disabled($import_status['is_running'], true); ?>>
                        <span class="dashicons <?php echo $import_status['is_running'] ? 'dashicons-update' : 'dashicons-download'; ?>"></span>
                        <span class="rwbe-cta-label"><?php echo $import_status['is_running'] ? esc_html__('Importação em curso…', 'rwbe-product-importer') : esc_html__('Iniciar Importação Completa', 'rwbe-product-importer'); ?></span>
                    </button>
                    
                    <?php if ($import_status['can_resume']): ?>
                    <button id="rwbe-resume-import-button" class="button button-secondary button-hero rwbe-cta-secondary">
                        <span class="dashicons dashicons-controls-play"></span>
                        <span class="rwbe-cta-label"><?php esc_html_e('Retomar Importação', 'rwbe-product-importer'); ?></span>
                    </button>
                    <?php endif; ?>
                    
                    <span id="rwbe-import-spinner" class="spinner"></span>
                </div>
                
                <?php if ($import_status['can_resume']): ?>
                <div class="rwbe-notice rwbe-notice-warning">
                    <span class="dashicons dashicons-warning"></span>
                    <?php echo esc_html($import_status['resume_info']); ?>
                </div>
                <?php endif; ?>
                
                <div id="rwbe-import-results" class="rwbe-import-results" style="display: none;">
                    <h3><?php _e('Resultados da Importação', 'rwbe-product-importer'); ?></h3>
                    <div id="rwbe-import-results-content"></div>
                </div>
            </div>

            <!-- Manutenção: limpar imagens placeholder duplicadas -->
            <div class="rwbe-card">
                <div class="rwbe-card-header">
                    <span class="dashicons dashicons-images-alt2"></span>
                    <h2><?php _e('Limpar Imagens Placeholder Duplicadas', 'rwbe-product-importer'); ?></h2>
                </div>
                <p><?php _e('Quando um produto não tem foto, o fornecedor devolve sempre a mesma imagem "Race Winning Brands". Importações antigas criaram uma cópia física por produto (dezenas de milhares de ficheiros).', 'rwbe-product-importer'); ?></p>
                <p class="description"><?php _e('Esta ação mantém uma única cópia partilhada, reaponta todos os produtos para ela e apaga as restantes cópias (ficheiros + registos). Corre em lotes; não feche a página enquanto decorre. Importações futuras já não duplicam.', 'rwbe-product-importer'); ?></p>

                <?php
                $rwbe_ph_state   = (new RWBE_Product_Importer())->get_placeholder_cleanup_state();
                $rwbe_ph_cursor  = (int) $rwbe_ph_state['cursor'];
                $rwbe_ph_running = !empty($rwbe_ph_state['running']);
                // Percentagem inicial, para a barra já aparecer preenchida no
                // carregamento da página em vez de saltar de 0 no primeiro poll.
                $rwbe_ph_percent = ($rwbe_ph_state['total'] > 0)
                    ? min(99, (int) round(($rwbe_ph_state['scanned'] / $rwbe_ph_state['total']) * 100))
                    : 0;
                ?>
                <?php if ($rwbe_ph_running) : ?>
                <p class="description">
                    <?php esc_html_e('Limpeza em curso no servidor. Pode fechar esta página ou mudar de janela — o processo continua e o progresso reaparece quando voltar.', 'rwbe-product-importer'); ?>
                </p>
                <?php elseif ($rwbe_ph_cursor > 0) : ?>
                <p class="description">
                    <?php printf(
                        /* translators: %d: attachment ID the previous pass stopped at */
                        esc_html__('Passagem anterior parou no anexo #%d. "Continuar" retoma a partir daí; "Recomeçar" volta a varrer tudo desde o início.', 'rwbe-product-importer'),
                        $rwbe_ph_cursor
                    ); ?>
                </p>
                <?php endif; ?>

                <div class="rwbe-import-actions">
                    <?php if ($rwbe_ph_cursor > 0) : ?>
                    <button id="rwbe-cleanup-continue-button" class="button button-primary button-hero rwbe-cta-secondary" <?php disabled($rwbe_ph_running, true); ?>>
                        <span class="dashicons dashicons-controls-play"></span>
                        <span class="rwbe-cta-label"><?php esc_html_e('Continuar Limpeza', 'rwbe-product-importer'); ?></span>
                    </button>
                    <?php endif; ?>
                    <button id="rwbe-cleanup-placeholders-button" class="button button-secondary button-hero rwbe-cta-secondary" <?php disabled($rwbe_ph_running, true); ?>>
                        <span class="dashicons dashicons-trash"></span>
                        <span class="rwbe-cta-label"><?php echo $rwbe_ph_cursor > 0
                            ? esc_html__('Recomeçar do Início', 'rwbe-product-importer')
                            : esc_html__('Apagar Placeholders Duplicados', 'rwbe-product-importer'); ?></span>
                    </button>
                    <button id="rwbe-cleanup-stop-button" class="button button-secondary button-hero rwbe-cta-secondary" style="<?php echo $rwbe_ph_running ? '' : 'display: none;'; ?>">
                        <span class="dashicons dashicons-no"></span>
                        <span class="rwbe-cta-label"><?php esc_html_e('Parar Limpeza', 'rwbe-product-importer'); ?></span>
                    </button>
                    <span id="rwbe-cleanup-spinner" class="spinner <?php echo $rwbe_ph_running ? 'is-active' : ''; ?>"></span>
                </div>

                <div id="rwbe-cleanup-status" style="<?php echo $rwbe_ph_running ? '' : 'display: none;'; ?> margin-top: 12px;">
                    <div class="rwbe-progress-bar">
                        <div id="rwbe-cleanup-fill" class="rwbe-progress-fill" style="width: <?php echo (int) $rwbe_ph_percent; ?>%"></div>
                        <span id="rwbe-cleanup-percent" class="rwbe-progress-text"><?php echo (int) $rwbe_ph_percent; ?>%</span>
                    </div>
                    <div id="rwbe-cleanup-progress" class="rwbe-notice" style="margin-top: 10px;"></div>
                </div>
            </div>

            <div id="rwbe-debug-log-results" class="rwbe-import-results" style="display: none;">
                <h3><?php _e('Debug Log', 'rwbe-product-importer'); ?></h3>
                <div id="rwbe-debug-log-content" style="max-height: 400px; overflow: auto;"></div>
            </div>

            <!-- Produtos Recentes -->
            <div class="rwbe-card">
                <div class="rwbe-card-header">
                    <span class="dashicons dashicons-list-view"></span>
                    <h2><?php _e('Produtos Recentemente Processados', 'rwbe-product-importer'); ?></h2>
                </div>
                <?php 
                $recent_logs = get_option('rwbe_recent_product_logs', array());
                if (!empty($recent_logs)):
                    $sample = array_slice($recent_logs, 0, 15);
                ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php _e('Hora', 'rwbe-product-importer'); ?></th>
                            <th><?php _e('SKU', 'rwbe-product-importer'); ?></th>
                            <th><?php _e('Título', 'rwbe-product-importer'); ?></th>
                            <th><?php _e('Ação', 'rwbe-product-importer'); ?></th>
                            <th><?php _e('Status', 'rwbe-product-importer'); ?></th>
                            <th><?php _e('Stock', 'rwbe-product-importer'); ?></th>
                            <th><?php _e('Fonte', 'rwbe-product-importer'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sample as $log): 
                            $action_class = '';
                            $action = isset($log['action']) ? $log['action'] : '';
                            if (strpos($action, 'created') !== false || strpos($action, 'criado') !== false) {
                                $action_class = 'rwbe-action-created';
                            } elseif (strpos($action, 'updated') !== false || strpos($action, 'atualizado') !== false) {
                                $action_class = 'rwbe-action-updated';
                            } elseif (strpos($action, 'error') !== false || strpos($action, 'erro') !== false) {
                                $action_class = 'rwbe-action-error';
                            }
                        ?>
                        <tr>
                            <td><?php echo esc_html(date_i18n('H:i:s', intval($log['timestamp']))); ?></td>
                            <td><code><?php echo esc_html(isset($log['item_code']) ? $log['item_code'] : ''); ?></code></td>
                            <td><?php echo esc_html(isset($log['title']) ? wp_trim_words($log['title'], 5) : ''); ?></td>
                            <td><span class="rwbe-action-badge <?php echo esc_attr($action_class); ?>"><?php echo esc_html($action); ?></span></td>
                            <td><?php echo esc_html(isset($log['status']) ? $log['status'] : ''); ?></td>
                            <td><strong><?php echo esc_html(isset($log['stock']) ? (string)intval($log['stock']) : '-'); ?></strong></td>
                            <td><?php echo esc_html(isset($log['stock_source']) && $log['stock_source'] === 'stock_endpoint' ? 'API Stock' : 'Detalhe'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <p class="description"><?php _e('Ainda não existem produtos processados para mostrar. Inicie uma importação para ver os resultados aqui.', 'rwbe-product-importer'); ?></p>
                <?php endif; ?>
            </div>
            
            <!-- Informações do Sistema -->
            <div class="rwbe-card rwbe-system-info">
                <div class="rwbe-card-header">
                    <span class="dashicons dashicons-info"></span>
                    <h2><?php _e('Informações do Sistema', 'rwbe-product-importer'); ?></h2>
                </div>
                <div class="rwbe-system-grid">
                    <div class="rwbe-system-item">
                        <strong><?php _e('Versão do Plugin:', 'rwbe-product-importer'); ?></strong>
                        <span><?php echo defined('RWBE_PRODUCT_IMPORTER_VERSION') ? RWBE_PRODUCT_IMPORTER_VERSION : '1.0.0'; ?></span>
                    </div>
                    <div class="rwbe-system-item">
                        <strong><?php _e('WooCommerce:', 'rwbe-product-importer'); ?></strong>
                        <span><?php echo defined('WC_VERSION') ? WC_VERSION : __('Não instalado', 'rwbe-product-importer'); ?></span>
                    </div>
                    <div class="rwbe-system-item">
                        <strong><?php _e('PHP:', 'rwbe-product-importer'); ?></strong>
                        <span><?php echo phpversion(); ?></span>
                    </div>
                    <div class="rwbe-system-item">
                        <strong><?php _e('Memory Limit:', 'rwbe-product-importer'); ?></strong>
                        <span><?php echo ini_get('memory_limit'); ?></span>
                    </div>
                    <div class="rwbe-system-item">
                        <strong><?php _e('Max Execution Time:', 'rwbe-product-importer'); ?></strong>
                        <span><?php echo ini_get('max_execution_time'); ?>s</span>
                    </div>
                    <div class="rwbe-system-item">
                        <strong><?php _e('WordPress Cron:', 'rwbe-product-importer'); ?></strong>
                        <span><?php echo defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? __('Desativado', 'rwbe-product-importer') : __('Ativo', 'rwbe-product-importer'); ?></span>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Handle AJAX import request
     */
    /**
     * AJAX endpoint for the placeholder-duplicate cleanup.
     *
     * The run itself belongs to the server (state in an option, work behind an
     * advisory lock, continued by cron), so this only starts it, stops it, reads
     * its progress, or lends the request to the worker for a few seconds:
     *
     *   op=start   begin a run ('reset' rewinds the cursor) and work on it
     *   op=work    keep working while the page is open (the default)
     *   op=status  read-only progress, used when the page is reopened
     *   op=stop    ask the run to stop after the current batch
     *
     * Closing the tab or switching window therefore does not cancel anything: the
     * queued cron worker carries the run on, and reopening the page picks the
     * progress bar back up.
     */
    public function ajax_cleanup_placeholders() {
        check_ajax_referer('rwbe_cleanup_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'rwbe-product-importer')));
            return;
        }

        $op = isset($_POST['op']) ? sanitize_key($_POST['op']) : 'work';
        $importer = new RWBE_Product_Importer();

        if ($op === 'status') {
            wp_send_json_success($importer->get_placeholder_cleanup_state());
            return;
        }

        if ($op === 'stop') {
            wp_send_json_success($importer->stop_placeholder_cleanup());
            return;
        }

        // Never run while an import is active — they touch the same attachments.
        $status = $this->get_current_import_status();
        if (!empty($status['is_running'])) {
            wp_send_json_error(array('message' => __('Importação em curso. Tente novamente quando terminar.', 'rwbe-product-importer')));
            return;
        }

        // Finish the batch in progress even if the operator navigates away.
        @ignore_user_abort(true);
        @set_time_limit(0);

        if ($op === 'start') {
            $reset = !empty($_POST['reset']) && $_POST['reset'] === 'true';
            $importer->start_placeholder_cleanup($reset);
        }

        $state = $importer->run_placeholder_cleanup_batches();

        // A failed canonical verification aborts the whole run — surface it as an
        // error so the page stops looping and the operator sees why.
        if (!empty($state['error'])) {
            wp_send_json_error(array('message' => $state['error'], 'state' => $state));
            return;
        }

        wp_send_json_success($state);
    }

    public function ajax_import_products() {
        // Check nonce
        check_ajax_referer('rwbe_import_nonce', 'nonce');
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'rwbe-product-importer')));
            return;
        }
        
        // Verificar se é para retomar a importação
        $resume = isset($_POST['resume']) && $_POST['resume'] === 'true';
        
        // Limpar logs recentes se não estiver retomando uma importação
        if (!$resume) {
            // Limpar logs recentes
            if (class_exists('RWBE_Product_Importer_Live_Log')) {
                RWBE_Product_Importer_Live_Log::clear_logs();
            }
            
            // Inicializar o progresso com valores zerados
            $progress = array(
                'status' => 'starting',
                'last_activity' => 'Starting import',
                'timestamp' => time(),
                'skip' => 0,
                'results' => array(
                    'total' => 0,
                    'created' => 0,
                    'updated' => 0,
                    'skipped' => 0,
                    'errors' => 0,
                    'error_messages' => array()
                )
            );
            update_option('rwbe_import_progress', $progress, false);
        }
        
        // Initialize the importer
        $importer = new RWBE_Product_Importer();
        $importer->init();
        // Ensure attributes exist and sync taxonomies before starting import
        $importer->ensure_all_attributes_exist();
        if (method_exists($importer, 'maybe_sync_taxonomies')) {
            $importer->maybe_sync_taxonomies();
        }
        
        // Iniciar a importação com resiliência em segundo plano
        $importer->import_products_with_resilience(false, $resume);
        
        // Também agendar via cron para garantir que continue em caso de timeout
        wp_schedule_single_event(time() + 60, 'rwbe_product_import_cron');
        
        // Preparar a resposta para o usuário
        $results = get_option('rwbe_import_progress', array());
        if (isset($results['results'])) {
            $formatted_results = $results['results'];
        } else {
            $formatted_results = array(
                'total' => 0,
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => 0,
                'error_messages' => array()
            );
        }
        
        // Format the results
        $response = array(
            'success' => true,
            'data' => $formatted_results,
            'html' => $this->format_import_results($formatted_results),
            'message' => __('Importação iniciada em segundo plano. Você pode acompanhar o progresso na página de Importação em Tempo Real.', 'rwbe-product-importer')
        );
        
        wp_send_json_success($response);
    }

    /**
     * Format import results for display
     *
     * @param array $results Import results
     * @return string Formatted HTML
     */
    private function format_import_results($results) {
        ob_start();
        ?>
        <div class="rwbe-import-summary">
            <p><strong><?php _e('Total products processed:', 'rwbe-product-importer'); ?></strong> <?php echo intval($results['total']); ?></p>
            <p><strong><?php _e('New products created:', 'rwbe-product-importer'); ?></strong> <?php echo intval($results['created']); ?></p>
            <p><strong><?php _e('Existing products updated:', 'rwbe-product-importer'); ?></strong> <?php echo intval($results['updated']); ?></p>
            <p><strong><?php _e('Products skipped:', 'rwbe-product-importer'); ?></strong> <?php echo intval($results['skipped']); ?></p>
            <p><strong><?php _e('Errors:', 'rwbe-product-importer'); ?></strong> <?php echo intval($results['errors']); ?></p>
        </div>
        
        <?php if (!empty($results['error_messages'])): ?>
        <div class="rwbe-import-errors">
            <h4><?php _e('Error Details:', 'rwbe-product-importer'); ?></h4>
            <ul>
                <?php foreach ($results['error_messages'] as $error): ?>
                <li><?php echo esc_html($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * Handle AJAX API test request
     */
    public function ajax_test_api_connection() {
        // Check nonce
        check_ajax_referer('rwbe_api_test_nonce', 'nonce');
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'rwbe-product-importer')));
            return;
        }
        
        // Include the API tester class
        require_once RWBE_PRODUCT_IMPORTER_PLUGIN_DIR . 'includes/class-rwbe-api-tester.php';

        // Optional token typed in the settings field (test before saving)
        $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : null;

        // Test the API connection
        $results = RWBE_API_Tester::test_connection($token);
        
        // Format the results
        $response = array(
            'success' => $results['success'],
            'message' => $results['message'],
            'html' => RWBE_API_Tester::render_results($results)
        );
        
        if ($results['success']) {
            wp_send_json_success($response);
        } else {
            wp_send_json_error($response);
        }
    }
    
    /**
     * Handle AJAX view debug log request
     */
    public function ajax_view_debug_log() {
        // Check nonce
        check_ajax_referer('rwbe_debug_log_nonce', 'nonce');
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'rwbe-product-importer')));
            return;
        }
        
        // Inicializar o logger para garantir que o caminho do arquivo esteja definido
        RWBE_Debug_Logger::init();
        
        // Obter o caminho do arquivo de log da classe logger
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/rwbe-logs';
        $log_file = $log_dir . '/debug.log';
        
        if (file_exists($log_file)) {
            $log_content = @file_get_contents($log_file);
            if ($log_content === false) {
                $log_content = __('Error reading debug log file. Check file permissions.', 'rwbe-product-importer');
            } elseif (empty($log_content)) {
                $log_content = __('The debug log is empty.', 'rwbe-product-importer');
            }
        } else {
            $log_content = __('No debug log file found. It will be created when the first log entry is added.', 'rwbe-product-importer');
        }
        
        wp_send_json_success(array(
            'content' => nl2br(esc_html($log_content))
        ));
    }
    
    /**
     * Handle AJAX clear debug log request
     */
    public function ajax_clear_debug_log() {
        // Check nonce
        check_ajax_referer('rwbe_debug_log_nonce', 'nonce');
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'rwbe-product-importer')));
            return;
        }
        
        // Inicializar o logger para garantir que o caminho do arquivo esteja definido
        RWBE_Debug_Logger::init();
        
        // Limpar o log
        $result = RWBE_Debug_Logger::clear_log();
        
        if ($result) {
            wp_send_json_success(array(
                'message' => __('Debug log cleared successfully.', 'rwbe-product-importer')
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Error clearing debug log. Check file permissions.', 'rwbe-product-importer')
            ));
        }
    }
}
