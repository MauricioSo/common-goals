/**
 * Common Goals — Frontend enhancements
 * Progressive enhancement: character counters, basic validation,
 * Reddit-style voting (AJAX) and threaded reply toggling.
 */
(function () {
    'use strict';

    var cfg = window.CommonGoalsBoard || {};

    document.addEventListener('DOMContentLoaded', function () {
        setupCharCounters();
        setupFormValidation();
        setupVoting();
        setupBookmarks();
        setupReplyToggles();
        setupMarkdownEditors();
        setupBell();
    });

    /* ------------------------------------------------------------------ *
     * Markdown editor (toolbar + live preview)
     * ------------------------------------------------------------------ */

    var BODY_SELECTOR = '[name="contribution_body"], [name="response_body"]';

    function setupMarkdownEditors() {
        document.querySelectorAll(BODY_SELECTOR).forEach(injectEditor);
    }

    function injectEditor(textarea) {
        if (textarea.getAttribute('data-md-enabled') === '1') return;
        textarea.setAttribute('data-md-enabled', '1');

        var toolbar = document.createElement('div');
        toolbar.className = 'common-goals-md-toolbar';
        toolbar.setAttribute('role', 'toolbar');

        var buttons = [
            { t: 'B', title: 'Bold', wrap: '**' },
            { t: 'I', title: 'Italic', wrap: '*' },
            { t: 'Code', title: 'Inline code', wrap: '`' },
            { t: '"', title: 'Quote', prefix: '> ' },
            { t: '•', title: 'List', prefix: '- ' },
            { t: 'H', title: 'Heading', prefix: '## ' },
            { t: 'Link', title: 'Link', link: true }
        ];

        buttons.forEach(function (b) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'common-goals-md-btn';
            btn.title = b.title;
            btn.textContent = b.t;
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                applyMarkdown(textarea, b);
                textarea.focus();
            });
            toolbar.appendChild(btn);
        });

        var previewBtn = document.createElement('button');
        previewBtn.type = 'button';
        previewBtn.className = 'common-goals-md-preview-btn';
        previewBtn.textContent = 'Preview';
        previewBtn.addEventListener('click', function (e) {
            e.preventDefault();
            togglePreview(textarea, previewBtn);
        });
        toolbar.appendChild(previewBtn);

        textarea.parentNode.insertBefore(toolbar, textarea);
    }

    function applyMarkdown(textarea, action) {
        var start = textarea.selectionStart;
        var end = textarea.selectionEnd;
        var value = textarea.value;
        var sel = value.substring(start, end);
        var inserted;

        if (action.link) {
            var url = window.prompt('URL del enlace', 'https://');
            if (!url) return;
            var text = sel || 'texto del enlace';
            inserted = '[' + text + '](' + url + ')';
            textarea.value = value.substring(0, start) + inserted + value.substring(end);
            textarea.selectionStart = start;
            textarea.selectionEnd = start + inserted.length;
        } else if (action.prefix) {
            var lineStart = value.lastIndexOf('\n', start - 1) + 1;
            textarea.value = value.substring(0, lineStart) + action.prefix + value.substring(lineStart);
            textarea.selectionStart = textarea.selectionEnd = start + action.prefix.length;
        } else if (action.wrap) {
            inserted = action.wrap + (sel || 'texto') + action.wrap;
            textarea.value = value.substring(0, start) + inserted + value.substring(end);
            textarea.selectionStart = start + action.wrap.length;
            textarea.selectionEnd = start + action.wrap.length + (sel ? sel.length : 'texto'.length);
        }

        textarea.dispatchEvent(new Event('input'));
    }

    function togglePreview(textarea, btn) {
        var existing = textarea.parentNode.querySelector('.common-goals-md-preview');
        if (existing) {
            existing.remove();
            btn.classList.remove('is-active');
            btn.textContent = 'Preview';
            textarea.style.display = '';
            return;
        }

        var preview = document.createElement('div');
        preview.className = 'common-goals-md-preview common-goals-contribution__body';
        preview.innerHTML = mdToHtml(textarea.value);
        textarea.parentNode.insertBefore(preview, textarea);
        textarea.style.display = 'none';
        btn.classList.add('is-active');
        btn.textContent = 'Write';
    }

    // Minimal client-side Markdown for preview (mirrors the PHP subset).
    function mdToHtml(src) {
        if (!src.trim()) return '<p class="common-goals-muted">No hay nada que previsualizar.</p>';
        var esc = function (s) { return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); };
        var blocks = [];
        src = src.replace(/```[^\n]*\n([\s\S]*?)```/g, function (m, c) {
            var k = '\u0000B' + blocks.length + '\u0000';
            blocks.push('<pre><code>' + esc(c.replace(/\n$/, '')) + '</code></pre>');
            return k;
        });
        src = esc(src);
        var lines = src.split('\n');
        var html = [], i = 0;
        while (i < lines.length) {
            var line = lines[i], trim = line.trim();
            if (trim === '') { i++; continue; }
            if (trim[0] === '\u0000' && blocks[ /\d+/.exec(trim) ]) { html.push(blocks[ +/\d+/.exec(trim)[0] ]); i++; continue; }
            if (/^#{1,4}\s+/.test(trim)) { html.push('<h3>' + inline(trim.replace(/^#{1,4}\s+/, '')) + '</h3>'); i++; continue; }
            if (/^\s*[-*+]\s+/.test(line)) { var it = []; while (i < lines.length && /^\s*[-*+]\s+/.test(lines[i])) { it.push(inline(lines[i].replace(/^\s*[-*+]\s+/, ''))); i++; } html.push('<ul><li>' + it.join('</li><li>') + '</li></ul>'); continue; }
            if (/^\s*\d+\.\s+/.test(line)) { var it2 = []; while (i < lines.length && /^\s*\d+\.\s+/.test(lines[i])) { it2.push(inline(lines[i].replace(/^\s*\d+\.\s+/, ''))); i++; } html.push('<ol><li>' + it2.join('</li><li>') + '</li></ol>'); continue; }
            if (/^&gt;\s?/.test(line)) { var q = []; while (i < lines.length && /^&gt;\s?/.test(lines[i])) { q.push(lines[i].replace(/^&gt;\s?/, '')); i++; } html.push('<blockquote><p>' + inline(q.join('<br>')) + '</p></blockquote>'); continue; }
            var p = []; while (i < lines.length && lines[i].trim() !== '' && !/^#{1,4}\s+/.test(lines[i].trim()) && !/^\s*[-*+]\s+/.test(lines[i]) && !/^\s*\d+\.\s+/.test(lines[i]) && !/^&gt;\s?/.test(lines[i])) { p.push(lines[i].trim()); i++; }
            html.push('<p>' + inline(p.join('<br>')) + '</p>');
        }
        return html.join('\n');

        function inline(s) {
            s = s.replace(/`([^`]+)`/g, function (m, c) { return '<code>' + c + '</code>'; });
            s = s.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+|mailto:[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="nofollow noopener">$1</a>');
            s = s.replace(/\*\*([^\s*][^*]*[^\s*]|[^\s*])\*\*/g, '<strong>$1</strong>');
            s = s.replace(/(^|[^\w*])\*([^\s*][^*]*[^\s*]|[^\s*])\*(?=[^\w*]|$)/g, '$1<em>$2</em>');
            return s;
        }
    }

    /* ------------------------------------------------------------------ *
     * Voting (AJAX via REST)
     * ------------------------------------------------------------------ */

    function setupVoting() {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.common-goals-vote__btn');
            if (!btn) return;

            e.preventDefault();
            handleVote(btn);
        });
    }

    function handleVote(btn) {
        if (!cfg.isLoggedIn) {
            alert(cfg.i18n && cfg.i18n.loginToVote ? cfg.i18n.loginToVote : 'Please log in to vote.');
            if (cfg.loginUrl) window.location.href = cfg.loginUrl;
            return;
        }

        var widget = btn.closest('.common-goals-vote');
        if (!widget || widget.getAttribute('data-busy') === '1') return;

        var objectType = widget.getAttribute('data-object-type');
        var objectId = widget.getAttribute('data-object-id');
        var value = parseInt(btn.getAttribute('data-value'), 10) >= 0 ? 1 : -1;

        widget.setAttribute('data-busy', '1');

        fetch(cfg.restUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': cfg.nonce
            },
            body: JSON.stringify({
                object_type: objectType,
                object_id: objectId,
                value: value
            })
        })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (data) {
                applyVoteState(widget, data.user_vote, data.score);
            })
            .catch(function () {
                alert(cfg.i18n && cfg.i18n.voteError ? cfg.i18n.voteError : 'Could not register your vote.');
            })
            .finally(function () {
                widget.removeAttribute('data-busy');
            });
    }

    function applyVoteState(widget, userVote, score) {
        var up = widget.querySelector('.common-goals-vote__up');
        var down = widget.querySelector('.common-goals-vote__down');
        var scoreEl = widget.querySelector('.common-goals-vote__score');

        if (up) up.classList.toggle('is-active', userVote === 1);
        if (down) down.classList.toggle('is-active', userVote === -1);
        if (scoreEl) scoreEl.textContent = String(score);
    }

    /* ------------------------------------------------------------------ *
     * Bookmarks (save thread)
     * ------------------------------------------------------------------ */

    function setupBookmarks() {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.common-goals-bookmark');
            if (!btn) return;

            e.preventDefault();
            handleBookmark(btn);
        });
    }

    function handleBookmark(btn) {
        if (!cfg.isLoggedIn) {
            alert(cfg.i18n && cfg.i18n.loginToVote ? cfg.i18n.loginToVote : 'Please log in.');
            if (cfg.loginUrl) window.location.href = cfg.loginUrl;
            return;
        }

        if (btn.getAttribute('data-busy') === '1') return;

        var contributionId = btn.getAttribute('data-contribution-id');
        btn.setAttribute('data-busy', '1');

        fetch(cfg.bookmarkUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': cfg.nonce
            },
            body: JSON.stringify({ contribution_id: parseInt(contributionId, 10) })
        })
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(function (data) {
                var active = !!data.bookmarked;
                btn.classList.toggle('is-active', active);
                var icon = btn.querySelector('.common-goals-bookmark__icon');
                var label = btn.querySelector('.common-goals-bookmark__label');
                if (icon) icon.textContent = active ? '★' : '☆';
                if (label) label.textContent = active ? (cfg.i18n && cfg.i18n.saved ? cfg.i18n.saved : 'Saved') : (cfg.i18n && cfg.i18n.save ? cfg.i18n.save : 'Save');
            })
            .catch(function () { alert(cfg.i18n && cfg.i18n.voteError ? cfg.i18n.voteError : 'Error.'); })
            .finally(function () { btn.removeAttribute('data-busy'); });
    }

    /* ------------------------------------------------------------------ *
     * Notifications bell
     * ------------------------------------------------------------------ */

    function setupBell() {
        var bell = document.querySelector('.common-goals-bell');
        if (!bell) return;

        var btn = bell.querySelector('.common-goals-bell__button');
        var panel = bell.querySelector('.common-goals-bell__panel');

        if (btn && panel) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var open = panel.hasAttribute('hidden') === false;
                if (open) { panel.setAttribute('hidden', ''); btn.setAttribute('aria-expanded', 'false'); }
                else { panel.removeAttribute('hidden'); btn.setAttribute('aria-expanded', 'true'); markAllRead(bell); }
            });

            document.addEventListener('click', function (e) {
                if (!bell.contains(e.target)) {
                    panel.setAttribute('hidden', '');
                    btn.setAttribute('aria-expanded', 'false');
                }
            });
        }

        var markAll = bell.querySelector('.common-goals-bell__markall');
        if (markAll) {
            markAll.addEventListener('click', function (e) { e.preventDefault(); markAllRead(bell); });
        }
    }

    function markAllRead(bell) {
        if (!cfg.isLoggedIn || !cfg.notifReadUrl) return;

        fetch(cfg.notifReadUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
            body: JSON.stringify({ all: 1 })
        })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
            .then(function (data) {
                var badge = bell.querySelector('.common-goals-bell__badge');
                if (badge) badge.remove();
                bell.querySelectorAll('.common-goals-notif-item--unread').forEach(function (el) { el.classList.remove('common-goals-notif-item--unread'); });
                bell.setAttribute('data-unread', String(data.unread || 0));
            })
            .catch(function () {});
    }

    /* ------------------------------------------------------------------ *
     * Threaded reply toggling
     * ------------------------------------------------------------------ */

    function setupReplyToggles() {
        document.addEventListener('click', function (e) {
            var toggle = e.target.closest('.common-goals-reply-toggle');
            if (toggle) {
                e.preventDefault();
                openReplyForm(toggle);
                return;
            }

            var cancel = e.target.closest('.common-goals-reply-cancel');
            if (cancel) {
                e.preventDefault();
                var form = cancel.closest('.common-goals-response-form--reply');
                if (form) form.remove();
            }
        });
    }

    function openReplyForm(toggle) {
        if (!cfg.isLoggedIn) {
            alert(cfg.i18n && cfg.i18n.loginToReply ? cfg.i18n.loginToReply : 'Please log in to reply.');
            if (cfg.loginUrl) window.location.href = cfg.loginUrl;
            return;
        }

        var responseEl = toggle.closest('.common-goals-response');
        if (!responseEl) return;

        // Remove any existing inline reply form within this response.
        var existing = responseEl.querySelector(':scope > .common-goals-response__row + .common-goals-response-form--reply, :scope > .common-goals-response-form--reply');
        if (existing) {
            existing.remove();
            return;
        }

        var tpl = document.getElementById('cg-reply-template');
        if (!tpl || !tpl.content) return;

        var contributionId = toggle.getAttribute('data-contribution-id');
        var parentId = toggle.getAttribute('data-parent-id');

        var clone = tpl.content.cloneNode(true);
        var form = clone.querySelector('form');

        form.querySelector('[name="contribution_id"]').value = contributionId;
        form.querySelector('[name="parent_id"]').value = parentId;

        // Insert the form right after the response's row, inside the response li.
        var row = responseEl.querySelector('.common-goals-response__row');
        if (row && row.parentNode === responseEl) {
            responseEl.insertBefore(form, row.nextSibling);
        } else {
            responseEl.appendChild(form);
        }

        var textarea = responseEl.querySelector('.common-goals-response-form--reply [name="response_body"]');
        if (textarea) {
            injectEditor(textarea);
            textarea.focus();
        }
    }

    /* ------------------------------------------------------------------ *
     * Form helpers (kept from the original enhancement)
     * ------------------------------------------------------------------ */

    function setupCharCounters() {
        var forms = document.querySelectorAll('.common-goals-form, .common-goals-response-form');
        forms.forEach(function (form) {
            var fields = form.querySelectorAll('[maxlength]');
            fields.forEach(function (field) {
                var max = parseInt(field.getAttribute('maxlength'), 10);
                if (!max) return;

                var counter = document.createElement('p');
                counter.className = 'common-goals-char-counter';
                counter.style.cssText = 'font-size:12px;color:#7a8a99;margin:2px 0 0;';
                updateCounter(counter, field, max);
                field.parentNode.appendChild(counter);

                field.addEventListener('input', function () {
                    updateCounter(counter, field, max);
                });
            });
        });
    }

    function updateCounter(counter, field, max) {
        var remaining = max - field.value.length;
        counter.textContent = remaining + ' / ' + max;

        if (remaining < 0) {
            counter.style.color = '#c74435';
        } else if (remaining < max * 0.1) {
            counter.style.color = '#c9962e';
        } else {
            counter.style.color = '#7a8a99';
        }
    }

    function setupFormValidation() {
        var form = document.querySelector('.common-goals-form');
        if (form) {
            form.addEventListener('submit', function (e) {
                var title = form.querySelector('[name="contribution_title"]');
                var body = form.querySelector('[name="contribution_body"]');

                if (title && title.value.trim() === '') {
                    e.preventDefault();
                    showError(title, form);
                    return;
                }

                if (body && body.value.trim() === '') {
                    e.preventDefault();
                    showError(body, form);
                    return;
                }
            });
        }

        var responseForms = document.querySelectorAll('.common-goals-response-form');
        responseForms.forEach(function (rf) {
            rf.addEventListener('submit', function (e) {
                var body = rf.querySelector('[name="response_body"]');
                if (body && body.value.trim() === '') {
                    e.preventDefault();
                    showError(body, rf);
                }
            });
        });
    }

    function showError(field, form) {
        field.style.borderColor = '#c74435';
        field.focus();

        var existing = form.querySelector('.common-goals-inline-error');
        if (existing) existing.remove();

        var error = document.createElement('p');
        error.className = 'common-goals-inline-error';
        error.style.cssText = 'color:#c74435;font-size:13px;margin:4px 0;';
        error.textContent = field.getAttribute('data-error') || 'Please fill in this field.';
        field.parentNode.appendChild(error);

        field.addEventListener('input', function () {
            field.style.borderColor = '';
            var err = form.querySelector('.common-goals-inline-error');
            if (err) err.remove();
        }, { once: true });
    }
})();
