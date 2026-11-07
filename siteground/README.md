# openGym on SiteGround shared hosting

This folder runs openGym on a SiteGround plan that includes Node.js projects (GrowBig or higher),
at an address under an existing website, for example `https://northdakotarigsmap.com/PierceStrong/`.

## How it fits together

```
phone / browser
   │  https://northdakotarigsmap.com/PierceStrong/...
   ▼
WordPress site ── /PierceStrong/ folder: index.php + .htaccess + config.php
   │  forwards every request, adding a shared secret
   ▼
SiteGround Node.js project ── start.js
   ├─ the app's files and exercise media
   └─ /api/* → the openGym server (api/server.js), data in DATA_DIR
```

Passkeys need the app and its API on the same address as the page, and SiteGround gives a Node.js
project its own address, so the PHP folder on the website forwards to it. The Node.js project
refuses anything that does not come through that folder (it checks the secret).

## Files

- `start.js`: what the Node.js project runs. Serves the built app and exercise media, starts the
  openGym API on an internal port and forwards `/api` to it. `/__sg/status` (through the website)
  shows where data is saved and whether it survived the last redeploy.
- `assemble.sh`: builds the folder the Node.js project runs from.
- `PierceStrong/`: the three files that go on the website (rename the folder to change the address).
- `../.github/workflows/siteground.yml`: on every push to `main`, builds everything and pushes it to
  the `siteground-deploy` branch, ready to run. The Node.js project deploys from that branch.

## Setup

1. **GitHub**: in this fork, open the Actions tab and enable workflows. Run "SiteGround build"
   once (Actions > SiteGround build > Run workflow). It creates the `siteground-deploy` branch.
   The fork also carries the original project's workflows; they can be disabled one by one in the
   Actions tab (each one's "..." menu > Disable workflow). Only "SiteGround build" is needed.
2. **SiteGround Node.js project**: My Account > Node.js Projects > Create. Choose the plan, connect
   GitHub, pick this repository and the `siteground-deploy` branch. Start command `npm start`,
   no build command, Node 22 or newer. Environment variables:

   | Variable           | Value                                         |
   |--------------------|-----------------------------------------------|
   | `PROXY_SECRET`     | a long random string (same as in config.php)  |
   | `ORIGIN`           | `https://northdakotarigsmap.com` (no path)    |
   | `RP_ID`            | `northdakotarigsmap.com`                      |
   | `RP_NAME`          | `PierceStrong`                                |
   | `FIRST_USER_ADMIN` | `1` (the first profile becomes the admin)     |
   | `DATA_DIR`         | optional: where data is saved (see step 5)    |

   After your own profile exists, add `INVITE_ONLY` = `1` so nobody else can sign up without an
   invite code from the admin dashboard.
3. **Website folder**: in Site Tools > Site > File Manager, create `public_html/PierceStrong/` and
   put `index.php` and `.htaccess` from `PierceStrong/` in it. Copy `config.sample.php` to
   `config.php` there and fill in the Node.js project's address and the secret.
4. **Caching**: if Speed Optimizer's dynamic caching is on, exclude `/PierceStrong/` from it.
5. **Check the data survives a redeploy**: open `https://northdakotarigsmap.com/PierceStrong/__sg/status`,
   redeploy the project, and open it again. `dataSurvivedARestart` should be `true` and
   `startsRecorded` should have gone up. If it is `false`, the folder in `dataDir` is wiped on each
   deploy: set `DATA_DIR` to a folder that is kept and check again.

## Updating

Sync the fork with the original repository (GitHub: "Sync fork" > "Update branch"). The workflow
rebuilds `siteground-deploy` and SiteGround redeploys it.

## Backups

Everything is in `DATA_DIR`. Each person can also export their own data from Settings in the app.
