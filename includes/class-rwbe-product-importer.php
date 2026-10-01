<?php
/**
 * Main class for RWBE Product Importer
 *
 * @since 1.0.0
 */
class RWBE_Product_Importer {

    /**
     * Buffer of product attribute definitions for the product currently being
     * processed. Filled by the set_product_* helpers and written to the
     * '_product_attributes' meta once per product (instead of once per attribute).
     *
     * @var array
     */
    private $pending_product_attributes = array();

    /**
     * Summary of what was associated to the product currently being processed
     * (brand, category, counts, images, price…). Surfaced in the live import log.
     *
     * @var array
     */
    private $last_product_summary = array();

    /**
     * Per-run in-memory cache of taxonomy term IDs, keyed by "taxonomy|name".
     * Brands, groups, makes, models and years repeat across many products; this
     * avoids a get_term_by() DB lookup for every product (matters most when no
     * persistent object cache is present).
     *
     * @var array
     */
    private $term_cache = array();

    /**
     * Per-run in-memory cache mapping a source image URL to its attachment ID, so
     * the same image referenced by multiple products is downloaded only once.
     *
     * @var array
     */
    private $image_source_cache = array();

    /**
     * Stock figures fetched from the /stock endpoint for the batch being processed,
     * keyed by API product id. Values are int or null ("asked, nothing usable").
     *
     * The cron path needs the live stock of every single product, and fetching it
     * one blocking request at a time was the dominant cost of a full sync: 35k
     * sequential round trips. prefetch_stock() fills this map for a whole batch in
     * parallel, and fetch_stock_for_product() reads it instead of going out again.
     *
     * @var array
     */
    private $stock_cache = array();

    /**
     * Resolve a taxonomy term by name, creating it if needed, with an in-memory
     * cache so repeated names across products don't hit the DB each time.
     *
     * @param string $name     Term name
     * @param string $taxonomy Taxonomy slug
     * @return int Term ID, or 0 on failure
     */
    private function get_or_create_term_id($name, $taxonomy) {
        $name = is_string($name) ? trim($name) : '';
        if ($name === '' || empty($taxonomy)) {
            return 0;
        }

        $key = $taxonomy . '|' . $name;
        if (isset($this->term_cache[$key])) {
            return $this->term_cache[$key];
        }

        $term_id = 0;
        $term = get_term_by('name', $name, $taxonomy);
        if ($term && !is_wp_error($term)) {
            $term_id = (int) $term->term_id;
        } else {
            $inserted = wp_insert_term($name, $taxonomy);
            if (!is_wp_error($inserted)) {
                $term_id = (int) $inserted['term_id'];
            } else {
                // term_exists race / already there: recover the existing ID
                $existing = term_exists($name, $taxonomy);
                if ($existing && !is_wp_error($existing)) {
                    $term_id = (int) (is_array($existing) ? $existing['term_id'] : $existing);
                } else {
                    RWBE_Debug_Logger::log('Failed to resolve/create term', [
                        'name' => $name,
                        'taxonomy' => $taxonomy,
                        'error' => $inserted->get_error_message()
                    ]);
                }
            }
        }

        if ($term_id) {
            $this->term_cache[$key] = $term_id;
        }
        return $term_id;
    }

    /**
     * Find an existing attachment previously imported from a given source URL,
     * across ALL products (global dedup), with an in-memory cache. Returns 0 if
     * the image has never been imported.
     *
     * @param string $url Source image URL
     * @return int Attachment ID, or 0
     */
    private function find_attachment_by_source_url($url) {
        if (empty($url)) {
            return 0;
        }
        if (isset($this->image_source_cache[$url])) {
            return $this->image_source_cache[$url];
        }

        global $wpdb;
        $attachment_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
                '_rwbe_source_url',
                $url
            )
        );

        // Confirm the attachment still exists (it may have been deleted)
        if ($attachment_id && get_post_type($attachment_id) === 'attachment') {
            $this->image_source_cache[$url] = $attachment_id;
            return $attachment_id;
        }
        return 0;
    }

    /**
     * Return the single shared attachment for the RWB placeholder image, creating
     * it once from the given downloaded temp file if it does not exist yet.
     *
     * Callers detect the placeholder by content hash (PLACEHOLDER_MD5) after
     * download and route it here instead of sideloading a new copy per product.
     * This method consumes $temp_file in all cases: media_handle_sideload() moves
     * it on success, and it is unlinked otherwise.
     *
     * The whole import already runs under the advisory lock, so only one process
     * can reach the create branch at a time — no risk of two canonical copies.
     *
     * @param string $temp_file Path to the downloaded placeholder file.
     * @param string $filename  Filename to store it under (should end in .png).
     * @return int Attachment ID, or 0 on failure.
     */
    private function get_or_create_placeholder_attachment($temp_file, $filename) {
        global $wpdb;

        $id = (int) get_option(self::PLACEHOLDER_OPTION);
        if ($id && get_post_type($id) === 'attachment') {
            // Canonical placeholder already exists — discard this duplicate download.
            @unlink($temp_file);
            return $id;
        }

        // The option can be lost (or wiped) while the attachment is still in the
        // library. Recover it by its content-hash meta instead of creating a
        // second canonical copy that the cleanup would never reconcile.
        $existing = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_rwbe_content_hash'
             WHERE p.post_type = 'attachment' AND m.meta_value = %s
             ORDER BY p.ID ASC LIMIT 1",
            self::PLACEHOLDER_MD5
        ));
        if ($existing) {
            update_option(self::PLACEHOLDER_OPTION, $existing, false);
            RWBE_Debug_Logger::log('Recovered canonical placeholder from content hash', ['attachment_id' => $existing]);
            @unlink($temp_file);
            return $existing;
        }

        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $file_array = array('name' => $filename, 'tmp_name' => $temp_file);
        $new_id = media_handle_sideload($file_array, 0, 'Race Winning Brands placeholder');
        if (is_wp_error($new_id)) {
            RWBE_Debug_Logger::log('Failed to create canonical placeholder attachment', [
                'error' => $new_id->get_error_message()
            ]);
            @unlink($temp_file);
            return 0;
        }

        update_post_meta($new_id, '_rwbe_placeholder', 1);
        update_post_meta($new_id, '_rwbe_content_hash', self::PLACEHOLDER_MD5);
        update_option(self::PLACEHOLDER_OPTION, $new_id, false);
        RWBE_Debug_Logger::log('Created canonical placeholder attachment', ['attachment_id' => $new_id]);
        return $new_id;
    }

    /**
     * Whether an attachment's file is byte-for-byte the RWB placeholder image.
     * Uses a cheap size pre-filter before hashing.
     *
     * @param int $attachment_id
     * @return bool
     */
    private function is_placeholder_attachment($attachment_id) {
        $file = get_attached_file((int) $attachment_id);
        if (!$file || !file_exists($file)) {
            return false;
        }
        if (filesize($file) !== self::PLACEHOLDER_SIZE) {
            return false;
        }
        return md5_file($file) === self::PLACEHOLDER_MD5;
    }

    /**
     * One batch of the bulk cleanup that de-duplicates the placeholder images
     * already imported (one physical copy per product). Designed to be called
     * repeatedly from an AJAX loop so it never hits a request timeout.
     *
     * Per batch it scans the next window of imported attachments (ordered by ID,
     * tracked with a persisted cursor), keeps the first placeholder it finds as
     * the single canonical copy, and for every other placeholder: repoints the
     * products that use it (featured image + galleries) to the canonical one and
     * then deletes the attachment and its files. The repointing is done once for
     * the whole batch, not once per attachment (see reassign_attachment_references).
     *
     * @param int  $batch_size      How many attachments to scan this call.
     * @param bool $reset           Start a fresh pass (rewind the cursor to 0).
     * @param bool $count_remaining Also count what is left. That is a full scan of the
     *                              candidate set (~1.7s on a 195k-attachment library)
     *                              and feeds nothing but the progress bar, so the
     *                              worker asks for it once per pass and derives the
     *                              rest. 'remaining' is null when not counted.
     * @return array {processed, deleted, remaining, done, canonical, error?}
     */
    public function cleanup_placeholder_duplicates($batch_size = 300, $reset = false, $count_remaining = true) {
        global $wpdb;

        if ($reset) {
            update_option('rwbe_ph_cleanup_cursor', 0, false);
        }
        $cursor = (int) get_option('rwbe_ph_cleanup_cursor');

        // Everything gets repointed at the canonical copy and the originals are
        // then deleted, so a wrong value here is unrecoverable. If the option
        // points at something that is no longer the placeholder, stop: earlier
        // batches already repointed products at it, and promoting a second copy
        // now would silently leave those products with a broken image.
        $canonical = (int) get_option(self::PLACEHOLDER_OPTION);
        if ($canonical && (get_post_type($canonical) !== 'attachment' || !$this->is_placeholder_attachment($canonical))) {
            RWBE_Debug_Logger::log('Aborting placeholder cleanup: canonical attachment failed verification', [
                'attachment_id' => $canonical
            ]);
            return array(
                'processed' => 0,
                'deleted'   => 0,
                'remaining' => 0,
                'done'      => true,
                'canonical' => $canonical,
                'error'     => sprintf(
                    /* translators: %d: attachment ID */
                    __('A imagem placeholder partilhada (anexo #%d) não existe ou já não corresponde ao placeholder. Limpeza cancelada para não reapontar produtos para a imagem errada.', 'rwbe-product-importer'),
                    $canonical
                ),
            );
        }

        // Next window of candidate attachments.
        $ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT p.ID " . $this->cleanup_candidate_sql() . "
             ORDER BY p.ID ASC LIMIT %d",
            $cursor, $this->placeholder_metadata_needle(), $batch_size
        )));

        // The window comes back as bare IDs from raw SQL, so nothing is in cache yet.
        // Without this, get_attached_file() below and wp_delete_attachment() further
        // down each trigger their own post + meta lookup, i.e. hundreds of queries per
        // batch; priming them costs two.
        if (!empty($ids)) {
            _prime_post_caches($ids, false, true);
        }

        $processed = 0;
        $last = $cursor;
        $duplicates = array();

        foreach ($ids as $aid) {
            $aid = (int) $aid;
            $last = $aid;
            $processed++;

            if ($aid === $canonical || !$this->is_placeholder_attachment($aid)) {
                continue;
            }

            // Promote the first placeholder found as the single canonical copy.
            if (!$canonical) {
                $canonical = $aid;
                update_post_meta($aid, '_rwbe_placeholder', 1);
                update_post_meta($aid, '_rwbe_content_hash', self::PLACEHOLDER_MD5);
                update_option(self::PLACEHOLDER_OPTION, $aid, false);
                RWBE_Debug_Logger::log('Promoted existing attachment to canonical placeholder', ['attachment_id' => $aid]);
                continue;
            }

            $duplicates[] = $aid;
        }

        $deleted = 0;
        if (!empty($duplicates)) {
            // Deleting before knowing the repoint worked is how products end up with
            // a _thumbnail_id or a gallery entry pointing at an attachment that no
            // longer exists. The repoint now reports failure (a REGEXP that MySQL
            // refuses under regexp_time_limit, a lost connection mid-UPDATE) and the
            // batch stops with the duplicates still on disk, which is recoverable;
            // broken references are not.
            if (!$this->reassign_attachment_references($duplicates, $canonical)) {
                RWBE_Debug_Logger::log('Aborting placeholder cleanup batch: repoint failed, nothing deleted', [
                    'duplicates' => count($duplicates),
                    'canonical'  => $canonical,
                ]);
                return array(
                    'processed' => $processed,
                    'deleted'   => 0,
                    'remaining' => null,
                    'done'      => true,
                    'canonical' => $canonical,
                    'error'     => __('Não foi possível reapontar os produtos para a imagem partilhada, por isso nada foi eliminado. Nenhum produto ficou com imagem em falta. Verifique o debug log e tente novamente.', 'rwbe-product-importer'),
                );
            }

            foreach ($duplicates as $aid) {
                wp_delete_attachment($aid, true); // true => also delete files from disk
                $deleted++;
            }
        }

        // Only advance the cursor once the batch has actually finished its deletions,
        // so an abort above re-examines the same window instead of skipping past it.
        update_option('rwbe_ph_cleanup_cursor', $last, false);

        return array(
            'processed' => $processed,
            'deleted'   => $deleted,
            // Counting what is left is a ~1.7s scan of the whole candidate set. It is
            // only ever shown on a progress bar, so the caller asks for it once per
            // pass and derives the rest from total - scanned.
            'remaining' => $count_remaining ? $this->count_cleanup_candidates($last) : null,
            'done'      => (count($ids) < $batch_size),
            'canonical' => $canonical,
        );
    }

    /**
     * Shared FROM/WHERE for the attachments a cleanup pass has to look at.
     *
     * Expects three prepare() arguments, in this order: the cursor (%d), the
     * metadata needle (%s) and — when the caller adds a LIMIT — the batch size.
     *
     * Imported attachments all carry _rwbe_source_url. On top of that, WordPress
     * records each file's byte size inside _wp_attachment_metadata, so the
     * placeholder's known size excludes the images that cannot possibly be it. On the
     * live catalogue that narrows 185k attachments to the 73k that are real
     * candidates — the other 112k were being opened and hashed from disk for nothing.
     *
     * is_placeholder_attachment() still has the final say, so this is a pre-filter and
     * nothing more: the worst it can do is leave a duplicate behind, which another
     * pass picks up. It can never cause the wrong image to be deleted. Attachments
     * whose metadata is missing, or carries no filesize at all, stay in the candidate
     * set rather than being assumed innocent.
     *
     * @return string
     */
    private function cleanup_candidate_sql() {
        global $wpdb;

        // %% because prepare() scans this string for placeholders.
        return "FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_rwbe_source_url'
                LEFT JOIN {$wpdb->postmeta} md ON md.post_id = p.ID AND md.meta_key = '_wp_attachment_metadata'
                WHERE p.post_type = 'attachment'
                  AND p.ID > %d
                  AND (
                        md.meta_value IS NULL
                     OR md.meta_value NOT LIKE '%%\"filesize\";i:%%'
                     OR md.meta_value LIKE %s
                  )";
    }

    /**
     * LIKE needle matching the placeholder's byte size inside the serialised
     * _wp_attachment_metadata blob.
     *
     * @return string
     */
    private function placeholder_metadata_needle() {
        return '%s:8:"filesize";i:' . (int) self::PLACEHOLDER_SIZE . ';%';
    }

    /**
     * How many candidate attachments are still above a cursor.
     *
     * @param int $cursor
     * @return int
     */
    private function count_cleanup_candidates($cursor) {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID) " . $this->cleanup_candidate_sql(),
            (int) $cursor,
            $this->placeholder_metadata_needle()
        ));
    }

    /**
     * Current progress of the placeholder cleanup, normalised with defaults.
     *
     * The state lives in an option (not in the browser) so a run is owned by the
     * server: switching window or closing the tab does not interrupt it, and the
     * admin page can render the progress bar again on the next page load.
     *
     * @return array
     */
    public function get_placeholder_cleanup_state() {
        $state = get_option(self::CLEANUP_STATE_OPTION, array());
        if (!is_array($state)) {
            $state = array();
        }

        $defaults = array(
            'running'   => false,
            'scanned'   => 0,
            'deleted'   => 0,
            'total'     => 0,
            'remaining' => 0,
            'done'      => false,
            'stopped'   => false,
            'error'     => '',
            'updated'   => 0,
        );
        $state = array_merge($defaults, $state);

        $state['running']   = !empty($state['running']);
        $state['done']      = !empty($state['done']);
        $state['stopped']   = !empty($state['stopped']);
        $state['scanned']   = (int) $state['scanned'];
        $state['deleted']   = (int) $state['deleted'];
        $state['total']     = (int) $state['total'];
        $state['remaining'] = (int) $state['remaining'];
        $state['updated']   = (int) $state['updated'];
        $state['error']     = (string) $state['error'];
        $state['cursor']    = (int) get_option('rwbe_ph_cleanup_cursor');
        $state['canonical'] = (int) get_option(self::PLACEHOLDER_OPTION);

        return $state;
    }

    /**
     * Persist the cleanup progress, stamping the time so the admin page can tell
     * a live run from one whose worker died.
     *
     * @param array $state
     * @return array The state as saved.
     */
    private function save_placeholder_cleanup_state($state) {
        $state['updated'] = time();
        update_option(self::CLEANUP_STATE_OPTION, $state, false);
        return $state;
    }

    /**
     * Whether the operator asked the cleanup to stop.
     *
     * Read straight from the DB: the worker loop can run for many seconds inside a
     * single request, during which get_option() would keep returning the value
     * cached when the request started and never see a concurrent stop.
     *
     * @return bool
     */
    private function cleanup_stop_requested() {
        global $wpdb;
        $val = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            self::CLEANUP_STOP_OPTION
        ));
        return !empty($val) && $val !== '0';
    }

    /**
     * Whether an import is genuinely running right now.
     *
     * The cleanup deletes attachments the import may be writing at that very
     * moment, so no worker may touch them while an import is active. Mirrors the
     * admin status logic: paused/interrupted states do not count as running,
     * and a progress option older than 5 minutes is treated as stale.
     *
     * Read straight from the DB so a long-lived worker request sees a status
     * written after its own request started.
     *
     * @return bool
     */
    private function import_is_running() {
        global $wpdb;

        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            'rwbe_import_progress'
        ));
        if (empty($raw)) {
            return false;
        }

        $progress = maybe_unserialize($raw);
        if (!is_array($progress)) {
            return false;
        }

        $active = array('starting', 'in_progress', 'processing_products', 'retrying_connection', 'resuming');
        $status = isset($progress['status']) ? $progress['status'] : '';
        $stamp  = isset($progress['timestamp']) ? (int) $progress['timestamp'] : 0;

        return (time() - $stamp) < 300 && in_array($status, $active, true);
    }

    /**
     * Make sure a cleanup worker is queued.
     *
     * @param int $delay Seconds from now.
     */
    private function schedule_placeholder_cleanup_cron($delay = 5) {
        if (!wp_next_scheduled('rwbe_ph_cleanup_cron')) {
            wp_schedule_single_event(time() + max(0, (int) $delay), 'rwbe_ph_cleanup_cron');
        }
    }

    /**
     * Begin a cleanup run: zero the counters, clear any stale stop request and
     * queue the first worker. It does no work itself — the caller (admin page or
     * cron) drives the batches, so a slow first batch never blocks the click.
     *
     * @param bool $reset Rewind the scan cursor and start a fresh full pass.
     * @return array The new state.
     */
    public function start_placeholder_cleanup($reset = false) {
        delete_option(self::CLEANUP_STOP_OPTION);

        if ($reset) {
            update_option('rwbe_ph_cleanup_cursor', 0, false);
        }

        RWBE_Debug_Logger::log('Placeholder cleanup started', [
            'reset'  => (bool) $reset,
            'cursor' => (int) get_option('rwbe_ph_cleanup_cursor')
        ]);

        $state = $this->save_placeholder_cleanup_state(array(
            'running'   => true,
            'scanned'   => 0,
            'deleted'   => 0,
            'total'     => 0,
            'remaining' => 0,
            'done'      => false,
            'stopped'   => false,
            'error'     => '',
        ));

        $this->schedule_placeholder_cleanup_cron(0);
        return $this->get_placeholder_cleanup_state();
    }

    /**
     * Ask the running cleanup to stop after the current batch.
     *
     * @return array The state after the request.
     */
    public function stop_placeholder_cleanup() {
        update_option(self::CLEANUP_STOP_OPTION, 1, false);
        wp_clear_scheduled_hook('rwbe_ph_cleanup_cron');
        RWBE_Debug_Logger::log('Placeholder cleanup stop requested');

        // If no worker holds the lock there is nobody left to notice the flag, so
        // close the run here instead of leaving it "running" forever.
        if ($this->acquire_lock(self::CLEANUP_LOCK_NAME)) {
            $state = $this->get_placeholder_cleanup_state();
            $state['running'] = false;
            $state['stopped'] = true;
            $this->save_placeholder_cleanup_state($state);
            $this->release_lock(self::CLEANUP_LOCK_NAME);
            delete_option(self::CLEANUP_STOP_OPTION);
        }

        return $this->get_placeholder_cleanup_state();
    }

    /**
     * Run cleanup batches for up to $max_seconds, then hand over.
     *
     * This is the single worker entry point, used both by the admin page (so the
     * operator sees the bar move while the page is open) and by cron (so the run
     * carries on when it is not). The advisory lock keeps them from processing the
     * same window twice; whoever loses simply reports the current progress.
     *
     * While work remains it re-queues itself, so the run keeps going without the
     * browser. Progress is persisted after every batch.
     *
     * @param int $max_seconds Wall-clock budget for this call.
     * @param int $batch_size  Attachments scanned per batch.
     * @return array The state after this call.
     */
    public function run_placeholder_cleanup_batches($max_seconds = 20, $batch_size = 300) {
        $state = $this->get_placeholder_cleanup_state();
        if (empty($state['running'])) {
            return $state;
        }

        // An import writes the very attachments this deletes: stand down and try
        // again later instead of racing it. The run stays 'running', so the bar
        // keeps showing and the watchdog keeps the worker queued.
        if ($this->import_is_running()) {
            RWBE_Debug_Logger::log('Placeholder cleanup paused: import in progress');
            $this->schedule_placeholder_cleanup_cron(60);
            return $state;
        }

        if (!$this->acquire_lock(self::CLEANUP_LOCK_NAME)) {
            // Another worker is already on it — report progress, do not duplicate work.
            return $state;
        }

        @set_time_limit(0);
        $started = time();
        $yielded = false;

        try {
            do {
                if ($this->import_is_running()) {
                    RWBE_Debug_Logger::log('Placeholder cleanup yielding: import started');
                    $yielded = true;
                    break;
                }

                if ($this->cleanup_stop_requested()) {
                    $state['running'] = false;
                    $state['stopped'] = true;
                    RWBE_Debug_Logger::log('Placeholder cleanup stopped by user', [
                        'scanned' => $state['scanned'], 'deleted' => $state['deleted']
                    ]);
                    break;
                }

                // Counting what is left is a full scan of the candidate set, so it is
                // asked for only on the batch that needs it to fix the pass total.
                $need_total = ($state['total'] <= 0);
                $result = $this->cleanup_placeholder_duplicates($batch_size, false, $need_total);

                if (!empty($result['error'])) {
                    $state['running'] = false;
                    $state['error'] = $result['error'];
                    break;
                }

                $state['scanned'] += (int) $result['processed'];
                $state['deleted'] += (int) $result['deleted'];

                if ($need_total) {
                    // Fixed on the first batch of this pass: everything still to scan.
                    $state['total']     = $state['scanned'] + (int) $result['remaining'];
                    $state['remaining'] = (int) $result['remaining'];
                } else {
                    // Derived, not re-counted. The cursor only moves forward, so this
                    // tracks the real figure without a 1.7s query per batch.
                    $state['remaining'] = max(0, $state['total'] - $state['scanned']);
                }

                if (!empty($result['done'])) {
                    $state['running']   = false;
                    $state['done']      = true;
                    $state['remaining'] = 0;
                    RWBE_Debug_Logger::log('Placeholder cleanup finished', [
                        'scanned' => $state['scanned'], 'deleted' => $state['deleted']
                    ]);
                    break;
                }

                $state = $this->save_placeholder_cleanup_state($state);
            } while ((time() - $started) < $max_seconds);
        } catch (\Throwable $e) {
            // \Throwable, not Exception: wp_delete_attachment() runs third-party
            // delete_post hooks, and a TypeError from one of those is an \Error. Caught
            // as Exception only, it escaped — leaving the run flagged as running with
            // its progress unsaved, so the watchdog re-queued the very same batch for
            // ever. The cursor is advanced inside each batch, so the retry resumes
            // instead of repeating.
            $state['running'] = false;
            $state['error'] = $e->getMessage();
            RWBE_Debug_Logger::log('Placeholder cleanup worker crashed', [
                'error' => $e->getMessage(),
                'type'  => get_class($e),
            ]);
        } finally {
            // Must happen even on the way out through a Throwable, or the next worker
            // finds the lock held and the progress bar frozen on a stale snapshot.
            $this->release_lock(self::CLEANUP_LOCK_NAME);
            $this->save_placeholder_cleanup_state($state);
        }

        if (!empty($state['running'])) {
            // Back off while an import holds the attachments; otherwise pick the
            // next batch up straight away.
            $this->schedule_placeholder_cleanup_cron($yielded ? 60 : 5);
        } else {
            wp_clear_scheduled_hook('rwbe_ph_cleanup_cron');
            delete_option(self::CLEANUP_STOP_OPTION);
        }

        return $this->get_placeholder_cleanup_state();
    }

    /**
     * Watchdog: re-queue a cleanup that is flagged as running but has no worker
     * scheduled (the driving request was aborted, or the process died mid-batch).
     */
    public function maybe_resume_placeholder_cleanup() {
        $state = $this->get_placeholder_cleanup_state();
        if (empty($state['running']) || wp_next_scheduled('rwbe_ph_cleanup_cron')) {
            return;
        }

        RWBE_Debug_Logger::log('Resuming interrupted placeholder cleanup', [
            'cursor'  => $state['cursor'],
            'scanned' => $state['scanned'],
            'deleted' => $state['deleted']
        ]);
        $this->schedule_placeholder_cleanup_cron(0);
    }

    /**
     * Repoint every product reference from a set of attachments to another one,
     * before those attachments are deleted: the featured image (_thumbnail_id)
     * and any product gallery (_product_image_gallery, a comma-separated ID list,
     * kept de-duplicated so the target never appears twice).
     *
     * Both meta_value lookups are unindexed table scans, so the whole batch is
     * resolved with one query per meta key instead of one query per attachment
     * (~36s down to ~0.5s per 300-attachment batch on a ~190k-attachment library).
     *
     * @param int[] $from_ids Attachments being removed.
     * @param int   $to_id    Attachment to point references at.
     * @return bool True when every reference was repointed. False means the caller
     *              must NOT delete the attachments: something in the references is
     *              still pointing at them.
     */
    private function reassign_attachment_references($from_ids, $to_id) {
        global $wpdb;

        $from_ids = array_values(array_unique(array_filter(array_map('intval', (array) $from_ids))));
        $to_id = (int) $to_id;
        if (empty($from_ids) || !$to_id) {
            return false;
        }

        $in = implode(',', $from_ids); // ints only, safe to interpolate
        $touched = array();

        // Featured images. The posts are collected before the UPDATE because raw
        // SQL bypasses the meta cache and it has to be invalidated by hand.
        $thumb_posts = $wpdb->get_col(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_thumbnail_id' AND meta_value IN ({$in})"
        );
        if ($wpdb->last_error) {
            RWBE_Debug_Logger::log('Repoint failed reading featured images', ['error' => $wpdb->last_error]);
            return false;
        }
        if (!empty($thumb_posts)) {
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->postmeta} SET meta_value = %s
                 WHERE meta_key = '_thumbnail_id' AND meta_value IN ({$in})",
                (string) $to_id
            ));
            if ($updated === false) {
                RWBE_Debug_Logger::log('Repoint failed updating featured images', ['error' => $wpdb->last_error]);
                return false;
            }
            $touched = array_merge($touched, $thumb_posts);
        }

        // Galleries: one regex over the whole batch instead of a FIND_IN_SET per ID.
        // Stray whitespace around an ID is tolerated so those rows are not skipped.
        $pattern = '(^|,)[[:space:]]*(' . implode('|', $from_ids) . ')[[:space:]]*(,|$)';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_product_image_gallery' AND meta_value REGEXP %s",
            $pattern
        ));
        // MySQL rejects a pattern that exceeds regexp_time_limit (default 32) or
        // regexp_stack_limit. get_results() then returns an empty array, which is
        // indistinguishable from "no gallery uses these" — and the caller would go on
        // to delete attachments the galleries still reference. Check explicitly.
        if ($wpdb->last_error) {
            RWBE_Debug_Logger::log('Repoint failed scanning galleries', [
                'error' => $wpdb->last_error,
                'ids'   => count($from_ids),
            ]);
            return false;
        }

        $replace = array_fill_keys($from_ids, $to_id);
        foreach ($rows as $row) {
            $ids = array_filter(array_map('intval', explode(',', $row->meta_value)));
            $out = array();
            foreach ($ids as $gid) {
                if (isset($replace[$gid])) {
                    $gid = $replace[$gid];
                }
                if (!in_array($gid, $out, true)) {
                    $out[] = $gid;
                }
            }
            update_post_meta($row->post_id, '_product_image_gallery', implode(',', $out));
            $touched[] = $row->post_id;
        }

        // update_post_meta() clears the meta cache, the raw UPDATE above does not,
        // and WooCommerce keeps its own product cache on top of it. Flush both so
        // the repoint is visible on sites with a persistent object cache.
        foreach (array_unique(array_map('intval', $touched)) as $post_id) {
            clean_post_cache($post_id);
            if (function_exists('wc_delete_product_transients')) {
                wc_delete_product_transients($post_id);
            }
        }

        return true;
    }

    /**
     * Build a compact summary of the elements associated with a product, for the
     * live import log (so the operator sees brand/category/models/images/price).
     *
     * @param array $product_data
     * @param array $application_data Output of extract_application_data()
     * @return array
     */
    private function build_product_summary($product_data, $application_data = array()) {
        $regular = isset($product_data['retailerPrice']) ? floatval($product_data['retailerPrice']) : 0;
        $promo   = isset($product_data['grossPromoPricing']) ? floatval($product_data['grossPromoPricing']) : 0;
        $gallery = (!empty($product_data['gallery']) && is_array($product_data['gallery'])) ? count($product_data['gallery']) : 0;

        return array(
            'brand'    => $this->extract_brand_name($product_data),
            'category' => isset($product_data['segment']) ? sanitize_text_field($product_data['segment']) : '',
            'group'    => $this->extract_product_group($product_data),
            'makes'    => isset($application_data['makes']) ? count($application_data['makes']) : 0,
            'models'   => isset($application_data['models']) ? count($application_data['models']) : 0,
            'years'    => isset($application_data['years']) ? count($application_data['years']) : 0,
            'gallery'  => $gallery,
            'image'    => !empty($product_data['photo']),
            'price'    => $regular,
            'sale'     => ($promo > 0 && $regular > 0 && $promo < $regular) ? $promo : null,
            'stock'    => isset($product_data['stock']) ? intval($product_data['stock']) : null,
        );
    }

    /**
     * Queue a product attribute definition to be written on flush.
     *
     * @param string $attribute_name e.g. 'pa_brands' or a custom attribute name
     * @param int    $position
     * @param bool   $is_taxonomy
     * @param string $value          Used only for non-taxonomy (custom) attributes
     */
    private function queue_product_attribute($attribute_name, $position, $is_taxonomy = true, $value = '') {
        $this->pending_product_attributes[$attribute_name] = array(
            'name'         => $attribute_name,
            'value'        => $value,
            'position'     => $position,
            'is_visible'   => 1,
            'is_variation' => 0,
            'is_taxonomy'  => $is_taxonomy ? 1 : 0,
        );
    }

    /**
     * Write all queued product attributes to the product in a single meta update,
     * merging with any existing attributes, then clear the buffer.
     *
     * @param int $product_id
     */
    private function flush_product_attributes($product_id) {
        if (empty($this->pending_product_attributes)) {
            return;
        }
        $existing = get_post_meta($product_id, '_product_attributes', true);
        if (!is_array($existing)) {
            $existing = array();
        }
        $merged = array_merge($existing, $this->pending_product_attributes);
        update_post_meta($product_id, '_product_attributes', $merged);
        $this->pending_product_attributes = array();
    }

    /**
     * Initialize the class
     */
    public function init() {
        // Add hook for the scheduled cron job
        add_action('rwbe_product_import_cron', array($this, 'import_products_cron'));
        
        // Adicionar hook para verificação frequente de importações interrompidas
        add_action('rwbe_check_interrupted_imports', array($this, 'check_and_resume_interrupted_imports'));

        // Worker da limpeza de placeholders. Registado fora do admin de propósito:
        // é o que permite que a limpeza continue a correr depois de o operador
        // fechar o separador (ou mudar de janela) — o browser deixa de ser
        // necessário para o trabalho avançar.
        add_action('rwbe_ph_cleanup_cron', array($this, 'run_placeholder_cleanup_batches'));

        // Watchdog: se uma limpeza ficou marcada como a correr mas perdeu o evento
        // agendado (pedido abortado, crash), volta a agendá-la.
        add_action('rwbe_check_interrupted_imports', array($this, 'maybe_resume_placeholder_cleanup'));

        // Backfill do fitment (marca+modelo+anos) para produtos importados antes da
        // tabela existir. Corre em lotes, um por minuto, e só quando iniciado.
        add_action('rwbe_fitment_backfill', array($this, 'run_fitment_backfill'));

        // IMPORTANTE: registar o intervalo personalizado SEMPRE (não só no admin).
        // O WP-Cron corre em contexto não-admin (wp-cron.php/loopback); se o
        // intervalo 'rwbe_two_minutes' só estiver registado no admin, o WordPress
        // não consegue reagendar o evento recorrente fora do admin e o watchdog
        // de retoma deixa silenciosamente de correr até alguém abrir o wp-admin.
        add_filter('cron_schedules', array($this, 'add_cron_interval'));

        // Registar o evento cron de verificação frequente (também fora do admin,
        // para que o watchdog exista mesmo sem tráfego no backoffice).
        // Auto-cura para instalações existentes: se o evento estiver agendado com
        // uma recorrência diferente de 'rwbe_two_minutes' (ex.: o antigo e inexistente
        // 'rwbe_five_minutes', que impedia o reagendamento), limpa e reagenda.
        $existing_schedule = wp_get_schedule('rwbe_check_interrupted_imports');
        if ($existing_schedule === false) {
            wp_schedule_event(time(), 'rwbe_two_minutes', 'rwbe_check_interrupted_imports');
        } elseif ($existing_schedule !== 'rwbe_two_minutes') {
            wp_clear_scheduled_hook('rwbe_check_interrupted_imports');
            wp_schedule_event(time(), 'rwbe_two_minutes', 'rwbe_check_interrupted_imports');
            RWBE_Debug_Logger::log('Rescheduled rwbe_check_interrupted_imports to rwbe_two_minutes', [
                'previous_schedule' => $existing_schedule
            ]);
        }

        // Garantir que todos os atributos necessários existam - apenas em admin
        if (is_admin()) {
            add_action('admin_init', array($this, 'ensure_all_attributes_exist'), 20);

            // Sincronizar taxonomias (marcas e grupos) periodicamente no admin, com cache por transient
            add_action('admin_init', array($this, 'maybe_sync_taxonomies'), 40);
        }
    }

    /**
     * Generic GET helper with authorization header
     * @param string $url
     * @param int $timeout
     * @return array|WP_Error
     */
    private function api_get($url, $timeout = 60) {
        $token = rwbe_get_api_token();
        if ($token === '') {
            RWBE_Debug_Logger::log('API request aborted: no API token configured', ['url' => $url]);
            return new WP_Error('rwbe_missing_token', __('Nenhum API Token configurado. Introduza o token nas Configurações do plugin.', 'rwbe-product-importer'));
        }

        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
                'Cache-Control' => 'no-cache',
            ),
            'timeout' => $timeout,
            'httpversion' => '1.1',
            'sslverify' => true,
            'redirection' => 3,
        );
        $url = add_query_arg('_nocache', self::cache_buster(), $url);
        return wp_remote_get($url, $args);
    }

    /**
     * Cache-busting token for API URLs.
     *
     * Still unique per run — a new PHP request gets a new token, so a sync never
     * sees data cached from an earlier one. What changed is that it is no longer
     * unique per *call*: a full import issues ~70k requests, and giving each one its
     * own URL made every response uncacheable anywhere along the path and filled the
     * supplier's caches with single-use entries. A no-cache request header carries
     * the same intent properly.
     *
     * @return string
     */
    private static function cache_buster() {
        static $token = null;
        if ($token === null) {
            $token = uniqid('', true);
        }
        return $token;
    }

    /**
     * Maybe sync brands and groups taxonomies with RWBE endpoints (admin-only, cached)
     */
    public function maybe_sync_taxonomies() {
        if (get_transient('rwbe_tax_sync_recent')) {
            return;
        }
        try {
            $this->sync_all_brands();
            $this->sync_all_groups();
        } catch (Exception $e) {
            RWBE_Debug_Logger::log('Taxonomies sync error', ['error' => $e->getMessage()]);
        }
        set_transient('rwbe_tax_sync_recent', 1, 12 * HOUR_IN_SECONDS);
    }

    /**
     * Fetch current stock for product via /stock endpoint
     * @param string $api_product_id
     * @return int|null
     */
    private function fetch_stock_for_product($api_product_id) {
        if (empty($api_product_id)) return null;

        // Served by prefetch_stock() when the batch has already been fetched in
        // parallel. array_key_exists, not isset: a cached null means "asked, no
        // usable answer" and must not trigger a second request.
        $cache_key = (string) $api_product_id;
        if (array_key_exists($cache_key, $this->stock_cache)) {
            return $this->stock_cache[$cache_key];
        }

        $url = $this->stock_url_for($api_product_id);
        $response = $this->api_get($url, 45);
        if (is_wp_error($response)) {
            RWBE_Debug_Logger::log('Stock endpoint error', ['product_id' => $api_product_id, 'error' => $response->get_error_message()]);
            return null;
        }
        if (wp_remote_retrieve_response_code($response) !== 200) return null;
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($body['stock'])) return intval($body['stock']);
        return null;
    }

    /**
     * Write a post meta value only when it differs from what is already stored.
     *
     * update_post_meta() does short-circuit an identical value, but only on a strict
     * comparison against the raw string the database handed back. The importer passes
     * ints and floats (stock, prices), so "5" === 5 was always false and every single
     * product reported itself as changed on every run — which is what made the
     * twice-daily sync re-save the whole catalogue. Comparing as strings answers the
     * question that actually matters: is the stored value already this one?
     *
     * @param int    $product_id Product ID
     * @param string $key        Meta key
     * @param mixed  $value      Value to store
     * @return bool True when the stored value was changed (or created).
     */
    private function update_meta_if_changed($product_id, $key, $value) {
        if (is_scalar($value) || $value === null) {
            if (metadata_exists('post', $product_id, $key)) {
                $current = get_post_meta($product_id, $key, true);
                if (is_scalar($current) && (string) $current === (string) $value) {
                    return false;
                }
            }
            update_post_meta($product_id, $key, $value);
            return true;
        }

        // Arrays/objects: let WordPress do the (serialized) comparison.
        return (bool) update_post_meta($product_id, $key, $value);
    }

    /**
     * Collect the API product ids present in a batch of API payloads.
     *
     * @param array $products Products as returned by fetch_products_from_api().
     * @return array List of ids (products without one are skipped).
     */
    private function collect_api_ids($products) {
        $ids = array();
        foreach ((array) $products as $product) {
            if (is_array($product) && !empty($product['id'])) {
                $ids[] = $product['id'];
            }
        }
        return $ids;
    }

    /**
     * Build the /stock endpoint URL for one API product id.
     *
     * @param string $api_product_id
     * @return string
     */
    private function stock_url_for($api_product_id) {
        $base = defined('RWBE_API_ENDPOINT_STOCK') ? RWBE_API_ENDPOINT_STOCK : 'https://portal.racewinningbrandseurope.com/apiv2/stock';
        return add_query_arg(array('product_id' => $api_product_id), trailingslashit($base));
    }

    /**
     * Fetch the live stock of a whole batch of products concurrently.
     *
     * Same endpoint, same parsing and the same "null means unusable" outcome as
     * fetch_stock_for_product() — only the transport differs: Requests does the
     * round trips in parallel instead of one after another. Products whose request
     * fails are deliberately left out of the cache, so the caller falls back to a
     * single retry through fetch_stock_for_product() exactly as before.
     *
     * @param array $api_product_ids API product ids.
     * @param int   $concurrency     Requests in flight per round.
     * @return void
     */
    private function prefetch_stock($api_product_ids, $concurrency = 10) {
        // Each batch stands on its own; dropping the previous one keeps the map
        // small on a 35k-product run.
        $this->stock_cache = array();

        $ids = array();
        foreach ((array) $api_product_ids as $id) {
            if ($id === null || $id === '') {
                continue;
            }
            $ids[(string) $id] = true;
        }
        $ids = array_keys($ids);
        if (empty($ids)) {
            return;
        }

        $token = rwbe_get_api_token();
        if ($token === '') {
            RWBE_Debug_Logger::log('Stock prefetch aborted: no API token configured');
            return;
        }

        $headers = array(
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
            'Cache-Control' => 'no-cache',
        );

        $fetched = 0;
        foreach (array_chunk($ids, max(1, (int) $concurrency)) as $chunk) {
            $requests = array();
            foreach ($chunk as $id) {
                $requests[$id] = array(
                    'url' => $this->stock_url_for($id),
                    'headers' => $headers,
                    'type' => 'GET',
                    'options' => array('timeout' => 45),
                );
            }

            $responses = $this->request_multiple_compat($requests, 45);

            foreach ($chunk as $id) {
                $resp = isset($responses[$id]) ? $responses[$id] : null;
                $code = (is_object($resp) && isset($resp->status_code)) ? intval($resp->status_code) : 0;
                $body = (is_object($resp) && isset($resp->body)) ? $resp->body : '';

                if ($code !== 200 || $body === '') {
                    // Leave it uncached: the per-product call retries it once.
                    continue;
                }

                $decoded = json_decode($body, true);
                $this->stock_cache[(string) $id] = (is_array($decoded) && isset($decoded['stock']))
                    ? intval($decoded['stock'])
                    : null;
                $fetched++;
            }
        }

        RWBE_Debug_Logger::log('Stock prefetched in parallel', [
            'requested' => count($ids),
            'resolved' => $fetched,
        ]);
    }

    /**
     * Sync all groups from /groups endpoint into pa_grupo taxonomy
     */
    private function sync_all_groups() {
        $base = defined('RWBE_API_ENDPOINT_GROUPS') ? RWBE_API_ENDPOINT_GROUPS : 'https://portal.racewinningbrandseurope.com/apiv2/groups';
        $response = $this->api_get(trailingslashit($base), 60);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return;
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) return;

        RWBE_Generic_Attribute_Helper::ensure_attribute_exists('grupo', 'Grupo');
        foreach ($data as $group) {
            $title = is_array($group) ? ($group['title'] ?? '') : '';
            if (empty($title)) continue;
            $term = get_term_by('name', $title, 'pa_grupo');
            if (!$term) {
                wp_insert_term($title, 'pa_grupo');
            }
        }
    }

    /**
     * Sync all brands from /brands endpoint into pa_brands taxonomy
     */
    private function sync_all_brands() {
        $base = defined('RWBE_API_ENDPOINT_BRANDS') ? RWBE_API_ENDPOINT_BRANDS : 'https://portal.racewinningbrandseurope.com/apiv2/brands';
        $response = $this->api_get(trailingslashit($base), 60);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return;
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) return;

        RWBE_Generic_Attribute_Helper::ensure_attribute_exists('brands', 'Brands');
        foreach ($data as $brand) {
            $title = is_array($brand) ? ($brand['title'] ?? '') : '';
            if (empty($title)) continue;
            $term = get_term_by('name', $title, 'pa_brands');
            if (!$term) {
                wp_insert_term($title, 'pa_brands');
            }

            // Also ensure the public taxonomy term exists for themes: product_brand
            if (taxonomy_exists('product_brand')) {
                $public_term = get_term_by('name', $title, 'product_brand');
                if (!$public_term) {
                    wp_insert_term($title, 'product_brand');
                }
            }
        }
    }

    /**
     * Fetch brand detail and store logo URL in brand term meta
     *
     * @param array $brand_info Array with at least id and/or logo
     * @param string $brand_name The brand name (term lookup)
     */
    private function maybe_update_brand_logo($brand_info, $brand_name) {
        if (empty($brand_name)) {
            return;
        }
        $attribute_tax = 'pa_brands';
        $term = get_term_by('name', $brand_name, $attribute_tax);
        if (!$term || is_wp_error($term)) {
            return;
        }

        $logo_url = '';
        if (!empty($brand_info['logo'])) {
            $logo_url = esc_url_raw($brand_info['logo']);
        } elseif (!empty($brand_info['id'])) {
            $base = defined('RWBE_API_ENDPOINT_BRAND_DETAIL') ? RWBE_API_ENDPOINT_BRAND_DETAIL : 'https://portal.racewinningbrandseurope.com/apiv2/brands/brand';
            $url = add_query_arg(array('brand_id' => $brand_info['id']), trailingslashit($base));
            $response = $this->api_get($url, 45);
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                if (is_array($body) && !empty($body['logo'])) {
                    $logo_url = esc_url_raw($body['logo']);
                }
            }
        }

        if (!empty($logo_url)) {
            $existing_url = get_term_meta($term->term_id, 'rwbe_brand_logo_url', true);
            $existing_attachment = get_term_meta($term->term_id, 'rwbe_brand_logo_attachment_id', true);

            // If URL unchanged and we already have an attachment, skip re-download
            if (!empty($existing_url) && $existing_url === $logo_url && !empty($existing_attachment)) {
                RWBE_Debug_Logger::log('Brand logo already up-to-date, skipping download', ['brand' => $brand_name, 'term_id' => $term->term_id]);
                return;
            }

            // Download and attach to media library
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');

            $temp_file = download_url($logo_url);
            if (is_wp_error($temp_file)) {
                RWBE_Debug_Logger::log('Error downloading brand logo', ['brand' => $brand_name, 'url' => $logo_url, 'error' => $temp_file->get_error_message()]);
                // Store URL anyway for reference
                update_term_meta($term->term_id, 'rwbe_brand_logo_url', $logo_url);
                return;
            }

            $filename = basename(parse_url($logo_url, PHP_URL_PATH));
            if (empty($filename)) {
                $filename = sanitize_title($brand_name) . '.png';
            }

            $file_array = array(
                'name' => $filename,
                'tmp_name' => $temp_file
            );

            $attachment_id = media_handle_sideload($file_array, 0, 'Brand logo: ' . $brand_name);
            if (is_wp_error($attachment_id)) {
                @unlink($temp_file);
                RWBE_Debug_Logger::log('Error attaching brand logo', ['brand' => $brand_name, 'error' => $attachment_id->get_error_message()]);
                // Store URL anyway for reference
                update_term_meta($term->term_id, 'rwbe_brand_logo_url', $logo_url);
                return;
            }

            // Save meta on term for later display
            update_term_meta($term->term_id, 'rwbe_brand_logo_attachment_id', intval($attachment_id));
            update_term_meta($term->term_id, 'rwbe_brand_logo_url', $logo_url);
            RWBE_Debug_Logger::log('Downloaded and stored brand logo', ['brand' => $brand_name, 'term_id' => $term->term_id, 'attachment_id' => $attachment_id, 'logo_url' => $logo_url]);
        }
    }
    
    /**
     * Adiciona intervalo personalizado para o cron do WordPress
     *
     * @param array $schedules Agendamentos existentes
     * @return array Agendamentos atualizados
     */
    public function add_cron_interval($schedules) {
        // Adicionar intervalo de 2 minutos
        $schedules['rwbe_two_minutes'] = array(
            'interval' => 120, // 2 minutos em segundos
            'display'  => esc_html__('A cada 2 minutos')
        );
        
        return $schedules;
    }

    /**
     * Ensure all required attributes exist
     */
    public function ensure_all_attributes_exist() {
        // Definir todos os atributos necessários com seus rótulos
        // Removidos os atributos redundantes 'modelo' e 'ano' que estavam vazios
        $attributes = [
            'brands' => 'Brands',
            'grupo' => 'Grupo',
            'make' => 'Marca Veículo',
            'model' => 'Modelo Veículo',
            'vehicle_year' => 'Ano'
        ];
        
        // Criar todos os atributos usando a classe de ajuda
        foreach ($attributes as $slug => $label) {
            RWBE_Generic_Attribute_Helper::ensure_attribute_exists($slug, $label);
        }
        
        RWBE_Debug_Logger::log('All required attributes checked/created');
    }

    /**
     * Import products from RWBE API
     *
     * @param bool $is_cron Whether this is a cron import
     * @param bool $resume Whether to resume from last import position
     * @return array Import results
     */
    public function import_products($is_cron = false, $resume = false) {
        RWBE_Debug_Logger::log('Starting product import', ['is_cron' => $is_cron ? 'yes' : 'no', 'resume' => $resume ? 'yes' : 'no']);

        // No credential configured: the plugin ships without one on purpose.
        if (!rwbe_has_api_token()) {
            $msg = __('Nenhum API Token configurado. Introduza o token nas Configurações do plugin antes de importar.', 'rwbe-product-importer');
            RWBE_Debug_Logger::log('Import aborted: no API token configured');
            return array(
                'total' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0,
                'errors' => 1, 'error_messages' => array($msg),
                'completed' => false, 'missing_token' => true
            );
        }

        // Concurrency guard: only one import may run at a time. Without this, a slow
        // page can let the interrupted-import watchdog (or a second click / cron)
        // start a parallel run over the same offset, and the SKU existence check
        // races between wp_insert_post() and the _sku write below, creating exact
        // duplicate products (same SKU, same second, consecutive IDs).
        if (!$this->acquire_import_lock()) {
            RWBE_Debug_Logger::log('Another import already holds the lock, aborting this run to avoid duplicates', [
                'is_cron' => $is_cron ? 'yes' : 'no',
                'resume'  => $resume ? 'yes' : 'no'
            ]);
            return array(
                'total' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0,
                'errors' => 0, 'error_messages' => array('Import already running'),
                'completed' => false, 'locked_out' => true
            );
        }

        // Term counts are recomputed once, at the end of the run, instead of after
        // every term assignment of every product. Each product touches brand, group,
        // make, model and year terms, so this removes several COUNT queries per
        // product without changing the counts anyone ever reads.
        wp_defer_term_counting(true);

        try {
            return $this->run_import($is_cron, $resume);
        } finally {
            wp_defer_term_counting(false);
            $this->release_import_lock();
        }
    }

    /**
     * Core import loop. Always invoked through import_products(), which holds the
     * advisory lock for the whole duration.
     */
    private function run_import($is_cron = false, $resume = false) {
        // A run is starting/resuming — clear any stale stop request.
        $this->clear_stop_flag();
        // Garantir que os atributos necessários existam antes de começar a importação
        $this->ensure_all_attributes_exist();
        // Sincronizar taxonomias (brands e grupos) antes de processar produtos
        $this->maybe_sync_taxonomies();
        
        $results = array(
            'total' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'error_messages' => array(),
            'completed' => false
        );

        $limit = 100;
        $skip = 0;
        $has_more = true;
        $max_retries = 3;
        $retry_delay = 10; // segundos
        
        // Se estamos retomando uma importação anterior, recuperar o progresso salvo
        if ($resume) {
            $progress = get_option('rwbe_import_progress');
            if ($progress && !empty($progress['skip'])) {
                $skip = intval($progress['skip']);
                RWBE_Debug_Logger::log('Resuming import from previous position', ['skip' => $skip]);
                
                // Adicionar resultados anteriores aos resultados atuais
                if (!empty($progress['results']) && is_array($progress['results'])) {
                    foreach (['total', 'created', 'updated', 'skipped', 'errors', 'completed'] as $key) {
                        if (isset($progress['results'][$key])) {
                            $results[$key] = $progress['results'][$key];
                        }
                    }
                    
                    if (!empty($progress['results']['error_messages']) && is_array($progress['results']['error_messages'])) {
                        $results['error_messages'] = $progress['results']['error_messages'];
                    }
                    
                    RWBE_Debug_Logger::log('Restored previous import results', $results);
                    
                    // Se a importação já foi concluída anteriormente, retornar os resultados
                    if (!empty($results['completed']) && $results['completed'] === true) {
                        RWBE_Debug_Logger::log('Import was already completed previously, returning results');
                        return $results;
                    }
                }
            }
        } else {
            // Se não estamos retomando, limpar qualquer progresso anterior
            delete_option('rwbe_import_progress');
        }

        while ($has_more) {
            RWBE_Debug_Logger::log('Fetching products from API', ['limit' => $limit, 'skip' => $skip]);
            
            // Implementar sistema de tentativas para lidar com falhas de conexão
            $retry_count = 0;
            $products = null;
            
            while ($retry_count < $max_retries) {
                $products = $this->fetch_products_from_api($limit, $skip);
                
                if (!is_wp_error($products)) {
                    // Sucesso na requisição, sair do loop de tentativas
                    break;
                }
                
                $retry_count++;
                RWBE_Debug_Logger::log('API request failed, retrying', [
                    'retry' => $retry_count, 
                    'max_retries' => $max_retries, 
                    'error' => $products->get_error_message()
                ]);
                
                if ($retry_count < $max_retries) {
                    // Aguardar antes de tentar novamente
                    sleep($retry_delay);
                }
            }
            
            // Se ainda é um erro após todas as tentativas, registrar e continuar
            if (is_wp_error($products)) {
                $error_message = $products->get_error_message();
                RWBE_Debug_Logger::log('Error fetching products from API', ['error' => $error_message]);
                $results['errors']++;
                $results['error_messages'][] = $error_message;
                break;
            }

            if (empty($products)) {
                RWBE_Debug_Logger::log('No products returned from API');
                $has_more = false;
                continue;
            }

            RWBE_Debug_Logger::log('Products fetched successfully', ['count' => count($products)]);
            $results['total'] += count($products);

            // The cron path refreshes stock for every product, so fetch the whole
            // batch's live figures concurrently instead of one blocking request per
            // product inside the loop below.
            if ($is_cron) {
                $this->prefetch_stock($this->collect_api_ids($products));
            }

            foreach ($products as $product_data) {
                RWBE_Debug_Logger::log('Processing product', ['itemCode' => $product_data['itemCode'], 'title' => $product_data['title']]);
                $import_result = $this->process_product($product_data, $is_cron);
                
                if (is_wp_error($import_result)) {
                    $error_message = $import_result->get_error_message();
                    RWBE_Debug_Logger::log('Error processing product', ['itemCode' => $product_data['itemCode'], 'error' => $error_message]);
                    $results['errors']++;
                    $results['error_messages'][] = $error_message;
                } else {
                    RWBE_Debug_Logger::log('Product processed successfully', ['itemCode' => $product_data['itemCode'], 'result' => $import_result]);
                    $results[$import_result]++;
                }
            }
            
            // Atualizar o contador para a próxima página
            $skip += $limit;
            
            // Verificar se há mais produtos para buscar
            $has_more = (count($products) == $limit);
            
            // Salvar o progresso para poder retomar se necessário
            $results['completed'] = !$has_more; // Marcar como concluído se não houver mais produtos
            update_option('rwbe_import_progress', array(
                'skip' => $skip,
                'results' => $results,
                'timestamp' => time()
            ), false);
            
            // Adicionar um pequeno atraso para evitar sobrecarga da API
            if ($has_more) {
                usleep(500000); // 0.5 segundos
            }
        }

        RWBE_Debug_Logger::log('Import completed', $results);
        
        // Guardar timestamp da última importação
        if ($is_cron) {
            update_option('rwbe_last_cron_import_time', time(), false);
        } else {
            update_option('rwbe_last_import_time', time(), false);
        }

        // Limpar o progresso da importação quando concluída com sucesso
        delete_option('rwbe_import_progress');
        RWBE_Debug_Logger::log('Import progress cleared');
        
        return $results;
    }

    /**
     * Fetch products from RWBE API
     *
     * @param int $limit Number of products to fetch
     * @param int $skip Number of products to skip
     * @param string $date_updated Optional YYYY-MM-DD filter to fetch only products updated since that date
     * @return array|WP_Error Array of products or WP_Error on failure
     */
    private function fetch_products_from_api($limit, $skip, $date_updated = '') {
        if (!rwbe_has_api_token()) {
            RWBE_Debug_Logger::log('Product fetch aborted: no API token configured');
            return false;
        }

        // A new page means the previous page's prefetched stock is stale. Drop it so
        // the per-product fallback below can never answer from another page's data.
        $this->stock_cache = array();

        // Definir um timeout adequado para a requisição
        $api_timeout = 180; // Aumentar timeout para 3 minutos para a requisição principal
        $product_timeout = 90; // Aumentar timeout para 90 segundos para requisições de produtos individuais

        // Primeiro, vamos obter a lista de produtos básica
        $query_args = array(
            'limit' => $limit,
            'skip' => $skip
        );
        // Incremental sync: only fetch products updated since the given date
        if (!empty($date_updated)) {
            $query_args['dateUpdated'] = $date_updated;
        }
        $url = add_query_arg($query_args, RWBE_API_ENDPOINT);

        RWBE_Debug_Logger::log('Making API request for product list', ['url' => $url]);

        // Configurar a requisição com parâmetros adequados
        $request_args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . rwbe_get_api_token(),
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            ),
            'timeout' => $api_timeout,
            'httpversion' => '1.1', // Usar HTTP 1.1 para melhor suporte a conexões persistentes
            'sslverify' => true, // Ativar verificação SSL para segurança
            'user-agent' => 'RWBE Product Importer/' . RWBE_PRODUCT_IMPORTER_VERSION . '; WordPress/' . get_bloginfo('version'),
            'redirection' => 5, // Permitir até 5 redirecionamentos
            'blocking' => true, // Requisição bloqueante
            'cookies' => array(), // Sem cookies
            'body' => null, // Sem corpo para GET
            'compress' => true, // Permitir compressão
            'decompress' => true, // Permitir descompressão
            'stream' => false, // Não usar streaming
            'filename' => null // Não salvar em arquivo
        );
        
        // Tentar a requisição com retry em caso de falha
        $max_retries = 5; // Aumentar número de retentativas
        $retry_count = 0;
        $response = null;
        $connection_success = false;
        
        while ($retry_count <= $max_retries && !$connection_success) {
            // Registrar tentativa
            RWBE_Debug_Logger::log('Attempting API request', [
                'attempt' => $retry_count + 1, 
                'max_retries' => $max_retries,
                'url' => $url
            ]);
            
            // Adicionar um identificador único para evitar cache
            $unique_url = add_query_arg('_nocache', uniqid(), $url);
            
            // Fazer a requisição
            $response = wp_remote_get($unique_url, $request_args);
            
            if (!is_wp_error($response)) {
                $response_code = wp_remote_retrieve_response_code($response);
                
                if ($response_code === 200) {
                    // Requisição bem-sucedida
                    $connection_success = true;
                    RWBE_Debug_Logger::log('API request successful', ['attempt' => $retry_count + 1, 'code' => $response_code]);
                    break;
                } else if ($response_code >= 500) {
                    // Erro do servidor, tentar novamente
                    RWBE_Debug_Logger::log('API server error, retrying', ['attempt' => $retry_count + 1, 'code' => $response_code]);
                } else if ($response_code === 429) {
                    // Rate limiting, esperar mais tempo
                    RWBE_Debug_Logger::log('API rate limit reached, waiting longer before retry', ['attempt' => $retry_count + 1, 'code' => $response_code]);
                    $retry_count++;
                    if ($retry_count <= $max_retries) {
                        // Esperar mais tempo para rate limiting (30 segundos)
                        sleep(30);
                    }
                    continue;
                } else {
                    // Outros códigos de erro, tentar algumas vezes mais
                    RWBE_Debug_Logger::log('API returned error code, retrying', ['attempt' => $retry_count + 1, 'code' => $response_code]);
                }
            } else {
                // Erro de conexão, tentar novamente
                $error_code = $response->get_error_code();
                $error_message = $response->get_error_message();
                
                RWBE_Debug_Logger::log('API connection error, retrying', [
                    'attempt' => $retry_count + 1, 
                    'error_code' => $error_code,
                    'error' => $error_message
                ]);
                
                // Tratar diferentes tipos de erros de conexão
                if ($error_code === 'http_request_failed' && strpos($error_message, 'timed out') !== false) {
                    // Timeout - aumentar o timeout para a próxima tentativa
                    $request_args['timeout'] = $api_timeout * 1.5;
                    RWBE_Debug_Logger::log('Increasing timeout for next attempt', ['new_timeout' => $request_args['timeout']]);
                }
            }
            
            $retry_count++;
            if ($retry_count <= $max_retries) {
                // Esperar antes de tentar novamente (backoff exponencial com limite máximo)
                $wait_time = min(pow(2, $retry_count), 60); // Máximo de 60 segundos de espera
                RWBE_Debug_Logger::log('Waiting before retry', ['wait_time' => $wait_time]);
                sleep($wait_time);
            }
        }

        // Verificar se ainda temos um erro após as tentativas
        if (!$connection_success) {
            $error_message = is_wp_error($response) ? $response->get_error_message() : 'API returned error code: ' . wp_remote_retrieve_response_code($response);
            RWBE_Debug_Logger::log('API request failed after all retries', ['error' => $error_message]);
            return is_wp_error($response) ? $response : new WP_Error('api_error', $error_message);
        }

        $body = wp_remote_retrieve_body($response);
        
        // Registrar apenas o tamanho do corpo, não o conteúdo completo
        $body_size = strlen($body);
        RWBE_Debug_Logger::log('API response received', ['size' => $body_size . ' bytes']);
        
        // Verificar se o corpo está vazio
        if (empty($body)) {
            RWBE_Debug_Logger::log('API returned empty response body');
            return new WP_Error('empty_response', 'API returned empty response body');
        }
        
        // Usar try/catch para capturar erros de JSON
        // Compatibilidade com PHP < 7.3: não usar JSON_THROW_ON_ERROR
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $error_message = 'Failed to parse API response: ' . json_last_error_msg();
            RWBE_Debug_Logger::log('JSON parse error', ['error' => json_last_error_msg(), 'body_preview' => substr($body, 0, 200)]);
            return new WP_Error('json_error', $error_message);
        }

        // Determinar a estrutura da resposta e obter a lista de produtos
        $products = [];
        if (isset($data['data']) && is_array($data['data'])) {
            RWBE_Debug_Logger::log('Found products in data wrapper', ['count' => count($data['data'])]);
            $products = $data['data'];
        } elseif (is_array($data) && !empty($data)) {
            // Se a resposta for diretamente um array de produtos
            RWBE_Debug_Logger::log('Found products in direct array', ['count' => count($data)]);
            $products = $data;
        } else {
            // Verificar se há alguma estrutura alternativa na resposta
            $structure = $this->describe_array_structure($data);
            RWBE_Debug_Logger::log('No products found in response. Response structure:', $structure);
            return array();
        }
        
        // Se não encontrou produtos, retornar array vazio
        if (empty($products)) {
            RWBE_Debug_Logger::log('No products found in API response');
            return array();
        }
        
        // Para cada produto, obter detalhes completos do endpoint de produto único
        $detailed_products = [];
        $product_endpoint = defined('RWBE_API_ENDPOINT_PRODUCT_DETAIL') ? RWBE_API_ENDPOINT_PRODUCT_DETAIL : 'https://portal.racewinningbrandseurope.com/apiv2/products/product';
        
        RWBE_Debug_Logger::log('Fetching detailed product information', ['product_count' => count($products)]);
        
        // Cabeçalhos partilhados para os pedidos de detalhe (cliente Requests paralelo)
        $product_headers = array(
            'Authorization' => 'Bearer ' . rwbe_get_api_token(),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-cache',
        );

        // Processar produtos em lotes, mas agora cada lote é buscado EM PARALELO
        // via Requests::request_multiple() em vez de pedidos sequenciais. Isto
        // reduz drasticamente o tempo de rede (o gargalo do import completo).
        $batch_size = 10; // 10 pedidos HTTP concorrentes por lote
        $product_batches = array_chunk($products, $batch_size);
        $max_rounds = 3; // tentativas para produtos que falhem dentro do lote

        foreach ($product_batches as $batch_index => $product_batch) {
            RWBE_Debug_Logger::log('Processing product batch (parallel)', [
                'batch' => $batch_index + 1,
                'total_batches' => count($product_batches),
                'batch_size' => count($product_batch)
            ]);

            // Produtos sem ID não têm detalhe a buscar — manter os dados básicos
            $pending = array();
            foreach ($product_batch as $product) {
                if (empty($product['id'])) {
                    RWBE_Debug_Logger::log('Product missing ID, skipping detailed fetch', ['product' => json_encode($product)]);
                    $detailed_products[] = $product;
                    continue;
                }
                $pending[] = $product;
            }

            $round = 0;
            while (!empty($pending) && $round <= $max_rounds) {
                // Construir o conjunto de pedidos paralelos para os produtos pendentes
                $requests = array();
                foreach ($pending as $i => $product) {
                    $product_url = add_query_arg(array('product_id' => $product['id']), $product_endpoint);
                    $product_url = add_query_arg('_nocache', self::cache_buster(), $product_url);
                    $requests[$i] = array(
                        'url' => $product_url,
                        'headers' => $product_headers,
                        'type' => 'GET',
                        'options' => array('timeout' => $product_timeout)
                    );
                }

                $responses = $this->request_multiple_compat($requests, $product_timeout);

                $still_pending = array();
                $rate_limited = false;
                foreach ($pending as $i => $product) {
                    $resp = isset($responses[$i]) ? $responses[$i] : null;
                    $code = (is_object($resp) && isset($resp->status_code)) ? intval($resp->status_code) : 0;
                    $body = (is_object($resp) && isset($resp->body)) ? $resp->body : '';

                    if ($code === 200 && !empty($body)) {
                        // Compatibilidade com PHP < 7.3: não usar JSON_THROW_ON_ERROR
                        $product_data = json_decode($body, true);
                        if (json_last_error() === JSON_ERROR_NONE && !empty($product_data) && is_array($product_data)) {
                            // Fallback de stock via /stock quando indisponível
                            if (!isset($product_data['stock']) && !empty($product['id'])) {
                                $fallback_stock = $this->fetch_stock_for_product($product['id']);
                                if ($fallback_stock !== null) {
                                    $product_data['stock'] = intval($fallback_stock);
                                    $product_data['_stock_source'] = 'stock_endpoint';
                                }
                            }
                            $detailed_products[] = $product_data;
                        } else {
                            // JSON inválido ou vazio — usar os dados básicos
                            RWBE_Debug_Logger::log('Invalid/empty detailed product JSON, using basic data', ['product_id' => $product['id']]);
                            $detailed_products[] = $product;
                        }
                        continue;
                    }

                    if ($code === 429) {
                        $rate_limited = true;
                    }
                    // Falhou — tentar de novo na próxima ronda
                    $still_pending[] = $product;
                }

                $pending = $still_pending;
                $round++;

                // Só recuar (backoff) quando há produtos a repetir; mais tempo se houve rate limit
                if (!empty($pending) && $round <= $max_rounds) {
                    $wait = $rate_limited ? 30 : min(pow(2, $round), 15);
                    RWBE_Debug_Logger::log('Retrying failed products in batch', [
                        'remaining' => count($pending),
                        'round' => $round,
                        'wait' => $wait,
                        'rate_limited' => $rate_limited
                    ]);
                    sleep($wait);
                }
            }

            // Produtos que continuaram a falhar após todas as rondas — manter dados básicos
            foreach ($pending as $product) {
                RWBE_Debug_Logger::log('Detailed fetch failed after retries, using basic data', ['product_id' => $product['id']]);
                $detailed_products[] = $product;
            }
        }

        RWBE_Debug_Logger::log('Completed fetching detailed products', [
            'original_count' => count($products),
            'detailed_count' => count($detailed_products)
        ]);

        return $detailed_products;
    }

    /**
     * Perform several HTTP GET requests in parallel using the HTTP client that
     * ships with WordPress (WpOrg\Requests\Requests on WP >= 6.2, legacy Requests
     * on older). Falls back to sequential wp_remote_get() if neither is available.
     *
     * Each result is normalised to an object exposing ->status_code and ->body so
     * callers don't depend on the concrete Requests/Response class.
     *
     * @param array $requests Map of key => array{url, headers, type, options}
     * @param int   $timeout  Default per-request timeout in seconds
     * @return array Map of the same keys => response-like object (or null on hard failure)
     */
    private function request_multiple_compat($requests, $timeout = 90) {
        if (empty($requests)) {
            return array();
        }

        $options = array('timeout' => $timeout);

        // Preferir o cliente nativo do WordPress (faz os pedidos concorrentes via
        // curl_multi quando a extensão curl está disponível).
        $class = null;
        if (class_exists('WpOrg\\Requests\\Requests')) {
            $class = 'WpOrg\\Requests\\Requests';
        } elseif (class_exists('Requests')) {
            $class = 'Requests';
        }

        if ($class !== null) {
            try {
                // request_multiple não lança em falhas individuais: devolve o objeto
                // de exceção na posição correspondente, que tratamos como falha.
                $results = call_user_func(array($class, 'request_multiple'), $requests, $options);
                $normalized = array();
                foreach ($requests as $key => $_req) {
                    $r = isset($results[$key]) ? $results[$key] : null;
                    if (is_object($r) && isset($r->status_code)) {
                        $normalized[$key] = $r;
                    } else {
                        $normalized[$key] = null; // exceção ou falha → falha
                    }
                }
                return $normalized;
            } catch (Exception $e) {
                RWBE_Debug_Logger::log('request_multiple failed, falling back to sequential', ['error' => $e->getMessage()]);
            } catch (\Throwable $e) {
                RWBE_Debug_Logger::log('request_multiple errored, falling back to sequential', ['error' => $e->getMessage()]);
            }
        }

        // Fallback: pedidos sequenciais via wp_remote_get(), normalizados ao mesmo formato.
        $normalized = array();
        foreach ($requests as $key => $req) {
            $args = array(
                'headers' => isset($req['headers']) ? $req['headers'] : array(),
                'timeout' => isset($req['options']['timeout']) ? $req['options']['timeout'] : $timeout,
                'sslverify' => true,
            );
            $resp = wp_remote_get($req['url'], $args);
            if (is_wp_error($resp)) {
                $normalized[$key] = null;
                continue;
            }
            $obj = new stdClass();
            $obj->status_code = wp_remote_retrieve_response_code($resp);
            $obj->body = wp_remote_retrieve_body($resp);
            $normalized[$key] = $obj;
        }
        return $normalized;
    }

    /**
     * Descreve a estrutura de um array para fins de depuração
     *
     * @param array $array Array para descrever
     * @return array Descrição da estrutura
     */
    private function describe_array_structure($array) {
        if (!is_array($array)) {
            return ['type' => gettype($array)];
        }
        
        $structure = [];
        
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $structure[$key] = [
                    'type' => 'array',
                    'count' => count($value),
                    'sample_keys' => array_slice(array_keys($value), 0, 5)
                ];
            } else {
                $structure[$key] = ['type' => gettype($value)];
            }
        }
        
        return $structure;
    }

    /**
     * Process a single product
     *
     * @param array $product_data Product data from API
     * @param bool $is_cron Whether the import is being run by cron
     * @return string|WP_Error 'created', 'updated', 'skipped' or WP_Error
     */
    private function process_product($product_data, $is_cron = false) {
        // Reset the per-product live-log summary so error rows never inherit it
        $this->last_product_summary = array();

        // Check if we have the required fields
        if (empty($product_data['itemCode']) || empty($product_data['title'])) {
            RWBE_Debug_Logger::log('Missing required fields for product', $product_data);
            return new WP_Error('missing_data', 'Product is missing required fields');
        }

        // Sanitize and trim the SKU to ensure consistent format
        $sku = trim(sanitize_text_field($product_data['itemCode']));
        
        // Log the SKU being processed for debugging
        RWBE_Debug_Logger::log('Processing product with SKU', ['sku' => $sku]);
        
        // Resolve existing product by SKU. wc_get_product_id_by_sku() is the canonical,
        // indexed (and cached) lookup; only fall back to a direct postmeta query if it
        // returns nothing. (create_product() also re-checks before inserting, so there
        // is no duplicate risk.)
        $product_id = wc_get_product_id_by_sku($sku);
        if (!$product_id) {
            global $wpdb;
            $product_id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value = %s LIMIT 1",
                $sku
            ));
        }

        RWBE_Debug_Logger::log('Product existence check', ['sku' => $sku, 'product_id' => $product_id]);
        
        if ($product_id) {
            RWBE_Debug_Logger::log('Product already exists', ['product_id' => $product_id, 'sku' => $sku]);
            
            // Se é uma importação via cron, apenas atualizar estoque e preço
            if ($is_cron) {
                $result = $this->update_product_stock_price($product_id, $product_data);
                // Registrar no log em tempo real
                if (class_exists('RWBE_Product_Importer_Live_Log')) {
                    RWBE_Product_Importer_Live_Log::add_product_log($product_data, $result, $this->last_product_summary);
                }
                return $result;
            }
            
            // Se não é cron, atualizar o produto completamente
            $result = $this->update_product($product_id, $product_data);
            // Registrar no log em tempo real
            if (class_exists('RWBE_Product_Importer_Live_Log')) {
                RWBE_Product_Importer_Live_Log::add_product_log($product_data, is_wp_error($result) ? 'error' : $result, $this->last_product_summary);
            }
            return $result;
        } else {
            // Produto não existe, criar novo
            RWBE_Debug_Logger::log('Product does not exist, creating new', ['sku' => $sku]);
            $result = $this->create_product($product_data);
            // Registrar no log em tempo real
            if (class_exists('RWBE_Product_Importer_Live_Log')) {
                RWBE_Product_Importer_Live_Log::add_product_log($product_data, is_wp_error($result) ? 'error' : $result, $this->last_product_summary);
            }
            return $result;
        }
    }

    /**
     * Create a new product
     *
     * @param array $product_data Product data from API
     * @return string|WP_Error 'created' or WP_Error
     */
    private function create_product($product_data) {
        RWBE_Debug_Logger::log('Starting product creation', ['itemCode' => $product_data['itemCode']]);

        // Reset the per-product attribute buffer
        $this->pending_product_attributes = array();
        
        // Sanitize and trim the SKU to ensure consistent format
        $sku = trim(sanitize_text_field($product_data['itemCode']));
        
        // Verificação final para garantir que não estamos criando um produto duplicado
        global $wpdb;
        $existing_product_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
            WHERE meta_key = '_sku' AND meta_value = %s LIMIT 1",
            $sku
        ));
        
        if ($existing_product_id) {
            RWBE_Debug_Logger::log('Attempted to create product that already exists', [
                'sku' => $sku,
                'existing_product_id' => $existing_product_id
            ]);
            
            // Retornar um erro ou atualizar o produto existente
            return new WP_Error('product_exists', 'Product with SKU ' . $sku . ' already exists (ID: ' . $existing_product_id . ')');
        }
        
        $title = sanitize_text_field($product_data['title']);
        // Use API 'description' as long description; fallback to 'subtitle'
        $description = isset($product_data['description'])
            ? wp_kses_post($product_data['description'])
            : (isset($product_data['subtitle']) ? wp_kses_post($product_data['subtitle']) : '');
        // Use API 'subtitle' as short description (optional)
        $short_description = isset($product_data['subtitle']) ? wp_kses_post($product_data['subtitle']) : '';
        $price = isset($product_data['retailerPrice']) ? floatval($product_data['retailerPrice']) : 0;
        $stock = isset($product_data['stock']) ? intval($product_data['stock']) : 0;
        $stock_status = $stock > 0 ? 'instock' : 'outofstock';
        
        RWBE_Debug_Logger::log('Product data prepared', [
            'sku' => $sku,
            'title' => $title,
            'price' => $price,
            'stock' => $stock,
            'stock_status' => $stock_status
        ]);
        
        // Create the product
        $product = array(
            'post_title' => $title,
            'post_content' => $description,
            'post_excerpt' => $short_description,
            'post_status' => 'publish',
            'post_type' => 'product',
        );
        
        RWBE_Debug_Logger::log('Inserting product post', $product);
        $product_id = wp_insert_post($product, true);
        
        if (is_wp_error($product_id)) {
            RWBE_Debug_Logger::log('Error creating product post', [
                'error' => $product_id->get_error_message(),
                'sku' => $sku
            ]);
            return $product_id;
        }
        
        RWBE_Debug_Logger::log('Product post created successfully', ['product_id' => $product_id, 'sku' => $sku]);

        // Write the SKU immediately so the existence check in a concurrent run can
        // see this product as soon as possible, minimising the race window between
        // wp_insert_post() and _sku being queryable. (The import lock is the primary
        // guard; this is defence in depth.)
        update_post_meta($product_id, '_sku', $sku);

        // Set product type to simple
        RWBE_Debug_Logger::log('Setting product type to simple', ['product_id' => $product_id]);
        $term_result = wp_set_object_terms($product_id, 'simple', 'product_type');
        if (is_wp_error($term_result)) {
            RWBE_Debug_Logger::log('Error setting product type', [
                'error' => $term_result->get_error_message(),
                'product_id' => $product_id
            ]);
        }
        
        // Set product meta
        RWBE_Debug_Logger::log('Setting product meta', [
            'product_id' => $product_id,
            'sku' => $sku,
            'price' => $price,
            'stock' => $stock
        ]);
        
        // _sku already written right after insert (see above) to shrink the race window.
        // Pricing (regular + promo) handled centrally; prices stored excl. VAT
        $this->apply_product_pricing($product_id, $product_data);
        update_post_meta($product_id, '_stock', $stock);
        update_post_meta($product_id, '_stock_status', $stock_status);
        update_post_meta($product_id, '_manage_stock', 'yes');
        
        // Store supplier status as product meta (informational)
        // Link this WooCommerce product to its RWBE API id (used by dashboard stats)
        if (!empty($product_data['id'])) {
            update_post_meta($product_id, '_rwbe_product_id', sanitize_text_field($product_data['id']));
        }
        $api_status = isset($product_data['status']) ? sanitize_text_field($product_data['status']) : '';
        update_post_meta($product_id, '_rwbe_status', $api_status);
        update_post_meta($product_id, '_rwbe_status_updated_at', time());
        
        // Apply availability (publish/draft + visibility) based on API status
        $this->apply_product_availability($product_id, $api_status);

        // Set category
        if (!empty($product_data['segment'])) {
            $category_name = sanitize_text_field($product_data['segment']);
            RWBE_Debug_Logger::log('Setting product category', ['product_id' => $product_id, 'category' => $category_name]);
            $this->set_product_category($product_id, $category_name);
        }
        
        // Set brand attribute
        $brand_name = $this->extract_brand_name($product_data);
        if (!empty($brand_name)) {
            RWBE_Debug_Logger::log('Setting product brand', ['product_id' => $product_id, 'brand' => $brand_name]);
            $this->set_product_brand($product_id, $brand_name);
            // Try to fetch and store brand logo using brand detail endpoint
            if (!empty($product_data['brand']) && (is_array($product_data['brand']) || is_object($product_data['brand']))) {
                $brand_info = is_array($product_data['brand']) ? $product_data['brand'] : (array)$product_data['brand'];
                $this->maybe_update_brand_logo($brand_info, $brand_name);
            }
        } else {
            RWBE_Debug_Logger::log('No brand information found for product', ['product_id' => $product_id, 'product_data' => json_encode(array_keys($product_data))]);
        }
        
        // Set product group attribute
        $group_name = $this->extract_product_group($product_data);
        if (!empty($group_name)) {
            RWBE_Debug_Logger::log('Setting product group', ['product_id' => $product_id, 'group' => $group_name]);
            $this->set_product_group($product_id, $group_name);
        } else {
            RWBE_Debug_Logger::log('No product group information found for product', ['product_id' => $product_id, 'product_data' => json_encode(array_keys($product_data))]);
        }
        
        // Set application data (make and model)
        $application_data = $this->extract_application_data($product_data);
        
        // Set make attribute
        if (!empty($application_data['makes'])) {
            RWBE_Debug_Logger::log('Setting product makes', ['product_id' => $product_id, 'makes_count' => count($application_data['makes'])]);
            $this->set_product_makes($product_id, $application_data['makes']);
        } else {
            RWBE_Debug_Logger::log('No make information found for product', ['product_id' => $product_id]);
        }
        
        // Set model attribute
        if (!empty($application_data['models'])) {
            RWBE_Debug_Logger::log('Setting product models', ['product_id' => $product_id, 'models_count' => count($application_data['models'])]);
            $this->set_product_models($product_id, $application_data['models']);
        } else {
            RWBE_Debug_Logger::log('No model information found for product', ['product_id' => $product_id]);
        }
        
        // Set year attribute - anos individuais como taxonomia pa_year
        if (!empty($application_data['years'])) {
            RWBE_Debug_Logger::log('Setting product years', ['product_id' => $product_id, 'years_count' => count($application_data['years'])]);
            $this->set_product_years($product_id, $application_data['years']);
        } else {
            RWBE_Debug_Logger::log('No year information found for product', ['product_id' => $product_id]);
        }

        // Store the intact make+model+year-range combinations. The three attributes
        // above cannot express which model goes with which make or year.
        $this->store_product_fitment($product_id, $application_data);
        
        // Set product image
        if (!empty($product_data['photo'])) {
            RWBE_Debug_Logger::log('Setting product image', ['product_id' => $product_id, 'image_url' => $product_data['photo']]);
            
            // Verificar se a URL da imagem está completa
            $image_url = $product_data['photo'];
            
            // Verificar se a URL começa com http:// ou https://
            if (!preg_match('/^https?:\/\//i', $image_url)) {
                RWBE_Debug_Logger::log('Image URL does not have protocol, adding https://', ['original_url' => $image_url]);
                $image_url = 'https://' . ltrim($image_url, '/');
            }
            
            // Tentar importar a imagem principal como imagem destacada
            $image_result = $this->set_product_image($product_id, $image_url);
            
            if (!$image_result && !empty($product_data['gallery']) && is_array($product_data['gallery'])) {
                // Se a imagem principal falhar, tentar a primeira imagem da galeria como imagem destacada
                RWBE_Debug_Logger::log('Main image import failed, trying first gallery image as featured image', ['product_id' => $product_id]);
                
                foreach ($product_data['gallery'] as $gallery_image) {
                    $gallery_url = $gallery_image;
                    
                    // Verificar se a URL começa com http:// ou https://
                    if (!preg_match('/^https?:\/\//i', $gallery_url)) {
                        $gallery_url = 'https://' . ltrim($gallery_url, '/');
                    }
                    
                    $image_result = $this->set_product_image($product_id, $gallery_url);
                    
                    if ($image_result) {
                        RWBE_Debug_Logger::log('Successfully imported gallery image as featured image', ['product_id' => $product_id, 'image_url' => $gallery_url]);
                        break;
                    }
                }
            }
        }
        
        // Processar imagens da galeria
        if (!empty($product_data['gallery']) && is_array($product_data['gallery'])) {
            RWBE_Debug_Logger::log('Processing gallery images from API response', ['product_id' => $product_id, 'gallery_count' => count($product_data['gallery'])]);
            $this->set_product_gallery($product_id, $product_data['gallery']);
        } else {
            // Se não houver campo gallery na resposta da API, tentar construir URLs de galeria com base no ID do produto
            if (!empty($product_data['id'])) {
                RWBE_Debug_Logger::log('Trying to generate gallery URLs based on product ID', ['product_id' => $product_id, 'api_product_id' => $product_data['id']]);
                $gallery_urls = $this->generate_gallery_urls($product_data['id']);
                if (!empty($gallery_urls)) {
                    RWBE_Debug_Logger::log('Generated gallery URLs', ['product_id' => $product_id, 'url_count' => count($gallery_urls)]);
                    $this->set_product_gallery($product_id, $gallery_urls);
                } else {
                    RWBE_Debug_Logger::log('No gallery URLs could be generated', ['product_id' => $product_id]);
                }
            } else {
                RWBE_Debug_Logger::log('No gallery images found for product and no product ID available', ['product_id' => $product_id]);
            }
        }
        
        // Processar atributos técnicos - apenas se o método existir
        if (method_exists($this, 'extract_technical_attributes') && method_exists($this, 'set_product_technical_attributes')) {
            $technical_attributes = $this->extract_technical_attributes($product_data);
            if (!empty($technical_attributes)) {
                RWBE_Debug_Logger::log('Processing technical attributes', ['product_id' => $product_id, 'attributes_count' => count($technical_attributes)]);
                $this->set_product_technical_attributes($product_id, $technical_attributes);
            } else {
                RWBE_Debug_Logger::log('No technical attributes found for product', ['product_id' => $product_id]);
            }
        }
        
        // Record what was associated for the live import log
        $this->last_product_summary = $this->build_product_summary($product_data, $application_data);

        // Write all queued product attributes in a single meta update
        $this->flush_product_attributes($product_id);

        // Sync lookup tables and flush caches so the product is fully ready
        $this->finalize_product($product_id);

        RWBE_Debug_Logger::log('Product creation completed successfully', ['product_id' => $product_id, 'sku' => $sku]);
        return 'created';
    }

    /**
     * Update an existing product (all fields)
     *
     * @param int $product_id Product ID
     * @param array $product_data Product data from API
     * @return string|WP_Error 'updated' or WP_Error
     */
    private function update_product($product_id, $product_data) {
        // Reset the per-product attribute buffer
        $this->pending_product_attributes = array();

        $title = sanitize_text_field($product_data['title']);
        // Use API 'description' as long description; fallback to 'subtitle'
        $description = isset($product_data['description'])
            ? wp_kses_post($product_data['description'])
            : (isset($product_data['subtitle']) ? wp_kses_post($product_data['subtitle']) : '');
        // Use API 'subtitle' as short description (optional)
        $short_description = isset($product_data['subtitle']) ? wp_kses_post($product_data['subtitle']) : '';
        $price = isset($product_data['retailerPrice']) ? floatval($product_data['retailerPrice']) : 0;
        $stock = isset($product_data['stock']) ? intval($product_data['stock']) : 0;
        $stock_status = $stock > 0 ? 'instock' : 'outofstock';
        
        // Update the product
        $product = array(
            'ID' => $product_id,
            'post_title' => $title,
            'post_content' => $description,
            'post_excerpt' => $short_description,
        );
        
        $updated_id = wp_update_post($product, true);
        
        if (is_wp_error($updated_id)) {
            return $updated_id;
        }
        
        // Update product meta
        // Pricing (regular + promo) handled centrally; prices stored excl. VAT
        $this->apply_product_pricing($product_id, $product_data);
        update_post_meta($product_id, '_stock', $stock);
        update_post_meta($product_id, '_stock_status', $stock_status);
        update_post_meta($product_id, '_manage_stock', 'yes');
        
        // Keep supplier status synced as informational meta
        // Link this WooCommerce product to its RWBE API id (used by dashboard stats)
        if (!empty($product_data['id'])) {
            update_post_meta($product_id, '_rwbe_product_id', sanitize_text_field($product_data['id']));
        }
        $api_status = isset($product_data['status']) ? sanitize_text_field($product_data['status']) : '';
        update_post_meta($product_id, '_rwbe_status', $api_status);
        update_post_meta($product_id, '_rwbe_status_updated_at', time());
        
        // Apply availability (publish/draft + visibility) based on API status
        $this->apply_product_availability($product_id, $api_status);

        // Set category
        if (!empty($product_data['segment'])) {
            $category_name = sanitize_text_field($product_data['segment']);
            $this->set_product_category($product_id, $category_name);
        }
        
        // Set brand attribute
        $brand_name = $this->extract_brand_name($product_data);
        if (!empty($brand_name)) {
            RWBE_Debug_Logger::log('Setting product brand during update', ['product_id' => $product_id, 'brand' => $brand_name]);
            $this->set_product_brand($product_id, $brand_name);
            // Try to fetch and store brand logo using brand detail endpoint
            if (!empty($product_data['brand']) && (is_array($product_data['brand']) || is_object($product_data['brand']))) {
                $brand_info = is_array($product_data['brand']) ? $product_data['brand'] : (array)$product_data['brand'];
                $this->maybe_update_brand_logo($brand_info, $brand_name);
            }
        } else {
            RWBE_Debug_Logger::log('No brand information found for product during update', ['product_id' => $product_id, 'product_data' => json_encode(array_keys($product_data))]);
        }
        
        // Set product group attribute
        $group_name = $this->extract_product_group($product_data);
        if (!empty($group_name)) {
            RWBE_Debug_Logger::log('Setting product group during update', ['product_id' => $product_id, 'group' => $group_name]);
            $this->set_product_group($product_id, $group_name);
        } else {
            RWBE_Debug_Logger::log('No product group information found for product during update', ['product_id' => $product_id, 'product_data' => json_encode(array_keys($product_data))]);
        }
        
        // Set application data (make and model)
        $application_data = $this->extract_application_data($product_data);
        
        // Set make attribute
        if (!empty($application_data['makes'])) {
            RWBE_Debug_Logger::log('Setting product makes during update', ['product_id' => $product_id, 'makes_count' => count($application_data['makes'])]);
            $this->set_product_makes($product_id, $application_data['makes']);
        } else {
            RWBE_Debug_Logger::log('No make information found for product during update', ['product_id' => $product_id]);
        }
        
        // Set model attribute
        if (!empty($application_data['models'])) {
            RWBE_Debug_Logger::log('Setting product models during update', ['product_id' => $product_id, 'models_count' => count($application_data['models'])]);
            $this->set_product_models($product_id, $application_data['models']);
        } else {
            RWBE_Debug_Logger::log('No model information found for product during update', ['product_id' => $product_id]);
        }
        
        // Set year attribute - anos individuais como taxonomia pa_year
        if (!empty($application_data['years'])) {
            RWBE_Debug_Logger::log('Setting product years during update', ['product_id' => $product_id, 'years_count' => count($application_data['years'])]);
            $this->set_product_years($product_id, $application_data['years']);
        } else {
            RWBE_Debug_Logger::log('No year information found for product during update', ['product_id' => $product_id]);
        }

        // Store the intact make+model+year-range combinations (see create path).
        $this->store_product_fitment($product_id, $application_data);
        
        // Set product image
        if (!empty($product_data['photo'])) {
            RWBE_Debug_Logger::log('Setting product image during update', ['product_id' => $product_id, 'image_url' => $product_data['photo']]);
            
            // Verificar se a URL da imagem está completa
            $image_url = $product_data['photo'];
            
            // Verificar se a URL começa com http:// ou https://
            if (!preg_match('/^https?:\/\//i', $image_url)) {
                RWBE_Debug_Logger::log('Image URL does not have protocol, adding https://', ['original_url' => $image_url]);
                $image_url = 'https://' . ltrim($image_url, '/');
            }
            
            // Tentar importar a imagem
            $image_result = $this->set_product_image($product_id, $image_url);
            
            if (!$image_result && !empty($product_data['gallery']) && is_array($product_data['gallery'])) {
                // Se a imagem principal falhar, tentar a primeira imagem da galeria
                RWBE_Debug_Logger::log('Main image import failed during update, trying first gallery image', ['product_id' => $product_id]);
                
                foreach ($product_data['gallery'] as $gallery_image) {
                    $gallery_url = $gallery_image;
                    
                    // Verificar se a URL começa com http:// ou https://
                    if (!preg_match('/^https?:\/\//i', $gallery_url)) {
                        $gallery_url = 'https://' . ltrim($gallery_url, '/');
                    }
                    
                    $image_result = $this->set_product_image($product_id, $gallery_url);
                    
                    if ($image_result) {
                        RWBE_Debug_Logger::log('Successfully imported gallery image during update', ['product_id' => $product_id, 'image_url' => $gallery_url]);
                        break; // Sair do loop se uma imagem for importada com sucesso
                    }
                }
            }
        }
        
        // Processar imagens da galeria
        if (!empty($product_data['gallery']) && is_array($product_data['gallery'])) {
            RWBE_Debug_Logger::log('Processing gallery images from API response during update', ['product_id' => $product_id, 'gallery_count' => count($product_data['gallery'])]);
            $this->set_product_gallery($product_id, $product_data['gallery']);
        } else {
            // Se não houver campo gallery na resposta da API, tentar construir URLs de galeria com base no ID do produto
            if (!empty($product_data['id'])) {
                RWBE_Debug_Logger::log('Trying to generate gallery URLs based on product ID during update', ['product_id' => $product_id, 'api_product_id' => $product_data['id']]);
                $gallery_urls = $this->generate_gallery_urls($product_data['id']);
                if (!empty($gallery_urls)) {
                    RWBE_Debug_Logger::log('Generated gallery URLs during update', ['product_id' => $product_id, 'url_count' => count($gallery_urls)]);
                    $this->set_product_gallery($product_id, $gallery_urls);
                } else {
                    RWBE_Debug_Logger::log('No gallery URLs could be generated during update', ['product_id' => $product_id]);
                }
            } else {
                RWBE_Debug_Logger::log('No gallery images found for product and no product ID available during update', ['product_id' => $product_id]);
            }
        }
        
        // Processar atributos técnicos - apenas se o método existir
        if (method_exists($this, 'extract_technical_attributes') && method_exists($this, 'set_product_technical_attributes')) {
            $technical_attributes = $this->extract_technical_attributes($product_data);
            if (!empty($technical_attributes)) {
                RWBE_Debug_Logger::log('Processing technical attributes during update', ['product_id' => $product_id, 'attributes_count' => count($technical_attributes)]);
                $this->set_product_technical_attributes($product_id, $technical_attributes);
            } else {
                RWBE_Debug_Logger::log('No technical attributes found for product during update', ['product_id' => $product_id]);
            }
        }

        // Record what was associated for the live import log
        $this->last_product_summary = $this->build_product_summary($product_data, $application_data);

        // Write all queued product attributes in a single meta update
        $this->flush_product_attributes($product_id);

        // Sync lookup tables and flush caches so the product is fully ready
        $this->finalize_product($product_id);

        return 'updated';
    }

    /**
     * Update only stock and price for an existing product (for cron job)
     *
     * @param int $product_id Product ID
     * @param array $product_data Product data from API
     * @return string 'updated'
     */
    private function update_product_stock_price($product_id, $product_data) {
        $price = isset($product_data['retailerPrice']) ? floatval($product_data['retailerPrice']) : 0;

        // Prefer freshest stock from /stock endpoint when available
        $api_stock = isset($product_data['stock']) ? intval($product_data['stock']) : null;
        if (!empty($product_data['id']) && method_exists($this, 'fetch_stock_for_product')) {
            $fresh_stock = $this->fetch_stock_for_product($product_data['id']);
            if ($fresh_stock !== null) {
                RWBE_Debug_Logger::log('Using fresh stock from /stock endpoint during cron', [
                    'api_product_id' => $product_data['id'],
                    'fresh_stock' => $fresh_stock
                ]);
                $api_stock = intval($fresh_stock);
            }
        }

        $stock = ($api_stock !== null) ? $api_stock : 0;
        $stock_status = $stock > 0 ? 'instock' : 'outofstock';

        // Every write below is tracked, because update_post_meta() / delete_post_meta()
        // report whether they actually altered anything. On a twice-daily sync of a
        // 35k-product catalogue the overwhelming majority of products come back
        // byte-identical, and the expensive part — finalize_product(), i.e. a full
        // WooCommerce CRUD save plus a transient and post-cache flush — is pure waste
        // for those. It now runs only when something really moved, which also covers a
        // manual edit in wp-admin: correcting it back counts as a change.
        $changed = false;

        // Update product meta
        // Pricing (regular + promo) handled centrally; prices stored excl. VAT
        $changed = $this->apply_product_pricing($product_id, $product_data) || $changed;
        $changed = $this->update_meta_if_changed($product_id, '_stock', $stock) || $changed;
        $changed = $this->update_meta_if_changed($product_id, '_stock_status', $stock_status) || $changed;

        // Sync supplier status meta during cron as well
        // Link this WooCommerce product to its RWBE API id (used by dashboard stats)
        if (!empty($product_data['id'])) {
            $changed = $this->update_meta_if_changed($product_id, '_rwbe_product_id', sanitize_text_field($product_data['id'])) || $changed;
        }
        $api_status = isset($product_data['status']) ? sanitize_text_field($product_data['status']) : '';
        $changed = $this->update_meta_if_changed($product_id, '_rwbe_status', $api_status) || $changed;

        // Apply availability (publish/draft + visibility) based on API status
        $changed = $this->apply_product_availability($product_id, $api_status) || $changed;

        // Record a minimal summary for the live import log (cron = stock/price only)
        $this->last_product_summary = array(
            'price' => $price,
            'sale'  => null,
            'stock' => $stock,
            'cron'  => true,
        );

        if ($changed) {
            // Stamped only on a real change, so it reads as "last time the sync moved
            // this product" instead of costing an UPDATE per product per run. Nothing
            // reads this value; it is diagnostic only.
            update_post_meta($product_id, '_rwbe_status_updated_at', time());

            // Sync lookup tables (price/stock) and flush caches so changes show immediately
            $this->finalize_product($product_id);
        }

        return 'updated';
    }

    /**
     * Apply pricing (regular + promotional) to a product from API data.
     *
     * Prices are stored EXCLUDING VAT; WooCommerce adds tax at checkout, so the
     * product is forced to 'taxable'. Regular price comes from `retailerPrice`.
     * Promotions come from `grossPromoPricing` + `promoSpecs` (dateFrom/dateTo).
     *
     * @param int   $product_id   Product ID
     * @param array $product_data Product data from API
     * @return bool True when any stored value actually changed. Lets the cron skip
     *              the expensive CRUD save for products whose pricing is untouched.
     */
    private function apply_product_pricing($product_id, $product_data) {
        $changed = false;

        // Regular price (excl. VAT). WooCommerce applies tax at checkout.
        $regular_price = isset($product_data['retailerPrice']) ? floatval($product_data['retailerPrice']) : 0;
        $changed = $this->update_meta_if_changed($product_id, '_regular_price', $regular_price) || $changed;

        // Ensure WooCommerce treats this product as taxable (prices stored excl. VAT)
        $changed = $this->update_meta_if_changed($product_id, '_tax_status', 'taxable') || $changed;

        // Promotional price + window
        $sale_price = isset($product_data['grossPromoPricing']) ? floatval($product_data['grossPromoPricing']) : 0;
        $date_from = '';
        $date_to = '';
        if (!empty($product_data['promoSpecs']) && is_array($product_data['promoSpecs'])) {
            $date_from = !empty($product_data['promoSpecs']['dateFrom']) ? $product_data['promoSpecs']['dateFrom'] : '';
            $date_to   = !empty($product_data['promoSpecs']['dateTo'])   ? $product_data['promoSpecs']['dateTo']   : '';
        }

        // A valid sale must be > 0 and strictly below the regular price
        $has_sale = ($sale_price > 0 && $regular_price > 0 && $sale_price < $regular_price);

        $from_ts = ($date_from !== '') ? strtotime($date_from . ' 00:00:00') : 0;
        $to_ts   = ($date_to !== '')   ? strtotime($date_to . ' 23:59:59')   : 0;

        if ($has_sale) {
            $changed = $this->update_meta_if_changed($product_id, '_sale_price', $sale_price) || $changed;
            $changed = $this->update_meta_if_changed($product_id, '_sale_price_dates_from', $from_ts ? $from_ts : '') || $changed;
            $changed = $this->update_meta_if_changed($product_id, '_sale_price_dates_to', $to_ts ? $to_ts : '') || $changed;

            // Is the sale active right now? (WooCommerce's wc_scheduled_sales cron
            // will also flip _price when the window opens/closes.)
            $now = current_time('timestamp');
            $started   = empty($from_ts) || $now >= $from_ts;
            $not_ended = empty($to_ts)   || $now <= $to_ts;
            $active = $started && $not_ended;

            $changed = $this->update_meta_if_changed($product_id, '_price', $active ? $sale_price : $regular_price) || $changed;
        } else {
            // No (valid) promo: clear any previous sale data
            $changed = delete_post_meta($product_id, '_sale_price') || $changed;
            $changed = delete_post_meta($product_id, '_sale_price_dates_from') || $changed;
            $changed = delete_post_meta($product_id, '_sale_price_dates_to') || $changed;
            $changed = $this->update_meta_if_changed($product_id, '_price', $regular_price) || $changed;
        }

        RWBE_Debug_Logger::log('Applied pricing', [
            'product_id' => $product_id,
            'regular'    => $regular_price,
            'sale'       => $has_sale ? $sale_price : null,
            'date_from'  => $date_from,
            'date_to'    => $date_to,
            'changed'    => $changed ? 'yes' : 'no'
        ]);

        return $changed;
    }

    /**
     * Finalize a product after all data and associations are set.
     *
     * Loads the product through WooCommerce CRUD and saves it ONCE so the product
     * lookup tables (price, stock, sku, attribute lookup) are populated and all
     * caches are flushed. Without this, products are created with the correct meta
     * and terms but may not appear properly in catalog filters, price sorting or
     * the in-stock filter until manually re-saved. This makes every product fully
     * ready automatically, with no manual step.
     *
     * @param int $product_id Product ID
     */
    private function finalize_product($product_id) {
        $product = wc_get_product($product_id);
        if ($product) {
            // CRUD save recomputes the active price (regular vs. active sale),
            // derives stock status, and triggers the attribute lookup update.
            $product->save();
            RWBE_Debug_Logger::log('Product finalized (lookup tables synced)', ['product_id' => $product_id]);
        } else {
            RWBE_Debug_Logger::log('Finalize: could not load product via CRUD', ['product_id' => $product_id]);
        }

        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($product_id);
        }
        clean_post_cache($product_id);
    }

    /**
     * Apply availability based on the API 'status' field.
     *
     * The RWBE API marks available products with status "A". Products whose status
     * is anything else (discontinued/inactive) are moved to 'draft' so they leave
     * the storefront; available products are (re)published and made visible.
     *
     * An empty/missing status is treated as available, so a missing field never
     * accidentally hides products in bulk.
     *
     * @param int    $product_id Product ID
     * @param string $api_status Raw status value from the API
     * @return bool True when the post status or the visibility terms changed.
     */
    private function apply_product_availability($product_id, $api_status) {
        $api_status = is_string($api_status) ? strtoupper(trim($api_status)) : '';
        $available = ($api_status === '' || $api_status === 'A');

        $current_status = get_post_status($product_id);
        $changed = false;

        if ($available) {
            if ($current_status !== 'publish') {
                wp_update_post(array('ID' => $product_id, 'post_status' => 'publish'));
                RWBE_Debug_Logger::log('Product (re)published (status available)', ['product_id' => $product_id, 'status' => $api_status]);
                $changed = true;
            }
            // Ensure visible in catalog and search.
            //
            // Checked first: wp_remove_object_terms() issues its DELETE whenever the
            // terms exist in the taxonomy, whether or not this product carries them,
            // so the unguarded call cost one query per product per run for a catalogue
            // that is almost entirely visible. has_term() answers from the object-terms
            // cache WooCommerce has already warmed.
            if (function_exists('wp_remove_object_terms') && taxonomy_exists('product_visibility')) {
                $hidden = array();
                foreach (array('exclude-from-catalog', 'exclude-from-search') as $term) {
                    if (has_term($term, 'product_visibility', $product_id)) {
                        $hidden[] = $term;
                    }
                }
                if (!empty($hidden)) {
                    $removed = wp_remove_object_terms($product_id, $hidden, 'product_visibility');
                    if ($removed === true) {
                        $changed = true;
                    }
                }
            }
        } else {
            if ($current_status !== 'draft') {
                wp_update_post(array('ID' => $product_id, 'post_status' => 'draft'));
                RWBE_Debug_Logger::log('Product unpublished (status not available)', ['product_id' => $product_id, 'status' => $api_status]);
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * Set product category
     *
     * @param int $product_id Product ID
     * @param string $category_name Category name
     * @return bool True on success, false on failure
     */
    private function set_product_category($product_id, $category_name) {
        RWBE_Debug_Logger::log('Setting product category', ['product_id' => $product_id, 'category_name' => $category_name]);
        
        if (empty($category_name)) {
            RWBE_Debug_Logger::log('Empty category name, skipping category assignment', ['product_id' => $product_id]);
            return false;
        }
        
        // Check if category contains hierarchy (separated by '>')
        $category_parts = array_map('trim', explode('>', $category_name));
        $parent_id = 0;
        $last_term_id = 0;
        $all_term_ids = array();
        
        // Process each level of the hierarchy
        foreach ($category_parts as $category_part) {
            if (empty($category_part)) {
                continue;
            }
            
            // Check if term exists at this level of hierarchy
            $term = term_exists($category_part, 'product_cat', $parent_id);
            
            if (!$term) {
                RWBE_Debug_Logger::log('Category does not exist, creating it', [
                    'category_name' => $category_part,
                    'parent_id' => $parent_id
                ]);
                
                $term = wp_insert_term($category_part, 'product_cat', [
                    'parent' => $parent_id
                ]);
            }
            
            if (is_wp_error($term)) {
                RWBE_Debug_Logger::log('Error creating category', [
                    'category_name' => $category_part,
                    'error' => $term->get_error_message()
                ]);
                return false;
            }
            
            $parent_id = $term['term_id'];
            $last_term_id = $term['term_id'];
            $all_term_ids[] = intval($term['term_id']);
        }
        
        // Set all hierarchy levels for the product (parent and children)
        // wp_set_object_terms replaces existing terms for this taxonomy by default
        $result = wp_set_object_terms($product_id, $all_term_ids, 'product_cat');
        
        if (is_wp_error($result)) {
            RWBE_Debug_Logger::log('Error setting category for product', [
                'product_id' => $product_id,
                'category_ids' => $all_term_ids,
                'error' => $result->get_error_message()
            ]);
            return false;
        }
        
        RWBE_Debug_Logger::log('Category set successfully for product', [
            'product_id' => $product_id,
            'category_ids' => $all_term_ids,
            'category_name' => $category_name
        ]);
        
        return true;
    }

    /**
     * Set product brand attribute
     *
     * @param int $product_id Product ID
     * @param string $brand_name Brand name
     * @return bool True on success, false on failure
     */
    private function set_product_brand($product_id, $brand_name) {
        RWBE_Debug_Logger::log('Starting brand configuration', ['product_id' => $product_id, 'brand_name' => $brand_name]);

        if (empty($brand_name)) {
            RWBE_Debug_Logger::log('Empty brand name, skipping brand configuration', ['product_id' => $product_id]);
            return false;
        }

        // Get attribute name from a constant or option for flexibility
        $attribute_slug = 'brands'; // Base slug without pa_ prefix
        $attribute_name = 'pa_' . $attribute_slug;
        $attribute_label = 'Brands';

        // Verificar se a taxonomia existe
        if (!taxonomy_exists($attribute_name)) {
            RWBE_Debug_Logger::log('Brand taxonomy does not exist, trying to create it', ['attribute_name' => $attribute_name]);
            
            // Tentar criar o atributo e a taxonomia usando a classe de ajuda
            if (!RWBE_Generic_Attribute_Helper::ensure_attribute_exists($attribute_slug, $attribute_label)) {
                RWBE_Debug_Logger::log('Failed to create brand attribute, cannot continue', ['attribute_name' => $attribute_name]);
                return false;
            }
        }
        
        // Keep original brand name for public taxonomy assignment; sanitize for attribute taxonomy
        $original_brand_name = $brand_name;
        // Sanitize the brand name to avoid special character issues for pa_ taxonomy lookups
        $brand_name = wc_sanitize_taxonomy_name($brand_name);
        
        // Resolver/criar o termo (com cache em memória por execução)
        $term_id = $this->get_or_create_term_id($brand_name, $attribute_name);
        if (!$term_id) {
            RWBE_Debug_Logger::log('Could not resolve brand term', ['brand_name' => $brand_name]);
            return false;
        }

        // Definir o termo para o produto (wp_set_object_terms substitui os termos
        // existentes deste atributo — não é preciso lê-los antes)
        $set_terms_result = wp_set_object_terms($product_id, $term_id, $attribute_name);
        
        if (is_wp_error($set_terms_result)) {
            RWBE_Debug_Logger::log('Error setting brand term for product', [
                'product_id' => $product_id,
                'term_id' => $term_id,
                'error' => $set_terms_result->get_error_message()
            ]);
            return false;
        }
        
        RWBE_Debug_Logger::log('Brand term set successfully for product', ['product_id' => $product_id, 'term_id' => $term_id]);
        
        // Queue the brand attribute; written once per product via flush_product_attributes()
        $this->queue_product_attribute($attribute_name, 0, true);
        
        // Mirror assignment into public taxonomy 'product_brand' for theme compatibility
        if (taxonomy_exists('product_brand')) {
            $public_term_id = $this->get_or_create_term_id($original_brand_name, 'product_brand');

            if (!empty($public_term_id)) {
                $public_set = wp_set_object_terms($product_id, $public_term_id, 'product_brand');
                if (is_wp_error($public_set)) {
                    RWBE_Debug_Logger::log('Error setting product_brand term for product', [
                        'product_id' => $product_id,
                        'term_id' => $public_term_id,
                        'error' => $public_set->get_error_message()
                    ]);
                }
            }
        }

        return true;
    }

    /**
     * Set product gallery images
     *
     * @param int $product_id Product ID
     * @param array $gallery_data Array of gallery image data (pode ser array de URLs ou array de objetos)
     * @return bool True if at least one image was added, false otherwise
     */
    private function set_product_gallery($product_id, $gallery_data) {
        RWBE_Debug_Logger::log('Starting gallery images import', ['product_id' => $product_id, 'gallery_count' => count($gallery_data)]);
        
        if (empty($gallery_data) || !is_array($gallery_data)) {
            RWBE_Debug_Logger::log('No gallery images to import', ['product_id' => $product_id]);
            return false;
        }
        
        // Processar o array de galeria para extrair URLs
        $gallery_urls = [];
        
        foreach ($gallery_data as $item) {
            if (is_string($item)) {
                // Se o item for uma string, assumimos que é uma URL
                $gallery_urls[] = $item;
                RWBE_Debug_Logger::log('Found gallery image URL (string)', ['url' => substr($item, 0, 100) . '...']);
            } elseif (is_array($item)) {
                // Se o item for um array, procuramos por chaves que possam conter a URL
                $possible_keys = ['url', 'src', 'source', 'path', 'image', 'photo'];
                
                foreach ($possible_keys as $key) {
                    if (!empty($item[$key]) && is_string($item[$key])) {
                        $gallery_urls[] = $item[$key];
                        RWBE_Debug_Logger::log('Found gallery image URL in object', ['key' => $key, 'url' => substr($item[$key], 0, 100) . '...']);
                        break;
                    }
                }
            }
        }
        
        RWBE_Debug_Logger::log('Extracted gallery URLs', ['count' => count($gallery_urls)]);
        
        if (empty($gallery_urls)) {
            RWBE_Debug_Logger::log('No valid gallery URLs found after processing', ['product_id' => $product_id]);
            return false;
        }
        
        // Required for download_url() and media_handle_sideload()
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        
        $attachment_ids = [];
        $success = false;
        
        // Obter os IDs de anexos existentes na galeria do produto
        $existing_attachment_ids = get_post_meta($product_id, '_product_image_gallery', true);
        $existing_attachment_ids = $existing_attachment_ids ? explode(',', $existing_attachment_ids) : [];

        // Map existing gallery attachments by their source URL so the same image is
        // never downloaded twice on re-imports (prevents media-library bloat).
        $existing_sources = array();
        foreach ($existing_attachment_ids as $eid) {
            $eid = intval($eid);
            if (!$eid) {
                continue;
            }
            $src = get_post_meta($eid, '_rwbe_source_url', true);
            if (!empty($src)) {
                $existing_sources[$src] = $eid;
            }
        }

        foreach ($gallery_urls as $index => $image_url) {
            // Verificar se a URL da imagem é válida
            if (empty($image_url)) {
                RWBE_Debug_Logger::log('Empty gallery image URL', ['product_id' => $product_id, 'index' => $index]);
                continue;
            }
            
            // Verificar se a URL começa com http:// ou https://
            if (!preg_match('/^https?:\/\//i', $image_url)) {
                RWBE_Debug_Logger::log('Adding https:// to gallery image URL', ['original_url' => $image_url]);
                $image_url = 'https://' . ltrim($image_url, '/');
            }

            // Already imported this exact URL for this product? Reuse it, don't re-download.
            if (isset($existing_sources[$image_url])) {
                RWBE_Debug_Logger::log('Gallery image already imported, skipping download', ['product_id' => $product_id, 'url' => $image_url]);
                $attachment_ids[] = $existing_sources[$image_url];
                $success = true;
                continue;
            }

            // Global dedup: the same image is often shared across many SKUs. If it was
            // already imported for ANY product, reuse that attachment instead of
            // downloading + regenerating thumbnails again.
            $shared_id = $this->find_attachment_by_source_url($image_url);
            if ($shared_id) {
                RWBE_Debug_Logger::log('Gallery image reused from another product, skipping download', ['product_id' => $product_id, 'url' => $image_url, 'attachment_id' => $shared_id]);
                $attachment_ids[] = $shared_id;
                $existing_sources[$image_url] = $shared_id;
                $success = true;
                continue;
            }

            // Verificar a extensão do arquivo
            $file_info = pathinfo($image_url);
            $file_extension = strtolower(isset($file_info['extension']) ? $file_info['extension'] : '');
            $filename = basename($image_url);
            
            if (empty($file_extension) || !in_array($file_extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                // Adicionar uma extensão padrão se não houver uma válida
                $filename .= '.jpg';
                RWBE_Debug_Logger::log('Added default extension to gallery image filename', ['new_filename' => $filename]);
            }
            
            RWBE_Debug_Logger::log('Downloading gallery image', ['product_id' => $product_id, 'index' => $index, 'url' => $image_url]);
            
            // Baixar a imagem
            $temp_file = download_url($image_url);
            
            if (is_wp_error($temp_file)) {
                RWBE_Debug_Logger::log('Error downloading gallery image', [
                    'product_id' => $product_id,
                    'index' => $index,
                    'error' => $temp_file->get_error_message()
                ]);
                continue;
            }

            // Placeholder detection (see set_product_image): reuse the single shared
            // attachment instead of importing the "no image" PNG per product.
            if (md5_file($temp_file) === self::PLACEHOLDER_MD5) {
                $placeholder_id = $this->get_or_create_placeholder_attachment($temp_file, 'rwb-placeholder.png');
                if ($placeholder_id) {
                    $attachment_ids[] = $placeholder_id;
                    $existing_sources[$image_url] = $placeholder_id;
                    $success = true;
                    RWBE_Debug_Logger::log('Gallery image is placeholder, reused shared attachment', ['product_id' => $product_id, 'attachment_id' => $placeholder_id]);
                }
                continue;
            }

            // Preparar o array de dados para o anexo
            $file_array = array(
                'name' => $filename,
                'tmp_name' => $temp_file
            );
            
            // Fazer a validação e armazenamento permanente
            $attachment_id = media_handle_sideload($file_array, $product_id);
            
            // Se houver erro ao armazenar permanentemente, excluir o arquivo temporário
            if (is_wp_error($attachment_id)) {
                RWBE_Debug_Logger::log('Error processing gallery image', [
                    'product_id' => $product_id, 
                    'index' => $index,
                    'error' => $attachment_id->get_error_message()
                ]);
                @unlink($temp_file);
                continue;
            }
            
            // Remember the source URL so re-imports (and other products in this run)
            // can skip this download — persisted as meta + cached in memory.
            update_post_meta($attachment_id, '_rwbe_source_url', $image_url);
            $this->image_source_cache[$image_url] = $attachment_id;

            RWBE_Debug_Logger::log('Gallery image processed successfully', [
                'product_id' => $product_id,
                'index' => $index,
                'attachment_id' => $attachment_id
            ]);

            // Adicionar o ID do anexo ao array
            $attachment_ids[] = $attachment_id;
            $success = true;
        }
        
        // Combinar os IDs de anexos existentes com os novos
        $all_attachment_ids = array_merge($existing_attachment_ids, $attachment_ids);
        $all_attachment_ids = array_unique($all_attachment_ids);
        
        if (!empty($all_attachment_ids)) {
            // Atualizar a galeria do produto
            $gallery_string = implode(',', $all_attachment_ids);
            update_post_meta($product_id, '_product_image_gallery', $gallery_string);
            
            RWBE_Debug_Logger::log('Product gallery updated', [
                'product_id' => $product_id, 
                'gallery_ids' => $gallery_string
            ]);
        }
        
        return $success;
    }
    
    /**
     * Set product image
     *
     * @param int $product_id Product ID
     * @param string $image_url Image URL
     */
    private function set_product_image($product_id, $image_url) {
        RWBE_Debug_Logger::log('Starting image import', ['product_id' => $product_id, 'image_url' => $image_url]);
        
        // Validar URL da imagem
        if (empty($image_url)) {
            RWBE_Debug_Logger::log('Empty image URL, skipping image import', ['product_id' => $product_id]);
            return false;
        }
        
        // Verificar se a URL é válida
        if (!filter_var($image_url, FILTER_VALIDATE_URL)) {
            RWBE_Debug_Logger::log('Invalid image URL', ['product_id' => $product_id, 'image_url' => $image_url]);
            return false;
        }
        
        // Check if the product already has an image
        if (has_post_thumbnail($product_id)) {
            RWBE_Debug_Logger::log('Product already has a thumbnail, skipping image import', ['product_id' => $product_id]);
            return false;
        }

        // Global dedup: if this exact image was already imported (featured or gallery)
        // for any product, reuse that attachment instead of downloading again.
        $shared_id = $this->find_attachment_by_source_url($image_url);
        if ($shared_id) {
            set_post_thumbnail($product_id, $shared_id);
            RWBE_Debug_Logger::log('Featured image reused from existing attachment', ['product_id' => $product_id, 'attachment_id' => $shared_id]);
            return true;
        }

        // Required for download_url() and media_handle_sideload()
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        RWBE_Debug_Logger::log('Downloading image', ['url' => $image_url]);
        
        // Download the image
        $temp_file = download_url($image_url);
        
        if (is_wp_error($temp_file)) {
            $error_message = $temp_file->get_error_message();
            RWBE_Debug_Logger::log('Error downloading image', [
                'product_id' => $product_id, 
                'image_url' => $image_url,
                'error' => $error_message
            ]);
            return false;
        }
        
        RWBE_Debug_Logger::log('Image downloaded successfully', ['temp_file' => $temp_file]);

        // Placeholder detection: the API returns the same "no image" PNG under a
        // per-product URL, so it can only be recognised by its bytes. Reuse the one
        // shared attachment instead of importing a new copy per product.
        if (md5_file($temp_file) === self::PLACEHOLDER_MD5) {
            $placeholder_id = $this->get_or_create_placeholder_attachment($temp_file, 'rwb-placeholder.png');
            if ($placeholder_id) {
                set_post_thumbnail($product_id, $placeholder_id);
                RWBE_Debug_Logger::log('Featured image is placeholder, reused shared attachment', ['product_id' => $product_id, 'attachment_id' => $placeholder_id]);
                return true;
            }
            return false;
        }

        // Get the filename and extension
        $filename = basename($image_url);
        
        // Verificar se o nome do arquivo tem uma extensão válida
        $valid_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $file_extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (empty($file_extension) || !in_array($file_extension, $valid_extensions)) {
            // Adicionar uma extensão padrão se não houver uma válida
            $filename .= '.jpg';
            RWBE_Debug_Logger::log('Added default extension to filename', ['new_filename' => $filename]);
        }
        
        // Prepare an array of post data for the attachment
        $file_array = array(
            'name' => $filename,
            'tmp_name' => $temp_file
        );
        
        RWBE_Debug_Logger::log('Processing image upload', ['filename' => $filename]);
        
        // Do the validation and storage stuff
        $attachment_id = media_handle_sideload($file_array, $product_id);
        
        // If error storing permanently, unlink
        if (is_wp_error($attachment_id)) {
            $error_message = $attachment_id->get_error_message();
            RWBE_Debug_Logger::log('Error processing image', [
                'product_id' => $product_id,
                'error' => $error_message
            ]);
            @unlink($temp_file);
            return false;
        }
        
        RWBE_Debug_Logger::log('Image processed successfully', ['attachment_id' => $attachment_id]);

        // Remember the source URL so future products/re-imports reuse this attachment
        update_post_meta($attachment_id, '_rwbe_source_url', $image_url);
        $this->image_source_cache[$image_url] = $attachment_id;

        // Set as featured image
        $result = set_post_thumbnail($product_id, $attachment_id);
        
        if ($result) {
            RWBE_Debug_Logger::log('Featured image set successfully', [
                'product_id' => $product_id,
                'attachment_id' => $attachment_id
            ]);
            return true;
        } else {
            RWBE_Debug_Logger::log('Failed to set featured image', [
                'product_id' => $product_id,
                'attachment_id' => $attachment_id
            ]);
            return false;
        }
    }

    /**
     * Ensure that the product group attribute exists in WooCommerce
     * This function creates the attribute if it doesn't exist
     * 
     * @return bool True if attribute exists or was created successfully, false otherwise
     */
    private function ensure_product_group_attribute_exists() {
        RWBE_Debug_Logger::log('Ensuring product group attribute exists');
        
        if (!function_exists('wc_get_attribute_taxonomies')) {
            RWBE_Debug_Logger::log('WooCommerce functions not available', ['function' => 'wc_get_attribute_taxonomies']);
            return false;
        }
        
        $attribute_name = 'pa_grupo';
        $attribute_label = 'Grupo';
        $attribute_slug = 'grupo';
        
        // Verificar se o atributo já existe
        $attribute_id = 0;
        $attribute_taxonomies = wc_get_attribute_taxonomies();
        
        foreach ($attribute_taxonomies as $taxonomy) {
            if ($taxonomy->attribute_name === $attribute_slug) {
                $attribute_id = $taxonomy->attribute_id;
                RWBE_Debug_Logger::log('Product group attribute already exists', ['attribute_id' => $attribute_id]);
                return true;
            }
        }
        
        RWBE_Debug_Logger::log('Product group attribute does not exist, creating it');
        
        // Criar o atributo
        $args = array(
            'name'         => $attribute_label,
            'slug'         => $attribute_slug,
            'type'         => 'select',
            'order_by'     => 'menu_order',
            'has_archives' => false
        );
        
        $result = wc_create_attribute($args);
        
        if (is_wp_error($result)) {
            RWBE_Debug_Logger::log('Error creating product group attribute', ['error' => $result->get_error_message()]);
            return false;
        }
        
        $attribute_id = $result;
        RWBE_Debug_Logger::log('Product group attribute created successfully', ['attribute_id' => $attribute_id]);
        
        // Forçar a atualização do cache de atributos do WooCommerce
        delete_transient('wc_attribute_taxonomies');
        
        // Registrar a taxonomia
        $taxonomy_args = array(
            'labels'       => array(
                'name' => $attribute_label,
            ),
            'hierarchical' => false,
            'show_ui'      => true,
            'query_var'    => true,
            'rewrite'      => false,
        );
        
        register_taxonomy($attribute_name, array('product'), $taxonomy_args);
        
        if (!taxonomy_exists($attribute_name)) {
            RWBE_Debug_Logger::log('Failed to register product group taxonomy');
            return false;
        }
        
        RWBE_Debug_Logger::log('Product group taxonomy registered successfully');
        
        // Limpar o cache de taxonomias
        delete_option('woocommerce_attribute_taxonomies');
        wp_cache_flush();
        
        return true;
    }
    
    /**
     * Ensure that the make attribute exists in WooCommerce
     * This function creates the attribute if it doesn't exist
     * 
     * @return bool True if attribute exists or was created successfully, false otherwise
     */
    private function ensure_make_attribute_exists() {
        RWBE_Debug_Logger::log('Ensuring make attribute exists');
        
        if (!function_exists('wc_get_attribute_taxonomies')) {
            RWBE_Debug_Logger::log('WooCommerce functions not available', ['function' => 'wc_get_attribute_taxonomies']);
            return false;
        }
        
        $attribute_name = 'pa_make';
        $attribute_label = 'Marca Veículo';
        $attribute_slug = 'make';
        
        // Verificar se o atributo já existe
        $attribute_id = 0;
        $attribute_taxonomies = wc_get_attribute_taxonomies();
        
        foreach ($attribute_taxonomies as $taxonomy) {
            if ($taxonomy->attribute_name === $attribute_slug) {
                $attribute_id = $taxonomy->attribute_id;
                RWBE_Debug_Logger::log('Make attribute already exists', ['attribute_id' => $attribute_id]);
                return true;
            }
        }
        
        RWBE_Debug_Logger::log('Make attribute does not exist, creating it');
        
        // Criar o atributo
        $args = array(
            'name'         => $attribute_label,
            'slug'         => $attribute_slug,
            'type'         => 'select',
            'order_by'     => 'menu_order',
            'has_archives' => false
        );
        
        $result = wc_create_attribute($args);
        
        if (is_wp_error($result)) {
            RWBE_Debug_Logger::log('Error creating make attribute', ['error' => $result->get_error_message()]);
            return false;
        }
        
        $attribute_id = $result;
        RWBE_Debug_Logger::log('Make attribute created successfully', ['attribute_id' => $attribute_id]);
        
        // Forçar a atualização do cache de atributos do WooCommerce
        delete_transient('wc_attribute_taxonomies');
        
        // Registrar a taxonomia
        $taxonomy_args = array(
            'labels'       => array(
                'name' => $attribute_label,
            ),
            'hierarchical' => false,
            'show_ui'      => true,
            'query_var'    => true,
            'rewrite'      => false,
        );
        
        register_taxonomy($attribute_name, array('product'), $taxonomy_args);
        
        if (!taxonomy_exists($attribute_name)) {
            RWBE_Debug_Logger::log('Failed to register make taxonomy');
            return false;
        }
        
        RWBE_Debug_Logger::log('Make taxonomy registered successfully');
        
        // Limpar o cache de taxonomias
        delete_option('woocommerce_attribute_taxonomies');
        wp_cache_flush();
        
        return true;
    }
    
    /**
     * Generate gallery image URLs based on product ID
     * 
     * @param string $product_id Product ID from API
     * @param int $max_images Maximum number of images to generate URLs for
     * @return array Array of gallery image URLs
     */
    private function generate_gallery_urls($product_id, $max_images = 5) {
        RWBE_Debug_Logger::log('Generating gallery URLs', ['product_id' => $product_id, 'max_images' => $max_images]);
        
        $gallery_urls = [];
        
        // URL da imagem principal (já deve estar sendo usada como imagem destacada)
        $main_image_url = 'https://portal.racewinningbrandseurope.com/apiv2/image/product/' . $product_id;
        
        // Gerar URLs para imagens adicionais da galeria
        for ($i = 1; $i <= $max_images; $i++) {
            $gallery_url = 'https://portal.racewinningbrandseurope.com/apiv2/image/product/' . $product_id . '/' . $i;
            $gallery_urls[] = $gallery_url;
        }
        
        RWBE_Debug_Logger::log('Generated gallery URLs', ['count' => count($gallery_urls), 'urls' => $gallery_urls]);
        
        return $gallery_urls;
    }
    
    /**
     * Ensure that the model attribute exists in WooCommerce
     * This function creates the attribute if it doesn't exist
     * 
     * @return bool True if attribute exists or was created successfully, false otherwise
     */
    private function ensure_model_attribute_exists() {
        RWBE_Debug_Logger::log('Ensuring model attribute exists');
        
        if (!function_exists('wc_get_attribute_taxonomies')) {
            RWBE_Debug_Logger::log('WooCommerce functions not available', ['function' => 'wc_get_attribute_taxonomies']);
            return false;
        }
        
        $attribute_name = 'pa_model';
        $attribute_label = 'Modelo Veículo';
        $attribute_slug = 'model';
        
        // Verificar se o atributo já existe
        $attribute_id = 0;
        $attribute_taxonomies = wc_get_attribute_taxonomies();
        
        foreach ($attribute_taxonomies as $taxonomy) {
            if ($taxonomy->attribute_name === $attribute_slug) {
                $attribute_id = $taxonomy->attribute_id;
                RWBE_Debug_Logger::log('Model attribute already exists', ['attribute_id' => $attribute_id]);
                return true;
            }
        }
        
        RWBE_Debug_Logger::log('Model attribute does not exist, creating it');
        
        // Criar o atributo
        $args = array(
            'name'         => $attribute_label,
            'slug'         => $attribute_slug,
            'type'         => 'select',
            'order_by'     => 'menu_order',
            'has_archives' => false
        );
        
        $result = wc_create_attribute($args);
        
        if (is_wp_error($result)) {
            RWBE_Debug_Logger::log('Error creating model attribute', ['error' => $result->get_error_message()]);
            return false;
        }
        
        $attribute_id = $result;
        RWBE_Debug_Logger::log('Model attribute created successfully', ['attribute_id' => $attribute_id]);
        
        // Forçar a atualização do cache de atributos do WooCommerce
        delete_transient('wc_attribute_taxonomies');
        
        // Registrar a taxonomia
        $taxonomy_args = array(
            'labels'       => array(
                'name' => $attribute_label,
            ),
            'hierarchical' => false,
            'show_ui'      => true,
            'query_var'    => true,
            'rewrite'      => false,
        );
        
        register_taxonomy($attribute_name, array('product'), $taxonomy_args);
        
        if (!taxonomy_exists($attribute_name)) {
            RWBE_Debug_Logger::log('Failed to register model taxonomy');
            return false;
        }
        
        RWBE_Debug_Logger::log('Model taxonomy registered successfully');
        
        // Limpar o cache de taxonomias
        delete_option('woocommerce_attribute_taxonomies');
        wp_cache_flush();
        
        return true;
    }
    
    /**
     * Extract product group name from product data
     *
     * @param array $product_data Product data from API
     * @return string Product group name or empty string if not found
     */
    private function extract_product_group($product_data) {
        RWBE_Debug_Logger::log('Extracting product group from product data', ['product_data_keys' => array_keys($product_data)]);
        
        // Verificar a estrutura padrão (group.title)
        if (!empty($product_data['group']) && is_array($product_data['group']) && !empty($product_data['group']['title'])) {
            $group_name = sanitize_text_field($product_data['group']['title']);
            RWBE_Debug_Logger::log('Found product group in standard structure', ['group_name' => $group_name]);
            return $group_name;
        }
        
        // Verificar estrutura alternativa (group como string)
        if (!empty($product_data['group']) && is_string($product_data['group'])) {
            $group_name = sanitize_text_field($product_data['group']);
            RWBE_Debug_Logger::log('Found product group as direct string', ['group_name' => $group_name]);
            return $group_name;
        }
        
        // Verificar outras possíveis estruturas
        $possible_group_keys = ['group_name', 'groupName', 'groupTitle', 'group_title', 'category', 'productGroup'];
        
        foreach ($possible_group_keys as $key) {
            if (!empty($product_data[$key])) {
                $group_name = sanitize_text_field($product_data[$key]);
                RWBE_Debug_Logger::log('Found product group in alternative key', ['key' => $key, 'group_name' => $group_name]);
                return $group_name;
            }
        }
        
        RWBE_Debug_Logger::log('No product group found in product data');
        return '';
    }
    
    /**
     * Set product group attribute for a product
     *
     * @param int $product_id Product ID
     * @param string $group_name Group name
     * @return bool True on success, false on failure
     */
    private function set_product_group($product_id, $group_name) {
        RWBE_Debug_Logger::log('Starting product group configuration', ['product_id' => $product_id, 'group_name' => $group_name]);
        
        if (empty($group_name)) {
            RWBE_Debug_Logger::log('Empty group name, skipping product group configuration', ['product_id' => $product_id]);
            return false;
        }
        
        $attribute_name = 'pa_grupo';
        
        // Verificar se a taxonomia existe
        if (!taxonomy_exists($attribute_name)) {
            RWBE_Debug_Logger::log('Product group taxonomy does not exist, trying to create it', ['attribute_name' => $attribute_name]);
            
            // Tentar criar o atributo e a taxonomia usando a classe de ajuda
            if (!RWBE_Generic_Attribute_Helper::ensure_attribute_exists('grupo', 'Grupo')) {
                RWBE_Debug_Logger::log('Failed to create product group attribute, cannot continue', ['attribute_name' => $attribute_name]);
                return false;
            }
        }
        
        // Resolver/criar o termo (com cache em memória por execução)
        $term_id = $this->get_or_create_term_id($group_name, $attribute_name);
        if (!$term_id) {
            RWBE_Debug_Logger::log('Could not resolve product group term', ['group_name' => $group_name]);
            return false;
        }

        // Definir o termo para o produto
        $set_terms_result = wp_set_object_terms($product_id, $term_id, $attribute_name);
        
        if (is_wp_error($set_terms_result)) {
            RWBE_Debug_Logger::log('Error setting product group term for product', [
                'product_id' => $product_id,
                'term_id' => $term_id,
                'error' => $set_terms_result->get_error_message()
            ]);
            return false;
        }
        
        RWBE_Debug_Logger::log('Product group term set successfully for product', ['product_id' => $product_id, 'term_id' => $term_id]);
        
        // Queue the group attribute; written once per product via flush_product_attributes()
        $this->queue_product_attribute($attribute_name, 1, true);
        
        return true;
    }
    
    /**
     * Fill the fitment table for products imported before it existed.
     *
     * Products already in the catalogue have make/model/year attributes but no
     * fitment rows, because the pairing was never stored. Rebuilding it needs the
     * API's application payload, which only the product-detail endpoint returns —
     * so this walks the catalogue in batches and asks for it, exactly the way a
     * normal import does, but without touching the products themselves.
     *
     * One batch per call; schedule_fitment_backfill() chains the batches.
     *
     * @param int $batch_size Products to process in this call.
     * @return array {
     *     @type int $processed Products looked at.
     *     @type int $written   Fitment rows written.
     *     @type int $remaining Products still without fitment rows.
     *     @type bool $done     True when nothing is left.
     * }
     */
    public function backfill_fitment_batch($batch_size = 200) {
        global $wpdb;

        if (!class_exists('RWBE_Fitment')) {
            return array('processed' => 0, 'written' => 0, 'remaining' => 0, 'done' => true);
        }
        if (!rwbe_has_api_token()) {
            RWBE_Debug_Logger::log('Fitment backfill aborted: no API token configured');
            return array('processed' => 0, 'written' => 0, 'remaining' => 0, 'done' => true);
        }
        RWBE_Fitment::maybe_install();

        $batch_size = max(1, min(500, (int) $batch_size));
        $checked    = RWBE_Fitment::META_CHECKED;

        // Published products with a RWBE id whose application data has not been read
        // yet. Keyed off the marker meta, not off the presence of fitment rows: many
        // parts are universal and correctly produce no rows at all.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID AS post_id, pm.meta_value AS rwbe_id
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_rwbe_product_id'
             LEFT JOIN {$wpdb->postmeta} done ON done.post_id = p.ID AND done.meta_key = %s
             WHERE p.post_type = 'product' AND p.post_status = 'publish'
               AND pm.meta_value <> ''
               AND done.post_id IS NULL
             GROUP BY p.ID, pm.meta_value
             LIMIT %d",
            $checked,
            $batch_size
        ));

        if (empty($rows)) {
            update_option('rwbe_fitment_backfill_state', array(
                'running' => false, 'processed' => 0, 'updated_at' => time(),
            ), false);
            RWBE_Fitment::flush_coverage_cache();
            return array('processed' => 0, 'written' => 0, 'remaining' => 0, 'done' => true);
        }

        $endpoint = defined('RWBE_API_ENDPOINT_PRODUCT_DETAIL')
            ? RWBE_API_ENDPOINT_PRODUCT_DETAIL
            : 'https://portal.racewinningbrandseurope.com/apiv2/products/product';

        $headers = array(
            'Authorization' => 'Bearer ' . rwbe_get_api_token(),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-cache',
        );

        $processed = 0;
        $written   = 0;

        // Same concurrency the importer uses for detail fetches.
        foreach (array_chunk($rows, 10) as $chunk) {
            $requests = array();
            foreach ($chunk as $i => $row) {
                $url = add_query_arg(array('product_id' => $row->rwbe_id), $endpoint);
                $url = add_query_arg('_nocache', self::cache_buster(), $url);
                $requests[$i] = array(
                    'url' => $url,
                    'headers' => $headers,
                    'type' => 'GET',
                    'options' => array('timeout' => 60),
                );
            }

            $responses = $this->request_multiple_compat($requests, 60);

            foreach ($chunk as $i => $row) {
                $processed++;
                $resp = isset($responses[$i]) ? $responses[$i] : null;
                $code = (is_object($resp) && isset($resp->status_code)) ? intval($resp->status_code) : 0;
                $body = (is_object($resp) && isset($resp->body)) ? $resp->body : '';

                if ($code !== 200 || $body === '') {
                    RWBE_Debug_Logger::log('Fitment backfill: detail fetch failed', array(
                        'post_id' => $row->post_id, 'rwbe_id' => $row->rwbe_id, 'code' => $code,
                    ));
                    continue;
                }

                $detail = json_decode($body, true);
                if (!is_array($detail)) {
                    continue;
                }
                // The endpoint may wrap the product in a single-item list.
                if (isset($detail[0]) && is_array($detail[0])) {
                    $detail = $detail[0];
                }

                $application = $this->extract_application_data($detail);
                $written += RWBE_Fitment::replace_for_product($row->post_id, $application['fitment']);
                RWBE_Fitment::mark_checked($row->post_id);
            }
        }

        RWBE_Fitment::flush_coverage_cache();

        $remaining = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_rwbe_product_id'
             LEFT JOIN {$wpdb->postmeta} done ON done.post_id = p.ID AND done.meta_key = %s
             WHERE p.post_type = 'product' AND p.post_status = 'publish'
               AND pm.meta_value <> '' AND done.post_id IS NULL",
            $checked
        ));

        RWBE_Debug_Logger::log('Fitment backfill batch done', array(
            'processed' => $processed, 'written' => $written, 'remaining' => $remaining,
        ));

        return array(
            'processed' => $processed,
            'written'   => $written,
            'remaining' => $remaining,
            'done'      => $remaining === 0,
        );
    }

    /**
     * Cron entry point: run one backfill batch and queue the next one.
     *
     * @return void
     */
    public function run_fitment_backfill() {
        $state = get_option('rwbe_fitment_backfill_state', array());
        if (empty($state['running'])) {
            return; // Stopped from the admin screen.
        }

        $result = $this->backfill_fitment_batch(isset($state['batch_size']) ? $state['batch_size'] : 200);

        $state['processed']  = (isset($state['processed']) ? (int) $state['processed'] : 0) + $result['processed'];
        $state['remaining']  = $result['remaining'];
        $state['updated_at'] = time();
        $state['running']    = !$result['done'];
        update_option('rwbe_fitment_backfill_state', $state, false);

        if (!$result['done']) {
            wp_schedule_single_event(time() + 60, 'rwbe_fitment_backfill');
        } elseif (class_exists('RWBE_Vehicle_Map')) {
            // Everything is in: switch the dropdowns over to the fitment data.
            RWBE_Vehicle_Map::rebuild(true);
        }
    }

    /**
     * Persist the vehicle fitment rows for a product.
     *
     * Writes to wp_rwbe_fitment, which keeps make + model + year range together.
     * Failures are logged and swallowed: fitment is an addition, never a reason to
     * abort an import that is otherwise fine.
     *
     * @param int   $product_id
     * @param array $application_data Output of extract_application_data()
     * @return void
     */
    private function store_product_fitment($product_id, $application_data) {
        if (!class_exists('RWBE_Fitment')) {
            return;
        }

        $rows = isset($application_data['fitment']) ? $application_data['fitment'] : array();

        try {
            $written = RWBE_Fitment::replace_for_product($product_id, $rows);
            // Mark the product as read even when it has no applications, so the
            // backfill never asks the API for it again.
            RWBE_Fitment::mark_checked($product_id);
            RWBE_Debug_Logger::log('Fitment rows stored', [
                'product_id' => $product_id,
                'rows' => $written,
            ]);
        } catch (Exception $e) {
            RWBE_Debug_Logger::log('Failed to store fitment rows', [
                'product_id' => $product_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Extract application data from product data
     *
     * @param array $product_data Product data from API
     * @return array Array with make, model, and years information or empty array if not found
     */
    private function extract_application_data($product_data) {
        RWBE_Debug_Logger::log('Extracting application data from product data', ['product_data_keys' => array_keys($product_data)]);
        
        $result = [
            'makes' => [],
            'models' => [],
            'years' => [],
            // Combinações intactas (marca + modelo + intervalo de anos) tal como a
            // API as envia. As três listas acima achatam esta informação e perdem o
            // emparelhamento; 'fitment' preserva-o para a tabela wp_rwbe_fitment.
            'fitment' => []
        ];

        // Verificar se há dados de aplicação
        if (empty($product_data['application']) || !is_array($product_data['application'])) {
            RWBE_Debug_Logger::log('No application data found in product data');
            return $result;
        }

        // Processar cada aplicação
        foreach ($product_data['application'] as $application) {
            // Verificar se a aplicação é um array válido
            if (!is_array($application)) {
                RWBE_Debug_Logger::log('Invalid application data format', ['application' => $application]);
                continue;
            }

            // Guardar a combinação desta aplicação antes de a achatar
            $fit_make  = !empty($application['make']) ? sanitize_text_field($application['make']) : '';
            $fit_model = !empty($application['model']) ? sanitize_text_field($application['model']) : '';
            if ($fit_make !== '' || $fit_model !== '') {
                $fit_from = isset($application['beginYear']) ? intval($application['beginYear']) : 0;
                $fit_to   = isset($application['endYear']) ? intval($application['endYear']) : 0;
                if ($fit_from < 0) { $fit_from = 0; }
                if ($fit_to < 0) { $fit_to = 0; }
                if ($fit_from > 0 && $fit_to === 0) { $fit_to = $fit_from; }
                if ($fit_to > 0 && $fit_from === 0) { $fit_from = $fit_to; }
                $result['fitment'][] = [
                    'make'      => $fit_make,
                    'model'     => $fit_model,
                    'year_from' => $fit_from,
                    'year_to'   => $fit_to,
                ];
            }

            // Extrair marca (make)
            if (!empty($application['make'])) {
                $make = sanitize_text_field($application['make']);
                if (!in_array($make, $result['makes'])) {
                    $result['makes'][] = $make;
                }
            }
            
            // Extrair modelo (model)
            if (!empty($application['model'])) {
                $model = sanitize_text_field($application['model']);
                if (!in_array($model, $result['models'])) {
                    $result['models'][] = $model;
                }
            }
            
            // Extrair anos individuais de beginYear e endYear
            if (isset($application['beginYear']) && isset($application['endYear'])) {
                $begin_year = intval($application['beginYear']);
                $end_year = intval($application['endYear']);
                
                // Verificar se os anos são válidos e gerar anos individuais
                if ($begin_year > 0 && $end_year >= $begin_year && ($end_year - $begin_year) <= 100) {
                    for ($year = $begin_year; $year <= $end_year; $year++) {
                        $year_str = (string) $year;
                        if (!in_array($year_str, $result['years'])) {
                            $result['years'][] = $year_str;
                        }
                    }
                }
            }
        }
        
        // Ordenar anos
        sort($result['years'], SORT_NUMERIC);
        
        RWBE_Debug_Logger::log('Application data extracted', [
            'makes_count' => count($result['makes']),
            'models_count' => count($result['models']),
            'years_count' => count($result['years']),
            'fitment_count' => count($result['fitment']),
            'makes' => $result['makes'],
            'models' => $result['models'],
            'years' => $result['years']
        ]);
        
        return $result;
    }
    
    /**
     * Set product make attribute
     *
     * @param int $product_id Product ID
     * @param array $makes Array of make names
     * @return bool True on success, false on failure
     */
    private function set_product_makes($product_id, $makes) {
        if (empty($makes)) {
            RWBE_Debug_Logger::log('No makes to set for product', ['product_id' => $product_id]);
            return false;
        }
        
        RWBE_Debug_Logger::log('Setting product makes', ['product_id' => $product_id, 'makes' => $makes]);
        
        $attribute_name = 'pa_make';
        
        // Verificar se a taxonomia existe
        if (!taxonomy_exists($attribute_name)) {
            RWBE_Debug_Logger::log('Make taxonomy does not exist, trying to create it', ['attribute_name' => $attribute_name]);
            
            // Tentar criar o atributo e a taxonomia usando a classe de ajuda
            if (!RWBE_Generic_Attribute_Helper::ensure_attribute_exists('make', 'Marca Veículo')) {
                RWBE_Debug_Logger::log('Failed to create make attribute, cannot continue', ['attribute_name' => $attribute_name]);
                return false;
            }
        }
        
        $term_ids = [];
        
        // Processar cada marca (resolução com cache em memória por execução)
        foreach ($makes as $make) {
            $term_id = $this->get_or_create_term_id($make, $attribute_name);
            if ($term_id) {
                $term_ids[] = $term_id;
            }
        }
        
        if (empty($term_ids)) {
            RWBE_Debug_Logger::log('No make terms to set for product', ['product_id' => $product_id]);
            return false;
        }
        
        // Definir os termos para o produto
        $set_terms_result = wp_set_object_terms($product_id, $term_ids, $attribute_name);
        
        if (is_wp_error($set_terms_result)) {
            RWBE_Debug_Logger::log('Error setting make terms for product', [
                'product_id' => $product_id,
                'error' => $set_terms_result->get_error_message()
            ]);
            return false;
        }
        
        RWBE_Debug_Logger::log('Make terms set successfully for product', ['product_id' => $product_id, 'term_ids' => $term_ids]);
        
        // Queue the make attribute; written once per product via flush_product_attributes()
        $this->queue_product_attribute($attribute_name, 2, true);
        return true;
    }
    
    /**
     * Set product model attribute
     *
     * @param int $product_id Product ID
     * @param array $models Array of model names
     * @return bool True on success, false on failure
     */
    private function set_product_models($product_id, $models) {
        if (empty($models)) {
            RWBE_Debug_Logger::log('No models to set for product', ['product_id' => $product_id]);
            return false;
        }
        
        RWBE_Debug_Logger::log('Setting product models', ['product_id' => $product_id, 'models' => $models]);
        
        $attribute_name = 'pa_model';
        
        // Verificar se a taxonomia existe
        if (!taxonomy_exists($attribute_name)) {
            RWBE_Debug_Logger::log('Model taxonomy does not exist, trying to create it', ['attribute_name' => $attribute_name]);
            
            // Tentar criar o atributo e a taxonomia usando a classe de ajuda
            if (!RWBE_Generic_Attribute_Helper::ensure_attribute_exists('model', 'Modelo Veículo')) {
                RWBE_Debug_Logger::log('Failed to create model attribute, cannot continue', ['attribute_name' => $attribute_name]);
                return false;
            }
        }
        
        $term_ids = [];
        
        // Processar cada modelo (resolução com cache em memória por execução)
        foreach ($models as $model) {
            $term_id = $this->get_or_create_term_id($model, $attribute_name);
            if ($term_id) {
                $term_ids[] = $term_id;
            }
        }
        
        if (empty($term_ids)) {
            RWBE_Debug_Logger::log('No model terms to set for product', ['product_id' => $product_id]);
            return false;
        }
        
        // Definir os termos para o produto
        $set_terms_result = wp_set_object_terms($product_id, $term_ids, $attribute_name);
        
        if (is_wp_error($set_terms_result)) {
            RWBE_Debug_Logger::log('Error setting model terms for product', [
                'product_id' => $product_id,
                'error' => $set_terms_result->get_error_message()
            ]);
            return false;
        }
        
        RWBE_Debug_Logger::log('Model terms set successfully for product', ['product_id' => $product_id, 'term_ids' => $term_ids]);
        
        // Queue the model attribute; written once per product via flush_product_attributes()
        $this->queue_product_attribute($attribute_name, 3, true);
        return true;
    }
    
    /**
     * Set product year attribute (individual years)
     *
     * @param int $product_id Product ID
     * @param array $years Array of individual years
     * @return bool True on success, false on failure
     */
    private function set_product_years($product_id, $years) {
        if (empty($years)) {
            RWBE_Debug_Logger::log('No years to set for product', ['product_id' => $product_id]);
            return false;
        }
        
        RWBE_Debug_Logger::log('Setting product years', ['product_id' => $product_id, 'years' => $years]);
        
        $attribute_name = 'pa_vehicle_year';
        
        // Verificar se a taxonomia existe
        if (!taxonomy_exists($attribute_name)) {
            RWBE_Debug_Logger::log('Year taxonomy does not exist, trying to create it', ['attribute_name' => $attribute_name]);
            
            // Tentar criar o atributo e a taxonomia usando a classe de ajuda
            if (!RWBE_Generic_Attribute_Helper::ensure_attribute_exists('vehicle_year', 'Ano')) {
                RWBE_Debug_Logger::log('Failed to create year attribute, cannot continue', ['attribute_name' => $attribute_name]);
                return false;
            }
        }
        
        $term_ids = [];
        
        // Processar cada ano individual (resolução com cache em memória por execução)
        foreach ($years as $year) {
            $year_str = (string) $year;
            $term_id = $this->get_or_create_term_id($year_str, $attribute_name);
            if ($term_id) {
                $term_ids[] = $term_id;
            }
        }
        
        if (empty($term_ids)) {
            RWBE_Debug_Logger::log('No year terms to set for product', ['product_id' => $product_id]);
            return false;
        }
        
        // Definir os termos para o produto
        $set_terms_result = wp_set_object_terms($product_id, $term_ids, $attribute_name);
        
        if (is_wp_error($set_terms_result)) {
            RWBE_Debug_Logger::log('Error setting year terms for product', [
                'product_id' => $product_id,
                'error' => $set_terms_result->get_error_message()
            ]);
            return false;
        }
        
        RWBE_Debug_Logger::log('Year terms set successfully for product', ['product_id' => $product_id, 'term_ids' => $term_ids]);
        
        // Queue the year attribute; written once per product via flush_product_attributes()
        $this->queue_product_attribute($attribute_name, 4, true);
        return true;
    }
    
    /**
     * Extract technical attributes from product data
     *
     * @param array $product_data Product data from API
     * @return array Array of technical attributes or empty array if not found
     */
    private function extract_technical_attributes($product_data) {
        RWBE_Debug_Logger::log('Extracting technical attributes from product data', ['product_data_keys' => array_keys($product_data)]);
        
        $attributes = [];
        
        // Verificar se há dados de atributos técnicos
        if (empty($product_data['attributes'])) {
            RWBE_Debug_Logger::log('No technical attributes found in product data');
            return $attributes;
        }
        
        // Verificar formato dos atributos
        if (!is_array($product_data['attributes'])) {
            RWBE_Debug_Logger::log('Technical attributes is not an array', ['type' => gettype($product_data['attributes'])]);
            return $attributes;
        }
        
        // Processar cada atributo técnico
        foreach ($product_data['attributes'] as $attribute) {
            // Verificar formato do atributo
            if (!is_array($attribute)) {
                RWBE_Debug_Logger::log('Invalid attribute format', ['attribute' => $attribute]);
                continue;
            }
            
            // Obter rótulo (usar 'title' do endpoint; fallback para 'name')
            $label = '';
            if (!empty($attribute['title'])) {
                $label = sanitize_text_field($attribute['title']);
            } elseif (!empty($attribute['name'])) {
                $label = sanitize_text_field($attribute['name']);
            }
            if ($label === '') {
                continue;
            }

            // Obter valor e unidade e concatenar quando existir
            $value = isset($attribute['value']) ? sanitize_text_field($attribute['value']) : '';
            $unit  = !empty($attribute['unit']) ? sanitize_text_field($attribute['unit']) : '';
            $value_with_unit = trim($value . ($unit !== '' ? ' ' . $unit : ''));

            if ($value_with_unit === '') {
                continue;
            }

            $attributes[$label] = $value_with_unit;
        }
        
        RWBE_Debug_Logger::log('Technical attributes extracted', [
            'attributes_count' => count($attributes),
            'attributes' => $attributes
        ]);
        
        return $attributes;
    }
    
    /**
     * Set product technical attributes
     *
     * @param int $product_id Product ID
     * @param array $technical_attributes Array of technical attributes
     * @return bool True on success, false on failure
     */
    private function set_product_technical_attributes($product_id, $technical_attributes) {
        if (empty($technical_attributes)) {
            RWBE_Debug_Logger::log('No technical attributes to set for product', ['product_id' => $product_id]);
            return false;
        }
        
        RWBE_Debug_Logger::log('Setting product technical attributes', ['product_id' => $product_id, 'attributes' => $technical_attributes]);
        
        // Queue technical attributes; written once per product via flush_product_attributes()
        foreach ($technical_attributes as $name => $value) {
            $this->queue_product_attribute($name, 5, false, $value);
        }
        return true;
    }
    
    /**
     * Extract brand name from product data
     *
     * @param array $product_data Product data from API
     * @return string Brand name or empty string if not found
     */
    private function extract_brand_name($product_data) {
        RWBE_Debug_Logger::log('Extracting brand name from product data', ['product_data_keys' => array_keys($product_data)]);
        
        // Verificar a estrutura padrão (brand.title)
        if (!empty($product_data['brand']) && is_array($product_data['brand']) && !empty($product_data['brand']['title'])) {
            $brand_name = sanitize_text_field($product_data['brand']['title']);
            RWBE_Debug_Logger::log('Found brand name in standard structure', ['brand_name' => $brand_name]);
            return $brand_name;
        }
        
        // Verificar estrutura alternativa (brand como string)
        if (!empty($product_data['brand']) && is_string($product_data['brand'])) {
            $brand_name = sanitize_text_field($product_data['brand']);
            RWBE_Debug_Logger::log('Found brand name as direct string', ['brand_name' => $brand_name]);
            return $brand_name;
        }
        
        // Verificar outras possíveis estruturas
        $possible_brand_keys = ['brand_name', 'brandName', 'brandTitle', 'brand_title', 'manufacturer'];
        
        foreach ($possible_brand_keys as $key) {
            if (!empty($product_data[$key])) {
                $brand_name = sanitize_text_field($product_data[$key]);
                RWBE_Debug_Logger::log('Found brand name in alternative key', ['key' => $key, 'brand_name' => $brand_name]);
                return $brand_name;
            }
        }
        
        // Verificar se há alguma chave que contenha 'brand' no nome
        foreach ($product_data as $key => $value) {
            if (stripos($key, 'brand') !== false && is_string($value) && !empty($value)) {
                $brand_name = sanitize_text_field($value);
                RWBE_Debug_Logger::log('Found brand name in key containing "brand"', ['key' => $key, 'brand_name' => $brand_name]);
                return $brand_name;
            }
        }
        
        RWBE_Debug_Logger::log('No brand name found in product data');
        return '';
    }

    /**
     * Check for interrupted imports and resume them if needed
     */
    public function check_and_resume_interrupted_imports() {
        // This watchdog fires every two minutes, forever. It used to log on entry and
        // again on exit whether or not there was anything to do, which on a quiet site
        // is ~2800 log entries a day of pure noise — enough to keep rotating the log
        // and pushing out the import diagnostics that are actually worth keeping.
        // Only real decisions are logged now.
        $progress = get_option('rwbe_import_progress');

        if (!$progress) {
            return;
        }
        
        $last_timestamp = isset($progress['timestamp']) ? intval($progress['timestamp']) : 0;
        $current_time = time();
        $time_diff = $current_time - $last_timestamp;
        $status = isset($progress['status']) ? $progress['status'] : '';
        $retry_after = isset($progress['retry_after']) ? intval($progress['retry_after']) : 0;

        // Honour an explicit "Parar" even if the request died mid-page and left the
        // status stuck at 'in_progress'/'processing_products'. Without this, the
        // watchdog would see a stale in-progress import and resume what the user
        // just stopped. Auto-resume still kicks in once the grace window passes.
        if ($this->is_stop_requested() && $time_diff <= $this->pause_auto_resume_seconds()) {
            RWBE_Debug_Logger::log('Stop requested by user, not resuming (within grace)', ['time_diff' => $time_diff]);
            return;
        }

        // Verificar se é hora de retomar uma importação que falhou por erro de conexão
        if ($status === 'connection_failed' && $current_time >= $retry_after) {
            RWBE_Debug_Logger::log('Resuming import after connection failure', [
                'last_update' => $last_timestamp,
                'time_diff' => $time_diff,
                'skip' => isset($progress['skip']) ? $progress['skip'] : 0
            ]);
            
            // Iniciar a importação resiliente
            $this->import_products_with_resilience(true);
            return;
        }
        
        // Verificar se uma importação pausada por limite de tempo pode ser retomada
        if ($status === 'paused_time_limit') {
            RWBE_Debug_Logger::log('Resuming import that was paused due to time limit', [
                'last_update' => $last_timestamp,
                'time_diff' => $time_diff,
                'skip' => isset($progress['skip']) ? $progress['skip'] : 0
            ]);

            // Iniciar a importação resiliente
            $this->import_products_with_resilience(true);
            return;
        }

        // Pausa MANUAL do utilizador: respeitar a pausa, mas retomar automaticamente
        // após o limiar configurado (default 3h) para não deixar as sincronizações
        // agendadas paradas indefinidamente se ninguém clicar em "Retomar".
        if ($status === 'paused_by_user') {
            $auto_resume_after = $this->pause_auto_resume_seconds();
            if ($time_diff > $auto_resume_after) {
                RWBE_Debug_Logger::log('Auto-resuming user-paused import after threshold', [
                    'last_update' => $last_timestamp,
                    'time_diff' => $time_diff,
                    'threshold' => $auto_resume_after,
                    'skip' => isset($progress['skip']) ? $progress['skip'] : 0
                ]);
                $this->import_products_with_resilience(true);
            }
            return;
        }

        // Verificar se uma importação em andamento está demorando muito para atualizar o status
        // Reduzimos para 3 minutos para ser mais agressivo na retomada
        if (($status === 'in_progress' || $status === 'processing_products' || $status === 'retrying_connection') && $time_diff > 180) { // 3 minutos
            RWBE_Debug_Logger::log('Found interrupted import, resuming automatically', [
                'last_update' => $last_timestamp,
                'time_diff' => $time_diff,
                'status' => $status,
                'skip' => isset($progress['skip']) ? $progress['skip'] : 0,
                'last_activity' => isset($progress['last_activity']) ? $progress['last_activity'] : 'Unknown'
            ]);
            
            // Iniciar a importação resiliente
            $this->import_products_with_resilience(true);
            return;
        }
        
        // Nothing to resume. Deliberately silent — see the note at the top.
    }
    
    /**
     * Check, bypassing the per-request options cache, whether the user has
     * requested a stop via the live dashboard ("Parar" button).
     *
     * The import loop runs synchronously inside a long-lived request, so a plain
     * get_option() would return the value cached at the start of that request and
     * never see the 'paused_by_user' flag written by the concurrent stop AJAX
     * request. We therefore read the option value straight from the DB.
     *
     * @return bool True if the persisted status is 'paused_by_user'
     */
    private function user_requested_stop() {
        global $wpdb;
        // The stop request lives in its OWN option, which the import loop never
        // writes. Previously the flag rode inside 'rwbe_import_progress', but the
        // loop rewrites that option every few products with 'in_progress' /
        // 'processing_products' — clobbering the pause before this check could
        // read it, so the import ignored "Parar" and the watchdog then resumed it.
        $val = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                self::STOP_FLAG_OPTION
            )
        );
        return !empty($val) && $val !== '0';
    }

    /**
     * Persist that the user asked to stop. Written by the stop AJAX handler; read
     * by user_requested_stop() inside the loop and honoured by the cron guards.
     */
    public function request_stop() {
        update_option(self::STOP_FLAG_OPTION, '1', false);
    }

    /**
     * Clear the stop flag. Called when a run starts/resumes and after a pause is
     * persisted, so a stale flag never instantly kills the next run.
     */
    private function clear_stop_flag() {
        delete_option(self::STOP_FLAG_OPTION);
    }

    /**
     * Whether a stop is currently requested (direct DB read, cache-safe).
     */
    private function is_stop_requested() {
        global $wpdb;
        $val = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                self::STOP_FLAG_OPTION
            )
        );
        return !empty($val) && $val !== '0';
    }

    /**
     * MySQL advisory lock name guarding the whole import. A single connection
     * (i.e. a single request) may hold it at a time, so overlapping runs
     * (manual button + import cron + interrupted-import watchdog) cannot process
     * the same offset concurrently and create duplicate products.
     */
    const IMPORT_LOCK_NAME = 'rwbe_product_import';

    /**
     * Option holding the user's "Parar" request. Kept separate from
     * 'rwbe_import_progress' so the import loop (which constantly rewrites the
     * progress option) can never clobber it. See user_requested_stop().
     */
    const STOP_FLAG_OPTION = 'rwbe_import_stop_requested';

    /**
     * md5 of the RWB "no image" placeholder PNG the API returns for products
     * without a real photo. Each product gets a distinct source URL
     * (.../image/product/{ID}/{n}) that resolves to identical bytes, so the
     * URL-based dedup never merges them and a fresh copy is imported every time.
     * We detect it by content hash after download and reuse a single shared
     * attachment instead (see get_or_create_placeholder_attachment()).
     */
    const PLACEHOLDER_MD5 = '05aefa99c9870be09a77d9dd68b4c55a';

    /**
     * Byte size of the original placeholder PNG. Used only as a cheap pre-filter
     * before hashing during the bulk cleanup, so non-placeholder images are
     * skipped without reading their bytes.
     */
    const PLACEHOLDER_SIZE = 11137;

    /**
     * WordPress option holding the ID of the one canonical placeholder attachment.
     */
    const PLACEHOLDER_OPTION = 'rwbe_placeholder_attachment_id';

    /**
     * MySQL advisory lock guarding the placeholder cleanup. The work can be driven
     * by the admin page and by the cron worker at the same time; the lock makes
     * sure only one of them processes a batch window at a time.
     */
    const CLEANUP_LOCK_NAME = 'rwbe_placeholder_cleanup';

    /**
     * Option holding the progress of the placeholder cleanup, so the run survives
     * the browser: closing the tab (or switching window) does not stop it, and
     * reopening the page picks the progress bar back up where it was.
     */
    const CLEANUP_STATE_OPTION = 'rwbe_ph_cleanup_state';

    /**
     * Option holding the user's "Parar" request for the cleanup. Kept apart from
     * the state option, which the worker rewrites after every batch.
     */
    const CLEANUP_STOP_OPTION = 'rwbe_ph_cleanup_stop_requested';

    /**
     * Try to acquire the import advisory lock without blocking.
     *
     * Uses MySQL GET_LOCK(), which is scoped to the DB connection and released
     * automatically when the connection closes. That means a crashed or timed-out
     * import request never leaves a stale lock behind — the next run just acquires
     * it cleanly, unlike an option/transient flag which would need a TTL.
     *
     * @return bool True if this process now holds the lock, false if another does.
     */
    private function acquire_import_lock() {
        return $this->acquire_lock(self::IMPORT_LOCK_NAME);
    }

    /**
     * Try to take a named MySQL advisory lock without blocking.
     *
     * @param string $name Lock name.
     * @return bool True if this process now holds it, false if another does.
     */
    private function acquire_lock($name) {
        global $wpdb;
        // Timeout 0 => return immediately: 1 acquired, 0 held elsewhere, NULL on error.
        $got = $wpdb->get_var(
            $wpdb->prepare("SELECT GET_LOCK(%s, 0)", $name)
        );
        return $got === '1' || $got === 1;
    }

    /**
     * Release a named MySQL advisory lock held by this connection.
     *
     * @param string $name Lock name.
     */
    private function release_lock($name) {
        global $wpdb;
        $wpdb->get_var(
            $wpdb->prepare("SELECT RELEASE_LOCK(%s)", $name)
        );
    }

    /**
     * Release the import advisory lock held by this connection.
     */
    private function release_import_lock() {
        $this->release_lock(self::IMPORT_LOCK_NAME);
    }

    /**
     * Seconds a manual ("Parar") pause is honoured before the cron/watchdog
     * auto-resumes it. Default 3h. Filterable via 'rwbe_pause_auto_resume_seconds'
     * so the threshold can be tuned without editing core logic.
     *
     * @return int
     */
    private function pause_auto_resume_seconds() {
        $seconds = (int) apply_filters('rwbe_pause_auto_resume_seconds', 3 * HOUR_IN_SECONDS);
        // Nunca abaixo de 5 min, para uma pausa manual ter sempre efeito prático.
        return max(300, $seconds);
    }

    /**
     * Persist a user-requested pause so the import can be resumed later.
     *
     * @param int   $skip    Offset to resume from (start of the current batch)
     * @param array $results Accumulated results so far
     */
    private function persist_user_pause($skip, $results) {
        RWBE_Debug_Logger::log('Import paused by user request, stopping loop', ['skip' => $skip]);
        update_option('rwbe_import_progress', array(
            'skip' => $skip,
            'results' => $results,
            'timestamp' => time(),
            'status' => 'paused_by_user',
            'last_activity' => 'Importação pausada pelo utilizador'
        ), false);
        // Consume the request: the loop has now honoured it. The status stays
        // 'paused_by_user' (respected by the cron grace window) until the user
        // resumes or the auto-resume threshold passes.
        $this->clear_stop_flag();
    }

    /**
     * Import products with resilience - handles larger batches and automatic resumption
     *
     * @param bool $is_cron Whether this is a cron import
     * @param bool $resume Whether to resume from last import position
     * @return array Import results
     */
    public function import_products_with_resilience($is_cron = false, $resume = true) {
        // Same concurrency guard as import_products(): this resilient loop is the
        // path the cron and the interrupted-import watchdog actually use, so it is
        // where overlapping runs would otherwise race and duplicate products.
        if (!$this->acquire_import_lock()) {
            RWBE_Debug_Logger::log('Another import already holds the lock, aborting resilient run to avoid duplicates', [
                'is_cron' => $is_cron ? 'yes' : 'no',
                'resume'  => $resume ? 'yes' : 'no'
            ]);
            return array(
                'total' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0,
                'errors' => 0, 'error_messages' => array('Import already running'),
                'completed' => false, 'locked_out' => true
            );
        }

        // See import_products(): counts are recomputed once when the run ends.
        wp_defer_term_counting(true);

        try {
            return $this->run_import_with_resilience($is_cron, $resume);
        } finally {
            wp_defer_term_counting(false);
            $this->release_import_lock();
        }
    }

    /**
     * Core resilient import loop. Always invoked through
     * import_products_with_resilience(), which holds the advisory lock.
     */
    private function run_import_with_resilience($is_cron = false, $resume = true) {
        RWBE_Debug_Logger::log('Starting resilient product import', ['is_cron' => $is_cron ? 'yes' : 'no', 'resume' => $resume ? 'yes' : 'no']);

        // A run is starting/resuming — clear any stale stop request so it does not
        // instantly kill this run. A new "Parar" click sets it again.
        $this->clear_stop_flag();

        // Garantir que os atributos necessários existam antes de começar a importação
        $this->ensure_all_attributes_exist();
        // Sincronizar taxonomias (brands e grupos) antes de processar produtos
        $this->maybe_sync_taxonomies();
        
        $results = array(
            'total' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'error_messages' => array(),
            'completed' => false
        );

        // Aumentar o tamanho do lote para 200 produtos (balanceando performance e estabilidade)
        $limit = 200;
        $skip = 0;
        $has_more = true;
        $max_retries = 10; // Aumentar significativamente o número de tentativas
        $retry_delay = 15; // segundos iniciais
        $max_execution_time = 0; // Sem limite de tempo de execução

        // Definir tempo máximo de execução para infinito (se possível)
        @set_time_limit($max_execution_time);

        // Incremental sync (cron only): fetch only products updated since the last
        // completed import via the API 'dateUpdated' filter. Manual runs always do
        // a full sync. As a safety net (in case the API does not bump dateUpdated
        // for stock-only changes), the cron forces a FULL reconciliation sync at
        // least once every 24h.
        $date_updated = '';
        if ($is_cron) {
            $last_full_sync = intval(get_option('rwbe_last_full_sync_time'));
            $needs_full_sync = ($last_full_sync <= 0) || ((time() - $last_full_sync) > DAY_IN_SECONDS);

            if ($needs_full_sync) {
                RWBE_Debug_Logger::log('Cron performing periodic full reconciliation sync');
            } else {
                $base_ts = max(
                    intval(get_option('rwbe_last_cron_import_time')),
                    intval(get_option('rwbe_last_import_time'))
                );
                if ($base_ts > 0) {
                    $date_updated = date('Y-m-d', $base_ts);
                    RWBE_Debug_Logger::log('Cron incremental sync enabled', ['dateUpdated' => $date_updated]);
                }
            }
        }
        
        // Se estamos retomando uma importação anterior, recuperar o progresso salvo
        if ($resume) {
            $progress = get_option('rwbe_import_progress');
            if ($progress && !empty($progress['skip'])) {
                $skip = intval($progress['skip']);
                RWBE_Debug_Logger::log('Resuming import from previous position', ['skip' => $skip]);
                
                // Adicionar resultados anteriores aos resultados atuais
                if (!empty($progress['results']) && is_array($progress['results'])) {
                    foreach (['total', 'created', 'updated', 'skipped', 'errors', 'completed'] as $key) {
                        if (isset($progress['results'][$key])) {
                            $results[$key] = $progress['results'][$key];
                        }
                    }
                    
                    if (!empty($progress['results']['error_messages']) && is_array($progress['results']['error_messages'])) {
                        $results['error_messages'] = $progress['results']['error_messages'];
                    }
                    
                    RWBE_Debug_Logger::log('Restored previous import results', $results);
                    
                    // Se a importação já foi concluída anteriormente, retornar os resultados
                    if (!empty($results['completed']) && $results['completed'] === true) {
                        RWBE_Debug_Logger::log('Import was already completed previously, returning results');
                        return $results;
                    }
                }
            }
        } else {
            // Se não estamos retomando, limpar qualquer progresso anterior
            delete_option('rwbe_import_progress');
        }
        
        // Track the import start time (kept in its own option so it survives the
        // many progress writes) — used by the live dashboard for elapsed/throughput.
        if (!$resume || !get_option('rwbe_import_started_at')) {
            update_option('rwbe_import_started_at', time(), false);
        }

        // Marcar a importação como em andamento
        update_option('rwbe_import_progress', array(
            'skip' => $skip,
            'results' => $results,
            'timestamp' => time(),
            'status' => 'in_progress',
            'last_activity' => 'Starting import process'
        ), false);

        // Definir um tempo limite para a execução total (4 horas) - apenas como segurança
        $start_time = time();
        $max_total_time = 4 * 60 * 60; // 4 horas em segundos
        
        while ($has_more) {
            // Verificar se excedemos o tempo máximo total
            $current_time = time();
            $elapsed_time = $current_time - $start_time;
            
            if ($elapsed_time > $max_total_time) {
                RWBE_Debug_Logger::log('Maximum total execution time reached, pausing import', [
                    'elapsed_time' => $elapsed_time,
                    'max_time' => $max_total_time,
                    'current_skip' => $skip
                ]);
                
                // Atualizar o status para indicar pausa por tempo
                update_option('rwbe_import_progress', array(
                    'skip' => $skip,
                    'results' => $results,
                    'timestamp' => time(),
                    'status' => 'paused_time_limit',
                    'last_activity' => 'Paused due to time limit'
                ), false);
                
                // Sair do loop, mas manter o progresso para continuar depois
                break;
            }

            // Verificar se o utilizador pediu para parar a importação (botão "Parar")
            if ($this->user_requested_stop()) {
                $this->persist_user_pause($skip, $results);
                break;
            }

            // Atualizar o status para mostrar atividade recente
            update_option('rwbe_import_progress', array(
                'skip' => $skip,
                'results' => $results,
                'timestamp' => time(),
                'status' => 'in_progress',
                'last_activity' => 'Fetching products from API (limit: ' . $limit . ', skip: ' . $skip . ')'
            ), false);
            
            RWBE_Debug_Logger::log('Fetching products from API', ['limit' => $limit, 'skip' => $skip]);
            
            // Implementar sistema de tentativas com backoff exponencial
            $retry_count = 0;
            $products = null;
            $connection_established = false;
            
            while ($retry_count < $max_retries && !$connection_established) {
                $products = $this->fetch_products_from_api($limit, $skip, $date_updated);
                
                if (!is_wp_error($products)) {
                    // Sucesso na requisição, sair do loop de tentativas
                    $connection_established = true;
                    break;
                }
                
                $retry_count++;
                $wait_time = $retry_delay * pow(2, min($retry_count - 1, 5)); // Backoff exponencial com limite máximo
                $error_message = $products->get_error_message();
                
                RWBE_Debug_Logger::log('API request failed, retrying with exponential backoff', [
                    'retry' => $retry_count, 
                    'max_retries' => $max_retries, 
                    'wait_time' => $wait_time,
                    'error' => $error_message
                ]);
                
                // Atualizar o status para mostrar que estamos tentando novamente
                update_option('rwbe_import_progress', array(
                    'skip' => $skip,
                    'results' => $results,
                    'timestamp' => time(),
                    'status' => 'retrying_connection',
                    'last_activity' => 'Retry ' . $retry_count . '/' . $max_retries . ': ' . $error_message,
                    'next_retry' => time() + $wait_time
                ), false);
                
                if ($retry_count < $max_retries) {
                    // Aguardar com backoff exponencial antes de tentar novamente
                    sleep($wait_time);
                }
            }
            
            // Se ainda é um erro após todas as tentativas, registrar e continuar
            if (!$connection_established) {
                $error_message = is_wp_error($products) ? $products->get_error_message() : 'Unknown connection error';
                RWBE_Debug_Logger::log('Error fetching products from API after all retries', ['error' => $error_message]);
                $results['errors']++;
                $results['error_messages'][] = $error_message;
                
                // Salvar o progresso atual mesmo com erro para poder retomar depois
                update_option('rwbe_import_progress', array(
                    'skip' => $skip,
                    'results' => $results,
                    'timestamp' => time(),
                    'status' => 'connection_failed',
                    'last_error' => $error_message,
                    'retry_after' => time() + 300 // Tentar novamente após 5 minutos
                ), false);
                
                // Notificar admin sobre o erro (opcional)
                $this->notify_admin('Erro na importação de produtos: ' . $error_message . '. A importação será retomada automaticamente.', 'error');
                
                // Parar a importação atual, mas manter o progresso para retomar depois
                break;
            }

            if (empty($products)) {
                RWBE_Debug_Logger::log('No products returned from API');
                $has_more = false;
                continue;
            }

            RWBE_Debug_Logger::log('Products fetched successfully', ['count' => count($products)]);
            $results['total'] += count($products);
            
            // Atualizar o progresso para permitir retomada e garantir estatísticas consistentes
            $total_processed = $results['created'] + $results['updated'] + $results['skipped'] + $results['errors'];
            
            // Garantir que o total nunca é menor que o número de itens processados
            if ($results['total'] < $total_processed) {
                $results['total'] = $total_processed;
            }
            
            update_option('rwbe_import_progress', array(
                'skip' => $skip + $limit, // Próximo lote
                'results' => $results,
                'timestamp' => time(),
                'status' => 'processing_products',
                'last_activity' => 'Processed ' . count($products) . ' products'
            ), false);

            // The cron path refreshes stock for every product, so fetch the whole
            // batch's live figures concurrently instead of one blocking request per
            // product inside the loop below.
            if ($is_cron) {
                $this->prefetch_stock($this->collect_api_ids($products));
            }

            $processed_count = 0;
            $stop_requested = false;
            foreach ($products as $product_data) {
                $processed_count++;

                // Atualizar o status periodicamente durante o processamento (a cada 10 produtos)
                if ($processed_count % 10 === 0) {
                    update_option('rwbe_import_progress', array(
                        'skip' => $skip,
                        'results' => $results,
                        'timestamp' => time(),
                        'status' => 'processing_products',
                        'last_activity' => 'Processed ' . $processed_count . '/' . count($products) . ' products'
                    ), false);

                    // Verificar pedido de paragem do utilizador a meio do lote
                    if ($this->user_requested_stop()) {
                        $this->persist_user_pause($skip, $results);
                        $stop_requested = true;
                        break;
                    }
                }

                RWBE_Debug_Logger::log('Processing product', ['itemCode' => $product_data['itemCode'], 'title' => $product_data['title']]);

                // Isolamento de falhas: um erro fatal num único produto (ex.: exceção
                // lançada por um hook de terceiros, lib de imagem, etc.) NÃO deve
                // abortar toda a importação. Captura-se, conta-se como erro e segue-se.
                try {
                    $import_result = $this->process_product($product_data, $is_cron);
                } catch (\Throwable $e) {
                    $import_result = new WP_Error('product_exception', $e->getMessage());
                    RWBE_Debug_Logger::log('Uncaught error while processing product, skipping', [
                        'itemCode' => isset($product_data['itemCode']) ? $product_data['itemCode'] : '',
                        'error' => $e->getMessage()
                    ]);
                }

                if (is_wp_error($import_result)) {
                    $error_message = $import_result->get_error_message();
                    RWBE_Debug_Logger::log('Error processing product', ['itemCode' => $product_data['itemCode'], 'error' => $error_message]);
                    $results['errors']++;
                    $results['error_messages'][] = $error_message;
                    // Limitar o histórico de mensagens de erro para a opção de progresso
                    // (gravada na BD a cada poucos produtos) não crescer sem limite.
                    if (count($results['error_messages']) > 50) {
                        $results['error_messages'] = array_slice($results['error_messages'], -50);
                    }
                } else {
                    RWBE_Debug_Logger::log('Product processed successfully', ['itemCode' => $product_data['itemCode'], 'result' => $import_result]);
                    $results[$import_result]++;
                }
            }

            // Se o utilizador pediu para parar a meio do lote, sair sem avançar o offset
            // (o progresso já foi gravado com 'paused_by_user' por persist_user_pause)
            if ($stop_requested) {
                break;
            }

            // Atualizar o contador para a próxima página
            $skip += $limit;

            // Verificar se há mais produtos para buscar
            $has_more = (count($products) == $limit);

            // Salvar o progresso para poder retomar se necessário
            $results['completed'] = !$has_more; // Marcar como concluído se não houver mais produtos
            update_option('rwbe_import_progress', array(
                'skip' => $skip,
                'results' => $results,
                'timestamp' => time(),
                'status' => $has_more ? 'in_progress' : 'completed',
                'last_activity' => $has_more ? 'Preparing for next batch' : 'Import completed'
            ), false);
            
            // Adicionar um pequeno atraso para evitar sobrecarga da API
            if ($has_more) {
                usleep(500000); // 0.5 segundos
            }
        }

        RWBE_Debug_Logger::log('Resilient import completed or paused', $results);
        
        // Limpar o progresso da importação apenas quando concluída com sucesso
        if ($results['completed']) {
            // Guardar timestamp da última importação
            if ($is_cron) {
                update_option('rwbe_last_cron_import_time', time(), false);
            } else {
                update_option('rwbe_last_import_time', time(), false);
            }

            // Track last FULL sync for periodic reconciliation. Manual imports are
            // always full; cron is full only when no dateUpdated filter was applied.
            if (!$is_cron || $date_updated === '') {
                update_option('rwbe_last_full_sync_time', time(), false);
            }

            delete_option('rwbe_import_progress');
            delete_option('rwbe_import_started_at');
            RWBE_Debug_Logger::log('Import progress cleared');

            // Notificar admin sobre a conclusão (opcional)
            $this->notify_admin('Importação de produtos concluída com sucesso', 'success');
        }
        
        return $results;
    }
    
    /**
     * Send notification to admin
     *
     * @param string $message The notification message
     * @param string $type The notification type (success, error, info)
     */
    private function notify_admin($message, $type = 'info') {
        // Implementação básica de notificação por email
        if ($type === 'error') {
            // Apenas enviar email para erros
            $admin_email = get_option('admin_email');
            $site_name = get_bloginfo('name');
            $subject = '[' . $site_name . '] Alerta de Importação RWBE';
            
            wp_mail($admin_email, $subject, $message);
        }
        
        // Registrar a notificação no log
        RWBE_Debug_Logger::log('Admin notification', ['message' => $message, 'type' => $type]);
    }

    /**
     * Import products via cron job
     */
    public function import_products_cron() {
        RWBE_Debug_Logger::log('Starting import via cron job');
        // Verificar se há uma importação em andamento ou que foi interrompida
        $progress = get_option('rwbe_import_progress');
        $resume = false;
        $should_import = true;
        
        if ($progress) {
            $status = isset($progress['status']) ? $progress['status'] : '';
            $last_timestamp = isset($progress['timestamp']) ? intval($progress['timestamp']) : 0;
            $current_time = time();
            $time_diff = $current_time - $last_timestamp;

            // Explicit "Parar" wins over a stale in-progress status (see watchdog).
            if ($this->is_stop_requested() && $time_diff <= $this->pause_auto_resume_seconds()) {
                RWBE_Debug_Logger::log('Stop requested by user, cron not resuming (within grace)', ['time_diff' => $time_diff]);
                $should_import = false;
            }
            // Se a importação está em andamento e foi atualizada recentemente, não iniciar nova importação
            else if (in_array($status, ['in_progress', 'processing_products', 'retrying_connection']) && $time_diff < 180) {
                RWBE_Debug_Logger::log('Import already in progress and recently updated, skipping', [
                    'status' => $status,
                    'last_update' => $last_timestamp,
                    'time_diff' => $time_diff
                ]);
                $should_import = false;
            }
            // Pausa MANUAL do utilizador: respeitar a pausa até ao limiar configurado
            // (default 3h). Antes disso, só o botão "Retomar" (status 'resuming')
            // continua — assim o cron agendado a +60s no arranque não desfaz o "Parar".
            // Passado o limiar, o cron retoma automaticamente.
            else if ($status === 'paused_by_user') {
                $auto_resume_after = $this->pause_auto_resume_seconds();
                if ($time_diff > $auto_resume_after) {
                    RWBE_Debug_Logger::log('Auto-resuming user-paused import after threshold (cron)', [
                        'time_diff' => $time_diff,
                        'threshold' => $auto_resume_after,
                        'skip' => isset($progress['skip']) ? $progress['skip'] : 0
                    ]);
                    $resume = true;
                } else {
                    RWBE_Debug_Logger::log('Import paused by user, within grace period, skipping cron', [
                        'time_diff' => $time_diff,
                        'threshold' => $auto_resume_after
                    ]);
                    $should_import = false;
                }
            }
            // Se a importação está em andamento mas não foi atualizada recentemente, retomar
            else if (in_array($status, ['in_progress', 'processing_products', 'retrying_connection', 'paused_time_limit', 'connection_failed', 'resuming'])) {
                RWBE_Debug_Logger::log('Found import to resume', [
                    'status' => $status,
                    'last_update' => $last_timestamp,
                    'time_diff' => $time_diff,
                    'skip' => isset($progress['skip']) ? $progress['skip'] : 0
                ]);
                $resume = true;
            }
            // Se a importação foi concluída, não iniciar nova importação
            else if ($status === 'completed') {
                RWBE_Debug_Logger::log('Import already completed, skipping');
                $should_import = false;
            }
        }
        
        // Iniciar ou retomar a importação se necessário
        if ($should_import) {
            RWBE_Debug_Logger::log('Starting resilient import', [
                'resume' => $resume ? 'yes' : 'no'
            ]);
            $this->import_products_with_resilience(true, $resume);
        }
    }
}
