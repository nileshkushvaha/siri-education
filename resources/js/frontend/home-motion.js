const initialiseHomeMotion = () => {
    const homepage = document.querySelector('[data-public-homepage]');

    if (! homepage || homepage.dataset.motionInitialised === 'true') {
        return;
    }

    homepage.dataset.motionInitialised = 'true';

    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }

    const revealTargets = new Set();
    const headingTargets = new Set();

    const addRevealTarget = (element, delay = 0, variant = 'content') => {
        if (revealTargets.has(element)) {
            return;
        }

        element.dataset.homeReveal = variant;
        element.style.setProperty('--home-reveal-delay', Math.min(delay, 360) + 'ms');
        revealTargets.add(element);
    };

    const splitHeading = (heading) => {
        if (heading.dataset.homeSplit === 'true') {
            return;
        }

        heading.dataset.homeSplit = 'true';
        heading.setAttribute('aria-label', heading.textContent.trim());
        let characterIndex = 0;

        // `background-clip: text` paints the element's own background
        // through its own text. Re-wrapping that text in per-character
        // inline-blocks leaves the characters transparent with nothing
        // painted behind them — the gradient line simply disappears. Such
        // an element is animated as a single unit instead of split.
        const isGradientText = (element) => {
            const style = window.getComputedStyle(element);

            return (style.webkitBackgroundClip === 'text' || style.backgroundClip === 'text');
        };

        const splitTextNodes = (parent) => {
            Array.from(parent.childNodes).forEach((node) => {
                if (node.nodeType === 3) {
                    const fragment = document.createDocumentFragment();

                    node.textContent.split(/(\s+)/).forEach((part) => {
                        if (! part) {
                            return;
                        }

                        if (/^\s+$/.test(part)) {
                            fragment.append(document.createTextNode(part));
                            return;
                        }

                        const word = document.createElement('span');
                        word.className = 'home-split-word';
                        word.setAttribute('aria-hidden', 'true');

                        Array.from(part).forEach((character) => {
                            const characterElement = document.createElement('span');
                            characterElement.className = 'home-split-char';
                            characterElement.style.setProperty('--home-char-index', characterIndex);
                            characterElement.textContent = character;
                            word.append(characterElement);
                            characterIndex += 1;
                        });

                        fragment.append(word);
                    });

                    node.replaceWith(fragment);
                    return;
                }

                if (node.nodeType === 1 && ! node.matches('svg, [aria-hidden="true"]')) {
                    if (isGradientText(node)) {
                        // Not `home-split-word` — that pins white-space to
                        // nowrap, which a full clause must be free to wrap.
                        node.classList.add('home-split-char');
                        node.style.setProperty('--home-char-index', characterIndex);
                        characterIndex += node.textContent.trim().length;

                        return;
                    }

                    splitTextNodes(node);
                }
            });
        };

        splitTextNodes(heading);
    };

    homepage.querySelectorAll(':scope > section').forEach((section) => {
        const sectionContent = section.querySelector(':scope > div:not([aria-hidden="true"])');

        if (! sectionContent) {
            return;
        }

        Array.from(sectionContent.children).forEach((element, index) => {
            addRevealTarget(element, index * 90);
        });

        section.querySelectorAll('h1, h2').forEach((heading) => {
            splitHeading(heading);
            heading.dataset.homeHeading = '';
            headingTargets.add(heading);
        });

        section.querySelectorAll(
            'ol > li, [class*="grid"] > article, [class*="grid"] > details, [class*="grid"] > a.group, [data-home-instructor-card]',
        ).forEach((card, index) => {
            addRevealTarget(card, (index % 4) * 90, 'card');
            card.dataset.homeShine = '';
        });
    });

    homepage.classList.add('home-motion-ready');

    if (! ('IntersectionObserver' in window)) {
        revealTargets.forEach((element) => element.classList.add('is-visible'));
        headingTargets.forEach((element) => element.classList.add('is-visible'));
        return;
    }

    // Everything above the fold intersects on the observer's very first
    // callback, which can land before the hidden start state has been
    // painted — the browser then has no earlier value to transition from
    // and the element snaps straight to its final style. That is why the
    // hero heading appeared instantly while headings further down the page
    // animated. Painting the start state first, then observing, gives every
    // element the same two-step the scrolled-into-view ones already get.
    const startObserving = (observer) => {
        void homepage.offsetHeight;

        window.requestAnimationFrame(() => {
            window.requestAnimationFrame(() => {
                revealTargets.forEach((element) => observer.observe(element));
                headingTargets.forEach((element) => observer.observe(element));
            });
        });
    };

    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (! entry.isIntersecting) {
                return;
            }

            entry.target.classList.add('is-visible');
            observer.unobserve(entry.target);
        });
    }, {
        rootMargin: '0px 0px -8% 0px',
        threshold: 0.12,
    });

    startObserving(observer);
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialiseHomeMotion, { once: true });
} else {
    initialiseHomeMotion();
}

document.addEventListener('livewire:navigated', initialiseHomeMotion);
