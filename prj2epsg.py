#! /usr/bin/env python3

import sys
from osgeo import osr

def esriprj2standards(shapeprj_path):
	prj_file = open(shapeprj_path, 'r')
	prj_txt = prj_file.read()
	srs = osr.SpatialReference()
	srs.ImportFromESRI([prj_txt])
	#print 'Shape prj is: %s' % prj_txt
	#print 'WKT is: %s' % srs.ExportToWkt()
	#print 'Proj4 is: %s' % srs.ExportToProj4()
	srs.AutoIdentifyEPSG()
	#print 'EPSG is: %s' % srs.GetAuthorityCode(None)
	#print '%s' % srs.GetAuthorityCode(None)
	#str(srs.GetAuthorityCode(None))
	code = srs.GetAuthorityCode(None)
	if code is None:
		# GDAL 3.10 (PHP 8.4 image) no longer auto-identifies ESRI .prj files
		# (e.g. GCS_WGS_1984); ask PROJ for its best match instead
		for match, confidence in (srs.FindMatches() or []):
			if confidence >= 70 and match.GetAuthorityCode(None):
				code = match.GetAuthorityCode(None)
				break
	print(code)

esriprj2standards(sys.argv[1])