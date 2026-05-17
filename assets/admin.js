/* global jQuery, mxchatDuckDB */
(function ($) {
    'use strict';

    var $status = null;

    function setStatus(msg, kind) {
        if (!$status) $status = $('#mxchat-duckdb-status');
        var color = kind === 'error' ? '#a00' : (kind === 'ok' ? '#0a0' : '#666');
        $status.css('color', color).text(msg);
    }

    function ajax(action, extra) {
        return $.post(mxchatDuckDB.ajaxUrl, $.extend({
            action: action,
            nonce: mxchatDuckDB.nonce
        }, extra || {}));
    }

    $(function () {
        $('#mxchat-duckdb-test').on('click', function (e) {
            e.preventDefault();
            setStatus(mxchatDuckDB.i18n.testing, 'info');
            ajax('mxchat_duckdb_test_connection')
                .done(function (resp) {
                    if (resp && resp.success) {
                        setStatus(
                            mxchatDuckDB.i18n.ok + ' — ' + resp.data.backend +
                            ' (' + resp.data.count + ' vecteurs)',
                            'ok'
                        );
                    } else {
                        setStatus(
                            mxchatDuckDB.i18n.error + ' : ' +
                            ((resp && resp.data && resp.data.message) || 'unknown'),
                            'error'
                        );
                    }
                })
                .fail(function (xhr) {
                    setStatus(mxchatDuckDB.i18n.error + ' (HTTP ' + xhr.status + ')', 'error');
                });
        });

        $('#mxchat-duckdb-sync').on('click', function (e) {
            e.preventDefault();
            var $btn = $(this).prop('disabled', true);
            setStatus(mxchatDuckDB.i18n.syncing, 'info');
            ajax('mxchat_duckdb_sync_now')
                .done(function (resp) {
                    if (resp && resp.success) {
                        setStatus(
                            mxchatDuckDB.i18n.syncComplete + ' (' + resp.data.synced + ' vecteurs)',
                            'ok'
                        );
                        setTimeout(function () { window.location.reload(); }, 1500);
                    } else {
                        setStatus(
                            mxchatDuckDB.i18n.error + ' : ' +
                            ((resp && resp.data && resp.data.message) || 'unknown'),
                            'error'
                        );
                    }
                })
                .fail(function (xhr) {
                    setStatus(mxchatDuckDB.i18n.error + ' (HTTP ' + xhr.status + ')', 'error');
                })
                .always(function () { $btn.prop('disabled', false); });
        });

        $('#mxchat-duckdb-reprocess').on('click', function (e) {
            e.preventDefault();
            if (!window.confirm(mxchatDuckDB.i18n.confirmReprocess)) return;

            var $btn = $(this).prop('disabled', true);
            var $progress = $('#mxchat-duckdb-progress').show();
            var $bar = $('#mxchat-duckdb-progress-bar');
            var postTypes = $('#mxchat-duckdb-post-types').val() || 'post,page';

            var totalProcessed = 0;
            var totalFailed = 0;

            function runBatch(offset) {
                return ajax('mxchat_duckdb_reprocess_batch', {
                    offset: offset,
                    batch_size: 10,
                    post_types: postTypes
                });
            }

            function loop(offset) {
                setStatus(
                    mxchatDuckDB.i18n.reprocessing.replace('%1$d', offset).replace('%2$d', '…'),
                    'info'
                );

                runBatch(offset)
                    .done(function (resp) {
                        if (!resp || !resp.success) {
                            var msg = (resp && resp.data && resp.data.message) || 'unknown';
                            setStatus(mxchatDuckDB.i18n.error + ' : ' + msg, 'error');
                            $btn.prop('disabled', false);
                            return;
                        }
                        var d = resp.data;
                        totalProcessed += (d.processed || 0);
                        totalFailed += (d.failed || 0);

                        if (d.total > 0) {
                            $bar.css('width', Math.round((d.done / d.total) * 100) + '%');
                        }

                        if (d.next_offset !== null && typeof d.next_offset !== 'undefined') {
                            setStatus(
                                mxchatDuckDB.i18n.reprocessing
                                    .replace('%1$d', d.done)
                                    .replace('%2$d', d.total),
                                'info'
                            );
                            // Yield to UI before next batch.
                            setTimeout(function () { loop(d.next_offset); }, 50);
                        } else {
                            $bar.css('width', '100%');
                            setStatus(
                                mxchatDuckDB.i18n.reprocessComplete
                                    .replace('%1$d', totalProcessed)
                                    .replace('%2$d', totalFailed),
                                totalFailed === 0 ? 'ok' : 'info'
                            );
                            $btn.prop('disabled', false);
                            setTimeout(function () { window.location.reload(); }, 2500);
                        }
                    })
                    .fail(function (xhr) {
                        setStatus(mxchatDuckDB.i18n.error + ' (HTTP ' + xhr.status + ')', 'error');
                        $btn.prop('disabled', false);
                    });
            }

            loop(0);
        });
    });
})(jQuery);
