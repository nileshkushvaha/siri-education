# Frontend Livewire components

Namespace for public/student-facing Livewire components — as opposed to
`App\Livewire\Navigation\*`, which powers the Filament admin nav builder.

## Rules

- **No business logic.** A component may only orchestrate: read input,
  call an existing Service/Action/Repository, hand the result to the
  view. If a component needs a rule, a query, or a side effect that
  doesn't already exist as a Service/Action/Repository method, add it
  there — not in the component.
- **Keep components thin.** Validation of *shape* (types, required
  fields) may live in the component via Livewire's `#[Validate]` /
  `rules()`; validation of *business rules* (availability, permissions,
  uniqueness against other records) must go through the same
  Service/Action layer everything else uses, so there is exactly one
  place each rule is enforced.
- **Authorization** goes through existing Policies (`$this->authorize(...)`
  or `Gate::authorize(...)`), never ad hoc checks inside the component.
- **Naming**: `App\Livewire\Frontend\{Feature}\{ComponentName}`, view at
  `resources/views/livewire/frontend/{feature}/{component-name}.blade.php`
  (Livewire's default convention — no manual registration needed).

## Conventions

- One component = one clear responsibility. If a component starts
  reaching into multiple unrelated Services, it's doing too much —
  split it or push the coordination into a Service instead.
- Prefer emitting/listening to events for cross-component
  communication over direct method calls into sibling components.
- Accessibility: components render real interactive elements (`<button>`,
  `<a>`, form controls) with visible focus states and appropriate ARIA
  — the same bar as the Blade components in `resources/views/components/`.

See `docs/frontend.md` for the layout/component/asset architecture this
namespace fits into.
