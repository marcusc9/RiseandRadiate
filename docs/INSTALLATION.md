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
- About Aoife
- Parent Support
- Teen Coaching
- Adult Coaching
- Organisations & Employers
- Contact

Confirm that:

- the header shows the Rise & Radiate identity, not “Agency”;
- Teen Coaching and Adult Coaching no longer return a not-found page;
- the Learn More button opens About Aoife;
- the Contact email and WhatsApp number are clickable;
- there is no placeholder Facebook link; and
- the form still submits to the expected inbox.

## Important privacy step

The plugin creates **Privacy Notice — Draft** but does not publish it. Aoife should confirm every service that receives information from the website—hosting, email, forms, analytics, booking, payment, and messaging—and have the final wording reviewed before publishing it.

## Recovery

The first pre-upgrade values are stored in the WordPress database under `rar_rescue_backup_v1`. Existing pages also receive a WordPress revision before replacement. Use the page’s **Revisions** panel to restore earlier content if necessary.

Deactivating the plugin removes the styling and redirects, but intentionally does not delete or roll back published pages. Restore page revisions or the hosting backup if a full rollback is required.
