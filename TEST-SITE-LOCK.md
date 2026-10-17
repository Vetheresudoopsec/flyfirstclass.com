# Test site lock (test branch only)

`test.flyfirstclass.com` asks for a username and password. This file lives on the `test` branch only. Never merge it, `.cpanel.yml`, `public_html/locked.html` or `ffc-private/.htpasswd-test` into `main`.

## How it works

- `public_html/.htaccess` has a block that turns on Apache Basic Auth for any host starting with `test.`. The live site is never affected, because the block checks the host name.
- The password list is `ffc-private/.htpasswd-test`. It holds one line per user: the username and a bcrypt hash. The password itself is not stored anywhere in this repository.
- `.cpanel.yml` copies that file to `/home/flyer007/test-site/ffc-private/.htpasswd-test`, which is the path named in `.htaccess` (`AuthUserFile`).
- A visitor who cancels the sign-in box sees `public_html/locked.html`: a page saying this is the private test version, with a link to https://flyfirstclass.com.
- `/robots.txt` and `/locked.html` stay open so search engines can read "Disallow: /" and the lock page can load.

## Check it after "Deploy HEAD Commit"

1. Open https://test.flyfirstclass.com in a private window. A sign-in box appears.
2. Press Cancel. The "This is the test version of our website" page appears.
3. Reload and sign in. The site loads, and pages and routes work as usual.
4. https://flyfirstclass.com (the live site) must open with no sign-in box.

If the whole test site shows a server error after deploying, the `AuthUserFile` path in `.htaccess` does not match where the file was copied. Confirm the file exists at `/home/flyer007/test-site/ffc-private/.htpasswd-test`.

## Change the password or add a person

Create a new line with either of these, then replace or add it in `ffc-private/.htpasswd-test` and redeploy:

- `htpasswd -nbB USERNAME 'NEW PASSWORD'` on any machine with Apache tools, or
- cPanel > Directory Privacy for the test site folder (it writes its own password file and can be used instead).

This repository is public, so the hash can be read by anyone. A long, random password makes it safe against guessing; a short or common one does not. Make the repository private, or move the password file to the server only, if the test site ever needs real protection.

## Things the lock changes on the test site

- The web-scheduler fallback `https://test.../api/cron?key=...` is blocked by the lock. The cPanel cron job (`php .../cron.php`) is not affected, because it runs from the command line.
- Opening `/api/status?key=...` in a browser asks for the sign-in first.
