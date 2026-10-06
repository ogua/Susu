// OguaFinance public website behaviour. Everything here is progressive
// enhancement: without JS the page is fully readable and every link works.
document.documentElement.classList.add('js');

const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

// Sticky header goes solid once the page scrolls.
const header = document.querySelector('[data-site-header]');
if (header) {
    const onScroll = () => header.classList.toggle('is-scrolled', window.scrollY > 12);
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
}

// Mobile navigation.
const navToggle = document.querySelector('[data-nav-toggle]');
const mobileNav = document.querySelector('[data-mobile-nav]');
if (navToggle && mobileNav) {
    const setOpen = (isOpen) => {
        mobileNav.classList.toggle('is-open', isOpen);
        navToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        navToggle.querySelector('[data-icon-open]')?.classList.toggle('hidden', isOpen);
        navToggle.querySelector('[data-icon-close]')?.classList.toggle('hidden', !isOpen);
    };

    navToggle.addEventListener('click', () => setOpen(!mobileNav.classList.contains('is-open')));
    mobileNav.querySelectorAll('a').forEach((link) => link.addEventListener('click', () => setOpen(false)));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') setOpen(false);
    });
}

// Scroll reveal for [data-reveal] blocks.
const revealTargets = document.querySelectorAll('[data-reveal]');
if (prefersReducedMotion || !('IntersectionObserver' in window)) {
    revealTargets.forEach((el) => el.classList.add('is-visible'));
} else {
    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                entry.target.classList.add('is-visible');
                observer.unobserve(entry.target);
            });
        },
        { threshold: 0.12, rootMargin: '0px 0px -40px 0px' },
    );
    revealTargets.forEach((el) => observer.observe(el));
}

// FAQ accordion — one open at a time, real buttons with aria-expanded.
document.querySelectorAll('[data-faq-item]').forEach((item) => {
    const trigger = item.querySelector('[data-faq-trigger]');
    if (!trigger) return;

    trigger.addEventListener('click', () => {
        const wasOpen = item.classList.contains('is-open');

        document.querySelectorAll('[data-faq-item].is-open').forEach((openItem) => {
            openItem.classList.remove('is-open');
            openItem.querySelector('[data-faq-trigger]')?.setAttribute('aria-expanded', 'false');
        });

        if (!wasOpen) {
            item.classList.add('is-open');
            trigger.setAttribute('aria-expanded', 'true');
        }
    });
});

// Passbook ticker: counts the stamped days up to the collected total once visible.
document.querySelectorAll('[data-count-to]').forEach((el) => {
    const target = Number(el.dataset.countTo);
    const format = (value) => value.toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    if (prefersReducedMotion || !('IntersectionObserver' in window)) {
        el.textContent = format(target);
        return;
    }

    const observer = new IntersectionObserver((entries) => {
        if (!entries[0].isIntersecting) return;
        observer.disconnect();
        const start = performance.now();
        const duration = 2200;
        const step = (now) => {
            const progress = Math.min((now - start) / duration, 1);
            el.textContent = format(target * (1 - Math.pow(1 - progress, 3)));
            if (progress < 1) requestAnimationFrame(step);
        };
        requestAnimationFrame(step);
    });
    observer.observe(el);
});
