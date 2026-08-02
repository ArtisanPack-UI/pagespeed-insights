# ArtisanPack UI PageSpeed Insights

Google PageSpeed Insights testing, score history, and drop-in UI components for the ArtisanPack UI ecosystem.

> **Status: in development.** The package is scaffolded and boots, but the API client, data model, and UI surfaces are still being built. This README is a placeholder and will be replaced with full documentation before the 1.0.0 release.

## What it will do

Google's PageSpeed Insights UI tells you how a page performs *right now*. This package adds the part it leaves out: **history**. Scheduled, queued re-tests build a per-URL score timeline, so performance regressions show up as a visible trend — and as an alert — instead of as a hunch.

Planned for 1.0.0:

- Scheduled PSI runs against a managed list of URLs, with full result history
- Lighthouse category scores, lab metrics, and CrUX field data stored per URL and strategy
- Livewire, React, and Vue components, plus CMS-framework admin widgets
- Regression alerts through standard Laravel notifications
- A CI-friendly `pagespeed:test` command with score budgets and non-zero exit codes
- Hooks so other ArtisanPack UI packages can register URLs and consume results

## Requirements

- PHP **8.2+**
- Laravel **10, 11, 12, or 13**
- A **PageSpeed Insights API key** — see [API key](#api-key)
- [`artisanpack-ui/google`](https://github.com/ArtisanPack-UI/google) **^1.0** — installed automatically; a connected Google account is optional and used only as an auth fallback
- **Livewire ^3.6** *(optional)* — required only for the Blade / Livewire components and the CMS-framework admin widget bridge

## Installation

```bash
composer require artisanpack-ui/pagespeed-insights
```

The service provider and the `PageSpeedInsights` facade are auto-discovered by Laravel.

## API key

The PageSpeed Insights API is quota-limited per project. Keyless requests are **not** a workable fallback — the shared anonymous project currently has a daily quota of zero — so an API key is effectively required.

Create one in the [Google Cloud Console](https://console.cloud.google.com/apis/credentials) with the **PageSpeed Insights API** enabled on the project.

Configuration is not wired up yet; see [`docs/psi-api-reference.md`](docs/psi-api-reference.md) for the verified API behaviour the implementation is built against.

## Development

```bash
composer install
composer test    # Pest
composer lint    # PHP-CS-Fixer (dry run) + PHPCS
composer fix     # PHP-CS-Fixer, applied
```

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

MIT — see [LICENSE](LICENSE).
