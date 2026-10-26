# Contributing

Thank you for helping improve StyleRaise.

## Reporting a bug

Open an [issue](https://github.com/ridni-foundation/styleraise/issues) and include:

- the versions of WordPress, PHP, WooCommerce, Elementor (and Elementor Pro, if
  used), Donation Platform for WooCommerce and this plugin — the plugin's
  settings screen under **Elementor → WCDP Integration** lists most of them;
- which widget or dynamic tag is affected and what you expected to see;
- any page cache, script optimizer, currency switcher or translation plugin on
  the site: many display problems come from how these interact;
- PHP errors from `wp-content/debug.log`, if any.

Please remove donor names, e-mail addresses and other personal data from
screenshots and logs before posting them. Security problems do not belong in
public issues — see [SECURITY.md](SECURITY.md).

## Pull requests

Pull requests are welcome. This repository is the published edition of a
private source tree (see the README), so:

- keep a pull request to one change and explain the reason for it;
- accepted changes are ported to the private source by a maintainer and come
  back here with the next release; the pull request is then closed with a link
  to that release, and you are credited in the changelog;
- code follows the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/),
  with English comments and PHPDoc;
- escape all output, sanitise all input, and use the `styleraise` text domain for
  every user-facing string;
- never use `!important`, floats or negative margins in the stylesheets —
  appearance is driven by CSS custom properties scoped to the widget;
- widget names and existing control keys are frozen: Elementor does not save a
  control left at its default, so renaming a key silently changes every widget
  already on a page. Add new keys instead.

By submitting a pull request you agree that your contribution is licensed under
the GNU General Public License v2.0 or later, like the rest of the project.
