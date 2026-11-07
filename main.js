// main.js — Logique commune du site Nurset YAZGOREN Expert Bâtiment
// Menu mobile (identique sur toutes les pages)

document.addEventListener('DOMContentLoaded', () => {
  const menuToggle = document.getElementById('menuToggle');
  const navLinks = document.getElementById('navLinks');

  if (menuToggle && navLinks) {
    const menuSpans = menuToggle.querySelectorAll('span');

    const closeMenu = () => {
      navLinks.classList.remove('open');
      menuToggle.setAttribute('aria-expanded', 'false');
      menuSpans.forEach((span) => { span.style.cssText = ''; });
    };

    menuToggle.addEventListener('click', (e) => {
      e.stopPropagation();
      const isOpen = navLinks.classList.toggle('open');
      menuToggle.setAttribute('aria-expanded', String(isOpen));
      if (isOpen) {
        menuSpans[0].style.cssText = 'transform:translateY(7px) rotate(45deg)';
        menuSpans[1].style.cssText = 'opacity:0; transform:scaleX(0)';
        menuSpans[2].style.cssText = 'transform:translateY(-7px) rotate(-45deg)';
      } else {
        closeMenu();
      }
    });

    navLinks.querySelectorAll('a').forEach(a => {
      a.addEventListener('click', closeMenu);
    });

    document.addEventListener('click', (e) => {
      if (!menuToggle.contains(e.target) && !navLinks.contains(e.target)) {
        closeMenu();
      }
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && navLinks.classList.contains('open')) {
        closeMenu();
        menuToggle.focus();
      }
    });
  }

  // Sous-menu « Zones » : ouverture au clic, fermeture par Échap, clic extérieur ou choix d'un lien
  document.querySelectorAll('.has-sub').forEach((item) => {
    const btn = item.querySelector('.nav-sub-toggle');
    if (!btn) return;
    const closeSub = () => { item.classList.remove('open'); btn.setAttribute('aria-expanded', 'false'); };

    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      const isOpen = item.classList.toggle('open');
      btn.setAttribute('aria-expanded', String(isOpen));
    });
    document.addEventListener('click', (e) => { if (!item.contains(e.target)) closeSub(); });
    item.addEventListener('focusout', (e) => { if (e.relatedTarget && !item.contains(e.relatedTarget)) closeSub(); });   // clavier : Tab hors du sous-menu
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && item.classList.contains('open')) { closeSub(); btn.focus(); }
    });
    item.querySelectorAll('.nav-sub a').forEach((a) => a.addEventListener('click', closeSub));
  });

});
