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
 *              Phase 1 (THIS branch — samples/micro-server-pdf):
 *                  Layout primitives + the cover / TOC / Project Details /
 *                  per-Dataset / per-Sample sections fully ported. Per-
 *                  Micrograph and per-Spot rendered as placeholder pages
 *                  ("Full layout pending Phase 2/3 of samples/micro-
 *                  server-pdf-*").
 *              Phase 2 (samples/micro-server-pdf-micrographs):
 *                  Per-Micrograph section with composite image embed from
 *                  straboMicroFiles/<pkey>/images/{uuid}.jpg + instrument /
 *                  orientation / feature-info sub-sections.
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

        // Per-Micrograph and per-Spot are stubbed in Phase 1 — Phase 2/3
        // will replace these implementations.
        foreach ($this->allMicrographs as $row) {
            $micrograph = $row['micrograph'];
            $sample     = $row['sample'];
            $dataset    = $row['dataset'];
            $this->AddPage();
            $this->bindTocLink('Micrograph: ' . ($micrograph->name ?: 'Unnamed'));
            $this->generateMicrographSectionContent_phase1Stub($micrograph, $sample, $dataset);
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
                            scale, description, notes
                       FROM micro_micrographmetadata WHERE sample_id = $1 ORDER BY id",
                    array($s->id)
                );
                $s->micrographs = is_array($mRows) ? $mRows : array();
                // Phase 1: spots are not loaded — they're rendered as the
                // stub. Phase 3 will add the spot query.
                foreach ($s->micrographs as $m) $m->spots = array();
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
    // Phase 1 STUBS — replaced by Phase 2 / 3 follow-on branches.
    // -----------------------------------------------------------------

    protected function generateMicrographSectionContent_phase1Stub($micrograph, $sample, $dataset)
    {
        $name = $micrograph->name ?: 'Unnamed';
        $this->addBreadcrumb(array(
            $dataset->name ?: 'Dataset',
            $this->sampleDisplayName($sample),
        ));
        $this->addSectionHeader('Micrograph: ' . $name);
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
        ));
        $this->endCard();
        $this->Ln(2);
        $this->SetFont('DejaVu', 'I', self::FS_SMALL);
        $this->setColor(self::C_LIGHT_TEXT, 'text');
        $this->MultiCell(0, 5,
            'Composite image, instrument metadata, orientation, and feature-info sections pending Phase 2 of samples/micro-server-pdf-*.',
            0, 'L');
    }

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
