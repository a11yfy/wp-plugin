# a11yfy — PDF Accessibility Checker & Fixer (WordPress plugin)

Source code of the [a11yfy WordPress plugin](https://wordpress.org/plugins/a11yfy-pdf-accessibility-checker-fixer/): a free, 100% in-browser PDF accessibility scan (42 machine-verifiable Matterhorn Protocol / PDF/UA-1 checkpoints) for every PDF in your Media Library, plus optional one-click remediation through the [a11yfy](https://a11yfy.com) service.

This repository is the public source of the plugin, including the **unminified source of the bundled analysis engine** (see `js/`). Releases are published to the WordPress.org plugin directory via SVN; this repo mirrors the released source.

## Layout

```
a11yfy.php            Plugin bootstrap
includes/             PHP: settings, API client, job queue, guardrails, admin UI
assets/js/            Admin orchestration scripts (plain JS)
assets/js/dist/       Built engine bundles — generated, not committed (see below)
js/                   Scan-engine source (pdf.js + pdf-lib, bundled with esbuild)
languages/            Translations (.po/.mo) + Matterhorn check catalog (JSON)
tests/phpunit/        PHPUnit suite (wp-env)
tests/e2e/            Playwright smoke tests
bin/                  Build & tooling scripts
```

## Building

Requirements: PHP 7.4+, Node 18+, Composer, Docker (for wp-env).

```bash
bin/fetch-vendors.sh              # fetch bundled PHP libs (Action Scheduler, smalot/pdfparser)
cd js && npm install && node build.mjs   # build the scan engine into assets/js/dist/
bin/build.sh                      # full distributable ZIP into build/
```

## Testing

```bash
cd js && npm test                 # vitest — engine unit tests
npm install && npx wp-env start   # WordPress dev environment
npm run test:php                  # PHPUnit (inside wp-env)
npm run test:e2e                  # Playwright smoke tests
composer install && vendor-dev/bin/phpcs   # WordPress Coding Standards
```

## Bundled third-party libraries

| Library | License | Where |
| --- | --- | --- |
| [pdf.js](https://github.com/mozilla/pdf.js) | Apache-2.0 | bundled into the JS engine |
| [pdf-lib](https://github.com/Hopding/pdf-lib) | MIT | bundled into the JS engine |
| [smalot/pdfparser](https://github.com/smalot/pdfparser) | LGPL-3.0 | `vendor/` (fetched at build time) |
| [Action Scheduler](https://github.com/woocommerce/action-scheduler) | GPL-3.0 | `vendor/` (fetched at build time) |

## License

GPL-2.0-or-later — see [LICENSE](LICENSE).
