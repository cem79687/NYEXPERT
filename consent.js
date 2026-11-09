/* consent.js — gestion du consentement aux traceurs optionnels
   ─────────────────────────────────────────────────────────────────────────────
   ÉTAT ACTUEL : DORMANT. Le site n'utilise aucun traceur soumis à consentement,
   donc ce fichier ne fait rien : pas de bandeau, aucun stockage dans le navigateur.

   POUR ACTIVER (ex. ajout de statistiques, d'une vidéo YouTube, d'un pixel…) :
     1. Déclarer le service dans SERVICES ci-dessous (un exemple est fourni).
     2. Mettre à jour la section 7 « Cookies et traceurs » des mentions légales.
   Le bandeau s'affiche alors, le service ne se charge QU'APRÈS acceptation,
   et un lien « Gérer mes cookies » apparaît dans le pied de page.
   ───────────────────────────────────────────────────────────────────────────── */
(function () {
  'use strict';

  var SERVICES = [
    /* EXEMPLE — à décommenter et adapter :
    {
      id: 'stats',
      label: 'Mesure d’audience',
      description: 'Statistiques de fréquentation pour améliorer le site.',
      load: function () {
        var s = document.createElement('script');
        s.async = true;
        s.src = 'https://exemple.tld/script-de-statistiques.js';
        document.head.appendChild(s);
      }
    }
    */
  ];

  if (!SERVICES.length) { return; }     // rien à consentir : aucun bandeau, aucun stockage

  var KEY = 'nyexpert-consent';
  var VALIDITE_JOURS = 180;              // le choix est redemandé au bout de 6 mois
  var banner = null;

  function lire() {
    try {
      var d = JSON.parse(localStorage.getItem(KEY));
      if (d && d.t && (Date.now() - d.t) < VALIDITE_JOURS * 864e5 && d.c) { return d.c; }
    } catch (e) { /* stockage indisponible : on redemandera */ }
    return null;
  }
  function ecrire(choix) {
    try { localStorage.setItem(KEY, JSON.stringify({ v: 1, t: Date.now(), c: choix })); } catch (e) { /* ignoré */ }
  }
  function appliquer(choix) {
    SERVICES.forEach(function (s) { if (choix[s.id] === true && !s._charge) { s._charge = true; s.load(); } });
  }
  function fermer() { if (banner) { banner.remove(); banner = null; } }
  function enregistrer(choix) { ecrire(choix); appliquer(choix); fermer(); }
  function tous(val) { var c = {}; SERVICES.forEach(function (s) { c[s.id] = val; }); return c; }

  function el(tag, attrs, texte) {
    var n = document.createElement(tag);
    if (attrs) { Object.keys(attrs).forEach(function (k) { n.setAttribute(k, attrs[k]); }); }
    if (texte) { n.textContent = texte; }
    return n;
  }

  function injecterStyle() {
    if (document.getElementById('consent-style')) { return; }
    var st = el('style', { id: 'consent-style' });
    st.textContent =
      '.consent{position:fixed;left:1rem;bottom:1rem;z-index:2000;width:min(440px,calc(100% - 2rem));background:#162538;color:#fff;border:1px solid rgba(201,150,60,.45);border-top:3px solid #C9963C;border-radius:4px;padding:1.4rem 1.5rem;box-shadow:0 18px 48px rgba(0,0,0,.4);font:400 .9rem/1.6 Inter,sans-serif}' +
      '.consent h2{font:600 1.25rem/1.2 "Cormorant Garamond",serif;margin:0 0 .6rem;color:#fff}' +
      '.consent p{margin:0 0 1rem;color:rgba(255,255,255,.82)}' +
      '.consent a{color:#E0B86A}' +
      '.consent-actions{display:flex;flex-wrap:wrap;gap:.6rem}' +
      '.consent-btn{flex:1 1 auto;min-height:44px;padding:.7rem 1rem;border-radius:4px;border:1.5px solid #E0B86A;background:#E0B86A;color:#162538;font:600 .88rem Inter,sans-serif;cursor:pointer}' +
      '.consent-btn:hover{background:#fff;border-color:#fff}' +
      '.consent-btn.alt{background:transparent;color:#fff;border-color:rgba(255,255,255,.5)}' +
      '.consent-btn.alt:hover{border-color:#fff;background:rgba(255,255,255,.08)}' +
      '.consent-btn:focus-visible,.consent a:focus-visible{outline:3px solid #fff;outline-offset:2px}' +
      '.consent-list{list-style:none;margin:0 0 1rem;padding:0}' +
      '.consent-list li{padding:.7rem 0;border-top:1px solid rgba(255,255,255,.12)}' +
      '.consent-list label{display:flex;gap:.7rem;align-items:flex-start;cursor:pointer}' +
      '.consent-list input{width:20px;height:20px;margin-top:.15rem;accent-color:#C9963C;flex:none}' +
      '.consent-list small{display:block;color:rgba(255,255,255,.7)}' +
      '@media(max-width:560px){.consent{left:0;right:0;bottom:0;width:100%;border-radius:4px 4px 0 0}}';
    document.head.appendChild(st);
  }

  function afficher(personnaliser) {
    fermer(); injecterStyle();
    var courant = lire() || {};
    banner = el('div', { class: 'consent', role: 'dialog', 'aria-modal': 'false', 'aria-labelledby': 'consent-titre' });
    banner.appendChild(el('h2', { id: 'consent-titre' }, 'Vos choix sur les cookies'));
    var p = el('p');
    p.appendChild(document.createTextNode('Avec votre accord, ce site peut utiliser des services optionnels. Ils ne se chargent qu\u2019après votre choix, que vous pouvez modifier à tout moment. '));
    p.appendChild(el('a', { href: 'mentions-legales.html#cookies' }, 'En savoir plus'));
    p.appendChild(document.createTextNode('.'));
    banner.appendChild(p);

    if (personnaliser) {
      var ul = el('ul', { class: 'consent-list' });
      SERVICES.forEach(function (s) {
        var li = el('li'), lb = el('label'), cb = el('input', { type: 'checkbox', 'data-id': s.id });
        cb.checked = courant[s.id] === true;
        var tx = el('span'); tx.appendChild(document.createTextNode(s.label)); tx.appendChild(el('small', null, s.description));
        lb.appendChild(cb); lb.appendChild(tx); li.appendChild(lb); ul.appendChild(li);
      });
      banner.appendChild(ul);
      var a2 = el('div', { class: 'consent-actions' });
      var save = el('button', { type: 'button', class: 'consent-btn' }, 'Enregistrer mes choix');
      save.addEventListener('click', function () {
        var c = {}; banner.querySelectorAll('input[data-id]').forEach(function (i) { c[i.getAttribute('data-id')] = i.checked; });
        enregistrer(c);
      });
      a2.appendChild(save); banner.appendChild(a2);
    } else {
      var a = el('div', { class: 'consent-actions' });
      var refuse = el('button', { type: 'button', class: 'consent-btn' }, 'Tout refuser');
      var accept = el('button', { type: 'button', class: 'consent-btn' }, 'Tout accepter');
      var perso = el('button', { type: 'button', class: 'consent-btn alt' }, 'Personnaliser');
      refuse.addEventListener('click', function () { enregistrer(tous(false)); });
      accept.addEventListener('click', function () { enregistrer(tous(true)); });
      perso.addEventListener('click', function () { afficher(true); });
      a.appendChild(refuse); a.appendChild(accept); a.appendChild(perso); banner.appendChild(a);
    }
    // placé au tout début du document : atteint dès la première touche Tab (utilisateurs au clavier / lecteurs d'écran)
    document.body.insertBefore(banner, document.body.firstChild);
  }

  function lienDeGestion() {
    var cible = document.querySelector('.footer-bottom p');
    if (!cible || document.getElementById('consent-open')) { return; }
    cible.appendChild(document.createTextNode(' \u00B7 '));
    var b = el('button', { type: 'button', id: 'consent-open', class: 'footer-legal-link' }, 'Gérer mes cookies');
    b.style.cssText = 'background:none;border:0;padding:0;cursor:pointer;font:inherit;text-decoration:underline;text-underline-offset:3px';
    b.addEventListener('click', function () { afficher(false); });
    cible.appendChild(b);
  }

  function demarrer() {
    lienDeGestion();
    var choix = lire();
    if (choix) { appliquer(choix); } else { afficher(false); }
  }
  window.nyConsent = { ouvrir: function () { afficher(false); }, accepte: function (id) { var c = lire(); return !!(c && c[id] === true); } };
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', demarrer); } else { demarrer(); }
})();
