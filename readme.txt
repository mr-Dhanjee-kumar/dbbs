=== Clean Code Projects ===
Contributors: mr-Dhanjee-kumar
Tags: custom post type, clean page, blank page, html, css, js, iframe, portfolio, grid
Requires at least: 5.9
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Store HTML/CSS/JS projects in a custom post type and open each one on a 100% clean page — no theme, no wp_head, no WordPress CSS or JS — plus a beautiful grid of all your projects.

== Description ==

Clean Code Projects adds a **Projects** custom post type where you paste three things per project:

* **Title** — the name of the project
* **Link** — the live/original URL (optional)
* **Code** — your raw HTML, CSS and JavaScript

Every project then gets its own **clean page** (`/p/your-slug/`) that contains **only the code you pasted**. The theme is never loaded, `wp_head`/`wp_footer` never run, the admin bar is switched off and every queued stylesheet and script is emptied. The result is a static page with no theme markup, no body classes, no `wp-json` link, no emoji script, no block-library CSS — nothing but your code.

It also ships a **grid of all your projects** as clean, minimal bordered cards that show **only a cover image and the title**. Set a featured image and it is used as the cover; if you don't set one, the plugin draws a soft placeholder block with the project title on it. The whole card links straight to the clean page — no code dumps, no buttons, no clutter. Drop `[ccp_projects]` on any page (a "Projects" page containing it is created automatically on activation).

= Why it is really clean =

The plugin hooks `template_redirect` at priority **0** — i.e. before WordPress picks a theme template and therefore before `header.php`, `wp_head()`, `wp_footer()` or the admin bar are ever executed. On top of that it:

* clears every open output buffer (so nothing buffered earlier can leak in)
* `remove_all_actions()`/`remove_all_filters()` on `wp_head`, `wp_footer`, `wp_enqueue_scripts`, `wp_print_styles`, `wp_print_scripts`, `wp_body_open`, `the_content`, …
* empties `WP_Styles::$registered/$queue` and `WP_Scripts::$registered/$queue`
* returns an empty string for `style_loader_src`, `script_loader_src`, `style_loader_tag` and `script_loader_tag`
* removes the RSD, WLW, generator, shortlink, REST, oEmbed and emoji hooks
* disables the admin bar

= Features =

* Custom post type **Projects** (`ccp_project`) with Title + Link + Code
* Syntax-highlighted code editor in the admin (core CodeMirror, no extra JS files)
* **External-link mode**: fill only the *Project link* and the clean page becomes a **borderless, full-width, full-height iframe** of that URL — perfect for embedding another tool or a hosted site
* Four output modes per project: **Auto** (code if pasted, otherwise the link in an iframe), **External link**, **Verbatim** (byte-for-byte, nothing added) and **Skeleton** (bare doctype + charset + viewport)
* Shortcode `[ccp_projects]` — minimal bordered card grid: featured image or a title placeholder, plus live search
* Shortcode `[ccp_project id="123"]` — embed a single clean page in an iframe
* Settings page for grid defaults (columns, per page, preview, code, search, new tab)
* "Open clean page" button + shortcode shown right in the projects list table
* Auto-created **Projects** page on activation
* Only **three files** total — drop `clean-code-projects.php` in and go
* Auto dark mode, keyboard accessible, fully responsive

== Installation ==

1. Put the three plugin files (`clean-code-projects.php`, `uninstall.php`, `readme.txt`) into a folder called `clean-code-projects`, upload it to `/wp-content/plugins/`, then zip it if you prefer *Plugins → Add New → Upload Plugin*. (`demo/grid-preview.html` is only a static mock-up of the grid — it is not needed on your site.)
2. Activate the plugin. A page called **Projects** containing `[ccp_projects]` is created for you.
3. Go to **Clean Projects → Add New Project**, enter a title, and either paste your HTML/CSS/JS or fill in the **Project link** for an external tool/site.
4. Click **Open clean page** — you get your code (or a full-screen borderless iframe of your link) and nothing else.
5. Visit the **Projects** page to see the card grid.

== Frequently Asked Questions ==

= Will the theme header, footer, sidebar or admin bar show up? =

No. The clean page never loads the theme at all, and the admin bar is disabled for that request. There is no `wp_head`, no `wp_footer`, no body classes and no enqueued CSS or JS — the response is literally your code.

= Does it remove the WordPress emoji script, block-library CSS, REST link or jQuery? =

Yes. None of them are printed because `wp_head()`/`wp_footer()` never execute. As a safety net, every style/script `src` and tag is also filtered to an empty string.

= What URL do project pages use? =

`/p/your-project-slug/`, e.g. `https://yoursite.com/p/nexora-store/`. Change it with `add_filter( 'ccp_rewrite_slug', function(){ return 'demo'; } );` and re-save your permalinks.

= How do I show an external tool / another site instead of my own code? =

Put its URL in the **Project link** field and leave the code empty (or set Output mode to **External link**). The clean page then renders that URL in a borderless iframe that fills the full width and height of the browser window. Note that some sites send `X-Frame-Options` / `frame-ancestors` headers and simply refuse to be embedded — nothing the plugin can do about that.

= What does the grid card show? =

Just the cover image and the title — nothing else. The cover is the project's **featured image**; if you haven't set one, the plugin renders a soft pastel placeholder with the project title on it. The whole card is a link to the clean page. Want live thumbnails instead? Use `[ccp_projects preview="1"]` — projects without a featured image then show a scaled-down iframe of their clean page.

= My code is only a snippet, not a full document. What happens? =

In **Auto** mode a snippet is wrapped in the smallest possible skeleton: doctype, `charset`, `viewport` and a `<title>`. There is no CSS and no JS in that skeleton. Switch the project to **Verbatim** if you want your bytes printed with nothing added at all.

= Can I use it with Elementor / Gutenberg / any page builder? =

Yes — the grid shortcode works anywhere shortcodes are parsed (Shortcode block, Elementor, classic editor). The clean project page always bypasses the builder, so nothing from it is loaded there.

= Is my code sanitised? =

The code is stored and printed raw on purpose — that is the whole point of the plugin. Only users with the `edit_post` capability for that project can change it. Do not give untrusted users access to the Projects editor.

= Can I change the number of columns? =

`[ccp_projects columns="4"]` or set the default under **Clean Projects → Settings**.

== Screenshots ==

1. The project editor: title, link, output mode and the syntax-highlighted code box.
2. The clean page — your code and nothing else.
3. The projects grid with live previews.

== Shortcodes ==

`[ccp_projects]` — all projects. Attributes:

* `columns` — 1 to 6 (default 3)
* `limit` / `per_page` — number of projects, `-1` = all
* `orderby` — `date`, `title`, `menu_order`, `modified`, `rand`
* `order` — `DESC` or `ASC`
* `ids` — comma separated IDs, e.g. `ids="12,15,18"`
* `preview` — `1`/`0` use a live iframe of the clean page as the cover when the project has no featured image (default 0)
* `search` — `1`/`0` live filter box
* `new_tab` — `1`/`0` open in a new tab
* `title` — heading above the grid

`[ccp_project id="123" height="600"]` — embed one project's clean page in an iframe.

== Developer hooks ==

* `ccp_clean_page_enabled` (bool) — return false to let the theme render the project instead.
* `ccp_clean_page_code` (string) — filter the raw code before it is printed.
* `ccp_clean_page_document` (string) — filter the final HTML (fragment mode).
* `ccp_rewrite_slug` (string) — change the permalink slug (default `project`).

== Changelog ==

= 1.1.0 =
* Project permalinks are now `/p/your-slug/` instead of `/project/your-slug/` (permalinks are flushed automatically on update; re-save Settings → Permalinks if you have a caching layer).
* New **External link** output mode: the Project link is rendered in a borderless iframe at 100% width and 100% height.
* Auto mode now falls back to the external-link iframe when no code is pasted.
* Grid cards are now minimal: featured image (or a pastel placeholder showing the project title) + title only — no code snippets and no CTA buttons. The whole card links to the clean page.
* Live iframe covers are now opt-in via `[ccp_projects preview="1"]` or the settings page.

= 1.0.0 =
* First release: Projects custom post type, 100% clean single pages, responsive card grid shortcode, single-project embed shortcode, settings page and auto-created Projects page.

== Upgrade Notice ==

= 1.1.0 =
Project URLs move from /project/slug/ to /p/slug/ and cards become image + title only. Old /project/ links will 404 — update any hardcoded links, or keep the old slug with `add_filter( 'ccp_rewrite_slug', function(){ return 'project'; } );`.
