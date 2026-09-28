/* LibreStats dashboard: progress bars, chart hover, live counter, copy button, site picker.
   No inline styles are allowed by the page's security policy, so widths are set here. */
(function () {
  "use strict";

  document.querySelectorAll("[data-pct]").forEach(function (bar) {
    requestAnimationFrame(function () { bar.style.width = Math.max(1, parseFloat(bar.getAttribute("data-pct"))) + "%"; });
  });

  // Line chart: crosshair, dot and a tooltip for the nearest point.
  document.querySelectorAll("[data-chart]").forEach(function (box) {
    var data = JSON.parse(box.getAttribute("data-chart"));
    var svg = box.querySelector("svg"), hit = svg.querySelector(".ls-hit");
    var cross = svg.querySelector(".ls-cross"), dot = svg.querySelector(".ls-hover"), tip = box.querySelector(".ls-tip");
    if (!data.points.length) return;
    function show(clientX) {
      var r = svg.getBoundingClientRect(), x = (clientX - r.left) * data.w / r.width, best = data.points[0];
      data.points.forEach(function (p) { if (Math.abs(p[0] - x) < Math.abs(best[0] - x)) best = p; });
      cross.setAttribute("x1", best[0]); cross.setAttribute("x2", best[0]); cross.setAttribute("visibility", "visible");
      dot.setAttribute("cx", best[0]); dot.setAttribute("cy", best[1]); dot.setAttribute("visibility", "visible");
      tip.textContent = "";
      var b = document.createElement("b"); b.textContent = best[2]; tip.appendChild(b);
      tip.appendChild(document.createTextNode(best[3].toLocaleString() + " " + data.name + (best[4] !== null && data.extra ? " · " + best[4].toLocaleString() + " " + data.extra : "")));
      tip.hidden = false;
      var left = best[0] * r.width / data.w;
      tip.style.left = Math.min(Math.max(left - tip.offsetWidth / 2, 0), r.width - tip.offsetWidth) + "px";
    }
    function hide() { tip.hidden = true; cross.setAttribute("visibility", "hidden"); dot.setAttribute("visibility", "hidden"); }
    hit.addEventListener("mousemove", function (e) { show(e.clientX); });
    hit.addEventListener("touchstart", function (e) { show(e.touches[0].clientX); }, { passive: true });
    hit.addEventListener("mouseleave", hide);
  });

  // "People on the site now", refreshed every 20 seconds while the tab is visible. On the
  // "waiting for the first visit" card, the page reloads as soon as someone arrives.
  document.querySelectorAll("[data-live]").forEach(function (el) {
    var url = el.getAttribute("data-live"), reload = el.hasAttribute("data-reload-on-visit");
    var count = el.querySelector("[data-live-count]"), word = el.querySelector("[data-live-word]");
    function poll() {
      if (document.hidden) return;
      fetch(url, { credentials: "same-origin", cache: "no-store" }).then(function (r) { return r.json(); }).then(function (d) {
        if (typeof d.visitors !== "number") return;
        if (reload && d.visitors > 0) { el.textContent = "It’s working! Loading your numbers…"; location.reload(); return; }
        el.classList.toggle("is-live", d.visitors > 0);
        if (count) count.textContent = d.visitors;
        if (word) word.textContent = d.visitors === 1 ? "person" : "people";
      }).catch(function () {});
    }
    setInterval(poll, reload ? 5000 : 20000);
    document.addEventListener("visibilitychange", poll);
  });

  document.querySelectorAll("[data-copy]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var text = document.getElementById(btn.getAttribute("data-copy")).textContent;
      navigator.clipboard.writeText(text).then(function () {
        btn.textContent = "Copied";
        setTimeout(function () { btn.textContent = "Copy"; }, 2000);
      });
    });
  });

  document.querySelectorAll("form[data-autosubmit] select").forEach(function (sel) {
    sel.addEventListener("change", function () { sel.form.submit(); });
  });

  document.querySelectorAll("[data-toggle]").forEach(function (a) {
    a.addEventListener("click", function (e) {
      e.preventDefault();
      document.getElementById(a.getAttribute("data-toggle")).classList.toggle("is-open");
    });
  });
})();
