<?php
/**
 * Frontend router for individual living guide pages with SEO metadata.
 *
 * @package CommonGoals
 */

namespace CommonGoals\Frontend;

use CommonGoals\Database;
use CommonGoals\Domain;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Registers rewrite rules, query variables and template loading so that each
 * published guide has a stable, indexable URL.
 */
final class GuideRouter
{
    public const QUERY_VAR    = 'cg_guide_slug';
    public const CONTRIBUTION_VAR = 'cg_contribution_id';
    public const AUTHOR_VAR   = 'cg_user_id';
    public const REWRITE_TAG  = 'guias';
    public const CONTRIBUTION_TAG = 'aportes';
    public const AUTHOR_TAG   = 'autor';

    /**
     * Registers WordPress hooks.
     */
    public function register_hooks(): void
    {
        add_action('init', [$this, 'register_rewrite_rules']);
        add_filter('query_vars', [$this, 'register_query_vars']);
        add_action('template_redirect', [$this, 'maybe_load_guide']);
        add_action('wp_head', [$this, 'maybe_output_seo'], 5);
    }

    /**
     * Adds the rewrite rules for /guias/{slug} and /aportes/{id}.
     */
    public function register_rewrite_rules(): void
    {
        add_rewrite_rule(
            '^' . self::REWRITE_TAG . '/([^/]+)/?$',
            'index.php?' . self::QUERY_VAR . '=$matches[1]',
            'top'
        );

        add_rewrite_rule(
            '^' . self::CONTRIBUTION_TAG . '/([0-9]+)/?$',
            'index.php?' . self::CONTRIBUTION_VAR . '=$matches[1]',
            'top'
        );

        add_rewrite_rule(
            '^' . self::AUTHOR_TAG . '/([0-9]+)/?$',
            'index.php?' . self::AUTHOR_VAR . '=$matches[1]',
            'top'
        );

        // Flush once per plugin version so newly added rules (like /autor/) take
        // effect without requiring a manual visit to Settings > Permalinks.
        $flush_version = get_option('common_goals_rewrite_version', '');

        if ($flush_version !== COMMON_GOALS_VERSION) {
            flush_rewrite_rules();
            update_option('common_goals_rewrite_version', COMMON_GOALS_VERSION);
        }
    }

    /**
     * Makes the guide slug and contribution ID query variables available.
     *
     * @param string[] $vars Existing query variables.
     * @return string[]
     */
    public function register_query_vars(array $vars): array
    {
        $vars[] = self::QUERY_VAR;
        $vars[] = self::CONTRIBUTION_VAR;
        $vars[] = self::AUTHOR_VAR;

        return $vars;
    }

    /**
     * Returns the public URL for a published guide.
     */
    public static function guide_url(string $slug): string
    {
        return home_url('/' . self::REWRITE_TAG . '/' . $slug . '/');
    }

    /**
     * Returns the public URL for a contribution.
     */
    public static function contribution_url(int $contribution_id): string
    {
        return home_url('/' . self::CONTRIBUTION_TAG . '/' . $contribution_id . '/');
    }

    /**
     * Returns the public URL for an author's profile page.
     */
    public static function author_url(int $user_id): string
    {
        return home_url('/' . self::AUTHOR_TAG . '/' . $user_id . '/');
    }

    /**
     * Loads the individual guide or contribution template when a query var is present.
     */
    public function maybe_load_guide(): void
    {
        $guide_slug = get_query_var(self::QUERY_VAR);

        if ($guide_slug !== '') {
            $this->load_guide($guide_slug);
            return;
        }

        $contribution_id = absint(get_query_var(self::CONTRIBUTION_VAR));

        if ($contribution_id > 0) {
            $this->load_contribution($contribution_id);
            return;
        }

        $user_id = absint(get_query_var(self::AUTHOR_VAR));

        if ($user_id > 0) {
            $this->load_author($user_id);
        }
    }

    /**
     * Loads a guide by slug.
     */
    private function load_guide(string $slug): void
    {
        global $wpdb, $cg_current_guide;

        $slug      = sanitize_title($slug);
        $cache_key = 'cg_guide_' . md5($slug);
        $guide     = wp_cache_get($cache_key, 'common_goals');

        if ($guide === false) {
            $guides_table = Database::guides_table();
            $guide        = $wpdb->get_row(
                $wpdb->prepare("SELECT id, contribution_id, slug, title, content, status, created_at, updated_at FROM {$guides_table} WHERE slug = %s AND status = 'published' LIMIT 1", $slug)
            );

            wp_cache_set($cache_key, $guide, 'common_goals', HOUR_IN_SECONDS);
        }

        if (! $guide) {
            $this->render_404();
            return;
        }

        $cg_current_guide = $guide;

        $this->render_guide_page($guide);
    }

    /**
     * Loads a contribution by ID with its published responses.
     */
    private function load_contribution(int $contribution_id): void
    {
        global $wpdb;

        $contribution = Domain::get_visible_contribution($contribution_id);

        if (! $contribution) {
            $this->render_404();
            return;
        }

        if ($this->increment_views((int) $contribution->id)) {
            $contribution->views = (int) ($contribution->views ?? 0) + 1;
        }

        $responses_table = Database::responses_table();
        $responses       = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$responses_table} WHERE contribution_id = %d AND status = 'published' ORDER BY score DESC, created_at ASC LIMIT 200", $contribution_id)
        );

        $author_names = [];
        $user_ids     = array_unique(array_filter(array_map('intval', array_merge(wp_list_pluck($responses, 'user_id'), [$contribution->user_id]))));

        foreach ($user_ids as $uid) {
            if ($uid <= 0) {
                continue;
            }
            $user = get_userdata($uid);
            $author_names[$uid] = $user ? $user->display_name : __('Unknown user', 'common-goals');
        }

        $response_ids     = array_map('intval', wp_list_pluck($responses, 'id'));
        $response_votes   = Domain::get_user_votes('response', $response_ids);
        $contrib_votes    = Domain::get_user_votes('contribution', [$contribution_id]);

        add_filter('document_title_parts', static function (array $title) use ($contribution): array {
            $title['title'] = $contribution->title . ' - ' . get_bloginfo('name');
            return $title;
        });

        wp_enqueue_style('common-goals-board');
        wp_enqueue_script('common-goals-board');

        $this->render_contribution_page($contribution, $responses, $author_names, $contrib_votes, $response_votes);
    }

    /**
     * Outputs canonical, Open Graph and Schema.org metadata for the guide.
     */
    public function maybe_output_seo(): void
    {
        global $cg_current_guide;

        if (! $cg_current_guide) {
            return;
        }

        $guide = $cg_current_guide;
        $url   = self::guide_url($guide->slug);
        $desc  = wp_strip_all_tags(wp_trim_words($guide->content, 30));
        $title = $guide->title;

        echo "\n<!-- Common Goals SEO -->\n";
        echo '<link rel="canonical" href="' . esc_url($url) . '" />' . "\n";
        echo '<meta property="og:type" content="article" />' . "\n";
        echo '<meta property="og:url" content="' . esc_url($url) . '" />' . "\n";
        echo '<meta property="og:title" content="' . esc_attr($title) . '" />' . "\n";
        echo '<meta property="og:description" content="' . esc_attr($desc) . '" />' . "\n";
        echo '<meta name="twitter:card" content="summary" />' . "\n";

        $schema = [
            '@context'      => 'https://schema.org',
            '@type'         => 'Article',
            'headline'      => $title,
            'description'   => $desc,
            'datePublished' => gmdate('c', strtotime($guide->created_at)),
            'dateModified'  => gmdate('c', strtotime($guide->updated_at)),
            'url'           => $url,
        ];

        echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
    }

    /**
     * Renders the guide page within the theme template.
     */
    private function render_guide_page(object $guide): void
    {
        add_filter('document_title_parts', static function (array $title) use ($guide): array {
            $title['title'] = $guide->title . ' - ' . get_bloginfo('name');

            return $title;
        });

        get_header();

        echo '<main id="primary" class="site-content common-goals-guide-page">' . "\n";

        $content = '<article class="common-goals-guide-single">';
        $content .= '<header class="common-goals-guide-single__header">';
        $content .= '<p class="common-goals-board__eyebrow">' . esc_html__('Living Guide', 'common-goals') . '</p>';
        $content .= '<h1 class="common-goals-guide-single__title">' . esc_html($guide->title) . '</h1>';
        $content .= '<p class="common-goals-muted">' . esc_html(sprintf(
            /* translators: %s: guide last updated date. */
            __('Updated %s', 'common-goals'),
            mysql2date(get_option('date_format'), $guide->updated_at)
        )) . '</p>';
        $content .= '</header>';
        $content .= '<div class="common-goals-guide-single__content">' . wp_kses_post(wpautop($guide->content)) . '</div>';
        $content .= '<p><a href="' . esc_url(self::guide_url('')) . '">' . esc_html__('Back to guides', 'common-goals') . '</a></p>';
        $content .= '</article>';

        echo $content;

        echo '</main>' . "\n";

        get_footer();

        exit;
    }

    /**
     * Renders the contribution page within the theme template.
     *
     * @param object  $contribution    Contribution row.
     * @param array   $responses       Published responses (flat, with parent_id).
     * @param array   $author_names    Map of user_id to display name.
     * @param array   $contrib_votes   Current user's votes on the contribution.
     * @param array   $response_votes  Current user's votes keyed by response ID.
     */
    private function render_contribution_page(object $contribution, array $responses, array $author_names, array $contrib_votes, array $response_votes): void
    {
        $url  = self::contribution_url((int) $contribution->id);
        $desc = wp_strip_all_tags(wp_trim_words($contribution->body, 30));

        echo '<link rel="canonical" href="' . esc_url($url) . '" />' . "\n";
        echo '<meta property="og:type" content="article" />' . "\n";
        echo '<meta property="og:url" content="' . esc_url($url) . '" />' . "\n";
        echo '<meta property="og:title" content="' . esc_attr($contribution->title) . '" />' . "\n";
        echo '<meta property="og:description" content="' . esc_attr($desc) . '" />' . "\n";

        $cid          = (int) $contribution->id;
        $author_id    = (int) $contribution->user_id;
        $author_name  = $author_names[$author_id] ?? __('Guest', 'common-goals');
        $user_vote    = (int) ($contrib_votes[$cid] ?? 0);
        $author_html  = $author_id > 0
            ? '<a class="common-goals-author-link" href="' . esc_url(self::author_url($author_id)) . '">' . esc_html($author_name) . '</a>'
            : esc_html($author_name);

        // Build threaded response tree.
        $children_by_parent = [];
        $roots              = [];

        foreach ($responses as $r) {
            $pid = (int) ($r->parent_id ?? 0);

            if ($pid > 0) {
                $children_by_parent[$pid][] = $r;
            } else {
                $roots[] = $r;
            }
        }

        get_header();

        echo '<main id="primary" class="site-content common-goals-contribution-page">' . "\n";
        echo '<article class="common-goals-contribution-single">';

        echo '<div class="common-goals-contribution-single__head">';
        $this->render_vote_widget_html('contribution', $cid, (int) ($contribution->score ?? 0), $user_vote);
        echo '<div>';
        echo '<h1>' . esc_html($contribution->title) . '</h1>';
        echo '<p class="common-goals-contribution__author">' . $author_html . ' · ' . esc_html(mysql2date(get_option('date_format'), $contribution->created_at) . ' · ' . sprintf(__('%s views', 'common-goals'), Domain::format_count((int) ($contribution->views ?? 0)))) . '</p>';
        echo '</div>';
        echo '</div>';

        echo '<div class="common-goals-contribution__body">' . Domain::render_content($contribution->body) . '</div>';

        echo '<section class="common-goals-responses-single">';
        echo '<h2>' . esc_html(sprintf(_n('%d comment', '%d comments', count($responses), 'common-goals'), count($responses))) . '</h2>';

        if (! empty($roots)) {
            $this->render_response_tree_html($children_by_parent, $roots, $author_names, $response_votes, $cid, 0);
        } else {
            echo '<p class="common-goals-muted">' . esc_html__('No responses yet.', 'common-goals') . '</p>';
        }

        if (is_user_logged_in()) {
            echo '<form class="common-goals-response-form common-goals-response-form--top" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="common_goals_create_response" />';
            echo '<input type="hidden" name="contribution_id" value="' . esc_attr((string) $cid) . '" />';
            echo '<input type="hidden" name="parent_id" value="0" />';
            wp_nonce_field('common_goals_create_response');
            echo '<label>' . esc_html__('Add a response', 'common-goals') . '<textarea name="response_body" rows="3" maxlength="' . esc_attr((string) Domain::MAX_RESPONSE_LENGTH) . '" required></textarea></label>';
            echo '<button type="submit">' . esc_html__('Send response', 'common-goals') . '</button>';
            echo '</form>';
        } else {
            echo '<p class="common-goals-login-hint">' . wp_kses_post(sprintf(__('<a href="%s">Log in</a> to add a response.', 'common-goals'), esc_url(wp_login_url($url)))) . '</p>';
        }

        echo '</section>';

        // Reply template cloned by JS.
        echo '<template id="cg-reply-template">';
        echo '<form class="common-goals-response-form common-goals-response-form--reply" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="common_goals_create_response" />';
        echo '<input type="hidden" name="contribution_id" value="" />';
        echo '<input type="hidden" name="parent_id" value="" />';
        wp_nonce_field('common_goals_create_response');
        echo '<label>' . esc_html__('Your reply', 'common-goals') . '<textarea name="response_body" rows="3" maxlength="' . esc_attr((string) Domain::MAX_RESPONSE_LENGTH) . '" required></textarea></label>';
        echo '<div class="common-goals-response-form__actions"><button type="submit">' . esc_html__('Send reply', 'common-goals') . '</button><button type="button" class="common-goals-reply-cancel common-goals-btn-ghost">' . esc_html__('Cancel', 'common-goals') . '</button></div>';
        echo '</form>';
        echo '</template>';

        echo '<p class="common-goals-back"><a href="javascript:history.back()">&larr; ' . esc_html__('Back', 'common-goals') . '</a></p>';
        echo '</article>';
        echo '</main>' . "\n";

        get_footer();
        exit;
    }

    /**
     * Renders the vote widget HTML for the single contribution page.
     */
    private function render_vote_widget_html(string $object_type, int $object_id, int $score, int $user_vote): void
    {
        $up_active   = $user_vote === 1 ? ' is-active' : '';
        $down_active = $user_vote === -1 ? ' is-active' : '';

        echo '<div class="common-goals-vote" data-object-type="' . esc_attr($object_type) . '" data-object-id="' . esc_attr((string) $object_id) . '">';
        echo '<button type="button" class="common-goals-vote__btn common-goals-vote__up' . esc_attr($up_active) . '" data-value="1" aria-label="' . esc_attr__('Upvote', 'common-goals') . '">&#9650;</button>';
        echo '<span class="common-goals-vote__score">' . esc_html((string) $score) . '</span>';
        echo '<button type="button" class="common-goals-vote__btn common-goals-vote__down' . esc_attr($down_active) . '" data-value="-1" aria-label="' . esc_attr__('Downvote', 'common-goals') . '">&#9660;</button>';
        echo '</div>';
    }

    /**
     * Recursively renders threaded responses as HTML for the single page.
     *
     * @param array $children_by_parent Responses grouped by parent_id.
     * @param array $roots              Top-level responses.
     * @param array $author_names       Map of user_id to display name.
     * @param array $response_votes     Current user's votes keyed by response ID.
     * @param int   $contribution_id    Owning contribution ID.
     * @param int   $depth              Current nesting depth.
     */
    private function render_response_tree_html(array $children_by_parent, array $roots, array $author_names, array $response_votes, int $contribution_id, int $depth): void
    {
        if ($depth >= 6) {
            return;
        }

        echo '<ul class="common-goals-thread' . ($depth > 0 ? ' common-goals-thread--child' : '') . '">';

        foreach ($roots as $response) {
            $rid         = (int) $response->id;
            $r_author    = $author_names[(int) $response->user_id] ?? __('Guest', 'common-goals');
            $children    = $children_by_parent[$rid] ?? [];

            echo '<li class="common-goals-response" data-response-id="' . esc_attr((string) $rid) . '">';
            echo '<div class="common-goals-response__row">';
            $this->render_vote_widget_html('response', $rid, (int) ($response->score ?? 0), (int) ($response_votes[$rid] ?? 0));
            echo '<div class="common-goals-response__body">';
            echo '<p class="common-goals-contribution__author">' . esc_html($r_author . ' · ' . mysql2date(get_option('date_format'), $response->created_at)) . '</p>';
            echo '<div>' . Domain::render_content($response->body) . '</div>';

            if (is_user_logged_in()) {
                echo '<button type="button" class="common-goals-reply-toggle common-goals-btn-ghost" data-contribution-id="' . esc_attr((string) $contribution_id) . '" data-parent-id="' . esc_attr((string) $rid) . '">' . esc_html__('Reply', 'common-goals') . '</button>';
            }

            echo '</div>';
            echo '</div>';

            if (! empty($children)) {
                $this->render_response_tree_html($children_by_parent, $children, $author_names, $response_votes, $contribution_id, $depth + 1);
            }

            echo '</li>';
        }

        echo '</ul>';
    }

    /**
     * Renders a 404 response for missing or unpublished guides.
     */
    private function render_404(): void
    {
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        nocache_headers();
    }

    /**
     * Increments a contribution's view counter at most once per visitor per
     * hour, so refreshing a thread does not inflate its popularity.
     *
     * @return bool True when the counter was actually incremented.
     */
    private function increment_views(int $contribution_id): bool
    {
        global $wpdb;

        $visitor = is_user_logged_in() ? 'u' . get_current_user_id() : 'i' . md5($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $key     = 'cg_viewed_' . $visitor . '_c' . $contribution_id;

        if (get_transient($key)) {
            return false;
        }

        $table = Database::contributions_table();
        $wpdb->query($wpdb->prepare("UPDATE {$table} SET views = views + 1 WHERE id = %d", $contribution_id));

        set_transient($key, 1, HOUR_IN_SECONDS);

        return true;
    }

    /**
     * Loads a user's profile with their public contributions.
     */
    private function load_author(int $user_id): void
    {
        global $wpdb;

        $user = get_userdata($user_id);

        if (! $user) {
            $this->render_404();
            return;
        }

        $contributions_table = Database::contributions_table();
        $responses_table     = Database::responses_table();
        $bookmarks_table     = Database::bookmarks_table();
        $statuses            = Domain::PUBLIC_STATUSES;
        $placeholders        = implode(',', array_fill(0, count($statuses), '%s'));
        $params              = array_merge([$user_id], $statuses);

        // "Saved" view: only the profile owner can see their own bookmarks.
        $view_saved = is_user_logged_in() && get_current_user_id() === $user_id && (sanitize_key($_GET['view'] ?? '') === 'saved');

        if ($view_saved) {
            $contributions = $wpdb->get_results(
                $wpdb->prepare("SELECT c.* FROM {$contributions_table} c INNER JOIN {$bookmarks_table} b ON b.contribution_id = c.id WHERE b.user_id = %d AND c.status IN ({$placeholders}) ORDER BY b.id DESC LIMIT 50", ...$params)
            );
        } else {
            $contributions = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM {$contributions_table} WHERE user_id = %d AND status IN ({$placeholders}) ORDER BY is_sticky DESC, (GREATEST(score, 0) * 3) + (LOG10(views + 1) * 6) DESC, created_at DESC LIMIT 50", ...$params)
            );
        }

        $stats = $wpdb->get_row(
            $wpdb->prepare("SELECT COUNT(*) AS threads, COALESCE(SUM(score), 0) AS score, COALESCE(SUM(views), 0) AS views FROM {$contributions_table} WHERE user_id = %d AND status IN ({$placeholders})", ...$params)
        );

        $ids           = array_map('absint', wp_list_pluck($contributions, 'id'));
        $user_votes    = Domain::get_user_votes('contribution', $ids);
        $comment_counts = [];

        if (! empty($ids)) {
            $ids_sql = implode(',', $ids);

            foreach ($wpdb->get_results("SELECT contribution_id, COUNT(*) AS c FROM {$responses_table} WHERE contribution_id IN ({$ids_sql}) AND status = 'published' GROUP BY contribution_id") as $row) {
                $comment_counts[(int) $row->contribution_id] = (int) $row->c;
            }
        }

        add_filter('document_title_parts', static function (array $title) use ($user): array {
            $title['title'] = $user->display_name . ' - ' . get_bloginfo('name');
            return $title;
        });

        wp_enqueue_style('common-goals-board');
        wp_enqueue_script('common-goals-board');

        $this->render_author_page($user, $contributions, $user_votes, $comment_counts, $stats, $view_saved);
    }

    /**
     * Renders the author profile page with their public contributions.
     *
     * @param \WP_User  $user            The author.
     * @param array     $contributions   Public contributions by the author.
     * @param array     $user_votes      Current viewer's votes keyed by contribution ID.
     * @param array     $comment_counts  Map of contribution_id => published response count.
     * @param object    $stats           Aggregate stats (threads, score, views).
     * @param bool      $view_saved      Whether the saved-threads tab is active.
     */
    private function render_author_page(\WP_User $user, array $contributions, array $user_votes, array $comment_counts, object $stats, bool $view_saved = false): void
    {
        $type_labels = [
            'question'  => __('Question', 'common-goals'),
            'problem'   => __('Problem', 'common-goals'),
            'experience' => __('Experience', 'common-goals'),
            'resource'  => __('Resource', 'common-goals'),
        ];

        get_header();

        echo '<main id="primary" class="site-content common-goals-author-page">' . "\n";
        echo '<div class="common-goals-author">';

        echo '<header class="common-goals-author__header">';
        echo '<div class="common-goals-author__avatar">' . get_avatar($user->ID, 96, '', '', ['class' => 'common-goals-author__avatar-img']) . '</div>';
        echo '<div class="common-goals-author__info">';
        echo '<h1 class="common-goals-author__name">' . esc_html($user->display_name) . '</h1>';
        echo '<p class="common-goals-author__meta">' . esc_html(sprintf(
            __('Member since %s', 'common-goals'),
            mysql2date(get_option('date_format'), $user->user_registered)
        )) . '</p>';
        echo '<ul class="common-goals-author__stats">';
        echo '<li><strong>' . esc_html(number_format_i18n((int) $stats->threads)) . '</strong> ' . esc_html(_n('thread', 'threads', (int) $stats->threads, 'common-goals')) . '</li>';
        echo '<li><strong>' . esc_html(Domain::format_count((int) $stats->score)) . '</strong> ' . esc_html__('karma', 'common-goals') . '</li>';
        echo '<li><strong>' . esc_html(Domain::format_count((int) $stats->views)) . '</strong> ' . esc_html__('views', 'common-goals') . '</li>';
        echo '</ul>';
        echo '</div>';
        echo '</header>';

        $is_own = is_user_logged_in() && get_current_user_id() === (int) $user->ID;

        if ($is_own) {
            $base = self::author_url((int) $user->ID);
            echo '<div class="common-goals-author__tabs">';
            echo '<a class="common-goals-sort-tab' . ($view_saved ? '' : ' is-active') . '" href="' . esc_url($base) . '">' . esc_html__('Threads', 'common-goals') . '</a>';
            echo '<a class="common-goals-sort-tab' . ($view_saved ? ' is-active' : '') . '" href="' . esc_url(add_query_arg('view', 'saved', $base)) . '">' . esc_html__('Saved', 'common-goals') . '</a>';
            echo '</div>';
        }

        if (! empty($contributions)) {
            echo '<h2 class="common-goals-author__section">' . esc_html($view_saved ? __('Saved threads', 'common-goals') : __('Threads', 'common-goals')) . '</h2>';
            echo '<ul class="common-goals-threadlist">';

            foreach ($contributions as $contribution) {
                $cid        = (int) $contribution->id;
                $url        = self::contribution_url($cid);
                $comments   = (int) ($comment_counts[$cid] ?? 0);
                $snippet    = wp_trim_words(wp_strip_all_tags($contribution->body), 24);

                echo '<li class="common-goals-contribution common-goals-contribution--' . esc_attr($contribution->type) . (! empty($contribution->is_sticky) ? ' common-goals-contribution--pinned' : '') . '">';
                $this->render_vote_widget_html('contribution', $cid, (int) ($contribution->score ?? 0), (int) ($user_votes[$cid] ?? 0));

                echo '<div class="common-goals-contribution__main">';
                echo '<div class="common-goals-contribution__badges">';
                if (! empty($contribution->is_sticky)) {
                    echo '<span class="common-goals-badge common-goals-badge--pinned">' . esc_html__('Pinned', 'common-goals') . '</span>';
                }
                echo '<span class="common-goals-badge common-goals-badge--' . esc_attr($contribution->type) . '">' . esc_html($type_labels[$contribution->type] ?? ucfirst($contribution->type)) . '</span>';
                echo '<span class="common-goals-badge common-goals-badge--' . esc_attr($contribution->status) . '">' . esc_html(ucfirst($contribution->status)) . '</span>';
                echo '</div>';

                echo '<h4 class="common-goals-contribution__title"><a href="' . esc_url($url) . '">' . esc_html($contribution->title) . '</a></h4>';
                echo '<p class="common-goals-contribution__author">' . esc_html(mysql2date(get_option('date_format'), $contribution->created_at)) . '</p>';

                if ($snippet !== '') {
                    echo '<p class="common-goals-contribution__snippet">' . esc_html($snippet) . '</p>';
                }

                echo '<div class="common-goals-contribution__footer">';
                echo '<a class="common-goals-contribution__comments" href="' . esc_url($url) . '">' . esc_html(sprintf(_n('%s comment', '%s comments', $comments, 'common-goals'), number_format_i18n($comments))) . '</a>';
                echo '<span class="common-goals-contribution__views">' . esc_html(sprintf(__('%s views', 'common-goals'), Domain::format_count((int) ($contribution->views ?? 0)))) . '</span>';
                echo '</div>';

                echo '</div>';
                echo '</li>';
            }

            echo '</ul>';
        } else {
            echo '<p class="common-goals-empty">' . esc_html__('This author has not published any threads yet.', 'common-goals') . '</p>';
        }

        echo '<p class="common-goals-back"><a href="javascript:history.back()">&larr; ' . esc_html__('Back', 'common-goals') . '</a></p>';
        echo '</div>';
        echo '</main>' . "\n";

        get_footer();
        exit;
    }
}
