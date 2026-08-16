<?php
/**
 * Public board template.
 *
 * Expected variables:
 *
 * @var object      $goal          Active community goal.
 * @var array       $contributions Recent contributions for the goal.
 * @var array       $allowed_types Accepted contribution types.
 * @var array       $responses_by_contribution_id Responses grouped by contribution ID.
 * @var string      $selected_status Current status filter.
 * @var string      $selected_type   Current type filter.
 * @var array       $visible_statuses Publicly visible statuses.
 * @var string      $current_notice  Notice message (may be empty).
 * @var string      $selected_topic  Current topic filter.
 * @var string      $search_term     Current search value.
 * @var string      $sort            Current sort ('new' or 'top').
 * @var int         $current_page    Current page number.
 * @var int         $total_pages     Total number of pages.
 * @var array<int, string> $author_names Map of user_id to display name.
 * @var array<int, int>   $contribution_votes Current user's votes on contributions.
 * @var array<int, int>   $response_votes Current user's votes on responses.
 *
 * @package CommonGoals
 */

if (! defined('ABSPATH')) {
    exit;
}

if (! function_exists('common_goals_render_vote_widget')) {
    /**
     * Renders the upvote / score / downvote widget.
     *
     * @param string $object_type 'contribution' or 'response'.
     * @param int    $object_id   Target ID.
     * @param int    $score       Current score.
     * @param int    $user_vote   Current user's vote (-1, 0 or 1).
     */
    function common_goals_render_vote_widget(string $object_type, int $object_id, int $score, int $user_vote): void
    {
        $up_active   = $user_vote === 1 ? ' is-active' : '';
        $down_active = $user_vote === -1 ? ' is-active' : '';
        ?>
        <div class="common-goals-vote" data-object-type="<?php echo esc_attr($object_type); ?>" data-object-id="<?php echo esc_attr((string) $object_id); ?>">
            <button type="button" class="common-goals-vote__btn common-goals-vote__up<?php echo esc_attr($up_active); ?>" data-value="1" aria-label="<?php echo esc_attr__('Upvote', 'common-goals'); ?>">▲</button>
            <span class="common-goals-vote__score"><?php echo esc_html((string) $score); ?></span>
            <button type="button" class="common-goals-vote__btn common-goals-vote__down<?php echo esc_attr($down_active); ?>" data-value="-1" aria-label="<?php echo esc_attr__('Downvote', 'common-goals'); ?>">▼</button>
        </div>
        <?php
    }
}

if (! function_exists('common_goals_render_response_tree')) {
    /**
     * Recursively renders threaded responses.
     *
     * @param array       $children_by_parent Responses grouped by parent_id.
     * @param array       $roots              Top-level responses.
     * @param array       $author_names       Map of user_id to display name.
     * @param array       $response_votes     Current user's votes keyed by response ID.
     * @param int         $contribution_id    Owning contribution ID.
     * @param int         $depth              Current nesting depth.
     */
    function common_goals_render_response_tree(array $children_by_parent, array $roots, array $author_names, array $response_votes, int $contribution_id, int $depth = 0): void
    {
        if ($depth >= 6) {
            return;
        }

        echo '<ul class="common-goals-thread' . ($depth > 0 ? ' common-goals-thread--child' : '') . '">';
        foreach ($roots as $response) {
            $rid         = (int) $response->id;
            $r_author_id = (int) $response->user_id;
            $r_author    = $author_names[$r_author_id] ?? __('Guest', 'common-goals');
            $children    = $children_by_parent[$rid] ?? [];
            ?>
            <li class="common-goals-response" data-response-id="<?php echo esc_attr((string) $rid); ?>">
                <div class="common-goals-response__row">
                    <?php common_goals_render_vote_widget('response', $rid, (int) ($response->score ?? 0), (int) ($response_votes[$rid] ?? 0)); ?>
                    <div class="common-goals-response__body">
                        <p class="common-goals-contribution__author"><?php echo esc_html($r_author . ' · ' . mysql2date(get_option('date_format'), $response->created_at)); ?></p>
                        <div>                                    <?php echo \CommonGoals\Domain::render_content($response->body); ?></div>
                        <?php if (is_user_logged_in()) : ?>
                            <button type="button" class="common-goals-reply-toggle common-goals-btn-ghost" data-contribution-id="<?php echo esc_attr((string) $contribution_id); ?>" data-parent-id="<?php echo esc_attr((string) $rid); ?>"><?php echo esc_html__('Reply', 'common-goals'); ?></button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php
                if (! empty($children)) {
                    common_goals_render_response_tree($children_by_parent, $children, $author_names, $response_votes, $contribution_id, $depth + 1);
                }
                ?>
            </li>
            <?php
        }
        echo '</ul>';
    }
}

$status_labels = [
    'open'        => __('Open', 'common-goals'),
    'in_progress' => __('In Progress', 'common-goals'),
    'resolved'    => __('Resolved', 'common-goals'),
    'pending'     => __('Pending', 'common-goals'),
    'spam'        => __('Spam', 'common-goals'),
    'hidden'      => __('Hidden', 'common-goals'),
];

$type_labels = [
    'question'  => __('Question', 'common-goals'),
    'problem'   => __('Problem', 'common-goals'),
    'experience' => __('Experience', 'common-goals'),
    'resource'  => __('Resource', 'common-goals'),
];

$sort_base_url = remove_query_arg(['cg_sort', 'cg_page']);
?>

<section class="common-goals-board">
    <?php if (is_user_logged_in()) : ?>
        <div class="common-goals-bell" data-unread="<?php echo esc_attr((string) $unread_count); ?>">
            <button type="button" class="common-goals-bell__button" aria-label="<?php echo esc_attr__('Notifications', 'common-goals'); ?>" aria-expanded="false">
                <span class="common-goals-bell__icon">&#128276;</span>
                <?php if ($unread_count > 0) : ?>
                    <span class="common-goals-bell__badge"><?php echo esc_html(\CommonGoals\Domain::format_count($unread_count)); ?></span>
                <?php endif; ?>
            </button>
            <div class="common-goals-bell__panel" hidden>
                <div class="common-goals-bell__head">
                    <strong><?php echo esc_html__('Notifications', 'common-goals'); ?></strong>
                    <button type="button" class="common-goals-bell__markall common-goals-btn-ghost"><?php echo esc_html__('Mark all read', 'common-goals'); ?></button>
                </div>
                <?php echo $notifications_html; // phpcs:ignore ?>
            </div>
        </div>
    <?php endif; ?>

    <header class="common-goals-board__header">
        <p class="common-goals-board__eyebrow"><?php echo esc_html__('Community Goal', 'common-goals'); ?></p>
        <h2 class="common-goals-board__title"><?php echo esc_html($goal->title); ?></h2>
        <div class="common-goals-board__description"><?php echo wp_kses_post(wpautop($goal->description)); ?></div>

        <?php if (! empty($goal->beneficiary)) : ?>
            <p class="common-goals-board__beneficiary">
                <strong><?php echo esc_html__('Helps:', 'common-goals'); ?></strong>
                <?php echo esc_html($goal->beneficiary); ?>
            </p>
        <?php endif; ?>
    </header>

    <?php if ($current_notice !== '') : ?>
        <div class="common-goals-notice" role="status" aria-live="polite">
            <?php echo esc_html($current_notice); ?>
        </div>
    <?php endif; ?>

    <div class="common-goals-contributions">
        <div class="common-goals-contributions__heading">
            <div class="common-goals-sort-tabs" role="tablist" aria-label="<?php echo esc_attr__('Sort', 'common-goals'); ?>">
                <a class="common-goals-sort-tab <?php echo $sort === 'hot' ? 'is-active' : ''; ?>" href="<?php echo esc_url(add_query_arg('cg_sort', 'hot', $sort_base_url)); ?>"><?php echo esc_html__('Hot', 'common-goals'); ?></a>
                <a class="common-goals-sort-tab <?php echo $sort === 'top' ? 'is-active' : ''; ?>" href="<?php echo esc_url(add_query_arg('cg_sort', 'top', $sort_base_url)); ?>"><?php echo esc_html__('Top', 'common-goals'); ?></a>
                <a class="common-goals-sort-tab <?php echo $sort === 'new' ? 'is-active' : ''; ?>" href="<?php echo esc_url(add_query_arg('cg_sort', 'new', $sort_base_url)); ?>"><?php echo esc_html__('New', 'common-goals'); ?></a>
            </div>
            <a class="common-goals-new-thread-cta" href="#cg-new-thread"><?php echo esc_html__('+ Start a new thread', 'common-goals'); ?></a>
        </div>

        <form class="common-goals-filters" method="get">
            <label>
                <?php echo esc_html__('Filter by type', 'common-goals'); ?>
                <select name="common_goals_type">
                    <option value=""><?php echo esc_html__('All types', 'common-goals'); ?></option>
                    <?php foreach ($allowed_types as $allowed_type) : ?>
                        <option value="<?php echo esc_attr($allowed_type); ?>" <?php selected($selected_type, $allowed_type); ?>>
                            <?php echo esc_html($type_labels[$allowed_type] ?? ucfirst($allowed_type)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <?php echo esc_html__('Filter by status', 'common-goals'); ?>
                <select name="common_goals_status">
                    <option value=""><?php echo esc_html__('All visible statuses', 'common-goals'); ?></option>
                    <?php foreach ($visible_statuses as $visible_status) : ?>
                        <option value="<?php echo esc_attr($visible_status); ?>" <?php selected($selected_status, $visible_status); ?>>
                            <?php echo esc_html($status_labels[$visible_status] ?? ucfirst($visible_status)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <?php echo esc_html__('Topic', 'common-goals'); ?>
                <input type="text" name="cg_topic" value="<?php echo esc_attr($selected_topic); ?>" placeholder="<?php echo esc_attr__('Filter by topic', 'common-goals'); ?>" />
            </label>

            <label>
                <?php echo esc_html__('Search', 'common-goals'); ?>
                <input type="search" name="cg_search" value="<?php echo esc_attr($search_term); ?>" placeholder="<?php echo esc_attr__('Search contributions', 'common-goals'); ?>" />
            </label>

            <input type="hidden" name="cg_sort" value="<?php echo esc_attr($sort); ?>" />
            <input type="hidden" name="cg_page" value="1" />
            <button type="submit"><?php echo esc_html__('Apply filters', 'common-goals'); ?></button>
        </form>

        <?php if (! empty($contributions)) : ?>
            <ul class="common-goals-threadlist">
            <?php foreach ($contributions as $contribution) :
                $cid           = (int) $contribution->id;
                $author_id     = (int) $contribution->user_id;
                $author_name   = $author_names[$author_id] ?? __('Guest', 'common-goals');
                $initial       = mb_substr(trim($author_name), 0, 1) ?: '?';
                $contribution_responses = $responses_by_contribution_id[$cid] ?? [];
                $comment_count         = count($contribution_responses);
                $views_count           = (int) ($contribution->views ?? 0);
                $thread_url            = \CommonGoals\Frontend\GuideRouter::contribution_url($cid);
                $snippet               = wp_trim_words(wp_strip_all_tags($contribution->body), 28);
            ?>
                <li class="common-goals-contribution common-goals-contribution--<?php echo esc_attr($contribution->type); ?><?php echo ! empty($contribution->is_sticky) ? ' common-goals-contribution--pinned' : ''; ?>">
                    <?php common_goals_render_vote_widget('contribution', $cid, (int) ($contribution->score ?? 0), (int) ($contribution_votes[$cid] ?? 0)); ?>

                    <div class="common-goals-contribution__main">
                        <div class="common-goals-contribution__badges">
                            <?php if (! empty($contribution->is_sticky)) : ?>
                                <span class="common-goals-badge common-goals-badge--pinned"><?php echo esc_html__('📌 Pinned', 'common-goals'); ?></span>
                            <?php endif; ?>
                            <span class="common-goals-badge common-goals-badge--<?php echo esc_attr($contribution->type); ?>"><?php echo esc_html($type_labels[$contribution->type] ?? ucfirst($contribution->type)); ?></span>
                            <span class="common-goals-badge common-goals-badge--<?php echo esc_attr($contribution->status); ?>"><?php echo esc_html($status_labels[$contribution->status] ?? ucfirst($contribution->status)); ?></span>
                            <?php if (! empty($contribution->topic)) : ?>
                                <span class="common-goals-badge common-goals-badge--topic"><?php echo esc_html('#' . $contribution->topic); ?></span>
                            <?php endif; ?>
                        </div>

                        <h4 class="common-goals-contribution__title"><a href="<?php echo esc_url($thread_url); ?>"><?php echo esc_html($contribution->title); ?></a></h4>
                        <p class="common-goals-contribution__author">
                            <?php
                            if ($author_id > 0) {
                                echo '<a class="common-goals-author-link" href="' . esc_url(\CommonGoals\Frontend\GuideRouter::author_url($author_id)) . '">' . esc_html($author_name) . '</a>';
                            } else {
                                echo esc_html($author_name);
                            }
                            echo ' · ' . esc_html(mysql2date(get_option('date_format'), $contribution->created_at));
                            ?>
                        </p>

                        <?php if ($snippet !== '') : ?>
                            <p class="common-goals-contribution__snippet"><?php echo esc_html($snippet); ?></p>
                        <?php endif; ?>

                        <div class="common-goals-contribution__footer">
                            <a class="common-goals-contribution__comments" href="<?php echo esc_url($thread_url); ?>">
                                <?php echo esc_html(sprintf(_n('%s comment', '%s comments', $comment_count, 'common-goals'), number_format_i18n($comment_count))); ?>
                            </a>
                            <span class="common-goals-contribution__views"><?php echo esc_html(sprintf(__('%s views', 'common-goals'), \CommonGoals\Domain::format_count($views_count))); ?></span>
                            <button type="button" class="common-goals-bookmark<?php echo in_array($cid, $bookmarked_ids, true) ? ' is-active' : ''; ?>" data-contribution-id="<?php echo esc_attr((string) $cid); ?>" aria-label="<?php echo esc_attr__('Save', 'common-goals'); ?>" title="<?php echo esc_attr__('Save', 'common-goals'); ?>">
                                <span class="common-goals-bookmark__icon"><?php echo in_array($cid, $bookmarked_ids, true) ? '★' : '☆'; ?></span>
                                <span class="common-goals-bookmark__label"><?php echo in_array($cid, $bookmarked_ids, true) ? esc_html__('Saved', 'common-goals') : esc_html__('Save', 'common-goals'); ?></span>
                            </button>

                            <?php if (is_user_logged_in()) : ?>
                                <details class="common-goals-report">
                                    <summary class="common-goals-report__toggle" title="<?php echo esc_attr__('Report', 'common-goals'); ?>"><?php echo esc_html__('Report', 'common-goals'); ?></summary>
                                    <form class="common-goals-report__form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <input type="hidden" name="action" value="common_goals_create_report" />
                                        <input type="hidden" name="object_type" value="contribution" />
                                        <input type="hidden" name="object_id" value="<?php echo esc_attr((string) $cid); ?>" />
                                        <?php wp_nonce_field('common_goals_create_report'); ?>
                                        <p class="common-goals-form__field common-goals-honeypot" aria-hidden="true">
                                            <label>Leave this field empty
                                                <input type="text" name="cg_website" value="" tabindex="-1" autocomplete="off" />
                                            </label>
                                        </p>
                                        <label>
                                            <?php echo esc_html__('Reason', 'common-goals'); ?>
                                            <select name="report_reason" required>
                                                <option value="spam"><?php echo esc_html__('Spam or scam', 'common-goals'); ?></option>
                                                <option value="harassment"><?php echo esc_html__('Harassment or hate', 'common-goals'); ?></option>
                                                <option value="off_topic"><?php echo esc_html__('Off topic', 'common-goals'); ?></option>
                                                <option value="other"><?php echo esc_html__('Other', 'common-goals'); ?></option>
                                            </select>
                                        </label>
                                        <label>
                                            <?php echo esc_html__('Details (optional)', 'common-goals'); ?>
                                            <input type="text" name="report_detail" maxlength="500" />
                                        </label>
                                        <button type="submit"><?php echo esc_html__('Send report', 'common-goals'); ?></button>
                                    </form>
                                </details>
                            <?php endif; ?>

                            <?php if (is_user_logged_in() && $author_id === get_current_user_id()) : ?>
                                <details class="common-goals-contribution__edit">
                                    <summary class="common-goals-btn-ghost"><?php echo esc_html__('Edit', 'common-goals'); ?></summary>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <input type="hidden" name="action" value="common_goals_edit_contribution" />
                                        <input type="hidden" name="contribution_id" value="<?php echo esc_attr((string) $contribution->id); ?>" />
                                        <?php wp_nonce_field('common_goals_edit_contribution'); ?>
                                        <p><input type="text" name="contribution_title" value="<?php echo esc_attr($contribution->title); ?>" maxlength="<?php echo esc_attr((string) \CommonGoals\Domain::MAX_TITLE_LENGTH); ?>" required /></p>
                                        <p><textarea name="contribution_body" rows="4" maxlength="<?php echo esc_attr((string) \CommonGoals\Domain::MAX_BODY_LENGTH); ?>" required><?php echo esc_textarea($contribution->body); ?></textarea></p>
                                        <button type="submit"><?php echo esc_html__('Save changes', 'common-goals'); ?></button>
                                    </form>
                                </details>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_attr(__('Delete this contribution and all its responses? This cannot be undone.', 'common-goals')); ?>');">
                                    <input type="hidden" name="action" value="common_goals_delete_contribution" />
                                    <input type="hidden" name="contribution_id" value="<?php echo esc_attr((string) $contribution->id); ?>" />
                                    <?php wp_nonce_field('common_goals_delete_contribution'); ?>
                                    <button type="submit" class="common-goals-btn-ghost"><?php echo esc_html__('Delete', 'common-goals'); ?></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </li>
            <?php endforeach; ?>
            </ul>

            <?php if ($total_pages > 1) : ?>
                <nav class="common-goals-pagination" aria-label="<?php echo esc_attr__('Pagination', 'common-goals'); ?>">
                    <?php
                    $base_url = remove_query_arg('cg_page');
                    for ($i = 1; $i <= $total_pages; $i++) :
                        $url = $i === 1 ? $base_url : add_query_arg('cg_page', $i, $base_url);
                    ?>
                        <?php if ($i === $current_page) : ?>
                            <span class="current" aria-current="page"><?php echo esc_html((string) $i); ?></span>
                        <?php else : ?>
                            <a href="<?php echo esc_url($url); ?>"><?php echo esc_html((string) $i); ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                </nav>
            <?php endif; ?>
        <?php else : ?>
            <p class="common-goals-empty"><?php echo esc_html__('No threads yet. Be the first to start the conversation.', 'common-goals'); ?></p>
        <?php endif; ?>
    </div>

    <details class="common-goals-form-wrap" id="cg-new-thread">
        <summary class="common-goals-form-wrap__summary"><?php echo esc_html__('Start a new thread', 'common-goals'); ?></summary>

        <form class="common-goals-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="common_goals_create_contribution" />
            <input type="hidden" name="goal_id" value="<?php echo esc_attr((string) $goal->id); ?>" />
            <?php wp_nonce_field('common_goals_create_contribution'); ?>

            <p class="common-goals-form__field common-goals-honeypot" aria-hidden="true">
                <label>Leave this field empty
                    <input type="text" name="cg_website" value="" tabindex="-1" autocomplete="off" />
                </label>
            </p>

            <label>
                <?php echo esc_html__('Type', 'common-goals'); ?>
                <select name="contribution_type" required>
                    <?php foreach ($allowed_types as $allowed_type) : ?>
                        <option value="<?php echo esc_attr($allowed_type); ?>">
                            <?php echo esc_html($type_labels[$allowed_type] ?? ucfirst($allowed_type)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <?php echo esc_html__('Topic', 'common-goals'); ?>
                <input type="text" name="contribution_topic" maxlength="<?php echo esc_attr((string) \CommonGoals\Domain::MAX_TOPIC_LENGTH); ?>" placeholder="<?php echo esc_attr__('Example: onboarding, pricing, setup', 'common-goals'); ?>" />
            </label>

            <label>
                <?php echo esc_html__('Title', 'common-goals'); ?>
                <input type="text" name="contribution_title" maxlength="<?php echo esc_attr((string) \CommonGoals\Domain::MAX_TITLE_LENGTH); ?>" required />
            </label>

            <label>
                <?php echo esc_html__('Context', 'common-goals'); ?>
                <textarea name="contribution_body" rows="5" maxlength="<?php echo esc_attr((string) \CommonGoals\Domain::MAX_BODY_LENGTH); ?>" required></textarea>
            </label>

            <?php if (! is_user_logged_in()) : ?>
                <p class="common-goals-form__hint" id="cg-guest-hint" role="note"><?php echo esc_html__('Submissions from guests are held for moderation before appearing publicly.', 'common-goals'); ?></p>
            <?php endif; ?>

            <button type="submit" name="contribution_submit"><?php echo esc_html__('Publish contribution', 'common-goals'); ?></button>
        </form>
    </details>
</section>
