# Security policy

## Reporting a vulnerability

Please do not open a public issue for a security problem. Use GitHub's private reporting instead:
[report a vulnerability](https://github.com/Referenced-AI/referenced-ai-crawler-tracking/security/advisories/new).

Include the plugin and WordPress versions, what you found and how to reproduce it. We aim to acknowledge a report
within three working days and to tell you what we plan to do about it within ten.

## Supported versions

Security fixes go into the latest release only. Please update to it before reporting.

## What the plugin handles

The plugin records no visitor data. It stores a per-crawler visit count in one table of its own and sends daily
totals to Referenced over HTTPS using a connection token that you paste in. The token is stored in WordPress
options with autoload off and is shown masked in the admin. The details are in `readme.txt` under External
Services.
