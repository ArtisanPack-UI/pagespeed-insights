# ArtisanPack UI PageSpeedInsights Changelog

## [Unreleased]

### Added

- `Contracts\ApiKeyRepository` — the storage contract for the PageSpeed Insights API key (`getApiKey()`, `save()`, `isConfigured()`). Because keyless PSI requests carry a daily quota of zero, `isConfigured() === false` is documented as a hard blocker callers should short-circuit on rather than a soft warning.
- `Configuration\ConfigDriver` — reads `PAGESPEED_API_KEY` / `config( 'pagespeed-insights.api_key' )`. Read-only; `save()` throws a `RuntimeException` naming the drivers that can persist a key.
- `Configuration\DatabaseDriver` — stores the key in the new `pagespeed_configurations` table, encrypted with the framework `Encrypter`, with a per-request cache and a `flush()` escape hatch. An undecryptable value logs a warning and degrades to unconfigured instead of throwing.
- `Configuration\CmsSettingsDriver` — stores the key via the CMS framework's Settings module under `artisanpack_pagespeed_api_key`. Encryption lives in the sanitize callback registered by the service provider, so both the driver and the Settings UI write the same ciphertext shape.
- `config/pagespeed-insights.php` with the `api_key` and `driver` keys, published under the `pagespeed-insights-config` tag. The comments state that an API key is required and that there is no supported keyless mode.
- `pagespeed_configurations` migration, loaded automatically and publishable under the `pagespeed-insights-migrations` tag.
- Driver selection via `PAGESPEED_CONFIG_DRIVER` (default `config`), bound in the service provider so the configured driver is re-read on each resolve, plus `PageSpeedInsights::config()` for reaching the active repository.
- Package scaffold generated from `package-blueprint`, following `artisanpack-ui/google-search-console` as the closest sibling reference.
- `composer.json` — `ArtisanPackUI\PageSpeedInsights` PSR-4 namespace, MIT license, `php ^8.2`, `illuminate/support ^10.0|^11.0|^12.0|^13.0`, and required `artisanpack-ui/core`, `artisanpack-ui/hooks`, `artisanpack-ui/google`, and `guzzlehttp/guzzle` dependencies. `suggest` entries for `livewire/livewire`, `artisanpack-ui/cms-framework`, and `artisanpack-ui/livewire-ui-components`.
- `PageSpeedInsightsServiceProvider` with the `pagespeed-insights` singleton binding, auto-discovered by Laravel.
- `PageSpeedInsights` facade and `pageSpeedInsights()` helper, both resolving the same container instance.
- Code style toolchain — `.php-cs-fixer.dist.php` and `phpcs.xml`, with `lint`, `fix`, `cs`, `cs:fix`, and `test` composer scripts.
- Pest + Orchestra Testbench setup with a base `TestCase` and coverage of the boot, binding, facade, and helper paths.
- CI workflow running lint plus the test matrix across PHP 8.2–8.4 and Laravel 12–13, and a release workflow that publishes to Packagist on tag.
- `docs/psi-api-reference.md` — PSI API endpoint, parameter enums, response schema, quota behaviour, and Lighthouse scoring, verified against the live discovery document and a live API probe on 2026-08-01. Records two findings that change the plan: keyless requests now carry a daily quota of **zero**, so an API key is effectively required rather than merely recommended; and the `category` enum has gained `AGENTIC_BROWSING` while `PWA` is deprecated, confirming the parser must tolerate unknown and absent categories.
