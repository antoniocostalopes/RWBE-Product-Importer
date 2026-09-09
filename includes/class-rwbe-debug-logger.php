<?php
/**
 * Debug Logger for RWBE Product Importer
 *
 * @since 1.0.0
 */
class RWBE_Debug_Logger {

    /**
     * Log file path
     *
     * @var string
     */
    private static $log_file;

    /**
     * Whether logging is enabled (cached per request)
     *
     * @var bool|null
     */
    private static $enabled = null;

    /**
     * Maximum log file size before rotation (5 MB)
     */
    const MAX_LOG_SIZE = 5242880;

    /**
     * Initialize the logger
     */
    public static function init() {
        // Usar o diretório uploads do WordPress para armazenar o log, que geralmente tem permissões corretas
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/rwbe-logs';

        // Criar o diretório se não existir
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }

        self::$log_file = $log_dir . '/debug.log';

        // Criar o arquivo se não existir
        if (!file_exists(self::$log_file)) {
            file_put_contents(self::$log_file, '');
        }
    }

    /**
     * Whether debug logging is enabled.
     *
     * Disabled if the constant RWBE_DISABLE_LOGGING is true, otherwise controlled
     * by the option 'rwbe_enable_debug_log' (default: enabled). Cached per request.
     *
     * @return bool
     */
    private static function is_enabled() {
        if (self::$enabled === null) {
            if (defined('RWBE_DISABLE_LOGGING') && RWBE_DISABLE_LOGGING) {
                self::$enabled = false;
            } elseif (function_exists('get_option')) {
                self::$enabled = (intval(get_option('rwbe_enable_debug_log', 1)) === 1);
            } else {
                self::$enabled = true;
            }
        }
        return self::$enabled;
    }

    /**
     * Rotate the log file when it exceeds the maximum size.
     *
     * Keeps a single previous backup (debug.log.1); total disk usage is therefore
     * bounded to roughly 2 x MAX_LOG_SIZE.
     */
    private static function maybe_rotate() {
        if (!self::$log_file || !file_exists(self::$log_file)) {
            return;
        }
        clearstatcache(true, self::$log_file);
        if (filesize(self::$log_file) <= self::MAX_LOG_SIZE) {
            return;
        }
        $backup = self::$log_file . '.1';
        if (file_exists($backup)) {
            @unlink($backup);
        }
        @rename(self::$log_file, $backup);
        @file_put_contents(self::$log_file, '');
    }

    /**
     * Log a message
     *
     * @param string $message Message to log
     * @param mixed $data Optional data to log
     */
    public static function log($message, $data = null) {
        if (!self::is_enabled()) {
            return;
        }

        if (!self::$log_file) {
            self::init();
        }

        // Keep disk usage bounded
        self::maybe_rotate();

        $timestamp = date('Y-m-d H:i:s');
        $log_message = "[{$timestamp}] {$message}";

        if ($data !== null) {
            $log_message .= "\n" . print_r($data, true);
        }

        $log_message .= "\n\n";

        file_put_contents(self::$log_file, $log_message, FILE_APPEND);
    }

    /**
     * Clear the log file
     *
     * @return bool True on success, false on failure
     */
    public static function clear_log() {
        if (!self::$log_file) {
            self::init();
        }

        // Also remove any rotated backup
        $backup = self::$log_file . '.1';
        if (file_exists($backup)) {
            @unlink($backup);
        }

        // Verificar se o arquivo existe e se temos permissão para escrever nele
        if (!file_exists(self::$log_file)) {
            // Se o arquivo não existe, tentar criá-lo
            $result = @file_put_contents(self::$log_file, '');
            return ($result !== false);
        }

        // Tentar limpar o arquivo existente
        $result = @file_put_contents(self::$log_file, '');
        return ($result !== false);
    }
}
