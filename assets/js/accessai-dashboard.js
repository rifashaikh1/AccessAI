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
    /* PDF Report Export Engine                                            */
    /* ------------------------------------------------------------------ */
    function initPdfReportExport() {
        var btn = document.getElementById("accessai-download-pdf-btn");
        if (!btn) return;

        function cleanText(el) {
            if (!el) return "";
            return (el.textContent || "").trim().replace(/\s+/g, " ");
        }

        function getAiText(row) {
            if (!row) return "";
            var cell = row.querySelector(".accessai-ai-cell");
            if (!cell) return "";
            var body = cell.querySelector(".accessai-ai-body");
            if (body) {
                var txt = cleanText(body);
                var fixEl = cell.querySelector(".accessai-ai-fix strong, .accessai-ai-fix em");
                if (fixEl) txt += " — Suggested fix: " + cleanText(fixEl);
                return txt;
            }
            if (cell.querySelector(".accessai-spinner") || cell.querySelector(".accessai-ai-na")) {
                return "";
            }
            return cleanText(cell);
        }

        btn.addEventListener("click", function () {
            if (typeof window.html2pdf !== "function") {
                alert("PDF export engine is initializing. Please try again in a moment.");
                return;
            }

            var originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="dashicons dashicons-update accessai-spin"></span><span>Generating PDF…</span>';

            var pageTitle = btn.dataset.pageTitle || "Audited Page";
            var pageUrl = btn.dataset.pageUrl || window.location.href;
            var score = btn.dataset.score || "—";
            var scoreLabel = btn.dataset.scoreLabel || "Evaluation Complete";
            var totalIssues = btn.dataset.totalIssues || "0";
            var passedChecks = btn.dataset.passedChecks || "0";
            var totalChecks = btn.dataset.totalChecks || "4";

            var now = new Date();
            var dateStr = now.toLocaleDateString("en-US", { year: "numeric", month: "short", day: "numeric" });
            var timeStr = now.toLocaleTimeString("en-US", { hour: "2-digit", minute: "2-digit" });
            var pageSlug = pageTitle.toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/(^-|-$)/g, "") || "report";

            // Determine score theme colors
            var numScore = parseInt(score, 10);
            var scoreBg = "#ecfdf5", scoreColor = "#059669", scoreBorder = "#10b981", badgeBg = "#d1fae5", badgeColor = "#065f46";
            if (isNaN(numScore) || numScore < 50) {
                scoreBg = "#fef2f2"; scoreColor = "#dc2626"; scoreBorder = "#ef4444"; badgeBg = "#fee2e2"; badgeColor = "#991b1b";
            } else if (numScore < 70) {
                scoreBg = "#fffbeb"; scoreColor = "#d97706"; scoreBorder = "#f59e0b"; badgeBg = "#fef3c7"; badgeColor = "#92400e";
            } else if (numScore < 85) {
                scoreBg = "#eff6ff"; scoreColor = "#2563eb"; scoreBorder = "#3b82f6"; badgeBg = "#dbeafe"; badgeColor = "#1e40af";
            }

            // Gather findings from all 10 category tables
            var altRows = document.querySelectorAll("#accessai-alt-table tbody tr");
            var altIssues = [];
            altRows.forEach(function (r) {
                var cols = r.querySelectorAll("td");
                if (cols.length >= 4) {
                    altIssues.push({
                        type: cleanText(cols[0]),
                        status: cleanText(cols[1]),
                        src: cleanText(cols[2]),
                        ai: getAiText(r) || "Add an informative, contextual alt attribute describing the image content or purpose."
                    });
                }
            });

            var headingRows = document.querySelectorAll("#accessai-heading-table tbody tr");
            var headingIssues = [];
            headingRows.forEach(function (r) {
                var cols = r.querySelectorAll("td");
                if (cols.length >= 4) {
                    headingIssues.push({
                        type: cleanText(cols[0]),
                        status: cleanText(cols[1]),
                        transition: cleanText(cols[2]),
                        text: cleanText(cols[3]),
                        ai: getAiText(r) || "Structure headings hierarchically (e.g., H1 followed by H2, then H3) without skipping levels."
                    });
                }
            });

            var formRows = document.querySelectorAll("#accessai-form-table tbody tr");
            var formIssues = [];
            formRows.forEach(function (r) {
                var cols = r.querySelectorAll("td");
                if (cols.length >= 4) {
                    formIssues.push({
                        type: cleanText(cols[0]),
                        status: cleanText(cols[1]),
                        control: cleanText(cols[2]),
                        field: cleanText(cols[3]),
                        ai: getAiText(r) || "Associate a descriptive <label for='...'> or aria-label attribute with this form control."
                    });
                }
            });

            var contrastRows = document.querySelectorAll("#accessai-contrast-table tbody tr");
            var contrastIssues = [];
            contrastRows.forEach(function (r) {
                var cols = r.querySelectorAll("td");
                if (cols.length >= 5) {
                    contrastIssues.push({
                        element: cleanText(cols[0]),
                        status: cleanText(cols[1]),
                        sample: cleanText(cols[2]),
                        ratio: cleanText(cols[3]),
                        required: cleanText(cols[4]),
                        ai: getAiText(r) || "Increase luminance contrast between foreground text and background to meet WCAG AA requirements."
                    });
                }
            });

            var keyboardRows = document.querySelectorAll("#accessai-keyboard-table tbody tr");
            var keyboardIssues = [];
            keyboardRows.forEach(function (r) {
                var cols = r.querySelectorAll("td");
                if (cols.length >= 4) {
                    keyboardIssues.push({
                        type: cleanText(cols[0]),
                        status: cleanText(cols[1]),
                        element: cleanText(cols[2]),
                        message: cleanText(cols[3]),
                        ai: getAiText(r) || "Ensure all interactive elements are reachable via Tab key without positive tabindex or focus traps."
                    });
                }
            });

            var linkRows = document.querySelectorAll("#accessai-link-table tbody tr");
            var linkIssues = [];
            linkRows.forEach(function (r) {
                var cols = r.querySelectorAll("td");
                if (cols.length >= 4) {
                    linkIssues.push({
                        type: cleanText(cols[0]),
                        status: cleanText(cols[1]),
                        text: cleanText(cols[2]),
                        url: cleanText(cols[3]),
                        ai: getAiText(r) || "Provide descriptive anchor text that explains the destination out of context or add an aria-label."
                    });
                }
            });

            var buttonRows = document.querySelectorAll("#accessai-button-table tbody tr");
            var buttonIssues = [];
            buttonRows.forEach(function (r) {
                var cols = r.querySelectorAll("td");
                if (cols.length >= 4) {
                    buttonIssues.push({
                        type: cleanText(cols[0]),
                        status: cleanText(cols[1]),
                        element: cleanText(cols[2]),
                        message: cleanText(cols[3]),
                        ai: getAiText(r) || "Provide an accessible name using visible text, aria-label, or descriptive alt text on enclosed icons."
                    });
                }
            });

            var textSpacingRows = document.querySelectorAll("#accessai-text-spacing-table tbody tr");
            var textSpacingIssues = [];
            textSpacingRows.forEach(function (r) {
                var cols = r.querySelectorAll("td");
                if (cols.length >= 5) {
                    textSpacingIssues.push({
                        type: cleanText(cols[0]),
                        status: cleanText(cols[1]),
                        element: cleanText(cols[2]),
                        sample: cleanText(cols[3]),
                        metrics: cleanText(cols[4]),
                        ai: getAiText(r) || "Allow containers to expand fluidly with min-height and relative units to accommodate 200% text resize and spacing."
                    });
                }
            });

            var focusRows = document.querySelectorAll("#accessai-focus-table tbody tr");
            var focusIssues = [];
            focusRows.forEach(function (r) {
                var cols = r.querySelectorAll("td");
                if (cols.length >= 4) {
                    focusIssues.push({
                        type: cleanText(cols[0]),
                        status: cleanText(cols[1]),
                        element: cleanText(cols[2]),
                        style: cleanText(cols[3]),
                        ai: getAiText(r) || "Do not suppress outline without providing an equivalent visible focus ring with at least 3:1 contrast."
                    });
                }
            });

            var tableRows = document.querySelectorAll("#accessai-table-table tbody tr");
            var tableIssues = [];
            tableRows.forEach(function (r) {
                var cols = r.querySelectorAll("td");
                if (cols.length >= 4) {
                    tableIssues.push({
                        type: cleanText(cols[0]),
                        status: cleanText(cols[1]),
                        element: cleanText(cols[2]),
                        message: cleanText(cols[3]),
                        ai: getAiText(r) || "Designate table headers using <th> tags with appropriate scope attributes (scope='col' or scope='row')."
                    });
                }
            });

            // Display a polished loading modal while generating
            var overlay = document.createElement("div");
            overlay.id = "accessai-pdf-loading-overlay";
            overlay.style.position = "fixed";
            overlay.style.inset = "0";
            overlay.style.backgroundColor = "rgba(15, 23, 42, 0.72)";
            overlay.style.backdropFilter = "blur(4px)";
            overlay.style.webkitBackdropFilter = "blur(4px)";
            overlay.style.zIndex = "9999999";
            overlay.style.display = "flex";
            overlay.style.alignItems = "center";
            overlay.style.justifyContent = "center";
            overlay.innerHTML = '<div style="background: #ffffff; border-radius: 16px; padding: 32px 36px; text-align: center; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); max-width: 400px; font-family: -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, sans-serif;">' +
                '<div style="width: 52px; height: 52px; border-radius: 14px; background: #fee2e2; color: #ef4444; display: flex; align-items: center; justify-content: center; margin: 0 auto 14px;"><span class="dashicons dashicons-pdf" style="font-size: 28px; width: 28px; height: 28px; line-height: 1;"></span></div>' +
                '<h3 style="margin: 0 0 6px; font-size: 17px; font-weight: 700; color: #0f172a;">Generating Audit PDF</h3>' +
                '<p style="margin: 0 0 16px; font-size: 12px; color: #64748b; line-height: 1.4;">Formatting 10 WCAG compliance checkpoint tables&hellip;</p>' +
                '<div style="display: inline-flex; align-items: center; gap: 8px; color: #4f46e5; font-size: 12px; font-weight: 600;"><span class="dashicons dashicons-update accessai-spin"></span> Assembling report&hellip;</div>' +
                '</div>';
            document.body.appendChild(overlay);

            // Offscreen wrapper
            var wrapper = document.createElement("div");
            wrapper.id = "accessai-pdf-wrapper";
            wrapper.style.position = "absolute";
            wrapper.style.left = "0";
            wrapper.style.top = "0";
            wrapper.style.width = "680px";
            wrapper.style.height = "1px";
            wrapper.style.overflow = "hidden";
            wrapper.style.zIndex = "-9999";
            wrapper.style.opacity = "1";
            wrapper.style.pointerEvents = "none";

            var container = document.createElement("div");
            container.id = "accessai-pdf-render-box";
            container.style.width = "680px";
            container.style.margin = "0 auto";
            container.style.background = "#ffffff";
            container.style.color = "#1e293b";
            container.style.fontFamily = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
            container.style.fontSize = "10.5px";
            container.style.lineHeight = "1.45";
            container.style.padding = "18px 20px";
            container.style.boxSizing = "border-box";

            var html = "";

            /* 1. Header Banner */
            html += '<div style="background-color: #0f172a; border-radius: 12px; padding: 18px 20px; color: #ffffff; margin-bottom: 14px; box-sizing: border-box;">';
            html += '  <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">';
            html += '    <div>';
            html += '      <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">';
            html += '        <span style="background: #4f46e5; color: #ffffff; font-weight: 800; font-size: 12px; padding: 3px 8px; border-radius: 6px;">AccessAI</span>';
            html += '        <span style="font-size: 9.5px; color: #cbd5e1; text-transform: uppercase; letter-spacing: 0.06em; font-weight: 700;">10-Category Audit Report</span>';
            html += '      </div>';
            html += '      <h1 style="margin: 0; font-size: 17px; font-weight: 700; color: #ffffff;">WCAG 2.1 Compliance Assessment</h1>';
            html += '    </div>';
            html += '    <div style="text-align: right;">';
            html += '      <div style="background: rgba(255,255,255,0.12); border: 1px solid rgba(255,255,255,0.22); border-radius: 8px; padding: 4px 8px; display: inline-block;">';
            html += '        <div style="font-size: 8.5px; color: #94a3b8; text-transform: uppercase; font-weight: 700;">Generated</div>';
            html += '        <div style="font-size: 10px; font-weight: 600; color: #ffffff;">' + esc(dateStr) + ' &bull; ' + esc(timeStr) + '</div>';
            html += '      </div>';
            html += '    </div>';
            html += '  </div>';

            html += '  <div style="display: flex; gap: 10px; border-top: 1px solid rgba(255,255,255,0.15); padding-top: 8px; font-size: 10px;">';
            html += '    <div style="flex: 2;">';
            html += '      <span style="color: #94a3b8; display: block; font-weight: 600; text-transform: uppercase; font-size: 8px;">Audited Target</span>';
            html += '      <strong style="color: #ffffff; font-size: 11px;">' + esc(pageTitle) + '</strong>';
            html += '      <span style="color: #cbd5e1; display: block; font-size: 9px; word-break: break-all;">' + esc(pageUrl) + '</span>';
            html += '    </div>';
            html += '    <div style="flex: 1;">';
            html += '      <span style="color: #94a3b8; display: block; font-weight: 600; text-transform: uppercase; font-size: 8px;">Standard Evaluated</span>';
            html += '      <strong style="color: #ffffff; font-size: 11px;">WCAG 2.1 (A &amp; AA)</strong>';
            html += '    </div>';
            html += '    <div style="flex: 1;">';
            html += '      <span style="color: #94a3b8; display: block; font-weight: 600; text-transform: uppercase; font-size: 8px;">Checkpoints</span>';
            html += '      <strong style="color: #ffffff; font-size: 11px;">10 Categories</strong>';
            html += '    </div>';
            html += '  </div>';
            html += '</div>';

            /* Disclaimer notice */
            html += '<div style="background: #f1f5f9; border-left: 3px solid #64748b; border-radius: 6px; padding: 7px 10px; margin-bottom: 12px; font-size: 8.5px; color: #475569; line-height: 1.4;">';
            html += '  <strong>Compliance Benchmark Notice:</strong> This score represents an automated assessment benchmark across 10 measurable WCAG 2.1 AA criteria. Automated testing cannot detect all subjective barriers and does not constitute legal proof of full WCAG conformance.';
            html += '</div>';

            /* 2. Executive Scorecard & Metrics */
            html += '<div style="display: flex; gap: 8px; margin-bottom: 14px; page-break-inside: avoid; break-inside: avoid;">';
            html += '  <div style="flex: 1.1; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 12px; display: flex; align-items: center; gap: 10px; box-sizing: border-box;">';
            html += '    <div style="width: 46px; height: 46px; border-radius: 50%; background: ' + scoreBg + '; color: ' + scoreColor + '; border: 3px solid ' + scoreBorder + '; display: flex; flex-direction: column; align-items: center; justify-content: center; font-weight: 800; flex-shrink: 0;">';
            html += '      <span style="font-size: 17px; line-height: 1;">' + esc(score) + '</span>';
            html += '      <span style="font-size: 7.5px; opacity: 0.85;">/100</span>';
            html += '    </div>';
            html += '    <div>';
            html += '      <div style="font-size: 8px; text-transform: uppercase; font-weight: 700; color: #64748b;">Accessibility Score</div>';
            html += '      <div style="font-size: 13.5px; font-weight: 800; color: #0f172a; margin: 1px 0;">' + esc(scoreLabel) + '</div>';
            html += '      <div style="font-size: 8.5px; color: #64748b;">' + esc(passedChecks) + ' of ' + esc(totalChecks) + ' Criteria Passing</div>';
            html += '    </div>';
            html += '  </div>';

            html += '  <div style="flex: 0.85; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 12px; box-sizing: border-box;">';
            html += '    <div style="font-size: 8px; text-transform: uppercase; font-weight: 700; color: #64748b;">Total Findings</div>';
            html += '    <div style="font-size: 20px; font-weight: 800; color: ' + (parseInt(totalIssues, 10) > 0 ? '#dc2626' : '#059669') + '; margin: 1px 0;">' + esc(totalIssues) + '</div>';
            html += '    <div style="font-size: 8.5px; color: #64748b;">Across 10 categories</div>';
            html += '  </div>';

            html += '  <div style="flex: 1.5; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px 10px; font-size: 8px; box-sizing: border-box;">';
            html += '    <div style="font-size: 8px; text-transform: uppercase; font-weight: 700; color: #64748b; margin-bottom: 3px;">10-Category Summary</div>';
            html += '    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1px 8px;">';
            html += '      <div>Alt Text: <strong style="color:' + (altIssues.length ? '#dc2626' : '#059669') + ';">' + altIssues.length + '</strong></div>';
            html += '      <div>Headings: <strong style="color:' + (headingIssues.length ? '#dc2626' : '#059669') + ';">' + headingIssues.length + '</strong></div>';
            html += '      <div>Form Labels: <strong style="color:' + (formIssues.length ? '#dc2626' : '#059669') + ';">' + formIssues.length + '</strong></div>';
            html += '      <div>Contrast: <strong style="color:' + (contrastIssues.length ? '#dc2626' : '#059669') + ';">' + contrastIssues.length + '</strong></div>';
            html += '      <div>Keyboard: <strong style="color:' + (keyboardIssues.length ? '#dc2626' : '#059669') + ';">' + keyboardIssues.length + '</strong></div>';
            html += '      <div>Links: <strong style="color:' + (linkIssues.length ? '#dc2626' : '#059669') + ';">' + linkIssues.length + '</strong></div>';
            html += '      <div>Buttons: <strong style="color:' + (buttonIssues.length ? '#dc2626' : '#059669') + ';">' + buttonIssues.length + '</strong></div>';
            html += '      <div>Text Spacing: <strong style="color:' + (textSpacingIssues.length ? '#dc2626' : '#059669') + ';">' + textSpacingIssues.length + '</strong></div>';
            html += '      <div>Focus Visible: <strong style="color:' + (focusIssues.length ? '#dc2626' : '#059669') + ';">' + focusIssues.length + '</strong></div>';
            html += '      <div>Tables: <strong style="color:' + (tableIssues.length ? '#dc2626' : '#059669') + ';">' + tableIssues.length + '</strong></div>';
            html += '    </div>';
            html += '  </div>';
            html += '</div>';

            /* Helper functions for category sections */
            function renderSectionHeader(title, wcag, count, isPassText) {
                var s = '<div style="display: flex; justify-content: space-between; align-items: center; border-left: 3px solid #4f46e5; padding-left: 8px; margin: 12px 0 6px; box-sizing: border-box;">';
                s += '  <div>';
                s += '    <h3 style="margin: 0; font-size: 11.5px; font-weight: 700; color: #0f172a;">' + esc(title) + '</h3>';
                s += '    <div style="font-size: 8px; color: #64748b;">' + esc(wcag) + '</div>';
                s += '  </div>';
                if (count === 0) {
                    s += '  <span style="background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; border-radius: 999px; padding: 2px 7px; font-size: 8px; font-weight: 700;">' + esc(isPassText || '✓ All Clear') + '</span>';
                } else {
                    s += '  <span style="background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; border-radius: 999px; padding: 2px 7px; font-size: 8px; font-weight: 700;">' + count + ' ' + (count === 1 ? 'Finding' : 'Findings') + '</span>';
                }
                s += '</div>';
                return s;
            }

            function renderEmptyNotice(msg) {
                return '<div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 6px 10px; color: #166534; font-size: 8.5px; margin-bottom: 8px; box-sizing: border-box;"><strong>✓ Passed:</strong> ' + esc(msg) + '</div>';
            }

            function renderStatusBadge(status) {
                var isFailure = (status.indexOf("Failure") !== -1);
                var isPotential = (status.indexOf("Potential") !== -1);
                var bg = isFailure ? "#fee2e2" : (isPotential ? "#fef3c7" : "#eff6ff");
                var color = isFailure ? "#991b1b" : (isPotential ? "#92400e" : "#1d4ed8");
                return '<span style="background: ' + bg + '; color: ' + color + '; padding: 2px 5px; border-radius: 4px; font-weight: 700; font-size: 7.5px; white-space: nowrap;">' + esc(status) + '</span>';
            }

            /* Section 1: Image Alt Text */
            html += '<div style="page-break-inside: avoid; break-inside: avoid;">';
            html += renderSectionHeader("1. Image Alternative Text", "WCAG 2.1 Guideline 1.1.1 Non-text Content (Level A)", altIssues.length, "✓ Compliant");
            if (altIssues.length === 0) {
                html += renderEmptyNotice("All images examined have valid, descriptive alternative text.");
            } else {
                html += '<table style="width: 100%; border-collapse: collapse; font-size: 8.5px; margin-bottom: 8px; border: 1px solid #e2e8f0; table-layout: fixed;">';
                html += '  <thead><tr style="background: #f1f5f9; text-align: left; color: #475569; font-size: 8px;">';
                html += '    <th style="padding: 5px 6px; width: 22%;">Issue</th>';
                html += '    <th style="padding: 5px 6px; width: 18%;">Status</th>';
                html += '    <th style="padding: 5px 6px; width: 26%;">Image Source</th>';
                html += '    <th style="padding: 5px 6px; width: 34%;">Remediation Guidance</th>';
                html += '  </tr></thead><tbody>';
                altIssues.forEach(function (issue, idx) {
                    html += '  <tr style="background: ' + (idx % 2 === 0 ? "#ffffff" : "#f8fafc") + '; border-bottom: 1px solid #e2e8f0;">';
                    html += '    <td style="padding: 5px 6px; font-weight: 700;">' + esc(issue.type) + '</td>';
                    html += '    <td style="padding: 5px 6px;">' + renderStatusBadge(issue.status) + '</td>';
                    html += '    <td style="padding: 5px 6px; word-break: break-all; font-family: monospace; font-size: 7.5px;">' + esc(issue.src) + '</td>';
                    html += '    <td style="padding: 5px 6px; color: #3730a3; background: #eef2ff; word-break: break-word;">' + esc(issue.ai) + '</td>';
                    html += '  </tr>';
                });
                html += '</tbody></table>';
            }
            html += '</div>';

            /* Section 2: Heading Structure */
            html += '<div style="page-break-inside: avoid; break-inside: avoid;">';
            html += renderSectionHeader("2. Heading Hierarchy & Structure", "WCAG 2.1 Guideline 1.3.1 Info & Relationships (Level A)", headingIssues.length, "✓ Logical Order");
            if (headingIssues.length === 0) {
                html += renderEmptyNotice("Headings follow a strictly sequential descending order without skipped levels.");
            } else {
                html += '<table style="width: 100%; border-collapse: collapse; font-size: 8.5px; margin-bottom: 8px; border: 1px solid #e2e8f0; table-layout: fixed;">';
                html += '  <thead><tr style="background: #f1f5f9; text-align: left; color: #475569; font-size: 8px;">';
                html += '    <th style="padding: 5px 6px; width: 22%;">Issue</th>';
                html += '    <th style="padding: 5px 6px; width: 16%;">Status</th>';
                html += '    <th style="padding: 5px 6px; width: 18%;">Transition</th>';
                html += '    <th style="padding: 5px 6px; width: 44%;">Heading &amp; Guidance</th>';
                html += '  </tr></thead><tbody>';
                headingIssues.forEach(function (issue, idx) {
                    html += '  <tr style="background: ' + (idx % 2 === 0 ? "#ffffff" : "#f8fafc") + '; border-bottom: 1px solid #e2e8f0;">';
                    html += '    <td style="padding: 5px 6px; font-weight: 700;">' + esc(issue.type) + '</td>';
                    html += '    <td style="padding: 5px 6px;">' + renderStatusBadge(issue.status) + '</td>';
                    html += '    <td style="padding: 5px 6px; font-weight: 600;">' + esc(issue.transition) + '</td>';
                    html += '    <td style="padding: 5px 6px; word-break: break-word;"><em>' + esc(issue.text) + '</em><div style="color: #3730a3; margin-top: 2px;">' + esc(issue.ai) + '</div></td>';
                    html += '  </tr>';
                });
                html += '</tbody></table>';
            }
            html += '</div>';

            /* Section 3: Form Controls */
            html += '<div style="page-break-inside: avoid; break-inside: avoid;">';
            html += renderSectionHeader("3. Form Controls & Input Labels", "WCAG 2.1 Guideline 3.3.2 Labels or Instructions (Level A)", formIssues.length, "✓ Controls Labeled");
            if (formIssues.length === 0) {
                html += renderEmptyNotice("All interactive form inputs, selects, and textareas have programmatic labels.");
            } else {
                html += '<table style="width: 100%; border-collapse: collapse; font-size: 8.5px; margin-bottom: 8px; border: 1px solid #e2e8f0; table-layout: fixed;">';
                html += '  <thead><tr style="background: #f1f5f9; text-align: left; color: #475569; font-size: 8px;">';
                html += '    <th style="padding: 5px 6px; width: 22%;">Issue</th>';
                html += '    <th style="padding: 5px 6px; width: 16%;">Status</th>';
                html += '    <th style="padding: 5px 6px; width: 22%;">Control Name</th>';
                html += '    <th style="padding: 5px 6px; width: 40%;">Remediation Guidance</th>';
                html += '  </tr></thead><tbody>';
                formIssues.forEach(function (issue, idx) {
                    html += '  <tr style="background: ' + (idx % 2 === 0 ? "#ffffff" : "#f8fafc") + '; border-bottom: 1px solid #e2e8f0;">';
                    html += '    <td style="padding: 5px 6px; font-weight: 700;">' + esc(issue.type) + '</td>';
                    html += '    <td style="padding: 5px 6px;">' + renderStatusBadge(issue.status) + '</td>';
                    html += '    <td style="padding: 5px 6px; font-family: monospace; font-size: 8px;">' + esc(issue.control) + ' (' + esc(issue.field) + ')</td>';
                    html += '    <td style="padding: 5px 6px; color: #3730a3; background: #eef2ff; word-break: break-word;">' + esc(issue.ai) + '</td>';
                    html += '  </tr>';
                });
                html += '</tbody></table>';
            }
            html += '</div>';

            /* Section 4: Color Contrast */
            html += '<div style="page-break-inside: avoid; break-inside: avoid;">';
            html += renderSectionHeader("4. Computed Colour Contrast Ratio", "WCAG 2.1 Guideline 1.4.3 Contrast Minimum (Level AA)", contrastIssues.length, "✓ AA Compliant");
            if (contrastIssues.length === 0) {
                html += renderEmptyNotice("Tested text elements meet or exceed WCAG Level AA minimum contrast ratios.");
            } else {
                html += '<table style="width: 100%; border-collapse: collapse; font-size: 8.5px; margin-bottom: 8px; border: 1px solid #e2e8f0; table-layout: fixed;">';
                html += '  <thead><tr style="background: #f1f5f9; text-align: left; color: #475569; font-size: 8px;">';
                html += '    <th style="padding: 5px 6px; width: 18%;">Element</th>';
                html += '    <th style="padding: 5px 6px; width: 16%;">Status</th>';
                html += '    <th style="padding: 5px 6px; width: 20%;">Text Sample</th>';
                html += '    <th style="padding: 5px 6px; width: 16%;">Ratio</th>';
                html += '    <th style="padding: 5px 6px; width: 30%;">Remediation Guidance</th>';
                html += '  </tr></thead><tbody>';
                contrastIssues.forEach(function (issue, idx) {
                    html += '  <tr style="background: ' + (idx % 2 === 0 ? "#ffffff" : "#f8fafc") + '; border-bottom: 1px solid #e2e8f0;">';
                    html += '    <td style="padding: 5px 6px; font-family: monospace; font-size: 8px;">' + esc(issue.element) + '</td>';
                    html += '    <td style="padding: 5px 6px;">' + renderStatusBadge(issue.status) + '</td>';
                    html += '    <td style="padding: 5px 6px; word-break: break-word;"><em>' + esc(issue.sample) + '</em></td>';
                    html += '    <td style="padding: 5px 6px;"><strong style="color:#b91c1c;">' + esc(issue.ratio) + '</strong> (need ' + esc(issue.required) + ')</td>';
                    html += '    <td style="padding: 5px 6px; color: #3730a3; background: #eef2ff; word-break: break-word;">' + esc(issue.ai) + '</td>';
                    html += '  </tr>';
                });
                html += '</tbody></table>';
            }
            html += '</div>';

            /* Section 5: Keyboard Accessibility */
            html += '<div style="page-break-inside: avoid; break-inside: avoid;">';
            html += renderSectionHeader("5. Keyboard Accessibility & Trap Prevention", "WCAG 2.1 Guidelines 2.1.1 Keyboard & 2.1.2 No Trap (Level A)", keyboardIssues.length, "✓ Navigable");
            if (keyboardIssues.length === 0) {
                html += renderEmptyNotice("Interactive controls are operable via keyboard without positive tabindex disruption or traps.");
            } else {
                html += '<table style="width: 100%; border-collapse: collapse; font-size: 8.5px; margin-bottom: 8px; border: 1px solid #e2e8f0; table-layout: fixed;">';
                html += '  <thead><tr style="background: #f1f5f9; text-align: left; color: #475569; font-size: 8px;">';
                html += '    <th style="padding: 5px 6px; width: 22%;">Finding</th>';
                html += '    <th style="padding: 5px 6px; width: 16%;">Status</th>';
                html += '    <th style="padding: 5px 6px; width: 24%;">Element / Target</th>';
                html += '    <th style="padding: 5px 6px; width: 38%;">Remediation Guidance</th>';
                html += '  </tr></thead><tbody>';
                keyboardIssues.forEach(function (issue, idx) {
                    html += '  <tr style="background: ' + (idx % 2 === 0 ? "#ffffff" : "#f8fafc") + '; border-bottom: 1px solid #e2e8f0;">';
                    html += '    <td style="padding: 5px 6px; font-weight: 700;">' + esc(issue.type) + '</td>';
                    html += '    <td style="padding: 5px 6px;">' + renderStatusBadge(issue.status) + '</td>';
                    html += '    <td style="padding: 5px 6px; word-break: break-all; font-family: monospace; font-size: 7.5px;">' + esc(issue.element) + '</td>';
                    html += '    <td style="padding: 5px 6px; color: #3730a3; background: #eef2ff; word-break: break-word;">' + esc(issue.ai) + '</td>';
                    html += '  </tr>';
                });
                html += '</tbody></table>';
            }
            html += '</div>';

            /* Section 6: Link Accessibility */
            html += '<div style="page-break-inside: avoid; break-inside: avoid;">';
            html += renderSectionHeader("6. Link Purpose & Context", "WCAG 2.1 Guideline 2.4.4 Link Purpose (In Context) (Level A)", linkIssues.length, "✓ Descriptive Links");
            if (linkIssues.length === 0) {
                html += renderEmptyNotice("All anchor links possess clear, contextual purpose without vague link text.");
            } else {
                html += '<table style="width: 100%; border-collapse: collapse; font-size: 8.5px; margin-bottom: 8px; border: 1px solid #e2e8f0; table-layout: fixed;">';
                html += '  <thead><tr style="background: #f1f5f9; text-align: left; color: #475569; font-size: 8px;">';
                html += '    <th style="padding: 5px 6px; width: 22%;">Issue</th>';
                html += '    <th style="padding: 5px 6px; width: 16%;">Status</th>';
                html += '    <th style="padding: 5px 6px; width: 26%;">Anchor &amp; URL</th>';
                html += '    <th style="padding: 5px 6px; width: 36%;">Remediation Guidance</th>';
                html += '  </tr></thead><tbody>';
                linkIssues.forEach(function (issue, idx) {
                    html += '  <tr style="background: ' + (idx % 2 === 0 ? "#ffffff" : "#f8fafc") + '; border-bottom: 1px solid #e2e8f0;">';
                    html += '    <td style="padding: 5px 6px; font-weight: 700;">' + esc(issue.type) + '</td>';
                    html += '    <td style="padding: 5px 6px;">' + renderStatusBadge(issue.status) + '</td>';
                    html += '    <td style="padding: 5px 6px; word-break: break-all;"><strong>"' + esc(issue.text) + '"</strong><br><span style="font-size:7.5px;color:#64748b;">' + esc(issue.url) + '</span></td>';
                    html += '    <td style="padding: 5px 6px; color: #3730a3; background: #eef2ff; word-break: break-word;">' + esc(issue.ai) + '</td>';
                    html += '  </tr>';
                });
                html += '</tbody></table>';
            }
            html += '</div>';

            /* Section 7: Button Accessibility */
            html += '<div style="page-break-inside: avoid; break-inside: avoid;">';
            html += renderSectionHeader("7. Button Name, Role & Value", "WCAG 2.1 Guideline 4.1.2 Name, Role, Value (Level A)", buttonIssues.length, "✓ All Buttons Labeled");
            if (buttonIssues.length === 0) {
                html += renderEmptyNotice("All buttons and interactive action triggers possess valid accessible names.");
            } else {
                html += '<table style="width: 100%; border-collapse: collapse; font-size: 8.5px; margin-bottom: 8px; border: 1px solid #e2e8f0; table-layout: fixed;">';
                html += '  <thead><tr style="background: #f1f5f9; text-align: left; color: #475569; font-size: 8px;">';
                html += '    <th style="padding: 5px 6px; width: 22%;">Issue</th>';
                html += '    <th style="padding: 5px 6px; width: 16%;">Status</th>';
                html += '    <th style="padding: 5px 6px; width: 26%;">Element Target</th>';
                html += '    <th style="padding: 5px 6px; width: 36%;">Remediation Guidance</th>';
                html += '  </tr></thead><tbody>';
                buttonIssues.forEach(function (issue, idx) {
                    html += '  <tr style="background: ' + (idx % 2 === 0 ? "#ffffff" : "#f8fafc") + '; border-bottom: 1px solid #e2e8f0;">';
                    html += '    <td style="padding: 5px 6px; font-weight: 700;">' + esc(issue.type) + '</td>';
                    html += '    <td style="padding: 5px 6px;">' + renderStatusBadge(issue.status) + '</td>';
                    html += '    <td style="padding: 5px 6px; word-break: break-all; font-family: monospace; font-size: 7.5px;">' + esc(issue.element) + '</td>';
                    html += '    <td style="padding: 5px 6px; color: #3730a3; background: #eef2ff; word-break: break-word;">' + esc(issue.ai) + '</td>';
                    html += '  </tr>';
                });
                html += '</tbody></table>';
            }
            html += '</div>';

            /* Section 8: Text Resizing & Spacing */
            html += '<div style="page-break-inside: avoid; break-inside: avoid;">';
            html += renderSectionHeader("8. Text Resizing & Spacing", "WCAG 2.1 Guidelines 1.4.4 & 1.4.12 (Level AA)", textSpacingIssues.length, "✓ Resilient Spacing");
            if (textSpacingIssues.length === 0) {
                html += renderEmptyNotice("Content scales to 200% zoom and accommodates WCAG text spacing without clipping.");
            } else {
                html += '<table style="width: 100%; border-collapse: collapse; font-size: 8.5px; margin-bottom: 8px; border: 1px solid #e2e8f0; table-layout: fixed;">';
                html += '  <thead><tr style="background: #f1f5f9; text-align: left; color: #475569; font-size: 8px;">';
                html += '    <th style="padding: 5px 6px; width: 22%;">Issue</th>';
                html += '    <th style="padding: 5px 6px; width: 16%;">Status</th>';
                html += '    <th style="padding: 5px 6px; width: 26%;">Container &amp; Sample</th>';
                html += '    <th style="padding: 5px 6px; width: 36%;">Remediation Guidance</th>';
                html += '  </tr></thead><tbody>';
                textSpacingIssues.forEach(function (issue, idx) {
                    html += '  <tr style="background: ' + (idx % 2 === 0 ? "#ffffff" : "#f8fafc") + '; border-bottom: 1px solid #e2e8f0;">';
                    html += '    <td style="padding: 5px 6px; font-weight: 700;">' + esc(issue.type) + '</td>';
                    html += '    <td style="padding: 5px 6px;">' + renderStatusBadge(issue.status) + '</td>';
                    html += '    <td style="padding: 5px 6px; word-break: break-word;"><code>' + esc(issue.element) + '</code><br><em>' + esc(issue.sample) + '</em></td>';
                    html += '    <td style="padding: 5px 6px; color: #3730a3; background: #eef2ff; word-break: break-word;">' + esc(issue.ai) + '</td>';
                    html += '  </tr>';
                });
                html += '</tbody></table>';
            }
            html += '</div>';

            /* Section 9: Focus Visibility */
            html += '<div style="page-break-inside: avoid; break-inside: avoid;">';
            html += renderSectionHeader("9. Focus Visibility Indicators", "WCAG 2.1 Guideline 2.4.7 Focus Visible (Level AA)", focusIssues.length, "✓ Focus Rings Present");
            if (focusIssues.length === 0) {
                html += renderEmptyNotice("All interactive elements display clear visible focus rings when navigated via keyboard.");
            } else {
                html += '<table style="width: 100%; border-collapse: collapse; font-size: 8.5px; margin-bottom: 8px; border: 1px solid #e2e8f0; table-layout: fixed;">';
                html += '  <thead><tr style="background: #f1f5f9; text-align: left; color: #475569; font-size: 8px;">';
                html += '    <th style="padding: 5px 6px; width: 22%;">Issue</th>';
                html += '    <th style="padding: 5px 6px; width: 16%;">Status</th>';
                html += '    <th style="padding: 5px 6px; width: 26%;">Element Target</th>';
                html += '    <th style="padding: 5px 6px; width: 36%;">Remediation Guidance</th>';
                html += '  </tr></thead><tbody>';
                focusIssues.forEach(function (issue, idx) {
                    html += '  <tr style="background: ' + (idx % 2 === 0 ? "#ffffff" : "#f8fafc") + '; border-bottom: 1px solid #e2e8f0;">';
                    html += '    <td style="padding: 5px 6px; font-weight: 700;">' + esc(issue.type) + '</td>';
                    html += '    <td style="padding: 5px 6px;">' + renderStatusBadge(issue.status) + '</td>';
                    html += '    <td style="padding: 5px 6px; word-break: break-all;"><code>' + esc(issue.element) + '</code> (' + esc(issue.style) + ')</td>';
                    html += '    <td style="padding: 5px 6px; color: #3730a3; background: #eef2ff; word-break: break-word;">' + esc(issue.ai) + '</td>';
                    html += '  </tr>';
                });
                html += '</tbody></table>';
            }
            html += '</div>';

            /* Section 10: Table Accessibility */
            html += '<div style="page-break-inside: avoid; break-inside: avoid;">';
            html += renderSectionHeader("10. Data Table Relationships", "WCAG 2.1 Guideline 1.3.1 Info & Relationships (Level A)", tableIssues.length, "✓ Semantic Tables");
            if (tableIssues.length === 0) {
                html += renderEmptyNotice("Data tables feature explicit <th> header cells and valid scope associations.");
            } else {
                html += '<table style="width: 100%; border-collapse: collapse; font-size: 8.5px; margin-bottom: 8px; border: 1px solid #e2e8f0; table-layout: fixed;">';
                html += '  <thead><tr style="background: #f1f5f9; text-align: left; color: #475569; font-size: 8px;">';
                html += '    <th style="padding: 5px 6px; width: 22%;">Issue</th>';
                html += '    <th style="padding: 5px 6px; width: 16%;">Status</th>';
                html += '    <th style="padding: 5px 6px; width: 26%;">Table Structure</th>';
                html += '    <th style="padding: 5px 6px; width: 36%;">Remediation Guidance</th>';
                html += '  </tr></thead><tbody>';
                tableIssues.forEach(function (issue, idx) {
                    html += '  <tr style="background: ' + (idx % 2 === 0 ? "#ffffff" : "#f8fafc") + '; border-bottom: 1px solid #e2e8f0;">';
                    html += '    <td style="padding: 5px 6px; font-weight: 700;">' + esc(issue.type) + '</td>';
                    html += '    <td style="padding: 5px 6px;">' + renderStatusBadge(issue.status) + '</td>';
                    html += '    <td style="padding: 5px 6px; word-break: break-all;"><code>' + esc(issue.element) + '</code></td>';
                    html += '    <td style="padding: 5px 6px; color: #3730a3; background: #eef2ff; word-break: break-word;">' + esc(issue.ai) + '</td>';
                    html += '  </tr>';
                });
                html += '</tbody></table>';
            }
            html += '</div>';

            /* 5. Remediation Roadmap Box */
            html += '<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 14px; margin-top: 12px; page-break-inside: avoid; break-inside: avoid; box-sizing: border-box;">';
            html += '  <h4 style="margin: 0 0 4px; font-size: 9.5px; font-weight: 700; color: #0f172a; text-transform: uppercase;">10-Checkpoint Remediation Roadmap</h4>';
            html += '  <ul style="margin: 0; padding-left: 14px; font-size: 8.5px; color: #475569; line-height: 1.4;">';
            html += '    <li><strong>Priority 1 (Critical Operability):</strong> Restore visible keyboard focus rings (outline/box-shadow) and ensure all custom interactive elements receive Tab focus.</li>';
            html += '    <li><strong>Priority 2 (High Names & Content):</strong> Supply descriptive alternative text on all active link images and accessible names on all icon buttons.</li>';
            html += '    <li><strong>Priority 3 (Medium Sensory & Layout):</strong> Adjust low-contrast colors to achieve 4.5:1 ratio and eliminate fixed height containers that clip resized text.</li>';
            html += '    <li><strong>Priority 4 (Structure & Semantics):</strong> Provide consecutive heading levels (H1 &rarr; H2 &rarr; H3) and add <code>&lt;th scope="col"&gt;</code> to data tables.</li>';
            html += '  </ul>';
            html += '</div>';

            /* 6. Document Footer */
            html += '<div style="border-top: 1px solid #e2e8f0; margin-top: 12px; padding-top: 8px; display: flex; justify-content: space-between; font-size: 8px; color: #94a3b8; page-break-inside: avoid; break-inside: avoid; box-sizing: border-box;">';
            html += '  <span>AccessAI Automated WCAG Compliance Engine &bull; WordPress Accessibility Suite (10 Categories)</span>';
            html += '  <span>Automated Benchmark Assessment Report</span>';
            html += '</div>';
            html += '</div>';

            container.innerHTML = html;
            wrapper.appendChild(container);
            document.body.appendChild(wrapper);

            function cleanup() {
                if (wrapper && wrapper.parentNode) wrapper.parentNode.removeChild(wrapper);
                if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay);
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }

            var opt = {
                margin: [10, 10, 12, 10],
                filename: "AccessAI-Audit-Report-" + pageSlug + "-" + now.toISOString().slice(0, 10) + ".pdf",
                image: { type: "jpeg", quality: 0.98 },
                html2canvas: {
                    scale: 2,
                    useCORS: true,
                    letterRendering: true,
                    logging: false,
                    backgroundColor: "#ffffff",
                    scrollX: 0,
                    scrollY: 0,
                    onclone: function (clonedDoc) {
                        var clonedBox = clonedDoc.getElementById("accessai-pdf-render-box");
                        if (clonedBox) {
                            clonedBox.style.position = "static";
                            clonedBox.style.height = "auto";
                            clonedBox.style.opacity = "1";
                            clonedBox.style.display = "block";
                            clonedBox.style.width = "680px";
                            clonedBox.style.boxSizing = "border-box";
                        }
                        var overlays = clonedDoc.querySelectorAll(".html2pdf__overlay");
                        overlays.forEach(function (o) {
                            o.style.opacity = "1";
                            o.style.visibility = "visible";
                            o.style.backgroundColor = "#ffffff";
                        });
                        var containers = clonedDoc.querySelectorAll(".html2pdf__container");
                        containers.forEach(function (c) {
                            c.style.opacity = "1";
                            c.style.visibility = "visible";
                        });
                    }
                },
                jsPDF: {
                    unit: "mm",
                    format: "a4",
                    orientation: "portrait"
                },
                pagebreak: {
                    mode: ["css", "legacy"]
                }
            };

            setTimeout(function () {
                window.html2pdf().set(opt).from(container).save().then(function () {
                    cleanup();
                    var statusEl = document.getElementById("accessai-scan-status");
                    if (statusEl) {
                        statusEl.className = "accessai-scan-status is-ok";
                        statusEl.textContent = "✓ PDF accessibility report downloaded successfully!";
                        setTimeout(function () {
                            statusEl.className = "accessai-scan-status";
                            statusEl.textContent = "";
                        }, 5000);
                    }
                }).catch(function (err) {
                    cleanup();
                    alert("Could not generate PDF: " + (err.message || "Unknown error."));
                });
            }, 100);
        });
    }

    /* ------------------------------------------------------------------ */
    /* Bootstrap                                                             */
    /* ------------------------------------------------------------------ */
    document.addEventListener("DOMContentLoaded", function () {
        initTestButton();
        initPageScan();
        initPdfReportExport();

        // Auto-explain all active issue tables sequentially to avoid connection congestion.
        var tables = [
            "accessai-alt-table",
            "accessai-heading-table",
            "accessai-form-table",
            "accessai-contrast-table",
            "accessai-keyboard-table",
            "accessai-link-table",
            "accessai-button-table",
            "accessai-text-spacing-table",
            "accessai-focus-table",
            "accessai-table-table"
        ];
        tables.reduce(function (p, tableId) {
            return p.then(function () {
                return autoExplainTable(tableId);
            });
        }, Promise.resolve());
    });

})();
