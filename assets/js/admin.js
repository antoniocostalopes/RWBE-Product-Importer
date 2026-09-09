/**
 * RWBE Product Importer Admin JavaScript
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        var importPollTimer = null;

        // Lock the import/resume buttons while an import is running
        function lockImportButtons(label) {
            $('#rwbe-import-button')
                .prop('disabled', true)
                .html('<span class="dashicons dashicons-update"></span><span class="rwbe-cta-label">' + (label || rwbe_importer.in_progress_text) + '</span>');
            $('#rwbe-resume-import-button').prop('disabled', true);
        }

        // Release the buttons once the import is finished
        function unlockImportButtons() {
            $('#rwbe-import-button')
                .prop('disabled', false)
                .html('<span class="dashicons dashicons-download"></span><span class="rwbe-cta-label">' + rwbe_importer.start_label + '</span>');
            $('#rwbe-resume-import-button').prop('disabled', false);
        }

        // Poll the server until the import is no longer running, then unlock + refresh
        function pollImportStatus() {
            if (importPollTimer) {
                return; // already polling
            }
            importPollTimer = setInterval(function() {
                $.ajax({
                    url: rwbe_importer.ajax_url,
                    type: 'POST',
                    data: { action: 'rwbe_import_status', nonce: rwbe_importer.nonce },
                    success: function(res) {
                        if (res && res.success && !res.data.running) {
                            clearInterval(importPollTimer);
                            importPollTimer = null;
                            unlockImportButtons();
                            // Reload to show the final statistics
                            location.reload();
                        }
                    }
                });
            }, rwbe_importer.status_poll_interval || 5000);
        }

        // Função para iniciar a importação
        function startImport(resume) {
            var $results = $('#rwbe-import-results');
            var $resultsContent = $('#rwbe-import-results-content');

            lockImportButtons(rwbe_importer.in_progress_text);
            $results.hide();
            $resultsContent.empty();

            $.ajax({
                url: rwbe_importer.ajax_url,
                type: 'POST',
                data: {
                    action: 'rwbe_import_products',
                    nonce: rwbe_importer.nonce,
                    resume: resume ? 'true' : 'false'
                },
                success: function(response) {
                    if (response && response.success) {
                        $resultsContent.html(response.data.html || '');
                        if (response.data.message) {
                            $resultsContent.append('<p class="rwbe-info">' + response.data.message + '</p>');
                        }
                        $results.show();
                    }
                }
                // No re-enable here: the button stays locked. The status poll below
                // decides when the whole import is actually finished.
            });

            // The import runs on the server and may outlive this request — keep the
            // button locked and poll until it completes.
            pollImportStatus();
        }
        
        // Import button click handler
        $('#rwbe-import-button').on('click', function(e) {
            e.preventDefault();
            if (rwbe_importer.confirm_import && !window.confirm(rwbe_importer.confirm_import)) {
                return;
            }
            startImport(false);
        });
        
        // Resume import button click handler
        $('#rwbe-resume-import-button').on('click', function(e) {
            e.preventDefault();
            startImport(true);
        });

        // If an import is already running when the page loads, keep the button
        // locked and poll until it finishes (survives page reloads).
        if (rwbe_importer.import_running) {
            lockImportButtons(rwbe_importer.in_progress_text);
            pollImportStatus();
        }
        
        // Settings: "Testar ligação" button next to the API Token field
        $('#rwbe-settings-test-api').on('click', function(e) {
            e.preventDefault();

            var $button = $(this);
            var $result = $('#rwbe-settings-test-result');
            var token = $('#rwbe_api_token').val();
            var originalHtml = $button.html();

            $button.prop('disabled', true);
            $result.removeClass('is-success is-error').text(rwbe_importer.testing_api || 'A testar…');

            $.ajax({
                url: rwbe_importer.ajax_url,
                type: 'POST',
                data: {
                    action: 'rwbe_test_api_connection',
                    nonce: rwbe_importer.api_test_nonce,
                    token: token
                },
                success: function(response) {
                    if (response.success) {
                        var msg = (response.data && response.data.message) ? response.data.message : (rwbe_importer.test_complete || 'Ligação OK');
                        $result.removeClass('is-error').addClass('is-success').text('✓ ' + msg);
                    } else {
                        var err = (response.data && response.data.message) ? response.data.message : (rwbe_importer.test_error || 'Falha na ligação');
                        $result.removeClass('is-success').addClass('is-error').text('✗ ' + err);
                    }
                },
                error: function() {
                    $result.removeClass('is-success').addClass('is-error').text('✗ ' + (rwbe_importer.test_error || 'Falha na ligação'));
                },
                complete: function() {
                    $button.prop('disabled', false).html(originalHtml);
                }
            });
        });

        // Os manipuladores de eventos para os botões de Debug Log foram removidos pois as seções correspondentes foram removidas da interface

        // --- Limpeza de imagens placeholder duplicadas ---
        // A corrida pertence ao servidor: o estado vive numa opção e um worker de
        // cron continua o trabalho. A página apenas arranca/pára a corrida, empresta
        // pedidos ao worker enquanto está aberta, e desenha o progresso. Mudar de
        // janela ou fechar o separador não interrompe nada; ao voltar, a barra
        // reaparece no ponto onde ia.
        var $cleanupBtn = $('#rwbe-cleanup-placeholders-button');
        var $cleanupContinueBtn = $('#rwbe-cleanup-continue-button');
        var $cleanupStopBtn = $('#rwbe-cleanup-stop-button');
        var $cleanupSpinner = $('#rwbe-cleanup-spinner');
        var $cleanupProgress = $('#rwbe-cleanup-progress');
        var $cleanupStatus = $('#rwbe-cleanup-status');
        var $cleanupFill = $('#rwbe-cleanup-fill');
        var $cleanupPercent = $('#rwbe-cleanup-percent');
        var cleanupPolling = false;

        function renderCleanupState(state) {
            var running = !!state.running;
            var finished = !running && !!state.done;
            var stopped = !running && !!state.stopped;

            var msg;
            if (finished) {
                msg = rwbe_importer.cleanup_done || 'Limpeza concluída.';
            } else if (stopped) {
                msg = rwbe_importer.cleanup_stopped || 'Limpeza parada.';
            } else {
                msg = rwbe_importer.cleanup_running || 'A limpar duplicados…';
            }

            // Percentagem = analisados / total (fixado no 1.º lote da passagem).
            var percent;
            if (finished) {
                percent = 100;
            } else if (state.total > 0) {
                percent = Math.min(99, Math.round((state.scanned / state.total) * 100));
            } else {
                percent = 0;
            }
            $cleanupFill.css('width', percent + '%');
            $cleanupPercent.text(percent + '%');

            var icon = finished ? 'yes' : (stopped ? 'controls-pause' : 'update');
            var tone = finished ? 'rwbe-notice-success' : 'rwbe-notice-warning';
            $cleanupProgress
                .removeClass('rwbe-notice-error rwbe-notice-warning rwbe-notice-success')
                .addClass(tone)
                .html('<span class="dashicons dashicons-' + icon + '"></span> ' +
                    msg + ' <strong>' + state.deleted + '</strong> apagados · ' +
                    state.scanned + ' analisados' +
                    (running ? ' · ~' + state.remaining + ' por analisar' : ''));

            $cleanupStatus.show();
            setCleanupRunning(running);
        }

        function showCleanupError(message) {
            $cleanupProgress
                .removeClass('rwbe-notice-success rwbe-notice-warning')
                .addClass('rwbe-notice-error')
                .html('<span class="dashicons dashicons-warning"></span> ' + message);
            $cleanupStatus.show();
            setCleanupRunning(false);
        }

        function setCleanupRunning(running) {
            cleanupPolling = running;
            $cleanupBtn.prop('disabled', running);
            $cleanupContinueBtn.prop('disabled', running);
            $cleanupStopBtn.toggle(running).prop('disabled', false);
            $cleanupSpinner.toggleClass('is-active', running);
        }

        // op: 'start' | 'work' | 'status' | 'stop'
        function cleanupRequest(op, reset, onDone) {
            return $.ajax({
                url: rwbe_importer.ajax_url,
                type: 'POST',
                data: {
                    action: 'rwbe_cleanup_placeholders',
                    nonce: rwbe_importer.cleanup_nonce,
                    op: op,
                    reset: reset ? 'true' : 'false'
                },
                success: function(response) {
                    if (!response || !response.success) {
                        showCleanupError((response && response.data && response.data.message)
                            ? response.data.message
                            : (rwbe_importer.cleanup_error || 'Erro durante a limpeza.'));
                        return;
                    }
                    renderCleanupState(response.data);
                    if (onDone) {
                        onDone(response.data);
                    }
                },
                error: function() {
                    // Um pedido perdido (rede, reinício do PHP) não cancela a corrida:
                    // o worker de cron continua. Voltamos a sondar o estado.
                    if (cleanupPolling) {
                        window.setTimeout(function() { cleanupRequest('status', false, resumeCleanupLoop); }, 5000);
                        return;
                    }
                    showCleanupError(rwbe_importer.cleanup_error || 'Erro durante a limpeza.');
                }
            });
        }

        // Enquanto a página está aberta empresta o pedido ao worker; assim o
        // trabalho avança à velocidade máxima e a barra mexe-se.
        function cleanupLoop() {
            cleanupRequest('work', false, function(state) {
                if (state.running) {
                    cleanupLoop();
                }
            });
        }

        function resumeCleanupLoop(state) {
            if (state && state.running) {
                cleanupLoop();
            }
        }

        // reset === true recomeça do início; false retoma do cursor guardado.
        function startCleanup(reset) {
            var msg = reset
                ? (rwbe_importer.confirm_cleanup || 'Apagar placeholders duplicados?')
                : (rwbe_importer.confirm_cleanup_resume || rwbe_importer.confirm_cleanup || 'Retomar a limpeza?');
            if (!window.confirm(msg)) {
                return;
            }
            setCleanupRunning(true);
            $cleanupStatus.show();
            cleanupRequest('start', reset, function(state) {
                if (state.running) {
                    cleanupLoop();
                }
            });
        }

        $cleanupBtn.on('click', function(e) {
            e.preventDefault();
            startCleanup(true);
        });

        $cleanupContinueBtn.on('click', function(e) {
            e.preventDefault();
            startCleanup(false);
        });

        $cleanupStopBtn.on('click', function(e) {
            e.preventDefault();
            if (!window.confirm(rwbe_importer.confirm_cleanup_stop || 'Parar a limpeza?')) {
                return;
            }
            $cleanupStopBtn.prop('disabled', true);
            cleanupPolling = false;
            cleanupRequest('stop', false, null);
        });

        // Página reaberta a meio de uma corrida: mostrar a barra e voltar a
        // acompanhar o trabalho que continuou no servidor.
        if ($cleanupBtn.length && rwbe_importer.cleanup_state && rwbe_importer.cleanup_state.running) {
            renderCleanupState(rwbe_importer.cleanup_state);
            cleanupLoop();
        }
    });

})(jQuery);
