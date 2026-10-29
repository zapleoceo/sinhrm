# Foundation implementation plan

## Reuse/spec

CONFIRMED: Angular22.2.1 +Material/CDK, OnPush/signals/inject, ThemeService light/dark consumed by shell and app startup. safeStorage exists; global styles.scss uses Material semantic tokens/light-dark plus reusable page patterns. Reuse these instead of parallel mockup CSS/controls.

Extend ThemeService compatibly with typed cosmetic preferences and named reference scheme; effective theme remains light/dark for existing clients. Themes system/light/dark; accent blue/violet/green/graphite; scheme custom/sintegrum; style glass/solid; wallpaper mist/sand/dusk; transparency0..40; reduced motion and fast mode. Use strict validators with allowlisted values, legacy sinhrm.theme migration, safe storage failures, no payload/PII. CSS attributes/data tokens are single shared contract, fast always opaque/no blur/animation. set/update/init methods apply from one authority.

Appearance page lazy standalone/OnPush, typed reactive forms, translated UK/RU/EN; route /settings/appearance and user menu link (shell wiring by root/next worker), no new API. Use existing Material controls and shared classes. No giant UI logic in service.

Meaningful targeted unit tests: corrupt/blocked storage, unknown enums/extra keys/bounds, legacy migration, system preference/effective reference theme, theme toggle/custom exit, DOM attrs/CSS var/fast/reducedmode; settings controls update/reset/save. Existing ThemeService API retained.

Ownership round1: Luna preferences owns core/theme/*, features/appearance/* and translations appearance section only; root task/docs and wiring until assigned. Global stylesheet work waits discovery/single token contract. Don't change auth/windowroutes in foundation.
