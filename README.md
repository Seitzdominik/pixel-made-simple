# Pixel Made Simple

Monorepo for the **Pixel Made Simple** WordPress plugin: lightweight Meta
Pixel & Conversions API tracking, plus Google Ads (Consent Mode v2) and
TikTok Pixel, with URL-based multi-platform events, GDPR cookie-consent
detection, and clean server/browser event deduplication.

The plugin ships as two installable packages built from the same source:

| Package | Slug | Distribution |
|---|---|---|
| Free | `pixel-made-simple` | WordPress.org |
| Pro | `pixel-made-simple-pro` | Self-hosted, GitHub Releases (auto-updates via [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker)) |

Both read/write the same `pms_settings` option, so upgrading from Free to Pro
on a live site keeps the existing configuration.

## Layout

```
src/                          <- everything that ships in a plugin package
  assets/                     <- admin + frontend JS/CSS (shared)
  includes/                   <- core logic (shared): settings, consent,
                                  attribution, CAPI, frontend, forms, debug,
                                  admin, tools
  languages/                  <- POT + de_DE translation (shared)
  plugin-update-checker/      <- vendored update-checker library (Pro only)
  pro/                        <- Pro-exclusive code, loaded only by the Pro
                                  main file
  pixel-made-simple.php       <- Free main file (defines PMS_IS_PRO = false)
  pixel-made-simple-pro.php   <- Pro main file  (defines PMS_IS_PRO = true)
  readme.txt                  <- WordPress.org readme
  uninstall.php               <- shared; only clears pms_* options once
                                  neither package is left installed
dev-tools/                    <- NOT shipped; local dev/test tooling
  test-suite.php              <- 200+ PHP tests, no WordPress install needed
  test-frontend-js.js         <- Node tests for assets/frontend.js
  build-translations.php      <- POT/PO/MO generator + validator
  preview-admin.php           <- renders the admin tabs as static HTML
.github/workflows/release.yml <- builds + releases both ZIPs on a `vX.Y.Z` tag
```

`src/` is the single source of truth for both packages — there is no
free-only or pro-only fork of a shared file. Package-specific behaviour lives
either in the two main files (collision guards, update checker, textdomain
loading) or under `pro/`.

## Releasing

1. Bump `PMS_VERSION` (and the `Version:` header) in **both**
   `src/pixel-made-simple.php` and `src/pixel-made-simple-pro.php`, and add a
   changelog entry to `src/readme.txt`.
2. Tag and push: `git tag v1.2.3 && git push origin v1.2.3`.
3. GitHub Actions builds `pixel-made-simple.zip` and
   `pixel-made-simple-pro.zip` from `src/` and attaches both to the release
   for that tag. No local ZIP-building step is needed anymore.

## Development

See [`CLAUDE.md`](CLAUDE.md) for the full architecture writeup, the
dev-tools command reference (tests, translation builds, admin-UI preview),
and known pitfalls hit while building this plugin.
