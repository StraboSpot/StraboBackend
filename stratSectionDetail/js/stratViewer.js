/**
 * Stratigraphic Section Viewer - D3.js Interactive Renderer
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    MIT
 */

(function () {
  'use strict';

  // ─── Constants ───────────────────────────────────────────────────────
  var PIXEL_RATIO = 4;
  var Y_AXIS_OFFSET = 50;
  var X_AXIS_OFFSET = 100;
  var X_INTERVAL = 10;
  var Y_INTERVAL = 20;
  var Y_TICK_WIDTH = 10;
  var X_TICK_WIDTH = 10;

  // ─── X-Axis Label Data ───────────────────────────────────────────────
  var X_AXIS_LABELS = {
    clastic: [
      { value: 'clay', label: 'clay' },
      { value: 'silt', label: 'silt' },
      { value: 'sand_very_fin', label: 'sand- very fine' },
      { value: 'sand_fine_low', label: 'sand- fine lower' },
      { value: 'sand_fine_upp', label: 'sand- fine upper' },
      { value: 'sand_medium_l', label: 'sand- medium lower' },
      { value: 'sand_medium_u', label: 'sand- medium upper' },
      { value: 'sand_coarse_l', label: 'sand- coarse lower' },
      { value: 'sand_coarse_u', label: 'sand- coarse upper' },
      { value: 'sand_very_coa', label: 'sand- very coarse' },
      { value: 'granule', label: 'granule' },
      { value: 'pebble', label: 'pebble' },
      { value: 'cobble', label: 'cobble' },
      { value: 'boulder', label: 'boulder' }
    ],
    carbonate: [
      { value: 'mudstone', label: 'mudstone' },
      { value: 'wackestone', label: 'wackestone' },
      { value: 'packstone', label: 'packstone' },
      { value: 'grainstone', label: 'grainstone' },
      { value: 'floatstone', label: 'floatstone' },
      { value: 'rudstone', label: 'rudstone' },
      { value: 'boundstone', label: 'boundstone' },
      { value: 'framestone', label: 'framestone' },
      { value: 'bindstone', label: 'bindstone' },
      { value: 'bafflestone', label: 'bafflestone' },
      { value: 'cementstone', label: 'cementstone' },
      { value: 'recrystallized', label: 'recrystallized' }
    ],
    misc: [
      { value: 'organic/coal', label: 'organic/coal' },
      { value: 'evaporite', label: 'evaporite' },
      { value: 'chert', label: 'chert' },
      { value: 'ironstone', label: 'ironstone' },
      { value: 'phosphatic', label: 'phosphatic' },
      { value: 'volcaniclastic', label: 'volcaniclastic' }
    ],
    basic_lithologies: [
      { value: 'other', label: 'other' },
      { value: 'coal', label: 'coal' },
      { value: 'mudstone', label: 'mudstone' },
      { value: 'sandstone', label: 'sandstone' },
      { value: 'conglomerate_breccia', label: 'conglomerate/breccia' },
      { value: 'limestone_dolomite', label: 'limestone/dolomite' }
    ],
    weathering: [
      { value: '1', label: '1 - least resistant' },
      { value: '2', label: '2' },
      { value: '3', label: '3 - moderately resistant' },
      { value: '4', label: '4' },
      { value: '5', label: '5 - most resistant' }
    ]
  };

  // ─── State ───────────────────────────────────────────────────────────
  var patternInfo = [];
  var loadedPatterns = {};
  var stratSection = null;
  var svgWidth = 500;
  var svgHeight = 500;
  var sectionHeight = 0;
  var xlabelcount = 0;
  var smallticks = [];
  var svg, defs, mainGroup, zoomBehavior;

  // ─── Entry Point ─────────────────────────────────────────────────────
  init(STRAT_SPOT_ID);

  function init(spotId) {
    fetchData(spotId)
      .then(function (geojson) {
        return processData(geojson, spotId);
      })
      .then(function (data) {
        return loadPatternInfo().then(function () { return data; });
      })
      .then(function (data) {
        return loadAllPatterns(data.spots).then(function () { return data; });
      })
      .then(function (data) {
        hideLoading();
        showViewer();
        render(data);
      })
      .catch(function (err) {
        hideLoading();
        showError(err.message || 'An unknown error occurred.');
        console.error(err);
      });
  }

  // ─── Data Loading ────────────────────────────────────────────────────
  function fetchData(spotId) {
    return fetch('getData.php?spot_id=' + spotId)
      .then(function (res) {
        return res.json().then(function (data) {
          if (!res.ok) throw new Error(data.error || 'Failed to load data.');
          return data;
        });
      });
  }

  function processData(geojson, spotId) {
    if (!geojson.features || geojson.features.length === 0) {
      throw new Error('No features found for this strat section.');
    }

    var rootSpot = null;
    var spots = [];

    for (var i = 0; i < geojson.features.length; i++) {
      var f = geojson.features[i];
      if (f.properties && f.properties.is_root_strat_section_spot) {
        rootSpot = f;
      } else {
        spots.push(f);
      }
    }

    if (!rootSpot) {
      throw new Error('Root strat section spot not found.');
    }

    stratSection = rootSpot.properties.sed.strat_section;

    if (!stratSection) {
      throw new Error('Strat section data not found on root spot.');
    }

    // Set subtitle
    var subtitle = rootSpot.properties.name || ('Spot ' + spotId);
    var profileLabel = (stratSection.column_profile || '').replace(/_/g, ' ');
    document.getElementById('strat-subtitle').textContent = subtitle + ' — ' + profileLabel;

    return { rootSpot: rootSpot, spots: spots, stratSection: stratSection };
  }

  // ─── Pattern Loading ─────────────────────────────────────────────────
  function loadPatternInfo() {
    return fetch('/includes/svg/cleaned/patterninfo.json')
      .then(function (res) { return res.json(); })
      .then(function (data) { patternInfo = data; });
  }

  function loadAllPatterns(spots) {
    var needed = {};

    for (var i = 0; i < spots.length; i++) {
      var spot = spots[i];
      var sed = spot.properties && spot.properties.sed;
      if (!sed || !sed.lithologies) continue;

      var liths = sed.lithologies;
      if (!Array.isArray(liths)) liths = [liths];

      for (var j = 0; j < liths.length; j++) {
        var isInterbed = getIsInterbed(spot, j);
        var dir = getPatternDir(spot, isInterbed);
        if (dir && dir !== 'Blank/Blank') {
          needed[dir] = true;
        }
      }
    }

    var dirs = Object.keys(needed);
    if (dirs.length === 0) return Promise.resolve();

    var fetches = dirs.map(function (dir) {
      return fetch('/includes/svg/cleaned/' + dir + '.svg')
        .then(function (res) { return res.text(); })
        .then(function (text) { loadedPatterns[dir] = text; })
        .catch(function () { /* pattern not found, will fall back to color */ });
    });

    return Promise.all(fetches);
  }

  function injectPatterns() {
    var dirs = Object.keys(loadedPatterns);
    for (var i = 0; i < dirs.length; i++) {
      var svgText = loadedPatterns[dirs[i]];
      // Parse the SVG text and inject style + pattern into our defs
      var parser = new DOMParser();
      var doc = parser.parseFromString('<svg xmlns="http://www.w3.org/2000/svg">' + svgText + '</svg>', 'image/svg+xml');

      var styles = doc.querySelectorAll('style');
      for (var s = 0; s < styles.length; s++) {
        defs.node().appendChild(document.importNode(styles[s], true));
      }
      var patterns = doc.querySelectorAll('pattern');
      for (var p = 0; p < patterns.length; p++) {
        defs.node().appendChild(document.importNode(patterns[p], true));
      }
    }
  }

  // ─── Rendering ───────────────────────────────────────────────────────
  function render(data) {
    // Calculate section height from geometry
    computeSectionHeight(data.spots);

    var yAxisHeight = sectionHeight + 40;
    svgHeight = (yAxisHeight * PIXEL_RATIO) + (130 * PIXEL_RATIO);

    // Build axes to determine xlabelcount, then set width
    var labelOffset = computeLabelOffset(stratSection.column_profile);
    computeXLabelCount(stratSection);
    svgWidth = (xlabelcount * PIXEL_RATIO * X_INTERVAL) + (100 * PIXEL_RATIO);

    // Create SVG
    svg = d3.select('#strat-viewer')
      .append('svg')
      .attr('xmlns', 'http://www.w3.org/2000/svg')
      .attr('xmlns:xlink', 'http://www.w3.org/1999/xlink')
      .attr('width', svgWidth)
      .attr('height', svgHeight)
      .attr('viewBox', '0 0 ' + svgWidth + ' ' + svgHeight);

    defs = svg.append('defs');
    injectPatterns();

    // Background
    svg.append('rect')
      .attr('width', svgWidth)
      .attr('height', svgHeight)
      .attr('fill', '#FFFFFF');

    mainGroup = svg.append('g').attr('class', 'main-group');

    // Draw layers in order
    var axisGroup = mainGroup.append('g').attr('class', 'axis-layer');
    var unitGroup = mainGroup.append('g').attr('class', 'unit-layer');
    var labelGroup = mainGroup.append('g').attr('class', 'label-layer');

    buildYAxis(axisGroup, yAxisHeight);
    buildXAxis(axisGroup, stratSection, yAxisHeight, labelOffset);
    drawSpots(data.spots, unitGroup, labelGroup);

    // Setup zoom
    setupZoom();

    // Setup detail panel
    setupDetailPanel();

    // Setup export
    setupExport();
  }

  function computeSectionHeight(spots) {
    sectionHeight = 0;
    for (var i = 0; i < spots.length; i++) {
      var geom = spots[i].geometry;
      if (!geom) continue;
      var maxY = getMaxY(geom);
      if (maxY > sectionHeight) sectionHeight = maxY;
    }
  }

  function getMaxY(geom) {
    if (geom.type === 'Polygon') {
      return getMaxYFromCoords(geom.coordinates);
    } else if (geom.type === 'LineString') {
      return getMaxYFromCoords([geom.coordinates]);
    } else if (geom.type === 'Point') {
      return geom.coordinates[1] || 0;
    } else if (geom.type === 'GeometryCollection') {
      var max = 0;
      for (var i = 0; i < geom.geometries.length; i++) {
        var m = getMaxY(geom.geometries[i]);
        if (m > max) max = m;
      }
      return max;
    }
    return 0;
  }

  function getMaxYFromCoords(rings) {
    var max = 0;
    for (var i = 0; i < rings.length; i++) {
      var ring = rings[i];
      if (!Array.isArray(ring)) continue;
      for (var j = 0; j < ring.length; j++) {
        var coord = ring[j];
        var y = Array.isArray(coord) ? coord[1] : 0;
        if (y > max) max = y;
      }
    }
    return max;
  }

  // ─── Y-Axis ──────────────────────────────────────────────────────────
  function buildYAxis(group, yAxisHeight) {
    var tickCount = Math.floor(yAxisHeight / Y_INTERVAL);

    for (var x = 0; x <= tickCount; x++) {
      var y = (x * Y_INTERVAL) + X_AXIS_OFFSET;
      if (x > 0) {
        drawLine(group, Y_AXIS_OFFSET, y, Y_AXIS_OFFSET - Y_TICK_WIDTH, y, 2, '#333');
        drawText(group, String(x), Y_AXIS_OFFSET - 5.5 - Y_TICK_WIDTH, y - 1, 0, 12, '#333', 'start');
      }
    }

    // Main Y axis line
    drawLine(group, Y_AXIS_OFFSET, X_AXIS_OFFSET - 15, Y_AXIS_OFFSET, yAxisHeight + X_AXIS_OFFSET, 2, '#333');
  }

  // ─── X-Axis ──────────────────────────────────────────────────────────
  function computeLabelOffset(profile) {
    if (profile === 'clastic') return 0;
    if (profile === 'carbonate') return 0.333;
    if (profile === 'mixed_clastic') return 0;
    if (profile === 'weathering_pro') return 0;
    if (profile === 'basic_lithologies') return 1;
    return 0;
  }

  function computeXLabelCount(ss) {
    xlabelcount = 0;
    var profile = ss.column_profile;

    if (profile === 'clastic') {
      xlabelcount = Math.max(xlabelcount, X_AXIS_LABELS.clastic.length);
    } else if (profile === 'carbonate') {
      xlabelcount = Math.max(xlabelcount, X_AXIS_LABELS.carbonate.length);
    } else if (profile === 'mixed_clastic') {
      xlabelcount = Math.max(xlabelcount, X_AXIS_LABELS.clastic.length);
      xlabelcount = Math.max(xlabelcount, X_AXIS_LABELS.carbonate.length);
    } else if (profile === 'weathering_pro') {
      xlabelcount = Math.max(xlabelcount, X_AXIS_LABELS.weathering.length);
    } else if (profile === 'basic_lithologies') {
      xlabelcount = Math.max(xlabelcount, X_AXIS_LABELS.basic_lithologies.length);
    }

    if (ss.misc_labels) {
      xlabelcount = Math.max(xlabelcount, X_AXIS_LABELS.misc.length);
    }
  }

  function buildXAxis(group, ss, yAxisHeight, labelOffset) {
    var profile = ss.column_profile;

    if (profile === 'clastic') {
      drawXLabels(group, 'clastic', 0, '#333333', 0, yAxisHeight);
    } else if (profile === 'carbonate') {
      drawXLabels(group, 'carbonate', 0, 'blue', 0.333, yAxisHeight);
    } else if (profile === 'mixed_clastic') {
      drawXLabels(group, 'clastic', 0, '#333333', 0, yAxisHeight);
      drawXLabels(group, 'carbonate', 35, 'blue', 0.333, yAxisHeight);
    } else if (profile === 'weathering_pro') {
      drawXLabels(group, 'weathering', 0, '#333333', 0, yAxisHeight);
    } else if (profile === 'basic_lithologies') {
      drawXLabels(group, 'basic_lithologies', 0, '#333333', 1, yAxisHeight);
    }

    if (ss.misc_labels) {
      drawXLabels(group, 'misc', 35, 'green', 1.666, yAxisHeight);
    }

    // Main X axis line
    drawLine(group, Y_AXIS_OFFSET - Y_TICK_WIDTH - 10, X_AXIS_OFFSET,
      (xlabelcount * X_INTERVAL) + Y_AXIS_OFFSET + X_INTERVAL + (labelOffset * X_INTERVAL), X_AXIS_OFFSET, 2, '#333');

    // Origin label
    drawText(group, '0' + (ss.column_y_axis_units || 'm'), Y_AXIS_OFFSET - Y_TICK_WIDTH - 18, X_AXIS_OFFSET - 1, 0, 14, '#333', 'start');
  }

  function drawXLabels(group, labelType, yoffset, color, offset, yAxisHeight) {
    var labels = X_AXIS_LABELS[labelType];
    if (!labels) return;

    if (labels.length > xlabelcount) xlabelcount = labels.length;

    for (var x = 0; x < labels.length; x++) {
      var idx = x + 1;
      var xPos = Y_AXIS_OFFSET + (idx * X_INTERVAL) + (offset * X_INTERVAL);

      // Tick marks
      drawLine(group, xPos, X_AXIS_OFFSET - 8 - yoffset, xPos, X_AXIS_OFFSET - yoffset, 2, color);

      // Label text (rotated)
      drawText(group, labels[x].label, xPos - 1, X_AXIS_OFFSET - yoffset - X_TICK_WIDTH - 1, 37, null, color, 'start');

      // Dotted grid line
      drawLine(group, xPos, X_AXIS_OFFSET, xPos, yAxisHeight + X_AXIS_OFFSET, 2, '#333', 'dot');
    }

    if (yoffset !== 0) {
      var endX = Y_AXIS_OFFSET + ((labels.length + offset) * X_INTERVAL);
      drawLine(group, Y_AXIS_OFFSET, X_AXIS_OFFSET - yoffset, endX, X_AXIS_OFFSET - yoffset, 2, color);
      drawLine(group, Y_AXIS_OFFSET, X_AXIS_OFFSET - 28 - yoffset, Y_AXIS_OFFSET, X_AXIS_OFFSET - 15, 2, color);
    }

    // Axis type label
    drawText(group, labelType, Y_AXIS_OFFSET - 25, X_AXIS_OFFSET - 10 - yoffset, 0, 12, color, 'start');
  }

  // ─── Drawing Primitives ──────────────────────────────────────────────
  function drawLine(group, x1, y1, x2, y2, weight, color, lineType) {
    var py1 = svgHeight - (PIXEL_RATIO * y1);
    var py2 = svgHeight - (PIXEL_RATIO * y2);
    var px1 = PIXEL_RATIO * x1;
    var px2 = PIXEL_RATIO * x2;

    var line = group.append('line')
      .attr('x1', px1).attr('y1', py1)
      .attr('x2', px2).attr('y2', py2)
      .attr('stroke', color || '#333')
      .attr('stroke-width', weight || 1);

    if (lineType === 'dot') {
      line.attr('stroke-dasharray', weight);
    } else if (lineType === 'dash') {
      line.attr('stroke-dasharray', weight * 2);
    }
  }

  function drawText(group, text, x, y, rotate, size, color, anchor) {
    var py = svgHeight - (PIXEL_RATIO * y);
    var px = PIXEL_RATIO * x;
    size = size || 16;

    var t = group.append('text')
      .attr('x', px)
      .attr('y', py)
      .attr('text-anchor', anchor || 'start')
      .attr('font-family', 'Arial')
      .attr('font-size', size + 'px')
      .attr('fill', color || '#333')
      .attr('stroke', 'none')
      .text(text);

    if (rotate) {
      t.attr('transform', 'rotate(' + rotate + ' ' + px + ',' + py + ')');
    }

    return t;
  }

  function drawSpotLabel(group, text, x, y, rotate, size, color, anchor) {
    var py = svgHeight - (PIXEL_RATIO * y);
    var px = PIXEL_RATIO * x;
    size = size || 16;
    text = text || '';

    var t = group.append('text')
      .attr('x', px)
      .attr('y', py)
      .attr('text-anchor', anchor || 'start')
      .attr('font-family', 'Arial')
      .attr('font-size', size + 'px')
      .attr('fill', color || '#333')
      .attr('stroke', '#FFFFFF')
      .attr('stroke-width', '2px')
      .attr('paint-order', 'stroke')
      .attr('font-weight', 'bold')
      .text(text);

    if (rotate) {
      t.attr('transform', 'rotate(' + rotate + ' ' + px + ',' + py + ')');
    }

    return t;
  }

  // ─── Spot Drawing ────────────────────────────────────────────────────
  function drawSpots(spots, unitGroup, labelGroup) {
    for (var i = 0; i < spots.length; i++) {
      var spot = spots[i];
      var geom = spot.geometry;
      if (!geom) continue;

      var spotName = (spot.properties && spot.properties.name) || '';

      if (geom.type === 'GeometryCollection' && geom.geometries) {
        drawGeometryCollection(spot, spotName, geom, unitGroup, labelGroup);
      } else if (geom.type === 'Polygon') {
        drawPolygonSpot(spot, spotName, geom, unitGroup, labelGroup, 'no');
      } else if (geom.type === 'LineString') {
        drawLineSpot(spot, spotName, geom, unitGroup, labelGroup);
      } else if (geom.type === 'Point') {
        drawPointSpot(spot, spotName, geom, unitGroup, labelGroup);
      }
    }
  }

  function drawGeometryCollection(spot, spotName, geom, unitGroup, labelGroup) {
    for (var i = 0; i < geom.geometries.length; i++) {
      var subGeom = geom.geometries[i];
      var isInterbed = getIsInterbed(spot, i);

      var classes = getClasses(spot, isInterbed);
      var coords = subGeom.coordinates;

      if (Array.isArray(classes)) {
        // Pattern fill - draw once per class
        for (var c = 0; c < classes.length; c++) {
          drawPolygon(unitGroup, coords[0], '', classes[c], spot);
        }
      } else {
        // Color fill
        drawPolygon(unitGroup, coords[0], classes, '', spot);
      }

      // Label at sub-geometry centroid
      var centroid = computeCentroid(coords[0]);
      var cx = centroid[0] + Y_AXIS_OFFSET;
      var cy = centroid[1] + X_AXIS_OFFSET - 1;
      drawSpotLabel(labelGroup, spotName, cx, cy, 0, 12, '#333333', 'middle');
    }
  }

  function drawPolygonSpot(spot, spotName, geom, unitGroup, labelGroup, isInterbed) {
    var coords = geom.coordinates[0];
    var classes = getClasses(spot, isInterbed);
    var centroid = computeCentroid(coords);

    if (Array.isArray(classes)) {
      for (var c = 0; c < classes.length; c++) {
        drawPolygon(unitGroup, coords, '', classes[c], spot);
      }
    } else {
      drawPolygon(unitGroup, coords, classes, '', spot);
    }

    var cx = centroid[0] + Y_AXIS_OFFSET;
    var cy = centroid[1] + X_AXIS_OFFSET - 1;
    drawSpotLabel(labelGroup, spotName, cx, cy, 0, 12, '#333333', 'middle');
  }

  function drawLineSpot(spot, spotName, geom, unitGroup, labelGroup) {
    var coords = geom.coordinates;
    var points = '';

    for (var i = 0; i < coords.length; i++) {
      var x = (coords[i][0] + Y_AXIS_OFFSET) * PIXEL_RATIO;
      var y = svgHeight - ((coords[i][1] + X_AXIS_OFFSET) * PIXEL_RATIO);
      points += x + ',' + y + ' ';
    }

    unitGroup.append('polyline')
      .attr('points', points.trim())
      .attr('fill', 'none')
      .attr('stroke', '#666666')
      .attr('stroke-width', 3)
      .attr('class', 'strat-line')
      .datum(spot);

    var centroid = computeCentroidLine(coords);
    var cx = centroid[0] + Y_AXIS_OFFSET;
    var cy = centroid[1] + X_AXIS_OFFSET + 3;
    drawSpotLabel(labelGroup, spotName, cx, cy, 0, 12, '#333333', 'middle');
  }

  function drawPointSpot(spot, spotName, geom, unitGroup, labelGroup) {
    var x = (geom.coordinates[0] + Y_AXIS_OFFSET) * PIXEL_RATIO;
    var y = svgHeight - ((geom.coordinates[1] + X_AXIS_OFFSET) * PIXEL_RATIO);

    unitGroup.append('circle')
      .attr('cx', x)
      .attr('cy', y)
      .attr('r', 7)
      .attr('stroke', 'black')
      .attr('stroke-width', 2)
      .attr('fill', '#e0eaf9')
      .attr('class', 'strat-point')
      .datum(spot);

    var cx = geom.coordinates[0] + Y_AXIS_OFFSET + 2;
    var cy = geom.coordinates[1] + X_AXIS_OFFSET + 2;
    drawSpotLabel(labelGroup, spotName, cx, cy, 0, 12, '#333333', 'start');
  }

  function drawPolygon(group, coords, color, patternClass, spot) {
    var points = '';

    // Remove closing coordinate (duplicate of first)
    var ring = coords.slice(0, -1);

    for (var i = 0; i < ring.length; i++) {
      var origX = ring[i][0];
      var origY = ring[i][1];

      var x = (origX + Y_AXIS_OFFSET) * PIXEL_RATIO;
      var y = svgHeight - ((origY + X_AXIS_OFFSET) * PIXEL_RATIO);

      // Small ticks for non-integer Y values on the axis
      if (origX === 0) {
        var yLabel = origY / Y_INTERVAL;
        if (yLabel !== Math.round(yLabel) && smallticks.indexOf(origY) === -1) {
          var axisGroup = mainGroup.select('.axis-layer');
          drawText(axisGroup, yLabel.toFixed(1), Y_AXIS_OFFSET - 10, (yLabel * Y_INTERVAL) + X_AXIS_OFFSET - 1, 0, 10, '#333', 'start');
          drawLine(axisGroup, Y_AXIS_OFFSET - 3, (yLabel * Y_INTERVAL) + X_AXIS_OFFSET, Y_AXIS_OFFSET, (yLabel * Y_INTERVAL) + X_AXIS_OFFSET, 2, '#333');
          smallticks.push(origY);
        }
      }

      points += x + ',' + y + ' ';
    }

    var polygon = group.append('polygon')
      .attr('points', points.trim())
      .attr('stroke', '#333333')
      .attr('stroke-width', 2)
      .attr('class', 'strat-unit')
      .datum(spot);

    if (color) {
      polygon.attr('fill', color);
    } else if (patternClass) {
      polygon.attr('class', 'strat-unit ' + patternClass);
    } else {
      polygon.attr('fill', '#FFFFFF');
    }
  }

  // ─── Lithology Logic (ported from straboSVG.php) ─────────────────────

  function getGrainSize(lithology) {
    if (!lithology) return '';
    if (lithology.mud_silt_grain_size) return lithology.mud_silt_grain_size;
    if (lithology.sand_grain_size) return lithology.sand_grain_size;
    if (lithology.congl_grain_size) return lithology.congl_grain_size;
    if (lithology.breccia_grain_size) return lithology.breccia_grain_size;
    if (lithology.dunham_classification) return lithology.dunham_classification;
    if (lithology.primary_lithology) return lithology.primary_lithology;
    return '';
  }

  function getIsInterbed(spot, i) {
    var sed = spot.properties && spot.properties.sed;
    if (sed && sed.bedding && sed.bedding.lithology_at_bottom_contact === 'lithology_2') {
      return (i % 2 === 0) ? 'yes' : 'no';
    }
    return (i % 2 !== 0) ? 'yes' : 'no';
  }

  function getClasses(spot, isInterbed) {
    var dir = getPatternDir(spot, isInterbed);
    var colorstring = '';

    if (dir === 'Blank/Blank') {
      colorstring = getColorString(spot, isInterbed);
    }

    if (colorstring) {
      return colorstring;
    }

    // Look up pattern classes from patternInfo
    var parts = dir.split('/');
    var folder1 = parts[0];
    var folder2 = parts[1];
    var cls1 = '', cls2 = '';

    for (var i = 0; i < patternInfo.length; i++) {
      var p = patternInfo[i];
      if (p.folder1 === folder1 && p.folder2 === folder2) {
        cls1 = p.class1;
        cls2 = p.class2;
        break;
      }
    }

    if (!cls1) cls1 = 'foo';
    if (!cls2) cls2 = 'foo';

    return [cls1, cls2];
  }

  function getColorString(spot, isInterbed) {
    var n = (isInterbed === 'no') ? 0 : 1;
    var sed = spot.properties && spot.properties.sed;
    if (!sed || !sed.lithologies) return '';

    var liths = sed.lithologies;
    if (!Array.isArray(liths)) liths = [liths];
    var lithology = liths[n] || liths[0];
    if (!lithology) return '';

    var grainSize = getGrainSize(lithology);

    if (stratSection.column_profile === 'basic_lithologies') {
      if (lithology.primary_lithology === 'limestone') return '#4dffde';
      if (lithology.primary_lithology === 'dolostone') return '#4dffb3';
      if (lithology.primary_lithology === 'organic_coal') return '#000000';
      if (lithology.primary_lithology === 'evaporite') return '#994dff';
      if (lithology.primary_lithology === 'chert') return '#664d4d';
      if (lithology.primary_lithology === 'ironstone') return '#990000';
      if (lithology.primary_lithology === 'phosphatic') return '#99ffb3';
      if (lithology.primary_lithology === 'volcaniclastic') return '#ff80ff';
      if (lithology.mud_silt_grain_size) return '#80de4d';
      if (lithology.sand_grain_size) return '#ffff4d';
      if (lithology.congl_grain_size) return '#ff6600';
      if (lithology.breccia_grain_size) return '#d50000';
      return '#ffffff';
    }

    // Detailed color mapping
    // Mudstone/Shale
    if (grainSize === 'clay') return '#80de4d';
    if (grainSize === 'mud') return '#4dff00';
    if (grainSize === 'silt') return '#99ff66';
    // Sandstone
    if (grainSize === 'sand_very_fin') return '#ffffb3';
    if (grainSize === 'sand_fine_low') return '#ffff99';
    if (grainSize === 'sand_fine_upp') return '#ffff80';
    if (grainSize === 'sand_medium_l') return '#ffff66';
    if (grainSize === 'sand_medium_u') return '#ffff4d';
    if (grainSize === 'sand_coarse_l') return '#ffff00';
    if (grainSize === 'sand_coarse_u') return '#ffeb00';
    if (grainSize === 'sand_very_coa') return '#ffde00';
    // Conglomerate
    if (lithology.primary_lithology === 'siliciclastic' && lithology.siliciclastic_type === 'conglomerate') {
      if (grainSize === 'granule') return '#ff9900';
      if (grainSize === 'pebble') return '#ff8000';
      if (grainSize === 'cobble') return '#ff6600';
      if (grainSize === 'boulder') return '#ff4d00';
    }
    // Breccia
    if (lithology.primary_lithology === 'siliciclastic' && lithology.siliciclastic_type === 'breccia') {
      if (grainSize === 'granule') return '#e60000';
      if (grainSize === 'pebble') return '#cc0000';
      if (grainSize === 'cobble') return '#b30000';
      if (grainSize === 'boulder') return '#990000';
    }
    // Limestone / Dolostone
    if (grainSize === 'mudstone') return '#4dff80';
    if (grainSize === 'wackestone') return '#4dffb3';
    if (grainSize === 'packstone') return '#4dffde';
    if (grainSize === 'grainstone') return '#b3ffff';
    if (grainSize === 'boundstone') return '#4d80ff';
    if (grainSize === 'cementstone') return '#00b3b3';
    if (grainSize === 'recrystallized') return '#0066de';
    if (grainSize === 'floatstone') return '#4dffff';
    if (grainSize === 'rudstone') return '#4dccff';
    if (grainSize === 'framestone') return '#4d80ff';
    if (grainSize === 'bafflestone') return '#4d80ff';
    if (grainSize === 'bindstone') return '#4d80ff';
    // Misc
    if (grainSize === 'evaporite') return '#994dff';
    if (grainSize === 'chert') return '#664d4d';
    if (grainSize === 'ironstone') return '#990000';
    if (grainSize === 'phosphatic') return '#99ffb3';
    if (grainSize === 'volcaniclastic') return '#ff80ff';
    if (grainSize === 'organic_coal') return '#000000';

    return '#ffffff';
  }

  function getPatternDir(spot, isInterbed) {
    var props = spot.properties;
    if (!props || !props.sed || !props.sed.lithologies || !props.strat_section_id) {
      return 'Blank/Blank';
    }

    var n = (isInterbed === 'yes') ? 1 : 0;
    var liths = props.sed.lithologies;
    if (!Array.isArray(liths)) liths = [liths];
    var lithology = liths[n] || liths[0];
    if (!lithology) return 'Blank/Blank';

    var returnpair = '';

    if (stratSection && stratSection.column_profile === 'basic_lithologies') {
      if (lithology.primary_lithology === 'limestone') returnpair = 'Basic/LimeSimple';
      else if (lithology.primary_lithology === 'dolostone') returnpair = 'Basic/DoloSimple';
      else if (lithology.primary_lithology === 'evaporite') returnpair = 'Misc/EvaBasic';
      else if (lithology.primary_lithology === 'chert') returnpair = 'Basic/ChertBasic';
      else if (lithology.primary_lithology === 'phosphatic') returnpair = 'Misc/PhosBasic';
      else if (lithology.primary_lithology === 'volcaniclastic') returnpair = 'Misc/VolBasic';
      else if (lithology.mud_silt_grain_size) returnpair = 'Basic/MudSimple';
      else if (lithology.sand_grain_size) returnpair = 'Basic/SandSimple';
      else if (lithology.congl_grain_size) returnpair = 'Basic/CongSimple';
      else if (lithology.breccia_grain_size) returnpair = 'Basic/BrecSimple';
    } else {
      var grainSize = getGrainSize(lithology);

      if (lithology.primary_lithology === 'limestone') {
        if (grainSize === 'bafflestone') returnpair = 'Limestone/LiBoBasic';
        else if (grainSize === 'bindstone') returnpair = 'Limestone/LiBoBasic';
        else if (grainSize === 'boundstone') returnpair = 'Limestone/LiBoBasic';
        else if (grainSize === 'floatstone') returnpair = 'Limestone/LiFloBasic';
        else if (grainSize === 'framestone') returnpair = 'Limestone/LiBoBasic';
        else if (grainSize === 'grainstone') returnpair = 'Limestone/LiGrBasic';
        else if (grainSize === 'mudstone') returnpair = 'Limestone/LiMudBasic';
        else if (grainSize === 'packstone') returnpair = 'Limestone/LiPaBasic';
        else if (grainSize === 'rudstone') returnpair = 'Limestone/LiRudBasic';
        else if (grainSize === 'wackestone') returnpair = 'Limestone/LiWaBasic';
      }

      if (lithology.primary_lithology === 'dolostone') {
        if (grainSize === 'bafflestone') returnpair = 'Dolomite/DoBoBasic';
        else if (grainSize === 'bindstone') returnpair = 'Dolomite/DoBoBasic';
        else if (grainSize === 'boundstone') returnpair = 'Dolomite/DoBoBasic';
        else if (grainSize === 'floatstone') returnpair = 'Dolomite/DoFloBasic';
        else if (grainSize === 'framestone') returnpair = 'Dolomite/DoBoBasic';
        else if (grainSize === 'grainstone') returnpair = 'Dolomite/DoGrBasic';
        else if (grainSize === 'mudstone') returnpair = 'Dolomite/DoMudBasic';
        else if (grainSize === 'packstone') returnpair = 'Dolomite/DoPaBasic';
        else if (grainSize === 'rudstone') returnpair = 'Dolomite/DoRudBasic';
        else if (grainSize === 'wackestone') returnpair = 'Dolomite/DoWaBasic';
      }

      if (lithology.primary_lithology === 'siliciclastic') {
        var silType = lithology.siliciclastic_type;

        if (silType === 'conglomerate') {
          if (grainSize === 'granule') returnpair = 'Siliciclastics/CGrBasic';
          else if (grainSize === 'pebble') returnpair = 'Siliciclastics/CPebBasic';
          else if (grainSize === 'cobble') returnpair = 'Siliciclastics/CCobBasic';
          else if (grainSize === 'boulder') returnpair = 'Siliciclastics/CBoBasic';
        } else if (silType === 'breccia') {
          if (grainSize === 'granule') returnpair = 'Siliciclastics/BGrBasic';
          else if (grainSize === 'pebble') returnpair = 'Siliciclastics/BPebBasic';
          else if (grainSize === 'cobble') returnpair = 'Siliciclastics/BCobBasic';
          else if (grainSize === 'boulder') returnpair = 'Siliciclastics/BBoBasic';
        } else {
          if (grainSize === 'evaporite') returnpair = 'Misc/EvaBasic';
          else if (grainSize === 'chert') returnpair = 'Misc/ChertBasic';
          else if (grainSize === 'phosphatic') returnpair = 'Misc/PhosBasic';
          else if (grainSize === 'volcaniclastic') returnpair = 'Misc/VolBasic';
          else if (grainSize === 'sand_very_fin') returnpair = 'Siliciclastics/VFBasic';
          else if (grainSize === 'sand_fine_low') returnpair = 'Siliciclastics/FLBasic';
          else if (grainSize === 'sand_fine_upp') returnpair = 'Siliciclastics/FUBasic';
          else if (grainSize === 'sand_medium_l') returnpair = 'Siliciclastics/MLBasic';
          else if (grainSize === 'sand_medium_u') returnpair = 'Siliciclastics/MUBasic';
          else if (grainSize === 'sand_coarse_l') returnpair = 'Siliciclastics/CLBasic';
          else if (grainSize === 'sand_coarse_u') returnpair = 'Siliciclastics/CUBasic';
          else if (grainSize === 'sand_very_coa') returnpair = 'Siliciclastics/VCBasic';
          else if (grainSize === 'clay') returnpair = 'Siliciclastics/ClayBasic';
          else if (grainSize === 'mud') returnpair = 'Siliciclastics/MudBasic';
          else if (grainSize === 'silt') returnpair = 'Siliciclastics/SiltBasic';
          else if (grainSize === 'mud_silt') returnpair = 'Basic/MudSimple';
          else if (grainSize === 'sandstone') returnpair = 'Basic/SandSimple';
          else if (grainSize === 'conglomerate') returnpair = 'Basic/CongSimple';
          else if (grainSize === 'breccia') returnpair = 'Basic/BrecSimple';
        }
      }
    }

    // Fallback matching (when primary_lithology didn't match above)
    if (!returnpair && stratSection) {
      var gs = getGrainSize(lithology);
      var pl = lithology.primary_lithology || '';

      if (gs === 'clay') returnpair = 'Siliciclastics/ClayBasic';
      else if (gs === 'mud') returnpair = 'Siliciclastics/MudBasic';
      else if (gs === 'silt') returnpair = 'Siliciclastics/SiltBasic';
      else if (gs === 'sand_very_fin') returnpair = 'Siliciclastics/VFBasic';
      else if (gs === 'sand_fine_low') returnpair = 'Siliciclastics/FLBasic';
      else if (gs === 'sand_fine_upp') returnpair = 'Siliciclastics/FUBasic';
      else if (gs === 'sand_medium_l') returnpair = 'Siliciclastics/MLBasic';
      else if (gs === 'sand_medium_u') returnpair = 'Siliciclastics/MUBasic';
      else if (gs === 'sand_coarse_l') returnpair = 'Siliciclastics/CLBasic';
      else if (gs === 'sand_coarse_u') returnpair = 'Siliciclastics/CUBasic';
      else if (gs === 'sand_very_coa') returnpair = 'Siliciclastics/VCBasic';
      else if (pl === 'limestone') {
        if (gs === 'bafflestone') returnpair = 'Limestone/LiBoBasic';
        else if (gs === 'bindstone') returnpair = 'Limestone/LiBoBasic';
        else if (gs === 'boundstone') returnpair = 'Limestone/LiBoBasic';
        else if (gs === 'floatstone') returnpair = 'Limestone/LiFloBasic';
        else if (gs === 'framestone') returnpair = 'Limestone/LiBoBasic';
        else if (gs === 'grainstone') returnpair = 'Limestone/LiGrBasic';
        else if (gs === 'mudstone') returnpair = 'Limestone/LiMudBasic';
        else if (gs === 'packstone') returnpair = 'Limestone/LiPaBasic';
        else if (gs === 'rudstone') returnpair = 'Limestone/LiRudBasic';
        else if (gs === 'wackestone') returnpair = 'Limestone/LiWaBasic';
      } else if (pl === 'dolomite') {
        if (gs === 'bafflestone') returnpair = 'Dolomite/DoBoBasic';
        else if (gs === 'bindstone') returnpair = 'Dolomite/DoBoBasic';
        else if (gs === 'boundstone') returnpair = 'Dolomite/DoBoBasic';
        else if (gs === 'floatstone') returnpair = 'Dolomite/DoFloBasic';
        else if (gs === 'framestone') returnpair = 'Dolomite/DoBoBasic';
        else if (gs === 'grainstone') returnpair = 'Dolomite/DoGrBasic';
        else if (gs === 'mudstone') returnpair = 'Dolomite/DoMudBasic';
        else if (gs === 'packstone') returnpair = 'Dolomite/DoPaBasic';
        else if (gs === 'rudstone') returnpair = 'Dolomite/DoRudBasic';
        else if (gs === 'wackestone') returnpair = 'Dolomite/DoWaBasic';
      } else if (gs === 'evaporite') returnpair = 'Misc/EvaBasic';
      else if (gs === 'chert') returnpair = 'Basic/ChertBasic';
      else if (gs === 'phosphatic') returnpair = 'Misc/PhosBasic';
      else if (gs === 'volcaniclastic') returnpair = 'Misc/VolBasic';
    }

    return returnpair || 'Blank/Blank';
  }

  // ─── Geometry Helpers ────────────────────────────────────────────────
  function computeCentroid(coords) {
    if (!coords || coords.length === 0) return [0, 0];
    var xSum = 0, ySum = 0, count = 0;
    for (var i = 0; i < coords.length; i++) {
      if (Array.isArray(coords[i])) {
        xSum += coords[i][0];
        ySum += coords[i][1];
        count++;
      }
    }
    return count > 0 ? [xSum / count, ySum / count] : [0, 0];
  }

  function computeCentroidLine(coords) {
    return computeCentroid(coords);
  }

  // ─── Zoom / Pan ──────────────────────────────────────────────────────
  function setupZoom() {
    zoomBehavior = d3.zoom()
      .scaleExtent([0.3, 10])
      .on('zoom', function (event) {
        mainGroup.attr('transform', event.transform);
      });

    svg.call(zoomBehavior);

    // Zoom controls
    document.getElementById('zoom-in').addEventListener('click', function () {
      svg.transition().duration(300).call(zoomBehavior.scaleBy, 1.5);
    });
    document.getElementById('zoom-out').addEventListener('click', function () {
      svg.transition().duration(300).call(zoomBehavior.scaleBy, 0.67);
    });
    document.getElementById('zoom-reset').addEventListener('click', function () {
      svg.transition().duration(300).call(zoomBehavior.transform, d3.zoomIdentity);
    });
  }

  // ─── Detail Panel ────────────────────────────────────────────────────
  function setupDetailPanel() {
    var panel = document.getElementById('detail-panel');
    var closeBtn = document.getElementById('detail-panel-close');

    closeBtn.addEventListener('click', function () {
      panel.classList.remove('open');
      d3.selectAll('.strat-unit.selected').classed('selected', false);
    });

    // Click on units
    d3.selectAll('.strat-unit, .strat-line, .strat-point').on('click', function (event, d) {
      event.stopPropagation();
      d3.selectAll('.strat-unit.selected').classed('selected', false);
      d3.select(this).classed('selected', true);
      showDetailPanel(d);
    });

    // Click on background to close
    svg.on('click', function () {
      panel.classList.remove('open');
      d3.selectAll('.strat-unit.selected').classed('selected', false);
    });
  }

  function showDetailPanel(spot) {
    var panel = document.getElementById('detail-panel');
    var content = document.getElementById('detail-panel-content');
    var title = document.getElementById('detail-panel-title');

    var props = spot.properties || {};
    var sed = props.sed || {};

    title.textContent = props.name || 'Unit Details';

    var html = '';

    // General info
    html += '<div class="detail-section">';
    html += '<h4>General</h4>';
    if (props.id) html += detailRow('ID', props.id);
    if (props.name) html += detailRow('Name', props.name);
    if (props.strat_section_id) html += detailRow('Section ID', props.strat_section_id);
    html += '</div>';

    // Lithology
    if (sed.lithologies) {
      var liths = Array.isArray(sed.lithologies) ? sed.lithologies : [sed.lithologies];
      for (var i = 0; i < liths.length; i++) {
        var lith = liths[i];
        html += '<div class="detail-section">';
        html += '<h4>Lithology' + (liths.length > 1 ? ' ' + (i + 1) : '') + '</h4>';
        if (lith.primary_lithology) html += detailRow('Primary', formatLabel(lith.primary_lithology));
        if (lith.mud_silt_grain_size) html += detailRow('Mud/Silt', formatLabel(lith.mud_silt_grain_size));
        if (lith.sand_grain_size) html += detailRow('Sand', formatLabel(lith.sand_grain_size));
        if (lith.congl_grain_size) html += detailRow('Conglomerate', formatLabel(lith.congl_grain_size));
        if (lith.breccia_grain_size) html += detailRow('Breccia', formatLabel(lith.breccia_grain_size));
        if (lith.dunham_classification) html += detailRow('Dunham', formatLabel(lith.dunham_classification));
        if (lith.siliciclastic_type) html += detailRow('Siliciclastic Type', formatLabel(lith.siliciclastic_type));
        html += '</div>';
      }
    }

    // Interval / Thickness
    if (sed.interval) {
      html += '<div class="detail-section">';
      html += '<h4>Interval</h4>';
      if (sed.interval.interval_thickness != null) {
        html += detailRow('Thickness', sed.interval.interval_thickness + ' ' + (sed.interval.thickness_units || 'm'));
      }
      html += '</div>';
    }

    // Bedding
    if (sed.bedding) {
      html += '<div class="detail-section">';
      html += '<h4>Bedding</h4>';
      if (sed.bedding.lithology_at_bottom_contact) {
        html += detailRow('Bottom Contact', formatLabel(sed.bedding.lithology_at_bottom_contact));
      }
      html += '</div>';
    }

    content.innerHTML = html;
    panel.classList.add('open');
  }

  function detailRow(label, value) {
    return '<div class="detail-row"><span class="detail-label">' + escapeHtml(label) + '</span><span class="detail-value">' + escapeHtml(String(value)) + '</span></div>';
  }

  function formatLabel(str) {
    if (!str) return '';
    return str.replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
  }

  function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

  // ─── Export ──────────────────────────────────────────────────────────
  function setupExport() {
    document.getElementById('export-svg').addEventListener('click', exportSVG);
    document.getElementById('export-png').addEventListener('click', exportPNG);
  }

  function exportSVG() {
    var svgEl = document.querySelector('#strat-viewer svg');
    var clone = svgEl.cloneNode(true);

    // Reset any zoom transform on the main group
    var mg = clone.querySelector('.main-group');
    if (mg) mg.removeAttribute('transform');

    var serializer = new XMLSerializer();
    var source = '<?xml version="1.0" encoding="UTF-8"?>\n' + serializer.serializeToString(clone);

    var blob = new Blob([source], { type: 'image/svg+xml;charset=utf-8' });
    downloadBlob(blob, 'strat_section.svg');
  }

  function exportPNG() {
    var svgEl = document.querySelector('#strat-viewer svg');
    var clone = svgEl.cloneNode(true);

    var mg = clone.querySelector('.main-group');
    if (mg) mg.removeAttribute('transform');

    var serializer = new XMLSerializer();
    var source = serializer.serializeToString(clone);

    var canvas = document.createElement('canvas');
    canvas.width = svgWidth * 2;
    canvas.height = svgHeight * 2;
    var ctx = canvas.getContext('2d');
    ctx.scale(2, 2);

    var img = new Image();
    img.onload = function () {
      ctx.drawImage(img, 0, 0);
      canvas.toBlob(function (blob) {
        downloadBlob(blob, 'strat_section.png');
      }, 'image/png');
    };

    var svgBlob = new Blob([source], { type: 'image/svg+xml;charset=utf-8' });
    img.src = URL.createObjectURL(svgBlob);
  }

  function downloadBlob(blob, filename) {
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(a.href);
  }

  // ─── UI Helpers ──────────────────────────────────────────────────────
  function hideLoading() {
    var el = document.getElementById('strat-loading');
    if (el) el.style.display = 'none';
  }

  function showViewer() {
    var el = document.getElementById('strat-viewer-wrapper');
    if (el) el.style.display = 'block';
  }

  function showError(msg) {
    var el = document.getElementById('strat-error-state');
    var msgEl = document.getElementById('strat-error-message');
    if (el) el.style.display = 'block';
    if (msgEl) msgEl.textContent = msg;
  }

})();
