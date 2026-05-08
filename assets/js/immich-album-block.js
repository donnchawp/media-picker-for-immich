( function ( blocks, blockEditor, components, element, ssr, i18n ) {
    'use strict';
    var registerBlockType = blocks.registerBlockType;
    var useBlockProps     = blockEditor.useBlockProps;
    var InspectorControls = blockEditor.InspectorControls;
    var Placeholder       = components.Placeholder;
    var Button            = components.Button;
    var PanelBody         = components.PanelBody;
    var RangeControl      = components.RangeControl;
    var SelectControl     = components.SelectControl;
    var ToggleControl     = components.ToggleControl;
    var Fragment          = element.Fragment;
    var el                = element.createElement;
    var ServerSideRender  = ssr;
    var __                = i18n.__;

    function openAlbumPicker( onPicked ) {
        var frame = wp.media( {
            title:    __( 'Choose an Immich album', 'media-picker-for-immich' ),
            library:  { type: 'immich-album' },
            button:   { text: __( 'Insert album', 'media-picker-for-immich' ) },
            multiple: false
        } );
        frame.once( 'immich:album-selected', function ( album ) {
            if ( album && album.id ) { onPicked( album ); }
            // Defer close: calling frame.close() synchronously inside the event
            // tears down the Backbone view that is still mid-dispatch. The
            // timeout lets the current call-stack unwind first.
            setTimeout( function () { frame.close(); }, 0 );
        } );
        frame.on( 'close', function () {
            frame.off( 'immich:album-selected' );
        } );
        frame.open();
    }

    function buildInspector( attrs, setAttrs, onPick ) {
        return el(
            InspectorControls,
            null,
            el(
                PanelBody,
                { title: __( 'Album', 'media-picker-for-immich' ), initialOpen: true },
                el( Button, { variant: 'secondary', onClick: onPick },
                    attrs.albumId
                        ? __( 'Change album', 'media-picker-for-immich' )
                        : __( 'Pick album', 'media-picker-for-immich' ) ),
                attrs.albumId
                    ? el( 'p', { style: { fontSize: '12px', color: '#757575', marginTop: '8px' } },
                        __( 'Album ID:', 'media-picker-for-immich' ) + ' ' + attrs.albumId )
                    : null
            ),
            el(
                PanelBody,
                { title: __( 'Layout', 'media-picker-for-immich' ), initialOpen: true },
                el( RangeControl, {
                    label: __( 'Columns', 'media-picker-for-immich' ),
                    min: 1, max: 8, value: attrs.columns,
                    onChange: function ( v ) { setAttrs( { columns: v } ); }
                } ),
                el( SelectControl, {
                    label: __( 'Image size', 'media-picker-for-immich' ),
                    value: attrs.imageSize,
                    options: [
                        { label: __( 'Thumbnail (~250 px)', 'media-picker-for-immich' ), value: 'thumbnail' },
                        { label: __( 'Preview (~1440 px)', 'media-picker-for-immich' ), value: 'preview' },
                        { label: __( 'Full size', 'media-picker-for-immich' ), value: 'fullsize' }
                    ],
                    onChange: function ( v ) { setAttrs( { imageSize: v } ); }
                } ),
                el( SelectControl, {
                    label: __( 'Sort order', 'media-picker-for-immich' ),
                    value: attrs.sortOrder,
                    options: [
                        { label: __( 'Album order', 'media-picker-for-immich' ), value: 'default' },
                        { label: __( 'Oldest first', 'media-picker-for-immich' ), value: 'oldest' },
                        { label: __( 'Newest first', 'media-picker-for-immich' ), value: 'newest' },
                        { label: __( 'Random', 'media-picker-for-immich' ), value: 'random' }
                    ],
                    onChange: function ( v ) { setAttrs( { sortOrder: v } ); }
                } ),
                el( RangeControl, {
                    label: __( 'Limit (0 = all)', 'media-picker-for-immich' ),
                    min: 0, max: 200, value: attrs.limit,
                    onChange: function ( v ) { setAttrs( { limit: v } ); }
                } )
            ),
            el(
                PanelBody,
                { title: __( 'Display', 'media-picker-for-immich' ), initialOpen: false },
                el( ToggleControl, {
                    label: __( 'Lightbox', 'media-picker-for-immich' ),
                    checked: attrs.lightbox,
                    onChange: function ( v ) { setAttrs( { lightbox: v } ); }
                } ),
                el( ToggleControl, {
                    label: __( 'Show captions', 'media-picker-for-immich' ),
                    checked: attrs.showCaptions,
                    onChange: function ( v ) { setAttrs( { showCaptions: v } ); }
                } ),
                el( ToggleControl, {
                    label: __( 'Show "View on Immich" link', 'media-picker-for-immich' ),
                    help: __( 'Append a link to the album in Immich when more assets are hidden. Requires your Immich URL to be reachable from visitors’ browsers.', 'media-picker-for-immich' ),
                    checked: attrs.showAlbumLink,
                    onChange: function ( v ) { setAttrs( { showAlbumLink: v } ); }
                } )
            )
        );
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

            var inspector = buildInspector( attrs, setAttrs, pick );

            var body = attrs.albumId
                ? el( ServerSideRender, {
                    block: 'immich/album-gallery',
                    attributes: attrs
                } )
                : el( Placeholder, {
                    icon: 'format-gallery',
                    label: __( 'Immich Album Gallery', 'media-picker-for-immich' ),
                    instructions: __( 'Pick an album from your Immich server.', 'media-picker-for-immich' )
                }, el( Button, { variant: 'primary', onClick: pick }, __( 'Pick album', 'media-picker-for-immich' ) ) );

            return el( Fragment, null, inspector, el( 'div', blockProps, body ) );
        },
        save: function () { return null; }
    } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element, window.wp.serverSideRender, window.wp.i18n );
