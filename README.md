# Site Deliveries

Current PHP delivery booking, gateboard and administration application, imported from the existing Plesk installation.

Production: https://sitedeliveries.site/

## Plesk updates

Plesk → sitedeliveries.site → Git → Pull now. The main branch deploys into `/sitedeliveries.site/httpdocs`. PHP 8.2 is used to match the original installation. The existing vendor libraries are retained, with their versions recorded in composer.lock.

## Local configuration and data

`db.php`, `includes/config.push.php` and `includes/vapid.php` contain installation credentials and are excluded from Git. They remain on the server when Plesk deploys updates. Copy the corresponding `.example.php` files and configure them for a fresh installation.

The MySQL database, uploads, delivery sheets, exports and backup archives are not versioned. The domain move uses the existing database and copies its file storage. Back up both separately before changing them. Browser push subscriptions must be enabled again on the new domain.

The original address redirects to the new domain, preserving paths and query strings. Its redirect is installed only on the old site.

## Construction Suite read-only API

Endpoints:
- `/api/suite-summary.php` — today, awaiting arrival, completed, overdue and no-show counts.
- `/api/suite-references.php` — authenticated, returns no site values because the
  existing calendar has no project/site column. Use **All data in this module**
  in Construction Suite for the current single-calendar installation.

For production, create an **untracked** `includes/suite.local.php` on Plesk
using `includes/suite.local.example.php` as a guide, setting the same
`CONSTRUCTION_SUITE_API_KEY` secret as the Hub's `SUITE_INTEGRATION_KEY`.
Or set `CONSTRUCTION_SUITE_API_KEY` as a Plesk PHP/server environment variable.
The API authenticates before trying to connect to the booking database.
Never store the integration secret in Git, URLs, screenshots or documentation.

After deployment, accessing either API in a browser without the private header
must return HTTP **401**, and a missing private key returns **503**. HTTP **500**
indicates an underlying server/application failure and should be checked in
Plesk logs. GitHub Actions tests API authentication and counts against an
isolated disposable database; it cannot authenticate to the live server.
