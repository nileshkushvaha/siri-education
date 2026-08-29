const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

const customMotionRoots = [
    '[data-public-homepage]',
    '[data-about-page]',
    '[data-contact-page]',
    '[data-public-faqs]',
    '[data-instructor-marketplace]',
    '[data-instructor-profile]',
    '[data-booking-wizard-page]',
].join(',');

let observer;
const pendingElements = new Set();
let sweepQueued = false;

const reveal = (element) => {
    element.dataset.publicVisible = 'true';
    pendingElements.delete(element);
    observer?.unobserve(element);
};

/*
 * Every pending element's geometry is read in one pass before a single
 * result is written back. The previous shape — a getBoundingClientRect()
 * read immediately followed by a dataset write, per element, inside
 * enhance()'s own write loops — invalidated layout on every iteration and
 * forced the browser to recompute it on the next read. On a content page
 * matching a few hundred elements that was the ~45 ms of forced reflow
 * Lighthouse reported. Reads and writes are the same work in the same
 * frame; only their order changed.
 */
const sweepVisible = () => {
    sweepQueued = false;
    if (!pendingElements.size) return;

    const limit = window.innerHeight * 0.94;
    const visible = [];

    pendingElements.forEach((element) => {
        const bounds = element.getBoundingClientRect();
        if (bounds.bottom >= 0 && bounds.top <= limit) visible.push(element);
    });

    visible.forEach(reveal);
};

const queueSweep = () => {
    if (sweepQueued) return;
    sweepQueued = true;
    requestAnimationFrame(sweepVisible);
};

const splitHeading = (heading) => {
    if (heading.dataset.publicSplit || heading.closest('[data-public-homepage]')) return;

    const label = heading.textContent.replace(/\s+/g, ' ').trim();
    if (!label) return;

    let characterIndex = 0;
    const walk = (node) => {
        [...node.childNodes].forEach((child) => {
            if (child.nodeType === Node.ELEMENT_NODE) {
                if (!child.matches('svg, img, picture, video, br, [aria-hidden="true"]')) walk(child);
                return;
            }
            if (child.nodeType !== Node.TEXT_NODE || !child.textContent.trim()) return;

            const fragment = document.createDocumentFragment();
            child.textContent.split(/(\s+)/).forEach((part) => {
                if (!part) return;
                if (/^\s+$/.test(part)) {
                    fragment.append(document.createTextNode(part));
                    return;
                }
                const word = document.createElement('span');
                word.className = 'public-split-word';
                [...part].forEach((character) => {
                    const span = document.createElement('span');
                    span.className = 'public-split-char';
                    span.style.setProperty('--public-char-index', Math.min(characterIndex++, 34));
                    span.textContent = character;
                    word.append(span);
                });
                fragment.append(word);
            });
            child.replaceWith(fragment);
        });
    };

    heading.dataset.publicSplit = 'true';
    heading.dataset.publicHeading = '';
    heading.setAttribute('aria-label', label);
    walk(heading);
    heading.querySelectorAll('.public-split-word').forEach((word) => word.setAttribute('aria-hidden', 'true'));
};

const observe = (element) => {
    if (element.dataset.publicVisible) return;

    element.dataset.publicObserved = 'true';
    pendingElements.add(element);
    observer?.observe(element);
};

const enhance = (root = document) => {
    const page = document.querySelector('[data-public-motion-page]');
    if (!page || reducedMotion.matches) return;

    root.querySelectorAll?.('h1, h2').forEach(splitHeading);
    root.querySelectorAll?.('[data-public-heading]').forEach(observe);

    root.querySelectorAll?.([
        'main section',
        'main > article',
        'main > div',
        '[data-instructor-application-page] > section',
    ].join(',')).forEach((section) => {
        if (section.closest(customMotionRoots) || section.matches(customMotionRoots)) return;
        section.dataset.publicReveal ||= 'section';
        observe(section);
    });

    root.querySelectorAll?.([
        'main article',
        'main details',
        'main form',
        'main li[class*="rounded"]',
        'main a[class*="rounded"]',
        'main div[class*="shadow"]',
        '[data-pre-footer-content] > *',
        '[data-footer-column]',
        '[data-footer-newsletter]',
        '[data-footer-bottom]',
        '[data-instructor-application-page] article',
        '[data-instructor-application-page] details',
        '[data-instructor-application-page] ol > li',
        '[data-instructor-application-page] section [class*="grid"] > div[class*="rounded"]',
    ].join(',')).forEach((item, index) => {
        if (item.closest(customMotionRoots)) return;
        item.dataset.publicReveal ||= 'item';
        item.style.setProperty('--public-item-delay', `${Math.min(index % 6, 5) * 55}ms`);
        observe(item);
    });

    root.querySelectorAll?.('main img:not([data-public-motion-image])').forEach((image) => {
        if (image.closest(customMotionRoots)) return;
        image.dataset.publicMotionImage = '';
        image.closest('picture, figure, [class*="overflow-hidden"]')?.setAttribute('data-public-shine', '');
    });

    // observe() no longer reveals an already-visible element itself, so the
    // batched sweep runs here — synchronously, in the same frame enhance()
    // was called in, so above-the-fold content still arrives revealed
    // rather than animating in on load.
    sweepVisible();
};

const boot = () => {
    if (!document.querySelector('[data-public-motion-page]') || reducedMotion.matches) return;
    document.documentElement.classList.add('public-motion-ready');
    observer?.disconnect();
    observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) return;
            entry.target.dataset.publicVisible = 'true';
            pendingElements.delete(entry.target);
            observer.unobserve(entry.target);
        });
    }, { threshold: 0.12, rootMargin: '0px' });
    enhance();
    // Retry visible content without prematurely completing below-the-fold motion.
    window.setTimeout(sweepVisible, 2400);
};

document.addEventListener('DOMContentLoaded', boot, { once: true });
document.addEventListener('livewire:navigated', boot);
document.addEventListener('livewire:initialized', () => {
    window.Livewire?.hook?.('morph.updated', ({ el }) => requestAnimationFrame(() => enhance(el)));
});

reducedMotion.addEventListener('change', () => window.location.reload());
window.addEventListener('scroll', queueSweep, { passive: true });
window.addEventListener('resize', queueSweep, { passive: true });
