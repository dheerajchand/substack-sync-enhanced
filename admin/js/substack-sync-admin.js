/**
 * Substack Sync Enhanced - Admin JavaScript
 *
 * Originally forked from Substack Sync by Christopher S. Penn.
 * Licensed under Apache-2.0.
 *
 * Requires substackSyncAdmin to be localized via wp_localize_script with:
 *   - ajaxUrl: WordPress admin-ajax.php URL
 *   - nonce: AJAX security nonce
 *   - categoryOptions: Array of { id, name } for category mapping
 */

/* global substackSyncAdmin */

(function () {
    'use strict';

    /**
     * Category mapping dynamic row management.
     */
    window.addCategoryMapping = function () {
        var container = document.getElementById('category-mapping-container');
        if (!container) return;

        var index = container.querySelectorAll('.category-mapping-row').length;
        var categoryOptions = substackSyncAdmin.categoryOptions || [];

        var optionsHtml = '<option value="">Select Category</option>';
        categoryOptions.forEach(function (cat) {
            optionsHtml += '<option value="' + cat.id + '">' + cat.name + '</option>';
        });

        var row = '<div class="category-mapping-row">' +
            '<label>Keyword: </label>' +
            '<input type="text" name="substack_sync_settings[category_mapping][' + index + '][keyword]" placeholder="e.g., marketing, tutorial" />' +
            '<label>Category: </label>' +
            '<select name="substack_sync_settings[category_mapping][' + index + '][category]">' + optionsHtml + '</select>' +
            '<button type="button" class="button remove-mapping" onclick="removeCategoryMapping(this)">Remove</button>' +
            '</div>';

        container.insertAdjacentHTML('beforeend', row);
    };

    window.removeCategoryMapping = function (button) {
        button.closest('.category-mapping-row').remove();
    };

    /**
     * Tab navigation.
     */
    function initTabs() {
        var tabs = document.querySelectorAll('.substack-sync-wrap .nav-tab');
        var tabContents = document.querySelectorAll('.substack-sync-wrap .tab-content');

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function (e) {
                e.preventDefault();

                tabs.forEach(function (t) { t.classList.remove('nav-tab-active'); });
                tabContents.forEach(function (tc) { tc.style.display = 'none'; });

                this.classList.add('nav-tab-active');

                var targetId = this.getAttribute('href').substring(1);
                var target = document.getElementById(targetId);
                if (target) target.style.display = 'block';
            });
        });
    }

    /**
     * Admin manager — handles retry, rollback, and log refresh actions.
     */
    function SubstackAdminManager() {
        this.ajaxUrl = substackSyncAdmin.ajaxUrl;
        this.nonce = substackSyncAdmin.nonce;
        this.initEventListeners();
    }

    SubstackAdminManager.prototype.initEventListeners = function () {
        var self = this;

        var retryBtn = document.getElementById('retry-failed-btn');
        if (retryBtn) {
            retryBtn.addEventListener('click', function () { self.retryFailedPosts(); });
        }

        var rollbackAllBtn = document.getElementById('rollback-all-btn');
        if (rollbackAllBtn) {
            rollbackAllBtn.addEventListener('click', function () { self.rollbackPosts('all'); });
        }

        var rollbackFailedBtn = document.getElementById('rollback-failed-btn');
        if (rollbackFailedBtn) {
            rollbackFailedBtn.addEventListener('click', function () { self.rollbackPosts('failed'); });
        }

        var rollbackDateBtn = document.getElementById('rollback-date-btn');
        if (rollbackDateBtn) {
            rollbackDateBtn.addEventListener('click', function () { self.rollbackPosts('date'); });
        }

        var refreshLogsBtn = document.getElementById('refresh-logs-btn');
        if (refreshLogsBtn) {
            refreshLogsBtn.addEventListener('click', function () { self.refreshLogs(); });
        }

        var sitemapSyncBtn = document.getElementById('sitemap-sync-btn');
        if (sitemapSyncBtn) {
            sitemapSyncBtn.addEventListener('click', function () { self.startSitemapSync(); });
        }

        var zipImportBtn = document.getElementById('zip-import-btn');
        if (zipImportBtn) {
            zipImportBtn.addEventListener('click', function () { self.importZip(); });
        }
    };

    SubstackAdminManager.prototype.retryFailedPosts = function () {
        if (!confirm('Are you sure you want to retry all failed posts?')) return;

        this.showStatus('sync-status', 'Retrying failed posts...', 'info');

        fetch(this.ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=substack_retry_failed&_ajax_nonce=' + this.nonce
        })
        .then(function (response) { return response.json(); })
        .then(function (data) {
            if (data.success) {
                this.showStatus('sync-status', data.data.message, 'success');
                setTimeout(function () { location.reload(); }, 2000);
            } else {
                this.showStatus('sync-status', data.data, 'error');
            }
        }.bind(this))
        .catch(function (error) {
            this.showStatus('sync-status', 'Error: ' + error.message, 'error');
        }.bind(this));
    };

    SubstackAdminManager.prototype.rollbackPosts = function (type) {
        var confirmMessage = 'This will permanently delete WordPress posts. Are you sure?';
        var postData = 'action=substack_rollback_posts&_ajax_nonce=' + this.nonce + '&type=' + type;

        if (type === 'date') {
            var dateFrom = document.getElementById('rollback-date-from').value;
            var dateTo = document.getElementById('rollback-date-to').value;

            if (!dateFrom || !dateTo) {
                alert('Please select both start and end dates.');
                return;
            }

            confirmMessage = 'This will delete all synced posts between ' + dateFrom + ' and ' + dateTo + '. Are you sure?';
            postData += '&date_from=' + dateFrom + '&date_to=' + dateTo;
        }

        if (!confirm(confirmMessage)) return;

        this.showStatus('rollback-status', 'Removing posts...', 'info');

        fetch(this.ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: postData
        })
        .then(function (response) { return response.json(); })
        .then(function (data) {
            if (data.success) {
                this.showStatus('rollback-status', data.data.message, 'success');
                setTimeout(function () { location.reload(); }, 2000);
            } else {
                this.showStatus('rollback-status', data.data, 'error');
            }
        }.bind(this))
        .catch(function (error) {
            this.showStatus('rollback-status', 'Error: ' + error.message, 'error');
        }.bind(this));
    };

    SubstackAdminManager.prototype.startSitemapSync = function () {
        if (!confirm('This will sync ALL posts from your Substack archive via the sitemap. Already-synced posts will be skipped. Continue?')) return;

        this.sitemapOffset = 0;
        this.sitemapTotal = 0;
        this.sitemapProcessed = 0;
        this.showStatus('sitemap-sync-status', 'Fetching sitemap and starting sync...', 'info');
        document.getElementById('sitemap-sync-btn').disabled = true;
        this.processSitemapBatch();
    };

    SubstackAdminManager.prototype.processSitemapBatch = function () {
        var self = this;

        fetch(this.ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=substack_sitemap_sync&_ajax_nonce=' + this.nonce + '&offset=' + this.sitemapOffset + '&batch_size=1'
        })
        .then(function (response) { return response.json(); })
        .then(function (data) {
            if (data.success) {
                var result = data.data;
                if (self.sitemapTotal === 0) {
                    self.sitemapTotal = result.total_posts;
                }
                self.sitemapProcessed += result.posts_processed;

                var msg = 'Progress: ' + self.sitemapProcessed + '/' + self.sitemapTotal + ' posts';
                if (result.processed_posts && result.processed_posts.length > 0) {
                    var post = result.processed_posts[0];
                    msg += ' — ' + post.action + ': ' + post.post_title;
                }
                self.showStatus('sitemap-sync-status', msg, 'info');

                if (result.has_more) {
                    self.sitemapOffset = result.next_offset;
                    setTimeout(function () { self.processSitemapBatch(); }, 500);
                } else {
                    self.showStatus('sitemap-sync-status', 'Sitemap sync complete! Processed ' + self.sitemapProcessed + ' posts.', 'success');
                    document.getElementById('sitemap-sync-btn').disabled = false;
                    setTimeout(function () { location.reload(); }, 3000);
                }
            } else {
                self.showStatus('sitemap-sync-status', 'Error: ' + (data.data.message || data.data), 'error');
                document.getElementById('sitemap-sync-btn').disabled = false;
            }
        })
        .catch(function (error) {
            self.showStatus('sitemap-sync-status', 'Network error: ' + error.message, 'error');
            document.getElementById('sitemap-sync-btn').disabled = false;
        });
    };

    SubstackAdminManager.prototype.importZip = function () {
        var fileInput = document.getElementById('substack-zip-file');
        if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
            alert('Please select a ZIP file first.');
            return;
        }

        this.showStatus('zip-import-status', 'Uploading and importing...', 'info');
        document.getElementById('zip-import-btn').disabled = true;

        var formData = new FormData();
        formData.append('action', 'substack_zip_import');
        formData.append('_ajax_nonce', this.nonce);
        formData.append('zip_file', fileInput.files[0]);

        var self = this;

        fetch(this.ajaxUrl, {
            method: 'POST',
            body: formData
        })
        .then(function (response) { return response.json(); })
        .then(function (data) {
            if (data.success) {
                self.showStatus('zip-import-status', data.data.message, 'success');
                setTimeout(function () { location.reload(); }, 3000);
            } else {
                self.showStatus('zip-import-status', 'Error: ' + (data.data.message || data.data), 'error');
            }
            document.getElementById('zip-import-btn').disabled = false;
        })
        .catch(function (error) {
            self.showStatus('zip-import-status', 'Network error: ' + error.message, 'error');
            document.getElementById('zip-import-btn').disabled = false;
        });
    };

    SubstackAdminManager.prototype.refreshLogs = function () {
        var self = this;

        fetch(this.ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=substack_get_sync_stats&_ajax_nonce=' + this.nonce
        })
        .then(function (response) { return response.json(); })
        .then(function (data) {
            if (data.success && data.data.logs) {
                var logContainer = document.getElementById('sync-activity-log');
                if (logContainer) {
                    logContainer.innerHTML = data.data.logs.map(function (log) {
                        return '<div style="margin-bottom: 5px; color: ' + self.getLogColor(log.status) + ';">' +
                            log.sync_date + ' - ' + log.status.toUpperCase() + ': ' + log.substack_title +
                            '</div>';
                    }).join('');
                }
            }
        });
    };

    SubstackAdminManager.prototype.showStatus = function (elementId, message, type) {
        var element = document.getElementById(elementId);
        if (!element) return;

        var cssClass = 'substack-sync-status';
        if (type) cssClass += ' substack-sync-status--' + type;

        element.innerHTML = '<div class="' + cssClass + '">' + message + '</div>';
    };

    SubstackAdminManager.prototype.getLogColor = function (status) {
        var colors = {
            'imported': '#46b450',
            'updated': '#ffb900',
            'error': '#dc3232'
        };
        return colors[status] || '#666';
    };

    /**
     * Sync progress tracker — handles batch sync with progress bar.
     */
    function SubstackSyncProgress() {
        this.button = document.getElementById('sync-now-btn');
        this.statusEl = document.getElementById('sync-status');
        this.ajaxUrl = substackSyncAdmin.ajaxUrl;
        this.nonce = substackSyncAdmin.nonce;
        this.currentOffset = 0;
        this.totalPosts = 0;
        this.processedPosts = 0;
        this.importedPosts = 0;
        this.updatedPosts = 0;
        this.errorCount = 0;
        this.isRunning = false;

        if (this.button) {
            this.button.addEventListener('click', this.startSync.bind(this));
        }
    }

    SubstackSyncProgress.prototype.startSync = function () {
        if (this.isRunning) return;

        this.isRunning = true;
        this.currentOffset = 0;
        this.totalPosts = 0;
        this.processedPosts = 0;
        this.importedPosts = 0;
        this.updatedPosts = 0;
        this.errorCount = 0;

        this.button.disabled = true;
        this.button.textContent = 'Syncing...';

        this.showProgressInterface();
        this.processBatch();
    };

    SubstackSyncProgress.prototype.showProgressInterface = function () {
        this.statusEl.innerHTML =
            '<div class="sync-progress">' +
                '<h3>Synchronization in Progress</h3>' +
                '<div class="sync-stats">' +
                    '<p><strong>Status:</strong> <span id="sync-current-status">Initializing...</span></p>' +
                    '<p><strong>Progress:</strong> <span id="sync-progress-text">0/0 posts processed</span></p>' +
                '</div>' +
                '<div class="progress-bar">' +
                    '<div class="progress-fill" id="progress-fill" style="width: 0%"></div>' +
                '</div>' +
                '<div class="sync-summary">' +
                    '<span>Imported: <strong id="imported-count">0</strong></span> | ' +
                    '<span>Updated: <strong id="updated-count">0</strong></span> | ' +
                    '<span>Errors: <strong id="error-count">0</strong></span>' +
                '</div>' +
                '<div class="post-log" id="post-log">' +
                    '<div class="post-entry">Starting synchronization process...</div>' +
                '</div>' +
            '</div>';
    };

    SubstackSyncProgress.prototype.processBatch = function () {
        this.updateStatus('Processing posts...');

        var formData = new FormData();
        formData.append('action', 'substack_sync_batch');
        formData.append('_ajax_nonce', this.nonce);
        formData.append('offset', this.currentOffset.toString());
        formData.append('batch_size', '1');

        fetch(this.ajaxUrl, {
            method: 'POST',
            body: formData
        })
        .then(function (response) { return response.json(); })
        .then(function (data) {
            if (data.success) {
                this.handleBatchSuccess(data.data);
            } else {
                this.handleError('Batch processing failed: ' + data.data);
            }
        }.bind(this))
        .catch(function (error) {
            this.handleError('Network error: ' + error.message);
        }.bind(this));
    };

    SubstackSyncProgress.prototype.handleBatchSuccess = function (result) {
        if (this.totalPosts === 0) {
            this.totalPosts = result.total_posts;
            this.logMessage('Found ' + this.totalPosts + ' posts in feed');
        }

        if (result.processed_posts && result.processed_posts.length > 0) {
            var self = this;
            result.processed_posts.forEach(function (post) {
                self.processedPosts++;

                switch (post.action) {
                    case 'imported':
                        self.importedPosts++;
                        self.logMessage('Imported: ' + post.post_title, 'success');
                        break;
                    case 'updated':
                        self.updatedPosts++;
                        self.logMessage('Updated: ' + post.post_title, 'success');
                        break;
                    case 'skipped':
                        self.logMessage('Skipped: ' + post.post_title + ' (' + post.message + ')', 'warning');
                        break;
                    case 'error':
                        self.errorCount++;
                        self.logMessage('Error: ' + post.message, 'error');
                        break;
                }
            });
        }

        this.updateProgress();

        if (result.has_more) {
            this.currentOffset = result.next_offset;
            setTimeout(this.processBatch.bind(this), 100);
        } else {
            this.finishSync();
        }
    };

    SubstackSyncProgress.prototype.updateProgress = function () {
        var percentage = this.totalPosts > 0 ? Math.round((this.processedPosts / this.totalPosts) * 100) : 0;

        var fillEl = document.getElementById('progress-fill');
        if (fillEl) fillEl.style.width = percentage + '%';

        var progressText = document.getElementById('sync-progress-text');
        if (progressText) progressText.textContent = this.processedPosts + '/' + this.totalPosts + ' posts processed (' + percentage + '%)';

        var importedEl = document.getElementById('imported-count');
        if (importedEl) importedEl.textContent = this.importedPosts;

        var updatedEl = document.getElementById('updated-count');
        if (updatedEl) updatedEl.textContent = this.updatedPosts;

        var errorEl = document.getElementById('error-count');
        if (errorEl) errorEl.textContent = this.errorCount;

        this.updateStatus('Processing post ' + (this.processedPosts + 1) + ' of ' + this.totalPosts + '...');
    };

    SubstackSyncProgress.prototype.finishSync = function () {
        this.isRunning = false;
        this.button.disabled = false;
        this.button.textContent = 'Sync Now';

        var successMessage = 'Sync completed! Processed ' + this.processedPosts + ' posts: ' +
            this.importedPosts + ' imported, ' + this.updatedPosts + ' updated';

        if (this.errorCount > 0) {
            this.updateStatus('Sync completed with ' + this.errorCount + ' errors');
            this.logMessage(successMessage + ' (' + this.errorCount + ' errors)', 'warning');
        } else {
            this.updateStatus('Sync completed successfully!');
            this.logMessage(successMessage, 'success');
        }
    };

    SubstackSyncProgress.prototype.handleError = function (message) {
        this.isRunning = false;
        this.button.disabled = false;
        this.button.textContent = 'Sync Now';

        this.updateStatus('Sync failed');
        this.logMessage(message, 'error');
    };

    SubstackSyncProgress.prototype.updateStatus = function (message) {
        var statusElement = document.getElementById('sync-current-status');
        if (statusElement) statusElement.textContent = message;
    };

    SubstackSyncProgress.prototype.logMessage = function (message, type) {
        var logElement = document.getElementById('post-log');
        if (logElement) {
            var entry = document.createElement('div');
            entry.className = 'post-entry ' + (type || 'info');
            entry.textContent = new Date().toLocaleTimeString() + ' - ' + message;
            logElement.appendChild(entry);
            logElement.scrollTop = logElement.scrollHeight;
        }
    };

    /**
     * Initialize everything when DOM is ready.
     */
    function init() {
        initTabs();

        if (document.getElementById('sync-now-btn')) {
            new SubstackSyncProgress();
            new SubstackAdminManager();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
