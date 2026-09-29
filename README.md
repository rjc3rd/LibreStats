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

To run without the built-in dashboard, for example when another app reads the numbers through the data API, set `'dashboard' => false` in `config.php`. Visitors then see a short notice instead of a login or setup page. Tracking and the API keep working, and the `bin/` tools still manage websites and goals. To keep the dashboard but never show the first-run page (so nobody can make the first login by arriving first), set `'first_run' => false`: logins then come from `php bin/user.php add` or from an app that runs LibreStats, see below.

Goals show as a funnel: a goal is reached when a visitor opens a page (like `/pricing`, or `/blog/*` for a whole section) or triggers an event you send from the page with `librestats("Signed up")` or `data-ls-event="Signed up"`. Every list can be downloaded as CSV.

Want to see it before any real traffic? `php bin/demo.php` fills a site called `demo.example` with 90 days of made-up visits.

Run the checks any time with `php tests/run.php` (it uses a separate `<database>_test` database).

## Viewers and teams

A login is either an **admin**, who can change everything (websites, goals, logins), or a **viewer**, who can only look at the numbers and change their own password. Add either kind under **Settings → People who can log in**, or from the command line: `php bin/user.php add sam --viewer`.

An app that runs LibreStats for other people, a hosting panel for example, can manage groups of viewers ("teams") for them through the data API. Give the app a key with team access: `php bin/apikey.php add "My panel" '*' --team` (or `php bin/apikey.php team "My panel" on` for a key it already has). The app then sends JSON to `api.php?team` with `Authorization: Bearer <key>`. Every request names the team it is about (`acct`, the app's own id for it: 1 to 64 letters, digits, dots, dashes or underscores) and only ever touches that team.

| `op` | Other fields | What it does |
| --- | --- | --- |
| `team.list` | | The team's viewers (`id`, `username`, `sites`, `joined`) and its places (`seats`: `used`, `limit`). |
| `team.create` | `username`, `password`, `sites` | Adds a viewer. The person who leads the team chooses the username and password. |
| `team.password` | `member`, `password` | Sets a new password for a viewer (there is no "forgot my password" here: the team's leader does it). |
| `team.remove` | `member` | Deletes a viewer's login. Their open sessions end with their next click. |
| `team.sites` | `sites` | Changes which websites everyone on the team can see. |

`sites` is required on purpose, so that leaving it out can never share everything: it is `"*"` for every website, or a list of domains such as `["example.com", "example.org"]`. A key can never share more than it can read itself. Answers are JSON: `{"ok": true, ...}` or `{"ok": false, "error": "..."}` with status 400 (bad request) or 409 (refused, with the reason). A team has `team_limit` places (5 unless set, `0` for no limit). Viewers log in on the dashboard like anyone else and see only their websites, with no Settings.

## Themes

LibreStats ships with a clean default theme. Every color, font, and chart style lives in the theme, so you can make it match your own site without touching the code.

## Credits

Country data: [IP Geolocation by DB-IP](https://db-ip.com), licensed under CC BY 4.0.

## License

Copyright (C) 2026 RJC3rd.

[GNU Affero General Public License v3.0](LICENSE). You're free to use, study, change, and share LibreStats. If you share it, or run a changed version for other people over a network, you must keep it under the same license and share your source too, so it stays free for everyone.
