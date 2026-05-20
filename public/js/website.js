(function () {
    'use strict';

    // Scroll reveal
    const revealObserver = new IntersectionObserver((entries) => {
        for (const entry of entries) {
            if (entry.isIntersecting) {
                entry.target.classList.add('in');
                revealObserver.unobserve(entry.target);
            }
        }
    }, { threshold: .14, rootMargin: '0px 0px -8% 0px' });

    for (const el of [...document.querySelectorAll('.reveal')]) {
        revealObserver.observe(el);
    }

    // Sticky nav after hero
    const nav = document.getElementById('nav');
    const hero = document.querySelector('.hero');

    if (!nav || !hero) return;

    const onScroll = () => {
        nav.classList.toggle('solid', window.scrollY > hero.offsetHeight - 100);
    };

    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
})();
