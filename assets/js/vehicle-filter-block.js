/* global wp */
(function (blocks, element, blockEditor, components, serverSideRender, i18n) {
    'use strict';

    var el = element.createElement;
    var __ = i18n.__;
    var InspectorControls = blockEditor.InspectorControls;
    var PanelBody = components.PanelBody;
    var TextControl = components.TextControl;
    var ServerSideRender = serverSideRender;

    blocks.registerBlockType('rwbe/vehicle-filter', {
        apiVersion: 2,
        title: __('RWBE — Filtro por Veículo (Marca + Modelo)', 'rwbe-product-importer'),
        description: __('Filtra a loja por marca e modelo de veículo.', 'rwbe-product-importer'),
        category: 'widgets',
        icon: 'car',
        keywords: ['rwbe', 'veículo', 'marca', 'modelo', 'filtro'],
        supports: { html: false },
        attributes: {
            make_label: { type: 'string', default: '' },
            model_label: { type: 'string', default: '' },
            shop_url: { type: 'string', default: '' }
        },

        edit: function (props) {
            var a = props.attributes;

            var inspector = el(
                InspectorControls,
                {},
                el(
                    PanelBody,
                    { title: __('Definições do filtro', 'rwbe-product-importer'), initialOpen: true },
                    el(TextControl, {
                        label: __('Rótulo da marca', 'rwbe-product-importer'),
                        value: a.make_label,
                        placeholder: __('Marca', 'rwbe-product-importer'),
                        onChange: function (v) { props.setAttributes({ make_label: v }); }
                    }),
                    el(TextControl, {
                        label: __('Rótulo do modelo', 'rwbe-product-importer'),
                        value: a.model_label,
                        placeholder: __('Modelo', 'rwbe-product-importer'),
                        onChange: function (v) { props.setAttributes({ model_label: v }); }
                    }),
                    el(TextControl, {
                        label: __('URL de resultados (vazio = Loja)', 'rwbe-product-importer'),
                        value: a.shop_url,
                        placeholder: '/loja/',
                        onChange: function (v) { props.setAttributes({ shop_url: v }); }
                    })
                )
            );

            var preview = el(ServerSideRender, {
                block: 'rwbe/vehicle-filter',
                attributes: a
            });

            return el('div', blockEditor.useBlockProps ? blockEditor.useBlockProps() : {}, inspector, preview);
        },

        // Dynamic block — server-rendered on the front end.
        save: function () { return null; }
    });
})(
    window.wp.blocks,
    window.wp.element,
    window.wp.blockEditor,
    window.wp.components,
    window.wp.serverSideRender,
    window.wp.i18n
);
