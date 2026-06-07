# Security Policy

WP Full MCP Gateway exposes WordPress automation through MCP endpoints, so security reports are welcome and treated as high priority.

## Supported Versions

Only the latest public `main` branch is currently supported.

## Reporting a Vulnerability

Please report suspected vulnerabilities privately before opening a public issue.

Include:

- A short description of the issue.
- Steps to reproduce.
- Expected and actual behavior.
- Any affected endpoint, tool name, or permission profile.
- WordPress, PHP, WooCommerce, and Elementor versions when relevant.

Do not include real site secrets, API keys, passwords, private keys, customer data, or production connector URLs in reports.

## Security Areas of Interest

Helpful reports include:

- Authentication or secret validation bypass.
- Privilege escalation through WordPress capabilities or service users.
- Unsafe tool execution, especially destructive actions.
- Missing confirmation or approval checks.
- Backup-before-action bypasses.
- Sensitive data exposure in diagnostics, settings export, audit logs, or tool responses.
- Server-side request forgery risks in remote media or kit imports.

## Disclosure

The project aims to acknowledge valid reports, prepare a fix, and publish a short security note when the issue is resolved.
