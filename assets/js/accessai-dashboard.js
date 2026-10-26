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

            // Gather Alt Text findings
            var altRows = document.querySelectorAll("#accessai-alt-table tbody tr");
            var altIssues = [];
            altRows.forEach(function (r) {
                var cols = r.querySelectorAll("td");
                if (cols.length >= 4) {
                    altIssues.push({
                        type: cleanText(cols[0]),
                        severity: cleanText(cols[1]),
                        page: cleanText(cols[2]),
                        src: cleanText(cols[3]),
                        ai: getAiText(r) || "Add an informative, contextual alt attribute describing the image content or purpose."
                    });
                }
            });

            // Gather Heading findings
            var headingRows = document.querySelectorAll("#accessai-heading-table tbody tr");
            var headingIssues = [];
            headingRows.forEach(function (r) {
                var cols = r.querySelectorAll("td");
                if (cols.length >= 4) {
                    headingIssues.push({
                        type: cleanText(cols[0]),
                        page: cleanText(cols[1]),
                        transition: cleanText(cols[2]),
                        text: cleanText(cols[3]),
                        ai: getAiText(r) || "Structure headings hierarchically (e.g., H1 followed by H2, then H3) without skipping levels."
                    });
                }
            });

            // Gather Form Label findings
            var formRows = document.querySelectorAll("#accessai-form-table tbody tr");
            var formIssues = [];
            formRows.forEach(function (r) {
                var cols = r.querySelectorAll("td");
                if (cols.length >= 4) {
                    formIssues.push({
                        type: cleanText(cols[0]),
                        page: cleanText(cols[1]),
                        control: cleanText(cols[2]),
                        field: cleanText(cols[3]),
                        ai: getAiText(r) || "Associate a descriptive <label for='...'> or aria-label attribute with this form control."
                    });
                }
            });

            // Gather Color Contrast findings
            var contrastRows = document.querySelectorAll("#accessai-contrast-table tbody tr");
            var contrastIssues = [];
            contrastRows.forEach(function (r) {
                var cols = r.querySelectorAll("td");
                if (cols.length >= 4) {
                    contrastIssues.push({
                        element: cleanText(cols[0]),
                        sample: cleanText(cols[1]),
                        ratio: cleanText(cols[2]),
                        required: cleanText(cols[3]),
                        ai: getAiText(r) || "Increase luminance contrast between foreground text and background to at least 4.5:1."
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
                '<p style="margin: 0 0 16px; font-size: 12px; color: #64748b; line-height: 1.4;">Formatting WCAG compliance tables and scorecards&hellip;</p>' +
                '<div style="display: inline-flex; align-items: center; gap: 8px; color: #4f46e5; font-size: 12px; font-weight: 600;"><span class="dashicons dashicons-update accessai-spin"></span> Assembling pages&hellip;</div>' +
                '</div>';
            document.body.appendChild(overlay);

            // Offscreen wrapper with opacity 1 so html2canvas renders, but clipped out of user view
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
            container.style.fontSize = "11px";
            container.style.lineHeight = "1.45";
            container.style.padding = "18px 20px";
            container.style.boxSizing = "border-box";

            var html = "";

            /* 1. Header Banner */
            html += '<div style="background-color: #0f172a; border-radius: 12px; padding: 18px 20px; color: #ffffff; margin-bottom: 18px; box-sizing: border-box;">';
            html += '  <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 14px;">';
            html += '    <div>';
            html += '      <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">';
            html += '        <span style="background: #4f46e5; color: #ffffff; font-weight: 800; font-size: 13px; padding: 3px 8px; border-radius: 6px; letter-spacing: 0.5px;">AccessAI</span>';
            html += '        <span style="font-size: 10px; color: #cbd5e1; text-transform: uppercase; letter-spacing: 0.06em; font-weight: 700;">Accessibility Audit Report</span>';
            html += '      </div>';
            html += '      <h1 style="margin: 0; font-size: 18px; font-weight: 700; color: #ffffff; letter-spacing: -0.01em;">WCAG 2.1 Compliance Assessment</h1>';
            html += '    </div>';
            html += '    <div style="text-align: right;">';
            html += '      <div style="background: rgba(255,255,255,0.12); border: 1px solid rgba(255,255,255,0.22); border-radius: 8px; padding: 5px 10px; display: inline-block;">';
            html += '        <div style="font-size: 9px; color: #94a3b8; text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em;">Generated</div>';
            html += '        <div style="font-size: 10.5px; font-weight: 600; color: #ffffff;">' + esc(dateStr) + ' &bull; ' + esc(timeStr) + '</div>';
            html += '      </div>';
            html += '    </div>';
            html += '  </div>';

            html += '  <div style="display: flex; gap: 12px; border-top: 1px solid rgba(255,255,255,0.15); padding-top: 10px; font-size: 10.5px;">';
            html += '    <div style="flex: 2;">';
            html += '      <span style="color: #94a3b8; display: block; font-weight: 600; text-transform: uppercase; font-size: 8.5px; letter-spacing: 0.05em;">Audited Target</span>';
            html += '      <strong style="color: #ffffff; font-size: 12px;">' + esc(pageTitle) + '</strong>';
            html += '      <span style="color: #cbd5e1; display: block; font-size: 9.5px; word-break: break-all;">' + esc(pageUrl) + '</span>';
            html += '    </div>';
            html += '    <div style="flex: 1;">';
            html += '      <span style="color: #94a3b8; display: block; font-weight: 600; text-transform: uppercase; font-size: 8.5px; letter-spacing: 0.05em;">Compliance Standard</span>';
            html += '      <strong style="color: #ffffff; font-size: 11.5px;">WCAG 2.1 Level AA</strong>';
            html += '    </div>';
            html += '    <div style="flex: 1;">';
            html += '      <span style="color: #94a3b8; display: block; font-weight: 600; text-transform: uppercase; font-size: 8.5px; letter-spacing: 0.05em;">Audit Engine</span>';
            html += '      <strong style="color: #ffffff; font-size: 11.5px;">AccessAI Engine v1.2</strong>';
            html += '    </div>';
            html += '  </div>';
            html += '</div>';

            /* 2. Executive Scorecard & Metrics */
            html += '<div style="display: flex; gap: 10px; margin-bottom: 18px; page-break-inside: avoid; break-inside: avoid;">';
            html += '  <div style="flex: 1.25; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 14px; display: flex; align-items: center; gap: 12px; box-sizing: border-box;">';
            html += '    <div style="width: 52px; height: 52px; border-radius: 50%; background: ' + scoreBg + '; color: ' + scoreColor + '; border: 3px solid ' + scoreBorder + '; display: flex; flex-direction: column; align-items: center; justify-content: center; font-weight: 800; flex-shrink: 0;">';
            html += '      <span style="font-size: 19px; line-height: 1;">' + esc(score) + '</span>';
            html += '      <span style="font-size: 8px; opacity: 0.85;">/100</span>';
            html += '    </div>';
            html += '    <div>';
            html += '      <div style="font-size: 8.5px; text-transform: uppercase; font-weight: 700; color: #64748b; letter-spacing: 0.06em;">Accessibility Score</div>';
            html += '      <div style="font-size: 15px; font-weight: 800; color: #0f172a; margin: 1px 0;">' + esc(scoreLabel) + '</div>';
            html += '      <div style="font-size: 9.5px; color: #64748b;">' + esc(passedChecks) + ' of ' + esc(totalChecks) + ' Criteria Passing</div>';
            html += '    </div>';
            html += '  </div>';

            html += '  <div style="flex: 0.95; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 14px; box-sizing: border-box;">';
            html += '    <div style="font-size: 8.5px; text-transform: uppercase; font-weight: 700; color: #64748b; letter-spacing: 0.06em;">Total Violations</div>';
            html += '    <div style="font-size: 22px; font-weight: 800; color: ' + (parseInt(totalIssues, 10) > 0 ? '#dc2626' : '#059669') + '; margin: 1px 0;">' + esc(totalIssues) + '</div>';
            html += '    <div style="font-size: 9.5px; color: #64748b;">Actionable items detected</div>';
            html += '  </div>';

            html += '  <div style="flex: 1.15; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px 12px; font-size: 9.5px; box-sizing: border-box;">';
            html += '    <div style="font-size: 8.5px; text-transform: uppercase; font-weight: 700; color: #64748b; letter-spacing: 0.06em; margin-bottom: 4px;">Findings Breakdown</div>';
            html += '    <div style="display: flex; justify-content: space-between; margin-bottom: 2px;"><span style="color: #475569;">Image Alt Text:</span> <strong style="color: ' + (altIssues.length ? '#dc2626' : '#059669') + ';">' + altIssues.length + '</strong></div>';
            html += '    <div style="display: flex; justify-content: space-between; margin-bottom: 2px;"><span style="color: #475569;">Heading Hierarchy:</span> <strong style="color: ' + (headingIssues.length ? '#dc2626' : '#059669') + ';">' + headingIssues.length + '</strong></div>';
            html += '    <div style="display: flex; justify-content: space-between; margin-bottom: 2px;"><span style="color: #475569;">Form Labels:</span> <strong style="color: ' + (formIssues.length ? '#dc2626' : '#059669') + ';">' + formIssues.length + '</strong></div>';
            html += '    <div style="display: flex; justify-content: space-between;"><span style="color: #475569;">Color Contrast:</span> <strong style="color: ' + (contrastIssues.length ? '#dc2626' : '#059669') + ';">' + contrastIssues.length + '</strong></div>';
            html += '  </div>';
            html += '</div>';

            /* Helper function for category sections */
            function renderSectionHeader(title, wcag, count, isPassText) {
                var s = '<div style="display: flex; justify-content: space-between; align-items: center; border-left: 4px solid #4f46e5; padding-left: 10px; margin: 16px 0 8px; box-sizing: border-box;">';
                s += '  <div>';
                s += '    <h3 style="margin: 0; font-size: 12.5px; font-weight: 700; color: #0f172a;">' + esc(title) + '</h3>';
                s += '    <div style="font-size: 8.5px; color: #64748b;">' + esc(wcag) + '</div>';
                s += '  </div>';
                if (count === 0) {
                    s += '  <span style="background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; border-radius: 999px; padding: 2px 8px; font-size: 9px; font-weight: 700; white-space: nowrap;">' + esc(isPassText || '✓ All Clear') + '</span>';
                } else {
                    s += '  <span style="background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; border-radius: 999px; padding: 2px 8px; font-size: 9px; font-weight: 700; white-space: nowrap;">' + count + ' ' + (count === 1 ? 'Issue' : 'Issues') + ' Found</span>';
                }
                s += '</div>';
                return s;
            }

            function renderEmptyNotice(msg) {
                return '<div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 10px 14px; color: #166534; font-size: 9.5px; margin-bottom: 12px; box-sizing: border-box;"><strong>✓ Passed:</strong> ' + esc(msg) + '</div>';
            }

            /* Section 1: Image Alt Text */
            html += '<div style="page-break-inside: avoid; break-inside: avoid;">';
            html += renderSectionHeader("1. Image Alternative Text Audit", "WCAG 2.1 Guideline 1.1.1 Non-text Content (Level A)", altIssues.length, "✓ All Images Compliant");
            if (altIssues.length === 0) {
                html += renderEmptyNotice("All images examined in this page contain descriptive alternative text for screen readers.");
            } else {
                html += '<table style="width: 100%; border-collapse: collapse; font-size: 9px; margin-bottom: 12px; border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden; box-sizing: border-box;">';
                html += '  <thead><tr style="background: #f1f5f9; text-align: left; color: #475569; font-size: 8.5px; text-transform: uppercase;">';
                html += '    <th style="padding: 6px 8px; border-bottom: 1px solid #cbd5e1; width: 18%;">Issue</th>';
                html += '    <th style="padding: 6px 8px; border-bottom: 1px solid #cbd5e1; width: 10%;">Severity</th>';
                html += '    <th style="padding: 6px 8px; border-bottom: 1px solid #cbd5e1; width: 32%;">Image Resource</th>';
                html += '    <th style="padding: 6px 8px; border-bottom: 1px solid #cbd5e1; width: 40%;">Remediation Guidance</th>';
                html += '  </tr></thead><tbody>';
                altIssues.forEach(function (issue, idx) {
                    var bg = idx % 2 === 0 ? "#ffffff" : "#f8fafc";
                    html += '  <tr style="background: ' + bg + '; border-bottom: 1px solid #e2e8f0; page-break-inside: avoid; break-inside: avoid;">';
                    html += '    <td style="padding: 6px 8px; font-weight: 700; color: #0f172a;">' + esc(issue.type) + '</td>';
                    html += '    <td style="padding: 6px 8px;"><span style="background: #fee2e2; color: #991b1b; padding: 2px 5px; border-radius: 4px; font-weight: 700; font-size: 8px; white-space: nowrap;">' + esc(issue.severity) + '</span></td>';
                    html += '    <td style="padding: 6px 8px; font-family: monospace; font-size: 8px; word-break: break-all; color: #334155;">' + esc(issue.src) + '</td>';
                    html += '    <td style="padding: 6px 8px; color: #3730a3; background: #eef2ff; border-radius: 4px; line-height: 1.35;">' + esc(issue.ai) + '</td>';
                    html += '  </tr>';
                });
                html += '</tbody></table>';
            }
            html += '</div>';

            /* Section 2: Heading Structure */
            html += '<div style="page-break-inside: avoid; break-inside: avoid;">';
            html += renderSectionHeader("2. Heading Hierarchy & Structure", "WCAG 2.1 Guideline 1.3.1 Info & Relationships (Level A)", headingIssues.length, "✓ Logical Hierarchy");
            if (headingIssues.length === 0) {
                html += renderEmptyNotice("Headings follow a strict logical progression without any skipped heading levels.");
            } else {
                html += '<table style="width: 100%; border-collapse: collapse; font-size: 9px; margin-bottom: 12px; border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden; box-sizing: border-box;">';
                html += '  <thead><tr style="background: #f1f5f9; text-align: left; color: #475569; font-size: 8.5px; text-transform: uppercase;">';
                html += '    <th style="padding: 6px 8px; border-bottom: 1px solid #cbd5e1; width: 18%;">Issue</th>';
                html += '    <th style="padding: 6px 8px; border-bottom: 1px solid #cbd5e1; width: 14%;">Sequence</th>';
                html += '    <th style="padding: 6px 8px; border-bottom: 1px solid #cbd5e1; width: 28%;">Heading Text</th>';
                html += '    <th style="padding: 6px 8px; border-bottom: 1px solid #cbd5e1; width: 40%;">Remediation Guidance</th>';
                html += '  </tr></thead><tbody>';
                headingIssues.forEach(function (issue, idx) {
                    var bg = idx % 2 === 0 ? "#ffffff" : "#f8fafc";
                    html += '  <tr style="background: ' + bg + '; border-bottom: 1px solid #e2e8f0; page-break-inside: avoid; break-inside: avoid;">';
                    html += '    <td style="padding: 6px 8px; font-weight: 700; color: #0f172a;">' + esc(issue.type) + '</td>';
                    html += '    <td style="padding: 6px 8px;"><span style="background: #fef3c7; color: #92400e; padding: 2px 5px; border-radius: 4px; font-weight: 700; font-size: 8px; white-space: nowrap;">' + esc(issue.transition) + '</span></td>';
                    html += '    <td style="padding: 6px 8px; font-style: italic; color: #334155; word-break: break-word;">' + esc(issue.text) + '</td>';
                    html += '    <td style="padding: 6px 8px; color: #3730a3; background: #eef2ff; border-radius: 4px; line-height: 1.35;">' + esc(issue.ai) + '</td>';
                    html += '  </tr>';
                });
                html += '</tbody></table>';
            }
            html += '</div>';

            /* Section 3: Form Controls */
            html += '<div style="page-break-inside: avoid; break-inside: avoid;">';
            html += renderSectionHeader("3. Form Controls & Input Labels", "WCAG 2.1 Guideline 3.3.2 Labels or Instructions (Level A)", formIssues.length, "✓ All Controls Labeled");
            if (formIssues.length === 0) {
                html += renderEmptyNotice("All interactive form inputs, selects, and textareas have accessible names and labels.");
            } else {
                html += '<table style="width: 100%; border-collapse: collapse; font-size: 9px; margin-bottom: 12px; border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden; box-sizing: border-box;">';
                html += '  <thead><tr style="background: #f1f5f9; text-align: left; color: #475569; font-size: 8.5px; text-transform: uppercase;">';
                html += '    <th style="padding: 6px 8px; border-bottom: 1px solid #cbd5e1; width: 18%;">Issue</th>';
                html += '    <th style="padding: 6px 8px; border-bottom: 1px solid #cbd5e1; width: 16%;">Control Type</th>';
                html += '    <th style="padding: 6px 8px; border-bottom: 1px solid #cbd5e1; width: 26%;">Field Identifier</th>';
                html += '    <th style="padding: 6px 8px; border-bottom: 1px solid #cbd5e1; width: 40%;">Remediation Guidance</th>';
                html += '  </tr></thead><tbody>';
                formIssues.forEach(function (issue, idx) {
                    var bg = idx % 2 === 0 ? "#ffffff" : "#f8fafc";
                    html += '  <tr style="background: ' + bg + '; border-bottom: 1px solid #e2e8f0; page-break-inside: avoid; break-inside: avoid;">';
                    html += '    <td style="padding: 6px 8px; font-weight: 700; color: #0f172a;">' + esc(issue.type) + '</td>';
                    html += '    <td style="padding: 6px 8px;"><span style="background: #f1f5f9; color: #475569; padding: 2px 5px; border-radius: 4px; font-weight: 600; font-size: 8px; white-space: nowrap;">' + esc(issue.control) + '</span></td>';
                    html += '    <td style="padding: 6px 8px; font-family: monospace; font-size: 8px; color: #334155; word-break: break-word;">' + esc(issue.field) + '</td>';
                    html += '    <td style="padding: 6px 8px; color: #3730a3; background: #eef2ff; border-radius: 4px; line-height: 1.35;">' + esc(issue.ai) + '</td>';
                    html += '  </tr>';
                });
                html += '</tbody></table>';
            }
            html += '</div>';

            /* Section 4: Color Contrast */
            html += '<div style="page-break-inside: avoid; break-inside: avoid;">';
            html += renderSectionHeader("4. Computed Color Contrast Ratio", "WCAG 2.1 Guideline 1.4.3 Contrast Minimum (Level AA)", contrastIssues.length, "✓ AA Compliant");
            if (contrastIssues.length === 0) {
                html += renderEmptyNotice("No color contrast violations detected. Tested text elements satisfy WCAG AA minimum 4.5:1 ratio.");
            } else {
                html += '<table style="width: 100%; border-collapse: collapse; font-size: 9px; margin-bottom: 12px; border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden; box-sizing: border-box;">';
                html += '  <thead><tr style="background: #f1f5f9; text-align: left; color: #475569; font-size: 8.5px; text-transform: uppercase;">';
                html += '    <th style="padding: 6px 8px; border-bottom: 1px solid #cbd5e1; width: 22%;">Element Selector</th>';
                html += '    <th style="padding: 6px 8px; border-bottom: 1px solid #cbd5e1; width: 22%;">Text Preview</th>';
                html += '    <th style="padding: 6px 8px; border-bottom: 1px solid #cbd5e1; width: 16%;">Measured vs Min</th>';
                html += '    <th style="padding: 6px 8px; border-bottom: 1px solid #cbd5e1; width: 40%;">Remediation Guidance</th>';
                html += '  </tr></thead><tbody>';
                contrastIssues.forEach(function (issue, idx) {
                    var bg = idx % 2 === 0 ? "#ffffff" : "#f8fafc";
                    html += '  <tr style="background: ' + bg + '; border-bottom: 1px solid #e2e8f0; page-break-inside: avoid; break-inside: avoid;">';
                    html += '    <td style="padding: 6px 8px; font-family: monospace; font-size: 8px; color: #0f172a; word-break: break-all;">' + esc(issue.element) + '</td>';
                    html += '    <td style="padding: 6px 8px; font-style: italic; color: #334155; word-break: break-word;">' + esc(issue.sample) + '</td>';
                    html += '    <td style="padding: 6px 8px;"><span style="background: #fee2e2; color: #991b1b; padding: 2px 5px; border-radius: 4px; font-weight: 700; font-size: 8px; white-space: nowrap;">' + esc(issue.ratio) + '</span> <span style="color: #64748b; font-size: 8px; white-space: nowrap;">(need ' + esc(issue.required) + ')</span></td>';
                    html += '    <td style="padding: 6px 8px; color: #3730a3; background: #eef2ff; border-radius: 4px; line-height: 1.35;">' + esc(issue.ai) + '</td>';
                    html += '  </tr>';
                });
                html += '</tbody></table>';
            }
            html += '</div>';

            /* 5. Remediation Roadmap Box */
            html += '<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 16px; margin-top: 16px; page-break-inside: avoid; break-inside: avoid; box-sizing: border-box;">';
            html += '  <h4 style="margin: 0 0 5px; font-size: 10.5px; font-weight: 700; color: #0f172a; text-transform: uppercase; letter-spacing: 0.05em;">Recommended Remediation Priority</h4>';
            html += '  <ul style="margin: 0; padding-left: 16px; font-size: 9.5px; color: #475569; line-height: 1.45;">';
            html += '    <li><strong>Priority 1 (Critical):</strong> Populate missing alt text on all active link images and informational graphics.</li>';
            html += '    <li><strong>Priority 2 (High):</strong> Ensure every interactive form field has a distinct, programmatic label for assistive technologies.</li>';
            html += '    <li><strong>Priority 3 (Medium):</strong> Adjust CSS color values on flagged low-contrast elements to achieve the 4.5:1 ratio requirement.</li>';
            html += '    <li><strong>Priority 4 (Structural):</strong> Maintain continuous H1 &rarr; H2 &rarr; H3 heading hierarchy for proper screen reader landmark navigation.</li>';
            html += '  </ul>';
            html += '</div>';

            /* 6. Document Footer */
            html += '<div style="border-top: 1px solid #e2e8f0; margin-top: 16px; padding-top: 10px; display: flex; justify-content: space-between; font-size: 8.5px; color: #94a3b8; page-break-inside: avoid; break-inside: avoid; box-sizing: border-box;">';
            html += '  <span>AccessAI Automated WCAG Compliance Engine &bull; WordPress Accessibility Suite</span>';
            html += '  <span>Confidential &bull; Page 1 of Audit Report</span>';
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
            "accessai-contrast-table"
        ];
        tables.reduce(function (p, tableId) {
            return p.then(function () {
                return autoExplainTable(tableId);
            });
        }, Promise.resolve());
    });

})();
