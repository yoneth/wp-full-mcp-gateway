# Changelog

All notable changes to WP Full MCP Gateway will be documented in this file.

The format is inspired by Keep a Changelog, and this project follows practical release notes rather than strict semantic versioning for now.

## 0.7.23 - 2026-06-07

### Security

- Removed automatic fallback to the first administrator when no MCP service user is configured.
- Added HTTPS-only remote download validation that blocks private and reserved IP targets before Elementor kit and media sideload downloads.
- Made destructive/high-risk tools approval-required by default.
- Changed plugin and theme slug allowlists to fail closed when empty.

## 0.7.22 - 2026-06-07

### Added

- Initial public release of WP Full MCP Gateway.
- HTTPS MCP endpoints for ChatGPT custom connectors and bearer-token MCP clients.
- WordPress tools for posts, pages, media, categories, users, options, plugins, themes, health checks, and diagnostics.
- WooCommerce tools for products, pricing, thumbnails, orders, notes, and store status.
- Elementor tools for CSS flush, template kit import, kit cleanup, import status, section-to-container conversion, and optional Elementor MCP bridge forwarding.
- Permission profiles for read-only, content editor, and admin/devops usage.
- Confirmation text, approval queue, audit log redaction, and backup-before-action guards for high-risk operations.
- Sanitized settings export/import and connector manifest/config helpers.

### Security

- Generated MCP secret on activation.
- Secret-bearing no-auth endpoint for clients that cannot send headers.
- Bearer endpoint for clients that can send `Authorization` headers.
- Sensitive settings exports omit secrets by default.
