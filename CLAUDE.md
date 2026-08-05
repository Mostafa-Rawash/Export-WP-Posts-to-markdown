# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A single WordPress admin plugin ("Posts Markdown") that exports `post` post-type content to Markdown + YAML front matter in a ZIP, and imports it back. No build step, no dependency manager, no test suite. The repo *is* the plugin directory — drop it in `wp-content/plugins/` and activate.

`AGENTS.md` (gitignored, present locally) holds the coding-style rules — WordPress legacy style, 4 spaces, `array()` syntax, Yoda conditions for constants, naming table. `README.md` documents the user-facing behavior and the full front-matter field list.

## Commands

```bash
# Syntax check everything (the only "build" available)
find . -name "*.php" -exec php -l {} \;

# Optional, if installed globally
phpcs --standard=WordPress includes/
phpstan analyse includes/ --level=5
```

There is no way to run this outside WordPress: every file after `posts-markdown.php` bails on `! defined( 'ABSPATH' )` and the code calls WP functions throughout. Verification means installing into a WP site and using **Posts Markdown > Dashboard > Debug Log** plus the **Post Content Inspector** (enter a post ID, see raw `post_content`).

## Architecture

`posts-markdown.php` is a 15-line loader that instantiates `WP_Posts_Markdown`, which is the composition root: it constructs `PWPM_Markdown`, `PWPM_Media`, `PWPM_Sync`, then injects them plus **callbacks** (`array( $this, 'log_debug' )`, `array( $this, 'fail_and_die' )`, `array( $this, 'stream_file_to_browser' )`) into `PWPM_Exporter` and `PWPM_Importer`. The helper classes never call WP admin/HTTP-response APIs directly — they call the injected callables. Preserve this when adding code: a helper that needs to log or abort takes another callback, it does not reach back into the main class.

`WP_Posts_Markdown` also owns *all* admin surface: menu pages, `admin_post_*` handlers, AJAX handlers, the inline `<script>`/`<style>` in `render_dashboard_page()`, stats caching, and the activity/debug logs. There are no template files or asset files — HTML/CSS/JS are echoed inline.

### The two conversion pipelines

These are the heart of the plugin and live in `PWPM_Markdown` (1100+ lines, all regex/line-scanner based — no parser library).

**Export** (`PWPM_Exporter::post_to_markdown`) runs a strict four-step order and breaking it corrupts content:

1. `preserve_wp_html_blocks()` — pulls `wp:html`, `wp:code`, `wp:freeform` blocks out and substitutes `[[PWPM_HTML_n]]` / `[[PWPM_CODE_n]]` / `[[PWPM_FREEFORM_n]]` placeholders. `wp:code` is converted to a fenced block *here*, language recovered from `class="language-x"`.
2. `strip_gutenberg_blocks()` — deletes remaining block comments.
3. `unwrap_simple_p_tags()` → `html_to_markdown()` — regex HTML→Markdown, ending in `wp_strip_all_tags()`. This is why step 1 exists: anything not extracted first gets flattened.
4. `restore_wp_html_blocks()` — `str_replace` the placeholders back.

The exporter logs each step's length and whether every placeholder survived; that trail in the Debug Log is the intended way to diagnose content loss.

**Import** (`PWPM_Importer::import_markdown_post`) is `parse_front_matter()` → `validate_front_matter_design()` → `markdown_to_wp_blocks()` → `wp_insert_post`/`wp_update_post` → a fixed sequence of side-effect appliers (terms, custom fields, folder path, Rank Math, featured image, page template, sticky, Polylang).

`markdown_to_wp_blocks()` is a single-pass line scanner holding mutable state (`$in_code`, `$in_html_block` + `$html_nesting`, `$in_list`, `$in_table`). Every branch that starts a new construct must first flush the pending list and table blocks — that "flush the others" preamble is repeated in each branch on purpose. Raw HTML lines opening a tag in `$html_block_tags` are buffered by nesting depth and emitted as one `wp:html` block.

Note that `PWPM_Markdown` carries **three** conversion entry points: `markdown_to_wp_blocks()` (used by the importer), plus `markdown_to_html()` and `mixed_content_to_html()` which are currently unreferenced legacy paths. `PWPM_Importer::blockify_html()` / `blockify_node()` (a DOMDocument-based HTML→blocks converter) and `is_html_content()` are likewise dead code. Check for callers before assuming an edit matters.

`parse_front_matter()` is a hand-rolled YAML subset: `key: value`, inline `[a, b]`, and block `- item` lists whose membership is tracked by `$current_key` (set when a key has an empty value, cleared otherwise). Nested maps like `translations:` survive only because the `- item` branch doesn't match `  en: 123` — such indented pairs are parsed as top-level keys. Adding nested structure requires reworking this function.

### Round-trip identity and the `_images/` gap

Post identity across a round-trip is the front-matter `id`. If a post with that ID exists it is updated; otherwise a new post is created and the old ID is stored in `_postsmd_original_id`, which is what `build_translation_id_mapping()` later uses to relink Polylang translations after a cross-site import.

Media is asymmetric: the importer resolves `_images/...` paths out of the ZIP via `PWPM_Media::prepare_zip_media_map()` (uploading each once, deduped by `_postsmd_source_path` meta), but **the exporter never writes an `_images/` folder into the ZIP** — it emits the live absolute URL for `featured_image` and leaves `<img src>` URLs as-is. `_images/` therefore only works for hand-authored or externally-prepared archives.

Directory layout in the ZIP comes from the `_postsmd_folder_path` meta (`folder_path` front matter) combined with post ancestors; on import, every directory that has no `index.md` gets a draft "folder post" created by `maybe_create_folder_posts()`.

### Sync

`PWPM_Sync` has two parallel families: `push_files_to_*` (per-Markdown-file, used by export) and `push_to_*` (whole-file, used by import). GitHub commits go through the contents API with a GET-then-PUT to fetch the existing `sha`; Drive uses hand-built multipart bodies. `fetch_github_file()` / `fetch_drive_file()` exist for pull-based import but have no UI wired to them. Enabling is `is_enabled()`: a per-request `$sync_overrides` entry wins over the saved `postsmd_settings` option, which is how the "Export & Sync to GitHub/Drive" buttons force a sync without changing settings.

## Persistence keys

Options `postsmd_settings` (integration config incl. tokens), `postsmd_debug_log` (capped at 100 entries), `postsmd_activity_log` (capped at 20). Transients `postsmd_stats_cache` (1h — invalidate with `delete_transient` after anything that changes post counts) and `postsmd_last_debug` (5 min, drains into the admin notice). Post meta `_postsmd_exported`, `_postsmd_last_exported`, `_postsmd_folder_path`, `_postsmd_original_id`; attachment meta `_postsmd_source_path`.

Debug entries are `array( 'time', 'status', 'message' )` with status in `success|error|warning|info`, but the renderers also accept legacy plain strings of the form `[time] message` — keep that fallback if you touch the log display.

## Third-party integrations

Rank Math (`rank_math_description`, `rank_math_focus_keyword` / `..._keywords`) and Polylang (`pll_get_post_language`, `pll_set_post_language`, `pll_save_post_translations`) are integrated by direct meta keys and `function_exists()` guards — never as hard dependencies.
