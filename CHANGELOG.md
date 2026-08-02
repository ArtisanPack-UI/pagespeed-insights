# ArtisanPack UI PageSpeedInsights Changelog

## [Unreleased]

### Added

- Package scaffold generated from `package-blueprint`, following `artisanpack-ui/google-search-console` as the closest sibling reference.
- `composer.json` — `ArtisanPackUI\PageSpeedInsights` PSR-4 namespace, MIT license, `php ^8.2`, `illuminate/support ^10.0|^11.0|^12.0|^13.0`, and required `artisanpack-ui/core`, `artisanpack-ui/hooks`, `artisanpack-ui/google`, and `guzzlehttp/guzzle` dependencies. `suggest` entries for `livewire/livewire`, `artisanpack-ui/cms-framework`, and `artisanpack-ui/livewire-ui-components`.
- `PageSpeedInsightsServiceProvider` with the `pagespeed-insights` singleton binding, auto-discovered by Laravel.
- `PageSpeedInsights` facade and `pageSpeedInsights()` helper, both resolving the same container instance.
- Code style toolchain — `.php-cs-fixer.dist.php` and `phpcs.xml`, with `lint`, `fix`, `cs`, `cs:fix`, and `test` composer scripts.
- Pest + Orchestra Testbench setup with a base `TestCase` and coverage of the boot, binding, facade, and helper paths.
- CI workflow running lint plus the test matrix across PHP 8.2–8.4 and Laravel 12–13, and a release workflow that publishes to Packagist on tag.
- `docs/psi-api-reference.md` — PSI API endpoint, parameter enums, response schema, quota behaviour, and Lighthouse scoring, verified against the live discovery document and a live API probe on 2026-08-01. Records two findings that change the plan: keyless requests now carry a daily quota of **zero**, so an API key is effectively required rather than merely recommended; and the `category` enum has gained `AGENTIC_BROWSING` while `PWA` is deprecated, confirming the parser must tolerate unknown and absent categories.
