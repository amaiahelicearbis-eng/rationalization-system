/* Run in SSMS after backing up DB_DISP1. Creates new web tables only; does not drop legacy tables. */
USE [DB_DISP1];
GO
IF OBJECT_ID(N'dbo.web_production_plan',N'U') IS NULL
BEGIN
 CREATE TABLE dbo.web_production_plan(
  id BIGINT IDENTITY(1,1) PRIMARY KEY, process_key NVARCHAR(40) NOT NULL,
  plan_date DATE NOT NULL, model_code NVARCHAR(100) NOT NULL, model_name NVARCHAR(255) NULL,
  machine_no SMALLINT NULL, shift_name NVARCHAR(30) NULL, start_time TIME(0) NULL, end_time TIME(0) NULL,
  planned_qty INT NOT NULL CONSTRAINT DF_web_plan_planned DEFAULT 0,
  actual_qty INT NOT NULL CONSTRAINT DF_web_plan_actual DEFAULT 0, notes NVARCHAR(500) NULL,
  created_by NVARCHAR(80) NOT NULL, created_at DATETIME2(0) NOT NULL DEFAULT SYSDATETIME(),
  updated_by NVARCHAR(80) NULL, updated_at DATETIME2(0) NULL,
  CONSTRAINT CK_web_plan_qty CHECK(planned_qty>=0 AND actual_qty>=0));
 CREATE INDEX IX_web_plan_date_process ON dbo.web_production_plan(plan_date,process_key);
 CREATE INDEX IX_web_plan_model ON dbo.web_production_plan(model_code);
END;
GO
IF OBJECT_ID(N'dbo.web_monthly_plan',N'U') IS NULL
BEGIN
 CREATE TABLE dbo.web_monthly_plan(
  id BIGINT IDENTITY(1,1) PRIMARY KEY, plan_month DATE NOT NULL, model_code NVARCHAR(100) NOT NULL,
  model_name NVARCHAR(255) NULL, planned_qty INT NOT NULL DEFAULT 0, created_by NVARCHAR(80) NOT NULL,
  created_at DATETIME2(0) NOT NULL DEFAULT SYSDATETIME(), updated_by NVARCHAR(80) NULL, updated_at DATETIME2(0) NULL,
  CONSTRAINT CK_web_monthly_qty CHECK(planned_qty>=0));
 CREATE UNIQUE INDEX UX_web_monthly_model_month ON dbo.web_monthly_plan(plan_month,model_code);
END;
GO
