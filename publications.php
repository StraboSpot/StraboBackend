<?php
/**
 * File: publications.php
 * Description: Publications - Searchable, paginated list of StraboSpot publications
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("includes/mheader.php");

$publications = array();
$csvFile = __DIR__ . '/data/publications.csv';
$expectedHeaders = array('Authors','Title','Publication','Volume','Number','Pages','Year','Publisher','URL','DOI');

if(file_exists($csvFile)){
	$fh = fopen($csvFile, 'r');
	if($fh){
		$headers = fgetcsv($fh);
		if($headers){
			// Strip BOM from first header if present (defensive — file is normalized on upload)
			if(isset($headers[0])) $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
			while(($row = fgetcsv($fh)) !== false){
				while(count($row) < count($headers)) $row[] = '';
				$assoc = @array_combine($headers, array_slice($row, 0, count($headers)));
				if($assoc) $publications[] = $assoc;
			}
		}
		fclose($fh);
	}
}
?>

<style>
	.pub-controls {
		display: flex;
		flex-wrap: wrap;
		gap: 1em;
		margin: 0 auto 1.5em auto;
		max-width: 640px;
		justify-content: center;
		align-items: center;
	}
	.pub-controls input[type="text"],
	.pub-controls select {
		background: rgba(255, 255, 255, 0.08);
		border: 1px solid rgba(255, 255, 255, 0.2);
		border-radius: 4px;
		color: #ffffff;
		padding: 0.6em 0.8em;
		font-size: 1em;
		box-sizing: border-box;
	}
	.pub-controls input[type="text"]:focus,
	.pub-controls select:focus {
		border-color: #e44c65;
		outline: none;
	}
	.pub-controls input[type="text"] {
		flex: 1 1 320px;
		min-width: 220px;
		max-width: 380px;
	}
	.pub-controls select {
		flex: 0 0 auto;
	}
	.pub-controls select option {
		background: #2a2a3a;
		color: #ffffff;
	}

	.pub-result-count {
		color: rgba(255, 255, 255, 0.6);
		font-size: 0.95em;
		margin-bottom: 1em;
		text-align: center;
	}

	.pub-card {
		position: relative;
		display: flex;
		gap: 1.25em;
		background: #ffffff;
		border: 1px solid rgba(0, 0, 0, 0.08);
		border-radius: 6px;
		padding: 1.5em 1.75em;
		margin-bottom: 1em;
		overflow: hidden;
	}

	.pub-card::after {
		content: '\201D';
		position: absolute;
		top: -0.45em;
		right: 0.15em;
		font-size: 9em;
		line-height: 1;
		color: rgba(0, 0, 0, 0.06);
		font-family: Georgia, "Times New Roman", serif;
		pointer-events: none;
		user-select: none;
	}

	.pub-icon {
		flex: 0 0 44px;
		width: 44px;
		height: 44px;
		border-radius: 50%;
		background: rgba(79, 168, 193, 0.12);
		border: 2px solid #4fa8c1;
		color: #4fa8c1;
		display: flex;
		align-items: center;
		justify-content: center;
		position: relative;
		z-index: 1;
	}
	.pub-icon svg {
		width: 20px;
		height: 20px;
	}

	.pub-body {
		flex: 1;
		min-width: 0;
		position: relative;
		z-index: 1;
	}

	.pub-title {
		color: #1a1a1a;
		font-weight: 700;
		font-size: 1.1em;
		margin-bottom: 0.4em;
		line-height: 1.35;
	}

	.pub-link {
		margin-bottom: 0.6em;
	}
	.pub-link a {
		color: #e44c65;
		font-size: 0.95em;
	}

	.pub-citation {
		color: #555555;
		font-size: 0.95em;
		line-height: 1.55;
	}

	.pub-pagination {
		display: flex;
		flex-wrap: wrap;
		gap: 0.35em;
		justify-content: center;
		margin-top: 1.5em;
	}
	.pub-page-btn {
		background: rgba(255, 255, 255, 0.06);
		border: 1px solid rgba(255, 255, 255, 0.15);
		color: rgba(255, 255, 255, 0.85);
		padding: 0.4em 0.8em;
		border-radius: 3px;
		cursor: pointer;
		font-size: 0.9em;
		min-width: 2.4em;
	}
	.pub-page-btn:hover:not(:disabled):not(.active) {
		background: rgba(255, 255, 255, 0.12);
	}
	.pub-page-btn.active {
		background: #e44c65;
		border-color: #e44c65;
		color: #fff;
		cursor: default;
	}
	.pub-page-btn:disabled {
		opacity: 0.35;
		cursor: not-allowed;
	}
	.pub-page-ellipsis {
		color: rgba(255, 255, 255, 0.5);
		padding: 0.4em 0.3em;
	}

	@media (max-width: 736px) {
		.pub-card {
			flex-direction: column;
			gap: 0.75em;
		}
	}
</style>

<!-- Main -->
<div id="main" class="wrapper style1">
	<div class="container">

		<header class="major">
			<h2>Publications</h2>
		</header>

		<section class="micro-section">
			<h2 class="exp-section-title" style="font-size: 1.6em;">StraboSpot Spotlight</h2>
			<p style="color: rgba(255, 255, 255, 0.85); font-size: 1.05em; line-height: 1.7; margin-bottom: 2em;">
				Peer-reviewed papers, conference abstracts, and other publications that feature
				or build on the StraboSpot data system. Use the search box to filter by author,
				title, journal, or year.
			</p>
		</section>

		<div class="pub-controls">
			<input type="text" id="pubSearch" placeholder="Search authors, title, journal, year...">
			<select id="pubSort">
				<option value="year_desc">Sort: Year (newest first)</option>
				<option value="year_asc">Sort: Year (oldest first)</option>
				<option value="author_asc">Sort: First Author (A&ndash;Z)</option>
				<option value="title_asc">Sort: Title (A&ndash;Z)</option>
			</select>
		</div>

		<div id="pubResultCount" class="pub-result-count"></div>
		<div id="pubList"></div>
		<div id="pubPagination" class="pub-pagination"></div>

		<div class="bottomSpacer"></div>

	</div>
</div>

<script>
var pubData = <?php echo json_encode($publications, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
var pageSize = 25;
var currentPage = 1;
var filteredPubs = [];

function escHtml(s){
	var d = document.createElement('div');
	d.appendChild(document.createTextNode(s == null ? '' : String(s)));
	return d.innerHTML;
}

// "Walker, J Douglas; Tikoff, Basil; " → "Walker, J. D., Tikoff, B."
function formatAuthors(authors){
	if(!authors) return '';
	var entries = authors.split(';').map(function(s){ return s.trim(); }).filter(Boolean);
	return entries.map(function(entry){
		var idx = entry.indexOf(',');
		if(idx === -1) return entry;
		var surname = entry.substring(0, idx).trim();
		var given = entry.substring(idx + 1).trim();
		if(!given) return surname;
		var initials = given.split(/\s+/).filter(Boolean).map(function(g){
			return g.charAt(0).toUpperCase() + '.';
		}).join(' ');
		return surname + ', ' + initials;
	}).join(', ');
}

function buildCitation(p){
	var parts = [];
	var authors = formatAuthors(p.Authors);
	if(authors) parts.push(authors);
	if(p.Year) parts.push(String(p.Year));
	var titlePub = '';
	if(p.Title) titlePub = p.Title;
	if(p.Publication){
		titlePub += (titlePub ? ': ' : '') + p.Publication;
	}
	if(titlePub) parts.push(titlePub);
	if(p.Volume) parts.push('v. ' + p.Volume);
	if(p.Number) parts.push('no. ' + p.Number);
	if(p.Pages) parts.push('p. ' + p.Pages);
	var s = parts.join(', ');
	if(p.Publisher) s += (s ? '. ' : '') + p.Publisher;
	if(s && s.charAt(s.length - 1) !== '.') s += '.';
	return s;
}

function pubLink(p){
	var doi = (p.DOI || '').trim();
	if(doi){
		if(/^https?:/i.test(doi)) return doi;
		return 'https://doi.org/' + doi.replace(/^doi:\s*/i, '');
	}
	var url = (p.URL || '').trim();
	if(url){
		if(/^https?:/i.test(url)) return url;
		return 'https://' + url;
	}
	return null;
}

function firstAuthorSortKey(authors){
	if(!authors) return '';
	return authors.split(';')[0].trim().toLowerCase();
}

function applyFilter(){
	var term = $('#pubSearch').val().trim().toLowerCase();
	if(term === ''){
		filteredPubs = pubData.slice();
	} else {
		filteredPubs = pubData.filter(function(p){
			for(var k in p){
				if(p.hasOwnProperty(k) && (p[k] || '').toString().toLowerCase().indexOf(term) !== -1) return true;
			}
			return false;
		});
	}
	applySort();
}

function applySort(){
	var mode = $('#pubSort').val();
	filteredPubs.sort(function(a, b){
		if(mode === 'year_desc') return (parseInt(b.Year, 10) || 0) - (parseInt(a.Year, 10) || 0);
		if(mode === 'year_asc')  return (parseInt(a.Year, 10) || 0) - (parseInt(b.Year, 10) || 0);
		if(mode === 'author_asc') return firstAuthorSortKey(a.Authors).localeCompare(firstAuthorSortKey(b.Authors));
		if(mode === 'title_asc')  return (a.Title || '').toLowerCase().localeCompare((b.Title || '').toLowerCase());
		return 0;
	});
	currentPage = 1;
	render();
}

function computePagesToShow(current, total){
	if(total <= 7){
		var arr = [];
		for(var i = 1; i <= total; i++) arr.push(i);
		return arr;
	}
	var keep = {};
	[1, total, current, current - 1, current + 1].forEach(function(p){
		if(p >= 1 && p <= total) keep[p] = true;
	});
	var sorted = Object.keys(keep).map(Number).sort(function(a, b){ return a - b; });
	var result = [];
	for(var i = 0; i < sorted.length; i++){
		if(i > 0 && sorted[i] - sorted[i-1] > 1) result.push('...');
		result.push(sorted[i]);
	}
	return result;
}

function render(){
	var total = filteredPubs.length;
	var totalPages = Math.max(1, Math.ceil(total / pageSize));
	if(currentPage > totalPages) currentPage = totalPages;
	var start = (currentPage - 1) * pageSize;
	var end = Math.min(total, start + pageSize);

	if(total === 0){
		$('#pubResultCount').text('No publications match your search.');
	} else {
		$('#pubResultCount').text('Showing ' + (start + 1) + '–' + end + ' of ' + total);
	}

	var iconSvg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="14" y2="17"/></svg>';
	var html = '';
	for(var i = start; i < end; i++){
		var p = filteredPubs[i];
		html += '<div class="pub-card">';
		html += '<div class="pub-icon">' + iconSvg + '</div>';
		html += '<div class="pub-body">';
		html += '<div class="pub-title">' + escHtml(p.Title || '(Untitled)') + '</div>';
		var link = pubLink(p);
		if(link){
			html += '<div class="pub-link"><a href="' + escHtml(link) + '" target="_blank" rel="noopener">Link to Publication</a></div>';
		}
		html += '<div class="pub-citation">' + escHtml(buildCitation(p)) + '</div>';
		html += '</div></div>';
	}
	$('#pubList').html(html);

	var pagHtml = '';
	if(totalPages > 1){
		pagHtml += '<button type="button" class="pub-page-btn" data-page="prev"' + (currentPage === 1 ? ' disabled' : '') + '>&laquo; Prev</button>';
		var pages = computePagesToShow(currentPage, totalPages);
		for(var j = 0; j < pages.length; j++){
			var pg = pages[j];
			if(pg === '...'){
				pagHtml += '<span class="pub-page-ellipsis">&hellip;</span>';
			} else {
				pagHtml += '<button type="button" class="pub-page-btn' + (pg === currentPage ? ' active' : '') + '" data-page="' + pg + '">' + pg + '</button>';
			}
		}
		pagHtml += '<button type="button" class="pub-page-btn" data-page="next"' + (currentPage === totalPages ? ' disabled' : '') + '>Next &raquo;</button>';
	}
	$('#pubPagination').html(pagHtml);
}

$(function(){
	$('#pubSearch').on('input', applyFilter);
	$('#pubSort').on('change', applySort);
	$(document).on('click', '.pub-page-btn', function(){
		if($(this).is(':disabled')) return;
		var p = $(this).data('page');
		var totalPages = Math.max(1, Math.ceil(filteredPubs.length / pageSize));
		if(p === 'prev')      currentPage = Math.max(1, currentPage - 1);
		else if(p === 'next') currentPage = Math.min(totalPages, currentPage + 1);
		else                  currentPage = parseInt(p, 10);
		render();
		$('html, body').animate({ scrollTop: $('.pub-controls').offset().top - 80 }, 200);
	});
	applyFilter();
});
</script>

<?php
include("includes/mfooter.php");
?>
