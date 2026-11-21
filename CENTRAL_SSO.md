# Optional NBC Central sign-in for Rationalization

Direct password login and Central SSO use separate sessions. There are no SQL
migrations, account provisioning, NAS changes or global PHP configuration changes.

## Production configuration

- Application folder: `C:\Xampp\htdocs\rationalization-system`.
- Central application key: `rationalization`.
- Callback: `https://192.168.23.64/rationalization-system/central-sso.php`.
- Private connector: `C:\Xampp\nbc-bridges`, outside the web root.
- Issuer: `https://192.168.20.10:4883/nbc-central`.

Keep the existing client registration, key and CA bundle. Do not rerun the old
bridge installer or copy Central database credentials into the application. The
private connector is discovered automatically. `RAT_REMOTE_ROOT` can override
its directory through trusted server configuration. The connector folder,
callback and cookie path must match the actual application directory.

For same-host installations the callback also accepts the existing bound POST
ticket through a sibling `nbc-central` installation. `RAT_CENTRAL_ROOT` can set
that directory. Cross-server production uses GET authorization codes, browser
state and S256 PKCE through the private connector, with TLS verification enabled.

Run this health check on the production application server before rollout:

```powershell
& C:\Xampp\php\php.exe C:\Xampp\nbc-bridges\diagnose.php rationalization
```

Require PASS, HTTP 200 and TLS verification result 0. The user-provided production
diagnostic passed on 2026-11-21. This verifies the CLI connector; a browser launch
must still verify Apache's PHP runtime and the actual account mapping.

## Native login and permissions

Native login retains `RATIONALIZATIONSESSID`, its existing storage, password
checks, CSRF checks and audit logging. Native login/setup never load Central.
Central uses `RAT_STAFF_SSO`, scoped to this application, with a separate
`rat-staff-sso-v1` directory under PHP's session storage.

SSO maps an existing active `dbo.users.username` as a string, preserving leading
zeros. It includes `user_process` as well as the native `admin` or `user` role.
Administrators retain all processes. Standard users retain the process parsing
and permissions already implemented by the application, including the legacy
`subassy` assignment for Saki and Ato. The landing page opens the first allowed
process; accounts with no allowed process are rejected. The old provider omitted
`user_process` and always opened Cut & Crimp, which could deny valid users access.

Every protected SSO request revalidates the Central grant and reloads the active
native account and assignments. Native page guards remain authoritative; no new
page permissions are granted. Account deactivation, grant revocation and process
assignment changes affect existing SSO sessions.

Links, pagination, GET forms, workspace iframes and polling retain `rat_auth` to
keep a tab's native/Central selection. A mode cookie supplies the default for bare
protected links. Opening `login.php` always uses the native session. Native logout
retains its existing destination. Central logout clears only its application SSO
session, records the native audit event when SQL is available and returns to the
trusted portal URL stored at handoff, even during a Central API outage.

## Existing production shim

Production has a legacy root `central-session.php` containing `NBC Central managed
bridge` and a require of `C:/Xampp/htdocs/nbc-central/integrations/guard.php`.
The checked root `.htaccess` had no matching prepend setting. A server-level
prepend setting has not been ruled out.

The new application-owned `central-session.php` intentionally loads only the
helper functions. The application bootstrap owns session selection, so the shim
is safe if an older prepend setting still references it. No new prepend setting
is installed. Deploy this replacement together with the helper, callback and
bootstrap changes. Do not retain the old managed guard wrapper.

Before deploying, back up that production shim outside the web root. If it is
untracked, a Git update that introduces the new tracked file may refuse to
overwrite it; handle that exact backed-up wrapper before retrying. Preserve
`config/config.php`, private NAS configuration, storage and unrelated changes.
Existing legacy Central cookies are not imported into native authentication;
users of the legacy SSO session should launch the application again from Central.

## Tests and rollout

Database/NAS-free regression commands:

```powershell
& C:\Xampp\php\php.exe tests\central-sso.php
& C:\Xampp\php\php.exe tests\central-sso.php --connector-root=C:\Xampp\nbc-bridges
& C:\Xampp\php\php.exe tests\master-data.php
```

The optional connector argument runs the installed connector functions with a
simulated cURL transport; it makes no network calls. It checks HTTPS-only
transport, certificate verification settings, redirect refusal, timeout bounds,
PKCE exchange and request validation. Do not add `--live` to the master-data test
unless a live NAS check is intended.

The SSO suite exercises real native authentication, process guards, ModelData,
fixture CSV reads, polling, workspace navigation and audit functions with fixture
SQL. It also checks session isolation, process reassignment, inactive accounts,
grant revocation, browser binding/replay, both logout paths and API outages.

After deploying the complete reviewed revision, verify direct login and a Central
tile launch in separate tabs. Test an administrator and a user assigned to a
process other than Cut & Crimp; confirm the landing page, allowed and forbidden
processes, filters, pagination, polling, workspace iframe and logout destinations.
Use authorized test accounts for production writes. Fixture tests do not prove
production browser sign-in.

To disable only SSO, create an empty `.nbc-central-disabled` file in the app root.
Native access remains available. Remove that exact marker to re-enable SSO.
