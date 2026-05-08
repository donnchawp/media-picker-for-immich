( function ( blocks, blockEditor, components, element, i18n ) {
    'use strict';
    var registerBlockType = blocks.registerBlockType;
    var useBlockProps     = blockEditor.useBlockProps;
    var Placeholder       = components.Placeholder;
    var el                = element.createElement;
    var __                = i18n.__;

    registerBlockType( 'immich/album-gallery', {
        edit: function ( props ) {
            var blockProps = useBlockProps();
            return el(
                'div',
                blockProps,
                el(
                    Placeholder,
                    {
                        icon: 'format-gallery',
                        label: __( 'Immich Album Gallery', 'media-picker-for-immich' ),
                        instructions: __( 'Pick an album from your Immich server.', 'media-picker-for-immich' )
                    },
                    el( 'p', null, props.attributes.albumId
                        ? __( 'Album:', 'media-picker-for-immich' ) + ' ' + props.attributes.albumId
                        : __( 'No album selected yet.', 'media-picker-for-immich' )
                    )
                )
            );
        },
        save: function () { return null; }
    } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element, window.wp.i18n );
