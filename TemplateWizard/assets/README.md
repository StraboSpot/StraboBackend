# Vendored assets — provenance & licensing

| File | Library | Version | License | Source |
|---|---|---|---|---|
| `handsontable.full.min.js` / `.css` | Handsontable Community Edition | **6.2.2** | **MIT** | https://github.com/handsontable/handsontable (tag 6.2.2) |
| `xlsx.full.min.js` | SheetJS Community Edition | **0.20.1** | **Apache-2.0** | https://cdn.sheetjs.com/xlsx-0.20.1/package/dist/xlsx.full.min.js |

## Handsontable 6.2.2 pin — decision record

Handsontable changed its license after 6.2.2: from 7.0 onward it is
proprietary (commercial license required for this use). 6.2.2 is the **last
MIT release** and may be used and self-hosted freely, forever.

**Decision (Jason Ash, 2026-07-04): the 6.2.2 MIT pin is approved.**
Do NOT upgrade `handsontable.full.min.js` past 6.2.2 without purchasing a
commercial license.

Notes for future work against this pin:
- The `licenseKey: 'non-commercial-and-evaluation'` setting in
  `js/design_template.js` is ignored by 6.2.2 (it predates license keys) and
  is kept only for forward compatibility of the config shape.
- 6.2.2 includes the CE plugins we rely on (ContextMenu, ManualColumnMove,
  CopyPaste, Autofill). MergeCells exists but is NOT used — its merge ranges
  and manualColumnMove disagree about coordinates and shatter on drag
  (found live 2026-07-04); the designer's section band is merge-free by
  design. NestedHeaders was Pro-only and is absent from this build.
- Known 6.2.2 quirk: the `cells()` callback receives PHYSICAL column indices
  while `getDataAtCell()` expects VISUAL ones — always use
  `getSourceDataAtCell()` for header lookups inside `cells()`.

## SheetJS 0.20.1

Vendored 2026-07-04 (was previously loaded from cdn.sheetjs.com — the
wizard's last external asset dependency). Apache-2.0 permits self-hosting;
the file's internal `version="0.20.1"` matches the pinned CDN URL it came
from. Upgrades are license-safe; keep the version pinned deliberately.
