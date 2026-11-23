(function () {
  var C = window.DBRS || {}, f = document.getElementById("dbrs-form"), o = document.getElementById("dbrs-out");
  if (!f || !o) { return; }
  // A blocked request (firewall 403), a rate limit or the host's busy page is not JSON: say what
  // happened instead of blaming the visitor's connection (TL-25).
  function readJson(r) {
    var why = r.status === 403 ? "The request was blocked by the site firewall. Check the name and try again."
      : (r.status === 429 ? "Too many lookups in a short time. Try again in a minute."
      : (r.status >= 500 ? "The server is busy. Try again in a minute." : "The lookup failed (HTTP " + r.status + ")."));
    if ((r.headers.get("content-type") || "").indexOf("json") < 0) { return Promise.resolve({ ok: false, j: { error: why } }); }
    return r.json().then(function (j) { return { ok: r.ok, j: j }; }, function () { return { ok: false, j: { error: why } }; });
  }
  function el(t, c, x) { var e = document.createElement(t); if (c) { e.className = c; } if (x !== undefined) { e.textContent = x; } return e; }
  function card(title, rows, wide) {
    var c = el("div", "dblk-card" + (wide ? " is-wide" : "")); c.appendChild(el("h3", "", title));
    var d = el("dl"); rows.forEach(function (r) { d.appendChild(el("dt", "", r[0])); d.appendChild(el("dd", "", r[1])); }); c.appendChild(d); return c;
  }
  function err(msg) { o.textContent = ""; o.appendChild(el("div", "dblk-err", msg)); }
  function busy(b, on) { b.disabled = on; if (on) { b.setAttribute("data-t", b.textContent); b.textContent = "Working..."; } else { b.textContent = b.getAttribute("data-t") || C.button; } }

  function renderResolve(j) {
    o.textContent = "";
    var h = el("div", "dblk-head"); h.appendChild(el("strong", "", j.domain + "  " + j.type)); h.appendChild(el("span", "", "Checked " + j.checked)); o.appendChild(h);
    Object.keys(j.results).forEach(function (k) {
      var r = j.results[k], rows = [["Status", r.status]];
      if (r.answers && r.answers.length) { r.answers.forEach(function (a, i) { rows.push([i ? "" : "Answer", a]); }); } else if (r.ok) { rows.push(["Answer", "No records of this type"]); }
      if (r.ttl !== null && r.ttl !== undefined) { rows.push(["Time to live", r.ttl + " seconds"]); }
      rows.push(["Response time", r.ms + " ms"]);
      o.appendChild(card(r.label, rows));
    });
    if (Object.keys(j.results).length > 1) {
      var s = el("div", "dblk-card is-wide"); s.appendChild(el("h3", "", j.summary.agree ? "Resolvers agree" : "Resolvers differ")); s.appendChild(el("p", "", j.summary.text)); o.appendChild(s);
    }
  }
  function renderCert(j) {
    o.textContent = "";
    o.appendChild(card("Certificate details", j.rows, true));
  }

  f.addEventListener("submit", function (ev) {
    ev.preventDefault();
    var b = f.querySelector(".dbrs-go"); busy(b, true); o.textContent = "";
    var req;
    if (C.mode === "cert") {
      var pem = f.pem.value.trim(); if (!pem) { busy(b, false); return err("Paste a certificate first."); }
      req = fetch(C.ep, { method: "POST", headers: { "Content-Type": "application/json", Accept: "application/json" }, body: JSON.stringify({ pem: pem }) });
    } else {
      var v = f.d.value.trim(); if (!v) { busy(b, false); return; }
      if (v.indexOf(".") < 0) { v += ".com"; f.d.value = v; }
      var q = "?d=" + encodeURIComponent(v) + "&type=" + encodeURIComponent(f.type.value) + "&resolver=" + encodeURIComponent(f.resolver ? f.resolver.value : (C.resolver || "all"));
      req = fetch(C.ep + q, { headers: { Accept: "application/json" } });
    }
    req.then(readJson)
      .then(function (x) { if (!x.ok) { err((x.j && (x.j.error || x.j.message)) || "The request failed."); } else if (C.mode === "cert") { renderCert(x.j); } else { renderResolve(x.j); } })
      .catch(function () { err("The request could not be completed. Check your connection and try again."); })
      .then(function () { busy(b, false); });
  });
  var qd = new URLSearchParams(location.search).get("d");
  if (qd && C.mode !== "cert") { f.d.value = qd; if (f.requestSubmit) { f.requestSubmit(); } }
}());
