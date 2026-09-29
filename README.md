# LibreStats

Privacy-first website statistics you host yourself. Free and open source.

LibreStats counts the real people visiting your website, not bots and server noise, without cookies, without storing IP addresses, and without sending anything to a third party. Your numbers stay on your own server.

> **Status:** early development. It works end to end (tracking, dashboard, login), but expect changes before the first release.

## What it will show

- Visitors, page views, time on site, and how many leave after one page
- Visitors per day, with any date range
- Top pages, and where visitors came from (search engines, links, campaigns)
- Countries, devices, browsers
- Goals and simple funnels (for example: viewed pricing → ordered → paid)
- Who's on the site right now

## How it works

Add one line to your pages (plain HTML, PHP, WordPress, anything):

```html
<script src="https://stats.example.com/s.js" data-site="example.com" defer></script>
```

The small script runs only in real browsers and sends a short note to your own LibreStats server when a page is viewed. Bots mostly never run it, so what you see is people.

## Privacy

- **No cookies.** Nothing is stored in the visitor's browser, so no consent banner is needed for it.
- **No IP addresses stored.** Each visitor becomes an anonymous code that changes every day, so nobody can be followed from one day to the next.
- **Respects Do Not Track and Global Privacy Control.** Those visitors aren't counted at all.
- **No third parties.** Everything stays on your server.
- **Data retention you control.** By default, raw visits are kept 13 months and monthly totals are kept for good.

## Requirements

PHP 8.2 or newer and MySQL or MariaDB. No other services.

## Installing (early, for testing)

1. Put the whole folder on your server and make **`public/`** the website's root. Everything else (code, settings, tools) must stay outside it.
2. Create a MariaDB/MySQL database and user, copy `config.example.php` to `config.php`, and fill in the database details.
3. Create the tables: `php bin/install.php`
4. Load the country data (free [DB-IP Lite](https://db-ip.com), updated monthly): `php bin/geo-update.php`
5. Add two cron jobs: `php bin/maintain.php --quiet` daily, and `php bin/geo-update.php --quiet` monthly.
6. Put the one-line script on your pages.
7. Open your LibreStats address in a browser. The first time, it asks you to create your login, then your first website. After that, websites, goals and logins are all managed on the dashboard's **Settings** tab (the `bin/` tools do the same from the command line).

To run without the built-in dashboard, for example when another app reads the numbers through the data API, set `'dashboard' => false` in `config.php`. Visitors then see a short notice instead of a login or setup page. Tracking and the API keep working, and the `bin/` tools still manage websites and goals.

Goals show as a funnel: a goal is reached when a visitor opens a page (like `/pricing`, or `/blog/*` for a whole section) or triggers an event you send from the page with `librestats("Signed up")` or `data-ls-event="Signed up"`. Every list can be downloaded as CSV.

Want to see it before any real traffic? `php bin/demo.php` fills a site called `demo.example` with 90 days of made-up visits.

Run the checks any time with `php tests/run.php` (it uses a separate `<database>_test` database).

## Themes

LibreStats ships with a clean default theme. Every color, font, and chart style lives in the theme, so you can make it match your own site without touching the code.

## Credits

Country data: [IP Geolocation by DB-IP](https://db-ip.com), licensed under CC BY 4.0.

## License

Copyright (C) 2026 RJC3rd.

[GNU Affero General Public License v3.0](LICENSE). You're free to use, study, change, and share LibreStats. If you share it, or run a changed version for other people over a network, you must keep it under the same license and share your source too, so it stays free for everyone.
