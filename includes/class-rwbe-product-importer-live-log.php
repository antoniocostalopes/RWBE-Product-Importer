<?php
/**
 * Live Log functionality for RWBE Product Importer
 *
 * @since 1.0.0
 */
class RWBE_Product_Importer_Live_Log {

    /**
     * How often the recent-product log is persisted, in seconds.
     *
     * The dashboard polls every 3s, so persisting at most every 2s keeps it just
     * as current while turning one read + one write per product (35k of each on a
     * full catalogue) into a handful per second.
     */
    const FLUSH_INTERVAL = 2;

    /**
     * In-memory copy of the recent-product log. Read from the option once per
     * request, then kept here.
     *
     * @var array|null
     */
    private static $logs_cache = null;

    /**
     * Whether the cache holds entries that are not in the option yet.
     *
     * @var bool
     */
    private static $logs_dirty = false;

    /**
     * Timestamp of the last persist.
     *
     * @var int
     */
    private static $last_flush = 0;

    /**
     * Whether the end-of-request flush has been registered.
     *
     * @var bool
     */
    private static $shutdown_hooked = false;

    /**
     * Initialize the class
     */
    public function init() {
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Register AJAX handlers
        add_action('wp_ajax_rwbe_get_import_logs', array($this, 'ajax_get_import_logs'));
        add_action('wp_ajax_rwbe_start_import', array($this, 'ajax_start_import'));
        add_action('wp_ajax_rwbe_stop_import', array($this, 'ajax_stop_import'));
        add_action('wp_ajax_rwbe_resume_import', array($this, 'ajax_resume_import'));
        
        // Register scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'register_assets'));
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'rwbe-product-importer',
            __('Importação em Tempo Real', 'rwbe-product-importer'),
            __('Importação em Tempo Real', 'rwbe-product-importer'),
            'manage_options',
            'rwbe-live-import',
            array($this, 'render_admin_page')
        );
    }
    
    /**
     * Register assets
     */
    public function register_assets($hook) {
        // Only load on the live-import page (hook prefix depends on the parent menu)
        if (strpos($hook, 'rwbe-live-import') === false) {
            return;
        }
        
        // Register and enqueue styles
        wp_register_style(
            'rwbe-live-log-styles',
            RWBE_PRODUCT_IMPORTER_PLUGIN_URL . 'assets/css/live-log.css',
            array(),
            RWBE_PRODUCT_IMPORTER_VERSION
        );
        wp_enqueue_style('rwbe-live-log-styles');
        
        // Register and enqueue scripts
        wp_register_script(
            'rwbe-live-log-scripts',
            RWBE_PRODUCT_IMPORTER_PLUGIN_URL . 'assets/js/live-log.js',
            array('jquery'),
            RWBE_PRODUCT_IMPORTER_VERSION,
            true
        );
        
        // Localize script with data
        wp_localize_script('rwbe-live-log-scripts', 'rwbeLiveLog', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rwbe-live-log-nonce'),
            'refreshInterval' => 3000, // Refresh every 3 seconds
            'i18n' => array(
                'loading' => __('Carregando logs...', 'rwbe-product-importer'),
                'noImport' => __('Nenhuma importação em andamento.', 'rwbe-product-importer'),
                'lastUpdated' => __('Última atualização:', 'rwbe-product-importer'),
                'status' => __('Status:', 'rwbe-product-importer'),
                'progress' => __('Progresso:', 'rwbe-product-importer'),
                'recentProducts' => __('Produtos Recentes:', 'rwbe-product-importer'),
                'startImport' => __('Iniciar Importação', 'rwbe-product-importer'),
                'stopImport' => __('Parar Importação', 'rwbe-product-importer'),
                'resumeImport' => __('Retomar Importação', 'rwbe-product-importer'),
                'confirmStop' => __('Tem certeza que deseja parar a importação? Você poderá retomá-la mais tarde.', 'rwbe-product-importer')
            )
        ));
        wp_enqueue_script('rwbe-live-log-scripts');
    }
    
    /**
     * Render admin page
     */
    public function render_admin_page() {
        ?>
        <div class="wrap rwbe-live-log-wrap">
            <div class="rwbe-ll-titlebar">
                <h1><?php _e('Importação em Tempo Real', 'rwbe-product-importer'); ?></h1>
                <div class="rwbe-ll-actions">
                    <button id="rwbe-start-import" class="button button-primary"><span class="dashicons dashicons-controls-play"></span> <?php _e('Iniciar', 'rwbe-product-importer'); ?></button>
                    <button id="rwbe-stop-import" class="button rwbe-btn-stop" style="display:none;"><span class="dashicons dashicons-no-alt"></span> <?php _e('Parar', 'rwbe-product-importer'); ?></button>
                    <button id="rwbe-resume-import" class="button button-primary" style="display:none;"><span class="dashicons dashicons-controls-play"></span> <?php _e('Retomar', 'rwbe-product-importer'); ?></button>
                </div>
            </div>

            <div class="rwbe-live-log-container">
                <!-- Metrics strip -->
                <div class="rwbe-ll-metrics">
                    <div class="rwbe-ll-metric rwbe-ll-status-cell">
                        <span class="rwbe-ll-metric-label"><?php _e('Estado', 'rwbe-product-importer'); ?></span>
                        <span class="rwbe-ll-status-pill" id="rwbe-status-pill">
                            <span class="rwbe-ll-status-dot"></span>
                            <span class="status-value" id="rwbe-import-status"><?php _e('A carregar…', 'rwbe-product-importer'); ?></span>
                        </span>
                    </div>
                    <div class="rwbe-ll-metric">
                        <span class="rwbe-ll-metric-label"><?php _e('Tempo decorrido', 'rwbe-product-importer'); ?></span>
                        <span class="rwbe-ll-metric-value" id="rwbe-import-elapsed">—</span>
                    </div>
                    <div class="rwbe-ll-metric">
                        <span class="rwbe-ll-metric-label"><?php _e('Ritmo', 'rwbe-product-importer'); ?></span>
                        <span class="rwbe-ll-metric-value" id="rwbe-import-rate">—</span>
                    </div>
                    <div class="rwbe-ll-metric">
                        <span class="rwbe-ll-metric-label"><?php _e('Progresso', 'rwbe-product-importer'); ?></span>
                        <span class="rwbe-ll-metric-value" id="rwbe-import-progress-text">0%</span>
                    </div>
                </div>

                <!-- Progress bar -->
                <div class="progress-bar-container">
                    <div class="progress-bar" id="rwbe-import-progress-bar"></div>
                </div>

                <!-- Counters -->
                <div class="stats-container">
                    <div class="stat-item">
                        <span class="stat-label"><?php _e('Total', 'rwbe-product-importer'); ?></span>
                        <span class="stat-value" id="rwbe-import-total">0</span>
                    </div>
                    <div class="stat-item stat-created">
                        <span class="stat-label"><?php _e('Criados', 'rwbe-product-importer'); ?></span>
                        <span class="stat-value" id="rwbe-import-created">0</span>
                    </div>
                    <div class="stat-item stat-updated">
                        <span class="stat-label"><?php _e('Atualizados', 'rwbe-product-importer'); ?></span>
                        <span class="stat-value" id="rwbe-import-updated">0</span>
                    </div>
                    <div class="stat-item stat-skipped">
                        <span class="stat-label"><?php _e('Ignorados', 'rwbe-product-importer'); ?></span>
                        <span class="stat-value" id="rwbe-import-skipped">0</span>
                    </div>
                    <div class="stat-item stat-errors">
                        <span class="stat-label"><?php _e('Erros', 'rwbe-product-importer'); ?></span>
                        <span class="stat-value" id="rwbe-import-errors">0</span>
                    </div>
                </div>

                <!-- Current activity -->
                <div class="rwbe-ll-activity" id="rwbe-current-activity-wrap">
                    <span class="dashicons dashicons-update rwbe-ll-spin"></span>
                    <span id="rwbe-current-activity"><?php _e('Aguardando…', 'rwbe-product-importer'); ?></span>
                </div>

                <!-- Activity feed -->
                <div class="rwbe-live-log-body">
                    <h2><?php _e('Atividade por produto', 'rwbe-product-importer'); ?></h2>
                    <div class="rwbe-live-log-entries" id="rwbe-live-log-entries">
                        <div class="rwbe-log-loading"><?php _e('A carregar…', 'rwbe-product-importer'); ?></div>
                    </div>
                </div>

                <div class="rwbe-live-log-footer">
                    <span class="last-update-label"><?php _e('Última atualização:', 'rwbe-product-importer'); ?></span>
                    <span class="last-update-value" id="rwbe-last-update">—</span>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * AJAX handler to get import logs
     */
    public function ajax_get_import_logs() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'rwbe-live-log-nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce'));
        }
        
        // Get import progress
        $progress = get_option('rwbe_import_progress', array());
        $recent_logs = get_option('rwbe_recent_product_logs', array());
        
        // Prepare response
        $response = array(
            'status' => 'no_import',
            'progress' => array(
                'total' => 0,
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => 0,
                'completed' => false
            ),
            'logs' => array(),
            'timestamp' => current_time('mysql'),
            'import_active' => false,
            'last_activity' => ''
        );
        
        // Verificar se existe uma importação em andamento ou concluída recentemente
        if (!empty($progress)) {
            $response['status'] = isset($progress['status']) ? $progress['status'] : 'unknown';
            $response['import_active'] = in_array($response['status'], ['in_progress', 'processing_products', 'retrying_connection']);
            
            // Usar os dados de progresso como fonte primária para estatísticas
            if (isset($progress['results']) && is_array($progress['results'])) {
                // Garantir que temos valores válidos
                foreach (['total', 'created', 'updated', 'skipped', 'errors', 'completed'] as $key) {
                    if (isset($progress['results'][$key])) {
                        $response['progress'][$key] = $progress['results'][$key];
                    }
                }
                
                // Garantir que o total nunca é menor que a soma dos itens processados
                $processed_items = $response['progress']['created'] + 
                                   $response['progress']['updated'] + 
                                   $response['progress']['skipped'] + 
                                   $response['progress']['errors'];
                                   
                if ($response['progress']['total'] < $processed_items) {
                    $response['progress']['total'] = $processed_items;
                }
            }
            
            // Adicionar informações de atividade e timestamp
            if (isset($progress['last_activity'])) {
                $response['last_activity'] = $progress['last_activity'];
            }
            
            if (isset($progress['timestamp'])) {
                $response['last_timestamp'] = $progress['timestamp'];
                $response['time_diff'] = human_time_diff($progress['timestamp'], time());
            }
        } else {
            // Se não houver dados de progresso, usar os logs recentes para estatísticas básicas
            // Isso é um fallback e não deve ser a fonte primária de estatísticas
            if (!empty($recent_logs) && is_array($recent_logs)) {
                $created = $updated = $skipped = $errors = 0;
                
                foreach ($recent_logs as $log) {
                    if (isset($log['action'])) {
                        switch ($log['action']) {
                            case 'created':
                                $created++;
                                break;
                            case 'updated':
                                $updated++;
                                break;
                            case 'skipped':
                                $skipped++;
                                break;
                            case 'error':
                                $errors++;
                                break;
                        }
                    }
                }
                
                $response['progress']['total'] = count($recent_logs);
                $response['progress']['created'] = $created;
                $response['progress']['updated'] = $updated;
                $response['progress']['skipped'] = $skipped;
                $response['progress']['errors'] = $errors;
            }
        }
        
        // Adicionar logs recentes à resposta
        if (!empty($recent_logs) && is_array($recent_logs)) {
            $response['logs'] = array_slice($recent_logs, 0, 50); // Limitar a 50 mais recentes
        }
        
        // Metrics for the live dashboard (elapsed time / throughput)
        $response['started_at'] = intval(get_option('rwbe_import_started_at', 0));
        $response['server_time'] = time();

        // Não atualizar o progresso aqui para evitar inconsistências
        // Apenas retornar os dados atuais

        wp_send_json_success($response);
    }
    
    /**
     * AJAX handler to start import
     */
    public function ajax_start_import() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'rwbe-live-log-nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce'));
        }
        
        // Check if import is already in progress
        $progress = get_option('rwbe_import_progress', array());
        if (!empty($progress) && isset($progress['status']) && 
            in_array($progress['status'], array('in_progress', 'processing_products', 'retrying_connection'))) {
            wp_send_json_error(array('message' => 'Import is already running'));
            return;
        }
        
        // Limpar logs antigos ao iniciar uma nova importação para evitar estatísticas inconsistentes
        self::clear_logs();
        
        // Inicializar estatísticas com valores zerados
        $initial_progress = array(
            'skip' => 0,
            'results' => array(
                'total' => 0,
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => 0,
                'error_messages' => array(),
                'completed' => false
            ),
            'timestamp' => time(),
            'status' => 'in_progress',
            'last_activity' => 'Starting new import'
        );
        
        update_option('rwbe_import_progress', $initial_progress, false);
        
        // Inicializar o importador
        $importer = new RWBE_Product_Importer();
        $importer->init();
        
        // Iniciar a importação diretamente (não via cron)
        $importer->import_products_with_resilience(false, false);
        
        // Também agendar via cron para garantir que continue em caso de timeout
        wp_schedule_single_event(time() + 60, 'rwbe_product_import_cron');
        
        wp_send_json_success(array(
            'message' => 'Import started',
            'timestamp' => current_time('mysql')
        ));
    }
    
    /**
     * AJAX handler to stop import
     */
    public function ajax_stop_import() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'rwbe-live-log-nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce'));
        }
        
        // Get current progress
        $progress = get_option('rwbe_import_progress', array());
        
        if (empty($progress)) {
            wp_send_json_error(array('message' => 'No import in progress'));
            return;
        }
        
        // Raise the dedicated stop flag FIRST. The running import loop reads this
        // (not the progress option, which it keeps overwriting) and will break out
        // at its next check, then persist the paused state itself.
        if (class_exists('RWBE_Product_Importer')) {
            $importer = new RWBE_Product_Importer();
            $importer->request_stop();
        } else {
            update_option('rwbe_import_stop_requested', '1', false);
        }

        // Also reflect the pause in the progress option for the UI. If a loop is
        // still running it may briefly overwrite this, but the stop flag above is
        // what actually stops it and keeps the watchdog from resuming.
        $progress['status'] = 'paused_by_user';
        $progress['last_activity'] = 'Import paused by user';
        $progress['timestamp'] = time();

        update_option('rwbe_import_progress', $progress, false);

        wp_send_json_success(array(
            'message' => 'Import paused',
            'timestamp' => current_time('mysql')
        ));
    }
    
    /**
     * AJAX handler to resume import
     */
    public function ajax_resume_import() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'rwbe-live-log-nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce'));
        }
        
        // Check if there's an import to resume
        $progress = get_option('rwbe_import_progress', array());
        
        if (empty($progress)) {
            wp_send_json_error(array('message' => 'No import to resume'));
            return;
        }
        
        // Update status to indicate resuming
        $progress['status'] = 'resuming';
        $progress['last_activity'] = 'Resuming import';
        $progress['timestamp'] = time();
        
        update_option('rwbe_import_progress', $progress, false);
        
        // Schedule the import to run immediately via WP Cron
        wp_schedule_single_event(time(), 'rwbe_product_import_cron');
        
        // Trigger the cron event immediately
        spawn_cron();
        
        wp_send_json_success(array(
            'message' => 'Import resumed',
            'timestamp' => current_time('mysql')
        ));
    }
    
    /**
     * Add a product to the recent logs
     *
     * @param array $product_data Product data
     * @param string $action Action performed (created, updated, skipped)
     */
    public static function add_product_log($product_data, $action, $details = array()) {
        if (empty($product_data) || empty($product_data['title'])) {
            return;
        }

        if (self::$logs_cache === null) {
            $stored = get_option('rwbe_recent_product_logs', array());
            self::$logs_cache = is_array($stored) ? $stored : array();
        }
        $recent_logs = self::$logs_cache;

        // Add new log entry
        $log_entry = array(
            'product_id' => isset($product_data['id']) ? $product_data['id'] : '',
            'item_code' => isset($product_data['itemCode']) ? $product_data['itemCode'] : '',
            'title' => $product_data['title'],
            'action' => $action,
            'timestamp' => time(),
            // Additional informational fields for UI
            'status' => isset($product_data['status']) ? $product_data['status'] : '',
            'stock' => isset($product_data['stock']) ? intval($product_data['stock']) : null,
            'stock_source' => isset($product_data['_stock_source']) ? $product_data['_stock_source'] : 'api_detail',
            'brand_title' => isset($product_data['brand']['title']) ? $product_data['brand']['title'] : '',
            // Per-product breakdown of associated elements (brand/category/models/images/price)
            'details' => is_array($details) ? $details : array()
        );
        
        // Add to beginning of array
        array_unshift($recent_logs, $log_entry);
        
        // Limit to 100 entries
        if (count($recent_logs) > 100) {
            $recent_logs = array_slice($recent_logs, 0, 100);
        }

        self::$logs_cache = $recent_logs;
        self::$logs_dirty = true;

        // Whatever is still unsaved when the request ends (or dies) must reach the
        // option, or the dashboard would lose the tail of the run.
        if (!self::$shutdown_hooked) {
            self::$shutdown_hooked = true;
            register_shutdown_function(array(__CLASS__, 'flush_product_logs'));
        }

        if ((time() - self::$last_flush) >= self::FLUSH_INTERVAL) {
            self::flush_product_logs();
        }
    }

    /**
     * Persist the buffered recent-product log.
     *
     * Autoload stays disabled: this can hold up to 100 entries and must not be
     * loaded on every frontend request.
     */
    public static function flush_product_logs() {
        if (!self::$logs_dirty || self::$logs_cache === null) {
            return;
        }
        update_option('rwbe_recent_product_logs', self::$logs_cache, false);
        self::$logs_dirty = false;
        self::$last_flush = time();
    }

    /**
     * Clear recent logs
     */
    public static function clear_logs() {
        // Drop the buffer too, otherwise a later entry in this same request would
        // write the cleared list straight back. (The importer clears the log and
        // then runs the import inside one request.)
        self::$logs_cache = array();
        self::$logs_dirty = false;
        delete_option('rwbe_recent_product_logs');
    }
}
