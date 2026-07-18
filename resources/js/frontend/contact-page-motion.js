const initialiseContactPageMotion = () => {
    const page = document.querySelector('[data-contact-page]');

    if (! page || page.dataset.motionInitialised === 'true') return;

    page.dataset.motionInitialised = 'true';

    const form = document.querySelector('[data-contact-form-block]');
    if (form) form.id = 'contact-form';

    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches || ! ('IntersectionObserver' in window)) return;

    document.documentElement.classList.add('contact-motion-ready');
    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (! entry.isIntersecting) return;
            entry.target.classList.add('is-visible');
            observer.unobserve(entry.target);
        });
    }, { rootMargin: '0px 0px -7% 0px', threshold: 0.1 });

    [...page.querySelectorAll('[data-contact-reveal]'), ...(form ? [form] : [])].forEach((element, index) => {
        element.style.setProperty('--contact-delay', `${Math.min((index % 3) * 80, 160)}ms`);
        observer.observe(element);
    });
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialiseContactPageMotion, { once: true });
} else {
    initialiseContactPageMotion();
}

document.addEventListener('livewire:navigated', initialiseContactPageMotion);
