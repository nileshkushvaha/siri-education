const initialiseHomeMotion = () => {
    const homepage = document.querySelector('[data-public-homepage]');

    if (! homepage || homepage.dataset.motionInitialised === 'true') {
        return;
    }

    homepage.dataset.motionInitialised = 'true';

    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }

    const revealTargets = [];

    homepage.querySelectorAll(':scope > section').forEach((section) => {
        const sectionContent = section.querySelector(':scope > div:not([aria-hidden="true"])');

        if (! sectionContent) {
            return;
        }

        Array.from(sectionContent.children).forEach((element, index) => {
            element.dataset.homeReveal = '';
            element.style.setProperty('--home-reveal-delay', `${Math.min(index * 90, 270)}ms`);
            revealTargets.push(element);
        });
    });

    homepage.classList.add('home-motion-ready');

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

    revealTargets.forEach((element) => observer.observe(element));
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialiseHomeMotion, { once: true });
} else {
    initialiseHomeMotion();
}

document.addEventListener('livewire:navigated', initialiseHomeMotion);
