-- Executed once when the local PostgreSQL volume is created (profile local-db).
-- The RAG module verifies the extension again before enabling itself.
CREATE EXTENSION IF NOT EXISTS vector;
