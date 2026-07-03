<p align="center">
  <img src="assets/logo-wordmark.svg" alt="ThickGrass" width="420">
</p>

<p align="center">
  <strong>A self-hosted helpdesk and ticketing system for WordPress.</strong><br>
  Tickets, SLAs, a knowledge base, canned responses, custom intake forms, and email-based approvals.
</p>

<p align="center">
  <img alt="License" src="https://img.shields.io/badge/license-GPLv2%2B-blue">
  <img alt="WordPress" src="https://img.shields.io/badge/WordPress-6.0%2B-21759b">
  <img alt="PHP" src="https://img.shields.io/badge/PHP-7.4%2B-777bb4">
  <img alt="Version" src="https://img.shields.io/badge/version-1.0.0-1a7a4c">
</p>

---

## About

ThickGrass turns any WordPress site into a complete end-user support desk. Requesters open tickets through a simple front-end portal; agents work them from a dedicated admin workbench with saved filters, SLA tracking, an activity timeline, and templated replies.

Every list of values — statuses, priorities, categories, ticket types, close reasons, business hours, role permissions, email templates — is configurable from the admin screens. Nothing is hardcoded, so the plugin adapts to how your team already works instead of forcing its own vocabulary on you. All data lives in the plugin's own tables; it never touches WordPress core files or other plugins.

## Features

- **Ticketing** — configurable ticket types with automatic numbering (`REQ-00001`, `INC-00001`), custom statuses/priorities/impact/categories, public comments vs. internal work notes, file attachments, and a full per-ticket activity log.
- **Calls (interaction log)** — agents log a phone call, email, or walk-in in seconds, then convert it into a ticket or close it with a reason. It's the only way staff can open a ticket, so every request stays traceable to an interaction.
- **SLA management** — four targets per ticket (assignment, first response, first update, resolution), scoped by organization/priority/category/type, calculated against each organization's business hours, with automatic pause/resume on hold, breach indicators, escalation, and CSV-exportable reports.
- **Organizations & assignment groups** — group requesters into organizations with their own business hours, and route tickets to the right team.
- **Knowledge Base** — a public, no-login article library, searchable via shortcode, and insertable straight into a ticket reply.
- **Canned responses** — reusable reply templates with merge fields, scoped by assignment group and/or location.
- **Custom intake forms** — build a form tied to a shortcode so specific request types land as pre-filled, pre-routed tickets.
- **Approvals by email** — request an approval and let the approver decide yes/no from an email link, no login required.
- **Notifications** — configurable email templates for ticket creation, status changes, replies, and SLA breaches, plus an IMAP inbox that links replies back to their ticket automatically.
- **Saved views** — agents and managers save their own ticket filters, shareable across the team.

## Installation

1. Upload the `thickgrass` folder to `/wp-content/plugins/`, or install it as a zip via **Plugins → Add New → Upload Plugin**.
2. Activate the plugin. Activation seeds sensible defaults (statuses, priorities, a `REQ`/`INC` ticket type, a default organization) and creates the front-end portal pages automatically.
3. Go to **ThickGrass → Setup** to review or adjust those defaults.
4. Place the front-end shortcodes on any page (see below).

By default, WordPress Administrators become ThickGrass Managers, Editors become Agents, and Subscribers become End-users.

## Front-end shortcodes

| Shortcode | Purpose |
|---|---|
| `[thickgrass_new_ticket]` | "Open a new ticket" form for logged-in end-users |
| `[thickgrass_my_tickets]` | A logged-in end-user's list of their own tickets |
| `[thickgrass_kb]` | Public Knowledge Base search/browse page (no login required) |
| `[thickgrass_custom_form slug="your-form-slug"]` | Renders a custom intake form built in Setup → Custom forms |
| `[thickgrass_approval]` | Landing page for the email approval flow (used internally) |

## Requirements

- WordPress 6.0+
- PHP 7.4+

## License

GPLv2 or later — see [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).
