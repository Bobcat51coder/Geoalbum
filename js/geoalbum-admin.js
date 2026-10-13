/* Geo Album — repères dans la gestion des albums Piwigo (1.1.0) */
(function () {
  var Z = window.GAB_ADMIN_ZONES || {};
  function info(id) { return Z[id]; }
  function badge(i) {
    var s = document.createElement('a');
    s.className = 'gab-badge';
    s.href = i.url;
    s.title = 'Geo Album — zone « ' + i.zone + ' » (' + (i.active ? 'active' : 'inactive') + ')';
    s.style.cssText = 'margin-left:6px;padding:1px 6px;border-radius:9px;font-size:11px;text-decoration:none;' +
      (i.active ? 'background:#dff3e3;color:#1b6b2f' : 'background:#eee;color:#777');
    s.textContent = (i.active ? '🌍 Geo Album' : '⏸ Geo Album inactif');
    return s;
  }
  function decorate() {
    // Liste des albums (Piwigo 13+ : lignes générées en JS, <span class="move-cat-title-container" data-id>)
    var rows = document.querySelectorAll('.move-cat-title-container[data-id]');
    for (var k = 0; k < rows.length; k++) {
      var r = rows[k], i0 = info(r.getAttribute('data-id'));
      if (!i0 || r.querySelector('.gab-badge')) continue;
      var b0 = badge(i0);
      b0.style.display = 'inline-block';
      r.appendChild(b0);
    }
    var pm = /[?&]page=album-(\d+)/.exec(location.search);
    if (pm && info(pm[1]) && !document.getElementById('gab-banner')) {
      var i = info(pm[1]), box = document.createElement('div');
      box.id = 'gab-banner';
      box.style.cssText = 'margin:10px 0;padding:8px 12px;border-radius:6px;font-size:13px;' +
        (i.active ? 'background:#dff3e3;color:#1b6b2f' : 'background:#eee;color:#555');
      box.innerHTML = (i.active ? '🌍 Album géré par Geo Album' : '⏸ Album Geo Album (zone inactive)') +
        ' — zone « ' + String(i.zone).replace(/</g, '&lt;') + ' » · <a href="' + i.url + '">Ouvrir Geo Album</a>' +
        '<br><small>Son contenu est alimenté automatiquement : modifier les photos à la main sera écrasé.</small>';
      var h = document.querySelector('#cat-modify .cat-modify-header');
      if (h) h.parentNode.insertBefore(box, h.nextSibling);
      else { var c = document.getElementById('cat-modify') || document.getElementById('content') || document.body; c.insertBefore(box, c.firstChild); }
    }
  }
  function start() {
    decorate();
    if (window.MutationObserver) {
      var t = null;
      new MutationObserver(function () { clearTimeout(t); t = setTimeout(decorate, 150); })
        .observe(document.body, { childList: true, subtree: true });
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
