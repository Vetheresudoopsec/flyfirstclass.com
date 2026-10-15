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
      favicon.svg, favicon.ico, apple-touch-icon.png   the site icon (the logo mark) shown in browser tabs and on phone home screens
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
2. **Create the mailbox.** cPanel > *Email Accounts* > create `quote@flyfirstclass.com`. HostGator's mail system only delivers reliably when the "From" address is a real mailbox.
3. **Find your cPanel username.** It is shown on the right of the cPanel home page, and it is the name in `/home/USERNAME`.
4. In `.cpanel.yml`, replace `USERNAME` with that name. Commit the change.

## 2. Put the files on the server (cPanel Git Version Control)

1. Put this `hostgator` folder in its own git repository (this folder must be the top of the repository, because `.cpanel.yml` has to sit at the top).
2. cPanel > *Git™ Version Control* > **Create**. Either clone your GitHub/GitLab repository (private repositories need the SSH key cPanel shows you added as a deploy key), or create an empty repository in cPanel and push to it.
3. Open **Manage** on the repository > *Pull or Deploy* > **Update from Remote** (if cloned) > **Deploy HEAD Commit**.
4. Check in *File Manager* (turn on *Show Hidden Files* in Settings): `public_html` should contain `index.html`, `api.php`, `.htaccess`, the three icon files (`favicon.svg`, `favicon.ico`, `apple-touch-icon.png`), and `ffc-private` should sit beside `public_html` with `core.php`, `lib`, and so on.

Deploying again later only replaces the code. It never touches `.env` or `cache/`.

## 3. Add your keys (once)

1. File Manager > open `ffc-private` > **+ File** > name it `.env`.
2. Open `.env.example` next to it, copy the contents into `.env`, and fill in `DUFFEL_TOKEN`, `AIRLABS_KEY`, `UNSPLASH_KEY`, and choose an `ADMIN_KEY` (a long random phrase).
3. Right-click `.env` > *Change Permissions* > **600**.
4. Optional but recommended: carry over what the Node server already collected. On your computer zip the old `cache` folder, upload the zip into `ffc-private` and *Extract* it. The PHP version reads the same files, so every documented fare and photo is kept.

## 4. Turn on the scheduled job (daily refresh, photos, newsletter retries, clean-up)

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
| Emails do not arrive | See "If emails do not arrive" below |
| `Too many new route lookups` | A visitor (often a crawler) hit `NEW_ROUTES_PER_IP_HOUR`. Raise it or wait an hour |
| Visitors see "We couldn't confirm that this request came from a person" | The bot check flagged them (an automation tool, a hidden form field filled in, a form sent in under a second, or a failed Turnstile check). They are shown your email and phone number instead. `cache/log.txt` has a `[guard] blocked` line with the reason. If real visitors are affected, add `BOT_GUARD=off` to `.env` while you investigate and tell me the reason in the log |
| You use Cloudflare | Add `TRUST_PROXY=on` to `.env` so each visitor's real address is used for limits and for the location in quote emails |

## 8. Bot protection

It is on by default and needs nothing from you. No one is ever asked to pick pictures or type distorted text.

- **When it runs.** Only when something is about to call the server: a route search, Find my request or Modify, sending a quote, and the contact form once someone starts filling it in. The home page, the carousel and the photos never start a check.
- **What it looks at.** Whether the visitor is a normal browser (not curl, a script, a crawler or a headless browser), whether the page was used by a person, a hidden form field that people never see, how fast the form was filled in, and that the request comes from your own site. A visitor who looks automated sees your email and phone number instead of results. The same checks run in the Node server.
- **Optional: Cloudflare Turnstile.** Adds an invisible check from Cloudflare. In Cloudflare > Turnstile > *Add widget*, choose widget mode **Invisible**, add your domain, and put `TURNSTILE_SITE_KEY` and `TURNSTILE_SECRET` in `.env`. If Cloudflare would ever need a puzzle, the site treats that as a bot and shows the plain message instead, so nothing interactive ever appears. Turnstile loads a script from Cloudflare and Cloudflare sees the visitor's address and browser details; say so in the Privacy Policy (the draft already mentions it) and have it reviewed.
- **Settings.** `BOT_GUARD=off` (testing only), `GUARD_TTL` (seconds a pass lasts, default 1800), `SITE_HOSTS` (extra host names, for example both `flyfirstclass.com` and `www.flyfirstclass.com` if the site answers on both through a proxy).
- **Fine print.** The checks are not a guarantee against every bot. They stop the common ones and the rate limits (`NEW_ROUTES_PER_IP_HOUR`, quote limits per hour) still apply behind them.

## 9. Where quote requests are delivered

Quote requests are emailed to `quote@flyfirstclass.com` by default, so no setting is needed once that mailbox exists. If an older `.env` still has a `MAIL_TO=` line (for example one that sends requests to a personal inbox), delete the line or change it to `quote@flyfirstclass.com`. Set `MAIL_TO` only when you want requests delivered somewhere else, and keep `MAIL_FROM` as the `quote@` mailbox.

## 10. If emails do not arrive

The site remembers the last 20 send attempts. Open `/api/mail-check?key=YOUR_KEY&send=1` (sends a test) and read `recent`: each line says when, whether the server accepted it, and the reason if not. `/api/status?key=YOUR_KEY` shows the same list as `recentEmails`.

- **`ok: false`** with an error: the message says what to fix (for example the mailbox is missing, or the SMTP login is wrong).
- **`ok: true` but nothing in the inbox:** the server handed the email to HostGator's mail system, which then did not deliver it, usually because Gmail marked or refused it. Check, in this order:
  1. The spam folder of the receiving inbox.
  2. cPanel > *Email* > **Track Delivery**: search for the receiving address. It shows Delivered, Deferred or Failed, with the reason.
  3. cPanel > *Email* > **Email Deliverability**: if the domain shows a problem with SPF or DKIM, press **Repair**. If your domain's DNS is managed somewhere else (for example Cloudflare), add the records it lists there.
  4. The mailbox named in `MAIL_FROM` (default `quote@flyfirstclass.com`) must exist in cPanel > *Email Accounts*.
- **Most reliable fix when the inbox is Gmail:** send through Gmail itself. In your Google account turn on 2-step verification, create an *App password*, then add to `.env`: `SMTP_HOST=smtp.gmail.com`, `SMTP_PORT=465`, `SMTP_USER=you@gmail.com`, `SMTP_PASS=` (the app password, never share it) and `MAIL_FROM=you@gmail.com`. Run the mail-check link again.

## 11. Newsletter sign-ups and Mailchimp

The site has three places where a visitor can join the newsletter: a section on the home page, a small prompt that appears after about 90 seconds on the site, on whichever page the visitor is on, and then stays in its corner as they move between pages until they close it or subscribe (closing it keeps it away for 90 days), and the tick-box on the quote form. Everyone who agrees is passed to Mailchimp. Until you do the steps below, sign-ups are simply held safely on the server and nothing is lost.

**Set it up (about 5 minutes):**

1. In Mailchimp > *Audience* > **Settings** > *Audience name and defaults*, copy the **Audience ID** (letters and numbers).
2. In Mailchimp > your profile > **Extras** > *API keys* > **Create A Key**. Copy the key. It ends with a dash and a code such as `-us21`: that code is your data centre and is used automatically.
3. Add two lines to `ffc-private/.env` (File Manager > right-click `.env` > Edit):

```
MAILCHIMP_API_KEY=the_key_ending_in_-us21
MAILCHIMP_LIST_ID=your_audience_id
```

4. Open `/api/status?key=YOUR_KEY` and look at `newsletter`: `mailchimp` should say `on`. Subscribe once from the home page with your own address and the same status page should show it as `sentToMailchimp` (the cron job from step 4 does the retries).

**How it behaves**

- Each new address is saved on the server, then sent to Mailchimp straight away. If Mailchimp is slow, down or the key is wrong, the address stays *waiting* and the 15-minute cron job tries again with longer and longer pauses (5 minutes, 30 minutes, 2 hours, 6 hours, 12 hours, then daily). After 12 failed tries it is marked *gave up* but kept so you can add it by hand.
- **Subscribe-straight-away mode.** Privacy Policy clause 2.3 now says people join the list as soon as they subscribe and receive a welcome email (no confirmation link). For the site to match, add `MAILCHIMP_DOUBLE_OPTIN=off` to `.env` and set up the welcome email (section 13). If you ever want Mailchimp's confirmation link back, remove that line and change clause 2.3 to describe the confirmation email again. Without that line the site defaults to double opt-in.
- Once Mailchimp has an address, the address and name are wiped from the server. Only a small stamp (page used, time) stays for 90 days so the counts make sense. Addresses Mailchimp has not accepted are held for at most six months.
- People get the tags `Website newsletter` and `Website newsletter - home` (or `about`, `services`, `listings`, `contact`, `privacy`, `terms`, `thanks`, `quote`) so you can see where they joined. Change the first tag with `MAILCHIMP_TAG=...`, or turn tags off with `MAILCHIMP_TAG=none`.
- Someone who unsubscribed in Mailchimp is never re-added by the website. If they want back on, they use the sign-up link Mailchimp provides. Someone who never clicks the confirmation link stays *pending* in Mailchimp; the website cannot resend it, so look for a resend option on that contact in Mailchimp.
- If the quote-form tick-box is ticked, the address is added the same way. The tick-box also still shows as `Newsletter: Yes` in the quote email.

**Checking and fixing**

| Address | What it tells you |
| --- | --- |
| `/api/status?key=YOUR_KEY` > `newsletter` | `waiting`, `sentToMailchimp`, `refusedByMailchimp` (for example a contact Mailchimp had permanently deleted), `gaveUp`, the oldest waiting sign-up and the last problem in plain words |
| `/api/newsletter-export?key=YOUR_KEY` | A CSV (Mailchimp's import format) of every address Mailchimp does not have yet. Import it in Mailchimp > *Audience* > *Import contacts* if you ever need to add them by hand |

- *"Mailchimp rejected the API key"*: copy the key again (the whole thing including the `-us21` part) into `MAILCHIMP_API_KEY`.
- *"Mailchimp could not find the audience"*: `MAILCHIMP_LIST_ID` must be the Audience ID, not the audience name.
- Mailchimp requires your physical address and an unsubscribe link in every campaign; both are added in Mailchimp's own campaign builder, not on this site.
- `MAILCHIMP_BASE` exists only for testing with a stand-in server. Leave it out.

## 12. Page addresses, search engines and the test site

Each page has its own address: `/about/`, `/services/`, `/routes/` (the Listings page), `/contact/`, `/terms/` and `/privacy-policy/`. Older links such as `/#about` still work and are tidied into the clean address.

- **`public_html/index.html` is the source of the whole site. After you change it, run `python3 tools/build_pages.py`** (from the repository folder). It rewrites the six page folders (`about/`, `services/` and so on), `sitemap.xml`, `robots.txt` and `robots-test.txt`, so each page reaches search engines with its own title, description and canonical address. Commit those generated files with the change. (`og-image.png`, the picture shown when the site is shared, is made once; delete it and run the script to make it again.)
- `robots.txt` lets search engines in and points them at `sitemap.xml`. On any address that starts with `test.`, `.htaccess` serves `robots-test.txt` instead (keep everything out) and adds a `noindex` header, so the test site never appears in Google. This needs no change when you go live: the same files work on both.
- `.htaccess` also sends the old WordPress addresses (`/fly-first-class-reviews/`, `/fly-first-class-offers/`, the city pages and so on) permanently (301) to the matching new page, so existing Google results and links keep working. Send me any other old address you want mapped.
- **The test site forgets you on every load.** On any address that starts with `test.`, the page clears what the browser remembers (newsletter sign-up, cookie choice, last request) each time it loads, so sign-up and the cookie banner can be tried again and again. Nothing like this happens on the live site. (The server still keeps each newsletter address once: an address that is already waiting or already sent is not queued again, so use a new one, such as `you+2@gmail.com`, for each new sign-up test.)
- **The business address** (100 Church St, New York, NY 10007-2601) is in four places in `index.html`: the Contact panel, the footer, the *Open in Google Maps* button (it searches for the address) and the structured data Google reads (`address`, `geo` and `hasMap` in the `TravelAgency` block at the top). If it ever changes, change all four, then run `python3 tools/build_pages.py`. Google's own Business Profile is separate: it is managed at business.google.com and should show the same address.
- After going live: add `https://flyfirstclass.com/sitemap.xml` in Google Search Console and Bing Webmaster Tools.

## 13. Welcome email for new subscribers

`email/welcome-email.html` is the "Welcome aboard" email (subject line and preview text are in the comment at the top of the file). It is not part of the website and is not deployed. To use it, paste it into a Mailchimp welcome automation (Automations > Welcome new subscribers > Code your own). It uses Mailchimp's own unsubscribe and postal-address tags, and Mailchimp needs your postal address saved in Audience settings (use `100 Church St, New York, NY 10007-2601`). To send it instead of Mailchimp's confirmation email, set `MAILCHIMP_DOUBLE_OPTIN=off` in `.env` (clause 2.3 of the Privacy Policy already describes the welcome email).
