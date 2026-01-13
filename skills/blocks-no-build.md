---
title: WordPress Blocks Without Build Step
description: Create Gutenberg blocks using vanilla JavaScript without JSX or compilation
category: blocks
---

# Creating WordPress Blocks Without a Build Step

You can create WordPress blocks without JSX or a build process by using `wp.element.createElement` directly.

## Common Mistakes to Avoid

### Mistake 1: Using ES6/JSX (requires build step)

```javascript
// WRONG: ES6 imports don't work in browsers
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';

// WRONG: JSX syntax requires compilation
return <div className="my-block"><p>Hello</p></div>;

// WRONG: Importing but never calling registerBlockType
import { registerBlockType } from '@wordpress/blocks';
// ... edit and save functions defined but block never registered
```

**Why this fails:** Browsers cannot execute ES6 `import` statements or JSX syntax directly. These require transpilation via Babel/webpack.

### Mistake 2: Expecting edit() interactivity on frontend

```javascript
// WRONG: Thinking button clicks in edit() work for site visitors
edit: function( props ) {
    return el( 'button', {
        onClick: function() { alert('clicked!'); }  // Only works in editor!
    }, 'Click me' );
},
save: function( props ) {
    return el( 'button', {}, 'Click me' );  // Static HTML, no click handler
}
```

**Why this fails:**

| Context | What Runs | Interactive? |
|---------|-----------|--------------|
| Editor (admin) | `edit()` function | Yes - React components with handlers |
| Frontend (visitors) | `save()` output only | No - just static HTML in database |

The `save()` function outputs static HTML that gets stored in the database. Event handlers, state, and any JavaScript logic in `edit()` do NOT carry over to the frontend.

**If your block needs frontend interactivity (buttons, animations, dynamic content), you MUST add a view_script.** See "Frontend Interactivity" section below.

## Correct No-Build Pattern

Use the global `wp` object and `createElement`:

```javascript
( function( blocks, element, blockEditor ) {
    var el = element.createElement;
    var useBlockProps = blockEditor.useBlockProps;

    // REQUIRED: You must call registerBlockType
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

## Frontend Interactivity

**CRITICAL:** The `save()` function outputs static HTML stored in the database. JavaScript in `edit()` (click handlers, state, effects) only works in the editor, NOT for site visitors.

If your block has buttons, animations, or any dynamic behavior for visitors, you MUST add a `view_script`.

### Complete Example: Interactive Button Block

**PHP Registration (plugin.php):**
```php
function my_register_block() {
    // Editor script (runs in admin)
    wp_register_script(
        'my-block-editor',
        plugins_url( 'blocks/my-block.js', __FILE__ ),
        array( 'wp-blocks', 'wp-element', 'wp-block-editor' ),
        filemtime( plugin_dir_path( __FILE__ ) . 'blocks/my-block.js' )
    );

    // View script (runs on frontend for visitors)
    wp_register_script(
        'my-block-view',
        plugins_url( 'blocks/my-block-view.js', __FILE__ ),
        array(),  // No wp dependencies needed
        filemtime( plugin_dir_path( __FILE__ ) . 'blocks/my-block-view.js' )
    );

    register_block_type( 'my-plugin/my-block', array(
        'editor_script' => 'my-block-editor',
        'view_script'   => 'my-block-view',  // THIS IS REQUIRED FOR FRONTEND JS
    ) );
}
add_action( 'init', 'my_register_block' );
```

**Editor Script (blocks/my-block.js):**
```javascript
( function( blocks, element, blockEditor ) {
    var el = element.createElement;

    blocks.registerBlockType( 'my-plugin/my-block', {
        title: 'My Interactive Block',
        icon: 'button',
        category: 'widgets',
        attributes: {
            items: { type: 'array', default: ['Option A', 'Option B', 'Option C'] }
        },
        edit: function( props ) {
            // Editor preview - can be interactive here
            return el( 'div', blockEditor.useBlockProps(),
                el( 'button', { className: 'my-block-button' }, 'Click me' ),
                el( 'div', { className: 'my-block-result' }, 'Result appears here' )
            );
        },
        save: function( props ) {
            // Static HTML output - NO event handlers here
            // Data attributes pass info to frontend JS
            return el( 'div', blockEditor.useBlockProps.save(),
                el( 'button', { className: 'my-block-button' }, 'Click me' ),
                el( 'div', { className: 'my-block-result' }, '' ),
                el( 'script', {
                    type: 'application/json',
                    className: 'my-block-data'
                }, JSON.stringify( props.attributes.items ) )
            );
        }
    } );
}( window.wp.blocks, window.wp.element, window.wp.blockEditor ) );
```

**Frontend Script (blocks/my-block-view.js):**
```javascript
document.addEventListener( 'DOMContentLoaded', function() {
    // Find all instances of this block on the page
    document.querySelectorAll( '.wp-block-my-plugin-my-block' ).forEach( function( block ) {
        var button = block.querySelector( '.my-block-button' );
        var result = block.querySelector( '.my-block-result' );
        var dataEl = block.querySelector( '.my-block-data' );
        var items = dataEl ? JSON.parse( dataEl.textContent ) : [];

        if ( button && result ) {
            button.addEventListener( 'click', function() {
                // Pick random item and display
                var randomItem = items[ Math.floor( Math.random() * items.length ) ];
                result.textContent = randomItem;
            } );
        }
    } );
} );
```

### Passing Data from Editor to Frontend

Use a hidden element or data attributes to pass configuration:

```javascript
// In save() - embed data as JSON
el( 'script', {
    type: 'application/json',
    className: 'block-config'
}, JSON.stringify( props.attributes ) )

// In view script - read the data
var config = JSON.parse( block.querySelector('.block-config').textContent );
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

For blocks that need PHP logic or database queries, use server-side rendering:

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

## Required Dependencies

Add these to your `wp_register_script` dependencies array:

- `wp-blocks` - Block registration API (registerBlockType)
- `wp-element` - React-like createElement
- `wp-block-editor` - Editor components (useBlockProps, RichText, InspectorControls)
- `wp-components` - UI components (Button, TextControl, PanelBody, etc.)
- `wp-server-side-render` - ServerSideRender component (if using dynamic blocks)

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

## Checklist

Before your block will work:

1. **No ES6 imports** - Use `window.wp.*` globals, NOT `import { } from '@wordpress/...'`
2. **No JSX** - Use `wp.element.createElement` (alias as `el`), NOT `<tags>`
3. **registerBlockType called** - Not just imported, actually invoked
4. **PHP registers script** - `wp_register_script` with correct dependencies
5. **PHP registers block** - `register_block_type()` with `editor_script`

**If your block has ANY frontend interactivity (buttons, clicks, animations):**

6. **view_script registered** - Separate `wp_register_script` for frontend JS
7. **view_script added to block** - `'view_script' => 'handle'` in `register_block_type()`
8. **Frontend JS file created** - Vanilla JS that finds blocks and adds event listeners
9. **Data passed via HTML** - Use hidden JSON or data-attributes, not JS variables
