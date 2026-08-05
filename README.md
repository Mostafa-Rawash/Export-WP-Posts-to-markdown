# Posts Markdown

WordPress plugin for exporting posts to Markdown files with YAML front matter and importing them back. Supports Gutenberg blocks, HTML preservation, code blocks, images, and full round-trip fidelity.

## Features

- **Export** posts to Markdown files in a ZIP archive with YAML front matter
- **Import** Markdown files or ZIP archives back into WordPress
- **Gutenberg Support**: Content stored as native WordPress blocks
- **HTML Preservation**: Raw HTML blocks preserved during import/export
- **Code Blocks**: Fenced code blocks with language support
- **Media Handling**: Local images in `_images/` folder are uploaded and reused
- **Front Matter**: Extensive YAML front matter support for post metadata
- **Debug Tools**: Built-in debug log and post content inspector
- **Cloud Sync**: Optional GitHub and Google Drive synchronization

## Installation

1. Copy the plugin folder into `wp-content/plugins/`
2. Activate it from **Plugins > Installed Plugins**
3. Access via **Posts Markdown** in the admin menu

## Usage

### Export
1. Go to **Posts Markdown > Dashboard** or **Posts Markdown > Export**
2. Optionally filter by status, author, or date range
3. Click **Export All Published** or configure filters
4. Download `wordpress-markdown-export-YYYYMMDD-HHMMSS.zip`

### Import
1. Go to **Posts Markdown > Import**
2. Upload either:
   - A single `.md` file, or
   - A `.zip` containing `.md` files and an optional `_images/` directory
3. Posts with matching `id` in front matter are updated; otherwise new posts are created

## Content Handling

### Import Flow

Markdown content is converted to WordPress Gutenberg blocks:

| Markdown | WordPress Block |
|----------|-----------------|
| `# Heading` | `<!-- wp:heading --><h1>Heading</h1><!-- /wp:heading -->` |
| Paragraph text | `<!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph -->` |
| `- Item` | `<!-- wp:list --><ul><li>Item</li></ul><!-- /wp:list -->` |
| `1. Item` | `<!-- wp:list {"ordered":true} --><ol><li>Item</li></ol><!-- /wp:list -->` |
| `| Col1 | Col2 |` | `<!-- wp:table --><table>...</table><!-- /wp:table -->` |
| `> Quote` | `<!-- wp:quote --><blockquote>...</blockquote><!-- /wp:quote -->` |
| ` ```code``` ` | `<!-- wp:code --><pre><code>code</code></pre><!-- /wp:code -->` |

**HTML blocks** are wrapped in `<!-- wp:html -->` for preservation:
```markdown
<div class="custom-section">
    <p>This HTML is preserved exactly</p>
</div>
```
Imports as:
```html
<!-- wp:html -->
<div class="custom-section">
    <p>This HTML is preserved exactly</p>
</div>
<!-- /wp:html -->
```

### Export Flow

1. **Preserve** `<!-- wp:html -->` and `<!-- wp:code -->` blocks
2. **Strip** Gutenberg block comments
3. **Convert** HTML to Markdown syntax
4. **Restore** preserved HTML/code blocks

### Round-Trip Fidelity

| Element | Import | Export | Status |
|---------|--------|--------|--------|
| HTML blocks | Wrapped in `wp:html` | Preserved as raw HTML | ✅ |
| Code blocks | `wp:code` with language class | Fenced code block | ✅ |
| Ordered lists | `wp:list {"ordered":true}` | `1. Item` format | ✅ |
| Unordered lists | `wp:list` | `- Item` format | ✅ |
| Tables | `wp:table` | Markdown table | ✅ |
| Headings | `wp:heading` | `# Heading` | ✅ |
| Blockquotes | `wp:quote` | `> Quote` | ✅ |
| Inline formatting | `<strong>`, `<em>` | `**bold**`, `*italic*` | ✅ |

## Front Matter Reference

Front matter is YAML between `---` lines at the top of the file:

```yaml
---
title: "Post Title"
date: 2024-12-01 20:14:59
status: "publish"
slug: "post-slug"
categories:
  - Category1
  - Category2
tags:
  - tag1
  - tag2
featured_image: _images/post-image.jpg
excerpt: "A brief summary"
id: 123
---
```

### Supported Fields

| Field | Description |
|-------|-------------|
| `title` | Post title |
| `status` / `post_status` | `publish`, `draft`, `pending`, `future` |
| `date` / `post_date` | Publication datetime |
| `slug` | URL slug |
| `author` | Author username or ID |
| `categories` | Array of category names |
| `tags` | Array of tag names |
| `taxonomy` | Array of `taxonomy: term` pairs |
| `excerpt` / `post_excerpt` | Post excerpt |
| `featured_image` | Path under `_images/` |
| `id` | WordPress post ID (for updates) |
| `menu_order` | Order for pages |
| `page_template` | Template filename |
| `stick_post` | `yes` to make sticky |
| `custom_fields` | Array of `key: value` pairs |
| `folder_path` | Export subfolder path |
| `meta_description` | Rank Math SEO description |
| `meta_keywords` | Rank Math focus keywords |
| `skip_file` | `yes` to skip import |

### Array Formats

Inline:
```yaml
tags: ["tag1", "tag2"]
```

Block list:
```yaml
tags:
  - tag1
  - tag2
```

## Images

Place images in `_images/` folder inside your ZIP:

```markdown
![Alt text](_images/example.jpg "Optional caption")
```

- Images uploaded once to Media Library and reused
- Obsidian-style `![[image.png]]` syntax supported
- Images can be referenced in `featured_image` front matter

## Code Blocks

Fenced code blocks with optional language:

```markdown
```javascript
function greet(name) {
    return `Hello, ${name}!`;
}
```
```

- Language is preserved during import (stored as CSS class)
- Code content is HTML-escaped properly

## Debug Tools

### Debug Log
Access at **Posts Markdown > Dashboard** - scroll to **Debug Log** section:
- Shows all import/export operations with timestamps
- Status indicators: Success (green), Error (red), Warning (yellow), Info (blue)
- Clear log button available

### Post Content Inspector
At **Posts Markdown > Dashboard** - under **Debug Tools**:
- Enter a Post ID to view raw WordPress content
- Shows exactly what WordPress stores in `post_content`
- Useful for debugging block conversion issues

## Admin URLs

The plugin uses custom menu pages. Correct URL format:

```
admin.php?page=posts-markdown           # Dashboard
admin.php?page=posts-markdown-export    # Export
admin.php?page=posts-markdown-import    # Import
admin.php?page=posts-markdown-integrations  # Settings
```

## Notes & Limits

- Only `post` post type is handled by default
- Remote URLs for `featured_image` are not supported; use `_images/`
- Maximum 100 debug log entries retained
- HTML in `wp:html` blocks is preserved exactly (no sanitization)

## File Structure

```
posts-markdown.php                    # Main plugin file
includes/
├── class-wp-posts-markdown.php       # Admin UI, handlers
├── class-pwpm-exporter.php           # Export logic
├── class-pwpm-importer.php           # Import logic
├── class-pwpm-markdown.php           # Markdown conversion
├── class-pwpm-media.php              # Image handling
└── class-pwpm-sync.php               # GitHub/Drive sync
```

## Changelog

### 1.1.0
- Added Polylang support: `lang` and `translations` exported, languages and translation links restored on import
- Obsidian `![[image.png]]` syntax now works on import, including `![[image.png|Alt text]]` and `|300` size suffixes
- `featured_image` now accepts a bare filename or wiki syntax, not just an explicit `_images/` path
- Fixed links exporting with `****` instead of `**` — links are no longer force-bolded on export
- Fixed fatal error when importing a single `.md` file

### 1.0.0
- Fixed HTML block preservation during export
- Fixed code block export with language support
- Fixed ordered list import (`1.`, `2.`, `3.` format)
- Added debug log table with status column
- Added post content inspector tool
- Improved image regex matching