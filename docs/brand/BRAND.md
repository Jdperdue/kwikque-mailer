# KwikQue brand assets

Service brand for the JDSS multi-tenant list manager and bulk mailer (app at https://app.kwikque.com).
This is the JDSS service brand, not a tenant brand: tenant branding (for example Rene Bates) lives in
the tenants table and tenant email templates, never in these files.

## Files
| File | Use |
|---|---|
| kwikque-icon.svg | Master square icon (navy rounded square). Use as `favicon.svg`. |
| kwikque-icon-square-full-bleed.svg | Same icon without rounded corners, for platforms that round it themselves. |
| kwikque-icon-512.png, -192.png | PWA / manifest icons. |
| apple-touch-icon.png | 180x180, full bleed, for `<link rel="apple-touch-icon">`. |
| kwikque-icon-32.png, -16.png, favicon.ico | Legacy favicon (ico holds 16, 32, 48). |
| kwikque-logo-on-light.svg / .png | Horizontal logo for light backgrounds (admin login, light UI). |
| kwikque-logo-on-dark.svg / .png | Horizontal logo for dark backgrounds. |

Logo SVGs have the wordmark converted to outlines, so they do not need Space Grotesk installed.
Logo PNGs are 1760 px wide with transparent backgrounds.

## Colors
- Navy `#0B1F3A` (text, icon background)
- Amber `#FFB400` (accent, envelope)
- App background `#F7F9FC`
- Muted text `#5B6B80`
- Do not use amber as text on light backgrounds (contrast too low); use navy.

## Type
Space Grotesk. Wordmark is "Kwik" in Bold 700 and "Que" in Regular 400, tight tracking (about -3% of size).
Use Space Grotesk for headings in the admin UI if convenient; body text may stay a system font.

## Laravel placement suggestion
- public/favicon.ico, public/favicon.svg, public/apple-touch-icon.png
- public/brand/ for the logos and the 192/512 icons
- Reference them via `asset()`; do not hard-code the brand name or URL in views, use config('app.name').

```html
<link rel="icon" href="/favicon.ico" sizes="48x48">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
```

## Rules
- Keep clear space around the logo of at least the height of the "K".
- Do not recolor, stretch, rotate or re-order the envelopes.
- Below 24 px use the icon, not the horizontal logo.
