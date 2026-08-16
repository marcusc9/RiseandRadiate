# Installation and verification

## Before installing

1. Confirm that Aoife owns or controls the domain, hosting, and primary WordPress administrator account.
2. Take a fresh hosting backup of both the files and database.
3. If the host provides staging, install the upgrade there first.

## Install the upgrade

1. Sign in to WordPress as an Administrator.
2. Open **Plugins → Add New Plugin → Upload Plugin**.
3. Select `rise-and-radiate-rescue.zip` and choose **Install Now**.
4. Activate **Rise & Radiate Rescue**.
5. Open **Tools → Rise & Radiate Rescue** and read the latest report.

## Verify the public site

Check these pages in a private browser window and on a phone:

- Home
- About
- Parent Support
- Teen Coaching
- Adults
- Organisations & Employers
- Contact

Confirm that:

- the header and mobile navigation work on every page;
- all seven pages open without a not-found page;
- the Learn More button opens About;
- the Contact email and WhatsApp number are clickable;
- the public wording matches the existing Rise & Radiate copy; and
- the form still submits to the expected inbox.

## Recovery

The first pre-upgrade values are stored in the WordPress database under `rar_rescue_backup_v1`. Existing pages also receive a WordPress revision before replacement. Use the page’s **Revisions** panel to restore earlier content if necessary.

Deactivating the plugin removes the rebuilt site shell, styling, redirects, and contact-form processing, but intentionally does not delete or roll back published pages. Restore page revisions or the hosting backup if a full rollback is required.
