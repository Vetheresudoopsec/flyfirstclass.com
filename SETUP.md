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
      data.json          airport lists, the price sheet used for the carousel's estimated fares (FARES: airline, cabin F or J, from, to, USD),
                         and the Singapore / Hong Kong / US destination order (MARKETS). Edit these two lists to change prices or route order.
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

## 4. Turn on the scheduled job (daily refresh, photos, newsletter retries, trip thank-you emails, clean-up)

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
| `/api/mail-check?key=YOUR_KEY&preview=confirmation` | Shows the visitor's confirmation email in the browser (also `confirmation-oneway` and `confirmation-multi`). Add `&send=1&to=you@example.com` to send that sample to yourself |
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

### The confirmation email to the visitor

When someone sends a **new quote request that includes a route** (an origin and a destination), they also receive a short "Thank you for your request" email with their trip, their contact details, the reference ID and how to reach us. It comes from `quote@flyfirstclass.com` (your `MAIL_FROM`), and a reply goes to `quote@flyfirstclass.com`. The email has a version for round-trip, one-way and multi-city trips.

- **Nothing is sent** for a plain message with no route, for a change to an existing request, a booking enquiry or a check-in request, or when the email address is not usable.
- It is sent just after the visitor sees their thank-you page, so it never slows the form down, and if it fails the visitor and the team email are not affected (the failure shows in `recent` on `/api/mail-check`, with the visitor's address partly hidden).
- Limits: one confirmation per reference ID, at most 3 a day to the same address and 300 an hour overall, so the form cannot be used to send mail to someone else's inbox in bulk.
- To turn it off, add `CUSTOMER_CONFIRMATION=off` to `.env`.
- The small logo in the email is loaded from `https://flyfirstclass.com/email-logo.png` (already on the live site).
- The wording and layout live in `ffc-private/lib/confirm.php`.

### Manage Booking and request history

The Manage Booking page (`/manage-booking/`) is where a visitor opens, changes and asks about a request. Every "edit my route" button on the site leads there: the booking box, the contact page's *Modify My Request* and *Edit* links, and the *Change* button on the page itself. The page is kept out of search results and out of `sitemap.xml`. The dark header applies to this page only.

**Request history** finds a visitor's requests without the reference ID, by the email address or phone number they used. Anyone can type any email address or phone number into the quote form, so a match alone is never trusted:

- The visitor types an email address or phone number and asks for a code. The page always answers "we have emailed a code if we have requests for that", whether or not anything matched, so it cannot be used to check who has made a request.
- If requests match, a 6-digit code is emailed to the email address on them (for a phone number, to each address on requests with that number, up to 3). The code works once, expires after 15 minutes and is cancelled after 6 wrong tries. Only a scrambled (hashed) copy is stored, in `cache/hist.json`.
- The right code lists the requests that carry that email address, newest first (25 at most, with a short trip summary). A phone number is only used to find where to send the code: requests are never listed by phone number alone.
- A pass of 30 minutes is kept in the visitor's browser tab, so reloading does not need a new code. *Hide History* ends it.
- Limits: 10 code requests an hour from one visitor address, 3 an hour for one typed email or phone number, 3 an hour to one mailbox (however it was found), and 300 an hour overall.
- The code email comes from `quote@flyfirstclass.com` (your `MAIL_FROM`) with the subject "123456 is your Fly First Class code". It uses the same mail settings as the confirmation email, so if that email arrives, this one will. If it does not, read `recent` on `/api/mail-check` (section 10).
- To turn it off, add `REQUEST_HISTORY=off` to `.env`. The history box then stops working (it shows an error); finding a request by its reference ID is not affected.
- The wording and the checks live in `ffc-private/lib/history.php`.

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
- **Reading the status page.** `sentToMailchimp` only means Mailchimp accepted the request. `mailchimpAnswered` counts what Mailchimp then said about each contact (no addresses are stored): `subscribed` = on the list now, `pending` = waiting for a confirmation click, `unsubscribed`, `cleaned` or `archived` = the address was already in the audience in that state, and the website never changes an existing contact. `mailchimpSource` shows where Mailchimp says each contact came from: a contact the website just created shows an API source, an older one shows its original source (for example `Admin Add` or `Import`). Sign-ups from before this was added show as "not recorded". Each unusual answer is also written to `cache/log.txt`.
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

Each page has its own address: `/about/`, `/services/`, `/routes/` (the Listings page), `/contact/`, `/manage-booking/` (Manage Booking), `/terms/` and `/privacy-policy/`. Older links such as `/#about` still work and are tidied into the clean address.

- **`public_html/index.html` is the source of the whole site. After you change it, run `python3 tools/build_pages.py`** (from the repository folder). It rewrites the page folders (`about/`, `services/`, `manage-booking/` and so on), `sitemap.xml`, `robots.txt` and `robots-test.txt`, so each page reaches search engines with its own title, description and canonical address. Commit those generated files with the change. (`og-image.png`, the picture shown when the site is shared, is made once; delete it and run the script to make it again.)
- `robots.txt` lets search engines in and points them at `sitemap.xml`. On any address that starts with `test.`, `.htaccess` serves `robots-test.txt` instead (keep everything out) and adds a `noindex` header, so the test site never appears in Google. This needs no change when you go live: the same files work on both.
- `.htaccess` also sends the old WordPress addresses (`/fly-first-class-reviews/`, `/fly-first-class-offers/`, the city pages and so on) permanently (301) to the matching new page, so existing Google results and links keep working. Send me any other old address you want mapped.
- `.htaccess` sends `www.flyfirstclass.com/...` permanently (301) to the same page on `flyfirstclass.com`, so Google only ever sees one address per page. If you ever want the `www.` address to be the main one instead, say so: the redirect, the canonical tags and the sitemap all change together.
- **The test site forgets you on every load.** On any address that starts with `test.`, the page clears what the browser remembers (newsletter sign-up, cookie choice, last request) each time it loads, so sign-up and the cookie banner can be tried again and again. Nothing like this happens on the live site. (The server still keeps each newsletter address once: an address that is already waiting or already sent is not queued again, so use a new one, such as `you+2@gmail.com`, for each new sign-up test.)
- **The business address** (100 Church St, New York, NY 10007-2601) is in four places in `index.html`: the Contact panel, the footer, the *Open in Google Maps* button (it searches for the address) and the structured data Google reads (`address`, `geo` and `hasMap` in the `TravelAgency` block at the top). If it ever changes, change all four, then run `python3 tools/build_pages.py`. Google's own Business Profile is separate: it is managed at business.google.com and should show the same address.
- After going live: add `https://flyfirstclass.com/sitemap.xml` in Google Search Console and Bing Webmaster Tools.

## 13. Welcome email for new subscribers

`email/welcome-email.html` is the "Welcome aboard" email (subject line and preview text are in the comment at the top of the file). It is not part of the website and is not deployed. To use it, paste it into a Mailchimp welcome automation (Automations > Welcome new subscribers > Code your own). It uses Mailchimp's own unsubscribe and postal-address tags, and Mailchimp needs your postal address saved in Audience settings (use `100 Church St, New York, NY 10007-2601`). To send it instead of Mailchimp's confirmation email, set `MAILCHIMP_DOUBLE_OPTIN=off` in `.env` (clause 2.3 of the Privacy Policy already describes the welcome email).

## 14. Quote email for clients

`email/quote-email.html` is the one-to-one quote email advisors send from Gmail or Apple Mail. It is not part of the website and is not deployed. It is built around screenshots: up to three options at different price points, each with a box where the advisor pastes the screenshot of that option, followed by the booking prompt (reply with first name, last name, date of birth and phone, plus the preferred option, and an invoice follows).

To use it:
1. Open the file in Chrome or Safari, select everything (Ctrl+A or Cmd+A) and copy.
2. Paste into a new message. The layout, colours and links come across.
3. In the message, replace each `[bracketed]` item by typing over it. The heading reads "Good morning, [Client first name]." (the advisor changes "morning" to suit the time of day). For each option, click into the dashed box, delete the bracketed text and paste the screenshot (in Gmail choose *Best fit* for the image size). Delete any option you do not need. The grey box under the options is for extra details about the flight plan; delete it if there are none.
4. Gmail can keep it as a template (turn on *Settings > See all settings > Advanced > Templates*, then *three-dot menu > Templates > Save draft as template*). In Apple Mail, keep a blank copy in Drafts and copy from it for each quote.

`email/ADVISOR-GUIDE.md` is the one-page guide for advisors (setup in Gmail, Apple Mail and Outlook, the steps for each quote, a checklist). The same text is also kept as a document in Claude Docs for sharing. The template loads its logo from `https://flyfirstclass.com/email-logo.png`, which only exists once the new site is live. The gold "Reply with my details" button opens a message to `quote@flyfirstclass.com`; a client who just presses Reply writes to the advisor, so both inboxes need watching.

The email also has a "Good to know" section (seat availability, last-minute tickets) and asks the client to read the Terms of Service before booking. The ticket rules it summarises are clause 7 of the Terms (`/terms/`): tickets valid for one year, rebooking within the same continent, non-refundable, no name changes, other airline restrictions. If those rules change, change clause 7, clause 6.5 and the "Can I change or cancel a booking?" answer in `public_html/index.html` (it appears twice), and the "In brief" sentence in the email, then run `python3 tools/build_pages.py`.

## 15. How the home page carousel picks its routes

Carousel cards do not show a price (prices appear only in the featured fares section on the home page). The price estimate below still decides which routes the carousel offers: only routes with a price are shown.

- **Where prices come from, in order.** (1) Your price sheet (`FARES` in `data.json`) when the route is on it. (2) An estimate from the nearest routes on the sheet, when one is close enough to trust. (3) The cheapest partner-airline fare Duffel returned for that route, multiplied by the ratio between your prices and Duffel's on the sheet's own routes. A route with no price we trust is replaced by the next one on that market's list.
- **AirLabs** only tells the site which routes exist; it has no fares. Duffel is the only live price source.
- **Needs the cron job.** The home page never searches Duffel itself. The cron job looks up your price-sheet routes first, then routes the carousel could not price, six per run (`WARM_PER_RUN`). The daily 12:00 PM Eastern refresh keeps the saved Duffel fares current.
- **Check it.** `/api/status` (with your admin key) has a `pricing` section: how many sheet routes Duffel has priced, the ratio, and how far off the ratio is on your own prices (`medianMiss`, `worstMiss`). The Duffel step only switches on when the median miss is 15% or less and the worst is 30% or less; until then those routes are replaced rather than priced.
- Duffel quotes some fares in other currencies. `FX` in `data.json` holds approximate rates; update it now and then.

## 16. Bookings, completed trips and Trustpilot invitations

`/api/trips` is a private page with a sidebar of three views: **Booking requests** (give a customer a reference ID for a quote done outside the site), **Trustpilot invitations** (the main view: add each customer once their trip is booked, and see what is waiting, what failed and what has been sent) and **Email preview**. Tabs, the sidebar and the *Add trip* panel switch instantly without contacting the server, and saving, removing or sending updates the page in place without a reload. This is done by one small script that the page's security policy allows by its exact fingerprint, so nothing else can run on it. Every tab, view and the panel are also plain links and forms, so the page still works one page load at a time if scripts are off. A few days after they get back, the server emails them a short thank-you and copies Trustpilot's Automatic Feedback Service address (Bcc). Trustpilot then sends the customer its own review invitation. Because the invitation comes from Trustpilot's own invitation system, the review should be marked as invited rather than unprompted. The page needs the scheduled job from step 4.

**Set it up (about 3 minutes):**

1. In Trustpilot Business open *Get reviews* > *Invitation methods* > *Trustpilot Automatic Invitations (AFS)* and use **Copy email address**. It looks like `flyfirstclass.com+abcdef1234@invite.trustpilot.com`.
2. Add it to `ffc-private/.env` (File Manager > right-click `.env` > Edit). Keep it private: anyone who has it can use up your invitations.

```
TRUSTPILOT_BCC=flyfirstclass.com+abcdef1234@invite.trustpilot.com
```

3. Open `https://flyfirstclass.com/api/trips` and sign in with your `ADMIN_KEY`. The page keeps you signed in for 8 hours. Open *Email preview* in the sidebar to look at the email and send one to your own address (a sample does not copy Trustpilot, so it uses none of your invitations).
4. Check `/api/status?key=YOUR_KEY` > `trips`: `method` should say it copies Trustpilot.

**Booking requests (quotes done by phone, email, Mailchimp and so on)**

- *Create a booking request* takes the customer's first name, email and, if you have them, phone, route, cabin, trip type, travellers, dates and a note. It makes a normal request with a reference ID (`FFC-XXXXXXXX`), the same as one sent through the website. The customer can look it up on Manage Booking, and it is kept for six months from its last change.
- Tick *Email them the confirmation with the reference ID* to send the usual "Thank you for your request" email (it needs both cities). Leave it unticked to send the ID yourself, for example inside your Mailchimp reply. If the email fails, the request is still created and the page tells you.
- *All requests* (above the form, in *Booking requests*) lists every request the server still keeps, newest first (the newest 100 if there are more): the ones sent through the website (tagged *Website*) and the ones made on this page (tagged *Created here*). When there are both kinds, the *All / Website / Created here* buttons filter the list instantly. A request already used for a completed trip shows *In invitations* instead of the use button. *Delete* is offered only for requests made here; requests that came from the website cannot be deleted from this page. Nothing is copied or moved: the list reads the same requests the Manage Booking page uses, and a request disappears six months after its last change.
- *Use for a completed trip* (or *Start from a booking reference ID* inside the *Add trip* panel) opens the panel and fills the completed-trip form with that customer's name, email, return date and reference ID, so you only check it and press *Add trip*.

**Using it**

- Press *Add trip* (top right of *Trustpilot invitations*) and fill in the panel that slides in: name, email, and the date they get back (for a one-way trip, the arrival date). The *Booking reference* is optional; when given, it is shown in the email and passed to Trustpilot with the invitation, so you can match a review to a booking. It is not shown to anyone else. It goes out `TRUSTPILOT_DAYS` days later (3 unless you change it) at 10:00 AM Eastern, on the next 15-minute run of the scheduled job. A date that has already passed is sent on the next run.
- The top of the page shows how many invitations went out this month against your limit (the bar turns amber at 80% and red at the limit) and a 30-day line with a dot for each day that has invitations waiting. Hover a dot to see who. The list below has three tabs: *Waiting to send*, *Needs attention* and *Sent*.
- *Send now* sends one straight away; *Remove* takes a waiting trip off the list. Add every customer in the same way, at the same point in the journey. Trustpilot's rules ask for exactly that: no picking only the happy ones, no incentives for reviews, no staff, family or friends, and one invitation per trip.
- The same email address added again within 30 days asks you to confirm it is a different trip, and the same address with the same return date is refused.
- If an email cannot be sent the server tries again after 1 hour, 6 hours and 1 day, then moves it to the *Needs attention* tab (a red "needs attention" label by the page title links straight to it) with the reason. *Try again* queues it again. A send that was cut off partway is also put there, never resent on its own: look in Sent mail for the customer first.
- **Monthly limit.** Trustpilot's free plan allows 50 invitations a month, so sending pauses at `TRUSTPILOT_MONTHLY_CAP` (50) sent in a calendar month and the rest wait for next month. If your plan allows more, raise it, or use `0` for no limit. Trustpilot's own month may start on a different day from the calendar month, so leave yourself a little room.
- **If Trustpilot sends its invitation later than you expect**, the delay is added to ours. If the Automatic Invitations page in Trustpilot has a delay setting, set it to the shortest.
- **Without the Trustpilot address.** If `TRUSTPILOT_BCC` is left out, the email carries the plain Trustpilot review link and a button instead (`TRUSTPILOT_LINK` can change the link). Those reviews are not marked as invited.
- **What is kept.** The name and email address are on the server only until the email has gone, then deleted. For 90 days a small stamp stays (initials, return date, send time, the booking reference if one was given, and a scrambled copy of the address so the same trip cannot be added twice). A trip still unsent 45 days after its send date is dropped. Booking requests are kept as in Privacy Policy clauses 1.1 and 1.5, and completed trips as in clause 1.4; check they still match what you do.
- `TRIPS=off` turns the page and the sending off.

**If something looks wrong**

| Symptom | What to check |
| --- | --- |
| The page says it needs a key | `ADMIN_KEY` is missing from `.env` |
| *Last try failed* or *Needs attention* with a mail-server message | The same email settings as quote requests: see section 10 |
| The customer got the thank-you but Trustpilot sent nothing | Check the address in `.env` is exactly the one Trustpilot shows, and that the Automatic Invitations page in Trustpilot is switched on; Trustpilot may not send straight away. The thank-you email is copied to Trustpilot by the mail system (Bcc), so a mail host that ignores Bcc would need `SMTP_HOST` set in `.env` |
| The reference ID does not show against the review in Trustpilot | The email carries it in the data block Trustpilot reads (name, email, reference). Do one real test first: add yourself with a second address of yours and a reference, send now, and check the invitation and the invitation record in Trustpilot. If it does not appear, the reference is still in our email and the stamp; check what Trustpilot shows for the invitation |
| *monthly limit reached* in the status or the cron result | Raise `TRUSTPILOT_MONTHLY_CAP` once your plan allows more, or wait for next month |
