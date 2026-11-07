# openGym on SiteGround shared hosting

This folder runs openGym on a SiteGround plan that includes Node.js projects (GrowBig or higher),
at an address under an existing website, for example `https://northdakotarigsmap.com/PierceStrong/`.

## How it fits together

```
phone / browser
   │  https://northdakotarigsmap.com/PierceStrong/...
   ▼
WordPress site ── /PierceStrong/ folder: index.php + .htaccess + config.php
   │  forwards every request to /__opengym/<path, base64url>, adding a shared secret
   ▼
SiteGround Node.js project ── start.js (the one listener SiteGround allows)
   ├─ the app's files and exercise media
   └─ /api/* → the openGym server (api/server.js, loaded in the same process), data in DATA_DIR
```

Passkeys need the app and its API on the same address as the page, and SiteGround gives a Node.js
project its own address, so the PHP folder on the website forwards to it. The Node.js project
refuses anything that does not come through that folder (it checks the secret).

### SiteGround details this works around

- **One listener per project.** SiteGround's Node runtime takes over the first `listen()` and
  refuses any other. `start.js` loads the API in-process and keeps its HTTP server and admin socket
  from listening; `/api` requests are handed to the API's request handler directly.
- **Path filters in front of the Node project.** SiteGround's front end refuses some paths before
  the app sees them (`GET /api/config` got a 403 page). The PHP side sends each path base64url-encoded
  as `/__opengym/<encoded>`, which no filter can read and which still gives every path its own address.
- **Caches key on the address.** Never send different files to one address: SiteGround's cache on
  either side will hand out one cached reply for all of them. If that ever happens anyway, flush
  the website's cache (Site Tools > Speed > Caching > Dynamic Cache > Flush), because the app's files
  are marked as never changing.
- **Data survives redeploys** when it lives outside the deployed folder. The default `DATA_DIR` is
  `~/opengym-data` (`/home/customer/opengym-data`), which is kept; the deployed code lives under
  `public_html/.nodeapp/` and is replaced on every deploy.
- **Logs**: the app's console output shows in the Node project's Site Tools > Statistics > Error Log.

## Files

- `start.js`: what the Node.js project runs. Serves the built app and exercise media and hands
  `/api` to the openGym API. `/__sg/status` (through the website) shows whether data survived the
  last redeploy; `SG_STATUS_DETAIL=1` adds the folder paths while setting up.
- `assemble.sh`: builds the folder the Node.js project runs from, packages included.
- `PierceStrong/`: the files that go on the website (rename the folder to change the address).
  Only `index.php` can run; `.htaccess` blocks `config.php` and any other PHP, config, backup,
  log or hidden file in the folder.
- `../.github/workflows/siteground.yml`: on every push to `main`, builds everything and pushes it to
  the `siteground-deploy` branch, ready to run. The Node.js project deploys from that branch.

## Setup

1. **GitHub**: in this fork, open the Actions tab and enable workflows. Run "SiteGround build"
   once (Actions > SiteGround build > Run workflow). It creates the `siteground-deploy` branch.
   The fork also carries the original project's workflows; disable them in the Actions tab (each
   one's "..." menu > Disable workflow). Only "SiteGround build" is needed.
2. **SiteGround Node.js project**: My Account > Node.js Projects > Create. Choose the plan, connect
   GitHub (allow only this repository). Then in the project's Site Tools > Node.js > Deployment
   Options: framework preset Custom, branch `siteground-deploy`, Node 22, package manager npm, no
   build command; Save and Deploy. Environment variables can be added once a deploy has succeeded:

   | Variable           | Value                                           |
   |--------------------|-------------------------------------------------|
   | `PROXY_SECRET`     | 32+ random characters (same as in config.php)   |
   | `ORIGIN`           | `https://northdakotarigsmap.com` (no path)      |
   | `RP_ID`            | `northdakotarigsmap.com`                        |
   | `RP_NAME`          | `PierceStrong`                                  |
   | `FIRST_USER_ADMIN` | `1` (the first profile becomes the admin)       |

   Save and Deploy again after changing them. After your own profile exists, add `INVITE_ONLY` =
   `1` so nobody else can sign up without an invite code from the admin dashboard.
3. **Website folder**: in the website's Site Tools > Site > File Manager, create
   `public_html/PierceStrong/` with `index.php` and `.htaccess` from `PierceStrong/`, and a
   `config.php` made from `config.sample.php` with the Node.js project's address and the secret.
4. **Check**: `https://northdakotarigsmap.com/PierceStrong/__sg/status` should answer with
   `"dataSurvivedARestart": true` once the project has started at least twice.

## Updating

Sync the fork with the original repository (GitHub: "Sync fork" > "Update branch"). The workflow
rebuilds `siteground-deploy` and SiteGround redeploys it. If a change in this folder touches the
PHP side, copy the new `index.php` or `.htaccess` to the website folder too.

## Backups

Everything is in `DATA_DIR` (`/home/customer/opengym-data` on the Node project). Each person can
also export their own data from Settings in the app.
