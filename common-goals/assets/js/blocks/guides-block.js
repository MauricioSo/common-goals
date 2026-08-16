/**
 * Common Goals Guides — Gutenberg editor component.
 */
(function () {
    'use strict';

    var el = wp.element.createElement;
    var Fragment = wp.element.Fragment;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var PanelBody = wp.components.PanelBody;
    var RangeControl = wp.components.RangeControl;

    wp.blocks.registerBlockType('common-goals/guides', {
        edit: function (props) {
            var attributes = props.attributes;

            return el(Fragment, null,
                el(InspectorControls, null,
                    el(PanelBody, { title: 'Common Goals Guides', initialOpen: true },
                        el(RangeControl, {
                            label: 'Number of guides',
                            value: attributes.limit || 20,
                            onChange: function (value) {
                                props.setAttributes({ limit: value });
                            },
                            min: 1,
                            max: 50
                        })
                    )
                ),
                el('div', { className: 'common-goals-block-preview' },
                    el('p', null, el('strong', null, 'Common Goals Guides')),
                    el('p', { style: { color: '#999', fontSize: '13px' } }, 'Showing ' + (attributes.limit || 20) + ' published guides on the frontend.')
                )
            );
        },

        save: function () {
            return null;
        }
    });
})();
