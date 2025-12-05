---
id: 123
title: "Sample Markdown Post"
post_status: "publish"
post_date: 2024-12-01 20:14:59
slug: "sample-markdown-post"
author: "admin"
menu_order: 0
comment_status: "open"
page_template: "default"
stick_post: "no"
categories: ["Guides", "Examples"]
tags: ["markdown", "import"]
taxonomy: ["custom_tax: Sample Term"]
post_excerpt: "A short summary used for the post excerpt."
custom_fields: ["meta_key: meta value", "another_key: another value"]
featured_image: "_images/post-image-1.jpg"
skip_file: "no"
---

# Sample Markdown Post

This is an example Markdown file formatted for the import tool. Update the values above to match your site.

## Details

- Supports inline **bold** and *italic* text
- Links like [WordPress](https://wordpress.org)
- Images from the ZIP `_images` folder: ![Alt text](/_images/post-image-1.jpg "Caption for the image")
- Taxonomies and custom fields can be supplied via front matter.

> Blockquotes are supported, too.

```
// Code fences are preserved
console.log('Hello from imported markdown');
```
