<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$error = '';
$success = '';

try {
    // The database itself must already exist. Run sql/install.sql in SSMS first.
    $pdo = db();

    $ddl = [
        "IF OBJECT_ID(N'dbo.users', N'U') IS NULL BEGIN
            CREATE TABLE dbo.users (
                id INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_users PRIMARY KEY,
                username NVARCHAR(80) NOT NULL,
                password_hash NVARCHAR(255) NOT NULL,
                full_name NVARCHAR(160) NOT NULL,
                role NVARCHAR(10) NOT NULL CONSTRAINT DF_users_role DEFAULT N'user',
                is_active BIT NOT NULL CONSTRAINT DF_users_is_active DEFAULT 1,
                created_at DATETIME2(0) NOT NULL CONSTRAINT DF_users_created_at DEFAULT SYSDATETIME(),
                updated_at DATETIME2(0) NULL,
                CONSTRAINT UQ_users_username UNIQUE (username),
                CONSTRAINT CK_users_role CHECK (role IN (N'admin', N'user'))
            );
        END",
        "IF OBJECT_ID(N'dbo.process_csv_sources', N'U') IS NULL BEGIN
            CREATE TABLE dbo.process_csv_sources (
                id INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_process_csv_sources PRIMARY KEY,
                process_key NVARCHAR(40) NOT NULL,
                process NVARCHAR(120) NOT NULL,
                link NVARCHAR(MAX) NULL,
                updated_by NVARCHAR(80) NULL,
                updated_at DATETIME2(0) NULL,
                created_at DATETIME2(0) NOT NULL CONSTRAINT DF_process_csv_sources_created_at DEFAULT SYSDATETIME(),
                CONSTRAINT UQ_process_csv_sources_process_key UNIQUE (process_key)
            );
        END",
        "IF OBJECT_ID(N'dbo.change_history', N'U') IS NULL BEGIN
            CREATE TABLE dbo.change_history (
                id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_change_history PRIMARY KEY,
                user_id INT NULL,
                username NVARCHAR(80) NOT NULL,
                action NVARCHAR(30) NOT NULL,
                entity_type NVARCHAR(80) NOT NULL,
                entity_id BIGINT NULL,
                description NVARCHAR(500) NOT NULL,
                before_data NVARCHAR(MAX) NULL,
                after_data NVARCHAR(MAX) NULL,
                ip_address VARCHAR(45) NULL,
                created_at DATETIME2(0) NOT NULL CONSTRAINT DF_change_history_created_at DEFAULT SYSDATETIME(),
                CONSTRAINT FK_change_history_user FOREIGN KEY (user_id) REFERENCES dbo.users(id) ON DELETE SET NULL
            );
        END",
        "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_change_history_created' AND object_id=OBJECT_ID(N'dbo.change_history')) CREATE INDEX IX_change_history_created ON dbo.change_history(created_at)",
        "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_change_history_user' AND object_id=OBJECT_ID(N'dbo.change_history')) CREATE INDEX IX_change_history_user ON dbo.change_history(user_id)",
        "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_change_history_entity' AND object_id=OBJECT_ID(N'dbo.change_history')) CREATE INDEX IX_change_history_entity ON dbo.change_history(entity_type, entity_id)",
    ];
    foreach ($ddl as $statement) $pdo->exec($statement);

    foreach (PROCESS_LIST as $key => $process) {
        $check = $pdo->prepare('SELECT COUNT(*) FROM dbo.process_csv_sources WHERE process_key = ?');
        $check->execute([$key]);
        if ((int)$check->fetchColumn() === 0) {
            $insert = $pdo->prepare('INSERT INTO dbo.process_csv_sources(process_key, process, link) VALUES(?, ?, NULL)');
            $insert->execute([$key, $process['label']]);
        }
    }

    // Seed the requested initial admin only when no account with that username exists.
    $adminCheck = $pdo->prepare('SELECT COUNT(*) FROM dbo.users WHERE username = ?');
    $adminCheck->execute(['admin']);
    if ((int)$adminCheck->fetchColumn() === 0) {
        $insertAdmin = $pdo->prepare('INSERT INTO dbo.users(username, password_hash, full_name, role, is_active) VALUES(?, ?, ?, ?, ?)');
        $insertAdmin->execute(['admin', '$2y$12$0SvrcaYgyD4BTFyWwV0jfeuRAs2GL8aHJM3fr7Vkw./I4XE953HpW', 'Administrator', 'admin', 1]);
    }

    $success = 'SQL Server tables and process configuration are ready.';
} catch (Throwable $e) {
    $error = 'Setup failed: ' . $e->getMessage();
}

$userCount = 0;
try { $userCount = (int) db()->query('SELECT COUNT(*) FROM dbo.users')->fetchColumn(); } catch (Throwable $e) {}
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>System setup</title><link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/csv.css"><link rel="stylesheet" href="assets/admin.css"></head>
<body class="auth-body"><main class="auth-card auth-card-wide"><div class="auth-logo">P</div><div class="eyebrow">INITIAL CONFIGURATION</div><h1>SQL Server setup</h1><p>This checks or creates the application tables in the configured SQL Server database. Run <code>sql/install.sql</code> in SSMS first to create the database.</p>
<?php if ($error): ?><div class="notice notice-error"><strong>Setup issue</strong><span><?= h($error) ?></span></div><?php endif; ?>
<?php if ($success): ?><div class="notice notice-success"><strong>Setup complete</strong><span><?= h($success) ?></span></div><?php endif; ?>
<?php if ($userCount > 0): ?><p>Accounts found: <strong><?= number_format($userCount) ?></strong></p><p>Initial login, if the seeded administrator has not been changed: <strong>admin</strong> / <strong>admin</strong>.</p><a class="btn btn-primary" href="login.php">Go to login</a><?php else: ?><p>No user account exists yet. Check the database setup and connection.</p><?php endif; ?>
<p class="auth-foot">After confirming setup, restrict or remove access to setup.php. Change the temporary administrator password immediately.</p></main></body></html>
