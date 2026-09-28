/*
 * LibreStats tracking script. Add to any page:
 *   <script src="https://stats.example.com/s.js" data-site="example.com" defer></script>
 * Optional: data-api="https://…/collect.php" if collect.php lives elsewhere.
 *
 * No cookies, no localStorage, no fingerprinting. Nothing runs for visitors with Do Not Track or
 * Global Privacy Control, or in automated browsers. Only the page's path, campaign tags, referrer,
 * title and screen width are sent, to your own LibreStats server.
 *
 * Count your own moments:  librestats("Built an order")  or  <a data-ls-event="Signed up">
 * Outbound links and file downloads are counted automatically.
 */
(function () {
  "use strict";
  var script = document.currentScript;
  if (!script || navigator.doNotTrack === "1" || window.doNotTrack === "1" ||
      navigator.globalPrivacyControl === true || navigator.webdriver) return;

  var site = script.getAttribute("data-site") || location.hostname.replace(/^www\./, "");
  var api = script.getAttribute("data-api") || new URL("collect.php", script.src).href;
  var DOWNLOADS = /\.(pdf|zip|gz|tgz|rar|7z|dmg|exe|msi|deb|rpm|apk|iso|csv|xlsx?|docx?|pptx?|odt|ods|mp3|mp4|mov|epub)$/i;

  var key, activeMs, shownAt, lastPath;

  function send(data) {
    data.d = site;
    data.u = pageUrl();
    var body = JSON.stringify(data);
    try {
      if (navigator.sendBeacon && navigator.sendBeacon(api, new Blob([body], { type: "text/plain" }))) return;
    } catch (e) { /* fall through */ }
    try { fetch(api, { method: "POST", body: body, keepalive: true, mode: "no-cors", credentials: "omit" }); } catch (e) { /* give up quietly */ }
  }

  // The address without any query string except campaign tags (utm_*), and without the #fragment.
  function pageUrl() {
    var params = new URLSearchParams(location.search), keep = new URLSearchParams();
    ["utm_source", "utm_medium", "utm_campaign"].forEach(function (k) { if (params.get(k)) keep.set(k, params.get(k)); });
    var q = keep.toString();
    return location.origin + location.pathname + (q ? "?" + q : "");
  }

  function newKey() {
    var chars = "abcdefghijklmnopqrstuvwxyz0123456789", bytes = new Uint8Array(16), out = "";
    crypto.getRandomValues(bytes);
    for (var i = 0; i < 16; i++) out += chars[bytes[i] % 36];
    return out;
  }

  function pageview() {
    key = newKey();
    activeMs = 0;
    shownAt = document.visibilityState === "visible" ? Date.now() : 0;
    lastPath = location.pathname;
    send({ n: "pageview", r: document.referrer, t: document.title.slice(0, 200), w: screen.width, k: key, b: navigator.brave ? 1 : 0 });
  }

  // Sent every time the page is hidden or left, with the running total of visible time.
  function leave() {
    if (!key) return;
    if (shownAt) { activeMs += Date.now() - shownAt; shownAt = 0; }
    send({ n: "leave", k: key, s: Math.round(activeMs / 1000) });
  }

  document.addEventListener("visibilitychange", function () {
    if (document.visibilityState === "hidden") leave();
    else if (!shownAt) shownAt = Date.now();
  });
  window.addEventListener("pagehide", leave);

  // Single-page sites: a new path counts as a new page view.
  function routeChanged() {
    if (location.pathname === lastPath) return;
    leave();
    pageview();
  }
  var push = history.pushState;
  history.pushState = function () { push.apply(this, arguments); routeChanged(); };
  window.addEventListener("popstate", routeChanged);

  function event(name, detail) {
    if (name) send({ n: "event", e: String(name).slice(0, 100), x: detail ? String(detail).slice(0, 512) : "" });
  }
  window.librestats = event;

  document.addEventListener("click", function (e) {
    var el = e.target.closest ? e.target.closest("[data-ls-event], a[href]") : null;
    if (!el) return;
    if (el.hasAttribute("data-ls-event")) return event(el.getAttribute("data-ls-event"), el.getAttribute("data-ls-detail"));
    var link;
    try { link = new URL(el.href, location.href); } catch (err) { return; }
    if (!/^https?:$/.test(link.protocol)) return;
    if (link.hostname.replace(/^www\./, "") !== location.hostname.replace(/^www\./, "")) event("Outbound link", link.hostname + link.pathname);
    else if (DOWNLOADS.test(link.pathname)) event("File download", link.pathname);
  }, true);

  if (document.visibilityState === "prerender") {
    document.addEventListener("visibilitychange", function once() {
      if (document.visibilityState !== "prerender") { document.removeEventListener("visibilitychange", once); pageview(); }
    });
  } else {
    pageview();
  }
})();
