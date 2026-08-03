-- Remove the StraboSearch Phase 0.3 spike schema entirely.
--   docker exec -i strabo-postgres psql -U postgres -d strabospot < searchdb/spike/spike_teardown.sql
DROP SCHEMA IF EXISTS strabosearch_spike CASCADE;
