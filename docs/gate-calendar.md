# Gate calendar upgrade

The authenticated weekly/daily calendar is `/schedule.php`. Existing admins keep `/admin/`, and the original calendar, multi-slot booking, drag tools, notes, blackouts, gate actions, notifications and reports remain under `/?legacy=1`.

## Site and company setup

Existing records migrate to Rochdale Road, operated by McGoff Construction, and Main gate. Dates, supplier names, vehicles, drivers and status history are retained. No gates or equipment names are invented. Admin > Gates, equipment & companies manages site/gate capacity, named shared equipment, companies and users with explicit site assignments. Add the actual cranes/forklifts before making new equipment-dependent bookings.

Historical company ownership remains unassigned and visible only to admins. Use Existing contractor ownership after reviewing which company owns each supplier's bookings. Company access always requires both company ownership and an active site membership. Other reservations appear only as Unavailable; records, exports, uploaded delivery sheets and legacy endpoints enforce the same restrictions. Revoking membership or disabling a company/user takes effect on the next request.

Bookings reserve the entire duration. Independent gates can operate simultaneously; a shared crane/forklift cannot overlap even between gates. Site-row transaction locks serialize competing writes. Revision checks prevent stale edits. Original opening hours, slot intervals and unloading methods apply to the new form. Original blackout settings apply to the original site only.

## Deployment

1. Run `scripts/logistics-preflight.php` privately with PHP 8.2.
2. Run `scripts/backup-logistics.php` privately. Verify backup_completed=true. Backups include database, uploaded documents and private configuration in a restricted sibling directory outside httpdocs. Do not expose or commit these files.
3. Upload the release files, preserving db.php, private push/Suite configuration and uploaded files. Never upload the disposable tests/runtime database.
4. Run `scripts/upgrade-logistics.php` (prepare only), inspect the site configuration, then run with `--activate`. Activation removes the old global time-slot uniqueness constraint; reservations are then checked by site, gate capacity and equipment. This migration is repeatable.
5. Verify count/history, existing admin access, authenticated calendar and anonymous denial of data/files. Do not restore the old code alone once activated: restore the private database snapshot and matching code together during a controlled rollback.

## Construction Suite

The authenticated reference API now advertises real active site IDs. Map Rochdale Road's Suite Deliveries reference to site 1. Summary requests require an explicit `site`; the Suite calendar link preserves that reference. Existing shared-key configuration is retained. The optional server-only CONSTRUCTION_SUITE_COMPANY_ID restricts a dedicated reporting connection to one company.

This shared Deliveries installation has its own company sign-in. It is not a verified tenant instance or a Suite single-sign-on adapter. Existing Suite isolation rules remain enforced; registering it as a ready tenant instance requires a separately verified identity/company mapping. A site reference alone never grants access.

## Verification

`php tests/logistics.php` covers privacy, gate/equipment conflicts, duration boundaries, stale revisions, cancellation history and blackouts. `php tests/suite-integration.php` covers authentication, old contracts, explicit site references and server-bound company summaries. MySQL fixture tests and HTTP checks use only the named loopback `deliveries_gate_fixture` database; never point them at production. Run mysql-logistics.php, the upgrade twice, mysql-assertions.php, a local server on port 8790, then logistics-http.py.
