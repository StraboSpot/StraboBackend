/**
 * Download dropdown handler. Replicates the option → URL mapping from
 * my_field_data.php:doDownload() but always uses the project OWNER's userpkey
 * (resolved server-side and passed via DATASET_DETAIL_CONFIG). This lets any
 * viewer download the dataset regardless of whether they own it — option (c)
 * from the planning discussion.
 *
 * "Landing Page" is intentionally omitted because this *is* the landing page.
 */
(function (global) {
	'use strict';

	var cfg = global.DATASET_DETAIL_CONFIG || {};
	var ownerPkey = cfg.owner_pkey;
	var datasetId = cfg.dataset_id;

	var select = document.getElementById('ddh-download-select');
	if (!select || !datasetId || !ownerPkey) return;

	select.addEventListener('change', function () {
		var choice = select.value;
		if (!choice) return;

		// Reset the dropdown so the same option can be chosen twice in a row.
		select.value = '';

		var url = urlFor(choice);
		if (!url) return;
		if (choice === 'fieldbook' || choice === 'fieldbookdev') {
			window.open(url, '_blank');
		} else {
			window.location = url;
		}
	});

	function urlFor(choice) {
		var up = encodeURIComponent(ownerPkey);
		var id = encodeURIComponent(datasetId);
		switch (choice) {
			case 'shapefile':
				return '/chooseshapefile?type=shapefiledev&userpkey=' + up + '&dsids=' + id;
			case 'kml':
				return '/searchdownload?type=kml&userpkey=' + up + '&dsids=' + id;
			case 'xls':
				return '/searchdownload?type=xls&userpkey=' + up + '&dsids=' + id;
			case 'stereonet':
				return '/searchdownload?type=stereonet&userpkey=' + up + '&dsids=' + id;
			case 'fieldbook':
				return '/searchdownload?type=fieldbook&userpkey=' + up + '&dsids=' + id;
			case 'fieldbookdev':
				return '/searchdownload?type=fieldbookdev&userpkey=' + up + '&dsids=' + id;
			case 'strat_sections':
				return '/dataset_strat_sections?dataset_id=' + id;
			case 'download_images':
				return '/searchdownload?type=download_images&userpkey=' + up + '&dsids=' + id;
			case 'sample_list':
				return '/searchdownload?type=sample_list&userpkey=' + up + '&dsids=' + id;
			case 'dev_sample_list':
				return '/searchdownload?type=dev_sample_list&userpkey=' + up + '&dsids=' + id;
			case 'gpkg':
				return '/searchdownload?type=gpkg&userpkey=' + up + '&dsids=' + id;
			case 'geojson':
				return '/searchdownload?type=geojson&userpkey=' + up + '&dsids=' + id;
			case 'gems':
				return '/gems_export?dsids=' + id;
			case 'image_basemaps':
				return '/image_basemaps?dataset_id=' + id;
		}
		return null;
	}
})(window);
