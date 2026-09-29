-- Extensions the application relies on. Created here so a fresh container is
-- already correct rather than failing on the first JSON column.

CREATE EXTENSION IF NOT EXISTS pgcrypto;
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
