<?php
/**
 * File: ExperimentPDF.php
 * Description: PDF generator class for StraboExperimental experiments
 *
 * @package    StraboExperimental
 * @author     StraboSpot Team
 * @copyright  2025 StraboSpot
 * @license    MIT License
 */

require_once(__DIR__ . '/tfpdf/tfpdf.php');

class ExperimentPDF extends tFPDF
{
    protected $experimentData;
    protected $experimentId;
    protected $projectName;
    protected $logoPath;
    protected $expimagesPath;

    // Colors (RGB)
    protected $headerBg = [220, 53, 69];      // StraboSpot red
    protected $sectionBg = [45, 55, 72];      // Dark blue-gray
    protected $lightBg = [248, 249, 250];     // Light gray
    protected $textDark = [33, 37, 41];       // Near black
    protected $textMuted = [108, 117, 125];   // Gray

    public function __construct($orientation = 'P', $unit = 'mm', $size = 'A4')
    {
        parent::__construct($orientation, $unit, $size);
        $this->SetAutoPageBreak(true, 15);
        $this->logoPath = __DIR__ . '/../public/strabospot-logo.png';
        $this->expimagesPath = __DIR__ . '/../expimages/';

        // Add Unicode fonts (the 4th param = true enables Unicode mode)
        $this->AddFont('DejaVu', '', 'DejaVuSans.ttf', true);
        $this->AddFont('DejaVu', 'B', 'DejaVuSans-Bold.ttf', true);

        // Set default font
        $this->SetFont('DejaVu', '', 10);
    }

    public function setExperimentData($data, $experimentId = '', $projectName = '')
    {
        $this->experimentData = $data;
        $this->experimentId = $experimentId;
        $this->projectName = $projectName;
    }

    // Page header
    public function Header()
    {
        // Logo placeholder - using text for now
        $this->SetFont('DejaVu', 'B', 14);
        $this->SetTextColor($this->headerBg[0], $this->headerBg[1], $this->headerBg[2]);
        $this->Cell(0, 8, 'STRABOEXPERIMENTAL', 0, 1, 'L');

        // Red line
        $this->SetDrawColor($this->headerBg[0], $this->headerBg[1], $this->headerBg[2]);
        $this->SetLineWidth(0.5);
        $this->Line(10, 18, 200, 18);

        $this->Ln(5);
    }

    // Page footer
    public function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('DejaVu', '', 8);
        $this->SetTextColor($this->textMuted[0], $this->textMuted[1], $this->textMuted[2]);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }

    /**
     * Generate the complete PDF document
     */
    public function generate()
    {
        $this->AliasNbPages();
        $this->AddPage();

        // Title page content
        $this->generateTitleSection();

        // Sample section
        if (!empty($this->experimentData->sample)) {
            $this->generateSampleSection($this->experimentData->sample);
        }

        // Facility & Apparatus section
        if (!empty($this->experimentData->facility) || !empty($this->experimentData->apparatus)) {
            $this->generateFacilityApparatusSection(
                $this->experimentData->facility ?? null,
                $this->experimentData->apparatus ?? null
            );
        }

        // Experimental Setup section
        if (!empty($this->experimentData->experiment)) {
            $this->generateExperimentalSetupSection($this->experimentData->experiment);
        }

        // DAQ section
        if (!empty($this->experimentData->daq)) {
            $this->generateDAQSection($this->experimentData->daq);
        }

        // Protocol section (within experiment data)
        if (!empty($this->experimentData->experiment->protocol)) {
            $this->generateProtocolSection($this->experimentData->experiment->protocol);
        }

        // Data section
        if (!empty($this->experimentData->data)) {
            $this->generateDataSection($this->experimentData->data);
        }
    }

    /**
     * Generate title section
     */
    protected function generateTitleSection()
    {
        $this->SetFont('DejaVu', 'B', 20);
        $this->SetTextColor($this->textDark[0], $this->textDark[1], $this->textDark[2]);

        // Experiment title
        $title = $this->experimentData->experiment->title ?? $this->experimentId ?? 'Experiment Report';
        $this->MultiCell(0, 10, $title, 0, 'C');
        $this->Ln(3);

        // Project name
        if (!empty($this->projectName)) {
            $this->SetFont('DejaVu', '', 12);
            $this->SetTextColor($this->textMuted[0], $this->textMuted[1], $this->textMuted[2]);
            $this->Cell(0, 6, 'Project: ' . $this->projectName, 0, 1, 'C');
        }

        // Experiment ID
        if (!empty($this->experimentData->experiment->id)) {
            $this->SetFont('DejaVu', '', 10);
            $this->Cell(0, 6, 'ID: ' . $this->experimentData->experiment->id, 0, 1, 'C');
        }

        // Date range
        $startDate = $this->experimentData->experiment->start_date ?? '';
        $endDate = $this->experimentData->experiment->end_date ?? '';
        if ($startDate || $endDate) {
            $dateStr = $startDate;
            if ($endDate && $endDate !== $startDate) {
                $dateStr .= ' to ' . $endDate;
            }
            $this->Cell(0, 6, $dateStr, 0, 1, 'C');
        }

        $this->Ln(5);

        // Description
        if (!empty($this->experimentData->experiment->description)) {
            $this->SetFont('DejaVu', '', 10);
            $this->SetTextColor($this->textDark[0], $this->textDark[1], $this->textDark[2]);
            $this->MultiCell(0, 5, $this->experimentData->experiment->description, 0, 'L');
            $this->Ln(3);
        }

        // Test Features
        if (!empty($this->experimentData->experiment->features)) {
            $this->sectionSubheader('Test Features');
            $features = implode(', ', $this->experimentData->experiment->features);
            $this->SetFont('DejaVu', '', 9);
            $this->MultiCell(0, 5, $features, 0, 'L');
            $this->Ln(3);
        }

        // Author
        if (!empty($this->experimentData->experiment->author)) {
            $author = $this->experimentData->experiment->author;
            $this->sectionSubheader('Author');
            $authorStr = trim(($author->firstname ?? '') . ' ' . ($author->lastname ?? ''));
            if (!empty($author->affiliation)) {
                $authorStr .= ' (' . $author->affiliation . ')';
            }
            $this->SetFont('DejaVu', '', 9);
            $this->Cell(0, 5, $authorStr, 0, 1);
            if (!empty($author->email)) {
                $this->Cell(0, 5, 'Email: ' . $author->email, 0, 1);
            }
            $this->Ln(3);
        }

        $this->Ln(5);
    }

    /**
     * Generate section header
     */
    protected function sectionHeader($title)
    {
        $this->Ln(3);
        $this->SetFillColor($this->headerBg[0], $this->headerBg[1], $this->headerBg[2]);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('DejaVu', 'B', 12);
        $this->Cell(0, 8, '  ' . strtoupper($title), 0, 1, 'L', true);
        $this->SetTextColor($this->textDark[0], $this->textDark[1], $this->textDark[2]);
        $this->Ln(2);
    }

    /**
     * Generate section subheader
     */
    protected function sectionSubheader($title)
    {
        $this->SetFont('DejaVu', 'B', 10);
        $this->SetTextColor($this->textDark[0], $this->textDark[1], $this->textDark[2]);
        $this->Cell(0, 6, $title, 'B', 1, 'L');
        $this->Ln(1);
    }

    /**
     * Generate a labeled field
     */
    protected function labeledField($label, $value, $labelWidth = 45)
    {
        if (empty($value) && $value !== '0') return;

        $this->SetFont('DejaVu', 'B', 9);
        $this->SetTextColor($this->textMuted[0], $this->textMuted[1], $this->textMuted[2]);
        $this->Cell($labelWidth, 5, $label . ':', 0, 0);

        $this->SetFont('DejaVu', '', 9);
        $this->SetTextColor($this->textDark[0], $this->textDark[1], $this->textDark[2]);
        $this->MultiCell(0, 5, $value, 0, 'L');
    }

    /**
     * Generate Sample section
     */
    protected function generateSampleSection($sample)
    {
        $this->sectionHeader('Sample');

        // Basic info
        $this->labeledField('Name', $sample->name ?? '');
        $this->labeledField('ID', $sample->id ?? '');
        $this->labeledField('IGSN', $sample->igsn ?? '');
        $this->labeledField('Description', $sample->description ?? '');

        // Parent sample
        if (!empty($sample->parent)) {
            $this->Ln(2);
            $this->sectionSubheader('Parent Sample');
            $this->labeledField('Name', $sample->parent->name ?? '');
            $this->labeledField('ID', $sample->parent->id ?? '');
            $this->labeledField('IGSN', $sample->parent->igsn ?? '');
            $this->labeledField('Description', $sample->parent->description ?? '');
        }

        // Material
        if (!empty($sample->material)) {
            $mat = $sample->material;

            if (!empty($mat->material)) {
                $this->Ln(2);
                $this->sectionSubheader('Material');
                $this->labeledField('Type', $mat->material->type ?? '');
                $this->labeledField('Name', $mat->material->name ?? '');
                $this->labeledField('State', $mat->material->state ?? '');
                $this->labeledField('Note', $mat->material->note ?? '');
            }

            // Mineralogy
            if (!empty($mat->composition) && is_array($mat->composition)) {
                $this->Ln(2);
                $this->sectionSubheader('Mineralogy');
                $this->generateMineralogyTable($mat->composition);
            }

            // Provenance
            if (!empty($mat->provenance)) {
                $this->Ln(2);
                $this->sectionSubheader('Provenance');
                $prov = $mat->provenance;
                $this->labeledField('Formation', $prov->formation ?? '');
                $this->labeledField('Member', $prov->member ?? '');
                $this->labeledField('Source', $prov->source ?? '');

                if (!empty($prov->location)) {
                    $loc = $prov->location;
                    $locStr = implode(', ', array_filter([
                        $loc->city ?? '',
                        $loc->state ?? '',
                        $loc->country ?? ''
                    ]));
                    if ($locStr) {
                        $this->labeledField('Location', $locStr);
                    }
                }
            }

            // Texture
            if (!empty($mat->texture)) {
                $this->Ln(2);
                $this->sectionSubheader('Texture');
                $tex = $mat->texture;
                $this->labeledField('Bedding', $tex->bedding ?? '');
                $this->labeledField('Lineation', $tex->lineation ?? '');
                $this->labeledField('Foliation', $tex->foliation ?? '');
                $this->labeledField('Fault', $tex->fault ?? '');
            }
        }

        // Parameters
        if (!empty($sample->parameters) && is_array($sample->parameters)) {
            $this->Ln(2);
            $this->sectionSubheader('Parameters');
            $this->generateParametersTable($sample->parameters, ['control', 'value', 'unit', 'note']);
        }

        // Documents
        if (!empty($sample->documents)) {
            $this->Ln(2);
            $this->generateDocuments($sample->documents);
        }

        $this->Ln(5);
    }

    /**
     * Generate mineralogy table
     */
    protected function generateMineralogyTable($composition)
    {
        $this->SetFont('DejaVu', 'B', 8);
        $this->SetFillColor($this->lightBg[0], $this->lightBg[1], $this->lightBg[2]);

        $this->Cell(50, 6, 'Mineral', 1, 0, 'L', true);
        $this->Cell(30, 6, 'Fraction', 1, 0, 'C', true);
        $this->Cell(25, 6, 'Unit', 1, 0, 'C', true);
        $this->Cell(30, 6, 'Grain Size', 1, 1, 'C', true);

        $this->SetFont('DejaVu', '', 8);
        foreach ($composition as $comp) {
            $this->Cell(50, 5, $comp->mineral ?? '', 1, 0);
            $this->Cell(30, 5, $comp->fraction ?? '', 1, 0, 'C');
            $this->Cell(25, 5, $comp->unit ?? '', 1, 0, 'C');
            $this->Cell(30, 5, ($comp->grainsize ?? '') . ($comp->grainsize ? ' um' : ''), 1, 1, 'C');
        }
    }

    /**
     * Generate parameters table
     */
    protected function generateParametersTable($params, $columns = ['control', 'value', 'unit', 'note'])
    {
        $this->SetFont('DejaVu', 'B', 8);
        $this->SetFillColor($this->lightBg[0], $this->lightBg[1], $this->lightBg[2]);

        // Header row
        $widths = ['control' => 45, 'type' => 45, 'value' => 25, 'min' => 20, 'max' => 20, 'unit' => 20, 'prefix' => 15, 'note' => 45, 'error' => 20];

        foreach ($columns as $col) {
            $w = $widths[$col] ?? 30;
            $label = ucfirst($col);
            if ($col === 'control') $label = 'Parameter';
            $this->Cell($w, 6, $label, 1, 0, 'L', true);
        }
        $this->Ln();

        $this->SetFont('DejaVu', '', 8);
        foreach ($params as $param) {
            foreach ($columns as $col) {
                $w = $widths[$col] ?? 30;
                $val = $param->{$col} ?? '';
                if ($val === '-') $val = '';
                $this->Cell($w, 5, $this->truncate($val, $w), 1, 0);
            }
            $this->Ln();
        }
    }

    /**
     * Generate Facility & Apparatus section
     */
    protected function generateFacilityApparatusSection($facility, $apparatus)
    {
        $this->sectionHeader('Facility & Apparatus');

        // Facility
        if (!empty($facility)) {
            $this->sectionSubheader('Facility');
            $this->labeledField('Name', $facility->name ?? '');
            $this->labeledField('Institute', $facility->institute ?? '');
            $this->labeledField('Department', $facility->department ?? '');
            $this->labeledField('Type', $facility->type ?? '');
            $this->labeledField('Description', $facility->description ?? '');

            if (!empty($facility->address)) {
                $addr = $facility->address;
                $addrStr = implode(', ', array_filter([
                    $addr->street ?? '',
                    $addr->building ?? '',
                    $addr->city ?? '',
                    $addr->state ?? '',
                    $addr->postcode ?? '',
                    $addr->country ?? ''
                ]));
                if ($addrStr) {
                    $this->labeledField('Address', $addrStr);
                }
            }

            if (!empty($facility->contact)) {
                $contact = $facility->contact;
                $contactStr = trim(($contact->firstname ?? '') . ' ' . ($contact->lastname ?? ''));
                if (!empty($contact->email)) {
                    $contactStr .= ' (' . $contact->email . ')';
                }
                $this->labeledField('Contact', $contactStr);
            }

            $this->Ln(3);
        }

        // Apparatus
        if (!empty($apparatus)) {
            $this->sectionSubheader('Apparatus');
            $this->labeledField('Name', $apparatus->name ?? '');
            $this->labeledField('Type', $apparatus->type ?? '');
            $this->labeledField('ID', $apparatus->id ?? '');
            $this->labeledField('Location', $apparatus->location ?? '');
            $this->labeledField('Description', $apparatus->description ?? '');

            // Features
            if (!empty($apparatus->features) && is_array($apparatus->features)) {
                $this->Ln(2);
                $this->SetFont('DejaVu', 'B', 9);
                $this->Cell(45, 5, 'Features:', 0, 0);
                $this->SetFont('DejaVu', '', 9);
                $this->MultiCell(0, 5, implode(', ', $apparatus->features), 0, 'L');
            }

            // Parameters
            if (!empty($apparatus->parameters) && is_array($apparatus->parameters)) {
                $this->Ln(2);
                $this->SetFont('DejaVu', 'B', 9);
                $this->Cell(0, 5, 'Parameters:', 0, 1);
                $this->generateParametersTable($apparatus->parameters, ['type', 'min', 'max', 'unit', 'note']);
            }

            // Documents
            if (!empty($apparatus->documents)) {
                $this->Ln(2);
                $this->generateDocuments($apparatus->documents);
            }
        }

        $this->Ln(5);
    }

    /**
     * Generate Experimental Setup section
     */
    protected function generateExperimentalSetupSection($experiment)
    {
        // Skip if already shown in title
        // Just show geometry here

        if (!empty($experiment->geometry) && is_array($experiment->geometry)) {
            $this->sectionHeader('Sample Assembly Geometry');

            // Sort by order
            $geometry = $experiment->geometry;
            usort($geometry, function($a, $b) {
                return ($a->order ?? 0) - ($b->order ?? 0);
            });

            foreach ($geometry as $geom) {
                $this->SetFont('DejaVu', 'B', 9);
                $title = ($geom->order ?? '') . '. ' . ($geom->type ?? 'Component');
                $this->Cell(0, 6, $title, 'B', 1);

                $this->SetFont('DejaVu', '', 9);
                $this->labeledField('Geometry', $geom->geometry ?? '');
                $this->labeledField('Material', $geom->material ?? '');

                // Dimensions
                if (!empty($geom->dimensions) && is_array($geom->dimensions)) {
                    $this->SetFont('DejaVu', 'B', 8);
                    $this->Cell(0, 5, 'Dimensions:', 0, 1);

                    $this->SetFont('DejaVu', '', 8);
                    foreach ($geom->dimensions as $dim) {
                        $dimStr = ($dim->variable ?? '') . ': ' . ($dim->value ?? '') . ' ' . ($dim->unit ?? '');
                        if (!empty($dim->note)) {
                            $dimStr .= ' (' . $dim->note . ')';
                        }
                        $this->Cell(10, 4, '', 0, 0);
                        $this->Cell(0, 4, $dimStr, 0, 1);
                    }
                }

                $this->Ln(2);
            }
        }

        // Documents from experiment setup
        if (!empty($experiment->documents)) {
            $this->Ln(2);
            $this->generateDocuments($experiment->documents);
        }

        $this->Ln(3);
    }

    /**
     * Generate DAQ section
     */
    protected function generateDAQSection($daq)
    {
        $this->sectionHeader('Data Acquisition (DAQ)');

        $this->labeledField('Name', $daq->name ?? '');
        $this->labeledField('Type', $daq->type ?? '');
        $this->labeledField('Location', $daq->location ?? '');
        $this->labeledField('Description', $daq->description ?? '');

        // Devices
        if (!empty($daq->devices) && is_array($daq->devices)) {
            foreach ($daq->devices as $idx => $device) {
                $this->Ln(3);
                $this->sectionSubheader('Device ' . ($idx + 1) . ': ' . ($device->name ?? 'Unknown'));

                // Channels
                if (!empty($device->channels) && is_array($device->channels)) {
                    $this->SetFont('DejaVu', 'B', 8);
                    $this->SetFillColor($this->lightBg[0], $this->lightBg[1], $this->lightBg[2]);

                    // Channel header table
                    $this->Cell(15, 6, 'Ch#', 1, 0, 'C', true);
                    $this->Cell(30, 6, 'Type', 1, 0, 'L', true);
                    $this->Cell(55, 6, 'Header', 1, 0, 'L', true);
                    $this->Cell(25, 6, 'Unit', 1, 0, 'C', true);
                    $this->Cell(50, 6, 'Sensor', 1, 1, 'L', true);

                    $this->SetFont('DejaVu', '', 7);
                    foreach ($device->channels as $channel) {
                        $header = $channel->header ?? new stdClass();
                        $headerStr = implode(' / ', array_filter([
                            $header->type ?? '',
                            $header->spec_a ?? '',
                            $header->spec_b ?? '',
                            $header->spec_c ?? ''
                        ]));

                        $sensor = $channel->sensor ?? new stdClass();
                        $sensorStr = $sensor->detail ?? $sensor->type ?? '';

                        $this->Cell(15, 5, $channel->number ?? '', 1, 0, 'C');
                        $this->Cell(30, 5, $this->truncate($channel->type ?? '', 30), 1, 0);
                        $this->Cell(55, 5, $this->truncate($headerStr, 55), 1, 0);
                        $this->Cell(25, 5, $header->unit ?? '', 1, 0, 'C');
                        $this->Cell(50, 5, $this->truncate($sensorStr, 50), 1, 1);
                    }
                }
            }
        }

        $this->Ln(5);
    }

    /**
     * Generate Protocol section
     */
    protected function generateProtocolSection($protocol)
    {
        $this->sectionHeader('Experimental Protocol');

        foreach ($protocol as $idx => $step) {
            $this->SetFont('DejaVu', 'B', 10);
            $stepTitle = 'Step ' . ($idx + 1);
            if (!empty($step->test)) {
                $stepTitle .= ': ' . $step->test;
            }
            $this->Cell(0, 6, $stepTitle, 'B', 1);

            $this->SetFont('DejaVu', '', 9);
            $this->labeledField('Objective', $step->objective ?? '');
            $this->labeledField('Description', $step->description ?? '');

            // Parameters
            if (!empty($step->parameters) && is_array($step->parameters)) {
                $this->SetFont('DejaVu', 'B', 8);
                $this->Cell(0, 5, 'Parameters:', 0, 1);

                $this->SetFont('DejaVu', '', 8);
                foreach ($step->parameters as $param) {
                    $paramStr = ($param->control ?? '') . ': ' . ($param->value ?? '') . ' ' . ($param->unit ?? '');
                    if (!empty($param->note)) {
                        $paramStr .= ' - ' . $param->note;
                    }
                    $this->Cell(10, 4, '', 0, 0);
                    $this->Cell(0, 4, $paramStr, 0, 1);
                }
            }

            $this->Ln(3);
        }

        $this->Ln(3);
    }

    /**
     * Generate Data section
     */
    protected function generateDataSection($data)
    {
        $this->sectionHeader('Data');

        // Datasets
        if (!empty($data->datasets) && is_array($data->datasets)) {
            foreach ($data->datasets as $idx => $dataset) {
                $this->sectionSubheader('Dataset ' . ($idx + 1) . ': ' . ($dataset->data ?? 'Unknown'));

                $this->labeledField('Type', $dataset->type ?? '');
                $this->labeledField('Format', $dataset->format ?? '');
                $this->labeledField('ID', $dataset->id ?? '');
                $this->labeledField('Rating', $dataset->rating ?? '');
                $this->labeledField('Description', $dataset->description ?? '');

                // Parameters (for Parameter datasets)
                if (!empty($dataset->parameters) && is_array($dataset->parameters)) {
                    $this->Ln(2);
                    $this->SetFont('DejaVu', 'B', 8);
                    $this->Cell(0, 5, 'Parameters:', 0, 1);
                    $this->generateParametersTable($dataset->parameters, ['control', 'value', 'error', 'unit', 'note']);
                }

                // Time series headers
                if (!empty($dataset->headers) && is_array($dataset->headers)) {
                    $this->Ln(2);
                    $this->SetFont('DejaVu', 'B', 8);
                    $this->Cell(0, 5, 'Time Series Headers:', 0, 1);

                    $this->SetFillColor($this->lightBg[0], $this->lightBg[1], $this->lightBg[2]);
                    $this->Cell(15, 5, 'Col#', 1, 0, 'C', true);
                    $this->Cell(60, 5, 'Header', 1, 0, 'L', true);
                    $this->Cell(25, 5, 'Type', 1, 0, 'L', true);
                    $this->Cell(20, 5, 'Unit', 1, 0, 'C', true);
                    $this->Cell(25, 5, 'Rating', 1, 1, 'C', true);

                    $this->SetFont('DejaVu', '', 7);
                    foreach ($dataset->headers as $hdr) {
                        $header = $hdr->header ?? new stdClass();
                        $headerStr = implode(' / ', array_filter([
                            $header->header ?? '',
                            $header->spec_a ?? '',
                            $header->spec_b ?? '',
                            $header->spec_c ?? ''
                        ]));

                        $this->Cell(15, 4, $hdr->number ?? '', 1, 0, 'C');
                        $this->Cell(60, 4, $this->truncate($headerStr, 60), 1, 0);
                        $this->Cell(25, 4, $this->truncate($hdr->type ?? '', 25), 1, 0);
                        $this->Cell(20, 4, $header->unit ?? '', 1, 0, 'C');
                        $this->Cell(25, 4, $hdr->rating ?? '', 1, 1, 'C');
                    }
                }

                // File path
                if (!empty($dataset->path)) {
                    $this->Ln(2);
                    $this->labeledField('File', $dataset->path);
                }

                $this->Ln(4);
            }
        }

        $this->Ln(3);
    }

    /**
     * Generate documents list with embedded images
     */
    protected function generateDocuments($documents)
    {
        if (empty($documents) || !is_array($documents)) return;

        $this->sectionSubheader('Documents');

        foreach ($documents as $doc) {
            $this->SetFont('DejaVu', 'B', 9);
            $docTitle = ($doc->type ?? 'Document') . ': ' . ($doc->id ?? $doc->uuid ?? 'Unknown');
            $this->Cell(0, 5, $docTitle, 0, 1);

            $this->SetFont('DejaVu', '', 8);
            if (!empty($doc->description)) {
                $this->Cell(0, 4, $doc->description, 0, 1);
            }

            // Try to embed image if it's a picture
            if (in_array(strtolower($doc->type ?? ''), ['picture', 'diagram']) &&
                in_array(strtolower($doc->format ?? ''), ['jpg', 'jpeg', 'png'])) {

                $imagePath = $this->resolveImagePath($doc);
                if ($imagePath && file_exists($imagePath)) {
                    $this->Ln(2);
                    try {
                        // Scale image to fit width
                        $maxWidth = 80;
                        $this->Image($imagePath, null, null, $maxWidth);
                        $this->Ln(2);
                    } catch (Exception $e) {
                        // Image couldn't be loaded, just show path
                        $this->SetFont('DejaVu', '', 7);
                        $this->Cell(0, 4, 'Path: ' . ($doc->path ?? ''), 0, 1);
                    }
                } else {
                    // Show URL if can't load locally
                    $this->SetFont('DejaVu', '', 7);
                    $this->SetTextColor($this->textMuted[0], $this->textMuted[1], $this->textMuted[2]);
                    $this->Cell(0, 4, 'Path: ' . ($doc->path ?? ''), 0, 1);
                    $this->SetTextColor($this->textDark[0], $this->textDark[1], $this->textDark[2]);
                }
            } else {
                // Non-image document
                $this->SetFont('DejaVu', '', 7);
                $this->SetTextColor($this->textMuted[0], $this->textMuted[1], $this->textMuted[2]);
                $this->Cell(0, 4, 'Format: ' . ($doc->format ?? 'unknown') . ' | Path: ' . ($doc->path ?? ''), 0, 1);
                $this->SetTextColor($this->textDark[0], $this->textDark[1], $this->textDark[2]);
            }

            $this->Ln(2);
        }
    }

    /**
     * Resolve image path (local or fetch from URL)
     */
    protected function resolveImagePath($doc)
    {
        // Check if it's a local file in expimages
        if (!empty($doc->uuid)) {
            $localPath = $this->expimagesPath . $doc->uuid;

            // Try with common extensions
            foreach (['', '.jpg', '.jpeg', '.png', '.gif'] as $ext) {
                if (file_exists($localPath . $ext)) {
                    return $localPath . $ext;
                }
            }

            // Check if directory exists with file inside
            if (is_dir($localPath)) {
                $files = glob($localPath . '/*');
                if (!empty($files)) {
                    return $files[0];
                }
            }
        }

        // Return null - can't embed remote URLs directly in PDF easily
        return null;
    }

    /**
     * Truncate string to fit in cell
     */
    protected function truncate($str, $width)
    {
        if (empty($str)) return '';

        // Rough estimate: 2.5 chars per mm at font size 8
        $maxChars = (int)($width / 2);

        if (strlen($str) > $maxChars) {
            return substr($str, 0, $maxChars - 2) . '..';
        }

        return $str;
    }
}
