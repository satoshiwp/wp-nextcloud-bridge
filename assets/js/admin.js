/**
 * WP Nextcloud Bridge — Admin JavaScript
 *
 * Three modules, each self-contained:
 *   1. ConnectionTester — "Test Connection" button
 *   2. FileBrowser      — interactive Nextcloud file browser
 *   3. SyncRunner       — "Sync Now" button with live log
 *
 * All AJAX calls go through a single `wpncRequest()` helper
 * that handles nonce injection, error display, and loading states.
 */
(function ($) {
  "use strict";

  /* ================================================================
   *  AJAX HELPER
   * ============================================================= */

  /**
   * Send an AJAX request to the WPNC backend.
   *
   * @param {string}   action  WordPress AJAX action name.
   * @param {Object}   data    Extra POST parameters.
   * @returns {Promise<Object>} Resolves with response.data on success.
   */
  function wpncRequest(action, data) {
    var payload = $.extend(
      {
        action: action,
        _nonce: wpncAdmin.nonce,
      },
      data || {}
    );

    return $.post(wpncAdmin.ajaxUrl, payload).then(
      function (resp) {
        if (resp && resp.success) {
          return resp.data;
        }
        var msg =
          (resp && resp.data) || wpncAdmin.i18n.error || "Unknown error";
        return $.Deferred().reject(msg).promise();
      },
      function (xhr) {
        var msg = xhr.statusText || wpncAdmin.i18n.error;
        return $.Deferred().reject(msg).promise();
      }
    );
  }

  /* ================================================================
   *  1. CONNECTION TESTER
   * ============================================================= */

  function initConnectionTester() {
    var $btn = $("#wpnc-test-btn");
    var $result = $("#wpnc-test-result");

    if (!$btn.length) return;

    $btn.on("click", function () {
      $btn.prop("disabled", true);
      $result
        .text(wpncAdmin.i18n.testing)
        .css("color", "#666")
        .removeClass("notice-success notice-error");

      wpncRequest("wpnc_test_connection")
        .then(function (data) {
          $result
            .text("✅ " + (data || wpncAdmin.i18n.success))
            .css("color", "#00a32a");
        })
        .fail(function (err) {
          $result
            .text("❌ " + err)
            .css("color", "#d63638");
        })
        .always(function () {
          $btn.prop("disabled", false);
        });
    });
  }

  /* ================================================================
   *  2. FILE BROWSER
   * ============================================================= */

  function initFileBrowser() {
    var $root = $("#wpnc-browser-root");
    if (!$root.length) return;

    // State: current path segments for breadcrumb.
    var currentPath = "";

    // Render wrapper.
    $root.html(
      '<div class="wpnc-browser">' +
        '  <div class="wpnc-breadcrumb"></div>' +
        '  <div class="wpnc-file-list"></div>' +
        '  <div class="wpnc-browser-status"></div>' +
        "</div>"
    );

    var $breadcrumb = $root.find(".wpnc-breadcrumb");
    var $list = $root.find(".wpnc-file-list");
    var $status = $root.find(".wpnc-browser-status");

    // Load a folder.
    function loadFolder(path) {
      currentPath = path || "";
      $status.text(wpncAdmin.i18n.loading).show();
      $list.empty();

      wpncRequest("wpnc_browse", { path: currentPath })
        .then(function (data) {
          $status.hide();
          renderBreadcrumb(currentPath);
          renderItems(data.items || []);
        })
        .fail(function (err) {
          $status.text("❌ " + err).show();
        });
    }

    // Breadcrumb.
    function renderBreadcrumb(path) {
      var segments = path ? path.split("/").filter(Boolean) : [];
      var html = '<a href="#" class="wpnc-crumb" data-path="">🏠 Root</a>';
      var built = "";

      for (var i = 0; i < segments.length; i++) {
        built += (built ? "/" : "") + segments[i];
        html +=
          ' <span class="wpnc-crumb-sep">›</span> ' +
          '<a href="#" class="wpnc-crumb" data-path="' +
          escAttr(built) +
          '">' +
          escHtml(segments[i]) +
          "</a>";
      }

      $breadcrumb.html(html);
    }

    // Item list.
    function renderItems(items) {
      if (!items.length) {
        $list.html(
          '<div class="wpnc-empty">' +
            wpncAdmin.i18n.empty_folder +
            "</div>"
        );
        return;
      }

      // Sort: folders first, then alphabetical.
      items.sort(function (a, b) {
        if (a.type !== b.type) return a.type === "folder" ? -1 : 1;
        return a.name.localeCompare(b.name);
      });

      var html = '<table class="widefat wpnc-items-table">';
      html += "<thead><tr>";
      html += "<th>Name</th><th>Type</th><th>Size</th><th>Actions</th>";
      html += "</tr></thead><tbody>";

      for (var i = 0; i < items.length; i++) {
        var it = items[i];
        var icon = it.type === "folder" ? "📁" : fileIcon(it.mime);
        var itemPath = currentPath
          ? currentPath + "/" + it.name
          : it.name;

        html += "<tr>";

        // Name column.
        if (it.type === "folder") {
          html +=
            '<td><a href="#" class="wpnc-folder-link" data-path="' +
            escAttr(itemPath) +
            '">' +
            icon +
            " " +
            escHtml(it.name) +
            "</a></td>";
        } else {
          html += "<td>" + icon + " " + escHtml(it.name) + "</td>";
        }

        // Type column.
        html +=
          "<td>" + escHtml(it.type === "folder" ? "Folder" : it.mime || "—") + "</td>";

        // Size column.
        html +=
          "<td>" +
          (it.type === "file" ? humanSize(it.size) : "—") +
          "</td>";

        // Actions column.
        html += "<td>";
        if (it.type === "file") {
          html +=
            '<button class="button button-small wpnc-share-btn" data-path="' +
            escAttr(itemPath) +
            '">🔗 Share</button> ';
          html +=
            '<button class="button button-small wpnc-del-btn" data-path="' +
            escAttr(itemPath) +
            '">🗑</button>';
        } else {
          html +=
            '<button class="button button-small wpnc-del-btn" data-path="' +
            escAttr(itemPath) +
            '">🗑</button>';
        }
        html += "</td></tr>";
      }

      html += "</tbody></table>";
      $list.html(html);
    }

    // Delegated events.
    $root.on("click", ".wpnc-crumb", function (e) {
      e.preventDefault();
      loadFolder($(this).data("path"));
    });

    $root.on("click", ".wpnc-folder-link", function (e) {
      e.preventDefault();
      loadFolder($(this).data("path"));
    });

    $root.on("click", ".wpnc-share-btn", function () {
      var $btn = $(this);
      var path = $btn.data("path");
      $btn.prop("disabled", true).text("…");

      wpncRequest("wpnc_get_public_url", { path: path })
        .then(function (data) {
          // Copy to clipboard.
          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(data.url);
            $btn.text("✅ Copied!");
          } else {
            window.prompt("Share URL:", data.url);
            $btn.text("🔗 Share");
          }
        })
        .fail(function (err) {
          $btn.text("❌ Error");
          $status.text(err).show();
        })
        .always(function () {
          setTimeout(function () {
            $btn.prop("disabled", false).text("🔗 Share");
          }, 2000);
        });
    });

    $root.on("click", ".wpnc-del-btn", function () {
      var $btn = $(this);
      var path = $btn.data("path");

      // Simple confirmation via inline approach (no native confirm dialog).
      if ($btn.data("confirming")) {
        $btn.prop("disabled", true).text("…");
        wpncRequest("wpnc_delete", { path: path })
          .then(function () {
            loadFolder(currentPath);
          })
          .fail(function (err) {
            $status.text("❌ " + err).show();
            $btn.prop("disabled", false).text("🗑").data("confirming", false);
          });
      } else {
        $btn.text("Sure?").css("color", "#d63638").data("confirming", true);
        setTimeout(function () {
          $btn.text("🗑").css("color", "").data("confirming", false);
        }, 3000);
      }
    });

    // Initial load.
    loadFolder("");
  }

  /* ================================================================
   *  3. SYNC RUNNER
   * ============================================================= */

  function initSyncRunner() {
    var $btn = $("#wpnc-sync-btn");
    var $result = $("#wpnc-sync-result");
    var $log = $("#wpnc-sync-log");

    if (!$btn.length) return;

    $btn.on("click", function () {
      $btn.prop("disabled", true);
      $result.text(wpncAdmin.i18n.syncing).css("color", "#666");
      $log.empty().show();

      wpncRequest("wpnc_sync_start")
        .then(function (data) {
          $result
            .text("✅ " + wpncAdmin.i18n.success)
            .css("color", "#00a32a");

          var lines = data.log || [];
          var html = "";
          for (var i = 0; i < lines.length; i++) {
            var cls = "wpnc-log-line";
            if (lines[i].indexOf("✗") === 0) cls += " wpnc-log-error";
            else if (lines[i].indexOf("⚠") === 0) cls += " wpnc-log-warn";
            else if (lines[i].indexOf("↑") === 0) cls += " wpnc-log-upload";
            else if (lines[i].indexOf("+") === 0) cls += " wpnc-log-create";

            html += '<div class="' + cls + '">' + escHtml(lines[i]) + "</div>";
          }
          $log.html(html);

          // Refresh file browser if present.
          var $crumbRoot = $(".wpnc-crumb[data-path='']");
          if ($crumbRoot.length) $crumbRoot.trigger("click");
        })
        .fail(function (err) {
          $result
            .text("❌ " + err)
            .css("color", "#d63638");
        })
        .always(function () {
          $btn.prop("disabled", false);
        });
    });
  }

  /* ================================================================
   *  4. SYNC DIR ROWS (dynamic add/remove)
   * ============================================================= */

  function initSyncDirRows() {
    var $wrap = $("#wpnc-sync-dirs-wrap");
    if (!$wrap.length) return;

    // Add row.
    $wrap.on("click", ".wpnc-add-row", function () {
      var $tbody = $("#wpnc-sync-rows");
      var idx = $tbody.find("tr").length;
      var html =
        "<tr>" +
        '<td><input type="text" name="wpnc_settings[sync_dirs][' +
        idx +
        '][local]" value="" class="regular-text" placeholder="wp-content/uploads" /></td>' +
        '<td><input type="text" name="wpnc_settings[sync_dirs][' +
        idx +
        '][remote]" value="" class="regular-text" placeholder="uploads" /></td>' +
        '<td><button type="button" class="button wpnc-remove-row">&times;</button></td>' +
        "</tr>";
      $tbody.append(html);
    });

    // Remove row.
    $wrap.on("click", ".wpnc-remove-row", function () {
      $(this).closest("tr").remove();
    });
  }

  /* ================================================================
   *  UTILITY FUNCTIONS
   * ============================================================= */

  function escHtml(str) {
    if (!str) return "";
    var div = document.createElement("div");
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
  }

  function escAttr(str) {
    return escHtml(str)
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function humanSize(bytes) {
    if (!bytes || bytes === 0) return "0 B";
    var units = ["B", "KB", "MB", "GB"];
    var i = 0;
    var size = bytes;
    while (size >= 1024 && i < units.length - 1) {
      size /= 1024;
      i++;
    }
    return size.toFixed(i > 0 ? 1 : 0) + " " + units[i];
  }

  function fileIcon(mime) {
    if (!mime) return "📄";
    if (mime.indexOf("image/") === 0) return "🖼️";
    if (mime.indexOf("video/") === 0) return "🎬";
    if (mime.indexOf("audio/") === 0) return "🎵";
    if (mime.indexOf("pdf") !== -1) return "📕";
    if (
      mime.indexOf("zip") !== -1 ||
      mime.indexOf("tar") !== -1 ||
      mime.indexOf("gz") !== -1
    )
      return "📦";
    if (
      mime.indexOf("text/") === 0 ||
      mime.indexOf("json") !== -1 ||
      mime.indexOf("xml") !== -1
    )
      return "📝";
    return "📄";
  }

  /* ================================================================
   *  BOOT
   * ============================================================= */

  $(function () {
    initConnectionTester();
    initFileBrowser();
    initSyncRunner();
    initSyncDirRows();
  });
})(jQuery);
