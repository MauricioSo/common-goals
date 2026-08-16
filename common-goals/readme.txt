=== Common Goals ===
Contributors: mauriciosoto
Tags: community, goals, collaboration, guides, knowledge-base, q&a
Requires at least: 6.5
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create goal-oriented community boards where questions, problems and experiences become organized knowledge.

== Description ==

Common Goals helps WordPress sites build communities organized around a shared goal instead of a general feed. Members contribute questions, problems, experiences and resources. Moderators review submissions, convert valuable content into living guides, and keep the community focused on what matters.

= How it differs from a forum =

A forum organizes by topic. Common Goals organizes by **goal**. Every contribution is evaluated by how it advances the community mission, not just by engagement. Contributions transform into living guides with their own SEO-friendly pages.

= Key features =

* **Multi-community goal boards** — create communities, assign goals to each community, and scope boards by community.
* **Community-scoped permissions** — community admins and moderators can work only inside their assigned communities.
* **Moderation queue** — guest submissions enter `pending`; registered users publish directly. Rate limiting and honeypot protect all public forms.
* **Living guides** — convert contributions into published guides with individual URLs at `/guias/{slug}`, complete with canonical tags, Open Graph and Schema.org structured data.
* **Draft-review-publish workflow** — guides pass through draft, review and published states.
* **Granular capabilities** — dedicated `manage_common_goals`, `moderate_common_goals`, `publish_common_goals_guides` and `view_common_goals_events` capabilities with a built-in moderator role.
* **Bulk moderation** — approve, hide or mark spam across multiple contributions at once.
* **Privacy integration** — WordPress personal data export and erasure for all community content. User attribution is anonymized on account deletion.
* **Audit log** — every creation, status change and guide conversion is recorded for accountability.
* **JSON export** — download all community data with a versioned schema manifest.
* **Site Health** — database integrity checks and schema version validation in WordPress Site Health screen.
* **Dark theme** — CSS adapts to `prefers-color-scheme: dark`.
* **Accessibility** — keyboard-visible focus outlines, `aria-live` notices, responsive layout and RTL support.

= Privacy =

Common Goals stores user IDs alongside contributions, responses and events. Guest submissions are attributed to user ID 0 (anonymous). The plugin does not collect IP addresses, cookies, browser fingerprints or third-party tracking data.

Data export and erasure requests through WordPress Tools include all community content. Upon erasure, attribution is anonymized (user ID set to 0) while the content is retained for community continuity.

= Capabilities and roles =

| Capability | Description |
|------------|-------------|
| `manage_common_goals` | Create and edit community goals. |
| `moderate_common_goals` | Review, approve, hide or mark spam on contributions and responses. |
| `publish_common_goals_guides` | Create, edit and publish living guides. |
| `view_common_goals_events` | View the audit event log. |

All four capabilities are granted to Administrators on activation. A `common_goals_moderator` role is also created with moderation and event-viewing capabilities. Editors receive moderation capabilities. Community-specific `admin`, `moderator` and `member` assignments are managed under **Common Goals > Communities** and limit non-global users to their assigned communities.

== Installation ==

= Automatic =

1. Go to Plugins > Add New in your WordPress admin.
2. Click Upload Plugin and choose the downloaded ZIP file.
3. Click Install Now, then Activate.

= Manual =

1. Unzip the downloaded file.
2. Upload the `common-goals` folder to `/wp-content/plugins/`.
3. Activate the plugin through the Plugins menu.

= After activation =

1. Go to **Common Goals** in the admin sidebar.
2. Optionally create additional communities under **Common Goals > Communities**.
3. Click **Create Community Goal** and fill in the community, title, description, who benefits, allowed contribution types and alignment rules.
4. Copy the generated shortcode `[common_goals_board goal_id="1" community_id="1"]` and paste it into any page.
5. Optionally add `[common_goals_guides limit="20"]` to display published guides.
6. Review pending submissions under **Common Goals > Contributions**.

== Usage ==

= Shortcodes =

`[common_goals_board goal_id="1" community_id="1"]`

Displays the community board for a specific goal and community. Includes the contribution form, filters (type, status, topic, search) and paginated contribution list. If `community_id` is omitted, the board uses the specified goal regardless of community.

`[common_goals_guides limit="20"]`

Lists published living guides with links to their individual SEO pages. The `limit` attribute accepts 1-50.

= Guide pages =

Published guides are accessible at `yoursite.com/guias/{slug}/`. Each page includes canonical URL, Open Graph metadata and Schema.org JSON-LD for search engines. If you change the guide slug, the URL updates and the old slug returns a 404.

= Moderation =

Guest contributions enter the `pending` queue. Registered users' contributions appear immediately. Responses follow the same pattern. Moderators can:

* Change individual contribution or response status.
* Use bulk actions to approve, hide or mark spam across selected contributions.
* Convert a contribution into a draft guide, then review and publish it.

= Export =

Go to **Common Goals > Contributions** and scroll to the Export section. Click **Download JSON export** to receive a structured JSON file with all goals, contributions, responses, guides, events and a manifest describing relationships and allowed values.

== Frequently Asked Questions ==

= Can guests post contributions? =

Yes. Guest submissions enter a `pending` queue and require moderator approval before appearing publicly. Registered users publish directly. You can require registration by deactivating the guest submission hooks if needed.

= What happens to data on uninstall? =

By default, all community data is preserved to avoid accidental loss. To enable full cleanup on uninstall, set the `common_goals_cleanup_on_uninstall` option to `true` via WP-CLI or custom code before uninstalling:

`wp option update common_goals_cleanup_on_uninstall 1`

This removes all custom tables, the schema version option and registered capabilities.

= Is Multisite supported? =

Not yet. The plugin should be activated on a single site. Multisite support will be added in a future release.

= How does rate limiting work? =

Each user (or IP hash for guests) can submit up to 5 contributions or responses within a 5-minute window. When the limit is exceeded, a friendly error message is shown.

= Can I customize the guide URL prefix? =

The default rewrite tag is `guias`. This can be modified by filtering the rewrite rules, but changing it requires flushing permalinks after activation.

= How long are audit events retained? =

By default, events older than 90 days are automatically deleted by a daily WP-Cron job. The retention period is configurable via the `common_goals_event_retention_days` option.

= Does the plugin work with page caching? =

Guide pages are cached via WordPress object cache for 1 hour. The board shortcode is dynamic and should be excluded from full-page caching or loaded via AJAX if your cache plugin does not support shortcodes.

= What themes does it work with? =

Common Goals uses CSS custom properties that adapt to light and dark themes. It does not override your theme's text color or typography. The plugin has been tested with default WordPress themes.

== Screenshots ==

1. **Community goal board** — The public board with contribution form, filters and paginated list.
2. **Goal management** — Admin screen for creating and editing goals with contribution type checkboxes.
3. **Moderation queue** — Contributions admin page with bulk actions and response moderation.
4. **Guide page** — Individual SEO-friendly guide page with structured data.
5. **Site Health** — Database integrity and schema version checks.

== Privacy ==

* **Data stored:** user IDs, contribution content (title, body, type, topic, status), response content, guide content, audit events.
* **Data not stored:** IP addresses, cookies, browser fingerprints, third-party tracking.
* **Export:** WordPress personal data exporter includes all contributions and responses linked to a user.
* **Erasure:** User attribution is anonymized (user ID set to 0); content is retained.
* **Policy text:** Suggested privacy policy text is registered via `wp_add_privacy_policy_content()`.

== Limitations ==

* Admin lists use fixed limits and lightweight custom screens instead of `WP_List_Table` pagination.
* No Multisite support yet.
* Admin lists use a fixed limit of 50 items without `WP_List_Table` pagination.
* No built-in sitemap integration for guide pages (WordPress core sitemaps do not cover custom rewrite rules).
* No email notifications for moderators or authors (planned).

== Troubleshooting ==

= Guide pages show 404 =

After installing or updating, go to **Settings > Permalinks** and click **Save Changes** to flush rewrite rules.

= "No community goal has been created yet" =

Create at least one goal under **Common Goals** in the admin. If a goal exists but is inactive, it will not appear in the board. Set its status to Active.

= Tables are missing after a server migration =

Go to **Tools > Site Health** and look for the Common Goals tests. If tables are reported as missing, deactivate and reactivate the plugin to run the migration runner.

= Contributions from guests are not appearing =

Guest submissions enter `pending` status. Review and approve them under **Common Goals > Contributions**.

= The event log is growing too large =

The default retention is 90 days. You can reduce it:

`wp option update common_goals_event_retention_days 30`

Then run the cleanup manually:

`wp cron event run common_goals_cleanup_events`

== Security =

See [SECURITY.md](https://github.com/MauricioSo/common-goals/blob/main/SECURITY.md) for the vulnerability reporting policy.

== Upgrade Notice ==

= 1.0.1 =

Security and hardening release. Public REST endpoints no longer expose internal `user_id` or `created_by` fields, the REST contribution endpoint now enforces the same rate limit, spam checks and length validation as the public forms, the contribution delete flow is transactional, and admin menus are only shown to users who can act on them.

= 1.0.0 =

Stable release. Includes multi-community boards, community-scoped permissions, REST endpoints, Gutenberg blocks, privacy/export tooling, Site Health checks and production packaging.

= 0.5.0 =

First beta release. Includes security hardening, privacy integration, guide SEO pages, granular capabilities, bulk moderation, JSON export and Site Health checks. Review the moderation queue after upgrading, as existing guest contributions remain in their original status.

== Changelog ==

= 1.0.1 =
* Security: REST API `GET /contributions`, `GET /contributions/{id}`, `GET /goals/{id}` and `GET /guides/{slug}` no longer leak internal columns (`user_id`, `created_by`, `alignment_rules`). Public endpoints return only safe, public fields.
* Security: `POST /contributions` now enforces the same abuse protections as the public form: rate limiting, spam check, and server-side length validation. Previously the REST write path bypassed all anti-abuse layers.
* Security: admin menus are registered only for users who can act on them (global capability or community-scoped role), via new `Domain::current_user_can_access_*` helpers. Subscribers without any community assignment no longer see Common Goals menus.
* Integrity: contribution deletion by the author is now fully transactional. If the cascade delete of responses fails, the whole operation rolls back instead of leaving orphaned data.
* Hardening: the configured `common_goals_rate_limit_max` setting now takes effect (previously the constant was used and the settings field was inert).
* Hardening: removed a non-functional Akismet integration that checked a non-existent function. Spam detection now relies on a lightweight link heuristic plus the documented `common_goals_spam_check` filter for Akismet, reCAPTCHA and third-party providers.
* Cleanup: removed duplicated `is_user_logged_in()` calls and redundant branches in the rate limit helper.
* Tests: added coverage for the configurable rate limit, the new access helpers and the spam heuristic (82 tests, 128 assertions).

= 1.0.0 =
* Release: promoted Common Goals to stable release after RC hardening.
* i18n: resolved placeholder warnings in notification and guide strings.
* Packaging: final release build generated with tests, static analysis and translation catalog passing.

= 0.11.0 =
* Permissions: added community-scoped authorization helpers for admin, moderator and member roles.
* Admin: goals, contributions, guides, events and communities now limit non-global users to authorized communities.
* Admin: community admins can manage members in their communities without global plugin management access.
* Hardening: added release checks for scoped permissions and regenerated translation catalog.

= 0.10.0 =
* Multi-community: admin contribution, guide and event screens can be filtered by community.
* Multi-community: JSON exports, privacy exports/erasers, Site Health and uninstall now include communities and members.
* REST API: added `/communities` and `community_id` support for goals, contributions and guides.
* Blocks: board block now supports a `community_id` attribute.
* Audit: community context is recorded for goal, contribution, response and guide events.

= 0.9.0 =
* Communities: added `cg_communities` and `cg_community_members` tables.
* Migration: existing goals receive a `community_id` and a default community is created automatically.
* Admin: added **Common Goals > Communities** for community and member management.
* Admin: goals can be assigned to communities and shortcodes include `community_id`.
* Frontend: board shortcode accepts `community_id` and scopes goal lookup by community.

= 0.8.0 =
* Extension: public `do_action` and `apply_filters` hooks on goal, guide, contribution and response lifecycle.
* REST API: `GET /wp-json/common-goals/v1/goals`, `/contributions`, `/guides` with filters and pagination.
* REST API: `GET /goals/{id}`, `/contributions/{id}`, `/guides/{slug}` for individual resources.
* REST API: `POST /contributions` with auth and guest policy enforcement.
* Blocks: `common-goals/board` Gutenberg block with Inspector Controls for goal_id.
* Blocks: `common-goals/guides` Gutenberg block with limit control.
* Blocks: both blocks use server-side render, falling back to shortcode rendering.
* Async: `TaskRunner` with optional ActionScheduler integration; falls back to direct execution.
* Filters: `common_goals_allowed_types` filter on Domain::allowed_types_for_goal.

= 0.7.0 =
* Notifications: moderators receive email when guest submissions enter pending queue.
* Notifications: authors receive email when their contribution is approved or gets a response.
* Notifications: all recipients, subjects and bodies are filterable via `common_goals_notification_*` hooks.
* Contribution pages: individual contribution URLs at `/aportes/{id}` with published responses.
* Author tools: logged-in users can edit their own contributions inline from the board.
* Author tools: logged-in users can delete their own contributions with confirmation (responses cascade-deleted).
* UX: JavaScript character counter on all `maxlength` fields.
* UX: client-side form validation before submit with inline error feedback.
* Public hooks: `do_action` on contribution created, response created and status changed.

= 0.6.0 =
* Settings: admin settings page under Common Goals > Settings with Settings API.
* Settings: guest posting toggle, honeypot toggle, rate limit, retention days, cleanup on uninstall.
* Templates: theme override system via `TemplateLoader` — copy templates to `your-theme/common-goals/`.
* Sitemap: published guides appear in `wp-sitemap.xml` via custom sitemap provider.
* Anti-spam: extensible `common_goals_spam_check` filter for Akismet, reCAPTCHA and third-party integrations.
* Anti-spam: honeypot can be disabled via settings.
* Anti-spam: guest posting can be disabled entirely via settings.

= 0.5.0 =
* Accessibility: `:focus-visible` outlines on all interactive elements.
* Accessibility: CSS custom properties adapt to light and dark themes via `prefers-color-scheme`.
* Accessibility: responsive layout with mobile breakpoints and RTL honeypot positioning.
* i18n: `Domain Path: /languages` declared; `load_plugin_textdomain()` called; POT catalog generated.
* Operations: WordPress Site Health tests for database tables and schema version.
* Operations: Site Health debug info showing version, counts and retention.
* Operations: WP-Cron event log cleanup with configurable 90-day retention.
* Performance: guide lookups cached via `wp_cache` with 1-hour TTL and cache invalidation.
* Performance: guide query selects only needed columns instead of `SELECT *`.

= 0.4.0 =
* SEO: individual guide pages at `/guias/{slug}` with rewrite rules and template loading.
* SEO: canonical URL, Open Graph tags and Schema.org JSON-LD on guide pages.
* Workflow: guides created as `draft`; new `review` status for draft-review-publish flow.
* Board: topic filter, full-text search, author display name and creation date.
* Board: pagination with numbered links (30 per page); response loading limited to 300.

= 0.3.0 =
* Capabilities: granular permissions replace `manage_options` throughout.
* Capabilities: dedicated `common_goals_moderator` role.
* Moderation: validated status transitions; response status management; bulk actions.
* Privacy: WordPress personal data exporter and eraser; anonymization on user deletion.
* Privacy: suggested privacy policy text via `wp_add_privacy_policy_content`.
* Export: versioned JSON export with schema manifest and relationship map.
* Uninstall: optional full data cleanup controlled by a site option.

= 0.2.0 =
* Security: guest submissions enter `pending` queue instead of publishing immediately.
* Security: rate limiting and honeypot protection on public forms.
* Security: server-side length validation for all public fields.
* Integrity: validate goals and contributions before accepting submissions.
* Integrity: apply per-goal `allowed_contribution_types` configuration.
* Integrity: all database writes check for errors; guide creation wrapped in transaction.
* Migration: versioned, idempotent schema migration runner with compound indices.
* Fix: positive status lists replace `!= 'hidden'`; inactive goals no longer exposed.
* UX: frontend notices with `aria-live`; admin notices distinguish success vs error.
* i18n: status and type labels are translatable in all templates.
* Testing: PHPUnit suite (18 tests) and GitHub Actions CI for PHP 8.1-8.4.

= 0.1.0 =
Initial MVP scaffold.
