# Site Deliveries

Current PHP delivery booking, gateboard and administration application, imported from the existing Plesk installation.

Production: https://sitedeliveries.site/

## Plesk updates

Plesk → sitedeliveries.site → Git → Pull now. The main branch deploys into `/sitedeliveries.site/httpdocs`. PHP 8.2 is used to match the original installation. The existing vendor libraries are retained, with their versions recorded in composer.lock.

## Local configuration and data

`db.php`, `includes/config.push.php` and `includes/vapid.php` contain installation credentials and are excluded from Git. They remain on the server when Plesk deploys updates. Copy the corresponding `.example.php` files and configure them for a fresh installation.

The MySQL database, uploads, delivery sheets, exports and backup archives are not versioned. The domain move uses the existing database and copies its file storage. Back up both separately before changing them. Browser push subscriptions must be enabled again on the new domain.

The original address redirects to the new domain, preserving paths and query strings. Its redirect is installed only on the old site.
