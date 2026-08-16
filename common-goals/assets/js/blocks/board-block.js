/**
 * Common Goals Board — Gutenberg editor component.
 */
(function () {
    'use strict';

    var el = wp.element.createElement;
    var Fragment = wp.element.Fragment;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var PanelBody = wp.components.PanelBody;
    var TextControl = wp.components.TextControl;

    wp.blocks.registerBlockType('common-goals/board', {
        edit: function (props) {
            var attributes = props.attributes;

            return el(Fragment, null,
                el(InspectorControls, null,
                    el(PanelBody, { title: 'Common Goals', initialOpen: true },
                        el(TextControl, {
                            label: 'Goal ID',
                            type: 'number',
                            value: attributes.goal_id || '',
                            onChange: function (value) {
                                props.setAttributes({ goal_id: parseInt(value, 10) || 0 });
                            },
                            help: 'Leave 0 to show the most recent active goal.'
                        }),
                        el(TextControl, {
                            label: 'Community ID',
                            type: 'number',
                            value: attributes.community_id || '',
                            onChange: function (value) {
                                props.setAttributes({ community_id: parseInt(value, 10) || 0 });
                            },
                            help: 'Optional. Scope the board to a specific community.'
                        })
                    )
                ),
                el('div', { className: 'common-goals-block-preview' },
                    el('p', null, el('strong', null, 'Common Goals Board')),
                    el('p', { style: { color: '#666' } }, 'Goal ID: ' + (attributes.goal_id || 'Latest active')),
                    el('p', { style: { color: '#666' } }, 'Community ID: ' + (attributes.community_id || 'Default/latest')),
                    el('p', { style: { color: '#999', fontSize: '13px' } }, 'The community board will render on the frontend.')
                )
            );
        },

        save: function () {
            return null;
        }
    });
})();
