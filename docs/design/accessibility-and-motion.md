# Accessibility and motion principles

Every sprint's design review and CI checks these. The standard is WCAG 2.1 AA.

## Accessibility

- **Contrast:** body text at least 4.5:1, large text and icons at least 3:1. Approved pairs are listed in `brand.md`. Orange is never used for text on white; white is never used on orange.
- **Never colour alone:** status, temperature, sender/receiver and errors always carry a label or icon as well as colour.
- **Keyboard:** every action works with a keyboard. Visible focus ring (2 px cobalt-400, 2 px offset) on every focusable element. Logical tab order. Drag and drop (kanban) has a keyboard alternative (move to stage menu).
- **Screen readers:** semantic HTML first; labelled form fields; errors linked to their fields and announced; toasts and real-time updates use polite live regions; charts have a data-table alternative; maps have a list alternative.
- **Touch:** targets at least 44 × 44 px; nothing depends on hover.
- **Forms:** labels always visible (no placeholder-only fields); inline validation after leaving a field, not on every keystroke; error summary at the top for long forms.
- **Text:** supports 200% zoom without loss of content; no text in images; line length under 80 characters for reading text.
- **Media:** training videos have captions and a transcript.
- **Language:** plain English, sentence case, short sentences. Dates and times show in the user's time zone with the zone name.
- **Testing:** automated axe checks in CI on every page; manual keyboard and screen-reader pass (VoiceOver, NVDA) before each release.

## Motion

- Motion explains change; it never decorates. Use it when something opens, moves, is added or removed, or confirms an action.
- Durations: 120 ms (hover, press), 200 ms (open/close, small moves), 320 ms (page-level and large panels). Nothing longer.
- Easing: standard `cubic-bezier(0.2, 0, 0, 1)`; enter `cubic-bezier(0, 0, 0, 1)`; exit `cubic-bezier(0.3, 0, 1, 1)`.
- One signature moment: when a referral is accepted, the arrow travels from the sender's cobalt to the receiver's orange on the timeline. No other celebratory animation.
- No auto-playing carousels, parallax or looping background animation.
- Respect `prefers-reduced-motion`: replace movement with instant changes or simple fades.
- Loading: skeletons that match the final layout; spinners only inside buttons. No layout shift when content arrives.
