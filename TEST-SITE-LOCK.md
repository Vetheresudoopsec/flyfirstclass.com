# Test site lock (test branch only)

`test.flyfirstclass.com` asks for a username and password. This file lives on the `test` branch only. Never merge it, `.cpanel.yml` or `public_html/locked.html` into `main`.

## How it works

- `public_html/.htaccess` has a block that turns on Apache Basic Auth for any host starting with `test.`. The live site is never affected, because the block checks the host name.
- The password list is `/home/flyer007/test-site/ffc-private/.htpasswd-test`, the path named in `.htaccess` (`AuthUserFile`). It holds one line per user: the username and a bcrypt hash. **It lives on the server only.** It used to be in this repository, but the repository is public, so anyone could download the hash and try to guess the password offline. It is now listed in `.gitignore`, and deploying never deletes or overwrites it (the copy already on the server keeps working).
- A visitor who cancels the sign-in box sees `public_html/locked.html`: a page saying this is the private test version, with a link to https://flyfirstclass.com.
- `/robots.txt` and `/locked.html` stay open so search engines can read "Disallow: /" and the lock page can load.

## Check it after "Deploy HEAD Commit"

1. Open https://test.flyfirstclass.com in a private window. A sign-in box appears.
2. Press Cancel. The "This is the test version of our website" page appears.
3. Reload and sign in. The site loads, and pages and routes work as usual.
4. https://flyfirstclass.com (the live site) must open with no sign-in box.

If the whole test site shows a server error after deploying, the password file is missing from `/home/flyer007/test-site/ffc-private/.htpasswd-test`. Create it as described below.

## Change the password or add a person

**Change the password now if it was set while the file was in the repository** (the old hash stays readable in the public git history).

1. Make a new line with either of these:
   - `htpasswd -nbB USERNAME 'NEW PASSWORD'` on any machine with Apache tools, or
   - cPanel > Directory Privacy for the test site folder (it writes its own password file and can be used instead).
2. cPanel > File Manager > `/home/flyer007/test-site/ffc-private/` (turn on *Show Hidden Files*) > edit `.htpasswd-test` and replace the old line with the new one (one line per person).
3. Right-click the file > *Change Permissions* > **644** (Apache must be able to read it). Nothing needs deploying.

Never commit the file: `.gitignore` keeps it out.

## Things the lock changes on the test site

- The web-scheduler fallback `https://test.../api/cron?key=...` is blocked by the lock. The cPanel cron job (`php .../cron.php`) is not affected, because it runs from the command line.
- Opening `/api/status?key=...` in a browser asks for the sign-in first.
