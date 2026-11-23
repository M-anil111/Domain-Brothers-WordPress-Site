(function () {
  var f = document.getElementById("dblk-form"), o = document.getElementById("dblk-out");
  if (!f || !o) { return; }
  var C = window.DBLK || {}, EP = C.ep, EXT = C.ext, SHOW = C.show || [], PARTS = C.parts || [], AUTOEXT = !!C.autoext;
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
  function money(n) { return "$" + Math.round(n).toLocaleString("en-US"); }
  function card(title, rows, wide) {
    var c = el("div", "dblk-card" + (wide ? " is-wide" : ""));
    c.setAttribute("data-card", title);
    c.appendChild(el("h3", "", title));
    if (typeof rows === "string") { c.appendChild(el("p", "", rows)); return c; }
    var d = el("dl");
    rows.forEach(function (r) { d.appendChild(el("dt", "", r[0])); d.appendChild(el("dd", "", r[1])); });
    c.appendChild(d);
    return c;
  }
  function add(c) {
    if (!SHOW.length || SHOW.indexOf(c.getAttribute("data-card")) > -1) { o.appendChild(c); return c; }
    return null;
  }
  function note(c, text) { if (c) { var p = el("p", "dblk-hint", text); p.style.marginTop = "12px"; c.appendChild(p); } }
  // The full registry timestamp when there is one (TL-26); a date alone is midnight UTC.
  function parse(s) { var t = Date.parse(s || ""); if (s && /T\d/.test(s) && !isNaN(t)) { return new Date(t); } var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || ""); return m ? new Date(Date.UTC(+m[1], +m[2] - 1, +m[3])) : null; }
  function span(a, b) {
    var y = b.getUTCFullYear() - a.getUTCFullYear(), m = b.getUTCMonth() - a.getUTCMonth(), d = b.getUTCDate() - a.getUTCDate();
    if (d < 0) { m -= 1; d += new Date(Date.UTC(b.getUTCFullYear(), b.getUTCMonth(), 0)).getUTCDate(); }
    if (m < 0) { y -= 1; m += 12; }
    var out = [];
    if (y) { out.push(y + (y === 1 ? " year" : " years")); }
    if (m) { out.push(m + (m === 1 ? " month" : " months")); }
    if (d || !out.length) { out.push(d + (d === 1 ? " day" : " days")); }
    return out.join(", ");
  }

  function render(j) {
    o.textContent = "";
    var h = el("div", "dblk-head"); h.appendChild(el("strong", "", j.domain)); h.appendChild(el("span", "", "Checked " + j.checked)); o.appendChild(h);
    var r = j.registry || {}, e = r.events || {}, now = new Date();

    // Registry record
    var c;
    if (r.state === "ok") {
      c = add(card("Registry record", [["Registered", e.registration || "Not shown"], ["Expires", e.expiration || "Not shown"], ["Last changed", e["last changed"] || e.lastchanged || "Not shown"], ["Registrar", r.registrar || "Not shown"], ["Name servers", (r.ns && r.ns.length) ? r.ns.join(", ") : "Not shown"]]));
      if (c && r.status && r.status.length) { var p = el("p"); p.style.marginTop = "12px"; r.status.forEach(function (s) { p.appendChild(el("span", "dblk-pill", s)); }); c.appendChild(p); }
      note(c, "Owner details are usually hidden by registrars for privacy, so a lookup rarely shows who owns a name.");
    } else if (r.state === "none") {
      add(card("Registry record", r.via === "dns" ? "No registration was found: the name does not exist in DNS. It looks unregistered; confirm at a registrar before you count on it." : "No registration record was found. The name looks unregistered."));
    } else if (r.reason === "no_rdap") {
      add(card("Registry record", "This extension does not publish a public registry record."));
    } else {
      add(card("Registry record", "The registry did not answer, so the record could not be checked. Try again in a minute."));
    }

    // Age and expiry
    var at = r.events_at || {}, reg = parse(at.registration || e.registration), exp = parse(at.expiration || e.expiration);
    if (r.state === "ok" && (reg || exp)) {
      var rows = [];
      if (reg) { rows.push(["Registered", e.registration + " (UTC)"]); rows.push(["Age", span(reg, now)]); }
      if (exp) { rows.push(["Expires", e.expiration + " (UTC)"]); rows.push([exp < now ? "Expired" : "Time left", exp < now ? span(exp, now) + " ago" : span(now, exp)]); }
      c = add(card("Age and expiry", rows));
      note(c, "Registration date is when the registry first recorded this name. A name can be older than its current owner.");
    } else if (SHOW.indexOf("Age and expiry") > -1) {
      add(card("Age and expiry", "No registration or expiry date is published for this name."));
    }

    // DNSSEC
    var d = j.dns || {}, has = function (k) { return d[k] && d[k].length; };
    if (SHOW.indexOf("DNSSEC") > -1) {
      c = add(card("DNSSEC", [["Signed at the registry", r.state === "ok" ? (r.dnssec === null ? "Not shown" : (r.dnssec ? "Yes (DS record published)" : "No")) : "Not available"], ["Validated by a public resolver", d.AD ? "Yes" : "No"]]));
      note(c, "DNSSEC signs DNS answers so they cannot be forged on the way to a visitor. Both should read Yes for a fully protected name.");
    }

    // DNS records
    c = add(card("DNS", [["Website address", has("A") || has("AAAA") ? (d.A || []).concat(d.AAAA || []).join(", ") : "None"], ["Mail servers", has("MX") ? (d.MX.length === 1 && /^0\s+\.?$/.test(String(d.MX[0]).trim()) ? "None: this domain does not accept email (null MX)" : d.MX.join(", ")) : "None"], ["Name servers", has("NS") ? d.NS.join(", ") : "None"], ["SPF record", d.SPF ? "Present" : "Not found"]]));

    // Website: chain, headers, security headers, summary
    var st = j.site || {};
    if (st.state === "unreachable") {
      // A redirect whose target failed: say so instead of showing the 3xx as the answer (TL-14).
      c = add(card("Website response", [["Result", "The redirect target did not answer"], ["Failed at", st.final || "Not shown"], ["Reason", st.error || "No response"]].concat((st.hops || []).map(function (x, i) { return ["Step " + (i + 1), (x.code ? x.code + "  " : "failed  ") + x.url]; }))));
    } else if (st.state === "ok") {
      if (SHOW.indexOf("Redirect chain") > -1) {
        c = add(card("Redirect chain", (st.hops || []).map(function (x, i) { return ["Step " + (i + 1), x.code + "  " + x.url]; })));
        note(c, (st.hops || []).length > 1 ? "The address redirects before it settles. Each extra step adds delay." : "No redirect: the address answers directly.");
      }
      if (SHOW.indexOf("HTTP response headers") > -1) {
        var hk = Object.keys(st.headers || {});
        c = add(card("HTTP response headers", [["Status", String(st.code)]].concat(hk.map(function (k) { return [k, st.headers[k]]; })), true));
      }
      if (SHOW.indexOf("Security headers") > -1) {
        var sec = st.secure || {}, names = Object.keys(sec), on = names.filter(function (k) { return sec[k]; });
        c = add(card("Security headers", names.map(function (k) { return [k, sec[k] ? "Present" : "Missing"]; }).concat([["Score", on.length + " of " + names.length]])));
        note(c, "These headers tell browsers how to protect visitors. Missing headers are not errors, but each one closes a known gap.");
      }
      if (SHOW.indexOf("Website response") > -1) {
        var on2 = Object.keys(st.secure || {}).filter(function (k) { return st.secure[k]; });
        add(card("Website response", [["Status", String(st.code) + (st.https ? " over HTTPS" : " over HTTP only")], ["Final address", st.final || "Not shown"], ["Page title", st.title || "None"], ["Server", st.server || "Not shown"], ["Security headers", on2.length + " of " + Object.keys(st.secure || {}).length]]));
      }
    } else {
      ["Redirect chain", "HTTP response headers", "Security headers", "Website response"].forEach(function (t) { if (SHOW.indexOf(t) > -1) { add(card(t, "The website did not respond, so there is nothing to show.")); } });
    }

    // SSL
    var sl = j.ssl || {};
    if (sl.state === "ok") {
      c = add(card("SSL certificate", [["Issuer", sl.issuer || "Not shown"], ["Valid from", sl.from], ["Expires", sl.to + (sl.days !== null ? " (" + (sl.days < 0 ? "expired" : sl.days + " days left") + ")" : "")], ["Covers this name", sl.covers ? "Yes" : "No"]]));
      if (sl.days !== null && sl.days >= 0 && sl.days < 30) { note(c, "This certificate expires within 30 days. Renew it before visitors see a browser warning."); }
    } else {
      add(card("SSL certificate", "No certificate was found on port 443. The site may not use HTTPS."));
    }

    // Email security
    var dm = j.dmarc || {};
    c = add(card("Email security", [["SPF", d.SPF ? "Present" : "Not found"], ["DMARC", dm.state === "ok" ? "Present, policy: " + dm.policy : "Not found"]]));
    note(c, "SPF lists who may send mail for the domain. DMARC tells receivers what to do with mail that fails the check; reject or quarantine is stronger than none.");

    // Hosting
    var ho = j.host || {};
    if (ho.state === "ok") { add(card("Hosting network", [["Address", ho.ip], ["Network", ho.network || "Not shown"], ["Country", ho.country || "Not shown"]])); }
    else if (SHOW.indexOf("Hosting network") > -1) { add(card("Hosting network", "No public address was found for this name.")); }

    // Archive
    var a = j.archive || {};
    if (a.state === "ok") {
      c = add(card("Web history", a.first
        ? [["First captured", a.first], ["Latest capture", a.date], ["Years with captures", a.years + " of " + a.span]]
        : "The Wayback Machine has captured this name. Latest capture near " + a.date + "."));
      if (c) { var l = el("p"); l.style.marginTop = "10px"; var an = el("a", "", "See every capture"); an.href = a.url; an.target = "_blank"; an.rel = "noopener"; l.appendChild(an); c.appendChild(l); }
    } else {
      add(card("Web history", a.state === "redirects" ? "The Wayback Machine has captured this name, but every capture is a redirect to another address." : (a.state === "none" ? "No captures found in the Wayback Machine." : "The archive did not answer.")));
    }

    // Rank
    var k = j.rank || {};
    add(card("Popularity", k.state === "ok" ? "Ranked #" + k.rank.toLocaleString("en-US") + " in the Tranco top one million sites (" + k.date + ")." : (k.state === "none" ? "Not in the top one million sites. Most names are not." : "The ranking service did not answer.")));

    // Name facts
    var nm = j.name;
    if (nm) { add(card("The name itself", [["Length", nm.length + " characters before the dot"], ["Extension", "." + nm.extension], ["Type", nm.type], ["Hyphens", String(nm.hyphens)], ["Digits", String(nm.digits)], ["Easy to read aloud", nm.readable ? "Likely" : "Probably not"]])); }

    // Comparables
    var m = (j.comparables || {}).sold || {}, n = (j.comparables || {}).ask || {};
    if (SHOW.indexOf("Comparable sales at Domain Brothers") > -1 || !SHOW.length) {
      // Shown only when enough similar sales have a recorded price; otherwise the card always said
      // there was too little data (TL-27).
      if (m.state === "ok") { add(card("Comparable sales at Domain Brothers", [["Basis", m.basis], ["Names sold", String(m.count)], ["Median price", money(m.median)], ["Range", money(m.low) + " to " + money(m.high)]])); }
      if (n.state === "ok") { add(card("Similar names for sale now (asking prices)", [["Basis", n.basis], ["Names listed", String(n.count)], ["Median asking price", money(n.median)], ["Range", money(n.low) + " to " + money(n.high)]])); }
    }

    // Other extensions
    if (nm && (!SHOW.length || SHOW.indexOf("Other extensions") > -1)) {
      var xc = el("div", "dblk-card is-wide"); xc.setAttribute("data-card", "Other extensions");
      xc.appendChild(el("h3", "", "Other extensions"));
      var xp = el("p", "", "See whether " + nm.label + " has a registration record under other popular extensions."); xc.appendChild(xp);
      var xb = el("button", "dblk-sec", "Check extensions"); xb.type = "button"; xc.appendChild(xb); o.appendChild(xc);
      xb.addEventListener("click", function () {
        xb.disabled = true; xb.textContent = "Checking...";
        fetch(EXT + "?d=" + encodeURIComponent(nm.label), { headers: { Accept: "application/json" } })
          .then(function (rs) { return readJson(rs).then(function (x) { return { ok: x.ok, q: x.j }; }); })
          .then(function (x) {
            xb.remove();
            if (!x.ok) { xp.textContent = (x.q && (x.q.error || x.q.message)) || "The check failed."; return; }
            xp.textContent = "";
            x.q.results.forEach(function (it) { xp.appendChild(el("span", "dblk-pill dblk-" + it.state, "." + it.ext + ": " + (it.state === "free" ? "no record found" : (it.state === "taken" ? "registered" : "cannot be checked")))); });
            xc.appendChild(el("p", "dblk-hint", "No record found means the registry shows no registration. Confirm with a registrar before relying on it."));
          })
          .catch(function () { xb.remove(); xp.textContent = "The check could not be completed."; });
      });
      if (AUTOEXT) { xb.click(); }
    }

    var s = j.estimate;
    if (s && s.state === "ok" && !SHOW.length) { add(card("Computer estimate (not an appraisal)", [["Auction", money(s.auction)], ["Marketplace", money(s.marketplace)], ["Brokerage", money(s.brokerage)]], true)); }
    if (o.children.length < 2) { o.appendChild(el("div", "dblk-err", "Nothing to show for this name.")); }
  }

  f.addEventListener("submit", function (ev) {
    ev.preventDefault();
    var v = f.d.value.trim(); if (!v) { return; }
    if (v.indexOf(".") < 0) { v += ".com"; f.d.value = v; }
    var b = f.querySelector("button"); b.disabled = true; b.textContent = "Looking up..."; o.textContent = "";
    fetch(EP + "?d=" + encodeURIComponent(v) + (PARTS.length ? "&parts=" + PARTS.join(",") : ""), { headers: { Accept: "application/json" } })
      .then(readJson)
      .then(function (x) { if (!x.ok) { o.appendChild(el("div", "dblk-err", (x.j && (x.j.error || x.j.message)) || "Lookup failed.")); } else { render(x.j); } })
      .catch(function () { o.appendChild(el("div", "dblk-err", "The lookup could not be completed. Check your connection and try again.")); })
      .then(function () { b.disabled = false; b.textContent = C.button || "Look up"; });
  });
  var q = new URLSearchParams(location.search).get("d");
  if (q) { f.d.value = q; if (f.requestSubmit) { f.requestSubmit(); } else { f.dispatchEvent(new Event("submit", { cancelable: true })); } }
}());
