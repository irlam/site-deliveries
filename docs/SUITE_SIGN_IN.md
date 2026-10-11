# Suite delivery sign-in

The existing site calendar and capacity locks remain shared. Each deployment-owned Suite connection selects exactly one local site and company. Suite identities get separate local accounts; matching an existing email or name never adopts that account. Company administrators become booking users, not hosting-wide delivery administrators. Viewer restrictions apply both from Suite and from the local user record.

Run `php bin/suite-schema.php` to install the identity map. Configure ignored `includes/suite.local.php` as an array indexed by Suite instance ID, with integer `instance_id`, `organization_id`, `project_id`, `local_site_id`, `local_company_id`, exact `origin` `https://sitedeliveries.site`, and a private 64-character hexadecimal `key`. Keep this file outside Git and owner-readable only. Suite inventory uses `scope_mode: company_site` and matching local site/company IDs. Keys are checked server-to-server; sessions are revalidated on each protected request. Changing company/site bindings invalidates existing Suite handoffs and sessions.

This source addition does not activate a connection. Inventory readiness must remain false until hosted callback, company isolation, Viewer mutations, logout and revocation checks pass. The existing owner login and administration remain available.

Validation: `php tests/suite-company-access.php` and `php tests/logistics.php`.
