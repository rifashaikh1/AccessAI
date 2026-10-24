/**
 * AccessAI — Colour Contrast Scanner
 * Runs automatically inside the secure scan iframe opened by the dashboard.
 * Tokens are verified server-side; no credentials appear in this script.
 */
(function () {
    "use strict";

    /* -------- colour parsing -------- */
    function parseColor(color) {
        var match = color.match(
            /rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)(?:\s*[,/]\s*([\d.]+))?\s*\)/
        );
        if (!match) return null;
        return {
            r: Number(match[1]),
            g: Number(match[2]),
            b: Number(match[3]),
            a: match[4] === undefined ? 1 : Number(match[4])
        };
    }

    function getLuminance(color) {
        var channels = [color.r, color.g, color.b].map(function (v) {
            v /= 255;
            return v <= 0.04045 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
        });
        return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
    }

    function getContrastRatio(fg, bg) {
        var l1 = getLuminance(fg);
        var l2 = getLuminance(bg);
        var lighter = Math.max(l1, l2);
        var darker = Math.min(l1, l2);
        return (lighter + 0.05) / (darker + 0.05);
    }

    function getBackground(element) {
        var current = element;
        while (current && current !== document.documentElement) {
            var color = parseColor(getComputedStyle(current).backgroundColor);
            if (color && color.a >= 1) return color;
            current = current.parentElement;
        }
        // Fall back to white (assumption; may be incorrect on some sites).
        return { r: 255, g: 255, b: 255, a: 1 };
    }

    /* -------- scan -------- */
    function scanContrast() {
        var elements = document.querySelectorAll(
            "div, p, span, a, li, label, button, h1, h2, h3, h4, h5, h6, td, th"
        );

        var issues = [];
        var checked = 0;
        // Deduplicate by a text+ratio key so the same block isn't reported multiple times.
        var seen = {};

        elements.forEach(function (element) {
            if (!element.textContent.trim()) return;

            var style = getComputedStyle(element);
            if (
                style.display === "none" ||
                style.visibility === "hidden" ||
                Number(style.opacity) === 0
            ) {
                return;
            }

            var foreground = parseColor(style.color);
            if (!foreground || foreground.a < 1) return;

            var background = getBackground(element);
            var ratio = getContrastRatio(foreground, background);
            var fontSize = parseFloat(style.fontSize);
            var isBold = parseInt(style.fontWeight, 10) >= 700;
            var isLargeText = fontSize >= 24 || (fontSize >= 18.66 && isBold);
            var required = isLargeText ? 3 : 4.5;

            checked++;

            if (ratio < required) {
                var dedupeKey =
                    element.tagName +
                    "|" + element.textContent.trim().slice(0, 60) +
                    "|" + ratio.toFixed(2);

                if (seen[dedupeKey]) return;
                seen[dedupeKey] = true;

                issues.push({
                    element: element.tagName.toLowerCase(),
                    text: element.textContent.trim().slice(0, 100),
                    ratio: ratio.toFixed(2) + ":1",
                    required: required + ":1"
                });
            }
        });

        console.group("AccessAI — Colour Contrast Scan");
        console.log("Text elements checked:", checked);
        console.log("Potential contrast issues:", issues.length);
        issues.forEach(function (i) {
            console.warn(i.element, '"' + i.text + '"', "Contrast:", i.ratio, "Required:", i.required);
        });
        console.groupEnd();

        return issues;
    }

    /* -------- send results -------- */
    function sendResults(ajaxUrl, nonce, issues) {
        var body = new URLSearchParams();
        body.append("action", "accessai_save_contrast_results");
        body.append("nonce", nonce);
        body.append("results", JSON.stringify(issues));

        return fetch(ajaxUrl, {
            method: "POST",
            credentials: "same-origin",
            headers: { "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8" },
            body: body.toString()
        })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (resp.success) {
                    console.log("AccessAI: Contrast results saved. Count:", resp.data.count);
                } else {
                    console.error("AccessAI: Server rejected results.", resp);
                }
            })
            .catch(function (err) {
                console.error("AccessAI: Could not send contrast results.", err);
            });
    }

    /* -------- token-based auto-trigger -------- */
    function runIfTokenPresent() {
        var tokenMeta = document.querySelector('meta[name="accessai-scan-token"]');
        var ajaxUrlMeta = document.querySelector('meta[name="accessai-ajax-url"]');

        if (!tokenMeta || !ajaxUrlMeta) return; // Not a scan request.

        var token = tokenMeta.getAttribute("content") || "";
        var ajaxUrl = ajaxUrlMeta.getAttribute("content") || "";

        if (!token || !ajaxUrl) return;

        console.log("AccessAI: Scan token detected. Verifying…");

        // Step 1: verify the token server-side to get the result nonce.
        var verifyBody = new URLSearchParams({ action: "accessai_verify_scan_token", token: token });
        fetch(ajaxUrl, {
            method: "POST",
            credentials: "same-origin",
            headers: { "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8" },
            body: verifyBody.toString()
        })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (!resp.success) {
                    console.warn("AccessAI: Token verification failed.", resp);
                    return;
                }
                var resultNonce = resp.data.resultNonce;
                var resultAjax = resp.data.ajaxUrl;

                console.log("AccessAI: Token verified. Running contrast scan…");
                var issues = scanContrast();
                return sendResults(resultAjax, resultNonce, issues);
            })
            .catch(function (err) {
                console.error("AccessAI: Token verification error.", err);
            });
    }

    /* -------- public API (kept for manual console use) -------- */
    window.AccessAIContrastScanner = {
        scan: function (ajaxUrl, nonce) {
            var issues = scanContrast();
            if (ajaxUrl && nonce) sendResults(ajaxUrl, nonce, issues);
            return issues;
        }
    };

    document.addEventListener("DOMContentLoaded", runIfTokenPresent);

})();
