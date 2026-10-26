# Security policy

## Supported versions

Security fixes are released for the latest version only. Please update to the
[latest release](https://github.com/ridni-foundation/styleraise/releases/latest) before reporting.

## Reporting a vulnerability

Please do not open a public issue for a security problem.

Report it privately through GitHub:
[Report a vulnerability](https://github.com/ridni-foundation/styleraise/security/advisories/new).
If you cannot use GitHub, write to community@ridni.org.ua with "Security" in
the subject.

Include the affected version, the steps to reproduce and what an attacker could
achieve. We confirm receipt within five working days and keep you informed
until a fix is released. Once it is, we credit you in the release notes unless
you prefer otherwise.

## Scope

The plugin runs inside WordPress next to WooCommerce, Elementor and Donation
Platform for WooCommerce. Problems in those plugins should be reported to their
authors. The plugin's own surface is its Elementor widgets and dynamic tags,
its settings screen (`manage_options` only) and one public, read-only REST
endpoint, `GET /wp-json/wcdp-el/v1/campaigns`.
