# Venture Native Ad Management

WordPress plugin for managing native ad clients, advertisements, campaigns, and analytics. Designed for Venture Media (Namibia) to serve ads to internal client websites with full tracking and admin control.

## Features

- **Clients & Ads Management** — Add/edit clients and their advertisements with image upload and target URLs.
- **Campaigns** — Create campaigns and drag-and-drop assign ads with ordering.
- **Analytics** — Built-in shortcodes for client-level and campaign-level performance (impressions, clicks, active ads).
- **REST API** — Secure endpoint for client sites to fetch ads (`/serve-campaign/{campaign_id}`) and track events.
- **Secret Key Authentication** — Persistent secret key for secure communication between sites.

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate **Venture Native Ad Management**
3. Go to **Venture Native Ads** in the admin menu to start adding clients, ads, and campaigns.

## Usage

### Admin Area
- **Clients** — Manage clients
- **Advertisements** — Manage ads (with media uploader)
- **Campaigns** — Create campaigns and assign/reorder ads via drag & drop
- **Settings** — View/copy the secret key (needed by client sites)

### Shortcodes

- `[venture-native-ad-management id="all"]` — All clients analytics
- `[venture-native-ad-management id="all_campaigns"]` — All campaigns analytics
- `[venture-native-ad-management id="camp_XXXXXXXXXXXX"]` — Single campaign analytics (use actual Campaign ID)
- `[venture-native-ad-management id="123"]` — Single client analytics (numeric ID)

### REST API (for client sites)

- `GET /wp-json/venture-native-ad-management/v1/serve-campaign/{campaign_id}` — Fetch next ad (balanced by impressions)
- `POST /wp-json/venture-native-ad-management/v1/track` — Track impression/click

Client sites must send the `X-Venture-Secret` header matching the key from Settings.



---

[**Venture Media**](https://www.venture.com.na/) — Namibia’s Premier Creative Studio
