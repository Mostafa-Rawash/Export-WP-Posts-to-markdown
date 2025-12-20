# Export Posts to Markdown

WordPress admin tool that lets you **export all published posts to Markdown in a ZIP** and **import Markdown back into WordPress**. Imports support front matter fields for titles, status, dates, taxonomies, custom fields, sticky posts, page templates, Rank Math SEO fields, and local images packaged in a `_images/` folder.

## Features
- Export all published posts (`post` type) to Markdown files in a ZIP, with YAML front matter (including `id`) and body converted from HTML. Filenames use post titles (including Arabic) with filesystem-safe cleanup.
- Import Markdown from a single `.md` or a ZIP containing multiple `.md` files.
- Update posts by `id` found in front matter or create new ones; preserves the original ID in meta when creating.
- Front matter support: `title`, `status`, `post_status`, `post_date`, `slug`, `menu_order`, `comment_status`, `page_template`, `stick_post`, `taxonomy` (tax: term), `categories`, `tags`, `custom_fields`, `excerpt`, `post_excerpt`, `featured_image`, `folder_path`, `meta_description`, `meta_keywords`, `metadata`, `keyword`, `keywords`, `skip_file`, `id`.
- Arrays in front matter can be provided as inline lists (`[ "foo", "bar" ]`) or YAML block lists (`tags:\n  - foo\n  - bar`). Keyword lists are normalized to a comma-separated string on import for Rank Math.
- Media handling: include an `_images/` directory in ZIPs; images are uploaded once, reused, and can be set as featured images; Markdown image URLs are rewritten to uploaded URLs.
- Debug logs are shown as admin notices on the Tools page after runs.

## Installation
1) Copy the plugin folder into `wp-content/plugins/export-posts-to-markdown/`.
2) Activate it from **Plugins > Installed Plugins**.

## Usage
### Export
1) Go to **Tools > Export to Markdown**.
2) Click **Download Markdown ZIP**. You will get `wordpress-markdown-export-YYYYMMDD-HHMMSS.zip`.

### Import
1) Go to **Tools > Export to Markdown**.
2) Upload either:
   - A single `.md` file, or
   - A `.zip` containing `.md` files and an optional `_images/` directory.
3) Submit to import. Posts with matching `id` in front matter are updated; otherwise new posts are created.

### What happens during import (add/edit flow)
1) Capability + nonce checks run.
2) ZIP? Media map is built from `_images/` entries and images are uploaded or reused (tracked via `_wpexportmd_source_path`).
3) Each `.md` file is parsed for front matter and content.
4) If `skip_file: yes`, the file is skipped.
5) Post lookup:
   - If `id` is in the front matter and that post exists, it is **updated**.
   - Otherwise a new post is **created**; any provided `id` is saved in `_wpexportmd_original_id` for reference.
6) Applied fields: `title`, `status`, `post_date`, `slug`, `menu_order`, `comment_status`, `page_template`, `stick_post`, `post_excerpt`, `custom_fields`, `taxonomy`, `categories`, `tags`, `folder_path`, `meta_description`, `meta_keywords`.
7) Content: Markdown is converted to HTML; images are rewritten to the uploaded/reused URLs. Markdown image titles become captions via `<figure><figcaption>`.
7) Content is stored as Gutenberg blocks (paragraphs/headings/lists/tables/etc.) instead of a single Classic block.
8) Featured image: if `featured_image` points to `_images/...`, it is set from the uploaded/reused attachment.
9) Debug log: results and any issues are stored in a transient and appended to `wp-content/uploads/wpexportmd.log`.

## Markdown Front Matter Reference
Front matter is YAML between `---` lines at the top of the `.md` file.

- `title`: Post title.
- `status`: `publish` | `draft` | `pending` | `future` (preferred).
- `post_status`: Same as `status` (legacy key).
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
- `excerpt`: Excerpt text (legacy key).
- `post_excerpt`: Excerpt text (preferred).
- `folder_path`: Optional subfolder path used on export (e.g. `Clients/Acme`).
- `meta_description`: Rank Math meta description (preferred).
- `meta_keywords`: Rank Math focus keyword(s) (preferred).
- `metadata`: Rank Math meta description (legacy key).
- `keyword` / `keywords`: Rank Math focus keyword(s) (legacy keys).
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
- Obsidian-style image embeds like `![[image.png]]` are supported and are assumed to live under `_images/`.
- Images are uploaded to the Media Library once and reused on subsequent imports. `_wpexportmd_source_path` meta tracks the source path.

## Notes & Limits
- Only `post` post type is exported/imported by default.
- Remote URLs for `featured_image` are ignored; use `_images/`.
- Markdown/HTML conversion is intentionally basic; complex HTML may need manual adjustments.
- Rank Math SEO fields are stored in `rank_math_description`, `rank_math_focus_keyword`, and `rank_math_focus_keywords`.
