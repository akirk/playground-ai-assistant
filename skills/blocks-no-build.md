---
title: WordPress Blocks Without Build Step
description: Create Gutenberg blocks using vanilla JavaScript without JSX or compilation
category: blocks
---

# Creating WordPress Blocks Without a Build Step

You can create WordPress blocks without JSX or a build process by using `wp.element.createElement` directly.

## Basic Block Structure

```javascript
( function( blocks, element, blockEditor ) {
    var el = element.createElement;
    var useBlockProps = blockEditor.useBlockProps;

    blocks.registerBlockType( 'my-plugin/my-block', {
        title: 'My Block',
        icon: 'smiley',
        category: 'widgets',
        attributes: {
            content: {
                type: 'string',
                default: ''
            }
        },
        edit: function( props ) {
            var blockProps = useBlockProps();
            return el( 'div', blockProps,
                el( 'p', {}, props.attributes.content || 'Edit me!' )
            );
        },
        save: function( props ) {
            var blockProps = useBlockProps.save();
            return el( 'div', blockProps,
                el( 'p', {}, props.attributes.content )
            );
        }
    } );
}( window.wp.blocks, window.wp.element, window.wp.blockEditor ) );
```

## PHP Registration

```php
function my_register_block() {
    wp_register_script(
        'my-block-script',
        plugins_url( 'blocks/my-block.js', __FILE__ ),
        array( 'wp-blocks', 'wp-element', 'wp-block-editor' ),
        filemtime( plugin_dir_path( __FILE__ ) . 'blocks/my-block.js' )
    );

    register_block_type( 'my-plugin/my-block', array(
        'editor_script' => 'my-block-script',
    ) );
}
add_action( 'init', 'my_register_block' );
```

## Using Components

Access WordPress components via global `wp.components`:

```javascript
var TextControl = wp.components.TextControl;
var Button = wp.components.Button;
var PanelBody = wp.components.PanelBody;
var InspectorControls = wp.blockEditor.InspectorControls;

// In edit function:
edit: function( props ) {
    var blockProps = useBlockProps();

    return el( 'div', {},
        // Sidebar controls
        el( InspectorControls, {},
            el( PanelBody, { title: 'Settings' },
                el( TextControl, {
                    label: 'Title',
                    value: props.attributes.title,
                    onChange: function( val ) {
                        props.setAttributes({ title: val });
                    }
                })
            )
        ),
        // Block content
        el( 'div', blockProps,
            el( 'h2', {}, props.attributes.title )
        )
    );
}
```

## Using RichText

```javascript
var RichText = wp.blockEditor.RichText;

edit: function( props ) {
    var blockProps = useBlockProps();

    return el( RichText, Object.assign( {}, blockProps, {
        tagName: 'p',
        value: props.attributes.content,
        onChange: function( val ) {
            props.setAttributes({ content: val });
        },
        placeholder: 'Enter text...'
    }));
},
save: function( props ) {
    var blockProps = useBlockProps.save();

    return el( RichText.Content, Object.assign( {}, blockProps, {
        tagName: 'p',
        value: props.attributes.content
    }));
}
```

## Dynamic Blocks (Server-Side Render)

For complex output, use server-side rendering:

```javascript
var ServerSideRender = wp.serverSideRender;

edit: function( props ) {
    var blockProps = useBlockProps();

    return el( 'div', blockProps,
        el( ServerSideRender, {
            block: 'my-plugin/my-block',
            attributes: props.attributes
        })
    );
},
save: function() {
    return null; // Rendered by PHP
}
```

PHP callback:
```php
register_block_type( 'my-plugin/my-block', array(
    'editor_script' => 'my-block-script',
    'render_callback' => function( $attributes ) {
        return '<div class="my-block">' . esc_html( $attributes['content'] ) . '</div>';
    },
) );
```

## Common Dependencies

- `wp-blocks` - Block registration API
- `wp-element` - React-like createElement
- `wp-block-editor` - Block editor components (useBlockProps, RichText, InspectorControls)
- `wp-components` - UI components (Button, TextControl, PanelBody, etc.)
- `wp-data` - Data/state management
- `wp-server-side-render` - ServerSideRender component

## createElement Syntax

```javascript
el( tagOrComponent, propsObject, ...children )

// Examples:
el( 'div', { className: 'wrapper' }, 'Hello' )
el( 'div', { className: 'wrapper' },
    el( 'h1', {}, 'Title' ),
    el( 'p', {}, 'Content' )
)
el( Button, { isPrimary: true, onClick: handleClick }, 'Click Me' )
```

## Tips

1. Wrap in IIFE to avoid global scope pollution
2. Use `Object.assign()` to merge props (no spread operator without build)
3. Check browser console for available components: `console.log(wp.components)`
4. For complex state, use `wp.element.useState` and `wp.element.useEffect`
