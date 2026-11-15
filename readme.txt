=== StyleRaise – Connector for Elementor and Donation Platform for WooCommerce ===
Contributors: gufi2019, ridni
Donate link: https://ridni.org.ua/en/donation/?utm_source=wordpress.org&utm_medium=referral&utm_campaign=styleraise&utm_content=plugin-donate-link
Tags: donation, elementor, woocommerce, fundraising, progress bar
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 3.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Donation progress, donor walls and campaign dynamic tags for Donation Platform for WooCommerce — fully styleable inside Elementor.

== Description ==

Running a fundraiser on WooCommerce and building the pages in Elementor? Then
you already know the problem: the donation plugin prints its own markup with
its own inline CSS, and every single campaign page ends up carrying a block of
hand-written overrides.

This add-on takes that presentation layer over. Donation progress, donor walls
and campaign figures become real Elementor widgets and dynamic tags — with a
Style tab as complete as any first-party widget, responsive on every control,
and wired to your Global Colors and Global Fonts.

It is built for **Donation Platform for WooCommerce (WCDP)**, which stays in
charge of the money: the amounts you see are the amounts WCDP itself
calculated, captured from its own filters rather than re-queried. Multi-currency
switchers keep working.

= What you get =

**7 widgets**

* **Donation Progress** — the bar, plus raised, goal, percentage, still-needed, deadline and donation count. Use one of ten presets or compose your own from toggles.
* **Donation Form** — the WCDP form with a full Style tab: palette, step header, amount buttons with hover and selected states, inputs, checkout fields, field messages, popup, fee-recovery box. All eight form layouts, and the real form right in the Elementor editor.
* **Donor Wall** — timeline or compact list, with separate controls for every value in a donor line.
* **Donation Counter** — the number renders in its own element, so it can be a badge.
* **Campaign Title, Price, Image** — for campaigns that are not the current post.

**7 dynamic tags**

Amount Raised · Fundraising Goal · Amount Still Needed · Percentage Raised ·
Donation Count · Campaign Deadline · Product ID

Drop "still needed: €12,400" into a plain Elementor Heading and style it with
Elementor's own typography controls. This is the feature that removes most
hand-written CSS.

= Why it is not just another wrapper =

* **Per-instance styling.** The donation plugin prints its bar CSS inline, once per page, behind a `define()` guard — so a second bar of the same style inherits the first one's look and cannot be styled separately. This add-on ships a real stylesheet and scopes every rule to the widget.
* **No floats, no negative margins.** The upstream markup uses both, which is why progress bars collapse inside Elementor containers and loop cards. The widgets here are flexbox throughout.
* **Every placeholder is its own element.** A big bold raised amount next to a small grey goal takes two controls, not a CSS snippet.
* **Nothing uses `!important`.** Your theme, Global Classes and Elementor's cascade all stay in charge.
* **Fresh figures on cached pages.** A page cache freezes the amount raised at the moment it stored the page. A 6 KB script refreshes the figures from a read-only endpoint, and a donation purges exactly the cached pages that show the campaign. Without JavaScript the server-rendered figure stays.
* **A donation form that does not wait for a mouse move.** "Delay JavaScript" features hold back the donation platform's form script, so the form sits behind a spinner until the visitor interacts — and on a phone that can take a while. The plugin excludes that script, together with exactly the scripts it needs, only on pages that show a form.

= Works with =

* Archive templates and Loop Grid — each card resolves its own campaign
* Polylang and WPML — optionally combine donations across translations
* Multi-currency switchers — amounts follow the visitor's currency
* Page caches — WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, Cache Enabler, SG Optimizer, Nginx Helper, Hummingbird, Breeze, WP Engine, Kinsta, and the Cloudflare plugin through `clean_post_cache()`
* Script optimizers — WP Rocket, LiteSpeed Cache, Perfmatters, Autoptimize; Cloudflare Rocket Loader, WP Meteor, NitroPack and SG Optimizer through opt-out attributes
* Elementor 3.20 through 4.x

== Installation ==

1. Install and activate **WooCommerce**, **Elementor** and **Donation Platform for WooCommerce**.
2. Install this plugin and activate it.
3. Edit a page in Elementor and look for the **WCDP Donation** category in the widget panel.
4. Dynamic tags live under the **WCDP Donation** group in any text control.

Enable **WooCommerce → Analytics**. The donation platform calculates raised
totals from analytics tables; with analytics off, every figure will be wrong.
The plugin's own settings screen warns you if this is the case.

== Frequently Asked Questions ==

= Do I need Donation Platform for WooCommerce? =

Yes. This plugin renders and styles that plugin's data; it does not process
donations itself.

= Will it change how my existing pages look? =

It adds widgets; it changes nothing already on your pages. Existing shortcodes
keep working exactly as before.

= Does it work with a currency switcher? =

Yes. Amounts are taken in the form the donation platform produced them, after
every filter on the site has run, so a converted amount stays converted. If you
prefer a fixed currency-symbol position instead, there is a control for that.

= Can one card in a Loop Grid show its own campaign? =

Yes. Set the campaign source to "Current post / loop item". If your campaign
lives in a custom field on a CPT, use "Post meta field" or a dynamic tag.

= Does the Elementor editor show the real donation form? =

Yes, since 3.2.0. The donation platform builds its form on top of a WooCommerce
cart, which the editor does not have, so for that render the plugin gives
WooCommerce a temporary cart that lives only in memory. Nothing is added to your
cart, no WooCommerce session, cookie or order is created, and the checkout fields
stay empty. If another plugin (for example Elementor Pro's WooCommerce module)
already loads a WooCommerce cart in the editor, the donation platform uses that
cart instead, and its checkout fields may show your own account details; that
form is shown only to you in the editor and is never saved with the page. When
you change a content setting such as the layout, the form
updates within about a second. Saving the page never stores the form or your
details in the page.

You can turn this off under Elementor → WCDP Integration → "Show the donation
form in the Elementor editor". The widget then shows a hint instead of the form,
as it also does with WooCommerce older than 6.9. One exception: if another
plugin (for example Elementor Pro's WooCommerce module) already loads a
WooCommerce cart in the editor, the donation platform builds its form with that
cart when the editor opens, behind its loading spinner, as it did before 3.2.0;
a redraw still shows the hint.

On or off, the editor never builds the form of a password-protected campaign or
of an unpublished campaign you cannot read: the widget shows a hint, both when
the editor opens and when it redraws. A form inside a nested Elementor Pro
template or loop item shows a hint when it redraws. Open the page or Preview to
see the form there: every style you set on the widget applies.

With Donation Platform for WooCommerce 1.3.2 the form shows when the editor
opens, but changing a content setting does not redraw it: close and reopen the
editor to see the change. Newer versions (tested with 1.5.1) redraw it.

= Which form layouts can I use? =

All eight layouts of the donation platform. Layout 6 needs Donation Platform for
WooCommerce 1.5.0 or newer, layouts 7 and 8 need 1.5.1 or newer. With an older
version they are not offered; if a page was saved with one of them, the form
falls back to layout 1 and the editor tells you which version to install.

= Does it slow the site down? =

No. Stylesheets load only on pages that use a widget, and the campaign picker
is built only inside the editor. The only front-end script is the 6 KB live
update script, which loads with Donation Progress and Donation Counter and does
nothing on a page without live figures. The "Maximum speed" freshness preset
turns it off entirely. On the site it was developed for, replacing 30+
shortcode instances with 4 widgets measurably improved page load.

= My page cache shows an old total. What should I do? =

Nothing, in most cases: live updates correct the figures in the browser, and a
donation purges the cached pages that show the campaign. Pick a freshness
preset under Elementor → WCDP Integration. For a hero progress bar on a busy
landing page, "Active campaign" refreshes every 30 seconds.

= Does it contact external services? =

No. The plugin makes no outbound HTTP requests and collects no telemetry. It
registers one public, read-only REST endpoint, `/wp-json/wcdp-el/v1/campaigns`,
which returns only campaign figures that are already printed on the page
calling it: no personal data, no writes.

== Screenshots ==

1. The Donation Progress widget in the Elementor editor, with Composition set to "Use a preset" and preset 2 on the left and the live bar showing €7,400 of €10,000 raised and 3 weeks to go on the right.
2. On the Style tab of the same preset, only the sections for the elements it shows appear: Progress Bar, Amount, Deadline and Layout.
3. In "Build it myself" mode, element toggles and text templates such as "Raised {raised}" and "of {goal}" build a custom layout: amounts above the bar, 74% inside it, and the deadline and donation count below.
4. A plain Heading widget uses the "Amount Still Needed" dynamic tag to show "Still needed: €2,600", with the WCDP Donation tag group open next to the Title field.
5. The Donor Wall's Donor Line style section, with typography, colour, alignment and margin controls and an Individual Values list for each part of a donor line (amount, first name, last name and more).
6. The finished campaign page as a donor sees it: progress bar, amount still needed, donation count, recent donors and the donation form.
7. The Donation Form widget in the Elementor editor, switched to Form Layout 7 (two steps, no header): the canvas shows the real donation form with the campaign's amounts and follows every change made in the panel.
8. The settings screen under Elementor → WCDP Integration, with freshness patterns, donation form script protection, the widget manager, the switch for the donation form in the Elementor editor and a diagnostics panel.

== Changelog ==

= 3.2.0 =
* The donation form widget now shows the real donation form in the Elementor editor, and updates it within about a second when you change a content setting such as the layout. WooCommerce gets a temporary cart in memory for that render only: nothing is added to your cart, and no session, cookie or order is created. Saving a page never stores the form or your details in the page. A new setting, "Show the donation form in the Elementor editor", turns it off. Needs WooCommerce 6.9 or newer; tested with Donation Platform for WooCommerce 1.5.1. With Donation Platform for WooCommerce 1.3.2 the form shows when the editor opens, but changing a content setting does not redraw it: close and reopen the editor to see the change. For a password-protected campaign, or an unpublished one you cannot read, the editor shows a hint instead of the form, both when it opens and when the widget redraws — with the setting on or off, also when another plugin (such as Elementor Pro's WooCommerce module) loads a WooCommerce cart in the editor.
* Form layouts 6 (Donation Platform for WooCommerce 1.5.0 or newer), 7 and 8 (1.5.1 or newer). With an older version they are not offered, and the editor explains a saved layout your version does not have.
* "Field Messages" style controls for the inline field messages of Donation Platform for WooCommerce 1.5.1.
* Script protection now keeps exactly what the donation form script needs out of "delay JavaScript": the WordPress translation scripts on Donation Platform for WooCommerce 1.5.0 and newer, jQuery only on older versions. In the recommended mode it applies only to pages that contain a donation form, no longer to the whole site.
* The button style controls no longer paint the back arrow at the top of layout 6.
* Editor hints now say exactly why the form is not shown and what to do.
* Declared as tested with Elementor 4.4 and WooCommerce 11.3.
* The divider image of layout 2 was removed from the form since 3.1.7; it shows again.
* In the Elementor editor, a popup form without a button is no longer printed into the editor interface.

= 3.1.10 =
* When the REST micro-cache was full, newly fetched campaigns were dropped instead of the oldest ones, so they were never served from the cache. Version 3.1.9 had this bug and was not published.
* Calls to LiteSpeed Cache's own hooks are marked as third-party hooks for the coding-standards check.

= 3.1.9 =
* The live-figures REST route keeps its server-side micro-cache in a single transient holding at most 20 campaigns, keyed by the validated and normalised campaign. Previously each distinct request created its own transient, so anonymous requests could add rows to the database without limit.

= 3.1.8 =
* The live-figures REST route (`wcdp-el/v1/campaigns`) now answers only for published campaigns without a password. Private, draft, pending and password-protected products are refused, also when passed as an additional product of a public campaign — then the whole campaign is refused, so its total cannot reveal the hidden one. Pages keep the figures they were rendered with.

= 3.1.7 =
* New name: StyleRaise – Connector for Elementor and Donation Platform for WooCommerce (previously "Connect WCDP for Elementor"), as requested by the WordPress.org plugin review. The plugin folder and text domain are now `styleraise`; widgets, saved pages and settings are not affected.
* The settings screen loads its styles from a stylesheet instead of an inline `<style>` block.
* Translations are loaded by WordPress itself; the plugin no longer calls `load_plugin_textdomain()`.
* Every piece of markup the widgets print — the donation form, the leaderboard, prices, titles, images and counters — now passes through `wp_kses()` with an explicit list of allowed tags and attributes.

= 3.1.6 =
* Requires WordPress 7.1 or newer, the oldest version it is tested on. No code changes.
* Declared as tested with Elementor 4.3 and WooCommerce 11.2.

= 3.1.5 =
* New screenshots and descriptions for the plugin page. No code changes.

= 3.1.4 =
* In the Elementor editor, the donation form widget no longer blames a second form or disabled donations when the donation platform cannot build the form. The editor has no WooCommerce cart, so the widget now says so and points to Preview, where the form shows with your styles.
* Diagnostics use the correct singular and plural forms ("1 page", "2 pages").
* The widget switches on the settings screen are clearly visible on WordPress 7.1.

= 3.1.3 =
* The plugin is now listed as "Connect WCDP for Elementor – Donation Progress, Donor Wall & Fundraising Widgets". The admin screen and notices show the short name, Connect WCDP for Elementor.
* The campaign picker no longer turns off every query filter to list campaigns in all languages. It asks Polylang and WPML for all languages explicitly, so other plugins that filter queries now apply to it too.
* Widget wrappers print their HTML attributes through Elementor's own escaping helper.
* The translation template is complete again.

= 3.1.2 =
* First public release, on GitHub. The code is the same as in 3.1.1.
* Tested with WordPress 7.1, WooCommerce 11.2, Elementor 4.3 and Donation Platform for WooCommerce 1.5 on PHP 7.4 through 8.5.

= 3.1.1 =
* Fixed the donation form waiting behind its loading spinner until the visitor moved the mouse. "Delay JavaScript execution" features held back the donation platform's own form script; on phones, where there is no mouse movement, the spinner stayed until the first tap or scroll. The plugin now excludes that script from delaying in WP Rocket, LiteSpeed Cache, Perfmatters and Autoptimize, and can mark it for Cloudflare Rocket Loader, WP Meteor, NitroPack and SG Optimizer.
* jQuery is always excluded together with the form script. The donation platform registers its form script without declaring jQuery as a dependency, so excluding the script on its own would run it before jQuery and break the form completely. The missing dependency is now declared as well.
* Added the "Donation form script" setting: *Only where the form is* (default) applies the exclusions only on pages that render a donation form, *Also mark the tags* adds opt-out attributes for edge optimizers that never run PHP, *Off* leaves the exclusions to you.
* Added a "Form script" diagnostics row that warns when an optimizer is detected that only the attribute mode can reach.
* The live-update script is now always excluded from delaying, so cached figures are corrected before the visitor interacts with the page.
* If you excluded the donation platform's script or jQuery by hand in your optimizer, you can remove those entries. A manual jQuery exclusion applies to the whole site.

= 3.1.0 =
* Added live figures on cached pages. A page cache stores the amount raised at the moment the page was cached, and a donation never updates a post, so cached pages kept showing an old total for hours. A small script now fetches current figures from a read-only REST endpoint (`GET /wp-json/wcdp-el/v1/campaigns`) and updates them in place. Without JavaScript the server-rendered figure stays, so nothing breaks and the layout does not jump.
* Added page cache purging when a donation is made, with adapters for WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, Cache Enabler, SG Optimizer, Nginx Helper, Hummingbird, Breeze, WP Engine and Kinsta, plus the generic `clean_post_cache()` signal used by the Cloudflare plugin and most host caches. Only the pages that actually show the campaign are purged.
* Added freshness presets in the settings (Active campaign, Balanced — the default, Maximum speed, Custom) that control the live updates, the purge throttle and how long donation data is cached.
* Added a per-widget "Live updates" switch (inherit / always / never) for Donation Progress and Donation Counter.
* Added optional live updates for dynamic tags, off by default.
* Added a "Purge page caches" button and diagnostics rows for the active preset, the last purge and the detected cache plugins.
* Added the `wcdp_el_freshness_config`, `wcdp_el_cache_adapters` and `wcdp_el_api_cache_ttl` filters and the `wcdp_el_cache_purged` action.
* Fixed three "Undefined array key" PHP warnings.
* The REST endpoint returns only figures that are already printed on the page that calls it. It writes nothing, exposes no personal data, caches responses for 15 seconds on the server and sends `Vary: Cookie` so a shared cache never serves one visitor's currency to another.

= 3.0.0 =
* Removed the legacy markup mode. Donation Progress and Donation Counter now always render the plugin's own markup; widgets that were still set to Legacy take their appearance from the Style tab.
* Fixed alignment and colour controls overflowing the Elementor panel at its minimum width.
* Redesigned the settings screen: widget manager, diagnostics with status pills, cache reset. Still no JavaScript in the admin.

= 2.1.1 =
* "Follow WooCommerce setting" now reads and enforces the store's currency position instead of deferring to whatever filters the format.
* Fixed Hide Symbol, Symbol Color and Symbol Size having no effect on amounts printed without markup.
* Added an "Automatic" currency position for sites that want a currency switcher to decide.

= 2.1.0 =
* Added a standalone Goal element to the progress builder.
* Added separate alignment for the rows above and below the bar, with the six alignment values of an Elementor container.
* Added a currency symbol position control.

= 2.0.1 =
* WooCommerce no longer lists the plugin as incompatible on sites with High-Performance Order Storage. The plugin now declares compatibility with HPOS and the product block editor, and incompatibility with the block checkout, which the donation platform does not support.

= 2.0.0 =
* Complete rewrite: native markup, a full Style tab on every widget, dynamic tags for campaign figures, per-instance styling and a settings screen.

= 1.0.2 =
* Added variable products and variable subscriptions to the campaign picker.
* A variation ID passed to a widget now resolves to its parent campaign.
* Variations without their own image fall back to the parent product image.

= 1.0.0 =
* Initial version: Elementor widgets for the donation form, progress bar, leaderboard, donation counter and campaign title, price and image, plus a Product ID dynamic tag.
