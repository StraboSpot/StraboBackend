-- StraboSearch Phase 2 — saved_search table.
--
-- Per §5.1.4 + §6.6 of DESIGN_PROPOSAL.md.
--
-- Replaces the legacy `public.fullsearches` PG table. Per §6.6 the cutover
-- translates existing fullsearches rows into the new DSL (silent OR/NOT→AND
-- translation per §4 Q6 — explicitly low-value).
--
-- `dsl_json` is the §4.4 query DSL: { subsystems[], pathway, criteria[],
-- sort, page, page_size }.

CREATE TABLE IF NOT EXISTS strabosearch.saved_search (
    saved_search_pkey bigserial PRIMARY KEY,
    user_pkey         integer     NOT NULL,
    search_name       text        NOT NULL,
    dsl_json          jsonb       NOT NULL,
    created_at        timestamptz NOT NULL DEFAULT now(),
    modified_at       timestamptz NOT NULL DEFAULT now()
);
ALTER TABLE strabosearch.saved_search OWNER TO strabodbuser;

-- Per-user name uniqueness keeps the rename / overwrite UX clean.
CREATE UNIQUE INDEX IF NOT EXISTS saved_search_user_name_uq
    ON strabosearch.saved_search (user_pkey, search_name);
