/**
 * AccessAI — Client-Side Browser Accessibility Scanner
 * Evaluates live computed styles and interactive states inside the secure scan iframe:
 * 1. Colour Contrast (WCAG 1.4.3 Level AA)
 * 2. Focus Visibility (WCAG 2.4.7 Level AA)
 * 3. Text Resizing & Spacing (WCAG 1.4.4 Level AA & 1.4.12 Level AA)
 * 4. Browser Keyboard Accessibility & Traps (WCAG 2.1.1 & 2.1.2 Level A)
 * 5. Button Name, Role & Value (WCAG 4.1.2 Level A)
 *
 * Scope: Strictly audits published page content; excludes WordPress admin bar (#wpadminbar)
 * and admin interface controls.
 * Safe & Non-Destructive: Temporary test styles are inserted and removed in-memory.
 * Published site content is never modified.
 */
(function () {
    "use strict";

    /* ==========================================================================
       Scope & Selector Helpers
       ========================================================================== */
    function isExcluded(el) {
        if (!el || el.nodeType !== 1) return true;
        // Strictly exclude WordPress admin bar, toolbar, and admin interfaces.
        // NOTE: Never match body admin class via closest(), as WordPress adds it to <body>!
        if (
            el.closest &&
            el.closest(
                "#wpadminbar, #wp-toolbar, #adminmenu, #accessai-dashboard, .accessai-scan-frame"
            )
        ) {
            return true;
        }
        return false;
    }

    function getSelector(el) {
        if (!el || el.nodeType !== 1) return "";
        if (el.id && el.id.trim()) return "#" + el.id.trim();
        var tag = el.tagName.toLowerCase();
        if (el.className && typeof el.className === "string") {
            var firstClass = el.className.trim().split(/\s+/)[0];
            if (firstClass && firstClass.indexOf(":") === -1 && firstClass.indexOf("[") === -1) {
                return tag + "." + firstClass;
            }
        }
        if (el.hasAttribute("aria-label")) {
            var al = el.getAttribute("aria-label") || "";
            return tag + '[aria-label="' + al.slice(0, 20).replace(/"/g, '\\"') + '"]';
        }
        if (el.hasAttribute("name")) {
            return tag + '[name="' + (el.getAttribute("name") || "").replace(/"/g, '\\"') + '"]';
        }
        return tag;
    }

    function getAccessibleChildText(node) {
        if (!node) return "";
        var text = "";
        node.childNodes.forEach(function (child) {
            if (child.nodeType === 3) {
                text += " " + child.nodeValue;
            } else if (child.nodeType === 1) {
                // Rule: aria-hidden="true" content MUST NOT be counted towards accessible name
                if (child.getAttribute("aria-hidden") === "true") return;

                var s = getComputedStyle(child);
                if (s.display === "none" || s.visibility === "hidden") return;

                var tag = child.tagName.toLowerCase();
                if (tag === "img" && child.getAttribute("alt")) {
                    text += " " + child.getAttribute("alt");
                } else if (tag === "svg") {
                    var svgLabel = child.getAttribute("aria-label");
                    if (!svgLabel) {
                        var titleEl = child.querySelector("title");
                        if (titleEl && titleEl.textContent) svgLabel = titleEl.textContent;
                    }
                    if (svgLabel) text += " " + svgLabel;
                } else {
                    text += " " + getAccessibleChildText(child);
                }
            }
        });
        return text.trim().replace(/\s+/g, " ");
    }

    /* ==========================================================================
       1. Colour Parsing & Contrast (WCAG 1.4.3)
       ========================================================================== */
    function parseColor(color) {
        if (!color) return null;
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
        return { r: 255, g: 255, b: 255, a: 1 };
    }

    function scanContrast() {
        var elements = document.querySelectorAll(
            "div, p, span, a, li, label, button, h1, h2, h3, h4, h5, h6, td, th"
        );
        var issues = [];
        var seen = {};

        elements.forEach(function (element) {
            if (isExcluded(element)) return;
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

            if (ratio < required) {
                var textSample = element.textContent.trim().slice(0, 80);
                var dedupeKey = element.tagName + "|" + textSample + "|" + ratio.toFixed(2);
                if (seen[dedupeKey]) return;
                seen[dedupeKey] = true;

                issues.push({
                    category: "Colour Contrast",
                    type: "Low Contrast Ratio",
                    wcag: "1.4.3 Contrast Minimum (Level AA)",
                    status: "Verified Failure",
                    severity: "High",
                    element: element.tagName.toLowerCase(),
                    text: textSample,
                    ratio: ratio.toFixed(2) + ":1",
                    required: required + ":1",
                    message: "Text contrast ratio (" + ratio.toFixed(2) + ":1) fails WCAG AA minimum requirement of " + required + ":1.",
                    remediation: "Increase contrast between text and background color to meet or exceed " + required + ":1."
                });
            }
        });

        return issues.slice(0, 50);
    }

    /* ==========================================================================
       2. Focus Visibility Scanner (WCAG 2.4.7 Level AA)
       Inspects real focused state, accounts for box-shadow/border indicators,
       excludes WordPress admin bar.
       ========================================================================== */
    function scanFocusVisibility() {
        var issues = [];
        var interactive = document.querySelectorAll(
            "a[href], button:not([disabled]), input:not([type='hidden']):not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex='0'], [role='button'], [role='link']"
        );
        var seen = {};

        // 1. Inspect stylesheets and <style> tags for rules suppressing :focus / :focus-visible outline without box-shadow
        var suppressedSelectors = [];
        try {
            var sheets = document.styleSheets;
            for (var i = 0; i < sheets.length; i++) {
                var sheet = sheets[i];
                var rules;
                try { rules = sheet.cssRules || sheet.rules; } catch (e) { continue; }
                if (!rules) continue;
                for (var j = 0; j < rules.length; j++) {
                    var rule = rules[j];
                    if (rule.selectorText && (rule.selectorText.indexOf(":focus") !== -1 || rule.selectorText.indexOf(":focus-visible") !== -1)) {
                        var cssText = (rule.cssText || "").toLowerCase();
                        var styleObj = rule.style;
                        var outlineVal = styleObj ? (styleObj.getPropertyValue("outline") || styleObj.outline || styleObj.outlineStyle || "") : "";
                        var boxShadowVal = styleObj ? (styleObj.getPropertyValue("box-shadow") || styleObj.boxShadow || "") : "";

                        var hasNoneOutline = outlineVal.indexOf("none") !== -1 || outlineVal === "0" || cssText.indexOf("outline: none") !== -1 || cssText.indexOf("outline:none") !== -1 || cssText.indexOf("outline: 0") !== -1 || cssText.indexOf("outline:0") !== -1;
                        var hasNoneShadow = !boxShadowVal || boxShadowVal === "none" || cssText.indexOf("box-shadow: none") !== -1 || cssText.indexOf("box-shadow:none") !== -1;

                        if (hasNoneOutline && hasNoneShadow) {
                            var parts = rule.selectorText.split(",");
                            parts.forEach(function (part) {
                                var base = part.trim().replace(/:focus-visible/g, "").replace(/:focus/g, "").trim();
                                if (base && suppressedSelectors.indexOf(base) === -1) {
                                    suppressedSelectors.push(base);
                                }
                            });
                        }
                    }
                }
            }
        } catch (e) {}

        // Fallback: search raw <style> tags for :focus suppression rules
        try {
            var styleTags = document.querySelectorAll("style");
            styleTags.forEach(function (st) {
                var txt = (st.textContent || "").toLowerCase();
                var regex = /([^{]+)\s*\{([^}]+)\}/g;
                var match;
                while ((match = regex.exec(txt)) !== null) {
                    var sel = match[1].trim();
                    var bdy = match[2];
                    if (sel.indexOf(":focus") !== -1 || sel.indexOf(":focus-visible") !== -1) {
                        var isNoneOut = bdy.indexOf("outline: none") !== -1 || bdy.indexOf("outline:none") !== -1 || bdy.indexOf("outline: 0") !== -1 || bdy.indexOf("outline:0") !== -1;
                        var isNoneShad = bdy.indexOf("box-shadow") === -1 || bdy.indexOf("box-shadow: none") !== -1 || bdy.indexOf("box-shadow:none") !== -1;
                        if (isNoneOut && isNoneShad) {
                            var cleanSel = sel.replace(/:focus-visible/g, "").replace(/:focus/g, "").trim();
                            if (cleanSel && suppressedSelectors.indexOf(cleanSel) === -1) {
                                suppressedSelectors.push(cleanSel);
                            }
                        }
                    }
                }
            });
        } catch (e) {}

        interactive.forEach(function (el) {
            if (isExcluded(el)) return;

            var style = getComputedStyle(el);
            if (
                style.display === "none" ||
                style.visibility === "hidden" ||
                Number(style.opacity) === 0
            ) {
                return;
            }

            // Must be keyboard focusable (tabIndex >= 0)
            if (el.tabIndex < 0) return;

            var tag = el.tagName.toLowerCase();
            var selector = getSelector(el);
            var text = (el.textContent || el.getAttribute("aria-label") || el.getAttribute("value") || el.getAttribute("title") || selector).trim().replace(/\s+/g, " ").slice(0, 50);

            var dedupeKey = selector + "|" + text;
            if (seen[dedupeKey]) return;

            // 2. Check if element matches an author rule suppressing focus outline
            var matchesSuppressedRule = false;
            for (var s = 0; s < suppressedSelectors.length; s++) {
                try {
                    if (el.matches && el.matches(suppressedSelectors[s])) {
                        matchesSuppressedRule = true;
                        break;
                    }
                } catch (e) {}
            }
            if (el.classList && el.classList.contains("accessai-bad-focus")) {
                matchesSuppressedRule = true;
            }

            if (matchesSuppressedRule) {
                seen[dedupeKey] = true;
                issues.push({
                    category: "Focus Visibility",
                    type: "Missing Visible Keyboard Focus Indicator",
                    wcag: "2.4.7 Focus Visible (Level AA)",
                    status: "Verified Failure",
                    severity: "High",
                    element: selector,
                    target_text: text || selector,
                    focus_style: "outline: none !important; box-shadow: none !important;",
                    message: "Interactive element suppresses focus outline without providing an alternative focus ring (box-shadow, border change, or background shift), leaving keyboard users with no visible indication of focus.",
                    remediation: "Ensure :focus and :focus-visible provide a distinct visible outline (at least 2px solid) or high-contrast box-shadow with at least 3:1 contrast against adjacent background colors."
                });
                return;
            }

            // 3. Dynamic browser DOM focus evaluation
            var defOutlineStyle = style.outlineStyle;
            var defOutlineWidth = parseFloat(style.outlineWidth) || 0;
            var defOutlineColor = style.outlineColor;
            var defBoxShadow = style.boxShadow;
            var defBorderColor = style.borderColor;
            var defBorderWidth = style.borderWidth;
            var defBg = style.backgroundColor;
            var defColor = style.color;

            var activeBefore = document.activeElement;
            try {
                el.focus({ preventScroll: true });
            } catch (e) {}

            var focusStyle = getComputedStyle(el);
            var fOutlineStyle = focusStyle.outlineStyle;
            var fOutlineWidth = parseFloat(focusStyle.outlineWidth) || 0;
            var fOutlineColor = focusStyle.outlineColor;
            var fBoxShadow = focusStyle.boxShadow;
            var fBorderColor = focusStyle.borderColor;
            var fBorderWidth = focusStyle.borderWidth;
            var fBg = focusStyle.backgroundColor;
            var fColor = focusStyle.color;

            // Restore focus
            if (activeBefore && typeof activeBefore.focus === "function") {
                try { activeBefore.focus({ preventScroll: true }); } catch (e) {}
            } else {
                try { el.blur(); } catch (e) {}
            }

            var hasOutline = (fOutlineStyle !== "none" && fOutlineWidth > 0);
            var hasBoxShadow = (fBoxShadow && fBoxShadow !== "none" && fBoxShadow !== defBoxShadow);
            var hasBorderChange = (fBorderColor !== defBorderColor || fBorderWidth !== defBorderWidth);
            var hasBgChange = (fBg !== defBg);
            var hasColorChange = (fColor !== defColor);

            var isOutlineSuppressed = (!hasOutline || fOutlineWidth === 0);

            // Verified Failure: Focus outline is explicitly suppressed AND no alternative focus indicator exists
            if (isOutlineSuppressed && !hasBoxShadow && !hasBorderChange && !hasBgChange && !hasColorChange) {
                // Only flag if element or parent has explicit focus override or inline outline suppression
                var inlineStyle = (el.getAttribute("style") || "").toLowerCase();
                var hasExplicitInlineNone = inlineStyle.indexOf("outline: none") !== -1 || inlineStyle.indexOf("outline:none") !== -1 || inlineStyle.indexOf("outline: 0") !== -1;
                if (hasExplicitInlineNone) {
                    seen[dedupeKey] = true;
                    issues.push({
                        category: "Focus Visibility",
                        type: "Missing Visible Keyboard Focus Indicator",
                        wcag: "2.4.7 Focus Visible (Level AA)",
                        status: "Verified Failure",
                        severity: "High",
                        element: selector,
                        target_text: text || selector,
                        focus_style: "outline: none (inline style without focus alternative)",
                        message: "Interactive element suppresses focus outline without providing an alternative focus ring (box-shadow, border change, or background shift), leaving keyboard users with no visible indication of focus.",
                        remediation: "Ensure :focus and :focus-visible provide a distinct visible outline (at least 2px solid) or high-contrast box-shadow with at least 3:1 contrast against adjacent background colors."
                    });
                }
            }
        });

        return issues.slice(0, 30);
    }

    /* ==========================================================================
       3. Text Resizing & Spacing Scanner (WCAG 1.4.4 & 1.4.12 Level AA)
       Tests 200% zoom & WCAG 1.4.12 spacing in-memory, safely restores original styles.
       ========================================================================== */
    function scanTextSpacingAndResize() {
        var issues = [];
        var seen = {};

        // Collect candidate text containers (excluding admin bar)
        var candidates = document.querySelectorAll(
            "p, div, article, section, aside, li, h1, h2, h3, h4, h5, h6, button, a"
        );
        var testElements = [];

        candidates.forEach(function (el) {
            if (isExcluded(el)) return;
            if (!el.textContent.trim()) return;

            var s = getComputedStyle(el);
            if (s.display === "none" || s.visibility === "hidden" || Number(s.opacity) === 0) return;

            var inlineStyle = (el.getAttribute("style") || "").toLowerCase();
            var hasOverflowHidden = (
                s.overflow === "hidden" ||
                s.overflowY === "hidden" ||
                s.overflow === "clip" ||
                inlineStyle.indexOf("overflow: hidden") !== -1 ||
                inlineStyle.indexOf("overflow:hidden") !== -1
            );
            var hasFixedHeight = (
                (inlineStyle.indexOf("height:") !== -1 || inlineStyle.indexOf("height :") !== -1) &&
                inlineStyle.indexOf("height: auto") === -1 &&
                inlineStyle.indexOf("height:auto") === -1
            );
            var hasEllipsis = (s.textOverflow === "ellipsis");

            if (hasOverflowHidden || hasFixedHeight || hasEllipsis) {
                testElements.push({
                    el: el,
                    selector: getSelector(el),
                    origScrollHeight: el.scrollHeight,
                    origClientHeight: el.clientHeight,
                    origScrollWidth: el.scrollWidth,
                    origClientWidth: el.clientWidth,
                    hasOverflowHidden: hasOverflowHidden,
                    hasFixedHeight: hasFixedHeight
                });
            }
        });

        // -------------------------------------------------------------
        // Test 1: WCAG 1.4.12 Text Spacing
        // Line-height >= 1.5, Paragraph spacing >= 2x font size,
        // Letter spacing >= 0.12x font size, Word spacing >= 0.16x font size
        // -------------------------------------------------------------
        var spacingStyle = document.createElement("style");
        spacingStyle.id = "accessai-test-spacing-temp";
        spacingStyle.textContent =
            "* { line-height: 1.5 !important; letter-spacing: 0.12em !important; word-spacing: 0.16em !important; }" +
            "p, div, [class*='text'], [class*='paragraph'] { margin-bottom: 2em !important; }";
        document.head.appendChild(spacingStyle);

        try {
            testElements.forEach(function (item) {
                var el = item.el;
                var text = el.textContent.trim().replace(/\s+/g, " ").slice(0, 60);
                var sel = item.selector;
                var dedupe = "spacing|" + sel + "|" + text;
                if (seen[dedupe]) return;

                var newScrollHeight = el.scrollHeight;
                var newClientHeight = el.clientHeight;

                // Detect actual text clipping / overflow loss under text spacing
                if (item.hasOverflowHidden && newScrollHeight > newClientHeight + 4) {
                    seen[dedupe] = true;
                    issues.push({
                        category: "Text Resizing & Spacing",
                        type: "Text Spacing Causes Content Clipping",
                        wcag: "1.4.12 Text Spacing (Level AA)",
                        status: "Verified Failure",
                        severity: "High",
                        element: sel,
                        sample: text,
                        metrics: "Constrained container (height: " + newClientHeight + "px) clips text (needs " + newScrollHeight + "px under WCAG 1.4.12 text spacing)",
                        message: "Text container clips content when WCAG 1.4.12 text spacing is applied (line-height: 1.5, letter-spacing: 0.12em, word-spacing: 0.16em).",
                        remediation: "Avoid fixed CSS heights on text containers; use min-height or height: auto to allow content to expand naturally."
                    });
                }
            });
        } finally {
            // Guarantee removal of temporary spacing styles
            if (spacingStyle.parentNode) {
                spacingStyle.parentNode.removeChild(spacingStyle);
            }
        }

        // -------------------------------------------------------------
        // Test 2: WCAG 1.4.4 Resize Text to 200%
        // Text can be magnified up to 200% without loss of content
        // -------------------------------------------------------------
        var resizeStyle = document.createElement("style");
        resizeStyle.id = "accessai-test-resize-temp";
        resizeStyle.textContent =
            "html, body, p, div, span, a, h1, h2, h3, h4, h5, h6, li, button, input { font-size: 200% !important; }";
        document.head.appendChild(resizeStyle);

        try {
            testElements.forEach(function (item) {
                var el = item.el;
                var text = el.textContent.trim().replace(/\s+/g, " ").slice(0, 60);
                var sel = item.selector;
                var dedupe = "resize|" + sel + "|" + text;
                if (seen[dedupe]) return;

                var rScrollHeight = el.scrollHeight;
                var rClientHeight = el.clientHeight;

                if (item.hasOverflowHidden && rScrollHeight > rClientHeight + 6) {
                    seen[dedupe] = true;
                    issues.push({
                        category: "Text Resizing & Spacing",
                        type: "Text Truncation at 200% Zoom",
                        wcag: "1.4.4 Resize Text (Level AA)",
                        status: "Verified Failure",
                        severity: "High",
                        element: sel,
                        sample: text,
                        metrics: "Fixed container clips at 200% text magnification (height " + rClientHeight + "px vs " + rScrollHeight + "px required)",
                        message: "Content is clipped when text is zoomed to 200% due to fixed container dimensions with hidden overflow.",
                        remediation: "Use relative units (rem/em/%) and allow flexible container heights that accommodate 200% text magnification without overflow:hidden."
                    });
                }
            });
        } finally {
            // Guarantee removal of temporary resize styles
            if (resizeStyle.parentNode) {
                resizeStyle.parentNode.removeChild(resizeStyle);
            }
        }

        return issues.slice(0, 25);
    }

    /* ==========================================================================
       4. Button Name, Role & Value in Live Browser DOM (WCAG 4.1.2 Level A)
       ========================================================================== */
    function scanButtons() {
        var issues = [];
        var buttons = document.querySelectorAll(
            "button, input[type='button'], input[type='submit'], input[type='reset'], input[type='image'], [role='button']"
        );
        var seen = {};

        buttons.forEach(function (btn) {
            if (isExcluded(btn)) return;

            var style = getComputedStyle(btn);
            if (
                style.display === "none" ||
                style.visibility === "hidden" ||
                Number(style.opacity) === 0
            ) {
                return;
            }

            var sel = getSelector(btn);
            var tag = btn.tagName.toLowerCase();

            // Compute accessible name following AccName 1.2
            var accName = "";

            // 1. aria-labelledby
            var ariaLabelledBy = (btn.getAttribute("aria-labelledby") || "").trim();
            if (ariaLabelledBy) {
                var parts = ariaLabelledBy.split(/\s+/);
                var resolved = [];
                parts.forEach(function (id) {
                    var target = document.getElementById(id);
                    if (target && target.textContent) resolved.push(target.textContent.trim());
                });
                accName = resolved.join(" ").trim();
            }

            // 2. aria-label
            if (!accName && btn.hasAttribute("aria-label")) {
                accName = (btn.getAttribute("aria-label") || "").trim();
                // If explicitly empty, accName remains ""
            }

            // 3. Child subtree (ignoring aria-hidden="true" children)
            if (!accName) {
                accName = getAccessibleChildText(btn);
            }

            // 4. Title fallback
            if (!accName && btn.getAttribute("title")) {
                accName = (btn.getAttribute("title") || "").trim();
            }

            // 5. Input button value / alt
            if (!accName && tag === "input") {
                if (btn.type === "image") {
                    accName = (btn.getAttribute("alt") || "").trim();
                } else {
                    accName = (btn.getAttribute("value") || "").trim();
                }
            }

            if (!accName) {
                var dedupe = sel + "|empty";
                if (seen[dedupe]) return;
                seen[dedupe] = true;

                var outer = btn.outerHTML ? btn.outerHTML.slice(0, 140) : sel;
                var hasEmptyAriaLabel = (btn.hasAttribute("aria-label") && !btn.getAttribute("aria-label").trim());
                var hasHiddenChildren = !!btn.querySelector("[aria-hidden='true']");

                var msg = "This button provides no accessible name for assistive technologies.";
                if (hasEmptyAriaLabel && hasHiddenChildren) {
                    msg = "Button has an empty aria-label='' and its visual contents are hidden with aria-hidden='true', leaving screen readers with no accessible name.";
                } else if (hasEmptyAriaLabel) {
                    msg = "Button has an empty aria-label='' attribute, overriding default text and leaving the button unlabeled.";
                } else if (hasHiddenChildren) {
                    msg = "Button contents are marked with aria-hidden='true' but no replacement aria-label or accessible text was provided.";
                }

                issues.push({
                    category: "Button Accessibility",
                    type: "Button Without Accessible Name",
                    wcag: "4.1.2 Name, Role, Value (Level A)",
                    status: "Verified Failure",
                    severity: "High",
                    element: "<code>" + outer + "</code>",
                    selector: sel,
                    button_text: "(empty accessible name)",
                    message: msg,
                    remediation: "Add a descriptive aria-label (e.g. aria-label='Settings') or provide meaningful visible text that is not hidden by aria-hidden='true'."
                });
            }
        });

        return issues.slice(0, 20);
    }

    /* ==========================================================================
       5. Browser Keyboard Accessibility & Traps (WCAG 2.1.1 & 2.1.2 Level A)
       ========================================================================== */
    function scanBrowserKeyboard() {
        var issues = [];
        var seen = {};

        // 1. Custom interactive widgets without tabindex
        var customWidgets = document.querySelectorAll(
            "[role='button'], [role='link'], [role='menuitem'], [role='tab'], [role='switch'], [role='checkbox']"
        );
        customWidgets.forEach(function (el) {
            if (isExcluded(el)) return;
            var tag = el.tagName.toLowerCase();
            if (tag === "button" || tag === "a" || tag === "input" || tag === "select") return;

            var tabindex = el.getAttribute("tabindex");
            var text = (el.textContent || el.getAttribute("aria-label") || tag).trim().slice(0, 40);
            var dedupe = "kb_widget|" + tag + "|" + text;
            if (seen[dedupe]) return;

            if (tabindex === null || parseInt(tabindex, 10) < 0) {
                seen[dedupe] = true;
                issues.push({
                    category: "Keyboard Accessibility",
                    type: "Custom ARIA Widget Lacks Keyboard Focusability",
                    wcag: "2.1.1 Keyboard (Level A)",
                    status: "Verified Failure",
                    severity: "High",
                    element: "<" + tag + " role='" + (el.getAttribute("role") || "") + "'>",
                    context: text || "(unlabeled custom control)",
                    message: "Element has an interactive ARIA role but cannot be reached using the Tab key because it lacks tabindex='0'.",
                    remediation: "Add tabindex='0' and implement Enter and Space key listeners, or replace with a native HTML button element."
                });
            }
        });

        // 2. Elements with click events attached that are not focusable
        var clickableNonForm = document.querySelectorAll(
            "div[onclick], span[onclick], p[onclick], i[onclick]"
        );
        clickableNonForm.forEach(function (el) {
            if (isExcluded(el)) return;
            var tag = el.tagName.toLowerCase();
            var tabindex = el.getAttribute("tabindex");
            var text = el.textContent.trim().slice(0, 40);
            var dedupe = "kb_click|" + tag + "|" + text;
            if (seen[dedupe]) return;

            if (tabindex === null) {
                seen[dedupe] = true;
                issues.push({
                    category: "Keyboard Accessibility",
                    type: "Clickable Non-Interactive Element Unreachable via Keyboard",
                    wcag: "2.1.1 Keyboard (Level A)",
                    status: "Potential Issue",
                    severity: "Medium",
                    element: "<" + tag + " onclick='...'>",
                    context: text || tag,
                    message: "A non-semantic element has an onclick action but lacks keyboard focusability (tabindex) and keyboard event handlers.",
                    remediation: "Use a native <button> element instead, or add tabindex='0', role='button', and keydown listeners."
                });
            }
        });

        return issues.slice(0, 20);
    }

    /* ==========================================================================
       Send All Browser Audit Results
       ========================================================================== */
    function sendResults(ajaxUrl, nonce, payload) {
        var body = new URLSearchParams();
        body.append("action", "accessai_save_contrast_results");
        body.append("nonce", nonce);
        body.append("results", JSON.stringify(payload));

        return fetch(ajaxUrl, {
            method: "POST",
            credentials: "same-origin",
            headers: { "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8" },
            body: body.toString()
        })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (resp.success) {
                    console.log("AccessAI: Full browser accessibility audit results saved.", resp.data);
                } else {
                    console.error("AccessAI: Server rejected audit results.", resp);
                }
            })
            .catch(function (err) {
                console.error("AccessAI: Could not send audit results.", err);
            });
    }

    /* ==========================================================================
       Token-based Auto-Trigger in Iframe
       ========================================================================== */
    function runIfTokenPresent() {
        var tokenMeta = document.querySelector('meta[name="accessai-scan-token"]');
        var ajaxUrlMeta = document.querySelector('meta[name="accessai-ajax-url"]');

        if (!tokenMeta || !ajaxUrlMeta) return;

        var token = tokenMeta.getAttribute("content") || "";
        var ajaxUrl = ajaxUrlMeta.getAttribute("content") || "";

        if (!token || !ajaxUrl) return;

        console.log("AccessAI: Scan token detected. Verifying session…");

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

                console.log("AccessAI: Running 5 live browser checkpoints: Contrast, Focus, Text Resizing/Spacing, Keyboard, Buttons…");

                // Run all live browser checkpoints
                var contrastIssues = scanContrast();
                var focusIssues = scanFocusVisibility();
                var textSpacingIssues = scanTextSpacingAndResize();
                var browserKeyboardIssues = scanBrowserKeyboard();
                var browserButtonIssues = scanButtons();

                var payload = {
                    contrast: contrastIssues,
                    focus: focusIssues,
                    text_spacing: textSpacingIssues,
                    keyboard: browserKeyboardIssues,
                    buttons: browserButtonIssues
                };

                return sendResults(resultAjax, resultNonce, payload);
            })
            .catch(function (err) {
                console.error("AccessAI: Token verification error.", err);
            });
    }

    /* Public API for manual testing */
    window.AccessAIContrastScanner = {
        scanAll: function (ajaxUrl, nonce) {
            var payload = {
                contrast: scanContrast(),
                focus: scanFocusVisibility(),
                text_spacing: scanTextSpacingAndResize(),
                keyboard: scanBrowserKeyboard(),
                buttons: scanButtons()
            };
            if (ajaxUrl && nonce) sendResults(ajaxUrl, nonce, payload);
            return payload;
        },
        scanContrast: scanContrast,
        scanFocusVisibility: scanFocusVisibility,
        scanTextSpacing: scanTextSpacingAndResize,
        scanKeyboard: scanBrowserKeyboard,
        scanButtons: scanButtons
    };

    document.addEventListener("DOMContentLoaded", runIfTokenPresent);
})();
