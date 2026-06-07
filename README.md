# WP Full MCP Gateway

WP Full MCP Gateway turns a WordPress site into a controlled HTTPS MCP server for remote AI clients such as ChatGPT custom connectors, Claude-compatible MCP clients, and internal automation agents.

The plugin exposes WordPress, WooCommerce, Elementor, backup, diagnostics, and admin operations as MCP tools while keeping high-risk actions behind WordPress capabilities, permission profiles, confirmation text, approval queues, and backup-before-action guards.

## What It Does

- Provides a Streamable HTTP-style MCP endpoint over WordPress REST.
- Supports ChatGPT custom connectors using a no-auth URL with a generated secret in the path.
- Supports generic MCP clients using `Authorization: Bearer <secret>`.
- Exposes practical WordPress tools for posts, pages, media, categories, plugins, themes, options, users, health checks, and diagnostics.
- Adds WooCommerce CRUD tools for products and order operations.
- Adds Elementor workflows for template kit import, tracked import status, kit cleanup, CSS flush, and forwarding to Elementor MCP when installed.
- Includes approval and confirmation controls for sensitive actions.
- Includes backup-before-action support using WPvivid when available, with internal database export fallback.
- Provides a connector manifest and connector config tool for client setup.

## Requirements

- WordPress 6.5 or newer.
- PHP 8.0 or newer.
- Pretty permalinks are recommended.
- HTTPS is strongly recommended for any remote connector.
- WooCommerce is optional and only required for `wc-*` tools.
- Elementor is optional and only required for Elementor-specific tools.
- WPvivid is optional and used when available for richer backup handling.

## Installation

1. Download or clone this repository.
2. Copy the `wp-full-mcp-gateway` folder into `wp-content/plugins/`.
3. Activate **WP Full MCP Gateway** in WordPress Admin.
4. Open **Settings -> WP Full MCP**.
5. Choose a permission profile and save settings.
6. Copy the connector endpoint from the plugin settings page.

The plugin generates a random secret on activation. Treat every endpoint containing that secret as sensitive.

## Endpoints

The plugin registers these endpoint styles:

```text
https://example.com/wp-json/wp-full-mcp/v1/mcp/<secret>
https://example.com/wp-json/wp-full-mcp/v1/mcp/bearer
https://example.com/mcp/bearer
```

For ChatGPT custom connectors, use the no-auth URL:

```text
https://example.com/wp-json/wp-full-mcp/v1/mcp/<secret>
```

For generic MCP clients, use the bearer endpoint with:

```text
Authorization: Bearer <secret>
Accept: application/json, text/event-stream
```

## ChatGPT Custom Connector Setup

1. In WordPress Admin, go to **Settings -> WP Full MCP**.
2. Copy the **ChatGPT No Auth** endpoint.
3. Create a custom connector in ChatGPT.
4. Use HTTP/Streamable HTTP transport if the client asks.
5. Set authentication to **No authentication**.
6. Paste the endpoint URL.
7. Test with `initialize`, then `tools/list`.

## Generic MCP Client Example

```json
{
  "mcpServers": {
    "wp-full-mcp-gateway": {
      "type": "http",
      "url": "https://example.com/wp-json/wp-full-mcp/v1/mcp/bearer",
      "headers": {
        "Accept": "application/json, text/event-stream",
        "Authorization": "Bearer YOUR_GENERATED_SECRET"
      }
    }
  }
}
```

## Permission Profiles

The plugin includes three profiles:

- **Safe / read-only**: discovery, listing, diagnostics, and read operations.
- **Content editor**: content, media, Elementor, WooCommerce, backup, settings, and diagnostics with destructive controls disabled by default.
- **Admin / devops**: broader plugin/theme/admin capabilities, still gated by WordPress capabilities, allow-lists, confirmations, approvals, and backups.

Profiles can be applied from **Settings -> WP Full MCP**. You can also manually tune enabled tool groups, allow-lists, approval-required tools, and backup-before-action tools.

## Tool Groups

The current build includes tools across these groups:

- **Core and content**: site info, posts, pages, products-as-posts, categories, cloning, trashing.
- **Media**: media listing and upload from URL.
- **Plugins and themes**: list, install, update, activate, deactivate, switch.
- **Options and users**: option get/update and user listing.
- **Elementor**: CSS flush, MCP bridge status/info/forwarding, kit import, kit deletion, import status, kit eraser, section-to-container conversion, agent guide.
- **WooCommerce**: store status, product CRUD, pricing, thumbnails, order listing/details/status/notes, native MCP flag.
- **Backup**: internal DB export, WPvivid status/list/create/task status, backup health, prune.
- **Settings and diagnostics**: connector config, connector manifest, settings export/import, health check, diagnostics.
- **Approvals and audit**: approval queue management and audit log.

Use the `wp-connector-manifest` tool to inspect the exact enabled tools on your site.

## Safety Model

WP Full MCP Gateway is designed for controlled automation, not anonymous public access.

- A generated secret is required for all MCP access.
- State-changing tools run through a WordPress service user and WordPress capabilities.
- High-risk plugin/theme actions are disabled unless explicitly allowed.
- Plugin/theme installs can be restricted by slug allow-lists.
- Destructive tools can require confirmation text.
- Sensitive tools can require an approval request before execution.
- Backup-before-action can run before configured tools.
- Diagnostics and settings export omit secrets unless explicitly requested.
- Audit logs redact common sensitive argument names.

Recommended production posture:

1. Use HTTPS only.
2. Use the **Safe / read-only** profile for untrusted or exploratory clients.
3. Create a dedicated low-privilege WordPress service user.
4. Keep plugin/theme install and activation disabled unless actively needed.
5. Keep backup-before-action enabled for destructive or bulk-changing tools.
6. Rotate the MCP secret if a connector URL is shared accidentally.

## Elementor Notes

Elementor kit import requires a public HTTPS URL to the kit ZIP. Local files from an AI client sandbox are not directly readable by WordPress, so upload the ZIP somewhere WordPress can download it first.

The Elementor MCP bridge forwards JSON-RPC payloads to the active Elementor MCP plugin route. This keeps layout-specific capabilities in Elementor's own MCP layer instead of duplicating them here.

## WooCommerce Notes

WooCommerce tools are available when WooCommerce is installed and active. Product tools use WooCommerce CRUD/API behavior and include compatibility aliases for `_regular_price`, `_price`, and `_thumbnail_id`.

Order tools omit personally identifiable information unless `include_pii=true` is explicitly passed.

## Settings Backup and Migration

Use:

- `wp-export-settings` to export sanitized gateway settings.
- `wp-import-settings` to import settings into another site.
- `rotate_secret=true` during import when you want a fresh secret.

By default, exported settings omit the secret.

## Development

Run a basic PHP syntax check:

```bash
php -l wp-full-mcp-gateway.php
php -l includes/class-wp-full-mcp-gateway.php
```

The repository intentionally ignores local backup snapshots such as `*.bak-*`.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

## Credits

Built by Rio & Dul for practical WordPress automation through MCP.
