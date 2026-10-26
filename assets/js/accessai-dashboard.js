/**
 * AccessAI Dashboard — Main JavaScript
 * Handles: auto AI explanations, contrast scan iframe flow, manual AI form, apply-fix.
 * API keys are never present here. All sensitive operations go through server-side AJAX.
 */
(function () {
    "use strict";

    var cfg = window.AccessAI_Config || {};
    var ajaxUrl = cfg.ajaxUrl || "";
    var nonce = cfg.nonce || "";
    var scanNonce = cfg.scanNonce || "";
    var hasApiKey = !!cfg.hasApiKey;

    /* ------------------------------------------------------------------ */
    /* Utility: AJAX POST via fetch                                         */
    /* ------------------------------------------------------------------ */
    function parseJsonResponse(r) {
        return r.text().then(function (text) {
            try {
                return JSON.parse(text);
            } catch (e) {
                return {
                    success: false,
                    data: { message: "Server connection momentarily busy. Please click Retry." }
                };
            }
        });
    }

    function ajaxPost(action, data) {
        var body = new URLSearchParams({ action: action, nonce: nonce, ...data });
        return fetch(ajaxUrl, {
            method: "POST",
            credentials: "same-origin",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: body.toString()
        }).then(parseJsonResponse);
    }

    function scanAjaxPost(action, data) {
        var body = new URLSearchParams({ action: action, nonce: scanNonce, ...data });
        return fetch(ajaxUrl, {
            method: "POST",
            credentials: "same-origin",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: body.toString()
        }).then(parseJsonResponse);
    }

    /* ------------------------------------------------------------------ */
    /* Auto-explain: collect all issue rows from tables, batch to AJAX      */
    /* ------------------------------------------------------------------ */
    function collectIssues(tableId) {
        var table = document.getElementById(tableId);
        if (!table) return [];
        var raw = table.dataset.issues;
        try { return JSON.parse(raw) || []; } catch (e) { return []; }
    }

    function attachRetry(cell, tableId, fp) {
        var retryBtn = cell.querySelector(".accessai-retry-btn");
        if (!retryBtn) return;

        retryBtn.addEventListener("click", function () {
            cell.innerHTML = '<span class="accessai-spinner" aria-label="Loading AI explanation"></span>';
            var issues = collectIssues(tableId);
            var targetIssue = null;
            for (var i = 0; i < issues.length; i++) {
                if (issues[i].fingerprint === fp) {
                    targetIssue = issues[i];
                    break;
                }
            }
            if (!targetIssue) {
                cell.innerHTML = '<span class="accessai-ai-error">Finding data not found.</span>';
                return;
            }

            ajaxPost("accessai_auto_explain_issues", {
                issues: JSON.stringify([targetIssue])
            }).then(function (resp) {
                if (resp.success && resp.data && resp.data.results && resp.data.results[fp]) {
                    renderExplanation(cell, resp.data.results[fp], tableId, fp);
                } else {
                    renderExplanation(
                        cell,
                        { error: (resp.data && resp.data.message) || "Retry failed." },
                        tableId,
                        fp
                    );
                }
            }).catch(function (err) {
                renderExplanation(cell, { error: err.message }, tableId, fp);
            });
        });
    }

    function renderExplanation(cell, result, tableId, fp) {
        if (result.error) {
            cell.innerHTML =
                '<details class="accessai-explain">' +
                '<summary style="color:#b91c1c;">⚠ AI notice — click for details</summary>' +
                '<div class="accessai-explain-body" style="border-left-color:#b91c1c;">' +
                '<p style="color:#b91c1c;font-size:0.82rem;margin-bottom:8px;">' + esc(result.error) + '</p>' +
                '<button type="button" class="button button-secondary accessai-retry-btn" style="font-size:0.8rem;height:26px;line-height:24px;">↻ Retry AI</button>' +
                '</div></details>';

            attachRetry(cell, tableId, fp);
            return;
        }

        var isAltTable = (tableId === "accessai-alt-table");
        var hasProposedAlt = isAltTable && result.proposed_alt_text && result.proposed_alt_text.trim() !== "";
        var isFallback = !!result.fallback;
        var row = cell.closest("tr");

        var summaryTitle = isFallback
            ? "Standard WCAG Guideline &amp; Fix"
            : "AI Recommendation &amp; Fix";

        var html =
            '<details class="accessai-explain" open>' +
            '<summary>' + summaryTitle + '</summary>' +
            '<div class="accessai-explain-body">' +
            '<p><strong>What it means:</strong> ' + esc(result.explanation) + '</p>' +
            '<p><strong>Who is affected:</strong> ' + esc(result.impact) + '</p>' +
            '<p><strong>Suggested fix:</strong> ' + esc(result.suggested_fix) + '</p>';

        if (hasProposedAlt) {
            html +=
                '<div class="accessai-eligible-fix-box">' +
                '<span class="accessai-fix-badge">Eligible Automated Fix</span>' +
                '<label class="accessai-fix-label">' +
                '<strong>Reviewed Alt Text:</strong>' +
                '<input type="text" class="accessai-fix-alt-input" value="' + escAttr(result.proposed_alt_text) + '" maxlength="500" />' +
                '</label>' +
                '<div class="accessai-fix-actions">' +
                '<button type="button" class="button button-primary accessai-apply-row-btn">Apply Fix</button>' +
                '<span class="accessai-row-status" role="status" aria-live="polite"></span>' +
                '</div>' +
                '</div>';
        } else if (isAltTable) {
            html +=
                '<p class="accessai-fix-note"><em>Manual review required: Review the image context and update alt text manually in the page editor.</em></p>';
        } else {
            html +=
                '<p class="accessai-fix-note"><em>Manual review required: Review the suggested fix above and update the page in the editor.</em></p>';
        }

        if (isFallback) {
            html +=
                '<div style="margin-top:10px;padding-top:8px;border-top:1px dashed #c3c4c7;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">' +
                '<button type="button" class="button button-secondary accessai-retry-btn" style="font-size:0.8rem;height:26px;line-height:24px;">↻ Refresh with Gemini AI</button>' +
                '<span style="font-size:0.78rem;color:#646970;">Standard WCAG guideline loaded while Gemini was busy</span>' +
                '</div>';
        }

        html += '</div></details>';
        cell.innerHTML = html;

        if (isFallback) {
            attachRetry(cell, tableId, fp);
        }

        if (hasProposedAlt && row) {
            var applyBtn = cell.querySelector(".accessai-apply-row-btn");
            var altInput = cell.querySelector(".accessai-fix-alt-input");
            var statusEl = cell.querySelector(".accessai-row-status");

            if (applyBtn && altInput && statusEl) {
                applyBtn.addEventListener("click", function () {
                    var approvedAlt = altInput.value.trim();
                    if (!approvedAlt) {
                        statusEl.style.color = "#b91c1c";
                        statusEl.textContent = "Please enter alt text.";
                        return;
                    }

                    if (!window.confirm("Apply this reviewed alt text to the page image?")) {
                        return;
                    }

                    applyBtn.disabled = true;
                    altInput.disabled = true;
                    statusEl.style.color = "#2271b1";
                    statusEl.textContent = "Applying fix…";

                    ajaxPost("accessai_apply_fix", {
                        page_id: row.dataset.pageId || "",
                        image_src: row.dataset.imageSrc || "",
                        approved_alt: approvedAlt,
                        fingerprint: fp || row.dataset.fingerprint || ""
                    }).then(function (resp) {
                        if (resp.success) {
                            statusEl.style.color = "#186a3b";
                            statusEl.textContent = "✓ Fixed! Refreshing dashboard…";
                            row.classList.add("accessai-row-fixed");
                            setTimeout(function () {
                                window.location.reload();
                            }, 1200);
                        } else {
                            statusEl.style.color = "#b91c1c";
                            statusEl.textContent = "✗ " + ((resp.data && resp.data.message) || "Apply failed.");
                            applyBtn.disabled = false;
                            altInput.disabled = false;
                        }
                    }).catch(function (err) {
                        statusEl.style.color = "#b91c1c";
                        statusEl.textContent = "✗ Error: " + err.message;
                        applyBtn.disabled = false;
                        altInput.disabled = false;
                    });
                });
            }
        }
    }

    function autoExplainTable(tableId) {
        if (!hasApiKey) {
            // Remove spinners gracefully when no API key configured.
            var table = document.getElementById(tableId);
            if (!table) return;
            table.querySelectorAll(".accessai-ai-cell").forEach(function (cell) {
                cell.innerHTML = '<span class="accessai-ai-na">No API key</span>';
            });
            return;
        }

        var issues = collectIssues(tableId);
        if (!issues.length) return Promise.resolve();

        // Build fingerprint → row cell map.
        var table = document.getElementById(tableId);
        var cellMap = {};
        table.querySelectorAll("tr[data-fingerprint]").forEach(function (row) {
            cellMap[row.dataset.fingerprint] = row.querySelector(".accessai-ai-cell");
        });

        // Process 1 issue at a time with a gentle delay to stay safely under API rate limits.
        var batches = [];
        for (var i = 0; i < issues.length; i += 1) {
            batches.push(issues.slice(i, i + 1));
        }

        function fetchBatch(batch, isRetry) {
            return ajaxPost("accessai_auto_explain_issues", {
                issues: JSON.stringify(batch)
            }).then(function (resp) {
                if (resp.success && resp.data && resp.data.results) {
                    var results = resp.data.results;
                    if (!isRetry) {
                        var hasTransientError = false;
                        Object.keys(results).forEach(function (fp) {
                            var errStr = (results[fp] && results[fp].error) || "";
                            if (
                                errStr.indexOf("503") !== -1 ||
                                errStr.indexOf("timed out") !== -1 ||
                                errStr.indexOf("demand") !== -1
                            ) {
                                hasTransientError = true;
                            }
                        });
                        if (hasTransientError) {
                            return new Promise(function (resolve) { setTimeout(resolve, 2500); }).then(function () {
                                return fetchBatch(batch, true);
                            });
                        }
                    }

                    Object.keys(results).forEach(function (fp) {
                        if (cellMap[fp]) {
                            renderExplanation(cellMap[fp], results[fp], tableId, fp);
                        }
                    });
                } else {
                    if (!isRetry) {
                        return new Promise(function (resolve) { setTimeout(resolve, 2500); }).then(function () {
                            return fetchBatch(batch, true);
                        });
                    }
                    batch.forEach(function (issue) {
                        if (cellMap[issue.fingerprint]) {
                            renderExplanation(
                                cellMap[issue.fingerprint],
                                { error: (resp.data && resp.data.message) || "AI explanation error." },
                                tableId,
                                issue.fingerprint
                            );
                        }
                    });
                }
            }).catch(function (err) {
                if (!isRetry) {
                    return new Promise(function (resolve) { setTimeout(resolve, 2500); }).then(function () {
                        return fetchBatch(batch, true);
                    });
                }
                batch.forEach(function (issue) {
                    if (cellMap[issue.fingerprint]) {
                        renderExplanation(
                            cellMap[issue.fingerprint],
                            { error: err.message || "Network request failed." },
                            tableId,
                            issue.fingerprint
                        );
                    }
                });
            });
        }

        return batches.reduce(function (chain, batch) {
            return chain.then(function () {
                return new Promise(function (resolve) { setTimeout(resolve, 2500); });
            }).then(function () {
                return fetchBatch(batch, false);
            });
        }, Promise.resolve());
    }

    /* ------------------------------------------------------------------ */
    /* Full Page Audit: selector + background contrast iframe flow         */
    /* ------------------------------------------------------------------ */
    function initPageScan() {
        var scanBtn = document.getElementById("accessai-scan-btn");
        var clearBtn = document.getElementById("accessai-clear-contrast-btn");
        var pageSelect = document.getElementById("accessai-page-select");
        var statusEl = document.getElementById("accessai-scan-status");
        var iframeContainer = document.getElementById("accessai-iframe-container");
        var noContrastNotice = document.getElementById("accessai-no-contrast-notice");

        if (!scanBtn || !pageSelect) return;

        function setStatus(msg, cls) {
            statusEl.className = "accessai-scan-status " + (cls || "");
            statusEl.textContent = msg;
        }

        scanBtn.addEventListener("click", function () {
            var pageId = pageSelect.value;
            if (!pageId) { setStatus("Please select a published page first.", "is-error"); return; }

            var selectedTitle = pageSelect.options[pageSelect.selectedIndex]
                ? pageSelect.options[pageSelect.selectedIndex].text
                : "page";

            scanBtn.disabled = true;
            setStatus("Preparing audit session for \u201C" + selectedTitle + "\u201D…", "is-loading");

            // Step 1: get a signed scan token + page URL from server.
            scanAjaxPost("accessai_trigger_contrast_scan", { page_id: pageId })
                .then(function (resp) {
                    if (!resp.success) {
                        throw new Error((resp.data && resp.data.message) || "Could not start scan.");
                    }
                    var token = resp.data.token;
                    var pageUrl = resp.data.page_url;

                    setStatus("Initializing background checkpoints…", "is-loading");

                    // Step 2: store the token in user meta so wp_head injects the meta tag.
                    return scanAjaxPost("accessai_store_scan_token", { token: token })
                        .then(function () { return { token: token, pageUrl: pageUrl, pageId: pageId, title: selectedTitle }; });
                })
                .then(function (info) {
                    setStatus("Auditing \u201C" + info.title + "\u201D across all checkpoints (Alt Text, Headings, Forms, Contrast)…", "is-loading");

                    // Remove any previous iframe.
                    iframeContainer.innerHTML = "";
                    if (noContrastNotice) noContrastNotice.style.display = "none";

                    // Step 3: open hidden iframe for live computed colour contrast checks.
                    var iframe = document.createElement("iframe");
                    iframe.src = info.pageUrl;
                    iframe.className = "accessai-scan-frame";
                    iframe.setAttribute("aria-hidden", "true");
                    iframe.setAttribute("title", "Accessibility contrast scan frame");
                    iframeContainer.appendChild(iframe);

                    // Step 4: wait for live iframe analysis to finish, then reload dashboard for this page.
                    setTimeout(function () {
                        iframeContainer.innerHTML = "";
                        setStatus("Audit complete — loading results for \u201C" + info.title + "\u201D…", "is-ok");
                        setTimeout(function () {
                            window.location.href = window.location.pathname + "?page=accessai&scanned_page=" + encodeURIComponent(info.pageId);
                        }, 1200);
                    }, 18000);
                })
                .catch(function (err) {
                    setStatus("Scan failed: " + (err.message || "Unknown error."), "is-error");
                    scanBtn.disabled = false;
                });
        });

        if (clearBtn) {
            clearBtn.addEventListener("click", function () {
                if (!window.confirm("Clear the current page audit results?")) return;
                clearBtn.disabled = true;
                scanAjaxPost("accessai_clear_contrast_results", {})
                    .then(function () {
                        window.location.href = window.location.pathname + "?page=accessai";
                    })
                    .catch(function () {
                        setStatus("Could not clear results.", "is-error");
                        clearBtn.disabled = false;
                    });
            });
        }
    }

    /* ------------------------------------------------------------------ */
    /* HTML escape helpers                                                   */
    /* ------------------------------------------------------------------ */
    function esc(str) {
        return String(str || "")
            .replace(/&/g, "&amp;").replace(/</g, "&lt;")
            .replace(/>/g, "&gt;").replace(/"/g, "&quot;");
    }
    function escAttr(str) { return esc(str); }

    /* ------------------------------------------------------------------ */
    /* Test Gemini connection button                                         */
    /* ------------------------------------------------------------------ */
    function initTestButton() {
        var btn = document.getElementById("accessai-test-btn");
        var result = document.getElementById("accessai-test-result");
        if (!btn || !result) return;

        btn.addEventListener("click", function () {
            btn.disabled = true;
            result.style.color = "#646970";
            result.textContent = "Testing…";

            ajaxPost("accessai_test_connection", {})
                .then(function (resp) {
                    if (resp.success) {
                        result.style.color = "#186a3b";
                        result.textContent = "✓ " + resp.data.message;
                    } else {
                        result.style.color = "#b91c1c";
                        result.textContent = "✗ " + (resp.data && resp.data.message ? resp.data.message : "Unknown error");
                    }
                })
                .catch(function (err) {
                    result.style.color = "#b91c1c";
                    result.textContent = "✗ Network error: " + err.message;
                })
                .finally(function () {
                    btn.disabled = false;
                });
        });
    }

    /* ------------------------------------------------------------------ */
    /* Bootstrap                                                             */
    /* ------------------------------------------------------------------ */
    document.addEventListener("DOMContentLoaded", function () {
        initTestButton();
        initPageScan();

        // Auto-explain all active issue tables sequentially to avoid connection congestion.
        var tables = [
            "accessai-alt-table",
            "accessai-heading-table",
            "accessai-form-table",
            "accessai-contrast-table"
        ];
        tables.reduce(function (p, tableId) {
            return p.then(function () {
                return autoExplainTable(tableId);
            });
        }, Promise.resolve());
    });

})();
