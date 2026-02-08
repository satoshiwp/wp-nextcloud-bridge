/**
 * WP Nextcloud Bridge — Frontend File Browser
 *
 * Vanilla JS (no jQuery). Handles:
 *   - File listing with breadcrumb navigation
 *   - Download / Share / Delete actions
 *   - New folder creation (custom modal, no alert/confirm/prompt)
 *   - Chunked upload with progress (Round 3 will flesh this out)
 *
 * Expects `wpncFront` global from wp_localize_script().
 *
 * @package WPNC
 */
;(function () {
    'use strict';

    /* ================================================================
     *  GLOBALS & CONFIG
     * ============================================================= */

    const CFG = window.wpncFront || {};
    const AJAX = CFG.ajaxUrl || '/wp-admin/admin-ajax.php';
    const NONCE = CFG.nonce || '';
    const CHUNK = CFG.chunkSize || 10 * 1024 * 1024;
    const I18N = CFG.i18n || {};

    /* ================================================================
     *  UTILITY HELPERS
     * ============================================================= */

    function escHtml(str) {
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    function humanSize(bytes) {
        if (bytes === 0) return '—';
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(1024));
        return (bytes / Math.pow(1024, i)).toFixed(i > 0 ? 1 : 0) + ' ' + units[i];
    }

    /** Map MIME / type to an emoji icon. */
    function fileIcon(item) {
        if (item.type === 'folder') return '📁';
        const m = (item.mime || '').toLowerCase();
        if (m.startsWith('image/'))  return '🖼️';
        if (m.startsWith('video/'))  return '🎬';
        if (m.startsWith('audio/'))  return '🎵';
        if (m.includes('pdf'))       return '📄';
        if (m.includes('zip') || m.includes('compressed') || m.includes('archive')) return '📦';
        if (m.includes('text') || m.includes('json') || m.includes('xml')) return '📝';
        if (m.includes('sheet') || m.includes('excel') || m.includes('csv')) return '📊';
        if (m.includes('presentation') || m.includes('powerpoint')) return '📽️';
        if (m.includes('word') || m.includes('document')) return '📃';
        return '📎';
    }

    /** Format date string from Last-Modified header. */
    function formatDate(str) {
        if (!str) return '—';
        const d = new Date(str);
        if (isNaN(d.getTime())) return str;
        return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
             + ' ' + d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
    }

    /* ================================================================
     *  AJAX HELPER
     * ============================================================= */

    /**
     * Send an AJAX request to the WP backend.
     *
     * @param {string} action   wp_ajax action name (e.g. 'wpnc_browse').
     * @param {Object} data     Key-value pairs.
     * @param {Object} [opts]   Optional: { method, file, onProgress }
     * @returns {Promise<Object>}  Parsed JSON response.
     */
    function wpncRequest(action, data, opts) {
        opts = opts || {};
        const fd = new FormData();
        fd.append('action', action);
        fd.append('_nonce', NONCE);

        if (data) {
            Object.keys(data).forEach(function (k) {
                fd.append(k, data[k]);
            });
        }

        // Attach a file if provided (for simple upload / chunk upload).
        if (opts.file) {
            fd.append(opts.fileField || 'file', opts.file, opts.fileName || opts.file.name);
        }

        return new Promise(function (resolve, reject) {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', AJAX, true);

            if (opts.onProgress) {
                xhr.upload.addEventListener('progress', opts.onProgress);
            }

            xhr.onload = function () {
                try {
                    const json = JSON.parse(xhr.responseText);
                    if (json.success) {
                        resolve(json.data);
                    } else {
                        reject(new Error(json.data || I18N.error || 'Error'));
                    }
                } catch (e) {
                    reject(new Error('Invalid response from server'));
                }
            };

            xhr.onerror = function () {
                reject(new Error('Network error'));
            };

            xhr.send(fd);
        });
    }

    /* ================================================================
     *  MODAL DIALOG SYSTEM (replaces alert / confirm / prompt)
     * ============================================================= */

    /** Show a modal inside the browser container. Returns a Promise. */
    function showModal(container, innerHTML) {
        return new Promise(function (resolve) {
            const backdrop = container.querySelector('.wpnc-modal-backdrop');
            const modal = container.querySelector('.wpnc-modal');

            modal.innerHTML = innerHTML;
            backdrop.style.display = 'flex';

            // Wire up buttons.
            const confirmBtn = modal.querySelector('[data-action="confirm"]');
            const cancelBtn  = modal.querySelector('[data-action="cancel"]');
            const inputEl    = modal.querySelector('input[data-modal-input]');

            function close(value) {
                backdrop.style.display = 'none';
                modal.innerHTML = '';
                resolve(value);
            }

            if (cancelBtn) {
                cancelBtn.addEventListener('click', function () { close(null); });
            }
            if (confirmBtn) {
                confirmBtn.addEventListener('click', function () {
                    close(inputEl ? inputEl.value.trim() : true);
                });
            }

            // Allow Enter key on input.
            if (inputEl) {
                inputEl.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        close(inputEl.value.trim());
                    }
                });
                setTimeout(function () { inputEl.focus(); }, 50);
            }

            // Click backdrop to cancel.
            backdrop.addEventListener('click', function (e) {
                if (e.target === backdrop) close(null);
            });
        });
    }

    function modalConfirm(container, message) {
        return showModal(container,
            '<div class="wpnc-modal-body">' +
            '  <p>' + escHtml(message) + '</p>' +
            '  <div class="wpnc-modal-actions">' +
            '    <button type="button" class="wpnc-btn wpnc-btn-cancel" data-action="cancel">' + escHtml(I18N.cancel || 'Cancel') + '</button>' +
            '    <button type="button" class="wpnc-btn wpnc-btn-danger" data-action="confirm">' + escHtml(I18N.delete || 'Delete') + '</button>' +
            '  </div>' +
            '</div>'
        );
    }

    function modalPrompt(container, label, placeholder) {
        return showModal(container,
            '<div class="wpnc-modal-body">' +
            '  <label class="wpnc-modal-label">' + escHtml(label) + '</label>' +
            '  <input type="text" data-modal-input class="wpnc-input" placeholder="' + escHtml(placeholder || '') + '" style="font-size:16px" />' +
            '  <div class="wpnc-modal-actions">' +
            '    <button type="button" class="wpnc-btn wpnc-btn-cancel" data-action="cancel">' + escHtml(I18N.cancel || 'Cancel') + '</button>' +
            '    <button type="button" class="wpnc-btn wpnc-btn-primary" data-action="confirm">' + escHtml(I18N.create || 'Create') + '</button>' +
            '  </div>' +
            '</div>'
        );
    }

    function modalAlert(container, message, isError) {
        return showModal(container,
            '<div class="wpnc-modal-body">' +
            '  <p class="' + (isError ? 'wpnc-text-error' : '') + '">' + escHtml(message) + '</p>' +
            '  <div class="wpnc-modal-actions">' +
            '    <button type="button" class="wpnc-btn wpnc-btn-primary" data-action="confirm">OK</button>' +
            '  </div>' +
            '</div>'
        );
    }

    /* ================================================================
     *  TOAST NOTIFICATION
     * ============================================================= */

    function showToast(container, message, type) {
        type = type || 'info';
        const toast = document.createElement('div');
        toast.className = 'wpnc-toast wpnc-toast-' + type;
        toast.textContent = message;
        container.appendChild(toast);

        // Animate in.
        requestAnimationFrame(function () {
            toast.classList.add('wpnc-toast-show');
        });

        // Remove after 3s.
        setTimeout(function () {
            toast.classList.remove('wpnc-toast-show');
            setTimeout(function () { toast.remove(); }, 300);
        }, 3000);
    }

    /* ================================================================
     *  FILE BROWSER CLASS
     * ============================================================= */

    function FileBrowser(el) {
        this.root       = el;
        this.currentPath = el.dataset.initialPath || '';
        this.allowUpload = el.dataset.allowUpload === '1';
        this.allowMkdir  = el.dataset.allowMkdir === '1';
        this.allowDelete = el.dataset.allowDelete === '1';
        this.items       = [];
        this.uploading   = false;

        // DOM refs.
        this.breadcrumb  = el.querySelector('.wpnc-breadcrumb');
        this.fileList    = el.querySelector('.wpnc-file-list');
        this.emptyEl     = el.querySelector('.wpnc-empty');
        this.errorEl     = el.querySelector('.wpnc-error');
        this.progressEl  = el.querySelector('.wpnc-upload-progress');
        this.dropOverlay = el.querySelector('.wpnc-dropzone-overlay');

        // Buttons.
        this.btnUpload   = el.querySelector('.wpnc-btn-upload');
        this.fileInput   = el.querySelector('.wpnc-file-input');
        this.btnMkdir    = el.querySelector('.wpnc-btn-mkdir');

        this.init();
    }

    FileBrowser.prototype.init = function () {
        var self = this;

        // Load initial directory.
        this.navigate(this.currentPath);

        // New folder button.
        if (this.btnMkdir) {
            this.btnMkdir.addEventListener('click', function () {
                self.onNewFolder();
            });
        }

        // Upload button → file input.
        if (this.btnUpload && this.fileInput) {
            this.btnUpload.addEventListener('click', function () {
                self.fileInput.click();
            });
            this.fileInput.addEventListener('change', function () {
                if (this.files.length > 0) {
                    self.onFilesSelected(Array.from(this.files));
                    this.value = '';
                }
            });
        }

        // Drag & drop.
        if (this.allowUpload) {
            this.initDragDrop();
        }
    };

    /* ── Navigation ─────────────────────────────────────────── */

    FileBrowser.prototype.navigate = function (path) {
        var self = this;
        this.currentPath = path;
        this.showLoading();

        wpncRequest('wpnc_browse', { path: path })
            .then(function (data) {
                self.items = data.items || [];
                self.renderBreadcrumb();
                self.renderFileList();
            })
            .catch(function (err) {
                self.showError(err.message);
            });
    };

    /* ── Breadcrumb ─────────────────────────────────────────── */

    FileBrowser.prototype.renderBreadcrumb = function () {
        var self = this;
        var html = '';
        var segments = this.currentPath ? this.currentPath.split('/').filter(Boolean) : [];

        // Root link.
        html += '<span class="wpnc-crumb wpnc-crumb-link" data-path="">' + escHtml(I18N.root || 'Root') + '</span>';

        // Each segment.
        var accumulated = '';
        segments.forEach(function (seg, i) {
            accumulated += (accumulated ? '/' : '') + seg;
            html += '<span class="wpnc-crumb-sep">/</span>';
            if (i === segments.length - 1) {
                html += '<span class="wpnc-crumb wpnc-crumb-current">' + escHtml(seg) + '</span>';
            } else {
                html += '<span class="wpnc-crumb wpnc-crumb-link" data-path="' + escHtml(accumulated) + '">' + escHtml(seg) + '</span>';
            }
        });

        this.breadcrumb.innerHTML = html;

        // Bind click events.
        this.breadcrumb.querySelectorAll('.wpnc-crumb-link').forEach(function (el) {
            el.addEventListener('click', function () {
                self.navigate(el.dataset.path);
            });
        });
    };

    /* ── File List Rendering ────────────────────────────────── */

    FileBrowser.prototype.renderFileList = function () {
        var self = this;
        this.errorEl.style.display = 'none';

        if (this.items.length === 0) {
            this.fileList.innerHTML = '';
            this.emptyEl.style.display = 'block';
            return;
        }

        this.emptyEl.style.display = 'none';

        // Sort: folders first, then alphabetical.
        this.items.sort(function (a, b) {
            if (a.type !== b.type) return a.type === 'folder' ? -1 : 1;
            return a.name.localeCompare(b.name);
        });

        var html = '<table class="wpnc-table">';
        html += '<thead><tr>';
        html += '<th class="wpnc-col-icon"></th>';
        html += '<th class="wpnc-col-name">Name</th>';
        html += '<th class="wpnc-col-size">Size</th>';
        html += '<th class="wpnc-col-date">Modified</th>';
        html += '<th class="wpnc-col-actions"></th>';
        html += '</tr></thead><tbody>';

        this.items.forEach(function (item) {
            var fullPath = self.currentPath ? self.currentPath.replace(/\/$/, '') + '/' + item.name : item.name;
            var isFolder = item.type === 'folder';

            html += '<tr class="wpnc-row' + (isFolder ? ' wpnc-row-folder' : ' wpnc-row-file') + '" data-path="' + escHtml(fullPath) + '" data-type="' + item.type + '">';

            // Icon.
            html += '<td class="wpnc-col-icon"><span class="wpnc-file-icon">' + fileIcon(item) + '</span></td>';

            // Name (clickable for folders).
            html += '<td class="wpnc-col-name">';
            if (isFolder) {
                html += '<a class="wpnc-folder-link" href="javascript:void(0)" data-nav="' + escHtml(fullPath) + '">' + escHtml(item.name) + '</a>';
            } else {
                html += '<span class="wpnc-file-name">' + escHtml(item.name) + '</span>';
            }
            html += '</td>';

            // Size.
            html += '<td class="wpnc-col-size">' + (isFolder ? '—' : humanSize(item.size)) + '</td>';

            // Date.
            html += '<td class="wpnc-col-date">' + formatDate(item.modified) + '</td>';

            // Actions.
            html += '<td class="wpnc-col-actions">';
            if (!isFolder) {
                html += '<button class="wpnc-action-btn" data-action="download" data-path="' + escHtml(fullPath) + '" title="' + escHtml(I18N.download || 'Download') + '">⬇️</button>';
                html += '<button class="wpnc-action-btn" data-action="share" data-path="' + escHtml(fullPath) + '" title="' + escHtml(I18N.share || 'Share') + '">🔗</button>';
            }
            if (self.allowDelete) {
                html += '<button class="wpnc-action-btn wpnc-action-delete" data-action="delete" data-path="' + escHtml(fullPath) + '" data-name="' + escHtml(item.name) + '" title="' + escHtml(I18N.delete || 'Delete') + '">🗑️</button>';
            }
            html += '</td>';

            html += '</tr>';
        });

        html += '</tbody></table>';
        this.fileList.innerHTML = html;

        // Bind events.
        this.fileList.querySelectorAll('[data-nav]').forEach(function (el) {
            el.addEventListener('click', function (e) {
                e.preventDefault();
                self.navigate(el.dataset.nav);
            });
        });

        this.fileList.querySelectorAll('[data-action]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var action = btn.dataset.action;
                var path   = btn.dataset.path;
                var name   = btn.dataset.name;
                if (action === 'download') self.onDownload(path);
                if (action === 'share')    self.onShare(path);
                if (action === 'delete')   self.onDelete(path, name);
            });
        });
    };

    /* ── Loading / Error states ─────────────────────────────── */

    FileBrowser.prototype.showLoading = function () {
        this.fileList.innerHTML = '<div class="wpnc-loading"><span class="wpnc-spinner"></span> ' + escHtml(I18N.loading || 'Loading…') + '</div>';
        this.emptyEl.style.display = 'none';
        this.errorEl.style.display = 'none';
    };

    FileBrowser.prototype.showError = function (msg) {
        this.fileList.innerHTML = '';
        this.emptyEl.style.display = 'none';
        this.errorEl.style.display = 'block';
        this.errorEl.innerHTML = '<p class="wpnc-error-text">❌ ' + escHtml(msg) + '</p>';
    };

    /* ── Download ────────────────────────────────────────────── */

    FileBrowser.prototype.onDownload = function (path) {
        var url = AJAX + '?action=wpnc_download_proxy&_nonce=' + encodeURIComponent(NONCE) + '&path=' + encodeURIComponent(path);
        // Trigger download via hidden link.
        var a = document.createElement('a');
        a.href = url;
        a.download = path.split('/').pop();
        a.style.display = 'none';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    };

    /* ── Share ────────────────────────────────────────────────── */

    FileBrowser.prototype.onShare = function (path) {
        var self = this;
        wpncRequest('wpnc_get_public_url', { path: path })
            .then(function (data) {
                // Copy to clipboard.
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(data.url).then(function () {
                        showToast(self.root, I18N.copied || 'Link copied!', 'success');
                    });
                } else {
                    // Fallback: show in modal.
                    modalAlert(self.root, data.url);
                }
            })
            .catch(function (err) {
                showToast(self.root, err.message, 'error');
            });
    };

    /* ── Delete ──────────────────────────────────────────────── */

    FileBrowser.prototype.onDelete = function (path, name) {
        var self = this;
        var msg = (I18N.confirm_delete || 'Delete "%s"? This cannot be undone.').replace('%s', name);

        modalConfirm(this.root, msg).then(function (confirmed) {
            if (!confirmed) return;

            wpncRequest('wpnc_delete', { path: path })
                .then(function () {
                    showToast(self.root, name + ' deleted', 'success');
                    self.navigate(self.currentPath); // Refresh.
                })
                .catch(function (err) {
                    showToast(self.root, err.message, 'error');
                });
        });
    };

    /* ── New Folder ──────────────────────────────────────────── */

    FileBrowser.prototype.onNewFolder = function () {
        var self = this;

        modalPrompt(this.root, I18N.new_folder || 'New folder', I18N.folder_name || 'Folder name')
            .then(function (name) {
                if (!name) return;

                var fullPath = self.currentPath ? self.currentPath.replace(/\/$/, '') + '/' + name : name;

                wpncRequest('wpnc_create_folder', { path: fullPath })
                    .then(function () {
                        showToast(self.root, '📁 ' + name, 'success');
                        self.navigate(self.currentPath);
                    })
                    .catch(function (err) {
                        showToast(self.root, err.message, 'error');
                    });
            });
    };

    /* ── Drag & Drop ─────────────────────────────────────────── */

    FileBrowser.prototype.initDragDrop = function () {
        var self = this;
        var dragCounter = 0;

        this.root.addEventListener('dragenter', function (e) {
            e.preventDefault();
            dragCounter++;
            if (self.dropOverlay) self.dropOverlay.classList.add('wpnc-dropzone-active');
        });

        this.root.addEventListener('dragleave', function (e) {
            e.preventDefault();
            dragCounter--;
            if (dragCounter <= 0) {
                dragCounter = 0;
                if (self.dropOverlay) self.dropOverlay.classList.remove('wpnc-dropzone-active');
            }
        });

        this.root.addEventListener('dragover', function (e) {
            e.preventDefault();
        });

        this.root.addEventListener('drop', function (e) {
            e.preventDefault();
            dragCounter = 0;
            if (self.dropOverlay) self.dropOverlay.classList.remove('wpnc-dropzone-active');

            var files = Array.from(e.dataTransfer.files || []);
            if (files.length > 0) {
                self.onFilesSelected(files);
            }
        });
    };

    /* ================================================================
     *  UPLOAD ENGINE (chunked for large files)
     * ============================================================= */

    FileBrowser.prototype.onFilesSelected = function (files) {
        var self = this;
        if (this.uploading) return;
        this.uploading = true;

        // Show progress area.
        this.progressEl.style.display = 'block';
        this.progressEl.innerHTML = '';

        // Process files sequentially to avoid overwhelming the server.
        var queue = files.slice();
        (function next() {
            if (queue.length === 0) {
                self.uploading = false;
                setTimeout(function () {
                    self.progressEl.style.display = 'none';
                }, 2000);
                self.navigate(self.currentPath); // Refresh file list.
                return;
            }

            var file = queue.shift();
            self.uploadFile(file).then(next).catch(function (err) {
                showToast(self.root, file.name + ': ' + err.message, 'error');
                next();
            });
        })();
    };

    /**
     * Upload a single file. Uses simple upload for small files,
     * chunked upload (3-step) for large files.
     */
    FileBrowser.prototype.uploadFile = function (file) {
        var self = this;

        // Create progress bar for this file.
        var row = document.createElement('div');
        row.className = 'wpnc-upload-row';
        row.innerHTML =
            '<div class="wpnc-upload-name">' + escHtml(file.name) + ' <span class="wpnc-upload-size">(' + humanSize(file.size) + ')</span></div>' +
            '<div class="wpnc-progress-bar"><div class="wpnc-progress-fill" style="width:0%"></div></div>' +
            '<div class="wpnc-upload-status">' + escHtml(I18N.preparing || 'Preparing…') + '</div>';
        this.progressEl.appendChild(row);

        var fillEl   = row.querySelector('.wpnc-progress-fill');
        var statusEl = row.querySelector('.wpnc-upload-status');

        function setProgress(pct) {
            fillEl.style.width = Math.min(100, Math.round(pct)) + '%';
        }

        function setStatus(text) {
            statusEl.textContent = text;
        }

        // Simple upload for files under chunk size.
        if (file.size <= CHUNK) {
            return new Promise(function (resolve, reject) {
                setStatus(I18N.uploading || 'Uploading…');
                wpncRequest('wpnc_upload_simple', { path: self.currentPath }, {
                    file: file,
                    onProgress: function (e) {
                        if (e.lengthComputable) setProgress((e.loaded / e.total) * 100);
                    }
                })
                .then(function (data) {
                    setProgress(100);
                    setStatus('✅ ' + (I18N.upload_done || 'Done'));
                    row.classList.add('wpnc-upload-done');
                    resolve(data);
                })
                .catch(function (err) {
                    setStatus('❌ ' + err.message);
                    row.classList.add('wpnc-upload-fail');
                    reject(err);
                });
            });
        }

        // Chunked upload.
        return self.uploadChunked(file, setProgress, setStatus, row);
    };

    /**
     * Three-step chunked upload:
     *   1. wpnc_upload_init  → get upload_id
     *   2. wpnc_upload_chunk → loop through slices
     *   3. wpnc_upload_finish → assemble on NC
     */
    FileBrowser.prototype.uploadChunked = function (file, setProgress, setStatus, row) {
        var self = this;
        var totalChunks = Math.ceil(file.size / CHUNK);

        return new Promise(function (resolve, reject) {
            // Step 1: Init.
            setStatus(I18N.preparing || 'Preparing…');

            wpncRequest('wpnc_upload_init', {
                filename: file.name,
                path: self.currentPath
            })
            .then(function (initData) {
                var uploadId = initData.upload_id;
                var offset = 0;
                var chunkIndex = 0;

                // Step 2: Send chunks sequentially.
                function sendChunk() {
                    if (offset >= file.size) {
                        // Step 3: Assemble.
                        setStatus('Assembling…');
                        wpncRequest('wpnc_upload_finish', {
                            upload_id: uploadId,
                            filename: file.name,
                            path: self.currentPath
                        })
                        .then(function (data) {
                            setProgress(100);
                            setStatus('✅ ' + (I18N.upload_done || 'Done'));
                            row.classList.add('wpnc-upload-done');
                            resolve(data);
                        })
                        .catch(reject);
                        return;
                    }

                    var end = Math.min(offset + CHUNK, file.size);
                    var blob = file.slice(offset, end);
                    var chunkNum = chunkIndex + 1;

                    // Wrap blob as a File for FormData.
                    var chunkFile = new File([blob], 'chunk', { type: 'application/octet-stream' });

                    var pctMsg = (I18N.chunk_progress || 'Uploading %1$s: %2$d%%')
                        .replace('%1$s', file.name)
                        .replace('%2$d', Math.round((offset / file.size) * 100));
                    setStatus(pctMsg + ' (' + chunkNum + '/' + totalChunks + ')');

                    wpncRequest('wpnc_upload_chunk', {
                        upload_id: uploadId,
                        offset: String(offset)
                    }, {
                        file: chunkFile,
                        fileField: 'chunk',
                        fileName: 'chunk',
                        onProgress: function (e) {
                            if (e.lengthComputable) {
                                // Overall progress = bytes completed so far + this chunk's progress.
                                var done = offset + e.loaded;
                                setProgress((done / file.size) * 100);
                            }
                        }
                    })
                    .then(function () {
                        offset = end;
                        chunkIndex++;
                        sendChunk();
                    })
                    .catch(function (err) {
                        setStatus('❌ ' + err.message);
                        row.classList.add('wpnc-upload-fail');
                        reject(err);
                    });
                }

                sendChunk();
            })
            .catch(function (err) {
                setStatus('❌ ' + err.message);
                row.classList.add('wpnc-upload-fail');
                reject(err);
            });
        });
    };

    /* ================================================================
     *  BOOTSTRAP — Init all [data-wpnc-browser] on the page
     * ============================================================= */

    function init() {
        document.querySelectorAll('[data-wpnc-browser]').forEach(function (el) {
            new FileBrowser(el);
        });
    }

    // Run on DOM ready.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
