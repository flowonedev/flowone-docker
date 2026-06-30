/**
 * Iframe srcdoc builder for sandboxed email rendering.
 * Builds a complete HTML document string for use with iframe srcdoc.
 */

const CSP_POLICY = [
  "default-src 'none'",
  "img-src data: cid: https:",
  "media-src data: blob: https:",
  "style-src 'unsafe-inline' 'self'",
  "script-src 'unsafe-inline'",
  "font-src 'self' data:",
  "connect-src 'none'",
  "frame-src https://www.youtube.com https://player.vimeo.com",
  "base-uri 'none'",
  "form-action 'none'",
].join('; ')

/**
 * Build the base stylesheet injected into every iframe.
 * Provides minimal typography reset and responsive guardrails.
 */
export function getBaseStylesheet(darkMode) {
  const base = `
    body {
      margin: 0;
      padding: 0;
      font-family: 'Outfit', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      font-size: 14px;
      line-height: 1.6;
      overflow-wrap: break-word;
    }
    img { max-width: 100%; height: auto; }
    table { max-width: 100%; }
    pre { overflow-x: auto; white-space: pre-wrap; }
    /* Email "preheader"/preview blocks hide themselves with zero dimensions plus
       overflow:hidden, but the backend HTMLPurifier strips overflow (it is not in
       CSS.AllowedProperties). Without it those 0-size boxes no longer clip: the
       max-height:0 one leaks a stray line and the width:0/height:0 one wraps one
       letter per line (the vertical strip bug). Re-clip any element that declares
       a zero dimension -- in email these are always meant to be hidden. */
    [style*="max-height:0"],
    [style*="max-height: 0"],
    [style*="width:0"][style*="height:0"],
    [style*="width: 0"][style*="height: 0"] {
      overflow: hidden !important;
    }
    .search-highlight { background: #fbbf24; color: #1f2937; border-radius: 2px; padding: 0 1px; }
    .search-highlight.current-occurrence { background: #f97316; color: white; }
  `

  if (!darkMode) {
    return `${base}
      body { color: #1f2937; background: #ffffff; }
      a { color: #2563eb; }
    `
  }

  return `${base}
    body { color: #e5e7eb; background: transparent; }
    a { color: #93c5fd; }
    [style*="background-color: #000"],
    [style*="background-color:#000"],
    [style*="background: #000"],
    [style*="background:#000"],
    [bgcolor="#000000"],
    [bgcolor="black"] {
      background-color: #374151 !important;
    }
  `
}

/**
 * Build the print stylesheet.
 */
export function getPrintStylesheet() {
  return `
    @media print {
      body {
        overflow: visible !important;
        height: auto !important;
        color: #000 !important;
        background: #fff !important;
      }
      img { max-width: 100% !important; }
    }
  `
}

/**
 * Build the bridge script injected into the iframe.
 * Handles: height reporting, click interception, anchor scrolling, text selection.
 */
export function getIframeScript(uid, token) {
  return `
    (function() {
      var TOKEN = ${JSON.stringify(token)};
      var UID = ${JSON.stringify(String(uid))};
      var lastHeight = 0;

      function sendMsg(data) {
        data.token = TOKEN;
        data.uid = UID;
        parent.postMessage(data, '*');
      }

      function reportHeight() {
        var h = document.body ? document.body.scrollHeight : 0;
        if (h !== lastHeight) {
          lastHeight = h;
          sendMsg({ type: 'emailHeight', height: h });
        }
      }

      if (typeof ResizeObserver !== 'undefined' && document.body) {
        new ResizeObserver(reportHeight).observe(document.body);
      }
      window.addEventListener('load', reportHeight);
      if (document.body) {
        new MutationObserver(reportHeight).observe(document.body, {
          childList: true, subtree: true, attributes: true
        });
      }
      setTimeout(reportHeight, 50);
      setTimeout(reportHeight, 300);

      // Tell the parent about EVERY click in the iframe so it can
      // dismiss floating UI (e.g. the calendar-picker dropdown) that
      // was opened from a previous click. The parent's document
      // click listener never sees clicks inside this iframe.
      document.addEventListener('click', function() {
        try { sendMsg({ type: 'bodyClick' }); } catch(_) {}
      }, true);

      document.addEventListener('click', function(e) {
        var anchor = e.target.closest ? e.target.closest('a') : null;
        if (!anchor) return;
        var href = anchor.getAttribute('href');
        if (!href) return;

        e.preventDefault();
        e.stopPropagation();

        // Collect data-* attributes FIRST. The backend injects
        // <a href="#add-to-calendar" data-action="add-to-calendar"> for
        // meeting-invite cards; without this dataset capture, the '#'
        // anchor below would short-circuit the click and Vue would
        // never see the action. (Bug: meeting invites' "Add to
        // Calendar" button did nothing because of that early return.)
        var dataset = {};
        if (anchor.dataset) {
          for (var k in anchor.dataset) dataset[k] = anchor.dataset[k];
        }

        // Actionable anchors (data-action) ALWAYS get relayed to the
        // parent, regardless of href scheme. The href is just a
        // semantic fallback for a11y / right-click "copy link".
        if (dataset.action) {
          sendMsg({ type: 'link', href: href, dataset: dataset });
          return;
        }

        if (href.charAt(0) === '#') {
          var targetId = href.substring(1);
          var target = document.getElementById(targetId) || document.querySelector('[name="' + targetId + '"]');
          if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
          return;
        }

        sendMsg({ type: 'link', href: href, dataset: dataset });
      }, true);

      document.addEventListener('mouseup', function() {
        setTimeout(function() {
          var sel = window.getSelection();
          var text = sel ? sel.toString().trim() : '';
          if (text && text.length > 0) {
            try {
              var range = sel.getRangeAt(0);
              var rect = range.getBoundingClientRect();
              if (rect.width > 0 && rect.height > 0) {
                sendMsg({
                  type: 'selection',
                  text: text.substring(0, 2000),
                  rect: { top: rect.top, left: rect.left, width: rect.width, height: rect.height }
                });
              }
            } catch(ex) {}
          } else {
            sendMsg({ type: 'selectionCleared' });
          }
        }, 10);
      });
    })();
  `
}

/**
 * Highlight search terms in HTML by walking text nodes.
 * Safer than regex on raw HTML since it won't break tags.
 */
export function applySearchHighlighting(html, query) {
  if (!html || !query || query.length < 2) return html

  const escapedQuery = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
  const regex = new RegExp(`(${escapedQuery})`, 'gi')

  const parts = html.split(/(<[^>]+>)/g)
  return parts.map(part => {
    if (part.startsWith('<')) return part
    return part.replace(regex, '<mark class="search-highlight">$1</mark>')
  }).join('')
}

/**
 * Build a complete srcdoc HTML document for the email iframe.
 */
export function buildSrcdoc(html, options = {}) {
  const { darkMode = false, uid = '', token = '', searchQuery = '' } = options

  let processedHtml = html || ''
  if (searchQuery) {
    processedHtml = applySearchHighlighting(processedHtml, searchQuery)
  }

  return `<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="Content-Security-Policy" content="${CSP_POLICY}">
  <meta name="referrer" content="no-referrer">
  <link rel="stylesheet" href="/fonts/outfit/font.css">
  <style>${getBaseStylesheet(darkMode)}</style>
  <style>${getPrintStylesheet()}</style>
</head>
<body>${processedHtml}<script>${getIframeScript(uid, token)}<\/script></body>
</html>`
}
