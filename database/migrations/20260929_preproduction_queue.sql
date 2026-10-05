ALTER TABLE integration_jobs ADD COLUMN IF NOT EXISTS dedupe_key CHAR(64) NULL AFTER payload;

SET @cobx_has_index = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'integration_jobs' AND INDEX_NAME = 'uq_jobs_active_dedupe'
);
SET @cobx_sql = IF(@cobx_has_index = 0,
  'CREATE UNIQUE INDEX uq_jobs_active_dedupe ON integration_jobs (dedupe_key)',
  'SELECT 1'
);
PREPARE cobx_stmt FROM @cobx_sql;
EXECUTE cobx_stmt;
DEALLOCATE PREPARE cobx_stmt;
