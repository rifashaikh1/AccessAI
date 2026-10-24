
(function () {
    "use strict";

    function parseColor(color) {
        const match = color.match(
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
        const channels = [color.r, color.g, color.b].map(function (value) {
            value /= 255;

            return value <= 0.04045
                ? value / 12.92
                : Math.pow((value + 0.055) / 1.055, 2.4);
        });

        return (
            0.2126 * channels[0] +
            0.7152 * channels[1] +
            0.0722 * channels[2]
        );
    }

    function getContrastRatio(foreground, background) {
        const first = getLuminance(foreground);
        const second = getLuminance(background);

        const lighter = Math.max(first, second);
        const darker = Math.min(first, second);

        return (lighter + 0.05) / (darker + 0.05);
    }

    function getBackground(element) {
        let current = element;

        while (current && current !== document.documentElement) {
            const color = parseColor(
                getComputedStyle(current).backgroundColor
            );

            if (color && color.a >= 1) {
                return color;
            }

            current = current.parentElement;
        }

        // Assume a white page background when no opaque background
        // is found. This assumption may be incorrect on some websites.
        return { r: 255, g: 255, b: 255, a: 1 };
    }

    function scanContrast() {
        
const elements = document.querySelectorAll(
    "div, p, span, a, li, label, button, h1, h2, h3, h4, h5, h6, td, th"
);


        const issues = [];
        let checked = 0;

        elements.forEach(function (element) {
            if (!element.textContent.trim()) return;

            const style = getComputedStyle(element);

            if (
                style.display === "none" ||
                style.visibility === "hidden" ||
                Number(style.opacity) === 0
            ) {
                return;
            }

            const foreground = parseColor(style.color);

            if (!foreground || foreground.a < 1) return;

            const background = getBackground(element);
            const ratio = getContrastRatio(foreground, background);
            const fontSize = parseFloat(style.fontSize);
            const isBold = parseInt(style.fontWeight, 10) >= 700;

            const isLargeText =
                fontSize >= 24 ||
                (fontSize >= 18.66 && isBold);

            const requiredRatio = isLargeText ? 3 : 4.5;

            checked++;

            if (ratio < requiredRatio) {
                issues.push({
                    element: element.tagName.toLowerCase(),
                    text: element.textContent.trim().slice(0, 100),
                    ratio: ratio.toFixed(2) + ":1",
                    required: requiredRatio + ":1",
                    node: element
                });
            }
        });

        console.group("AccessAI — Color Contrast Scan");
        console.log("Text elements checked:", checked);
        console.log("Potential contrast issues:", issues.length);

        issues.forEach(function (issue) {
            console.warn(
                issue.element,
                '"' + issue.text + '"',
                "Contrast:", issue.ratio,
                "Required:", issue.required,
                issue.node
            );
        });

        console.groupEnd();

        return issues;
    }

    
function sendContrastResults(issues) {
    if (
        typeof AccessAIConfig === "undefined" ||
        !AccessAIConfig.ajaxUrl ||
        !AccessAIConfig.nonce
    ) {
        console.error("AccessAI: WordPress connection is not configured.");
        return;
    }

    const formData = new URLSearchParams();

    formData.append("action", "accessai_save_contrast_results");
    formData.append("nonce", AccessAIConfig.nonce);
    formData.append(
        "results",
        JSON.stringify(
            issues.map(function (issue) {
                return {
                    element: issue.element,
                    text: issue.text,
                    ratio: issue.ratio,
                    required: issue.required
                };
            })
        )
    );

    fetch(AccessAIConfig.ajaxUrl, {
        method: "POST",
        credentials: "same-origin",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8"
        },
        body: formData.toString()
    })
        .then(function (response) {
            if (!response.ok) {
                throw new Error("HTTP " + response.status);
            }

            return response.json();
        })
        .then(function (response) {
            if (response.success) {
                console.log(
                    "AccessAI: Contrast results sent successfully.",
                    response.data
                );
            } else {
                console.error("AccessAI: WordPress rejected the results.", response);
            }
        })
        .catch(function (error) {
            console.error("AccessAI: Could not send contrast results.", error);
        });
}

window.AccessAIContrastScanner = {
    scan: function () {
        const issues = scanContrast();
        sendContrastResults(issues);
        return issues;
    }
};

document.addEventListener("DOMContentLoaded", function () {
    if (window.location.search.includes("accessai_scan=1")) {
        window.AccessAIContrastScanner.scan();
    }
});

})();
