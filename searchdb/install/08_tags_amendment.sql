-- StraboSearch Phase 2 — TAGS amendment (2026-06-29 design amendment).
--
-- Adds the U10 (tag name, universal) / F11 (tag type, Field-only) columns
-- to both primary tables plus the live tag-type vocabulary table. Per the
-- amended §5.1.3 / §5.1.4 / §4.3-Tag of DESIGN_PROPOSAL.md:
--
--   tag_names     TEXT[]    tag names attached to the item (U10 prefix/
--                           typeahead path; names are ALSO folded into
--                           searchtext_tsv by the extractors so the U1
--                           global keyword box catches them)
--   tag_types     TEXT[]    distinct tag types on the item (F11 multi-select;
--                           Field populates today, Micro name-only → NULL,
--                           Exp/Samples NULL until those subsystems gain tags)
--   tag_text_tsv  TSVECTOR  tag names only — the dedicated U10 keyword/
--                           phrase path (tag:"fault zone")
--
-- image_hit gets the same trio: Field images inherit the parent spot's
-- tags (§5.1.3 Q4b inheritance); Micro micrographs carry native tag names
-- (the micrograph IS the image).
--
-- Idempotent: ADD COLUMN / CREATE TABLE / CREATE INDEX all guarded.
-- Safe to re-run in the 0?_*.sql install pipe.

ALTER TABLE strabosearch.item_hit
    ADD COLUMN IF NOT EXISTS tag_names    text[],
    ADD COLUMN IF NOT EXISTS tag_types    text[],
    ADD COLUMN IF NOT EXISTS tag_text_tsv tsvector;

ALTER TABLE strabosearch.image_hit
    ADD COLUMN IF NOT EXISTS tag_names    text[],
    ADD COLUMN IF NOT EXISTS tag_types    text[],
    ADD COLUMN IF NOT EXISTS tag_text_tsv tsvector;

-- Live F11 dropdown vocabulary — refreshed each Field extract from observed
-- values. Governance is a deny-list of NULL/empty ONLY (allow-list
-- deliberately rejected per §8 — it rots as tag types grow). display_label
-- auto-derives snake_case → Title Case at upsert time; unknown future types
-- render and search with zero code change.
CREATE TABLE IF NOT EXISTS strabosearch.vocab_tag_type (
    raw_value     varchar    NOT NULL,               -- observed Tag.type value
    display_label varchar    NOT NULL,               -- derived Title Case label
    subsystem     varchar    NOT NULL,               -- 'field' (Micro is name-only)
    PRIMARY KEY (subsystem, raw_value)
);
ALTER TABLE strabosearch.vocab_tag_type OWNER TO strabodbuser;

-- §5.1.5 index shapes for the tag criteria. GIN on the tsvector for the
-- dedicated U10 keyword path; GIN on the text arrays for U10 prefix/
-- typeahead + the F11 IN (...) predicate.
CREATE INDEX IF NOT EXISTS item_hit_tag_text_tsv_gin  ON strabosearch.item_hit  USING gin (tag_text_tsv);
CREATE INDEX IF NOT EXISTS item_hit_tag_names_gin     ON strabosearch.item_hit  USING gin (tag_names);
CREATE INDEX IF NOT EXISTS item_hit_tag_types_gin     ON strabosearch.item_hit  USING gin (tag_types);
CREATE INDEX IF NOT EXISTS image_hit_tag_text_tsv_gin ON strabosearch.image_hit USING gin (tag_text_tsv);
CREATE INDEX IF NOT EXISTS image_hit_tag_names_gin    ON strabosearch.image_hit USING gin (tag_names);
CREATE INDEX IF NOT EXISTS image_hit_tag_types_gin    ON strabosearch.image_hit USING gin (tag_types);
