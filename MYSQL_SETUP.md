# MySQL setup

Upload `index.html`, `api.php`, `setup.php`, and `.htaccess` to the same public website folder.

1. Open `https://your-domain/setup.php`.
2. Use the values from your hosting panel:
   - Hostname: the MySQL hostname
   - Port: usually `3306`
   - Database name: the name of the database
   - Username: the MySQL username
   - Password: enter the MySQL password only on this setup page
   - Editor login password: choose a separate strong password for creating and editing Fielddesk entries
   - Confirm editor password: repeat the editor password
3. Submit the form. It verifies the connection and creates `fielddesk_records`.
4. Reload `index.html`.
5. Delete `setup.php` after setup is complete.

Fielddesk is password-gated before the interface loads. Visitors are shown the unlock screen, and only successful PHP session login enables editor controls on hosted MySQL deployments.
The public stream link is available to viewers, but the internal multiview link is only returned by the API to an authenticated editor.

Credentials are not written into `index.html` or JavaScript. They are stored in `fielddesk-config.php` outside the public HTML folder when the host permits it, or in the app folder when not.

Protect the deployed folder with HTTPS. If `fielddesk-config.php` is stored inside the app folder, keep `.htaccess` enabled so Apache denies direct access. On a host that does not support `.htaccess`, remove setup.php and serve the app from a folder with the config stored outside the web root.

The API stores flexible records in one `fielddesk_records` table:

- `store_name`: events, cameras, crew, channels, atem, cables, or songs
- `record_id`: stable record identifier
- `record_data`: full JSON record
- `updated_at`: server update timestamp

Do not use a MySQL account with broader privileges than needed. The application account should have `SELECT`, `INSERT`, `UPDATE`, and `DELETE` on the Fielddesk database.
