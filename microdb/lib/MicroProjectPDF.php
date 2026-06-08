<?php
/**
 * File: microdb/lib/MicroProjectPDF.php
 * Description: Server-side Micro project PDF generator. Ports the
 *              StraboMicro2 desktop client's PDF export
 *              (docs/StraboSamples/micro_pdf_reference/StraboMicro2/
 *              electron/pdfProjectExport.js) to tFPDF so the server can
 *              regenerate the artifact when a Samples-app spine edit
 *              flips micro_projectmetadata.pdf_dirty.
 *
 *              Phase 1 (samples/micro-server-pdf):
 *                  Layout primitives + the cover / TOC / Project Details /
 *                  per-Dataset / per-Sample sections fully ported. Per-
 *                  Micrograph and per-Spot rendered as placeholder pages.
 *              Phase 2 (THIS branch — samples/micro-server-pdf-micrographs):
 *                  Per-Micrograph section with composite image embed from
 *                  straboMicroFiles/<project_internal_id>/images/{strabo_id}.jpg
 *                  + Instrument Information sub-section (with detector list) +
 *                  Orientation Information sub-section + addFeatureInfoSections
 *                  helper (mineralogy / grain info / fabric) shared with Phase 3.
 *              Phase 3 (samples/micro-server-pdf-spots):
 *                  Per-Spot section + remaining feature-info renderers.
 *
 *              Data source: micro_projectmetadata + micro_datasetmetadata
 *              + micro_samplemetadata + micro_micrographmetadata. The
 *              sample spine is overlaid from strabosamples.samples at
 *              render time (mirrors the read-through pattern used by the
 *              Micro download endpoints — see microdb/lib/sample_overlay.php).
 *
 *              Layout mirrors pdfProjectExport.js as closely as tFPDF allows.
 *              Mismatches are intentional and called out inline where they
 *              matter; the goal is "visually similar with same content +
 *              same structure", not pixel-perfect.
 */

require_once __DIR__ . '/../../includes/tfpdf/tfpdf.php';

class MicroProjectPDF extends tFPDF
{
    // Page dimensions are tFPDF's LETTER preset (in mm — tFPDF uses
    // millimeter units by default). 216 x 279.4mm = 612 x 792pt.
    const PAGE_WIDTH_MM  = 215.9;
    const PAGE_HEIGHT_MM = 279.4;
    const MARGIN_MM      = 15;

    // Font sizes (pt) — mirror StraboMicro2's FONT_SIZES table.
    const FS_TITLE    = 24;
    const FS_HEADING1 = 18;
    const FS_HEADING2 = 14;
    const FS_HEADING3 = 12;
    const FS_BODY     = 10;
    const FS_SMALL    = 9;
    const FS_CAPTION  = 8;

    // RGB colors (matching pdfProjectExport.js COLORS).
    const C_PRIMARY     = array(44, 62, 80);     // #2C3E50
    const C_SECONDARY   = array(127, 140, 141);  // #7F8C8D
    const C_ACCENT      = array(52, 152, 219);   // #3498DB
    const C_TEXT        = array(51, 51, 51);     // #333333
    const C_LIGHT_TEXT  = array(102, 102, 102);  // #666666
    const C_BORDER      = array(189, 195, 199);  // #BDC3C7
    const C_BACKGROUND  = array(248, 249, 250);  // #F8F9FA

    /** @var object PG handle (ezSQL-style: get_row_prepared, get_results_prepared, get_var_prepared) */
    protected $db;

    /** @var int micro_projectmetadata.id (internal numeric pkey) */
    protected $projectInternalId;

    /** @var int micro_projectmetadata.userpkey (project owner) */
    protected $ownerPkey;

    /** @var object Project metadata row */
    protected $project;

    /** @var array Datasets — each has ->samples (and samples each have ->micrographs) loaded eagerly */
    protected $datasets = array();

    /** @var array Flat list of {sample, dataset} pairs for the per-sample pass */
    protected $allSamples = array();

    /** @var array Flat list of {micrograph, sample, dataset} pairs */
    protected $allMicrographs = array();

    /** @var array Flat list of {spot, micrograph, sample, dataset} pairs */
    protected $allSpots = array();

    /** @var array TOC entries: [{ title, link, level }] — link is the tFPDF AddLink() id */
    protected $tocEntries = array();

    /** @var array Flat list of every section/subsection header rendered.
     *  Lets callers verify layout presence without parsing the glyph-encoded
     *  PDF text. Captured at render time; production code paths ignore it. */
    protected $renderedSectionHeaders = array();

    public function getRenderedSectionHeaders()
    {
        return $this->renderedSectionHeaders;
    }

    public function __construct($db, $projectInternalId, $ownerPkey)
    {
        // 'P'ortrait, 'mm' units, 'Letter' size — matches PDF Letter at 612x792pt.
        parent::__construct('P', 'mm', 'Letter');

        $this->db                = $db;
        $this->projectInternalId = (int)$projectInternalId;
        $this->ownerPkey         = (int)$ownerPkey;

        $this->SetMargins(self::MARGIN_MM, self::MARGIN_MM, self::MARGIN_MM);
        $this->SetAutoPageBreak(true, self::MARGIN_MM);
        $this->SetCompression(true);

        // Use the DejaVu fonts that tFPDF ships with Unicode support
        // (the same fonts ExperimentPDF.php uses). Lazy-add only the
        // styles we touch.
        $this->AddFont('DejaVu', '',  'DejaVuSansCondensed.ttf', true);
        $this->AddFont('DejaVu', 'B', 'DejaVuSansCondensed-Bold.ttf', true);
        $this->AddFont('DejaVu', 'I', 'DejaVuSansCondensed-Oblique.ttf', true);
    }

    // -----------------------------------------------------------------
    // Public entry point
    // -----------------------------------------------------------------

    /**
     * Build the whole document and write it to $outputPath.
     * Returns the path on success, throws on hard failure.
     */
    public function generateToFile($outputPath)
    {
        $this->loadProjectData();
        $this->collectFlatLists();

        // Pre-create one tFPDF AddLink() id per TOC entry so the TOC page
        // can link forward to content pages.
        $this->preallocateTocLinks();

        // Page 1: Cover (no header/footer chrome).
        $this->AddPage();
        $this->generateCoverPageContent();

        // Pages 2+: TOC (clickable). At least one page; spills onto more
        // as needed (tFPDF's page break + Cell flow handles this naturally).
        $this->AddPage();
        $this->generateTableOfContents();

        // Project details + datasets + samples — one new page per section.
        $this->AddPage();
        $this->bindTocLink('Project Details');
        $this->generateProjectDetailsContent();

        foreach ($this->datasets as $d) {
            $this->AddPage();
            $this->bindTocLink('Dataset: ' . ($d->name ?: 'Unnamed'));
            $this->generateDatasetSectionContent($d);
        }

        foreach ($this->allSamples as $row) {
            $sample  = $row['sample'];
            $dataset = $row['dataset'];
            $this->AddPage();
            $this->bindTocLink('Sample: ' . $this->sampleDisplayName($sample));
            $this->generateSampleSectionContent($sample, $dataset);
        }

        // Per-Spot remains stubbed until Phase 3.
        foreach ($this->allMicrographs as $row) {
            $micrograph = $row['micrograph'];
            $sample     = $row['sample'];
            $dataset    = $row['dataset'];
            $this->AddPage();
            $this->bindTocLink('Micrograph: ' . ($micrograph->name ?: 'Unnamed'));
            $this->generateMicrographSectionContent($micrograph, $sample, $dataset);
        }

        foreach ($this->allSpots as $row) {
            $spot       = $row['spot'];
            $micrograph = $row['micrograph'];
            $sample     = $row['sample'];
            $dataset    = $row['dataset'];
            $this->AddPage();
            $this->bindTocLink('Spot: ' . ($spot->name ?: 'Unnamed'));
            $this->generateSpotSectionContent_phase1Stub($spot, $micrograph, $sample, $dataset);
        }

        $this->Output('F', $outputPath);
        return $outputPath;
    }

    // -----------------------------------------------------------------
    // Data loading
    // -----------------------------------------------------------------

    protected function loadProjectData()
    {
        $this->project = $this->db->get_row_prepared(
            "SELECT id, userpkey, strabo_id, name, original_filename,
                    startdate, enddate, purposeofstudy, areaofinterest,
                    projectlocation, instrumentsused, otherteammembers,
                    gpsdatum, magneticdeclination, notes, date,
                    modifiedtimestamp
               FROM micro_projectmetadata
              WHERE id = $1 AND userpkey = $2 LIMIT 1",
            array($this->projectInternalId, $this->ownerPkey)
        );
        if (!$this->project) {
            throw new RuntimeException("Project not found: id=$this->projectInternalId userpkey=$this->ownerPkey");
        }

        $dsRows = $this->db->get_results_prepared(
            "SELECT id, strabo_id, name FROM micro_datasetmetadata
              WHERE project_id = $1 ORDER BY id",
            array($this->projectInternalId)
        );
        $this->datasets = is_array($dsRows) ? $dsRows : array();

        foreach ($this->datasets as $d) {
            // Samples in this dataset (overlaid with strabosamples spine
            // at the very end so any Samples-app edit shows up).
            $sRows = $this->db->get_results_prepared(
                "SELECT id, strabo_id, label, sampleid, latitude, longitude,
                        mainsamplingpurpose, sampledescription, materialtype,
                        inplacenessofsample, orientedsample, samplesize,
                        degreeofweathering, samplenotes, sampletype, color,
                        lithology, sampleunit, othermaterialtype,
                        sampleorientationnotes, othersamplingpurpose
                   FROM micro_samplemetadata WHERE dataset_id = $1 ORDER BY id",
                array($d->id)
            );
            $d->samples = is_array($sRows) ? $sRows : array();

            foreach ($d->samples as $s) {
                $mRows = $this->db->get_results_prepared(
                    "SELECT id, strabo_id, name, parentid, imagetype,
                            width, height, scalepixelspercentimeter,
                            scale, description, notes, polish, polishdescription
                       FROM micro_micrographmetadata WHERE sample_id = $1 ORDER BY id",
                    array($s->id)
                );
                $s->micrographs = is_array($mRows) ? $mRows : array();
                foreach ($s->micrographs as $m) {
                    // Phase 3 will load spots — Phase 2 just stubs the list so
                    // the per-micrograph "Spots" summary renders nothing.
                    $m->spots = array();
                    $this->loadMicrographSubresources($m);
                }
            }
        }

        // Overlay strabosamples spine onto each sample. Builds a fake
        // project.json shape that micro_sample_overlay_apply expects.
        require_once __DIR__ . '/sample_overlay.php';
        $fakeJson = (object)array('datasets' => array());
        foreach ($this->datasets as $d) {
            $shellSamples = array();
            foreach ($d->samples as $s) {
                if (empty($s->strabo_id)) continue;
                // The overlay reads `$s->id` to look up by strabosamples.id.
                // micro_samplemetadata's `strabo_id` IS the strabosamples id.
                $shellSamples[] = (object)array(
                    'id'                  => (string)$s->strabo_id,
                    'sampleID'            => $s->sampleid,
                    'sampleDescription'   => $s->sampledescription,
                    'sampleNotes'         => $s->samplenotes,
                    'latitude'            => $s->latitude,
                    'longitude'           => $s->longitude,
                    'materialType'        => $s->materialtype,
                    'mainSamplingPurpose' => $s->mainsamplingpurpose,
                    '_src_sample'         => $s,   // private — back-pointer for post-apply read-back
                );
            }
            if (!empty($shellSamples)) {
                $fakeJson->datasets[] = (object)array('samples' => $shellSamples);
            }
        }
        micro_sample_overlay_apply($fakeJson, $this->db, $this->ownerPkey);
        // Push the overlaid values back onto our raw $s rows so the
        // section renderers below read fresh data.
        foreach ($fakeJson->datasets as $fakeD) {
            foreach ($fakeD->samples as $fakeS) {
                if (!isset($fakeS->_src_sample)) continue;
                $src = $fakeS->_src_sample;
                if (isset($fakeS->sampleID))            $src->sampleid            = $fakeS->sampleID;
                if (isset($fakeS->sampleDescription))   $src->sampledescription   = $fakeS->sampleDescription;
                if (isset($fakeS->sampleNotes))         $src->samplenotes         = $fakeS->sampleNotes;
                if (isset($fakeS->latitude))            $src->latitude            = $fakeS->latitude;
                if (isset($fakeS->longitude))           $src->longitude           = $fakeS->longitude;
                if (isset($fakeS->materialType))        $src->materialtype        = $fakeS->materialType;
                if (isset($fakeS->mainSamplingPurpose)) $src->mainsamplingpurpose = $fakeS->mainSamplingPurpose;
            }
        }
    }

    /**
     * Load instrument + orientation per micrograph, plus the feature-info
     * children (mineralogy + grain info + fabric info) that addFeatureInfoSections
     * renders. Stays inside Phase 2's scope — the other feature-info types
     * (fracture/fold/vein/etc.) are not surfaced today.
     */
    protected function loadMicrographSubresources($m)
    {
        $instr = $this->db->get_row_prepared(
            "SELECT * FROM micro_instrument WHERE micrograph_id = $1 ORDER BY id LIMIT 1",
            array($m->id)
        );
        if ($instr) {
            $detRows = $this->db->get_results_prepared(
                "SELECT detectortype, detectormake, detectormodel
                   FROM micro_instrumentdetector WHERE instrument_id = $1 ORDER BY id",
                array($instr->id)
            );
            $instr->detectors = is_array($detRows) ? $detRows : array();
        }
        $m->instrument = $instr ?: null;

        $orient = $this->db->get_row_prepared(
            "SELECT * FROM micro_micrographorientation
              WHERE micrographmetadata_id = $1 ORDER BY id LIMIT 1",
            array($m->id)
        );
        $m->orientation = $orient ?: null;

        $this->loadFeatureInfo($m, 'micrograph_id');
    }

    /**
     * Load mineralogy + grain info + fabric info onto $entity. $fkColumn is
     * either 'micrograph_id' or 'spot_id' — the three feature-info parent tables
     * are polymorphic over micrograph/spot.
     */
    protected function loadFeatureInfo($entity, $fkColumn)
    {
        $miner = $this->db->get_row_prepared(
            "SELECT id, mineralogymethod, notes
               FROM micro_mineralogy WHERE $fkColumn = $1 ORDER BY id LIMIT 1",
            array($entity->id)
        );
        if ($miner) {
            $mRows = $this->db->get_results_prepared(
                "SELECT name, operator, percentage
                   FROM micro_mineral WHERE mineralogy_id = $1 ORDER BY id",
                array($miner->id)
            );
            $miner->minerals = is_array($mRows) ? $mRows : array();
        }
        $entity->mineralogy = $miner ?: null;

        $grain = $this->db->get_row_prepared(
            "SELECT id, grainsizenotes, grainshapenotes, grainorientationnotes
               FROM micro_graininfo WHERE $fkColumn = $1 ORDER BY id LIMIT 1",
            array($entity->id)
        );
        if ($grain) {
            $grain->grainsize = $this->db->get_results_prepared(
                "SELECT phases, mean, median, mode, standarddeviation, sizeunit
                   FROM micro_grainsize WHERE graininfo_id = $1 ORDER BY id",
                array($grain->id)
            ) ?: array();
            $grain->grainshape = $this->db->get_results_prepared(
                "SELECT phases, shape
                   FROM micro_grainshape WHERE graininfo_id = $1 ORDER BY id",
                array($grain->id)
            ) ?: array();
            $grain->grainorientation = $this->db->get_results_prepared(
                "SELECT phases, meanorientation, relativeto, software
                   FROM micro_grainorientation WHERE graininfo_id = $1 ORDER BY id",
                array($grain->id)
            ) ?: array();
        }
        $entity->graininfo = $grain ?: null;

        $fab = $this->db->get_row_prepared(
            "SELECT id, notes FROM micro_fabricinfo WHERE $fkColumn = $1 ORDER BY id LIMIT 1",
            array($entity->id)
        );
        if ($fab) {
            $fab->fabrics = $this->db->get_results_prepared(
                "SELECT fabriclabel, fabricelement, fabriccategory,
                        fabricspacing, fabricdefinedby
                   FROM micro_fabric WHERE fabric_info_id = $1 ORDER BY id",
                array($fab->id)
            ) ?: array();
        }
        $entity->fabricinfo = $fab ?: null;
    }

    protected function collectFlatLists()
    {
        foreach ($this->datasets as $d) {
            foreach ($d->samples as $s) {
                $this->allSamples[] = array('sample' => $s, 'dataset' => $d);
                foreach ($s->micrographs as $m) {
                    $this->allMicrographs[] = array(
                        'micrograph' => $m, 'sample' => $s, 'dataset' => $d
                    );
                    foreach ($m->spots as $spot) {
                        $this->allSpots[] = array(
                            'spot' => $spot, 'micrograph' => $m,
                            'sample' => $s, 'dataset' => $d
                        );
                    }
                }
            }
        }
    }

    // -----------------------------------------------------------------
    // TOC link bookkeeping
    // -----------------------------------------------------------------

    /** @var array title → tFPDF link id (created up front, bound when the page is added) */
    protected $tocLinkByTitle = array();

    protected function preallocateTocLinks()
    {
        $this->tocEntries = array();
        $this->_pushToc('Project Details', 0);
        foreach ($this->datasets as $d) {
            $this->_pushToc('Dataset: ' . ($d->name ?: 'Unnamed'), 0);
        }
        foreach ($this->allSamples as $row) {
            $this->_pushToc('Sample: ' . $this->sampleDisplayName($row['sample']), 1);
        }
        foreach ($this->allMicrographs as $row) {
            $this->_pushToc('Micrograph: ' . ($row['micrograph']->name ?: 'Unnamed'), 1);
        }
        foreach ($this->allSpots as $row) {
            $this->_pushToc('Spot: ' . ($row['spot']->name ?: 'Unnamed'), 2);
        }
    }

    protected function _pushToc($title, $level)
    {
        $link = $this->AddLink();
        $this->tocEntries[] = array('title' => $title, 'link' => $link, 'level' => $level);
        $this->tocLinkByTitle[$title] = $link;
    }

    /** Bind a TOC link to the current page so the TOC entry jumps here. */
    protected function bindTocLink($title)
    {
        if (isset($this->tocLinkByTitle[$title])) {
            $this->SetLink($this->tocLinkByTitle[$title]);
        }
    }

    // -----------------------------------------------------------------
    // Layout primitives — mirror pdfProjectExport.js helpers
    // -----------------------------------------------------------------

    protected function setColor($rgb, $what = 'text')
    {
        list($r, $g, $b) = $rgb;
        if      ($what === 'text') $this->SetTextColor($r, $g, $b);
        elseif  ($what === 'draw') $this->SetDrawColor($r, $g, $b);
        elseif  ($what === 'fill') $this->SetFillColor($r, $g, $b);
    }

    protected function addSectionHeader($text)
    {
        $this->renderedSectionHeaders[] = $text;
        $this->SetFont('DejaVu', 'B', self::FS_HEADING1);
        $this->setColor(self::C_PRIMARY, 'text');
        $this->MultiCell(0, 9, $text, 0, 'L');
        $yAfter = $this->GetY() + 1;
        $this->setColor(self::C_ACCENT, 'draw');
        $this->SetLineWidth(0.6);
        $this->Line(self::MARGIN_MM, $yAfter, self::MARGIN_MM + 40, $yAfter);
        $this->Ln(4);
    }

    protected function addSubsectionHeader($text)
    {
        $this->renderedSectionHeaders[] = $text;
        $this->SetFont('DejaVu', 'B', self::FS_HEADING2);
        $this->setColor(self::C_PRIMARY, 'text');
        $this->MultiCell(0, 7, $text, 0, 'L');
        $this->Ln(1);
    }

    protected function addBreadcrumb(array $path)
    {
        $this->SetFont('DejaVu', '', self::FS_SMALL);
        $this->setColor(self::C_SECONDARY, 'text');
        $this->MultiCell(0, 5, implode(' > ', $path), 0, 'L');
        $this->Ln(1);
    }

    /** Visual indent — tFPDF doesn't have a real "card" so we shift x. */
    protected function startCard()
    {
        $this->_cardStartX = $this->GetX();
        $this->SetX(self::MARGIN_MM + 3);
    }

    protected function endCard()
    {
        $this->Ln(2);
        $this->SetX(self::MARGIN_MM);
    }

    /**
     * Render a list of {key, label, format?} field descriptors against
     * $obj. Skip when the value is empty (mirrors hasValue() in
     * pdfProjectExport.js).
     */
    protected function addFieldList($obj, array $fields)
    {
        foreach ($fields as $f) {
            $key  = $f['key'];
            $val  = is_object($obj) && isset($obj->$key) ? $obj->$key
                  : (is_array($obj) && isset($obj[$key])   ? $obj[$key] : null);
            if (!$this->hasValue($val)) continue;
            if (isset($f['format']) && is_callable($f['format'])) {
                $val = call_user_func($f['format'], $val);
                if (!$this->hasValue($val)) continue;
            }

            $labelW = 50;
            $startX = $this->GetX();
            $startY = $this->GetY();
            $this->SetFont('DejaVu', 'B', self::FS_BODY);
            $this->setColor(self::C_PRIMARY, 'text');
            $this->MultiCell($labelW, 5, $f['label'] . ':', 0, 'L');
            // Move cursor to the right of the label column to render the
            // value. MultiCell drops the cursor below the label, so reset
            // Y to startY and place X just after the label.
            $this->SetXY($startX + $labelW, $startY);

            $this->SetFont('DejaVu', '', self::FS_BODY);
            $this->setColor(self::C_TEXT, 'text');
            $availW = self::PAGE_WIDTH_MM - $this->GetX() - self::MARGIN_MM;
            $this->MultiCell($availW, 5, (string)$val, 0, 'L');

            $this->SetX(self::MARGIN_MM + 3);  // back to card-indent column
        }
    }

    protected function checkPageBreak($neededMm)
    {
        if ($this->GetY() + $neededMm > self::PAGE_HEIGHT_MM - self::MARGIN_MM) {
            $this->AddPage();
        }
    }

    protected function hasValue($v)
    {
        if ($v === null) return false;
        if (is_string($v) && trim($v) === '') return false;
        if (is_array($v) && empty($v)) return false;
        return true;
    }

    protected function sampleDisplayName($sample)
    {
        if (!empty($sample->sampleid)) return (string)$sample->sampleid;
        if (!empty($sample->label))    return (string)$sample->label;
        return 'Unnamed';
    }

    // -----------------------------------------------------------------
    // Section renderers
    // -----------------------------------------------------------------

    protected function generateCoverPageContent()
    {
        $name = $this->project->name ?: 'Untitled Project';

        // Title — centered, near top third of page.
        $this->SetY(60);
        $this->SetFont('DejaVu', 'B', self::FS_TITLE + 8);
        $this->setColor(self::C_PRIMARY, 'text');
        $this->MultiCell(0, 14, $name, 0, 'C');

        $this->Ln(4);
        $this->SetFont('DejaVu', '', self::FS_HEADING1);
        $this->setColor(self::C_SECONDARY, 'text');
        $this->MultiCell(0, 9, 'StraboMicro Project Report', 0, 'C');

        // Horizontal rule.
        $this->Ln(8);
        $y = $this->GetY();
        $this->setColor(self::C_BORDER, 'draw');
        $this->SetLineWidth(0.3);
        $this->Line(self::MARGIN_MM + 40, $y, self::PAGE_WIDTH_MM - self::MARGIN_MM - 40, $y);
        $this->Ln(10);

        // Summary stats.
        $stats = sprintf(
            '%d Dataset%s  |  %d Sample%s  |  %d Micrograph%s  |  %d Spot%s',
            count($this->datasets),       count($this->datasets) === 1 ? '' : 's',
            count($this->allSamples),     count($this->allSamples) === 1 ? '' : 's',
            count($this->allMicrographs), count($this->allMicrographs) === 1 ? '' : 's',
            count($this->allSpots),       count($this->allSpots) === 1 ? '' : 's'
        );
        $this->SetFont('DejaVu', '', self::FS_BODY);
        $this->setColor(self::C_TEXT, 'text');
        $this->MultiCell(0, 6, $stats, 0, 'C');

        // Generation date at the bottom.
        $this->SetY(self::PAGE_HEIGHT_MM - 35);
        $this->SetFont('DejaVu', '', self::FS_SMALL);
        $this->setColor(self::C_LIGHT_TEXT, 'text');
        $this->MultiCell(
            0, 5,
            'Generated on ' . date('F j, Y'),
            0, 'C'
        );
    }

    protected function generateTableOfContents()
    {
        $this->SetFont('DejaVu', 'B', self::FS_HEADING1);
        $this->setColor(self::C_PRIMARY, 'text');
        $this->MultiCell(0, 9, 'Table of Contents', 0, 'L');
        $this->Ln(4);

        // Use tFPDF's AutoPageBreak for spillover. Each entry is one
        // Cell line with a Link rectangle.
        $lineH = 6;
        $rightColW = 12;  // (reserved if we add page numbers later)
        $contentW = self::PAGE_WIDTH_MM - 2 * self::MARGIN_MM - $rightColW;

        foreach ($this->tocEntries as $entry) {
            $indent  = $entry['level'] * 5;
            $isTop   = $entry['level'] === 0;
            $this->SetFont('DejaVu', $isTop ? 'B' : '', $isTop ? self::FS_BODY : self::FS_SMALL);
            $this->setColor(self::C_ACCENT, 'text');

            $title = $entry['title'];
            $this->SetX(self::MARGIN_MM + $indent);
            // tFPDF's Cell() accepts a Link target as the 8th arg.
            $this->Cell(
                $contentW - $indent,
                $lineH,
                $title,
                0,
                1,    // ln = 1 → cursor to next line
                'L',
                false,
                $entry['link']
            );
        }
    }

    protected function generateProjectDetailsContent()
    {
        $this->addSectionHeader('Project Details');
        $this->startCard();
        $fields = array(
            array('key' => 'name',                'label' => 'Project Name'),
            array('key' => 'startdate',           'label' => 'Start Date'),
            array('key' => 'enddate',             'label' => 'End Date'),
            array('key' => 'purposeofstudy',      'label' => 'Purpose of Study'),
            array('key' => 'areaofinterest',      'label' => 'Area of Interest'),
            array('key' => 'projectlocation',     'label' => 'Project Location'),
            array('key' => 'instrumentsused',     'label' => 'Instruments Used'),
            array('key' => 'otherteammembers',    'label' => 'Team Members'),
            array('key' => 'gpsdatum',            'label' => 'GPS Datum'),
            array('key' => 'magneticdeclination', 'label' => 'Magnetic Declination'),
            array('key' => 'date',                'label' => 'Date Created'),
            array('key' => 'modifiedtimestamp',   'label' => 'Last Modified',
                  'format' => array($this, 'formatModifiedTimestamp')),
            array('key' => 'notes',               'label' => 'Notes'),
        );
        $this->addFieldList($this->project, $fields);
        $this->endCard();
    }

    protected function generateDatasetSectionContent($dataset)
    {
        $name = $dataset->name ?: 'Unnamed';
        $this->addSectionHeader('Dataset: ' . $name);
        $this->startCard();
        $fields = array(
            array('key' => 'name',      'label' => 'Name'),
            array('key' => 'strabo_id', 'label' => 'Strabo ID'),
        );
        $this->addFieldList($dataset, $fields);

        $sampleCount = is_array($dataset->samples) ? count($dataset->samples) : 0;
        $this->SetFont('DejaVu', '', self::FS_BODY);
        $this->setColor(self::C_TEXT, 'text');
        $this->MultiCell(0, 5,
            sprintf('Contains %d sample%s', $sampleCount, $sampleCount === 1 ? '' : 's'),
            0, 'L');
        $this->endCard();
    }

    protected function generateSampleSectionContent($sample, $dataset)
    {
        $name = $this->sampleDisplayName($sample);
        $this->addBreadcrumb(array($dataset->name ?: 'Dataset'));
        $this->addSectionHeader('Sample: ' . $name);
        $this->startCard();

        $fields = array(
            array('key' => 'label',                  'label' => 'Label'),
            array('key' => 'sampleid',               'label' => 'Sample ID'),
            array('key' => 'latitude',               'label' => 'Latitude'),
            array('key' => 'longitude',              'label' => 'Longitude'),
            array('key' => 'mainsamplingpurpose',    'label' => 'Sampling Purpose'),
            array('key' => 'sampledescription',      'label' => 'Description'),
            array('key' => 'materialtype',           'label' => 'Material Type'),
            array('key' => 'sampletype',             'label' => 'Sample Type'),
            array('key' => 'lithology',              'label' => 'Lithology'),
            array('key' => 'color',                  'label' => 'Color'),
            array('key' => 'samplesize',             'label' => 'Sample Size'),
            array('key' => 'degreeofweathering',     'label' => 'Degree of Weathering'),
            array('key' => 'inplacenessofsample',    'label' => 'Inplaceness'),
            array('key' => 'orientedsample',         'label' => 'Oriented Sample'),
            array('key' => 'sampleorientationnotes', 'label' => 'Orientation Notes'),
            array('key' => 'sampleunit',             'label' => 'Sample Unit'),
            array('key' => 'othermaterialtype',      'label' => 'Other Material Type'),
            array('key' => 'othersamplingpurpose',   'label' => 'Other Sampling Purpose'),
            array('key' => 'samplenotes',            'label' => 'Notes'),
        );
        $this->addFieldList($sample, $fields);

        $micrographCount = is_array($sample->micrographs) ? count($sample->micrographs) : 0;
        $this->SetFont('DejaVu', '', self::FS_BODY);
        $this->setColor(self::C_TEXT, 'text');
        $this->MultiCell(0, 5,
            sprintf('Contains %d micrograph%s', $micrographCount, $micrographCount === 1 ? '' : 's'),
            0, 'L');
        $this->endCard();
    }

    // -----------------------------------------------------------------
    // Per-Micrograph (Phase 2)
    // -----------------------------------------------------------------

    protected function generateMicrographSectionContent($micrograph, $sample, $dataset)
    {
        $name = $micrograph->name ?: 'Unnamed';

        $crumbs = array($dataset->name ?: 'Dataset', $this->sampleDisplayName($sample));
        if (!empty($micrograph->parentid)) {
            foreach ($this->getMicrographParentChain($micrograph->parentid, $sample) as $p) {
                $crumbs[] = $p;
            }
        }
        $this->addBreadcrumb($crumbs);
        $this->addSectionHeader('Micrograph: ' . $name);

        $this->SetFont('DejaVu', 'I', self::FS_SMALL);
        $this->setColor(self::C_SECONDARY, 'text');
        $this->MultiCell(0, 5,
            empty($micrograph->parentid) ? 'Reference Micrograph' : 'Associated Micrograph',
            0, 'L');
        $this->Ln(2);

        $this->embedCompositeImage($micrograph);

        $this->startCard();
        $this->addFieldList($micrograph, array(
            array('key' => 'name',                     'label' => 'Name'),
            array('key' => 'imagetype',                'label' => 'Image Type'),
            array('key' => 'width',                    'label' => 'Width (px)'),
            array('key' => 'height',                   'label' => 'Height (px)'),
            array('key' => 'scalepixelspercentimeter', 'label' => 'Scale (px/cm)'),
            array('key' => 'scale',                    'label' => 'Scale Description'),
            array('key' => 'description',              'label' => 'Description'),
            array('key' => 'notes',                    'label' => 'Notes'),
            array('key' => 'polish',                   'label' => 'Polish',
                  'format' => array($this, 'formatYesNo')),
            array('key' => 'polishdescription',        'label' => 'Polish Description'),
        ));
        $this->endCard();

        if (!empty($micrograph->instrument)) {
            $this->checkPageBreak(150);
            $this->addSubsectionHeader('Instrument Information');
            $this->startCard();
            $this->addFieldList($micrograph->instrument, array(
                array('key' => 'instrumenttype',                'label' => 'Instrument Type'),
                array('key' => 'datatype',                      'label' => 'Data Type'),
                array('key' => 'instrumentbrand',               'label' => 'Brand'),
                array('key' => 'instrumentmodel',               'label' => 'Model'),
                array('key' => 'university',                    'label' => 'University'),
                array('key' => 'laboratory',                    'label' => 'Laboratory'),
                array('key' => 'datacollectionsoftware',        'label' => 'Collection Software'),
                array('key' => 'datacollectionsoftwareversion', 'label' => 'Software Version'),
                array('key' => 'postprocessingsoftware',        'label' => 'Post Processing Software'),
                array('key' => 'postprocessingsoftwareversion', 'label' => 'Post Processing Version'),
                array('key' => 'filamenttype',                  'label' => 'Filament Type'),
                array('key' => 'accelerationvoltage',           'label' => 'Acceleration Voltage'),
                array('key' => 'beamcurrent',                   'label' => 'Beam Current'),
                array('key' => 'spotsize',                      'label' => 'Spot Size'),
                array('key' => 'workingdistance',               'label' => 'Working Distance'),
                array('key' => 'instrumentnotes',               'label' => 'Notes'),
            ));

            if (!empty($micrograph->instrument->detectors)) {
                $this->SetFont('DejaVu', 'B', self::FS_BODY);
                $this->setColor(self::C_PRIMARY, 'text');
                $this->MultiCell(0, 5, 'Detectors:', 0, 'L');
                $this->SetFont('DejaVu', '', self::FS_SMALL);
                $this->setColor(self::C_TEXT, 'text');
                foreach ($micrograph->instrument->detectors as $det) {
                    $type = $this->hasValue($det->detectortype) ? $det->detectortype : 'Unknown';
                    $make = $this->hasValue($det->detectormake) ? " ($det->detectormake)" : '';
                    $this->MultiCell(0, 5, "  - $type$make", 0, 'L');
                }
            }
            $this->endCard();
        }

        if (!empty($micrograph->orientation)) {
            $this->checkPageBreak(100);
            $this->addSubsectionHeader('Orientation Information');
            $this->startCard();
            $this->addFieldList($micrograph->orientation, array(
                array('key' => 'orientationmethod',  'label' => 'Method'),
                array('key' => 'toptrend',           'label' => 'Top Trend'),
                array('key' => 'topplunge',          'label' => 'Top Plunge'),
                array('key' => 'topreferencecorner', 'label' => 'Top Reference Corner'),
                array('key' => 'sidetrend',          'label' => 'Side Trend'),
                array('key' => 'sideplunge',         'label' => 'Side Plunge'),
                array('key' => 'sidereferencecorner','label' => 'Side Reference Corner'),
                array('key' => 'fabricreference',    'label' => 'Fabric Reference'),
                array('key' => 'fabricstrike',       'label' => 'Fabric Strike'),
                array('key' => 'fabricdip',          'label' => 'Fabric Dip'),
                array('key' => 'lookdirection',      'label' => 'Look Direction'),
                array('key' => 'topcorner',          'label' => 'Top Corner'),
            ));
            $this->endCard();
        }

        $this->addFeatureInfoSections($micrograph);

        if (!empty($micrograph->spots)) {
            $this->checkPageBreak(80);
            $this->addSubsectionHeader('Spots');
            $n = count($micrograph->spots);
            $this->SetFont('DejaVu', '', self::FS_BODY);
            $this->setColor(self::C_TEXT, 'text');
            $this->MultiCell(0, 5,
                sprintf('This micrograph contains %d spot%s:', $n, $n === 1 ? '' : 's'),
                0, 'L');
            $this->SetFont('DejaVu', '', self::FS_SMALL);
            $this->setColor(self::C_ACCENT, 'text');
            foreach ($micrograph->spots as $spot) {
                $this->MultiCell(0, 5, '  - ' . ($spot->name ?: 'Unnamed Spot'), 0, 'L');
            }
        }
    }

    /**
     * Embed straboMicroFiles/<project_internal_id>/images/<strabo_id>.jpg —
     * the composite written server-side by StraboMicro::createProjectImages
     * at upload time. Scales to fit page width up to ~120mm tall.
     */
    protected function embedCompositeImage($micrograph)
    {
        if (empty($micrograph->strabo_id)) return;
        $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : '/srv/app/www';
        $imgPath = "$docRoot/straboMicroFiles/$this->projectInternalId/images/$micrograph->strabo_id.jpg";
        if (!is_file($imgPath)) return;

        $size = @getimagesize($imgPath);
        if ($size === false) return;
        $imgW = $size[0] ?: 1;
        $imgH = $size[1] ?: 1;

        $maxW = self::PAGE_WIDTH_MM - 2 * self::MARGIN_MM;
        $maxH = 120;
        $dispW = $imgW;
        $dispH = $imgH;
        if ($dispW > $maxW) {
            $scale = $maxW / $dispW;
            $dispW = $maxW;
            $dispH = $imgH * $scale;
        }
        if ($dispH > $maxH) {
            $scale = $maxH / $dispH;
            $dispH = $maxH;
            $dispW = $dispW * $scale;
        }

        if ($this->GetY() + $dispH > self::PAGE_HEIGHT_MM - self::MARGIN_MM - 10) {
            $this->AddPage();
        }
        $y = $this->GetY();
        $this->Image($imgPath, self::MARGIN_MM, $y, $dispW, $dispH, 'JPG');
        $this->SetY($y + $dispH + 4);
    }

    protected function getMicrographParentChain($parentStraboId, $sample)
    {
        $chain = array();
        $current = $parentStraboId;
        $guard = 0;
        while ($current && $guard++ < 100) {
            $found = null;
            foreach ($sample->micrographs as $m) {
                if ((string)$m->strabo_id === (string)$current) { $found = $m; break; }
            }
            if (!$found) break;
            array_unshift($chain, $found->name ?: 'Micrograph');
            $current = $found->parentid;
        }
        return $chain;
    }

    /**
     * Render Mineralogy + Grain Information + Fabric Information for either
     * a micrograph or a spot row. Shared with Phase 3 — the structures hang
     * off both via the polymorphic (micrograph_id, spot_id) parent columns.
     */
    protected function addFeatureInfoSections($entity)
    {
        $miner = isset($entity->mineralogy) ? $entity->mineralogy : null;
        if ($miner && (!empty($miner->minerals) || $this->hasValue($miner->notes))) {
            $this->checkPageBreak(100);
            $this->addSubsectionHeader('Mineralogy');
            $this->startCard();
            if ($this->hasValue($miner->mineralogymethod)) {
                $this->SetFont('DejaVu', '', self::FS_BODY);
                $this->setColor(self::C_TEXT, 'text');
                $this->MultiCell(0, 5, 'Method: ' . $miner->mineralogymethod, 0, 'L');
            }
            if (!empty($miner->minerals)) {
                $this->SetFont('DejaVu', 'B', self::FS_BODY);
                $this->setColor(self::C_PRIMARY, 'text');
                $this->MultiCell(0, 5, 'Minerals:', 0, 'L');
                $this->SetFont('DejaVu', '', self::FS_SMALL);
                $this->setColor(self::C_TEXT, 'text');
                foreach ($miner->minerals as $mineral) {
                    $pct = $this->hasValue($mineral->percentage)
                        ? ' (' . ($this->hasValue($mineral->operator) ? $mineral->operator : '')
                          . $mineral->percentage . '%)'
                        : '';
                    $nm = $this->hasValue($mineral->name) ? $mineral->name : 'Unknown';
                    $this->MultiCell(0, 5, "  - $nm$pct", 0, 'L');
                }
            }
            if ($this->hasValue($miner->notes)) {
                $this->SetFont('DejaVu', 'I', self::FS_SMALL);
                $this->setColor(self::C_LIGHT_TEXT, 'text');
                $this->MultiCell(0, 5, 'Notes: ' . $miner->notes, 0, 'L');
            }
            $this->endCard();
        }

        $grain = isset($entity->graininfo) ? $entity->graininfo : null;
        if ($grain) {
            $hasGrain = !empty($grain->grainsize) || !empty($grain->grainshape) ||
                        !empty($grain->grainorientation) ||
                        $this->hasValue($grain->grainsizenotes) ||
                        $this->hasValue($grain->grainshapenotes) ||
                        $this->hasValue($grain->grainorientationnotes);
            if ($hasGrain) {
                $this->checkPageBreak(100);
                $this->addSubsectionHeader('Grain Information');
                $this->startCard();

                if (!empty($grain->grainsize)) {
                    $this->SetFont('DejaVu', 'B', self::FS_BODY);
                    $this->setColor(self::C_PRIMARY, 'text');
                    $this->MultiCell(0, 5, 'Grain Size:', 0, 'L');
                    $this->SetFont('DejaVu', '', self::FS_SMALL);
                    $this->setColor(self::C_TEXT, 'text');
                    foreach ($grain->grainsize as $size) {
                        $stats = array();
                        if ($this->hasValue($size->mean))              $stats[] = "Mean: $size->mean";
                        if ($this->hasValue($size->median))            $stats[] = "Median: $size->median";
                        if ($this->hasValue($size->mode))              $stats[] = "Mode: $size->mode";
                        if ($this->hasValue($size->standarddeviation)) $stats[] = "StdDev: $size->standarddeviation";
                        $phases = $this->hasValue($size->phases) ? $size->phases : 'All phases';
                        $unit = $this->hasValue($size->sizeunit) ? " $size->sizeunit" : '';
                        $this->MultiCell(0, 5, "  $phases: " . implode(', ', $stats) . $unit, 0, 'L');
                    }
                }
                if (!empty($grain->grainshape)) {
                    $this->SetFont('DejaVu', 'B', self::FS_BODY);
                    $this->setColor(self::C_PRIMARY, 'text');
                    $this->MultiCell(0, 5, 'Grain Shape:', 0, 'L');
                    $this->SetFont('DejaVu', '', self::FS_SMALL);
                    $this->setColor(self::C_TEXT, 'text');
                    foreach ($grain->grainshape as $sh) {
                        $phases = $this->hasValue($sh->phases) ? $sh->phases : 'All phases';
                        $shape = $this->hasValue($sh->shape) ? $sh->shape : 'Not specified';
                        $this->MultiCell(0, 5, "  $phases: $shape", 0, 'L');
                    }
                }
                if ($this->hasValue($grain->grainsizenotes)) {
                    $this->SetFont('DejaVu', 'I', self::FS_SMALL);
                    $this->setColor(self::C_LIGHT_TEXT, 'text');
                    $this->MultiCell(0, 5, 'Size Notes: ' . $grain->grainsizenotes, 0, 'L');
                }
                if ($this->hasValue($grain->grainshapenotes)) {
                    $this->SetFont('DejaVu', 'I', self::FS_SMALL);
                    $this->setColor(self::C_LIGHT_TEXT, 'text');
                    $this->MultiCell(0, 5, 'Shape Notes: ' . $grain->grainshapenotes, 0, 'L');
                }
                $this->endCard();
            }
        }

        $fab = isset($entity->fabricinfo) ? $entity->fabricinfo : null;
        if ($fab && (!empty($fab->fabrics) || $this->hasValue($fab->notes))) {
            $this->checkPageBreak(100);
            $this->addSubsectionHeader('Fabric Information');
            $this->startCard();
            if (!empty($fab->fabrics)) {
                foreach ($fab->fabrics as $fabric) {
                    $this->SetFont('DejaVu', 'B', self::FS_BODY);
                    $this->setColor(self::C_PRIMARY, 'text');
                    $this->MultiCell(0, 5, $this->hasValue($fabric->fabriclabel) ? $fabric->fabriclabel : 'Fabric', 0, 'L');
                    $this->SetFont('DejaVu', '', self::FS_SMALL);
                    $this->setColor(self::C_TEXT, 'text');
                    if ($this->hasValue($fabric->fabricelement))  $this->MultiCell(0, 5, "  Element: $fabric->fabricelement",  0, 'L');
                    if ($this->hasValue($fabric->fabriccategory)) $this->MultiCell(0, 5, "  Category: $fabric->fabriccategory", 0, 'L');
                    if ($this->hasValue($fabric->fabricspacing))  $this->MultiCell(0, 5, "  Spacing: $fabric->fabricspacing",   0, 'L');
                    if ($this->hasValue($fabric->fabricdefinedby))$this->MultiCell(0, 5, "  Defined By: $fabric->fabricdefinedby", 0, 'L');
                }
            }
            if ($this->hasValue($fab->notes)) {
                $this->SetFont('DejaVu', 'I', self::FS_SMALL);
                $this->setColor(self::C_LIGHT_TEXT, 'text');
                $this->MultiCell(0, 5, 'Notes: ' . $fab->notes, 0, 'L');
            }
            $this->endCard();
        }
    }

    public function formatYesNo($v)
    {
        if ($v === null || $v === '') return null;
        if ($v === true  || $v === 't' || $v === 1 || $v === '1' || $v === 'true')  return 'Yes';
        if ($v === false || $v === 'f' || $v === 0 || $v === '0' || $v === 'false') return 'No';
        return (string)$v;
    }

    // -----------------------------------------------------------------
    // Phase 1 STUBS — Phase 3 will replace.
    // -----------------------------------------------------------------

    protected function generateSpotSectionContent_phase1Stub($spot, $micrograph, $sample, $dataset)
    {
        $name = $spot->name ?: 'Unnamed';
        $this->addBreadcrumb(array(
            $dataset->name ?: 'Dataset',
            $this->sampleDisplayName($sample),
            $micrograph->name ?: 'Micrograph',
        ));
        $this->addSectionHeader('Spot: ' . $name);
        $this->SetFont('DejaVu', 'I', self::FS_SMALL);
        $this->setColor(self::C_LIGHT_TEXT, 'text');
        $this->MultiCell(0, 5,
            'Spot detail layout pending Phase 3 of samples/micro-server-pdf-*.',
            0, 'L');
    }

    // -----------------------------------------------------------------
    // Misc utilities
    // -----------------------------------------------------------------

    public function formatModifiedTimestamp($v)
    {
        if ($v === null || $v === '') return null;
        // Accept either ms-epoch (Field/Micro convention) or ISO 8601
        // (some prod Micro projects have this — see
        // project_micro_modifiedtimestamp_formats).
        if (is_numeric($v)) {
            $ms = (float)$v;
            if ($ms <= 0) return null;
            return date('F j, Y g:i A', (int)($ms / 1000));
        }
        $ts = strtotime((string)$v);
        if ($ts === false) return null;
        return date('F j, Y g:i A', $ts);
    }
}
