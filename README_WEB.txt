RATIONALIZATION SYSTEM - WEB EDITION

WHAT IS INCLUDED
- Existing live NAS/Synology CSV explorer retained.
- Login, users and audit trail retained.
- New daily production planning for all nine processes.
- New monthly production target page.
- New dashboard for daily planned/actual/remaining/achievement.
- Model/part-number suggestions in daily plans read from the configured process CSV.

INSTALLATION
1. Back up your existing web folder and SQL databases first.
2. The supplied disp.11.5datascript.sql script targets DB_DISP1. Confirm DB_DISP1 exists in SSMS.
3. config/config.php now points to DB_DISP1. Confirm host, SQL username and password are correct for your environment.
4. Run sql/web_migration_DB_DISP1.sql in SSMS. This creates only dbo.web_production_plan and dbo.web_monthly_plan. It does not drop or alter legacy tables.
5. Run setup.php once. It creates dbo.users, dbo.process_csv_sources and dbo.change_history in DB_DISP1 if missing. Existing user accounts in the separate rationalization-system database are not migrated automatically.
6. Open login.php. Initial admin is admin/admin only if setup seeded it; change the password immediately.
7. Automatic master data sources are configured in config/master-data.php. By default PHP reads \\192.168.20.11\BarcodeTagPrint\MASTER DATA directly using the Apache Windows account. That account needs read permission to the share; mapped drive O: is not required. File Station is optional: use a synology: root and a private Synology configuration when Windows share access is unavailable.

IMPORTANT DATA NOTES
- Existing CSV functionality was retained. Daily production page uses the configured CSV for model/part-number suggestions.
- Actual Qty is currently manually editable. The conversation only established that CSV supplies actual model/part number; no actual quantity column mapping was confirmed.
- Legacy tables such as TB_MONTHLY, TB_CUTPRESSURE, TB_KITTING, TB_SUBASSY_SAKI, TB_SUBASSY_ATO, TB_ASSY, TB_CONTACT_SW, TB_MR_SW, TB_HOT_MELT, TB_FINISH_GOODS and TB_M_ITEM are not deleted or modified.
- Do NOT delete DB_DISP1 or the BrightKeeper/second database yet. The original C# application queried BrightKeeper scan logs for actual production, so the dependency must be checked before retirement.
- This is the first integrated web implementation. It provides planning CRUD and dashboard but is not yet a complete one-for-one port of every original hourly calculation or scanner integration.

REQUIREMENTS
PHP 8.1+, PDO_SQLSRV, SQL Server, cURL for Synology API access.

AUTOMATIC MASTER DATA
- Cut & Crimp: engineering CNC (003) plus standard CNC (000).
- Finished Goods: QC (002).
- Saki, Ato, Contact SW, MR-SW, Hot Melt and Assembly: engineering SUB-ASSY (003), DOOR-MIRROR and standard SUB-ASSY (001).
- Kitting: all six sources above.
- Only the newest matching CSV in each configured folder is used. Backup and DO NOT USE subfolders are never scanned.
- ModelName + Branch + Barcode identifies a record, ignoring case and surrounding spaces. Later configured sources win on duplicates; distinct branches/barcodes remain separate. Without a usable identity, only equal complete rows are removed.
- Model Data and production suggestions use the same sources. The data explorer checks for changes every 10 seconds, including source filename changes. The old manual source-selection panel has been removed.
- Run php tests/master-data.php to check discovery, merging, duplicate removal and refresh signatures without connecting to the NAS or SQL Server.


UNIFIED LAYOUT UPDATE
Open workspace.php after login. Dashboard, Monthly Schedule, Production Planning, Live CSV / Model Data, Change History, and User Management now load inside one shared navigation shell. The process shortcuts open the selected process in the embedded page. Existing standalone PHP endpoints remain available for compatibility.
