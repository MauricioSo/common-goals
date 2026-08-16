/**
 * Common Goals — AI assistant frontend (progressive enhancement).
 *
 * Wires the member-facing MVP flows (discover, compose, answer, summarize)
 * to the REST surface under /wp-json/common-goals/v1/ai/*. The assistant only
 * suggests; every action still requires the human to publish through the
 * existing forms. Safe no-op when the global config is missing.
 */
(function () {
    'use strict';

    var cfg = window.CommonGoalsBoard || {};
    var aiBase = cfg.aiBaseUrl;
    var nonce = cfg.nonce;
    var i18n = cfg.i18n || {};

    document.addEventListener('DOMContentLoaded', function () {
        if (!aiBase || !nonce) return;
        enhanceDiscover();
        enhanceCompose();
        enhanceAnswer();
        enhanceSummarize();
    });

    /* ------------------------------------------------------------------ *
     * Shared HTTP helper
     * ------------------------------------------------------------------ */

    function callAi(flow, payload) {
        return fetch(aiBase + '/' + flow, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': nonce
            },
            body: JSON.stringify(payload)
        }).then(function (r) {
            if (!r.ok) {
                return r.json().then(function (data) {
                    throw new Error((data && data.message) || ('HTTP ' + r.status));
                }, function () { throw new Error('HTTP ' + r.status); });
            }
            return r.json();
        });
    }

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        if (attrs) {
            Object.keys(attrs).forEach(function (k) {
                if (k === 'class') node.className = attrs[k];
                else if (k === 'text') node.textContent = attrs[k];
                else if (k.indexOf('on') === 0 && typeof attrs[k] === 'function') node.addEventListener(k.slice(2), attrs[k]);
                else node.setAttribute(k, attrs[k]);
            });
        }
        (children || []).forEach(function (c) { node.appendChild(c); });
        return node;
    }

    function injectNotice(container, message, kind) {
        var existing = container.querySelector('.common-goals-ai-notice');
        if (existing) existing.remove();
        var notice = el('div', { class: 'common-goals-ai-notice common-goals-notice', role: 'status', text: message });
        if (kind === 'error') notice.style.borderColor = '#e5b8b3';
        container.insertBefore(notice, container.firstChild);
    }

    function setBusy(button, busy, label) {
        button.disabled = busy;
        if (busy) { button.dataset.prevLabel = button.textContent; button.textContent = i18n.aiLoading || '…'; }
        else { button.textContent = label || button.dataset.prevLabel || button.textContent; }
    }

    /* ------------------------------------------------------------------ *
     * 01 · Discover: suggest related threads before posting
     * ------------------------------------------------------------------ */

    function enhanceDiscover() {
        var form = document.querySelector('[data-cg-ai="discover"]');
        if (!form) return;
        var input = form.querySelector('input, textarea');
        var results = form.parentElement.querySelector('[data-cg-ai-discover-results]') || createDiscoverResults(form);

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var query = input.value.trim();
            if (query.length < 4) { injectNotice(form.parentElement, i18n.aiError || 'Too short', 'error'); return; }
            setBusy(form.querySelector('button'), true);
            results.innerHTML = '';
            callAi('discover', {
                query: query,
                goal_id: parseInt(form.dataset.goalId, 10) || 0,
                community_id: parseInt(form.dataset.communityId, 10) || 0
            }).then(function (data) {
                renderDiscover(results, data);
            }).catch(function (err) {
                injectNotice(form.parentElement, err.message || (i18n.aiError), 'error');
            }).finally(function () { setBusy(form.querySelector('button'), false); });
        });
    }

    function createDiscoverResults(form) {
        var box = el('div', { class: 'common-goals-ai-discover-results', 'data-cg-ai-discover-results': '' });
        form.parentElement.appendChild(box);
        return box;
    }

    function renderDiscover(container, data) {
        container.innerHTML = '';
        if (data.suggestion) container.appendChild(el('p', { class: 'common-goals-muted', text: data.suggestion }));
        if (!data.related || !data.related.length) return;
        var list = el('ul', { class: 'common-goals-threadlist' });
        data.related.forEach(function (item) {
            list.appendChild(el('li', { class: 'common-goals-contribution' }, [
                el('h4', {}, [el('a', { href: item.url, text: item.title })]),
                el('p', { class: 'common-goals-muted', text: item.reason + (item.confidence ? ' · ' + Math.round(item.confidence * 100) + '%' : '') })
            ]));
        });
        container.appendChild(list);
    }

    /* ------------------------------------------------------------------ *
     * 02 · Compose: improve a draft
     * ------------------------------------------------------------------ */

    function enhanceCompose() {
        var button = document.querySelector('[data-cg-ai="compose"]');
        if (!button) return;
        var textarea = document.querySelector('[name="contribution_body"]');

        button.addEventListener('click', function () {
            if (!textarea) return;
            var draft = textarea.value.trim();
            if (draft.length < 10) { injectNotice(textarea.parentElement, i18n.aiError || 'Too short', 'error'); return; }
            var goalForm = button.closest('form');
            setBusy(button, true);
            callAi('compose', {
                draft: draft,
                goal_id: parseInt(goalForm && goalForm.querySelector('[name="goal_id"]') && goalForm.querySelector('[name="goal_id"]').value, 10) || 0
            }).then(function (data) {
                applyComposeSuggestion(textarea, data, button);
            }).catch(function (err) {
                injectNotice(textarea.parentElement, err.message || (i18n.aiError), 'error');
            }).finally(function () { setBusy(button, false); });
        });
    }

    function applyComposeSuggestion(textarea, data, button) {
        var prev = button.dataset.composePanel && document.getElementById(button.dataset.composePanel);
        if (prev) prev.remove();
        var panel = el('div', { class: 'common-goals-ai-panel', id: 'cg-ai-compose-' + Date.now() });
        button.dataset.composePanel = panel.id;

        if (data.summary_of_changes) panel.appendChild(el('p', { class: 'common-goals-muted', text: data.summary_of_changes }));
        panel.appendChild(el('label', { class: 'common-goals-editor-label', text: 'Título sugerido' }));
        panel.appendChild(el('input', { type: 'text', value: data.title || '', class: 'common-goals-editor' }));
        panel.appendChild(el('label', { class: 'common-goals-editor-label', text: 'Cuerpo sugerido' }));
        panel.appendChild(el('textarea', { rows: '6', class: 'common-goals-editor' }, [document.createTextNode(data.body || '')]));
        if (data.topic) panel.appendChild(el('p', { class: 'common-goals-muted', text: 'Tema sugerido: ' + data.topic + ' · Tipo: ' + data.type }));

        var actions = el('div', { class: 'common-goals-btn-row' });
        actions.appendChild(el('button', { type: 'button', class: 'common-goals-btn', text: i18n.aiUseDraft || 'Usar' , onclick: function () {
            var titleField = document.querySelector('[name="contribution_title"]');
            if (titleField && data.title) titleField.value = data.title;
            textarea.value = data.body || '';
            var topicField = document.querySelector('[name="cg_topic"]');
            if (topicField && data.topic) topicField.value = data.topic;
            panel.remove();
        }}));
        actions.appendChild(el('button', { type: 'button', class: 'common-goals-btn common-goals-btn-ghost', text: 'Cerrar', onclick: function () { panel.remove(); }}));
        panel.appendChild(actions);

        textarea.parentElement.insertBefore(panel, textarea.nextSibling);
    }

    /* ------------------------------------------------------------------ *
     * 03 · Answer: draft a grounded response
     * ------------------------------------------------------------------ */

    function enhanceAnswer() {
        var button = document.querySelector('[data-cg-ai="answer"]');
        if (!button) return;
        var textarea = document.querySelector('[name="response_body"]');

        button.addEventListener('click', function () {
            if (!textarea) return;
            setBusy(button, true);
            callAi('answer', {
                contribution_id: parseInt(button.dataset.contributionId, 10) || 0,
                community_id: parseInt(button.dataset.communityId, 10) || 0
            }).then(function (data) {
                applyAnswerDraft(textarea, data, button);
            }).catch(function (err) {
                injectNotice(textarea.parentElement, err.message || (i18n.aiError), 'error');
            }).finally(function () { setBusy(button, false); });
        });
    }

    function applyAnswerDraft(textarea, data, button) {
        var prev = button.dataset.answerPanel && document.getElementById(button.dataset.answerPanel);
        if (prev) prev.remove();
        var panel = el('div', { class: 'common-goals-ai-panel', id: 'cg-ai-answer-' + Date.now() });
        button.dataset.answerPanel = panel.id;

        panel.appendChild(el('p', {}, [document.createTextNode(data.draft || '')]));
        if (data.citations && data.citations.length) {
            var cites = el('ul', { class: 'common-goals-muted' });
            data.citations.forEach(function (c) {
                cites.appendChild(el('li', {}, [el('a', { href: c.url, text: 'Fuente #' + c.id }), document.createTextNode(' — “' + c.quote + '”')]));
            });
            panel.appendChild(cites);
        }
        if (data.missing_info) panel.appendChild(el('p', { class: 'common-goals-muted', text: 'Por verificar: ' + data.missing_info }));

        var actions = el('div', { class: 'common-goals-btn-row' });
        actions.appendChild(el('button', { type: 'button', class: 'common-goals-btn', text: i18n.aiUseDraft || 'Usar', onclick: function () {
            textarea.value = data.draft || '';
            panel.remove();
        }}));
        actions.appendChild(el('button', { type: 'button', class: 'common-goals-btn common-goals-btn-ghost', text: 'Cerrar', onclick: function () { panel.remove(); }}));
        panel.appendChild(actions);

        textarea.parentElement.insertBefore(panel, textarea.nextSibling);
    }

    /* ------------------------------------------------------------------ *
     * 04 · Summarize: layered thread summary on demand
     * ------------------------------------------------------------------ */

    function enhanceSummarize() {
        var button = document.querySelector('[data-cg-ai="summarize"]');
        if (!button) return;
        var target = document.querySelector('[data-cg-ai-summary-target]') || button.parentElement;

        button.addEventListener('click', function () {
            setBusy(button, true);
            callAi('summarize', {
                contribution_id: parseInt(button.dataset.contributionId, 10) || 0,
                community_id: parseInt(button.dataset.communityId, 10) || 0
            }).then(function (data) {
                renderSummary(target, data);
            }).catch(function (err) {
                injectNotice(target, err.message || (i18n.aiError), 'error');
            }).finally(function () { setBusy(button, false); });
        });
    }

    function renderSummary(container, data) {
        var prev = container.querySelector('.common-goals-ai-summary');
        if (prev) prev.remove();
        var panel = el('div', { class: 'common-goals-ai-summary common-goals-ai-panel' });

        function section(title, items) {
            if (!items || !items.length) return;
            panel.appendChild(el('strong', { text: title }));
            var ul = el('ul', { class: 'common-goals-muted' });
            items.forEach(function (t) { ul.appendChild(el('li', { text: t })); });
            panel.appendChild(ul);
        }

        section('Acuerdos', data.agreements);
        section('Sin resolver', data.open_points);
        section('Desacuerdos', data.disagreements);
        section('Próximos pasos', data.next_steps);
        if (data.cutoff_after && data.total_responses > data.cutoff_after) {
            panel.appendChild(el('p', { class: 'common-goals-muted', text: 'Cubre hasta la respuesta #' + data.cutoff_after + ' de ' + data.total_responses + '.' }));
        }
        container.insertBefore(panel, container.firstChild);
    }
})();
