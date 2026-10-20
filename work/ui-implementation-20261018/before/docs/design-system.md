# Lailaps UI

Geist is the visual reference; shadcn-vue and Reka UI are the implementation.
This is a Lailaps adaptation, not an installation of Vercel's React components.

## Authoritative files

- `resources/css/app.css`: fonts, light/dark semantic colors, radius and typography.
- `resources/js/components/ui`: shared component defaults and typed variants.
- `resources/js/components/AppHeader.vue`: responsive application navigation.
- `resources/js/pages/projects/Index.vue`: reference for lists and empty states.
- `resources/js/pages/projects/Create.vue`: reference for forms and upload states.
- `resources/js/pages/projects/Show.vue`: reference for detail pages.

## Foundations

- Geist Sans for UI; Geist Mono for hashes, paths and identifiers. Fonts are
  acquired and served by the existing Laravel Vite font pipeline.
- Neutral surfaces and thin borders. Colors communicate states and always have
  accompanying text; source readiness is distinct from vulnerability severity.
- Use `bg-background`, `bg-card`, `bg-muted`, `text-foreground`,
  `text-muted-foreground`, `border-border` and semantic status tokens.
- Theme values live in `app.css`. Pages should not introduce raw colors or
  independent light/dark palettes. Preserve contrast and focus visibility.
- Standard controls are 36px high (`size="default"`). Use the existing smaller
  variants for secondary compact actions; preserve usable touch targets.
- Use `page-container`, `page-title`, `section-title`, `page-description`,
  `metadata` and `technical-value`. Layout uses Tailwind's standard spacing scale.
- Body UI is 14px. Page titles are 24px/30px responsive; section titles are 16px;
  metadata is 12px. Inputs retain 16px text on mobile to avoid focus zoom.
- Panels use the shared Card; reserve prominent shadows for floating surfaces.
- Lucide (`@lucide/vue`) is the icon library for application UI. Icon-only actions
  need an accessible name. Technical values wrap without widening the page.

## UI copy

Use English in UI and code, and Italian in developer chat. Do not add code
comments. Do not add explanatory subtitles, hints, privacy reminders or filler
copy by default. Prefer concise titles, labels, actions and empty states. Keep
necessary validation errors, statuses and progress feedback.

Keep internal entities, identifiers and content hashes out of product pages.
Show the operational environment status without exposing Lab management.

## Implementation

Import components from `@/components/ui`; choose `variant` and `size` rather
than overriding their geometry or palette in individual pages. Change shared
defaults centrally when the change applies to the product. Local layout classes
remain appropriate. Create composed components only for patterns actually reused.

SourceUpload accepts one ZIP. SourceStatus shows the backend state without
inferring readiness or success. Backend failures retain the form and selection.
New ZIP uploads replace the active source according to the existing action.
Project edit/delete, source delete and GitHub import are outside this UI's scope.

New shadcn components or blocks must be aligned with these tokens and defaults;
their upstream styling does not automatically follow this adaptation.

## Visual review

View modified pages in the browser at desktop and mobile widths, in light and
dark themes. Check populated/empty/error states, upload progress, keyboard focus,
long names/hashes and horizontal overflow. Do not mark an upload successful or a
source ready unless the backend actually returned that outcome.

## References

- https://vercel.com/geist/colors
- https://vercel.com/geist/typography
- https://vercel.com/font
- https://www.shadcn-vue.com/docs/theming
