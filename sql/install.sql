/* Rationalization System - Microsoft SQL Server setup
   Run this script in SQL Server Management Studio (SSMS).
   It creates the database, tables, process rows and initial admin account if missing.
*/
IF DB_ID(N'rationalization-system') IS NULL
BEGIN
    CREATE DATABASE [rationalization-system];
END;
GO
USE [rationalization-system];
GO
IF OBJECT_ID(N'dbo.users', N'U') IS NULL
BEGIN
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
END;
GO
IF OBJECT_ID(N'dbo.process_csv_sources', N'U') IS NULL
BEGIN
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
END;
GO
IF OBJECT_ID(N'dbo.change_history', N'U') IS NULL
BEGIN
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
END;
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_change_history_created' AND object_id=OBJECT_ID(N'dbo.change_history')) CREATE INDEX IX_change_history_created ON dbo.change_history(created_at);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_change_history_user' AND object_id=OBJECT_ID(N'dbo.change_history')) CREATE INDEX IX_change_history_user ON dbo.change_history(user_id);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_change_history_entity' AND object_id=OBJECT_ID(N'dbo.change_history')) CREATE INDEX IX_change_history_entity ON dbo.change_history(entity_type, entity_id);
GO
IF NOT EXISTS (SELECT 1 FROM dbo.process_csv_sources WHERE process_key=N'cut') INSERT INTO dbo.process_csv_sources(process_key,process,link) VALUES(N'cut',N'Cut & Crimp',NULL);
IF NOT EXISTS (SELECT 1 FROM dbo.process_csv_sources WHERE process_key=N'kitting') INSERT INTO dbo.process_csv_sources(process_key,process,link) VALUES(N'kitting',N'Kitting',NULL);
IF NOT EXISTS (SELECT 1 FROM dbo.process_csv_sources WHERE process_key=N'saki') INSERT INTO dbo.process_csv_sources(process_key,process,link) VALUES(N'saki',N'Sub Assy (Saki)',NULL);
IF NOT EXISTS (SELECT 1 FROM dbo.process_csv_sources WHERE process_key=N'ato') INSERT INTO dbo.process_csv_sources(process_key,process,link) VALUES(N'ato',N'Sub Assy (Ato)',NULL);
IF NOT EXISTS (SELECT 1 FROM dbo.process_csv_sources WHERE process_key=N'contact') INSERT INTO dbo.process_csv_sources(process_key,process,link) VALUES(N'contact',N'Contact SW',NULL);
IF NOT EXISTS (SELECT 1 FROM dbo.process_csv_sources WHERE process_key=N'mrsw') INSERT INTO dbo.process_csv_sources(process_key,process,link) VALUES(N'mrsw',N'MR-SW',NULL);
IF NOT EXISTS (SELECT 1 FROM dbo.process_csv_sources WHERE process_key=N'hotmelt') INSERT INTO dbo.process_csv_sources(process_key,process,link) VALUES(N'hotmelt',N'Hot Melt',NULL);
IF NOT EXISTS (SELECT 1 FROM dbo.process_csv_sources WHERE process_key=N'finished') INSERT INTO dbo.process_csv_sources(process_key,process,link) VALUES(N'finished',N'Finished Goods',NULL);
IF NOT EXISTS (SELECT 1 FROM dbo.process_csv_sources WHERE process_key=N'assy') INSERT INTO dbo.process_csv_sources(process_key,process,link) VALUES(N'assy',N'Assembly',NULL);
GO
/* bcrypt hash for temporary password "admin"; change immediately after first login. */
IF NOT EXISTS (SELECT 1 FROM dbo.users WHERE username=N'admin')
BEGIN
    INSERT INTO dbo.users(username,password_hash,full_name,role,is_active)
    VALUES(N'admin',N'$2y$12$0SvrcaYgyD4BTFyWwV0jfeuRAs2GL8aHJM3fr7Vkw./I4XE953HpW',N'Administrator',N'admin',1);
END;
GO
SELECT N'Rationalization System SQL Server setup completed.' AS result;
GO
