# Common Goals

Common Goals is a free and open-source WordPress plugin for communities organized around a shared goal. It turns questions, problems and experiences into moderated contributions and living guides.

> Webs para usar, no solo para mirar.

## What is included

- Goal-oriented boards and contribution workflows.
- Moderation, roles, capabilities and audit events.
- Living guides with public, SEO-friendly pages.
- WordPress privacy export and erasure integration.
- REST endpoints and Gutenberg blocks.
- Optional AI-assisted flows with a provider API key configured locally in WordPress.
- PHPUnit, integration, property and browser smoke tests.

This repository contains the free Common Goals core only. It does not include commercial modules, credentials, customer data, production configuration, generated exports, internal roadmaps, private QA documentation or prototype material.

## Requirements

- WordPress 6.5 or later
- PHP 8.1 or later

## Installation

1. Download or clone this repository.
2. Copy the `common-goals` directory to `wp-content/plugins/`.
3. Activate **Common Goals** from the WordPress Plugins screen.
4. Create a goal and place `[common_goals_board goal_id="1"]` on a page.

The complete WordPress-oriented installation and usage notes are in [`common-goals/readme.txt`](common-goals/readme.txt).

## Development

Install PHP dependencies with Composer, then run the checks you need:

```bash
composer install
composer test
composer stan
composer lint
```

The browser smoke test uses `E2E_BASE_URL` when set; otherwise it targets a local WordPress environment. Never commit `.env` files, API keys, database dumps or production exports.

## Security

Please read [`SECURITY.md`](SECURITY.md) before reporting a vulnerability. Keep provider API keys in the WordPress options screen or environment-managed configuration; this repository intentionally contains no live credentials.

## Author and license

Common Goals is authored by [Mauricio Soto](https://heymauricio.com). The plugin is released under the [GNU General Public License v2.0 or later](common-goals/LICENSE).
