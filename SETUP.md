# Fly First Class International: HostGator setup (PHP version)

This folder is the whole website for HostGator shared hosting. It does what `server.js` did (fares, routes, photos, quote reference IDs, email, the daily 12:00 PM Eastern refresh) without needing Node.

```
hostgator/
  .cpanel.yml            tells cPanel how to copy the files when you press "Deploy"
  .gitignore             keeps .env and cache/ out of git
  public_html/           goes to your website folder
      index.html         the site
      api.php            the one PHP file visitors' browsers talk to
      .htaccess          sends /api/... to api.php and hides private files
  ffc-private/           goes NEXT TO public_html, outside the web folder (nobody can open it from a browser)
      core.php, lib/     the server code
      cron.php           the scheduled job
      data.json          airport lists
      .env.example       the template for your keys
      .env               YOU create this on the server (never in git)
      cache/             created automatically (fares, photos, quote requests)
```

## 1. Before you start

1. **PHP version.** cPanel > *MultiPHP Manager* > pick your domain > choose **PHP 8.1 or newer** (7.4 is the minimum). Keep the default extensions (curl, mbstring, json, openssl).
2. **Create the mailbox.** cPanel > *Email Accounts* > create `hello@flyfirstclass.com`. HostGator's mail system only delivers reliably when the "From" address is a real mailbox.
3. **Find your cPanel username.** It is shown on the right of the cPanel home page, and it is the name in `/home/USERNAME`.
4. In `.cpanel.yml`, replace `USERNAME` with that name. Commit the change.

## 2. Put the files on the server (cPanel Git Version Control)

1. Put this `hostgator` folder in its own git repository (this folder must be the top of the repository, because `.cpanel.yml` has to sit at the top).
2. cPanel > *Git™ Version Control* > **Create**. Either clone your GitHub/GitLab repository (private repositories need the SSH key cPanel shows you added as a deploy key), or create an empty repository in cPanel and push to it.
3. Open **Manage** on the repository > *Pull or Deploy* > **Update from Remote** (if cloned) > **Deploy HEAD Commit**.
4. Check in *File Manager* (turn on *Show Hidden Files* in Settings): `public_html` should contain `index.html`, `api.php`, `.htaccess`, and `ffc-private` should sit beside `public_html` with `core.php`, `lib`, and so on.

Deploying again later only replaces the code. It never touches `.env` or `cache/`.

## 3. Add your keys (once)

1. File Manager > open `ffc-private` > **+ File** > name it `.env`.
2. Open `.env.example` next to it, copy the contents into `.env`, and fill in `DUFFEL_TOKEN`, `AIRLABS_KEY`, `UNSPLASH_KEY`, and choose an `ADMIN_KEY` (a long random phrase).
3. Right-click `.env` > *Change Permissions* > **600**.
4. Optional but recommended: carry over what the Node server already collected. On your computer zip the old `cache` folder, upload the zip into `ffc-private` and *Extract* it. The PHP version reads the same files, so every documented fare and photo is kept.

## 4. Turn on the scheduled job (daily refresh, photos, clean-up)

cPanel > *Cron Jobs* > *Add New Cron Job* > *Common Settings*: **Once Per Fifteen Minutes**, command:

```
/usr/local/bin/php /home/USERNAME/ffc-private/cron.php >/dev/null 2>&1
```

If that php path does not work, use the one that matches the PHP version you picked, for example `/opt/cpanel/ea-php82/root/usr/bin/php`.

The job is safe to run every 15 minutes: it only works when something is due. The 12:00 PM Eastern refresh is done in steps over a few runs, so a slow day simply takes a few runs. If your plan does not allow cron jobs, point a free web scheduler (for example cron-job.org) at `https://yourdomain.com/api/cron?key=YOUR_ADMIN_KEY` every 15 minutes instead.

## 5. Check that it works

Open these in a browser (use your `ADMIN_KEY` for `YOUR_KEY`):

| Address | What it tells you |
| --- | --- |
| `/api/status?key=YOUR_KEY` | Duffel token accepted?, routes stored, photos saved, last and next refresh, whether the cron job has run, the last log lines |
| `/api/duffel-check?key=YOUR_KEY&search=1` | Sends one real test search and explains any Duffel error in plain words |
| `/api/mail-check?key=YOUR_KEY&send=1` | Sends a test email to the `MAIL_TO` inbox |
| `/api/photo-test?key=YOUR_KEY&code=LHR` | Looks up one photo and shows what Unsplash said |
| `/api/status` (no key) | Only the build number, so strangers learn nothing |

If something is wrong, `ffc-private/cache/log.txt` has the details.

## 6. What is different from the Node version

- There is no always-running program. The first time a visitor opens an origin that has never been looked up, their page request fetches the first few routes, answers "pending" and the page asks again every 3 seconds, exactly as before. The very first visitor to a brand-new origin waits a little longer than they used to. Everything is cached afterwards.
- The daily refresh and background photo filling run from the cron job instead of a timer. Photos fill about 6 per run and leave the last Unsplash requests of each hour for visitors.
- A Duffel 429 ("too many requests") is not treated as an error: routes are tried again shortly. A 401 (bad token) stops all searches until you change the token in `.env`, then clears by itself.
- Safety limits: `DUFFEL_RPM` (default 40 searches a minute), `DUFFEL_DAILY_CAP` (default 1000 a day, 0 turns it off), one visitor can start at most `NEW_ROUTES_PER_IP_HOUR` (80) new route lookups an hour, and 30 new quote requests an hour. Raise them in `.env` if your Duffel plan allows more.
- The page now changes a saved request with POST instead of PUT, because many shared hosts' firewalls refuse PUT.

## 7. If a visitor sees an error

| Symptom | Likely cause and fix |
| --- | --- |
| Whole site shows a "500" | Wrong PHP version (need 7.4+), or `ffc-private` is not beside `public_html`. Check cPanel > *Errors* and `cache/log.txt` |
| The site loads but fares never appear and `/api/status` says 404 | `.htaccess` did not upload (it is a hidden file). Turn on *Show Hidden Files* and check it is in `public_html` |
| `/api/...` returns 403 or 406 from the host | The host's firewall (ModSecurity) blocked a request. Contact HostGator support with the time and the address, or whitelist the rule |
| Emails do not arrive | Run `/api/mail-check?key=...&send=1`. Check the spam folder. Create the mailbox, or use Gmail by filling the `SMTP_*` lines in `.env` |
| `Too many new route lookups` | A visitor (often a crawler) hit `NEW_ROUTES_PER_IP_HOUR`. Raise it or wait an hour |
| You use Cloudflare | Add `TRUST_PROXY=on` to `.env` so each visitor's real address is used for limits and for the location in quote emails |

## 8. Moving to the real address

When you are ready for quote requests to go to `hello@flyfirstclass.com`, change `MAIL_TO` in `.env` to that address (and keep `MAIL_FROM` the same mailbox).
