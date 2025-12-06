# Export Posts to Markdown

WordPress admin tool that lets you **export all published posts to Markdown in a ZIP** and **import Markdown back into WordPress**. Imports support front‑matter fields for titles, status, dates, taxonomies, custom fields, sticky posts, page templates, and local images packaged in a `_images/` folder.

## Features
- Export all published posts (`post` type) to Markdown files in a ZIP, with YAML front matter (including `id`) and body converted from HTML; supports filtering by status/author/date and skipping posts already exported.
- Import Markdown from a single `.md` or a ZIP containing multiple `.md` files.
- Update posts by ID found in the filename/front matter or create new ones; preserves original ID in meta when creating.
- Front matter support: `title`, `post_status`, `post_date`, `slug`, `menu_order`, `comment_status`, `page_template`, `stick_post`, `taxonomy` (tax: term), `categories`, `tags`, `custom_fields`, `post_excerpt`, `featured_image`, `skip_file`, `id`.
- Arrays in front matter can be provided as inline lists (`[ "foo", "bar" ]`) or YAML block lists (`tags:\n  - foo\n  - bar`).
- Media handling: include an `_images/` directory in ZIPs; images are uploaded once, reused, and can be set as featured images; Markdown image URLs are rewritten to uploaded URLs.
- Debug logs are shown as admin notices on the Tools page after runs.

## Installation
1) Copy the plugin folder into `wp-content/plugins/export-posts-to-markdown/`.
2) Activate it from **Plugins > Installed Plugins**.

## Usage
### Export
1) Go to **Tools > Export to Markdown**.
2) (Optional) Choose filters: status, author, date range, and whether to exclude posts already marked as exported.
3) Click **Download Markdown ZIP**. You will get `wordpress-markdown-export-YYYYMMDD-HHMMSS.zip`.

### Import
1) Go to **Tools > Export to Markdown**.
2) Upload either:
   - A single `.md` file, or
   - A ZIP containing `.md` files and an optional `_images/` directory for local images/featured images.
3) Submit to import. Posts with matching IDs are updated; otherwise new posts are created.

### What happens during import (add/edit flow)
1) Capability + nonce checks run.
2) ZIP? Media map is built from `_images/` entries and images are uploaded or reused (tracked via `_wpexportmd_source_path`).
3) Each `.md` file is parsed for front matter and content.
4) If `skip_file: yes`, the file is skipped.
5) Post lookup:
   - If `id` is in the filename or front matter and that post exists, it is **updated**.
   - Otherwise a new post is **created**; any provided `id` is saved in `_wpexportmd_original_id` for reference.
6) Applied fields: `title`, `post_status`, `post_date`, `slug`, `menu_order`, `comment_status`, `page_template`, `stick_post`, `post_excerpt`, `custom_fields`, `taxonomy`, `categories`, `tags`.
7) Content: Markdown is converted to HTML; images are rewritten to the uploaded/reused URLs. Markdown image titles become captions via `<figure><figcaption>`.
8) Featured image: if `featured_image` points to `_images/...`, it’s set from the uploaded/reused attachment.
9) Debug log: results and any issues are stored in a transient and appended to `wp-content/uploads/wpexportmd.log`.

## Markdown Front Matter Reference
Front matter is YAML between `---` lines at the top of the `.md` file.

- `title`: Post title.
- `post_status`: `publish` | `draft` | `pending` | `future`.
- `post_date`: Datetime, e.g. `2024-12-01 20:14:59`.
- `slug`: Post slug.
- `menu_order`: Integer.
- `comment_status`: `open` | `closed`.
- `page_template`: Template file name (for pages/templates in your theme).
- `stick_post`: `yes` to stick, `no` to unstick.
- `taxonomy`: Array of `taxonomy: term` pairs (e.g. `["genre: Fiction"]`).
- `categories`: Array of category names.
- `tags`: Array of tag names.
- `custom_fields`: Array of `key: value` pairs (e.g. `["foo: bar"]`).
- `post_excerpt`: Excerpt text.
- `featured_image`: Path under `_images/` in the ZIP (e.g. `_images/post-image-1.jpg` or `/_images/post-image-1.jpg`). Remote URLs are not supported.
- `skip_file`: `yes` to skip importing this file.
- `id` (optional): Used to update an existing post; if not found, the ID is stored in meta `_wpexportmd_original_id` on create.
- Arrays can be written inline (`["foo", "bar"]`) or as block lists:
  ```
  tags:
    - tag11
    - tag22
  categories:
    - Examples
    - Guides
  ```

## Images
- Place images in `_images/` inside your ZIP. Use them in Markdown like:
  ```
  ![Alt text](/_images/example.jpg "Caption")
  ![Alt text](_images/example.jpg "Caption")
  ```
- Both `_images/...` and `/_images/...` paths are accepted for inline images and `featured_image`.
- Images are uploaded to the Media Library once and reused on subsequent imports. `_wpexportmd_source_path` meta tracks the source path.

## Example
See `example-import.md` in this plugin for a full example of supported front matter and Markdown.

## Notes & Limits
- Only `post` post type is exported/imported by default.
- Remote URLs for `featured_image` are ignored; use `_images/`.
- Markdown/HTML conversion is intentionally basic; complex HTML may need manual adjustments.
- Each exported post is flagged in post meta (`_wpexportmd_exported`) with a timestamp; use the checkbox to skip previously exported posts.
