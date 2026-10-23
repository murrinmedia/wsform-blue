# WS Form - Blue

A custom WS Form Pro action that sends form submissions to [Blue](https://blue.app) as database records, via Blue's REST API.

## What it does

Adds a "Send to Blue" action to any WS Form. In the form's Actions tab you pick a Blue Workspace, then a Database, then map form fields to Blue fields (or set static/merge-tag values via Custom Mapping). On submission, it creates a new record in Blue with those values.

## Requirements

WS Form Pro must be active. Drop this file in as a standard WordPress plugin (`wp-content/plugins/ws-form-blue/ws-form-blue.php`) and activate it.

## Setup

In **WS Form > Settings > Blue**, enter:

- **Token ID** — from Blue: profile avatar (top-right) > API > Generate a Token
- **Secret Key** — the secret shown once when you generate the token (starts with `pat_`)
- **Organization ID** — the Blue org this should create records in (find it under Organization Settings, or in the URL when viewing your org)

Blue's REST API authenticates with the `blue-token-id`, `blue-token-secret` and `blue-org-id` headers (no `Authorization: Bearer` header) — this plugin handles that automatically once the three settings fields above are filled in.

## Status

Live and tested — confirmed working end to end on a real form submission.

## History

This repo is a snapshot/backup of the plugin file. Changes are currently made directly on the live site (via an AI-assisted workflow) and synced back here afterwards, rather than deployed automatically from git pushes.
