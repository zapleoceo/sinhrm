# Prototype specification

## Desktop reference

- A compact OS-style top edge, with the app launcher as the page's primary content.
- App shortcuts sit inside labeled category panels. Category headings are understated; icons carry category color in rounded square tiles, with concise labels beneath.
- Several app groups form a loose grid on the left and center. A distinct, vertically stacked widget rail occupies the right side.
- A floating translucent Dock sits centered above the bottom edge. It uses compact icons, a separator before utility shortcuts, and remains legible over the background.
- The background is expressive and multicolor, while panels retain enough contrast to separate content.

## Adaptation to this product

- Populate only from the existing authorized `DESKTOP_APPS` registry and translated labels. Hide categories with no authorized entries.
- Populate the widget rail only with the current user-configurable widgets and live `DesktopDataStore` response; retain loading, unavailable, and failure states.
- Keep the existing working window Dock semantics and current per-user widget layout/order/size controls.
- Reinterpret the multicolor background through existing appearance tokens and no-motion fast mode; do not force a screenshot-specific palette over user-selected appearance.

## Responsive states

- Wide desktop: app group matrix plus right widget rail; bottom-centered Dock.
- Narrow desktop/tablet: app groups use fewer columns; widget rail stacks when its minimum readable width cannot fit.
- Mobile: single-column reading order, touch-friendly app shortcuts, existing widget controls and safe-area-aware Dock.
