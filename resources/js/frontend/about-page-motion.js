const initialiseAboutPageMotion = () => {
    const page = document.querySelector('[data-about-page]');

    if (! page || page.dataset.motionInitialised === 'true') {
        return;
    }

    page.dataset.motionInitialised = 'true';

    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches || ! ('IntersectionObserver' in window)) {
        return;
    }

    page.classList.add('about-motion-ready');

    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (! entry.isIntersecting) return;

            entry.target.classList.add('is-visible');
            observer.unobserve(entry.target);
        });
    }, { rootMargin: '0px 0px -7% 0px', threshold: 0.1 });

    page.querySelectorAll('[data-about-reveal]').forEach((element, index) => {
        element.style.setProperty('--about-delay', `${Math.min((index % 3) * 90, 180)}ms`);
        observer.observe(element);
    });
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialiseAboutPageMotion, { once: true });
} else {
    initialiseAboutPageMotion();
}

document.addEventListener('livewire:navigated', initialiseAboutPageMotion);
