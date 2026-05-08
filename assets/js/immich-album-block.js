( function ( blocks, blockEditor, components, element, i18n ) {
    'use strict';
    var registerBlockType = blocks.registerBlockType;
    var useBlockProps     = blockEditor.useBlockProps;
    var Placeholder       = components.Placeholder;
    var Button            = components.Button;
    var el                = element.createElement;
    var __                = i18n.__;

    function openAlbumPicker( onPicked ) {
        var frame = wp.media( {
            title:    __( 'Choose an Immich album', 'media-picker-for-immich' ),
            library:  { type: 'immich-album' },
            button:   { text: __( 'Insert album', 'media-picker-for-immich' ) },
            multiple: false
        } );
        frame.once( 'immich:album-selected', function ( album ) {
            if ( album && album.id ) {
                onPicked( album );
            }
            // Defer close so we don't tear down the dispatching view mid-event.
            setTimeout( function () { frame.close(); }, 0 );
        } );
        frame.on( 'close', function () {
            frame.off( 'immich:album-selected' );
        } );
        frame.open();
    }

    registerBlockType( 'immich/album-gallery', {
        edit: function ( props ) {
            var blockProps = useBlockProps();
            var attrs      = props.attributes;
            var setAttrs   = props.setAttributes;

            function pick() {
                openAlbumPicker( function ( album ) {
                    setAttrs( { albumId: album.id } );
                } );
            }

            var label = attrs.albumId
                ? __( 'Change album', 'media-picker-for-immich' )
                : __( 'Pick album', 'media-picker-for-immich' );

            return el(
                'div',
                blockProps,
                el(
                    Placeholder,
                    {
                        icon: 'format-gallery',
                        label: __( 'Immich Album Gallery', 'media-picker-for-immich' ),
                        instructions: attrs.albumId
                            ? __( 'Album:', 'media-picker-for-immich' ) + ' ' + attrs.albumId
                            : __( 'Pick an album from your Immich server.', 'media-picker-for-immich' )
                    },
                    el( Button, { variant: 'primary', onClick: pick }, label )
                )
            );
        },
        save: function () { return null; }
    } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element, window.wp.i18n );
