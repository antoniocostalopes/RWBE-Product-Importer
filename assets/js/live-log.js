jQuery(document).ready(function ($) {
    // Elements
    const $logEntries = $('#rwbe-live-log-entries');
    const $importStatus = $('#rwbe-import-status');
    const $statusPill = $('#rwbe-status-pill');
    const $currentActivity = $('#rwbe-current-activity');
    const $activityWrap = $('#rwbe-current-activity-wrap');
    const $importProgressBar = $('#rwbe-import-progress-bar');
    const $importProgressText = $('#rwbe-import-progress-text');
    const $elapsed = $('#rwbe-import-elapsed');
    const $rate = $('#rwbe-import-rate');
    const $lastUpdate = $('#rwbe-last-update');
    const $importTotal = $('#rwbe-import-total');
    const $importCreated = $('#rwbe-import-created');
    const $importUpdated = $('#rwbe-import-updated');
    const $importSkipped = $('#rwbe-import-skipped');
    const $importErrors = $('#rwbe-import-errors');
    const $startImportBtn = $('#rwbe-start-import');
    const $stopImportBtn = $('#rwbe-stop-import');
    const $resumeImportBtn = $('#rwbe-resume-import');

    let refreshInterval;
    let previousLogs = [];
    let isFirstLoad = true;

    initRefresh();

    function initRefresh() {
        refreshLogs();
        refreshInterval = setInterval(refreshLogs, rwbeLiveLog.refreshInterval);
    }

    function refreshLogs() {
        $.ajax({
            url: rwbeLiveLog.ajaxUrl,
            type: 'POST',
            data: { action: 'rwbe_get_import_logs', nonce: rwbeLiveLog.nonce },
            success: function (response) {
                if (response.success) {
                    updateUI(response.data);
                } else if (response.data) {
                    console.error('Error fetching logs:', response.data.message);
                }
            },
            error: function (xhr, status, error) {
                console.error('AJAX error:', error);
            }
        });
    }

    function updateUI(data) {
        updateStatus(data.status, data.last_activity, data.import_active);
        updateMetrics(data);
        updateProgress(data.progress);
        updateLogs(data.logs);
        $lastUpdate.text(data.timestamp);
        updateButtons(data.status, data.import_active);
        isFirstLoad = false;
    }

    function updateStatus(status, lastActivity, importActive) {
        const map = {
            in_progress:        ['Importação em andamento', 'running'],
            processing_products:['A processar produtos', 'running'],
            retrying_connection:['A reconectar à API', 'running'],
            resuming:           ['A retomar', 'running'],
            completed:          ['Importação concluída', 'completed'],
            paused_time_limit:  ['Pausada (limite de tempo)', 'interrupted'],
            paused_by_user:     ['Pausada pelo utilizador', 'interrupted'],
            connection_failed:  ['Falha de ligação (retoma automática)', 'interrupted'],
            no_import:          ['Nenhuma importação em curso', 'inactive']
        };
        const entry = map[status] || ['Estado: ' + status, 'inactive'];
        const statusText = entry[0];
        const pillClass = entry[1];

        $importStatus.text(statusText);
        $statusPill.removeClass('running completed interrupted inactive').addClass(pillClass);

        const active = !!importActive || pillClass === 'running';
        $activityWrap.toggleClass('is-active', active);
        $currentActivity.text(lastActivity && active ? lastActivity : statusText);
    }

    function updateMetrics(data) {
        let processed = 0;
        if (data.progress) {
            processed = (data.progress.created || 0) + (data.progress.updated || 0) +
                        (data.progress.skipped || 0) + (data.progress.errors || 0);
        }

        if (data.started_at && data.server_time && data.started_at > 0) {
            const secs = Math.max(0, data.server_time - data.started_at);
            $elapsed.text(formatElapsed(secs));
            const mins = secs / 60;
            $rate.text((mins > 0.1 && processed > 0) ? Math.round(processed / mins) + '/min' : '—');
        } else {
            $elapsed.text('—');
            $rate.text('—');
        }
    }

    function formatElapsed(s) {
        s = Math.floor(s);
        const h = Math.floor(s / 3600);
        const m = Math.floor((s % 3600) / 60);
        const sec = s % 60;
        if (h > 0) return h + 'h ' + m + 'm';
        if (m > 0) return m + 'm ' + sec + 's';
        return sec + 's';
    }

    function updateProgress(progress) {
        if (!progress) return;

        let percentage = 0;
        if (progress.total > 0) {
            const processed = (progress.created || 0) + (progress.updated || 0) +
                              (progress.skipped || 0) + (progress.errors || 0);
            percentage = Math.min(100, Math.round((processed / progress.total) * 100));
        }

        $importProgressBar.css('width', percentage + '%');
        $importProgressText.text(percentage + '%');

        setCount($importTotal, progress.total || 0);
        setCount($importCreated, progress.created || 0);
        setCount($importUpdated, progress.updated || 0);
        setCount($importSkipped, progress.skipped || 0);
        setCount($importErrors, progress.errors || 0);
    }

    function setCount($el, value) {
        if ($el.text() !== String(value)) {
            $el.text(value);
            const $item = $el.closest('.stat-item');
            $item.addClass('highlight-update');
            setTimeout(function () { $item.removeClass('highlight-update'); }, 700);
        }
    }

    function updateLogs(logs) {
        if (!logs || !logs.length) {
            if (isFirstLoad) {
                $logEntries.html('<div class="rwbe-log-loading">' + rwbeLiveLog.i18n.noImport + '</div>');
            }
            return;
        }

        let newIds = [];
        if (previousLogs.length > 0) {
            const previousIds = previousLogs.map(function (l) { return l.product_id + '-' + l.timestamp; });
            newIds = logs
                .filter(function (l) { return previousIds.indexOf(l.product_id + '-' + l.timestamp) === -1; })
                .map(function (l) { return l.product_id + '-' + l.timestamp; });
        }

        let html = '';
        logs.forEach(function (log) {
            const isNew = newIds.indexOf(log.product_id + '-' + log.timestamp) !== -1;
            const actionClass = log.action || 'unknown';
            const time = new Date(log.timestamp * 1000).toLocaleTimeString();

            html +=
                '<div class="rwbe-log-entry ' + actionClass + (isNew ? ' new' : '') + '">' +
                    '<div class="rwbe-log-head">' +
                        '<span class="rwbe-log-action rwbe-log-action-' + actionClass + '">' + getActionText(log.action) + '</span>' +
                        '<span class="rwbe-log-entry-title">' + esc(log.title) + '</span>' +
                        '<span class="rwbe-log-time">' + time + '</span>' +
                    '</div>' +
                    (log.item_code ? '<div class="rwbe-log-sku">' + esc(log.item_code) + '</div>' : '') +
                    buildChips(log.details) +
                '</div>';
        });

        $logEntries.html(html);
        previousLogs = logs.slice();
    }

    function buildChips(d) {
        if (!d || typeof d !== 'object') return '';
        const chips = [];

        if (d.cron) chips.push(chip('dashicons-update', 'Stock/Preço', 'cron'));
        if (d.brand) chips.push(chip('dashicons-tag', d.brand));
        if (d.category) chips.push(chip('dashicons-category', d.category));
        if (d.group) chips.push(chip('dashicons-screenoptions', d.group));
        if (d.models > 0) chips.push(chip('dashicons-car', d.models + ' ' + plural(d.models, 'modelo', 'modelos')));
        if (d.years > 0) chips.push(chip('dashicons-calendar-alt', d.years + ' ' + plural(d.years, 'ano', 'anos')));

        const imgs = (d.gallery || 0) + (d.image ? 1 : 0);
        if (imgs > 0) chips.push(chip('dashicons-format-image', imgs + ' ' + plural(imgs, 'imagem', 'imagens')));

        if (typeof d.stock === 'number') {
            chips.push(chip('dashicons-archive', 'Stock: ' + d.stock, d.stock > 0 ? 'ok' : 'warn'));
        }

        if (d.price > 0) {
            if (d.sale) {
                chips.push(chip('dashicons-money-alt', '<s>' + money(d.price) + '</s> ' + money(d.sale), 'promo'));
            } else {
                chips.push(chip('dashicons-money-alt', money(d.price)));
            }
        }

        return chips.length ? '<div class="rwbe-chips">' + chips.join('') + '</div>' : '';
    }

    function chip(icon, text, variant) {
        const ic = icon ? '<span class="dashicons ' + icon + '"></span>' : '';
        return '<span class="rwbe-chip' + (variant ? ' rwbe-chip-' + variant : '') + '">' + ic + text + '</span>';
    }

    function money(v) {
        return '€' + Number(v).toFixed(2);
    }

    function plural(n, one, many) {
        return n > 1 ? many : one;
    }

    function esc(str) {
        return $('<div>').text(str == null ? '' : String(str)).html();
    }

    function getActionText(action) {
        switch (action) {
            case 'created': return 'Criado';
            case 'updated': return 'Atualizado';
            case 'skipped': return 'Ignorado';
            case 'error': return 'Erro';
            default: return action;
        }
    }

    function updateButtons(status, importActive) {
        const pausedStates = ['paused_time_limit', 'connection_failed', 'paused_by_user'];
        const runningStates = ['in_progress', 'processing_products', 'retrying_connection', 'resuming'];

        // Estados pausados têm prioridade: mostrar "Retomar" mesmo que existam
        // logs/estatísticas de uma importação anterior na página.
        if (pausedStates.indexOf(status) !== -1) {
            $startImportBtn.hide(); $stopImportBtn.hide(); $resumeImportBtn.show();
            return;
        }

        const hasActivity = $('#rwbe-live-log-entries .rwbe-log-entry').length > 0;
        const hasStats = parseInt($importTotal.text(), 10) > 0 ||
                         parseInt($importCreated.text(), 10) > 0 ||
                         parseInt($importUpdated.text(), 10) > 0;
        const isActive = importActive || runningStates.indexOf(status) !== -1 ||
            (hasActivity && hasStats && status !== 'completed' && status !== 'no_import');

        if (isActive) {
            $startImportBtn.hide(); $stopImportBtn.show(); $resumeImportBtn.hide();
        } else {
            $startImportBtn.show(); $stopImportBtn.hide(); $resumeImportBtn.hide();
        }
    }

    // Button handlers
    $startImportBtn.on('click', function () { runAction('rwbe_start_import', $startImportBtn); });
    $stopImportBtn.on('click', function () {
        if (confirm(rwbeLiveLog.i18n.confirmStop)) {
            runAction('rwbe_stop_import', $stopImportBtn);
        }
    });
    $resumeImportBtn.on('click', function () { runAction('rwbe_resume_import', $resumeImportBtn); });

    function runAction(action, $btn) {
        const original = $btn.html();
        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update rwbe-ll-spin"></span> …');
        $.ajax({
            url: rwbeLiveLog.ajaxUrl,
            type: 'POST',
            data: { action: action, nonce: rwbeLiveLog.nonce },
            success: function (response) {
                if (!response.success && response.data) {
                    alert(response.data.message || 'Erro.');
                }
                refreshLogs();
            },
            error: function () { alert('Erro na operação. Tente novamente.'); },
            complete: function () { $btn.prop('disabled', false).html(original); }
        });
    }

    $(window).on('unload', function () { clearInterval(refreshInterval); });
});
