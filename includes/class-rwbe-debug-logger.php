<?php
/**
 * Debug Logger for RWBE Product Importer
 *
 * Writes are buffered and flushed in batches. A full import emits hundreds of
 * thousands of log lines; one open/append/close per line (plus a filesize() stat
 * for the rotation check) dominated the import's CPU and I/O time. Nothing about
 * what gets logged has changed — only how often the file is touched.
 *
 * @since 1.0.0
 */

// Exit when accessed directly: these files only make sense inside WordPress.
if (!defined('WPINC')) {
    die;
}

class RWBE_Debug_Logger {

    /**
     * Log file path. Resolved lazily, on the first actual write.
     *
     * @var string|null
     */
    private static $log_file = null;

    /**
     * Whether the log directory and file have been prepared this request.
     *
     * @var bool
     */
    private static $prepared = false;

    /**
     * Whether logging is enabled (cached per request)
     *
     * @var bool|null
     */
    private static $enabled = null;

    /**
     * Pending log entries, written out in a single file operation.
     *
     * @var array
     */
    private static $buffer = array();

    /**
     * Bytes currently held in the buffer.
     *
     * @var int
     */
    private static $buffer_bytes = 0;

    /**
     * Whether the end-of-request flush has been registered.
     *
     * @var bool
     */
    private static $shutdown_hooked = false;

    /**
     * Maximum log file size before rotation (5 MB)
     */
    const MAX_LOG_SIZE = 5242880;

    /**
     * Flush once the buffer holds this many bytes.
     */
    const FLUSH_BYTES = 65536;

    /**
     * Flush once the buffer holds this many entries, so a stalled request never
     * sits on a large backlog of unwritten lines.
     */
    const FLUSH_ENTRIES = 200;

    /**
     * Initialize the logger.
     *
     * Deliberately does no work. The log path, the directory and the file are only
     * touched the first time something is actually written: this runs on every
     * single request (storefront included), and the previous version ran
     * wp_upload_dir() plus two file_exists() every time, even with logging off.
     */
    public static function init() {
        // Nothing to do — see prepare().
    }

    /**
     * Resolve the log path and make sure the directory and file exist.
     *
     * @return bool True when the log file can be written to.
     */
    private static function prepare() {
        if (self::$prepared) {
            return self::$log_file !== null;
        }
        self::$prepared = true;

        if (!function_exists('wp_upload_dir')) {
            return false;
        }

        $upload_dir = wp_upload_dir();
        if (empty($upload_dir['basedir'])) {
            return false;
        }

        $log_dir = $upload_dir['basedir'] . '/rwbe-logs';

        // Criar o diretório se não existir
        if (!file_exists($log_dir) && !wp_mkdir_p($log_dir)) {
            return false;
        }

        self::$log_file = $log_dir . '/debug.log';

        // Criar o arquivo se não existir
        if (!file_exists(self::$log_file)) {
            @file_put_contents(self::$log_file, '');
        }

        return true;
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
     * bounded to roughly 2 x MAX_LOG_SIZE. Called once per flush rather than once
     * per log line.
     *
     * @param int $incoming Bytes about to be appended.
     */
    private static function maybe_rotate($incoming = 0) {
        if (!self::$log_file || !file_exists(self::$log_file)) {
            return;
        }
        clearstatcache(true, self::$log_file);
        if ((filesize(self::$log_file) + $incoming) <= self::MAX_LOG_SIZE) {
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

        $timestamp = date('Y-m-d H:i:s');
        $log_message = "[{$timestamp}] {$message}";

        if ($data !== null) {
            $log_message .= "\n" . print_r($data, true);
        }

        $log_message .= "\n\n";

        self::$buffer[] = $log_message;
        self::$buffer_bytes += strlen($log_message);

        // Make sure whatever is still buffered reaches disk, including when the
        // request dies on a fatal error — that is exactly when the tail matters.
        if (!self::$shutdown_hooked) {
            self::$shutdown_hooked = true;
            register_shutdown_function(array(__CLASS__, 'flush'));
        }

        if (self::$buffer_bytes >= self::FLUSH_BYTES || count(self::$buffer) >= self::FLUSH_ENTRIES) {
            self::flush();
        }
    }

    /**
     * Write every buffered entry to disk in one append.
     */
    public static function flush() {
        if (empty(self::$buffer)) {
            return;
        }

        $chunk = implode('', self::$buffer);
        self::$buffer = array();
        self::$buffer_bytes = 0;

        if (!self::prepare()) {
            return;
        }

        // Keep disk usage bounded
        self::maybe_rotate(strlen($chunk));

        @file_put_contents(self::$log_file, $chunk, FILE_APPEND);
    }

    /**
     * Clear the log file
     *
     * @return bool True on success, false on failure
     */
    public static function clear_log() {
        // Anything still buffered belongs to the log the operator just cleared.
        self::$buffer = array();
        self::$buffer_bytes = 0;

        if (!self::prepare()) {
            return false;
        }

        // Also remove any rotated backup
        $backup = self::$log_file . '.1';
        if (file_exists($backup)) {
            @unlink($backup);
        }

        // Tentar limpar (ou criar) o ficheiro
        $result = @file_put_contents(self::$log_file, '');
        return ($result !== false);
    }
}
