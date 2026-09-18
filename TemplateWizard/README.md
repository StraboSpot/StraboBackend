# Template Wizard

A three-page wizard application for creating and managing custom data entry templates in StraboSpot.

## Overview

The Template Wizard allows users to either select from existing templates or create new ones by choosing data sections and customizing field layouts through an interactive HandsonTable interface.

## Files

- **index.php** - Landing page: Export / Import / Design cards + My Templates list (js/landing.js)
- **design_template.php** - HandsonTable-based template designer
- **save_template.php** - Data processing and debug output page

## Usage

### Starting the Wizard

Navigate to: `/TemplateWizard/`

### Page 1: Landing (task first, 2026-09-18)

Three cards lead: **Export a dataset** (export.php), **Import a spreadsheet**
(review.php) and **Design a template** (unfolds the sections picker inline and
POSTs `template_method=new` + `selected_sections[]` to design_template.php).
**My Templates** below lists saved designs with Download blank / Edit / Delete;
Edit is the "use an existing template" path (GET `template_id`).

### Page 2: Template Design

Features:
- Interactive HandsonTable with dark theme
- Drag-and-drop column reordering
- Paste data from Excel, CSV, or Google Sheets
- Auto-save detection (shows save button when changes detected)
- Template naming for new templates

### Page 3: Debug Output

Displays all submitted data for testing and debugging purposes.

## Column Mappings

The following sections can be included in templates:

**Spot Data** (always included):
- Name, Date, Self, Notes, Real World Coordinates, Pixel Coordinates, Latitude, Longitude, Altitude

**Orientation Data**:
- Strike, Dip, Planar Feature Type, Other Feature, Rake, Trend, Plunge, Linear Feature Type

**Rock Units**:
- Label, Type, Color

**Sample Data**:
- Sample Type, Sample Size, Sample Color

## Dependencies

- **HandsonTable** v12.4.0 (loaded via CDN)
- StraboSpot standard header/footer includes
- Database connection (`db.php`) for future integration

## Data Flow

### Page 1 → Page 2
```json
{
  "template_method": "existing|new",
  "template_id": "123",
  "selected_sections": ["spot", "orientation", "sample"]
}
```

### Page 2 → Page 3
```json
{
  "template_method": "existing|new",
  "template_id": "123",
  "template_name": "My Template",
  "table_data": "[['Name','Date','Strike'],['Sample 1','2025-01-15','45']]"
}
```

## Future Development

- Database integration for saving/loading templates
- User authentication and template ownership
- Template validation and sharing
- Field-level configuration (data types, validation rules)

## Testing

All placeholder data is currently used for testing. Database connections will be implemented in Phase 2.

## Documentation

See `/docs/TemplateWizardPRD.md` for complete product requirements and specifications.
