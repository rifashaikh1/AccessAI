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
    function ajaxPost(action, data) {
        var body = new URLSearchParams({ action: action, nonce: nonce, ...data });
        return fetch(ajaxUrl, {
            method: "POST",
            credentials: "same-origin",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: body.toString()
        }).then(function (r) { return r.json(); });
    }

    function scanAjaxPost(action, data) {
        var body = new URLSearchParams({ action: action, nonce: scanNonce, ...data });
        return fetch(ajaxUrl, {
            method: "POST",
            credentials: "same-origin",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: body.toString()
        }).then(function (r) { return r.json(); });
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

    function renderExplanation(cell, result) {
        if (result.error) {
            cell.innerHTML =
                '<span class="accessai-ai-error" title="' + escAttr(result.error) + '">⚠ AI unavailable</span>';
            return;
        }
        var html =
            '<details class="accessai-explain">' +
            '<summary>View AI Explanation</summary>' +
            '<div class="accessai-explain-body">' +
            '<p><strong>What it means:</strong> ' + esc(result.explanation) + '</p>' +
            '<p><strong>Who is affected:</strong> ' + esc(result.impact) + '</p>' +
            '<p><strong>Suggested fix:</strong> ' + esc(result.suggested_fix) + '</p>' +
            (result.proposed_alt_text
                ? '<p><strong>Proposed alt text:</strong> <em>' + esc(result.proposed_alt_text) + '</em></p>'
                : '') +
            '</div>' +
            '</details>';
        cell.innerHTML = html;
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
        if (!issues.length) return;

        // Build fingerprint → row cell map.
        var table = document.getElementById(tableId);
        var cellMap = {};
        table.querySelectorAll("tr[data-fingerprint]").forEach(function (row) {
            cellMap[row.dataset.fingerprint] = row.querySelector(".accessai-ai-cell");
        });

        // Process in batches of 10.
        var batches = [];
        for (var i = 0; i < issues.length; i += 10) {
            batches.push(issues.slice(i, i + 10));
        }

        batches.reduce(function (chain, batch) {
            return chain.then(function () {
                return ajaxPost("accessai_auto_explain_issues", {
                    issues: JSON.stringify(batch)
                }).then(function (resp) {
                    if (resp.success && resp.data && resp.data.results) {
                        var results = resp.data.results;
                        Object.keys(results).forEach(function (fp) {
                            if (cellMap[fp]) {
                                renderExplanation(cellMap[fp], results[fp]);
                            }
                        });
                    } else {
                        // Mark all batch cells as error.
                        batch.forEach(function (issue) {
                            if (cellMap[issue.fingerprint]) {
                                cellMap[issue.fingerprint].innerHTML =
                                    '<span class="accessai-ai-error">⚠ ' +
                                    esc((resp.data && resp.data.message) || "AI error") +
                                    "</span>";
                            }
                        });
                    }
                });
            });
        }, Promise.resolve());
    }

    /* ------------------------------------------------------------------ */
    /* Contrast scan: page selector + hidden iframe flow                    */
    /* ------------------------------------------------------------------ */
    function initContrastScan() {
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
            if (!pageId) { setStatus("Please select a page first.", "is-error"); return; }

            scanBtn.disabled = true;
            setStatus("Preparing scan token…", "is-loading");

            // Step 1: get a signed scan token + page URL from server.
            scanAjaxPost("accessai_trigger_contrast_scan", { page_id: pageId })
                .then(function (resp) {
                    if (!resp.success) {
                        throw new Error((resp.data && resp.data.message) || "Could not start scan.");
                    }
                    var token = resp.data.token;
                    var pageUrl = resp.data.page_url;

                    setStatus("Storing scan token…", "is-loading");

                    // Step 2: store the token in user meta so wp_head injects the meta tag.
                    return scanAjaxPost("accessai_store_scan_token", { token: token })
                        .then(function () { return { token: token, pageUrl: pageUrl }; });
                })
                .then(function (info) {
                    setStatus("Opening page in background scan frame…", "is-loading");

                    // Remove any previous iframe.
                    iframeContainer.innerHTML = "";
                    if (noContrastNotice) noContrastNotice.style.display = "none";

                    // Step 3: open hidden iframe — contrast-scanner.js runs automatically.
                    var iframe = document.createElement("iframe");
                    iframe.src = info.pageUrl;
                    iframe.className = "accessai-scan-frame";
                    iframe.setAttribute("aria-hidden", "true");
                    iframe.setAttribute("title", "Accessibility contrast scan frame");
                    iframeContainer.appendChild(iframe);

                    setStatus(
                        "Scanning \u201C" + pageSelect.options[pageSelect.selectedIndex].text + "\u201D \u2014 this may take up to 20 seconds…",
                        "is-loading"
                    );

                    // Step 4: wait for the scan to finish (max 25 s), then reload.
                    setTimeout(function () {
                        iframeContainer.innerHTML = "";
                        setStatus("Scan complete — refreshing results…", "is-ok");
                        // Small delay so the server has time to persist results.
                        setTimeout(function () { window.location.reload(); }, 1500);
                    }, 20000);
                })
                .catch(function (err) {
                    setStatus("Scan failed: " + (err.message || "Unknown error."), "is-error");
                    scanBtn.disabled = false;
                });
        });

        if (clearBtn) {
            clearBtn.addEventListener("click", function () {
                if (!window.confirm("Clear the current contrast scan results?")) return;
                clearBtn.disabled = true;
                scanAjaxPost("accessai_clear_contrast_results", {})
                    .then(function () { window.location.reload(); })
                    .catch(function () {
                        setStatus("Could not clear results.", "is-error");
                        clearBtn.disabled = false;
                    });
            });
        }
    }

    /* ------------------------------------------------------------------ */
    /* Manual AI form (existing functionality, preserved)                   */
    /* ------------------------------------------------------------------ */
    function initManualAiForm() {
        var form = document.getElementById("accessai-ai-form");
        if (!form) return;

        var message = document.getElementById("accessai-ai-message");
        var preview = document.getElementById("accessai-ai-preview");
        var applyButton = document.getElementById("accessai-apply-button");
        var currentPreviewId = "";

        function showMessage(text, isError) {
            message.textContent = text;
            message.style.color = isError ? "#b91c1c" : "#166534";
        }

        function addDetail(label, value) {
            var section = document.createElement("section");
            section.style.marginTop = "14px";
            var h = document.createElement("h3");
            h.textContent = label;
            var p = document.createElement("p");
            p.textContent = value || "Not provided.";
            section.append(h, p);
            preview.appendChild(section);
        }

        form.addEventListener("submit", async function (event) {
            event.preventDefault();
            var type = document.getElementById("accessai-issue-type").value;
            var details = document.getElementById("accessai-issue-details").value.trim();

            if (!details) {
                showMessage("Enter details about the accessibility issue first.", true);
                return;
            }

            form.querySelector('[type="submit"]').disabled = true;
            applyButton.hidden = true;
            preview.replaceChildren();
            currentPreviewId = "";
            showMessage("Asking Gemini…", false);

            try {
                var resp = await ajaxPost("accessai_ai_preview", { issue_type: type, details: details });
                if (!resp.success) throw new Error(resp.data && resp.data.message || "Request failed.");

                currentPreviewId = resp.data.preview_id;
                var result = resp.data.result;

                addDetail("Explanation", result.explanation);
                addDetail("Who may be affected", result.impact);
                addDetail("Suggested fix", result.suggested_fix);

                if (type === "alt_text" && result.proposed_alt_text) {
                    var h3 = document.createElement("h3");
                    h3.textContent = "Proposed image alt text";
                    var input = document.createElement("input");
                    input.type = "text"; input.id = "accessai-approved-alt";
                    input.maxLength = 500; input.value = result.proposed_alt_text;
                    input.style.width = "100%";
                    var note = document.createElement("p");
                    note.textContent = "Review carefully. Enter the WordPress image attachment ID to apply.";
                    var idLabel = document.createElement("label");
                    idLabel.htmlFor = "accessai-attachment-id";
                    idLabel.textContent = "Image attachment ID";
                    var idInput = document.createElement("input");
                    idInput.type = "number"; idInput.min = "1"; idInput.step = "1";
                    idInput.id = "accessai-attachment-id";
                    idInput.style.cssText = "display:block;margin-top:6px;";
                    preview.append(h3, input, note, idLabel, idInput);
                    applyButton.hidden = false;
                    applyButton.style.display = "inline-block";
                } else {
                    addDetail("Next step", "This finding is review-only. AccessAI has not changed your site.");
                }
                showMessage(resp.data.message, false);
            } catch (err) {
                showMessage(err.message, true);
            } finally {
                form.querySelector('[type="submit"]').disabled = false;
            }
        });

        applyButton.addEventListener("click", async function () {
            if (!currentPreviewId) return;
            var altInput = document.getElementById("accessai-approved-alt");
            var attachInput = document.getElementById("accessai-attachment-id");
            if (!altInput || !attachInput || !attachInput.value) {
                showMessage("Enter the image attachment ID before applying.", true); return;
            }
            if (!window.confirm("Apply this reviewed alt text to the selected image attachment?")) return;
            applyButton.disabled = true;
            showMessage("Applying approved change…", false);
            try {
                var resp = await ajaxPost("accessai_apply_fix", {
                    preview_id: currentPreviewId,
                    attachment_id: attachInput.value,
                    approved_alt: altInput.value
                });
                if (!resp.success) throw new Error(resp.data && resp.data.message || "Apply failed.");
                showMessage(resp.data.message, false);
                applyButton.hidden = true; applyButton.style.display = "none";
                currentPreviewId = "";
            } catch (err) {
                showMessage(err.message, true);
            } finally {
                applyButton.disabled = false;
            }
        });
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
    /* Bootstrap                                                             */
    /* ------------------------------------------------------------------ */
    document.addEventListener("DOMContentLoaded", function () {
        initContrastScan();
        initManualAiForm();

        // Auto-explain all issue tables.
        ["accessai-alt-table", "accessai-heading-table",
         "accessai-form-table", "accessai-contrast-table"].forEach(autoExplainTable);
    });

})();
