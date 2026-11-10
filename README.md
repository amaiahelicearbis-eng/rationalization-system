# Rationalization System — SQL Server Web Edition

This PHP application reads a different CSV file for each production process. CSV headers and rows are discovered dynamically. The Monthly Schedule module is intentionally excluded.

## Included processes
- Cut & Crimp
- Kitting
- Sub Assy (Saki)
- Sub Assy (Ato)
- Contact SW
- MR-SW
- Hot Melt
- Finished Goods
- Assembly

## Technology and requirements
- PHP 8.1 or later
- Microsoft SQL Server
- PHP `pdo_sqlsrv` extension enabled
- Apache/IIS identity with read permission to the shared CSV folder

## Database connection
`config/config.php` is configured to match the connection details supplied for this installation:

- Server: `localhost`
- Database: `rationalization-system`
- Username: `sa`
- Driver: PDO_SQLSRV

The supplied configuration includes the password from your message. For a deployed system, change it to a dedicated SQL Server login with only the permissions the application needs; avoid using `sa` outside local testing.

## Installation
1. Copy the `RationalizationWeb` folder to your web root, such as `C:\xampp\htdocs\RationalizationWeb`.
2. Install and enable Microsoft's PHP SQL Server drivers, including `pdo_sqlsrv`, for the PHP version used by Apache.
3. Open `sql/install.sql` in SQL Server Management Studio (SSMS) and execute it. The script creates the `rationalization-system` database, application tables, process rows, and the initial admin account if missing.
4. Confirm `config/config.php` has the correct connection details.
5. In a browser, open `http://localhost/RationalizationWeb/setup.php`. This checks/creates missing application tables and process configuration. After confirming setup, restrict or remove access to `setup.php`.
6. Sign in with username `admin` and initial password `admin` if that account was seeded and has not been changed. Change this password immediately.

## Shared CSV paths
The source link for each process is stored in `dbo.process_csv_sources.link`. No individual CSV path is hardcoded in a process page. A signed-in user can enter a full CSV path and save it. Link changes are written to `dbo.change_history` with the username, action, before/after values, timestamp, and IP address.

The application now uses the browser's native **Browse files…** dialog. Choose a CSV from Desktop, Documents, This PC, a mapped drive, or a shared folder, then click **Upload and use this CSV**. The selected file is copied to the protected `storage/uploads` folder on the web server, and that server-side path is saved in `dbo.process_csv_sources.link`. This is necessary because browsers do not expose the original full desktop path to PHP. If you want the app to keep reading the original shared file without uploading, that requires a different setup where the PHP server itself can access the share. Ensure the web server can write to `storage/uploads`.

The CSV source is read-only: this app displays and searches the CSV but does not modify the shared file itself. The page reads the file again when refreshed.

## Database tables
- `dbo.users`: user accounts, roles, active status, and password hashes.
- `dbo.process_csv_sources`: one configured CSV source per process.
- `dbo.change_history`: audit log of logins, logouts, process source changes, and user administration actions.

## Notes
- The PHP backend uses SQL Server syntax (`TOP`, `SYSDATETIME`, `OUTPUT INSERTED`, and SQL Server pagination) and PDO_SQLSRV, not PDO_MYSQL.
- The process data viewer reads CSV columns dynamically; it does not require the CSV columns to exist in SQL Server tables.
- Production use should be tested with your actual SQL Server and shared-folder permissions before rollout.
- The initial admin password is weak and intended only for first-time setup.


## Select CSV files from your desktop

On a process page, click **Browse files…** to open the normal Windows file picker. Select a `.csv` file, then click **Upload and use this CSV**. The application validates the CSV header, stores a protected copy under `storage/uploads`, saves that server-side file path in SQL Server, and logs the before/after change in `dbo.change_history`. The file input is limited to CSV files and a 50 MB upload limit. Configure PHP `upload_max_filesize` and `post_max_size` to at least 50M if you need the full limit. If the source file changes later on your desktop, select and upload the updated copy again.
