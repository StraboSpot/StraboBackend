<?php
/**
 * File: straboOutputClass.php
 * Description: straboOutputClass class
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


class straboOutputClass
{

	 public $holdings = [];

	 public function addHolding($row, $col, $val){
		 if(is_array($val)){
			 $this->holdings[$row][$col] = implode(", ", $val);
		 }else{
			 $this->holdings[$row][$col] = $val;
		 }
	 }

	 public function straboOutputClass($strabo,$get){
		$this->strabo=$strabo;
		$this->get=$get;

		//Build columns for XLS output.
		$this->cols = [];
		for($x=65; $x<91; $x++){
			$this->cols[] = chr($x);
		}
		for($x=65; $x<91; $x++){
			for($y=65; $y<91; $y++){
				$this->cols[] = chr($x).chr($y);
			}
		}
	 }

	public function dumpVar($var){
		echo "<pre>";
		print_r($var);
		echo "</pre>";
	}

	/**
	 * Explode a multi-orientation spot into multiple features, one per orientation.
	 * Mirrors the mobile app's getSpotsAsFeatures() logic: each orientation (including
	 * associated orientations) becomes a separate feature at the same geometry.
	 * Each feature gets that orientation's data mapped to the primary po/lo/to columns.
	 *
	 * @param array $spot A spot that has already been through fixSpot() with orientation_data preserved
	 * @return array Array of spot features (one per orientation, or just the original if single/no orientation)
	 */
	public function explodeOrientations($spot){
		// Parse the raw orientation_data JSON if available
		$odJson = $spot['properties']['orientation_data'] ?? null;
		if(!$odJson || $odJson == ''){
			return [$spot];
		}

		$orientations = json_decode($odJson);
		if(!is_array($orientations) || count($orientations) <= 1){
			// Single or no orientation — check for associated_orientation on the single entry
			if(count($orientations) == 1 && isset($orientations[0]->associated_orientation) && !empty($orientations[0]->associated_orientation)){
				// Has associated orientations — fall through to explosion logic
			}else{
				return [$spot];
			}
		}

		// Collect all individual orientations (including associated ones)
		$allOrientations = [];
		foreach($orientations as $or){
			if(isset($or->associated_orientation) && !empty($or->associated_orientation)){
				foreach($or->associated_orientation as $assoc){
					$allOrientations[] = $assoc;
				}
			}
			$allOrientations[] = $or;
		}

		// Strip all orientation columns from the base properties
		$baseProps = [];
		foreach($spot['properties'] as $key => $value){
			if(!preg_match('/^(po_|lo_|to_)\d*/', $key) && $key !== 'orientation_data'){
				$baseProps[$key] = $value;
			}
		}

		// Create one feature per orientation
		$features = [];
		foreach($allOrientations as $or){
			$feature = $spot;
			$feature['properties'] = $baseProps;

			// Flatten this single orientation into primary columns
			$type = $or->type ?? '';
			$prefix = 'po_';
			if($type == 'linear_orientation') $prefix = 'lo_';
			if($type == 'tabular_orientation') $prefix = 'to_';

			foreach($or as $key => $value){
				if($key != 'type' && $key != 'id' && $key != 'associated_orientation'){
					$feature['properties'][$prefix . $key] = $value;
				}
			}

			$features[] = $feature;
		}

		return $features;
	}

	public function gatherOrientations($orientations){

		$this->neworientations = array();
		$this->planar_orientation_num = 0;
		$this->linear_orientation_num = 0;
		$this->tabular_zone_orientation_num = 0;

		$returnorientations = $this->buildOrientations($orientations);
		return $returnorientations;

	}

	public function buildOrientations($orientations){

		foreach($orientations as $or){

			$type = $or->type;

			$prefix = "foo";

			if($type=="planar_orientation"){
				$this->planar_orientation_num++;
				if($this->planar_orientation_num==1){
					$prefix="po_";
				}else{
					$prefix="po_".$this->planar_orientation_num."_";
				}
			}

			if($type=="linear_orientation"){
				$this->linear_orientation_num++;
				if($this->linear_orientation_num==1){
					$prefix="lo_";
				}else{
					$prefix="lo_".$this->linear_orientation_num."_";
				}
			}

			if($type=="tabular_orientation"){
				$this->tabular_zone_orientation_num++;
				if($this->tabular_zone_orientation_num==1){
					$prefix="to_";
				}else{
					$prefix="to_".$this->tabular_zone_orientation_num."_";
				}
			}

			foreach($or as $key=>$value){

				if($key != "type" && $key != "id" && $key != "associated_orientation"){

					$this->neworientations[$prefix.$key]=$value;

				}

			}

			if($or->associated_orientation){
				$this->buildOrientations($or->associated_orientation);
			}

		}

		return $this->neworientations;

	}

	public function buildStructures($structures){

		foreach($structures as $struct){

			$type = $struct->type;

			$prefix = "foo";

			if($type=="fabric"){
				$this->fabric_num++;
				if($this->fabric_num==1){
					$prefix="fab_";
				}else{
					$prefix="fab_".$this->fabric_num."_";
				}
			}

			if($type=="fold"){
				$this->fold_num++;
				if($this->fold_num==1){
					$prefix="fold_";
				}else{
					$prefix="fold_".$this->fold_num."_";
				}
			}

			if($type=="tensor"){
				$this->tensor_num++;
				if($this->tensor_num==1){
					$prefix="tns_";
				}else{
					$prefix="tns_".$this->tensor_num."_";
				}
			}

			if($type=="other"){
				$this->other_num++;
				if($this->other_num==1){
					$prefix="_3d_";
				}else{
					$prefix="_3d_".$this->other_num."_";
				}
			}

			foreach($struct as $key=>$value){

				if($key != "type" && $key != "id" && $key != "associated_orientation"){

					$this->newstructures[$prefix.$key]=$value;

				}

			}
		}

		return $newstructures;

	}

	public function gatherSamples($samples){

		$this->newsamples = array();
		$this->sample_num = 0;

		$returnsamples = $this->buildSamples($samples);

		return $returnsamples;

	}

	public function buildSamples($samples){

		foreach($samples as $samp){

			$prefix = "foo";

			$sample_num++;
			if($sample_num==1){
				$prefix="samp_";
			}else{
				$prefix="samp_".$sample_num."_";
			}

			foreach($samp as $key=>$value){

				if($key != "id"){

					$this->newsamples[$prefix.$key]=$value;

				}
			}
		}

		return $newsamples;

	}

	public function gatherTephras($tephras){

		$this->newtephras = array();
		$this->tephra_num = 0;

		$returntephras = $this->buildTephras($tephras);

		return $returntephras;

	}

	public function buildTephras($tephras){

		foreach($tephras as $tephra){

			$prefix = "foo";

			$tephra_num++;
			if($tephra_num==1){
				$prefix="tephra_";
			}else{
				$prefix="tephra_".$tephra_num."_";
			}

			foreach($tephra as $key=>$value){

				if($key != "id"){

					$rawvalue = $value;
					if(is_array($rawvalue)){
						$showval = implode(", ", $rawvalue);
					}else{
						$showval = $rawvalue;
					}

					$this->newtephras[$prefix.$key]=$showval;

				}
			}
		}

		return $this->newtephras;

	}

	public function gatherOtherFeatures($otherfeatures){

		$this->newotherfeatures = array();
		$this->otherfeature_num = 0;

		$returnotherfeatures = $this->buildOtherFeatures($otherfeatures);

		return $returnotherfeatures;

	}

	public function gatherStructures($structures){

		$this->newstructures = array();
		$this->fabric_num = 0;
		$this->fold_num = 0;
		$this->tensor_num = 0;
		$this->other_num = 0;

		$returnstructures = $this->buildStructures($structures);

		return $returnstructures;

	}

	public function buildOtherFeatures($otherfeatures){

		foreach($otherfeatures as $of){

			$otherfeature_num++;
			if($otherfeature_num==1){
				$prefix="of_";
			}else{
				$prefix="of_".$otherfeature_num."_";
			}

			foreach($of as $key=>$value){

				if($key != "id"){

					$newotherfeatures[$prefix.$key]=$value;

				}
			}
		}

		return $newotherfeatures;

	}

	public function fixSpot($spot, $preserveOrientationJson = false){

		$id = $spot['properties']['id'];

		unset($spot['original_geometry']);

		// For GeoPackage export: preserve orientation_data as a JSON string
		// so QGIS QML GeometryGenerator can parse it for orientation arrows
		if($preserveOrientationJson && $spot['properties']['orientation_data']){
			$rawOrientationJson = json_encode($spot['properties']['orientation_data']);
		}

		if($spot['properties']['orientation_data']){
			$orientations = $this->gatherOrientations($spot['properties']['orientation_data']);
			foreach($orientations as $key=>$value){
				$spot['properties'][$key]=$value;
			}
		}

		if($spot['properties']['_3d_structures']){
			$structures = $this->gatherStructures($spot['properties']['_3d_structures']);
			foreach($structures as $key=>$value){
				$spot['properties'][$key]=$value;
			}
		}

		if($spot['properties']['samples']){
			$samples = $this->gatherSamples($spot['properties']['samples']);
			foreach($samples as $key=>$value){
				$spot['properties'][$key]=$value;
			}
		}

		if($spot['properties']['other_features']){
			$otherfeatures = $this->gatherOtherFeatures($spot['properties']['other_features']);
			foreach($otherfeatures as $key=>$value){
				$spot['properties'][$key]=$value;
			}
		}

		//surface feature
		if($spot['properties']['surface_feature']){
			foreach($spot['properties']['surface_feature'] as $key=>$value){
				$spot['properties']['sf_'.$key]=$value;
			}
			unset($spot['properties']['surface_feature']);
		}

		//trace
		if($spot['properties']['trace']){
			foreach($spot['properties']['trace'] as $key=>$value){
				if($key != "id" && $key != "date" ){
					$spot['properties']['tr_'.$key]=$value;
				}
			}
			unset($spot['properties']['trace']);
		}

		if($spot['properties']['tephra']){
			$tephras = $this->gatherTephras($spot['properties']['tephra']);
			foreach($tephras as $key=>$value){
				$spot['properties'][$key]=$value;
			}
			unset($spot['properties']['tephra']);
		}

		//rock units
		if($this->alltags){
			foreach($this->alltags as $tag){
				$found = "no";
				if($tag->spots){
					foreach($tag->spots as $spotid){
						if($spotid == $id){
							$found = "yes";
						}
					}
				}

				if($found == "yes"){
					if($tag->type=="geologic_unit"){
						foreach($tag as $key=>$value){
							if($key != "date" && $key != "spots" && $key != "features" && $key != "id" ){
								$spot['properties']['ru_'.$key]=$value;
							}
						}
					}
				}
			}
		}

		$rocknames = [];
		if($this->alltags){
			foreach($this->alltags as $tag){
				$found = "no";
				if($tag->spots){
					foreach($tag->spots as $spotid){
						if($spotid == $id){
							$found = "yes";
						}
					}
				}

				if($found == "yes"){
					if($tag->type=="geologic_unit"){
						$rocknames[] = $tag->name;
					}
				}
			}
		}

		$lithnum = 1;
		foreach($rocknames as $rockname){
			$spot['properties']['lith'.$lithnum] = $rockname;
			$lithnum++;
		}

		if($spot['properties']['custom_fields']){
			foreach($spot['properties']['custom_fields'] as $key=>$value){
				if($key != "idaaaaa" && $key != "dateaaaaa" ){
					$spot['properties']['cust_'.$key]=$value;
				}
			}
			unset($spot['properties']['custom_fields']);
		}

		$images = "";
		$imagesdelim = "";
		if($spot['properties']['images']){
			foreach($spot['properties']['images'] as $image){
				$images.=$imagesdelim."https://strabospot.org/pi/".$image['id'];
				$imagesdelim=";";
			}
		}

		unset($spot['properties']['images']);

		if($images!=""){
			$spot['properties']['images'] = $images;
		}

		unset($spot['properties']['date']);
		unset($spot['properties']['time']);
		unset($spot['properties']['id']);
		unset($spot['properties']['self']);
		unset($spot['properties']['modified_timestamp']);
		unset($spot['properties']['samples']);
		unset($spot['properties']['_3d_structures']);
		unset($spot['properties']['geometrytype']);
		if($preserveOrientationJson && isset($rawOrientationJson)){
			$spot['properties']['orientation_data'] = $rawOrientationJson;
		}else{
			unset($spot['properties']['orientation_data']);
		}
		unset($spot['properties']['other_features']);

		unset($spot['properties']['image_basemap']);

		$spot['properties']['spot_name']=$spot['properties']['name'];

		unset($spot['properties']['name']);

		return $spot;
	}

	public function rowcol($row,$col){

		$colletter = $this->cols[$col];
		$row=$row+1;
		return $colletter.$row;
	}

	public function fix_column_name($name){
		$newstring="";
		$delim="";
		$parts = explode("_",$name);
		foreach($parts as $part){
			$part = ucfirst($part);
			$newstring.=$delim.$part;
			$delim=" ";
		}

		if($name=="id"){$newstring="ID";}

		return $newstring;
	}

	public function shapefile_fix_column_name($name){
		$newstring="";
		$newstring = strtolower($name);
		$newstring = str_replace(" ", "_", $newstring);
		return $newstring;
	}

	public function fixLabel($label){
		$returnlabel = "";
		$delim = "";
		$labels = explode("_",$label);
		foreach($labels as $label){
			$label = ucfirst($label);
			$returnlabel.=$delim.$label;
			$delim=" ";
		}
		$returnlabel = trim($returnlabel);
		return $returnlabel;
	}

	public function gdThumb($filename){

		if(file_exists("/srv/app/www/dbimages/$filename")){

			$thumbwidth = 300;

			$src = imagecreatefromjpeg("/srv/app/www/dbimages/$filename");
			list($origwidth, $origheight) = getimagesize("/srv/app/www/dbimages/$filename");

			$ratio = $origheight / $origwidth;

			$thumbheight = round($thumbwidth * $ratio);

			$tmp = imagecreatetruecolor($thumbwidth, $thumbheight);

			$filename = '/path/to/images/' . $_FILES['file']['name'];

			imagecopyresampled($tmp, $src, 0, 0, 0, 0, $thumbwidth, $thumbheight, $origwidth, $origheight);

			return $tmp;

		}else{

			return null;

		}
	}

	public function imagettfstroketext(&$image, $size, $angle, $x, $y, &$textcolor, &$strokecolor, $fontfile, $text, $px) {
		for($c1 = ($x-abs($px)); $c1 <= ($x+abs($px)); $c1++)
			for($c2 = ($y-abs($px)); $c2 <= ($y+abs($px)); $c2++)
				$bg = imagettftext($image, $size, $angle, $c1, $c2, $strokecolor, $fontfile, $text);
	   return imagettftext($image, $size, $angle, $x, $y, $textcolor, $fontfile, $text);
	}

	public function calculateTextBox($font_size, $font_angle, $font_file, $text) {

		$box = imagettfbbox($font_size, $font_angle, $font_file, $text);

		if( !$box ) return false;

		$min_x = min( array($box[0], $box[2], $box[4], $box[6]) );
		$max_x = max( array($box[0], $box[2], $box[4], $box[6]) );
		$min_y = min( array($box[1], $box[3], $box[5], $box[7]) );
		$max_y = max( array($box[1], $box[3], $box[5], $box[7]) );

		$width = ( $max_x - $min_x );
		$height = ( $max_y - $min_y );

		$left = abs( $min_x ) + $width;
		$top = abs( $min_y ) + $height;

		// to calculate the exact bounding box i write the text in a large image

		$img = @imagecreatetruecolor( $width << 2, $height << 2 );
		$white = imagecolorallocate( $img, 255, 255, 255 );
		$black = imagecolorallocate( $img, 0, 0, 0 );
		imagefilledrectangle($img, 0, 0, imagesx($img), imagesy($img), $black);

		// for sure the text is completely in the image!
		imagettftext( $img, $font_size, $font_angle, $left, $top, $white, $font_file, $text);

		$rleft = $w4 = $width<<2;
		$rright = 0;
		$rbottom = 0;
		$rtop = $h4 = $height<<2;

		for( $x = 0; $x < $w4; $x++ )
			for( $y = 0; $y < $h4; $y++ )
				if( imagecolorat( $img, $x, $y ) ){
					$rleft = min( $rleft, $x );
					$rright = max( $rright, $x );
					$rtop = min( $rtop, $y );
					$rbottom = max( $rbottom, $y );
				}

		// destroy img and serve the result
		imagedestroy( $img );

		return array( "left" => $left - $rleft, "top" => $top - $rtop, "width" => $rright - $rleft + 1, "height" => $rbottom - $rtop + 1 );

	}

	public function gdThumbWithSpots($filename, $imageid, $spots){

		if(file_exists("/srv/app/www/dbimages/$filename")){

			$thumbwidth = 900;

			$src = imagecreatefromjpeg("/srv/app/www/dbimages/$filename");

			$polyfillcolor = imagecolorallocatealpha($src, 129, 124, 215, 25);
			$polylinecolor = imagecolorallocatealpha($src, 0, 0, 0, 0);
			$white = imagecolorallocate($src, 255, 255, 255);
			$black = imagecolorallocate($src, 0, 0, 0);
			$font = $_SERVER['DOCUMENT_ROOT']."/includes/arial.ttf";
			$fontsize = 30;

			list($filewidth, $fileheight) = getimagesize("/srv/app/www/dbimages/$filename");

			foreach($spots as $spot){
				foreach($spot['properties']['images'] as $image){
					if($image['id'] == $imageid){
						$straboimagewidth = $image['width'];
						$straboimageheight = $image['height'];
					}
				}
			}

			if($straboimagewidth == "" || $straboimageheight == ""){

				return null;

			}else{

				$drawratio = $filewidth / $straboimagewidth;

				//paint spots and labels here
				//origwidth: 2000 origheight: 1500

				foreach($spots as $spot){

					imagesetthickness($src, 2);

					if($spot['properties']['image_basemap'] == $imageid){

						$spotid = $spot['properties']['id'];

						if($spot['original_geometry']->type == "Polygon"){

							$numpoints = 0;
							$drawpoints = array();

							$minx = 99999;
							$maxx = -99999;
							$miny = 99999;
							$maxy = -99999;

							foreach($spot['original_geometry']->coordinates[0] as $coord){
								$numpoints ++;
								$x = $coord[0] * $drawratio;
								$y = $fileheight - ($coord[1] * $drawratio);

								if($x < $minx) $minx = $x;
								if($x > $maxx) $maxx = $x;
								if($y < $miny) $miny = $y;
								if($y > $maxy) $maxy = $y;

								$drawpoints[] = $x;
								$drawpoints[] = $y;

							}

							$midx = (($maxx - $minx) / 2) + $minx;
							$midy = ((($maxy - $miny) / 2) + $miny);

							//figure out color here
							$thispolycolor = "";

							//first check for tag
							foreach($this->alltags as $tag){
								foreach($tag->spots as $tagspotid){
									if($tagspotid == $spotid){
										if($tag->color != ""){
											list($r, $g, $b) = sscanf($tag->color, "#%02x%02x%02x");
											$thispolycolor = imagecolorallocatealpha($src, $r, $g, $b, 55);
										}
									}
								}
							}

							if($thispolycolor == ""){
								if($spot['properties']['surface_feature']->surface_feature_type == "rock_unit"){
									$thispolycolor = imagecolorallocatealpha($src, 0, 255, 255, 55);
								}elseif($spot['properties']['surface_feature']->surface_feature_type == "contiguous_outcrop"){
									$thispolycolor = imagecolorallocatealpha($src, 240, 128, 128, 55);
								}elseif($spot['properties']['surface_feature']->surface_feature_type == "geologic_structure"){
									$thispolycolor = imagecolorallocatealpha($src, 0, 255, 255, 55);
								}elseif($spot['properties']['surface_feature']->surface_feature_type == "geomorphic_feature"){
									$thispolycolor = imagecolorallocatealpha($src, 0, 128, 0, 55);
								}elseif($spot['properties']['surface_feature']->surface_feature_type == "anthropogenic_feature"){
									$thispolycolor = imagecolorallocatealpha($src, 128, 0, 128, 55);
								}elseif($spot['properties']['surface_feature']->surface_feature_type == "extent_of_mapping"){
									$thispolycolor = imagecolorallocatealpha($src, 128, 0, 128, 55);
								}elseif($spot['properties']['surface_feature']->surface_feature_type == "extent_of_biological_marker"){
									$thispolycolor = imagecolorallocatealpha($src, 0, 128, 0, 55);
								}elseif($spot['properties']['surface_feature']->surface_feature_type == "subjected_to_similar_process"){
									$thispolycolor = imagecolorallocatealpha($src, 255, 165, 0, 55);
								}elseif($spot['properties']['surface_feature']->surface_feature_type == "gradients"){
									$thispolycolor = imagecolorallocatealpha($src, 255, 165, 0, 55);
								}else{
									$thispolycolor = imagecolorallocatealpha($src, 129, 124, 215, 55);
								}

							}

							imagefilledpolygon($src, $drawpoints, $numpoints, $thispolycolor);
							imagepolygon($src, $drawpoints, $numpoints, $polylinecolor);

							$spotname = $spot['properties']['name'];

							$labelwidth = $this->calculateTextBox($fontsize, 0, $font, $spotname);
							$midx = $midx - ($labelwidth['width']/2);

							//Now Label
							$this->imagettfstroketext($src, $fontsize, 0, $midx, $midy, $white, $black, $font, $spotname, 3);

						}elseif($spot['original_geometry']->type == "Point"){

							$spotxcoord = $spot['original_geometry']->coordinates[0];
							$spotycoord = $spot['original_geometry']->coordinates[1];

							if($spot['properties']['orientation_data'] != "" && count($spot['properties']['orientation_data']) > 0 ){

								foreach($spot['properties']['orientation_data'] as $o){

									//symbolrotation
									if($o->dip != ""){
										$symbolorientation = $o->dip;
									}elseif($o->plunge != ""){
										$symbolorientation = $o->plunge;
									}else{
										$symbolorientation = 0;
									}

									if($o->facing == 'overturned' && $o->feature_type == "bedding"){
										$symbolfile = "bedding_overturned";
									}elseif($symbolorientation == 0 && ( $o->feature_type == "bedding" || $o->feature_type == "foliation" )){
										$symbolfile = $o->feature_type . "_horizontal";
									}elseif( ( $symbolorientation > 0 && $symbolorientation < 90 ) && ( $o->feature_type == "bedding" || $o->feature_type == "contact" || $o->feature_type == "foliation" || $o->feature_type == "shear_zone" ) ){
										$symbolfile = $o->feature_type . "_inclined";
									}elseif( ( $symbolorientation == 90 ) && ( $o->feature_type == "bedding" || $o->feature_type == "contact" || $o->feature_type == "foliation" || $o->feature_type == "shear_zone" ) ){
										$symbolfile = $o->feature_type . "_vertical";
									}elseif( $o->feature_type == "fault" || $o->feature_type == "fracture" || $o->feature_type == "vein" ){
										$symbolfile = $o->feature_type;
									}elseif($o->type == "linear_orientation"){
										$symbolfile = "lineation_general";
									}else{
										$symbolfile = "point";
									}

									//iconrotation
									if($o->strike != ""){
										$iconrotation = $o->strike;
									}elseif($o->dip_direction != ""){
										$iconrotation = 360 % (90 - $o->dip_direction);
									}elseif($o->trend != ""){
										$iconrotation = $o->trend;
									}else{
										$iconrotation = 0;
									}

									$spotimage = imagecreatefrompng("/srv/app/www/includes/symbology/strabo2/".$symbolfile.".png");

									imagesavealpha($spotimage , true);
									$pngTransparency = imagecolorallocatealpha($spotimage , 0, 0, 0, 127);
									imagefill($spotimage , 0, 0, $pngTransparency);

									// Rotate the canvas including the required transparent "color"
									$spotimage = imagerotate($spotimage, ($iconrotation * -1), $pngTransparency);

									$spotimagewidth = imagesx($spotimage);
									$spotimageheight = imagesy($spotimage);

									$x = ($spotxcoord * $drawratio) - 75;
									$y = $fileheight - ($spotycoord * $drawratio) - 75;

									imagecopyresampled($src, $spotimage, $x, $y, 0, 0, 150, 150, $spotimagewidth, $spotimageheight);
									imagedestroy($spotimage);

									//label
									if($o->plunge != null || $o->plunge === 0){
										$iconlabel = $o->plunge;
									}elseif($o->dip != null || $o->dip === 0){
										$iconlabel = $o->dip;
									}else{
										$iconlabel = $spot['properties']['name'];
									}

									if(($iconrotation >= 60 && $iconrotation <= 120) || ($iconrotation >= 240 && $iconrotation <= 300)){
										$labelx = ($spotxcoord * $drawratio) + 60;
										$labely = $fileheight - ($spotycoord * $drawratio) + 10;
									}else{
										$labelx = ($spotxcoord * $drawratio) + 20;
										$labely =  $fileheight - ($spotycoord * $drawratio) + 10;
									}

									$this->imagettfstroketext($src, $fontsize, 0, $labelx, $labely, $white, $black, $font, $iconlabel, 3);

								}

							}else{
								//Only draw point

								$spotimage = imagecreatefrompng("/srv/app/www/includes/symbology/strabo2/point.png");

								imagesavealpha($spotimage , true);
								$pngTransparency = imagecolorallocatealpha($spotimage , 0, 0, 0, 127);
								imagefill($spotimage , 0, 0, $pngTransparency);

								// Rotate the canvas including the required transparent "color"

								$spotimagewidth = imagesx($spotimage);
								$spotimageheight = imagesy($spotimage);

								$x = ($spotxcoord * $drawratio) - 75;
								$y = $fileheight - ($spotycoord * $drawratio) - 75;

								imagecopyresampled($src, $spotimage, $x, $y, 0, 0, 150, 150, $spotimagewidth, $spotimageheight);
								imagedestroy($spotimage);

								//label
								$iconlabel = $spot['properties']['name'];

								$labelx = ($spotxcoord * $drawratio) + 20;
								$labely =  $fileheight - ($spotycoord * $drawratio) + 10;

								$this->imagettfstroketext($src, $fontsize, 0, $labelx, $labely, $white, $black, $font, $iconlabel, 3);
							}

						}elseif($spot['original_geometry']->type == "LineString"){

	/*

	*/
							$thislinecolor = "";
							$thickness = 4;
							$thick = false;

							if($spot['properties']['trace']->trace_type == "geologic_struc"){
								$thislinecolor = imagecolorallocate($src, 255, 0, 0);
								if($spot['properties']['trace']->geologic_structure_type == "fault" || $spot['properties']['trace']->geologic_structure_type == "shear_zone" ){
									$thickness = 6;
									$thick = true;
								}
							}elseif($spot['properties']['trace']->trace_type == "contact"){
								$thislinecolor = imagecolorallocate($src, 0, 0, 0);
								if($spot['properties']['trace']->contact_type == "intrusive" && $spot['properties']['trace']->intrusive_contact_type == "dike" ){
									$thickness = 6;
									$thick = true;
								}
							}elseif($spot['properties']['trace']->trace_type == "geomorphic_fea"){
								$thislinecolor = imagecolorallocate($src, 0, 0, 255);
								$thickness = 6;
								$thick = true;
							}elseif($spot['properties']['trace']->trace_type == "anthropenic_fe"){
								$thislinecolor = imagecolorallocate($src, 128, 0, 128);
								$thickness = 6;
								$thick = true;
							}

							if($thislinecolor == ""){
								$thislinecolor = imagecolorallocate($src, 102, 51, 0); 
							}

							$style = $this->getDottedStyle($thislinecolor, $thick);

							if($spot['properties']['trace']->trace_quality == "known"){
								$style = array($thislinecolor, $thislinecolor);
							}elseif($spot['properties']['trace']->trace_quality == "approximate" || $spot['properties']['trace']->trace_quality == "approximate(?)"){
								$style = $this->getDashedStyle($thislinecolor, $thick);
							}elseif($spot['properties']['trace']->trace_quality == "known"){
								$style = $this->getDotDashedStyle($thislinecolor, $thick);
							}

							imagesetstyle($src, $style);

							$coords = $spot['original_geometry']->coordinates;
							$coordcount = count($coords);

							imagesetthickness($src, $thickness);

							for($c = 0; $c < $coordcount - 1; $c++){
								imageline($src, $coords[$c][0] * $drawratio, $fileheight - ($coords[$c][1] * $drawratio), $coords[$c + 1][0] * $drawratio, $fileheight - ($coords[$c + 1][1] * $drawratio), IMG_COLOR_STYLED);
							}

							$minx = 99999;
							$maxx = -99999;
							$miny = 99999;
							$maxy = -99999;

							foreach($coords as $coord){
								$x = $coord[0] * $drawratio;
								$y = $fileheight - ($coord[1] * $drawratio);

								if($x < $minx) $minx = $x;
								if($x > $maxx) $maxx = $x;
								if($y < $miny) $miny = $y;
								if($y > $maxy) $maxy = $y;

								$drawpoints[] = $x;
								$drawpoints[] = $y;

							}

							$midx = (($maxx - $minx) / 2) + $minx;
							$midy = ((($maxy - $miny) / 2) + $miny);

							$spotname = $spot['properties']['name'];

							$labelwidth = $this->calculateTextBox($fontsize, 0, $font, $spotname);
							$midx = $midx - ($labelwidth['width']/2);

							//Now Label
							$this->imagettfstroketext($src, $fontsize, 0, $midx, $midy, $white, $black, $font, $spotname, 3);

						}

						//Point spot below
						// Rotate the canvas including the required transparent "color"

					}
				}

				$ratio = $fileheight / $filewidth;

				$thumbheight = round($thumbwidth * $ratio);

				$tmp = imagecreatetruecolor($thumbwidth, $thumbheight);

				$filename = '/path/to/images/' . $_FILES['file']['name'];

				imagecopyresampled($tmp, $src, 0, 0, 0, 0, $thumbwidth, $thumbheight, $filewidth, $fileheight);

				imagedestroy($src);

				return $tmp;

			}

		}else{

			return null;

		}
	}

	public function getDottedStyle($thislinecolor, $thick){

		$style = array();

		if($thick == true){
			for($color = 0; $color < 20; $color++){
				$style[] = $thislinecolor;
			}

			for($n = 0; $n < 50; $n++){
				$style[] = IMG_COLOR_TRANSPARENT;
			}
		}else{
			for($color = 0; $color < 20; $color++){
				$style[] = $thislinecolor;
			}

			for($n = 0; $n < 50; $n++){
				$style[] = IMG_COLOR_TRANSPARENT;
			}

		}

		return $style;

	}

	public function getDashedStyle($thislinecolor, $thick){

		$style = array();

		if($thick == true){
			for($color = 0; $color < 88; $color++){
				$style[] = $thislinecolor;
			}

			for($n = 0; $n < 58; $n++){
				$style[] = IMG_COLOR_TRANSPARENT;
			}
		}else{
			for($color = 0; $color < 88; $color++){
				$style[] = $thislinecolor;
			}

			for($n = 0; $n < 58; $n++){
				$style[] = IMG_COLOR_TRANSPARENT;
			}

		}

		return $style;

	}

	public function getDotDashedStyle($thislinecolor, $thick){

		$style = array();

		if($thick == true){
			for($color = 0; $color < 88; $color++){
				$style[] = $thislinecolor;
			}

			for($n = 0; $n < 46; $n++){
				$style[] = IMG_COLOR_TRANSPARENT;
			}

			for($color = 0; $color < 25; $color++){
				$style[] = $thislinecolor;
			}

			for($n = 0; $n < 47; $n++){
				$style[] = IMG_COLOR_TRANSPARENT;
			}
		}else{
			for($color = 0; $color < 88; $color++){
				$style[] = $thislinecolor;
			}

			for($n = 0; $n < 26; $n++){
				$style[] = IMG_COLOR_TRANSPARENT;
			}

			for($color = 0; $color < 25; $color++){
				$style[] = $thislinecolor;
			}

			for($n = 0; $n < 27; $n++){
				$style[] = IMG_COLOR_TRANSPARENT;
			}

		}

		return $style;

	}

	public function fixFileName($filename){
		$filename = str_replace(" ", "_", $filename);
		$filename = preg_replace('/[^A-Za-z0-9\-_]/', '', $filename);
		return $filename;
	}

	public function oldxlsSampleList20250702(){

			if($this->get['dsids']!=""){

				$dsids=$this->get['dsids'];
				$this->alltags = $this->strabo->getTagsFromDatasetIds($dsids);

				$data = $this->strabo->getDatasetSpotsSearch(null,$this->get);

				if(count($data)==0){
				echo "no data found for dataset $id";exit();
				}

				//Gather all samples and show error if none found.

				$foundSamples = [];
				foreach($data['features'] as $feature){

					$samples = $feature['properties']['samples'];
					if($samples){
						foreach($samples as $sample){
							$sample->geometry = $feature['geometry'];
							$foundSamples[] = $sample;
						}
					}

					$x++;

				}

				if(count($foundSamples)==0){
				echo "No samples found for this dataset.";exit();
				}

				//Get Centroids
				$fixedSamples = [];
				foreach($foundSamples as $s){
					$mygeojson=$s->geometry;
					$mygeojson=trim(json_encode($mygeojson));
					try {
						$mywkt=geoPHP::load($mygeojson,"json");
						$centroid = $mywkt->centroid();
						$out = $centroid->out("json");
					} catch (Exception $e) {
						$centroid="";
						$out = "";
					}

					if($out != ""){
						$out = json_decode($out);
						$out = $out->coordinates;
					}

					$s->centroid = $out;
					$fixedSamples[] = $s;
				}

				/** PHPExcel */
				include 'PHPExcel.php';

				/** PHPExcel_Writer_Excel2007 */
				include 'PHPExcel/Writer/Excel2007.php';

				$objPHPExcel = new PHPExcel();

				// Set properties
				$objPHPExcel->getProperties()->setCreator("strabospot.org");
				$objPHPExcel->getProperties()->setLastModifiedBy("strabospot.org");
				$objPHPExcel->getProperties()->setTitle("StraboSpot.org Sample List");
				$objPHPExcel->getProperties()->setSubject("StraboSpot.org Sample List");
				$objPHPExcel->getProperties()->setDescription("StraboSpot.org Sample List");

				// Rename sheet
				$objPHPExcel->getActiveSheet()->setTitle('Samples');

				// Add some data
				$objPHPExcel->setActiveSheetIndex(0);

				$objPHPExcel->getActiveSheet()->SetCellValue('A1', "StraboSpot Sample List: $datasetname");

				$objPHPExcel->getActiveSheet()->SetCellValue('A3', "Sample ID");
				$objPHPExcel->getActiveSheet()->SetCellValue('B3', "Sample Type");
				$objPHPExcel->getActiveSheet()->SetCellValue('C3', "Label");
				$objPHPExcel->getActiveSheet()->SetCellValue('D3', "Longitude");
				$objPHPExcel->getActiveSheet()->SetCellValue('E3', "Latitude");
				$objPHPExcel->getActiveSheet()->SetCellValue('F3', "Sampling Purpose");
				$objPHPExcel->getActiveSheet()->SetCellValue('G3', "Sample Description");
				$objPHPExcel->getActiveSheet()->SetCellValue('H3', "Material Type");

				$rownum = 3;

				foreach($fixedSamples as $s){
					$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,0), $s->sample_id_name);
					$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,1), $s->sample_type);
					$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,2), $s->label);
					$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,3), $s->centroid[0]);
					$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,4), $s->centroid[1]);
					$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,5), $s->main_sampling_purpose);
					$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,6), $s->sample_description);
					$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,7), $s->material_type);
					$rownum ++;
				}

				// Save Excel 2007 file
				$objWriter = new PHPExcel_Writer_Excel2007($objPHPExcel);

				// We'll be outputting an excel file
				header('Content-type: application/vnd.ms-excel');

				$filedate = date("m_d_Y");

				// It will be called file.xls
				header('Content-Disposition: attachment; filename="'."StraboSpot_Samples_".$filedate.".xlsx".'"');

				// Write file to the browser
				$objWriter->save('php://output');

			}else{
				echo "Dataset not found.";exit();
			}

		}

	public function oldxlsSampleList20250925(){

			if($this->get['dsids']!=""){

				$dsids=$this->get['dsids'];
				$this->alltags = $this->strabo->getTagsFromDatasetIds($dsids);

				$data = $this->strabo->getDatasetSpotsSearch(null,$this->get);

				if(count($data)==0){
				echo "no data found for dataset $id";exit();
				}

				//Gather all samples and show error if none found.

				$foundSamples = [];
				foreach($data['features'] as $feature){

					$samples = $feature['properties']['samples'];
					if($samples){
						foreach($samples as $sample){
							$sample->geometry = $feature['geometry'];
							$foundSamples[] = $sample;
						}
					}

					$x++;

				}

				if(count($foundSamples)==0){
				echo "No samples found for this dataset.";exit();
				}

				//Get Centroids
				$fixedSamples = [];
				foreach($foundSamples as $s){
					$mygeojson=$s->geometry;
					$mygeojson=trim(json_encode($mygeojson));
					try {
						$mywkt=geoPHP::load($mygeojson,"json");
						$centroid = $mywkt->centroid();
						$out = $centroid->out("json");
					} catch (Exception $e) {
						$centroid="";
						$out = "";
					}

					if($out != ""){
						$out = json_decode($out);
						$out = $out->coordinates;
					}

					$s->centroid = $out;
					$fixedSamples[] = $s;
				}

				//rowcol(col, row)

				$cols = [
							"sample_id_name",
							"sample_type",
							"label",
							"longitude",
							"latitude",
							"main_sampling_purpose",
							"sample_description",
							"material_type"
						];

				foreach($fixedSamples as $samp){
					foreach($samp as $key=>$value){
						if($key != "centroid" && $key != "geometry"){
							if(!in_array($key, $cols)) $cols[] = $key;
						}
					}
				}

				/** PHPExcel */
				include 'PHPExcel.php';

				/** PHPExcel_Writer_Excel2007 */
				include 'PHPExcel/Writer/Excel2007.php';

				$objPHPExcel = new PHPExcel();

				// Set properties
				$objPHPExcel->getProperties()->setCreator("strabospot.org");
				$objPHPExcel->getProperties()->setLastModifiedBy("strabospot.org");
				$objPHPExcel->getProperties()->setTitle("StraboSpot.org Sample List");
				$objPHPExcel->getProperties()->setSubject("StraboSpot.org Sample List");
				$objPHPExcel->getProperties()->setDescription("StraboSpot.org Sample List");

				// Rename sheet
				$objPHPExcel->getActiveSheet()->setTitle('Samples');

				// Add some data
				$objPHPExcel->setActiveSheetIndex(0);

				$objPHPExcel->getActiveSheet()->SetCellValue('A1', "StraboSpot Sample List: $datasetname");

				$colnum = 0;
				foreach($cols as $col){
					if($col == "sample_id_name") $col = "sample_id";
					if($col == "main_sampling_purpose") $col = "sampling_purpose";

					$fixcolname = $this->fix_column_name($col);

					$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol(2,$colnum), $fixcolname);
					$colnum++;
				}

				$rownum = 3;

				foreach($fixedSamples as $s){

					foreach($s as $key=>$value){

						$colnum = 0;
						foreach($cols as $col){

							if($col == $key){
								$showval = $value;
								if(is_array($showval)){
									$showval = implode(", ", $showval);
								}
								$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,$colnum), $showval);
							}

							$colnum++;
						}

					}

					$rownum ++;
				}

				// Save Excel 2007 file
				$objWriter = new PHPExcel_Writer_Excel2007($objPHPExcel);

				// We'll be outputting an excel file
				header('Content-type: application/vnd.ms-excel');

				$filedate = date("m_d_Y");

				// It will be called file.xls
				header('Content-Disposition: attachment; filename="'."StraboSpot_Samples_".$filedate.".xlsx".'"');

				// Write file to the browser
				$objWriter->save('php://output');

			}else{
				echo "Dataset not found.";exit();
			}

		}

	public function xlsSampleList(){

			if($this->get['dsids']!=""){

				$dsids=$this->get['dsids'];
				$this->alltags = $this->strabo->getTagsFromDatasetIds($dsids);

				$data = $this->strabo->getDatasetSpotsSearch(null,$this->get);

				$filedate = date("m_d_Y");
				$outFileName = $this->fixFileName($this->strabo->getDatasetName($dsids)."_Strabo_Sample_List_".$filedate);
				$outFileName .= ".xlsx";

				if(count($data)==0){
				echo "no data found for dataset $id";exit();
				}

				//Gather all samples and show error if none found.

				$foundSamples = [];
				foreach($data['features'] as $feature){

					$samples = $feature['properties']['samples'];
					if($samples){
						foreach($samples as $sample){
							$sample->geometry = $feature['geometry'];
							$foundSamples[] = $sample;
						}
					}

					$x++;

				}

				if(count($foundSamples)==0){
				echo "No samples found for this dataset.";exit();
				}

				//Get Centroids
				$fixedSamples = [];
				foreach($foundSamples as $s){
					$mygeojson=$s->geometry;
					$mygeojson=trim(json_encode($mygeojson));
					try {
						$mywkt=geoPHP::load($mygeojson,"json");
						$centroid = $mywkt->centroid();
						$out = $centroid->out("json");
					} catch (Exception $e) {
						$centroid="";
						$out = "";
					}

					if($out != ""){
						$out = json_decode($out);
						$out = $out->coordinates;
					}

					$s->centroid = $out;
					$fixedSamples[] = $s;
				}

				//rowcol(col, row)

				$cols = [
							"sample_id_name",
							"sample_type",
							"label",
							"longitude",
							"latitude",
							"main_sampling_purpose",
							"sample_description",
							"material_type"
						];

				foreach($fixedSamples as $samp){
					foreach($samp as $key=>$value){
						if($key != "centroid" && $key != "geometry"){
							if(!in_array($key, $cols)) $cols[] = $key;
						}
					}
				}

				/** PHPExcel */
				include 'PHPExcel.php';

				/** PHPExcel_Writer_Excel2007 */
				include 'PHPExcel/Writer/Excel2007.php';

				$objPHPExcel = new PHPExcel();

				// Set properties
				$objPHPExcel->getProperties()->setCreator("strabospot.org");
				$objPHPExcel->getProperties()->setLastModifiedBy("strabospot.org");
				$objPHPExcel->getProperties()->setTitle("StraboSpot.org Sample List");
				$objPHPExcel->getProperties()->setSubject("StraboSpot.org Sample List");
				$objPHPExcel->getProperties()->setDescription("StraboSpot.org Sample List");

				// Rename sheet
				$objPHPExcel->getActiveSheet()->setTitle('Samples');

				// Add some data
				$objPHPExcel->setActiveSheetIndex(0);

				$objPHPExcel->getActiveSheet()->SetCellValue('A1', "StraboSpot Sample List: $datasetname");

				$colnum = 0;
				foreach($cols as $col){
					if($col == "sample_id_name") $col = "sample_id";
					if($col == "main_sampling_purpose") $col = "sampling_purpose";

					$fixcolname = $this->fix_column_name($col);

					$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol(2,$colnum), $fixcolname);
					$colnum++;
				}

				$rownum = 3;

				foreach($fixedSamples as $s){

					foreach($s as $key=>$value){

						$colnum = 0;
						foreach($cols as $col){

							if($col == $key){
								$showval = $value;
								if(is_array($showval)){
									$showval = implode(", ", $showval);
								}
								$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,$colnum), $showval);
							}

							$colnum++;
						}

					}

					$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,3), $s->centroid[0]);
					$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,4), $s->centroid[1]);

					$rownum ++;
				}

				// Save Excel 2007 file
				$objWriter = new PHPExcel_Writer_Excel2007($objPHPExcel);

				// We'll be outputting an excel file
				header('Content-type: application/vnd.ms-excel');

				// It will be called file.xls
				header('Content-Disposition: attachment; filename="'.$outFileName);

				// Write file to the browser
				$objWriter->save('php://output');

			}else{
				echo "Dataset not found.";exit();
			}

		}

	public function debugOut(){

		if($this->get['dsids']!=""){

			$dsids=$this->get['dsids'];
			$this->alltags = $this->strabo->getTagsFromDatasetIds($dsids);

			$spots = $this->strabo->getDatasetSpotsSearch(null,$this->get);
			$spots = $spots['features'];

			$this->dumpVar($spots);

		} //end if dsids

	}

	public function stereonetOut(){

			if($this->get['dsids']!=""){

				$dsids=$this->get['dsids'];
				$this->alltags = $this->strabo->getTagsFromDatasetIds($dsids);

				$spots = $this->strabo->getDatasetSpotsSearch(null,$this->get);
				$spots = $spots['features'];

				if(count($spots)==0){
				echo "no data found for dataset $id";exit();
				}

				$headers = array (
					"No.",
					"Type",
					"Structure",
					"Color",
					"Trd/Strk",
					"Plg/Dip",
					"Longitude",
					"Latitude",
					"Horiz ± m",
					"Elevation",
					"Elev ± m",
					"Time",
					"Day",
					"Month",
					"Year",
					"Notes"
				);

				unset($lines);
				unset($planes);

				$count = count($spots);

				foreach($spots as $spot){

					if($spot['properties']['orientation_data']){

						$longitude = 999;
						$latitude = 99;
						$trendstrike = "";
						$plungedip = "";

						//check if spot is point and set lat/lon if so
						if($spot['geometry']->type == "Point"){
							$longitude = $spot['geometry']->coordinates[0];
							$latitude = $spot['geometry']->coordinates[1];
						}

						foreach($spot['properties']['orientation_data'] as $or){

							if($or->type=="planar_orientation"){

								if($or->strike != "" && $or->dip != ""){

									$trendstrike = $or->strike;
									$plungedip = $or->dip;
									$notes = $or->notes;

									if($or->feature_type != ""){
										$showtype = ucwords(str_replace("_", " ", $or->feature_type));
									}else{
										$showtype = "Plane";
									}

									$row = array(
													"",
													"P",
													$showtype,
													"000000000",
													"$trendstrike",
													"$plungedip",
													"$longitude",
													"$latitude",
													"",
													"0",
													"",
													"",
													"0",
													"0",
													"0",
													"$notes"
												);
									$planes[] = $row;
								}

							}elseif($or->type=="linear_orientation"){

								if($or->trend != "" && $or->plunge != ""){

									$trendstrike = $or->trend;
									$plungedip = $or->plunge;
									$notes = $or->notes;

									if($or->feature_type != ""){
										$showtype = ucwords(str_replace("_", " ", $or->feature_type));
									}else{
										$showtype = "Line";
									}

									$row = array(
													"",
													"L",
													$showtype,
													"000000000",
													"$trendstrike",
													"$plungedip",
													"$longitude",
													"$latitude",
													"",
													"0",
													"",
													"",
													"0",
													"0",
													"0",
													"$notes"
												);
									$lines[] = $row;
								}

							}

							foreach($or->associated_orientation as $aor){

								if($aor->type=="planar_orientation"){

									if($aor->strike != "" && $aor->dip != ""){

										$trendstrike = $aor->strike;
										$plungedip = $aor->dip;
										$notes = $aor->notes;

										if($aor->feature_type != ""){
											$showtype = ucwords(str_replace("_", " ", $aor->feature_type));
										}else{
											$showtype = "Plane";
										}

										$row = array(
														"",
														"P",
														$showtype,
														"000000000",
														"$trendstrike",
														"$plungedip",
														"$longitude",
														"$latitude",
														"",
														"0",
														"",
														"",
														"0",
														"0",
														"0",
														"$notes"
													);
										$planes[] = $row;
									}

								}elseif($aor->type=="linear_orientation"){

									if($aor->trend != "" && $aor->plunge != ""){

										$trendstrike = $aor->trend;
										$plungedip = $aor->plunge;
										$notes = $aor->notes;

										if($aor->feature_type != ""){
											$showtype = ucwords(str_replace("_", " ", $aor->feature_type));
										}else{
											$showtype = "Line";
										}

										$row = array(
														"",
														"L",
														$showtype,
														"000000000",
														"$trendstrike",
														"$plungedip",
														"$longitude",
														"$latitude",
														"",
														"0",
														"",
														"",
														"0",
														"0",
														"0",
														"$notes"
													);
										$lines[] = $row;
									}

								}

							}

						}

					}

				}

				if($lines!="" || $planes != ""){

					$recordnum = 1;

					$out[]=implode("\t",$headers);
					if($planes!=""){
						foreach($planes as $plane){
							$plane[0]=$recordnum;
							$out[]=implode("\t",$plane);;
							$recordnum++;
						}
					}
					if($lines!=""){
						foreach($lines as $line){
							$line[0]=$recordnum;
							$out[]=implode("\t",$line);;
							$recordnum++;
						}
					}

					$out = implode("\n",$out);

					$filedate = date("m_d_Y");
					$outname="StraboSpot_Search_$filedate.txt";

					header("Content-disposition: attachment; filename=$outname");
					header('Content-type: text/plain');

					echo $out;

				}else{

					include("includes/mheader.php");
					?>

			<!-- Main -->
				<div id="main" class="wrapper style1">
					<div class="container">

						<header class="major">
							<h2>Stereonet Output:</h2>
						</header>
							<section id="content">

					Sorry, no orientation data found for this dataset.

							</section>

					<div class="bottomSpacer"></div>

					</div>
				</div>

					<?php
					include("includes/mfooter.php");

				}

			} //end if dsids

		}

	public function bkupdownloadImages20250213(){
		if($this->get['dsids']!=""){

			$hasImages = false;
			$randnum = rand(111111111,999999999);

			$dsids=$this->get['dsids'];
			$this->alltags = $this->strabo->getTagsFromDatasetIds($dsids);

			$json = $this->strabo->getDatasetSpotsSearch(null,$this->get);

			$dsname = $this->strabo->getDatasetName($dsids);

			$dailynotes = $this->strabo->getDailyNotesFromDatasetID($dsids);

			$spots = $json['features'];

			mkdir("ziptemp/$randnum");
			mkdir("ziptemp/$randnum/StraboImages");

			foreach($spots as $spot){

				$spotname = $this->cleanString($spot['properties']['name']);

				if(count($spot['properties']['images']) > 0){

					$hasImages = true;

					$imagenum = 1;
					foreach($spot['properties']['images'] as $image){

						$iname = $this->strabo->getImageFilename($image['id']);
						$imagefilename = $image['id'].".jpg";

						exec("cp /srv/app/www/dbimages/$iname /srv/app/www/ziptemp/$randnum/StraboImages/$imagefilename");

						$imagenum++;
					}
				}

			}

			if($hasImages){
				//zip folder and redirect
				//zip -r output_file.zip file1 folder1

				exec("cd /srv/app/www/ziptemp/$randnum/; zip -r StraboImages.zip StraboImages");
				header("Location: https://strabospot.org/ziptemp/$randnum/StraboImages.zip");

			}else{
				//show error message
				echo "Error. No images found in dataset.";
			}

			exec("rm -rf /srv/app/www/ziptemp/$randnum/StraboImages/");

		}
	}

	public function downloadImages(){
		if($this->get['dsids']!=""){

			$hasImages = false;
			$randnum = rand(111111111,999999999);

			$dsids=$this->get['dsids'];
			$this->alltags = $this->strabo->getTagsFromDatasetIds($dsids);

			$json = $this->strabo->getDatasetSpotsSearch(null,$this->get);

			$dsname = $this->strabo->getDatasetName($dsids);

			$dailynotes = $this->strabo->getDailyNotesFromDatasetID($dsids);

			$spots = $json['features'];

			mkdir("ziptemp/$randnum");
			mkdir("ziptemp/$randnum/StraboImages");

			foreach($spots as $spot){

				$spotname = $this->cleanString($spot['properties']['name']);

				if(count($spot['properties']['images']) > 0){

					$hasImages = true;

					$imagenum = 1;
					foreach($spot['properties']['images'] as $image){

						$iname = $this->strabo->getImageFilename($image['id']);

						if($image["title"] != ""){
							$title = $image["title"];
							$title = $this->fixFileName($title);
							$imagefilename = "spot_" . $spotname . "-image_" . $title.".jpg";
						}else{
							$imagefilename = "spot_" . $spotname . "-image_" . $image['id'].".jpg";
						}

						exec("cp /srv/app/www/dbimages/$iname /srv/app/www/ziptemp/$randnum/StraboImages/$imagefilename");

						$imagenum++;
					}
				}

			}
			if($hasImages){
				//zip folder and redirect
				//zip -r output_file.zip file1 folder1

				exec("cd /srv/app/www/ziptemp/$randnum/; zip -r StraboImages.zip StraboImages");
				header("Location: https://strabospot.org/ziptemp/$randnum/StraboImages.zip");

			}else{
				//show error message
				echo "Error. No images found in dataset.";
			}

			exec("rm -rf /srv/app/www/ziptemp/$randnum/StraboImages/");

		}
	}

	public function hollyTest(){
		if($this->get['dsids']!=""){

			$hasImages = false;
			$randnum = rand(111111111,999999999);

			$dsids=$this->get['dsids'];

			$json = $this->strabo->getDatasetSpotsSearch(null,$this->get);

			$spots = $json['features'];

			foreach($spots as $spot){

				$spotname = $this->cleanString($spot['properties']['name']);

				if(count($spot['properties']['images']) > 0){

					$hasImages = true;

					$imagenum = 1;
					foreach($spot['properties']['images'] as $image){

						$iname = $this->strabo->getImageFilename($image['id']);
						$imagefilename = $image['id'].".jpg";

						//   /volumes/volume01/StraboData/bigDriveData/tempFiles/holly/
						//   /volumes/volume01/StraboData/dbimages

						echo "cp /volumes/volume01/StraboData/dbimages/$iname /volumes/volume01/StraboData/bigDriveData/tempFiles/holly/$imagefilename<br>";

						$imagenum++;
					}
				}

			}

		}
	}

	public function cleanString($string) {
	   $string = str_replace(' ', '-', $string); // Replaces all spaces with hyphens.

	   return preg_replace('/[^A-Za-z0-9\-]/', '', $string); // Removes special chars.
	}

	public function varToPDF($var, $indent){
		$rows = [];
		if(is_array($var)){
			foreach($var as $v){
				$newrows = $this->varToPDF($v, $indent + 2);
				foreach($newrows as $newrow){
					$rows[]=$newrow;
				}
			}
		}elseif(is_object($var)){
			foreach($var as $key=>$v){
				if($key!="id"){
					$rows[] = array($indent, $key, 'label');
					$newrows = $this->varToPDF($v, $indent + 2);
					foreach($newrows as $newrow){
						$rows[]=$newrow;
					}
				}
			}
		}else{
			if($var != "id"){
				$rows[] = array($indent, $var, 'value');
			}
		}

		return $rows;
	}

	public function groupVarToPDF($array){
		$currentindent = $array[0][0];
		$outrows = [];
		$valarray = [];

		foreach($array as $row){
			if($row[0] != $currentindent){
				if($lasttype == "label"){
					$thislabel = implode(", ", $valarray);
					$thislabel = $this->fixLabel($thislabel);
					$outrows[] = array($currentindent, $thislabel, $lasttype);
				}else{
					$outrows[] = array($currentindent, implode(", ", $valarray), $lasttype);
				}

				$valarray = [];
				$currentindent = $row[0];
			}
			$valarray[] = $row[1];
			$lasttype = $row[2];
		}

		$outrows[] = array($currentindent, implode(", ", $valarray), $lasttype);

		return $outrows;
	}

	function buildKMLIcon($id,$randnum,$strike,$showval,$trend){ 

		$finalimage = imagecreatefrompng("assets/files/kmlfiles/bubbleicon/blankbubble.png");

		imagealphablending($finalimage, true);
		imagesavealpha($finalimage, true);

		if($strike!="" && $strike!=0 && is_numeric($strike)){

			$strike = round($strike);

			$arrow = imagecreatefrompng("assets/files/kmlfiles/bubbleicon/planar_bar.png");

			// Transparent Background
			imagealphablending($arrow, true);
			$transparency = imagecolorallocatealpha($arrow, 0, 0, 0, 127);
			imagesavealpha($arrow, true);

			// Drawing over
			$black = imagecolorallocate($arrow, 0, 0, 0);
			$fontcolor = imagecolorallocate($arrow, 255, 255, 255);//255, 176, 0

			$arrow = imagerotate($arrow, ($strike*-1), $transparency);

			//crop to 100x100 again
			$sizex = imagesx($arrow);
			$sizey = imagesy($arrow);

			$halfx = round($sizex/2);
			$halfy = round($sizey/2);

			$locx = 50 - $halfx;
			$locy = 40 - $halfy;

			imagecopy($finalimage, $arrow, $locx, $locy, 0, 0, $sizex, $sizey);

		}

		if($trend!="" && $trend!=0 && is_numeric($trend)){

			$trend = round($trend);

			$trendbar = imagecreatefrompng("assets/files/kmlfiles/bubbleicon/linear_arrow.png");

			// Transparent Background
			imagealphablending($trendbar, true);
			$transparency = imagecolorallocatealpha($trendbar, 0, 0, 0, 127);
			imagesavealpha($trendbar, true);

			// Drawing over
			$black = imagecolorallocate($arrow, 0, 0, 0);
			$fontcolor = imagecolorallocate($trendbar, 255, 255, 255);//255, 176, 0

			$trendbar = imagerotate($trendbar, ($trend*-1), $transparency);

			//crop to 100x100 again
			$sizex = imagesx($trendbar);
			$sizey = imagesy($trendbar);

			$halfx = round($sizex/2);
			$halfy = round($sizey/2);

			$locx = 50 - $halfx;
			$locy = 40 - $halfy;

			imagecopy($finalimage, $trendbar, $locx, $locy, 0, 0, $sizex, $sizey);

		}

		if($showval!="" && is_numeric($showval)){

			$showval = round($showval);

			$diplabel = imagecreatetruecolor(100, 100);

			// Transparent Background
			imagealphablending($diplabel, true);
			$diplabeltransparency = imagecolorallocatealpha($diplabel, 0, 0, 0, 127);
			imagefill($diplabel, 0, 0, $diplabeltransparency);
			imagesavealpha($diplabel, true);

			// Drawing over
			$black = imagecolorallocate($diplabel, 0, 0, 0);

			imagettftext($diplabel, 16, 0, 50, 85, $black, "assets/files/kmlfiles/roadway.ttf", "$showval");

			imagecopy($finalimage, $diplabel, 0, 0, 0, 0, 100, 100);

		}

		imagepng($finalimage, "ogrtemp/$randnum/data/files/$id.png");

	}

	public function ffbuildKMLIcon($id,$randnum,$strike,$showval,$trend){ //outline
		$backbox = imagecreatetruecolor(100, 100);

		// Transparent Background
		imagealphablending($backbox, true);
		$backboxtransparency = imagecolorallocatealpha($backbox, 0, 0, 0, 127);
		imagefill($backbox, 0, 0, $backboxtransparency);
		imagesavealpha($backbox, true);

		if($strike!="" && $strike!=0 && is_numeric($strike)){

			$strike = round($strike);

			$arrow = imagecreatetruecolor(100, 100);

			// Transparent Background
			imagealphablending($arrow, true);
			$transparency = imagecolorallocatealpha($arrow, 0, 0, 0, 127);
			imagefill($arrow, 0, 0, $transparency);
			imagesavealpha($arrow, true);

			// Drawing over
			$black = imagecolorallocate($arrow, 0, 0, 0);
			$fontcolor = imagecolorallocate($arrow, 255, 255, 255);//255, 176, 0

			// set up array of points for arrow
			$whitevalues = array(
						45,0,
						45,100,
						55,100,
						55,60,
						66,50,
						55,40,
						55,0,
						45,0
						);

			imagefilledpolygon($arrow, $whitevalues, 8, $fontcolor);

			// set up array of points for arrow
			$blackvalues = array(
						48,3,
						48,97,
						52,97,
						52,58,
						62,50,
						52,42,
						52,3,
						48,3
						);

			imagefilledpolygon($arrow, $blackvalues, 8, $black);

			$arrow = imagerotate($arrow, ($strike*-1), $transparency);

			//crop to 100x100 again
			$sizex = imagesx($arrow);
			$sizey = imagesy($arrow);

			$xoffset = round(($sizex-100)/2);
			$yoffset = round(($sizex-100)/2);

			$arrow = imagecrop($arrow, ['x' => $xoffset, 'y' => $yoffset, 'width' => 100, 'height' => 100]);

			imagecopy($backbox, $arrow, 0, 0, 0, 0, 100, 100);

		}

		if($trend!="" && is_numeric($trend)){

			$trend = round($trend);

			$trendbar = imagecreatetruecolor(100, 100);

			// Transparent Background
			imagealphablending($trendbar, true);
			$trendtransparency = imagecolorallocatealpha($trendbar, 0, 0, 0, 127);
			imagefill($trendbar, 0, 0, $trendtransparency);
			imagesavealpha($trendbar, true);

			// Drawing over
			$black = imagecolorallocate($trendbar, 0, 0, 0);
			$fontcolor = imagecolorallocate($trendbar, 255, 255, 255);//255, 176, 0

			// set up array of points for arrow
			$whitevalues = array(
						50,0,
						37,18,
						45,13,
						45,100,
						55,100,
						55,13,
						63,18,
						50,0
						);
			imagefilledpolygon($trendbar, $whitevalues, 8, $fontcolor);

			// set up array of points for arrow
			$blackvalues = array(
						50,2,
						40,15,
						48,10,
						48,97,
						52,97,
						52,10,
						60,15,
						50,2
						);
			imagefilledpolygon($trendbar, $blackvalues, 8, $black);

			$trendbar = imagerotate($trendbar, ($trend*-1), $trendtransparency);

			//crop to 100x100 again
			$sizex = imagesx($trendbar);
			$sizey = imagesy($trendbar);

			$xoffset = round(($sizex-100)/2);
			$yoffset = round(($sizex-100)/2);

			$trendbar = imagecrop($trendbar, ['x' => $xoffset, 'y' => $yoffset, 'width' => 100, 'height' => 100]);

			imagecopy($backbox, $trendbar, 0, 0, 0, 0, 100, 100);

		}

		if($showval!="" && is_numeric($showval)){

			$showval = round($showval);

			$diplabel = imagecreatetruecolor(100, 100);

			// Transparent Background
			imagealphablending($diplabel, true);
			$diplabeltransparency = imagecolorallocatealpha($diplabel, 0, 0, 0, 127);
			imagefill($diplabel, 0, 0, $diplabeltransparency);
			imagesavealpha($diplabel, true);

			// Drawing over
			$black = imagecolorallocate($diplabel, 0, 0, 0);

			imagettftext($diplabel, 24, 0, 50, 31, $fontcolor, "files/kmlfiles/roadway.ttf", "$showval");

			imagecopy($backbox, $diplabel, 0, 0, 0, 0, 100, 100);

		}

		imagepng($backbox, "ogrtemp/$randnum/data/files/$id.png");

	}

	public function buildCustomPoint($spot,$randnum){

	/*
	strike
	dip
	trend
	plunge
	*/

		$id=$spot['id'];
		foreach($spot['orientation_data'] as $od){
			foreach($od as $key=>$value){
				if($key=="strike") $strike=$value;
				if($key=="dip") $dip=$value;
				if($key=="trend") $trend=$value;
				if($key=="plunge") $plunge=$value;

			}

			foreach($od->associated_orientation as $ao){
				foreach($ao as $key=>$value){
					if($key=="strike") $strike=$value;
					if($key=="dip") $dip=$value;
					if($key=="trend") $trend=$value;
					if($key=="plunge") $plunge=$value;
				}
			}

		}

		if($strike!="" || $trend!=""){

			if($dip!=""){
				$showval=$dip;
			}elseif($plunge!=""){
				$showval=$plunge;
			}

			$this->buildKMLIcon($id,$randnum,$strike,$showval,$trend);

			$pointstyle="<Style><IconStyle><Icon><href>files/$id.png</href></Icon></IconStyle></Style>";
		}else{
			$pointstyle="";
		}

		return $pointstyle;

	}

	public function getTagColor($id, $tags){
		if($id == "" || $tags == ""){
			return "";
		}

		$outcolor = "";

		foreach($tags as $tag){
			if(in_array($id, $tag->spots)){
				$outcolor = $tag->color;
			}
		}

		return $outcolor;
	}

	public function oldaddSpotToPDF20250630(&$pdf, &$spot, &$allspots, $indent = 0){

		//Move all of this to its own function.

		$spotname = $spot['name'];
		if($spot['geometrytype']){
			$spotname .= " (".$spot['geometrytype'].")";
		}

		$pdf->spotTitle($spotname, 15 + $indent);

		$modified = (string) $spot['id'];
		$modified = substr($modified,0,10);
		$modified = date("F j, Y",$modified);
		$pdf->valueRow("Created",$modified,15 + $indent);

		$modified = (string) $spot['modified_timestamp'];
		$modified = substr($modified,0,10);
		$modified = date("F j, Y",$modified);
		$pdf->valueRow("Last Modified",$modified,15 + $indent);

		if($rawspot['geometry']->type=="Point" && $rawspot['properties']['strat_section_id']==""){
			$pdf->valueRow("Longitude",$rawspot['geometry']->coordinates[0],15 + $indent);
			$pdf->valueRow("Latitude",$rawspot['geometry']->coordinates[1],15 + $indent);
		}

		if($spot['notes']){
			$notes = $spot['notes'];
			$pdf->notesRow("Notes",$notes,15 + $indent);
		}

		if($spot['surface_feature']){
			foreach($spot['surface_feature'] as $key=>$value){
				$key = $this->fixLabel($key);
				if(is_string($value)){
					$value = $this->fixLabel($value);
				}
				$pdf->valueRow($key,$value,15 + $indent);
			}
		}

		if($spot['trace']){
			foreach($spot['trace'] as $key=>$value){
				if($key != "trace_feature"){
					$key = $this->fixLabel($key);
					if(is_string($value)){
						$value = $this->fixLabel($value);
					}
					$pdf->valueRow($key,$value,15 + $indent);
				}
			}
		}

		if($spot['orientation_data']){
			$pdf->valueRow("Orientations","",15 + $indent);
			foreach($spot['orientation_data'] as $o){
				$pdf->valueTitle($this->fixLabel($o->type).": ",20 + $indent);
				foreach($o as $key=>$value){
					if($key!="id" && $key!="associated_orientation" && $key!="type"){
						$key = $this->fixLabel($key);
						if(is_string($value)){
							$value = $this->fixLabel($value);
						}
						$pdf->valueRow($key,$value,20 + $indent);
					}
				}

				if($o->associated_orientation){
					$pdf->valueRow("Associated Orientation Data","",20 + $indent);
					foreach($o->associated_orientation as $ao){
						$pdf->valueTitle($this->fixLabel($ao->type).": ",30 + $indent);
						foreach($ao as $key=>$value){
							if($key!="id" && $key!="associated_orientation" && $key!="type"){
								$key = $this->fixLabel($key);
								if(is_string($value)){
									$value = $this->fixLabel($value);
								}
								$pdf->valueRow($key,$value,30 + $indent);
							}
						}
						$pdf->Ln(1);
					}
				}

				$pdf->Ln(1);
			}
		}

		if($spot['_3d_structures']){
			$pdf->valueRow("3D Structures","",15 + $indent);
			foreach($spot['_3d_structures'] as $o){
				$pdf->valueTitle($this->fixLabel($o->type).": ",20 + $indent);
				foreach($o as $key=>$value){
					if($key!="id" && $key!="type"){
						$key = $this->fixLabel($key);
						if(is_string($value)){
							$value = $this->fixLabel($value);
						}
						$pdf->valueRow($key,$value,20 + $indent);
					}
				}

				$pdf->Ln(1);
			}
		}

		if($spot['samples']){
			$pdf->valueRow("Samples","",15 + $indent);
			foreach($spot['samples'] as $o){
				$pdf->valueTitle($this->fixLabel($o->label).": ",20 + $indent);
				foreach($o as $key=>$value){
					if($key!="id" && $key!="label"){
						$key = $this->fixLabel($key);
						if(is_string($value)){
							$value = $this->fixLabel($value);
						}
						$pdf->valueRow($key,$value,20 + $indent);
					}
				}

				$pdf->Ln(1);
			}
		}

		if($spot['tephra']){
			$pdf->valueRow("Tephra Intervals","",15 + $indent);
			foreach($spot['tephra'] as $o){
				$pdf->valueTitle("Interval: ",20 + $indent);
				foreach($o as $key=>$value){
					if($key!="id" && $key!="label"){
						$key = $this->fixLabel($key);
						if(is_string($value)){
							$value = $this->fixLabel($value);
						}
						$pdf->valueRow($key,$value,20 + $indent);
					}
				}

				$pdf->Ln(1);
			}
		}

		if($spot['other_features']){
			$pdf->valueRow("Other Features","",15 + $indent);
			foreach($spot['other_features'] as $o){
				$pdf->valueTitle($this->fixLabel($o->label).": ",20 + $indent);
				foreach($o as $key=>$value){
					if($key!="id" && $key!="label"){
						$key = $this->fixLabel($key);
						if(is_string($value)){
							$value = $this->fixLabel($value);
						}
						$pdf->valueRow($key,$value,20 + $indent);
					}
				}

				$pdf->Ln(1);
			}
		}

		$hastags = "no";

		if($this->alltags){
			foreach($this->alltags as $tag){
				$found = "no";
				if($tag->spots){
					if($tag->type!="geologic_unit"){
						foreach($tag->spots as $spotid){
							if($spotid == $id){
								$hastags = "yes";
							}
						}
					}
				}

			}
		}

		if($hastags == "yes"){

			$pdf->valueRow("Tags","",15 + $indent);

			if($this->alltags){
				foreach($this->alltags as $tag){
					$found = "no";
					if($tag->spots){
						if($tag->type!="geologic_unit"){
							foreach($tag->spots as $spotid){
								if($spotid == $id){
									$found = "yes";
								}
							}
						}
					}

					if($found == "yes"){

						$pdf->valueTitle($tag->name,20 + $indent);
						foreach($tag as $key=>$value){

							if($key != "date" && $key != "spots" && $key != "features" && $key != "id" && $key != "name" ){
								$key = $this->fixLabel($key);
								if(is_string($value)){
									$value = $this->fixLabel($value);
								}elseif(is_array($value)){
									$value = implode(", ", $value);
								}
								$pdf->valueRow($key,$value,20 + $indent);
							}

						}

						$pdf->Ln(1);

					}
				}
			}

		}

		$hastags = "no";

		if($this->alltags){
			foreach($this->alltags as $tag){
				$found = "no";
				if($tag->spots){
					if($tag->type=="geologic_unit"){
						foreach($tag->spots as $spotid){
							if($spotid == $id){
								$hastags = "yes";
							}
						}
					}
				}

			}
		}

		if($hastags == "yes"){

			$pdf->valueRow("Geologic Unit(s)","",15 + $indent);

			if($this->alltags){
				foreach($this->alltags as $tag){
					$found = "no";
					if($tag->spots){
						if($tag->type=="geologic_unit"){
							foreach($tag->spots as $spotid){
								if($spotid == $id){
									$found = "yes";
								}
							}
						}
					}

					if($found == "yes"){

						$pdf->valueTitle($tag->name,20 + $indent);
						foreach($tag as $key=>$value){

							if($key != "date" && $key != "spots" && $key != "features" && $key != "id" && $key != "name" ){
								$key = $this->fixLabel($key);
								if(is_string($value)){
									$value = $this->fixLabel($value);
								}elseif(is_array($value)){
									$value = implode(", ", $value);
								}
								$pdf->valueRow($key,$value,20 + $indent);
							}

						}

						$pdf->Ln(1);

					}
				}
			}

		}

		if($spot['sed']){
			$pdf->valueRow("Sed","",15 + $indent);
			if($spot['sed']->strat_section){
				$pdf->valueTitle("Strat Section: ", 20 + $indent);
				$content = $spot['sed']->strat_section;

				if($content->section_well_name!=""){
					$pdf->valueRow("Section/Well Name",$content->section_well_name,25 + $indent);
				}

				if($content->column_profile!=""){
					$pdf->valueRow("Column Profile",$content->column_profile,25 + $indent);
				}

				if($content->column_y_axis_units!=""){
					$pdf->valueRow("Column Y-Axis Units",$content->column_y_axis_units,25 + $indent);
				}

				if($content->section_type!=""){
					$pdf->valueRow("Section Type",$content->section_type,25 + $indent);
				}

				if($content->what_core_repository!=""){
					$pdf->valueRow("What Core Repository?",$content->what_core_repository,25 + $indent);
				}

				if($content->type_of_corer!=""){
					$pdf->valueRow("Type of corer",$content->type_of_corer,25 + $indent);
				}

				if($content->depth_from_surface_to_start_of!=""){
					$pdf->valueRow("Depth from surface to start of core",$content->depth_from_surface_to_start_of,25 + $indent);
				}

				if($content->total_core_length!=""){
					$pdf->valueRow("Total core length",$content->total_core_length,25 + $indent);
				}

				if($content->location_locality!=""){
					$pdf->valueRow("Location/Locality",$content->location_locality,25 + $indent);
				}

				if($content->basin!=""){
					$pdf->valueRow("Basin",$content->basin,25 + $indent);
				}

				if($content->age!=""){
					$pdf->valueRow("Age",$content->age,25 + $indent);
				}

				if($content->purpose!=""){
					$pdf->valueRow("Purpose",implode($content->purpose, ", "),25 + $indent);
				}

				if($content->other_purpose!=""){
					$pdf->valueRow("Other Purpose",$content->other_purpose,25 + $indent);
				}

				if($content->project_description!=""){
					$pdf->valueRow("Project Description",$content->project_description,25 + $indent);
				}

				if($content->dates_of_work!=""){
					$pdf->valueRow("Dates of Work",$content->dates_of_work,25 + $indent);
				}

				if($content->scale_of_interest!=""){
					$pdf->valueRow("Scale of Interest",implode($content->scale_of_interest, ", "),25 + $indent);
				}

				if($content->other_scale_of_interest!=""){
					$pdf->valueRow("Other Scale of Interest",$content->other_scale_of_interest,25 + $indent);
				}

				if($content->obs_interval_bed_obs_scale!=""){
					$pdf->valueRow("Observation Interval (average bed/observation scale)",$content->obs_interval_bed_obs_scale,25 + $indent);
				}

				if($content->how_is_section_georeferenced!=""){
					$pdf->valueRow("How is the Section Georeferenced?",$content->how_is_section_georeferenced,25 + $indent);
				}

				if($content->strat_section_notes!=""){
					$pdf->valueRow("Notes",$content->strat_section_notes,25 + $indent);
				}

				//strat section link here
				$pdf->httpLink("Download Strat Section", 25, "https://strabospot.org/pstrat_section?id=".$spot['id']."&did=".$dsids);

			}

			if($spot['sed']->lithologies){
				$pdf->valueTitle("Lithologies: ", 20 + $indent);
				$contents = $spot['sed']->lithologies;
				foreach($contents as $content){
					$pdf->valueTitle("Primary Lithology: ", 25 + $indent);

					if($content->primary_lithology!=""){
						$pdf->valueRow("Primary Lithology",$content->primary_lithology,30 + $indent);
					}

					if($content->siliciclastic_type!=""){
						$pdf->valueRow("Siliciclastic Type",$content->siliciclastic_type,30 + $indent);
					}

					if($content->dunham_classification!=""){
						$pdf->valueRow("Dunham Classification",$content->dunham_classification,30 + $indent);
					}

					if($content->grain_type!=""){
						$pdf->valueRow("Grain Type",$content->grain_type,30 + $indent);
					}

					if($content->evaporite_type!=""){
						$pdf->valueRow("Evaporite type",implode($content->evaporite_type, ", "),30 + $indent);
					}

					if($content->other_evaporite_type!=""){
						$pdf->valueRow("Other Evaporite Type",$content->other_evaporite_type,30 + $indent);
					}

					if($content->organic_coal_lithologies!=""){
						$pdf->valueRow("Organic/Coal Lithologies",implode($content->organic_coal_lithologies, ", "),30 + $indent);
					}

					if($content->other_organic_coal_lithology!=""){
						$pdf->valueRow("Other Organic/Coal Lithology",$content->other_organic_coal_lithology,30 + $indent);
					}

					if($content->volcaniclastic_type!=""){
						$pdf->valueRow("Volcaniclastic type",implode($content->volcaniclastic_type, ", "),30 + $indent);
					}

					if($content->other_volcaniclastic_type!=""){
						$pdf->valueRow("Other Volcaniclastic Type",$content->other_volcaniclastic_type,30 + $indent);
					}

					if($content->report_presence_of_particle_ag!=""){
						$pdf->valueRow("Report presence of particle aggregates ",$content->report_presence_of_particle_ag,30 + $indent);
					}

					if($content->componentry!=""){
						$pdf->valueRow("Componentry",implode($content->componentry, ", "),30 + $indent);
					}

					if($content->approximate_relative_abundance!=""){
						$pdf->valueRow("Approximate relative abundances of clasts",$content->approximate_relative_abundance,30 + $indent);
					}

					if($content->phosphorite_type!=""){
						$pdf->valueRow("Phosphorite type",implode($content->phosphorite_type, ", "),30 + $indent);
					}

					if($content->other_phosphorite_type!=""){
						$pdf->valueRow("Other Phosphorite Type",$content->other_phosphorite_type,30 + $indent);
					}

					$pdf->valueTitle("Lithification & Color: ", 25 + $indent);

					if($content->relative_resistance_weather!=""){
						$pdf->valueRow("Relative resistance (weathering profile)",$content->relative_resistance_weather,30 + $indent);
					}

					if($content->lithification!=""){
						$pdf->valueRow("Lithification",$content->lithification,30 + $indent);
					}

					if($content->evidence_of_deposit_alteration!=""){
						$pdf->valueRow("Evidence of deposit alteration",$content->evidence_of_deposit_alteration,30 + $indent);
					}

					if($content->evidence_of_clast_alteration!=""){
						$pdf->valueRow("Evidence of clast alteration",$content->evidence_of_clast_alteration,30 + $indent);
					}

					if($content->fresh_color!=""){
						$pdf->valueRow("Fresh Color",$content->fresh_color,30 + $indent);
					}

					if($content->weathered_color!=""){
						$pdf->valueRow("Weathered Color",$content->weathered_color,30 + $indent);
					}

					if($content->color_appearance!=""){
						$pdf->valueRow("Color Appearance",implode($content->color_appearance, ", "),30 + $indent);
					}

					if($content->other_color_appearance!=""){
						$pdf->valueRow("Other Color Appearance",$content->other_color_appearance,30 + $indent);
					}

					if($content->notes!=""){
						$pdf->valueRow("Notes",$content->notes,30 + $indent);
					}

					$pdf->valueTitle("Composition: ", 25 + $indent);

					if($content->minerals_present!=""){
						$pdf->valueRow("Minerals Present",implode($content->minerals_present, ", "),30 + $indent);
					}

					if($content->other_minerals!=""){
						$pdf->valueRow("Other Mineral(s)",$content->other_minerals,30 + $indent);
					}

					if($content->sandstone_type_dott!=""){
						$pdf->valueRow("Dott Classification",implode($content->sandstone_type_dott, ", "),30 + $indent);
					}

					if($content->sandstone_type_folk_mcbride!=""){
						$pdf->valueRow("Folk/McBride Classification",implode($content->sandstone_type_folk_mcbride, ", "),30 + $indent);
					}

					if($content->sandstone_modifier!=""){
						$pdf->valueRow("Sandstone modifier",implode($content->sandstone_modifier, ", "),30 + $indent);
					}

					if($content->other_sandstone_modifier!=""){
						$pdf->valueRow("Other Sandstone Modifier",$content->other_sandstone_modifier,30 + $indent);
					}

					if($content->skeletal_carbonate_components!=""){
						$pdf->valueRow("Skeletal Carbonate Components",implode($content->skeletal_carbonate_components, ", "),30 + $indent);
					}

					if($content->other_skeletal_carbonate_component!=""){
						$pdf->valueRow("Other Skeletal Carbonate Component",$content->other_skeletal_carbonate_component,30 + $indent);
					}

					if($content->skeletal_general_percent!=""){
						$pdf->valueRow("Skeletal (General) percent",$content->skeletal_general_percent,30 + $indent);
					}

					if($content->mollusc_percent!=""){
						$pdf->valueRow("Mollusc percent",$content->mollusc_percent,30 + $indent);
					}

					if($content->brachiopod_percent!=""){
						$pdf->valueRow("Brachiopod percent",$content->brachiopod_percent,30 + $indent);
					}

					if($content->coral_percent!=""){
						$pdf->valueRow("Coral percent",$content->coral_percent,30 + $indent);
					}

					if($content->echinoderm_percent!=""){
						$pdf->valueRow("Echinoderm percent",$content->echinoderm_percent,30 + $indent);
					}

					if($content->bryozoan_percent!=""){
						$pdf->valueRow("Bryozoan percent",$content->bryozoan_percent,30 + $indent);
					}

					if($content->calcareous_algae_percent!=""){
						$pdf->valueRow("Calcareous Algae percent",$content->calcareous_algae_percent,30 + $indent);
					}

					if($content->foraminifera_percent!=""){
						$pdf->valueRow("Foraminifera percent",$content->foraminifera_percent,30 + $indent);
					}

					if($content->stromatolite_percent!=""){
						$pdf->valueRow("Stromatolite percent",$content->stromatolite_percent,30 + $indent);
					}

					if($content->thrombolite_percent!=""){
						$pdf->valueRow("Thrombolite percent",$content->thrombolite_percent,30 + $indent);
					}

					if($content->dendrolite_percent!=""){
						$pdf->valueRow("Dendrolite percent",$content->dendrolite_percent,30 + $indent);
					}

					if($content->leiolite_percent!=""){
						$pdf->valueRow("Leiolite percent",$content->leiolite_percent,30 + $indent);
					}

					if($content->other_skeletal_carbonate_component_percent!=""){
						$pdf->valueRow("Other Skeletal Carbonate Component percent",$content->other_skeletal_carbonate_component_percent,30 + $indent);
					}

					if($content->non_skeletal_carbonate_compone!=""){
						$pdf->valueRow("Non-Skeletal Carbonate Components",implode($content->non_skeletal_carbonate_compone, ", "),30 + $indent);
					}

					if($content->other_non_skeletal_carbonate_component!=""){
						$pdf->valueRow("Other Non-Skeletal Carbonate Component",$content->other_non_skeletal_carbonate_component,30 + $indent);
					}

					if($content->mud_percent!=""){
						$pdf->valueRow("Mud percent",$content->mud_percent,30 + $indent);
					}

					if($content->cement_percent!=""){
						$pdf->valueRow("Cement percent",$content->cement_percent,30 + $indent);
					}

					if($content->intraclast_percent!=""){
						$pdf->valueRow("Intraclast percent",$content->intraclast_percent,30 + $indent);
					}

					if($content->peloid_percent!=""){
						$pdf->valueRow("Peloid percent",$content->peloid_percent,30 + $indent);
					}

					if($content->ooid_percent!=""){
						$pdf->valueRow("Ooid percent",$content->ooid_percent,30 + $indent);
					}

					if($content->oncoid_percent!=""){
						$pdf->valueRow("Oncoid percent",$content->oncoid_percent,30 + $indent);
					}

					if($content->pisoid_percent!=""){
						$pdf->valueRow("Pisoid percent",$content->pisoid_percent,30 + $indent);
					}

					if($content->coated_grian_percent!=""){
						$pdf->valueRow("Coated Grian percent",$content->coated_grian_percent,30 + $indent);
					}

					if($content->grapestone_percent!=""){
						$pdf->valueRow("Grapestone percent",$content->grapestone_percent,30 + $indent);
					}

					if($content->giant_ooid_percent!=""){
						$pdf->valueRow("Giant Ooid percent",$content->giant_ooid_percent,30 + $indent);
					}

					if($content->seafloor_precipitate_percent!=""){
						$pdf->valueRow("Seafloor Precipitate percent",$content->seafloor_precipitate_percent,30 + $indent);
					}

					if($content->molar_tooth_percent!=""){
						$pdf->valueRow("Molar Tooth percent",$content->molar_tooth_percent,30 + $indent);
					}

					if($content->other_non_skeletal_component_percent!=""){
						$pdf->valueRow("Other Non-Skeletal Component percent",$content->other_non_skeletal_component_percent,30 + $indent);
					}

					if($content->clay_or_mudstone_type!=""){
						$pdf->valueRow("Claystone or Mudstone Type",implode($content->clay_or_mudstone_type, ", "),30 + $indent);
					}

					if($content->other_claystone_mudstone!=""){
						$pdf->valueRow("Other Claystone/Mudstone",$content->other_claystone_mudstone,30 + $indent);
					}

					if($content->siliceous_mudstone_percent!=""){
						$pdf->valueRow("Siliceous mudstone percent",$content->siliceous_mudstone_percent,30 + $indent);
					}

					if($content->siliceous_calcareous_mudstone_percent!=""){
						$pdf->valueRow("Siliceous calcareous mudstone percent",$content->siliceous_calcareous_mudstone_percent,30 + $indent);
					}

					if($content->siliceous_volcanicla_tic_mudstone_percent!=""){
						$pdf->valueRow("Siliceous volcaniclastic mudstone percent",$content->siliceous_volcanicla_tic_mudstone_percent,30 + $indent);
					}

					if($content->calcareous_mudstone_percent!=""){
						$pdf->valueRow("Calcareous mudstone percent",$content->calcareous_mudstone_percent,30 + $indent);
					}

					if($content->black_shale_percent!=""){
						$pdf->valueRow("Black shale percent",$content->black_shale_percent,30 + $indent);
					}

					if($content->red_clay_percent!=""){
						$pdf->valueRow("Red clay percent",$content->red_clay_percent,30 + $indent);
					}

					if($content->red_mudstone_percent!=""){
						$pdf->valueRow("Red mudstone percent",$content->red_mudstone_percent,30 + $indent);
					}

					if($content->green_mudstone_percent!=""){
						$pdf->valueRow("Green mudstone percent",$content->green_mudstone_percent,30 + $indent);
					}

					if($content->variegated_mudstone_percent!=""){
						$pdf->valueRow("Variegated mudstone percent",$content->variegated_mudstone_percent,30 + $indent);
					}

					if($content->marl_percent!=""){
						$pdf->valueRow("Marl percent",$content->marl_percent,30 + $indent);
					}

					if($content->sarl_percent!=""){
						$pdf->valueRow("Sarl percent",$content->sarl_percent,30 + $indent);
					}

					if($content->argillaceous_mudstone_percent!=""){
						$pdf->valueRow("Argillaceous mudstone percent",$content->argillaceous_mudstone_percent,30 + $indent);
					}

					if($content->conglomerate_composition!=""){
						$pdf->valueRow("Conglomerate/Breccia Composition",implode($content->conglomerate_composition, ", "),30 + $indent);
					}

					if($content->clast_composition!=""){
						$pdf->valueRow("Clast Composition",implode($content->clast_composition, ", "),30 + $indent);
					}

					if($content->other_clast_types!=""){
						$pdf->valueRow("Other Clast Type(s)",$content->other_clast_types,30 + $indent);
					}

					if($content->intrusive_igneous_clast_percent!=""){
						$pdf->valueRow("Intrusive igneous clast percent",$content->intrusive_igneous_clast_percent,30 + $indent);
					}

					if($content->extrusive_igneous_clast_percent!=""){
						$pdf->valueRow("Volcanic clast percent",$content->extrusive_igneous_clast_percent,30 + $indent);
					}

					if($content->metamorphic_clast_percent!=""){
						$pdf->valueRow("Metamorphic clast percent",$content->metamorphic_clast_percent,30 + $indent);
					}

					if($content->mudstone_clast_percent!=""){
						$pdf->valueRow("Mudstone clast percent",$content->mudstone_clast_percent,30 + $indent);
					}

					if($content->siltstone_clast_percent!=""){
						$pdf->valueRow("Siltstone clast percent",$content->siltstone_clast_percent,30 + $indent);
					}

					if($content->sandstone_clast_percent!=""){
						$pdf->valueRow("Sandstone clast percent",$content->sandstone_clast_percent,30 + $indent);
					}

					if($content->conglomerate_clast_percent!=""){
						$pdf->valueRow("Conglomerate clast percent",$content->conglomerate_clast_percent,30 + $indent);
					}

					if($content->limestone_clast_percent!=""){
						$pdf->valueRow("Limestone clast percent",$content->limestone_clast_percent,30 + $indent);
					}

					if($content->dolostone_clast_percent!=""){
						$pdf->valueRow("Dolostone clast percent",$content->dolostone_clast_percent,30 + $indent);
					}

					if($content->wackestone_clast_percent!=""){
						$pdf->valueRow("Wackestone clast percent",$content->wackestone_clast_percent,30 + $indent);
					}

					if($content->packstone_clast_percent!=""){
						$pdf->valueRow("Packstone clast percent",$content->packstone_clast_percent,30 + $indent);
					}

					if($content->grainstone_clast_percent!=""){
						$pdf->valueRow("Grainstone clast percent",$content->grainstone_clast_percent,30 + $indent);
					}

					if($content->boundstone_clast_percent!=""){
						$pdf->valueRow("Boundstone clast percent",$content->boundstone_clast_percent,30 + $indent);
					}

					if($content->other_clast_percent!=""){
						$pdf->valueRow("Other clast percent",$content->other_clast_percent,30 + $indent);
					}

					if($content->matrix_composition!=""){
						$pdf->valueRow("Matrix composition",implode($content->matrix_composition, ", "),30 + $indent);
					}

					if($content->other_matrix_types!=""){
						$pdf->valueRow("Other Matrix Type(s)",$content->other_matrix_types,30 + $indent);
					}

					if($content->intrusive_igneous_matrix_percent!=""){
						$pdf->valueRow("Intrusive igneous matrix percent",$content->intrusive_igneous_matrix_percent,30 + $indent);
					}

					if($content->extrusive_igneous_matrix_percent!=""){
						$pdf->valueRow("Extrusive igneous matrix percent",$content->extrusive_igneous_matrix_percent,30 + $indent);
					}

					if($content->metamorphic_igneous_matrix_percent!=""){
						$pdf->valueRow("Metamorphic igneous matrix percent",$content->metamorphic_igneous_matrix_percent,30 + $indent);
					}

					if($content->mudstone_matrix_percent!=""){
						$pdf->valueRow("Mudstone matrix percent",$content->mudstone_matrix_percent,30 + $indent);
					}

					if($content->siltstone_matrix_percent!=""){
						$pdf->valueRow("Siltstone matrix percent",$content->siltstone_matrix_percent,30 + $indent);
					}

					if($content->sandstone_matrix_percent!=""){
						$pdf->valueRow("Sandstone matrix percent",$content->sandstone_matrix_percent,30 + $indent);
					}

					if($content->conglomerate_matrix_percent!=""){
						$pdf->valueRow("Conglomerate matrix percent",$content->conglomerate_matrix_percent,30 + $indent);
					}

					if($content->carbonate_matrix_type!=""){
						$pdf->valueRow("Carbonate Matrix Type",implode($content->carbonate_matrix_type, ", "),30 + $indent);
					}

					if($content->limestone_matrix_percent!=""){
						$pdf->valueRow("Limestone matrix percent",$content->limestone_matrix_percent,30 + $indent);
					}

					if($content->dolostone_matrix_percent!=""){
						$pdf->valueRow("Dolostone matrix percent",$content->dolostone_matrix_percent,30 + $indent);
					}

					if($content->skeletal_matrix_percent!=""){
						$pdf->valueRow("Skeletal matrix percent",$content->skeletal_matrix_percent,30 + $indent);
					}

					if($content->wackestone_matrix_percent!=""){
						$pdf->valueRow("Wackestone matrix percent",$content->wackestone_matrix_percent,30 + $indent);
					}

					if($content->packstone_matrix_percent!=""){
						$pdf->valueRow("Packstone matrix percent",$content->packstone_matrix_percent,30 + $indent);
					}

					if($content->grainstone_matrix_percent!=""){
						$pdf->valueRow("Grainstone matrix percent",$content->grainstone_matrix_percent,30 + $indent);
					}

					if($content->boundstone_matrix_percent!=""){
						$pdf->valueRow("Boundstone matrix percent",$content->boundstone_matrix_percent,30 + $indent);
					}

					if($content->other_carbonate_matrix_percent!=""){
						$pdf->valueRow("Other carbonate matrix percent",$content->other_carbonate_matrix_percent,30 + $indent);
					}

					if($content->other_matrix_percent!=""){
						$pdf->valueRow("Other matrix percent",$content->other_matrix_percent,30 + $indent);
					}

					if($content->volcaniclastic_type!=""){
						$pdf->valueRow("Volcaniclastic type",implode($content->volcaniclastic_type, ", "),30 + $indent);
					}

					if($content->other_volcaniclastic_type!=""){
						$pdf->valueRow("Other Volcaniclastic Type",$content->other_volcaniclastic_type,30 + $indent);
					}

					if($content->glass_percent!=""){
						$pdf->valueRow("Glass percent",$content->glass_percent,30 + $indent);
					}

					if($content->crystals_percent!=""){
						$pdf->valueRow("Crystals percent",$content->crystals_percent,30 + $indent);
					}

					if($content->lithic_fragments_percent!=""){
						$pdf->valueRow("Lithic fragments percent",$content->lithic_fragments_percent,30 + $indent);
					}

					if($content->volcanic_mudstone_percent!=""){
						$pdf->valueRow("Volcanic mudstone percent",$content->volcanic_mudstone_percent,30 + $indent);
					}

					if($content->volcanic_sandstone_percent!=""){
						$pdf->valueRow("Volcanic sandstone percent",$content->volcanic_sandstone_percent,30 + $indent);
					}

					if($content->lapillistone_percent!=""){
						$pdf->valueRow("Lapillistone percent",$content->lapillistone_percent,30 + $indent);
					}

					if($content->agglomerate_percent!=""){
						$pdf->valueRow("Agglomerate percent",$content->agglomerate_percent,30 + $indent);
					}

					if($content->volcanic_breccia_percent!=""){
						$pdf->valueRow("Volcanic breccia percent",$content->volcanic_breccia_percent,30 + $indent);
					}

					if($content->bentonite_percent!=""){
						$pdf->valueRow("Bentonite percent",$content->bentonite_percent,30 + $indent);
					}

					if($content->tuff_percent!=""){
						$pdf->valueRow("Tuff percent",$content->tuff_percent,30 + $indent);
					}

					if($content->welded_tuff_percent!=""){
						$pdf->valueRow("Welded tuff percent",$content->welded_tuff_percent,30 + $indent);
					}

					if($content->ignimbrite_percent!=""){
						$pdf->valueRow("Ignimbrite percent",$content->ignimbrite_percent,30 + $indent);
					}

					if($content->other_volcaniclastic_type_percent!=""){
						$pdf->valueRow("Other volcaniclastic type percent",$content->other_volcaniclastic_type_percent,30 + $indent);
					}

					if($content->evaporite_type!=""){
						$pdf->valueRow("Evaporite type",implode($content->evaporite_type, ", "),30 + $indent);
					}

					if($content->gypsum_anhydrite_primary_percent!=""){
						$pdf->valueRow("Gypsum - anhydrite primary percent",$content->gypsum_anhydrite_primary_percent,30 + $indent);
					}

					if($content->gypsum_anhydrite_primary_type!=""){
						$pdf->valueRow("Gypsum - anhydrite primary type",implode($content->gypsum_anhydrite_primary_type, ", "),30 + $indent);
					}

					if($content->gypsum_anhydrite_diagenetic_percent!=""){
						$pdf->valueRow("Gypsum - anhydrite diagenetic percent",$content->gypsum_anhydrite_diagenetic_percent,30 + $indent);
					}

					if($content->gypsum_anhydrite_diagenetic_type!=""){
						$pdf->valueRow("Gypsum - anhydrite diagenetic type",implode($content->gypsum_anhydrite_diagenetic_type, ", "),30 + $indent);
					}

					if($content->halite_primary_percent!=""){
						$pdf->valueRow("Halite - primary percent",$content->halite_primary_percent,30 + $indent);
					}

					if($content->halite_primary_type!=""){
						$pdf->valueRow("Halite - primary type",implode($content->halite_primary_type, ", "),30 + $indent);
					}

					if($content->halite_diagenetic_percent!=""){
						$pdf->valueRow("Halite - diagenetic percent",$content->halite_diagenetic_percent,30 + $indent);
					}

					if($content->halite_diagenetic_type!=""){
						$pdf->valueRow("Halite - diagenetic type",implode($content->halite_diagenetic_type, ", "),30 + $indent);
					}

					if($content->phosphorite_type!=""){
						$pdf->valueRow("Phosphorite type",implode($content->phosphorite_type, ", "),30 + $indent);
					}

					if($content->organic_coal_lithologies!=""){
						$pdf->valueRow("Organic/Coal Lithologies",implode($content->organic_coal_lithologies, ", "),30 + $indent);
					}

					if($content->amber_percent!=""){
						$pdf->valueRow("Amber percent",$content->amber_percent,30 + $indent);
					}

					if($content->peat_percent!=""){
						$pdf->valueRow("Peat percent",$content->peat_percent,30 + $indent);
					}

					if($content->lignite_percent!=""){
						$pdf->valueRow("Lignite percent",$content->lignite_percent,30 + $indent);
					}

					if($content->subbituminous_percent!=""){
						$pdf->valueRow("Subbituminous percent",$content->subbituminous_percent,30 + $indent);
					}

					if($content->bituminous_percent!=""){
						$pdf->valueRow("Bituminous percent",$content->bituminous_percent,30 + $indent);
					}

					if($content->coal_ball_percent!=""){
						$pdf->valueRow("Coal ball percent",$content->coal_ball_percent,30 + $indent);
					}

					if($content->tar_percent!=""){
						$pdf->valueRow("Tar percent",$content->tar_percent,30 + $indent);
					}

					if($content->notes!=""){
						$pdf->valueRow("Notes",$content->notes,30 + $indent);
					}

					$pdf->valueTitle("Texture: ", 25 + $indent);

					if($content->mud_silt_grain_size!=""){
						$pdf->valueRow("Mudstone/Siltstone Grain Size",$content->mud_silt_grain_size,30 + $indent);
					}

					if($content->sand_grain_size!=""){
						$pdf->valueRow("Sandstone Grain Size",$content->sand_grain_size,30 + $indent);
					}

					if($content->congl_grain_size!=""){
						$pdf->valueRow("Conglomerate Grain Size",$content->congl_grain_size,30 + $indent);
					}

					if($content->breccia_grain_size!=""){
						$pdf->valueRow("Breccia Grain Size",$content->breccia_grain_size,30 + $indent);
					}

					if($content->grain_size_range!=""){
						$pdf->valueRow("Grain Size Range",implode($content->grain_size_range, ", "),30 + $indent);
					}

					if($content->clay_percent!=""){
						$pdf->valueRow("Clay percent",$content->clay_percent,30 + $indent);
					}

					if($content->silt_percent!=""){
						$pdf->valueRow("Silt percent",$content->silt_percent,30 + $indent);
					}

					if($content->sand_very_fine_percent!=""){
						$pdf->valueRow("Sand - very fine percent",$content->sand_very_fine_percent,30 + $indent);
					}

					if($content->sand_fine_lower_percent!=""){
						$pdf->valueRow("Sand - fine lower percent",$content->sand_fine_lower_percent,30 + $indent);
					}

					if($content->sand_fine_upper_percent!=""){
						$pdf->valueRow("Sand - fine upper percent",$content->sand_fine_upper_percent,30 + $indent);
					}

					if($content->sand_medium_lower_percent!=""){
						$pdf->valueRow("Sand - medium lower percent",$content->sand_medium_lower_percent,30 + $indent);
					}

					if($content->sand_medium_upper_percent!=""){
						$pdf->valueRow("Sand - medium upper percent",$content->sand_medium_upper_percent,30 + $indent);
					}

					if($content->sand_coarse_lower_percent!=""){
						$pdf->valueRow("Sand - coarse lower percent",$content->sand_coarse_lower_percent,30 + $indent);
					}

					if($content->sand_coarse_upper_percent!=""){
						$pdf->valueRow("Sand - coarse upper percent",$content->sand_coarse_upper_percent,30 + $indent);
					}

					if($content->sand_very_coarse_percent!=""){
						$pdf->valueRow("Sand - very coarse percent",$content->sand_very_coarse_percent,30 + $indent);
					}

					if($content->granule_percent!=""){
						$pdf->valueRow("Granule percent",$content->granule_percent,30 + $indent);
					}

					if($content->pebble_percent!=""){
						$pdf->valueRow("Pebble percent",$content->pebble_percent,30 + $indent);
					}

					if($content->cobble_percent!=""){
						$pdf->valueRow("Cobble percent",$content->cobble_percent,30 + $indent);
					}

					if($content->boulder_percent!=""){
						$pdf->valueRow("Boulder percent",$content->boulder_percent,30 + $indent);
					}

					if($content->maximum_clast_size_cm!=""){
						$pdf->valueRow("Maximum Clast Size (cm)",$content->maximum_clast_size_cm,30 + $indent);
					}

					if($content->minimum_clast_size_cm!=""){
						$pdf->valueRow("Minimum Clast Size (cm)",$content->minimum_clast_size_cm,30 + $indent);
					}

					if($content->average_clast_size_cm!=""){
						$pdf->valueRow("Average Clast Size (cm)",$content->average_clast_size_cm,30 + $indent);
					}

					if($content->matrix_size!=""){
						$pdf->valueRow("Matrix Size",implode($content->matrix_size, ", "),30 + $indent);
					}

					if($content->character!=""){
						$pdf->valueRow("Character",implode($content->character, ", "),30 + $indent);
					}

					if($content->sorting!=""){
						$pdf->valueRow("Sorting",implode($content->sorting, ", "),30 + $indent);
					}

					if($content->rounding!=""){
						$pdf->valueRow("Rounding",implode($content->rounding, ", "),30 + $indent);
					}

					if($content->shape!=""){
						$pdf->valueRow("Shape",implode($content->shape, ", "),30 + $indent);
					}

					if($content->other_shape!=""){
						$pdf->valueRow("Other Shape",$content->other_shape,30 + $indent);
					}

					if($content->notes!=""){
						$pdf->valueRow("Notes",$content->notes,30 + $indent);
					}

					if($content->stratification!=""){
						$pdf->valueRow("Stratification",implode($content->stratification, ", "),30 + $indent);
					}

					if($content->laminae_thickness_i_select_more_than_one!=""){
						$pdf->valueRow("Laminae Thickness",implode($content->laminae_thickness_i_select_more_than_one, ", "),30 + $indent);
					}

					if($content->lamination_character!=""){
						$pdf->valueRow("Lamination Character",implode($content->lamination_character, ", "),30 + $indent);
					}

					if($content->bedding_thickness!=""){
						$pdf->valueRow("Bedding Thickness",implode($content->bedding_thickness, ", "),30 + $indent);
					}

					if($content->bedding_character!=""){
						$pdf->valueRow("Bedding character",implode($content->bedding_character, ", "),30 + $indent);
					}

					if($content->package_geometry!=""){
						$pdf->valueRow("Package Geometry",implode($content->package_geometry, ", "),30 + $indent);
					}

					if($content->package_lateral_extent!=""){
						$pdf->valueRow("Package Lateral Extent",$content->package_lateral_extent,30 + $indent);
					}

					if($content->package_bedding_trends!=""){
						$pdf->valueRow("Package Bedding Trends",$content->package_bedding_trends,30 + $indent);
					}

					if($content->other_bedding_trend!=""){
						$pdf->valueRow("Other bedding trend",$content->other_bedding_trend,30 + $indent);
					}

					if($content->shape_of_lower_contacts!=""){
						$pdf->valueRow("Shape of lower contact(s)",implode($content->shape_of_lower_contacts, ", "),30 + $indent);
					}

					if($content->character_of_lower_contacts!=""){
						$pdf->valueRow("Character of lower contact(s)",implode($content->character_of_lower_contacts, ", "),30 + $indent);
					}

					if($content->lower_contact_relief!=""){
						$pdf->valueRow("Lower contact relief",$content->lower_contact_relief,30 + $indent);
					}

					if($content->shape_of_upper_contacts!=""){
						$pdf->valueRow("Shape of upper contact(s)",implode($content->shape_of_upper_contacts, ", "),30 + $indent);
					}

					if($content->character_of_upper_contacts!=""){
						$pdf->valueRow("Character of upper contact(s)",implode($content->character_of_upper_contacts, ", "),30 + $indent);
					}

					if($content->upper_contact_relief!=""){
						$pdf->valueRow("Upper Contact Relief",$content->upper_contact_relief,30 + $indent);
					}

					if($content->interbed_proportion!=""){
						$pdf->valueRow("Interbed Relative Proportion (%)",$content->interbed_proportion,30 + $indent);
					}

					if($content->interbed_thickness!=""){
						$pdf->valueRow("Interbed Thickness",$content->interbed_thickness,30 + $indent);
					}

					if($content->interbed_proportion_change!=""){
						$pdf->valueRow("Interbed Proportion Change (Up Section)",$content->interbed_proportion_change,30 + $indent);
					}

					if($content->interbed_thickness_change!=""){
						$pdf->valueRow("Interbed Thickness Change (Up Section)",$content->interbed_thickness_change,30 + $indent);
					}

					if($content->Notes!=""){
						$pdf->valueRow("Notes",$content->Notes,30 + $indent);
					}

				}

			}

			if($spot['sed']->bedding){
				$pdf->valueTitle("Bedding: ", 20 + $indent);
				$content = $spot['sed']->bedding;

				if($content->interbed_proportion_change!=""){
					$pdf->valueRow("Interbed Proportion Change (Up Section)",$content->interbed_proportion_change,25 + $indent);
				}

				if($content->interbed_proportion!=""){
					$pdf->valueRow("Lithology 1: Interbed Relative Proportion (%)",$content->interbed_proportion,25 + $indent);
				}

				if($content->lithology_at_bottom_contact!=""){
					$pdf->valueRow("Which Lithology is at Bottom Contact?",$content->lithology_at_bottom_contact,25 + $indent);
				}

				if($content->lithology_at_top_contact!=""){
					$pdf->valueRow("Which Lithology is at Top Contact?",$content->lithology_at_top_contact,25 + $indent);
				}

				if($content->thickness_of_individual_beds!=""){
					$pdf->valueRow("Thickness of Individual Beds",$content->thickness_of_individual_beds,25 + $indent);
				}

				if($content->package_thickness_units!=""){
					$pdf->valueRow("Package Beds Thickness Units",$content->package_thickness_units,25 + $indent);
				}

				if($content->package_bedding_trends!=""){
					$pdf->valueRow("Package Bedding Trends",$content->package_bedding_trends,25 + $indent);
				}

				if($content->other_bedding_trend!=""){
					$pdf->valueRow("Other Package Bedding Trend",$content->other_bedding_trend,25 + $indent);
				}

				$beds = $spot['sed']->bedding->beds;
				foreach($beds as $content){

					if($content->package_geometry!=""){
						$pdf->valueRow("Bed Geometry",implode($content->package_geometry, ", "),25 + $indent);
					}

					$pdf->valueTitle("Lower Contact: ", 25 + $indent);

					if($content->shape_of_lower_contacts!=""){
						$pdf->valueRow("Shape of lower contact",implode($content->shape_of_lower_contacts, ", "),30 + $indent);
					}

					if($content->character_of_lower_contacts!=""){
						$pdf->valueRow("Character of lower contact(s)",implode($content->character_of_lower_contacts, ", "),30 + $indent);
					}

					if($content->lower_contact_relief!=""){
						$pdf->valueRow("Lower contact relief",$content->lower_contact_relief,30 + $indent);
					}

					$pdf->valueTitle("Upper Contact: ", 25 + $indent);

					if($content->shape_of_upper_contacts!=""){
						$pdf->valueRow("Shape of upper contact",implode($content->shape_of_upper_contacts, ", "),30 + $indent);
					}

					if($content->character_of_upper_contacts!=""){
						$pdf->valueRow("Character of upper contact",implode($content->character_of_upper_contacts, ", "),30 + $indent);
					}

					if($content->upper_contact_relief!=""){
						$pdf->valueRow("Upper Contact Relief",$content->upper_contact_relief,30 + $indent);
					}

					$pdf->valueTitle("Interbed Thickness: ", 25 + $indent);

					if($content->avg_thickness!=""){
						$pdf->valueRow("Average Thickness",$content->avg_thickness,30 + $indent);
					}

					if($content->max_thickness!=""){
						$pdf->valueRow("Maximum Thickness",$content->max_thickness,30 + $indent);
					}

					if($content->min_thickness!=""){
						$pdf->valueRow("Minimum Thickness",$content->min_thickness,30 + $indent);
					}

					if($content->interbed_thickness_units!=""){
						$pdf->valueRow("Interbed Thickness Units",$content->interbed_thickness_units,30 + $indent);
					}

					if($content->notes!=""){
						$pdf->valueRow("Notes",$content->notes,30 + $indent);
					}

				}
			}

			if($spot['sed']->structures){
				$pdf->valueTitle("Structures: ", 20 + $indent);
				$contents = $spot['sed']->structures;
				foreach($contents as $content){

					if($content->massive_structureless!=""){
						$pdf->valueRow("Massive/Structureless?",$content->massive_structureless,25 + $indent);
					}

					$pdf->valueTitle("Cross Bedding: ", 25 + $indent);

					if($content->cross_bedding_type!=""){
						$pdf->valueRow("Cross Bedding Type",implode($content->cross_bedding_type, ", "),30 + $indent);
					}

					if($content->cross_bedding_height_cm!=""){
						$pdf->valueRow("Cross Bedding Height (cm)",$content->cross_bedding_height_cm,30 + $indent);
					}

					if($content->cross_bedding_width_cm!=""){
						$pdf->valueRow("Cross Bedding Width (cm)",$content->cross_bedding_width_cm,30 + $indent);
					}

					if($content->cross_bedding_thickness_cm!=""){
						$pdf->valueRow("Cross Bedding Thickness (cm)",$content->cross_bedding_thickness_cm,30 + $indent);
					}

					if($content->cross_bedding_spacing_cm!=""){
						$pdf->valueRow("Cross Bedding Spacing (cm)",$content->cross_bedding_spacing_cm,30 + $indent);
					}

					$pdf->valueTitle("Ripple Lamination: ", 25 + $indent);

					if($content->ripple_lamination_type!=""){
						$pdf->valueRow("Ripple Lamination Type",implode($content->ripple_lamination_type, ", "),30 + $indent);
					}

					if($content->other_ripple_lamination_type!=""){
						$pdf->valueRow("Other Ripple Lamination Type",$content->other_ripple_lamination_type,30 + $indent);
					}

					if($content->ripple_lamination_height_mm!=""){
						$pdf->valueRow("Ripple Lamination Height (mm)",$content->ripple_lamination_height_mm,30 + $indent);
					}

					if($content->ripple_lamination_width_mm!=""){
						$pdf->valueRow("Ripple Lamination Width (mm)",$content->ripple_lamination_width_mm,30 + $indent);
					}

					if($content->ripple_lamination_thick_mm!=""){
						$pdf->valueRow("Ripple Lmation Thickness (mm)",$content->ripple_lamination_thick_mm,30 + $indent);
					}

					if($content->ripple_lamination_spacing_mm!=""){
						$pdf->valueRow("Ripple Lamination Spacing (mm)",$content->ripple_lamination_spacing_mm,30 + $indent);
					}

					$pdf->valueTitle("Horizontal Bedding: ", 25 + $indent);

					if($content->horizontal_bedding_type!=""){
						$pdf->valueRow("Horizontal Bedding Type",implode($content->horizontal_bedding_type, ", "),30 + $indent);
					}

					if($content->other_horizontal_bedding_type!=""){
						$pdf->valueRow("Other Horizontal Bedding Type",$content->other_horizontal_bedding_type,30 + $indent);
					}

					$pdf->valueTitle("Graded Bedding: ", 25 + $indent);

					if($content->graded_bedding_type!=""){
						$pdf->valueRow("Graded Bedding Type",$content->graded_bedding_type,30 + $indent);
					}

					$pdf->valueTitle("Deformation Structures: ", 25 + $indent);

					if($content->deformation_structures!=""){
						$pdf->valueRow("Deformation Structure Type",implode($content->deformation_structures, ", "),30 + $indent);
					}

					if($content->other_deformation_structure_type!=""){
						$pdf->valueRow("Other Deformation Structure Type",$content->other_deformation_structure_type,30 + $indent);
					}

					$pdf->valueTitle("Lags: ", 25 + $indent);

					if($content->lag_type!=""){
						$pdf->valueRow("Lag Type",implode($content->lag_type, ", "),30 + $indent);
					}

					if($content->other_lag_type!=""){
						$pdf->valueRow("Other Lag Type",$content->other_lag_type,30 + $indent);
					}

					if($content->clast_composition!=""){
						$pdf->valueRow("Clast Composition",$content->clast_composition,30 + $indent);
					}

					if($content->clast_size!=""){
						$pdf->valueRow("Clast Size",$content->clast_size,30 + $indent);
					}

					if($content->layer_thickness_shape!=""){
						$pdf->valueRow("Layer Thickness/Shape",$content->layer_thickness_shape,30 + $indent);
					}

					$pdf->valueTitle("Other Common Structures: ", 25 + $indent);

					if($content->other_common_structures!=""){
						$pdf->valueRow("Other Common Structure Type",implode($content->other_common_structures, ", "),30 + $indent);
					}

					if($content->bouma_sequence_part!=""){
						$pdf->valueRow("Bouma Sequence Part",implode($content->bouma_sequence_part, ", "),30 + $indent);
					}

					if($content->bioturbation_index!=""){
						$pdf->valueRow("Bioturbation Index",$content->bioturbation_index,30 + $indent);
					}

					if($content->bedding_plane_features!=""){
						$pdf->valueRow("Bedding plane features",implode($content->bedding_plane_features, ", "),30 + $indent);
					}

					if($content->other_bedding_plane_feature!=""){
						$pdf->valueRow("Other Bedding Plane Feature",$content->other_bedding_plane_feature,30 + $indent);
					}

					if($content->bedding_plane_features_scale!=""){
						$pdf->valueRow("Bedding Plane Features Scale",$content->bedding_plane_features_scale,30 + $indent);
					}

					if($content->bedding_plane_features_orientation!=""){
						$pdf->valueRow("Bedding Plane Features Orientation",$content->bedding_plane_features_orientation,30 + $indent);
					}

					if($content->bedform_type!=""){
						$pdf->valueRow("Bedform Type",implode($content->bedform_type, ", "),30 + $indent);
					}

					if($content->other_bedform_type!=""){
						$pdf->valueRow("Other Bedform Type",$content->other_bedform_type,30 + $indent);
					}

					if($content->bedform_scale!=""){
						$pdf->valueRow("Bedform Scale",$content->bedform_scale,30 + $indent);
					}

					if($content->crest_orientation_azimuth_0_360!=""){
						$pdf->valueRow("Crest Orientation",$content->crest_orientation_azimuth_0_360,30 + $indent);
					}

					$pdf->valueTitle("Pedogenic Structures: ", 25 + $indent);

					if($content->paleosol_horizons!=""){
						$pdf->valueRow("Master Paleosol Horizons",implode($content->paleosol_horizons, ", "),30 + $indent);
					}

					if($content->other_horizon!=""){
						$pdf->valueRow("Other Horizon",$content->other_horizon,30 + $indent);
					}

					if($content->o_horizon_thickness_cm!=""){
						$pdf->valueRow("O Horizon thickness (cm)",$content->o_horizon_thickness_cm,30 + $indent);
					}

					if($content->a_horizon_thickness_cm!=""){
						$pdf->valueRow("A Horizon thickness (cm)",$content->a_horizon_thickness_cm,30 + $indent);
					}

					if($content->e_horizon_thickness_cm!=""){
						$pdf->valueRow("E Horizon thickness (cm)",$content->e_horizon_thickness_cm,30 + $indent);
					}

					if($content->b_horizon_thickness_cm!=""){
						$pdf->valueRow("B Horizon thickness (cm)",$content->b_horizon_thickness_cm,30 + $indent);
					}

					if($content->k_horizon_thickness_cm!=""){
						$pdf->valueRow("K Horizon thickness (cm)",$content->k_horizon_thickness_cm,30 + $indent);
					}

					if($content->c_horizon_thickness_cm!=""){
						$pdf->valueRow("C Horizon thickness (cm)",$content->c_horizon_thickness_cm,30 + $indent);
					}

					if($content->r_horizon_thickness_cm!=""){
						$pdf->valueRow("R Horizon thickness (cm)",$content->r_horizon_thickness_cm,30 + $indent);
					}

					if($content->compound_thickness_cm!=""){
						$pdf->valueRow("Compound thickness (cm)",$content->compound_thickness_cm,30 + $indent);
					}

					if($content->composite_thickness_cm!=""){
						$pdf->valueRow("Composite thickness (cm)",$content->composite_thickness_cm,30 + $indent);
					}

					if($content->paleosol_structures!=""){
						$pdf->valueRow("Paleosol structures",implode($content->paleosol_structures, ", "),30 + $indent);
					}

					if($content->other_structure!=""){
						$pdf->valueRow("Other Paleosol Structure",$content->other_structure,30 + $indent);
					}

					if($content->additional_modifiers!=""){
						$pdf->valueRow("Additional modifiers",$content->additional_modifiers,30 + $indent);
					}

					if($content->paleosol_classification!=""){
						$pdf->valueRow("Paleosol classification",implode($content->paleosol_classification, ", "),30 + $indent);
					}

					if($content->notes!=""){
						$pdf->valueRow("Notes",$content->notes,30 + $indent);
					}

				}

			}

			if($spot['sed']->diagenesis){
				$pdf->valueTitle("Diagenesis: ", 20 + $indent);
				$contents = $spot['sed']->diagenesis;
				foreach($contents as $content){

					$pdf->valueTitle("Cement: ", 25 + $indent);

					if($content->cement_composition!=""){
						$pdf->valueRow("Cement Mineralogy",implode($content->cement_composition, ", "),30 + $indent);
					}

					if($content->other_cement_composition!=""){
						$pdf->valueRow("Other Cement Mineralogy",$content->other_cement_composition,30 + $indent);
					}

					$pdf->valueTitle("Veins: ", 25 + $indent);

					if($content->vein_type!=""){
						$pdf->valueRow("Vein Type",$content->vein_type,30 + $indent);
					}

					if($content->vein_width!=""){
						$pdf->valueRow("Vein Width (cm)",$content->vein_width,30 + $indent);
					}

					if($content->vein_length!=""){
						$pdf->valueRow("Vein Length (cm)",$content->vein_length,30 + $indent);
					}

					if($content->vein_orientation!=""){
						$pdf->valueRow("Vein Orientation",$content->vein_orientation,30 + $indent);
					}

					if($content->vein_mineralogy!=""){
						$pdf->valueRow("Vein Mineralogy",$content->vein_mineralogy,30 + $indent);
					}

					if($content->other_vein_mineralogy!=""){
						$pdf->valueRow("Other Vein Mineralogy",$content->other_vein_mineralogy,30 + $indent);
					}

					$pdf->valueTitle("Fractures: ", 25 + $indent);

					if($content->fracture_type!=""){
						$pdf->valueRow("Fracture Type",$content->fracture_type,30 + $indent);
					}

					if($content->fracture_width!=""){
						$pdf->valueRow("Fracture Width (cm)",$content->fracture_width,30 + $indent);
					}

					if($content->fracture_length!=""){
						$pdf->valueRow("Fracture Length (cm)",$content->fracture_length,30 + $indent);
					}

					if($content->fracture_orientation!=""){
						$pdf->valueRow("Fracture Orientation",$content->fracture_orientation,30 + $indent);
					}

					if($content->fracture_mineralogy!=""){
						$pdf->valueRow("Fracture Mineralogy",$content->fracture_mineralogy,30 + $indent);
					}

					if($content->other_fracture_mineralogy!=""){
						$pdf->valueRow("Other Fracture Mineralogy",$content->other_fracture_mineralogy,30 + $indent);
					}

					$pdf->valueTitle("Nodules/Concretions: ", 25 + $indent);

					if($content->nodules_concretions_size!=""){
						$pdf->valueRow("Nodules/Concretions Size",$content->nodules_concretions_size,30 + $indent);
					}

					if($content->min!=""){
						$pdf->valueRow("Min",$content->min,30 + $indent);
					}

					if($content->max!=""){
						$pdf->valueRow("Max",$content->max,30 + $indent);
					}

					if($content->average!=""){
						$pdf->valueRow("Average",$content->average,30 + $indent);
					}

					if($content->nodules_concretions_shape!=""){
						$pdf->valueRow("Nodules/Concretions Shape",implode($content->nodules_concretions_shape, ", "),30 + $indent);
					}

					if($content->other_nodules_concretion_shape!=""){
						$pdf->valueRow("Other Nodule/Concretions Shape",$content->other_nodules_concretion_shape,30 + $indent);
					}

					if($content->spacing!=""){
						$pdf->valueRow("Spacing",$content->spacing,30 + $indent);
					}

					if($content->nodules_concretions_type!=""){
						$pdf->valueRow("Nodules/Concretions Type",$content->nodules_concretions_type,30 + $indent);
					}

					if($content->other_nodules_concretions_type!=""){
						$pdf->valueRow("Other Nodules/Concretions Type",$content->other_nodules_concretions_type,30 + $indent);
					}

					if($content->nodules_concretions_comp!=""){
						$pdf->valueRow("Nodules/Concretions Composition",implode($content->nodules_concretions_comp, ", "),30 + $indent);
					}

					if($content->other_nodules_concretion_comp!=""){
						$pdf->valueRow("Other Nodules/Concretions Composition",$content->other_nodules_concretion_comp,30 + $indent);
					}

					$pdf->valueTitle("Replacement: ", 25 + $indent);

					if($content->replacement_type!=""){
						$pdf->valueRow("Replacement Type",$content->replacement_type,30 + $indent);
					}

					if($content->other_replacement_type!=""){
						$pdf->valueRow("Other Replacement Type",$content->other_replacement_type,30 + $indent);
					}

					$pdf->valueTitle("Recrystallization: ", 25 + $indent);

					if($content->recrystallization_type!=""){
						$pdf->valueRow("Recrystallization Type",$content->recrystallization_type,30 + $indent);
					}

					if($content->other_recrystallization_type!=""){
						$pdf->valueRow("Other Recrystallization Type",$content->other_recrystallization_type,30 + $indent);
					}

					$pdf->valueTitle("Other Diagenetic Features: ", 25 + $indent);

					if($content->other_diagenetic_features!=""){
						$pdf->valueRow("Other Diagenetic Features",implode($content->other_diagenetic_features, ", "),30 + $indent);
					}

					if($content->other_features!=""){
						$pdf->valueRow("Other Features",$content->other_features,30 + $indent);
					}

					$pdf->valueTitle("Porosity type: ", 25 + $indent);

					if($content->fabric_selective!=""){
						$pdf->valueRow("Fabric Selective",implode($content->fabric_selective, ", "),30 + $indent);
					}

					if($content->other_fabric_selective!=""){
						$pdf->valueRow("Other Fabric Selective",$content->other_fabric_selective,30 + $indent);
					}

					if($content->non_selective!=""){
						$pdf->valueRow("Non-Frabric Selective",implode($content->non_selective, ", "),30 + $indent);
					}

					if($content->other_non_selective!=""){
						$pdf->valueRow("Other Non-Fabric Selective",$content->other_non_selective,30 + $indent);
					}

					$pdf->valueTitle("Carbonate Desiccation and Dissolution: ", 25 + $indent);

					if($content->carbonate_desicc_and_diss!=""){
						$pdf->valueRow("Carbonate Desiccation and Dissolution Type",implode($content->carbonate_desicc_and_diss, ", "),30 + $indent);
					}

					if($content->other_carbonate_desicc_diss!=""){
						$pdf->valueRow("Other Carbonate Desiccation and Dissolution Type",$content->other_carbonate_desicc_diss,30 + $indent);
					}

					if($content->notes!=""){
						$pdf->valueRow("Notes",$content->notes,30 + $indent);
					}

				}
			}

			if($spot['sed']->fossils){
				$pdf->valueTitle("Fossils: ", 20 + $indent);
				$contents = $spot['sed']->fossils;
				foreach($contents as $content){

					$pdf->valueTitle("Body: ", 25 + $indent);

					if($content->invertebrate!=""){
						$pdf->valueRow("Invertebrate",implode($content->invertebrate, ", "),30 + $indent);
					}

					if($content->other_invertebrate!=""){
						$pdf->valueRow("Other Invertebrate",$content->other_invertebrate,30 + $indent);
					}

					if($content->mollusca!=""){
						$pdf->valueRow("Mollusc",implode($content->mollusca, ", "),30 + $indent);
					}

					if($content->other_mollusca!=""){
						$pdf->valueRow("Other Mollusc",$content->other_mollusca,30 + $indent);
					}

					if($content->arthropoda!=""){
						$pdf->valueRow("Arthropod",implode($content->arthropoda, ", "),30 + $indent);
					}

					if($content->other_anthropoda!=""){
						$pdf->valueRow("Other Arthropod",$content->other_anthropoda,30 + $indent);
					}

					if($content->echinodermata!=""){
						$pdf->valueRow("Echinoderm",implode($content->echinodermata, ", "),30 + $indent);
					}

					if($content->other_echinodermata!=""){
						$pdf->valueRow("Other Echinoderm",$content->other_echinodermata,30 + $indent);
					}

					if($content->cnidaria!=""){
						$pdf->valueRow("Cnidarian",implode($content->cnidaria, ", "),30 + $indent);
					}

					if($content->other_cnidaria!=""){
						$pdf->valueRow("Other Cnidarian",$content->other_cnidaria,30 + $indent);
					}

					if($content->chordate!=""){
						$pdf->valueRow("Chordate",$content->chordate,30 + $indent);
					}

					if($content->other_chordata!=""){
						$pdf->valueRow("Other Chordate",$content->other_chordata,30 + $indent);
					}

					if($content->protista!=""){
						$pdf->valueRow("Protist",implode($content->protista, ", "),30 + $indent);
					}

					if($content->other_protista!=""){
						$pdf->valueRow("Other Protist",$content->other_protista,30 + $indent);
					}

					if($content->calcimicrobe!=""){
						$pdf->valueRow("Calcimicrobe",implode($content->calcimicrobe, ", "),30 + $indent);
					}

					if($content->other_calcimicrobe!=""){
						$pdf->valueRow("Other Calcimicrobe",$content->other_calcimicrobe,30 + $indent);
					}

					if($content->plant_algae!=""){
						$pdf->valueRow("Plant/algae",implode($content->plant_algae, ", "),30 + $indent);
					}

					if($content->other_plant_algae!=""){
						$pdf->valueRow("Other Plant/Algae",$content->other_plant_algae,30 + $indent);
					}

					if($content->green_algae!=""){
						$pdf->valueRow("Green Algae",implode($content->green_algae, ", "),30 + $indent);
					}

					if($content->other_green_algae!=""){
						$pdf->valueRow("Other Green Algae",$content->other_green_algae,30 + $indent);
					}

					if($content->vertebrate!=""){
						$pdf->valueRow("Vertebrate",implode($content->vertebrate, ", "),30 + $indent);
					}

					if($content->other_vertebrate!=""){
						$pdf->valueRow("Other Vertebrate",$content->other_vertebrate,30 + $indent);
					}

					if($content->faunal_assemblage!=""){
						$pdf->valueRow("Faunal assemblage",$content->faunal_assemblage,30 + $indent);
					}

					if($content->other_faunal_assemblage!=""){
						$pdf->valueRow("Other faunal assemblage",$content->other_faunal_assemblage,30 + $indent);
					}

					$pdf->valueTitle("Trace: ", 25 + $indent);

					if($content->diversity!=""){
						$pdf->valueRow("Diversity",$content->diversity,30 + $indent);
					}

					if($content->descriptive!=""){
						$pdf->valueRow("Descriptive",implode($content->descriptive, ", "),30 + $indent);
					}

					if($content->other_descriptive!=""){
						$pdf->valueRow("Other Descriptive",$content->other_descriptive,30 + $indent);
					}

					if($content->burrow_fill_type!=""){
						$pdf->valueRow("Burrow Fill Type",implode($content->burrow_fill_type, ", "),30 + $indent);
					}

					if($content->other_burrow_fill!=""){
						$pdf->valueRow("Other Burrow Fill Type",$content->other_burrow_fill,30 + $indent);
					}

					if($content->behavioral_grouping!=""){
						$pdf->valueRow("Behavioral grouping",$content->behavioral_grouping,30 + $indent);
					}

					if($content->other_behavioral_grouping!=""){
						$pdf->valueRow("Other Behavioral Grouping",$content->other_behavioral_grouping,30 + $indent);
					}

					if($content->ichnofacies!=""){
						$pdf->valueRow("Ichnofacies",$content->ichnofacies,30 + $indent);
					}

					if($content->other_ichnofacies!=""){
						$pdf->valueRow("Other Ichnofacies",$content->other_ichnofacies,30 + $indent);
					}

					if($content->list_of_specific_types!=""){
						$pdf->valueRow("List of specific types",$content->list_of_specific_types,30 + $indent);
					}

					$pdf->valueTitle("Biogenic Growth Structures: ", 25 + $indent);

					if($content->dominant_component!=""){
						$pdf->valueRow("Dominant component",$content->dominant_component,30 + $indent);
					}

					if($content->other_dominant_component!=""){
						$pdf->valueRow("Other Dominant Component",$content->other_dominant_component,30 + $indent);
					}

					if($content->microbial_reef_or_skelatal_mic!=""){
						$pdf->valueRow("Microbial Reef or Skeletal-Microbial Reef Type",implode($content->microbial_reef_or_skelatal_mic, ", "),30 + $indent);
					}

					if($content->other_microbial_or_skeletal_mi!=""){
						$pdf->valueRow("Other Microbial or Skeletal Microbial Reef",$content->other_microbial_or_skeletal_mi,30 + $indent);
					}

					if($content->mud_mound!=""){
						$pdf->valueRow("Mud Mound Type",implode($content->mud_mound, ", "),30 + $indent);
					}

					if($content->other_mud_mound!=""){
						$pdf->valueRow("Other Mud Mound",$content->other_mud_mound,30 + $indent);
					}

					$pdf->valueTitle("Biogenic Growth Structure Scale and Orientation: ", 25 + $indent);

					if($content->height!=""){
						$pdf->valueRow("Height",$content->height,30 + $indent);
					}

					if($content->width!=""){
						$pdf->valueRow("Width",$content->width,30 + $indent);
					}

					if($content->shape!=""){
						$pdf->valueRow("Shape",$content->shape,30 + $indent);
					}

					if($content->type!=""){
						$pdf->valueRow("Type",$content->type,30 + $indent);
					}

					if($content->other_type!=""){
						$pdf->valueRow("Other Type",$content->other_type,30 + $indent);
					}

					if($content->accessory_structures!=""){
						$pdf->valueRow("Accessory Structures",implode($content->accessory_structures, ", "),30 + $indent);
					}

					if($content->other_accessory_structure!=""){
						$pdf->valueRow("Other Accessory Structure",$content->other_accessory_structure,30 + $indent);
					}

					if($content->notes!=""){
						$pdf->valueRow("Notes",$content->notes,30 + $indent);
					}
				}
			}

			if($spot['sed']->interpretations){
				$pdf->valueTitle("Interpretations: ", 20 + $indent);
				$contents = $spot['sed']->interpretations;
				foreach($contents as $content){

					$pdf->valueTitle("Process Interpretation: ", 25 + $indent);

					if($content->energy!=""){
						$pdf->valueRow("Energy",$content->energy,30 + $indent);
					}

					if($content->other_energy!=""){
						$pdf->valueRow("Other Energy",$content->other_energy,30 + $indent);
					}

					if($content->sediment_transport!=""){
						$pdf->valueRow("Sediment Transport",implode($content->sediment_transport, ", "),30 + $indent);
					}

					if($content->other_sediment_transport!=""){
						$pdf->valueRow("Other Sediment Transport",$content->other_sediment_transport,30 + $indent);
					}

					if($content->fluidization!=""){
						$pdf->valueRow("Fluidization",$content->fluidization,30 + $indent);
					}

					if($content->other_fluidization!=""){
						$pdf->valueRow("Other Fluidization",$content->other_fluidization,30 + $indent);
					}

					if($content->miscellaneous!=""){
						$pdf->valueRow("Miscellaneous",implode($content->miscellaneous, ", "),30 + $indent);
					}

					if($content->other_miscellaneous!=""){
						$pdf->valueRow("Other Misc. Process",$content->other_miscellaneous,30 + $indent);
					}

					$pdf->valueTitle("Environment Interpretation: ", 25 + $indent);

					if($content->general!=""){
						$pdf->valueRow("General",implode($content->general, ", "),30 + $indent);
					}

					if($content->clastic!=""){
						$pdf->valueRow("Clastic",implode($content->clastic, ", "),30 + $indent);
					}

					if($content->alluvial_fan_environments!=""){
						$pdf->valueRow("Alluvial fan environments",implode($content->alluvial_fan_environments, ", "),30 + $indent);
					}

					if($content->other_alluvial_fan!=""){
						$pdf->valueRow("Other Alluvial Fan",$content->other_alluvial_fan,30 + $indent);
					}

					if($content->eolian_environments!=""){
						$pdf->valueRow("Eolian environments",implode($content->eolian_environments, ", "),30 + $indent);
					}

					if($content->other_eolian!=""){
						$pdf->valueRow("Other Eolian",$content->other_eolian,30 + $indent);
					}

					if($content->fluvial_environments!=""){
						$pdf->valueRow("Fluvial environments",implode($content->fluvial_environments, ", "),30 + $indent);
					}

					if($content->other_fluvial!=""){
						$pdf->valueRow("Other Fluvial",$content->other_fluvial,30 + $indent);
					}

					if($content->shallow_marine_clastic_environ!=""){
						$pdf->valueRow("Shallow marine clastic environments",implode($content->shallow_marine_clastic_environ, ", "),30 + $indent);
					}

					if($content->other_shallow_marine!=""){
						$pdf->valueRow("Other Shallow Marine",$content->other_shallow_marine,30 + $indent);
					}

					if($content->deep_marine_environments!=""){
						$pdf->valueRow("Deep marine environments",implode($content->deep_marine_environments, ", "),30 + $indent);
					}

					if($content->other_deep_marine!=""){
						$pdf->valueRow("Other Deep Marine",$content->other_deep_marine,30 + $indent);
					}

					if($content->glacial_and_proglacial_environ!=""){
						$pdf->valueRow("Glacial and proglacial environments",implode($content->glacial_and_proglacial_environ, ", "),30 + $indent);
					}

					if($content->other_glacial!=""){
						$pdf->valueRow("Other Glacial",$content->other_glacial,30 + $indent);
					}

					if($content->lake_environments!=""){
						$pdf->valueRow("Lake environments",implode($content->lake_environments, ", "),30 + $indent);
					}

					if($content->other_lake!=""){
						$pdf->valueRow("Other Lake",$content->other_lake,30 + $indent);
					}

					if($content->other_clastic!=""){
						$pdf->valueRow("Other Clastic",$content->other_clastic,30 + $indent);
					}

					if($content->carbonates!=""){
						$pdf->valueRow("Carbonates",implode($content->carbonates, ", "),30 + $indent);
					}

					if($content->factory!=""){
						$pdf->valueRow("Factory",implode($content->factory, ", "),30 + $indent);
					}

					if($content->carbonate!=""){
						$pdf->valueRow("Environment",implode($content->carbonate, ", "),30 + $indent);
					}

					if($content->other_carbonate_environment!=""){
						$pdf->valueRow("Other Carbonate Environment",$content->other_carbonate_environment,30 + $indent);
					}

					if($content->lake_subenvironments!=""){
						$pdf->valueRow("Lake Subenvironments",implode($content->lake_subenvironments, ", "),30 + $indent);
					}

					if($content->other_carbonate_lake_subenvironment!=""){
						$pdf->valueRow("Other Carbonate Lake Subenvironment",$content->other_carbonate_lake_subenvironment,30 + $indent);
					}

					if($content->tidal_flat_subenvironments!=""){
						$pdf->valueRow("Tidal Flat Subenvironments",implode($content->tidal_flat_subenvironments, ", "),30 + $indent);
					}

					if($content->other_tidal_flat!=""){
						$pdf->valueRow("Other Tidal Flat",$content->other_tidal_flat,30 + $indent);
					}

					if($content->reef_subenvironments!=""){
						$pdf->valueRow("Reef Subenvironments",implode($content->reef_subenvironments, ", "),30 + $indent);
					}

					if($content->other_reef!=""){
						$pdf->valueRow("Other Reef",$content->other_reef,30 + $indent);
					}

					if($content->detailed_carbonate_env_interpr!=""){
						$pdf->valueRow("Detailed carbonate environmental interpretations",$content->detailed_carbonate_env_interpr,30 + $indent);
					}

					if($content->tectonic_setting!=""){
						$pdf->valueRow("Tectonic Setting",implode($content->tectonic_setting, ", "),30 + $indent);
					}

					if($content->other_tectonic_setting!=""){
						$pdf->valueRow("Other Tectonic Setting",$content->other_tectonic_setting,30 + $indent);
					}

					$pdf->valueTitle("Sedimentary Surfaces (for line spots only): ", 25 + $indent);

					if($content->geometry!=""){
						$pdf->valueRow("Geometry",$content->geometry,30 + $indent);
					}

					if($content->relief!=""){
						$pdf->valueRow("Relief",$content->relief,30 + $indent);
					}

					if($content->relief_scale!=""){
						$pdf->valueRow("Relief Scale",$content->relief_scale,30 + $indent);
					}

					if($content->extent!=""){
						$pdf->valueRow("Extent",$content->extent,30 + $indent);
					}

					if($content->extent_scale!=""){
						$pdf->valueRow("Extent Scale",$content->extent_scale,30 + $indent);
					}

					if($content->type!=""){
						$pdf->valueRow("Type",implode($content->type, ", "),30 + $indent);
					}

					if($content->other_type!=""){
						$pdf->valueRow("Other Type",$content->other_type,30 + $indent);
					}

					if($content->stratal_termination!=""){
						$pdf->valueRow("Stratal Termination",$content->stratal_termination,30 + $indent);
					}

					$pdf->valueTitle("Sedimentary Surface Interpretation: ", 25 + $indent);

					if($content->general_surfaces!=""){
						$pdf->valueRow("General Surfaces",$content->general_surfaces,30 + $indent);
					}

					if($content->sequence_stratigraphic_surfaces!=""){
						$pdf->valueRow("Sequence Stratigraphic Surfaces",$content->sequence_stratigraphic_surfaces,30 + $indent);
					}

					if($content->other_sequence_stratigraphic_surface!=""){
						$pdf->valueRow("Other Sequence Stratigraphic Surface",$content->other_sequence_stratigraphic_surface,30 + $indent);
					}

					if($content->named!=""){
						$pdf->valueRow("Named",$content->named,30 + $indent);
					}

					$pdf->valueTitle("Architecture Interpretation: ", 25 + $indent);

					if($content->description!=""){
						$pdf->valueRow("Description",implode($content->description, ", "),30 + $indent);
					}

					if($content->stacking_sequence_stratigraphy!=""){
						$pdf->valueRow("Stacking/Sequence Stratigraphy",implode($content->stacking_sequence_stratigraphy, ", "),30 + $indent);
					}

					if($content->other_stacking_sequence_stratigraphy!=""){
						$pdf->valueRow("Other Stacking/Sequence Stratigraphy",$content->other_stacking_sequence_stratigraphy,30 + $indent);
					}

					if($content->fluvial_architectural_elements!=""){
						$pdf->valueRow("Fluvial Architectural Elements",implode($content->fluvial_architectural_elements, ", "),30 + $indent);
					}

					if($content->other_fluvial_element!=""){
						$pdf->valueRow("Other Fluvial Element",$content->other_fluvial_element,30 + $indent);
					}

					if($content->lacustrine_architecture_interpretation!=""){
						$pdf->valueRow("Lacustrine Architecture Interpretation",implode($content->lacustrine_architecture_interpretation, ", "),30 + $indent);
					}

					if($content->other_lacustrine_architecture_interpretation!=""){
						$pdf->valueRow("Other Lacustrine Architecture Interpretation",$content->other_lacustrine_architecture_interpretation,30 + $indent);
					}

					if($content->carbonate_platform_geometry!=""){
						$pdf->valueRow("Carbonate Platform Geometry",implode($content->carbonate_platform_geometry, ", "),30 + $indent);
					}

					if($content->other_platform_geometry!=""){
						$pdf->valueRow("Other Platform Geometry",$content->other_platform_geometry,30 + $indent);
					}

					if($content->deep_water_architctural_element!=""){
						$pdf->valueRow("Deep-Water Architectural Elements",implode($content->deep_water_architctural_element, ", "),30 + $indent);
					}

					if($content->other_deep_water_architectural_element!=""){
						$pdf->valueRow("Other Deep-Water Architectural Element",$content->other_deep_water_architectural_element,30 + $indent);
					}

					if($content->carbonate_margin_geometry!=""){
						$pdf->valueRow("Carbonate Margin Geometry",implode($content->carbonate_margin_geometry, ", "),30 + $indent);
					}

					if($content->other_carbonate_margin_geometry!=""){
						$pdf->valueRow("Other Carbonate Margin Geometry",$content->other_carbonate_margin_geometry,30 + $indent);
					}

					if($content->notes!=""){
						$pdf->valueRow("Notes",$content->notes,30 + $indent);
					}

				}
			}

		}

		if($spot['pet']){
			if($spot['pet']->metamorphic){
				$pdf->valueTitle("Metamorphic Rock(s): ", 15 + $indent);
				$rocks = $spot['pet']->metamorphic;

				$rockNum = 1;
				foreach($rocks as $r){
					$rockString = "";
					$rockString .= ", " . $r->metamorphic_rock_type;
					$rockString .= ", " . implode(", ", $r->facies);
					$rockString .= ", " . $r->protolith;
					$rockString .= ", " . implode(", ", $r->zone);

					$pdf->valueRow($rockNum, $rockString, 20 + $indent);
					$rockNum++;
				}

				if($content->section_well_name!=""){
					$pdf->valueRow("Section/Well Name",$content->section_well_name,25 + $indent);
				}
			}

			if($spot['pet']->igneous){
				$pdf->valueTitle("Igneous Rock(s): ", 15 + $indent);
				$rocks = $spot['pet']->igneous;

				$rockNum = 1;
				foreach($rocks as $r){
					$rockString = "";
					if($r->igneous_rock_class) $rockString .= ", " . $r->igneous_rock_class;
					if($r->volcanic_rock_type) $rockString .= ", " . $r->volcanic_rock_type;
					if($r->occurence_volcanic) $rockString .= ", " . $r->occurence_volcanic;
					if($r->plutonic_rock_type) $rockString .= ", " . $r->plutonic_rock_type;
					if($r->occurence_plutonic) $rockString .= ", " . $r->occurence_plutonic;
					if($r->texture_volcanic) $rockString .= ", " . implode(", ", $r->texture_volcanic);
					if($r->texture_plutonic) $rockString .= ", " . implode(", ", $r->texture_plutonic);
					if($r->color_index_volc) $rockString .= ", " . $r->color_index_volc;
					if($r->color_index_pluton) $rockString .= ", " . $r->color_index_pluton;
					if($r->color_index_source_volc) $rockString .= ", " . $r->color_index_source_volc;
					if($r->color_index_source_pluton) $rockString .= ", " . $r->color_index_source_pluton;
					if($r->alteration_volcanic) $rockString .= ", " . implode(", ", $r->alteration_volcanic);
					if($r->alteration_plutonic) $rockString .= ", " . implode(", ", $r->alteration_plutonic);

					$pdf->valueRow($rockNum, $rockString, 20 + $indent);
					$rockNum++;
				}

				if($content->section_well_name!=""){
					$pdf->valueRow("Section/Well Name",$content->section_well_name,25 + $indent);
				}
			}

			if($spot['pet']->minerals){
				$pdf->valueTitle("Mineral(s): ", 15 + $indent);
				$rocks = $spot['pet']->minerals;

				$rockNum = 1;
				foreach($rocks as $r){
					$rockString = "";
					if($r->full_mineral_name) $rockString .= ", " . $r->full_mineral_name;
					if($r->igneous_or_metamorphic == "ig_min"){
						$rockString .= " (Igneous)";
					}else{
						$rockString .= " (Metamorphic)";
					}
					if($r->average_grain_size_mm) $rockString .= ", Avg Size: " . $r->average_grain_size_mm . "mm";
					if($r->maximum_grain_size_mm) $rockString .= ", Max Size: " . $r->maximum_grain_size_mm . "mm";
					if($r->modal) $rockString .= ", Modal: " . $r->modal . "%";
					if($r->mineral_notes) $rockString .= " " . $r->mineral_notes;

					$pdf->valueRow($rockNum, $rockString, 20 + $indent);
					$rockNum++;
				}

				if($content->section_well_name!=""){
					$pdf->valueRow("Section/Well Name",$content->section_well_name,25 + $indent);
				}
			}

		}

		if($spot['images']){

			$pdf->valueRow("Images","",15 + $indent);
			$pdf->Ln(1);
			foreach($spot['images'] as $o){
				if($o['title']){
					$thistitle = $this->fixLabel($o['title']);
					$pdf->valueTitle($thistitle.": ",20 + $indent);
				}else{
					$thistitle = $o['id'];
				}

				foreach($o as $key=>$value){
					if($value != ""){
						if($key!="id" && $key!="self" && $key!="annotated" && $key!="title" && $key!="width" && $key!="height" && $key!="image_type" && $key!="caption" ){
							$key = $this->fixLabel($key);
							if(is_string($value)){
								$value = $this->fixLabel($value);
							}
							$pdf->valueRow($key,$value,20 + $indent);
						}
					}
				}

				$pdf->Ln(1);

				$filename = $this->strabo->getImageFilename($o['id']);

				if($filename){
					$gdimage = $this->gdThumbWithSpots($filename, $o['id'], $allspots);
					if($gdimage){
						$pdf->GDImage($gdimage, 20, null, 170); //60
					}
				}

				if(trim($o['caption']) != ""){
					$pdf->imageCaptionRow("Caption", $o['caption'], 20 + $indent);
				}

				//Add spots on image basemap here. Figure out how to indent all options.

				$imagehasspots = false;
				foreach($allspots as $imagespot){
					$imagespot = $imagespot['properties'];
					if($imagespot['image_basemap'] == $o['id']){
						$imagehasspots = true;
					}

				}

				if($imagehasspots){

					//add "spots on basemap"
					$pdf->largeValue("Spots on Basemap:", $indent + 20);

					foreach($allspots as $imagespot){

						$imagespot = $imagespot['properties'];

						if($imagespot['image_basemap'] == $o['id']){
							$this->addSpotToPDF($pdf, $imagespot, $allspots, $indent + 10);
						}

					}
				}

			}
		}

		$pdf->Ln(5);

	}

	public function addSpotToPDF(&$pdf, &$spot, &$allspots, $indent = 0){

		$rawspot = $spot;

		$spot = $spot['properties'];

		$id = $spot['id'];

		//Move all of this to its own function.

		$spotname = $spot['name'];
		if($spot['geometrytype']){
			$spotname .= " (".$spot['geometrytype'].")";
		}

		$pdf->spotTitle($spotname, 15 + $indent);

		$modified = (string) $spot['id'];
		$modified = substr($modified,0,10);
		$modified = date("F j, Y",$modified);
		$pdf->valueRow("Created",$modified,15 + $indent);

		$modified = (string) $spot['modified_timestamp'];
		$modified = substr($modified,0,10);
		$modified = date("F j, Y",$modified);
		$pdf->valueRow("Last Modified",$modified,15 + $indent);

		if($rawspot['geometry']->type=="Point" && $rawspot['properties']['strat_section_id']==""){
			$pdf->valueRow("Longitude",$rawspot['geometry']->coordinates[0],15 + $indent);
			$pdf->valueRow("Latitude",$rawspot['geometry']->coordinates[1],15 + $indent);
		}

		if($spot['notes']){
			$notes = $spot['notes'];
			$pdf->notesRow("Notes",$notes,15 + $indent);
		}

		if($spot['surface_feature']){
			foreach($spot['surface_feature'] as $key=>$value){
				$key = $this->fixLabel($key);
				if(is_string($value)){
					$value = $this->fixLabel($value);
				}
				$pdf->valueRow($key,$value,15 + $indent);
			}
		}

		if($spot['trace']){
			foreach($spot['trace'] as $key=>$value){
				if($key != "trace_feature"){
					$key = $this->fixLabel($key);
					if(is_string($value)){
						$value = $this->fixLabel($value);
					}
					$pdf->valueRow($key,$value,15 + $indent);
				}
			}
		}

		if($spot['orientation_data']){
			$pdf->valueRow("Orientations","",15 + $indent);
			foreach($spot['orientation_data'] as $o){
				$pdf->valueTitle($this->fixLabel($o->type).": ",20 + $indent);
				foreach($o as $key=>$value){
					if($key!="id" && $key!="associated_orientation" && $key!="type"){
						$key = $this->fixLabel($key);
						if(is_string($value)){
							$value = $this->fixLabel($value);
						}
						$pdf->valueRow($key,$value,20 + $indent);
					}
				}

				if($o->associated_orientation){
					$pdf->valueRow("Associated Orientation Data","",20 + $indent);
					foreach($o->associated_orientation as $ao){
						$pdf->valueTitle($this->fixLabel($ao->type).": ",30 + $indent);
						foreach($ao as $key=>$value){
							if($key!="id" && $key!="associated_orientation" && $key!="type"){
								$key = $this->fixLabel($key);
								if(is_string($value)){
									$value = $this->fixLabel($value);
								}
								$pdf->valueRow($key,$value,30 + $indent);
							}
						}
						$pdf->Ln(1);
					}
				}

				$pdf->Ln(1);
			}
		}

		if($spot['_3d_structures']){
			$pdf->valueRow("3D Structures","",15 + $indent);
			foreach($spot['_3d_structures'] as $o){
				$pdf->valueTitle($this->fixLabel($o->type).": ",20 + $indent);
				foreach($o as $key=>$value){
					if($key!="id" && $key!="type"){
						$key = $this->fixLabel($key);
						if(is_string($value)){
							$value = $this->fixLabel($value);
						}
						$pdf->valueRow($key,$value,20 + $indent);
					}
				}

				$pdf->Ln(1);
			}
		}

		if($spot['samples']){
			$pdf->valueRow("Samples","",15 + $indent);
			foreach($spot['samples'] as $o){
				$pdf->valueTitle($this->fixLabel($o->label).": ",20 + $indent);
				foreach($o as $key=>$value){
					if($key!="id" && $key!="label"){
						$key = $this->fixLabel($key);
						if(is_string($value)){
							$value = $this->fixLabel($value);
						}
						$pdf->valueRow($key,$value,20 + $indent);
					}
				}

				$pdf->Ln(1);
			}
		}

		if($spot['tephra']){
			$pdf->valueRow("Tephra Intervals","",15 + $indent);
			foreach($spot['tephra'] as $o){
				$pdf->valueTitle("Interval: ",20 + $indent);
				foreach($o as $key=>$value){
					if($key!="id" && $key!="label"){
						$key = $this->fixLabel($key);
						if(is_string($value)){
							$value = $this->fixLabel($value);
						}
						$pdf->valueRow($key,$value,20 + $indent);
					}
				}

				$pdf->Ln(1);
			}
		}

		if($spot['other_features']){
			$pdf->valueRow("Other Features","",15 + $indent);
			foreach($spot['other_features'] as $o){
				$pdf->valueTitle($this->fixLabel($o->label).": ",20 + $indent);
				foreach($o as $key=>$value){
					if($key!="id" && $key!="label"){
						$key = $this->fixLabel($key);
						if(is_string($value)){
							$value = $this->fixLabel($value);
						}
						$pdf->valueRow($key,$value,20 + $indent);
					}
				}

				$pdf->Ln(1);
			}
		}

		$hastags = "no";

		if($this->alltags){
			foreach($this->alltags as $tag){
				$found = "no";
				if($tag->spots){
					if($tag->type!="geologic_unit"){
						foreach($tag->spots as $spotid){
							if($spotid == $id){
								$hastags = "yes";
							}
						}
					}
				}

			}
		}

		if($hastags == "yes"){

			$pdf->valueRow("Tags","",15 + $indent);

			if($this->alltags){
				foreach($this->alltags as $tag){
					$found = "no";
					if($tag->spots){
						if($tag->type!="geologic_unit"){
							foreach($tag->spots as $spotid){
								if($spotid == $id){
									$found = "yes";
								}
							}
						}
					}

					if($found == "yes"){

						$pdf->valueTitle($tag->name,20 + $indent);
						foreach($tag as $key=>$value){

							if($key != "date" && $key != "spots" && $key != "features" && $key != "id" && $key != "name" ){
								$key = $this->fixLabel($key);
								if(is_string($value)){
									$value = $this->fixLabel($value);
								}elseif(is_array($value)){
									$value = implode(", ", $value);
								}
								$pdf->valueRow($key,$value,20 + $indent);
							}

						}

						$pdf->Ln(1);

					}
				}
			}

		}

		$hastags = "no";

		if($this->alltags){
			foreach($this->alltags as $tag){
				$found = "no";
				if($tag->spots){
					if($tag->type=="geologic_unit"){
						foreach($tag->spots as $spotid){
							if($spotid == $id){
								$hastags = "yes";
							}
						}
					}
				}

			}
		}

		if($hastags == "yes"){

			$pdf->valueRow("Geologic Unit(s)","",15 + $indent);

			if($this->alltags){
				foreach($this->alltags as $tag){
					$found = "no";
					if($tag->spots){
						if($tag->type=="geologic_unit"){
							foreach($tag->spots as $spotid){
								if($spotid == $id){
									$found = "yes";
								}
							}
						}
					}

					if($found == "yes"){

						$pdf->valueTitle($tag->name,20 + $indent);
						foreach($tag as $key=>$value){

							if($key != "date" && $key != "spots" && $key != "features" && $key != "id" && $key != "name" ){
								$key = $this->fixLabel($key);
								if(is_string($value)){
									$value = $this->fixLabel($value);
								}elseif(is_array($value)){
									$value = implode(", ", $value);
								}
								$pdf->valueRow($key,$value,20 + $indent);
							}

						}

						$pdf->Ln(1);

					}
				}
			}

		}

		if($spot['sed']){
			$pdf->valueRow("Sed","",15 + $indent);
			if($spot['sed']->strat_section){
				$pdf->valueTitle("Strat Section: ", 20 + $indent);
				$content = $spot['sed']->strat_section;

				if($content->section_well_name!=""){
					$pdf->valueRow("Section/Well Name",$content->section_well_name,25 + $indent);
				}

				if($content->column_profile!=""){
					$pdf->valueRow("Column Profile",$content->column_profile,25 + $indent);
				}

				if($content->column_y_axis_units!=""){
					$pdf->valueRow("Column Y-Axis Units",$content->column_y_axis_units,25 + $indent);
				}

				if($content->section_type!=""){
					$pdf->valueRow("Section Type",$content->section_type,25 + $indent);
				}

				if($content->what_core_repository!=""){
					$pdf->valueRow("What Core Repository?",$content->what_core_repository,25 + $indent);
				}

				if($content->type_of_corer!=""){
					$pdf->valueRow("Type of corer",$content->type_of_corer,25 + $indent);
				}

				if($content->depth_from_surface_to_start_of!=""){
					$pdf->valueRow("Depth from surface to start of core",$content->depth_from_surface_to_start_of,25 + $indent);
				}

				if($content->total_core_length!=""){
					$pdf->valueRow("Total core length",$content->total_core_length,25 + $indent);
				}

				if($content->location_locality!=""){
					$pdf->valueRow("Location/Locality",$content->location_locality,25 + $indent);
				}

				if($content->basin!=""){
					$pdf->valueRow("Basin",$content->basin,25 + $indent);
				}

				if($content->age!=""){
					$pdf->valueRow("Age",$content->age,25 + $indent);
				}

				if($content->purpose!=""){
					$pdf->valueRow("Purpose",implode($content->purpose, ", "),25 + $indent);
				}

				if($content->other_purpose!=""){
					$pdf->valueRow("Other Purpose",$content->other_purpose,25 + $indent);
				}

				if($content->project_description!=""){
					$pdf->valueRow("Project Description",$content->project_description,25 + $indent);
				}

				if($content->dates_of_work!=""){
					$pdf->valueRow("Dates of Work",$content->dates_of_work,25 + $indent);
				}

				if($content->scale_of_interest!=""){
					$pdf->valueRow("Scale of Interest",implode($content->scale_of_interest, ", "),25 + $indent);
				}

				if($content->other_scale_of_interest!=""){
					$pdf->valueRow("Other Scale of Interest",$content->other_scale_of_interest,25 + $indent);
				}

				if($content->obs_interval_bed_obs_scale!=""){
					$pdf->valueRow("Observation Interval (average bed/observation scale)",$content->obs_interval_bed_obs_scale,25 + $indent);
				}

				if($content->how_is_section_georeferenced!=""){
					$pdf->valueRow("How is the Section Georeferenced?",$content->how_is_section_georeferenced,25 + $indent);
				}

				if($content->strat_section_notes!=""){
					$pdf->valueRow("Notes",$content->strat_section_notes,25 + $indent);
				}

				//strat section link here
				$pdf->httpLink("Download Strat Section", 25, "https://strabospot.org/pstrat_section?id=".$spot['id']."&did=".$dsids);

			}

			if($spot['sed']->lithologies){
				$pdf->valueTitle("Lithologies: ", 20 + $indent);
				$contents = $spot['sed']->lithologies;
				foreach($contents as $content){
					$pdf->valueTitle("Primary Lithology: ", 25 + $indent);

					if($content->primary_lithology!=""){
						$pdf->valueRow("Primary Lithology",$content->primary_lithology,30 + $indent);
					}

					if($content->siliciclastic_type!=""){
						$pdf->valueRow("Siliciclastic Type",$content->siliciclastic_type,30 + $indent);
					}

					if($content->dunham_classification!=""){
						$pdf->valueRow("Dunham Classification",$content->dunham_classification,30 + $indent);
					}

					if($content->grain_type!=""){
						$pdf->valueRow("Grain Type",$content->grain_type,30 + $indent);
					}

					if($content->evaporite_type!=""){
						$pdf->valueRow("Evaporite type",implode($content->evaporite_type, ", "),30 + $indent);
					}

					if($content->other_evaporite_type!=""){
						$pdf->valueRow("Other Evaporite Type",$content->other_evaporite_type,30 + $indent);
					}

					if($content->organic_coal_lithologies!=""){
						$pdf->valueRow("Organic/Coal Lithologies",implode($content->organic_coal_lithologies, ", "),30 + $indent);
					}

					if($content->other_organic_coal_lithology!=""){
						$pdf->valueRow("Other Organic/Coal Lithology",$content->other_organic_coal_lithology,30 + $indent);
					}

					if($content->volcaniclastic_type!=""){
						$pdf->valueRow("Volcaniclastic type",implode($content->volcaniclastic_type, ", "),30 + $indent);
					}

					if($content->other_volcaniclastic_type!=""){
						$pdf->valueRow("Other Volcaniclastic Type",$content->other_volcaniclastic_type,30 + $indent);
					}

					if($content->report_presence_of_particle_ag!=""){
						$pdf->valueRow("Report presence of particle aggregates ",$content->report_presence_of_particle_ag,30 + $indent);
					}

					if($content->componentry!=""){
						$pdf->valueRow("Componentry",implode($content->componentry, ", "),30 + $indent);
					}

					if($content->approximate_relative_abundance!=""){
						$pdf->valueRow("Approximate relative abundances of clasts",$content->approximate_relative_abundance,30 + $indent);
					}

					if($content->phosphorite_type!=""){
						$pdf->valueRow("Phosphorite type",implode($content->phosphorite_type, ", "),30 + $indent);
					}

					if($content->other_phosphorite_type!=""){
						$pdf->valueRow("Other Phosphorite Type",$content->other_phosphorite_type,30 + $indent);
					}

					$pdf->valueTitle("Lithification & Color: ", 25 + $indent);

					if($content->relative_resistance_weather!=""){
						$pdf->valueRow("Relative resistance (weathering profile)",$content->relative_resistance_weather,30 + $indent);
					}

					if($content->lithification!=""){
						$pdf->valueRow("Lithification",$content->lithification,30 + $indent);
					}

					if($content->evidence_of_deposit_alteration!=""){
						$pdf->valueRow("Evidence of deposit alteration",$content->evidence_of_deposit_alteration,30 + $indent);
					}

					if($content->evidence_of_clast_alteration!=""){
						$pdf->valueRow("Evidence of clast alteration",$content->evidence_of_clast_alteration,30 + $indent);
					}

					if($content->fresh_color!=""){
						$pdf->valueRow("Fresh Color",$content->fresh_color,30 + $indent);
					}

					if($content->weathered_color!=""){
						$pdf->valueRow("Weathered Color",$content->weathered_color,30 + $indent);
					}

					if($content->color_appearance!=""){
						$pdf->valueRow("Color Appearance",implode($content->color_appearance, ", "),30 + $indent);
					}

					if($content->other_color_appearance!=""){
						$pdf->valueRow("Other Color Appearance",$content->other_color_appearance,30 + $indent);
					}

					if($content->notes!=""){
						$pdf->valueRow("Notes",$content->notes,30 + $indent);
					}

					$pdf->valueTitle("Composition: ", 25 + $indent);

					if($content->minerals_present!=""){
						$pdf->valueRow("Minerals Present",implode($content->minerals_present, ", "),30 + $indent);
					}

					if($content->other_minerals!=""){
						$pdf->valueRow("Other Mineral(s)",$content->other_minerals,30 + $indent);
					}

					if($content->sandstone_type_dott!=""){
						$pdf->valueRow("Dott Classification",implode($content->sandstone_type_dott, ", "),30 + $indent);
					}

					if($content->sandstone_type_folk_mcbride!=""){
						$pdf->valueRow("Folk/McBride Classification",implode($content->sandstone_type_folk_mcbride, ", "),30 + $indent);
					}

					if($content->sandstone_modifier!=""){
						$pdf->valueRow("Sandstone modifier",implode($content->sandstone_modifier, ", "),30 + $indent);
					}

					if($content->other_sandstone_modifier!=""){
						$pdf->valueRow("Other Sandstone Modifier",$content->other_sandstone_modifier,30 + $indent);
					}

					if($content->skeletal_carbonate_components!=""){
						$pdf->valueRow("Skeletal Carbonate Components",implode($content->skeletal_carbonate_components, ", "),30 + $indent);
					}

					if($content->other_skeletal_carbonate_component!=""){
						$pdf->valueRow("Other Skeletal Carbonate Component",$content->other_skeletal_carbonate_component,30 + $indent);
					}

					if($content->skeletal_general_percent!=""){
						$pdf->valueRow("Skeletal (General) percent",$content->skeletal_general_percent,30 + $indent);
					}

					if($content->mollusc_percent!=""){
						$pdf->valueRow("Mollusc percent",$content->mollusc_percent,30 + $indent);
					}

					if($content->brachiopod_percent!=""){
						$pdf->valueRow("Brachiopod percent",$content->brachiopod_percent,30 + $indent);
					}

					if($content->coral_percent!=""){
						$pdf->valueRow("Coral percent",$content->coral_percent,30 + $indent);
					}

					if($content->echinoderm_percent!=""){
						$pdf->valueRow("Echinoderm percent",$content->echinoderm_percent,30 + $indent);
					}

					if($content->bryozoan_percent!=""){
						$pdf->valueRow("Bryozoan percent",$content->bryozoan_percent,30 + $indent);
					}

					if($content->calcareous_algae_percent!=""){
						$pdf->valueRow("Calcareous Algae percent",$content->calcareous_algae_percent,30 + $indent);
					}

					if($content->foraminifera_percent!=""){
						$pdf->valueRow("Foraminifera percent",$content->foraminifera_percent,30 + $indent);
					}

					if($content->stromatolite_percent!=""){
						$pdf->valueRow("Stromatolite percent",$content->stromatolite_percent,30 + $indent);
					}

					if($content->thrombolite_percent!=""){
						$pdf->valueRow("Thrombolite percent",$content->thrombolite_percent,30 + $indent);
					}

					if($content->dendrolite_percent!=""){
						$pdf->valueRow("Dendrolite percent",$content->dendrolite_percent,30 + $indent);
					}

					if($content->leiolite_percent!=""){
						$pdf->valueRow("Leiolite percent",$content->leiolite_percent,30 + $indent);
					}

					if($content->other_skeletal_carbonate_component_percent!=""){
						$pdf->valueRow("Other Skeletal Carbonate Component percent",$content->other_skeletal_carbonate_component_percent,30 + $indent);
					}

					if($content->non_skeletal_carbonate_compone!=""){
						$pdf->valueRow("Non-Skeletal Carbonate Components",implode($content->non_skeletal_carbonate_compone, ", "),30 + $indent);
					}

					if($content->other_non_skeletal_carbonate_component!=""){
						$pdf->valueRow("Other Non-Skeletal Carbonate Component",$content->other_non_skeletal_carbonate_component,30 + $indent);
					}

					if($content->mud_percent!=""){
						$pdf->valueRow("Mud percent",$content->mud_percent,30 + $indent);
					}

					if($content->cement_percent!=""){
						$pdf->valueRow("Cement percent",$content->cement_percent,30 + $indent);
					}

					if($content->intraclast_percent!=""){
						$pdf->valueRow("Intraclast percent",$content->intraclast_percent,30 + $indent);
					}

					if($content->peloid_percent!=""){
						$pdf->valueRow("Peloid percent",$content->peloid_percent,30 + $indent);
					}

					if($content->ooid_percent!=""){
						$pdf->valueRow("Ooid percent",$content->ooid_percent,30 + $indent);
					}

					if($content->oncoid_percent!=""){
						$pdf->valueRow("Oncoid percent",$content->oncoid_percent,30 + $indent);
					}

					if($content->pisoid_percent!=""){
						$pdf->valueRow("Pisoid percent",$content->pisoid_percent,30 + $indent);
					}

					if($content->coated_grian_percent!=""){
						$pdf->valueRow("Coated Grian percent",$content->coated_grian_percent,30 + $indent);
					}

					if($content->grapestone_percent!=""){
						$pdf->valueRow("Grapestone percent",$content->grapestone_percent,30 + $indent);
					}

					if($content->giant_ooid_percent!=""){
						$pdf->valueRow("Giant Ooid percent",$content->giant_ooid_percent,30 + $indent);
					}

					if($content->seafloor_precipitate_percent!=""){
						$pdf->valueRow("Seafloor Precipitate percent",$content->seafloor_precipitate_percent,30 + $indent);
					}

					if($content->molar_tooth_percent!=""){
						$pdf->valueRow("Molar Tooth percent",$content->molar_tooth_percent,30 + $indent);
					}

					if($content->other_non_skeletal_component_percent!=""){
						$pdf->valueRow("Other Non-Skeletal Component percent",$content->other_non_skeletal_component_percent,30 + $indent);
					}

					if($content->clay_or_mudstone_type!=""){
						$pdf->valueRow("Claystone or Mudstone Type",implode($content->clay_or_mudstone_type, ", "),30 + $indent);
					}

					if($content->other_claystone_mudstone!=""){
						$pdf->valueRow("Other Claystone/Mudstone",$content->other_claystone_mudstone,30 + $indent);
					}

					if($content->siliceous_mudstone_percent!=""){
						$pdf->valueRow("Siliceous mudstone percent",$content->siliceous_mudstone_percent,30 + $indent);
					}

					if($content->siliceous_calcareous_mudstone_percent!=""){
						$pdf->valueRow("Siliceous calcareous mudstone percent",$content->siliceous_calcareous_mudstone_percent,30 + $indent);
					}

					if($content->siliceous_volcanicla_tic_mudstone_percent!=""){
						$pdf->valueRow("Siliceous volcaniclastic mudstone percent",$content->siliceous_volcanicla_tic_mudstone_percent,30 + $indent);
					}

					if($content->calcareous_mudstone_percent!=""){
						$pdf->valueRow("Calcareous mudstone percent",$content->calcareous_mudstone_percent,30 + $indent);
					}

					if($content->black_shale_percent!=""){
						$pdf->valueRow("Black shale percent",$content->black_shale_percent,30 + $indent);
					}

					if($content->red_clay_percent!=""){
						$pdf->valueRow("Red clay percent",$content->red_clay_percent,30 + $indent);
					}

					if($content->red_mudstone_percent!=""){
						$pdf->valueRow("Red mudstone percent",$content->red_mudstone_percent,30 + $indent);
					}

					if($content->green_mudstone_percent!=""){
						$pdf->valueRow("Green mudstone percent",$content->green_mudstone_percent,30 + $indent);
					}

					if($content->variegated_mudstone_percent!=""){
						$pdf->valueRow("Variegated mudstone percent",$content->variegated_mudstone_percent,30 + $indent);
					}

					if($content->marl_percent!=""){
						$pdf->valueRow("Marl percent",$content->marl_percent,30 + $indent);
					}

					if($content->sarl_percent!=""){
						$pdf->valueRow("Sarl percent",$content->sarl_percent,30 + $indent);
					}

					if($content->argillaceous_mudstone_percent!=""){
						$pdf->valueRow("Argillaceous mudstone percent",$content->argillaceous_mudstone_percent,30 + $indent);
					}

					if($content->conglomerate_composition!=""){
						$pdf->valueRow("Conglomerate/Breccia Composition",implode($content->conglomerate_composition, ", "),30 + $indent);
					}

					if($content->clast_composition!=""){
						$pdf->valueRow("Clast Composition",implode($content->clast_composition, ", "),30 + $indent);
					}

					if($content->other_clast_types!=""){
						$pdf->valueRow("Other Clast Type(s)",$content->other_clast_types,30 + $indent);
					}

					if($content->intrusive_igneous_clast_percent!=""){
						$pdf->valueRow("Intrusive igneous clast percent",$content->intrusive_igneous_clast_percent,30 + $indent);
					}

					if($content->extrusive_igneous_clast_percent!=""){
						$pdf->valueRow("Volcanic clast percent",$content->extrusive_igneous_clast_percent,30 + $indent);
					}

					if($content->metamorphic_clast_percent!=""){
						$pdf->valueRow("Metamorphic clast percent",$content->metamorphic_clast_percent,30 + $indent);
					}

					if($content->mudstone_clast_percent!=""){
						$pdf->valueRow("Mudstone clast percent",$content->mudstone_clast_percent,30 + $indent);
					}

					if($content->siltstone_clast_percent!=""){
						$pdf->valueRow("Siltstone clast percent",$content->siltstone_clast_percent,30 + $indent);
					}

					if($content->sandstone_clast_percent!=""){
						$pdf->valueRow("Sandstone clast percent",$content->sandstone_clast_percent,30 + $indent);
					}

					if($content->conglomerate_clast_percent!=""){
						$pdf->valueRow("Conglomerate clast percent",$content->conglomerate_clast_percent,30 + $indent);
					}

					if($content->limestone_clast_percent!=""){
						$pdf->valueRow("Limestone clast percent",$content->limestone_clast_percent,30 + $indent);
					}

					if($content->dolostone_clast_percent!=""){
						$pdf->valueRow("Dolostone clast percent",$content->dolostone_clast_percent,30 + $indent);
					}

					if($content->wackestone_clast_percent!=""){
						$pdf->valueRow("Wackestone clast percent",$content->wackestone_clast_percent,30 + $indent);
					}

					if($content->packstone_clast_percent!=""){
						$pdf->valueRow("Packstone clast percent",$content->packstone_clast_percent,30 + $indent);
					}

					if($content->grainstone_clast_percent!=""){
						$pdf->valueRow("Grainstone clast percent",$content->grainstone_clast_percent,30 + $indent);
					}

					if($content->boundstone_clast_percent!=""){
						$pdf->valueRow("Boundstone clast percent",$content->boundstone_clast_percent,30 + $indent);
					}

					if($content->other_clast_percent!=""){
						$pdf->valueRow("Other clast percent",$content->other_clast_percent,30 + $indent);
					}

					if($content->matrix_composition!=""){
						$pdf->valueRow("Matrix composition",implode($content->matrix_composition, ", "),30 + $indent);
					}

					if($content->other_matrix_types!=""){
						$pdf->valueRow("Other Matrix Type(s)",$content->other_matrix_types,30 + $indent);
					}

					if($content->intrusive_igneous_matrix_percent!=""){
						$pdf->valueRow("Intrusive igneous matrix percent",$content->intrusive_igneous_matrix_percent,30 + $indent);
					}

					if($content->extrusive_igneous_matrix_percent!=""){
						$pdf->valueRow("Extrusive igneous matrix percent",$content->extrusive_igneous_matrix_percent,30 + $indent);
					}

					if($content->metamorphic_igneous_matrix_percent!=""){
						$pdf->valueRow("Metamorphic igneous matrix percent",$content->metamorphic_igneous_matrix_percent,30 + $indent);
					}

					if($content->mudstone_matrix_percent!=""){
						$pdf->valueRow("Mudstone matrix percent",$content->mudstone_matrix_percent,30 + $indent);
					}

					if($content->siltstone_matrix_percent!=""){
						$pdf->valueRow("Siltstone matrix percent",$content->siltstone_matrix_percent,30 + $indent);
					}

					if($content->sandstone_matrix_percent!=""){
						$pdf->valueRow("Sandstone matrix percent",$content->sandstone_matrix_percent,30 + $indent);
					}

					if($content->conglomerate_matrix_percent!=""){
						$pdf->valueRow("Conglomerate matrix percent",$content->conglomerate_matrix_percent,30 + $indent);
					}

					if($content->carbonate_matrix_type!=""){
						$pdf->valueRow("Carbonate Matrix Type",implode($content->carbonate_matrix_type, ", "),30 + $indent);
					}

					if($content->limestone_matrix_percent!=""){
						$pdf->valueRow("Limestone matrix percent",$content->limestone_matrix_percent,30 + $indent);
					}

					if($content->dolostone_matrix_percent!=""){
						$pdf->valueRow("Dolostone matrix percent",$content->dolostone_matrix_percent,30 + $indent);
					}

					if($content->skeletal_matrix_percent!=""){
						$pdf->valueRow("Skeletal matrix percent",$content->skeletal_matrix_percent,30 + $indent);
					}

					if($content->wackestone_matrix_percent!=""){
						$pdf->valueRow("Wackestone matrix percent",$content->wackestone_matrix_percent,30 + $indent);
					}

					if($content->packstone_matrix_percent!=""){
						$pdf->valueRow("Packstone matrix percent",$content->packstone_matrix_percent,30 + $indent);
					}

					if($content->grainstone_matrix_percent!=""){
						$pdf->valueRow("Grainstone matrix percent",$content->grainstone_matrix_percent,30 + $indent);
					}

					if($content->boundstone_matrix_percent!=""){
						$pdf->valueRow("Boundstone matrix percent",$content->boundstone_matrix_percent,30 + $indent);
					}

					if($content->other_carbonate_matrix_percent!=""){
						$pdf->valueRow("Other carbonate matrix percent",$content->other_carbonate_matrix_percent,30 + $indent);
					}

					if($content->other_matrix_percent!=""){
						$pdf->valueRow("Other matrix percent",$content->other_matrix_percent,30 + $indent);
					}

					if($content->volcaniclastic_type!=""){
						$pdf->valueRow("Volcaniclastic type",implode($content->volcaniclastic_type, ", "),30 + $indent);
					}

					if($content->other_volcaniclastic_type!=""){
						$pdf->valueRow("Other Volcaniclastic Type",$content->other_volcaniclastic_type,30 + $indent);
					}

					if($content->glass_percent!=""){
						$pdf->valueRow("Glass percent",$content->glass_percent,30 + $indent);
					}

					if($content->crystals_percent!=""){
						$pdf->valueRow("Crystals percent",$content->crystals_percent,30 + $indent);
					}

					if($content->lithic_fragments_percent!=""){
						$pdf->valueRow("Lithic fragments percent",$content->lithic_fragments_percent,30 + $indent);
					}

					if($content->volcanic_mudstone_percent!=""){
						$pdf->valueRow("Volcanic mudstone percent",$content->volcanic_mudstone_percent,30 + $indent);
					}

					if($content->volcanic_sandstone_percent!=""){
						$pdf->valueRow("Volcanic sandstone percent",$content->volcanic_sandstone_percent,30 + $indent);
					}

					if($content->lapillistone_percent!=""){
						$pdf->valueRow("Lapillistone percent",$content->lapillistone_percent,30 + $indent);
					}

					if($content->agglomerate_percent!=""){
						$pdf->valueRow("Agglomerate percent",$content->agglomerate_percent,30 + $indent);
					}

					if($content->volcanic_breccia_percent!=""){
						$pdf->valueRow("Volcanic breccia percent",$content->volcanic_breccia_percent,30 + $indent);
					}

					if($content->bentonite_percent!=""){
						$pdf->valueRow("Bentonite percent",$content->bentonite_percent,30 + $indent);
					}

					if($content->tuff_percent!=""){
						$pdf->valueRow("Tuff percent",$content->tuff_percent,30 + $indent);
					}

					if($content->welded_tuff_percent!=""){
						$pdf->valueRow("Welded tuff percent",$content->welded_tuff_percent,30 + $indent);
					}

					if($content->ignimbrite_percent!=""){
						$pdf->valueRow("Ignimbrite percent",$content->ignimbrite_percent,30 + $indent);
					}

					if($content->other_volcaniclastic_type_percent!=""){
						$pdf->valueRow("Other volcaniclastic type percent",$content->other_volcaniclastic_type_percent,30 + $indent);
					}

					if($content->evaporite_type!=""){
						$pdf->valueRow("Evaporite type",implode($content->evaporite_type, ", "),30 + $indent);
					}

					if($content->gypsum_anhydrite_primary_percent!=""){
						$pdf->valueRow("Gypsum - anhydrite primary percent",$content->gypsum_anhydrite_primary_percent,30 + $indent);
					}

					if($content->gypsum_anhydrite_primary_type!=""){
						$pdf->valueRow("Gypsum - anhydrite primary type",implode($content->gypsum_anhydrite_primary_type, ", "),30 + $indent);
					}

					if($content->gypsum_anhydrite_diagenetic_percent!=""){
						$pdf->valueRow("Gypsum - anhydrite diagenetic percent",$content->gypsum_anhydrite_diagenetic_percent,30 + $indent);
					}

					if($content->gypsum_anhydrite_diagenetic_type!=""){
						$pdf->valueRow("Gypsum - anhydrite diagenetic type",implode($content->gypsum_anhydrite_diagenetic_type, ", "),30 + $indent);
					}

					if($content->halite_primary_percent!=""){
						$pdf->valueRow("Halite - primary percent",$content->halite_primary_percent,30 + $indent);
					}

					if($content->halite_primary_type!=""){
						$pdf->valueRow("Halite - primary type",implode($content->halite_primary_type, ", "),30 + $indent);
					}

					if($content->halite_diagenetic_percent!=""){
						$pdf->valueRow("Halite - diagenetic percent",$content->halite_diagenetic_percent,30 + $indent);
					}

					if($content->halite_diagenetic_type!=""){
						$pdf->valueRow("Halite - diagenetic type",implode($content->halite_diagenetic_type, ", "),30 + $indent);
					}

					if($content->phosphorite_type!=""){
						$pdf->valueRow("Phosphorite type",implode($content->phosphorite_type, ", "),30 + $indent);
					}

					if($content->organic_coal_lithologies!=""){
						$pdf->valueRow("Organic/Coal Lithologies",implode($content->organic_coal_lithologies, ", "),30 + $indent);
					}

					if($content->amber_percent!=""){
						$pdf->valueRow("Amber percent",$content->amber_percent,30 + $indent);
					}

					if($content->peat_percent!=""){
						$pdf->valueRow("Peat percent",$content->peat_percent,30 + $indent);
					}

					if($content->lignite_percent!=""){
						$pdf->valueRow("Lignite percent",$content->lignite_percent,30 + $indent);
					}

					if($content->subbituminous_percent!=""){
						$pdf->valueRow("Subbituminous percent",$content->subbituminous_percent,30 + $indent);
					}

					if($content->bituminous_percent!=""){
						$pdf->valueRow("Bituminous percent",$content->bituminous_percent,30 + $indent);
					}

					if($content->coal_ball_percent!=""){
						$pdf->valueRow("Coal ball percent",$content->coal_ball_percent,30 + $indent);
					}

					if($content->tar_percent!=""){
						$pdf->valueRow("Tar percent",$content->tar_percent,30 + $indent);
					}

					if($content->notes!=""){
						$pdf->valueRow("Notes",$content->notes,30 + $indent);
					}

					$pdf->valueTitle("Texture: ", 25 + $indent);

					if($content->mud_silt_grain_size!=""){
						$pdf->valueRow("Mudstone/Siltstone Grain Size",$content->mud_silt_grain_size,30 + $indent);
					}

					if($content->sand_grain_size!=""){
						$pdf->valueRow("Sandstone Grain Size",$content->sand_grain_size,30 + $indent);
					}

					if($content->congl_grain_size!=""){
						$pdf->valueRow("Conglomerate Grain Size",$content->congl_grain_size,30 + $indent);
					}

					if($content->breccia_grain_size!=""){
						$pdf->valueRow("Breccia Grain Size",$content->breccia_grain_size,30 + $indent);
					}

					if($content->grain_size_range!=""){
						$pdf->valueRow("Grain Size Range",implode($content->grain_size_range, ", "),30 + $indent);
					}

					if($content->clay_percent!=""){
						$pdf->valueRow("Clay percent",$content->clay_percent,30 + $indent);
					}

					if($content->silt_percent!=""){
						$pdf->valueRow("Silt percent",$content->silt_percent,30 + $indent);
					}

					if($content->sand_very_fine_percent!=""){
						$pdf->valueRow("Sand - very fine percent",$content->sand_very_fine_percent,30 + $indent);
					}

					if($content->sand_fine_lower_percent!=""){
						$pdf->valueRow("Sand - fine lower percent",$content->sand_fine_lower_percent,30 + $indent);
					}

					if($content->sand_fine_upper_percent!=""){
						$pdf->valueRow("Sand - fine upper percent",$content->sand_fine_upper_percent,30 + $indent);
					}

					if($content->sand_medium_lower_percent!=""){
						$pdf->valueRow("Sand - medium lower percent",$content->sand_medium_lower_percent,30 + $indent);
					}

					if($content->sand_medium_upper_percent!=""){
						$pdf->valueRow("Sand - medium upper percent",$content->sand_medium_upper_percent,30 + $indent);
					}

					if($content->sand_coarse_lower_percent!=""){
						$pdf->valueRow("Sand - coarse lower percent",$content->sand_coarse_lower_percent,30 + $indent);
					}

					if($content->sand_coarse_upper_percent!=""){
						$pdf->valueRow("Sand - coarse upper percent",$content->sand_coarse_upper_percent,30 + $indent);
					}

					if($content->sand_very_coarse_percent!=""){
						$pdf->valueRow("Sand - very coarse percent",$content->sand_very_coarse_percent,30 + $indent);
					}

					if($content->granule_percent!=""){
						$pdf->valueRow("Granule percent",$content->granule_percent,30 + $indent);
					}

					if($content->pebble_percent!=""){
						$pdf->valueRow("Pebble percent",$content->pebble_percent,30 + $indent);
					}

					if($content->cobble_percent!=""){
						$pdf->valueRow("Cobble percent",$content->cobble_percent,30 + $indent);
					}

					if($content->boulder_percent!=""){
						$pdf->valueRow("Boulder percent",$content->boulder_percent,30 + $indent);
					}

					if($content->maximum_clast_size_cm!=""){
						$pdf->valueRow("Maximum Clast Size (cm)",$content->maximum_clast_size_cm,30 + $indent);
					}

					if($content->minimum_clast_size_cm!=""){
						$pdf->valueRow("Minimum Clast Size (cm)",$content->minimum_clast_size_cm,30 + $indent);
					}

					if($content->average_clast_size_cm!=""){
						$pdf->valueRow("Average Clast Size (cm)",$content->average_clast_size_cm,30 + $indent);
					}

					if($content->matrix_size!=""){
						$pdf->valueRow("Matrix Size",implode($content->matrix_size, ", "),30 + $indent);
					}

					if($content->character!=""){
						$pdf->valueRow("Character",implode($content->character, ", "),30 + $indent);
					}

					if($content->sorting!=""){
						$pdf->valueRow("Sorting",implode($content->sorting, ", "),30 + $indent);
					}

					if($content->rounding!=""){
						$pdf->valueRow("Rounding",implode($content->rounding, ", "),30 + $indent);
					}

					if($content->shape!=""){
						$pdf->valueRow("Shape",implode($content->shape, ", "),30 + $indent);
					}

					if($content->other_shape!=""){
						$pdf->valueRow("Other Shape",$content->other_shape,30 + $indent);
					}

					if($content->notes!=""){
						$pdf->valueRow("Notes",$content->notes,30 + $indent);
					}

					if($content->stratification!=""){
						$pdf->valueRow("Stratification",implode($content->stratification, ", "),30 + $indent);
					}

					if($content->laminae_thickness_i_select_more_than_one!=""){
						$pdf->valueRow("Laminae Thickness",implode($content->laminae_thickness_i_select_more_than_one, ", "),30 + $indent);
					}

					if($content->lamination_character!=""){
						$pdf->valueRow("Lamination Character",implode($content->lamination_character, ", "),30 + $indent);
					}

					if($content->bedding_thickness!=""){
						$pdf->valueRow("Bedding Thickness",implode($content->bedding_thickness, ", "),30 + $indent);
					}

					if($content->bedding_character!=""){
						$pdf->valueRow("Bedding character",implode($content->bedding_character, ", "),30 + $indent);
					}

					if($content->package_geometry!=""){
						$pdf->valueRow("Package Geometry",implode($content->package_geometry, ", "),30 + $indent);
					}

					if($content->package_lateral_extent!=""){
						$pdf->valueRow("Package Lateral Extent",$content->package_lateral_extent,30 + $indent);
					}

					if($content->package_bedding_trends!=""){
						$pdf->valueRow("Package Bedding Trends",$content->package_bedding_trends,30 + $indent);
					}

					if($content->other_bedding_trend!=""){
						$pdf->valueRow("Other bedding trend",$content->other_bedding_trend,30 + $indent);
					}

					if($content->shape_of_lower_contacts!=""){
						$pdf->valueRow("Shape of lower contact(s)",implode($content->shape_of_lower_contacts, ", "),30 + $indent);
					}

					if($content->character_of_lower_contacts!=""){
						$pdf->valueRow("Character of lower contact(s)",implode($content->character_of_lower_contacts, ", "),30 + $indent);
					}

					if($content->lower_contact_relief!=""){
						$pdf->valueRow("Lower contact relief",$content->lower_contact_relief,30 + $indent);
					}

					if($content->shape_of_upper_contacts!=""){
						$pdf->valueRow("Shape of upper contact(s)",implode($content->shape_of_upper_contacts, ", "),30 + $indent);
					}

					if($content->character_of_upper_contacts!=""){
						$pdf->valueRow("Character of upper contact(s)",implode($content->character_of_upper_contacts, ", "),30 + $indent);
					}

					if($content->upper_contact_relief!=""){
						$pdf->valueRow("Upper Contact Relief",$content->upper_contact_relief,30 + $indent);
					}

					if($content->interbed_proportion!=""){
						$pdf->valueRow("Interbed Relative Proportion (%)",$content->interbed_proportion,30 + $indent);
					}

					if($content->interbed_thickness!=""){
						$pdf->valueRow("Interbed Thickness",$content->interbed_thickness,30 + $indent);
					}

					if($content->interbed_proportion_change!=""){
						$pdf->valueRow("Interbed Proportion Change (Up Section)",$content->interbed_proportion_change,30 + $indent);
					}

					if($content->interbed_thickness_change!=""){
						$pdf->valueRow("Interbed Thickness Change (Up Section)",$content->interbed_thickness_change,30 + $indent);
					}

					if($content->Notes!=""){
						$pdf->valueRow("Notes",$content->Notes,30 + $indent);
					}

				}

			}

			if($spot['sed']->bedding){
				$pdf->valueTitle("Bedding: ", 20 + $indent);
				$content = $spot['sed']->bedding;

				if($content->interbed_proportion_change!=""){
					$pdf->valueRow("Interbed Proportion Change (Up Section)",$content->interbed_proportion_change,25 + $indent);
				}

				if($content->interbed_proportion!=""){
					$pdf->valueRow("Lithology 1: Interbed Relative Proportion (%)",$content->interbed_proportion,25 + $indent);
				}

				if($content->lithology_at_bottom_contact!=""){
					$pdf->valueRow("Which Lithology is at Bottom Contact?",$content->lithology_at_bottom_contact,25 + $indent);
				}

				if($content->lithology_at_top_contact!=""){
					$pdf->valueRow("Which Lithology is at Top Contact?",$content->lithology_at_top_contact,25 + $indent);
				}

				if($content->thickness_of_individual_beds!=""){
					$pdf->valueRow("Thickness of Individual Beds",$content->thickness_of_individual_beds,25 + $indent);
				}

				if($content->package_thickness_units!=""){
					$pdf->valueRow("Package Beds Thickness Units",$content->package_thickness_units,25 + $indent);
				}

				if($content->package_bedding_trends!=""){
					$pdf->valueRow("Package Bedding Trends",$content->package_bedding_trends,25 + $indent);
				}

				if($content->other_bedding_trend!=""){
					$pdf->valueRow("Other Package Bedding Trend",$content->other_bedding_trend,25 + $indent);
				}

				$beds = $spot['sed']->bedding->beds;
				foreach($beds as $content){

					if($content->package_geometry!=""){
						$pdf->valueRow("Bed Geometry",implode($content->package_geometry, ", "),25 + $indent);
					}

					$pdf->valueTitle("Lower Contact: ", 25 + $indent);

					if($content->shape_of_lower_contacts!=""){
						$pdf->valueRow("Shape of lower contact",implode($content->shape_of_lower_contacts, ", "),30 + $indent);
					}

					if($content->character_of_lower_contacts!=""){
						$pdf->valueRow("Character of lower contact(s)",implode($content->character_of_lower_contacts, ", "),30 + $indent);
					}

					if($content->lower_contact_relief!=""){
						$pdf->valueRow("Lower contact relief",$content->lower_contact_relief,30 + $indent);
					}

					$pdf->valueTitle("Upper Contact: ", 25 + $indent);

					if($content->shape_of_upper_contacts!=""){
						$pdf->valueRow("Shape of upper contact",implode($content->shape_of_upper_contacts, ", "),30 + $indent);
					}

					if($content->character_of_upper_contacts!=""){
						$pdf->valueRow("Character of upper contact",implode($content->character_of_upper_contacts, ", "),30 + $indent);
					}

					if($content->upper_contact_relief!=""){
						$pdf->valueRow("Upper Contact Relief",$content->upper_contact_relief,30 + $indent);
					}

					$pdf->valueTitle("Interbed Thickness: ", 25 + $indent);

					if($content->avg_thickness!=""){
						$pdf->valueRow("Average Thickness",$content->avg_thickness,30 + $indent);
					}

					if($content->max_thickness!=""){
						$pdf->valueRow("Maximum Thickness",$content->max_thickness,30 + $indent);
					}

					if($content->min_thickness!=""){
						$pdf->valueRow("Minimum Thickness",$content->min_thickness,30 + $indent);
					}

					if($content->interbed_thickness_units!=""){
						$pdf->valueRow("Interbed Thickness Units",$content->interbed_thickness_units,30 + $indent);
					}

					if($content->notes!=""){
						$pdf->valueRow("Notes",$content->notes,30 + $indent);
					}

				}
			}

			if($spot['sed']->structures){
				$pdf->valueTitle("Structures: ", 20 + $indent);
				$contents = $spot['sed']->structures;
				foreach($contents as $content){

					if($content->massive_structureless!=""){
						$pdf->valueRow("Massive/Structureless?",$content->massive_structureless,25 + $indent);
					}

					$pdf->valueTitle("Cross Bedding: ", 25 + $indent);

					if($content->cross_bedding_type!=""){
						$pdf->valueRow("Cross Bedding Type",implode($content->cross_bedding_type, ", "),30 + $indent);
					}

					if($content->cross_bedding_height_cm!=""){
						$pdf->valueRow("Cross Bedding Height (cm)",$content->cross_bedding_height_cm,30 + $indent);
					}

					if($content->cross_bedding_width_cm!=""){
						$pdf->valueRow("Cross Bedding Width (cm)",$content->cross_bedding_width_cm,30 + $indent);
					}

					if($content->cross_bedding_thickness_cm!=""){
						$pdf->valueRow("Cross Bedding Thickness (cm)",$content->cross_bedding_thickness_cm,30 + $indent);
					}

					if($content->cross_bedding_spacing_cm!=""){
						$pdf->valueRow("Cross Bedding Spacing (cm)",$content->cross_bedding_spacing_cm,30 + $indent);
					}

					$pdf->valueTitle("Ripple Lamination: ", 25 + $indent);

					if($content->ripple_lamination_type!=""){
						$pdf->valueRow("Ripple Lamination Type",implode($content->ripple_lamination_type, ", "),30 + $indent);
					}

					if($content->other_ripple_lamination_type!=""){
						$pdf->valueRow("Other Ripple Lamination Type",$content->other_ripple_lamination_type,30 + $indent);
					}

					if($content->ripple_lamination_height_mm!=""){
						$pdf->valueRow("Ripple Lamination Height (mm)",$content->ripple_lamination_height_mm,30 + $indent);
					}

					if($content->ripple_lamination_width_mm!=""){
						$pdf->valueRow("Ripple Lamination Width (mm)",$content->ripple_lamination_width_mm,30 + $indent);
					}

					if($content->ripple_lamination_thick_mm!=""){
						$pdf->valueRow("Ripple Lmation Thickness (mm)",$content->ripple_lamination_thick_mm,30 + $indent);
					}

					if($content->ripple_lamination_spacing_mm!=""){
						$pdf->valueRow("Ripple Lamination Spacing (mm)",$content->ripple_lamination_spacing_mm,30 + $indent);
					}

					$pdf->valueTitle("Horizontal Bedding: ", 25 + $indent);

					if($content->horizontal_bedding_type!=""){
						$pdf->valueRow("Horizontal Bedding Type",implode($content->horizontal_bedding_type, ", "),30 + $indent);
					}

					if($content->other_horizontal_bedding_type!=""){
						$pdf->valueRow("Other Horizontal Bedding Type",$content->other_horizontal_bedding_type,30 + $indent);
					}

					$pdf->valueTitle("Graded Bedding: ", 25 + $indent);

					if($content->graded_bedding_type!=""){
						$pdf->valueRow("Graded Bedding Type",$content->graded_bedding_type,30 + $indent);
					}

					$pdf->valueTitle("Deformation Structures: ", 25 + $indent);

					if($content->deformation_structures!=""){
						$pdf->valueRow("Deformation Structure Type",implode($content->deformation_structures, ", "),30 + $indent);
					}

					if($content->other_deformation_structure_type!=""){
						$pdf->valueRow("Other Deformation Structure Type",$content->other_deformation_structure_type,30 + $indent);
					}

					$pdf->valueTitle("Lags: ", 25 + $indent);

					if($content->lag_type!=""){
						$pdf->valueRow("Lag Type",implode($content->lag_type, ", "),30 + $indent);
					}

					if($content->other_lag_type!=""){
						$pdf->valueRow("Other Lag Type",$content->other_lag_type,30 + $indent);
					}

					if($content->clast_composition!=""){
						$pdf->valueRow("Clast Composition",$content->clast_composition,30 + $indent);
					}

					if($content->clast_size!=""){
						$pdf->valueRow("Clast Size",$content->clast_size,30 + $indent);
					}

					if($content->layer_thickness_shape!=""){
						$pdf->valueRow("Layer Thickness/Shape",$content->layer_thickness_shape,30 + $indent);
					}

					$pdf->valueTitle("Other Common Structures: ", 25 + $indent);

					if($content->other_common_structures!=""){
						$pdf->valueRow("Other Common Structure Type",implode($content->other_common_structures, ", "),30 + $indent);
					}

					if($content->bouma_sequence_part!=""){
						$pdf->valueRow("Bouma Sequence Part",implode($content->bouma_sequence_part, ", "),30 + $indent);
					}

					if($content->bioturbation_index!=""){
						$pdf->valueRow("Bioturbation Index",$content->bioturbation_index,30 + $indent);
					}

					if($content->bedding_plane_features!=""){
						$pdf->valueRow("Bedding plane features",implode($content->bedding_plane_features, ", "),30 + $indent);
					}

					if($content->other_bedding_plane_feature!=""){
						$pdf->valueRow("Other Bedding Plane Feature",$content->other_bedding_plane_feature,30 + $indent);
					}

					if($content->bedding_plane_features_scale!=""){
						$pdf->valueRow("Bedding Plane Features Scale",$content->bedding_plane_features_scale,30 + $indent);
					}

					if($content->bedding_plane_features_orientation!=""){
						$pdf->valueRow("Bedding Plane Features Orientation",$content->bedding_plane_features_orientation,30 + $indent);
					}

					if($content->bedform_type!=""){
						$pdf->valueRow("Bedform Type",implode($content->bedform_type, ", "),30 + $indent);
					}

					if($content->other_bedform_type!=""){
						$pdf->valueRow("Other Bedform Type",$content->other_bedform_type,30 + $indent);
					}

					if($content->bedform_scale!=""){
						$pdf->valueRow("Bedform Scale",$content->bedform_scale,30 + $indent);
					}

					if($content->crest_orientation_azimuth_0_360!=""){
						$pdf->valueRow("Crest Orientation",$content->crest_orientation_azimuth_0_360,30 + $indent);
					}

					$pdf->valueTitle("Pedogenic Structures: ", 25 + $indent);

					if($content->paleosol_horizons!=""){
						$pdf->valueRow("Master Paleosol Horizons",implode($content->paleosol_horizons, ", "),30 + $indent);
					}

					if($content->other_horizon!=""){
						$pdf->valueRow("Other Horizon",$content->other_horizon,30 + $indent);
					}

					if($content->o_horizon_thickness_cm!=""){
						$pdf->valueRow("O Horizon thickness (cm)",$content->o_horizon_thickness_cm,30 + $indent);
					}

					if($content->a_horizon_thickness_cm!=""){
						$pdf->valueRow("A Horizon thickness (cm)",$content->a_horizon_thickness_cm,30 + $indent);
					}

					if($content->e_horizon_thickness_cm!=""){
						$pdf->valueRow("E Horizon thickness (cm)",$content->e_horizon_thickness_cm,30 + $indent);
					}

					if($content->b_horizon_thickness_cm!=""){
						$pdf->valueRow("B Horizon thickness (cm)",$content->b_horizon_thickness_cm,30 + $indent);
					}

					if($content->k_horizon_thickness_cm!=""){
						$pdf->valueRow("K Horizon thickness (cm)",$content->k_horizon_thickness_cm,30 + $indent);
					}

					if($content->c_horizon_thickness_cm!=""){
						$pdf->valueRow("C Horizon thickness (cm)",$content->c_horizon_thickness_cm,30 + $indent);
					}

					if($content->r_horizon_thickness_cm!=""){
						$pdf->valueRow("R Horizon thickness (cm)",$content->r_horizon_thickness_cm,30 + $indent);
					}

					if($content->compound_thickness_cm!=""){
						$pdf->valueRow("Compound thickness (cm)",$content->compound_thickness_cm,30 + $indent);
					}

					if($content->composite_thickness_cm!=""){
						$pdf->valueRow("Composite thickness (cm)",$content->composite_thickness_cm,30 + $indent);
					}

					if($content->paleosol_structures!=""){
						$pdf->valueRow("Paleosol structures",implode($content->paleosol_structures, ", "),30 + $indent);
					}

					if($content->other_structure!=""){
						$pdf->valueRow("Other Paleosol Structure",$content->other_structure,30 + $indent);
					}

					if($content->additional_modifiers!=""){
						$pdf->valueRow("Additional modifiers",$content->additional_modifiers,30 + $indent);
					}

					if($content->paleosol_classification!=""){
						$pdf->valueRow("Paleosol classification",implode($content->paleosol_classification, ", "),30 + $indent);
					}

					if($content->notes!=""){
						$pdf->valueRow("Notes",$content->notes,30 + $indent);
					}

				}

			}

			if($spot['sed']->diagenesis){
				$pdf->valueTitle("Diagenesis: ", 20 + $indent);
				$contents = $spot['sed']->diagenesis;
				foreach($contents as $content){

					$pdf->valueTitle("Cement: ", 25 + $indent);

					if($content->cement_composition!=""){
						$pdf->valueRow("Cement Mineralogy",implode($content->cement_composition, ", "),30 + $indent);
					}

					if($content->other_cement_composition!=""){
						$pdf->valueRow("Other Cement Mineralogy",$content->other_cement_composition,30 + $indent);
					}

					$pdf->valueTitle("Veins: ", 25 + $indent);

					if($content->vein_type!=""){
						$pdf->valueRow("Vein Type",$content->vein_type,30 + $indent);
					}

					if($content->vein_width!=""){
						$pdf->valueRow("Vein Width (cm)",$content->vein_width,30 + $indent);
					}

					if($content->vein_length!=""){
						$pdf->valueRow("Vein Length (cm)",$content->vein_length,30 + $indent);
					}

					if($content->vein_orientation!=""){
						$pdf->valueRow("Vein Orientation",$content->vein_orientation,30 + $indent);
					}

					if($content->vein_mineralogy!=""){
						$pdf->valueRow("Vein Mineralogy",$content->vein_mineralogy,30 + $indent);
					}

					if($content->other_vein_mineralogy!=""){
						$pdf->valueRow("Other Vein Mineralogy",$content->other_vein_mineralogy,30 + $indent);
					}

					$pdf->valueTitle("Fractures: ", 25 + $indent);

					if($content->fracture_type!=""){
						$pdf->valueRow("Fracture Type",$content->fracture_type,30 + $indent);
					}

					if($content->fracture_width!=""){
						$pdf->valueRow("Fracture Width (cm)",$content->fracture_width,30 + $indent);
					}

					if($content->fracture_length!=""){
						$pdf->valueRow("Fracture Length (cm)",$content->fracture_length,30 + $indent);
					}

					if($content->fracture_orientation!=""){
						$pdf->valueRow("Fracture Orientation",$content->fracture_orientation,30 + $indent);
					}

					if($content->fracture_mineralogy!=""){
						$pdf->valueRow("Fracture Mineralogy",$content->fracture_mineralogy,30 + $indent);
					}

					if($content->other_fracture_mineralogy!=""){
						$pdf->valueRow("Other Fracture Mineralogy",$content->other_fracture_mineralogy,30 + $indent);
					}

					$pdf->valueTitle("Nodules/Concretions: ", 25 + $indent);

					if($content->nodules_concretions_size!=""){
						$pdf->valueRow("Nodules/Concretions Size",$content->nodules_concretions_size,30 + $indent);
					}

					if($content->min!=""){
						$pdf->valueRow("Min",$content->min,30 + $indent);
					}

					if($content->max!=""){
						$pdf->valueRow("Max",$content->max,30 + $indent);
					}

					if($content->average!=""){
						$pdf->valueRow("Average",$content->average,30 + $indent);
					}

					if($content->nodules_concretions_shape!=""){
						$pdf->valueRow("Nodules/Concretions Shape",implode($content->nodules_concretions_shape, ", "),30 + $indent);
					}

					if($content->other_nodules_concretion_shape!=""){
						$pdf->valueRow("Other Nodule/Concretions Shape",$content->other_nodules_concretion_shape,30 + $indent);
					}

					if($content->spacing!=""){
						$pdf->valueRow("Spacing",$content->spacing,30 + $indent);
					}

					if($content->nodules_concretions_type!=""){
						$pdf->valueRow("Nodules/Concretions Type",$content->nodules_concretions_type,30 + $indent);
					}

					if($content->other_nodules_concretions_type!=""){
						$pdf->valueRow("Other Nodules/Concretions Type",$content->other_nodules_concretions_type,30 + $indent);
					}

					if($content->nodules_concretions_comp!=""){
						$pdf->valueRow("Nodules/Concretions Composition",implode($content->nodules_concretions_comp, ", "),30 + $indent);
					}

					if($content->other_nodules_concretion_comp!=""){
						$pdf->valueRow("Other Nodules/Concretions Composition",$content->other_nodules_concretion_comp,30 + $indent);
					}

					$pdf->valueTitle("Replacement: ", 25 + $indent);

					if($content->replacement_type!=""){
						$pdf->valueRow("Replacement Type",$content->replacement_type,30 + $indent);
					}

					if($content->other_replacement_type!=""){
						$pdf->valueRow("Other Replacement Type",$content->other_replacement_type,30 + $indent);
					}

					$pdf->valueTitle("Recrystallization: ", 25 + $indent);

					if($content->recrystallization_type!=""){
						$pdf->valueRow("Recrystallization Type",$content->recrystallization_type,30 + $indent);
					}

					if($content->other_recrystallization_type!=""){
						$pdf->valueRow("Other Recrystallization Type",$content->other_recrystallization_type,30 + $indent);
					}

					$pdf->valueTitle("Other Diagenetic Features: ", 25 + $indent);

					if($content->other_diagenetic_features!=""){
						$pdf->valueRow("Other Diagenetic Features",implode($content->other_diagenetic_features, ", "),30 + $indent);
					}

					if($content->other_features!=""){
						$pdf->valueRow("Other Features",$content->other_features,30 + $indent);
					}

					$pdf->valueTitle("Porosity type: ", 25 + $indent);

					if($content->fabric_selective!=""){
						$pdf->valueRow("Fabric Selective",implode($content->fabric_selective, ", "),30 + $indent);
					}

					if($content->other_fabric_selective!=""){
						$pdf->valueRow("Other Fabric Selective",$content->other_fabric_selective,30 + $indent);
					}

					if($content->non_selective!=""){
						$pdf->valueRow("Non-Frabric Selective",implode($content->non_selective, ", "),30 + $indent);
					}

					if($content->other_non_selective!=""){
						$pdf->valueRow("Other Non-Fabric Selective",$content->other_non_selective,30 + $indent);
					}

					$pdf->valueTitle("Carbonate Desiccation and Dissolution: ", 25 + $indent);

					if($content->carbonate_desicc_and_diss!=""){
						$pdf->valueRow("Carbonate Desiccation and Dissolution Type",implode($content->carbonate_desicc_and_diss, ", "),30 + $indent);
					}

					if($content->other_carbonate_desicc_diss!=""){
						$pdf->valueRow("Other Carbonate Desiccation and Dissolution Type",$content->other_carbonate_desicc_diss,30 + $indent);
					}

					if($content->notes!=""){
						$pdf->valueRow("Notes",$content->notes,30 + $indent);
					}

				}
			}

			if($spot['sed']->fossils){
				$pdf->valueTitle("Fossils: ", 20 + $indent);
				$contents = $spot['sed']->fossils;
				foreach($contents as $content){

					$pdf->valueTitle("Body: ", 25 + $indent);

					if($content->invertebrate!=""){
						$pdf->valueRow("Invertebrate",implode($content->invertebrate, ", "),30 + $indent);
					}

					if($content->other_invertebrate!=""){
						$pdf->valueRow("Other Invertebrate",$content->other_invertebrate,30 + $indent);
					}

					if($content->mollusca!=""){
						$pdf->valueRow("Mollusc",implode($content->mollusca, ", "),30 + $indent);
					}

					if($content->other_mollusca!=""){
						$pdf->valueRow("Other Mollusc",$content->other_mollusca,30 + $indent);
					}

					if($content->arthropoda!=""){
						$pdf->valueRow("Arthropod",implode($content->arthropoda, ", "),30 + $indent);
					}

					if($content->other_anthropoda!=""){
						$pdf->valueRow("Other Arthropod",$content->other_anthropoda,30 + $indent);
					}

					if($content->echinodermata!=""){
						$pdf->valueRow("Echinoderm",implode($content->echinodermata, ", "),30 + $indent);
					}

					if($content->other_echinodermata!=""){
						$pdf->valueRow("Other Echinoderm",$content->other_echinodermata,30 + $indent);
					}

					if($content->cnidaria!=""){
						$pdf->valueRow("Cnidarian",implode($content->cnidaria, ", "),30 + $indent);
					}

					if($content->other_cnidaria!=""){
						$pdf->valueRow("Other Cnidarian",$content->other_cnidaria,30 + $indent);
					}

					if($content->chordate!=""){
						$pdf->valueRow("Chordate",$content->chordate,30 + $indent);
					}

					if($content->other_chordata!=""){
						$pdf->valueRow("Other Chordate",$content->other_chordata,30 + $indent);
					}

					if($content->protista!=""){
						$pdf->valueRow("Protist",implode($content->protista, ", "),30 + $indent);
					}

					if($content->other_protista!=""){
						$pdf->valueRow("Other Protist",$content->other_protista,30 + $indent);
					}

					if($content->calcimicrobe!=""){
						$pdf->valueRow("Calcimicrobe",implode($content->calcimicrobe, ", "),30 + $indent);
					}

					if($content->other_calcimicrobe!=""){
						$pdf->valueRow("Other Calcimicrobe",$content->other_calcimicrobe,30 + $indent);
					}

					if($content->plant_algae!=""){
						$pdf->valueRow("Plant/algae",implode($content->plant_algae, ", "),30 + $indent);
					}

					if($content->other_plant_algae!=""){
						$pdf->valueRow("Other Plant/Algae",$content->other_plant_algae,30 + $indent);
					}

					if($content->green_algae!=""){
						$pdf->valueRow("Green Algae",implode($content->green_algae, ", "),30 + $indent);
					}

					if($content->other_green_algae!=""){
						$pdf->valueRow("Other Green Algae",$content->other_green_algae,30 + $indent);
					}

					if($content->vertebrate!=""){
						$pdf->valueRow("Vertebrate",implode($content->vertebrate, ", "),30 + $indent);
					}

					if($content->other_vertebrate!=""){
						$pdf->valueRow("Other Vertebrate",$content->other_vertebrate,30 + $indent);
					}

					if($content->faunal_assemblage!=""){
						$pdf->valueRow("Faunal assemblage",$content->faunal_assemblage,30 + $indent);
					}

					if($content->other_faunal_assemblage!=""){
						$pdf->valueRow("Other faunal assemblage",$content->other_faunal_assemblage,30 + $indent);
					}

					$pdf->valueTitle("Trace: ", 25 + $indent);

					if($content->diversity!=""){
						$pdf->valueRow("Diversity",$content->diversity,30 + $indent);
					}

					if($content->descriptive!=""){
						$pdf->valueRow("Descriptive",implode($content->descriptive, ", "),30 + $indent);
					}

					if($content->other_descriptive!=""){
						$pdf->valueRow("Other Descriptive",$content->other_descriptive,30 + $indent);
					}

					if($content->burrow_fill_type!=""){
						$pdf->valueRow("Burrow Fill Type",implode($content->burrow_fill_type, ", "),30 + $indent);
					}

					if($content->other_burrow_fill!=""){
						$pdf->valueRow("Other Burrow Fill Type",$content->other_burrow_fill,30 + $indent);
					}

					if($content->behavioral_grouping!=""){
						$pdf->valueRow("Behavioral grouping",$content->behavioral_grouping,30 + $indent);
					}

					if($content->other_behavioral_grouping!=""){
						$pdf->valueRow("Other Behavioral Grouping",$content->other_behavioral_grouping,30 + $indent);
					}

					if($content->ichnofacies!=""){
						$pdf->valueRow("Ichnofacies",$content->ichnofacies,30 + $indent);
					}

					if($content->other_ichnofacies!=""){
						$pdf->valueRow("Other Ichnofacies",$content->other_ichnofacies,30 + $indent);
					}

					if($content->list_of_specific_types!=""){
						$pdf->valueRow("List of specific types",$content->list_of_specific_types,30 + $indent);
					}

					$pdf->valueTitle("Biogenic Growth Structures: ", 25 + $indent);

					if($content->dominant_component!=""){
						$pdf->valueRow("Dominant component",$content->dominant_component,30 + $indent);
					}

					if($content->other_dominant_component!=""){
						$pdf->valueRow("Other Dominant Component",$content->other_dominant_component,30 + $indent);
					}

					if($content->microbial_reef_or_skelatal_mic!=""){
						$pdf->valueRow("Microbial Reef or Skeletal-Microbial Reef Type",implode($content->microbial_reef_or_skelatal_mic, ", "),30 + $indent);
					}

					if($content->other_microbial_or_skeletal_mi!=""){
						$pdf->valueRow("Other Microbial or Skeletal Microbial Reef",$content->other_microbial_or_skeletal_mi,30 + $indent);
					}

					if($content->mud_mound!=""){
						$pdf->valueRow("Mud Mound Type",implode($content->mud_mound, ", "),30 + $indent);
					}

					if($content->other_mud_mound!=""){
						$pdf->valueRow("Other Mud Mound",$content->other_mud_mound,30 + $indent);
					}

					$pdf->valueTitle("Biogenic Growth Structure Scale and Orientation: ", 25 + $indent);

					if($content->height!=""){
						$pdf->valueRow("Height",$content->height,30 + $indent);
					}

					if($content->width!=""){
						$pdf->valueRow("Width",$content->width,30 + $indent);
					}

					if($content->shape!=""){
						$pdf->valueRow("Shape",$content->shape,30 + $indent);
					}

					if($content->type!=""){
						$pdf->valueRow("Type",$content->type,30 + $indent);
					}

					if($content->other_type!=""){
						$pdf->valueRow("Other Type",$content->other_type,30 + $indent);
					}

					if($content->accessory_structures!=""){
						$pdf->valueRow("Accessory Structures",implode($content->accessory_structures, ", "),30 + $indent);
					}

					if($content->other_accessory_structure!=""){
						$pdf->valueRow("Other Accessory Structure",$content->other_accessory_structure,30 + $indent);
					}

					if($content->notes!=""){
						$pdf->valueRow("Notes",$content->notes,30 + $indent);
					}
				}
			}

			if($spot['sed']->interpretations){
				$pdf->valueTitle("Interpretations: ", 20 + $indent);
				$contents = $spot['sed']->interpretations;
				foreach($contents as $content){

					$pdf->valueTitle("Process Interpretation: ", 25 + $indent);

					if($content->energy!=""){
						$pdf->valueRow("Energy",$content->energy,30 + $indent);
					}

					if($content->other_energy!=""){
						$pdf->valueRow("Other Energy",$content->other_energy,30 + $indent);
					}

					if($content->sediment_transport!=""){
						$pdf->valueRow("Sediment Transport",implode($content->sediment_transport, ", "),30 + $indent);
					}

					if($content->other_sediment_transport!=""){
						$pdf->valueRow("Other Sediment Transport",$content->other_sediment_transport,30 + $indent);
					}

					if($content->fluidization!=""){
						$pdf->valueRow("Fluidization",$content->fluidization,30 + $indent);
					}

					if($content->other_fluidization!=""){
						$pdf->valueRow("Other Fluidization",$content->other_fluidization,30 + $indent);
					}

					if($content->miscellaneous!=""){
						$pdf->valueRow("Miscellaneous",implode($content->miscellaneous, ", "),30 + $indent);
					}

					if($content->other_miscellaneous!=""){
						$pdf->valueRow("Other Misc. Process",$content->other_miscellaneous,30 + $indent);
					}

					$pdf->valueTitle("Environment Interpretation: ", 25 + $indent);

					if($content->general!=""){
						$pdf->valueRow("General",implode($content->general, ", "),30 + $indent);
					}

					if($content->clastic!=""){
						$pdf->valueRow("Clastic",implode($content->clastic, ", "),30 + $indent);
					}

					if($content->alluvial_fan_environments!=""){
						$pdf->valueRow("Alluvial fan environments",implode($content->alluvial_fan_environments, ", "),30 + $indent);
					}

					if($content->other_alluvial_fan!=""){
						$pdf->valueRow("Other Alluvial Fan",$content->other_alluvial_fan,30 + $indent);
					}

					if($content->eolian_environments!=""){
						$pdf->valueRow("Eolian environments",implode($content->eolian_environments, ", "),30 + $indent);
					}

					if($content->other_eolian!=""){
						$pdf->valueRow("Other Eolian",$content->other_eolian,30 + $indent);
					}

					if($content->fluvial_environments!=""){
						$pdf->valueRow("Fluvial environments",implode($content->fluvial_environments, ", "),30 + $indent);
					}

					if($content->other_fluvial!=""){
						$pdf->valueRow("Other Fluvial",$content->other_fluvial,30 + $indent);
					}

					if($content->shallow_marine_clastic_environ!=""){
						$pdf->valueRow("Shallow marine clastic environments",implode($content->shallow_marine_clastic_environ, ", "),30 + $indent);
					}

					if($content->other_shallow_marine!=""){
						$pdf->valueRow("Other Shallow Marine",$content->other_shallow_marine,30 + $indent);
					}

					if($content->deep_marine_environments!=""){
						$pdf->valueRow("Deep marine environments",implode($content->deep_marine_environments, ", "),30 + $indent);
					}

					if($content->other_deep_marine!=""){
						$pdf->valueRow("Other Deep Marine",$content->other_deep_marine,30 + $indent);
					}

					if($content->glacial_and_proglacial_environ!=""){
						$pdf->valueRow("Glacial and proglacial environments",implode($content->glacial_and_proglacial_environ, ", "),30 + $indent);
					}

					if($content->other_glacial!=""){
						$pdf->valueRow("Other Glacial",$content->other_glacial,30 + $indent);
					}

					if($content->lake_environments!=""){
						$pdf->valueRow("Lake environments",implode($content->lake_environments, ", "),30 + $indent);
					}

					if($content->other_lake!=""){
						$pdf->valueRow("Other Lake",$content->other_lake,30 + $indent);
					}

					if($content->other_clastic!=""){
						$pdf->valueRow("Other Clastic",$content->other_clastic,30 + $indent);
					}

					if($content->carbonates!=""){
						$pdf->valueRow("Carbonates",implode($content->carbonates, ", "),30 + $indent);
					}

					if($content->factory!=""){
						$pdf->valueRow("Factory",implode($content->factory, ", "),30 + $indent);
					}

					if($content->carbonate!=""){
						$pdf->valueRow("Environment",implode($content->carbonate, ", "),30 + $indent);
					}

					if($content->other_carbonate_environment!=""){
						$pdf->valueRow("Other Carbonate Environment",$content->other_carbonate_environment,30 + $indent);
					}

					if($content->lake_subenvironments!=""){
						$pdf->valueRow("Lake Subenvironments",implode($content->lake_subenvironments, ", "),30 + $indent);
					}

					if($content->other_carbonate_lake_subenvironment!=""){
						$pdf->valueRow("Other Carbonate Lake Subenvironment",$content->other_carbonate_lake_subenvironment,30 + $indent);
					}

					if($content->tidal_flat_subenvironments!=""){
						$pdf->valueRow("Tidal Flat Subenvironments",implode($content->tidal_flat_subenvironments, ", "),30 + $indent);
					}

					if($content->other_tidal_flat!=""){
						$pdf->valueRow("Other Tidal Flat",$content->other_tidal_flat,30 + $indent);
					}

					if($content->reef_subenvironments!=""){
						$pdf->valueRow("Reef Subenvironments",implode($content->reef_subenvironments, ", "),30 + $indent);
					}

					if($content->other_reef!=""){
						$pdf->valueRow("Other Reef",$content->other_reef,30 + $indent);
					}

					if($content->detailed_carbonate_env_interpr!=""){
						$pdf->valueRow("Detailed carbonate environmental interpretations",$content->detailed_carbonate_env_interpr,30 + $indent);
					}

					if($content->tectonic_setting!=""){
						$pdf->valueRow("Tectonic Setting",implode($content->tectonic_setting, ", "),30 + $indent);
					}

					if($content->other_tectonic_setting!=""){
						$pdf->valueRow("Other Tectonic Setting",$content->other_tectonic_setting,30 + $indent);
					}

					$pdf->valueTitle("Sedimentary Surfaces (for line spots only): ", 25 + $indent);

					if($content->geometry!=""){
						$pdf->valueRow("Geometry",$content->geometry,30 + $indent);
					}

					if($content->relief!=""){
						$pdf->valueRow("Relief",$content->relief,30 + $indent);
					}

					if($content->relief_scale!=""){
						$pdf->valueRow("Relief Scale",$content->relief_scale,30 + $indent);
					}

					if($content->extent!=""){
						$pdf->valueRow("Extent",$content->extent,30 + $indent);
					}

					if($content->extent_scale!=""){
						$pdf->valueRow("Extent Scale",$content->extent_scale,30 + $indent);
					}

					if($content->type!=""){
						$pdf->valueRow("Type",implode($content->type, ", "),30 + $indent);
					}

					if($content->other_type!=""){
						$pdf->valueRow("Other Type",$content->other_type,30 + $indent);
					}

					if($content->stratal_termination!=""){
						$pdf->valueRow("Stratal Termination",$content->stratal_termination,30 + $indent);
					}

					$pdf->valueTitle("Sedimentary Surface Interpretation: ", 25 + $indent);

					if($content->general_surfaces!=""){
						$pdf->valueRow("General Surfaces",$content->general_surfaces,30 + $indent);
					}

					if($content->sequence_stratigraphic_surfaces!=""){
						$pdf->valueRow("Sequence Stratigraphic Surfaces",$content->sequence_stratigraphic_surfaces,30 + $indent);
					}

					if($content->other_sequence_stratigraphic_surface!=""){
						$pdf->valueRow("Other Sequence Stratigraphic Surface",$content->other_sequence_stratigraphic_surface,30 + $indent);
					}

					if($content->named!=""){
						$pdf->valueRow("Named",$content->named,30 + $indent);
					}

					$pdf->valueTitle("Architecture Interpretation: ", 25 + $indent);

					if($content->description!=""){
						$pdf->valueRow("Description",implode($content->description, ", "),30 + $indent);
					}

					if($content->stacking_sequence_stratigraphy!=""){
						$pdf->valueRow("Stacking/Sequence Stratigraphy",implode($content->stacking_sequence_stratigraphy, ", "),30 + $indent);
					}

					if($content->other_stacking_sequence_stratigraphy!=""){
						$pdf->valueRow("Other Stacking/Sequence Stratigraphy",$content->other_stacking_sequence_stratigraphy,30 + $indent);
					}

					if($content->fluvial_architectural_elements!=""){
						$pdf->valueRow("Fluvial Architectural Elements",implode($content->fluvial_architectural_elements, ", "),30 + $indent);
					}

					if($content->other_fluvial_element!=""){
						$pdf->valueRow("Other Fluvial Element",$content->other_fluvial_element,30 + $indent);
					}

					if($content->lacustrine_architecture_interpretation!=""){
						$pdf->valueRow("Lacustrine Architecture Interpretation",implode($content->lacustrine_architecture_interpretation, ", "),30 + $indent);
					}

					if($content->other_lacustrine_architecture_interpretation!=""){
						$pdf->valueRow("Other Lacustrine Architecture Interpretation",$content->other_lacustrine_architecture_interpretation,30 + $indent);
					}

					if($content->carbonate_platform_geometry!=""){
						$pdf->valueRow("Carbonate Platform Geometry",implode($content->carbonate_platform_geometry, ", "),30 + $indent);
					}

					if($content->other_platform_geometry!=""){
						$pdf->valueRow("Other Platform Geometry",$content->other_platform_geometry,30 + $indent);
					}

					if($content->deep_water_architctural_element!=""){
						$pdf->valueRow("Deep-Water Architectural Elements",implode($content->deep_water_architctural_element, ", "),30 + $indent);
					}

					if($content->other_deep_water_architectural_element!=""){
						$pdf->valueRow("Other Deep-Water Architectural Element",$content->other_deep_water_architectural_element,30 + $indent);
					}

					if($content->carbonate_margin_geometry!=""){
						$pdf->valueRow("Carbonate Margin Geometry",implode($content->carbonate_margin_geometry, ", "),30 + $indent);
					}

					if($content->other_carbonate_margin_geometry!=""){
						$pdf->valueRow("Other Carbonate Margin Geometry",$content->other_carbonate_margin_geometry,30 + $indent);
					}

					if($content->notes!=""){
						$pdf->valueRow("Notes",$content->notes,30 + $indent);
					}

				}
			}

		}

		if($spot['pet']){
			if($spot['pet']->metamorphic){
				$pdf->valueTitle("Metamorphic Rock(s): ", 15 + $indent);
				$rocks = $spot['pet']->metamorphic;

				$rockNum = 1;
				foreach($rocks as $r){
					$rockString = "";
					$rockString .= ", " . $r->metamorphic_rock_type;
					$rockString .= ", " . implode(", ", $r->facies);
					$rockString .= ", " . $r->protolith;
					$rockString .= ", " . implode(", ", $r->zone);

					$pdf->valueRow($rockNum, $rockString, 20 + $indent);
					$rockNum++;
				}

				if($content->section_well_name!=""){
					$pdf->valueRow("Section/Well Name",$content->section_well_name,25 + $indent);
				}
			}

			if($spot['pet']->igneous){
				$pdf->valueTitle("Igneous Rock(s): ", 15 + $indent);
				$rocks = $spot['pet']->igneous;

				$rockNum = 1;
				foreach($rocks as $r){
					$rockString = "";
					if($r->igneous_rock_class) $rockString .= ", " . $r->igneous_rock_class;
					if($r->volcanic_rock_type) $rockString .= ", " . $r->volcanic_rock_type;
					if($r->occurence_volcanic) $rockString .= ", " . $r->occurence_volcanic;
					if($r->plutonic_rock_type) $rockString .= ", " . $r->plutonic_rock_type;
					if($r->occurence_plutonic) $rockString .= ", " . $r->occurence_plutonic;
					if($r->texture_volcanic) $rockString .= ", " . implode(", ", $r->texture_volcanic);
					if($r->texture_plutonic) $rockString .= ", " . implode(", ", $r->texture_plutonic);
					if($r->color_index_volc) $rockString .= ", " . $r->color_index_volc;
					if($r->color_index_pluton) $rockString .= ", " . $r->color_index_pluton;
					if($r->color_index_source_volc) $rockString .= ", " . $r->color_index_source_volc;
					if($r->color_index_source_pluton) $rockString .= ", " . $r->color_index_source_pluton;
					if($r->alteration_volcanic) $rockString .= ", " . implode(", ", $r->alteration_volcanic);
					if($r->alteration_plutonic) $rockString .= ", " . implode(", ", $r->alteration_plutonic);

					$pdf->valueRow($rockNum, $rockString, 20 + $indent);
					$rockNum++;
				}

				if($content->section_well_name!=""){
					$pdf->valueRow("Section/Well Name",$content->section_well_name,25 + $indent);
				}
			}

			if($spot['pet']->minerals){
				$pdf->valueTitle("Mineral(s): ", 15 + $indent);
				$rocks = $spot['pet']->minerals;

				$rockNum = 1;
				foreach($rocks as $r){
					$rockString = "";
					if($r->full_mineral_name) $rockString .= ", " . $r->full_mineral_name;
					if($r->igneous_or_metamorphic == "ig_min"){
						$rockString .= " (Igneous)";
					}else{
						$rockString .= " (Metamorphic)";
					}
					if($r->average_grain_size_mm) $rockString .= ", Avg Size: " . $r->average_grain_size_mm . "mm";
					if($r->maximum_grain_size_mm) $rockString .= ", Max Size: " . $r->maximum_grain_size_mm . "mm";
					if($r->modal) $rockString .= ", Modal: " . $r->modal . "%";
					if($r->mineral_notes) $rockString .= " " . $r->mineral_notes;

					$pdf->valueRow($rockNum, $rockString, 20 + $indent);
					$rockNum++;
				}

				if($content->section_well_name!=""){
					$pdf->valueRow("Section/Well Name",$content->section_well_name,25 + $indent);
				}
			}

		}

		if($spot['images']){

			$pdf->valueRow("Images","",15 + $indent);
			$pdf->Ln(1);
			foreach($spot['images'] as $o){
				if($o['title']){
					$thistitle = $this->fixLabel($o['title']);
					$pdf->valueTitle($thistitle.": ",20 + $indent);
				}else{
					$thistitle = $o['id'];
				}

				foreach($o as $key=>$value){
					if($value != ""){
						if($key!="id" && $key!="self" && $key!="annotated" && $key!="title" && $key!="width" && $key!="height" && $key!="image_type" && $key!="caption" ){
							$key = $this->fixLabel($key);
							if(is_string($value)){
								$value = $this->fixLabel($value);
							}
							$pdf->valueRow($key,$value,20 + $indent);
						}
					}
				}

				$pdf->Ln(1);

				$filename = $this->strabo->getImageFilename($o['id']);

				if($filename){
					$gdimage = $this->gdThumbWithSpots($filename, $o['id'], $allspots);
					if($gdimage){
						$pdf->GDImage($gdimage, 20, null, 170); //60
					}
				}

				if(trim($o['caption']) != ""){
					$pdf->imageCaptionRow("Caption", $o['caption'], 20 + $indent);
				}

				//Add spots on image basemap here. Figure out how to indent all options.

				$imagehasspots = false;
				foreach($allspots as $imagespot){
					$imagespot = $imagespot['properties'];
					if($imagespot['image_basemap'] == $o['id']){
						$imagehasspots = true;
					}

				}

				if($imagehasspots){

					//add "spots on basemap"
					$pdf->largeValue("Spots on Basemap:", $indent + 20);

					foreach($allspots as $imagespot){

						$imagespot = $imagespot['properties'];

						if($imagespot['image_basemap'] == $o['id']){
							$this->addSpotToPDF($pdf, $imagespot, $allspots, $indent + 10);
						}

					}
				}

			}
		}

		$pdf->Ln(5);

	}

	public function devaddSpotToPDF(&$pdf, &$spot, &$allspots, $indent = 0){

		$rawspot = $spot;

		$spot = $spot['properties'];

		$id = $spot['id'];

		//Move all of this to its own function.

		$spotname = $spot['name'];
		if($spot['geometrytype']){
			$spotname .= " (".$spot['geometrytype'].")";
		}

		$pdf->spotTitle($spotname, 15 + $indent);

		$modified = (string) $spot['id'];
		$modified = substr($modified,0,10);
		$modified = date("F j, Y",$modified);
		$pdf->valueRow("Created",$modified,15 + $indent);

		$modified = (string) $spot['modified_timestamp'];
		$modified = substr($modified,0,10);
		$modified = date("F j, Y",$modified);
		$pdf->valueRow("Last Modified",$modified,15 + $indent);

		if($rawspot['geometry']->type=="Point" && $rawspot['properties']['strat_section_id']==""){
			$pdf->valueRow("Longitude",$rawspot['geometry']->coordinates[0],15 + $indent);
			$pdf->valueRow("Latitude",$rawspot['geometry']->coordinates[1],15 + $indent);
		}

		if($spot['notes']){
			$notes = $spot['notes'];
			$pdf->notesRow("Notes",$notes,15 + $indent);
		}

		if($spot['surface_feature']){
			foreach($spot['surface_feature'] as $key=>$value){
				$key = $this->fixLabel($key);
				if(is_string($value)){
					$value = $this->fixLabel($value);
				}
				$pdf->valueRow($key,$value,15 + $indent);
			}
		}

		if($spot['trace']){
			foreach($spot['trace'] as $key=>$value){
				if($key != "trace_feature"){
					$key = $this->fixLabel($key);
					if(is_string($value)){
						$value = $this->fixLabel($value);
					}
					$pdf->valueRow($key,$value,15 + $indent);
				}
			}
		}

		if($spot['orientation_data']){
			$pdf->valueRow("Orientations","",15 + $indent);
			foreach($spot['orientation_data'] as $o){
				$pdf->valueTitle($this->fixLabel($o->type).": ",20 + $indent);
				foreach($o as $key=>$value){
					if($key!="id" && $key!="associated_orientation" && $key!="type"){
						$key = $this->fixLabel($key);
						if(is_string($value)){
							$value = $this->fixLabel($value);
						}
						$pdf->valueRow($key,$value,20 + $indent);
					}
				}

				if($o->associated_orientation){
					$pdf->valueRow("Associated Orientation Data","",20 + $indent);
					foreach($o->associated_orientation as $ao){
						$pdf->valueTitle($this->fixLabel($ao->type).": ",30 + $indent);
						foreach($ao as $key=>$value){
							if($key!="id" && $key!="associated_orientation" && $key!="type"){
								$key = $this->fixLabel($key);
								if(is_string($value)){
									$value = $this->fixLabel($value);
								}
								$pdf->valueRow($key,$value,30 + $indent);
							}
						}
						$pdf->Ln(1);
					}
				}

				$pdf->Ln(1);
			}
		}

		if($spot['_3d_structures']){
			$pdf->valueRow("3D Structures","",15 + $indent);
			foreach($spot['_3d_structures'] as $o){
				$pdf->valueTitle($this->fixLabel($o->type).": ",20 + $indent);
				foreach($o as $key=>$value){
					if($key!="id" && $key!="type"){
						$key = $this->fixLabel($key);
						if(is_string($value)){
							$value = $this->fixLabel($value);
						}
						$pdf->valueRow($key,$value,20 + $indent);
					}
				}

				$pdf->Ln(1);
			}
		}

		if($spot['samples']){
			$pdf->valueRow("Samples","",15 + $indent);
			foreach($spot['samples'] as $o){
				$pdf->valueTitle($this->fixLabel($o->label).": ",20 + $indent);
				foreach($o as $key=>$value){
					if($key!="id" && $key!="label"){
						$key = $this->fixLabel($key);
						if(is_string($value)){
							$value = $this->fixLabel($value);
						}
						$pdf->valueRow($key,$value,20 + $indent);
					}
				}

				$pdf->Ln(1);
			}
		}

		if($spot['tephra']){
			$pdf->valueRow("Tephra Intervals","",15 + $indent);
			foreach($spot['tephra'] as $o){
				$pdf->valueTitle("Interval: ",20 + $indent);
				foreach($o as $key=>$value){
					if($key!="id" && $key!="label"){
						$key = $this->fixLabel($key);
						if(is_string($value)){
							$value = $this->fixLabel($value);
						}
						$pdf->valueRow($key,$value,20 + $indent);
					}
				}

				$pdf->Ln(1);
			}
		}

		if($spot['other_features']){
			$pdf->valueRow("Other Features","",15 + $indent);
			foreach($spot['other_features'] as $o){
				$pdf->valueTitle($this->fixLabel($o->label).": ",20 + $indent);
				foreach($o as $key=>$value){
					if($key!="id" && $key!="label"){
						$key = $this->fixLabel($key);
						if(is_string($value)){
							$value = $this->fixLabel($value);
						}
						$pdf->valueRow($key,$value,20 + $indent);
					}
				}

				$pdf->Ln(1);
			}
		}

		$hastags = "no";

		if($this->alltags){
			foreach($this->alltags as $tag){
				$found = "no";
				if($tag->spots){
					if($tag->type!="geologic_unit"){
						foreach($tag->spots as $spotid){
							if($spotid == $id){
								$hastags = "yes";
							}
						}
					}
				}

			}
		}

		if($hastags == "yes"){

			$pdf->valueRow("Tags","",15 + $indent);

			if($this->alltags){
				foreach($this->alltags as $tag){
					$found = "no";
					if($tag->spots){
						if($tag->type!="geologic_unit"){
							foreach($tag->spots as $spotid){
								if($spotid == $id){
									$found = "yes";
								}
							}
						}
					}

					if($found == "yes"){

						$pdf->valueTitle($tag->name,20 + $indent);
						foreach($tag as $key=>$value){

							if($key != "date" && $key != "spots" && $key != "features" && $key != "id" && $key != "name" ){
								$key = $this->fixLabel($key);
								if(is_string($value)){
									$value = $this->fixLabel($value);
								}elseif(is_array($value)){
									$value = implode(", ", $value);
								}
								$pdf->valueRow($key,$value,20 + $indent);
							}

						}

						$pdf->Ln(1);

					}
				}
			}

		}

		$hastags = "no";

		if($this->alltags){
			foreach($this->alltags as $tag){

				$found = "no";
				if($tag->spots){
					if($tag->type=="geologic_unit"){
						foreach($tag->spots as $spotid){
							if($spotid == $id){
								$hastags = "yes";
							}
						}
					}
				}

			}
		}

		if($hastags == "yes"){

			$pdf->valueRow("Geologic Unit(s)","",15 + $indent);

			if($this->alltags){
				foreach($this->alltags as $tag){
					$found = "no";
					if($tag->spots){
						if($tag->type=="geologic_unit"){
							foreach($tag->spots as $spotid){
								if($spotid == $id){
									$found = "yes";
								}
							}
						}
					}

					if($found == "yes"){

						$pdf->valueTitle($tag->name,20 + $indent);
						foreach($tag as $key=>$value){

							if($key != "date" && $key != "spots" && $key != "features" && $key != "id" && $key != "name" ){
								$key = $this->fixLabel($key);
								if(is_string($value)){
									$value = $this->fixLabel($value);
								}elseif(is_array($value)){
									$value = implode(", ", $value);
								}
								$pdf->valueRow($key,$value,20 + $indent);
							}

						}

						$pdf->Ln(1);

					}
				}
			}

		}

		if($spot['sed']){
			$pdf->valueRow("Sed","",15 + $indent);
			if($spot['sed']->strat_section){
				$pdf->valueTitle("Strat Section: ", 20 + $indent);
				$content = $spot['sed']->strat_section;

				if($content->section_well_name!=""){
					$pdf->valueRow("Section/Well Name",$content->section_well_name,25 + $indent);
				}

				if($content->column_profile!=""){
					$pdf->valueRow("Column Profile",$content->column_profile,25 + $indent);
				}

				if($content->column_y_axis_units!=""){
					$pdf->valueRow("Column Y-Axis Units",$content->column_y_axis_units,25 + $indent);
				}

				if($content->section_type!=""){
					$pdf->valueRow("Section Type",$content->section_type,25 + $indent);
				}

				if($content->what_core_repository!=""){
					$pdf->valueRow("What Core Repository?",$content->what_core_repository,25 + $indent);
				}

				if($content->type_of_corer!=""){
					$pdf->valueRow("Type of corer",$content->type_of_corer,25 + $indent);
				}

				if($content->depth_from_surface_to_start_of!=""){
					$pdf->valueRow("Depth from surface to start of core",$content->depth_from_surface_to_start_of,25 + $indent);
				}

				if($content->total_core_length!=""){
					$pdf->valueRow("Total core length",$content->total_core_length,25 + $indent);
				}

				if($content->location_locality!=""){
					$pdf->valueRow("Location/Locality",$content->location_locality,25 + $indent);
				}

				if($content->basin!=""){
					$pdf->valueRow("Basin",$content->basin,25 + $indent);
				}

				if($content->age!=""){
					$pdf->valueRow("Age",$content->age,25 + $indent);
				}

				if($content->purpose!=""){
					$pdf->valueRow("Purpose",implode($content->purpose, ", "),25 + $indent);
				}

				if($content->other_purpose!=""){
					$pdf->valueRow("Other Purpose",$content->other_purpose,25 + $indent);
				}

				if($content->project_description!=""){
					$pdf->valueRow("Project Description",$content->project_description,25 + $indent);
				}

				if($content->dates_of_work!=""){
					$pdf->valueRow("Dates of Work",$content->dates_of_work,25 + $indent);
				}

				if($content->scale_of_interest!=""){
					$pdf->valueRow("Scale of Interest",implode($content->scale_of_interest, ", "),25 + $indent);
				}

				if($content->other_scale_of_interest!=""){
					$pdf->valueRow("Other Scale of Interest",$content->other_scale_of_interest,25 + $indent);
				}

				if($content->obs_interval_bed_obs_scale!=""){
					$pdf->valueRow("Observation Interval (average bed/observation scale)",$content->obs_interval_bed_obs_scale,25 + $indent);
				}

				if($content->how_is_section_georeferenced!=""){
					$pdf->valueRow("How is the Section Georeferenced?",$content->how_is_section_georeferenced,25 + $indent);
				}

				if($content->strat_section_notes!=""){
					$pdf->valueRow("Notes",$content->strat_section_notes,25 + $indent);
				}

				//strat section link here
				$pdf->httpLink("Download Strat Section", 25, "https://strabospot.org/pstrat_section?id=".$spot['id']."&did=".$dsids);

			}

			if($spot['sed']->lithologies){
				$pdf->valueTitle("Lithologies: ", 20 + $indent);
				$contents = $spot['sed']->lithologies;
				foreach($contents as $content){
					$pdf->valueTitle("Primary Lithology: ", 25 + $indent);

					if($content->primary_lithology!=""){
						$pdf->valueRow("Primary Lithology",$content->primary_lithology,30 + $indent);
					}

					if($content->siliciclastic_type!=""){
						$pdf->valueRow("Siliciclastic Type",$content->siliciclastic_type,30 + $indent);
					}

					if($content->dunham_classification!=""){
						$pdf->valueRow("Dunham Classification",$content->dunham_classification,30 + $indent);
					}

					if($content->grain_type!=""){
						$pdf->valueRow("Grain Type",$content->grain_type,30 + $indent);
					}

					if($content->evaporite_type!=""){
						$pdf->valueRow("Evaporite type",implode($content->evaporite_type, ", "),30 + $indent);
					}

					if($content->other_evaporite_type!=""){
						$pdf->valueRow("Other Evaporite Type",$content->other_evaporite_type,30 + $indent);
					}

					if($content->organic_coal_lithologies!=""){
						$pdf->valueRow("Organic/Coal Lithologies",implode($content->organic_coal_lithologies, ", "),30 + $indent);
					}

					if($content->other_organic_coal_lithology!=""){
						$pdf->valueRow("Other Organic/Coal Lithology",$content->other_organic_coal_lithology,30 + $indent);
					}

					if($content->volcaniclastic_type!=""){
						$pdf->valueRow("Volcaniclastic type",implode($content->volcaniclastic_type, ", "),30 + $indent);
					}

					if($content->other_volcaniclastic_type!=""){
						$pdf->valueRow("Other Volcaniclastic Type",$content->other_volcaniclastic_type,30 + $indent);
					}

					if($content->report_presence_of_particle_ag!=""){
						$pdf->valueRow("Report presence of particle aggregates ",$content->report_presence_of_particle_ag,30 + $indent);
					}

					if($content->componentry!=""){
						$pdf->valueRow("Componentry",implode($content->componentry, ", "),30 + $indent);
					}

					if($content->approximate_relative_abundance!=""){
						$pdf->valueRow("Approximate relative abundances of clasts",$content->approximate_relative_abundance,30 + $indent);
					}

					if($content->phosphorite_type!=""){
						$pdf->valueRow("Phosphorite type",implode($content->phosphorite_type, ", "),30 + $indent);
					}

					if($content->other_phosphorite_type!=""){
						$pdf->valueRow("Other Phosphorite Type",$content->other_phosphorite_type,30 + $indent);
					}

					$pdf->valueTitle("Lithification & Color: ", 25 + $indent);

					if($content->relative_resistance_weather!=""){
						$pdf->valueRow("Relative resistance (weathering profile)",$content->relative_resistance_weather,30 + $indent);
					}

					if($content->lithification!=""){
						$pdf->valueRow("Lithification",$content->lithification,30 + $indent);
					}

					if($content->evidence_of_deposit_alteration!=""){
						$pdf->valueRow("Evidence of deposit alteration",$content->evidence_of_deposit_alteration,30 + $indent);
					}

					if($content->evidence_of_clast_alteration!=""){
						$pdf->valueRow("Evidence of clast alteration",$content->evidence_of_clast_alteration,30 + $indent);
					}

					if($content->fresh_color!=""){
						$pdf->valueRow("Fresh Color",$content->fresh_color,30 + $indent);
					}

					if($content->weathered_color!=""){
						$pdf->valueRow("Weathered Color",$content->weathered_color,30 + $indent);
					}

					if($content->color_appearance!=""){
						$pdf->valueRow("Color Appearance",implode($content->color_appearance, ", "),30 + $indent);
					}

					if($content->other_color_appearance!=""){
						$pdf->valueRow("Other Color Appearance",$content->other_color_appearance,30 + $indent);
					}

					if($content->notes!=""){
						$pdf->valueRow("Notes",$content->notes,30 + $indent);
					}

					$pdf->valueTitle("Composition: ", 25 + $indent);

					if($content->minerals_present!=""){
						$pdf->valueRow("Minerals Present",implode($content->minerals_present, ", "),30 + $indent);
					}

					if($content->other_minerals!=""){
						$pdf->valueRow("Other Mineral(s)",$content->other_minerals,30 + $indent);
					}

					if($content->sandstone_type_dott!=""){
						$pdf->valueRow("Dott Classification",implode($content->sandstone_type_dott, ", "),30 + $indent);
					}

					if($content->sandstone_type_folk_mcbride!=""){
						$pdf->valueRow("Folk/McBride Classification",implode($content->sandstone_type_folk_mcbride, ", "),30 + $indent);
					}

					if($content->sandstone_modifier!=""){
						$pdf->valueRow("Sandstone modifier",implode($content->sandstone_modifier, ", "),30 + $indent);
					}

					if($content->other_sandstone_modifier!=""){
						$pdf->valueRow("Other Sandstone Modifier",$content->other_sandstone_modifier,30 + $indent);
					}

					if($content->skeletal_carbonate_components!=""){
						$pdf->valueRow("Skeletal Carbonate Components",implode($content->skeletal_carbonate_components, ", "),30 + $indent);
					}

					if($content->other_skeletal_carbonate_component!=""){
						$pdf->valueRow("Other Skeletal Carbonate Component",$content->other_skeletal_carbonate_component,30 + $indent);
					}

					if($content->skeletal_general_percent!=""){
						$pdf->valueRow("Skeletal (General) percent",$content->skeletal_general_percent,30 + $indent);
					}

					if($content->mollusc_percent!=""){
						$pdf->valueRow("Mollusc percent",$content->mollusc_percent,30 + $indent);
					}

					if($content->brachiopod_percent!=""){
						$pdf->valueRow("Brachiopod percent",$content->brachiopod_percent,30 + $indent);
					}

					if($content->coral_percent!=""){
						$pdf->valueRow("Coral percent",$content->coral_percent,30 + $indent);
					}

					if($content->echinoderm_percent!=""){
						$pdf->valueRow("Echinoderm percent",$content->echinoderm_percent,30 + $indent);
					}

					if($content->bryozoan_percent!=""){
						$pdf->valueRow("Bryozoan percent",$content->bryozoan_percent,30 + $indent);
					}

					if($content->calcareous_algae_percent!=""){
						$pdf->valueRow("Calcareous Algae percent",$content->calcareous_algae_percent,30 + $indent);
					}

					if($content->foraminifera_percent!=""){
						$pdf->valueRow("Foraminifera percent",$content->foraminifera_percent,30 + $indent);
					}

					if($content->stromatolite_percent!=""){
						$pdf->valueRow("Stromatolite percent",$content->stromatolite_percent,30 + $indent);
					}

					if($content->thrombolite_percent!=""){
						$pdf->valueRow("Thrombolite percent",$content->thrombolite_percent,30 + $indent);
					}

					if($content->dendrolite_percent!=""){
						$pdf->valueRow("Dendrolite percent",$content->dendrolite_percent,30 + $indent);
					}

					if($content->leiolite_percent!=""){
						$pdf->valueRow("Leiolite percent",$content->leiolite_percent,30 + $indent);
					}

					if($content->other_skeletal_carbonate_component_percent!=""){
						$pdf->valueRow("Other Skeletal Carbonate Component percent",$content->other_skeletal_carbonate_component_percent,30 + $indent);
					}

					if($content->non_skeletal_carbonate_compone!=""){
						$pdf->valueRow("Non-Skeletal Carbonate Components",implode($content->non_skeletal_carbonate_compone, ", "),30 + $indent);
					}

					if($content->other_non_skeletal_carbonate_component!=""){
						$pdf->valueRow("Other Non-Skeletal Carbonate Component",$content->other_non_skeletal_carbonate_component,30 + $indent);
					}

					if($content->mud_percent!=""){
						$pdf->valueRow("Mud percent",$content->mud_percent,30 + $indent);
					}

					if($content->cement_percent!=""){
						$pdf->valueRow("Cement percent",$content->cement_percent,30 + $indent);
					}

					if($content->intraclast_percent!=""){
						$pdf->valueRow("Intraclast percent",$content->intraclast_percent,30 + $indent);
					}

					if($content->peloid_percent!=""){
						$pdf->valueRow("Peloid percent",$content->peloid_percent,30 + $indent);
					}

					if($content->ooid_percent!=""){
						$pdf->valueRow("Ooid percent",$content->ooid_percent,30 + $indent);
					}

					if($content->oncoid_percent!=""){
						$pdf->valueRow("Oncoid percent",$content->oncoid_percent,30 + $indent);
					}

					if($content->pisoid_percent!=""){
						$pdf->valueRow("Pisoid percent",$content->pisoid_percent,30 + $indent);
					}

					if($content->coated_grian_percent!=""){
						$pdf->valueRow("Coated Grian percent",$content->coated_grian_percent,30 + $indent);
					}

					if($content->grapestone_percent!=""){
						$pdf->valueRow("Grapestone percent",$content->grapestone_percent,30 + $indent);
					}

					if($content->giant_ooid_percent!=""){
						$pdf->valueRow("Giant Ooid percent",$content->giant_ooid_percent,30 + $indent);
					}

					if($content->seafloor_precipitate_percent!=""){
						$pdf->valueRow("Seafloor Precipitate percent",$content->seafloor_precipitate_percent,30 + $indent);
					}

					if($content->molar_tooth_percent!=""){
						$pdf->valueRow("Molar Tooth percent",$content->molar_tooth_percent,30 + $indent);
					}

					if($content->other_non_skeletal_component_percent!=""){
						$pdf->valueRow("Other Non-Skeletal Component percent",$content->other_non_skeletal_component_percent,30 + $indent);
					}

					if($content->clay_or_mudstone_type!=""){
						$pdf->valueRow("Claystone or Mudstone Type",implode($content->clay_or_mudstone_type, ", "),30 + $indent);
					}

					if($content->other_claystone_mudstone!=""){
						$pdf->valueRow("Other Claystone/Mudstone",$content->other_claystone_mudstone,30 + $indent);
					}

					if($content->siliceous_mudstone_percent!=""){
						$pdf->valueRow("Siliceous mudstone percent",$content->siliceous_mudstone_percent,30 + $indent);
					}

					if($content->siliceous_calcareous_mudstone_percent!=""){
						$pdf->valueRow("Siliceous calcareous mudstone percent",$content->siliceous_calcareous_mudstone_percent,30 + $indent);
					}

					if($content->siliceous_volcanicla_tic_mudstone_percent!=""){
						$pdf->valueRow("Siliceous volcaniclastic mudstone percent",$content->siliceous_volcanicla_tic_mudstone_percent,30 + $indent);
					}

					if($content->calcareous_mudstone_percent!=""){
						$pdf->valueRow("Calcareous mudstone percent",$content->calcareous_mudstone_percent,30 + $indent);
					}

					if($content->black_shale_percent!=""){
						$pdf->valueRow("Black shale percent",$content->black_shale_percent,30 + $indent);
					}

					if($content->red_clay_percent!=""){
						$pdf->valueRow("Red clay percent",$content->red_clay_percent,30 + $indent);
					}

					if($content->red_mudstone_percent!=""){
						$pdf->valueRow("Red mudstone percent",$content->red_mudstone_percent,30 + $indent);
					}

					if($content->green_mudstone_percent!=""){
						$pdf->valueRow("Green mudstone percent",$content->green_mudstone_percent,30 + $indent);
					}

					if($content->variegated_mudstone_percent!=""){
						$pdf->valueRow("Variegated mudstone percent",$content->variegated_mudstone_percent,30 + $indent);
					}

					if($content->marl_percent!=""){
						$pdf->valueRow("Marl percent",$content->marl_percent,30 + $indent);
					}

					if($content->sarl_percent!=""){
						$pdf->valueRow("Sarl percent",$content->sarl_percent,30 + $indent);
					}

					if($content->argillaceous_mudstone_percent!=""){
						$pdf->valueRow("Argillaceous mudstone percent",$content->argillaceous_mudstone_percent,30 + $indent);
					}

					if($content->conglomerate_composition!=""){
						$pdf->valueRow("Conglomerate/Breccia Composition",implode($content->conglomerate_composition, ", "),30 + $indent);
					}

					if($content->clast_composition!=""){
						$pdf->valueRow("Clast Composition",implode($content->clast_composition, ", "),30 + $indent);
					}

					if($content->other_clast_types!=""){
						$pdf->valueRow("Other Clast Type(s)",$content->other_clast_types,30 + $indent);
					}

					if($content->intrusive_igneous_clast_percent!=""){
						$pdf->valueRow("Intrusive igneous clast percent",$content->intrusive_igneous_clast_percent,30 + $indent);
					}

					if($content->extrusive_igneous_clast_percent!=""){
						$pdf->valueRow("Volcanic clast percent",$content->extrusive_igneous_clast_percent,30 + $indent);
					}

					if($content->metamorphic_clast_percent!=""){
						$pdf->valueRow("Metamorphic clast percent",$content->metamorphic_clast_percent,30 + $indent);
					}

					if($content->mudstone_clast_percent!=""){
						$pdf->valueRow("Mudstone clast percent",$content->mudstone_clast_percent,30 + $indent);
					}

					if($content->siltstone_clast_percent!=""){
						$pdf->valueRow("Siltstone clast percent",$content->siltstone_clast_percent,30 + $indent);
					}

					if($content->sandstone_clast_percent!=""){
						$pdf->valueRow("Sandstone clast percent",$content->sandstone_clast_percent,30 + $indent);
					}

					if($content->conglomerate_clast_percent!=""){
						$pdf->valueRow("Conglomerate clast percent",$content->conglomerate_clast_percent,30 + $indent);
					}

					if($content->limestone_clast_percent!=""){
						$pdf->valueRow("Limestone clast percent",$content->limestone_clast_percent,30 + $indent);
					}

					if($content->dolostone_clast_percent!=""){
						$pdf->valueRow("Dolostone clast percent",$content->dolostone_clast_percent,30 + $indent);
					}

					if($content->wackestone_clast_percent!=""){
						$pdf->valueRow("Wackestone clast percent",$content->wackestone_clast_percent,30 + $indent);
					}

					if($content->packstone_clast_percent!=""){
						$pdf->valueRow("Packstone clast percent",$content->packstone_clast_percent,30 + $indent);
					}

					if($content->grainstone_clast_percent!=""){
						$pdf->valueRow("Grainstone clast percent",$content->grainstone_clast_percent,30 + $indent);
					}

					if($content->boundstone_clast_percent!=""){
						$pdf->valueRow("Boundstone clast percent",$content->boundstone_clast_percent,30 + $indent);
					}

					if($content->other_clast_percent!=""){
						$pdf->valueRow("Other clast percent",$content->other_clast_percent,30 + $indent);
					}

					if($content->matrix_composition!=""){
						$pdf->valueRow("Matrix composition",implode($content->matrix_composition, ", "),30 + $indent);
					}

					if($content->other_matrix_types!=""){
						$pdf->valueRow("Other Matrix Type(s)",$content->other_matrix_types,30 + $indent);
					}

					if($content->intrusive_igneous_matrix_percent!=""){
						$pdf->valueRow("Intrusive igneous matrix percent",$content->intrusive_igneous_matrix_percent,30 + $indent);
					}

					if($content->extrusive_igneous_matrix_percent!=""){
						$pdf->valueRow("Extrusive igneous matrix percent",$content->extrusive_igneous_matrix_percent,30 + $indent);
					}

					if($content->metamorphic_igneous_matrix_percent!=""){
						$pdf->valueRow("Metamorphic igneous matrix percent",$content->metamorphic_igneous_matrix_percent,30 + $indent);
					}

					if($content->mudstone_matrix_percent!=""){
						$pdf->valueRow("Mudstone matrix percent",$content->mudstone_matrix_percent,30 + $indent);
					}

					if($content->siltstone_matrix_percent!=""){
						$pdf->valueRow("Siltstone matrix percent",$content->siltstone_matrix_percent,30 + $indent);
					}

					if($content->sandstone_matrix_percent!=""){
						$pdf->valueRow("Sandstone matrix percent",$content->sandstone_matrix_percent,30 + $indent);
					}

					if($content->conglomerate_matrix_percent!=""){
						$pdf->valueRow("Conglomerate matrix percent",$content->conglomerate_matrix_percent,30 + $indent);
					}

					if($content->carbonate_matrix_type!=""){
						$pdf->valueRow("Carbonate Matrix Type",implode($content->carbonate_matrix_type, ", "),30 + $indent);
					}

					if($content->limestone_matrix_percent!=""){
						$pdf->valueRow("Limestone matrix percent",$content->limestone_matrix_percent,30 + $indent);
					}

					if($content->dolostone_matrix_percent!=""){
						$pdf->valueRow("Dolostone matrix percent",$content->dolostone_matrix_percent,30 + $indent);
					}

					if($content->skeletal_matrix_percent!=""){
						$pdf->valueRow("Skeletal matrix percent",$content->skeletal_matrix_percent,30 + $indent);
					}

					if($content->wackestone_matrix_percent!=""){
						$pdf->valueRow("Wackestone matrix percent",$content->wackestone_matrix_percent,30 + $indent);
					}

					if($content->packstone_matrix_percent!=""){
						$pdf->valueRow("Packstone matrix percent",$content->packstone_matrix_percent,30 + $indent);
					}

					if($content->grainstone_matrix_percent!=""){
						$pdf->valueRow("Grainstone matrix percent",$content->grainstone_matrix_percent,30 + $indent);
					}

					if($content->boundstone_matrix_percent!=""){
						$pdf->valueRow("Boundstone matrix percent",$content->boundstone_matrix_percent,30 + $indent);
					}

					if($content->other_carbonate_matrix_percent!=""){
						$pdf->valueRow("Other carbonate matrix percent",$content->other_carbonate_matrix_percent,30 + $indent);
					}

					if($content->other_matrix_percent!=""){
						$pdf->valueRow("Other matrix percent",$content->other_matrix_percent,30 + $indent);
					}

					if($content->volcaniclastic_type!=""){
						$pdf->valueRow("Volcaniclastic type",implode($content->volcaniclastic_type, ", "),30 + $indent);
					}

					if($content->other_volcaniclastic_type!=""){
						$pdf->valueRow("Other Volcaniclastic Type",$content->other_volcaniclastic_type,30 + $indent);
					}

					if($content->glass_percent!=""){
						$pdf->valueRow("Glass percent",$content->glass_percent,30 + $indent);
					}

					if($content->crystals_percent!=""){
						$pdf->valueRow("Crystals percent",$content->crystals_percent,30 + $indent);
					}

					if($content->lithic_fragments_percent!=""){
						$pdf->valueRow("Lithic fragments percent",$content->lithic_fragments_percent,30 + $indent);
					}

					if($content->volcanic_mudstone_percent!=""){
						$pdf->valueRow("Volcanic mudstone percent",$content->volcanic_mudstone_percent,30 + $indent);
					}

					if($content->volcanic_sandstone_percent!=""){
						$pdf->valueRow("Volcanic sandstone percent",$content->volcanic_sandstone_percent,30 + $indent);
					}

					if($content->lapillistone_percent!=""){
						$pdf->valueRow("Lapillistone percent",$content->lapillistone_percent,30 + $indent);
					}

					if($content->agglomerate_percent!=""){
						$pdf->valueRow("Agglomerate percent",$content->agglomerate_percent,30 + $indent);
					}

					if($content->volcanic_breccia_percent!=""){
						$pdf->valueRow("Volcanic breccia percent",$content->volcanic_breccia_percent,30 + $indent);
					}

					if($content->bentonite_percent!=""){
						$pdf->valueRow("Bentonite percent",$content->bentonite_percent,30 + $indent);
					}

					if($content->tuff_percent!=""){
						$pdf->valueRow("Tuff percent",$content->tuff_percent,30 + $indent);
					}

					if($content->welded_tuff_percent!=""){
						$pdf->valueRow("Welded tuff percent",$content->welded_tuff_percent,30 + $indent);
					}

					if($content->ignimbrite_percent!=""){
						$pdf->valueRow("Ignimbrite percent",$content->ignimbrite_percent,30 + $indent);
					}

					if($content->other_volcaniclastic_type_percent!=""){
						$pdf->valueRow("Other volcaniclastic type percent",$content->other_volcaniclastic_type_percent,30 + $indent);
					}

					if($content->evaporite_type!=""){
						$pdf->valueRow("Evaporite type",implode($content->evaporite_type, ", "),30 + $indent);
					}

					if($content->gypsum_anhydrite_primary_percent!=""){
						$pdf->valueRow("Gypsum - anhydrite primary percent",$content->gypsum_anhydrite_primary_percent,30 + $indent);
					}

					if($content->gypsum_anhydrite_primary_type!=""){
						$pdf->valueRow("Gypsum - anhydrite primary type",implode($content->gypsum_anhydrite_primary_type, ", "),30 + $indent);
					}

					if($content->gypsum_anhydrite_diagenetic_percent!=""){
						$pdf->valueRow("Gypsum - anhydrite diagenetic percent",$content->gypsum_anhydrite_diagenetic_percent,30 + $indent);
					}

					if($content->gypsum_anhydrite_diagenetic_type!=""){
						$pdf->valueRow("Gypsum - anhydrite diagenetic type",implode($content->gypsum_anhydrite_diagenetic_type, ", "),30 + $indent);
					}

					if($content->halite_primary_percent!=""){
						$pdf->valueRow("Halite - primary percent",$content->halite_primary_percent,30 + $indent);
					}

					if($content->halite_primary_type!=""){
						$pdf->valueRow("Halite - primary type",implode($content->halite_primary_type, ", "),30 + $indent);
					}

					if($content->halite_diagenetic_percent!=""){
						$pdf->valueRow("Halite - diagenetic percent",$content->halite_diagenetic_percent,30 + $indent);
					}

					if($content->halite_diagenetic_type!=""){
						$pdf->valueRow("Halite - diagenetic type",implode($content->halite_diagenetic_type, ", "),30 + $indent);
					}

					if($content->phosphorite_type!=""){
						$pdf->valueRow("Phosphorite type",implode($content->phosphorite_type, ", "),30 + $indent);
					}

					if($content->organic_coal_lithologies!=""){
						$pdf->valueRow("Organic/Coal Lithologies",implode($content->organic_coal_lithologies, ", "),30 + $indent);
					}

					if($content->amber_percent!=""){
						$pdf->valueRow("Amber percent",$content->amber_percent,30 + $indent);
					}

					if($content->peat_percent!=""){
						$pdf->valueRow("Peat percent",$content->peat_percent,30 + $indent);
					}

					if($content->lignite_percent!=""){
						$pdf->valueRow("Lignite percent",$content->lignite_percent,30 + $indent);
					}

					if($content->subbituminous_percent!=""){
						$pdf->valueRow("Subbituminous percent",$content->subbituminous_percent,30 + $indent);
					}

					if($content->bituminous_percent!=""){
						$pdf->valueRow("Bituminous percent",$content->bituminous_percent,30 + $indent);
					}

					if($content->coal_ball_percent!=""){
						$pdf->valueRow("Coal ball percent",$content->coal_ball_percent,30 + $indent);
					}

					if($content->tar_percent!=""){
						$pdf->valueRow("Tar percent",$content->tar_percent,30 + $indent);
					}

					if($content->notes!=""){
						$pdf->valueRow("Notes",$content->notes,30 + $indent);
					}

					$pdf->valueTitle("Texture: ", 25 + $indent);

					if($content->mud_silt_grain_size!=""){
						$pdf->valueRow("Mudstone/Siltstone Grain Size",$content->mud_silt_grain_size,30 + $indent);
					}

					if($content->sand_grain_size!=""){
						$pdf->valueRow("Sandstone Grain Size",$content->sand_grain_size,30 + $indent);
					}

					if($content->congl_grain_size!=""){
						$pdf->valueRow("Conglomerate Grain Size",$content->congl_grain_size,30 + $indent);
					}

					if($content->breccia_grain_size!=""){
						$pdf->valueRow("Breccia Grain Size",$content->breccia_grain_size,30 + $indent);
					}

					if($content->grain_size_range!=""){
						$pdf->valueRow("Grain Size Range",implode($content->grain_size_range, ", "),30 + $indent);
					}

					if($content->clay_percent!=""){
						$pdf->valueRow("Clay percent",$content->clay_percent,30 + $indent);
					}

					if($content->silt_percent!=""){
						$pdf->valueRow("Silt percent",$content->silt_percent,30 + $indent);
					}

					if($content->sand_very_fine_percent!=""){
						$pdf->valueRow("Sand - very fine percent",$content->sand_very_fine_percent,30 + $indent);
					}

					if($content->sand_fine_lower_percent!=""){
						$pdf->valueRow("Sand - fine lower percent",$content->sand_fine_lower_percent,30 + $indent);
					}

					if($content->sand_fine_upper_percent!=""){
						$pdf->valueRow("Sand - fine upper percent",$content->sand_fine_upper_percent,30 + $indent);
					}

					if($content->sand_medium_lower_percent!=""){
						$pdf->valueRow("Sand - medium lower percent",$content->sand_medium_lower_percent,30 + $indent);
					}

					if($content->sand_medium_upper_percent!=""){
						$pdf->valueRow("Sand - medium upper percent",$content->sand_medium_upper_percent,30 + $indent);
					}

					if($content->sand_coarse_lower_percent!=""){
						$pdf->valueRow("Sand - coarse lower percent",$content->sand_coarse_lower_percent,30 + $indent);
					}

					if($content->sand_coarse_upper_percent!=""){
						$pdf->valueRow("Sand - coarse upper percent",$content->sand_coarse_upper_percent,30 + $indent);
					}

					if($content->sand_very_coarse_percent!=""){
						$pdf->valueRow("Sand - very coarse percent",$content->sand_very_coarse_percent,30 + $indent);
					}

					if($content->granule_percent!=""){
						$pdf->valueRow("Granule percent",$content->granule_percent,30 + $indent);
					}

					if($content->pebble_percent!=""){
						$pdf->valueRow("Pebble percent",$content->pebble_percent,30 + $indent);
					}

					if($content->cobble_percent!=""){
						$pdf->valueRow("Cobble percent",$content->cobble_percent,30 + $indent);
					}

					if($content->boulder_percent!=""){
						$pdf->valueRow("Boulder percent",$content->boulder_percent,30 + $indent);
					}

					if($content->maximum_clast_size_cm!=""){
						$pdf->valueRow("Maximum Clast Size (cm)",$content->maximum_clast_size_cm,30 + $indent);
					}

					if($content->minimum_clast_size_cm!=""){
						$pdf->valueRow("Minimum Clast Size (cm)",$content->minimum_clast_size_cm,30 + $indent);
					}

					if($content->average_clast_size_cm!=""){
						$pdf->valueRow("Average Clast Size (cm)",$content->average_clast_size_cm,30 + $indent);
					}

					if($content->matrix_size!=""){
						$pdf->valueRow("Matrix Size",implode($content->matrix_size, ", "),30 + $indent);
					}

					if($content->character!=""){
						$pdf->valueRow("Character",implode($content->character, ", "),30 + $indent);
					}

					if($content->sorting!=""){
						$pdf->valueRow("Sorting",implode($content->sorting, ", "),30 + $indent);
					}

					if($content->rounding!=""){
						$pdf->valueRow("Rounding",implode($content->rounding, ", "),30 + $indent);
					}

					if($content->shape!=""){
						$pdf->valueRow("Shape",implode($content->shape, ", "),30 + $indent);
					}

					if($content->other_shape!=""){
						$pdf->valueRow("Other Shape",$content->other_shape,30 + $indent);
					}

					if($content->notes!=""){
						$pdf->valueRow("Notes",$content->notes,30 + $indent);
					}

					if($content->stratification!=""){
						$pdf->valueRow("Stratification",implode($content->stratification, ", "),30 + $indent);
					}

					if($content->laminae_thickness_i_select_more_than_one!=""){
						$pdf->valueRow("Laminae Thickness",implode($content->laminae_thickness_i_select_more_than_one, ", "),30 + $indent);
					}

					if($content->lamination_character!=""){
						$pdf->valueRow("Lamination Character",implode($content->lamination_character, ", "),30 + $indent);
					}

					if($content->bedding_thickness!=""){
						$pdf->valueRow("Bedding Thickness",implode($content->bedding_thickness, ", "),30 + $indent);
					}

					if($content->bedding_character!=""){
						$pdf->valueRow("Bedding character",implode($content->bedding_character, ", "),30 + $indent);
					}

					if($content->package_geometry!=""){
						$pdf->valueRow("Package Geometry",implode($content->package_geometry, ", "),30 + $indent);
					}

					if($content->package_lateral_extent!=""){
						$pdf->valueRow("Package Lateral Extent",$content->package_lateral_extent,30 + $indent);
					}

					if($content->package_bedding_trends!=""){
						$pdf->valueRow("Package Bedding Trends",$content->package_bedding_trends,30 + $indent);
					}

					if($content->other_bedding_trend!=""){
						$pdf->valueRow("Other bedding trend",$content->other_bedding_trend,30 + $indent);
					}

					if($content->shape_of_lower_contacts!=""){
						$pdf->valueRow("Shape of lower contact(s)",implode($content->shape_of_lower_contacts, ", "),30 + $indent);
					}

					if($content->character_of_lower_contacts!=""){
						$pdf->valueRow("Character of lower contact(s)",implode($content->character_of_lower_contacts, ", "),30 + $indent);
					}

					if($content->lower_contact_relief!=""){
						$pdf->valueRow("Lower contact relief",$content->lower_contact_relief,30 + $indent);
					}

					if($content->shape_of_upper_contacts!=""){
						$pdf->valueRow("Shape of upper contact(s)",implode($content->shape_of_upper_contacts, ", "),30 + $indent);
					}

					if($content->character_of_upper_contacts!=""){
						$pdf->valueRow("Character of upper contact(s)",implode($content->character_of_upper_contacts, ", "),30 + $indent);
					}

					if($content->upper_contact_relief!=""){
						$pdf->valueRow("Upper Contact Relief",$content->upper_contact_relief,30 + $indent);
					}

					if($content->interbed_proportion!=""){
						$pdf->valueRow("Interbed Relative Proportion (%)",$content->interbed_proportion,30 + $indent);
					}

					if($content->interbed_thickness!=""){
						$pdf->valueRow("Interbed Thickness",$content->interbed_thickness,30 + $indent);
					}

					if($content->interbed_proportion_change!=""){
						$pdf->valueRow("Interbed Proportion Change (Up Section)",$content->interbed_proportion_change,30 + $indent);
					}

					if($content->interbed_thickness_change!=""){
						$pdf->valueRow("Interbed Thickness Change (Up Section)",$content->interbed_thickness_change,30 + $indent);
					}

					if($content->Notes!=""){
						$pdf->valueRow("Notes",$content->Notes,30 + $indent);
					}

				}

			}

			if($spot['sed']->bedding){
				$pdf->valueTitle("Bedding: ", 20 + $indent);
				$content = $spot['sed']->bedding;

				if($content->interbed_proportion_change!=""){
					$pdf->valueRow("Interbed Proportion Change (Up Section)",$content->interbed_proportion_change,25 + $indent);
				}

				if($content->interbed_proportion!=""){
					$pdf->valueRow("Lithology 1: Interbed Relative Proportion (%)",$content->interbed_proportion,25 + $indent);
				}

				if($content->lithology_at_bottom_contact!=""){
					$pdf->valueRow("Which Lithology is at Bottom Contact?",$content->lithology_at_bottom_contact,25 + $indent);
				}

				if($content->lithology_at_top_contact!=""){
					$pdf->valueRow("Which Lithology is at Top Contact?",$content->lithology_at_top_contact,25 + $indent);
				}

				if($content->thickness_of_individual_beds!=""){
					$pdf->valueRow("Thickness of Individual Beds",$content->thickness_of_individual_beds,25 + $indent);
				}

				if($content->package_thickness_units!=""){
					$pdf->valueRow("Package Beds Thickness Units",$content->package_thickness_units,25 + $indent);
				}

				if($content->package_bedding_trends!=""){
					$pdf->valueRow("Package Bedding Trends",$content->package_bedding_trends,25 + $indent);
				}

				if($content->other_bedding_trend!=""){
					$pdf->valueRow("Other Package Bedding Trend",$content->other_bedding_trend,25 + $indent);
				}

				$beds = $spot['sed']->bedding->beds;
				foreach($beds as $content){

					if($content->package_geometry!=""){
						$pdf->valueRow("Bed Geometry",implode($content->package_geometry, ", "),25 + $indent);
					}

					$pdf->valueTitle("Lower Contact: ", 25 + $indent);

					if($content->shape_of_lower_contacts!=""){
						$pdf->valueRow("Shape of lower contact",implode($content->shape_of_lower_contacts, ", "),30 + $indent);
					}

					if($content->character_of_lower_contacts!=""){
						$pdf->valueRow("Character of lower contact(s)",implode($content->character_of_lower_contacts, ", "),30 + $indent);
					}

					if($content->lower_contact_relief!=""){
						$pdf->valueRow("Lower contact relief",$content->lower_contact_relief,30 + $indent);
					}

					$pdf->valueTitle("Upper Contact: ", 25 + $indent);

					if($content->shape_of_upper_contacts!=""){
						$pdf->valueRow("Shape of upper contact",implode($content->shape_of_upper_contacts, ", "),30 + $indent);
					}

					if($content->character_of_upper_contacts!=""){
						$pdf->valueRow("Character of upper contact",implode($content->character_of_upper_contacts, ", "),30 + $indent);
					}

					if($content->upper_contact_relief!=""){
						$pdf->valueRow("Upper Contact Relief",$content->upper_contact_relief,30 + $indent);
					}

					$pdf->valueTitle("Interbed Thickness: ", 25 + $indent);

					if($content->avg_thickness!=""){
						$pdf->valueRow("Average Thickness",$content->avg_thickness,30 + $indent);
					}

					if($content->max_thickness!=""){
						$pdf->valueRow("Maximum Thickness",$content->max_thickness,30 + $indent);
					}

					if($content->min_thickness!=""){
						$pdf->valueRow("Minimum Thickness",$content->min_thickness,30 + $indent);
					}

					if($content->interbed_thickness_units!=""){
						$pdf->valueRow("Interbed Thickness Units",$content->interbed_thickness_units,30 + $indent);
					}

					if($content->notes!=""){
						$pdf->valueRow("Notes",$content->notes,30 + $indent);
					}

				}
			}

			if($spot['sed']->structures){
				$pdf->valueTitle("Structures: ", 20 + $indent);
				$contents = $spot['sed']->structures;
				foreach($contents as $content){

					if($content->massive_structureless!=""){
						$pdf->valueRow("Massive/Structureless?",$content->massive_structureless,25 + $indent);
					}

					$pdf->valueTitle("Cross Bedding: ", 25 + $indent);

					if($content->cross_bedding_type!=""){
						$pdf->valueRow("Cross Bedding Type",implode($content->cross_bedding_type, ", "),30 + $indent);
					}

					if($content->cross_bedding_height_cm!=""){
						$pdf->valueRow("Cross Bedding Height (cm)",$content->cross_bedding_height_cm,30 + $indent);
					}

					if($content->cross_bedding_width_cm!=""){
						$pdf->valueRow("Cross Bedding Width (cm)",$content->cross_bedding_width_cm,30 + $indent);
					}

					if($content->cross_bedding_thickness_cm!=""){
						$pdf->valueRow("Cross Bedding Thickness (cm)",$content->cross_bedding_thickness_cm,30 + $indent);
					}

					if($content->cross_bedding_spacing_cm!=""){
						$pdf->valueRow("Cross Bedding Spacing (cm)",$content->cross_bedding_spacing_cm,30 + $indent);
					}

					$pdf->valueTitle("Ripple Lamination: ", 25 + $indent);

					if($content->ripple_lamination_type!=""){
						$pdf->valueRow("Ripple Lamination Type",implode($content->ripple_lamination_type, ", "),30 + $indent);
					}

					if($content->other_ripple_lamination_type!=""){
						$pdf->valueRow("Other Ripple Lamination Type",$content->other_ripple_lamination_type,30 + $indent);
					}

					if($content->ripple_lamination_height_mm!=""){
						$pdf->valueRow("Ripple Lamination Height (mm)",$content->ripple_lamination_height_mm,30 + $indent);
					}

					if($content->ripple_lamination_width_mm!=""){
						$pdf->valueRow("Ripple Lamination Width (mm)",$content->ripple_lamination_width_mm,30 + $indent);
					}

					if($content->ripple_lamination_thick_mm!=""){
						$pdf->valueRow("Ripple Lmation Thickness (mm)",$content->ripple_lamination_thick_mm,30 + $indent);
					}

					if($content->ripple_lamination_spacing_mm!=""){
						$pdf->valueRow("Ripple Lamination Spacing (mm)",$content->ripple_lamination_spacing_mm,30 + $indent);
					}

					$pdf->valueTitle("Horizontal Bedding: ", 25 + $indent);

					if($content->horizontal_bedding_type!=""){
						$pdf->valueRow("Horizontal Bedding Type",implode($content->horizontal_bedding_type, ", "),30 + $indent);
					}

					if($content->other_horizontal_bedding_type!=""){
						$pdf->valueRow("Other Horizontal Bedding Type",$content->other_horizontal_bedding_type,30 + $indent);
					}

					$pdf->valueTitle("Graded Bedding: ", 25 + $indent);

					if($content->graded_bedding_type!=""){
						$pdf->valueRow("Graded Bedding Type",$content->graded_bedding_type,30 + $indent);
					}

					$pdf->valueTitle("Deformation Structures: ", 25 + $indent);

					if($content->deformation_structures!=""){
						$pdf->valueRow("Deformation Structure Type",implode($content->deformation_structures, ", "),30 + $indent);
					}

					if($content->other_deformation_structure_type!=""){
						$pdf->valueRow("Other Deformation Structure Type",$content->other_deformation_structure_type,30 + $indent);
					}

					$pdf->valueTitle("Lags: ", 25 + $indent);

					if($content->lag_type!=""){
						$pdf->valueRow("Lag Type",implode($content->lag_type, ", "),30 + $indent);
					}

					if($content->other_lag_type!=""){
						$pdf->valueRow("Other Lag Type",$content->other_lag_type,30 + $indent);
					}

					if($content->clast_composition!=""){
						$pdf->valueRow("Clast Composition",$content->clast_composition,30 + $indent);
					}

					if($content->clast_size!=""){
						$pdf->valueRow("Clast Size",$content->clast_size,30 + $indent);
					}

					if($content->layer_thickness_shape!=""){
						$pdf->valueRow("Layer Thickness/Shape",$content->layer_thickness_shape,30 + $indent);
					}

					$pdf->valueTitle("Other Common Structures: ", 25 + $indent);

					if($content->other_common_structures!=""){
						$pdf->valueRow("Other Common Structure Type",implode($content->other_common_structures, ", "),30 + $indent);
					}

					if($content->bouma_sequence_part!=""){
						$pdf->valueRow("Bouma Sequence Part",implode($content->bouma_sequence_part, ", "),30 + $indent);
					}

					if($content->bioturbation_index!=""){
						$pdf->valueRow("Bioturbation Index",$content->bioturbation_index,30 + $indent);
					}

					if($content->bedding_plane_features!=""){
						$pdf->valueRow("Bedding plane features",implode($content->bedding_plane_features, ", "),30 + $indent);
					}

					if($content->other_bedding_plane_feature!=""){
						$pdf->valueRow("Other Bedding Plane Feature",$content->other_bedding_plane_feature,30 + $indent);
					}

					if($content->bedding_plane_features_scale!=""){
						$pdf->valueRow("Bedding Plane Features Scale",$content->bedding_plane_features_scale,30 + $indent);
					}

					if($content->bedding_plane_features_orientation!=""){
						$pdf->valueRow("Bedding Plane Features Orientation",$content->bedding_plane_features_orientation,30 + $indent);
					}

					if($content->bedform_type!=""){
						$pdf->valueRow("Bedform Type",implode($content->bedform_type, ", "),30 + $indent);
					}

					if($content->other_bedform_type!=""){
						$pdf->valueRow("Other Bedform Type",$content->other_bedform_type,30 + $indent);
					}

					if($content->bedform_scale!=""){
						$pdf->valueRow("Bedform Scale",$content->bedform_scale,30 + $indent);
					}

					if($content->crest_orientation_azimuth_0_360!=""){
						$pdf->valueRow("Crest Orientation",$content->crest_orientation_azimuth_0_360,30 + $indent);
					}

					$pdf->valueTitle("Pedogenic Structures: ", 25 + $indent);

					if($content->paleosol_horizons!=""){
						$pdf->valueRow("Master Paleosol Horizons",implode($content->paleosol_horizons, ", "),30 + $indent);
					}

					if($content->other_horizon!=""){
						$pdf->valueRow("Other Horizon",$content->other_horizon,30 + $indent);
					}

					if($content->o_horizon_thickness_cm!=""){
						$pdf->valueRow("O Horizon thickness (cm)",$content->o_horizon_thickness_cm,30 + $indent);
					}

					if($content->a_horizon_thickness_cm!=""){
						$pdf->valueRow("A Horizon thickness (cm)",$content->a_horizon_thickness_cm,30 + $indent);
					}

					if($content->e_horizon_thickness_cm!=""){
						$pdf->valueRow("E Horizon thickness (cm)",$content->e_horizon_thickness_cm,30 + $indent);
					}

					if($content->b_horizon_thickness_cm!=""){
						$pdf->valueRow("B Horizon thickness (cm)",$content->b_horizon_thickness_cm,30 + $indent);
					}

					if($content->k_horizon_thickness_cm!=""){
						$pdf->valueRow("K Horizon thickness (cm)",$content->k_horizon_thickness_cm,30 + $indent);
					}

					if($content->c_horizon_thickness_cm!=""){
						$pdf->valueRow("C Horizon thickness (cm)",$content->c_horizon_thickness_cm,30 + $indent);
					}

					if($content->r_horizon_thickness_cm!=""){
						$pdf->valueRow("R Horizon thickness (cm)",$content->r_horizon_thickness_cm,30 + $indent);
					}

					if($content->compound_thickness_cm!=""){
						$pdf->valueRow("Compound thickness (cm)",$content->compound_thickness_cm,30 + $indent);
					}

					if($content->composite_thickness_cm!=""){
						$pdf->valueRow("Composite thickness (cm)",$content->composite_thickness_cm,30 + $indent);
					}

					if($content->paleosol_structures!=""){
						$pdf->valueRow("Paleosol structures",implode($content->paleosol_structures, ", "),30 + $indent);
					}

					if($content->other_structure!=""){
						$pdf->valueRow("Other Paleosol Structure",$content->other_structure,30 + $indent);
					}

					if($content->additional_modifiers!=""){
						$pdf->valueRow("Additional modifiers",$content->additional_modifiers,30 + $indent);
					}

					if($content->paleosol_classification!=""){
						$pdf->valueRow("Paleosol classification",implode($content->paleosol_classification, ", "),30 + $indent);
					}

					if($content->notes!=""){
						$pdf->valueRow("Notes",$content->notes,30 + $indent);
					}

				}

			}

			if($spot['sed']->diagenesis){
				$pdf->valueTitle("Diagenesis: ", 20 + $indent);
				$contents = $spot['sed']->diagenesis;
				foreach($contents as $content){

					$pdf->valueTitle("Cement: ", 25 + $indent);

					if($content->cement_composition!=""){
						$pdf->valueRow("Cement Mineralogy",implode($content->cement_composition, ", "),30 + $indent);
					}

					if($content->other_cement_composition!=""){
						$pdf->valueRow("Other Cement Mineralogy",$content->other_cement_composition,30 + $indent);
					}

					$pdf->valueTitle("Veins: ", 25 + $indent);

					if($content->vein_type!=""){
						$pdf->valueRow("Vein Type",$content->vein_type,30 + $indent);
					}

					if($content->vein_width!=""){
						$pdf->valueRow("Vein Width (cm)",$content->vein_width,30 + $indent);
					}

					if($content->vein_length!=""){
						$pdf->valueRow("Vein Length (cm)",$content->vein_length,30 + $indent);
					}

					if($content->vein_orientation!=""){
						$pdf->valueRow("Vein Orientation",$content->vein_orientation,30 + $indent);
					}

					if($content->vein_mineralogy!=""){
						$pdf->valueRow("Vein Mineralogy",$content->vein_mineralogy,30 + $indent);
					}

					if($content->other_vein_mineralogy!=""){
						$pdf->valueRow("Other Vein Mineralogy",$content->other_vein_mineralogy,30 + $indent);
					}

					$pdf->valueTitle("Fractures: ", 25 + $indent);

					if($content->fracture_type!=""){
						$pdf->valueRow("Fracture Type",$content->fracture_type,30 + $indent);
					}

					if($content->fracture_width!=""){
						$pdf->valueRow("Fracture Width (cm)",$content->fracture_width,30 + $indent);
					}

					if($content->fracture_length!=""){
						$pdf->valueRow("Fracture Length (cm)",$content->fracture_length,30 + $indent);
					}

					if($content->fracture_orientation!=""){
						$pdf->valueRow("Fracture Orientation",$content->fracture_orientation,30 + $indent);
					}

					if($content->fracture_mineralogy!=""){
						$pdf->valueRow("Fracture Mineralogy",$content->fracture_mineralogy,30 + $indent);
					}

					if($content->other_fracture_mineralogy!=""){
						$pdf->valueRow("Other Fracture Mineralogy",$content->other_fracture_mineralogy,30 + $indent);
					}

					$pdf->valueTitle("Nodules/Concretions: ", 25 + $indent);

					if($content->nodules_concretions_size!=""){
						$pdf->valueRow("Nodules/Concretions Size",$content->nodules_concretions_size,30 + $indent);
					}

					if($content->min!=""){
						$pdf->valueRow("Min",$content->min,30 + $indent);
					}

					if($content->max!=""){
						$pdf->valueRow("Max",$content->max,30 + $indent);
					}

					if($content->average!=""){
						$pdf->valueRow("Average",$content->average,30 + $indent);
					}

					if($content->nodules_concretions_shape!=""){
						$pdf->valueRow("Nodules/Concretions Shape",implode($content->nodules_concretions_shape, ", "),30 + $indent);
					}

					if($content->other_nodules_concretion_shape!=""){
						$pdf->valueRow("Other Nodule/Concretions Shape",$content->other_nodules_concretion_shape,30 + $indent);
					}

					if($content->spacing!=""){
						$pdf->valueRow("Spacing",$content->spacing,30 + $indent);
					}

					if($content->nodules_concretions_type!=""){
						$pdf->valueRow("Nodules/Concretions Type",$content->nodules_concretions_type,30 + $indent);
					}

					if($content->other_nodules_concretions_type!=""){
						$pdf->valueRow("Other Nodules/Concretions Type",$content->other_nodules_concretions_type,30 + $indent);
					}

					if($content->nodules_concretions_comp!=""){
						$pdf->valueRow("Nodules/Concretions Composition",implode($content->nodules_concretions_comp, ", "),30 + $indent);
					}

					if($content->other_nodules_concretion_comp!=""){
						$pdf->valueRow("Other Nodules/Concretions Composition",$content->other_nodules_concretion_comp,30 + $indent);
					}

					$pdf->valueTitle("Replacement: ", 25 + $indent);

					if($content->replacement_type!=""){
						$pdf->valueRow("Replacement Type",$content->replacement_type,30 + $indent);
					}

					if($content->other_replacement_type!=""){
						$pdf->valueRow("Other Replacement Type",$content->other_replacement_type,30 + $indent);
					}

					$pdf->valueTitle("Recrystallization: ", 25 + $indent);

					if($content->recrystallization_type!=""){
						$pdf->valueRow("Recrystallization Type",$content->recrystallization_type,30 + $indent);
					}

					if($content->other_recrystallization_type!=""){
						$pdf->valueRow("Other Recrystallization Type",$content->other_recrystallization_type,30 + $indent);
					}

					$pdf->valueTitle("Other Diagenetic Features: ", 25 + $indent);

					if($content->other_diagenetic_features!=""){
						$pdf->valueRow("Other Diagenetic Features",implode($content->other_diagenetic_features, ", "),30 + $indent);
					}

					if($content->other_features!=""){
						$pdf->valueRow("Other Features",$content->other_features,30 + $indent);
					}

					$pdf->valueTitle("Porosity type: ", 25 + $indent);

					if($content->fabric_selective!=""){
						$pdf->valueRow("Fabric Selective",implode($content->fabric_selective, ", "),30 + $indent);
					}

					if($content->other_fabric_selective!=""){
						$pdf->valueRow("Other Fabric Selective",$content->other_fabric_selective,30 + $indent);
					}

					if($content->non_selective!=""){
						$pdf->valueRow("Non-Frabric Selective",implode($content->non_selective, ", "),30 + $indent);
					}

					if($content->other_non_selective!=""){
						$pdf->valueRow("Other Non-Fabric Selective",$content->other_non_selective,30 + $indent);
					}

					$pdf->valueTitle("Carbonate Desiccation and Dissolution: ", 25 + $indent);

					if($content->carbonate_desicc_and_diss!=""){
						$pdf->valueRow("Carbonate Desiccation and Dissolution Type",implode($content->carbonate_desicc_and_diss, ", "),30 + $indent);
					}

					if($content->other_carbonate_desicc_diss!=""){
						$pdf->valueRow("Other Carbonate Desiccation and Dissolution Type",$content->other_carbonate_desicc_diss,30 + $indent);
					}

					if($content->notes!=""){
						$pdf->valueRow("Notes",$content->notes,30 + $indent);
					}

				}
			}

			if($spot['sed']->fossils){
				$pdf->valueTitle("Fossils: ", 20 + $indent);
				$contents = $spot['sed']->fossils;
				foreach($contents as $content){

					$pdf->valueTitle("Body: ", 25 + $indent);

					if($content->invertebrate!=""){
						$pdf->valueRow("Invertebrate",implode($content->invertebrate, ", "),30 + $indent);
					}

					if($content->other_invertebrate!=""){
						$pdf->valueRow("Other Invertebrate",$content->other_invertebrate,30 + $indent);
					}

					if($content->mollusca!=""){
						$pdf->valueRow("Mollusc",implode($content->mollusca, ", "),30 + $indent);
					}

					if($content->other_mollusca!=""){
						$pdf->valueRow("Other Mollusc",$content->other_mollusca,30 + $indent);
					}

					if($content->arthropoda!=""){
						$pdf->valueRow("Arthropod",implode($content->arthropoda, ", "),30 + $indent);
					}

					if($content->other_anthropoda!=""){
						$pdf->valueRow("Other Arthropod",$content->other_anthropoda,30 + $indent);
					}

					if($content->echinodermata!=""){
						$pdf->valueRow("Echinoderm",implode($content->echinodermata, ", "),30 + $indent);
					}

					if($content->other_echinodermata!=""){
						$pdf->valueRow("Other Echinoderm",$content->other_echinodermata,30 + $indent);
					}

					if($content->cnidaria!=""){
						$pdf->valueRow("Cnidarian",implode($content->cnidaria, ", "),30 + $indent);
					}

					if($content->other_cnidaria!=""){
						$pdf->valueRow("Other Cnidarian",$content->other_cnidaria,30 + $indent);
					}

					if($content->chordate!=""){
						$pdf->valueRow("Chordate",$content->chordate,30 + $indent);
					}

					if($content->other_chordata!=""){
						$pdf->valueRow("Other Chordate",$content->other_chordata,30 + $indent);
					}

					if($content->protista!=""){
						$pdf->valueRow("Protist",implode($content->protista, ", "),30 + $indent);
					}

					if($content->other_protista!=""){
						$pdf->valueRow("Other Protist",$content->other_protista,30 + $indent);
					}

					if($content->calcimicrobe!=""){
						$pdf->valueRow("Calcimicrobe",implode($content->calcimicrobe, ", "),30 + $indent);
					}

					if($content->other_calcimicrobe!=""){
						$pdf->valueRow("Other Calcimicrobe",$content->other_calcimicrobe,30 + $indent);
					}

					if($content->plant_algae!=""){
						$pdf->valueRow("Plant/algae",implode($content->plant_algae, ", "),30 + $indent);
					}

					if($content->other_plant_algae!=""){
						$pdf->valueRow("Other Plant/Algae",$content->other_plant_algae,30 + $indent);
					}

					if($content->green_algae!=""){
						$pdf->valueRow("Green Algae",implode($content->green_algae, ", "),30 + $indent);
					}

					if($content->other_green_algae!=""){
						$pdf->valueRow("Other Green Algae",$content->other_green_algae,30 + $indent);
					}

					if($content->vertebrate!=""){
						$pdf->valueRow("Vertebrate",implode($content->vertebrate, ", "),30 + $indent);
					}

					if($content->other_vertebrate!=""){
						$pdf->valueRow("Other Vertebrate",$content->other_vertebrate,30 + $indent);
					}

					if($content->faunal_assemblage!=""){
						$pdf->valueRow("Faunal assemblage",$content->faunal_assemblage,30 + $indent);
					}

					if($content->other_faunal_assemblage!=""){
						$pdf->valueRow("Other faunal assemblage",$content->other_faunal_assemblage,30 + $indent);
					}

					$pdf->valueTitle("Trace: ", 25 + $indent);

					if($content->diversity!=""){
						$pdf->valueRow("Diversity",$content->diversity,30 + $indent);
					}

					if($content->descriptive!=""){
						$pdf->valueRow("Descriptive",implode($content->descriptive, ", "),30 + $indent);
					}

					if($content->other_descriptive!=""){
						$pdf->valueRow("Other Descriptive",$content->other_descriptive,30 + $indent);
					}

					if($content->burrow_fill_type!=""){
						$pdf->valueRow("Burrow Fill Type",implode($content->burrow_fill_type, ", "),30 + $indent);
					}

					if($content->other_burrow_fill!=""){
						$pdf->valueRow("Other Burrow Fill Type",$content->other_burrow_fill,30 + $indent);
					}

					if($content->behavioral_grouping!=""){
						$pdf->valueRow("Behavioral grouping",$content->behavioral_grouping,30 + $indent);
					}

					if($content->other_behavioral_grouping!=""){
						$pdf->valueRow("Other Behavioral Grouping",$content->other_behavioral_grouping,30 + $indent);
					}

					if($content->ichnofacies!=""){
						$pdf->valueRow("Ichnofacies",$content->ichnofacies,30 + $indent);
					}

					if($content->other_ichnofacies!=""){
						$pdf->valueRow("Other Ichnofacies",$content->other_ichnofacies,30 + $indent);
					}

					if($content->list_of_specific_types!=""){
						$pdf->valueRow("List of specific types",$content->list_of_specific_types,30 + $indent);
					}

					$pdf->valueTitle("Biogenic Growth Structures: ", 25 + $indent);

					if($content->dominant_component!=""){
						$pdf->valueRow("Dominant component",$content->dominant_component,30 + $indent);
					}

					if($content->other_dominant_component!=""){
						$pdf->valueRow("Other Dominant Component",$content->other_dominant_component,30 + $indent);
					}

					if($content->microbial_reef_or_skelatal_mic!=""){
						$pdf->valueRow("Microbial Reef or Skeletal-Microbial Reef Type",implode($content->microbial_reef_or_skelatal_mic, ", "),30 + $indent);
					}

					if($content->other_microbial_or_skeletal_mi!=""){
						$pdf->valueRow("Other Microbial or Skeletal Microbial Reef",$content->other_microbial_or_skeletal_mi,30 + $indent);
					}

					if($content->mud_mound!=""){
						$pdf->valueRow("Mud Mound Type",implode($content->mud_mound, ", "),30 + $indent);
					}

					if($content->other_mud_mound!=""){
						$pdf->valueRow("Other Mud Mound",$content->other_mud_mound,30 + $indent);
					}

					$pdf->valueTitle("Biogenic Growth Structure Scale and Orientation: ", 25 + $indent);

					if($content->height!=""){
						$pdf->valueRow("Height",$content->height,30 + $indent);
					}

					if($content->width!=""){
						$pdf->valueRow("Width",$content->width,30 + $indent);
					}

					if($content->shape!=""){
						$pdf->valueRow("Shape",$content->shape,30 + $indent);
					}

					if($content->type!=""){
						$pdf->valueRow("Type",$content->type,30 + $indent);
					}

					if($content->other_type!=""){
						$pdf->valueRow("Other Type",$content->other_type,30 + $indent);
					}

					if($content->accessory_structures!=""){
						$pdf->valueRow("Accessory Structures",implode($content->accessory_structures, ", "),30 + $indent);
					}

					if($content->other_accessory_structure!=""){
						$pdf->valueRow("Other Accessory Structure",$content->other_accessory_structure,30 + $indent);
					}

					if($content->notes!=""){
						$pdf->valueRow("Notes",$content->notes,30 + $indent);
					}
				}
			}

			if($spot['sed']->interpretations){
				$pdf->valueTitle("Interpretations: ", 20 + $indent);
				$contents = $spot['sed']->interpretations;
				foreach($contents as $content){

					$pdf->valueTitle("Process Interpretation: ", 25 + $indent);

					if($content->energy!=""){
						$pdf->valueRow("Energy",$content->energy,30 + $indent);
					}

					if($content->other_energy!=""){
						$pdf->valueRow("Other Energy",$content->other_energy,30 + $indent);
					}

					if($content->sediment_transport!=""){
						$pdf->valueRow("Sediment Transport",implode($content->sediment_transport, ", "),30 + $indent);
					}

					if($content->other_sediment_transport!=""){
						$pdf->valueRow("Other Sediment Transport",$content->other_sediment_transport,30 + $indent);
					}

					if($content->fluidization!=""){
						$pdf->valueRow("Fluidization",$content->fluidization,30 + $indent);
					}

					if($content->other_fluidization!=""){
						$pdf->valueRow("Other Fluidization",$content->other_fluidization,30 + $indent);
					}

					if($content->miscellaneous!=""){
						$pdf->valueRow("Miscellaneous",implode($content->miscellaneous, ", "),30 + $indent);
					}

					if($content->other_miscellaneous!=""){
						$pdf->valueRow("Other Misc. Process",$content->other_miscellaneous,30 + $indent);
					}

					$pdf->valueTitle("Environment Interpretation: ", 25 + $indent);

					if($content->general!=""){
						$pdf->valueRow("General",implode($content->general, ", "),30 + $indent);
					}

					if($content->clastic!=""){
						$pdf->valueRow("Clastic",implode($content->clastic, ", "),30 + $indent);
					}

					if($content->alluvial_fan_environments!=""){
						$pdf->valueRow("Alluvial fan environments",implode($content->alluvial_fan_environments, ", "),30 + $indent);
					}

					if($content->other_alluvial_fan!=""){
						$pdf->valueRow("Other Alluvial Fan",$content->other_alluvial_fan,30 + $indent);
					}

					if($content->eolian_environments!=""){
						$pdf->valueRow("Eolian environments",implode($content->eolian_environments, ", "),30 + $indent);
					}

					if($content->other_eolian!=""){
						$pdf->valueRow("Other Eolian",$content->other_eolian,30 + $indent);
					}

					if($content->fluvial_environments!=""){
						$pdf->valueRow("Fluvial environments",implode($content->fluvial_environments, ", "),30 + $indent);
					}

					if($content->other_fluvial!=""){
						$pdf->valueRow("Other Fluvial",$content->other_fluvial,30 + $indent);
					}

					if($content->shallow_marine_clastic_environ!=""){
						$pdf->valueRow("Shallow marine clastic environments",implode($content->shallow_marine_clastic_environ, ", "),30 + $indent);
					}

					if($content->other_shallow_marine!=""){
						$pdf->valueRow("Other Shallow Marine",$content->other_shallow_marine,30 + $indent);
					}

					if($content->deep_marine_environments!=""){
						$pdf->valueRow("Deep marine environments",implode($content->deep_marine_environments, ", "),30 + $indent);
					}

					if($content->other_deep_marine!=""){
						$pdf->valueRow("Other Deep Marine",$content->other_deep_marine,30 + $indent);
					}

					if($content->glacial_and_proglacial_environ!=""){
						$pdf->valueRow("Glacial and proglacial environments",implode($content->glacial_and_proglacial_environ, ", "),30 + $indent);
					}

					if($content->other_glacial!=""){
						$pdf->valueRow("Other Glacial",$content->other_glacial,30 + $indent);
					}

					if($content->lake_environments!=""){
						$pdf->valueRow("Lake environments",implode($content->lake_environments, ", "),30 + $indent);
					}

					if($content->other_lake!=""){
						$pdf->valueRow("Other Lake",$content->other_lake,30 + $indent);
					}

					if($content->other_clastic!=""){
						$pdf->valueRow("Other Clastic",$content->other_clastic,30 + $indent);
					}

					if($content->carbonates!=""){
						$pdf->valueRow("Carbonates",implode($content->carbonates, ", "),30 + $indent);
					}

					if($content->factory!=""){
						$pdf->valueRow("Factory",implode($content->factory, ", "),30 + $indent);
					}

					if($content->carbonate!=""){
						$pdf->valueRow("Environment",implode($content->carbonate, ", "),30 + $indent);
					}

					if($content->other_carbonate_environment!=""){
						$pdf->valueRow("Other Carbonate Environment",$content->other_carbonate_environment,30 + $indent);
					}

					if($content->lake_subenvironments!=""){
						$pdf->valueRow("Lake Subenvironments",implode($content->lake_subenvironments, ", "),30 + $indent);
					}

					if($content->other_carbonate_lake_subenvironment!=""){
						$pdf->valueRow("Other Carbonate Lake Subenvironment",$content->other_carbonate_lake_subenvironment,30 + $indent);
					}

					if($content->tidal_flat_subenvironments!=""){
						$pdf->valueRow("Tidal Flat Subenvironments",implode($content->tidal_flat_subenvironments, ", "),30 + $indent);
					}

					if($content->other_tidal_flat!=""){
						$pdf->valueRow("Other Tidal Flat",$content->other_tidal_flat,30 + $indent);
					}

					if($content->reef_subenvironments!=""){
						$pdf->valueRow("Reef Subenvironments",implode($content->reef_subenvironments, ", "),30 + $indent);
					}

					if($content->other_reef!=""){
						$pdf->valueRow("Other Reef",$content->other_reef,30 + $indent);
					}

					if($content->detailed_carbonate_env_interpr!=""){
						$pdf->valueRow("Detailed carbonate environmental interpretations",$content->detailed_carbonate_env_interpr,30 + $indent);
					}

					if($content->tectonic_setting!=""){
						$pdf->valueRow("Tectonic Setting",implode($content->tectonic_setting, ", "),30 + $indent);
					}

					if($content->other_tectonic_setting!=""){
						$pdf->valueRow("Other Tectonic Setting",$content->other_tectonic_setting,30 + $indent);
					}

					$pdf->valueTitle("Sedimentary Surfaces (for line spots only): ", 25 + $indent);

					if($content->geometry!=""){
						$pdf->valueRow("Geometry",$content->geometry,30 + $indent);
					}

					if($content->relief!=""){
						$pdf->valueRow("Relief",$content->relief,30 + $indent);
					}

					if($content->relief_scale!=""){
						$pdf->valueRow("Relief Scale",$content->relief_scale,30 + $indent);
					}

					if($content->extent!=""){
						$pdf->valueRow("Extent",$content->extent,30 + $indent);
					}

					if($content->extent_scale!=""){
						$pdf->valueRow("Extent Scale",$content->extent_scale,30 + $indent);
					}

					if($content->type!=""){
						$pdf->valueRow("Type",implode($content->type, ", "),30 + $indent);
					}

					if($content->other_type!=""){
						$pdf->valueRow("Other Type",$content->other_type,30 + $indent);
					}

					if($content->stratal_termination!=""){
						$pdf->valueRow("Stratal Termination",$content->stratal_termination,30 + $indent);
					}

					$pdf->valueTitle("Sedimentary Surface Interpretation: ", 25 + $indent);

					if($content->general_surfaces!=""){
						$pdf->valueRow("General Surfaces",$content->general_surfaces,30 + $indent);
					}

					if($content->sequence_stratigraphic_surfaces!=""){
						$pdf->valueRow("Sequence Stratigraphic Surfaces",$content->sequence_stratigraphic_surfaces,30 + $indent);
					}

					if($content->other_sequence_stratigraphic_surface!=""){
						$pdf->valueRow("Other Sequence Stratigraphic Surface",$content->other_sequence_stratigraphic_surface,30 + $indent);
					}

					if($content->named!=""){
						$pdf->valueRow("Named",$content->named,30 + $indent);
					}

					$pdf->valueTitle("Architecture Interpretation: ", 25 + $indent);

					if($content->description!=""){
						$pdf->valueRow("Description",implode($content->description, ", "),30 + $indent);
					}

					if($content->stacking_sequence_stratigraphy!=""){
						$pdf->valueRow("Stacking/Sequence Stratigraphy",implode($content->stacking_sequence_stratigraphy, ", "),30 + $indent);
					}

					if($content->other_stacking_sequence_stratigraphy!=""){
						$pdf->valueRow("Other Stacking/Sequence Stratigraphy",$content->other_stacking_sequence_stratigraphy,30 + $indent);
					}

					if($content->fluvial_architectural_elements!=""){
						$pdf->valueRow("Fluvial Architectural Elements",implode($content->fluvial_architectural_elements, ", "),30 + $indent);
					}

					if($content->other_fluvial_element!=""){
						$pdf->valueRow("Other Fluvial Element",$content->other_fluvial_element,30 + $indent);
					}

					if($content->lacustrine_architecture_interpretation!=""){
						$pdf->valueRow("Lacustrine Architecture Interpretation",implode($content->lacustrine_architecture_interpretation, ", "),30 + $indent);
					}

					if($content->other_lacustrine_architecture_interpretation!=""){
						$pdf->valueRow("Other Lacustrine Architecture Interpretation",$content->other_lacustrine_architecture_interpretation,30 + $indent);
					}

					if($content->carbonate_platform_geometry!=""){
						$pdf->valueRow("Carbonate Platform Geometry",implode($content->carbonate_platform_geometry, ", "),30 + $indent);
					}

					if($content->other_platform_geometry!=""){
						$pdf->valueRow("Other Platform Geometry",$content->other_platform_geometry,30 + $indent);
					}

					if($content->deep_water_architctural_element!=""){
						$pdf->valueRow("Deep-Water Architectural Elements",implode($content->deep_water_architctural_element, ", "),30 + $indent);
					}

					if($content->other_deep_water_architectural_element!=""){
						$pdf->valueRow("Other Deep-Water Architectural Element",$content->other_deep_water_architectural_element,30 + $indent);
					}

					if($content->carbonate_margin_geometry!=""){
						$pdf->valueRow("Carbonate Margin Geometry",implode($content->carbonate_margin_geometry, ", "),30 + $indent);
					}

					if($content->other_carbonate_margin_geometry!=""){
						$pdf->valueRow("Other Carbonate Margin Geometry",$content->other_carbonate_margin_geometry,30 + $indent);
					}

					if($content->notes!=""){
						$pdf->valueRow("Notes",$content->notes,30 + $indent);
					}

				}
			}

		}

		if($spot['pet']){
			if($spot['pet']->metamorphic){
				$pdf->valueTitle("Metamorphic Rock(s): ", 15 + $indent);
				$rocks = $spot['pet']->metamorphic;

				$rockNum = 1;
				foreach($rocks as $r){
					$rockString = "";
					$rockString .= ", " . $r->metamorphic_rock_type;
					$rockString .= ", " . implode(", ", $r->facies);
					$rockString .= ", " . $r->protolith;
					$rockString .= ", " . implode(", ", $r->zone);

					$pdf->valueRow($rockNum, $rockString, 20 + $indent);
					$rockNum++;
				}

				if($content->section_well_name!=""){
					$pdf->valueRow("Section/Well Name",$content->section_well_name,25 + $indent);
				}
			}

			if($spot['pet']->igneous){
				$pdf->valueTitle("Igneous Rock(s): ", 15 + $indent);
				$rocks = $spot['pet']->igneous;

				$rockNum = 1;
				foreach($rocks as $r){
					$rockString = "";
					if($r->igneous_rock_class) $rockString .= ", " . $r->igneous_rock_class;
					if($r->volcanic_rock_type) $rockString .= ", " . $r->volcanic_rock_type;
					if($r->occurence_volcanic) $rockString .= ", " . $r->occurence_volcanic;
					if($r->plutonic_rock_type) $rockString .= ", " . $r->plutonic_rock_type;
					if($r->occurence_plutonic) $rockString .= ", " . $r->occurence_plutonic;
					if($r->texture_volcanic) $rockString .= ", " . implode(", ", $r->texture_volcanic);
					if($r->texture_plutonic) $rockString .= ", " . implode(", ", $r->texture_plutonic);
					if($r->color_index_volc) $rockString .= ", " . $r->color_index_volc;
					if($r->color_index_pluton) $rockString .= ", " . $r->color_index_pluton;
					if($r->color_index_source_volc) $rockString .= ", " . $r->color_index_source_volc;
					if($r->color_index_source_pluton) $rockString .= ", " . $r->color_index_source_pluton;
					if($r->alteration_volcanic) $rockString .= ", " . implode(", ", $r->alteration_volcanic);
					if($r->alteration_plutonic) $rockString .= ", " . implode(", ", $r->alteration_plutonic);

					$pdf->valueRow($rockNum, $rockString, 20 + $indent);
					$rockNum++;
				}

				if($content->section_well_name!=""){
					$pdf->valueRow("Section/Well Name",$content->section_well_name,25 + $indent);
				}
			}

			if($spot['pet']->minerals){
				$pdf->valueTitle("Mineral(s): ", 15 + $indent);
				$rocks = $spot['pet']->minerals;

				$rockNum = 1;
				foreach($rocks as $r){
					$rockString = "";
					if($r->full_mineral_name) $rockString .= ", " . $r->full_mineral_name;
					if($r->igneous_or_metamorphic == "ig_min"){
						$rockString .= " (Igneous)";
					}else{
						$rockString .= " (Metamorphic)";
					}
					if($r->average_grain_size_mm) $rockString .= ", Avg Size: " . $r->average_grain_size_mm . "mm";
					if($r->maximum_grain_size_mm) $rockString .= ", Max Size: " . $r->maximum_grain_size_mm . "mm";
					if($r->modal) $rockString .= ", Modal: " . $r->modal . "%";
					if($r->mineral_notes) $rockString .= " " . $r->mineral_notes;

					$pdf->valueRow($rockNum, $rockString, 20 + $indent);
					$rockNum++;
				}

				if($content->section_well_name!=""){
					$pdf->valueRow("Section/Well Name",$content->section_well_name,25 + $indent);
				}
			}

		}

		if($spot['images']){

			$pdf->valueRow("Images","",15 + $indent);
			$pdf->Ln(1);
			foreach($spot['images'] as $o){
				if($o['title']){
					$thistitle = $this->fixLabel($o['title']);
					$pdf->valueTitle($thistitle.": ",20 + $indent);
				}else{
					$thistitle = $o['id'];
				}

				foreach($o as $key=>$value){
					if($value != ""){
						if($key!="id" && $key!="self" && $key!="annotated" && $key!="title" && $key!="width" && $key!="height" && $key!="image_type" && $key!="caption" ){
							$key = $this->fixLabel($key);
							if(is_string($value)){
								$value = $this->fixLabel($value);
							}
							$pdf->valueRow($key,$value,20 + $indent);
						}
					}
				}

				$pdf->Ln(1);

				$filename = $this->strabo->getImageFilename($o['id']);

				if($filename){
					$gdimage = $this->gdThumbWithSpots($filename, $o['id'], $allspots);
					if($gdimage){
						$pdf->GDImage($gdimage, 20, null, 170); //60
					}
				}

				if(trim($o['caption']) != ""){
					$pdf->imageCaptionRow("Caption", $o['caption'], 20 + $indent);
				}

				//Add spots on image basemap here. Figure out how to indent all options.

				$imagehasspots = false;
				foreach($allspots as $imagespot){
					$imagespot = $imagespot['properties'];
					if($imagespot['image_basemap'] == $o['id']){
						$imagehasspots = true;
					}

				}

				if($imagehasspots){

					//add "spots on basemap"
					$pdf->largeValue("Spots on Basemap:", $indent + 20);

					foreach($allspots as $imagespot){

						$imagespot = $imagespot['properties'];

						if($imagespot['image_basemap'] == $o['id']){
							$this->addSpotToPDF($pdf, $imagespot, $allspots, $indent + 10);
						}

					}
				}

			}
		}

		$pdf->Ln(5);

	}

	public function doiDataOut($projectid=""){

		$showout = false;

		if($projectid == ""){
			$projectid = (int)$_GET['projectid'];
			$showout = true;
		}

		if($projectid != ""){

			$project = $this->strabo->getProject($projectid);

			if($project->Error == ""){

				$projectname = $project->description->project_name;

				$out = new stdClass();

				$out->mapNamesDb = new stdClass();
				$out->mapTilesDb = new stdClass();
				$out->otherMapsDb = new stdClass();

				//Gather datasets for later use
				$datasets = $this->strabo->getProjectDatasets($projectid);
				$datasets = $datasets['datasets'];
				$alldatasetids = [];
				foreach($datasets as $dataset){
					$alldatasetids[] = $dataset['id'];
				}

				//Create projectDb
				$projectDb = new stdClass();

				//project
				$projectDb->activeDatasetsIds = $alldatasetids;
				$projectDb->selectedDatasetId = $alldatasetids[0];
				$projectDb->project = $project;

				$spotsDbSpots = [];

				//datasets
				$filedatasets = new stdClass();
				foreach($datasets as $d){
					$d = (object) $d;
					$datasetid = $d->id;

					//Gather images and spots for dataset
					$getvals = array();
					$getvals['dsids'] = $datasetid;
					$getvals['userpkey'] = $this->strabo->userpkey;
					$getvals['type'] = "doi";
					$json = $this->strabo->getDatasetSpotsSearch(null,$getvals);
					$spots = $json['features'];

					$spotids = array();
					$imageids = array();

					foreach($spots as $spot){
						$spotsDbSpots[] = $spot;
						$spot = json_decode(json_encode($spot));
						$spot = $spot->properties;
						$spotids[] = $spot->id;
						foreach($spot->images as $im){
							$imageids[] = $im->id;
						}
					}

					$fileimages = new stdClass();
					$fileimages->neededImageIds = array();
					$fileimages->imageIds = $imageids;

					$d->images = $fileimages;
					$d->spotIds = $spotids;

					$filedatasets->$datasetid = $d;
				}

				$projectDb->datasets = $filedatasets;

				$projectDb->deviceBackUpDirectoryExists = true;

				//Build File Name
				$fixedprojectname = $this->fixFileName($projectname);
				$date = new DateTimeImmutable();
				$datestring = $date->format('Y-m-d_gia');
				$filename = $datestring."_".$fixedprojectname;
				$projectDb->backupFileName = $filename;

				$projectDb->downloadsDirectory = false;

				$projectDb->isTestingMode = false;

				$selectedProject = new stdClass();
				$selectedProject->project = "";
				$selectedProject->source = "";
				$projectDb->selectedProject = $selectedProject;

				//Get tag from project
				$projectDb->selectedTag = $project->tags[0];

				$projectDb->isMultipleFeaturesTaggingEnabled = false;
				$projectDb->addTagToSelectedSpot = false;
				$projectDb->projectTransferProgress = 0;
				$projectDb->isImageTransferring = false;

				$persist = new stdClass();
				$persist->version = -1;
				$persist->rehydrated = true;
				$projectDb->_persist = $persist;

				$out->projectDb = $projectDb;

				//Now spotsDb
				$spotsDb = new stdClass();

				foreach($spotsDbSpots as $spot){
					$spot = json_decode(json_encode($spot));
					$spotid = $spot->properties->id;
					$spot->geometry = $spot->original_geometry;
					unset($spot->original_geometry);
					$spotsDb->$spotid = $spot;
				}

				$out->spotsDb = $spotsDb;

				if($showout){
					//Send to browser for testing:
					header('Content-Type: application/json; charset=utf-8');
					echo json_encode($out, JSON_PRETTY_PRINT);
				}

				return($out);

			}else{
			}

		}else{
		}

	}

	public function bkupdoiDataOut20240516(){

		$projectid = (int)$_GET['projectid'];

		if($projectid != ""){

			$project = $this->strabo->getProject($projectid);

			if($project->Error == ""){

				$projectname = $project->description->project_name;

				$out = new stdClass();

				$out->mapNamesDb = new stdClass();
				$out->mapTilesDb = new stdClass();
				$out->otherMapsDb = new stdClass();

				//Gather datasets for later use
				$datasets = $this->strabo->getProjectDatasets($projectid);
				$datasets = $datasets['datasets'];
				$alldatasetids = [];
				foreach($datasets as $dataset){
					$alldatasetids[] = $dataset['id'];
				}

				//Create projectDb
				$projectDb = new stdClass();

				//project
				$projectDb->activeDatasetsIds = $alldatasetids;
				$projectDb->selectedDatasetId = $alldatasetids[0];
				$projectDb->project = $project;

				//datasets
				$filedatasets = new stdClass();
				foreach($datasets as $d){
					$d = (object) $d;
					$datasetid = $d->id;

					//Gather images and spots for dataset
					$getvals = array();
					$getvals['dsids'] = $datasetid;
					$getvals['userpkey'] = $this->strabo->userpkey;
					$getvals['type'] = "doi";
					$json = $this->strabo->getDatasetSpotsSearch(null,$getvals);
					$spots = $json['features'];

					$spotids = array();
					$imageids = array();

					foreach($spots as $spot){
						$spot = json_decode(json_encode($spot));
						$spot = $spot->properties;
						$spotids[] = $spot->id;
						foreach($spot->images as $im){
							$imageids[] = $im->id;
						}
					}

					$fileimages = new stdClass();
					$fileimages->neededImageIds = array();
					$fileimages->imageIds = $imageids;

					$d->images = $fileimages;
					$d->spotIds = $spotids;

					$filedatasets->$datasetid = $d;
				}

				$projectDb->datasets = $filedatasets;

				$projectDb->deviceBackUpDirectoryExists = true;

				//Build File Name
				$fixedprojectname = $this->fixFileName($projectname);
				$date = new DateTimeImmutable();
				$datestring = $date->format('Y-m-d_gia');
				$filename = $datestring."_".$fixedprojectname;
				$projectDb->backupFileName = $filename;

				$projectDb->downloadsDirectory = false;

				$projectDb->isTestingMode = false;

				$selectedProject = new stdClass();
				$selectedProject->project = "";
				$selectedProject->source = "";
				$projectDb->selectedProject = $selectedProject;

				//Get tag from project
				$projectDb->selectedTag = $project->tags[0];

				$projectDb->isMultipleFeaturesTaggingEnabled = false;
				$projectDb->addTagToSelectedSpot = false;
				$projectDb->projectTransferProgress = 0;
				$projectDb->isImageTransferring = false;

				$persist = new stdClass();
				$persist->version = -1;
				$persist->rehydrated = true;
				$projectDb->_persist = $persist;

				$out->projectDb = $projectDb;

				//Now spotsDb
				$spotsDb = new stdClass();

				foreach($spots as $spot){
					$spot = json_decode(json_encode($spot));
					$spotid = $spot->properties->id;
					$spot->geometry = $spot->original_geometry;
					unset($spot->original_geometry);
					$spotsDb->$spotid = $spot;
				}

				$out->spotsDb = $spotsDb;

				//Send to browser for testing:
				header('Content-Type: text/plain; charset=UTF-8');
				echo json_encode($out, JSON_PRETTY_PRINT);

			}else{
				die($project->Error);
			}

		}else{
			die("Project not provided.");
		}

	}

	public function doiPDFOut(){

		$projectid = (int)$_GET['projectid'];

		if($projectid != ""){

			$project = $this->strabo->getProject($projectid);

			if($project->Error == ""){

				require('includes/PDF_LabBook.php');

				$pdf = new PDF_MemImage('P','mm','Letter');
				$pdf->setType("doi");

				$pdf->AddFont('msjh','','msjh.ttf',true);

				$pdf->AddPage();

				$indent = 0;

				$projectname = $project->description->project_name;
				$pdf->projectTitle($projectname);

				$modified = (string) $project->id;
				$modified = substr($modified,0,10);
				$modified = date("F j, Y",$modified);
				$pdf->valueRow("Created",$modified,0);

				$modified = (string) $project->modified_timestamp;
				$modified = substr($modified,0,10);
				$modified = date("F j, Y",$modified);
				$pdf->valueRow("Last Modified",$modified,0);

				//Landing Page
				$pdf->httpLink("Details: https://strabospot.org/landing/12345", 0, "https://strabospot.org/landing/12345");

				$pdf->SetFont('msjh','',14);
				$pdf->cell(0,10,"Datasets:",0,1,'L');
				$pdf->Ln(1);

				$datasets = $this->strabo->getProjectDatasets($projectid);
				$datasets = $datasets['datasets'];

				foreach($datasets as $dataset){

					$datasetid = $dataset['id'];

					$dsname = $dataset['name'];
					if($dsname == "") $dsname = "default";
					$pdf->largeValue("Dataset Name: ".$dsname);

					$modified = (string) $dataset['id'];
					$modified = substr($modified,0,10);
					$modified = date("F j, Y",$modified);

					$pdf->valueRow("Created",$modified,15);

					$modified = (string) $dataset['modified_timestamp'];
					$modified = substr($modified,0,10);
					$modified = date("F j, Y",$modified);
					$pdf->valueRow("Last Modified",$modified,15);
					$pdf->blankRow();

					//Get Spots

					$getvals = array();
					$getvals['dsids'] = $datasetid;
					$getvals['userpkey'] = $this->strabo->userpkey;
					$getvals['type'] = "doi";
					$json = $this->strabo->getDatasetSpotsSearch(null,$getvals);
					$spots = $json['features'];

					foreach($spots as $spot){

						$rawspot = $spot;

						$spot = $spot['properties'];

						if( $spot['image_basemap'] == ""){ //and image_basemap is not set...

							$this->addSpotToPDF($pdf, $spot, $spots, 5);

						}else{ //end if date matches
						}

					}//end foreach spots

				}

				$filedate = date("m_d_Y");
				$pdfname="StraboSpot_Project.pdf";
				$pdf->Output($pdfname,"D"); //Download

			}else{
				die($project->Error);
			}

		}else{
			die("Project not provided.");
		}

	}

	public function getKMLHtml($inSpots, $geoType){

		$spots = [];
		foreach($inSpots as $spot){

			$mygeojson=$spot['geometry'];
			$mygeotype = $mygeojson->type;

			if($geoType == "polygon"){
				if($mygeotype=="Polygon" || $mygeotype=="MultiPolygon"){
					$spots[] = $spot;
				}
			}elseif($geoType == "line"){
				if($mygeotype=="LineString" || $mygeotype=="MultiLineString"){
					$spots[] = $spot;
				}
			}elseif($geoType == "point"){

				if($mygeotype=="Polygon" || $mygeotype=="MultiPolygon"){
				}elseif($mygeotype=="LineString" || $mygeotype=="MultiLineString"){
				}elseif($mygeotype=="Point"){
					$spots[] = $spot;
				}else{
					$spots[] = $spot;
				}
			}
		}

		foreach($spots as $spot){

			//use geoPHP to get WKT
			$mygeojson=$spot['geometry'];

			$mygeotype = $mygeojson->type;

			//pick a style for KML feature
			if($mygeotype=="Polygon" || $mygeotype=="MultiPolygon"){
				$thisstyle="m_strabo_polygon";
			}elseif($mygeotype=="LineString" || $mygeotype=="MultiLineString"){
				$thisstyle="m_strabo_line";
			}elseif($mygeotype=="Point"){
				$thisstyle="m_strabo_point";
			}else{
				$thisstyle="m_strabo_point";
			}

			if($mygeotype!=""){

				if($spot['properties']['name']!=""){
					$spotname = $spot['properties']['name'];
				}else{
					$spotname = $spot['properties']['id'];
				}

				if($mygeotype=="Polygon" || $mygeotype=="MultiPolygon"){
					//test polystyle override here
					$color = $this->getTagColor($spot['properties']['id'], $this->alltags);

					if($color!=""){
						$newcolor = "#88". substr($color, 5, 2) . substr($color, 3, 2) . substr($color, 1, 2);
						$polystyle = "<Style><PolyStyle><color>$newcolor</color><outline>0</outline></PolyStyle></Style>";
					}else{
						$polystyle = "<Style><PolyStyle><color>#4bDC7878</color><outline>0</outline></PolyStyle></Style>";
					}

				}

				$spotname = htmlspecialchars($spotname);

				$html.="<Placemark>\n<name>$spotname</name>\n<description>\n<![CDATA[\n";

				$html.="<img style=\"max-width:300px;\" src=\"files/bubblehead.jpg\">\n";

				$mygeojson=trim(json_encode($mygeojson));

				try {
					$mywkt=geoPHP::load($mygeojson,"json");
					$kmlgeo = $mywkt->out('kml');
				} catch (Exception $e) {
					$kmlgeo="";
				}

				$spot = $spot['properties'];

				$id = $spot['id'];

				$spotname = $spot['name'];
				if($spot['geometrytype']){
					$spotname .= " (".$spot['geometrytype'].")";
				}

				$html.="<div class=\"spotTitle\">Spot Name: $spotname</div>\n<br>\n";

				$modified = (string) $spot['id'];
				$modified = substr($modified,0,10);
				$modified = date("c",$modified);
				$html.="<div>Created: $modified</div>\n";

				$modified = (string) $spot['modified_timestamp'];
				$modified = substr($modified,0,10);
				$modified = date("c",$modified);
				$html.="<div>Last Modified: $modified</div>\n";

				if($spot['surface_feature']){
					foreach($spot['surface_feature'] as $key=>$value){
						$key = $this->fixLabel($key);
						if(is_string($value)){
							$value = $this->fixLabel($value);
						}
						$html.="<div>$key: $value</div>\n";
					}
				}

				if($spot['orientation_data']){
					$html.="<div>Orientations:</div>\n";
					$html.="<div class=\"leftPad\">\n";
					foreach($spot['orientation_data'] as $o){
						$html.="<div class=\"sectionTitle\">".$this->fixLabel($o->type).": "."</div>\n";
						foreach($o as $key=>$value){
							if($key!="id" && $key!="associated_orientation" && $key!="type"){
								$key = $this->fixLabel($key);
								if(is_string($value)){
									$value = $this->fixLabel($value);
								}
								$html.="<div>$key: $value</div>\n";
							}
						}

						if($o->associated_orientation){
							$html.="<div>Orientations:</div>\n";
							$html.="<div class=\"leftPad\">\n";
							foreach($o->associated_orientation as $ao){
								$html.="<div class=\"sectionTitle\">".$this->fixLabel($o->type).": "."</div>\n";
								foreach($ao as $key=>$value){
									if($key!="id" && $key!="associated_orientation" && $key!="type"){
										$key = $this->fixLabel($key);
										if(is_string($value)){
											$value = $this->fixLabel($value);
										}
										$html.="<div>$key: $value</div>\n";
									}
								}

							}

							$html.="</div>\n"; //end leftPad
						}

					}

					$html.="</div>\n"; //end leftPad
				}

				if($spot['pet']){
					if($spot['pet']->metamorphic){
						$html.="<div>Metamorphic Rock(s):</div>\n";
						$html.="<div class=\"leftPad\">\n";
						if($spot['pet']->metamorphic){

							$rocks = $spot['pet']->metamorphic;

							$rockNum = 1;
							foreach($rocks as $r){
								$rockString = "";
								if($r->metamorphic_rock_type != "") $rockString .= "" . $r->metamorphic_rock_type;
								if($r->facies != "") $rockString .= ", " . implode(", ", $r->facies);
								if($r->protolith != "") $rockString .= ", " . $r->protolith;
								if($r->zone != "") $rockString .= ", " . implode(", ", $r->zone);

								$html.="<div class=\"\">".$rockNum.": ".$rockString."</div>\n";
								$rockNum++;
							}

						}

						$html.="</div>\n"; //end leftPad
					}

					if($spot['pet']->igneous){
						$html.="<div>Igneous Rock(s):</div>\n";
						$html.="<div class=\"leftPad\">\n";
						if($spot['pet']->metamorphic){

							$rocks = $spot['pet']->igneous;

							$rockNum = 1;
							foreach($rocks as $r){
								$rockString = "";
								if($r->igneous_rock_class) $rockString .= "" . $r->igneous_rock_class;
								if($r->volcanic_rock_type) $rockString .= ", " . $r->volcanic_rock_type;
								if($r->occurence_volcanic) $rockString .= ", " . $r->occurence_volcanic;
								if($r->plutonic_rock_type) $rockString .= ", " . $r->plutonic_rock_type;
								if($r->occurence_plutonic) $rockString .= ", " . $r->occurence_plutonic;
								if($r->texture_volcanic) $rockString .= ", " . implode(", ", $r->texture_volcanic);
								if($r->texture_plutonic) $rockString .= ", " . implode(", ", $r->texture_plutonic);
								if($r->color_index_volc) $rockString .= ", " . $r->color_index_volc;
								if($r->color_index_pluton) $rockString .= ", " . $r->color_index_pluton;
								if($r->color_index_source_volc) $rockString .= ", " . $r->color_index_source_volc;
								if($r->color_index_source_pluton) $rockString .= ", " . $r->color_index_source_pluton;
								if($r->alteration_volcanic) $rockString .= ", " . implode(", ", $r->alteration_volcanic);
								if($r->alteration_plutonic) $rockString .= ", " . implode(", ", $r->alteration_plutonic);

								$html.="<div class=\"\">".$rockNum.": ".$rockString."</div>\n";
								$rockNum++;
							}

						}

						$html.="</div>\n"; //end leftPad
					}

					if($spot['pet']->igneous){
						$html.="<div>Mineral(s):</div>\n";
						$html.="<div class=\"leftPad\">\n";
						if($spot['pet']->minerals){

							$rocks = $spot['pet']->minerals;

							$rockNum = 1;
							foreach($rocks as $r){
								$rockString = "";
								if($r->full_mineral_name) $rockString .= ", " . $r->full_mineral_name;
								if($r->igneous_or_metamorphic == "ig_min"){
									$rockString .= " (Igneous)";
								}else{
									$rockString .= " (Metamorphic)";
								}
								if($r->average_grain_size_mm) $rockString .= ", Avg Size: " . $r->average_grain_size_mm . "mm";
								if($r->maximum_grain_size_mm) $rockString .= ", Max Size: " . $r->maximum_grain_size_mm . "mm";
								if($r->modal) $rockString .= ", Modal: " . $r->modal . "%";
								if($r->mineral_notes) $rockString .= " " . $r->mineral_notes;

								$html.="<div class=\"\">".$rockNum.": ".$rockString."</div>\n";
								$rockNum++;
							}

						}

						$html.="</div>\n"; //end leftPad
					}

				}

				if($spot['_3d_structures']){
					$html.="<div>3D Structures:</div>\n";
					$html.="<div class=\"leftPad\">\n";
					foreach($spot['_3d_structures'] as $o){
						$html.="<div class=\"sectionTitle\">".$this->fixLabel($o->type).": "."</div>\n";
						foreach($o as $key=>$value){
							if($key!="id" && $key!="type"){
								$key = $this->fixLabel($key);
								if(is_string($value)){
									$value = $this->fixLabel($value);
								}
								$html.="<div>$key: $value</div>\n";
							}
						}

					}

					$html.="</div>\n"; //end leftPad
				}

				if($spot['trace']){
					$html.="<div>Trace:</div>\n";
					$html.="<div class=\"leftPad\">\n";

					foreach($spot['trace'] as $key=>$value){
						if($key!="id" && $key!="label"){
							if($key!="trace_feature"){
								$key = $this->fixLabel($key);
								if(is_string($value)){
									$value = $this->fixLabel($value);
								}
								$html.="<div>$key: $value</div>\n";
							}
						}
					}

					$html.="</div>\n"; //end leftPad
				}

				if($spot['samples']){
					$html.="<div>Samples:</div>\n";
					$html.="<div class=\"leftPad\">\n";
					foreach($spot['samples'] as $o){
						$html.="<div class=\"sectionTitle\">".$this->fixLabel($o->label).": "."</div>\n";
						foreach($o as $key=>$value){
							if($key!="id" && $key!="label"){
								$key = $this->fixLabel($key);
								if(is_string($value)){
									$value = $this->fixLabel($value);
								}
								$html.="<div>$key: $value</div>\n";
							}
						}

					}

					$html.="</div>\n"; //end leftPad
				}

				if($spot['tephra']){
					$html.="<div>Tephra Intervals:</div>\n";
					$html.="<div class=\"leftPad\">\n";
					foreach($spot['tephra'] as $o){
						$html.="<div class=\"sectionTitle\">Interval:</div>\n";
						foreach($o as $key=>$value){
							if($key!="id" && $key!="label"){
								$key = $this->fixLabel($key);
								if(is_array($value)){
									$value = implode(", ", $value);
								}elseif(is_string($value)){
									$value = $this->fixLabel($value);
								}
								$html.="<div>$key: $value</div>\n";
							}
						}

					}

					$html.="</div>\n"; //end leftPad
				}

				if($spot['other_features']){
					$html.="<div>Other Features:</div>\n";
					$html.="<div class=\"leftPad\">\n";
					foreach($spot['other_features'] as $o){
						$html.="<div class=\"sectionTitle\">".$this->fixLabel($o->label).": "."</div>\n";
						foreach($o as $key=>$value){
							if($key!="id" && $key!="label"){
								$key = $this->fixLabel($key);
								if(is_string($value)){
									$value = $this->fixLabel($value);
								}
								$html.="<div>$key: $value</div>\n";
							}
						}

					}

					$html.="</div>\n"; //end leftPad
				}

				if($project->tags){
					foreach($project->tags as $tag){
						$found = "no";
						if($tag->spots){
							foreach($tag->spots as $spotid){
								if($spotid == $id){
									$found = "yes";
								}
							}
						}

						if($found == "yes"){
							if($tag->type=="geologic_unit"){

								$html.="<div>Rock Unit:</div>\n";
								$html.="<div class=\"leftPad\">\n";
								foreach($tag as $key=>$value){
									if($key != "date" && $key != "spots" && $key != "features" && $key != "id" ){
										$key = $this->fixLabel($key);
										if(is_string($value)){
											$value = $this->fixLabel($value);
										}
										$html.="<div>$key: $value</div>\n";
									}
								}

								$html.="</div>\n"; //end leftPad
							}
						}
					}
				}

				$hastags = "no";

				if($this->alltags){
					foreach($this->alltags as $tag){
						$found = "no";
						if($tag->spots){
							if($tag->type!="geologic_unit"){
								foreach($tag->spots as $spotid){
									if($spotid == $id){
										$hastags = "yes";
									}
								}
							}
						}

					}
				}

				if($hastags == "yes"){

					$html.="<div>Tags:</div>\n";
					$html.="<div class=\"leftPad\">\n";

					if($this->alltags){
						foreach($this->alltags as $tag){
							$found = "no";
							if($tag->spots){
								if($tag->type!="geologic_unit"){
									foreach($tag->spots as $spotid){
										if($spotid == $id){
											$found = "yes";
										}
									}
								}
							}

							if($found == "yes"){

								$html.="<div class=\"sectionTitle\">".$tag->name.": "."</div>\n";
								foreach($tag as $key=>$value){

									if($key != "date" && $key != "spots" && $key != "features" && $key != "id" && $key != "name" ){
										$key = $this->fixLabel($key);
										if(is_string($value)){
											$value = $this->fixLabel($value);
										}
										$html.="<div>$key: $value</div>\n";
									}

								}

							}
						}
					}

					$html.="</div>\n"; //end leftPad

				}

				if($spot['images']){
					$html.="<div>Images:</div>\n";
					$html.="<div class=\"leftPad\">\n";
					foreach($spot['images'] as $o){
						if($o['title']){
							$thistitle = $this->fixLabel($o['title']);
						}else{
							$thistitle = $o['id'];
						}
						$html.="<div class=\"sectionTitle\">".$thistitle.": "."</div>\n";
						foreach($o as $key=>$value){
							if($key!="id" && $key!="self" && $key!="annotated" && $key!="title"){
								$key = $this->fixLabel($key);
								if(is_string($value)){
									$value = $this->fixLabel($value);
								}
								$html.="<div>$key: $value</div>\n";
							}
						}

						$imageid = $o['id'];

						$filename = $this->strabo->getImageFilename($imageid);

						if($filename){
							$gdimage = $this->gdThumb($filename);
							if($gdimage){
								//write image to folder here (imagecreatetruecolor)
								imagejpeg($gdimage, "ogrtemp/$randnum/data/files/$imageid.jpg");

								$html.="<div><a href=\"https://www.strabospot.org/geimage/$imageid\"><img src=\"files/$imageid.jpg\"></a></div>\n";
							}
						}

					}

					$html.="</div>\n"; //end leftPad
				}

				if($mygeotype=="Point"){
					//build custom icon here if needed
					$customstyle=$this->buildCustomPoint($spot,$randnum);
					if($customstyle!=""){
						$pointstyle=$customstyle;
					}else{
						$pointstyle="";
					}
				}else{
					$pointstyle="";
				}

				$html.="]]>\n</description>\n<styleUrl>#".$thisstyle."</styleUrl>".$pointstyle.$polystyle."\n$kmlgeo\n</Placemark>\n\n";

			}

		}// end foreach spot

		return $html;
	}

	public function bkupfieldbookOut20240531(){

			if($this->get['dsids']!=""){

				$dsids=$this->get['dsids'];
				$this->alltags = $this->strabo->getTagsFromDatasetIds($dsids);

				$json = $this->strabo->getDatasetSpotsSearch(null,$this->get);

				$dsname = $this->strabo->getDatasetName($dsids);

				$dailynotes = $this->strabo->getDailyNotesFromDatasetID($dsids);

				$spots = $json['features'];

				//unix time 1567364734  10 digits
				//id 15609769344266     14 digits

				//get day for each spot

				//create date array to loop over

				if(count($spots)>0){

					$spotdates = [];
					$founddates = [];
					foreach($spots as $spot){

						$ut = substr($spot['properties']['id'], 0 ,10);

						$datestring = date("l, F j, Y", $ut);

						if(!in_array($datestring, $founddates)){
							$founddates[] = $datestring;
							$showformat = date("l, F j, Y", $ut);
							$lookformat = date("Y-m-d", $ut);
							$thisobj = new stdClass();
							$thisobj->showformat = $showformat;
							$thisobj->lookformat = $lookformat;
							$spotdates[] = $thisobj;
						}

					}

					require('includes/PDF_LabBook.php');

					$pdf = new PDF_MemImage('P','mm','Letter');

					$pdf->AddFont('msjh','','msjh.ttf',true);

					$pdf->AddPage();

					if($dsname != ""){
						$pdf->datasetTitle($dsname);
					}

					foreach($spotdates as $spotdate){

						$showdate = $spotdate->showformat;
						$pdf->dayTitle($showdate);

						//look for daily notes (format in data is YYYY-MM-DD)
						$lookdate = $spotdate->lookformat;

						if($dailynotes!=""){
							foreach($dailynotes as $dn){
								$dndate = substr($dn->date,0,10);

								if($dndate == $lookdate){
									$pdf->dailyNotesRow("Daily Setup Notes",$dn->notes,10);
								}
							}
						}

						foreach($spots as $spot){

							$rawspot = $spot;

							$spot = $spot['properties'];

							$id = $spot['id'];

							$ut = substr($id, 0 ,10);

							$thisspotdate = date("l, F j, Y", $ut);

							if($thisspotdate == $spotdate->showformat){

								$spotname = $spot['name'];
								if($spot['geometrytype']){
									$spotname .= " (".$spot['geometrytype'].")";
								}

								$pdf->spotTitle($spotname);

								$modified = (string) $spot['id'];
								$modified = substr($modified,0,10);
								$modified = date("F j, Y",$modified);
								$pdf->valueRow("Created",$modified,15);

								$modified = (string) $spot['modified_timestamp'];
								$modified = substr($modified,0,10);
								$modified = date("F j, Y",$modified);
								$pdf->valueRow("Last Modified",$modified,15);

								if($rawspot['geometry']->type=="Point" && $rawspot['properties']['strat_section_id']==""){
									$pdf->valueRow("Longitude",$rawspot['geometry']->coordinates[0],15);
									$pdf->valueRow("Latitude",$rawspot['geometry']->coordinates[1],15);
								}else{
								}

								if($spot['notes']){
									$notes = $spot['notes'];
									$pdf->notesRow("Notes",$notes,15);
								}

								if($spot['surface_feature']){
									foreach($spot['surface_feature'] as $key=>$value){
										$key = $this->fixLabel($key);
										if(is_string($value)){
											$value = $this->fixLabel($value);
										}
										$pdf->valueRow($key,$value,15);
									}
								}

								if($spot['orientation_data']){
									$pdf->valueRow("Orientations","",15);
									foreach($spot['orientation_data'] as $o){
										$pdf->valueTitle($this->fixLabel($o->type).": ",20);
										foreach($o as $key=>$value){
											if($key!="id" && $key!="associated_orientation" && $key!="type"){
												$key = $this->fixLabel($key);
												if(is_string($value)){
													$value = $this->fixLabel($value);
												}
												$pdf->valueRow($key,$value,20);
											}
										}

										if($o->associated_orientation){
											$pdf->valueRow("Associated Orientation Data","",20);
											foreach($o->associated_orientation as $ao){
												$pdf->valueTitle($this->fixLabel($ao->type).": ",30);
												foreach($ao as $key=>$value){
													if($key!="id" && $key!="associated_orientation" && $key!="type"){
														$key = $this->fixLabel($key);
														if(is_string($value)){
															$value = $this->fixLabel($value);
														}
														$pdf->valueRow($key,$value,30);
													}
												}
												$pdf->Ln(1);
											}
										}

										$pdf->Ln(1);
									}
								}

								if($spot['_3d_structures']){
									$pdf->valueRow("3D Structures","",15);
									foreach($spot['_3d_structures'] as $o){
										$pdf->valueTitle($this->fixLabel($o->type).": ",20);
										foreach($o as $key=>$value){
											if($key!="id" && $key!="type"){
												$key = $this->fixLabel($key);
												if(is_string($value)){
													$value = $this->fixLabel($value);
												}
												$pdf->valueRow($key,$value,20);
											}
										}

										$pdf->Ln(1);
									}
								}

								if($spot['samples']){
									$pdf->valueRow("Samples","",15);
									foreach($spot['samples'] as $o){
										$pdf->valueTitle($this->fixLabel($o->label).": ",20);
										foreach($o as $key=>$value){
											if($key!="id" && $key!="label"){
												$key = $this->fixLabel($key);
												if(is_string($value)){
													$value = $this->fixLabel($value);
												}
												$pdf->valueRow($key,$value,20);
											}
										}

										$pdf->Ln(1);
									}
								}

								if($spot['other_features']){
									$pdf->valueRow("Other Features","",15);
									foreach($spot['other_features'] as $o){
										$pdf->valueTitle($this->fixLabel($o->label).": ",20);
										foreach($o as $key=>$value){
											if($key!="id" && $key!="label"){
												$key = $this->fixLabel($key);
												if(is_string($value)){
													$value = $this->fixLabel($value);
												}
												$pdf->valueRow($key,$value,20);
											}
										}

										$pdf->Ln(1);
									}
								}

								$hastags = "no";

								if($this->alltags){
									foreach($this->alltags as $tag){
										$found = "no";
										if($tag->spots){
											if($tag->type!="geologic_unit"){
												foreach($tag->spots as $spotid){
													if($spotid == $id){
														$hastags = "yes";
													}
												}
											}
										}

									}
								}

								if($hastags == "yes"){

									$pdf->valueRow("Tags","",15);

									if($this->alltags){
										foreach($this->alltags as $tag){
											$found = "no";
											if($tag->spots){
												if($tag->type!="geologic_unit"){
													foreach($tag->spots as $spotid){
														if($spotid == $id){
															$found = "yes";
														}
													}
												}
											}

											if($found == "yes"){

												$pdf->valueTitle($tag->name,20);
												foreach($tag as $key=>$value){

													if($key != "date" && $key != "spots" && $key != "features" && $key != "id" && $key != "name" ){
														$key = $this->fixLabel($key);
														if(is_string($value)){
															$value = $this->fixLabel($value);
														}elseif(is_array($value)){
															$value = implode(", ", $value);
														}
														$pdf->valueRow($key,$value,20);
													}

												}

												$pdf->Ln(1);

											}
										}
									}

								}

								$hastags = "no";

								if($this->alltags){
									foreach($this->alltags as $tag){
										$found = "no";
										if($tag->spots){
											if($tag->type=="geologic_unit"){
												foreach($tag->spots as $spotid){
													if($spotid == $id){
														$hastags = "yes";
													}
												}
											}
										}

									}
								}

								if($hastags == "yes"){

									$pdf->valueRow("Geologic Unit(s)","",15);

									if($this->alltags){
										foreach($this->alltags as $tag){
											$found = "no";
											if($tag->spots){
												if($tag->type=="geologic_unit"){
													foreach($tag->spots as $spotid){
														if($spotid == $id){
															$found = "yes";
														}
													}
												}
											}

											if($found == "yes"){

												$pdf->valueTitle($tag->name,20);
												foreach($tag as $key=>$value){

													if($key != "date" && $key != "spots" && $key != "features" && $key != "id" && $key != "name" ){
														$key = $this->fixLabel($key);
														if(is_string($value)){
															$value = $this->fixLabel($value);
														}elseif(is_array($value)){
															$value = implode(", ", $value);
														}
														$pdf->valueRow($key,$value,20);
													}

												}

												$pdf->Ln(1);

											}
										}
									}

								}

								if($spot['sed']){
									$pdf->valueRow("Sed","",15);
									if($spot['sed']->strat_section){
										$pdf->valueTitle("Strat Section: ", 20);
										$content = $spot['sed']->strat_section;

										if($content->section_well_name!=""){
											$pdf->valueRow("Section/Well Name",$content->section_well_name,25);
										}

										if($content->column_profile!=""){
											$pdf->valueRow("Column Profile",$content->column_profile,25);
										}

										if($content->column_y_axis_units!=""){
											$pdf->valueRow("Column Y-Axis Units",$content->column_y_axis_units,25);
										}

										if($content->section_type!=""){
											$pdf->valueRow("Section Type",$content->section_type,25);
										}

										if($content->what_core_repository!=""){
											$pdf->valueRow("What Core Repository?",$content->what_core_repository,25);
										}

										if($content->type_of_corer!=""){
											$pdf->valueRow("Type of corer",$content->type_of_corer,25);
										}

										if($content->depth_from_surface_to_start_of!=""){
											$pdf->valueRow("Depth from surface to start of core",$content->depth_from_surface_to_start_of,25);
										}

										if($content->total_core_length!=""){
											$pdf->valueRow("Total core length",$content->total_core_length,25);
										}

										if($content->location_locality!=""){
											$pdf->valueRow("Location/Locality",$content->location_locality,25);
										}

										if($content->basin!=""){
											$pdf->valueRow("Basin",$content->basin,25);
										}

										if($content->age!=""){
											$pdf->valueRow("Age",$content->age,25);
										}

										if($content->purpose!=""){
											$pdf->valueRow("Purpose",implode($content->purpose, ", "),25);
										}

										if($content->other_purpose!=""){
											$pdf->valueRow("Other Purpose",$content->other_purpose,25);
										}

										if($content->project_description!=""){
											$pdf->valueRow("Project Description",$content->project_description,25);
										}

										if($content->dates_of_work!=""){
											$pdf->valueRow("Dates of Work",$content->dates_of_work,25);
										}

										if($content->scale_of_interest!=""){
											$pdf->valueRow("Scale of Interest",implode($content->scale_of_interest, ", "),25);
										}

										if($content->other_scale_of_interest!=""){
											$pdf->valueRow("Other Scale of Interest",$content->other_scale_of_interest,25);
										}

										if($content->obs_interval_bed_obs_scale!=""){
											$pdf->valueRow("Observation Interval (average bed/observation scale)",$content->obs_interval_bed_obs_scale,25);
										}

										if($content->how_is_section_georeferenced!=""){
											$pdf->valueRow("How is the Section Georeferenced?",$content->how_is_section_georeferenced,25);
										}

										if($content->strat_section_notes!=""){
											$pdf->valueRow("Notes",$content->strat_section_notes,25);
										}

										//strat section link here
										$pdf->httpLink("Download Strat Section", 25, "https://strabospot.org/pstrat_section?id=".$spot['id']."&did=".$dsids);

									}

									if($spot['sed']->lithologies){
										$pdf->valueTitle("Lithologies: ", 20);
										$contents = $spot['sed']->lithologies;
										foreach($contents as $content){
											$pdf->valueTitle("Primary Lithology: ", 25);

											if($content->primary_lithology!=""){
												$pdf->valueRow("Primary Lithology",$content->primary_lithology,30);
											}

											if($content->siliciclastic_type!=""){
												$pdf->valueRow("Siliciclastic Type",$content->siliciclastic_type,30);
											}

											if($content->dunham_classification!=""){
												$pdf->valueRow("Dunham Classification",$content->dunham_classification,30);
											}

											if($content->grain_type!=""){
												$pdf->valueRow("Grain Type",$content->grain_type,30);
											}

											if($content->evaporite_type!=""){
												$pdf->valueRow("Evaporite type",implode($content->evaporite_type, ", "),30);
											}

											if($content->other_evaporite_type!=""){
												$pdf->valueRow("Other Evaporite Type",$content->other_evaporite_type,30);
											}

											if($content->organic_coal_lithologies!=""){
												$pdf->valueRow("Organic/Coal Lithologies",implode($content->organic_coal_lithologies, ", "),30);
											}

											if($content->other_organic_coal_lithology!=""){
												$pdf->valueRow("Other Organic/Coal Lithology",$content->other_organic_coal_lithology,30);
											}

											if($content->volcaniclastic_type!=""){
												$pdf->valueRow("Volcaniclastic type",implode($content->volcaniclastic_type, ", "),30);
											}

											if($content->other_volcaniclastic_type!=""){
												$pdf->valueRow("Other Volcaniclastic Type",$content->other_volcaniclastic_type,30);
											}

											if($content->report_presence_of_particle_ag!=""){
												$pdf->valueRow("Report presence of particle aggregates ",$content->report_presence_of_particle_ag,30);
											}

											if($content->componentry!=""){
												$pdf->valueRow("Componentry",implode($content->componentry, ", "),30);
											}

											if($content->approximate_relative_abundance!=""){
												$pdf->valueRow("Approximate relative abundances of clasts",$content->approximate_relative_abundance,30);
											}

											if($content->phosphorite_type!=""){
												$pdf->valueRow("Phosphorite type",implode($content->phosphorite_type, ", "),30);
											}

											if($content->other_phosphorite_type!=""){
												$pdf->valueRow("Other Phosphorite Type",$content->other_phosphorite_type,30);
											}

											$pdf->valueTitle("Lithification & Color: ", 25);

											if($content->relative_resistance_weather!=""){
												$pdf->valueRow("Relative resistance (weathering profile)",$content->relative_resistance_weather,30);
											}

											if($content->lithification!=""){
												$pdf->valueRow("Lithification",$content->lithification,30);
											}

											if($content->evidence_of_deposit_alteration!=""){
												$pdf->valueRow("Evidence of deposit alteration",$content->evidence_of_deposit_alteration,30);
											}

											if($content->evidence_of_clast_alteration!=""){
												$pdf->valueRow("Evidence of clast alteration",$content->evidence_of_clast_alteration,30);
											}

											if($content->fresh_color!=""){
												$pdf->valueRow("Fresh Color",$content->fresh_color,30);
											}

											if($content->weathered_color!=""){
												$pdf->valueRow("Weathered Color",$content->weathered_color,30);
											}

											if($content->color_appearance!=""){
												$pdf->valueRow("Color Appearance",implode($content->color_appearance, ", "),30);
											}

											if($content->other_color_appearance!=""){
												$pdf->valueRow("Other Color Appearance",$content->other_color_appearance,30);
											}

											if($content->notes!=""){
												$pdf->valueRow("Notes",$content->notes,30);
											}

											$pdf->valueTitle("Composition: ", 25);

											if($content->minerals_present!=""){
												$pdf->valueRow("Minerals Present",implode($content->minerals_present, ", "),30);
											}

											if($content->other_minerals!=""){
												$pdf->valueRow("Other Mineral(s)",$content->other_minerals,30);
											}

											if($content->sandstone_type_dott!=""){
												$pdf->valueRow("Dott Classification",implode($content->sandstone_type_dott, ", "),30);
											}

											if($content->sandstone_type_folk_mcbride!=""){
												$pdf->valueRow("Folk/McBride Classification",implode($content->sandstone_type_folk_mcbride, ", "),30);
											}

											if($content->sandstone_modifier!=""){
												$pdf->valueRow("Sandstone modifier",implode($content->sandstone_modifier, ", "),30);
											}

											if($content->other_sandstone_modifier!=""){
												$pdf->valueRow("Other Sandstone Modifier",$content->other_sandstone_modifier,30);
											}

											if($content->skeletal_carbonate_components!=""){
												$pdf->valueRow("Skeletal Carbonate Components",implode($content->skeletal_carbonate_components, ", "),30);
											}

											if($content->other_skeletal_carbonate_component!=""){
												$pdf->valueRow("Other Skeletal Carbonate Component",$content->other_skeletal_carbonate_component,30);
											}

											if($content->skeletal_general_percent!=""){
												$pdf->valueRow("Skeletal (General) percent",$content->skeletal_general_percent,30);
											}

											if($content->mollusc_percent!=""){
												$pdf->valueRow("Mollusc percent",$content->mollusc_percent,30);
											}

											if($content->brachiopod_percent!=""){
												$pdf->valueRow("Brachiopod percent",$content->brachiopod_percent,30);
											}

											if($content->coral_percent!=""){
												$pdf->valueRow("Coral percent",$content->coral_percent,30);
											}

											if($content->echinoderm_percent!=""){
												$pdf->valueRow("Echinoderm percent",$content->echinoderm_percent,30);
											}

											if($content->bryozoan_percent!=""){
												$pdf->valueRow("Bryozoan percent",$content->bryozoan_percent,30);
											}

											if($content->calcareous_algae_percent!=""){
												$pdf->valueRow("Calcareous Algae percent",$content->calcareous_algae_percent,30);
											}

											if($content->foraminifera_percent!=""){
												$pdf->valueRow("Foraminifera percent",$content->foraminifera_percent,30);
											}

											if($content->stromatolite_percent!=""){
												$pdf->valueRow("Stromatolite percent",$content->stromatolite_percent,30);
											}

											if($content->thrombolite_percent!=""){
												$pdf->valueRow("Thrombolite percent",$content->thrombolite_percent,30);
											}

											if($content->dendrolite_percent!=""){
												$pdf->valueRow("Dendrolite percent",$content->dendrolite_percent,30);
											}

											if($content->leiolite_percent!=""){
												$pdf->valueRow("Leiolite percent",$content->leiolite_percent,30);
											}

											if($content->other_skeletal_carbonate_component_percent!=""){
												$pdf->valueRow("Other Skeletal Carbonate Component percent",$content->other_skeletal_carbonate_component_percent,30);
											}

											if($content->non_skeletal_carbonate_compone!=""){
												$pdf->valueRow("Non-Skeletal Carbonate Components",implode($content->non_skeletal_carbonate_compone, ", "),30);
											}

											if($content->other_non_skeletal_carbonate_component!=""){
												$pdf->valueRow("Other Non-Skeletal Carbonate Component",$content->other_non_skeletal_carbonate_component,30);
											}

											if($content->mud_percent!=""){
												$pdf->valueRow("Mud percent",$content->mud_percent,30);
											}

											if($content->cement_percent!=""){
												$pdf->valueRow("Cement percent",$content->cement_percent,30);
											}

											if($content->intraclast_percent!=""){
												$pdf->valueRow("Intraclast percent",$content->intraclast_percent,30);
											}

											if($content->peloid_percent!=""){
												$pdf->valueRow("Peloid percent",$content->peloid_percent,30);
											}

											if($content->ooid_percent!=""){
												$pdf->valueRow("Ooid percent",$content->ooid_percent,30);
											}

											if($content->oncoid_percent!=""){
												$pdf->valueRow("Oncoid percent",$content->oncoid_percent,30);
											}

											if($content->pisoid_percent!=""){
												$pdf->valueRow("Pisoid percent",$content->pisoid_percent,30);
											}

											if($content->coated_grian_percent!=""){
												$pdf->valueRow("Coated Grian percent",$content->coated_grian_percent,30);
											}

											if($content->grapestone_percent!=""){
												$pdf->valueRow("Grapestone percent",$content->grapestone_percent,30);
											}

											if($content->giant_ooid_percent!=""){
												$pdf->valueRow("Giant Ooid percent",$content->giant_ooid_percent,30);
											}

											if($content->seafloor_precipitate_percent!=""){
												$pdf->valueRow("Seafloor Precipitate percent",$content->seafloor_precipitate_percent,30);
											}

											if($content->molar_tooth_percent!=""){
												$pdf->valueRow("Molar Tooth percent",$content->molar_tooth_percent,30);
											}

											if($content->other_non_skeletal_component_percent!=""){
												$pdf->valueRow("Other Non-Skeletal Component percent",$content->other_non_skeletal_component_percent,30);
											}

											if($content->clay_or_mudstone_type!=""){
												$pdf->valueRow("Claystone or Mudstone Type",implode($content->clay_or_mudstone_type, ", "),30);
											}

											if($content->other_claystone_mudstone!=""){
												$pdf->valueRow("Other Claystone/Mudstone",$content->other_claystone_mudstone,30);
											}

											if($content->siliceous_mudstone_percent!=""){
												$pdf->valueRow("Siliceous mudstone percent",$content->siliceous_mudstone_percent,30);
											}

											if($content->siliceous_calcareous_mudstone_percent!=""){
												$pdf->valueRow("Siliceous calcareous mudstone percent",$content->siliceous_calcareous_mudstone_percent,30);
											}

											if($content->siliceous_volcanicla_tic_mudstone_percent!=""){
												$pdf->valueRow("Siliceous volcaniclastic mudstone percent",$content->siliceous_volcanicla_tic_mudstone_percent,30);
											}

											if($content->calcareous_mudstone_percent!=""){
												$pdf->valueRow("Calcareous mudstone percent",$content->calcareous_mudstone_percent,30);
											}

											if($content->black_shale_percent!=""){
												$pdf->valueRow("Black shale percent",$content->black_shale_percent,30);
											}

											if($content->red_clay_percent!=""){
												$pdf->valueRow("Red clay percent",$content->red_clay_percent,30);
											}

											if($content->red_mudstone_percent!=""){
												$pdf->valueRow("Red mudstone percent",$content->red_mudstone_percent,30);
											}

											if($content->green_mudstone_percent!=""){
												$pdf->valueRow("Green mudstone percent",$content->green_mudstone_percent,30);
											}

											if($content->variegated_mudstone_percent!=""){
												$pdf->valueRow("Variegated mudstone percent",$content->variegated_mudstone_percent,30);
											}

											if($content->marl_percent!=""){
												$pdf->valueRow("Marl percent",$content->marl_percent,30);
											}

											if($content->sarl_percent!=""){
												$pdf->valueRow("Sarl percent",$content->sarl_percent,30);
											}

											if($content->argillaceous_mudstone_percent!=""){
												$pdf->valueRow("Argillaceous mudstone percent",$content->argillaceous_mudstone_percent,30);
											}

											if($content->conglomerate_composition!=""){
												$pdf->valueRow("Conglomerate/Breccia Composition",implode($content->conglomerate_composition, ", "),30);
											}

											if($content->clast_composition!=""){
												$pdf->valueRow("Clast Composition",implode($content->clast_composition, ", "),30);
											}

											if($content->other_clast_types!=""){
												$pdf->valueRow("Other Clast Type(s)",$content->other_clast_types,30);
											}

											if($content->intrusive_igneous_clast_percent!=""){
												$pdf->valueRow("Intrusive igneous clast percent",$content->intrusive_igneous_clast_percent,30);
											}

											if($content->extrusive_igneous_clast_percent!=""){
												$pdf->valueRow("Volcanic clast percent",$content->extrusive_igneous_clast_percent,30);
											}

											if($content->metamorphic_clast_percent!=""){
												$pdf->valueRow("Metamorphic clast percent",$content->metamorphic_clast_percent,30);
											}

											if($content->mudstone_clast_percent!=""){
												$pdf->valueRow("Mudstone clast percent",$content->mudstone_clast_percent,30);
											}

											if($content->siltstone_clast_percent!=""){
												$pdf->valueRow("Siltstone clast percent",$content->siltstone_clast_percent,30);
											}

											if($content->sandstone_clast_percent!=""){
												$pdf->valueRow("Sandstone clast percent",$content->sandstone_clast_percent,30);
											}

											if($content->conglomerate_clast_percent!=""){
												$pdf->valueRow("Conglomerate clast percent",$content->conglomerate_clast_percent,30);
											}

											if($content->limestone_clast_percent!=""){
												$pdf->valueRow("Limestone clast percent",$content->limestone_clast_percent,30);
											}

											if($content->dolostone_clast_percent!=""){
												$pdf->valueRow("Dolostone clast percent",$content->dolostone_clast_percent,30);
											}

											if($content->wackestone_clast_percent!=""){
												$pdf->valueRow("Wackestone clast percent",$content->wackestone_clast_percent,30);
											}

											if($content->packstone_clast_percent!=""){
												$pdf->valueRow("Packstone clast percent",$content->packstone_clast_percent,30);
											}

											if($content->grainstone_clast_percent!=""){
												$pdf->valueRow("Grainstone clast percent",$content->grainstone_clast_percent,30);
											}

											if($content->boundstone_clast_percent!=""){
												$pdf->valueRow("Boundstone clast percent",$content->boundstone_clast_percent,30);
											}

											if($content->other_clast_percent!=""){
												$pdf->valueRow("Other clast percent",$content->other_clast_percent,30);
											}

											if($content->matrix_composition!=""){
												$pdf->valueRow("Matrix composition",implode($content->matrix_composition, ", "),30);
											}

											if($content->other_matrix_types!=""){
												$pdf->valueRow("Other Matrix Type(s)",$content->other_matrix_types,30);
											}

											if($content->intrusive_igneous_matrix_percent!=""){
												$pdf->valueRow("Intrusive igneous matrix percent",$content->intrusive_igneous_matrix_percent,30);
											}

											if($content->extrusive_igneous_matrix_percent!=""){
												$pdf->valueRow("Extrusive igneous matrix percent",$content->extrusive_igneous_matrix_percent,30);
											}

											if($content->metamorphic_igneous_matrix_percent!=""){
												$pdf->valueRow("Metamorphic igneous matrix percent",$content->metamorphic_igneous_matrix_percent,30);
											}

											if($content->mudstone_matrix_percent!=""){
												$pdf->valueRow("Mudstone matrix percent",$content->mudstone_matrix_percent,30);
											}

											if($content->siltstone_matrix_percent!=""){
												$pdf->valueRow("Siltstone matrix percent",$content->siltstone_matrix_percent,30);
											}

											if($content->sandstone_matrix_percent!=""){
												$pdf->valueRow("Sandstone matrix percent",$content->sandstone_matrix_percent,30);
											}

											if($content->conglomerate_matrix_percent!=""){
												$pdf->valueRow("Conglomerate matrix percent",$content->conglomerate_matrix_percent,30);
											}

											if($content->carbonate_matrix_type!=""){
												$pdf->valueRow("Carbonate Matrix Type",implode($content->carbonate_matrix_type, ", "),30);
											}

											if($content->limestone_matrix_percent!=""){
												$pdf->valueRow("Limestone matrix percent",$content->limestone_matrix_percent,30);
											}

											if($content->dolostone_matrix_percent!=""){
												$pdf->valueRow("Dolostone matrix percent",$content->dolostone_matrix_percent,30);
											}

											if($content->skeletal_matrix_percent!=""){
												$pdf->valueRow("Skeletal matrix percent",$content->skeletal_matrix_percent,30);
											}

											if($content->wackestone_matrix_percent!=""){
												$pdf->valueRow("Wackestone matrix percent",$content->wackestone_matrix_percent,30);
											}

											if($content->packstone_matrix_percent!=""){
												$pdf->valueRow("Packstone matrix percent",$content->packstone_matrix_percent,30);
											}

											if($content->grainstone_matrix_percent!=""){
												$pdf->valueRow("Grainstone matrix percent",$content->grainstone_matrix_percent,30);
											}

											if($content->boundstone_matrix_percent!=""){
												$pdf->valueRow("Boundstone matrix percent",$content->boundstone_matrix_percent,30);
											}

											if($content->other_carbonate_matrix_percent!=""){
												$pdf->valueRow("Other carbonate matrix percent",$content->other_carbonate_matrix_percent,30);
											}

											if($content->other_matrix_percent!=""){
												$pdf->valueRow("Other matrix percent",$content->other_matrix_percent,30);
											}

											if($content->volcaniclastic_type!=""){
												$pdf->valueRow("Volcaniclastic type",implode($content->volcaniclastic_type, ", "),30);
											}

											if($content->other_volcaniclastic_type!=""){
												$pdf->valueRow("Other Volcaniclastic Type",$content->other_volcaniclastic_type,30);
											}

											if($content->glass_percent!=""){
												$pdf->valueRow("Glass percent",$content->glass_percent,30);
											}

											if($content->crystals_percent!=""){
												$pdf->valueRow("Crystals percent",$content->crystals_percent,30);
											}

											if($content->lithic_fragments_percent!=""){
												$pdf->valueRow("Lithic fragments percent",$content->lithic_fragments_percent,30);
											}

											if($content->volcanic_mudstone_percent!=""){
												$pdf->valueRow("Volcanic mudstone percent",$content->volcanic_mudstone_percent,30);
											}

											if($content->volcanic_sandstone_percent!=""){
												$pdf->valueRow("Volcanic sandstone percent",$content->volcanic_sandstone_percent,30);
											}

											if($content->lapillistone_percent!=""){
												$pdf->valueRow("Lapillistone percent",$content->lapillistone_percent,30);
											}

											if($content->agglomerate_percent!=""){
												$pdf->valueRow("Agglomerate percent",$content->agglomerate_percent,30);
											}

											if($content->volcanic_breccia_percent!=""){
												$pdf->valueRow("Volcanic breccia percent",$content->volcanic_breccia_percent,30);
											}

											if($content->bentonite_percent!=""){
												$pdf->valueRow("Bentonite percent",$content->bentonite_percent,30);
											}

											if($content->tuff_percent!=""){
												$pdf->valueRow("Tuff percent",$content->tuff_percent,30);
											}

											if($content->welded_tuff_percent!=""){
												$pdf->valueRow("Welded tuff percent",$content->welded_tuff_percent,30);
											}

											if($content->ignimbrite_percent!=""){
												$pdf->valueRow("Ignimbrite percent",$content->ignimbrite_percent,30);
											}

											if($content->other_volcaniclastic_type_percent!=""){
												$pdf->valueRow("Other volcaniclastic type percent",$content->other_volcaniclastic_type_percent,30);
											}

											if($content->evaporite_type!=""){
												$pdf->valueRow("Evaporite type",implode($content->evaporite_type, ", "),30);
											}

											if($content->gypsum_anhydrite_primary_percent!=""){
												$pdf->valueRow("Gypsum - anhydrite primary percent",$content->gypsum_anhydrite_primary_percent,30);
											}

											if($content->gypsum_anhydrite_primary_type!=""){
												$pdf->valueRow("Gypsum - anhydrite primary type",implode($content->gypsum_anhydrite_primary_type, ", "),30);
											}

											if($content->gypsum_anhydrite_diagenetic_percent!=""){
												$pdf->valueRow("Gypsum - anhydrite diagenetic percent",$content->gypsum_anhydrite_diagenetic_percent,30);
											}

											if($content->gypsum_anhydrite_diagenetic_type!=""){
												$pdf->valueRow("Gypsum - anhydrite diagenetic type",implode($content->gypsum_anhydrite_diagenetic_type, ", "),30);
											}

											if($content->halite_primary_percent!=""){
												$pdf->valueRow("Halite - primary percent",$content->halite_primary_percent,30);
											}

											if($content->halite_primary_type!=""){
												$pdf->valueRow("Halite - primary type",implode($content->halite_primary_type, ", "),30);
											}

											if($content->halite_diagenetic_percent!=""){
												$pdf->valueRow("Halite - diagenetic percent",$content->halite_diagenetic_percent,30);
											}

											if($content->halite_diagenetic_type!=""){
												$pdf->valueRow("Halite - diagenetic type",implode($content->halite_diagenetic_type, ", "),30);
											}

											if($content->phosphorite_type!=""){
												$pdf->valueRow("Phosphorite type",implode($content->phosphorite_type, ", "),30);
											}

											if($content->organic_coal_lithologies!=""){
												$pdf->valueRow("Organic/Coal Lithologies",implode($content->organic_coal_lithologies, ", "),30);
											}

											if($content->amber_percent!=""){
												$pdf->valueRow("Amber percent",$content->amber_percent,30);
											}

											if($content->peat_percent!=""){
												$pdf->valueRow("Peat percent",$content->peat_percent,30);
											}

											if($content->lignite_percent!=""){
												$pdf->valueRow("Lignite percent",$content->lignite_percent,30);
											}

											if($content->subbituminous_percent!=""){
												$pdf->valueRow("Subbituminous percent",$content->subbituminous_percent,30);
											}

											if($content->bituminous_percent!=""){
												$pdf->valueRow("Bituminous percent",$content->bituminous_percent,30);
											}

											if($content->coal_ball_percent!=""){
												$pdf->valueRow("Coal ball percent",$content->coal_ball_percent,30);
											}

											if($content->tar_percent!=""){
												$pdf->valueRow("Tar percent",$content->tar_percent,30);
											}

											if($content->notes!=""){
												$pdf->valueRow("Notes",$content->notes,30);
											}

											$pdf->valueTitle("Texture: ", 25);

											if($content->mud_silt_grain_size!=""){
												$pdf->valueRow("Mudstone/Siltstone Grain Size",$content->mud_silt_grain_size,30);
											}

											if($content->sand_grain_size!=""){
												$pdf->valueRow("Sandstone Grain Size",$content->sand_grain_size,30);
											}

											if($content->congl_grain_size!=""){
												$pdf->valueRow("Conglomerate Grain Size",$content->congl_grain_size,30);
											}

											if($content->breccia_grain_size!=""){
												$pdf->valueRow("Breccia Grain Size",$content->breccia_grain_size,30);
											}

											if($content->grain_size_range!=""){
												$pdf->valueRow("Grain Size Range",implode($content->grain_size_range, ", "),30);
											}

											if($content->clay_percent!=""){
												$pdf->valueRow("Clay percent",$content->clay_percent,30);
											}

											if($content->silt_percent!=""){
												$pdf->valueRow("Silt percent",$content->silt_percent,30);
											}

											if($content->sand_very_fine_percent!=""){
												$pdf->valueRow("Sand - very fine percent",$content->sand_very_fine_percent,30);
											}

											if($content->sand_fine_lower_percent!=""){
												$pdf->valueRow("Sand - fine lower percent",$content->sand_fine_lower_percent,30);
											}

											if($content->sand_fine_upper_percent!=""){
												$pdf->valueRow("Sand - fine upper percent",$content->sand_fine_upper_percent,30);
											}

											if($content->sand_medium_lower_percent!=""){
												$pdf->valueRow("Sand - medium lower percent",$content->sand_medium_lower_percent,30);
											}

											if($content->sand_medium_upper_percent!=""){
												$pdf->valueRow("Sand - medium upper percent",$content->sand_medium_upper_percent,30);
											}

											if($content->sand_coarse_lower_percent!=""){
												$pdf->valueRow("Sand - coarse lower percent",$content->sand_coarse_lower_percent,30);
											}

											if($content->sand_coarse_upper_percent!=""){
												$pdf->valueRow("Sand - coarse upper percent",$content->sand_coarse_upper_percent,30);
											}

											if($content->sand_very_coarse_percent!=""){
												$pdf->valueRow("Sand - very coarse percent",$content->sand_very_coarse_percent,30);
											}

											if($content->granule_percent!=""){
												$pdf->valueRow("Granule percent",$content->granule_percent,30);
											}

											if($content->pebble_percent!=""){
												$pdf->valueRow("Pebble percent",$content->pebble_percent,30);
											}

											if($content->cobble_percent!=""){
												$pdf->valueRow("Cobble percent",$content->cobble_percent,30);
											}

											if($content->boulder_percent!=""){
												$pdf->valueRow("Boulder percent",$content->boulder_percent,30);
											}

											if($content->maximum_clast_size_cm!=""){
												$pdf->valueRow("Maximum Clast Size (cm)",$content->maximum_clast_size_cm,30);
											}

											if($content->minimum_clast_size_cm!=""){
												$pdf->valueRow("Minimum Clast Size (cm)",$content->minimum_clast_size_cm,30);
											}

											if($content->average_clast_size_cm!=""){
												$pdf->valueRow("Average Clast Size (cm)",$content->average_clast_size_cm,30);
											}

											if($content->matrix_size!=""){
												$pdf->valueRow("Matrix Size",implode($content->matrix_size, ", "),30);
											}

											if($content->character!=""){
												$pdf->valueRow("Character",implode($content->character, ", "),30);
											}

											if($content->sorting!=""){
												$pdf->valueRow("Sorting",implode($content->sorting, ", "),30);
											}

											if($content->rounding!=""){
												$pdf->valueRow("Rounding",implode($content->rounding, ", "),30);
											}

											if($content->shape!=""){
												$pdf->valueRow("Shape",implode($content->shape, ", "),30);
											}

											if($content->other_shape!=""){
												$pdf->valueRow("Other Shape",$content->other_shape,30);
											}

											if($content->notes!=""){
												$pdf->valueRow("Notes",$content->notes,30);
											}

											if($content->stratification!=""){
												$pdf->valueRow("Stratification",implode($content->stratification, ", "),30);
											}

											if($content->laminae_thickness_i_select_more_than_one!=""){
												$pdf->valueRow("Laminae Thickness",implode($content->laminae_thickness_i_select_more_than_one, ", "),30);
											}

											if($content->lamination_character!=""){
												$pdf->valueRow("Lamination Character",implode($content->lamination_character, ", "),30);
											}

											if($content->bedding_thickness!=""){
												$pdf->valueRow("Bedding Thickness",implode($content->bedding_thickness, ", "),30);
											}

											if($content->bedding_character!=""){
												$pdf->valueRow("Bedding character",implode($content->bedding_character, ", "),30);
											}

											if($content->package_geometry!=""){
												$pdf->valueRow("Package Geometry",implode($content->package_geometry, ", "),30);
											}

											if($content->package_lateral_extent!=""){
												$pdf->valueRow("Package Lateral Extent",$content->package_lateral_extent,30);
											}

											if($content->package_bedding_trends!=""){
												$pdf->valueRow("Package Bedding Trends",$content->package_bedding_trends,30);
											}

											if($content->other_bedding_trend!=""){
												$pdf->valueRow("Other bedding trend",$content->other_bedding_trend,30);
											}

											if($content->shape_of_lower_contacts!=""){
												$pdf->valueRow("Shape of lower contact(s)",implode($content->shape_of_lower_contacts, ", "),30);
											}

											if($content->character_of_lower_contacts!=""){
												$pdf->valueRow("Character of lower contact(s)",implode($content->character_of_lower_contacts, ", "),30);
											}

											if($content->lower_contact_relief!=""){
												$pdf->valueRow("Lower contact relief",$content->lower_contact_relief,30);
											}

											if($content->shape_of_upper_contacts!=""){
												$pdf->valueRow("Shape of upper contact(s)",implode($content->shape_of_upper_contacts, ", "),30);
											}

											if($content->character_of_upper_contacts!=""){
												$pdf->valueRow("Character of upper contact(s)",implode($content->character_of_upper_contacts, ", "),30);
											}

											if($content->upper_contact_relief!=""){
												$pdf->valueRow("Upper Contact Relief",$content->upper_contact_relief,30);
											}

											if($content->interbed_proportion!=""){
												$pdf->valueRow("Interbed Relative Proportion (%)",$content->interbed_proportion,30);
											}

											if($content->interbed_thickness!=""){
												$pdf->valueRow("Interbed Thickness",$content->interbed_thickness,30);
											}

											if($content->interbed_proportion_change!=""){
												$pdf->valueRow("Interbed Proportion Change (Up Section)",$content->interbed_proportion_change,30);
											}

											if($content->interbed_thickness_change!=""){
												$pdf->valueRow("Interbed Thickness Change (Up Section)",$content->interbed_thickness_change,30);
											}

											if($content->Notes!=""){
												$pdf->valueRow("Notes",$content->Notes,30);
											}

										}

									}

									if($spot['sed']->bedding){
										$pdf->valueTitle("Bedding: ", 20);
										$content = $spot['sed']->bedding;

										if($content->interbed_proportion_change!=""){
											$pdf->valueRow("Interbed Proportion Change (Up Section)",$content->interbed_proportion_change,25);
										}

										if($content->interbed_proportion!=""){
											$pdf->valueRow("Lithology 1: Interbed Relative Proportion (%)",$content->interbed_proportion,25);
										}

										if($content->lithology_at_bottom_contact!=""){
											$pdf->valueRow("Which Lithology is at Bottom Contact?",$content->lithology_at_bottom_contact,25);
										}

										if($content->lithology_at_top_contact!=""){
											$pdf->valueRow("Which Lithology is at Top Contact?",$content->lithology_at_top_contact,25);
										}

										if($content->thickness_of_individual_beds!=""){
											$pdf->valueRow("Thickness of Individual Beds",$content->thickness_of_individual_beds,25);
										}

										if($content->package_thickness_units!=""){
											$pdf->valueRow("Package Beds Thickness Units",$content->package_thickness_units,25);
										}

										if($content->package_bedding_trends!=""){
											$pdf->valueRow("Package Bedding Trends",$content->package_bedding_trends,25);
										}

										if($content->other_bedding_trend!=""){
											$pdf->valueRow("Other Package Bedding Trend",$content->other_bedding_trend,25);
										}

										$beds = $spot['sed']->bedding->beds;
										foreach($beds as $content){

											if($content->package_geometry!=""){
												$pdf->valueRow("Bed Geometry",implode($content->package_geometry, ", "),25);
											}

											$pdf->valueTitle("Lower Contact: ", 25);

											if($content->shape_of_lower_contacts!=""){
												$pdf->valueRow("Shape of lower contact",implode($content->shape_of_lower_contacts, ", "),30);
											}

											if($content->character_of_lower_contacts!=""){
												$pdf->valueRow("Character of lower contact(s)",implode($content->character_of_lower_contacts, ", "),30);
											}

											if($content->lower_contact_relief!=""){
												$pdf->valueRow("Lower contact relief",$content->lower_contact_relief,30);
											}

											$pdf->valueTitle("Upper Contact: ", 25);

											if($content->shape_of_upper_contacts!=""){
												$pdf->valueRow("Shape of upper contact",implode($content->shape_of_upper_contacts, ", "),30);
											}

											if($content->character_of_upper_contacts!=""){
												$pdf->valueRow("Character of upper contact",implode($content->character_of_upper_contacts, ", "),30);
											}

											if($content->upper_contact_relief!=""){
												$pdf->valueRow("Upper Contact Relief",$content->upper_contact_relief,30);
											}

											$pdf->valueTitle("Interbed Thickness: ", 25);

											if($content->avg_thickness!=""){
												$pdf->valueRow("Average Thickness",$content->avg_thickness,30);
											}

											if($content->max_thickness!=""){
												$pdf->valueRow("Maximum Thickness",$content->max_thickness,30);
											}

											if($content->min_thickness!=""){
												$pdf->valueRow("Minimum Thickness",$content->min_thickness,30);
											}

											if($content->interbed_thickness_units!=""){
												$pdf->valueRow("Interbed Thickness Units",$content->interbed_thickness_units,30);
											}

											if($content->notes!=""){
												$pdf->valueRow("Notes",$content->notes,30);
											}

										}
									}

									if($spot['sed']->structures){
										$pdf->valueTitle("Structures: ", 20);
										$contents = $spot['sed']->structures;
										foreach($contents as $content){

											if($content->massive_structureless!=""){
												$pdf->valueRow("Massive/Structureless?",$content->massive_structureless,25);
											}

											$pdf->valueTitle("Cross Bedding: ", 25);

											if($content->cross_bedding_type!=""){
												$pdf->valueRow("Cross Bedding Type",implode($content->cross_bedding_type, ", "),30);
											}

											if($content->cross_bedding_height_cm!=""){
												$pdf->valueRow("Cross Bedding Height (cm)",$content->cross_bedding_height_cm,30);
											}

											if($content->cross_bedding_width_cm!=""){
												$pdf->valueRow("Cross Bedding Width (cm)",$content->cross_bedding_width_cm,30);
											}

											if($content->cross_bedding_thickness_cm!=""){
												$pdf->valueRow("Cross Bedding Thickness (cm)",$content->cross_bedding_thickness_cm,30);
											}

											if($content->cross_bedding_spacing_cm!=""){
												$pdf->valueRow("Cross Bedding Spacing (cm)",$content->cross_bedding_spacing_cm,30);
											}

											$pdf->valueTitle("Ripple Lamination: ", 25);

											if($content->ripple_lamination_type!=""){
												$pdf->valueRow("Ripple Lamination Type",implode($content->ripple_lamination_type, ", "),30);
											}

											if($content->other_ripple_lamination_type!=""){
												$pdf->valueRow("Other Ripple Lamination Type",$content->other_ripple_lamination_type,30);
											}

											if($content->ripple_lamination_height_mm!=""){
												$pdf->valueRow("Ripple Lamination Height (mm)",$content->ripple_lamination_height_mm,30);
											}

											if($content->ripple_lamination_width_mm!=""){
												$pdf->valueRow("Ripple Lamination Width (mm)",$content->ripple_lamination_width_mm,30);
											}

											if($content->ripple_lamination_thick_mm!=""){
												$pdf->valueRow("Ripple Lmation Thickness (mm)",$content->ripple_lamination_thick_mm,30);
											}

											if($content->ripple_lamination_spacing_mm!=""){
												$pdf->valueRow("Ripple Lamination Spacing (mm)",$content->ripple_lamination_spacing_mm,30);
											}

											$pdf->valueTitle("Horizontal Bedding: ", 25);

											if($content->horizontal_bedding_type!=""){
												$pdf->valueRow("Horizontal Bedding Type",implode($content->horizontal_bedding_type, ", "),30);
											}

											if($content->other_horizontal_bedding_type!=""){
												$pdf->valueRow("Other Horizontal Bedding Type",$content->other_horizontal_bedding_type,30);
											}

											$pdf->valueTitle("Graded Bedding: ", 25);

											if($content->graded_bedding_type!=""){
												$pdf->valueRow("Graded Bedding Type",$content->graded_bedding_type,30);
											}

											$pdf->valueTitle("Deformation Structures: ", 25);

											if($content->deformation_structures!=""){
												$pdf->valueRow("Deformation Structure Type",implode($content->deformation_structures, ", "),30);
											}

											if($content->other_deformation_structure_type!=""){
												$pdf->valueRow("Other Deformation Structure Type",$content->other_deformation_structure_type,30);
											}

											$pdf->valueTitle("Lags: ", 25);

											if($content->lag_type!=""){
												$pdf->valueRow("Lag Type",implode($content->lag_type, ", "),30);
											}

											if($content->other_lag_type!=""){
												$pdf->valueRow("Other Lag Type",$content->other_lag_type,30);
											}

											if($content->clast_composition!=""){
												$pdf->valueRow("Clast Composition",$content->clast_composition,30);
											}

											if($content->clast_size!=""){
												$pdf->valueRow("Clast Size",$content->clast_size,30);
											}

											if($content->layer_thickness_shape!=""){
												$pdf->valueRow("Layer Thickness/Shape",$content->layer_thickness_shape,30);
											}

											$pdf->valueTitle("Other Common Structures: ", 25);

											if($content->other_common_structures!=""){
												$pdf->valueRow("Other Common Structure Type",implode($content->other_common_structures, ", "),30);
											}

											if($content->bouma_sequence_part!=""){
												$pdf->valueRow("Bouma Sequence Part",implode($content->bouma_sequence_part, ", "),30);
											}

											if($content->bioturbation_index!=""){
												$pdf->valueRow("Bioturbation Index",$content->bioturbation_index,30);
											}

											if($content->bedding_plane_features!=""){
												$pdf->valueRow("Bedding plane features",implode($content->bedding_plane_features, ", "),30);
											}

											if($content->other_bedding_plane_feature!=""){
												$pdf->valueRow("Other Bedding Plane Feature",$content->other_bedding_plane_feature,30);
											}

											if($content->bedding_plane_features_scale!=""){
												$pdf->valueRow("Bedding Plane Features Scale",$content->bedding_plane_features_scale,30);
											}

											if($content->bedding_plane_features_orientation!=""){
												$pdf->valueRow("Bedding Plane Features Orientation",$content->bedding_plane_features_orientation,30);
											}

											if($content->bedform_type!=""){
												$pdf->valueRow("Bedform Type",implode($content->bedform_type, ", "),30);
											}

											if($content->other_bedform_type!=""){
												$pdf->valueRow("Other Bedform Type",$content->other_bedform_type,30);
											}

											if($content->bedform_scale!=""){
												$pdf->valueRow("Bedform Scale",$content->bedform_scale,30);
											}

											if($content->crest_orientation_azimuth_0_360!=""){
												$pdf->valueRow("Crest Orientation",$content->crest_orientation_azimuth_0_360,30);
											}

											$pdf->valueTitle("Pedogenic Structures: ", 25);

											if($content->paleosol_horizons!=""){
												$pdf->valueRow("Master Paleosol Horizons",implode($content->paleosol_horizons, ", "),30);
											}

											if($content->other_horizon!=""){
												$pdf->valueRow("Other Horizon",$content->other_horizon,30);
											}

											if($content->o_horizon_thickness_cm!=""){
												$pdf->valueRow("O Horizon thickness (cm)",$content->o_horizon_thickness_cm,30);
											}

											if($content->a_horizon_thickness_cm!=""){
												$pdf->valueRow("A Horizon thickness (cm)",$content->a_horizon_thickness_cm,30);
											}

											if($content->e_horizon_thickness_cm!=""){
												$pdf->valueRow("E Horizon thickness (cm)",$content->e_horizon_thickness_cm,30);
											}

											if($content->b_horizon_thickness_cm!=""){
												$pdf->valueRow("B Horizon thickness (cm)",$content->b_horizon_thickness_cm,30);
											}

											if($content->k_horizon_thickness_cm!=""){
												$pdf->valueRow("K Horizon thickness (cm)",$content->k_horizon_thickness_cm,30);
											}

											if($content->c_horizon_thickness_cm!=""){
												$pdf->valueRow("C Horizon thickness (cm)",$content->c_horizon_thickness_cm,30);
											}

											if($content->r_horizon_thickness_cm!=""){
												$pdf->valueRow("R Horizon thickness (cm)",$content->r_horizon_thickness_cm,30);
											}

											if($content->compound_thickness_cm!=""){
												$pdf->valueRow("Compound thickness (cm)",$content->compound_thickness_cm,30);
											}

											if($content->composite_thickness_cm!=""){
												$pdf->valueRow("Composite thickness (cm)",$content->composite_thickness_cm,30);
											}

											if($content->paleosol_structures!=""){
												$pdf->valueRow("Paleosol structures",implode($content->paleosol_structures, ", "),30);
											}

											if($content->other_structure!=""){
												$pdf->valueRow("Other Paleosol Structure",$content->other_structure,30);
											}

											if($content->additional_modifiers!=""){
												$pdf->valueRow("Additional modifiers",$content->additional_modifiers,30);
											}

											if($content->paleosol_classification!=""){
												$pdf->valueRow("Paleosol classification",implode($content->paleosol_classification, ", "),30);
											}

											if($content->notes!=""){
												$pdf->valueRow("Notes",$content->notes,30);
											}

										}

									}

									if($spot['sed']->diagenesis){
										$pdf->valueTitle("Diagenesis: ", 20);
										$contents = $spot['sed']->diagenesis;
										foreach($contents as $content){

											$pdf->valueTitle("Cement: ", 25);

											if($content->cement_composition!=""){
												$pdf->valueRow("Cement Mineralogy",implode($content->cement_composition, ", "),30);
											}

											if($content->other_cement_composition!=""){
												$pdf->valueRow("Other Cement Mineralogy",$content->other_cement_composition,30);
											}

											$pdf->valueTitle("Veins: ", 25);

											if($content->vein_type!=""){
												$pdf->valueRow("Vein Type",$content->vein_type,30);
											}

											if($content->vein_width!=""){
												$pdf->valueRow("Vein Width (cm)",$content->vein_width,30);
											}

											if($content->vein_length!=""){
												$pdf->valueRow("Vein Length (cm)",$content->vein_length,30);
											}

											if($content->vein_orientation!=""){
												$pdf->valueRow("Vein Orientation",$content->vein_orientation,30);
											}

											if($content->vein_mineralogy!=""){
												$pdf->valueRow("Vein Mineralogy",$content->vein_mineralogy,30);
											}

											if($content->other_vein_mineralogy!=""){
												$pdf->valueRow("Other Vein Mineralogy",$content->other_vein_mineralogy,30);
											}

											$pdf->valueTitle("Fractures: ", 25);

											if($content->fracture_type!=""){
												$pdf->valueRow("Fracture Type",$content->fracture_type,30);
											}

											if($content->fracture_width!=""){
												$pdf->valueRow("Fracture Width (cm)",$content->fracture_width,30);
											}

											if($content->fracture_length!=""){
												$pdf->valueRow("Fracture Length (cm)",$content->fracture_length,30);
											}

											if($content->fracture_orientation!=""){
												$pdf->valueRow("Fracture Orientation",$content->fracture_orientation,30);
											}

											if($content->fracture_mineralogy!=""){
												$pdf->valueRow("Fracture Mineralogy",$content->fracture_mineralogy,30);
											}

											if($content->other_fracture_mineralogy!=""){
												$pdf->valueRow("Other Fracture Mineralogy",$content->other_fracture_mineralogy,30);
											}

											$pdf->valueTitle("Nodules/Concretions: ", 25);

											if($content->nodules_concretions_size!=""){
												$pdf->valueRow("Nodules/Concretions Size",$content->nodules_concretions_size,30);
											}

											if($content->min!=""){
												$pdf->valueRow("Min",$content->min,30);
											}

											if($content->max!=""){
												$pdf->valueRow("Max",$content->max,30);
											}

											if($content->average!=""){
												$pdf->valueRow("Average",$content->average,30);
											}

											if($content->nodules_concretions_shape!=""){
												$pdf->valueRow("Nodules/Concretions Shape",implode($content->nodules_concretions_shape, ", "),30);
											}

											if($content->other_nodules_concretion_shape!=""){
												$pdf->valueRow("Other Nodule/Concretions Shape",$content->other_nodules_concretion_shape,30);
											}

											if($content->spacing!=""){
												$pdf->valueRow("Spacing",$content->spacing,30);
											}

											if($content->nodules_concretions_type!=""){
												$pdf->valueRow("Nodules/Concretions Type",$content->nodules_concretions_type,30);
											}

											if($content->other_nodules_concretions_type!=""){
												$pdf->valueRow("Other Nodules/Concretions Type",$content->other_nodules_concretions_type,30);
											}

											if($content->nodules_concretions_comp!=""){
												$pdf->valueRow("Nodules/Concretions Composition",implode($content->nodules_concretions_comp, ", "),30);
											}

											if($content->other_nodules_concretion_comp!=""){
												$pdf->valueRow("Other Nodules/Concretions Composition",$content->other_nodules_concretion_comp,30);
											}

											$pdf->valueTitle("Replacement: ", 25);

											if($content->replacement_type!=""){
												$pdf->valueRow("Replacement Type",$content->replacement_type,30);
											}

											if($content->other_replacement_type!=""){
												$pdf->valueRow("Other Replacement Type",$content->other_replacement_type,30);
											}

											$pdf->valueTitle("Recrystallization: ", 25);

											if($content->recrystallization_type!=""){
												$pdf->valueRow("Recrystallization Type",$content->recrystallization_type,30);
											}

											if($content->other_recrystallization_type!=""){
												$pdf->valueRow("Other Recrystallization Type",$content->other_recrystallization_type,30);
											}

											$pdf->valueTitle("Other Diagenetic Features: ", 25);

											if($content->other_diagenetic_features!=""){
												$pdf->valueRow("Other Diagenetic Features",implode($content->other_diagenetic_features, ", "),30);
											}

											if($content->other_features!=""){
												$pdf->valueRow("Other Features",$content->other_features,30);
											}

											$pdf->valueTitle("Porosity type: ", 25);

											if($content->fabric_selective!=""){
												$pdf->valueRow("Fabric Selective",implode($content->fabric_selective, ", "),30);
											}

											if($content->other_fabric_selective!=""){
												$pdf->valueRow("Other Fabric Selective",$content->other_fabric_selective,30);
											}

											if($content->non_selective!=""){
												$pdf->valueRow("Non-Frabric Selective",implode($content->non_selective, ", "),30);
											}

											if($content->other_non_selective!=""){
												$pdf->valueRow("Other Non-Fabric Selective",$content->other_non_selective,30);
											}

											$pdf->valueTitle("Carbonate Desiccation and Dissolution: ", 25);

											if($content->carbonate_desicc_and_diss!=""){
												$pdf->valueRow("Carbonate Desiccation and Dissolution Type",implode($content->carbonate_desicc_and_diss, ", "),30);
											}

											if($content->other_carbonate_desicc_diss!=""){
												$pdf->valueRow("Other Carbonate Desiccation and Dissolution Type",$content->other_carbonate_desicc_diss,30);
											}

											if($content->notes!=""){
												$pdf->valueRow("Notes",$content->notes,30);
											}

										}
									}

									if($spot['sed']->fossils){
										$pdf->valueTitle("Fossils: ", 20);
										$contents = $spot['sed']->fossils;
										foreach($contents as $content){

											$pdf->valueTitle("Body: ", 25);

											if($content->invertebrate!=""){
												$pdf->valueRow("Invertebrate",implode($content->invertebrate, ", "),30);
											}

											if($content->other_invertebrate!=""){
												$pdf->valueRow("Other Invertebrate",$content->other_invertebrate,30);
											}

											if($content->mollusca!=""){
												$pdf->valueRow("Mollusc",implode($content->mollusca, ", "),30);
											}

											if($content->other_mollusca!=""){
												$pdf->valueRow("Other Mollusc",$content->other_mollusca,30);
											}

											if($content->arthropoda!=""){
												$pdf->valueRow("Arthropod",implode($content->arthropoda, ", "),30);
											}

											if($content->other_anthropoda!=""){
												$pdf->valueRow("Other Arthropod",$content->other_anthropoda,30);
											}

											if($content->echinodermata!=""){
												$pdf->valueRow("Echinoderm",implode($content->echinodermata, ", "),30);
											}

											if($content->other_echinodermata!=""){
												$pdf->valueRow("Other Echinoderm",$content->other_echinodermata,30);
											}

											if($content->cnidaria!=""){
												$pdf->valueRow("Cnidarian",implode($content->cnidaria, ", "),30);
											}

											if($content->other_cnidaria!=""){
												$pdf->valueRow("Other Cnidarian",$content->other_cnidaria,30);
											}

											if($content->chordate!=""){
												$pdf->valueRow("Chordate",$content->chordate,30);
											}

											if($content->other_chordata!=""){
												$pdf->valueRow("Other Chordate",$content->other_chordata,30);
											}

											if($content->protista!=""){
												$pdf->valueRow("Protist",implode($content->protista, ", "),30);
											}

											if($content->other_protista!=""){
												$pdf->valueRow("Other Protist",$content->other_protista,30);
											}

											if($content->calcimicrobe!=""){
												$pdf->valueRow("Calcimicrobe",implode($content->calcimicrobe, ", "),30);
											}

											if($content->other_calcimicrobe!=""){
												$pdf->valueRow("Other Calcimicrobe",$content->other_calcimicrobe,30);
											}

											if($content->plant_algae!=""){
												$pdf->valueRow("Plant/algae",implode($content->plant_algae, ", "),30);
											}

											if($content->other_plant_algae!=""){
												$pdf->valueRow("Other Plant/Algae",$content->other_plant_algae,30);
											}

											if($content->green_algae!=""){
												$pdf->valueRow("Green Algae",implode($content->green_algae, ", "),30);
											}

											if($content->other_green_algae!=""){
												$pdf->valueRow("Other Green Algae",$content->other_green_algae,30);
											}

											if($content->vertebrate!=""){
												$pdf->valueRow("Vertebrate",implode($content->vertebrate, ", "),30);
											}

											if($content->other_vertebrate!=""){
												$pdf->valueRow("Other Vertebrate",$content->other_vertebrate,30);
											}

											if($content->faunal_assemblage!=""){
												$pdf->valueRow("Faunal assemblage",$content->faunal_assemblage,30);
											}

											if($content->other_faunal_assemblage!=""){
												$pdf->valueRow("Other faunal assemblage",$content->other_faunal_assemblage,30);
											}

											$pdf->valueTitle("Trace: ", 25);

											if($content->diversity!=""){
												$pdf->valueRow("Diversity",$content->diversity,30);
											}

											if($content->descriptive!=""){
												$pdf->valueRow("Descriptive",implode($content->descriptive, ", "),30);
											}

											if($content->other_descriptive!=""){
												$pdf->valueRow("Other Descriptive",$content->other_descriptive,30);
											}

											if($content->burrow_fill_type!=""){
												$pdf->valueRow("Burrow Fill Type",implode($content->burrow_fill_type, ", "),30);
											}

											if($content->other_burrow_fill!=""){
												$pdf->valueRow("Other Burrow Fill Type",$content->other_burrow_fill,30);
											}

											if($content->behavioral_grouping!=""){
												$pdf->valueRow("Behavioral grouping",$content->behavioral_grouping,30);
											}

											if($content->other_behavioral_grouping!=""){
												$pdf->valueRow("Other Behavioral Grouping",$content->other_behavioral_grouping,30);
											}

											if($content->ichnofacies!=""){
												$pdf->valueRow("Ichnofacies",$content->ichnofacies,30);
											}

											if($content->other_ichnofacies!=""){
												$pdf->valueRow("Other Ichnofacies",$content->other_ichnofacies,30);
											}

											if($content->list_of_specific_types!=""){
												$pdf->valueRow("List of specific types",$content->list_of_specific_types,30);
											}

											$pdf->valueTitle("Biogenic Growth Structures: ", 25);

											if($content->dominant_component!=""){
												$pdf->valueRow("Dominant component",$content->dominant_component,30);
											}

											if($content->other_dominant_component!=""){
												$pdf->valueRow("Other Dominant Component",$content->other_dominant_component,30);
											}

											if($content->microbial_reef_or_skelatal_mic!=""){
												$pdf->valueRow("Microbial Reef or Skeletal-Microbial Reef Type",implode($content->microbial_reef_or_skelatal_mic, ", "),30);
											}

											if($content->other_microbial_or_skeletal_mi!=""){
												$pdf->valueRow("Other Microbial or Skeletal Microbial Reef",$content->other_microbial_or_skeletal_mi,30);
											}

											if($content->mud_mound!=""){
												$pdf->valueRow("Mud Mound Type",implode($content->mud_mound, ", "),30);
											}

											if($content->other_mud_mound!=""){
												$pdf->valueRow("Other Mud Mound",$content->other_mud_mound,30);
											}

											$pdf->valueTitle("Biogenic Growth Structure Scale and Orientation: ", 25);

											if($content->height!=""){
												$pdf->valueRow("Height",$content->height,30);
											}

											if($content->width!=""){
												$pdf->valueRow("Width",$content->width,30);
											}

											if($content->shape!=""){
												$pdf->valueRow("Shape",$content->shape,30);
											}

											if($content->type!=""){
												$pdf->valueRow("Type",$content->type,30);
											}

											if($content->other_type!=""){
												$pdf->valueRow("Other Type",$content->other_type,30);
											}

											if($content->accessory_structures!=""){
												$pdf->valueRow("Accessory Structures",implode($content->accessory_structures, ", "),30);
											}

											if($content->other_accessory_structure!=""){
												$pdf->valueRow("Other Accessory Structure",$content->other_accessory_structure,30);
											}

											if($content->notes!=""){
												$pdf->valueRow("Notes",$content->notes,30);
											}
										}
									}

									if($spot['sed']->interpretations){
										$pdf->valueTitle("Interpretations: ", 20);
										$contents = $spot['sed']->interpretations;
										foreach($contents as $content){

											$pdf->valueTitle("Process Interpretation: ", 25);

											if($content->energy!=""){
												$pdf->valueRow("Energy",$content->energy,30);
											}

											if($content->other_energy!=""){
												$pdf->valueRow("Other Energy",$content->other_energy,30);
											}

											if($content->sediment_transport!=""){
												$pdf->valueRow("Sediment Transport",implode($content->sediment_transport, ", "),30);
											}

											if($content->other_sediment_transport!=""){
												$pdf->valueRow("Other Sediment Transport",$content->other_sediment_transport,30);
											}

											if($content->fluidization!=""){
												$pdf->valueRow("Fluidization",$content->fluidization,30);
											}

											if($content->other_fluidization!=""){
												$pdf->valueRow("Other Fluidization",$content->other_fluidization,30);
											}

											if($content->miscellaneous!=""){
												$pdf->valueRow("Miscellaneous",implode($content->miscellaneous, ", "),30);
											}

											if($content->other_miscellaneous!=""){
												$pdf->valueRow("Other Misc. Process",$content->other_miscellaneous,30);
											}

											$pdf->valueTitle("Environment Interpretation: ", 25);

											if($content->general!=""){
												$pdf->valueRow("General",implode($content->general, ", "),30);
											}

											if($content->clastic!=""){
												$pdf->valueRow("Clastic",implode($content->clastic, ", "),30);
											}

											if($content->alluvial_fan_environments!=""){
												$pdf->valueRow("Alluvial fan environments",implode($content->alluvial_fan_environments, ", "),30);
											}

											if($content->other_alluvial_fan!=""){
												$pdf->valueRow("Other Alluvial Fan",$content->other_alluvial_fan,30);
											}

											if($content->eolian_environments!=""){
												$pdf->valueRow("Eolian environments",implode($content->eolian_environments, ", "),30);
											}

											if($content->other_eolian!=""){
												$pdf->valueRow("Other Eolian",$content->other_eolian,30);
											}

											if($content->fluvial_environments!=""){
												$pdf->valueRow("Fluvial environments",implode($content->fluvial_environments, ", "),30);
											}

											if($content->other_fluvial!=""){
												$pdf->valueRow("Other Fluvial",$content->other_fluvial,30);
											}

											if($content->shallow_marine_clastic_environ!=""){
												$pdf->valueRow("Shallow marine clastic environments",implode($content->shallow_marine_clastic_environ, ", "),30);
											}

											if($content->other_shallow_marine!=""){
												$pdf->valueRow("Other Shallow Marine",$content->other_shallow_marine,30);
											}

											if($content->deep_marine_environments!=""){
												$pdf->valueRow("Deep marine environments",implode($content->deep_marine_environments, ", "),30);
											}

											if($content->other_deep_marine!=""){
												$pdf->valueRow("Other Deep Marine",$content->other_deep_marine,30);
											}

											if($content->glacial_and_proglacial_environ!=""){
												$pdf->valueRow("Glacial and proglacial environments",implode($content->glacial_and_proglacial_environ, ", "),30);
											}

											if($content->other_glacial!=""){
												$pdf->valueRow("Other Glacial",$content->other_glacial,30);
											}

											if($content->lake_environments!=""){
												$pdf->valueRow("Lake environments",implode($content->lake_environments, ", "),30);
											}

											if($content->other_lake!=""){
												$pdf->valueRow("Other Lake",$content->other_lake,30);
											}

											if($content->other_clastic!=""){
												$pdf->valueRow("Other Clastic",$content->other_clastic,30);
											}

											if($content->carbonates!=""){
												$pdf->valueRow("Carbonates",implode($content->carbonates, ", "),30);
											}

											if($content->factory!=""){
												$pdf->valueRow("Factory",implode($content->factory, ", "),30);
											}

											if($content->carbonate!=""){
												$pdf->valueRow("Environment",implode($content->carbonate, ", "),30);
											}

											if($content->other_carbonate_environment!=""){
												$pdf->valueRow("Other Carbonate Environment",$content->other_carbonate_environment,30);
											}

											if($content->lake_subenvironments!=""){
												$pdf->valueRow("Lake Subenvironments",implode($content->lake_subenvironments, ", "),30);
											}

											if($content->other_carbonate_lake_subenvironment!=""){
												$pdf->valueRow("Other Carbonate Lake Subenvironment",$content->other_carbonate_lake_subenvironment,30);
											}

											if($content->tidal_flat_subenvironments!=""){
												$pdf->valueRow("Tidal Flat Subenvironments",implode($content->tidal_flat_subenvironments, ", "),30);
											}

											if($content->other_tidal_flat!=""){
												$pdf->valueRow("Other Tidal Flat",$content->other_tidal_flat,30);
											}

											if($content->reef_subenvironments!=""){
												$pdf->valueRow("Reef Subenvironments",implode($content->reef_subenvironments, ", "),30);
											}

											if($content->other_reef!=""){
												$pdf->valueRow("Other Reef",$content->other_reef,30);
											}

											if($content->detailed_carbonate_env_interpr!=""){
												$pdf->valueRow("Detailed carbonate environmental interpretations",$content->detailed_carbonate_env_interpr,30);
											}

											if($content->tectonic_setting!=""){
												$pdf->valueRow("Tectonic Setting",implode($content->tectonic_setting, ", "),30);
											}

											if($content->other_tectonic_setting!=""){
												$pdf->valueRow("Other Tectonic Setting",$content->other_tectonic_setting,30);
											}

											$pdf->valueTitle("Sedimentary Surfaces (for line spots only): ", 25);

											if($content->geometry!=""){
												$pdf->valueRow("Geometry",$content->geometry,30);
											}

											if($content->relief!=""){
												$pdf->valueRow("Relief",$content->relief,30);
											}

											if($content->relief_scale!=""){
												$pdf->valueRow("Relief Scale",$content->relief_scale,30);
											}

											if($content->extent!=""){
												$pdf->valueRow("Extent",$content->extent,30);
											}

											if($content->extent_scale!=""){
												$pdf->valueRow("Extent Scale",$content->extent_scale,30);
											}

											if($content->type!=""){
												$pdf->valueRow("Type",implode($content->type, ", "),30);
											}

											if($content->other_type!=""){
												$pdf->valueRow("Other Type",$content->other_type,30);
											}

											if($content->stratal_termination!=""){
												$pdf->valueRow("Stratal Termination",$content->stratal_termination,30);
											}

											$pdf->valueTitle("Sedimentary Surface Interpretation: ", 25);

											if($content->general_surfaces!=""){
												$pdf->valueRow("General Surfaces",$content->general_surfaces,30);
											}

											if($content->sequence_stratigraphic_surfaces!=""){
												$pdf->valueRow("Sequence Stratigraphic Surfaces",$content->sequence_stratigraphic_surfaces,30);
											}

											if($content->other_sequence_stratigraphic_surface!=""){
												$pdf->valueRow("Other Sequence Stratigraphic Surface",$content->other_sequence_stratigraphic_surface,30);
											}

											if($content->named!=""){
												$pdf->valueRow("Named",$content->named,30);
											}

											$pdf->valueTitle("Architecture Interpretation: ", 25);

											if($content->description!=""){
												$pdf->valueRow("Description",implode($content->description, ", "),30);
											}

											if($content->stacking_sequence_stratigraphy!=""){
												$pdf->valueRow("Stacking/Sequence Stratigraphy",implode($content->stacking_sequence_stratigraphy, ", "),30);
											}

											if($content->other_stacking_sequence_stratigraphy!=""){
												$pdf->valueRow("Other Stacking/Sequence Stratigraphy",$content->other_stacking_sequence_stratigraphy,30);
											}

											if($content->fluvial_architectural_elements!=""){
												$pdf->valueRow("Fluvial Architectural Elements",implode($content->fluvial_architectural_elements, ", "),30);
											}

											if($content->other_fluvial_element!=""){
												$pdf->valueRow("Other Fluvial Element",$content->other_fluvial_element,30);
											}

											if($content->lacustrine_architecture_interpretation!=""){
												$pdf->valueRow("Lacustrine Architecture Interpretation",implode($content->lacustrine_architecture_interpretation, ", "),30);
											}

											if($content->other_lacustrine_architecture_interpretation!=""){
												$pdf->valueRow("Other Lacustrine Architecture Interpretation",$content->other_lacustrine_architecture_interpretation,30);
											}

											if($content->carbonate_platform_geometry!=""){
												$pdf->valueRow("Carbonate Platform Geometry",implode($content->carbonate_platform_geometry, ", "),30);
											}

											if($content->other_platform_geometry!=""){
												$pdf->valueRow("Other Platform Geometry",$content->other_platform_geometry,30);
											}

											if($content->deep_water_architctural_element!=""){
												$pdf->valueRow("Deep-Water Architectural Elements",implode($content->deep_water_architctural_element, ", "),30);
											}

											if($content->other_deep_water_architectural_element!=""){
												$pdf->valueRow("Other Deep-Water Architectural Element",$content->other_deep_water_architectural_element,30);
											}

											if($content->carbonate_margin_geometry!=""){
												$pdf->valueRow("Carbonate Margin Geometry",implode($content->carbonate_margin_geometry, ", "),30);
											}

											if($content->other_carbonate_margin_geometry!=""){
												$pdf->valueRow("Other Carbonate Margin Geometry",$content->other_carbonate_margin_geometry,30);
											}

											if($content->notes!=""){
												$pdf->valueRow("Notes",$content->notes,30);
											}

										}
									}

								}

								if($spot['pet']){
									if($spot['pet']->metamorphic){
										$pdf->valueTitle("Metamorphic Rock(s): ", 15);
										$rocks = $spot['pet']->metamorphic;

										$rockNum = 1;
										foreach($rocks as $r){
											$rockString = "";
											$rockString .= ", " . $r->metamorphic_rock_type;
											$rockString .= ", " . implode(", ", $r->facies);
											$rockString .= ", " . $r->protolith;
											$rockString .= ", " . implode(", ", $r->zone);

											$pdf->valueRow($rockNum, $rockString, 20);
											$rockNum++;
										}

										if($content->section_well_name!=""){
											$pdf->valueRow("Section/Well Name",$content->section_well_name,25);
										}
									}

									if($spot['pet']->igneous){
										$pdf->valueTitle("Igneous Rock(s): ", 15);
										$rocks = $spot['pet']->igneous;

										$rockNum = 1;
										foreach($rocks as $r){
											$rockString = "";
											if($r->igneous_rock_class) $rockString .= ", " . $r->igneous_rock_class;
											if($r->volcanic_rock_type) $rockString .= ", " . $r->volcanic_rock_type;
											if($r->occurence_volcanic) $rockString .= ", " . $r->occurence_volcanic;
											if($r->plutonic_rock_type) $rockString .= ", " . $r->plutonic_rock_type;
											if($r->occurence_plutonic) $rockString .= ", " . $r->occurence_plutonic;
											if($r->texture_volcanic) $rockString .= ", " . implode(", ", $r->texture_volcanic);
											if($r->texture_plutonic) $rockString .= ", " . implode(", ", $r->texture_plutonic);
											if($r->color_index_volc) $rockString .= ", " . $r->color_index_volc;
											if($r->color_index_pluton) $rockString .= ", " . $r->color_index_pluton;
											if($r->color_index_source_volc) $rockString .= ", " . $r->color_index_source_volc;
											if($r->color_index_source_pluton) $rockString .= ", " . $r->color_index_source_pluton;
											if($r->alteration_volcanic) $rockString .= ", " . implode(", ", $r->alteration_volcanic);
											if($r->alteration_plutonic) $rockString .= ", " . implode(", ", $r->alteration_plutonic);

											$pdf->valueRow($rockNum, $rockString, 20);
											$rockNum++;
										}

										if($content->section_well_name!=""){
											$pdf->valueRow("Section/Well Name",$content->section_well_name,25);
										}
									}

									if($spot['pet']->minerals){
										$pdf->valueTitle("Mineral(s): ", 15);
										$rocks = $spot['pet']->minerals;

										$rockNum = 1;
										foreach($rocks as $r){
											$rockString = "";
											if($r->full_mineral_name) $rockString .= ", " . $r->full_mineral_name;
											if($r->igneous_or_metamorphic == "ig_min"){
												$rockString .= " (Igneous)";
											}else{
												$rockString .= " (Metamorphic)";
											}
											if($r->average_grain_size_mm) $rockString .= ", Avg Size: " . $r->average_grain_size_mm . "mm";
											if($r->maximum_grain_size_mm) $rockString .= ", Max Size: " . $r->maximum_grain_size_mm . "mm";
											if($r->modal) $rockString .= ", Modal: " . $r->modal . "%";
											if($r->mineral_notes) $rockString .= " " . $r->mineral_notes;

											$pdf->valueRow($rockNum, $rockString, 20);
											$rockNum++;
										}

										if($content->section_well_name!=""){
											$pdf->valueRow("Section/Well Name",$content->section_well_name,25);
										}
									}

								}

								if($spot['images']){

									$pdf->valueRow("Images","",15);
									$pdf->Ln(1);
									foreach($spot['images'] as $o){
										if($o['title']){
											$thistitle = $this->fixLabel($o['title']);
											$pdf->valueTitle($thistitle.": ",20);
										}else{
											$thistitle = $o['id'];
										}

										foreach($o as $key=>$value){
											if($value != ""){
												if($key!="id" && $key!="self" && $key!="annotated" && $key!="title" && $key!="width" && $key!="height" && $key!="image_type" && $key!="caption" ){
													$key = $this->fixLabel($key);
													if(is_string($value)){
														$value = $this->fixLabel($value);
													}
													$pdf->valueRow($key,$value,20);
												}
											}
										}

										$pdf->Ln(1);

										$filename = $this->strabo->getImageFilename($o['id']);

										if($filename){
											$gdimage = $this->gdThumb($filename);
											if($gdimage){
												$pdf->GDImage($gdimage, 20, null, 60);
											}
										}

										if($o['caption'] != ""){
											$pdf->imageCaptionRow("Caption", $o['caption'], 20);
										}

										$pdf->Ln(1);
										$pdf->Ln(1);
										$pdf->Ln(1);
										$pdf->Ln(1);
										$pdf->Ln(1);

									}
								}

								$pdf->Ln(10);

							}else{ //end if date matches
							}

						}//end foreach spots

					}// end foreach spotdates

					$filedate = date("m_d_Y");
					$pdfname="StraboSpot_Field_Book_$filedate.pdf";
					$pdf->Output($pdfname,"D"); //Download

				}else{

					echo "No spots found for this search.";

				}

			} //end if dsids

		}

	public function oldfieldbookOut20250630(){

			if($this->get['dsids']!=""){

				$dsids=$this->get['dsids'];
				$this->alltags = $this->strabo->getTagsFromDatasetIds($dsids);

				$json = $this->strabo->getDatasetSpotsSearch(null,$this->get);

				$dsname = $this->strabo->getDatasetName($dsids);

				$dailynotes = $this->strabo->getDailyNotesFromDatasetID($dsids);

				$spots = $json['features'];

				//unix time 1567364734  10 digits
				//id 15609769344266     14 digits

				//get day for each spot

				//create date array to loop over

				if(count($spots)>0){

					$spotdates = [];
					$founddates = [];
					foreach($spots as $spot){

						if($spot['properties']['image_basemap'] == ""){

							$ut = substr($spot['properties']['id'], 0 ,10);

							$datestring = date("l, F j, Y", $ut);

							if(!in_array($datestring, $founddates)){
								$founddates[] = $datestring;
								$showformat = date("l, F j, Y", $ut);
								$lookformat = date("Y-m-d", $ut);
								$thisobj = new stdClass();
								$thisobj->showformat = $showformat;
								$thisobj->lookformat = $lookformat;
								$spotdates[] = $thisobj;
							}

						}

					}

					require('includes/PDF_LabBook.php');

					$pdf = new PDF_MemImage('P','mm','Letter');

					$pdf->AddFont('msjh','','msjh.ttf',true);

					$pdf->AddPage();

					if($dsname != ""){
						$pdf->datasetTitle($dsname);
					}

					foreach($spotdates as $spotdate){

						$showdate = trim($spotdate->showformat);
						$pdf->dayTitle($showdate);

						//look for daily notes (format in data is YYYY-MM-DD)
						$lookdate = $spotdate->lookformat;

						if($dailynotes!=""){
							foreach($dailynotes as $dn){
								$dndate = substr($dn->date,0,10);

								if($dndate == $lookdate){
									$pdf->dailyNotesRow("Daily Setup Notes",$dn->notes,10);
								}
							}
						}

						foreach($spots as $spot){

							$rawspot = $spot;

							$spot = $spot['properties'];

							$id = $spot['id'];

							$ut = substr($id, 0 ,10);

							$thisspotdate = date("l, F j, Y", $ut);

							if($thisspotdate == $showdate && $spot['image_basemap'] == ""){

								$this->addSpotToPDF($pdf, $spot, $spots, 5);

							}else{ //end if date matches
							}

						}//end foreach spots

					}// end foreach spotdates

					$filedate = date("m_d_Y");
					$pdfname="StraboSpot_Field_Book_$filedate.pdf";
					$pdf->Output($pdfname,"I");

				}else{

					echo "No spots found for this search.";

				}

			} //end if dsids

		}

	public function devfieldbookOut(){

			if($this->get['dsids']!=""){

				$dsids=$this->get['dsids'];
				$this->alltags = $this->strabo->getTagsFromDatasetIds($dsids);

				$json = $this->strabo->getDatasetSpotsSearch(null,$this->get);

				$dsname = $this->strabo->getDatasetName($dsids);

				$dailynotes = $this->strabo->getDailyNotesFromDatasetID($dsids);

				$spots = $json['features'];

				//unix time 1567364734  10 digits
				//id 15609769344266     14 digits

				//get day for each spot

				//create date array to loop over

				if(count($spots)>0){

					$spotdates = [];
					$founddates = [];
					foreach($spots as $spot){

						if($spot['properties']['image_basemap'] == ""){

							$ut = substr($spot['properties']['id'], 0 ,10);

							$datestring = date("l, F j, Y", $ut);

							if(!in_array($datestring, $founddates)){
								$founddates[] = $datestring;
								$showformat = date("l, F j, Y", $ut);
								$lookformat = date("Y-m-d", $ut);
								$thisobj = new stdClass();
								$thisobj->showformat = $showformat;
								$thisobj->lookformat = $lookformat;
								$spotdates[] = $thisobj;
							}

						}

					}

					require('includes/PDF_LabBook.php');

					$pdf = new PDF_MemImage('P','mm','Letter');

					$pdf->AddFont('msjh','','msjh.ttf',true);

					$pdf->AddPage();

					if($dsname != ""){
						$pdf->datasetTitle($dsname);
					}

					foreach($spotdates as $spotdate){

						$showdate = trim($spotdate->showformat);
						$pdf->dayTitle($showdate);

						//look for daily notes (format in data is YYYY-MM-DD)
						$lookdate = $spotdate->lookformat;

						if($dailynotes!=""){
							foreach($dailynotes as $dn){
								$dndate = substr($dn->date,0,10);

								if($dndate == $lookdate){
									$pdf->dailyNotesRow("Daily Setup Notes",$dn->notes,10);
								}
							}
						}

						foreach($spots as $spot){

							$rawspot = $spot;

							$id = $spot['properties']['id'];

							$ut = substr($id, 0 ,10);

							$thisspotdate = date("l, F j, Y", $ut);

							if($thisspotdate == $showdate && $spot['image_basemap'] == ""){

								$this->devaddSpotToPDF($pdf, $spot, $spots, 5);

							}else{ //end if date matches
							}

						}//end foreach spots

					}// end foreach spotdates

					$filedate = date("m_d_Y");
					$pdfname="StraboSpot_Field_Book_$filedate.pdf";
					$pdf->Output($pdfname,"I");

				}else{

					echo "No spots found for this search.";

				}

			} //end if dsids

		}

	public function fieldbookOut(){

			if($this->get['dsids']!=""){

				$dsids=$this->get['dsids'];
				$this->alltags = $this->strabo->getTagsFromDatasetIds($dsids);

				$json = $this->strabo->getDatasetSpotsSearch(null,$this->get);

				$dsname = $this->strabo->getDatasetName($dsids);

				$dailynotes = $this->strabo->getDailyNotesFromDatasetID($dsids);

				$spots = $json['features'];

				//unix time 1567364734  10 digits
				//id 15609769344266     14 digits

				//get day for each spot

				//create date array to loop over

				if(count($spots)>0){

					$spotdates = [];
					$founddates = [];
					foreach($spots as $spot){

						if($spot['properties']['image_basemap'] == ""){

							$ut = substr($spot['properties']['id'], 0 ,10);

							$datestring = date("l, F j, Y", $ut);

							if(!in_array($datestring, $founddates)){
								$founddates[] = $datestring;
								$showformat = date("l, F j, Y", $ut);
								$lookformat = date("Y-m-d", $ut);
								$thisobj = new stdClass();
								$thisobj->showformat = $showformat;
								$thisobj->lookformat = $lookformat;
								$spotdates[] = $thisobj;
							}

						}

					}

					require('includes/PDF_LabBook.php');

					$pdf = new PDF_MemImage('P','mm','Letter');

					$pdf->AddFont('msjh','','msjh.ttf',true);

					$pdf->AddPage();

					if($dsname != ""){
						$pdf->datasetTitle($dsname);
					}

					foreach($spotdates as $spotdate){

						$showdate = trim($spotdate->showformat);
						$pdf->dayTitle($showdate);

						//look for daily notes (format in data is YYYY-MM-DD)
						$lookdate = $spotdate->lookformat;

						if($dailynotes!=""){
							foreach($dailynotes as $dn){
								$dndate = substr($dn->date,0,10);

								if($dndate == $lookdate){
									$pdf->dailyNotesRow("Daily Setup Notes",$dn->notes,10);
								}
							}
						}

						foreach($spots as $spot){

							$rawspot = $spot;

							$id = $spot['properties']['id'];

							$ut = substr($id, 0 ,10);

							$thisspotdate = date("l, F j, Y", $ut);

							if($thisspotdate == $showdate && $spot['image_basemap'] == ""){

								$this->addSpotToPDF($pdf, $spot, $spots, 5);

							}else{ //end if date matches
							}

						}//end foreach spots

					}// end foreach spotdates

					$filedate = date("m_d_Y");
					$pdfname="StraboSpot_Field_Book_$filedate.pdf";
					$pdf->Output($pdfname,"I");

				}else{

					echo "No spots found for this search.";

				}

			} //end if dsids

		}

	public function olddevfieldbookOut(){

			if($this->get['dsids']!=""){

				$dsids=$this->get['dsids'];
				$this->alltags = $this->strabo->getTagsFromDatasetIds($dsids);

				$json = $this->strabo->getDatasetSpotsSearch(null,$this->get);

				$dsname = $this->strabo->getDatasetName($dsids);

				$dailynotes = $this->strabo->getDailyNotesFromDatasetID($dsids);

				$spots = $json['features'];

				//unix time 1567364734  10 digits
				//id 15609769344266     14 digits

				//get day for each spot

				//create date array to loop over

				if(count($spots)>0){

					$spotdates = [];
					$founddates = [];
					foreach($spots as $spot){

						if($spot['properties']['image_basemap'] == ""){

							$ut = substr($spot['properties']['id'], 0 ,10);

							$datestring = date("l, F j, Y", $ut);

							if(!in_array($datestring, $founddates)){
								$founddates[] = $datestring;
								$showformat = date("l, F j, Y", $ut);
								$lookformat = date("Y-m-d", $ut);
								$thisobj = new stdClass();
								$thisobj->showformat = $showformat;
								$thisobj->lookformat = $lookformat;
								$spotdates[] = $thisobj;
							}

						}

					}

					require('includes/PDF_LabBook.php');

					$pdf = new PDF_MemImage('P','mm','Letter');

					$pdf->AddFont('msjh','','msjh.ttf',true);

					$pdf->AddPage();

					if($dsname != ""){
						$pdf->datasetTitle($dsname);
					}

					foreach($spotdates as $spotdate){

						$showdate = trim($spotdate->showformat);
						$pdf->dayTitle($showdate);

						//look for daily notes (format in data is YYYY-MM-DD)
						$lookdate = $spotdate->lookformat;

						if($dailynotes!=""){
							foreach($dailynotes as $dn){
								$dndate = substr($dn->date,0,10);

								if($dndate == $lookdate){
									$pdf->dailyNotesRow("Daily Setup Notes",$dn->notes,10);
								}
							}
						}

						foreach($spots as $spot){

							$rawspot = $spot;

							$spot = $spot['properties'];

							$id = $spot['id'];

							$ut = substr($id, 0 ,10);

							$thisspotdate = date("l, F j, Y", $ut);

							if($thisspotdate == $showdate && $spot['image_basemap'] == ""){

								$this->addSpotToPDF($pdf, $spot, $spots, 5);

							}else{ //end if date matches
							}

						}//end foreach spots

					}// end foreach spotdates

					$filedate = date("m_d_Y");
					$pdfname="StraboSpot_Field_Book_$filedate.pdf";

					$pdf->Output("/srv/app/www/ziptemp/".$pdfname,'F');

				}else{

					echo "No spots found for this search.";

				}

			} //end if dsids

		}

	public function oldfieldbookOut20240610(){

			if($this->get['dsids']!=""){

				$dsids=$this->get['dsids'];
				$this->alltags = $this->strabo->getTagsFromDatasetIds($dsids);

				$json = $this->strabo->getDatasetSpotsSearch(null,$this->get);

				$dsname = $this->strabo->getDatasetName($dsids);

				$dailynotes = $this->strabo->getDailyNotesFromDatasetID($dsids);

				$spots = $json['features'];

				//unix time 1567364734  10 digits
				//id 15609769344266     14 digits

				//get day for each spot

				//create date array to loop over

				if(count($spots)>0){

					$spotdates = [];
					$founddates = [];
					foreach($spots as $spot){

						$ut = substr($spot['properties']['id'], 0 ,10);

						$datestring = date("l, F j, Y", $ut);

						if(!in_array($datestring, $founddates)){
							$founddates[] = $datestring;
							$showformat = date("l, F j, Y", $ut);
							$lookformat = date("Y-m-d", $ut);
							$thisobj = new stdClass();
							$thisobj->showformat = $showformat;
							$thisobj->lookformat = $lookformat;
							$spotdates[] = $thisobj;
						}

					}

					require('includes/PDF_LabBook.php');

					$pdf = new PDF_MemImage('P','mm','Letter');

					$pdf->AddFont('msjh','','msjh.ttf',true);

					$pdf->AddPage();

					if($dsname != ""){
						$pdf->datasetTitle($dsname);
					}

					foreach($spotdates as $spotdate){

						$showdate = $spotdate->showformat;
						$pdf->dayTitle($showdate);

						//look for daily notes (format in data is YYYY-MM-DD)
						$lookdate = $spotdate->lookformat;

						if($dailynotes!=""){
							foreach($dailynotes as $dn){
								$dndate = substr($dn->date,0,10);

								if($dndate == $lookdate){
									$pdf->dailyNotesRow("Daily Setup Notes",$dn->notes,10);
								}
							}
						}

						foreach($spots as $spot){

							$rawspot = $spot;

							$spot = $spot['properties'];

							$id = $spot['id'];

							$ut = substr($id, 0 ,10);

							$thisspotdate = date("l, F j, Y", $ut);

							if($thisspotdate == $spotdate->showformat){

								$spotname = $spot['name'];
								if($spot['geometrytype']){
									$spotname .= " (".$spot['geometrytype'].")";
								}

								$pdf->spotTitle($spotname);

								$modified = (string) $spot['id'];
								$modified = substr($modified,0,10);
								$modified = date("F j, Y",$modified);
								$pdf->valueRow("Created",$modified,15);

								$modified = (string) $spot['modified_timestamp'];
								$modified = substr($modified,0,10);
								$modified = date("F j, Y",$modified);
								$pdf->valueRow("Last Modified",$modified,15);

								if($rawspot['geometry']->type=="Point" && $rawspot['properties']['strat_section_id']==""){
									$pdf->valueRow("Longitude",$rawspot['geometry']->coordinates[0],15);
									$pdf->valueRow("Latitude",$rawspot['geometry']->coordinates[1],15);
								}else{
								}

								if($spot['notes']){
									$notes = $spot['notes'];
									$pdf->notesRow("Notes",$notes,15);
								}

								if($spot['surface_feature']){
									foreach($spot['surface_feature'] as $key=>$value){
										$key = $this->fixLabel($key);
										if(is_string($value)){
											$value = $this->fixLabel($value);
										}
										$pdf->valueRow($key,$value,15);
									}
								}

								if($spot['orientation_data']){
									$pdf->valueRow("Orientations","",15);
									foreach($spot['orientation_data'] as $o){
										$pdf->valueTitle($this->fixLabel($o->type).": ",20);
										foreach($o as $key=>$value){
											if($key!="id" && $key!="associated_orientation" && $key!="type"){
												$key = $this->fixLabel($key);
												if(is_string($value)){
													$value = $this->fixLabel($value);
												}
												$pdf->valueRow($key,$value,20);
											}
										}

										if($o->associated_orientation){
											$pdf->valueRow("Associated Orientation Data","",20);
											foreach($o->associated_orientation as $ao){
												$pdf->valueTitle($this->fixLabel($ao->type).": ",30);
												foreach($ao as $key=>$value){
													if($key!="id" && $key!="associated_orientation" && $key!="type"){
														$key = $this->fixLabel($key);
														if(is_string($value)){
															$value = $this->fixLabel($value);
														}
														$pdf->valueRow($key,$value,30);
													}
												}
												$pdf->Ln(1);
											}
										}

										$pdf->Ln(1);
									}
								}

								if($spot['_3d_structures']){
									$pdf->valueRow("3D Structures","",15);
									foreach($spot['_3d_structures'] as $o){
										$pdf->valueTitle($this->fixLabel($o->type).": ",20);
										foreach($o as $key=>$value){
											if($key!="id" && $key!="type"){
												$key = $this->fixLabel($key);
												if(is_string($value)){
													$value = $this->fixLabel($value);
												}
												$pdf->valueRow($key,$value,20);
											}
										}

										$pdf->Ln(1);
									}
								}

								if($spot['samples']){
									$pdf->valueRow("Samples","",15);
									foreach($spot['samples'] as $o){
										$pdf->valueTitle($this->fixLabel($o->label).": ",20);
										foreach($o as $key=>$value){
											if($key!="id" && $key!="label"){
												$key = $this->fixLabel($key);
												if(is_string($value)){
													$value = $this->fixLabel($value);
												}
												$pdf->valueRow($key,$value,20);
											}
										}

										$pdf->Ln(1);
									}
								}

								if($spot['tephra']){
									$pdf->valueRow("Tephra Intervals","",15);
									foreach($spot['tephra'] as $o){
										$pdf->valueTitle("Interval: ",20);
										foreach($o as $key=>$value){
											if($key!="id" && $key!="label"){
												$key = $this->fixLabel($key);
												if(is_array($value)){
													$value = implode(", ", $value);
												}elseif(is_string($value)){
													$value = $this->fixLabel($value);
												}
												$pdf->valueRow($key,$value,20);
											}
										}

										$pdf->Ln(1);
									}
								}

								if($spot['other_features']){
									$pdf->valueRow("Other Features","",15);
									foreach($spot['other_features'] as $o){
										$pdf->valueTitle($this->fixLabel($o->label).": ",20);
										foreach($o as $key=>$value){
											if($key!="id" && $key!="label"){
												$key = $this->fixLabel($key);
												if(is_string($value)){
													$value = $this->fixLabel($value);
												}
												$pdf->valueRow($key,$value,20);
											}
										}

										$pdf->Ln(1);
									}
								}

								$hastags = "no";

								if($this->alltags){
									foreach($this->alltags as $tag){
										$found = "no";
										if($tag->spots){
											if($tag->type!="geologic_unit"){
												foreach($tag->spots as $spotid){
													if($spotid == $id){
														$hastags = "yes";
													}
												}
											}
										}

									}
								}

								if($hastags == "yes"){

									$pdf->valueRow("Tags","",15);

									if($this->alltags){
										foreach($this->alltags as $tag){
											$found = "no";
											if($tag->spots){
												if($tag->type!="geologic_unit"){
													foreach($tag->spots as $spotid){
														if($spotid == $id){
															$found = "yes";
														}
													}
												}
											}

											if($found == "yes"){

												$pdf->valueTitle($tag->name,20);
												foreach($tag as $key=>$value){

													if($key != "date" && $key != "spots" && $key != "features" && $key != "id" && $key != "name" ){
														$key = $this->fixLabel($key);
														if(is_string($value)){
															$value = $this->fixLabel($value);
														}elseif(is_array($value)){
															$value = implode(", ", $value);
														}
														$pdf->valueRow($key,$value,20);
													}

												}

												$pdf->Ln(1);

											}
										}
									}

								}

								$hastags = "no";

								if($this->alltags){
									foreach($this->alltags as $tag){
										$found = "no";
										if($tag->spots){
											if($tag->type=="geologic_unit"){
												foreach($tag->spots as $spotid){
													if($spotid == $id){
														$hastags = "yes";
													}
												}
											}
										}

									}
								}

								if($hastags == "yes"){

									$pdf->valueRow("Geologic Unit(s)","",15);

									if($this->alltags){
										foreach($this->alltags as $tag){
											$found = "no";
											if($tag->spots){
												if($tag->type=="geologic_unit"){
													foreach($tag->spots as $spotid){
														if($spotid == $id){
															$found = "yes";
														}
													}
												}
											}

											if($found == "yes"){

												$pdf->valueTitle($tag->name,20);
												foreach($tag as $key=>$value){

													if($key != "date" && $key != "spots" && $key != "features" && $key != "id" && $key != "name" ){
														$key = $this->fixLabel($key);
														if(is_string($value)){
															$value = $this->fixLabel($value);
														}elseif(is_array($value)){
															$value = implode(", ", $value);
														}
														$pdf->valueRow($key,$value,20);
													}

												}

												$pdf->Ln(1);

											}
										}
									}

								}

								if($spot['sed']){
									$pdf->valueRow("Sed","",15);
									if($spot['sed']->strat_section){
										$pdf->valueTitle("Strat Section: ", 20);
										$content = $spot['sed']->strat_section;

										if($content->section_well_name!=""){
											$pdf->valueRow("Section/Well Name",$content->section_well_name,25);
										}

										if($content->column_profile!=""){
											$pdf->valueRow("Column Profile",$content->column_profile,25);
										}

										if($content->column_y_axis_units!=""){
											$pdf->valueRow("Column Y-Axis Units",$content->column_y_axis_units,25);
										}

										if($content->section_type!=""){
											$pdf->valueRow("Section Type",$content->section_type,25);
										}

										if($content->what_core_repository!=""){
											$pdf->valueRow("What Core Repository?",$content->what_core_repository,25);
										}

										if($content->type_of_corer!=""){
											$pdf->valueRow("Type of corer",$content->type_of_corer,25);
										}

										if($content->depth_from_surface_to_start_of!=""){
											$pdf->valueRow("Depth from surface to start of core",$content->depth_from_surface_to_start_of,25);
										}

										if($content->total_core_length!=""){
											$pdf->valueRow("Total core length",$content->total_core_length,25);
										}

										if($content->location_locality!=""){
											$pdf->valueRow("Location/Locality",$content->location_locality,25);
										}

										if($content->basin!=""){
											$pdf->valueRow("Basin",$content->basin,25);
										}

										if($content->age!=""){
											$pdf->valueRow("Age",$content->age,25);
										}

										if($content->purpose!=""){
											$pdf->valueRow("Purpose",implode($content->purpose, ", "),25);
										}

										if($content->other_purpose!=""){
											$pdf->valueRow("Other Purpose",$content->other_purpose,25);
										}

										if($content->project_description!=""){
											$pdf->valueRow("Project Description",$content->project_description,25);
										}

										if($content->dates_of_work!=""){
											$pdf->valueRow("Dates of Work",$content->dates_of_work,25);
										}

										if($content->scale_of_interest!=""){
											$pdf->valueRow("Scale of Interest",implode($content->scale_of_interest, ", "),25);
										}

										if($content->other_scale_of_interest!=""){
											$pdf->valueRow("Other Scale of Interest",$content->other_scale_of_interest,25);
										}

										if($content->obs_interval_bed_obs_scale!=""){
											$pdf->valueRow("Observation Interval (average bed/observation scale)",$content->obs_interval_bed_obs_scale,25);
										}

										if($content->how_is_section_georeferenced!=""){
											$pdf->valueRow("How is the Section Georeferenced?",$content->how_is_section_georeferenced,25);
										}

										if($content->strat_section_notes!=""){
											$pdf->valueRow("Notes",$content->strat_section_notes,25);
										}

										//strat section link here
										$pdf->httpLink("Download Strat Section", 25, "https://strabospot.org/pstrat_section?id=".$spot['id']."&did=".$dsids);

									}

									if($spot['sed']->lithologies){
										$pdf->valueTitle("Lithologies: ", 20);
										$contents = $spot['sed']->lithologies;
										foreach($contents as $content){
											$pdf->valueTitle("Primary Lithology: ", 25);

											if($content->primary_lithology!=""){
												$pdf->valueRow("Primary Lithology",$content->primary_lithology,30);
											}

											if($content->siliciclastic_type!=""){
												$pdf->valueRow("Siliciclastic Type",$content->siliciclastic_type,30);
											}

											if($content->dunham_classification!=""){
												$pdf->valueRow("Dunham Classification",$content->dunham_classification,30);
											}

											if($content->grain_type!=""){
												$pdf->valueRow("Grain Type",$content->grain_type,30);
											}

											if($content->evaporite_type!=""){
												$pdf->valueRow("Evaporite type",implode($content->evaporite_type, ", "),30);
											}

											if($content->other_evaporite_type!=""){
												$pdf->valueRow("Other Evaporite Type",$content->other_evaporite_type,30);
											}

											if($content->organic_coal_lithologies!=""){
												$pdf->valueRow("Organic/Coal Lithologies",implode($content->organic_coal_lithologies, ", "),30);
											}

											if($content->other_organic_coal_lithology!=""){
												$pdf->valueRow("Other Organic/Coal Lithology",$content->other_organic_coal_lithology,30);
											}

											if($content->volcaniclastic_type!=""){
												$pdf->valueRow("Volcaniclastic type",implode($content->volcaniclastic_type, ", "),30);
											}

											if($content->other_volcaniclastic_type!=""){
												$pdf->valueRow("Other Volcaniclastic Type",$content->other_volcaniclastic_type,30);
											}

											if($content->report_presence_of_particle_ag!=""){
												$pdf->valueRow("Report presence of particle aggregates ",$content->report_presence_of_particle_ag,30);
											}

											if($content->componentry!=""){
												$pdf->valueRow("Componentry",implode($content->componentry, ", "),30);
											}

											if($content->approximate_relative_abundance!=""){
												$pdf->valueRow("Approximate relative abundances of clasts",$content->approximate_relative_abundance,30);
											}

											if($content->phosphorite_type!=""){
												$pdf->valueRow("Phosphorite type",implode($content->phosphorite_type, ", "),30);
											}

											if($content->other_phosphorite_type!=""){
												$pdf->valueRow("Other Phosphorite Type",$content->other_phosphorite_type,30);
											}

											$pdf->valueTitle("Lithification & Color: ", 25);

											if($content->relative_resistance_weather!=""){
												$pdf->valueRow("Relative resistance (weathering profile)",$content->relative_resistance_weather,30);
											}

											if($content->lithification!=""){
												$pdf->valueRow("Lithification",$content->lithification,30);
											}

											if($content->evidence_of_deposit_alteration!=""){
												$pdf->valueRow("Evidence of deposit alteration",$content->evidence_of_deposit_alteration,30);
											}

											if($content->evidence_of_clast_alteration!=""){
												$pdf->valueRow("Evidence of clast alteration",$content->evidence_of_clast_alteration,30);
											}

											if($content->fresh_color!=""){
												$pdf->valueRow("Fresh Color",$content->fresh_color,30);
											}

											if($content->weathered_color!=""){
												$pdf->valueRow("Weathered Color",$content->weathered_color,30);
											}

											if($content->color_appearance!=""){
												$pdf->valueRow("Color Appearance",implode($content->color_appearance, ", "),30);
											}

											if($content->other_color_appearance!=""){
												$pdf->valueRow("Other Color Appearance",$content->other_color_appearance,30);
											}

											if($content->notes!=""){
												$pdf->valueRow("Notes",$content->notes,30);
											}

											$pdf->valueTitle("Composition: ", 25);

											if($content->minerals_present!=""){
												$pdf->valueRow("Minerals Present",implode($content->minerals_present, ", "),30);
											}

											if($content->other_minerals!=""){
												$pdf->valueRow("Other Mineral(s)",$content->other_minerals,30);
											}

											if($content->sandstone_type_dott!=""){
												$pdf->valueRow("Dott Classification",implode($content->sandstone_type_dott, ", "),30);
											}

											if($content->sandstone_type_folk_mcbride!=""){
												$pdf->valueRow("Folk/McBride Classification",implode($content->sandstone_type_folk_mcbride, ", "),30);
											}

											if($content->sandstone_modifier!=""){
												$pdf->valueRow("Sandstone modifier",implode($content->sandstone_modifier, ", "),30);
											}

											if($content->other_sandstone_modifier!=""){
												$pdf->valueRow("Other Sandstone Modifier",$content->other_sandstone_modifier,30);
											}

											if($content->skeletal_carbonate_components!=""){
												$pdf->valueRow("Skeletal Carbonate Components",implode($content->skeletal_carbonate_components, ", "),30);
											}

											if($content->other_skeletal_carbonate_component!=""){
												$pdf->valueRow("Other Skeletal Carbonate Component",$content->other_skeletal_carbonate_component,30);
											}

											if($content->skeletal_general_percent!=""){
												$pdf->valueRow("Skeletal (General) percent",$content->skeletal_general_percent,30);
											}

											if($content->mollusc_percent!=""){
												$pdf->valueRow("Mollusc percent",$content->mollusc_percent,30);
											}

											if($content->brachiopod_percent!=""){
												$pdf->valueRow("Brachiopod percent",$content->brachiopod_percent,30);
											}

											if($content->coral_percent!=""){
												$pdf->valueRow("Coral percent",$content->coral_percent,30);
											}

											if($content->echinoderm_percent!=""){
												$pdf->valueRow("Echinoderm percent",$content->echinoderm_percent,30);
											}

											if($content->bryozoan_percent!=""){
												$pdf->valueRow("Bryozoan percent",$content->bryozoan_percent,30);
											}

											if($content->calcareous_algae_percent!=""){
												$pdf->valueRow("Calcareous Algae percent",$content->calcareous_algae_percent,30);
											}

											if($content->foraminifera_percent!=""){
												$pdf->valueRow("Foraminifera percent",$content->foraminifera_percent,30);
											}

											if($content->stromatolite_percent!=""){
												$pdf->valueRow("Stromatolite percent",$content->stromatolite_percent,30);
											}

											if($content->thrombolite_percent!=""){
												$pdf->valueRow("Thrombolite percent",$content->thrombolite_percent,30);
											}

											if($content->dendrolite_percent!=""){
												$pdf->valueRow("Dendrolite percent",$content->dendrolite_percent,30);
											}

											if($content->leiolite_percent!=""){
												$pdf->valueRow("Leiolite percent",$content->leiolite_percent,30);
											}

											if($content->other_skeletal_carbonate_component_percent!=""){
												$pdf->valueRow("Other Skeletal Carbonate Component percent",$content->other_skeletal_carbonate_component_percent,30);
											}

											if($content->non_skeletal_carbonate_compone!=""){
												$pdf->valueRow("Non-Skeletal Carbonate Components",implode($content->non_skeletal_carbonate_compone, ", "),30);
											}

											if($content->other_non_skeletal_carbonate_component!=""){
												$pdf->valueRow("Other Non-Skeletal Carbonate Component",$content->other_non_skeletal_carbonate_component,30);
											}

											if($content->mud_percent!=""){
												$pdf->valueRow("Mud percent",$content->mud_percent,30);
											}

											if($content->cement_percent!=""){
												$pdf->valueRow("Cement percent",$content->cement_percent,30);
											}

											if($content->intraclast_percent!=""){
												$pdf->valueRow("Intraclast percent",$content->intraclast_percent,30);
											}

											if($content->peloid_percent!=""){
												$pdf->valueRow("Peloid percent",$content->peloid_percent,30);
											}

											if($content->ooid_percent!=""){
												$pdf->valueRow("Ooid percent",$content->ooid_percent,30);
											}

											if($content->oncoid_percent!=""){
												$pdf->valueRow("Oncoid percent",$content->oncoid_percent,30);
											}

											if($content->pisoid_percent!=""){
												$pdf->valueRow("Pisoid percent",$content->pisoid_percent,30);
											}

											if($content->coated_grian_percent!=""){
												$pdf->valueRow("Coated Grian percent",$content->coated_grian_percent,30);
											}

											if($content->grapestone_percent!=""){
												$pdf->valueRow("Grapestone percent",$content->grapestone_percent,30);
											}

											if($content->giant_ooid_percent!=""){
												$pdf->valueRow("Giant Ooid percent",$content->giant_ooid_percent,30);
											}

											if($content->seafloor_precipitate_percent!=""){
												$pdf->valueRow("Seafloor Precipitate percent",$content->seafloor_precipitate_percent,30);
											}

											if($content->molar_tooth_percent!=""){
												$pdf->valueRow("Molar Tooth percent",$content->molar_tooth_percent,30);
											}

											if($content->other_non_skeletal_component_percent!=""){
												$pdf->valueRow("Other Non-Skeletal Component percent",$content->other_non_skeletal_component_percent,30);
											}

											if($content->clay_or_mudstone_type!=""){
												$pdf->valueRow("Claystone or Mudstone Type",implode($content->clay_or_mudstone_type, ", "),30);
											}

											if($content->other_claystone_mudstone!=""){
												$pdf->valueRow("Other Claystone/Mudstone",$content->other_claystone_mudstone,30);
											}

											if($content->siliceous_mudstone_percent!=""){
												$pdf->valueRow("Siliceous mudstone percent",$content->siliceous_mudstone_percent,30);
											}

											if($content->siliceous_calcareous_mudstone_percent!=""){
												$pdf->valueRow("Siliceous calcareous mudstone percent",$content->siliceous_calcareous_mudstone_percent,30);
											}

											if($content->siliceous_volcanicla_tic_mudstone_percent!=""){
												$pdf->valueRow("Siliceous volcaniclastic mudstone percent",$content->siliceous_volcanicla_tic_mudstone_percent,30);
											}

											if($content->calcareous_mudstone_percent!=""){
												$pdf->valueRow("Calcareous mudstone percent",$content->calcareous_mudstone_percent,30);
											}

											if($content->black_shale_percent!=""){
												$pdf->valueRow("Black shale percent",$content->black_shale_percent,30);
											}

											if($content->red_clay_percent!=""){
												$pdf->valueRow("Red clay percent",$content->red_clay_percent,30);
											}

											if($content->red_mudstone_percent!=""){
												$pdf->valueRow("Red mudstone percent",$content->red_mudstone_percent,30);
											}

											if($content->green_mudstone_percent!=""){
												$pdf->valueRow("Green mudstone percent",$content->green_mudstone_percent,30);
											}

											if($content->variegated_mudstone_percent!=""){
												$pdf->valueRow("Variegated mudstone percent",$content->variegated_mudstone_percent,30);
											}

											if($content->marl_percent!=""){
												$pdf->valueRow("Marl percent",$content->marl_percent,30);
											}

											if($content->sarl_percent!=""){
												$pdf->valueRow("Sarl percent",$content->sarl_percent,30);
											}

											if($content->argillaceous_mudstone_percent!=""){
												$pdf->valueRow("Argillaceous mudstone percent",$content->argillaceous_mudstone_percent,30);
											}

											if($content->conglomerate_composition!=""){
												$pdf->valueRow("Conglomerate/Breccia Composition",implode($content->conglomerate_composition, ", "),30);
											}

											if($content->clast_composition!=""){
												$pdf->valueRow("Clast Composition",implode($content->clast_composition, ", "),30);
											}

											if($content->other_clast_types!=""){
												$pdf->valueRow("Other Clast Type(s)",$content->other_clast_types,30);
											}

											if($content->intrusive_igneous_clast_percent!=""){
												$pdf->valueRow("Intrusive igneous clast percent",$content->intrusive_igneous_clast_percent,30);
											}

											if($content->extrusive_igneous_clast_percent!=""){
												$pdf->valueRow("Volcanic clast percent",$content->extrusive_igneous_clast_percent,30);
											}

											if($content->metamorphic_clast_percent!=""){
												$pdf->valueRow("Metamorphic clast percent",$content->metamorphic_clast_percent,30);
											}

											if($content->mudstone_clast_percent!=""){
												$pdf->valueRow("Mudstone clast percent",$content->mudstone_clast_percent,30);
											}

											if($content->siltstone_clast_percent!=""){
												$pdf->valueRow("Siltstone clast percent",$content->siltstone_clast_percent,30);
											}

											if($content->sandstone_clast_percent!=""){
												$pdf->valueRow("Sandstone clast percent",$content->sandstone_clast_percent,30);
											}

											if($content->conglomerate_clast_percent!=""){
												$pdf->valueRow("Conglomerate clast percent",$content->conglomerate_clast_percent,30);
											}

											if($content->limestone_clast_percent!=""){
												$pdf->valueRow("Limestone clast percent",$content->limestone_clast_percent,30);
											}

											if($content->dolostone_clast_percent!=""){
												$pdf->valueRow("Dolostone clast percent",$content->dolostone_clast_percent,30);
											}

											if($content->wackestone_clast_percent!=""){
												$pdf->valueRow("Wackestone clast percent",$content->wackestone_clast_percent,30);
											}

											if($content->packstone_clast_percent!=""){
												$pdf->valueRow("Packstone clast percent",$content->packstone_clast_percent,30);
											}

											if($content->grainstone_clast_percent!=""){
												$pdf->valueRow("Grainstone clast percent",$content->grainstone_clast_percent,30);
											}

											if($content->boundstone_clast_percent!=""){
												$pdf->valueRow("Boundstone clast percent",$content->boundstone_clast_percent,30);
											}

											if($content->other_clast_percent!=""){
												$pdf->valueRow("Other clast percent",$content->other_clast_percent,30);
											}

											if($content->matrix_composition!=""){
												$pdf->valueRow("Matrix composition",implode($content->matrix_composition, ", "),30);
											}

											if($content->other_matrix_types!=""){
												$pdf->valueRow("Other Matrix Type(s)",$content->other_matrix_types,30);
											}

											if($content->intrusive_igneous_matrix_percent!=""){
												$pdf->valueRow("Intrusive igneous matrix percent",$content->intrusive_igneous_matrix_percent,30);
											}

											if($content->extrusive_igneous_matrix_percent!=""){
												$pdf->valueRow("Extrusive igneous matrix percent",$content->extrusive_igneous_matrix_percent,30);
											}

											if($content->metamorphic_igneous_matrix_percent!=""){
												$pdf->valueRow("Metamorphic igneous matrix percent",$content->metamorphic_igneous_matrix_percent,30);
											}

											if($content->mudstone_matrix_percent!=""){
												$pdf->valueRow("Mudstone matrix percent",$content->mudstone_matrix_percent,30);
											}

											if($content->siltstone_matrix_percent!=""){
												$pdf->valueRow("Siltstone matrix percent",$content->siltstone_matrix_percent,30);
											}

											if($content->sandstone_matrix_percent!=""){
												$pdf->valueRow("Sandstone matrix percent",$content->sandstone_matrix_percent,30);
											}

											if($content->conglomerate_matrix_percent!=""){
												$pdf->valueRow("Conglomerate matrix percent",$content->conglomerate_matrix_percent,30);
											}

											if($content->carbonate_matrix_type!=""){
												$pdf->valueRow("Carbonate Matrix Type",implode($content->carbonate_matrix_type, ", "),30);
											}

											if($content->limestone_matrix_percent!=""){
												$pdf->valueRow("Limestone matrix percent",$content->limestone_matrix_percent,30);
											}

											if($content->dolostone_matrix_percent!=""){
												$pdf->valueRow("Dolostone matrix percent",$content->dolostone_matrix_percent,30);
											}

											if($content->skeletal_matrix_percent!=""){
												$pdf->valueRow("Skeletal matrix percent",$content->skeletal_matrix_percent,30);
											}

											if($content->wackestone_matrix_percent!=""){
												$pdf->valueRow("Wackestone matrix percent",$content->wackestone_matrix_percent,30);
											}

											if($content->packstone_matrix_percent!=""){
												$pdf->valueRow("Packstone matrix percent",$content->packstone_matrix_percent,30);
											}

											if($content->grainstone_matrix_percent!=""){
												$pdf->valueRow("Grainstone matrix percent",$content->grainstone_matrix_percent,30);
											}

											if($content->boundstone_matrix_percent!=""){
												$pdf->valueRow("Boundstone matrix percent",$content->boundstone_matrix_percent,30);
											}

											if($content->other_carbonate_matrix_percent!=""){
												$pdf->valueRow("Other carbonate matrix percent",$content->other_carbonate_matrix_percent,30);
											}

											if($content->other_matrix_percent!=""){
												$pdf->valueRow("Other matrix percent",$content->other_matrix_percent,30);
											}

											if($content->volcaniclastic_type!=""){
												$pdf->valueRow("Volcaniclastic type",implode($content->volcaniclastic_type, ", "),30);
											}

											if($content->other_volcaniclastic_type!=""){
												$pdf->valueRow("Other Volcaniclastic Type",$content->other_volcaniclastic_type,30);
											}

											if($content->glass_percent!=""){
												$pdf->valueRow("Glass percent",$content->glass_percent,30);
											}

											if($content->crystals_percent!=""){
												$pdf->valueRow("Crystals percent",$content->crystals_percent,30);
											}

											if($content->lithic_fragments_percent!=""){
												$pdf->valueRow("Lithic fragments percent",$content->lithic_fragments_percent,30);
											}

											if($content->volcanic_mudstone_percent!=""){
												$pdf->valueRow("Volcanic mudstone percent",$content->volcanic_mudstone_percent,30);
											}

											if($content->volcanic_sandstone_percent!=""){
												$pdf->valueRow("Volcanic sandstone percent",$content->volcanic_sandstone_percent,30);
											}

											if($content->lapillistone_percent!=""){
												$pdf->valueRow("Lapillistone percent",$content->lapillistone_percent,30);
											}

											if($content->agglomerate_percent!=""){
												$pdf->valueRow("Agglomerate percent",$content->agglomerate_percent,30);
											}

											if($content->volcanic_breccia_percent!=""){
												$pdf->valueRow("Volcanic breccia percent",$content->volcanic_breccia_percent,30);
											}

											if($content->bentonite_percent!=""){
												$pdf->valueRow("Bentonite percent",$content->bentonite_percent,30);
											}

											if($content->tuff_percent!=""){
												$pdf->valueRow("Tuff percent",$content->tuff_percent,30);
											}

											if($content->welded_tuff_percent!=""){
												$pdf->valueRow("Welded tuff percent",$content->welded_tuff_percent,30);
											}

											if($content->ignimbrite_percent!=""){
												$pdf->valueRow("Ignimbrite percent",$content->ignimbrite_percent,30);
											}

											if($content->other_volcaniclastic_type_percent!=""){
												$pdf->valueRow("Other volcaniclastic type percent",$content->other_volcaniclastic_type_percent,30);
											}

											if($content->evaporite_type!=""){
												$pdf->valueRow("Evaporite type",implode($content->evaporite_type, ", "),30);
											}

											if($content->gypsum_anhydrite_primary_percent!=""){
												$pdf->valueRow("Gypsum - anhydrite primary percent",$content->gypsum_anhydrite_primary_percent,30);
											}

											if($content->gypsum_anhydrite_primary_type!=""){
												$pdf->valueRow("Gypsum - anhydrite primary type",implode($content->gypsum_anhydrite_primary_type, ", "),30);
											}

											if($content->gypsum_anhydrite_diagenetic_percent!=""){
												$pdf->valueRow("Gypsum - anhydrite diagenetic percent",$content->gypsum_anhydrite_diagenetic_percent,30);
											}

											if($content->gypsum_anhydrite_diagenetic_type!=""){
												$pdf->valueRow("Gypsum - anhydrite diagenetic type",implode($content->gypsum_anhydrite_diagenetic_type, ", "),30);
											}

											if($content->halite_primary_percent!=""){
												$pdf->valueRow("Halite - primary percent",$content->halite_primary_percent,30);
											}

											if($content->halite_primary_type!=""){
												$pdf->valueRow("Halite - primary type",implode($content->halite_primary_type, ", "),30);
											}

											if($content->halite_diagenetic_percent!=""){
												$pdf->valueRow("Halite - diagenetic percent",$content->halite_diagenetic_percent,30);
											}

											if($content->halite_diagenetic_type!=""){
												$pdf->valueRow("Halite - diagenetic type",implode($content->halite_diagenetic_type, ", "),30);
											}

											if($content->phosphorite_type!=""){
												$pdf->valueRow("Phosphorite type",implode($content->phosphorite_type, ", "),30);
											}

											if($content->organic_coal_lithologies!=""){
												$pdf->valueRow("Organic/Coal Lithologies",implode($content->organic_coal_lithologies, ", "),30);
											}

											if($content->amber_percent!=""){
												$pdf->valueRow("Amber percent",$content->amber_percent,30);
											}

											if($content->peat_percent!=""){
												$pdf->valueRow("Peat percent",$content->peat_percent,30);
											}

											if($content->lignite_percent!=""){
												$pdf->valueRow("Lignite percent",$content->lignite_percent,30);
											}

											if($content->subbituminous_percent!=""){
												$pdf->valueRow("Subbituminous percent",$content->subbituminous_percent,30);
											}

											if($content->bituminous_percent!=""){
												$pdf->valueRow("Bituminous percent",$content->bituminous_percent,30);
											}

											if($content->coal_ball_percent!=""){
												$pdf->valueRow("Coal ball percent",$content->coal_ball_percent,30);
											}

											if($content->tar_percent!=""){
												$pdf->valueRow("Tar percent",$content->tar_percent,30);
											}

											if($content->notes!=""){
												$pdf->valueRow("Notes",$content->notes,30);
											}

											$pdf->valueTitle("Texture: ", 25);

											if($content->mud_silt_grain_size!=""){
												$pdf->valueRow("Mudstone/Siltstone Grain Size",$content->mud_silt_grain_size,30);
											}

											if($content->sand_grain_size!=""){
												$pdf->valueRow("Sandstone Grain Size",$content->sand_grain_size,30);
											}

											if($content->congl_grain_size!=""){
												$pdf->valueRow("Conglomerate Grain Size",$content->congl_grain_size,30);
											}

											if($content->breccia_grain_size!=""){
												$pdf->valueRow("Breccia Grain Size",$content->breccia_grain_size,30);
											}

											if($content->grain_size_range!=""){
												$pdf->valueRow("Grain Size Range",implode($content->grain_size_range, ", "),30);
											}

											if($content->clay_percent!=""){
												$pdf->valueRow("Clay percent",$content->clay_percent,30);
											}

											if($content->silt_percent!=""){
												$pdf->valueRow("Silt percent",$content->silt_percent,30);
											}

											if($content->sand_very_fine_percent!=""){
												$pdf->valueRow("Sand - very fine percent",$content->sand_very_fine_percent,30);
											}

											if($content->sand_fine_lower_percent!=""){
												$pdf->valueRow("Sand - fine lower percent",$content->sand_fine_lower_percent,30);
											}

											if($content->sand_fine_upper_percent!=""){
												$pdf->valueRow("Sand - fine upper percent",$content->sand_fine_upper_percent,30);
											}

											if($content->sand_medium_lower_percent!=""){
												$pdf->valueRow("Sand - medium lower percent",$content->sand_medium_lower_percent,30);
											}

											if($content->sand_medium_upper_percent!=""){
												$pdf->valueRow("Sand - medium upper percent",$content->sand_medium_upper_percent,30);
											}

											if($content->sand_coarse_lower_percent!=""){
												$pdf->valueRow("Sand - coarse lower percent",$content->sand_coarse_lower_percent,30);
											}

											if($content->sand_coarse_upper_percent!=""){
												$pdf->valueRow("Sand - coarse upper percent",$content->sand_coarse_upper_percent,30);
											}

											if($content->sand_very_coarse_percent!=""){
												$pdf->valueRow("Sand - very coarse percent",$content->sand_very_coarse_percent,30);
											}

											if($content->granule_percent!=""){
												$pdf->valueRow("Granule percent",$content->granule_percent,30);
											}

											if($content->pebble_percent!=""){
												$pdf->valueRow("Pebble percent",$content->pebble_percent,30);
											}

											if($content->cobble_percent!=""){
												$pdf->valueRow("Cobble percent",$content->cobble_percent,30);
											}

											if($content->boulder_percent!=""){
												$pdf->valueRow("Boulder percent",$content->boulder_percent,30);
											}

											if($content->maximum_clast_size_cm!=""){
												$pdf->valueRow("Maximum Clast Size (cm)",$content->maximum_clast_size_cm,30);
											}

											if($content->minimum_clast_size_cm!=""){
												$pdf->valueRow("Minimum Clast Size (cm)",$content->minimum_clast_size_cm,30);
											}

											if($content->average_clast_size_cm!=""){
												$pdf->valueRow("Average Clast Size (cm)",$content->average_clast_size_cm,30);
											}

											if($content->matrix_size!=""){
												$pdf->valueRow("Matrix Size",implode($content->matrix_size, ", "),30);
											}

											if($content->character!=""){
												$pdf->valueRow("Character",implode($content->character, ", "),30);
											}

											if($content->sorting!=""){
												$pdf->valueRow("Sorting",implode($content->sorting, ", "),30);
											}

											if($content->rounding!=""){
												$pdf->valueRow("Rounding",implode($content->rounding, ", "),30);
											}

											if($content->shape!=""){
												$pdf->valueRow("Shape",implode($content->shape, ", "),30);
											}

											if($content->other_shape!=""){
												$pdf->valueRow("Other Shape",$content->other_shape,30);
											}

											if($content->notes!=""){
												$pdf->valueRow("Notes",$content->notes,30);
											}

											if($content->stratification!=""){
												$pdf->valueRow("Stratification",implode($content->stratification, ", "),30);
											}

											if($content->laminae_thickness_i_select_more_than_one!=""){
												$pdf->valueRow("Laminae Thickness",implode($content->laminae_thickness_i_select_more_than_one, ", "),30);
											}

											if($content->lamination_character!=""){
												$pdf->valueRow("Lamination Character",implode($content->lamination_character, ", "),30);
											}

											if($content->bedding_thickness!=""){
												$pdf->valueRow("Bedding Thickness",implode($content->bedding_thickness, ", "),30);
											}

											if($content->bedding_character!=""){
												$pdf->valueRow("Bedding character",implode($content->bedding_character, ", "),30);
											}

											if($content->package_geometry!=""){
												$pdf->valueRow("Package Geometry",implode($content->package_geometry, ", "),30);
											}

											if($content->package_lateral_extent!=""){
												$pdf->valueRow("Package Lateral Extent",$content->package_lateral_extent,30);
											}

											if($content->package_bedding_trends!=""){
												$pdf->valueRow("Package Bedding Trends",$content->package_bedding_trends,30);
											}

											if($content->other_bedding_trend!=""){
												$pdf->valueRow("Other bedding trend",$content->other_bedding_trend,30);
											}

											if($content->shape_of_lower_contacts!=""){
												$pdf->valueRow("Shape of lower contact(s)",implode($content->shape_of_lower_contacts, ", "),30);
											}

											if($content->character_of_lower_contacts!=""){
												$pdf->valueRow("Character of lower contact(s)",implode($content->character_of_lower_contacts, ", "),30);
											}

											if($content->lower_contact_relief!=""){
												$pdf->valueRow("Lower contact relief",$content->lower_contact_relief,30);
											}

											if($content->shape_of_upper_contacts!=""){
												$pdf->valueRow("Shape of upper contact(s)",implode($content->shape_of_upper_contacts, ", "),30);
											}

											if($content->character_of_upper_contacts!=""){
												$pdf->valueRow("Character of upper contact(s)",implode($content->character_of_upper_contacts, ", "),30);
											}

											if($content->upper_contact_relief!=""){
												$pdf->valueRow("Upper Contact Relief",$content->upper_contact_relief,30);
											}

											if($content->interbed_proportion!=""){
												$pdf->valueRow("Interbed Relative Proportion (%)",$content->interbed_proportion,30);
											}

											if($content->interbed_thickness!=""){
												$pdf->valueRow("Interbed Thickness",$content->interbed_thickness,30);
											}

											if($content->interbed_proportion_change!=""){
												$pdf->valueRow("Interbed Proportion Change (Up Section)",$content->interbed_proportion_change,30);
											}

											if($content->interbed_thickness_change!=""){
												$pdf->valueRow("Interbed Thickness Change (Up Section)",$content->interbed_thickness_change,30);
											}

											if($content->Notes!=""){
												$pdf->valueRow("Notes",$content->Notes,30);
											}

										}

									}

									if($spot['sed']->bedding){
										$pdf->valueTitle("Bedding: ", 20);
										$content = $spot['sed']->bedding;

										if($content->interbed_proportion_change!=""){
											$pdf->valueRow("Interbed Proportion Change (Up Section)",$content->interbed_proportion_change,25);
										}

										if($content->interbed_proportion!=""){
											$pdf->valueRow("Lithology 1: Interbed Relative Proportion (%)",$content->interbed_proportion,25);
										}

										if($content->lithology_at_bottom_contact!=""){
											$pdf->valueRow("Which Lithology is at Bottom Contact?",$content->lithology_at_bottom_contact,25);
										}

										if($content->lithology_at_top_contact!=""){
											$pdf->valueRow("Which Lithology is at Top Contact?",$content->lithology_at_top_contact,25);
										}

										if($content->thickness_of_individual_beds!=""){
											$pdf->valueRow("Thickness of Individual Beds",$content->thickness_of_individual_beds,25);
										}

										if($content->package_thickness_units!=""){
											$pdf->valueRow("Package Beds Thickness Units",$content->package_thickness_units,25);
										}

										if($content->package_bedding_trends!=""){
											$pdf->valueRow("Package Bedding Trends",$content->package_bedding_trends,25);
										}

										if($content->other_bedding_trend!=""){
											$pdf->valueRow("Other Package Bedding Trend",$content->other_bedding_trend,25);
										}

										$beds = $spot['sed']->bedding->beds;
										foreach($beds as $content){

											if($content->package_geometry!=""){
												$pdf->valueRow("Bed Geometry",implode($content->package_geometry, ", "),25);
											}

											$pdf->valueTitle("Lower Contact: ", 25);

											if($content->shape_of_lower_contacts!=""){
												$pdf->valueRow("Shape of lower contact",implode($content->shape_of_lower_contacts, ", "),30);
											}

											if($content->character_of_lower_contacts!=""){
												$pdf->valueRow("Character of lower contact(s)",implode($content->character_of_lower_contacts, ", "),30);
											}

											if($content->lower_contact_relief!=""){
												$pdf->valueRow("Lower contact relief",$content->lower_contact_relief,30);
											}

											$pdf->valueTitle("Upper Contact: ", 25);

											if($content->shape_of_upper_contacts!=""){
												$pdf->valueRow("Shape of upper contact",implode($content->shape_of_upper_contacts, ", "),30);
											}

											if($content->character_of_upper_contacts!=""){
												$pdf->valueRow("Character of upper contact",implode($content->character_of_upper_contacts, ", "),30);
											}

											if($content->upper_contact_relief!=""){
												$pdf->valueRow("Upper Contact Relief",$content->upper_contact_relief,30);
											}

											$pdf->valueTitle("Interbed Thickness: ", 25);

											if($content->avg_thickness!=""){
												$pdf->valueRow("Average Thickness",$content->avg_thickness,30);
											}

											if($content->max_thickness!=""){
												$pdf->valueRow("Maximum Thickness",$content->max_thickness,30);
											}

											if($content->min_thickness!=""){
												$pdf->valueRow("Minimum Thickness",$content->min_thickness,30);
											}

											if($content->interbed_thickness_units!=""){
												$pdf->valueRow("Interbed Thickness Units",$content->interbed_thickness_units,30);
											}

											if($content->notes!=""){
												$pdf->valueRow("Notes",$content->notes,30);
											}

										}
									}

									if($spot['sed']->structures){
										$pdf->valueTitle("Structures: ", 20);
										$contents = $spot['sed']->structures;
										foreach($contents as $content){

											if($content->massive_structureless!=""){
												$pdf->valueRow("Massive/Structureless?",$content->massive_structureless,25);
											}

											$pdf->valueTitle("Cross Bedding: ", 25);

											if($content->cross_bedding_type!=""){
												$pdf->valueRow("Cross Bedding Type",implode($content->cross_bedding_type, ", "),30);
											}

											if($content->cross_bedding_height_cm!=""){
												$pdf->valueRow("Cross Bedding Height (cm)",$content->cross_bedding_height_cm,30);
											}

											if($content->cross_bedding_width_cm!=""){
												$pdf->valueRow("Cross Bedding Width (cm)",$content->cross_bedding_width_cm,30);
											}

											if($content->cross_bedding_thickness_cm!=""){
												$pdf->valueRow("Cross Bedding Thickness (cm)",$content->cross_bedding_thickness_cm,30);
											}

											if($content->cross_bedding_spacing_cm!=""){
												$pdf->valueRow("Cross Bedding Spacing (cm)",$content->cross_bedding_spacing_cm,30);
											}

											$pdf->valueTitle("Ripple Lamination: ", 25);

											if($content->ripple_lamination_type!=""){
												$pdf->valueRow("Ripple Lamination Type",implode($content->ripple_lamination_type, ", "),30);
											}

											if($content->other_ripple_lamination_type!=""){
												$pdf->valueRow("Other Ripple Lamination Type",$content->other_ripple_lamination_type,30);
											}

											if($content->ripple_lamination_height_mm!=""){
												$pdf->valueRow("Ripple Lamination Height (mm)",$content->ripple_lamination_height_mm,30);
											}

											if($content->ripple_lamination_width_mm!=""){
												$pdf->valueRow("Ripple Lamination Width (mm)",$content->ripple_lamination_width_mm,30);
											}

											if($content->ripple_lamination_thick_mm!=""){
												$pdf->valueRow("Ripple Lmation Thickness (mm)",$content->ripple_lamination_thick_mm,30);
											}

											if($content->ripple_lamination_spacing_mm!=""){
												$pdf->valueRow("Ripple Lamination Spacing (mm)",$content->ripple_lamination_spacing_mm,30);
											}

											$pdf->valueTitle("Horizontal Bedding: ", 25);

											if($content->horizontal_bedding_type!=""){
												$pdf->valueRow("Horizontal Bedding Type",implode($content->horizontal_bedding_type, ", "),30);
											}

											if($content->other_horizontal_bedding_type!=""){
												$pdf->valueRow("Other Horizontal Bedding Type",$content->other_horizontal_bedding_type,30);
											}

											$pdf->valueTitle("Graded Bedding: ", 25);

											if($content->graded_bedding_type!=""){
												$pdf->valueRow("Graded Bedding Type",$content->graded_bedding_type,30);
											}

											$pdf->valueTitle("Deformation Structures: ", 25);

											if($content->deformation_structures!=""){
												$pdf->valueRow("Deformation Structure Type",implode($content->deformation_structures, ", "),30);
											}

											if($content->other_deformation_structure_type!=""){
												$pdf->valueRow("Other Deformation Structure Type",$content->other_deformation_structure_type,30);
											}

											$pdf->valueTitle("Lags: ", 25);

											if($content->lag_type!=""){
												$pdf->valueRow("Lag Type",implode($content->lag_type, ", "),30);
											}

											if($content->other_lag_type!=""){
												$pdf->valueRow("Other Lag Type",$content->other_lag_type,30);
											}

											if($content->clast_composition!=""){
												$pdf->valueRow("Clast Composition",$content->clast_composition,30);
											}

											if($content->clast_size!=""){
												$pdf->valueRow("Clast Size",$content->clast_size,30);
											}

											if($content->layer_thickness_shape!=""){
												$pdf->valueRow("Layer Thickness/Shape",$content->layer_thickness_shape,30);
											}

											$pdf->valueTitle("Other Common Structures: ", 25);

											if($content->other_common_structures!=""){
												$pdf->valueRow("Other Common Structure Type",implode($content->other_common_structures, ", "),30);
											}

											if($content->bouma_sequence_part!=""){
												$pdf->valueRow("Bouma Sequence Part",implode($content->bouma_sequence_part, ", "),30);
											}

											if($content->bioturbation_index!=""){
												$pdf->valueRow("Bioturbation Index",$content->bioturbation_index,30);
											}

											if($content->bedding_plane_features!=""){
												$pdf->valueRow("Bedding plane features",implode($content->bedding_plane_features, ", "),30);
											}

											if($content->other_bedding_plane_feature!=""){
												$pdf->valueRow("Other Bedding Plane Feature",$content->other_bedding_plane_feature,30);
											}

											if($content->bedding_plane_features_scale!=""){
												$pdf->valueRow("Bedding Plane Features Scale",$content->bedding_plane_features_scale,30);
											}

											if($content->bedding_plane_features_orientation!=""){
												$pdf->valueRow("Bedding Plane Features Orientation",$content->bedding_plane_features_orientation,30);
											}

											if($content->bedform_type!=""){
												$pdf->valueRow("Bedform Type",implode($content->bedform_type, ", "),30);
											}

											if($content->other_bedform_type!=""){
												$pdf->valueRow("Other Bedform Type",$content->other_bedform_type,30);
											}

											if($content->bedform_scale!=""){
												$pdf->valueRow("Bedform Scale",$content->bedform_scale,30);
											}

											if($content->crest_orientation_azimuth_0_360!=""){
												$pdf->valueRow("Crest Orientation",$content->crest_orientation_azimuth_0_360,30);
											}

											$pdf->valueTitle("Pedogenic Structures: ", 25);

											if($content->paleosol_horizons!=""){
												$pdf->valueRow("Master Paleosol Horizons",implode($content->paleosol_horizons, ", "),30);
											}

											if($content->other_horizon!=""){
												$pdf->valueRow("Other Horizon",$content->other_horizon,30);
											}

											if($content->o_horizon_thickness_cm!=""){
												$pdf->valueRow("O Horizon thickness (cm)",$content->o_horizon_thickness_cm,30);
											}

											if($content->a_horizon_thickness_cm!=""){
												$pdf->valueRow("A Horizon thickness (cm)",$content->a_horizon_thickness_cm,30);
											}

											if($content->e_horizon_thickness_cm!=""){
												$pdf->valueRow("E Horizon thickness (cm)",$content->e_horizon_thickness_cm,30);
											}

											if($content->b_horizon_thickness_cm!=""){
												$pdf->valueRow("B Horizon thickness (cm)",$content->b_horizon_thickness_cm,30);
											}

											if($content->k_horizon_thickness_cm!=""){
												$pdf->valueRow("K Horizon thickness (cm)",$content->k_horizon_thickness_cm,30);
											}

											if($content->c_horizon_thickness_cm!=""){
												$pdf->valueRow("C Horizon thickness (cm)",$content->c_horizon_thickness_cm,30);
											}

											if($content->r_horizon_thickness_cm!=""){
												$pdf->valueRow("R Horizon thickness (cm)",$content->r_horizon_thickness_cm,30);
											}

											if($content->compound_thickness_cm!=""){
												$pdf->valueRow("Compound thickness (cm)",$content->compound_thickness_cm,30);
											}

											if($content->composite_thickness_cm!=""){
												$pdf->valueRow("Composite thickness (cm)",$content->composite_thickness_cm,30);
											}

											if($content->paleosol_structures!=""){
												$pdf->valueRow("Paleosol structures",implode($content->paleosol_structures, ", "),30);
											}

											if($content->other_structure!=""){
												$pdf->valueRow("Other Paleosol Structure",$content->other_structure,30);
											}

											if($content->additional_modifiers!=""){
												$pdf->valueRow("Additional modifiers",$content->additional_modifiers,30);
											}

											if($content->paleosol_classification!=""){
												$pdf->valueRow("Paleosol classification",implode($content->paleosol_classification, ", "),30);
											}

											if($content->notes!=""){
												$pdf->valueRow("Notes",$content->notes,30);
											}

										}

									}

									if($spot['sed']->diagenesis){
										$pdf->valueTitle("Diagenesis: ", 20);
										$contents = $spot['sed']->diagenesis;
										foreach($contents as $content){

											$pdf->valueTitle("Cement: ", 25);

											if($content->cement_composition!=""){
												$pdf->valueRow("Cement Mineralogy",implode($content->cement_composition, ", "),30);
											}

											if($content->other_cement_composition!=""){
												$pdf->valueRow("Other Cement Mineralogy",$content->other_cement_composition,30);
											}

											$pdf->valueTitle("Veins: ", 25);

											if($content->vein_type!=""){
												$pdf->valueRow("Vein Type",$content->vein_type,30);
											}

											if($content->vein_width!=""){
												$pdf->valueRow("Vein Width (cm)",$content->vein_width,30);
											}

											if($content->vein_length!=""){
												$pdf->valueRow("Vein Length (cm)",$content->vein_length,30);
											}

											if($content->vein_orientation!=""){
												$pdf->valueRow("Vein Orientation",$content->vein_orientation,30);
											}

											if($content->vein_mineralogy!=""){
												$pdf->valueRow("Vein Mineralogy",$content->vein_mineralogy,30);
											}

											if($content->other_vein_mineralogy!=""){
												$pdf->valueRow("Other Vein Mineralogy",$content->other_vein_mineralogy,30);
											}

											$pdf->valueTitle("Fractures: ", 25);

											if($content->fracture_type!=""){
												$pdf->valueRow("Fracture Type",$content->fracture_type,30);
											}

											if($content->fracture_width!=""){
												$pdf->valueRow("Fracture Width (cm)",$content->fracture_width,30);
											}

											if($content->fracture_length!=""){
												$pdf->valueRow("Fracture Length (cm)",$content->fracture_length,30);
											}

											if($content->fracture_orientation!=""){
												$pdf->valueRow("Fracture Orientation",$content->fracture_orientation,30);
											}

											if($content->fracture_mineralogy!=""){
												$pdf->valueRow("Fracture Mineralogy",$content->fracture_mineralogy,30);
											}

											if($content->other_fracture_mineralogy!=""){
												$pdf->valueRow("Other Fracture Mineralogy",$content->other_fracture_mineralogy,30);
											}

											$pdf->valueTitle("Nodules/Concretions: ", 25);

											if($content->nodules_concretions_size!=""){
												$pdf->valueRow("Nodules/Concretions Size",$content->nodules_concretions_size,30);
											}

											if($content->min!=""){
												$pdf->valueRow("Min",$content->min,30);
											}

											if($content->max!=""){
												$pdf->valueRow("Max",$content->max,30);
											}

											if($content->average!=""){
												$pdf->valueRow("Average",$content->average,30);
											}

											if($content->nodules_concretions_shape!=""){
												$pdf->valueRow("Nodules/Concretions Shape",implode($content->nodules_concretions_shape, ", "),30);
											}

											if($content->other_nodules_concretion_shape!=""){
												$pdf->valueRow("Other Nodule/Concretions Shape",$content->other_nodules_concretion_shape,30);
											}

											if($content->spacing!=""){
												$pdf->valueRow("Spacing",$content->spacing,30);
											}

											if($content->nodules_concretions_type!=""){
												$pdf->valueRow("Nodules/Concretions Type",$content->nodules_concretions_type,30);
											}

											if($content->other_nodules_concretions_type!=""){
												$pdf->valueRow("Other Nodules/Concretions Type",$content->other_nodules_concretions_type,30);
											}

											if($content->nodules_concretions_comp!=""){
												$pdf->valueRow("Nodules/Concretions Composition",implode($content->nodules_concretions_comp, ", "),30);
											}

											if($content->other_nodules_concretion_comp!=""){
												$pdf->valueRow("Other Nodules/Concretions Composition",$content->other_nodules_concretion_comp,30);
											}

											$pdf->valueTitle("Replacement: ", 25);

											if($content->replacement_type!=""){
												$pdf->valueRow("Replacement Type",$content->replacement_type,30);
											}

											if($content->other_replacement_type!=""){
												$pdf->valueRow("Other Replacement Type",$content->other_replacement_type,30);
											}

											$pdf->valueTitle("Recrystallization: ", 25);

											if($content->recrystallization_type!=""){
												$pdf->valueRow("Recrystallization Type",$content->recrystallization_type,30);
											}

											if($content->other_recrystallization_type!=""){
												$pdf->valueRow("Other Recrystallization Type",$content->other_recrystallization_type,30);
											}

											$pdf->valueTitle("Other Diagenetic Features: ", 25);

											if($content->other_diagenetic_features!=""){
												$pdf->valueRow("Other Diagenetic Features",implode($content->other_diagenetic_features, ", "),30);
											}

											if($content->other_features!=""){
												$pdf->valueRow("Other Features",$content->other_features,30);
											}

											$pdf->valueTitle("Porosity type: ", 25);

											if($content->fabric_selective!=""){
												$pdf->valueRow("Fabric Selective",implode($content->fabric_selective, ", "),30);
											}

											if($content->other_fabric_selective!=""){
												$pdf->valueRow("Other Fabric Selective",$content->other_fabric_selective,30);
											}

											if($content->non_selective!=""){
												$pdf->valueRow("Non-Frabric Selective",implode($content->non_selective, ", "),30);
											}

											if($content->other_non_selective!=""){
												$pdf->valueRow("Other Non-Fabric Selective",$content->other_non_selective,30);
											}

											$pdf->valueTitle("Carbonate Desiccation and Dissolution: ", 25);

											if($content->carbonate_desicc_and_diss!=""){
												$pdf->valueRow("Carbonate Desiccation and Dissolution Type",implode($content->carbonate_desicc_and_diss, ", "),30);
											}

											if($content->other_carbonate_desicc_diss!=""){
												$pdf->valueRow("Other Carbonate Desiccation and Dissolution Type",$content->other_carbonate_desicc_diss,30);
											}

											if($content->notes!=""){
												$pdf->valueRow("Notes",$content->notes,30);
											}

										}
									}

									if($spot['sed']->fossils){
										$pdf->valueTitle("Fossils: ", 20);
										$contents = $spot['sed']->fossils;
										foreach($contents as $content){

											$pdf->valueTitle("Body: ", 25);

											if($content->invertebrate!=""){
												$pdf->valueRow("Invertebrate",implode($content->invertebrate, ", "),30);
											}

											if($content->other_invertebrate!=""){
												$pdf->valueRow("Other Invertebrate",$content->other_invertebrate,30);
											}

											if($content->mollusca!=""){
												$pdf->valueRow("Mollusc",implode($content->mollusca, ", "),30);
											}

											if($content->other_mollusca!=""){
												$pdf->valueRow("Other Mollusc",$content->other_mollusca,30);
											}

											if($content->arthropoda!=""){
												$pdf->valueRow("Arthropod",implode($content->arthropoda, ", "),30);
											}

											if($content->other_anthropoda!=""){
												$pdf->valueRow("Other Arthropod",$content->other_anthropoda,30);
											}

											if($content->echinodermata!=""){
												$pdf->valueRow("Echinoderm",implode($content->echinodermata, ", "),30);
											}

											if($content->other_echinodermata!=""){
												$pdf->valueRow("Other Echinoderm",$content->other_echinodermata,30);
											}

											if($content->cnidaria!=""){
												$pdf->valueRow("Cnidarian",implode($content->cnidaria, ", "),30);
											}

											if($content->other_cnidaria!=""){
												$pdf->valueRow("Other Cnidarian",$content->other_cnidaria,30);
											}

											if($content->chordate!=""){
												$pdf->valueRow("Chordate",$content->chordate,30);
											}

											if($content->other_chordata!=""){
												$pdf->valueRow("Other Chordate",$content->other_chordata,30);
											}

											if($content->protista!=""){
												$pdf->valueRow("Protist",implode($content->protista, ", "),30);
											}

											if($content->other_protista!=""){
												$pdf->valueRow("Other Protist",$content->other_protista,30);
											}

											if($content->calcimicrobe!=""){
												$pdf->valueRow("Calcimicrobe",implode($content->calcimicrobe, ", "),30);
											}

											if($content->other_calcimicrobe!=""){
												$pdf->valueRow("Other Calcimicrobe",$content->other_calcimicrobe,30);
											}

											if($content->plant_algae!=""){
												$pdf->valueRow("Plant/algae",implode($content->plant_algae, ", "),30);
											}

											if($content->other_plant_algae!=""){
												$pdf->valueRow("Other Plant/Algae",$content->other_plant_algae,30);
											}

											if($content->green_algae!=""){
												$pdf->valueRow("Green Algae",implode($content->green_algae, ", "),30);
											}

											if($content->other_green_algae!=""){
												$pdf->valueRow("Other Green Algae",$content->other_green_algae,30);
											}

											if($content->vertebrate!=""){
												$pdf->valueRow("Vertebrate",implode($content->vertebrate, ", "),30);
											}

											if($content->other_vertebrate!=""){
												$pdf->valueRow("Other Vertebrate",$content->other_vertebrate,30);
											}

											if($content->faunal_assemblage!=""){
												$pdf->valueRow("Faunal assemblage",$content->faunal_assemblage,30);
											}

											if($content->other_faunal_assemblage!=""){
												$pdf->valueRow("Other faunal assemblage",$content->other_faunal_assemblage,30);
											}

											$pdf->valueTitle("Trace: ", 25);

											if($content->diversity!=""){
												$pdf->valueRow("Diversity",$content->diversity,30);
											}

											if($content->descriptive!=""){
												$pdf->valueRow("Descriptive",implode($content->descriptive, ", "),30);
											}

											if($content->other_descriptive!=""){
												$pdf->valueRow("Other Descriptive",$content->other_descriptive,30);
											}

											if($content->burrow_fill_type!=""){
												$pdf->valueRow("Burrow Fill Type",implode($content->burrow_fill_type, ", "),30);
											}

											if($content->other_burrow_fill!=""){
												$pdf->valueRow("Other Burrow Fill Type",$content->other_burrow_fill,30);
											}

											if($content->behavioral_grouping!=""){
												$pdf->valueRow("Behavioral grouping",$content->behavioral_grouping,30);
											}

											if($content->other_behavioral_grouping!=""){
												$pdf->valueRow("Other Behavioral Grouping",$content->other_behavioral_grouping,30);
											}

											if($content->ichnofacies!=""){
												$pdf->valueRow("Ichnofacies",$content->ichnofacies,30);
											}

											if($content->other_ichnofacies!=""){
												$pdf->valueRow("Other Ichnofacies",$content->other_ichnofacies,30);
											}

											if($content->list_of_specific_types!=""){
												$pdf->valueRow("List of specific types",$content->list_of_specific_types,30);
											}

											$pdf->valueTitle("Biogenic Growth Structures: ", 25);

											if($content->dominant_component!=""){
												$pdf->valueRow("Dominant component",$content->dominant_component,30);
											}

											if($content->other_dominant_component!=""){
												$pdf->valueRow("Other Dominant Component",$content->other_dominant_component,30);
											}

											if($content->microbial_reef_or_skelatal_mic!=""){
												$pdf->valueRow("Microbial Reef or Skeletal-Microbial Reef Type",implode($content->microbial_reef_or_skelatal_mic, ", "),30);
											}

											if($content->other_microbial_or_skeletal_mi!=""){
												$pdf->valueRow("Other Microbial or Skeletal Microbial Reef",$content->other_microbial_or_skeletal_mi,30);
											}

											if($content->mud_mound!=""){
												$pdf->valueRow("Mud Mound Type",implode($content->mud_mound, ", "),30);
											}

											if($content->other_mud_mound!=""){
												$pdf->valueRow("Other Mud Mound",$content->other_mud_mound,30);
											}

											$pdf->valueTitle("Biogenic Growth Structure Scale and Orientation: ", 25);

											if($content->height!=""){
												$pdf->valueRow("Height",$content->height,30);
											}

											if($content->width!=""){
												$pdf->valueRow("Width",$content->width,30);
											}

											if($content->shape!=""){
												$pdf->valueRow("Shape",$content->shape,30);
											}

											if($content->type!=""){
												$pdf->valueRow("Type",$content->type,30);
											}

											if($content->other_type!=""){
												$pdf->valueRow("Other Type",$content->other_type,30);
											}

											if($content->accessory_structures!=""){
												$pdf->valueRow("Accessory Structures",implode($content->accessory_structures, ", "),30);
											}

											if($content->other_accessory_structure!=""){
												$pdf->valueRow("Other Accessory Structure",$content->other_accessory_structure,30);
											}

											if($content->notes!=""){
												$pdf->valueRow("Notes",$content->notes,30);
											}
										}
									}

									if($spot['sed']->interpretations){
										$pdf->valueTitle("Interpretations: ", 20);
										$contents = $spot['sed']->interpretations;
										foreach($contents as $content){

											$pdf->valueTitle("Process Interpretation: ", 25);

											if($content->energy!=""){
												$pdf->valueRow("Energy",$content->energy,30);
											}

											if($content->other_energy!=""){
												$pdf->valueRow("Other Energy",$content->other_energy,30);
											}

											if($content->sediment_transport!=""){
												$pdf->valueRow("Sediment Transport",implode($content->sediment_transport, ", "),30);
											}

											if($content->other_sediment_transport!=""){
												$pdf->valueRow("Other Sediment Transport",$content->other_sediment_transport,30);
											}

											if($content->fluidization!=""){
												$pdf->valueRow("Fluidization",$content->fluidization,30);
											}

											if($content->other_fluidization!=""){
												$pdf->valueRow("Other Fluidization",$content->other_fluidization,30);
											}

											if($content->miscellaneous!=""){
												$pdf->valueRow("Miscellaneous",implode($content->miscellaneous, ", "),30);
											}

											if($content->other_miscellaneous!=""){
												$pdf->valueRow("Other Misc. Process",$content->other_miscellaneous,30);
											}

											$pdf->valueTitle("Environment Interpretation: ", 25);

											if($content->general!=""){
												$pdf->valueRow("General",implode($content->general, ", "),30);
											}

											if($content->clastic!=""){
												$pdf->valueRow("Clastic",implode($content->clastic, ", "),30);
											}

											if($content->alluvial_fan_environments!=""){
												$pdf->valueRow("Alluvial fan environments",implode($content->alluvial_fan_environments, ", "),30);
											}

											if($content->other_alluvial_fan!=""){
												$pdf->valueRow("Other Alluvial Fan",$content->other_alluvial_fan,30);
											}

											if($content->eolian_environments!=""){
												$pdf->valueRow("Eolian environments",implode($content->eolian_environments, ", "),30);
											}

											if($content->other_eolian!=""){
												$pdf->valueRow("Other Eolian",$content->other_eolian,30);
											}

											if($content->fluvial_environments!=""){
												$pdf->valueRow("Fluvial environments",implode($content->fluvial_environments, ", "),30);
											}

											if($content->other_fluvial!=""){
												$pdf->valueRow("Other Fluvial",$content->other_fluvial,30);
											}

											if($content->shallow_marine_clastic_environ!=""){
												$pdf->valueRow("Shallow marine clastic environments",implode($content->shallow_marine_clastic_environ, ", "),30);
											}

											if($content->other_shallow_marine!=""){
												$pdf->valueRow("Other Shallow Marine",$content->other_shallow_marine,30);
											}

											if($content->deep_marine_environments!=""){
												$pdf->valueRow("Deep marine environments",implode($content->deep_marine_environments, ", "),30);
											}

											if($content->other_deep_marine!=""){
												$pdf->valueRow("Other Deep Marine",$content->other_deep_marine,30);
											}

											if($content->glacial_and_proglacial_environ!=""){
												$pdf->valueRow("Glacial and proglacial environments",implode($content->glacial_and_proglacial_environ, ", "),30);
											}

											if($content->other_glacial!=""){
												$pdf->valueRow("Other Glacial",$content->other_glacial,30);
											}

											if($content->lake_environments!=""){
												$pdf->valueRow("Lake environments",implode($content->lake_environments, ", "),30);
											}

											if($content->other_lake!=""){
												$pdf->valueRow("Other Lake",$content->other_lake,30);
											}

											if($content->other_clastic!=""){
												$pdf->valueRow("Other Clastic",$content->other_clastic,30);
											}

											if($content->carbonates!=""){
												$pdf->valueRow("Carbonates",implode($content->carbonates, ", "),30);
											}

											if($content->factory!=""){
												$pdf->valueRow("Factory",implode($content->factory, ", "),30);
											}

											if($content->carbonate!=""){
												$pdf->valueRow("Environment",implode($content->carbonate, ", "),30);
											}

											if($content->other_carbonate_environment!=""){
												$pdf->valueRow("Other Carbonate Environment",$content->other_carbonate_environment,30);
											}

											if($content->lake_subenvironments!=""){
												$pdf->valueRow("Lake Subenvironments",implode($content->lake_subenvironments, ", "),30);
											}

											if($content->other_carbonate_lake_subenvironment!=""){
												$pdf->valueRow("Other Carbonate Lake Subenvironment",$content->other_carbonate_lake_subenvironment,30);
											}

											if($content->tidal_flat_subenvironments!=""){
												$pdf->valueRow("Tidal Flat Subenvironments",implode($content->tidal_flat_subenvironments, ", "),30);
											}

											if($content->other_tidal_flat!=""){
												$pdf->valueRow("Other Tidal Flat",$content->other_tidal_flat,30);
											}

											if($content->reef_subenvironments!=""){
												$pdf->valueRow("Reef Subenvironments",implode($content->reef_subenvironments, ", "),30);
											}

											if($content->other_reef!=""){
												$pdf->valueRow("Other Reef",$content->other_reef,30);
											}

											if($content->detailed_carbonate_env_interpr!=""){
												$pdf->valueRow("Detailed carbonate environmental interpretations",$content->detailed_carbonate_env_interpr,30);
											}

											if($content->tectonic_setting!=""){
												$pdf->valueRow("Tectonic Setting",implode($content->tectonic_setting, ", "),30);
											}

											if($content->other_tectonic_setting!=""){
												$pdf->valueRow("Other Tectonic Setting",$content->other_tectonic_setting,30);
											}

											$pdf->valueTitle("Sedimentary Surfaces (for line spots only): ", 25);

											if($content->geometry!=""){
												$pdf->valueRow("Geometry",$content->geometry,30);
											}

											if($content->relief!=""){
												$pdf->valueRow("Relief",$content->relief,30);
											}

											if($content->relief_scale!=""){
												$pdf->valueRow("Relief Scale",$content->relief_scale,30);
											}

											if($content->extent!=""){
												$pdf->valueRow("Extent",$content->extent,30);
											}

											if($content->extent_scale!=""){
												$pdf->valueRow("Extent Scale",$content->extent_scale,30);
											}

											if($content->type!=""){
												$pdf->valueRow("Type",implode($content->type, ", "),30);
											}

											if($content->other_type!=""){
												$pdf->valueRow("Other Type",$content->other_type,30);
											}

											if($content->stratal_termination!=""){
												$pdf->valueRow("Stratal Termination",$content->stratal_termination,30);
											}

											$pdf->valueTitle("Sedimentary Surface Interpretation: ", 25);

											if($content->general_surfaces!=""){
												$pdf->valueRow("General Surfaces",$content->general_surfaces,30);
											}

											if($content->sequence_stratigraphic_surfaces!=""){
												$pdf->valueRow("Sequence Stratigraphic Surfaces",$content->sequence_stratigraphic_surfaces,30);
											}

											if($content->other_sequence_stratigraphic_surface!=""){
												$pdf->valueRow("Other Sequence Stratigraphic Surface",$content->other_sequence_stratigraphic_surface,30);
											}

											if($content->named!=""){
												$pdf->valueRow("Named",$content->named,30);
											}

											$pdf->valueTitle("Architecture Interpretation: ", 25);

											if($content->description!=""){
												$pdf->valueRow("Description",implode($content->description, ", "),30);
											}

											if($content->stacking_sequence_stratigraphy!=""){
												$pdf->valueRow("Stacking/Sequence Stratigraphy",implode($content->stacking_sequence_stratigraphy, ", "),30);
											}

											if($content->other_stacking_sequence_stratigraphy!=""){
												$pdf->valueRow("Other Stacking/Sequence Stratigraphy",$content->other_stacking_sequence_stratigraphy,30);
											}

											if($content->fluvial_architectural_elements!=""){
												$pdf->valueRow("Fluvial Architectural Elements",implode($content->fluvial_architectural_elements, ", "),30);
											}

											if($content->other_fluvial_element!=""){
												$pdf->valueRow("Other Fluvial Element",$content->other_fluvial_element,30);
											}

											if($content->lacustrine_architecture_interpretation!=""){
												$pdf->valueRow("Lacustrine Architecture Interpretation",implode($content->lacustrine_architecture_interpretation, ", "),30);
											}

											if($content->other_lacustrine_architecture_interpretation!=""){
												$pdf->valueRow("Other Lacustrine Architecture Interpretation",$content->other_lacustrine_architecture_interpretation,30);
											}

											if($content->carbonate_platform_geometry!=""){
												$pdf->valueRow("Carbonate Platform Geometry",implode($content->carbonate_platform_geometry, ", "),30);
											}

											if($content->other_platform_geometry!=""){
												$pdf->valueRow("Other Platform Geometry",$content->other_platform_geometry,30);
											}

											if($content->deep_water_architctural_element!=""){
												$pdf->valueRow("Deep-Water Architectural Elements",implode($content->deep_water_architctural_element, ", "),30);
											}

											if($content->other_deep_water_architectural_element!=""){
												$pdf->valueRow("Other Deep-Water Architectural Element",$content->other_deep_water_architectural_element,30);
											}

											if($content->carbonate_margin_geometry!=""){
												$pdf->valueRow("Carbonate Margin Geometry",implode($content->carbonate_margin_geometry, ", "),30);
											}

											if($content->other_carbonate_margin_geometry!=""){
												$pdf->valueRow("Other Carbonate Margin Geometry",$content->other_carbonate_margin_geometry,30);
											}

											if($content->notes!=""){
												$pdf->valueRow("Notes",$content->notes,30);
											}

										}
									}

								}

								if($spot['pet']){
									if($spot['pet']->metamorphic){
										$pdf->valueTitle("Metamorphic Rock(s): ", 15);
										$rocks = $spot['pet']->metamorphic;

										$rockNum = 1;
										foreach($rocks as $r){
											$rockString = "";
											$rockString .= ", " . $r->metamorphic_rock_type;
											$rockString .= ", " . implode(", ", $r->facies);
											$rockString .= ", " . $r->protolith;
											$rockString .= ", " . implode(", ", $r->zone);

											$pdf->valueRow($rockNum, $rockString, 20);
											$rockNum++;
										}

										if($content->section_well_name!=""){
											$pdf->valueRow("Section/Well Name",$content->section_well_name,25);
										}
									}

									if($spot['pet']->igneous){
										$pdf->valueTitle("Igneous Rock(s): ", 15);
										$rocks = $spot['pet']->igneous;

										$rockNum = 1;
										foreach($rocks as $r){
											$rockString = "";
											if($r->igneous_rock_class) $rockString .= ", " . $r->igneous_rock_class;
											if($r->volcanic_rock_type) $rockString .= ", " . $r->volcanic_rock_type;
											if($r->occurence_volcanic) $rockString .= ", " . $r->occurence_volcanic;
											if($r->plutonic_rock_type) $rockString .= ", " . $r->plutonic_rock_type;
											if($r->occurence_plutonic) $rockString .= ", " . $r->occurence_plutonic;
											if($r->texture_volcanic) $rockString .= ", " . implode(", ", $r->texture_volcanic);
											if($r->texture_plutonic) $rockString .= ", " . implode(", ", $r->texture_plutonic);
											if($r->color_index_volc) $rockString .= ", " . $r->color_index_volc;
											if($r->color_index_pluton) $rockString .= ", " . $r->color_index_pluton;
											if($r->color_index_source_volc) $rockString .= ", " . $r->color_index_source_volc;
											if($r->color_index_source_pluton) $rockString .= ", " . $r->color_index_source_pluton;
											if($r->alteration_volcanic) $rockString .= ", " . implode(", ", $r->alteration_volcanic);
											if($r->alteration_plutonic) $rockString .= ", " . implode(", ", $r->alteration_plutonic);

											$pdf->valueRow($rockNum, $rockString, 20);
											$rockNum++;
										}

										if($content->section_well_name!=""){
											$pdf->valueRow("Section/Well Name",$content->section_well_name,25);
										}
									}

									if($spot['pet']->minerals){
										$pdf->valueTitle("Mineral(s): ", 15);
										$rocks = $spot['pet']->minerals;

										$rockNum = 1;
										foreach($rocks as $r){
											$rockString = "";
											if($r->full_mineral_name) $rockString .= ", " . $r->full_mineral_name;
											if($r->igneous_or_metamorphic == "ig_min"){
												$rockString .= " (Igneous)";
											}else{
												$rockString .= " (Metamorphic)";
											}
											if($r->average_grain_size_mm) $rockString .= ", Avg Size: " . $r->average_grain_size_mm . "mm";
											if($r->maximum_grain_size_mm) $rockString .= ", Max Size: " . $r->maximum_grain_size_mm . "mm";
											if($r->modal) $rockString .= ", Modal: " . $r->modal . "%";
											if($r->mineral_notes) $rockString .= " " . $r->mineral_notes;

											$pdf->valueRow($rockNum, $rockString, 20);
											$rockNum++;
										}

										if($content->section_well_name!=""){
											$pdf->valueRow("Section/Well Name",$content->section_well_name,25);
										}
									}

								}

								if($spot['images']){

									$pdf->valueRow("Images","",15);
									$pdf->Ln(1);
									foreach($spot['images'] as $o){
										if($o['title']){
											$thistitle = $this->fixLabel($o['title']);
											$pdf->valueTitle($thistitle.": ",20);
										}else{
											$thistitle = $o['id'];
										}

										foreach($o as $key=>$value){
											if($value != ""){
												if($key!="id" && $key!="self" && $key!="annotated" && $key!="title" && $key!="width" && $key!="height" && $key!="image_type" && $key!="caption" ){
													$key = $this->fixLabel($key);
													if(is_string($value)){
														$value = $this->fixLabel($value);
													}
													$pdf->valueRow($key,$value,20);
												}
											}
										}

										$pdf->Ln(1);

										$filename = $this->strabo->getImageFilename($o['id']);

										if($filename){
											$gdimage = $this->gdThumb($filename);
											if($gdimage){
												$pdf->GDImage($gdimage, 20, null, 60);
											}
										}

										if($o['caption'] != ""){
											$pdf->imageCaptionRow("Caption", $o['caption'], 20);
										}

										$pdf->Ln(1);
										$pdf->Ln(1);
										$pdf->Ln(1);
										$pdf->Ln(1);
										$pdf->Ln(1);

									}
								}

								$pdf->Ln(10);

							}else{ //end if date matches
							}

						}//end foreach spots

					}// end foreach spotdates

					$filedate = date("m_d_Y");
					$pdfname="StraboSpot_Field_Book_$filedate.pdf";
					$pdf->Output($pdfname,"D"); //Download this is the final

				}else{

					echo "No spots found for this search.";

				}

			} //end if dsids

		}

	public function bkupfieldbookOut20240610(){

			if($this->get['dsids']!=""){

				$dsids=$this->get['dsids'];
				$this->alltags = $this->strabo->getTagsFromDatasetIds($dsids);

				$json = $this->strabo->getDatasetSpotsSearch(null,$this->get);

				$dsname = $this->strabo->getDatasetName($dsids);

				$dailynotes = $this->strabo->getDailyNotesFromDatasetID($dsids);

				$spots = $json['features'];

				//unix time 1567364734  10 digits
				//id 15609769344266     14 digits

				//get day for each spot

				//create date array to loop over

				if(count($spots)>0){

					$spotdates = [];
					$founddates = [];
					foreach($spots as $spot){

						$ut = substr($spot['properties']['id'], 0 ,10);

						$datestring = date("l, F j, Y", $ut);

						if(!in_array($datestring, $founddates)){
							$founddates[] = $datestring;
							$showformat = date("l, F j, Y", $ut);
							$lookformat = date("Y-m-d", $ut);
							$thisobj = new stdClass();
							$thisobj->showformat = $showformat;
							$thisobj->lookformat = $lookformat;
							$spotdates[] = $thisobj;
						}

					}

					require('includes/PDF_LabBook.php');

					$pdf = new PDF_MemImage('P','mm','Letter');

					$pdf->AddFont('msjh','','msjh.ttf',true);

					$pdf->AddPage();

					if($dsname != ""){
						$pdf->datasetTitle($dsname);
					}

					foreach($spotdates as $spotdate){

						$showdate = $spotdate->showformat;
						$pdf->dayTitle($showdate);

						//look for daily notes (format in data is YYYY-MM-DD)
						$lookdate = $spotdate->lookformat;

						if($dailynotes!=""){
							foreach($dailynotes as $dn){
								$dndate = substr($dn->date,0,10);

								if($dndate == $lookdate){
									$pdf->dailyNotesRow("Daily Setup Notes",$dn->notes,10);
								}
							}
						}

						foreach($spots as $spot){

							$rawspot = $spot;

							$spot = $spot['properties'];

							$id = $spot['id'];

							$ut = substr($id, 0 ,10);

							$thisspotdate = date("l, F j, Y", $ut);

							if($thisspotdate == $spotdate->showformat){

								$spotname = $spot['name'];
								if($spot['geometrytype']){
									$spotname .= " (".$spot['geometrytype'].")";
								}

								$pdf->spotTitle($spotname);

								$modified = (string) $spot['id'];
								$modified = substr($modified,0,10);
								$modified = date("F j, Y",$modified);
								$pdf->valueRow("Created",$modified,15);

								$modified = (string) $spot['modified_timestamp'];
								$modified = substr($modified,0,10);
								$modified = date("F j, Y",$modified);
								$pdf->valueRow("Last Modified",$modified,15);

								if($rawspot['geometry']->type=="Point" && $rawspot['properties']['strat_section_id']==""){
									$pdf->valueRow("Longitude",$rawspot['geometry']->coordinates[0],15);
									$pdf->valueRow("Latitude",$rawspot['geometry']->coordinates[1],15);
								}else{
								}

								if($spot['notes']){
									$notes = $spot['notes'];
									$pdf->notesRow("Notes",$notes,15);
								}

								if($spot['surface_feature']){
									foreach($spot['surface_feature'] as $key=>$value){
										$key = $this->fixLabel($key);
										if(is_string($value)){
											$value = $this->fixLabel($value);
										}
										$pdf->valueRow($key,$value,15);
									}
								}

								if($spot['orientation_data']){
									$pdf->valueRow("Orientations","",15);
									foreach($spot['orientation_data'] as $o){
										$pdf->valueTitle($this->fixLabel($o->type).": ",20);
										foreach($o as $key=>$value){
											if($key!="id" && $key!="associated_orientation" && $key!="type"){
												$key = $this->fixLabel($key);
												if(is_string($value)){
													$value = $this->fixLabel($value);
												}
												$pdf->valueRow($key,$value,20);
											}
										}

										if($o->associated_orientation){
											$pdf->valueRow("Associated Orientation Data","",20);
											foreach($o->associated_orientation as $ao){
												$pdf->valueTitle($this->fixLabel($ao->type).": ",30);
												foreach($ao as $key=>$value){
													if($key!="id" && $key!="associated_orientation" && $key!="type"){
														$key = $this->fixLabel($key);
														if(is_string($value)){
															$value = $this->fixLabel($value);
														}
														$pdf->valueRow($key,$value,30);
													}
												}
												$pdf->Ln(1);
											}
										}

										$pdf->Ln(1);
									}
								}

								if($spot['_3d_structures']){
									$pdf->valueRow("3D Structures","",15);
									foreach($spot['_3d_structures'] as $o){
										$pdf->valueTitle($this->fixLabel($o->type).": ",20);
										foreach($o as $key=>$value){
											if($key!="id" && $key!="type"){
												$key = $this->fixLabel($key);
												if(is_string($value)){
													$value = $this->fixLabel($value);
												}
												$pdf->valueRow($key,$value,20);
											}
										}

										$pdf->Ln(1);
									}
								}

								if($spot['samples']){
									$pdf->valueRow("Samples","",15);
									foreach($spot['samples'] as $o){
										$pdf->valueTitle($this->fixLabel($o->label).": ",20);
										foreach($o as $key=>$value){
											if($key!="id" && $key!="label"){
												$key = $this->fixLabel($key);
												if(is_string($value)){
													$value = $this->fixLabel($value);
												}
												$pdf->valueRow($key,$value,20);
											}
										}

										$pdf->Ln(1);
									}
								}

								if($spot['tephra']){
									$pdf->valueRow("Tephra Intervals","",15);
									foreach($spot['tephra'] as $o){
										$pdf->valueTitle("Interval: ",20);
										foreach($o as $key=>$value){
											if($key!="id" && $key!="label"){
												$key = $this->fixLabel($key);
												if(is_array($value)){
													$value = implode(", ", $value);
												}elseif(is_string($value)){
													$value = $this->fixLabel($value);
												}
												$pdf->valueRow($key,$value,20);
											}
										}

										$pdf->Ln(1);
									}
								}

								if($spot['other_features']){
									$pdf->valueRow("Other Features","",15);
									foreach($spot['other_features'] as $o){
										$pdf->valueTitle($this->fixLabel($o->label).": ",20);
										foreach($o as $key=>$value){
											if($key!="id" && $key!="label"){
												$key = $this->fixLabel($key);
												if(is_string($value)){
													$value = $this->fixLabel($value);
												}
												$pdf->valueRow($key,$value,20);
											}
										}

										$pdf->Ln(1);
									}
								}

								$hastags = "no";

								if($this->alltags){
									foreach($this->alltags as $tag){
										$found = "no";
										if($tag->spots){
											if($tag->type!="geologic_unit"){
												foreach($tag->spots as $spotid){
													if($spotid == $id){
														$hastags = "yes";
													}
												}
											}
										}

									}
								}

								if($hastags == "yes"){

									$pdf->valueRow("Tags","",15);

									if($this->alltags){
										foreach($this->alltags as $tag){
											$found = "no";
											if($tag->spots){
												if($tag->type!="geologic_unit"){
													foreach($tag->spots as $spotid){
														if($spotid == $id){
															$found = "yes";
														}
													}
												}
											}

											if($found == "yes"){

												$pdf->valueTitle($tag->name,20);
												foreach($tag as $key=>$value){

													if($key != "date" && $key != "spots" && $key != "features" && $key != "id" && $key != "name" ){
														$key = $this->fixLabel($key);
														if(is_string($value)){
															$value = $this->fixLabel($value);
														}elseif(is_array($value)){
															$value = implode(", ", $value);
														}
														$pdf->valueRow($key,$value,20);
													}

												}

												$pdf->Ln(1);

											}
										}
									}

								}

								$hastags = "no";

								if($this->alltags){
									foreach($this->alltags as $tag){
										$found = "no";
										if($tag->spots){
											if($tag->type=="geologic_unit"){
												foreach($tag->spots as $spotid){
													if($spotid == $id){
														$hastags = "yes";
													}
												}
											}
										}

									}
								}

								if($hastags == "yes"){

									$pdf->valueRow("Geologic Unit(s)","",15);

									if($this->alltags){
										foreach($this->alltags as $tag){
											$found = "no";
											if($tag->spots){
												if($tag->type=="geologic_unit"){
													foreach($tag->spots as $spotid){
														if($spotid == $id){
															$found = "yes";
														}
													}
												}
											}

											if($found == "yes"){

												$pdf->valueTitle($tag->name,20);
												foreach($tag as $key=>$value){

													if($key != "date" && $key != "spots" && $key != "features" && $key != "id" && $key != "name" ){
														$key = $this->fixLabel($key);
														if(is_string($value)){
															$value = $this->fixLabel($value);
														}elseif(is_array($value)){
															$value = implode(", ", $value);
														}
														$pdf->valueRow($key,$value,20);
													}

												}

												$pdf->Ln(1);

											}
										}
									}

								}

								if($spot['sed']){
									$pdf->valueRow("Sed","",15);
									if($spot['sed']->strat_section){
										$pdf->valueTitle("Strat Section: ", 20);
										$content = $spot['sed']->strat_section;

										if($content->section_well_name!=""){
											$pdf->valueRow("Section/Well Name",$content->section_well_name,25);
										}

										if($content->column_profile!=""){
											$pdf->valueRow("Column Profile",$content->column_profile,25);
										}

										if($content->column_y_axis_units!=""){
											$pdf->valueRow("Column Y-Axis Units",$content->column_y_axis_units,25);
										}

										if($content->section_type!=""){
											$pdf->valueRow("Section Type",$content->section_type,25);
										}

										if($content->what_core_repository!=""){
											$pdf->valueRow("What Core Repository?",$content->what_core_repository,25);
										}

										if($content->type_of_corer!=""){
											$pdf->valueRow("Type of corer",$content->type_of_corer,25);
										}

										if($content->depth_from_surface_to_start_of!=""){
											$pdf->valueRow("Depth from surface to start of core",$content->depth_from_surface_to_start_of,25);
										}

										if($content->total_core_length!=""){
											$pdf->valueRow("Total core length",$content->total_core_length,25);
										}

										if($content->location_locality!=""){
											$pdf->valueRow("Location/Locality",$content->location_locality,25);
										}

										if($content->basin!=""){
											$pdf->valueRow("Basin",$content->basin,25);
										}

										if($content->age!=""){
											$pdf->valueRow("Age",$content->age,25);
										}

										if($content->purpose!=""){
											$pdf->valueRow("Purpose",implode($content->purpose, ", "),25);
										}

										if($content->other_purpose!=""){
											$pdf->valueRow("Other Purpose",$content->other_purpose,25);
										}

										if($content->project_description!=""){
											$pdf->valueRow("Project Description",$content->project_description,25);
										}

										if($content->dates_of_work!=""){
											$pdf->valueRow("Dates of Work",$content->dates_of_work,25);
										}

										if($content->scale_of_interest!=""){
											$pdf->valueRow("Scale of Interest",implode($content->scale_of_interest, ", "),25);
										}

										if($content->other_scale_of_interest!=""){
											$pdf->valueRow("Other Scale of Interest",$content->other_scale_of_interest,25);
										}

										if($content->obs_interval_bed_obs_scale!=""){
											$pdf->valueRow("Observation Interval (average bed/observation scale)",$content->obs_interval_bed_obs_scale,25);
										}

										if($content->how_is_section_georeferenced!=""){
											$pdf->valueRow("How is the Section Georeferenced?",$content->how_is_section_georeferenced,25);
										}

										if($content->strat_section_notes!=""){
											$pdf->valueRow("Notes",$content->strat_section_notes,25);
										}

										//strat section link here
										$pdf->httpLink("Download Strat Section", 25, "https://strabospot.org/pstrat_section?id=".$spot['id']."&did=".$dsids);

									}

									if($spot['sed']->lithologies){
										$pdf->valueTitle("Lithologies: ", 20);
										$contents = $spot['sed']->lithologies;
										foreach($contents as $content){
											$pdf->valueTitle("Primary Lithology: ", 25);

											if($content->primary_lithology!=""){
												$pdf->valueRow("Primary Lithology",$content->primary_lithology,30);
											}

											if($content->siliciclastic_type!=""){
												$pdf->valueRow("Siliciclastic Type",$content->siliciclastic_type,30);
											}

											if($content->dunham_classification!=""){
												$pdf->valueRow("Dunham Classification",$content->dunham_classification,30);
											}

											if($content->grain_type!=""){
												$pdf->valueRow("Grain Type",$content->grain_type,30);
											}

											if($content->evaporite_type!=""){
												$pdf->valueRow("Evaporite type",implode($content->evaporite_type, ", "),30);
											}

											if($content->other_evaporite_type!=""){
												$pdf->valueRow("Other Evaporite Type",$content->other_evaporite_type,30);
											}

											if($content->organic_coal_lithologies!=""){
												$pdf->valueRow("Organic/Coal Lithologies",implode($content->organic_coal_lithologies, ", "),30);
											}

											if($content->other_organic_coal_lithology!=""){
												$pdf->valueRow("Other Organic/Coal Lithology",$content->other_organic_coal_lithology,30);
											}

											if($content->volcaniclastic_type!=""){
												$pdf->valueRow("Volcaniclastic type",implode($content->volcaniclastic_type, ", "),30);
											}

											if($content->other_volcaniclastic_type!=""){
												$pdf->valueRow("Other Volcaniclastic Type",$content->other_volcaniclastic_type,30);
											}

											if($content->report_presence_of_particle_ag!=""){
												$pdf->valueRow("Report presence of particle aggregates ",$content->report_presence_of_particle_ag,30);
											}

											if($content->componentry!=""){
												$pdf->valueRow("Componentry",implode($content->componentry, ", "),30);
											}

											if($content->approximate_relative_abundance!=""){
												$pdf->valueRow("Approximate relative abundances of clasts",$content->approximate_relative_abundance,30);
											}

											if($content->phosphorite_type!=""){
												$pdf->valueRow("Phosphorite type",implode($content->phosphorite_type, ", "),30);
											}

											if($content->other_phosphorite_type!=""){
												$pdf->valueRow("Other Phosphorite Type",$content->other_phosphorite_type,30);
											}

											$pdf->valueTitle("Lithification & Color: ", 25);

											if($content->relative_resistance_weather!=""){
												$pdf->valueRow("Relative resistance (weathering profile)",$content->relative_resistance_weather,30);
											}

											if($content->lithification!=""){
												$pdf->valueRow("Lithification",$content->lithification,30);
											}

											if($content->evidence_of_deposit_alteration!=""){
												$pdf->valueRow("Evidence of deposit alteration",$content->evidence_of_deposit_alteration,30);
											}

											if($content->evidence_of_clast_alteration!=""){
												$pdf->valueRow("Evidence of clast alteration",$content->evidence_of_clast_alteration,30);
											}

											if($content->fresh_color!=""){
												$pdf->valueRow("Fresh Color",$content->fresh_color,30);
											}

											if($content->weathered_color!=""){
												$pdf->valueRow("Weathered Color",$content->weathered_color,30);
											}

											if($content->color_appearance!=""){
												$pdf->valueRow("Color Appearance",implode($content->color_appearance, ", "),30);
											}

											if($content->other_color_appearance!=""){
												$pdf->valueRow("Other Color Appearance",$content->other_color_appearance,30);
											}

											if($content->notes!=""){
												$pdf->valueRow("Notes",$content->notes,30);
											}

											$pdf->valueTitle("Composition: ", 25);

											if($content->minerals_present!=""){
												$pdf->valueRow("Minerals Present",implode($content->minerals_present, ", "),30);
											}

											if($content->other_minerals!=""){
												$pdf->valueRow("Other Mineral(s)",$content->other_minerals,30);
											}

											if($content->sandstone_type_dott!=""){
												$pdf->valueRow("Dott Classification",implode($content->sandstone_type_dott, ", "),30);
											}

											if($content->sandstone_type_folk_mcbride!=""){
												$pdf->valueRow("Folk/McBride Classification",implode($content->sandstone_type_folk_mcbride, ", "),30);
											}

											if($content->sandstone_modifier!=""){
												$pdf->valueRow("Sandstone modifier",implode($content->sandstone_modifier, ", "),30);
											}

											if($content->other_sandstone_modifier!=""){
												$pdf->valueRow("Other Sandstone Modifier",$content->other_sandstone_modifier,30);
											}

											if($content->skeletal_carbonate_components!=""){
												$pdf->valueRow("Skeletal Carbonate Components",implode($content->skeletal_carbonate_components, ", "),30);
											}

											if($content->other_skeletal_carbonate_component!=""){
												$pdf->valueRow("Other Skeletal Carbonate Component",$content->other_skeletal_carbonate_component,30);
											}

											if($content->skeletal_general_percent!=""){
												$pdf->valueRow("Skeletal (General) percent",$content->skeletal_general_percent,30);
											}

											if($content->mollusc_percent!=""){
												$pdf->valueRow("Mollusc percent",$content->mollusc_percent,30);
											}

											if($content->brachiopod_percent!=""){
												$pdf->valueRow("Brachiopod percent",$content->brachiopod_percent,30);
											}

											if($content->coral_percent!=""){
												$pdf->valueRow("Coral percent",$content->coral_percent,30);
											}

											if($content->echinoderm_percent!=""){
												$pdf->valueRow("Echinoderm percent",$content->echinoderm_percent,30);
											}

											if($content->bryozoan_percent!=""){
												$pdf->valueRow("Bryozoan percent",$content->bryozoan_percent,30);
											}

											if($content->calcareous_algae_percent!=""){
												$pdf->valueRow("Calcareous Algae percent",$content->calcareous_algae_percent,30);
											}

											if($content->foraminifera_percent!=""){
												$pdf->valueRow("Foraminifera percent",$content->foraminifera_percent,30);
											}

											if($content->stromatolite_percent!=""){
												$pdf->valueRow("Stromatolite percent",$content->stromatolite_percent,30);
											}

											if($content->thrombolite_percent!=""){
												$pdf->valueRow("Thrombolite percent",$content->thrombolite_percent,30);
											}

											if($content->dendrolite_percent!=""){
												$pdf->valueRow("Dendrolite percent",$content->dendrolite_percent,30);
											}

											if($content->leiolite_percent!=""){
												$pdf->valueRow("Leiolite percent",$content->leiolite_percent,30);
											}

											if($content->other_skeletal_carbonate_component_percent!=""){
												$pdf->valueRow("Other Skeletal Carbonate Component percent",$content->other_skeletal_carbonate_component_percent,30);
											}

											if($content->non_skeletal_carbonate_compone!=""){
												$pdf->valueRow("Non-Skeletal Carbonate Components",implode($content->non_skeletal_carbonate_compone, ", "),30);
											}

											if($content->other_non_skeletal_carbonate_component!=""){
												$pdf->valueRow("Other Non-Skeletal Carbonate Component",$content->other_non_skeletal_carbonate_component,30);
											}

											if($content->mud_percent!=""){
												$pdf->valueRow("Mud percent",$content->mud_percent,30);
											}

											if($content->cement_percent!=""){
												$pdf->valueRow("Cement percent",$content->cement_percent,30);
											}

											if($content->intraclast_percent!=""){
												$pdf->valueRow("Intraclast percent",$content->intraclast_percent,30);
											}

											if($content->peloid_percent!=""){
												$pdf->valueRow("Peloid percent",$content->peloid_percent,30);
											}

											if($content->ooid_percent!=""){
												$pdf->valueRow("Ooid percent",$content->ooid_percent,30);
											}

											if($content->oncoid_percent!=""){
												$pdf->valueRow("Oncoid percent",$content->oncoid_percent,30);
											}

											if($content->pisoid_percent!=""){
												$pdf->valueRow("Pisoid percent",$content->pisoid_percent,30);
											}

											if($content->coated_grian_percent!=""){
												$pdf->valueRow("Coated Grian percent",$content->coated_grian_percent,30);
											}

											if($content->grapestone_percent!=""){
												$pdf->valueRow("Grapestone percent",$content->grapestone_percent,30);
											}

											if($content->giant_ooid_percent!=""){
												$pdf->valueRow("Giant Ooid percent",$content->giant_ooid_percent,30);
											}

											if($content->seafloor_precipitate_percent!=""){
												$pdf->valueRow("Seafloor Precipitate percent",$content->seafloor_precipitate_percent,30);
											}

											if($content->molar_tooth_percent!=""){
												$pdf->valueRow("Molar Tooth percent",$content->molar_tooth_percent,30);
											}

											if($content->other_non_skeletal_component_percent!=""){
												$pdf->valueRow("Other Non-Skeletal Component percent",$content->other_non_skeletal_component_percent,30);
											}

											if($content->clay_or_mudstone_type!=""){
												$pdf->valueRow("Claystone or Mudstone Type",implode($content->clay_or_mudstone_type, ", "),30);
											}

											if($content->other_claystone_mudstone!=""){
												$pdf->valueRow("Other Claystone/Mudstone",$content->other_claystone_mudstone,30);
											}

											if($content->siliceous_mudstone_percent!=""){
												$pdf->valueRow("Siliceous mudstone percent",$content->siliceous_mudstone_percent,30);
											}

											if($content->siliceous_calcareous_mudstone_percent!=""){
												$pdf->valueRow("Siliceous calcareous mudstone percent",$content->siliceous_calcareous_mudstone_percent,30);
											}

											if($content->siliceous_volcanicla_tic_mudstone_percent!=""){
												$pdf->valueRow("Siliceous volcaniclastic mudstone percent",$content->siliceous_volcanicla_tic_mudstone_percent,30);
											}

											if($content->calcareous_mudstone_percent!=""){
												$pdf->valueRow("Calcareous mudstone percent",$content->calcareous_mudstone_percent,30);
											}

											if($content->black_shale_percent!=""){
												$pdf->valueRow("Black shale percent",$content->black_shale_percent,30);
											}

											if($content->red_clay_percent!=""){
												$pdf->valueRow("Red clay percent",$content->red_clay_percent,30);
											}

											if($content->red_mudstone_percent!=""){
												$pdf->valueRow("Red mudstone percent",$content->red_mudstone_percent,30);
											}

											if($content->green_mudstone_percent!=""){
												$pdf->valueRow("Green mudstone percent",$content->green_mudstone_percent,30);
											}

											if($content->variegated_mudstone_percent!=""){
												$pdf->valueRow("Variegated mudstone percent",$content->variegated_mudstone_percent,30);
											}

											if($content->marl_percent!=""){
												$pdf->valueRow("Marl percent",$content->marl_percent,30);
											}

											if($content->sarl_percent!=""){
												$pdf->valueRow("Sarl percent",$content->sarl_percent,30);
											}

											if($content->argillaceous_mudstone_percent!=""){
												$pdf->valueRow("Argillaceous mudstone percent",$content->argillaceous_mudstone_percent,30);
											}

											if($content->conglomerate_composition!=""){
												$pdf->valueRow("Conglomerate/Breccia Composition",implode($content->conglomerate_composition, ", "),30);
											}

											if($content->clast_composition!=""){
												$pdf->valueRow("Clast Composition",implode($content->clast_composition, ", "),30);
											}

											if($content->other_clast_types!=""){
												$pdf->valueRow("Other Clast Type(s)",$content->other_clast_types,30);
											}

											if($content->intrusive_igneous_clast_percent!=""){
												$pdf->valueRow("Intrusive igneous clast percent",$content->intrusive_igneous_clast_percent,30);
											}

											if($content->extrusive_igneous_clast_percent!=""){
												$pdf->valueRow("Volcanic clast percent",$content->extrusive_igneous_clast_percent,30);
											}

											if($content->metamorphic_clast_percent!=""){
												$pdf->valueRow("Metamorphic clast percent",$content->metamorphic_clast_percent,30);
											}

											if($content->mudstone_clast_percent!=""){
												$pdf->valueRow("Mudstone clast percent",$content->mudstone_clast_percent,30);
											}

											if($content->siltstone_clast_percent!=""){
												$pdf->valueRow("Siltstone clast percent",$content->siltstone_clast_percent,30);
											}

											if($content->sandstone_clast_percent!=""){
												$pdf->valueRow("Sandstone clast percent",$content->sandstone_clast_percent,30);
											}

											if($content->conglomerate_clast_percent!=""){
												$pdf->valueRow("Conglomerate clast percent",$content->conglomerate_clast_percent,30);
											}

											if($content->limestone_clast_percent!=""){
												$pdf->valueRow("Limestone clast percent",$content->limestone_clast_percent,30);
											}

											if($content->dolostone_clast_percent!=""){
												$pdf->valueRow("Dolostone clast percent",$content->dolostone_clast_percent,30);
											}

											if($content->wackestone_clast_percent!=""){
												$pdf->valueRow("Wackestone clast percent",$content->wackestone_clast_percent,30);
											}

											if($content->packstone_clast_percent!=""){
												$pdf->valueRow("Packstone clast percent",$content->packstone_clast_percent,30);
											}

											if($content->grainstone_clast_percent!=""){
												$pdf->valueRow("Grainstone clast percent",$content->grainstone_clast_percent,30);
											}

											if($content->boundstone_clast_percent!=""){
												$pdf->valueRow("Boundstone clast percent",$content->boundstone_clast_percent,30);
											}

											if($content->other_clast_percent!=""){
												$pdf->valueRow("Other clast percent",$content->other_clast_percent,30);
											}

											if($content->matrix_composition!=""){
												$pdf->valueRow("Matrix composition",implode($content->matrix_composition, ", "),30);
											}

											if($content->other_matrix_types!=""){
												$pdf->valueRow("Other Matrix Type(s)",$content->other_matrix_types,30);
											}

											if($content->intrusive_igneous_matrix_percent!=""){
												$pdf->valueRow("Intrusive igneous matrix percent",$content->intrusive_igneous_matrix_percent,30);
											}

											if($content->extrusive_igneous_matrix_percent!=""){
												$pdf->valueRow("Extrusive igneous matrix percent",$content->extrusive_igneous_matrix_percent,30);
											}

											if($content->metamorphic_igneous_matrix_percent!=""){
												$pdf->valueRow("Metamorphic igneous matrix percent",$content->metamorphic_igneous_matrix_percent,30);
											}

											if($content->mudstone_matrix_percent!=""){
												$pdf->valueRow("Mudstone matrix percent",$content->mudstone_matrix_percent,30);
											}

											if($content->siltstone_matrix_percent!=""){
												$pdf->valueRow("Siltstone matrix percent",$content->siltstone_matrix_percent,30);
											}

											if($content->sandstone_matrix_percent!=""){
												$pdf->valueRow("Sandstone matrix percent",$content->sandstone_matrix_percent,30);
											}

											if($content->conglomerate_matrix_percent!=""){
												$pdf->valueRow("Conglomerate matrix percent",$content->conglomerate_matrix_percent,30);
											}

											if($content->carbonate_matrix_type!=""){
												$pdf->valueRow("Carbonate Matrix Type",implode($content->carbonate_matrix_type, ", "),30);
											}

											if($content->limestone_matrix_percent!=""){
												$pdf->valueRow("Limestone matrix percent",$content->limestone_matrix_percent,30);
											}

											if($content->dolostone_matrix_percent!=""){
												$pdf->valueRow("Dolostone matrix percent",$content->dolostone_matrix_percent,30);
											}

											if($content->skeletal_matrix_percent!=""){
												$pdf->valueRow("Skeletal matrix percent",$content->skeletal_matrix_percent,30);
											}

											if($content->wackestone_matrix_percent!=""){
												$pdf->valueRow("Wackestone matrix percent",$content->wackestone_matrix_percent,30);
											}

											if($content->packstone_matrix_percent!=""){
												$pdf->valueRow("Packstone matrix percent",$content->packstone_matrix_percent,30);
											}

											if($content->grainstone_matrix_percent!=""){
												$pdf->valueRow("Grainstone matrix percent",$content->grainstone_matrix_percent,30);
											}

											if($content->boundstone_matrix_percent!=""){
												$pdf->valueRow("Boundstone matrix percent",$content->boundstone_matrix_percent,30);
											}

											if($content->other_carbonate_matrix_percent!=""){
												$pdf->valueRow("Other carbonate matrix percent",$content->other_carbonate_matrix_percent,30);
											}

											if($content->other_matrix_percent!=""){
												$pdf->valueRow("Other matrix percent",$content->other_matrix_percent,30);
											}

											if($content->volcaniclastic_type!=""){
												$pdf->valueRow("Volcaniclastic type",implode($content->volcaniclastic_type, ", "),30);
											}

											if($content->other_volcaniclastic_type!=""){
												$pdf->valueRow("Other Volcaniclastic Type",$content->other_volcaniclastic_type,30);
											}

											if($content->glass_percent!=""){
												$pdf->valueRow("Glass percent",$content->glass_percent,30);
											}

											if($content->crystals_percent!=""){
												$pdf->valueRow("Crystals percent",$content->crystals_percent,30);
											}

											if($content->lithic_fragments_percent!=""){
												$pdf->valueRow("Lithic fragments percent",$content->lithic_fragments_percent,30);
											}

											if($content->volcanic_mudstone_percent!=""){
												$pdf->valueRow("Volcanic mudstone percent",$content->volcanic_mudstone_percent,30);
											}

											if($content->volcanic_sandstone_percent!=""){
												$pdf->valueRow("Volcanic sandstone percent",$content->volcanic_sandstone_percent,30);
											}

											if($content->lapillistone_percent!=""){
												$pdf->valueRow("Lapillistone percent",$content->lapillistone_percent,30);
											}

											if($content->agglomerate_percent!=""){
												$pdf->valueRow("Agglomerate percent",$content->agglomerate_percent,30);
											}

											if($content->volcanic_breccia_percent!=""){
												$pdf->valueRow("Volcanic breccia percent",$content->volcanic_breccia_percent,30);
											}

											if($content->bentonite_percent!=""){
												$pdf->valueRow("Bentonite percent",$content->bentonite_percent,30);
											}

											if($content->tuff_percent!=""){
												$pdf->valueRow("Tuff percent",$content->tuff_percent,30);
											}

											if($content->welded_tuff_percent!=""){
												$pdf->valueRow("Welded tuff percent",$content->welded_tuff_percent,30);
											}

											if($content->ignimbrite_percent!=""){
												$pdf->valueRow("Ignimbrite percent",$content->ignimbrite_percent,30);
											}

											if($content->other_volcaniclastic_type_percent!=""){
												$pdf->valueRow("Other volcaniclastic type percent",$content->other_volcaniclastic_type_percent,30);
											}

											if($content->evaporite_type!=""){
												$pdf->valueRow("Evaporite type",implode($content->evaporite_type, ", "),30);
											}

											if($content->gypsum_anhydrite_primary_percent!=""){
												$pdf->valueRow("Gypsum - anhydrite primary percent",$content->gypsum_anhydrite_primary_percent,30);
											}

											if($content->gypsum_anhydrite_primary_type!=""){
												$pdf->valueRow("Gypsum - anhydrite primary type",implode($content->gypsum_anhydrite_primary_type, ", "),30);
											}

											if($content->gypsum_anhydrite_diagenetic_percent!=""){
												$pdf->valueRow("Gypsum - anhydrite diagenetic percent",$content->gypsum_anhydrite_diagenetic_percent,30);
											}

											if($content->gypsum_anhydrite_diagenetic_type!=""){
												$pdf->valueRow("Gypsum - anhydrite diagenetic type",implode($content->gypsum_anhydrite_diagenetic_type, ", "),30);
											}

											if($content->halite_primary_percent!=""){
												$pdf->valueRow("Halite - primary percent",$content->halite_primary_percent,30);
											}

											if($content->halite_primary_type!=""){
												$pdf->valueRow("Halite - primary type",implode($content->halite_primary_type, ", "),30);
											}

											if($content->halite_diagenetic_percent!=""){
												$pdf->valueRow("Halite - diagenetic percent",$content->halite_diagenetic_percent,30);
											}

											if($content->halite_diagenetic_type!=""){
												$pdf->valueRow("Halite - diagenetic type",implode($content->halite_diagenetic_type, ", "),30);
											}

											if($content->phosphorite_type!=""){
												$pdf->valueRow("Phosphorite type",implode($content->phosphorite_type, ", "),30);
											}

											if($content->organic_coal_lithologies!=""){
												$pdf->valueRow("Organic/Coal Lithologies",implode($content->organic_coal_lithologies, ", "),30);
											}

											if($content->amber_percent!=""){
												$pdf->valueRow("Amber percent",$content->amber_percent,30);
											}

											if($content->peat_percent!=""){
												$pdf->valueRow("Peat percent",$content->peat_percent,30);
											}

											if($content->lignite_percent!=""){
												$pdf->valueRow("Lignite percent",$content->lignite_percent,30);
											}

											if($content->subbituminous_percent!=""){
												$pdf->valueRow("Subbituminous percent",$content->subbituminous_percent,30);
											}

											if($content->bituminous_percent!=""){
												$pdf->valueRow("Bituminous percent",$content->bituminous_percent,30);
											}

											if($content->coal_ball_percent!=""){
												$pdf->valueRow("Coal ball percent",$content->coal_ball_percent,30);
											}

											if($content->tar_percent!=""){
												$pdf->valueRow("Tar percent",$content->tar_percent,30);
											}

											if($content->notes!=""){
												$pdf->valueRow("Notes",$content->notes,30);
											}

											$pdf->valueTitle("Texture: ", 25);

											if($content->mud_silt_grain_size!=""){
												$pdf->valueRow("Mudstone/Siltstone Grain Size",$content->mud_silt_grain_size,30);
											}

											if($content->sand_grain_size!=""){
												$pdf->valueRow("Sandstone Grain Size",$content->sand_grain_size,30);
											}

											if($content->congl_grain_size!=""){
												$pdf->valueRow("Conglomerate Grain Size",$content->congl_grain_size,30);
											}

											if($content->breccia_grain_size!=""){
												$pdf->valueRow("Breccia Grain Size",$content->breccia_grain_size,30);
											}

											if($content->grain_size_range!=""){
												$pdf->valueRow("Grain Size Range",implode($content->grain_size_range, ", "),30);
											}

											if($content->clay_percent!=""){
												$pdf->valueRow("Clay percent",$content->clay_percent,30);
											}

											if($content->silt_percent!=""){
												$pdf->valueRow("Silt percent",$content->silt_percent,30);
											}

											if($content->sand_very_fine_percent!=""){
												$pdf->valueRow("Sand - very fine percent",$content->sand_very_fine_percent,30);
											}

											if($content->sand_fine_lower_percent!=""){
												$pdf->valueRow("Sand - fine lower percent",$content->sand_fine_lower_percent,30);
											}

											if($content->sand_fine_upper_percent!=""){
												$pdf->valueRow("Sand - fine upper percent",$content->sand_fine_upper_percent,30);
											}

											if($content->sand_medium_lower_percent!=""){
												$pdf->valueRow("Sand - medium lower percent",$content->sand_medium_lower_percent,30);
											}

											if($content->sand_medium_upper_percent!=""){
												$pdf->valueRow("Sand - medium upper percent",$content->sand_medium_upper_percent,30);
											}

											if($content->sand_coarse_lower_percent!=""){
												$pdf->valueRow("Sand - coarse lower percent",$content->sand_coarse_lower_percent,30);
											}

											if($content->sand_coarse_upper_percent!=""){
												$pdf->valueRow("Sand - coarse upper percent",$content->sand_coarse_upper_percent,30);
											}

											if($content->sand_very_coarse_percent!=""){
												$pdf->valueRow("Sand - very coarse percent",$content->sand_very_coarse_percent,30);
											}

											if($content->granule_percent!=""){
												$pdf->valueRow("Granule percent",$content->granule_percent,30);
											}

											if($content->pebble_percent!=""){
												$pdf->valueRow("Pebble percent",$content->pebble_percent,30);
											}

											if($content->cobble_percent!=""){
												$pdf->valueRow("Cobble percent",$content->cobble_percent,30);
											}

											if($content->boulder_percent!=""){
												$pdf->valueRow("Boulder percent",$content->boulder_percent,30);
											}

											if($content->maximum_clast_size_cm!=""){
												$pdf->valueRow("Maximum Clast Size (cm)",$content->maximum_clast_size_cm,30);
											}

											if($content->minimum_clast_size_cm!=""){
												$pdf->valueRow("Minimum Clast Size (cm)",$content->minimum_clast_size_cm,30);
											}

											if($content->average_clast_size_cm!=""){
												$pdf->valueRow("Average Clast Size (cm)",$content->average_clast_size_cm,30);
											}

											if($content->matrix_size!=""){
												$pdf->valueRow("Matrix Size",implode($content->matrix_size, ", "),30);
											}

											if($content->character!=""){
												$pdf->valueRow("Character",implode($content->character, ", "),30);
											}

											if($content->sorting!=""){
												$pdf->valueRow("Sorting",implode($content->sorting, ", "),30);
											}

											if($content->rounding!=""){
												$pdf->valueRow("Rounding",implode($content->rounding, ", "),30);
											}

											if($content->shape!=""){
												$pdf->valueRow("Shape",implode($content->shape, ", "),30);
											}

											if($content->other_shape!=""){
												$pdf->valueRow("Other Shape",$content->other_shape,30);
											}

											if($content->notes!=""){
												$pdf->valueRow("Notes",$content->notes,30);
											}

											if($content->stratification!=""){
												$pdf->valueRow("Stratification",implode($content->stratification, ", "),30);
											}

											if($content->laminae_thickness_i_select_more_than_one!=""){
												$pdf->valueRow("Laminae Thickness",implode($content->laminae_thickness_i_select_more_than_one, ", "),30);
											}

											if($content->lamination_character!=""){
												$pdf->valueRow("Lamination Character",implode($content->lamination_character, ", "),30);
											}

											if($content->bedding_thickness!=""){
												$pdf->valueRow("Bedding Thickness",implode($content->bedding_thickness, ", "),30);
											}

											if($content->bedding_character!=""){
												$pdf->valueRow("Bedding character",implode($content->bedding_character, ", "),30);
											}

											if($content->package_geometry!=""){
												$pdf->valueRow("Package Geometry",implode($content->package_geometry, ", "),30);
											}

											if($content->package_lateral_extent!=""){
												$pdf->valueRow("Package Lateral Extent",$content->package_lateral_extent,30);
											}

											if($content->package_bedding_trends!=""){
												$pdf->valueRow("Package Bedding Trends",$content->package_bedding_trends,30);
											}

											if($content->other_bedding_trend!=""){
												$pdf->valueRow("Other bedding trend",$content->other_bedding_trend,30);
											}

											if($content->shape_of_lower_contacts!=""){
												$pdf->valueRow("Shape of lower contact(s)",implode($content->shape_of_lower_contacts, ", "),30);
											}

											if($content->character_of_lower_contacts!=""){
												$pdf->valueRow("Character of lower contact(s)",implode($content->character_of_lower_contacts, ", "),30);
											}

											if($content->lower_contact_relief!=""){
												$pdf->valueRow("Lower contact relief",$content->lower_contact_relief,30);
											}

											if($content->shape_of_upper_contacts!=""){
												$pdf->valueRow("Shape of upper contact(s)",implode($content->shape_of_upper_contacts, ", "),30);
											}

											if($content->character_of_upper_contacts!=""){
												$pdf->valueRow("Character of upper contact(s)",implode($content->character_of_upper_contacts, ", "),30);
											}

											if($content->upper_contact_relief!=""){
												$pdf->valueRow("Upper Contact Relief",$content->upper_contact_relief,30);
											}

											if($content->interbed_proportion!=""){
												$pdf->valueRow("Interbed Relative Proportion (%)",$content->interbed_proportion,30);
											}

											if($content->interbed_thickness!=""){
												$pdf->valueRow("Interbed Thickness",$content->interbed_thickness,30);
											}

											if($content->interbed_proportion_change!=""){
												$pdf->valueRow("Interbed Proportion Change (Up Section)",$content->interbed_proportion_change,30);
											}

											if($content->interbed_thickness_change!=""){
												$pdf->valueRow("Interbed Thickness Change (Up Section)",$content->interbed_thickness_change,30);
											}

											if($content->Notes!=""){
												$pdf->valueRow("Notes",$content->Notes,30);
											}

										}

									}

									if($spot['sed']->bedding){
										$pdf->valueTitle("Bedding: ", 20);
										$content = $spot['sed']->bedding;

										if($content->interbed_proportion_change!=""){
											$pdf->valueRow("Interbed Proportion Change (Up Section)",$content->interbed_proportion_change,25);
										}

										if($content->interbed_proportion!=""){
											$pdf->valueRow("Lithology 1: Interbed Relative Proportion (%)",$content->interbed_proportion,25);
										}

										if($content->lithology_at_bottom_contact!=""){
											$pdf->valueRow("Which Lithology is at Bottom Contact?",$content->lithology_at_bottom_contact,25);
										}

										if($content->lithology_at_top_contact!=""){
											$pdf->valueRow("Which Lithology is at Top Contact?",$content->lithology_at_top_contact,25);
										}

										if($content->thickness_of_individual_beds!=""){
											$pdf->valueRow("Thickness of Individual Beds",$content->thickness_of_individual_beds,25);
										}

										if($content->package_thickness_units!=""){
											$pdf->valueRow("Package Beds Thickness Units",$content->package_thickness_units,25);
										}

										if($content->package_bedding_trends!=""){
											$pdf->valueRow("Package Bedding Trends",$content->package_bedding_trends,25);
										}

										if($content->other_bedding_trend!=""){
											$pdf->valueRow("Other Package Bedding Trend",$content->other_bedding_trend,25);
										}

										$beds = $spot['sed']->bedding->beds;
										foreach($beds as $content){

											if($content->package_geometry!=""){
												$pdf->valueRow("Bed Geometry",implode($content->package_geometry, ", "),25);
											}

											$pdf->valueTitle("Lower Contact: ", 25);

											if($content->shape_of_lower_contacts!=""){
												$pdf->valueRow("Shape of lower contact",implode($content->shape_of_lower_contacts, ", "),30);
											}

											if($content->character_of_lower_contacts!=""){
												$pdf->valueRow("Character of lower contact(s)",implode($content->character_of_lower_contacts, ", "),30);
											}

											if($content->lower_contact_relief!=""){
												$pdf->valueRow("Lower contact relief",$content->lower_contact_relief,30);
											}

											$pdf->valueTitle("Upper Contact: ", 25);

											if($content->shape_of_upper_contacts!=""){
												$pdf->valueRow("Shape of upper contact",implode($content->shape_of_upper_contacts, ", "),30);
											}

											if($content->character_of_upper_contacts!=""){
												$pdf->valueRow("Character of upper contact",implode($content->character_of_upper_contacts, ", "),30);
											}

											if($content->upper_contact_relief!=""){
												$pdf->valueRow("Upper Contact Relief",$content->upper_contact_relief,30);
											}

											$pdf->valueTitle("Interbed Thickness: ", 25);

											if($content->avg_thickness!=""){
												$pdf->valueRow("Average Thickness",$content->avg_thickness,30);
											}

											if($content->max_thickness!=""){
												$pdf->valueRow("Maximum Thickness",$content->max_thickness,30);
											}

											if($content->min_thickness!=""){
												$pdf->valueRow("Minimum Thickness",$content->min_thickness,30);
											}

											if($content->interbed_thickness_units!=""){
												$pdf->valueRow("Interbed Thickness Units",$content->interbed_thickness_units,30);
											}

											if($content->notes!=""){
												$pdf->valueRow("Notes",$content->notes,30);
											}

										}
									}

									if($spot['sed']->structures){
										$pdf->valueTitle("Structures: ", 20);
										$contents = $spot['sed']->structures;
										foreach($contents as $content){

											if($content->massive_structureless!=""){
												$pdf->valueRow("Massive/Structureless?",$content->massive_structureless,25);
											}

											$pdf->valueTitle("Cross Bedding: ", 25);

											if($content->cross_bedding_type!=""){
												$pdf->valueRow("Cross Bedding Type",implode($content->cross_bedding_type, ", "),30);
											}

											if($content->cross_bedding_height_cm!=""){
												$pdf->valueRow("Cross Bedding Height (cm)",$content->cross_bedding_height_cm,30);
											}

											if($content->cross_bedding_width_cm!=""){
												$pdf->valueRow("Cross Bedding Width (cm)",$content->cross_bedding_width_cm,30);
											}

											if($content->cross_bedding_thickness_cm!=""){
												$pdf->valueRow("Cross Bedding Thickness (cm)",$content->cross_bedding_thickness_cm,30);
											}

											if($content->cross_bedding_spacing_cm!=""){
												$pdf->valueRow("Cross Bedding Spacing (cm)",$content->cross_bedding_spacing_cm,30);
											}

											$pdf->valueTitle("Ripple Lamination: ", 25);

											if($content->ripple_lamination_type!=""){
												$pdf->valueRow("Ripple Lamination Type",implode($content->ripple_lamination_type, ", "),30);
											}

											if($content->other_ripple_lamination_type!=""){
												$pdf->valueRow("Other Ripple Lamination Type",$content->other_ripple_lamination_type,30);
											}

											if($content->ripple_lamination_height_mm!=""){
												$pdf->valueRow("Ripple Lamination Height (mm)",$content->ripple_lamination_height_mm,30);
											}

											if($content->ripple_lamination_width_mm!=""){
												$pdf->valueRow("Ripple Lamination Width (mm)",$content->ripple_lamination_width_mm,30);
											}

											if($content->ripple_lamination_thick_mm!=""){
												$pdf->valueRow("Ripple Lmation Thickness (mm)",$content->ripple_lamination_thick_mm,30);
											}

											if($content->ripple_lamination_spacing_mm!=""){
												$pdf->valueRow("Ripple Lamination Spacing (mm)",$content->ripple_lamination_spacing_mm,30);
											}

											$pdf->valueTitle("Horizontal Bedding: ", 25);

											if($content->horizontal_bedding_type!=""){
												$pdf->valueRow("Horizontal Bedding Type",implode($content->horizontal_bedding_type, ", "),30);
											}

											if($content->other_horizontal_bedding_type!=""){
												$pdf->valueRow("Other Horizontal Bedding Type",$content->other_horizontal_bedding_type,30);
											}

											$pdf->valueTitle("Graded Bedding: ", 25);

											if($content->graded_bedding_type!=""){
												$pdf->valueRow("Graded Bedding Type",$content->graded_bedding_type,30);
											}

											$pdf->valueTitle("Deformation Structures: ", 25);

											if($content->deformation_structures!=""){
												$pdf->valueRow("Deformation Structure Type",implode($content->deformation_structures, ", "),30);
											}

											if($content->other_deformation_structure_type!=""){
												$pdf->valueRow("Other Deformation Structure Type",$content->other_deformation_structure_type,30);
											}

											$pdf->valueTitle("Lags: ", 25);

											if($content->lag_type!=""){
												$pdf->valueRow("Lag Type",implode($content->lag_type, ", "),30);
											}

											if($content->other_lag_type!=""){
												$pdf->valueRow("Other Lag Type",$content->other_lag_type,30);
											}

											if($content->clast_composition!=""){
												$pdf->valueRow("Clast Composition",$content->clast_composition,30);
											}

											if($content->clast_size!=""){
												$pdf->valueRow("Clast Size",$content->clast_size,30);
											}

											if($content->layer_thickness_shape!=""){
												$pdf->valueRow("Layer Thickness/Shape",$content->layer_thickness_shape,30);
											}

											$pdf->valueTitle("Other Common Structures: ", 25);

											if($content->other_common_structures!=""){
												$pdf->valueRow("Other Common Structure Type",implode($content->other_common_structures, ", "),30);
											}

											if($content->bouma_sequence_part!=""){
												$pdf->valueRow("Bouma Sequence Part",implode($content->bouma_sequence_part, ", "),30);
											}

											if($content->bioturbation_index!=""){
												$pdf->valueRow("Bioturbation Index",$content->bioturbation_index,30);
											}

											if($content->bedding_plane_features!=""){
												$pdf->valueRow("Bedding plane features",implode($content->bedding_plane_features, ", "),30);
											}

											if($content->other_bedding_plane_feature!=""){
												$pdf->valueRow("Other Bedding Plane Feature",$content->other_bedding_plane_feature,30);
											}

											if($content->bedding_plane_features_scale!=""){
												$pdf->valueRow("Bedding Plane Features Scale",$content->bedding_plane_features_scale,30);
											}

											if($content->bedding_plane_features_orientation!=""){
												$pdf->valueRow("Bedding Plane Features Orientation",$content->bedding_plane_features_orientation,30);
											}

											if($content->bedform_type!=""){
												$pdf->valueRow("Bedform Type",implode($content->bedform_type, ", "),30);
											}

											if($content->other_bedform_type!=""){
												$pdf->valueRow("Other Bedform Type",$content->other_bedform_type,30);
											}

											if($content->bedform_scale!=""){
												$pdf->valueRow("Bedform Scale",$content->bedform_scale,30);
											}

											if($content->crest_orientation_azimuth_0_360!=""){
												$pdf->valueRow("Crest Orientation",$content->crest_orientation_azimuth_0_360,30);
											}

											$pdf->valueTitle("Pedogenic Structures: ", 25);

											if($content->paleosol_horizons!=""){
												$pdf->valueRow("Master Paleosol Horizons",implode($content->paleosol_horizons, ", "),30);
											}

											if($content->other_horizon!=""){
												$pdf->valueRow("Other Horizon",$content->other_horizon,30);
											}

											if($content->o_horizon_thickness_cm!=""){
												$pdf->valueRow("O Horizon thickness (cm)",$content->o_horizon_thickness_cm,30);
											}

											if($content->a_horizon_thickness_cm!=""){
												$pdf->valueRow("A Horizon thickness (cm)",$content->a_horizon_thickness_cm,30);
											}

											if($content->e_horizon_thickness_cm!=""){
												$pdf->valueRow("E Horizon thickness (cm)",$content->e_horizon_thickness_cm,30);
											}

											if($content->b_horizon_thickness_cm!=""){
												$pdf->valueRow("B Horizon thickness (cm)",$content->b_horizon_thickness_cm,30);
											}

											if($content->k_horizon_thickness_cm!=""){
												$pdf->valueRow("K Horizon thickness (cm)",$content->k_horizon_thickness_cm,30);
											}

											if($content->c_horizon_thickness_cm!=""){
												$pdf->valueRow("C Horizon thickness (cm)",$content->c_horizon_thickness_cm,30);
											}

											if($content->r_horizon_thickness_cm!=""){
												$pdf->valueRow("R Horizon thickness (cm)",$content->r_horizon_thickness_cm,30);
											}

											if($content->compound_thickness_cm!=""){
												$pdf->valueRow("Compound thickness (cm)",$content->compound_thickness_cm,30);
											}

											if($content->composite_thickness_cm!=""){
												$pdf->valueRow("Composite thickness (cm)",$content->composite_thickness_cm,30);
											}

											if($content->paleosol_structures!=""){
												$pdf->valueRow("Paleosol structures",implode($content->paleosol_structures, ", "),30);
											}

											if($content->other_structure!=""){
												$pdf->valueRow("Other Paleosol Structure",$content->other_structure,30);
											}

											if($content->additional_modifiers!=""){
												$pdf->valueRow("Additional modifiers",$content->additional_modifiers,30);
											}

											if($content->paleosol_classification!=""){
												$pdf->valueRow("Paleosol classification",implode($content->paleosol_classification, ", "),30);
											}

											if($content->notes!=""){
												$pdf->valueRow("Notes",$content->notes,30);
											}

										}

									}

									if($spot['sed']->diagenesis){
										$pdf->valueTitle("Diagenesis: ", 20);
										$contents = $spot['sed']->diagenesis;
										foreach($contents as $content){

											$pdf->valueTitle("Cement: ", 25);

											if($content->cement_composition!=""){
												$pdf->valueRow("Cement Mineralogy",implode($content->cement_composition, ", "),30);
											}

											if($content->other_cement_composition!=""){
												$pdf->valueRow("Other Cement Mineralogy",$content->other_cement_composition,30);
											}

											$pdf->valueTitle("Veins: ", 25);

											if($content->vein_type!=""){
												$pdf->valueRow("Vein Type",$content->vein_type,30);
											}

											if($content->vein_width!=""){
												$pdf->valueRow("Vein Width (cm)",$content->vein_width,30);
											}

											if($content->vein_length!=""){
												$pdf->valueRow("Vein Length (cm)",$content->vein_length,30);
											}

											if($content->vein_orientation!=""){
												$pdf->valueRow("Vein Orientation",$content->vein_orientation,30);
											}

											if($content->vein_mineralogy!=""){
												$pdf->valueRow("Vein Mineralogy",$content->vein_mineralogy,30);
											}

											if($content->other_vein_mineralogy!=""){
												$pdf->valueRow("Other Vein Mineralogy",$content->other_vein_mineralogy,30);
											}

											$pdf->valueTitle("Fractures: ", 25);

											if($content->fracture_type!=""){
												$pdf->valueRow("Fracture Type",$content->fracture_type,30);
											}

											if($content->fracture_width!=""){
												$pdf->valueRow("Fracture Width (cm)",$content->fracture_width,30);
											}

											if($content->fracture_length!=""){
												$pdf->valueRow("Fracture Length (cm)",$content->fracture_length,30);
											}

											if($content->fracture_orientation!=""){
												$pdf->valueRow("Fracture Orientation",$content->fracture_orientation,30);
											}

											if($content->fracture_mineralogy!=""){
												$pdf->valueRow("Fracture Mineralogy",$content->fracture_mineralogy,30);
											}

											if($content->other_fracture_mineralogy!=""){
												$pdf->valueRow("Other Fracture Mineralogy",$content->other_fracture_mineralogy,30);
											}

											$pdf->valueTitle("Nodules/Concretions: ", 25);

											if($content->nodules_concretions_size!=""){
												$pdf->valueRow("Nodules/Concretions Size",$content->nodules_concretions_size,30);
											}

											if($content->min!=""){
												$pdf->valueRow("Min",$content->min,30);
											}

											if($content->max!=""){
												$pdf->valueRow("Max",$content->max,30);
											}

											if($content->average!=""){
												$pdf->valueRow("Average",$content->average,30);
											}

											if($content->nodules_concretions_shape!=""){
												$pdf->valueRow("Nodules/Concretions Shape",implode($content->nodules_concretions_shape, ", "),30);
											}

											if($content->other_nodules_concretion_shape!=""){
												$pdf->valueRow("Other Nodule/Concretions Shape",$content->other_nodules_concretion_shape,30);
											}

											if($content->spacing!=""){
												$pdf->valueRow("Spacing",$content->spacing,30);
											}

											if($content->nodules_concretions_type!=""){
												$pdf->valueRow("Nodules/Concretions Type",$content->nodules_concretions_type,30);
											}

											if($content->other_nodules_concretions_type!=""){
												$pdf->valueRow("Other Nodules/Concretions Type",$content->other_nodules_concretions_type,30);
											}

											if($content->nodules_concretions_comp!=""){
												$pdf->valueRow("Nodules/Concretions Composition",implode($content->nodules_concretions_comp, ", "),30);
											}

											if($content->other_nodules_concretion_comp!=""){
												$pdf->valueRow("Other Nodules/Concretions Composition",$content->other_nodules_concretion_comp,30);
											}

											$pdf->valueTitle("Replacement: ", 25);

											if($content->replacement_type!=""){
												$pdf->valueRow("Replacement Type",$content->replacement_type,30);
											}

											if($content->other_replacement_type!=""){
												$pdf->valueRow("Other Replacement Type",$content->other_replacement_type,30);
											}

											$pdf->valueTitle("Recrystallization: ", 25);

											if($content->recrystallization_type!=""){
												$pdf->valueRow("Recrystallization Type",$content->recrystallization_type,30);
											}

											if($content->other_recrystallization_type!=""){
												$pdf->valueRow("Other Recrystallization Type",$content->other_recrystallization_type,30);
											}

											$pdf->valueTitle("Other Diagenetic Features: ", 25);

											if($content->other_diagenetic_features!=""){
												$pdf->valueRow("Other Diagenetic Features",implode($content->other_diagenetic_features, ", "),30);
											}

											if($content->other_features!=""){
												$pdf->valueRow("Other Features",$content->other_features,30);
											}

											$pdf->valueTitle("Porosity type: ", 25);

											if($content->fabric_selective!=""){
												$pdf->valueRow("Fabric Selective",implode($content->fabric_selective, ", "),30);
											}

											if($content->other_fabric_selective!=""){
												$pdf->valueRow("Other Fabric Selective",$content->other_fabric_selective,30);
											}

											if($content->non_selective!=""){
												$pdf->valueRow("Non-Frabric Selective",implode($content->non_selective, ", "),30);
											}

											if($content->other_non_selective!=""){
												$pdf->valueRow("Other Non-Fabric Selective",$content->other_non_selective,30);
											}

											$pdf->valueTitle("Carbonate Desiccation and Dissolution: ", 25);

											if($content->carbonate_desicc_and_diss!=""){
												$pdf->valueRow("Carbonate Desiccation and Dissolution Type",implode($content->carbonate_desicc_and_diss, ", "),30);
											}

											if($content->other_carbonate_desicc_diss!=""){
												$pdf->valueRow("Other Carbonate Desiccation and Dissolution Type",$content->other_carbonate_desicc_diss,30);
											}

											if($content->notes!=""){
												$pdf->valueRow("Notes",$content->notes,30);
											}

										}
									}

									if($spot['sed']->fossils){
										$pdf->valueTitle("Fossils: ", 20);
										$contents = $spot['sed']->fossils;
										foreach($contents as $content){

											$pdf->valueTitle("Body: ", 25);

											if($content->invertebrate!=""){
												$pdf->valueRow("Invertebrate",implode($content->invertebrate, ", "),30);
											}

											if($content->other_invertebrate!=""){
												$pdf->valueRow("Other Invertebrate",$content->other_invertebrate,30);
											}

											if($content->mollusca!=""){
												$pdf->valueRow("Mollusc",implode($content->mollusca, ", "),30);
											}

											if($content->other_mollusca!=""){
												$pdf->valueRow("Other Mollusc",$content->other_mollusca,30);
											}

											if($content->arthropoda!=""){
												$pdf->valueRow("Arthropod",implode($content->arthropoda, ", "),30);
											}

											if($content->other_anthropoda!=""){
												$pdf->valueRow("Other Arthropod",$content->other_anthropoda,30);
											}

											if($content->echinodermata!=""){
												$pdf->valueRow("Echinoderm",implode($content->echinodermata, ", "),30);
											}

											if($content->other_echinodermata!=""){
												$pdf->valueRow("Other Echinoderm",$content->other_echinodermata,30);
											}

											if($content->cnidaria!=""){
												$pdf->valueRow("Cnidarian",implode($content->cnidaria, ", "),30);
											}

											if($content->other_cnidaria!=""){
												$pdf->valueRow("Other Cnidarian",$content->other_cnidaria,30);
											}

											if($content->chordate!=""){
												$pdf->valueRow("Chordate",$content->chordate,30);
											}

											if($content->other_chordata!=""){
												$pdf->valueRow("Other Chordate",$content->other_chordata,30);
											}

											if($content->protista!=""){
												$pdf->valueRow("Protist",implode($content->protista, ", "),30);
											}

											if($content->other_protista!=""){
												$pdf->valueRow("Other Protist",$content->other_protista,30);
											}

											if($content->calcimicrobe!=""){
												$pdf->valueRow("Calcimicrobe",implode($content->calcimicrobe, ", "),30);
											}

											if($content->other_calcimicrobe!=""){
												$pdf->valueRow("Other Calcimicrobe",$content->other_calcimicrobe,30);
											}

											if($content->plant_algae!=""){
												$pdf->valueRow("Plant/algae",implode($content->plant_algae, ", "),30);
											}

											if($content->other_plant_algae!=""){
												$pdf->valueRow("Other Plant/Algae",$content->other_plant_algae,30);
											}

											if($content->green_algae!=""){
												$pdf->valueRow("Green Algae",implode($content->green_algae, ", "),30);
											}

											if($content->other_green_algae!=""){
												$pdf->valueRow("Other Green Algae",$content->other_green_algae,30);
											}

											if($content->vertebrate!=""){
												$pdf->valueRow("Vertebrate",implode($content->vertebrate, ", "),30);
											}

											if($content->other_vertebrate!=""){
												$pdf->valueRow("Other Vertebrate",$content->other_vertebrate,30);
											}

											if($content->faunal_assemblage!=""){
												$pdf->valueRow("Faunal assemblage",$content->faunal_assemblage,30);
											}

											if($content->other_faunal_assemblage!=""){
												$pdf->valueRow("Other faunal assemblage",$content->other_faunal_assemblage,30);
											}

											$pdf->valueTitle("Trace: ", 25);

											if($content->diversity!=""){
												$pdf->valueRow("Diversity",$content->diversity,30);
											}

											if($content->descriptive!=""){
												$pdf->valueRow("Descriptive",implode($content->descriptive, ", "),30);
											}

											if($content->other_descriptive!=""){
												$pdf->valueRow("Other Descriptive",$content->other_descriptive,30);
											}

											if($content->burrow_fill_type!=""){
												$pdf->valueRow("Burrow Fill Type",implode($content->burrow_fill_type, ", "),30);
											}

											if($content->other_burrow_fill!=""){
												$pdf->valueRow("Other Burrow Fill Type",$content->other_burrow_fill,30);
											}

											if($content->behavioral_grouping!=""){
												$pdf->valueRow("Behavioral grouping",$content->behavioral_grouping,30);
											}

											if($content->other_behavioral_grouping!=""){
												$pdf->valueRow("Other Behavioral Grouping",$content->other_behavioral_grouping,30);
											}

											if($content->ichnofacies!=""){
												$pdf->valueRow("Ichnofacies",$content->ichnofacies,30);
											}

											if($content->other_ichnofacies!=""){
												$pdf->valueRow("Other Ichnofacies",$content->other_ichnofacies,30);
											}

											if($content->list_of_specific_types!=""){
												$pdf->valueRow("List of specific types",$content->list_of_specific_types,30);
											}

											$pdf->valueTitle("Biogenic Growth Structures: ", 25);

											if($content->dominant_component!=""){
												$pdf->valueRow("Dominant component",$content->dominant_component,30);
											}

											if($content->other_dominant_component!=""){
												$pdf->valueRow("Other Dominant Component",$content->other_dominant_component,30);
											}

											if($content->microbial_reef_or_skelatal_mic!=""){
												$pdf->valueRow("Microbial Reef or Skeletal-Microbial Reef Type",implode($content->microbial_reef_or_skelatal_mic, ", "),30);
											}

											if($content->other_microbial_or_skeletal_mi!=""){
												$pdf->valueRow("Other Microbial or Skeletal Microbial Reef",$content->other_microbial_or_skeletal_mi,30);
											}

											if($content->mud_mound!=""){
												$pdf->valueRow("Mud Mound Type",implode($content->mud_mound, ", "),30);
											}

											if($content->other_mud_mound!=""){
												$pdf->valueRow("Other Mud Mound",$content->other_mud_mound,30);
											}

											$pdf->valueTitle("Biogenic Growth Structure Scale and Orientation: ", 25);

											if($content->height!=""){
												$pdf->valueRow("Height",$content->height,30);
											}

											if($content->width!=""){
												$pdf->valueRow("Width",$content->width,30);
											}

											if($content->shape!=""){
												$pdf->valueRow("Shape",$content->shape,30);
											}

											if($content->type!=""){
												$pdf->valueRow("Type",$content->type,30);
											}

											if($content->other_type!=""){
												$pdf->valueRow("Other Type",$content->other_type,30);
											}

											if($content->accessory_structures!=""){
												$pdf->valueRow("Accessory Structures",implode($content->accessory_structures, ", "),30);
											}

											if($content->other_accessory_structure!=""){
												$pdf->valueRow("Other Accessory Structure",$content->other_accessory_structure,30);
											}

											if($content->notes!=""){
												$pdf->valueRow("Notes",$content->notes,30);
											}
										}
									}

									if($spot['sed']->interpretations){
										$pdf->valueTitle("Interpretations: ", 20);
										$contents = $spot['sed']->interpretations;
										foreach($contents as $content){

											$pdf->valueTitle("Process Interpretation: ", 25);

											if($content->energy!=""){
												$pdf->valueRow("Energy",$content->energy,30);
											}

											if($content->other_energy!=""){
												$pdf->valueRow("Other Energy",$content->other_energy,30);
											}

											if($content->sediment_transport!=""){
												$pdf->valueRow("Sediment Transport",implode($content->sediment_transport, ", "),30);
											}

											if($content->other_sediment_transport!=""){
												$pdf->valueRow("Other Sediment Transport",$content->other_sediment_transport,30);
											}

											if($content->fluidization!=""){
												$pdf->valueRow("Fluidization",$content->fluidization,30);
											}

											if($content->other_fluidization!=""){
												$pdf->valueRow("Other Fluidization",$content->other_fluidization,30);
											}

											if($content->miscellaneous!=""){
												$pdf->valueRow("Miscellaneous",implode($content->miscellaneous, ", "),30);
											}

											if($content->other_miscellaneous!=""){
												$pdf->valueRow("Other Misc. Process",$content->other_miscellaneous,30);
											}

											$pdf->valueTitle("Environment Interpretation: ", 25);

											if($content->general!=""){
												$pdf->valueRow("General",implode($content->general, ", "),30);
											}

											if($content->clastic!=""){
												$pdf->valueRow("Clastic",implode($content->clastic, ", "),30);
											}

											if($content->alluvial_fan_environments!=""){
												$pdf->valueRow("Alluvial fan environments",implode($content->alluvial_fan_environments, ", "),30);
											}

											if($content->other_alluvial_fan!=""){
												$pdf->valueRow("Other Alluvial Fan",$content->other_alluvial_fan,30);
											}

											if($content->eolian_environments!=""){
												$pdf->valueRow("Eolian environments",implode($content->eolian_environments, ", "),30);
											}

											if($content->other_eolian!=""){
												$pdf->valueRow("Other Eolian",$content->other_eolian,30);
											}

											if($content->fluvial_environments!=""){
												$pdf->valueRow("Fluvial environments",implode($content->fluvial_environments, ", "),30);
											}

											if($content->other_fluvial!=""){
												$pdf->valueRow("Other Fluvial",$content->other_fluvial,30);
											}

											if($content->shallow_marine_clastic_environ!=""){
												$pdf->valueRow("Shallow marine clastic environments",implode($content->shallow_marine_clastic_environ, ", "),30);
											}

											if($content->other_shallow_marine!=""){
												$pdf->valueRow("Other Shallow Marine",$content->other_shallow_marine,30);
											}

											if($content->deep_marine_environments!=""){
												$pdf->valueRow("Deep marine environments",implode($content->deep_marine_environments, ", "),30);
											}

											if($content->other_deep_marine!=""){
												$pdf->valueRow("Other Deep Marine",$content->other_deep_marine,30);
											}

											if($content->glacial_and_proglacial_environ!=""){
												$pdf->valueRow("Glacial and proglacial environments",implode($content->glacial_and_proglacial_environ, ", "),30);
											}

											if($content->other_glacial!=""){
												$pdf->valueRow("Other Glacial",$content->other_glacial,30);
											}

											if($content->lake_environments!=""){
												$pdf->valueRow("Lake environments",implode($content->lake_environments, ", "),30);
											}

											if($content->other_lake!=""){
												$pdf->valueRow("Other Lake",$content->other_lake,30);
											}

											if($content->other_clastic!=""){
												$pdf->valueRow("Other Clastic",$content->other_clastic,30);
											}

											if($content->carbonates!=""){
												$pdf->valueRow("Carbonates",implode($content->carbonates, ", "),30);
											}

											if($content->factory!=""){
												$pdf->valueRow("Factory",implode($content->factory, ", "),30);
											}

											if($content->carbonate!=""){
												$pdf->valueRow("Environment",implode($content->carbonate, ", "),30);
											}

											if($content->other_carbonate_environment!=""){
												$pdf->valueRow("Other Carbonate Environment",$content->other_carbonate_environment,30);
											}

											if($content->lake_subenvironments!=""){
												$pdf->valueRow("Lake Subenvironments",implode($content->lake_subenvironments, ", "),30);
											}

											if($content->other_carbonate_lake_subenvironment!=""){
												$pdf->valueRow("Other Carbonate Lake Subenvironment",$content->other_carbonate_lake_subenvironment,30);
											}

											if($content->tidal_flat_subenvironments!=""){
												$pdf->valueRow("Tidal Flat Subenvironments",implode($content->tidal_flat_subenvironments, ", "),30);
											}

											if($content->other_tidal_flat!=""){
												$pdf->valueRow("Other Tidal Flat",$content->other_tidal_flat,30);
											}

											if($content->reef_subenvironments!=""){
												$pdf->valueRow("Reef Subenvironments",implode($content->reef_subenvironments, ", "),30);
											}

											if($content->other_reef!=""){
												$pdf->valueRow("Other Reef",$content->other_reef,30);
											}

											if($content->detailed_carbonate_env_interpr!=""){
												$pdf->valueRow("Detailed carbonate environmental interpretations",$content->detailed_carbonate_env_interpr,30);
											}

											if($content->tectonic_setting!=""){
												$pdf->valueRow("Tectonic Setting",implode($content->tectonic_setting, ", "),30);
											}

											if($content->other_tectonic_setting!=""){
												$pdf->valueRow("Other Tectonic Setting",$content->other_tectonic_setting,30);
											}

											$pdf->valueTitle("Sedimentary Surfaces (for line spots only): ", 25);

											if($content->geometry!=""){
												$pdf->valueRow("Geometry",$content->geometry,30);
											}

											if($content->relief!=""){
												$pdf->valueRow("Relief",$content->relief,30);
											}

											if($content->relief_scale!=""){
												$pdf->valueRow("Relief Scale",$content->relief_scale,30);
											}

											if($content->extent!=""){
												$pdf->valueRow("Extent",$content->extent,30);
											}

											if($content->extent_scale!=""){
												$pdf->valueRow("Extent Scale",$content->extent_scale,30);
											}

											if($content->type!=""){
												$pdf->valueRow("Type",implode($content->type, ", "),30);
											}

											if($content->other_type!=""){
												$pdf->valueRow("Other Type",$content->other_type,30);
											}

											if($content->stratal_termination!=""){
												$pdf->valueRow("Stratal Termination",$content->stratal_termination,30);
											}

											$pdf->valueTitle("Sedimentary Surface Interpretation: ", 25);

											if($content->general_surfaces!=""){
												$pdf->valueRow("General Surfaces",$content->general_surfaces,30);
											}

											if($content->sequence_stratigraphic_surfaces!=""){
												$pdf->valueRow("Sequence Stratigraphic Surfaces",$content->sequence_stratigraphic_surfaces,30);
											}

											if($content->other_sequence_stratigraphic_surface!=""){
												$pdf->valueRow("Other Sequence Stratigraphic Surface",$content->other_sequence_stratigraphic_surface,30);
											}

											if($content->named!=""){
												$pdf->valueRow("Named",$content->named,30);
											}

											$pdf->valueTitle("Architecture Interpretation: ", 25);

											if($content->description!=""){
												$pdf->valueRow("Description",implode($content->description, ", "),30);
											}

											if($content->stacking_sequence_stratigraphy!=""){
												$pdf->valueRow("Stacking/Sequence Stratigraphy",implode($content->stacking_sequence_stratigraphy, ", "),30);
											}

											if($content->other_stacking_sequence_stratigraphy!=""){
												$pdf->valueRow("Other Stacking/Sequence Stratigraphy",$content->other_stacking_sequence_stratigraphy,30);
											}

											if($content->fluvial_architectural_elements!=""){
												$pdf->valueRow("Fluvial Architectural Elements",implode($content->fluvial_architectural_elements, ", "),30);
											}

											if($content->other_fluvial_element!=""){
												$pdf->valueRow("Other Fluvial Element",$content->other_fluvial_element,30);
											}

											if($content->lacustrine_architecture_interpretation!=""){
												$pdf->valueRow("Lacustrine Architecture Interpretation",implode($content->lacustrine_architecture_interpretation, ", "),30);
											}

											if($content->other_lacustrine_architecture_interpretation!=""){
												$pdf->valueRow("Other Lacustrine Architecture Interpretation",$content->other_lacustrine_architecture_interpretation,30);
											}

											if($content->carbonate_platform_geometry!=""){
												$pdf->valueRow("Carbonate Platform Geometry",implode($content->carbonate_platform_geometry, ", "),30);
											}

											if($content->other_platform_geometry!=""){
												$pdf->valueRow("Other Platform Geometry",$content->other_platform_geometry,30);
											}

											if($content->deep_water_architctural_element!=""){
												$pdf->valueRow("Deep-Water Architectural Elements",implode($content->deep_water_architctural_element, ", "),30);
											}

											if($content->other_deep_water_architectural_element!=""){
												$pdf->valueRow("Other Deep-Water Architectural Element",$content->other_deep_water_architectural_element,30);
											}

											if($content->carbonate_margin_geometry!=""){
												$pdf->valueRow("Carbonate Margin Geometry",implode($content->carbonate_margin_geometry, ", "),30);
											}

											if($content->other_carbonate_margin_geometry!=""){
												$pdf->valueRow("Other Carbonate Margin Geometry",$content->other_carbonate_margin_geometry,30);
											}

											if($content->notes!=""){
												$pdf->valueRow("Notes",$content->notes,30);
											}

										}
									}

								}

								if($spot['pet']){
									if($spot['pet']->metamorphic){
										$pdf->valueTitle("Metamorphic Rock(s): ", 15);
										$rocks = $spot['pet']->metamorphic;

										$rockNum = 1;
										foreach($rocks as $r){
											$rockString = "";
											$rockString .= ", " . $r->metamorphic_rock_type;
											$rockString .= ", " . implode(", ", $r->facies);
											$rockString .= ", " . $r->protolith;
											$rockString .= ", " . implode(", ", $r->zone);

											$pdf->valueRow($rockNum, $rockString, 20);
											$rockNum++;
										}

										if($content->section_well_name!=""){
											$pdf->valueRow("Section/Well Name",$content->section_well_name,25);
										}
									}

									if($spot['pet']->igneous){
										$pdf->valueTitle("Igneous Rock(s): ", 15);
										$rocks = $spot['pet']->igneous;

										$rockNum = 1;
										foreach($rocks as $r){
											$rockString = "";
											if($r->igneous_rock_class) $rockString .= ", " . $r->igneous_rock_class;
											if($r->volcanic_rock_type) $rockString .= ", " . $r->volcanic_rock_type;
											if($r->occurence_volcanic) $rockString .= ", " . $r->occurence_volcanic;
											if($r->plutonic_rock_type) $rockString .= ", " . $r->plutonic_rock_type;
											if($r->occurence_plutonic) $rockString .= ", " . $r->occurence_plutonic;
											if($r->texture_volcanic) $rockString .= ", " . implode(", ", $r->texture_volcanic);
											if($r->texture_plutonic) $rockString .= ", " . implode(", ", $r->texture_plutonic);
											if($r->color_index_volc) $rockString .= ", " . $r->color_index_volc;
											if($r->color_index_pluton) $rockString .= ", " . $r->color_index_pluton;
											if($r->color_index_source_volc) $rockString .= ", " . $r->color_index_source_volc;
											if($r->color_index_source_pluton) $rockString .= ", " . $r->color_index_source_pluton;
											if($r->alteration_volcanic) $rockString .= ", " . implode(", ", $r->alteration_volcanic);
											if($r->alteration_plutonic) $rockString .= ", " . implode(", ", $r->alteration_plutonic);

											$pdf->valueRow($rockNum, $rockString, 20);
											$rockNum++;
										}

										if($content->section_well_name!=""){
											$pdf->valueRow("Section/Well Name",$content->section_well_name,25);
										}
									}

									if($spot['pet']->minerals){
										$pdf->valueTitle("Mineral(s): ", 15);
										$rocks = $spot['pet']->minerals;

										$rockNum = 1;
										foreach($rocks as $r){
											$rockString = "";
											if($r->full_mineral_name) $rockString .= ", " . $r->full_mineral_name;
											if($r->igneous_or_metamorphic == "ig_min"){
												$rockString .= " (Igneous)";
											}else{
												$rockString .= " (Metamorphic)";
											}
											if($r->average_grain_size_mm) $rockString .= ", Avg Size: " . $r->average_grain_size_mm . "mm";
											if($r->maximum_grain_size_mm) $rockString .= ", Max Size: " . $r->maximum_grain_size_mm . "mm";
											if($r->modal) $rockString .= ", Modal: " . $r->modal . "%";
											if($r->mineral_notes) $rockString .= " " . $r->mineral_notes;

											$pdf->valueRow($rockNum, $rockString, 20);
											$rockNum++;
										}

										if($content->section_well_name!=""){
											$pdf->valueRow("Section/Well Name",$content->section_well_name,25);
										}
									}

								}

								if($spot['images']){

									$pdf->valueRow("Images","",15);
									$pdf->Ln(1);
									foreach($spot['images'] as $o){
										if($o['title']){
											$thistitle = $this->fixLabel($o['title']);
											$pdf->valueTitle($thistitle.": ",20);
										}else{
											$thistitle = $o['id'];
										}

										foreach($o as $key=>$value){
											if($value != ""){
												if($key!="id" && $key!="self" && $key!="annotated" && $key!="title" && $key!="width" && $key!="height" && $key!="image_type" && $key!="caption" ){
													$key = $this->fixLabel($key);
													if(is_string($value)){
														$value = $this->fixLabel($value);
													}
													$pdf->valueRow($key,$value,20);
												}
											}
										}

										$pdf->Ln(1);

										$filename = $this->strabo->getImageFilename($o['id']);

										if($filename){
											$gdimage = $this->gdThumb($filename);
											if($gdimage){
												$pdf->GDImage($gdimage, 20, null, 60);
											}
										}

										if($o['caption'] != ""){
											$pdf->imageCaptionRow("Caption", $o['caption'], 20);
										}

										$pdf->Ln(1);
										$pdf->Ln(1);
										$pdf->Ln(1);
										$pdf->Ln(1);
										$pdf->Ln(1);

									}
								}

								$pdf->Ln(10);

							}else{ //end if date matches
							}

						}//end foreach spots

					}// end foreach spotdates

					$filedate = date("m_d_Y");
					$pdfname="StraboSpot_Field_Book_$filedate.pdf";
					$pdf->Output($pdfname,"D"); //Download this is the final

				}else{

					echo "No spots found for this search.";

				}

			} //end if dsids

		}

	public function bkupkmlOut20240531(){

		if($this->get['dsids']!=""){

			$dsids=$this->get['dsids'];
			$this->alltags = $this->strabo->getTagsFromDatasetIds($dsids);

			$json = $this->strabo->getDatasetSpotsSearch(null,$this->get);

			$spots = $json['features'];

			if(count($spots)>0){

				$randnum=$this->strabo->db->get_var("select nextval('file_seq')");

				//make directory in ogrtemp to hold data
				mkdir("ogrtemp/$randnum");
				mkdir("ogrtemp/$randnum/data");
				mkdir("ogrtemp/$randnum/data/files");

				copy("assets/files/kmlfiles/bubblehead.jpg","ogrtemp/$randnum/data/files/bubblehead.jpg");

				copy("assets/files/kmlfiles/bubbleicon/dotbubble.png","ogrtemp/$randnum/data/files/dot.png");

				copy("assets/files/kmlfiles/mysavedplaces_closed.png","ogrtemp/$randnum/data/files/mysavedplaces_closed.png");
				copy("assets/files/kmlfiles/mysavedplaces_open.png","ogrtemp/$randnum/data/files/mysavedplaces_open.png");
				copy("assets/files/kmlfiles/overlay.jpg","ogrtemp/$randnum/data/files/overlay.jpg");
				copy("assets/files/kmlfiles/rock.jpg","ogrtemp/$randnum/data/files/rock.jpg");

	/*

	files/kmlfiles

	bubblehead.jpg
	dot.png
	mysavedplaces_closed.png
	mysavedplaces_open.png
	overlay.jpg
	rock.jpg

	Polygon
	Point
	LineString
	MultiPolygon
	MultiLineString

	*/

				$pointHtml = $this->getKMLHtml($spots, "point");
				$lineHtml = $this->getKMLHtml($spots, "line");
				$polygonHtml = $this->getKMLHtml($spots, "polygon");

				foreach($spots as $spot){

					//use geoPHP to get WKT
					$mygeojson=$spot['geometry'];

					$mygeotype = $mygeojson->type;

					//pick a style for KML feature
					if($mygeotype=="Polygon" || $mygeotype=="MultiPolygon"){
						$thisstyle="m_strabo_polygon";
					}elseif($mygeotype=="LineString" || $mygeotype=="MultiLineString"){
						$thisstyle="m_strabo_line";
					}elseif($mygeotype=="Point"){
						$thisstyle="m_strabo_point";
					}else{
						$thisstyle="m_strabo_point";
					}

					if($mygeotype!=""){

						if($spot['properties']['name']!=""){
							$spotname = $spot['properties']['name'];
						}else{
							$spotname = $spot['properties']['id'];
						}

						if($mygeotype=="Polygon" || $mygeotype=="MultiPolygon"){
							//test polystyle override here
							$color = $this->getTagColor($spot['properties']['id'], $this->alltags);

							if($color!=""){
								$newcolor = "#88". substr($color, 5, 2) . substr($color, 3, 2) . substr($color, 1, 2);
								$polystyle = "<Style><PolyStyle><color>$newcolor</color><outline>0</outline></PolyStyle></Style>";
							}else{
								$polystyle = "<Style><PolyStyle><color>#4bDC7878</color><outline>0</outline></PolyStyle></Style>";
							}

						}

						$spotname = htmlspecialchars($spotname);

						$html.="<Placemark>\n<name>$spotname</name>\n<description>\n<![CDATA[\n";

						$html.="<img style=\"max-width:300px;\" src=\"files/bubblehead.jpg\">\n";

						$mygeojson=trim(json_encode($mygeojson));

						try {
							$mywkt=geoPHP::load($mygeojson,"json");
							$kmlgeo = $mywkt->out('kml');
						} catch (Exception $e) {
							$kmlgeo="";
						}

						$spot = $spot['properties'];

						$id = $spot['id'];

						$spotname = $spot['name'];
						if($spot['geometrytype']){
							$spotname .= " (".$spot['geometrytype'].")";
						}

						$html.="<div class=\"spotTitle\">Spot Name: $spotname</div>\n<br>\n";

						$modified = (string) $spot['id'];
						$modified = substr($modified,0,10);
						$modified = date("c",$modified);
						$html.="<div>Created: $modified</div>\n";

						$modified = (string) $spot['modified_timestamp'];
						$modified = substr($modified,0,10);
						$modified = date("c",$modified);
						$html.="<div>Last Modified: $modified</div>\n";

						if($spot['surface_feature']){
							foreach($spot['surface_feature'] as $key=>$value){
								$key = $this->fixLabel($key);
								if(is_string($value)){
									$value = $this->fixLabel($value);
								}
								$html.="<div>$key: $value</div>\n";
							}
						}

						if($spot['orientation_data']){
							$html.="<div>Orientations:</div>\n";
							$html.="<div class=\"leftPad\">\n";
							foreach($spot['orientation_data'] as $o){
								$html.="<div class=\"sectionTitle\">".$this->fixLabel($o->type).": "."</div>\n";
								foreach($o as $key=>$value){
									if($key!="id" && $key!="associated_orientation" && $key!="type"){
										$key = $this->fixLabel($key);
										if(is_string($value)){
											$value = $this->fixLabel($value);
										}
										$html.="<div>$key: $value</div>\n";
									}
								}

								if($o->associated_orientation){
									$html.="<div>Orientations:</div>\n";
									$html.="<div class=\"leftPad\">\n";
									foreach($o->associated_orientation as $ao){
										$html.="<div class=\"sectionTitle\">".$this->fixLabel($o->type).": "."</div>\n";
										foreach($ao as $key=>$value){
											if($key!="id" && $key!="associated_orientation" && $key!="type"){
												$key = $this->fixLabel($key);
												if(is_string($value)){
													$value = $this->fixLabel($value);
												}
												$html.="<div>$key: $value</div>\n";
											}
										}

									}

									$html.="</div>\n"; //end leftPad
								}

							}

							$html.="</div>\n"; //end leftPad
						}

						if($spot['_3d_structures']){
							$html.="<div>3D Structures:</div>\n";
							$html.="<div class=\"leftPad\">\n";
							foreach($spot['_3d_structures'] as $o){
								$html.="<div class=\"sectionTitle\">".$this->fixLabel($o->type).": "."</div>\n";
								foreach($o as $key=>$value){
									if($key!="id" && $key!="type"){
										$key = $this->fixLabel($key);
										if(is_string($value)){
											$value = $this->fixLabel($value);
										}
										$html.="<div>$key: $value</div>\n";
									}
								}

							}

							$html.="</div>\n"; //end leftPad
						}

						if($spot['trace']){
							$html.="<div>Trace:</div>\n";
							$html.="<div class=\"leftPad\">\n";

							foreach($spot['trace'] as $key=>$value){
								if($key!="id" && $key!="label"){
									if($key!="trace_feature"){
										$key = $this->fixLabel($key);
										if(is_string($value)){
											$value = $this->fixLabel($value);
										}
										$html.="<div>$key: $value</div>\n";
									}
								}
							}

							$html.="</div>\n"; //end leftPad
						}

						if($spot['samples']){
							$html.="<div>Samples:</div>\n";
							$html.="<div class=\"leftPad\">\n";
							foreach($spot['samples'] as $o){
								$html.="<div class=\"sectionTitle\">".$this->fixLabel($o->label).": "."</div>\n";
								foreach($o as $key=>$value){
									if($key!="id" && $key!="label"){
										$key = $this->fixLabel($key);
										if(is_string($value)){
											$value = $this->fixLabel($value);
										}
										$html.="<div>$key: $value</div>\n";
									}
								}

							}

							$html.="</div>\n"; //end leftPad
						}

						if($spot['other_features']){
							$html.="<div>Other Features:</div>\n";
							$html.="<div class=\"leftPad\">\n";
							foreach($spot['other_features'] as $o){
								$html.="<div class=\"sectionTitle\">".$this->fixLabel($o->label).": "."</div>\n";
								foreach($o as $key=>$value){
									if($key!="id" && $key!="label"){
										$key = $this->fixLabel($key);
										if(is_string($value)){
											$value = $this->fixLabel($value);
										}
										$html.="<div>$key: $value</div>\n";
									}
								}

							}

							$html.="</div>\n"; //end leftPad
						}

						if($project->tags){
							foreach($project->tags as $tag){
								$found = "no";
								if($tag->spots){
									foreach($tag->spots as $spotid){
										if($spotid == $id){
											$found = "yes";
										}
									}
								}

								if($found == "yes"){
									if($tag->type=="geologic_unit"){

										$html.="<div>Rock Unit:</div>\n";
										$html.="<div class=\"leftPad\">\n";
										foreach($tag as $key=>$value){
											if($key != "date" && $key != "spots" && $key != "features" && $key != "id" ){
												$key = $this->fixLabel($key);
												if(is_string($value)){
													$value = $this->fixLabel($value);
												}
												$html.="<div>$key: $value</div>\n";
											}
										}

										$html.="</div>\n"; //end leftPad
									}
								}
							}
						}

						$hastags = "no";

						if($this->alltags){
							foreach($this->alltags as $tag){
								$found = "no";
								if($tag->spots){
									if($tag->type!="geologic_unit"){
										foreach($tag->spots as $spotid){
											if($spotid == $id){
												$hastags = "yes";
											}
										}
									}
								}

							}
						}

						if($hastags == "yes"){

							$html.="<div>Tags:</div>\n";
							$html.="<div class=\"leftPad\">\n";

							if($this->alltags){
								foreach($this->alltags as $tag){
									$found = "no";
									if($tag->spots){
										if($tag->type!="geologic_unit"){
											foreach($tag->spots as $spotid){
												if($spotid == $id){
													$found = "yes";
												}
											}
										}
									}

									if($found == "yes"){

										$html.="<div class=\"sectionTitle\">".$tag->name.": "."</div>\n";
										foreach($tag as $key=>$value){

											if($key != "date" && $key != "spots" && $key != "features" && $key != "id" && $key != "name" ){
												$key = $this->fixLabel($key);
												if(is_string($value)){
													$value = $this->fixLabel($value);
												}
												$html.="<div>$key: $value</div>\n";
											}

										}

									}
								}
							}

							$html.="</div>\n"; //end leftPad

						}

						if($spot['images']){
							$html.="<div>Images:</div>\n";
							$html.="<div class=\"leftPad\">\n";
							foreach($spot['images'] as $o){
								if($o['title']){
									$thistitle = $this->fixLabel($o['title']);
								}else{
									$thistitle = $o['id'];
								}
								$html.="<div class=\"sectionTitle\">".$thistitle.": "."</div>\n";
								foreach($o as $key=>$value){
									if($key!="id" && $key!="self" && $key!="annotated" && $key!="title"){
										$key = $this->fixLabel($key);
										if(is_string($value)){
											$value = $this->fixLabel($value);
										}
										$html.="<div>$key: $value</div>\n";
									}
								}

								$imageid = $o['id'];

								$filename = $this->strabo->getImageFilename($imageid);

								if($filename){
									$gdimage = $this->gdThumb($filename);
									if($gdimage){
										//write image to folder here (imagecreatetruecolor)
										imagejpeg($gdimage, "ogrtemp/$randnum/data/files/$imageid.jpg");

										$html.="<div><a href=\"https://www.strabospot.org/geimage/$imageid\"><img src=\"files/$imageid.jpg\"></a></div>\n";
									}
								}

							}

							$html.="</div>\n"; //end leftPad
						}

						if($mygeotype=="Point"){
							//build custom icon here if needed
							$customstyle=$this->buildCustomPoint($spot,$randnum);
							if($customstyle!=""){
								$pointstyle=$customstyle;
							}else{
								$pointstyle="";
							}
						}else{
							$pointstyle="";
						}

						$html.="]]>\n</description>\n<styleUrl>#".$thisstyle."</styleUrl>".$pointstyle.$polystyle."\n$kmlgeo\n</Placemark>\n\n";

					}

				}// end foreach spot

					$stylestring = '				<html><head>
										<style type="text/css">
											html{
												margin:0px;
												padding:0px;
											}
											body{
												margin:2px;
												font-family: \'Open sans\', sans-serif;
												font-size: 13px;
												color: #666666;
												background-color: #ffffff;
												width:700px;
											}
											.spotTitle{
												font-weight:bold;
												font-size:15px;
											}
											.leftPad{
												padding-left:20px;
											}
											.sectionTitle{
												text-decoration:underline;
											}
										</style>
										</head>
										<body>$[description]</body>
									</html>';

									$html='<?xml version="1.0" encoding="UTF-8"?>
									<kml xmlns="http://www.opengis.net/kml/2.2" xmlns:gx="http://www.google.com/kml/ext/2.2" xmlns:kml="http://www.opengis.net/kml/2.2" xmlns:atom="http://www.w3.org/2005/Atom">
									<Document>
										<name>StraboSpot Data</name>

										<StyleMap id="m_strabo_point">
											<Pair>
												<key>normal</key>
												<styleUrl>#s_strabo_point</styleUrl>
											</Pair>
											<Pair>
												<key>highlight</key>
												<styleUrl>#s_strabo_point_hl</styleUrl>
											</Pair>
										</StyleMap>

										<Style id="s_strabo_point">
											<IconStyle>
												<scale>1.7</scale>
												<Icon>
													<href>files/dot.png</href>
												</Icon>
												<hotSpot x="50" y="50" xunits="pixels" yunits="pixels"/>
												<heading>360</heading>
											</IconStyle>
											<LineStyle>
												<color>ff000000</color>
												<width>1.5</width>
											</LineStyle>
											<PolyStyle>
												<color>4bDC7878</color>
											</PolyStyle>
										</Style>

										<Style id="s_strabo_point_hl">
											<IconStyle>
												<scale>1.9</scale>
												<Icon>
													<href>files/dot.png</href>
												</Icon>
												<hotSpot x="50" y="50" xunits="pixels" yunits="pixels"/>
												<heading>360</heading>
											</IconStyle>
											<LineStyle>
												<color>ff000000</color>
												<width>1.5</width>
											</LineStyle>
											<PolyStyle>
												<color>64DC7878</color>
											</PolyStyle>
											<BalloonStyle>
												<text><![CDATA[
												'.$stylestring.'
												]]></text>
											</BalloonStyle>
										</Style>

										<StyleMap id="m_strabo_line">
											<Pair>
												<key>normal</key>
												<styleUrl>#s_strabo_line</styleUrl>
											</Pair>
											<Pair>
												<key>highlight</key>
												<styleUrl>#s_strabo_line_hl</styleUrl>
											</Pair>
										</StyleMap>

										<Style id="s_strabo_line">
											<LineStyle>
												<color>ff000000</color>
												<width>2</width>
											</LineStyle>
										</Style>

										<Style id="s_strabo_line_hl">
											<LineStyle>
												<color>ff000000</color>
												<width>4</width>
											</LineStyle>
											<BalloonStyle>
												<text><![CDATA[
												'.$stylestring.'
												]]></text>
											</BalloonStyle>
										</Style>

										<StyleMap id="m_strabo_polygon">
											<Pair>
												<key>normal</key>
												<styleUrl>#s_strabo_polygon</styleUrl>
											</Pair>
											<Pair>
												<key>highlight</key>
												<styleUrl>#s_strabo_polygon_hl</styleUrl>
											</Pair>
										</StyleMap>

										<Style id="s_strabo_polygon">
											<LineStyle>
												<color>ff000000</color>
												<width>1.5</width>
											</LineStyle>
											<PolyStyle>
												<color>4bDC7878</color>
											</PolyStyle>
										</Style>

										<Style id="s_strabo_polygon_hl">
											<LineStyle>
												<color>ff000000</color>
												<width>1.5</width>
											</LineStyle>
											<PolyStyle>
												<color>641478FF</color>
											</PolyStyle>
											<BalloonStyle>
												<text><![CDATA[
												'.$stylestring.'
												]]></text>
											</BalloonStyle>
										</Style>
										';

if($pointHtml != ""){
	$html .=	'								<Folder>
											<name>Points</name>
											<open>0</open>
											<Style>
												<ListStyle>
													<listItemType>check</listItemType>
													<ItemIcon>
														<state>open</state>
														<href>files/mysavedplaces_open.png</href>
													</ItemIcon>
													<ItemIcon>
														<state>closed</state>
														<href>files/mysavedplaces_closed.png</href>
													</ItemIcon>
													<bgColor>14F0C814</bgColor>
													<maxSnippetLines>0</maxSnippetLines>
												</ListStyle>
											</Style> <!--placemarks here -->

									'.$pointHtml.'

							<ScreenOverlay>
								  <Style>
									<ListStyle>
									  <ItemIcon>
										<href>
										  <a href="http://www.earthchemportal.org">EarthChem</a>
										</href>
									  </ItemIcon>
									  <listItemType>checkHideChildren</listItemType>
									</ListStyle>
								  </Style>
								  <name>
									<a href="https://www.strabospot.org">StraboSpot</a>
								  </name>
								  <Icon>
									<href>files/overlay.jpg</href>
								  </Icon>
								  <overlayXY x="0.01" y="0.035" xunits="fraction" yunits="fraction" />
								  <screenXY x="0.01" y="0.035" xunits="fraction" yunits="fraction" />
								  <size x="300" y="55" xunits="pixels" yunits="pixels" />
								</ScreenOverlay>

							</Folder>
							';
}

if($lineHtml != ""){
	$html .=	'								<Folder>
											<name>Lines</name>
											<open>0</open>
											<Style>
												<ListStyle>
													<listItemType>check</listItemType>
													<ItemIcon>
														<state>open</state>
														<href>files/mysavedplaces_open.png</href>
													</ItemIcon>
													<ItemIcon>
														<state>closed</state>
														<href>files/mysavedplaces_closed.png</href>
													</ItemIcon>
													<bgColor>14F0C814</bgColor>
													<maxSnippetLines>0</maxSnippetLines>
												</ListStyle>
											</Style> <!--placemarks here -->

									'.$lineHtml.'

							<ScreenOverlay>
								  <Style>
									<ListStyle>
									  <ItemIcon>
										<href>
										  <a href="http://www.earthchemportal.org">EarthChem</a>
										</href>
									  </ItemIcon>
									  <listItemType>checkHideChildren</listItemType>
									</ListStyle>
								  </Style>
								  <name>
									<a href="https://www.strabospot.org">StraboSpot</a>
								  </name>
								  <Icon>
									<href>files/overlay.jpg</href>
								  </Icon>
								  <overlayXY x="0.01" y="0.035" xunits="fraction" yunits="fraction" />
								  <screenXY x="0.01" y="0.035" xunits="fraction" yunits="fraction" />
								  <size x="300" y="55" xunits="pixels" yunits="pixels" />
								</ScreenOverlay>

							</Folder>
							';
}

if($polygonHtml != ""){
	$html .=	'								<Folder>
											<name>Polygons</name>
											<open>0</open>
											<Style>
												<ListStyle>
													<listItemType>check</listItemType>
													<ItemIcon>
														<state>open</state>
														<href>files/mysavedplaces_open.png</href>
													</ItemIcon>
													<ItemIcon>
														<state>closed</state>
														<href>files/mysavedplaces_closed.png</href>
													</ItemIcon>
													<bgColor>14F0C814</bgColor>
													<maxSnippetLines>0</maxSnippetLines>
												</ListStyle>
											</Style> <!--placemarks here -->

									'.$polygonHtml.'

							<ScreenOverlay>
								  <Style>
									<ListStyle>
									  <ItemIcon>
										<href>
										  <a href="http://www.earthchemportal.org">EarthChem</a>
										</href>
									  </ItemIcon>
									  <listItemType>checkHideChildren</listItemType>
									</ListStyle>
								  </Style>
								  <name>
									<a href="https://www.strabospot.org">StraboSpot</a>
								  </name>
								  <Icon>
									<href>files/overlay.jpg</href>
								  </Icon>
								  <overlayXY x="0.01" y="0.035" xunits="fraction" yunits="fraction" />
								  <screenXY x="0.01" y="0.035" xunits="fraction" yunits="fraction" />
								  <size x="300" y="55" xunits="pixels" yunits="pixels" />
								</ScreenOverlay>

							</Folder>
							';
}

$html.='

						</Document>
						</kml>';

				file_put_contents("ogrtemp/$randnum/data/doc.kml", $html);

				$filedate = date("m_d_Y");

				exec("cd ogrtemp/$randnum/data; zip -r strabo_$filedate.kmz doc.kml files 2>&1",$results);

					//zip -r foo.zip doc.kml files

				//force download of file
				header("Content-Type: application/kmz");
				header("Content-Disposition: attachment; filename=strabo_$filedate.kmz");
				header("Content-Length: " . filesize("ogrtemp/$randnum/data/strabo_$filedate.kmz"));

				readfile("ogrtemp/$randnum/data/strabo_$filedate.kmz");

				//remove temp directory
				if($randnum!=""){
				}

			}else{

				echo "No spots found for this search.";

			}

		} //end if dsids

	}

	public function kmlOut(){

		if($this->get['dsids']!=""){

			$dsids=$this->get['dsids'];
			$this->alltags = $this->strabo->getTagsFromDatasetIds($dsids);

			$json = $this->strabo->getDatasetSpotsSearch(null,$this->get);

			$spots = $json['features'];

			if(count($spots)>0){

				$randnum=$this->strabo->db->get_var("select nextval('file_seq')");

				//make directory in ogrtemp to hold data
				mkdir("ogrtemp/$randnum");
				mkdir("ogrtemp/$randnum/data");
				mkdir("ogrtemp/$randnum/data/files");

				copy("assets/files/kmlfiles/bubblehead.jpg","ogrtemp/$randnum/data/files/bubblehead.jpg");

				copy("assets/files/kmlfiles/bubbleicon/dotbubble.png","ogrtemp/$randnum/data/files/dot.png");

				copy("assets/files/kmlfiles/mysavedplaces_closed.png","ogrtemp/$randnum/data/files/mysavedplaces_closed.png");
				copy("assets/files/kmlfiles/mysavedplaces_open.png","ogrtemp/$randnum/data/files/mysavedplaces_open.png");
				copy("assets/files/kmlfiles/overlay.jpg","ogrtemp/$randnum/data/files/overlay.jpg");
				copy("assets/files/kmlfiles/rock.jpg","ogrtemp/$randnum/data/files/rock.jpg");

	/*

	files/kmlfiles

	bubblehead.jpg
	dot.png
	mysavedplaces_closed.png
	mysavedplaces_open.png
	overlay.jpg
	rock.jpg

	Polygon
	Point
	LineString
	MultiPolygon
	MultiLineString

	*/

				$pointHtml = $this->getKMLHtml($spots, "point");
				$lineHtml = $this->getKMLHtml($spots, "line");
				$polygonHtml = $this->getKMLHtml($spots, "polygon");

				foreach($spots as $spot){

					//use geoPHP to get WKT
					$mygeojson=$spot['geometry'];

					$mygeotype = $mygeojson->type;

					//pick a style for KML feature
					if($mygeotype=="Polygon" || $mygeotype=="MultiPolygon"){
						$thisstyle="m_strabo_polygon";
					}elseif($mygeotype=="LineString" || $mygeotype=="MultiLineString"){
						$thisstyle="m_strabo_line";
					}elseif($mygeotype=="Point"){
						$thisstyle="m_strabo_point";
					}else{
						$thisstyle="m_strabo_point";
					}

					if($mygeotype!=""){

						if($spot['properties']['name']!=""){
							$spotname = $spot['properties']['name'];
						}else{
							$spotname = $spot['properties']['id'];
						}

						if($mygeotype=="Polygon" || $mygeotype=="MultiPolygon"){
							//test polystyle override here
							$color = $this->getTagColor($spot['properties']['id'], $this->alltags);

							if($color!=""){
								$newcolor = "#88". substr($color, 5, 2) . substr($color, 3, 2) . substr($color, 1, 2);
								$polystyle = "<Style><PolyStyle><color>$newcolor</color><outline>0</outline></PolyStyle></Style>";
							}else{
								$polystyle = "<Style><PolyStyle><color>#4bDC7878</color><outline>0</outline></PolyStyle></Style>";
							}

						}

						$spotname = htmlspecialchars($spotname);

						$html.="<Placemark>\n<name>$spotname</name>\n<description>\n<![CDATA[\n";

						$html.="<img style=\"max-width:300px;\" src=\"files/bubblehead.jpg\">\n";

						$mygeojson=trim(json_encode($mygeojson));

						try {
							$mywkt=geoPHP::load($mygeojson,"json");
							$kmlgeo = $mywkt->out('kml');
						} catch (Exception $e) {
							$kmlgeo="";
						}

						$spot = $spot['properties'];

						$id = $spot['id'];

						$spotname = $spot['name'];
						if($spot['geometrytype']){
							$spotname .= " (".$spot['geometrytype'].")";
						}

						$html.="<div class=\"spotTitle\">Spot Name: $spotname</div>\n<br>\n";

						$modified = (string) $spot['id'];
						$modified = substr($modified,0,10);
						$modified = date("c",$modified);
						$html.="<div>Created: $modified</div>\n";

						$modified = (string) $spot['modified_timestamp'];
						$modified = substr($modified,0,10);
						$modified = date("c",$modified);
						$html.="<div>Last Modified: $modified</div>\n";

						if($spot['surface_feature']){
							foreach($spot['surface_feature'] as $key=>$value){
								$key = $this->fixLabel($key);
								if(is_string($value)){
									$value = $this->fixLabel($value);
								}
								$html.="<div>$key: $value</div>\n";
							}
						}

						if($spot['orientation_data']){
							$html.="<div>Orientationsdd:</div>\n";
							$html.="<div class=\"leftPad\">\n";
							foreach($spot['orientation_data'] as $o){
								$html.="<div class=\"sectionTitle\">".$this->fixLabel($o->type).": "."</div>\n";
								foreach($o as $key=>$value){
									if($key!="id" && $key!="associated_orientation" && $key!="type"){
										$key = $this->fixLabel($key);
										if(is_string($value)){
											$value = $this->fixLabel($value);
										}
										$html.="<div>$key: $value</div>\n";
									}
								}

								if($o->associated_orientation){
									$html.="<div>Orientations:</div>\n";
									$html.="<div class=\"leftPad\">\n";
									foreach($o->associated_orientation as $ao){
										$html.="<div class=\"sectionTitle\">".$this->fixLabel($o->type).": "."</div>\n";
										foreach($ao as $key=>$value){
											if($key!="id" && $key!="associated_orientation" && $key!="type"){
												$key = $this->fixLabel($key);
												if(is_string($value)){
													$value = $this->fixLabel($value);
												}
												$html.="<div>$key: $value</div>\n";
											}
										}

									}

									$html.="</div>\n"; //end leftPad
								}

							}

							$html.="</div>\n"; //end leftPad
						}

						if($spot['_3d_structures']){
							$html.="<div>3D Structures:</div>\n";
							$html.="<div class=\"leftPad\">\n";
							foreach($spot['_3d_structures'] as $o){
								$html.="<div class=\"sectionTitle\">".$this->fixLabel($o->type).": "."</div>\n";
								foreach($o as $key=>$value){
									if($key!="id" && $key!="type"){
										$key = $this->fixLabel($key);
										if(is_string($value)){
											$value = $this->fixLabel($value);
										}
										$html.="<div>$key: $value</div>\n";
									}
								}

							}

							$html.="</div>\n"; //end leftPad
						}

						if($spot['trace']){
							$html.="<div>Trace:</div>\n";
							$html.="<div class=\"leftPad\">\n";

							foreach($spot['trace'] as $key=>$value){
								if($key!="id" && $key!="label"){
									if($key!="trace_feature"){
										$key = $this->fixLabel($key);
										if(is_string($value)){
											$value = $this->fixLabel($value);
										}
										$html.="<div>$key: $value</div>\n";
									}
								}
							}

							$html.="</div>\n"; //end leftPad
						}

						if($spot['samples']){
							$html.="<div>Samples:</div>\n";
							$html.="<div class=\"leftPad\">\n";
							foreach($spot['samples'] as $o){
								$html.="<div class=\"sectionTitle\">".$this->fixLabel($o->label).": "."</div>\n";
								foreach($o as $key=>$value){
									if($key!="id" && $key!="label"){
										$key = $this->fixLabel($key);
										if(is_string($value)){
											$value = $this->fixLabel($value);
										}
										$html.="<div>$key: $value</div>\n";
									}
								}

							}

							$html.="</div>\n"; //end leftPad
						}

						if($spot['other_features']){
							$html.="<div>Other Features:</div>\n";
							$html.="<div class=\"leftPad\">\n";
							foreach($spot['other_features'] as $o){
								$html.="<div class=\"sectionTitle\">".$this->fixLabel($o->label).": "."</div>\n";
								foreach($o as $key=>$value){
									if($key!="id" && $key!="label"){
										$key = $this->fixLabel($key);
										if(is_string($value)){
											$value = $this->fixLabel($value);
										}
										$html.="<div>$key: $value</div>\n";
									}
								}

							}

							$html.="</div>\n"; //end leftPad
						}

						if($project->tags){
							foreach($project->tags as $tag){
								$found = "no";
								if($tag->spots){
									foreach($tag->spots as $spotid){
										if($spotid == $id){
											$found = "yes";
										}
									}
								}

								if($found == "yes"){
									if($tag->type=="geologic_unit"){

										$html.="<div>Rock Unit:</div>\n";
										$html.="<div class=\"leftPad\">\n";
										foreach($tag as $key=>$value){
											if($key != "date" && $key != "spots" && $key != "features" && $key != "id" ){
												$key = $this->fixLabel($key);
												if(is_string($value)){
													$value = $this->fixLabel($value);
												}
												$html.="<div>$key: $value</div>\n";
											}
										}

										$html.="</div>\n"; //end leftPad
									}
								}
							}
						}

						$hastags = "no";

						if($this->alltags){
							foreach($this->alltags as $tag){
								$found = "no";
								if($tag->spots){
									if($tag->type!="geologic_unit"){
										foreach($tag->spots as $spotid){
											if($spotid == $id){
												$hastags = "yes";
											}
										}
									}
								}

							}
						}

						if($hastags == "yes"){

							$html.="<div>Tags:</div>\n";
							$html.="<div class=\"leftPad\">\n";

							if($this->alltags){
								foreach($this->alltags as $tag){
									$found = "no";
									if($tag->spots){
										if($tag->type!="geologic_unit"){
											foreach($tag->spots as $spotid){
												if($spotid == $id){
													$found = "yes";
												}
											}
										}
									}

									if($found == "yes"){

										$html.="<div class=\"sectionTitle\">".$tag->name.": "."</div>\n";
										foreach($tag as $key=>$value){

											if($key != "date" && $key != "spots" && $key != "features" && $key != "id" && $key != "name" ){
												$key = $this->fixLabel($key);
												if(is_string($value)){
													$value = $this->fixLabel($value);
												}
												$html.="<div>$key: $value</div>\n";
											}

										}

									}
								}
							}

							$html.="</div>\n"; //end leftPad

						}

						if($spot['images']){
							$html.="<div>Images:</div>\n";
							$html.="<div class=\"leftPad\">\n";
							foreach($spot['images'] as $o){
								if($o['title']){
									$thistitle = $this->fixLabel($o['title']);
								}else{
									$thistitle = $o['id'];
								}
								$html.="<div class=\"sectionTitle\">".$thistitle.": "."</div>\n";
								foreach($o as $key=>$value){
									if($key!="id" && $key!="self" && $key!="annotated" && $key!="title"){
										$key = $this->fixLabel($key);
										if(is_string($value)){
											$value = $this->fixLabel($value);
										}
										$html.="<div>$key: $value</div>\n";
									}
								}

								$imageid = $o['id'];

								$filename = $this->strabo->getImageFilename($imageid);

								if($filename){

									$gdimage = $this->gdThumbWithSpots($filename, $imageid, $spots);

									if($gdimage){
										//write image to folder here (imagecreatetruecolor)
										imagejpeg($gdimage, "ogrtemp/$randnum/data/files/$imageid.jpg");

										$html.="<div><a href=\"https://www.strabospot.org/geimage/$imageid\"><img src=\"files/$imageid.jpg\"></a></div>\n";
									}
								}

							}

							$html.="</div>\n"; //end leftPad
						}

						if($mygeotype=="Point"){
							//build custom icon here if needed
							$customstyle=$this->buildCustomPoint($spot,$randnum);
							if($customstyle!=""){
								$pointstyle=$customstyle;
							}else{
								$pointstyle="";
							}
						}else{
							$pointstyle="";
						}

						$html.="]]>\n</description>\n<styleUrl>#".$thisstyle."</styleUrl>".$pointstyle.$polystyle."\n$kmlgeo\n</Placemark>\n\n";

					}

				}// end foreach spot

					$stylestring = '				<html><head>
										<style type="text/css">
											html{
												margin:0px;
												padding:0px;
											}
											body{
												margin:2px;
												font-family: \'Open sans\', sans-serif;
												font-size: 13px;
												color: #666666;
												background-color: #ffffff;
												width:700px;
											}
											.spotTitle{
												font-weight:bold;
												font-size:15px;
											}
											.leftPad{
												padding-left:20px;
											}
											.sectionTitle{
												text-decoration:underline;
											}
										</style>
										</head>
										<body>$[description]</body>
									</html>';

									$html='<?xml version="1.0" encoding="UTF-8"?>
									<kml xmlns="http://www.opengis.net/kml/2.2" xmlns:gx="http://www.google.com/kml/ext/2.2" xmlns:kml="http://www.opengis.net/kml/2.2" xmlns:atom="http://www.w3.org/2005/Atom">
									<Document>
										<name>StraboSpot Data</name>

										<StyleMap id="m_strabo_point">
											<Pair>
												<key>normal</key>
												<styleUrl>#s_strabo_point</styleUrl>
											</Pair>
											<Pair>
												<key>highlight</key>
												<styleUrl>#s_strabo_point_hl</styleUrl>
											</Pair>
										</StyleMap>

										<Style id="s_strabo_point">
											<IconStyle>
												<scale>1.7</scale>
												<Icon>
													<href>files/dot.png</href>
												</Icon>
												<hotSpot x="50" y="50" xunits="pixels" yunits="pixels"/>
												<heading>360</heading>
											</IconStyle>
											<LineStyle>
												<color>ff000000</color>
												<width>1.5</width>
											</LineStyle>
											<PolyStyle>
												<color>4bDC7878</color>
											</PolyStyle>
										</Style>

										<Style id="s_strabo_point_hl">
											<IconStyle>
												<scale>1.9</scale>
												<Icon>
													<href>files/dot.png</href>
												</Icon>
												<hotSpot x="50" y="50" xunits="pixels" yunits="pixels"/>
												<heading>360</heading>
											</IconStyle>
											<LineStyle>
												<color>ff000000</color>
												<width>1.5</width>
											</LineStyle>
											<PolyStyle>
												<color>64DC7878</color>
											</PolyStyle>
											<BalloonStyle>
												<text><![CDATA[
												'.$stylestring.'
												]]></text>
											</BalloonStyle>
										</Style>

										<StyleMap id="m_strabo_line">
											<Pair>
												<key>normal</key>
												<styleUrl>#s_strabo_line</styleUrl>
											</Pair>
											<Pair>
												<key>highlight</key>
												<styleUrl>#s_strabo_line_hl</styleUrl>
											</Pair>
										</StyleMap>

										<Style id="s_strabo_line">
											<LineStyle>
												<color>ff000000</color>
												<width>2</width>
											</LineStyle>
										</Style>

										<Style id="s_strabo_line_hl">
											<LineStyle>
												<color>ff000000</color>
												<width>4</width>
											</LineStyle>
											<BalloonStyle>
												<text><![CDATA[
												'.$stylestring.'
												]]></text>
											</BalloonStyle>
										</Style>

										<StyleMap id="m_strabo_polygon">
											<Pair>
												<key>normal</key>
												<styleUrl>#s_strabo_polygon</styleUrl>
											</Pair>
											<Pair>
												<key>highlight</key>
												<styleUrl>#s_strabo_polygon_hl</styleUrl>
											</Pair>
										</StyleMap>

										<Style id="s_strabo_polygon">
											<LineStyle>
												<color>ff000000</color>
												<width>1.5</width>
											</LineStyle>
											<PolyStyle>
												<color>4bDC7878</color>
											</PolyStyle>
										</Style>

										<Style id="s_strabo_polygon_hl">
											<LineStyle>
												<color>ff000000</color>
												<width>1.5</width>
											</LineStyle>
											<PolyStyle>
												<color>641478FF</color>
											</PolyStyle>
											<BalloonStyle>
												<text><![CDATA[
												'.$stylestring.'
												]]></text>
											</BalloonStyle>
										</Style>
										';

if($pointHtml != ""){
	$html .=	'								<Folder>
											<name>Points</name>
											<open>0</open>
											<Style>
												<ListStyle>
													<listItemType>check</listItemType>
													<ItemIcon>
														<state>open</state>
														<href>files/mysavedplaces_open.png</href>
													</ItemIcon>
													<ItemIcon>
														<state>closed</state>
														<href>files/mysavedplaces_closed.png</href>
													</ItemIcon>
													<bgColor>14F0C814</bgColor>
													<maxSnippetLines>0</maxSnippetLines>
												</ListStyle>
											</Style> <!--placemarks here -->

									'.$pointHtml.'

							<ScreenOverlay>
								  <Style>
									<ListStyle>
									  <ItemIcon>
										<href>
										  <a href="http://www.earthchemportal.org">EarthChem</a>
										</href>
									  </ItemIcon>
									  <listItemType>checkHideChildren</listItemType>
									</ListStyle>
								  </Style>
								  <name>
									<a href="https://www.strabospot.org">StraboSpot</a>
								  </name>
								  <Icon>
									<href>files/overlay.jpg</href>
								  </Icon>
								  <overlayXY x="0.01" y="0.035" xunits="fraction" yunits="fraction" />
								  <screenXY x="0.01" y="0.035" xunits="fraction" yunits="fraction" />
								  <size x="300" y="55" xunits="pixels" yunits="pixels" />
								</ScreenOverlay>

							</Folder>
							';
}

if($lineHtml != ""){
	$html .=	'								<Folder>
											<name>Lines</name>
											<open>0</open>
											<Style>
												<ListStyle>
													<listItemType>check</listItemType>
													<ItemIcon>
														<state>open</state>
														<href>files/mysavedplaces_open.png</href>
													</ItemIcon>
													<ItemIcon>
														<state>closed</state>
														<href>files/mysavedplaces_closed.png</href>
													</ItemIcon>
													<bgColor>14F0C814</bgColor>
													<maxSnippetLines>0</maxSnippetLines>
												</ListStyle>
											</Style> <!--placemarks here -->

									'.$lineHtml.'

							<ScreenOverlay>
								  <Style>
									<ListStyle>
									  <ItemIcon>
										<href>
										  <a href="http://www.earthchemportal.org">EarthChem</a>
										</href>
									  </ItemIcon>
									  <listItemType>checkHideChildren</listItemType>
									</ListStyle>
								  </Style>
								  <name>
									<a href="https://www.strabospot.org">StraboSpot</a>
								  </name>
								  <Icon>
									<href>files/overlay.jpg</href>
								  </Icon>
								  <overlayXY x="0.01" y="0.035" xunits="fraction" yunits="fraction" />
								  <screenXY x="0.01" y="0.035" xunits="fraction" yunits="fraction" />
								  <size x="300" y="55" xunits="pixels" yunits="pixels" />
								</ScreenOverlay>

							</Folder>
							';
}

if($polygonHtml != ""){
	$html .=	'								<Folder>
											<name>Polygons</name>
											<open>0</open>
											<Style>
												<ListStyle>
													<listItemType>check</listItemType>
													<ItemIcon>
														<state>open</state>
														<href>files/mysavedplaces_open.png</href>
													</ItemIcon>
													<ItemIcon>
														<state>closed</state>
														<href>files/mysavedplaces_closed.png</href>
													</ItemIcon>
													<bgColor>14F0C814</bgColor>
													<maxSnippetLines>0</maxSnippetLines>
												</ListStyle>
											</Style> <!--placemarks here -->

									'.$polygonHtml.'

							<ScreenOverlay>
								  <Style>
									<ListStyle>
									  <ItemIcon>
										<href>
										  <a href="http://www.earthchemportal.org">EarthChem</a>
										</href>
									  </ItemIcon>
									  <listItemType>checkHideChildren</listItemType>
									</ListStyle>
								  </Style>
								  <name>
									<a href="https://www.strabospot.org">StraboSpot</a>
								  </name>
								  <Icon>
									<href>files/overlay.jpg</href>
								  </Icon>
								  <overlayXY x="0.01" y="0.035" xunits="fraction" yunits="fraction" />
								  <screenXY x="0.01" y="0.035" xunits="fraction" yunits="fraction" />
								  <size x="300" y="55" xunits="pixels" yunits="pixels" />
								</ScreenOverlay>

							</Folder>
							';
}

$html.='

						</Document>
						</kml>';

				file_put_contents("ogrtemp/$randnum/data/doc.kml", $html);

				$filedate = date("m_d_Y");

				exec("cd ogrtemp/$randnum/data; zip -r strabo_$filedate.kmz doc.kml files 2>&1",$results);

					//zip -r foo.zip doc.kml files

				//force download of file
				header("Content-Type: application/kmz");
				header("Content-Disposition: attachment; filename=strabo_$filedate.kmz");
				header("Content-Length: " . filesize("ogrtemp/$randnum/data/strabo_$filedate.kmz"));

				readfile("ogrtemp/$randnum/data/strabo_$filedate.kmz");

				//remove temp directory
				if($randnum!=""){
				}

			}else{

				echo "No spots found for this search.";

			}

		} //end if dsids

	}

	public function bkupxlsOut20240531(){

			if($this->get['dsids']!=""){

				$dsids=$this->get['dsids'];
				$this->alltags = $this->strabo->getTagsFromDatasetIds($dsids);

				$data = $this->strabo->getDatasetSpotsSearch(null,$this->get);

				if(count($data)==0){
				echo "no data found for dataset $id";exit();
				}

				$columns['spot']['name']=0;
				$columns['spot']['date']=1;
				$columns['spot']['self']=2;
				$columns['spot']['notes']=3;
				$columns['spot']['geometry']=4;
				$columns['spot']['original_geometry']=5;
				$columns['spot']['Latitude']=6;
				$columns['spot']['Longitude']=7;
				$columns['spot']['Altitude(m)']=8;

				$colnum=9;

				$x=0;

				$arrayMultipleData = [];

				foreach($data['features'] as $feature){

					$orientationdatas = $feature['properties']['orientation_data'];
					if($orientationdatas){
						foreach($orientationdatas as $orientationdata){

							if($orientationdata->type=="linear_orientation"){
								foreach($orientationdata as $key=>$value){
									if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
										if($key=="feature_type"){$key="linear_feature_type";}
										if(!$columns['orientation']['linear'][$key]){
											$columns['orientation']['linear'][$key]=$colnum;
											$colnum++;
										}
									}
								}
							}

							if($orientationdata->type=="planar_orientation"){
								foreach($orientationdata as $key=>$value){
									if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
										if($key=="feature_type"){$key="planar_feature_type";}
										if(!$columns['orientation']['planar'][$key]){
											$columns['orientation']['planar'][$key]=$colnum;
											$colnum++;
										}
									}
								}
							}

							if($orientationdata->type=="tabular_orientation"){
								foreach($orientationdata as $key=>$value){
									if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
										if($key=="feature_type"){$key="tabular_feature_type";}
										if(!$columns['orientation']['tabular'][$key]){
											$columns['orientation']['tabular'][$key]=$colnum;
											$colnum++;
										}
									}
								}
							}

							$associatedorientations = $orientationdata->associated_orientation;
							if($associatedorientations){
								foreach($associatedorientations as $associatedorientation){
									if($associatedorientation->type=="planar_orientation"){
										foreach($associatedorientation as $key=>$value){
											if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
												if($key=="feature_type"){$key="planar_feature_type";}
												if(!$columns['orientation']['planar'][$key]){
													$columns['orientation']['planar'][$key]=$colnum;
													$colnum++;
												}
											}
										}

									}

									if($associatedorientation->type=="linear_orientation"){
										foreach($associatedorientation as $key=>$value){
											if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
												if($key=="feature_type"){$key="linear_feature_type";}
												if(!$columns['orientation']['linear'][$key]){
													$columns['orientation']['linear'][$key]=$colnum;
													$colnum++;
												}
											}
										}
									}

									if($associatedorientation->type=="tabular_orientation"){
										foreach($associatedorientation as $key=>$value){
											if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
												if($key=="feature_type"){$key="tabular_feature_type";}
												if(!$columns['orientation']['tabular'][$key]){
													$columns['orientation']['tabular'][$key]=$colnum;
													$colnum++;
												}
											}
										}
									}
								}
							}
						}
					}

					$x++;

				}

				foreach($data['features'] as $feature){

					$pet = $feature['properties']['pet'];
					if($pet){
						if($pet->metamorphic){

							foreach($pet->metamorphic as $met){

								foreach($met as $key=>$value){
									if($key != "id"){
										if(!$columns['pet']['metamorphic'][$key]){
											$columns['pet']['metamorphic'][$key]=$colnum;
											$colnum++;
										}
									}
								}

							}
						}
					}

					$x++;

				}

				foreach($data['features'] as $feature){

					$pet = $feature['properties']['pet'];
					if($pet){
						if($pet->igneous){

							foreach($pet->igneous as $ig){

								foreach($ig as $key=>$value){
									if($key != "id"){
										if(!$columns['pet']['igneous'][$key]){
											$columns['pet']['igneous'][$key]=$colnum;
											$colnum++;
										}
									}
								}

							}
						}
					}

					$x++;

				}

				foreach($data['features'] as $feature){

					$pet = $feature['properties']['pet'];
					if($pet){
						if($pet->minerals){

							foreach($pet->minerals as $min){

								foreach($min as $key=>$value){
									if($key != "id"){
										if(!$columns['pet']['minerals'][$key]){
											$columns['pet']['minerals'][$key]=$colnum;
											$colnum++;
										}
									}
								}

							}
						}
					}

					$x++;

				}

				foreach($data['features'] as $feature){

					$other_features = $feature['properties']['other_features'];
					if($other_features){
						foreach($other_features as $other_feature){

							foreach($other_feature as $key=>$value){
								if($key != "id"){
									if(!$columns['other_features'][$key]){
										$columns['other_features'][$key]=$colnum;
										$colnum++;
									}
								}
							}

						}
					}

					$x++;

				}

				foreach($data['features'] as $feature){

					$samples = $feature['properties']['samples'];
					if($samples){
						foreach($samples as $sample){

							foreach($sample as $key=>$value){
								if($key != "id"){
									if(!$columns['samples'][$key]){
										$columns['samples'][$key]=$colnum;
										$colnum++;
									}
								}
							}

						}
					}

					$x++;

				}

				foreach($data['features'] as $feature){

					$trace = $feature['properties']['trace'];
					if($trace){
						foreach($trace as $key=>$value){

							if(!$columns['trace'][$key]){
								$columns['trace'][$key]=$colnum;
								$colnum++;
							}
						}
					}

					$x++;

				}

				foreach($data['features'] as $feature){

					$customfields = $feature['properties']['custom_fields'];
					if($customfields){
						foreach($customfields as $key=>$value){

							if(!$columns['custom_fields'][$key]){
								$columns['custom_fields'][$key]=$colnum;
								$colnum++;
							}
						}
					}

					$x++;

				}

				//also add tags to sheet!
				//need to add a column for each key of each tag and then put an X in the corresponding cells for a given spot

	// 							}
	// 						}
	// 					}
	// 				}
	// 			}

				$tagarray = [];
				if($this->alltags != ""){

					foreach($this->alltags as $currenttag){
						foreach($currenttag as $key=>$value){
							if($key!="id" && $key!="spots"){
								if(!in_array($key, $tagarray)){
									$tagarray[]=$key;
								}
							}
						}

						if(!$columns['tags'][$currenttag->id]){
							$columns['tags'][$currenttag->id]=$colnum;
							$colnum++;
						}

					}

					$tagnum = 0;
					$tagscolumns = [];
					foreach($tagarray as $tag){
						$tagscolumns[$tag] = $tagnum;
						$tagnum++;
					}

				}

				/** PHPExcel */
				include 'PHPExcel.php';

				/** PHPExcel_Writer_Excel2007 */
				include 'PHPExcel/Writer/Excel2007.php';

				$objPHPExcel = new PHPExcel();

				// Set properties
				$objPHPExcel->getProperties()->setCreator("strabospot.org");
				$objPHPExcel->getProperties()->setLastModifiedBy("strabospot.org");
				$objPHPExcel->getProperties()->setTitle("StraboSpot.org Download");
				$objPHPExcel->getProperties()->setSubject("StraboSpot.org Download");
				$objPHPExcel->getProperties()->setDescription("StraboSpot.org Download");

				// Rename sheet
				$objPHPExcel->getActiveSheet()->setTitle('Spots');

				// Add some data
				$objPHPExcel->setActiveSheetIndex(0);

				$objPHPExcel->getActiveSheet()->SetCellValue('A1', "StraboSpot Dataset Download: $datasetname");

				$colnum=0;

				foreach($columns['spot'] as $key=>$value){

					if($key=="geometry"){$key="Real World Coordinates";}
					if($key=="original_geometry"){$key="Pixel Coordinates";}

					$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol(3,$value), $this->fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thisheader=="Age (Ma)"){$thiswidth="12";}
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;
				}

				if($columns['orientation']['tabular']){
					foreach($columns['orientation']['tabular'] as $key=>$value){

						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol(3,$value), "Tabular Orientation ".$this->fix_column_name($key));

						$thiswidth=strlen($key)-1;
						if($thiswidth<10){
							$thiswidth=10;
						}

						$colnum++;

					}
				}

				if($columns['orientation']['planar']){
					foreach($columns['orientation']['planar'] as $key=>$value){

						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol(3,$value), "Planar Orientation ".$this->fix_column_name($key));

						$thiswidth=strlen($key)-1;
						if($thiswidth<10){
							$thiswidth=10;
						}

						$colnum++;

					}
				}

				if($columns['orientation']['linear']){
					foreach($columns['orientation']['linear'] as $key=>$value){

						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol(3,$value), "Linear Orientation ".$this->fix_column_name($key));

						$thiswidth=strlen($key)-1;
						if($thiswidth<10){
							$thiswidth=10;
						}

						$colnum++;

					}
				}

				if($columns['pet']['metamorphic']){
					foreach($columns['pet']['metamorphic'] as $key=>$value){

						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol(3,$value), "Metamorphic ".$this->fix_column_name($key));

						$thiswidth=strlen($key)-1;
						if($thiswidth<10){
							$thiswidth=10;
						}

						$colnum++;

					}
				}

				if($columns['pet']['igneous']){
					foreach($columns['pet']['igneous'] as $key=>$value){

						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol(3,$value), "Igneous ".$this->fix_column_name($key));

						$thiswidth=strlen($key)-1;
						if($thiswidth<10){
							$thiswidth=10;
						}

						$colnum++;

					}
				}

				if($columns['pet']['minerals']){
					foreach($columns['pet']['igneous'] as $key=>$value){

						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol(3,$value), "Mineral ".$this->fix_column_name($key));

						$thiswidth=strlen($key)-1;
						if($thiswidth<10){
							$thiswidth=10;
						}

						$colnum++;

					}
				}

				if($columns['other_features']){
					foreach($columns['other_features'] as $key=>$value){

						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol(3,$value), "Other Feature ".$this->fix_column_name($key));

						$thiswidth=strlen($key)-1;
						if($thisheader=="Age (Ma)"){$thiswidth="12";}
						if($thiswidth<10){
							$thiswidth=10;
						}

						$colnum++;
					}
				}

				if($columns['samples']){
					foreach($columns['samples'] as $key=>$value){

						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol(3,$value), "Sample ".$this->fix_column_name($key));

						$thiswidth=strlen($key)-1;
						if($thisheader=="Age (Ma)"){$thiswidth="12";}
						if($thiswidth<10){
							$thiswidth=10;
						}

						$colnum++;
					}
				}

				if($columns['trace']){
					foreach($columns['trace'] as $key=>$value){

						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol(3,$value), "Trace ".$this->fix_column_name($key));

						$thiswidth=strlen($key)-1;
						if($thisheader=="Age (Ma)"){$thiswidth="12";}
						if($thiswidth<10){
							$thiswidth=10;
						}

						$colnum++;
					}
				}

				if($columns['custom_fields']){
					foreach($columns['custom_fields'] as $key=>$value){

						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol(3,$value), $this->fix_column_name($key));

						$thiswidth=strlen($key)-1;
						if($thisheader=="Age (Ma)"){$thiswidth="12";}
						if($thiswidth<10){
							$thiswidth=10;
						}

						$colnum++;
					}
				}

				if($columns['tags']){
					foreach($columns['tags'] as $key=>$value){

						$taglabel = "";
						foreach($this->alltags as $tag){

							if($key == $tag->id){
								$taglabel = "Tag:".$tag->name;
							}
						}

						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol(3,$value), $taglabel);

						$colnum++;
					}
				}

				//write data
				$rownum=4;
				foreach($data['features'] as $feature){

					//use geoPHP to get WKT
					$mygeojson=$feature['geometry'];
					$mygeojson=trim(json_encode($mygeojson));

					try {
						$mywkt=geoPHP::load($mygeojson,"json");
						$wkt = $mywkt->out('wkt');
						$geometry=$wkt;
					} catch (Exception $e) {
						$geometry="";
					}

					//use geoPHP to get WKT
					$mygeojson=$feature['original_geometry'];
					$mygeojson=trim(json_encode($mygeojson));

					try {
						$mywkt=geoPHP::load($mygeojson,"json");
						$wkt = $mywkt->out('wkt');
						$original_geometry=$wkt;
					} catch (Exception $e) {
						$original_geometry="";
					}

					$spotid = $feature['properties']['id'];
					$spotname = (string)$feature['properties']['name'];
					$spotdate = $feature['properties']['date'];
					$spotself = $feature['properties']['self'];
					$spotnotes = $feature['properties']['notes'];
					$altitude = $feature['properties']['altitude'];

					$latitude = "";
					$longitude = "";

					if(strtolower(substr($geometry,0,5))=="point"){
						$lonlatgeom=$geometry;
						$lonlatgeom=strtolower($lonlatgeom);
						$lonlatgeom=str_replace("point ","",$lonlatgeom);
						$lonlatgeom=str_replace("(","",$lonlatgeom);
						$lonlatgeom=str_replace(")","",$lonlatgeom);
						$lonlatgeom=explode(" ",$lonlatgeom);
						$longitude=$lonlatgeom[0];
						$latitude=$lonlatgeom[1];
					}

					unset($arrayMultipleData);
					$maxcount = 0;
					$linear_orientation_count = 0;
					$planar_orientation_count = 0;
					$tabular_orientation_count = 0;
					$_3d_structure_count = 0;
					$other_features_count = 0;
					$samples_count = 0;
					$traces_count = 0;
					$metamorphics_count = 0;
					$igneouses_count = 0;
					$minerals_count = 0;
					$tags_count = 0;

					$orientationdatas = $feature['properties']['orientation_data'];

					if($orientationdatas){
						foreach($orientationdatas as $orientationdata){

							if($orientationdata->type=="linear_orientation"){
								foreach($orientationdata as $key=>$value){
									if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
										if($key=="feature_type"){$key="linear_feature_type";}
										$arrayMultipleData['linear'][$linear_orientation_count][$key]=$value;
									}
								}
								$linear_orientation_count++;
							}

							if($orientationdata->type=="planar_orientation"){
								foreach($orientationdata as $key=>$value){
									if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
										if($key=="feature_type"){$key="planar_feature_type";}
										$arrayMultipleData['planar'][$planar_orientation_count][$key]=$value;
									}
								}
								$planar_orientation_count++;
							}

							if($orientationdata->type=="tabular_orientation"){
								foreach($orientationdata as $key=>$value){
									if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
										if($key=="feature_type"){$key="tabular_feature_type";}
										$arrayMultipleData['tabular'][$tabular_orientation_count][$key]=$value;
									}
								}
								$tabular_orientation_count++;
							}

							$associatedorientations = $orientationdata->associated_orientation;
							if($associatedorientations){
								foreach($associatedorientations as $associatedorientation){
									if($associatedorientation->type=="planar_orientation"){
										foreach($associatedorientation as $key=>$value){
											if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
												if($key=="feature_type"){$key="planar_feature_type";}
												$arrayMultipleData['planar'][$planar_orientation_count][$key]=$value;
											}
										}
										$planar_orientation_count++;
									}

									if($associatedorientation->type=="linear_orientation"){
										foreach($associatedorientation as $key=>$value){
											if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
												if($key=="feature_type"){$key="linear_feature_type";}
												$arrayMultipleData['linear'][$linear_orientation_count][$key]=$value;
											}
										}
										$linear_orientation_count++;
									}

									if($associatedorientation->type=="tabular_orientation"){
										foreach($associatedorientation as $key=>$value){
											if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
												if($key=="feature_type"){$key="tabular_feature_type";}
												$arrayMultipleData['tabular'][$tabular_orientation_count][$key]=$value;
											}
										}
										$tabular_orientation_count++;
									}
								}
							}
						}
					}

					$metamorphics = $feature['properties']['pet']->metamorphic;
					if($metamorphics){
						foreach($metamorphics as $metamorphic){

							foreach($metamorphic as $key=>$value){
								if($key != "id" && $key != "" ){
									$arrayMultipleData['metamorphic'][$metamorphics_count][$key]=$value;
								}
							}
							$metamorphics_count++;
						}
					}

					$igneouses = $feature['properties']['pet']->igneous;
					if($igneouses){
						foreach($igneouses as $igneous){

							foreach($igneous as $key=>$value){
								if($key != "id" && $key != "" ){
									$arrayMultipleData['igneous'][$igneouses_count][$key]=$value;
								}
							}
							$igneouses_count++;
						}
					}

					$minerals = $feature['properties']['pet']->minerals;
					if($minerals){
						foreach($minerals as $mineral){

							foreach($mineral as $key=>$value){
								if($key != "id" && $key != "" ){
									$arrayMultipleData['mineral'][$minerals_count][$key]=$value;
								}
							}
							$minerals_count++;
						}
					}

					$other_features = $feature['properties']['other_features'];
					if($other_features){
						foreach($other_features as $other_feature){
							foreach($other_feature as $key=>$value){
								if($key != "id"){
									$arrayMultipleData['other_features'][$other_features_count][$key]=$value;
								}
							}
							$other_features_count++;
						}
					}

					$samples = $feature['properties']['samples'];
					if($samples){
						foreach($samples as $sample){
							foreach($sample as $key=>$value){
								if($key != "id"){
									$arrayMultipleData['samples'][$samples_count][$key]=$value;
								}
							}
							$samples_count++;
						}
					}

					$trace = $feature['properties']['trace'];
					if($trace){
						foreach($trace as $key=>$value){
							$arrayMultipleData['trace'][$traces_count][$key]=$value;
						}
						$traces_count++;
					}

					if($linear_orientation_count > $maxcount) $maxcount = $linear_orientation_count;
					if($planar_orientation_count > $maxcount) $maxcount = $planar_orientation_count;
					if($tabular_orientation_count > $maxcount) $maxcount = $tabular_orientation_count;
					if($other_features_count > $maxcount) $maxcount = $other_features_count;
					if($samples_count > $maxcount) $maxcount = $samples_count;
					if($traces_count > $maxcount) $maxcount = $traces_count;
					if($metamorphics_count > $maxcount) $maxcount = $metamorphics_count;
					if($igneouses_count > $maxcount) $maxcount = $igneouses_count;
					if($minerals_count > $maxcount) $maxcount = $minerals_count;

					if($maxcount==0) $maxcount = 1;

					for($spotrepeat = 0; $spotrepeat < $maxcount; $spotrepeat ++){

						//write out each row here
						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,0), $spotname);
						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,1), $spotdate);
						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,2), $spotself);
						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,3),$spotnotes);
						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,4),$geometry);

						if($geometry!=$original_geometry){
							$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,5),$original_geometry);
						}

						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,6),$latitude);
						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,7),$longitude);
						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,8),$altitude);

						foreach($columns as $key=>$value){
							if($key!="spot"){
								if($key == "orientation" || $key == "pet"){
									foreach($value as $otype=>$ors){
										foreach($ors as $orkey=>$orval){
											$rawVal = $arrayMultipleData[$otype][$spotrepeat][$orkey];
											if(is_array($rawVal)){
												$showVal = implode(", ", $rawVal);
											}else{
												$showVal = $rawVal;
											}

											$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,$orval),$showVal);
										}
									}
								}else{
									foreach($value as $newkey=>$column){
										$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,$column),$arrayMultipleData[$key][$spotrepeat][$newkey]);
									}
								}
							}
						}

						foreach($columns as $key=>$value){
							if($key=="tags"){
								//determine if the spot belongs in the tag and mark X if it is
								foreach($value as $tagid=>$colnum){
									foreach($this->alltags as $tag){
										if($tagid == $tag->id){

											if(in_array($spotid, $tag->spots)){
												$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,$colnum),"X");
											}

											if($tag->features != null){
												foreach($tag->features as $snum=>$other){
													if($snum == $spotid){
														$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,$colnum),"X");
													}
												}
											}
										}
									}
								}
							}
						}

						$rownum++;

					}

				}//end foreach feature

				//Add tags if necessary
				// Rename sheet
				if($this->alltags != ""){
					$tagsWorkSheet = $objPHPExcel->createSheet(1); //Setting index when creating

					//Write cells
					$tagsWorkSheet->SetCellValue('A1', 'Tags:');

					// Rename sheet
					$tagsWorkSheet->setTitle("Tag Details");

					$rownum = 2;
					$colnum = 0;
					foreach($tagscolumns as $key=>$value){
						$tagsWorkSheet->SetCellValue($this->rowcol($rownum,$value),$this->fix_column_name($key));
					}

					$rownum = 3;
					foreach($this->alltags as $tag){
						foreach($tag as $key=>$value){
							if($key!="id" && $key!="spots" && $key!="features"){
								if(is_array($value)){
									$showvalue = implode(",", $value);
								}else{
									$showvalue = $value;
								}

								$tagsWorkSheet->SetCellValue($this->rowcol($rownum,$tagscolumns[$key]),$showvalue);
							}
						}
						$rownum++;
					}

				}

			}

			// Save Excel 2007 file
			$objWriter = new PHPExcel_Writer_Excel2007($objPHPExcel);

			// We'll be outputting an excel file
			header('Content-type: application/vnd.ms-excel');

			$filedate = date("m_d_Y");

			// It will be called file.xls
			header('Content-Disposition: attachment; filename="'."StraboSpot_Output_".$filedate.".xlsx".'"');

			// Write file to the browser
			$objWriter->save('php://output');

		}

	public function bkupxlsOut_20241113(){

			if($this->get['dsids']!=""){

				$dsids=$this->get['dsids'];
				$this->alltags = $this->strabo->getTagsFromDatasetIds($dsids);

				$data = $this->strabo->getDatasetSpotsSearch(null,$this->get);

				if(count($data)==0){
				echo "no data found for dataset $id";exit();
				}

				$columns['spot']['name']=0;
				$columns['spot']['date']=1;
				$columns['spot']['self']=2;
				$columns['spot']['notes']=3;
				$columns['spot']['geometry']=4;
				$columns['spot']['original_geometry']=5;
				$columns['spot']['Latitude']=6;
				$columns['spot']['Longitude']=7;
				$columns['spot']['Altitude(m)']=8;

				$colnum=9;

				$x=0;

				$arrayMultipleData = [];

				foreach($data['features'] as $feature){

					$orientationdatas = $feature['properties']['orientation_data'];
					if($orientationdatas){
						foreach($orientationdatas as $orientationdata){

							if($orientationdata->type=="linear_orientation"){
								foreach($orientationdata as $key=>$value){
									if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
										if($key=="feature_type"){$key="linear_feature_type";}
										if(!$columns['orientation']['linear'][$key]){
											$columns['orientation']['linear'][$key]=$colnum;
											$colnum++;
										}
									}
								}
							}

							if($orientationdata->type=="planar_orientation"){
								foreach($orientationdata as $key=>$value){
									if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
										if($key=="feature_type"){$key="planar_feature_type";}
										if(!$columns['orientation']['planar'][$key]){
											$columns['orientation']['planar'][$key]=$colnum;
											$colnum++;
										}
									}
								}
							}

							if($orientationdata->type=="tabular_orientation"){
								foreach($orientationdata as $key=>$value){
									if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
										if($key=="feature_type"){$key="tabular_feature_type";}
										if(!$columns['orientation']['tabular'][$key]){
											$columns['orientation']['tabular'][$key]=$colnum;
											$colnum++;
										}
									}
								}
							}

							$associatedorientations = $orientationdata->associated_orientation;
							if($associatedorientations){
								foreach($associatedorientations as $associatedorientation){
									if($associatedorientation->type=="planar_orientation"){
										foreach($associatedorientation as $key=>$value){
											if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
												if($key=="feature_type"){$key="planar_feature_type";}
												if(!$columns['orientation']['planar'][$key]){
													$columns['orientation']['planar'][$key]=$colnum;
													$colnum++;
												}
											}
										}

									}

									if($associatedorientation->type=="linear_orientation"){
										foreach($associatedorientation as $key=>$value){
											if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
												if($key=="feature_type"){$key="linear_feature_type";}
												if(!$columns['orientation']['linear'][$key]){
													$columns['orientation']['linear'][$key]=$colnum;
													$colnum++;
												}
											}
										}
									}

									if($associatedorientation->type=="tabular_orientation"){
										foreach($associatedorientation as $key=>$value){
											if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
												if($key=="feature_type"){$key="tabular_feature_type";}
												if(!$columns['orientation']['tabular'][$key]){
													$columns['orientation']['tabular'][$key]=$colnum;
													$colnum++;
												}
											}
										}
									}
								}
							}
						}
					}

					$x++;

				}

				foreach($data['features'] as $feature){

					$pet = $feature['properties']['pet'];
					if($pet){
						if($pet->metamorphic){

							foreach($pet->metamorphic as $met){

								foreach($met as $key=>$value){
									if($key != "id"){
										if(!$columns['pet']['metamorphic'][$key]){
											$columns['pet']['metamorphic'][$key]=$colnum;
											$colnum++;
										}
									}
								}

							}
						}
					}

					$x++;

				}

				foreach($data['features'] as $feature){

					$pet = $feature['properties']['pet'];
					if($pet){
						if($pet->igneous){

							foreach($pet->igneous as $ig){

								foreach($ig as $key=>$value){
									if($key != "id"){
										if(!$columns['pet']['igneous'][$key]){
											$columns['pet']['igneous'][$key]=$colnum;
											$colnum++;
										}
									}
								}

							}
						}
					}

					$x++;

				}

				foreach($data['features'] as $feature){

					$pet = $feature['properties']['pet'];
					if($pet){
						if($pet->minerals){

							foreach($pet->minerals as $min){

								foreach($min as $key=>$value){
									if($key != "id"){
										if(!$columns['pet']['minerals'][$key]){
											$columns['pet']['minerals'][$key]=$colnum;
											$colnum++;
										}
									}
								}

							}
						}
					}

					$x++;

				}

				foreach($data['features'] as $feature){

					$other_features = $feature['properties']['other_features'];
					if($other_features){
						foreach($other_features as $other_feature){

							foreach($other_feature as $key=>$value){
								if($key != "id"){
									if(!$columns['other_features'][$key]){
										$columns['other_features'][$key]=$colnum;
										$colnum++;
									}
								}
							}

						}
					}

					$x++;

				}

				foreach($data['features'] as $feature){

					$samples = $feature['properties']['samples'];
					if($samples){
						foreach($samples as $sample){

							foreach($sample as $key=>$value){
								if($key != "id"){
									if(!$columns['samples'][$key]){
										$columns['samples'][$key]=$colnum;
										$colnum++;
									}
								}
							}

						}
					}

					$x++;

				}

				foreach($data['features'] as $feature){

					$trace = $feature['properties']['trace'];
					if($trace){
						foreach($trace as $key=>$value){

							if(!$columns['trace'][$key]){
								$columns['trace'][$key]=$colnum;
								$colnum++;
							}
						}
					}

					$x++;

				}

				foreach($data['features'] as $feature){

					$tephra = $feature['properties']['tephra'];
					if($tephra){
						foreach($tephra as $t){
							foreach($t as $key=>$value){
								if($key != "id"){
									if(!$columns['tephra'][$key]){
										$columns['tephra'][$key]=$colnum;
										$colnum++;
									}
								}
							}
						}
					}

					$x++;

				}

				foreach($data['features'] as $feature){

					$customfields = $feature['properties']['custom_fields'];
					if($customfields){
						foreach($customfields as $key=>$value){

							if(!$columns['custom_fields'][$key]){
								$columns['custom_fields'][$key]=$colnum;
								$colnum++;
							}
						}
					}

					$x++;

				}

				//also add tags to sheet!
				//need to add a column for each key of each tag and then put an X in the corresponding cells for a given spot

	// 							}
	// 						}
	// 					}
	// 				}
	// 			}

				$tagarray = [];
				if($this->alltags != ""){

					foreach($this->alltags as $currenttag){
						foreach($currenttag as $key=>$value){
							if($key!="id" && $key!="spots"){
								if(!in_array($key, $tagarray)){
									$tagarray[]=$key;
								}
							}
						}

						if(!$columns['tags'][$currenttag->id]){
							$columns['tags'][$currenttag->id]=$colnum;
							$colnum++;
						}

					}

					$tagnum = 0;
					$tagscolumns = [];
					foreach($tagarray as $tag){
						$tagscolumns[$tag] = $tagnum;
						$tagnum++;
					}

				}

				/** PHPExcel */
				include 'PHPExcel.php';

				/** PHPExcel_Writer_Excel2007 */
				include 'PHPExcel/Writer/Excel2007.php';

				$objPHPExcel = new PHPExcel();

				// Set properties
				$objPHPExcel->getProperties()->setCreator("strabospot.org");
				$objPHPExcel->getProperties()->setLastModifiedBy("strabospot.org");
				$objPHPExcel->getProperties()->setTitle("StraboSpot.org Download");
				$objPHPExcel->getProperties()->setSubject("StraboSpot.org Download");
				$objPHPExcel->getProperties()->setDescription("StraboSpot.org Download");

				// Rename sheet
				$objPHPExcel->getActiveSheet()->setTitle('Spots');

				// Add some data
				$objPHPExcel->setActiveSheetIndex(0);

				$objPHPExcel->getActiveSheet()->SetCellValue('A1', "StraboSpot Dataset Download: $datasetname");

				$colnum=0;

				foreach($columns['spot'] as $key=>$value){

					if($key=="geometry"){$key="Real World Coordinates";}
					if($key=="original_geometry"){$key="Pixel Coordinates";}

					$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol(3,$value), $this->fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thisheader=="Age (Ma)"){$thiswidth="12";}
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;
				}

				if($columns['orientation']['tabular']){
					foreach($columns['orientation']['tabular'] as $key=>$value){

						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol(3,$value), "Tabular Orientation ".$this->fix_column_name($key));

						$thiswidth=strlen($key)-1;
						if($thiswidth<10){
							$thiswidth=10;
						}

						$colnum++;

					}
				}

				if($columns['orientation']['planar']){
					foreach($columns['orientation']['planar'] as $key=>$value){

						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol(3,$value), "Planar Orientation ".$this->fix_column_name($key));

						$thiswidth=strlen($key)-1;
						if($thiswidth<10){
							$thiswidth=10;
						}

						$colnum++;

					}
				}

				if($columns['orientation']['linear']){
					foreach($columns['orientation']['linear'] as $key=>$value){

						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol(3,$value), "Linear Orientation ".$this->fix_column_name($key));

						$thiswidth=strlen($key)-1;
						if($thiswidth<10){
							$thiswidth=10;
						}

						$colnum++;

					}
				}

				if($columns['pet']['metamorphic']){
					foreach($columns['pet']['metamorphic'] as $key=>$value){

						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol(3,$value), "Metamorphic ".$this->fix_column_name($key));

						$thiswidth=strlen($key)-1;
						if($thiswidth<10){
							$thiswidth=10;
						}

						$colnum++;

					}
				}

				if($columns['pet']['igneous']){
					foreach($columns['pet']['igneous'] as $key=>$value){

						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol(3,$value), "Igneous ".$this->fix_column_name($key));

						$thiswidth=strlen($key)-1;
						if($thiswidth<10){
							$thiswidth=10;
						}

						$colnum++;

					}
				}

				if($columns['pet']['minerals']){
					foreach($columns['pet']['igneous'] as $key=>$value){

						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol(3,$value), "Mineral ".$this->fix_column_name($key));

						$thiswidth=strlen($key)-1;
						if($thiswidth<10){
							$thiswidth=10;
						}

						$colnum++;

					}
				}

				if($columns['other_features']){
					foreach($columns['other_features'] as $key=>$value){

						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol(3,$value), "Other Feature ".$this->fix_column_name($key));

						$thiswidth=strlen($key)-1;
						if($thisheader=="Age (Ma)"){$thiswidth="12";}
						if($thiswidth<10){
							$thiswidth=10;
						}

						$colnum++;
					}
				}

				if($columns['samples']){
					foreach($columns['samples'] as $key=>$value){

						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol(3,$value), "Sample ".$this->fix_column_name($key));

						$thiswidth=strlen($key)-1;
						if($thisheader=="Age (Ma)"){$thiswidth="12";}
						if($thiswidth<10){
							$thiswidth=10;
						}

						$colnum++;
					}
				}

				if($columns['trace']){
					foreach($columns['trace'] as $key=>$value){

						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol(3,$value), "Trace ".$this->fix_column_name($key));

						$thiswidth=strlen($key)-1;
						if($thisheader=="Age (Ma)"){$thiswidth="12";}
						if($thiswidth<10){
							$thiswidth=10;
						}

						$colnum++;
					}
				}

				if($columns['tephra']){
					foreach($columns['tephra'] as $key=>$value){

						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol(3,$value), "Tephra ".$this->fix_column_name($key));

						$thiswidth=strlen($key)-1;
						if($thisheader=="Age (Ma)"){$thiswidth="12";}
						if($thiswidth<10){
							$thiswidth=10;
						}

						$colnum++;
					}
				}

				if($columns['custom_fields']){
					foreach($columns['custom_fields'] as $key=>$value){

						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol(3,$value), $this->fix_column_name($key));

						$thiswidth=strlen($key)-1;
						if($thisheader=="Age (Ma)"){$thiswidth="12";}
						if($thiswidth<10){
							$thiswidth=10;
						}

						$colnum++;
					}
				}

				if($columns['tags']){
					foreach($columns['tags'] as $key=>$value){

						$taglabel = "";
						foreach($this->alltags as $tag){

							if($key == $tag->id){
								$taglabel = "Tag:".$tag->name;
							}
						}

						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol(3,$value), $taglabel);

						$colnum++;
					}
				}

				//write data
				$rownum=4;
				foreach($data['features'] as $feature){

					//use geoPHP to get WKT
					$mygeojson=$feature['geometry'];
					$mygeojson=trim(json_encode($mygeojson));

					try {
						$mywkt=geoPHP::load($mygeojson,"json");
						$wkt = $mywkt->out('wkt');
						$geometry=$wkt;
					} catch (Exception $e) {
						$geometry="";
					}

					//use geoPHP to get WKT
					$mygeojson=$feature['original_geometry'];
					$mygeojson=trim(json_encode($mygeojson));

					try {
						$mywkt=geoPHP::load($mygeojson,"json");
						$wkt = $mywkt->out('wkt');
						$original_geometry=$wkt;
					} catch (Exception $e) {
						$original_geometry="";
					}

					$spotid = $feature['properties']['id'];
					$spotname = (string)$feature['properties']['name'];
					$spotdate = $feature['properties']['date'];
					$spotself = $feature['properties']['self'];
					$spotnotes = $feature['properties']['notes'];
					$altitude = $feature['properties']['altitude'];

					$latitude = "";
					$longitude = "";

					if(strtolower(substr($geometry,0,5))=="point"){
						$lonlatgeom=$geometry;
						$lonlatgeom=strtolower($lonlatgeom);
						$lonlatgeom=str_replace("point ","",$lonlatgeom);
						$lonlatgeom=str_replace("(","",$lonlatgeom);
						$lonlatgeom=str_replace(")","",$lonlatgeom);
						$lonlatgeom=explode(" ",$lonlatgeom);
						$longitude=$lonlatgeom[0];
						$latitude=$lonlatgeom[1];
					}

					unset($arrayMultipleData);
					$maxcount = 0;
					$linear_orientation_count = 0;
					$planar_orientation_count = 0;
					$tabular_orientation_count = 0;
					$_3d_structure_count = 0;
					$other_features_count = 0;
					$samples_count = 0;
					$traces_count = 0;
					$tephras_count = 0;
					$metamorphics_count = 0;
					$igneouses_count = 0;
					$minerals_count = 0;
					$tags_count = 0;

					$orientationdatas = $feature['properties']['orientation_data'];

					if($orientationdatas){
						foreach($orientationdatas as $orientationdata){

							if($orientationdata->type=="linear_orientation"){
								foreach($orientationdata as $key=>$value){
									if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
										if($key=="feature_type"){$key="linear_feature_type";}
										$arrayMultipleData['linear'][$linear_orientation_count][$key]=$value;
									}
								}
								$linear_orientation_count++;
							}

							if($orientationdata->type=="planar_orientation"){
								foreach($orientationdata as $key=>$value){
									if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
										if($key=="feature_type"){$key="planar_feature_type";}
										$arrayMultipleData['planar'][$planar_orientation_count][$key]=$value;
									}
								}
								$planar_orientation_count++;
							}

							if($orientationdata->type=="tabular_orientation"){
								foreach($orientationdata as $key=>$value){
									if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
										if($key=="feature_type"){$key="tabular_feature_type";}
										$arrayMultipleData['tabular'][$tabular_orientation_count][$key]=$value;
									}
								}
								$tabular_orientation_count++;
							}

							$associatedorientations = $orientationdata->associated_orientation;
							if($associatedorientations){
								foreach($associatedorientations as $associatedorientation){
									if($associatedorientation->type=="planar_orientation"){
										foreach($associatedorientation as $key=>$value){
											if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
												if($key=="feature_type"){$key="planar_feature_type";}
												$arrayMultipleData['planar'][$planar_orientation_count][$key]=$value;
											}
										}
										$planar_orientation_count++;
									}

									if($associatedorientation->type=="linear_orientation"){
										foreach($associatedorientation as $key=>$value){
											if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
												if($key=="feature_type"){$key="linear_feature_type";}
												$arrayMultipleData['linear'][$linear_orientation_count][$key]=$value;
											}
										}
										$linear_orientation_count++;
									}

									if($associatedorientation->type=="tabular_orientation"){
										foreach($associatedorientation as $key=>$value){
											if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
												if($key=="feature_type"){$key="tabular_feature_type";}
												$arrayMultipleData['tabular'][$tabular_orientation_count][$key]=$value;
											}
										}
										$tabular_orientation_count++;
									}
								}
							}
						}
					}

					$metamorphics = $feature['properties']['pet']->metamorphic;
					if($metamorphics){
						foreach($metamorphics as $metamorphic){

							foreach($metamorphic as $key=>$value){
								if($key != "id" && $key != "" ){
									$arrayMultipleData['metamorphic'][$metamorphics_count][$key]=$value;
								}
							}
							$metamorphics_count++;
						}
					}

					$igneouses = $feature['properties']['pet']->igneous;
					if($igneouses){
						foreach($igneouses as $igneous){

							foreach($igneous as $key=>$value){
								if($key != "id" && $key != "" ){
									$arrayMultipleData['igneous'][$igneouses_count][$key]=$value;
								}
							}
							$igneouses_count++;
						}
					}

					$minerals = $feature['properties']['pet']->minerals;
					if($minerals){
						foreach($minerals as $mineral){

							foreach($mineral as $key=>$value){
								if($key != "id" && $key != "" ){
									$arrayMultipleData['mineral'][$minerals_count][$key]=$value;
								}
							}
							$minerals_count++;
						}
					}

					$other_features = $feature['properties']['other_features'];
					if($other_features){
						foreach($other_features as $other_feature){
							foreach($other_feature as $key=>$value){
								if($key != "id"){
									$arrayMultipleData['other_features'][$other_features_count][$key]=$value;
								}
							}
							$other_features_count++;
						}
					}

					$samples = $feature['properties']['samples'];
					if($samples){
						foreach($samples as $sample){
							foreach($sample as $key=>$value){
								if($key != "id"){
									$arrayMultipleData['samples'][$samples_count][$key]=$value;
								}
							}
							$samples_count++;
						}
					}

					$trace = $feature['properties']['trace'];
					if($trace){
						foreach($trace as $key=>$value){
							$arrayMultipleData['trace'][$traces_count][$key]=$value;
						}
						$traces_count++;
					}

					$tephras = $feature['properties']['tephra'];
					if($tephras){
						foreach($tephras as $tephra){
							foreach($tephra as $key=>$value){
								if($key != "id"){
									$arrayMultipleData['tephra'][$tephras_count][$key]=$value;
								}
							}
							$tephras_count++;
						}
					}

					if($linear_orientation_count > $maxcount) $maxcount = $linear_orientation_count;
					if($planar_orientation_count > $maxcount) $maxcount = $planar_orientation_count;
					if($tabular_orientation_count > $maxcount) $maxcount = $tabular_orientation_count;
					if($other_features_count > $maxcount) $maxcount = $other_features_count;
					if($samples_count > $maxcount) $maxcount = $samples_count;
					if($traces_count > $maxcount) $maxcount = $traces_count;
					if($tephras_count > $maxcount) $maxcount = $tephras_count;
					if($metamorphics_count > $maxcount) $maxcount = $metamorphics_count;
					if($igneouses_count > $maxcount) $maxcount = $igneouses_count;
					if($minerals_count > $maxcount) $maxcount = $minerals_count;

					if($maxcount==0) $maxcount = 1;

					for($spotrepeat = 0; $spotrepeat < $maxcount; $spotrepeat ++){

						//write out each row here
						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,0), $spotname);
						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,1), $spotdate);
						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,2), $spotself);
						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,3),$spotnotes);
						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,4),$geometry);

						if($geometry!=$original_geometry){
							$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,5),$original_geometry);
						}

						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,6),$latitude);
						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,7),$longitude);
						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,8),$altitude);

						foreach($columns as $key=>$value){
							if($key!="spot"){
								if($key == "orientation" || $key == "pet"){
									foreach($value as $otype=>$ors){
										foreach($ors as $orkey=>$orval){
											$rawVal = $arrayMultipleData[$otype][$spotrepeat][$orkey];
											if(is_array($rawVal)){
												$showVal = implode(", ", $rawVal);
											}else{
												$showVal = $rawVal;
											}

											$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,$orval),$showVal);
										}
									}
								}else{
									foreach($value as $newkey=>$column){

										$rawVal = $arrayMultipleData[$key][$spotrepeat][$newkey];
										if(is_array($rawVal)){
											$showVal = implode(", ", $rawVal);
										}else{
											$showVal = $rawVal;
										}

										$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,$column),$showVal);
									}
								}
							}
						}

						foreach($columns as $key=>$value){
							if($key=="tags"){
								//determine if the spot belongs in the tag and mark X if it is
								foreach($value as $tagid=>$colnum){
									foreach($this->alltags as $tag){
										if($tagid == $tag->id){

											if(in_array($spotid, $tag->spots)){
												$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,$colnum),"X");
											}

											if($tag->features != null){
												foreach($tag->features as $snum=>$other){
													if($snum == $spotid){
														$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,$colnum),"X");
													}
												}
											}
										}
									}
								}
							}
						}

						$rownum++;

					}

				}//end foreach feature

				//Add tags if necessary
				// Rename sheet
				if($this->alltags != ""){
					$tagsWorkSheet = $objPHPExcel->createSheet(1); //Setting index when creating

					//Write cells
					$tagsWorkSheet->SetCellValue('A1', 'Tags:');

					// Rename sheet
					$tagsWorkSheet->setTitle("Tag Details");

					$rownum = 2;
					$colnum = 0;
					foreach($tagscolumns as $key=>$value){
						$tagsWorkSheet->SetCellValue($this->rowcol($rownum,$value),$this->fix_column_name($key));
					}

					$rownum = 3;
					foreach($this->alltags as $tag){
						foreach($tag as $key=>$value){
							if($key!="id" && $key!="spots" && $key!="features"){
								if(is_array($value)){
									$showvalue = implode(",", $value);
								}else{
									$showvalue = $value;
								}

								$tagsWorkSheet->SetCellValue($this->rowcol($rownum,$tagscolumns[$key]),$showvalue);
							}
						}
						$rownum++;
					}

				}

			}

			// Save Excel 2007 file
			$objWriter = new PHPExcel_Writer_Excel2007($objPHPExcel);

			// We'll be outputting an excel file
			header('Content-type: application/vnd.ms-excel');

			$filedate = date("m_d_Y");

			// It will be called file.xls
			header('Content-Disposition: attachment; filename="'."StraboSpot_Output_".$filedate.".xlsx".'"');

			// Write file to the browser
			$objWriter->save('php://output');

		}

	public function xlsOut(){

			if($this->get['dsids']!=""){

				$dsids=$this->get['dsids'];
				$this->alltags = $this->strabo->getTagsFromDatasetIds($dsids);

				$data = $this->strabo->getDatasetSpotsSearch(null,$this->get);

				if(count($data)==0){
				echo "no data found for dataset $id";exit();
				}

				$columns['spot']['name']=0;
				$columns['spot']['date']=1;
				$columns['spot']['self']=2;
				$columns['spot']['notes']=3;
				$columns['spot']['geometry']=4;
				$columns['spot']['original_geometry']=5;
				$columns['spot']['Latitude']=6;
				$columns['spot']['Longitude']=7;
				$columns['spot']['Altitude(m)']=8;

				$colnum=9;

				$x=0;

				$arrayMultipleData = [];

				foreach($data['features'] as $feature){

					$orientationdatas = $feature['properties']['orientation_data'];
					if($orientationdatas){
						foreach($orientationdatas as $orientationdata){

							if($orientationdata->type=="linear_orientation"){
								foreach($orientationdata as $key=>$value){
									if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
										if($key=="feature_type"){$key="linear_feature_type";}
										if(!$columns['orientation']['linear'][$key]){
											$columns['orientation']['linear'][$key]=$colnum;
											$colnum++;
										}
									}
								}
							}

							if($orientationdata->type=="planar_orientation"){
								foreach($orientationdata as $key=>$value){
									if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
										if($key=="feature_type"){$key="planar_feature_type";}
										if(!$columns['orientation']['planar'][$key]){
											$columns['orientation']['planar'][$key]=$colnum;
											$colnum++;
										}
									}
								}
							}

							if($orientationdata->type=="tabular_orientation"){
								foreach($orientationdata as $key=>$value){
									if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
										if($key=="feature_type"){$key="tabular_feature_type";}
										if(!$columns['orientation']['tabular'][$key]){
											$columns['orientation']['tabular'][$key]=$colnum;
											$colnum++;
										}
									}
								}
							}

							$associatedorientations = $orientationdata->associated_orientation;
							if($associatedorientations){
								foreach($associatedorientations as $associatedorientation){
									if($associatedorientation->type=="planar_orientation"){
										foreach($associatedorientation as $key=>$value){
											if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
												if($key=="feature_type"){$key="planar_feature_type";}
												if(!$columns['orientation']['planar'][$key]){
													$columns['orientation']['planar'][$key]=$colnum;
													$colnum++;
												}
											}
										}

									}

									if($associatedorientation->type=="linear_orientation"){
										foreach($associatedorientation as $key=>$value){
											if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
												if($key=="feature_type"){$key="linear_feature_type";}
												if(!$columns['orientation']['linear'][$key]){
													$columns['orientation']['linear'][$key]=$colnum;
													$colnum++;
												}
											}
										}
									}

									if($associatedorientation->type=="tabular_orientation"){
										foreach($associatedorientation as $key=>$value){
											if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
												if($key=="feature_type"){$key="tabular_feature_type";}
												if(!$columns['orientation']['tabular'][$key]){
													$columns['orientation']['tabular'][$key]=$colnum;
													$colnum++;
												}
											}
										}
									}
								}
							}
						}
					}

					$x++;

				}

				foreach($data['features'] as $feature){

					$pet = $feature['properties']['pet'];
					if($pet){
						if($pet->metamorphic){

							foreach($pet->metamorphic as $met){

								foreach($met as $key=>$value){
									if($key != "id"){
										if(!$columns['pet']['metamorphic'][$key]){
											$columns['pet']['metamorphic'][$key]=$colnum;
											$colnum++;
										}
									}
								}

							}
						}
					}

					$x++;

				}

				foreach($data['features'] as $feature){

					$pet = $feature['properties']['pet'];
					if($pet){
						if($pet->igneous){

							foreach($pet->igneous as $ig){

								foreach($ig as $key=>$value){
									if($key != "id"){
										if(!$columns['pet']['igneous'][$key]){
											$columns['pet']['igneous'][$key]=$colnum;
											$colnum++;
										}
									}
								}

							}
						}
					}

					$x++;

				}

				foreach($data['features'] as $feature){

					$pet = $feature['properties']['pet'];
					if($pet){
						if($pet->minerals){

							foreach($pet->minerals as $min){

								foreach($min as $key=>$value){
									if($key != "id"){
										if(!$columns['pet']['minerals'][$key]){
											$columns['pet']['minerals'][$key]=$colnum;
											$colnum++;
										}
									}
								}

							}
						}
					}

					$x++;

				}

				foreach($data['features'] as $feature){

					$other_features = $feature['properties']['other_features'];
					if($other_features){
						foreach($other_features as $other_feature){

							foreach($other_feature as $key=>$value){
								if($key != "id"){
									if(!$columns['other_features'][$key]){
										$columns['other_features'][$key]=$colnum;
										$colnum++;
									}
								}
							}

						}
					}

					$x++;

				}

				foreach($data['features'] as $feature){

					$samples = $feature['properties']['samples'];
					if($samples){
						foreach($samples as $sample){

							foreach($sample as $key=>$value){
								if($key != "id"){
									if(!$columns['samples'][$key]){
										$columns['samples'][$key]=$colnum;
										$colnum++;
									}
								}
							}

						}
					}

					$x++;

				}

				foreach($data['features'] as $feature){

					$_3ds = $feature['properties']['_3d_structures'];
					if($_3ds){
						foreach($_3ds as $_3d){

							foreach($_3d as $key=>$value){
								if($key != "id"){
									if(!$columns['_3d_structures'][$key]){
										$columns['_3d_structures'][$key]=$colnum;
										$colnum++;
									}
								}
							}

						}
					}

					$x++;

				}

				foreach($data['features'] as $feature){

					$trace = $feature['properties']['trace'];
					if($trace){
						foreach($trace as $key=>$value){

							if(!$columns['trace'][$key]){
								$columns['trace'][$key]=$colnum;
								$colnum++;
							}
						}
					}

					$x++;

				}

				foreach($data['features'] as $feature){

					$tephra = $feature['properties']['tephra'];
					if($tephra){
						foreach($tephra as $t){
							foreach($t as $key=>$value){
								if($key != "id"){
									if(!$columns['tephra'][$key]){
										$columns['tephra'][$key]=$colnum;
										$colnum++;
									}
								}
							}
						}
					}

					$x++;

				}

				foreach($data['features'] as $feature){

					$customfields = $feature['properties']['custom_fields'];
					if($customfields){
						foreach($customfields as $key=>$value){

							if(!$columns['custom_fields'][$key]){
								$columns['custom_fields'][$key]=$colnum;
								$colnum++;
							}
						}
					}

					$x++;

				}

				//also add tags to sheet!
				//need to add a column for each key of each tag and then put an X in the corresponding cells for a given spot

	// 							}
	// 						}
	// 					}
	// 				}
	// 			}

				$tagarray = [];
				if($this->alltags != ""){

					foreach($this->alltags as $currenttag){
						foreach($currenttag as $key=>$value){
							if($key!="id" && $key!="spots"){
								if(!in_array($key, $tagarray)){
									$tagarray[]=$key;
								}
							}
						}

						if(!$columns['tags'][$currenttag->id]){
							$columns['tags'][$currenttag->id]=$colnum;
							$colnum++;
						}

					}

					$tagnum = 0;
					$tagscolumns = [];
					foreach($tagarray as $tag){
						$tagscolumns[$tag] = $tagnum;
						$tagnum++;
					}

				}

				/** PHPExcel */
				include 'PHPExcel.php';

				/** PHPExcel_Writer_Excel2007 */
				include 'PHPExcel/Writer/Excel2007.php';

				$objPHPExcel = new PHPExcel();

				// Set properties
				$objPHPExcel->getProperties()->setCreator("strabospot.org");
				$objPHPExcel->getProperties()->setLastModifiedBy("strabospot.org");
				$objPHPExcel->getProperties()->setTitle("StraboSpot.org Download");
				$objPHPExcel->getProperties()->setSubject("StraboSpot.org Download");
				$objPHPExcel->getProperties()->setDescription("StraboSpot.org Download");

				// Rename sheet
				$objPHPExcel->getActiveSheet()->setTitle('Spots');

				// Add some data
				$objPHPExcel->setActiveSheetIndex(0);

				$objPHPExcel->getActiveSheet()->SetCellValue('A1', "StraboSpot Dataset Download: $datasetname");

				$colnum=0;

				foreach($columns['spot'] as $key=>$value){

					if($key=="geometry"){$key="Real World Coordinates";}
					if($key=="original_geometry"){$key="Pixel Coordinates";}

					$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol(3,$value), $this->fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thisheader=="Age (Ma)"){$thiswidth="12";}
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;
				}

				if($columns['orientation']['tabular']){
					foreach($columns['orientation']['tabular'] as $key=>$value){

						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol(3,$value), "Tabular Orientation ".$this->fix_column_name($key));

						$thiswidth=strlen($key)-1;
						if($thiswidth<10){
							$thiswidth=10;
						}

						$colnum++;

					}
				}

				if($columns['orientation']['planar']){
					foreach($columns['orientation']['planar'] as $key=>$value){

						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol(3,$value), "Planar Orientation ".$this->fix_column_name($key));

						$thiswidth=strlen($key)-1;
						if($thiswidth<10){
							$thiswidth=10;
						}

						$colnum++;

					}
				}

				if($columns['orientation']['linear']){
					foreach($columns['orientation']['linear'] as $key=>$value){

						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol(3,$value), "Linear Orientation ".$this->fix_column_name($key));

						$thiswidth=strlen($key)-1;
						if($thiswidth<10){
							$thiswidth=10;
						}

						$colnum++;

					}
				}

				if($columns['pet']['metamorphic']){
					foreach($columns['pet']['metamorphic'] as $key=>$value){

						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol(3,$value), "Metamorphic ".$this->fix_column_name($key));

						$thiswidth=strlen($key)-1;
						if($thiswidth<10){
							$thiswidth=10;
						}

						$colnum++;

					}
				}

				if($columns['pet']['igneous']){
					foreach($columns['pet']['igneous'] as $key=>$value){

						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol(3,$value), "Igneous ".$this->fix_column_name($key));

						$thiswidth=strlen($key)-1;
						if($thiswidth<10){
							$thiswidth=10;
						}

						$colnum++;

					}
				}

				if($columns['pet']['minerals']){
					foreach($columns['pet']['igneous'] as $key=>$value){

						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol(3,$value), "Mineral ".$this->fix_column_name($key));

						$thiswidth=strlen($key)-1;
						if($thiswidth<10){
							$thiswidth=10;
						}

						$colnum++;

					}
				}

				if($columns['other_features']){
					foreach($columns['other_features'] as $key=>$value){

						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol(3,$value), "Other Feature ".$this->fix_column_name($key));

						$thiswidth=strlen($key)-1;
						if($thisheader=="Age (Ma)"){$thiswidth="12";}
						if($thiswidth<10){
							$thiswidth=10;
						}

						$colnum++;
					}
				}

				if($columns['samples']){
					foreach($columns['samples'] as $key=>$value){

						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol(3,$value), "Sample ".$this->fix_column_name($key));

						$thiswidth=strlen($key)-1;
						if($thisheader=="Age (Ma)"){$thiswidth="12";}
						if($thiswidth<10){
							$thiswidth=10;
						}

						$colnum++;
					}
				}

				if($columns['_3d_structures']){
					foreach($columns['_3d_structures'] as $key=>$value){

						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol(3,$value), "3D Struc ".$this->fix_column_name($key));

						$thiswidth=strlen($key)-1;
						if($thisheader=="Age (Ma)"){$thiswidth="12";}
						if($thiswidth<10){
							$thiswidth=10;
						}

						$colnum++;
					}
				}

				if($columns['trace']){
					foreach($columns['trace'] as $key=>$value){

						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol(3,$value), "Trace ".$this->fix_column_name($key));

						$thiswidth=strlen($key)-1;
						if($thisheader=="Age (Ma)"){$thiswidth="12";}
						if($thiswidth<10){
							$thiswidth=10;
						}

						$colnum++;
					}
				}

				if($columns['tephra']){
					foreach($columns['tephra'] as $key=>$value){

						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol(3,$value), "Tephra ".$this->fix_column_name($key));

						$thiswidth=strlen($key)-1;
						if($thisheader=="Age (Ma)"){$thiswidth="12";}
						if($thiswidth<10){
							$thiswidth=10;
						}

						$colnum++;
					}
				}

				if($columns['custom_fields']){
					foreach($columns['custom_fields'] as $key=>$value){

						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol(3,$value), $this->fix_column_name($key));

						$thiswidth=strlen($key)-1;
						if($thisheader=="Age (Ma)"){$thiswidth="12";}
						if($thiswidth<10){
							$thiswidth=10;
						}

						$colnum++;
					}
				}

				if($columns['tags']){
					foreach($columns['tags'] as $key=>$value){

						$taglabel = "";
						foreach($this->alltags as $tag){

							if($key == $tag->id){
								$taglabel = "Tag:".$tag->name;
							}
						}

						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol(3,$value), $taglabel);

						$colnum++;
					}
				}

				//write data
				$rownum=4;
				foreach($data['features'] as $feature){

					//use geoPHP to get WKT
					$mygeojson=$feature['geometry'];
					$mygeojson=trim(json_encode($mygeojson));

					try {
						$mywkt=geoPHP::load($mygeojson,"json");
						$wkt = $mywkt->out('wkt');
						$geometry=$wkt;
					} catch (Exception $e) {
						$geometry="";
					}

					//use geoPHP to get WKT
					$mygeojson=$feature['original_geometry'];
					$mygeojson=trim(json_encode($mygeojson));

					try {
						$mywkt=geoPHP::load($mygeojson,"json");
						$wkt = $mywkt->out('wkt');
						$original_geometry=$wkt;
					} catch (Exception $e) {
						$original_geometry="";
					}

					$spotid = $feature['properties']['id'];
					$spotname = (string)$feature['properties']['name'];
					$spotdate = $feature['properties']['date'];
					$spotself = $feature['properties']['self'];
					$spotnotes = $feature['properties']['notes'];
					$altitude = $feature['properties']['altitude'];

					$latitude = "";
					$longitude = "";

					if(strtolower(substr($geometry,0,5))=="point"){
						$lonlatgeom=$geometry;
						$lonlatgeom=strtolower($lonlatgeom);
						$lonlatgeom=str_replace("point ","",$lonlatgeom);
						$lonlatgeom=str_replace("(","",$lonlatgeom);
						$lonlatgeom=str_replace(")","",$lonlatgeom);
						$lonlatgeom=explode(" ",$lonlatgeom);
						$longitude=$lonlatgeom[0];
						$latitude=$lonlatgeom[1];
					}

					unset($arrayMultipleData);
					$maxcount = 0;
					$linear_orientation_count = 0;
					$planar_orientation_count = 0;
					$tabular_orientation_count = 0;
					$_3d_structure_count = 0;
					$other_features_count = 0;
					$samples_count = 0;
					$traces_count = 0;
					$tephras_count = 0;
					$metamorphics_count = 0;
					$igneouses_count = 0;
					$minerals_count = 0;
					$tags_count = 0;

					$orientationdatas = $feature['properties']['orientation_data'];

					if($orientationdatas){
						foreach($orientationdatas as $orientationdata){

							if($orientationdata->type=="linear_orientation"){
								foreach($orientationdata as $key=>$value){
									if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
										if($key=="feature_type"){$key="linear_feature_type";}
										$arrayMultipleData['linear'][$linear_orientation_count][$key]=$value;
									}
								}
								$linear_orientation_count++;
							}

							if($orientationdata->type=="planar_orientation"){
								foreach($orientationdata as $key=>$value){
									if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
										if($key=="feature_type"){$key="planar_feature_type";}
										$arrayMultipleData['planar'][$planar_orientation_count][$key]=$value;
									}
								}
								$planar_orientation_count++;
							}

							if($orientationdata->type=="tabular_orientation"){
								foreach($orientationdata as $key=>$value){
									if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
										if($key=="feature_type"){$key="tabular_feature_type";}
										$arrayMultipleData['tabular'][$tabular_orientation_count][$key]=$value;
									}
								}
								$tabular_orientation_count++;
							}

							$associatedorientations = $orientationdata->associated_orientation;
							if($associatedorientations){
								foreach($associatedorientations as $associatedorientation){
									if($associatedorientation->type=="planar_orientation"){
										foreach($associatedorientation as $key=>$value){
											if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
												if($key=="feature_type"){$key="planar_feature_type";}
												$arrayMultipleData['planar'][$planar_orientation_count][$key]=$value;
											}
										}
										$planar_orientation_count++;
									}

									if($associatedorientation->type=="linear_orientation"){
										foreach($associatedorientation as $key=>$value){
											if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
												if($key=="feature_type"){$key="linear_feature_type";}
												$arrayMultipleData['linear'][$linear_orientation_count][$key]=$value;
											}
										}
										$linear_orientation_count++;
									}

									if($associatedorientation->type=="tabular_orientation"){
										foreach($associatedorientation as $key=>$value){
											if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
												if($key=="feature_type"){$key="tabular_feature_type";}
												$arrayMultipleData['tabular'][$tabular_orientation_count][$key]=$value;
											}
										}
										$tabular_orientation_count++;
									}
								}
							}
						}
					}

					$metamorphics = $feature['properties']['pet']->metamorphic;
					if($metamorphics){
						foreach($metamorphics as $metamorphic){

							foreach($metamorphic as $key=>$value){
								if($key != "id" && $key != "" ){
									$arrayMultipleData['metamorphic'][$metamorphics_count][$key]=$value;
								}
							}
							$metamorphics_count++;
						}
					}

					$igneouses = $feature['properties']['pet']->igneous;
					if($igneouses){
						foreach($igneouses as $igneous){

							foreach($igneous as $key=>$value){
								if($key != "id" && $key != "" ){
									$arrayMultipleData['igneous'][$igneouses_count][$key]=$value;
								}
							}
							$igneouses_count++;
						}
					}

					$minerals = $feature['properties']['pet']->minerals;
					if($minerals){
						foreach($minerals as $mineral){

							foreach($mineral as $key=>$value){
								if($key != "id" && $key != "" ){
									$arrayMultipleData['mineral'][$minerals_count][$key]=$value;
								}
							}
							$minerals_count++;
						}
					}

					$other_features = $feature['properties']['other_features'];
					if($other_features){
						foreach($other_features as $other_feature){
							foreach($other_feature as $key=>$value){
								if($key != "id"){
									$arrayMultipleData['other_features'][$other_features_count][$key]=$value;
								}
							}
							$other_features_count++;
						}
					}

					$samples = $feature['properties']['samples'];
					if($samples){
						foreach($samples as $sample){
							foreach($sample as $key=>$value){
								if($key != "id"){
									$arrayMultipleData['samples'][$samples_count][$key]=$value;
								}
							}
							$samples_count++;
						}
					}

					$_3ds = $feature['properties']['_3d_structures'];
					if($_3ds){
						foreach($_3ds as $_3d){
							foreach($_3d as $key=>$value){
								if($key != "id"){
									$arrayMultipleData['_3d_structures'][$_3d_structure_count][$key]=$value;
								}
							}
							$_3d_structure_count++;
						}
					}

					$trace = $feature['properties']['trace'];
					if($trace){
						foreach($trace as $key=>$value){
							$arrayMultipleData['trace'][$traces_count][$key]=$value;
						}
						$traces_count++;
					}

					$tephras = $feature['properties']['tephra'];
					if($tephras){
						foreach($tephras as $tephra){
							foreach($tephra as $key=>$value){
								if($key != "id"){
									$arrayMultipleData['tephra'][$tephras_count][$key]=$value;
								}
							}
							$tephras_count++;
						}
					}

					if($linear_orientation_count > $maxcount) $maxcount = $linear_orientation_count;
					if($planar_orientation_count > $maxcount) $maxcount = $planar_orientation_count;
					if($tabular_orientation_count > $maxcount) $maxcount = $tabular_orientation_count;
					if($other_features_count > $maxcount) $maxcount = $other_features_count;
					if($samples_count > $maxcount) $maxcount = $samples_count;
					if($_3d_structure_count > $maxcount) $maxcount = $_3d_structure_count;
					if($traces_count > $maxcount) $maxcount = $traces_count;
					if($tephras_count > $maxcount) $maxcount = $tephras_count;
					if($metamorphics_count > $maxcount) $maxcount = $metamorphics_count;
					if($igneouses_count > $maxcount) $maxcount = $igneouses_count;
					if($minerals_count > $maxcount) $maxcount = $minerals_count;

					if($maxcount==0) $maxcount = 1;

					for($spotrepeat = 0; $spotrepeat < $maxcount; $spotrepeat ++){

						//write out each row here
						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,0), $spotname);
						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,1), $spotdate);
						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,2), $spotself);
						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,3),$spotnotes);
						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,4),$geometry);

						if($geometry!=$original_geometry){
							$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,5),$original_geometry);
						}

						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,6),$latitude);
						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,7),$longitude);
						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,8),$altitude);

						foreach($columns as $key=>$value){
							if($key!="spot"){
								if($key == "orientation" || $key == "pet"){
									foreach($value as $otype=>$ors){
										foreach($ors as $orkey=>$orval){
											$rawVal = $arrayMultipleData[$otype][$spotrepeat][$orkey];
											if(is_array($rawVal)){
												$showVal = implode(", ", $rawVal);
											}else{
												$showVal = $rawVal;
											}

											$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,$orval),$showVal);
										}
									}
								}else{
									foreach($value as $newkey=>$column){

										$rawVal = $arrayMultipleData[$key][$spotrepeat][$newkey];
										if(is_array($rawVal)){
											$showVal = implode(", ", $rawVal);
										}else{
											$showVal = $rawVal;
										}

										$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,$column),$showVal);
									}
								}
							}
						}

						foreach($columns as $key=>$value){
							if($key=="tags"){
								//determine if the spot belongs in the tag and mark X if it is
								foreach($value as $tagid=>$colnum){
									foreach($this->alltags as $tag){
										if($tagid == $tag->id){

											if(in_array($spotid, $tag->spots)){
												$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,$colnum),"X");
											}

											if($tag->features != null){
												foreach($tag->features as $snum=>$other){
													if($snum == $spotid){
														$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($rownum,$colnum),"X");
													}
												}
											}
										}
									}
								}
							}
						}

						$rownum++;

					}

				}//end foreach feature

				//Add tags if necessary
				// Rename sheet
				if($this->alltags != ""){
					$tagsWorkSheet = $objPHPExcel->createSheet(1); //Setting index when creating

					//Write cells
					$tagsWorkSheet->SetCellValue('A1', 'Tags:');

					// Rename sheet
					$tagsWorkSheet->setTitle("Tag Details");

					$rownum = 2;
					$colnum = 0;
					foreach($tagscolumns as $key=>$value){
						$tagsWorkSheet->SetCellValue($this->rowcol($rownum,$value),$this->fix_column_name($key));
					}

					$rownum = 3;
					foreach($this->alltags as $tag){
						foreach($tag as $key=>$value){
							if($key!="id" && $key!="spots" && $key!="features"){
								if(is_array($value)){
									$showvalue = implode(",", $value);
								}else{
									$showvalue = $value;
								}

								$tagsWorkSheet->SetCellValue($this->rowcol($rownum,$tagscolumns[$key]),$showvalue);
							}
						}
						$rownum++;
					}

				}

			}

			// Save Excel 2007 file
			$objWriter = new PHPExcel_Writer_Excel2007($objPHPExcel);

			// We'll be outputting an excel file
			header('Content-type: application/vnd.ms-excel');

			$filedate = date("m_d_Y");

			// It will be called file.xls
			header('Content-Disposition: attachment; filename="'."StraboSpot_Output_".$filedate.".xlsx".'"');

			// Write file to the browser
			$objWriter->save('php://output');

		}

	public function bkupexpandedShapefileOut_20250516(){

		if($this->get['dsids']!=""){

			$dsids=$this->get['dsids'];
			$this->alltags = $this->strabo->getTagsFromDatasetIds($dsids);

			$data = $this->strabo->getDatasetSpotsSearch(null,$this->get);

			if(count($data)==0){
			echo "no data found for dataset $id";exit();
			}

			$columns['spot']['name']=0;
			$columns['spot']['date']=1;
			$columns['spot']['self']=2;
			$columns['spot']['notes']=3;
			$columns['spot']['geometry']=4;
			$columns['spot']['images']=5;
			$columns['spot']['Latitude']=6;
			$columns['spot']['Longitude']=7;
			$columns['spot']['Altitude(m)']=8;

			$colnum=9;

			$x=0;

			$arrayMultipleData = [];

			foreach($data['features'] as $feature){

				if($feature['properties']['strat_section_id'] == "" && $feature['properties']['image_basemap'] == ""){

					$orientationdatas = $feature['properties']['orientation_data'];
					if($orientationdatas){
						foreach($orientationdatas as $orientationdata){

							if($orientationdata->type=="linear_orientation"){
								foreach($orientationdata as $key=>$value){
									if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
										if($key=="feature_type"){$key="linear_feature_type";}
										if(!$columns['orientation']['linear'][$key]){
											$columns['orientation']['linear'][$key]=$colnum;
											$colnum++;
										}
									}
								}
							}

							if($orientationdata->type=="planar_orientation"){
								foreach($orientationdata as $key=>$value){
									if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
										if($key=="feature_type"){$key="planar_feature_type";}
										if(!$columns['orientation']['planar'][$key]){
											$columns['orientation']['planar'][$key]=$colnum;
											$colnum++;
										}
									}
								}
							}

							if($orientationdata->type=="tabular_orientation"){
								foreach($orientationdata as $key=>$value){
									if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
										if($key=="feature_type"){$key="tabular_feature_type";}
										if(!$columns['orientation']['tabular'][$key]){
											$columns['orientation']['tabular'][$key]=$colnum;
											$colnum++;
										}
									}
								}
							}

							$associatedorientations = $orientationdata->associated_orientation;
							if($associatedorientations){
								foreach($associatedorientations as $associatedorientation){
									if($associatedorientation->type=="planar_orientation"){
										foreach($associatedorientation as $key=>$value){
											if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
												if($key=="feature_type"){$key="planar_feature_type";}
												if(!$columns['orientation']['planar'][$key]){
													$columns['orientation']['planar'][$key]=$colnum;
													$colnum++;
												}
											}
										}

									}

									if($associatedorientation->type=="linear_orientation"){
										foreach($associatedorientation as $key=>$value){
											if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
												if($key=="feature_type"){$key="linear_feature_type";}
												if(!$columns['orientation']['linear'][$key]){
													$columns['orientation']['linear'][$key]=$colnum;
													$colnum++;
												}
											}
										}
									}

									if($associatedorientation->type=="tabular_orientation"){
										foreach($associatedorientation as $key=>$value){
											if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
												if($key=="feature_type"){$key="tabular_feature_type";}
												if(!$columns['orientation']['tabular'][$key]){
													$columns['orientation']['tabular'][$key]=$colnum;
													$colnum++;
												}
											}
										}
									}
								}
							}
						}
					}

					$x++;

				}

			}

			foreach($data['features'] as $feature){

				$pet = $feature['properties']['pet'];
				if($pet){
					if($pet->metamorphic){

						foreach($pet->metamorphic as $met){

							foreach($met as $key=>$value){
								if($key != "id"){
									if(!$columns['pet']['metamorphic'][$key]){
										$columns['pet']['metamorphic'][$key]=$colnum;
										$colnum++;
									}
								}
							}

						}
					}
				}

				$x++;

			}

			foreach($data['features'] as $feature){

				$pet = $feature['properties']['pet'];
				if($pet){
					if($pet->igneous){

						foreach($pet->igneous as $ig){

							foreach($ig as $key=>$value){
								if($key != "id"){
									if(!$columns['pet']['igneous'][$key]){
										$columns['pet']['igneous'][$key]=$colnum;
										$colnum++;
									}
								}
							}

						}
					}
				}

				$x++;

			}

			foreach($data['features'] as $feature){

				$pet = $feature['properties']['pet'];
				if($pet){
					if($pet->minerals){

						foreach($pet->minerals as $min){

							foreach($min as $key=>$value){
								if($key != "id"){
									if(!$columns['pet']['minerals'][$key]){
										$columns['pet']['minerals'][$key]=$colnum;
										$colnum++;
									}
								}
							}

						}
					}
				}

				$x++;

			}

			foreach($data['features'] as $feature){

				if($feature['properties']['strat_section_id'] == "" && $feature['properties']['image_basemap'] == ""){

					$other_features = $feature['properties']['other_features'];
					if($other_features){
						foreach($other_features as $other_feature){

							foreach($other_feature as $key=>$value){
								if($key != "id"){
									if(!$columns['other_features'][$key]){
										$columns['other_features'][$key]=$colnum;
										$colnum++;
									}
								}
							}

						}
					}

					$x++;

				}

			}

			foreach($data['features'] as $feature){

				if($feature['properties']['strat_section_id'] == "" && $feature['properties']['image_basemap'] == ""){

					$samples = $feature['properties']['samples'];
					if($samples){
						foreach($samples as $sample){

							foreach($sample as $key=>$value){
								if($key != "id"){
									if(!$columns['samples'][$key]){
										$columns['samples'][$key]=$colnum;
										$colnum++;
									}
								}
							}

						}
					}

					$x++;

				}

			}

			foreach($data['features'] as $feature){

				if($feature['properties']['strat_section_id'] == "" && $feature['properties']['image_basemap'] == ""){

					$trace = $feature['properties']['trace'];
					if($trace){
						foreach($trace as $key=>$value){

							if(!$columns['trace'][$key]){
								$columns['trace'][$key]=$colnum;
								$colnum++;
							}
						}
					}

					$x++;

				}

			}

			foreach($data['features'] as $feature){

				if($feature['properties']['strat_section_id'] == "" && $feature['properties']['image_basemap'] == ""){

					$tephra = $feature['properties']['tephra'];
					if($tephra){
						foreach($tephra as $t){
							foreach($t as $key=>$value){
								if($key != "id"){
									if(!$columns['tephra'][$key]){
										$columns['tephra'][$key]=$colnum;
										$colnum++;
									}
								}
							}
						}
					}

					$x++;

				}

			}

			foreach($data['features'] as $feature){

				if($feature['properties']['strat_section_id'] == "" && $feature['properties']['image_basemap'] == ""){

					$customfields = $feature['properties']['custom_fields'];
					if($customfields){
						foreach($customfields as $key=>$value){

							if(!$columns['custom_fields'][$key]){
								$columns['custom_fields'][$key]=$colnum;
								$colnum++;
							}
						}
					}

					$x++;

				}

			}

			$colnum=0;

			foreach($columns['spot'] as $key=>$value){

				$this->addHolding(0, $value, $this->shapefile_fix_column_name($key));

				$thiswidth=strlen($key)-1;
				if($thisheader=="Age (Ma)"){$thiswidth="12";}
				if($thiswidth<10){
					$thiswidth=10;
				}

				$colnum++;
			}

			if($columns['orientation']['tabular']){
				foreach($columns['orientation']['tabular'] as $key=>$value){

					$this->addHolding(0, $value, "to_".$this->shapefile_fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;

				}
			}

			if($columns['orientation']['planar']){
				foreach($columns['orientation']['planar'] as $key=>$value){

					$this->addHolding(0, $value, "po_".$this->shapefile_fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;

				}
			}

			if($columns['orientation']['linear']){
				foreach($columns['orientation']['linear'] as $key=>$value){

					$this->addHolding(0, $value, "lo_".$this->shapefile_fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;

				}
			}

			if($columns['pet']['metamorphic']){
				foreach($columns['pet']['metamorphic'] as $key=>$value){

					$this->addHolding(0, $value, "met_".$this->shapefile_fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;

				}
			}

			if($columns['pet']['igneous']){
				foreach($columns['pet']['igneous'] as $key=>$value){

					$this->addHolding(0, $value, "ig_".$this->shapefile_fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;

				}
			}

			if($columns['pet']['minerals']){
				foreach($columns['pet']['igneous'] as $key=>$value){

					$this->addHolding(0, $value, "min_".$this->shapefile_fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;

				}
			}

			if($columns['other_features']){
				foreach($columns['other_features'] as $key=>$value){

					$this->addHolding(0, $value, "other_".$this->shapefile_fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thisheader=="Age (Ma)"){$thiswidth="12";}
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;
				}
			}

			if($columns['samples']){
				foreach($columns['samples'] as $key=>$value){

					$this->addHolding(0, $value, "samp_".$this->shapefile_fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thisheader=="Age (Ma)"){$thiswidth="12";}
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;
				}
			}

			if($columns['trace']){
				foreach($columns['trace'] as $key=>$value){

					$this->addHolding(0, $value, "trace_".$this->shapefile_fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thisheader=="Age (Ma)"){$thiswidth="12";}
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;
				}
			}

			if($columns['tephra']){
				foreach($columns['tephra'] as $key=>$value){

					$this->addHolding(0, $value, "tephra_".$this->shapefile_fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thisheader=="Age (Ma)"){$thiswidth="12";}
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;
				}
			}

			if($columns['custom_fields']){
				foreach($columns['custom_fields'] as $key=>$value){

					$this->addHolding(0, $value, $this->shapefile_fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thisheader=="Age (Ma)"){$thiswidth="12";}
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;
				}
			}

			if($columns['tags']){
				foreach($columns['tags'] as $key=>$value){

					$taglabel = "";
					foreach($this->alltags as $tag){

						if($key == $tag->id){
							$taglabel = "Tag:".$tag->name;
						}
					}

					$this->addHolding(0, $value, $taglabel);

					$colnum++;
				}
			}

			//write data
			$rownum=1;
			foreach($data['features'] as $feature){

				if($feature['properties']['strat_section_id'] == "" && $feature['properties']['image_basemap'] == ""){

					//use geoPHP to get WKT
					$mygeojson=$feature['geometry'];
					$mygeojson=trim(json_encode($mygeojson));

					try {
						$mywkt=geoPHP::load($mygeojson,"json");
						$wkt = $mywkt->out('wkt');
						$geometry=$wkt;
					} catch (Exception $e) {
						$geometry="";
					}

					//use geoPHP to get WKT
					$mygeojson=$feature['original_geometry'];
					$mygeojson=trim(json_encode($mygeojson));

					try {
						$mywkt=geoPHP::load($mygeojson,"json");
						$wkt = $mywkt->out('wkt');
						$original_geometry=$wkt;
					} catch (Exception $e) {
						$original_geometry="";
					}

					$spotid = $feature['properties']['id'];
					$spotname = (string)$feature['properties']['name'];
					$spotdate = $feature['properties']['date'];
					$spotself = $feature['properties']['self'];
					$spotnotes = $feature['properties']['notes'];
					$altitude = $feature['properties']['altitude'];

					//build images here
					//https://strabospot.org/pi/16907249300069;https://strabospot.org/pi/16874590296725
					$foundImages = [];
					$imagesString = "";
					if(count($feature['properties']['images']) > 0){
						foreach($feature['properties']['images'] as $i){
							$foundImages[] = "https://strabospot.org/pi/".$i['id'];
						}
						$imagesString = implode(";", $foundImages);
					}

					$latitude = "";
					$longitude = "";

					if(strtolower(substr($geometry,0,5))=="point"){
						$lonlatgeom=$geometry;
						$lonlatgeom=strtolower($lonlatgeom);
						$lonlatgeom=str_replace("point ","",$lonlatgeom);
						$lonlatgeom=str_replace("(","",$lonlatgeom);
						$lonlatgeom=str_replace(")","",$lonlatgeom);
						$lonlatgeom=explode(" ",$lonlatgeom);
						$longitude=$lonlatgeom[0];
						$latitude=$lonlatgeom[1];
					}

					unset($arrayMultipleData);
					$maxcount = 0;
					$linear_orientation_count = 0;
					$planar_orientation_count = 0;
					$tabular_orientation_count = 0;
					$_3d_structure_count = 0;
					$other_features_count = 0;
					$samples_count = 0;
					$traces_count = 0;
					$tephras_count = 0;
					$metamorphics_count = 0;
					$igneouses_count = 0;
					$minerals_count = 0;
					$tags_count = 0;

					$orientationdatas = $feature['properties']['orientation_data'];
					if($orientationdatas){
						foreach($orientationdatas as $orientationdata){

							if($orientationdata->type=="linear_orientation"){
								foreach($orientationdata as $key=>$value){
									if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
										if($key=="feature_type"){$key="linear_feature_type";}
										$arrayMultipleData['linear'][$linear_orientation_count][$key]=$value;
									}
								}
								$linear_orientation_count++;
							}

							if($orientationdata->type=="planar_orientation"){
								foreach($orientationdata as $key=>$value){
									if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
										if($key=="feature_type"){$key="planar_feature_type";}
										$arrayMultipleData['planar'][$planar_orientation_count][$key]=$value;
									}
								}
								$planar_orientation_count++;
							}

							if($orientationdata->type=="tabular_orientation"){
								foreach($orientationdata as $key=>$value){
									if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
										if($key=="feature_type"){$key="tabular_feature_type";}
										$arrayMultipleData['tabular'][$tabular_orientation_count][$key]=$value;
									}
								}
								$tabular_orientation_count++;
							}

							$associatedorientations = $orientationdata->associated_orientation;
							if($associatedorientations){
								foreach($associatedorientations as $associatedorientation){
									if($associatedorientation->type=="planar_orientation"){
										foreach($associatedorientation as $key=>$value){
											if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
												if($key=="feature_type"){$key="planar_feature_type";}
												$arrayMultipleData['planar'][$planar_orientation_count][$key]=$value;
											}
										}
										$planar_orientation_count++;
									}

									if($associatedorientation->type=="linear_orientation"){
										foreach($associatedorientation as $key=>$value){
											if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
												if($key=="feature_type"){$key="linear_feature_type";}
												$arrayMultipleData['linear'][$linear_orientation_count][$key]=$value;
											}
										}
										$linear_orientation_count++;
									}

									if($associatedorientation->type=="tabular_orientation"){
										foreach($associatedorientation as $key=>$value){
											if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
												if($key=="feature_type"){$key="tabular_feature_type";}
												$arrayMultipleData['tabular'][$tabular_orientation_count][$key]=$value;
											}
										}
										$tabular_orientation_count++;
									}
								}
							}
						}
					}

					$metamorphics = $feature['properties']['pet']->metamorphic;
					if($metamorphics){
						foreach($metamorphics as $metamorphic){

							foreach($metamorphic as $key=>$value){
								if($key != "id" && $key != "" ){
									$arrayMultipleData['metamorphic'][$metamorphics_count][$key]=$value;
								}
							}
							$metamorphics_count++;
						}
					}

					$igneouses = $feature['properties']['pet']->igneous;
					if($igneouses){
						foreach($igneouses as $igneous){

							foreach($igneous as $key=>$value){
								if($key != "id" && $key != "" ){
									$arrayMultipleData['igneous'][$igneouses_count][$key]=$value;
								}
							}
							$igneouses_count++;
						}
					}

					$minerals = $feature['properties']['pet']->minerals;
					if($minerals){
						foreach($minerals as $mineral){

							foreach($mineral as $key=>$value){
								if($key != "id" && $key != "" ){
									$arrayMultipleData['mineral'][$minerals_count][$key]=$value;
								}
							}
							$minerals_count++;
						}
					}

					$other_features = $feature['properties']['other_features'];
					if($other_features){
						foreach($other_features as $other_feature){
							foreach($other_feature as $key=>$value){
								if($key != "id"){
									$arrayMultipleData['other_features'][$other_features_count][$key]=$value;
								}
							}
							$other_features_count++;
						}
					}

					$samples = $feature['properties']['samples'];
					if($samples){
						foreach($samples as $sample){
							foreach($sample as $key=>$value){
								if($key != "id"){
									$arrayMultipleData['samples'][$samples_count][$key]=$value;
								}
							}
							$samples_count++;
						}
					}

					$trace = $feature['properties']['trace'];
					if($trace){
						foreach($trace as $key=>$value){
							$arrayMultipleData['trace'][$traces_count][$key]=$value;
						}
						$traces_count++;
					}

					$tephras = $feature['properties']['tephra'];
					if($tephras){
						foreach($tephras as $tephra){
							foreach($tephra as $key=>$value){
								if($key != "id"){
									$arrayMultipleData['tephra'][$tephras_count][$key]=$value;
								}
							}
							$tephras_count++;
						}
					}

					if($linear_orientation_count > $maxcount) $maxcount = $linear_orientation_count;
					if($planar_orientation_count > $maxcount) $maxcount = $planar_orientation_count;
					if($tabular_orientation_count > $maxcount) $maxcount = $tabular_orientation_count;
					if($other_features_count > $maxcount) $maxcount = $other_features_count;
					if($samples_count > $maxcount) $maxcount = $samples_count;
					if($traces_count > $maxcount) $maxcount = $traces_count;
					if($tephras_count > $maxcount) $maxcount = $tephras_count;

					if($maxcount==0) $maxcount = 1;

					for($spotrepeat = 0; $spotrepeat < $maxcount; $spotrepeat ++){

						//write out each row here
						$this->addHolding($rownum,0, $spotname);
						$this->addHolding($rownum,1, $spotdate);
						$this->addHolding($rownum,2, $spotself);
						$this->addHolding($rownum,3,$spotnotes);
						$this->addHolding($rownum,4,$geometry);

						$this->addHolding($rownum,5,$imagesString);

						//JMA 20230730
						//}

						$this->addHolding($rownum,6,$latitude);
						$this->addHolding($rownum,7,$longitude);
						$this->addHolding($rownum,8,$altitude);

						foreach($columns as $key=>$value){
							if($key!="spot"){
								if($key == "orientation" || $key == "pet"){
									foreach($value as $otype=>$ors){
										foreach($ors as $orkey=>$orval){

											$rawVal = $arrayMultipleData[$otype][$spotrepeat][$orkey];
											if(is_array($rawVal)){
												$showVal = implode(", ", $rawVal);
											}else{
												$showVal = $rawVal;
											}

											$this->addHolding($rownum,$orval,$showVal);
										}
									}
								}else{
									foreach($value as $newkey=>$column){
										$this->addHolding($rownum,$column,$arrayMultipleData[$key][$spotrepeat][$newkey]);
									}
								}
							}
						}

						foreach($columns as $key=>$value){
							if($key=="tags"){
								//determine if the spot belongs in the tag and mark X if it is
								foreach($value as $tagid=>$colnum){
									foreach($this->alltags as $tag){
										if($tagid == $tag->id){

											if(in_array($spotid, $tag->spots)){
												$this->addHolding($rownum,$colnum,"X");
											}

											if($tag->features != null){
												foreach($tag->features as $snum=>$other){
													if($snum == $spotid){
														$this->addHolding($rownum,$colnum,"X");
													}
												}
											}

										}
									}
								}
							}
						}

						$rownum++;
					}

				}//end foreach feature

			}

		}

		$pointjson = "";
		$linejson = "";
		$polygonjson = "";

		$fields = $this->holdings[0];

		//Point
		$features = [];
		for($x=1; $x<count($this->holdings); $x++){
			$row = $this->holdings[$x];
			$geometry = $row[4];
			if(substr($geometry,0,5)=="POINT" || substr($geometry,0,10)=="MULTIPOINT"){
				$thisFeature = new stdClass();
				$thisFeature->type = "Feature";
				$mywkt=geoPHP::load($geometry,"wkt");
				$thisgeometry = json_decode($mywkt->out('json'));
				$thisFeature->geometry = $thisgeometry;
				$properties = [];
				for($col = 0; $col < count($fields); $col++){
					eval("\$properties['".$fields[$col]."'] = \$row[$col];");
				}
				$thisFeature->properties = $properties;
				$features[] = $thisFeature;
			}
		}
		if(count($features) > 0){
			$featureCollection = new stdClass();
			$featureCollection->type = "FeatureCollection";
			$featureCollection->features = $features;
			$pointjson = json_encode($featureCollection, JSON_PRETTY_PRINT);
		}

		//Line
		$features = [];
		for($x=1; $x<count($this->holdings); $x++){
			$row = $this->holdings[$x];
			$geometry = $row[4];
			if(substr($geometry,0,10)=="LINESTRING"){
				$thisFeature = new stdClass();
				$thisFeature->type = "Feature";
				$mywkt=geoPHP::load($geometry,"wkt");
				$thisgeometry = json_decode($mywkt->out('json'));
				$thisFeature->geometry = $thisgeometry;
				$properties = [];
				for($col = 0; $col < count($fields); $col++){
					eval("\$properties['".$fields[$col]."'] = \$row[$col];");
				}
				$thisFeature->properties = $properties;
				$features[] = $thisFeature;
			}
		}

		if(count($features) > 0){
			$featureCollection = new stdClass();
			$featureCollection->type = "FeatureCollection";
			$featureCollection->features = $features;
			$linejson = json_encode($featureCollection, JSON_PRETTY_PRINT);
		}

		//Polygon
		$features = [];
		for($x=1; $x<count($this->holdings); $x++){
			$row = $this->holdings[$x];
			$geometry = $row[4];
			if(substr($geometry,0,7)=="POLYGON"){
				$thisFeature = new stdClass();
				$thisFeature->type = "Feature";
				$mywkt=geoPHP::load($geometry,"wkt");
				$thisgeometry = json_decode($mywkt->out('json'));
				$thisFeature->geometry = $thisgeometry;
				$properties = [];
				for($col = 0; $col < count($fields); $col++){
					eval("\$properties['".$fields[$col]."'] = \$row[$col];");
				}
				$thisFeature->properties = $properties;
				$features[] = $thisFeature;
			}
		}
		if(count($features) > 0){
			$featureCollection = new stdClass();
			$featureCollection->type = "FeatureCollection";
			$featureCollection->features = $features;
			$polygonjson = json_encode($featureCollection, JSON_PRETTY_PRINT);
		}

		if($polygonjson!="" || $pointjson!="" || $linejson!=""){

			$randnum=$this->strabo->db->get_var("select nextval('file_seq')");

			//make directory in ogrtemp to hold data
			mkdir("ogrtemp/$randnum");
			mkdir("ogrtemp/$randnum/data");

			if($polygonjson!=""){
				file_put_contents("ogrtemp/$randnum/polygon.json", $polygonjson);
				exec("ogr2ogr -nlt POLYGON -f \"ESRI Shapefile\" -skipfailures ogrtemp/$randnum/data/polygons.shp ogrtemp/$randnum/polygon.json 2>&1",$results);
			}

			if($linejson!=""){
				file_put_contents("ogrtemp/$randnum/line.json", $linejson);
				exec("ogr2ogr -nlt LINESTRING -f \"ESRI Shapefile\" -skipfailures ogrtemp/$randnum/data/lines.shp ogrtemp/$randnum/line.json 2>&1",$results);
				unlink("ogrtemp/$randnum/line.json");
			}

			if($pointjson!=""){
				file_put_contents("ogrtemp/$randnum/point.json", $pointjson);
				exec("ogr2ogr -nlt POINT -f \"ESRI Shapefile\" -skipfailures ogrtemp/$randnum/data/points.shp ogrtemp/$randnum/point.json 2>&1",$results);
				unlink("ogrtemp/$randnum/point.json");
			}

			//create zip file
			exec("zip -j ogrtemp/$randnum/strabo$randnum.zip ogrtemp/$randnum/data/* 2>&1",$results);

			//force download of file
			header("Content-Type: application/zip");
			header("Content-Disposition: attachment; filename=search_download.zip");
			header("Content-Length: " . filesize("ogrtemp/$randnum/strabo$randnum.zip"));

			readfile("ogrtemp/$randnum/strabo$randnum.zip");

			//remove temp directory
			if($randnum!=""){
			}

		}else{
			echo "No data found for this dataset.";
		}

	}

	public function bkupexpandedShapefileOut_broken_fix_20250516(){

		if($this->get['dsids']!=""){

			$dsids=$this->get['dsids'];
			$this->alltags = $this->strabo->getTagsFromDatasetIds($dsids);

			$data = $this->strabo->getDatasetSpotsSearch(null,$this->get);

			if(count($data)==0){
			echo "no data found for dataset $id";exit();
			}

			$columns['spot']['name']=0;
			$columns['spot']['date']=1;
			$columns['spot']['self']=2;
			$columns['spot']['notes']=3;
			$columns['spot']['geometry']=4;
			$columns['spot']['images']=5;
			$columns['spot']['Latitude']=6;
			$columns['spot']['Longitude']=7;
			$columns['spot']['Altitude(m)']=8;
			$colnum=9;

			$tagnum = 1;
			foreach($this->alltags as $tag){
				if($tag->type == "geologic_unit"){
					$columns['spot']['lith'.$tagnum] = $colnum;
					$tagnum++;
					$colnum++;
				}
			}

			$x=0;

			$arrayMultipleData = [];

			foreach($data['features'] as $feature){

				if($feature['properties']['strat_section_id'] == "" && $feature['properties']['image_basemap'] == ""){

					$orientationdatas = $feature['properties']['orientation_data'];
					if($orientationdatas){
						foreach($orientationdatas as $orientationdata){

							if($orientationdata->type=="linear_orientation"){
								foreach($orientationdata as $key=>$value){
									if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
										if($key=="feature_type"){$key="linear_feature_type";}
										if(!$columns['orientation']['linear'][$key]){
											$columns['orientation']['linear'][$key]=$colnum;
											$colnum++;
										}
									}
								}
							}

							if($orientationdata->type=="planar_orientation"){
								foreach($orientationdata as $key=>$value){
									if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
										if($key=="feature_type"){$key="planar_feature_type";}
										if(!$columns['orientation']['planar'][$key]){
											$columns['orientation']['planar'][$key]=$colnum;
											$colnum++;
										}
									}
								}
							}

							if($orientationdata->type=="tabular_orientation"){
								foreach($orientationdata as $key=>$value){
									if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
										if($key=="feature_type"){$key="tabular_feature_type";}
										if(!$columns['orientation']['tabular'][$key]){
											$columns['orientation']['tabular'][$key]=$colnum;
											$colnum++;
										}
									}
								}
							}

							$associatedorientations = $orientationdata->associated_orientation;
							if($associatedorientations){
								foreach($associatedorientations as $associatedorientation){
									if($associatedorientation->type=="planar_orientation"){
										foreach($associatedorientation as $key=>$value){
											if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
												if($key=="feature_type"){$key="planar_feature_type";}
												if(!$columns['orientation']['planar'][$key]){
													$columns['orientation']['planar'][$key]=$colnum;
													$colnum++;
												}
											}
										}

									}

									if($associatedorientation->type=="linear_orientation"){
										foreach($associatedorientation as $key=>$value){
											if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
												if($key=="feature_type"){$key="linear_feature_type";}
												if(!$columns['orientation']['linear'][$key]){
													$columns['orientation']['linear'][$key]=$colnum;
													$colnum++;
												}
											}
										}
									}

									if($associatedorientation->type=="tabular_orientation"){
										foreach($associatedorientation as $key=>$value){
											if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
												if($key=="feature_type"){$key="tabular_feature_type";}
												if(!$columns['orientation']['tabular'][$key]){
													$columns['orientation']['tabular'][$key]=$colnum;
													$colnum++;
												}
											}
										}
									}
								}
							}
						}
					}

					$x++;

				}

			}

			foreach($data['features'] as $feature){

				$pet = $feature['properties']['pet'];
				if($pet){
					if($pet->metamorphic){

						foreach($pet->metamorphic as $met){

							foreach($met as $key=>$value){
								if($key != "id"){
									if(!$columns['pet']['metamorphic'][$key]){
										$columns['pet']['metamorphic'][$key]=$colnum;
										$colnum++;
									}
								}
							}

						}
					}
				}

				$x++;

			}

			foreach($data['features'] as $feature){

				$pet = $feature['properties']['pet'];
				if($pet){
					if($pet->igneous){

						foreach($pet->igneous as $ig){

							foreach($ig as $key=>$value){
								if($key != "id"){
									if(!$columns['pet']['igneous'][$key]){
										$columns['pet']['igneous'][$key]=$colnum;
										$colnum++;
									}
								}
							}

						}
					}
				}

				$x++;

			}

			foreach($data['features'] as $feature){

				$pet = $feature['properties']['pet'];
				if($pet){
					if($pet->minerals){

						foreach($pet->minerals as $min){

							foreach($min as $key=>$value){
								if($key != "id"){
									if(!$columns['pet']['minerals'][$key]){
										$columns['pet']['minerals'][$key]=$colnum;
										$colnum++;
									}
								}
							}

						}
					}
				}

				$x++;

			}

			foreach($data['features'] as $feature){

				if($feature['properties']['strat_section_id'] == "" && $feature['properties']['image_basemap'] == ""){

					$other_features = $feature['properties']['other_features'];
					if($other_features){
						foreach($other_features as $other_feature){

							foreach($other_feature as $key=>$value){
								if($key != "id"){
									if(!$columns['other_features'][$key]){
										$columns['other_features'][$key]=$colnum;
										$colnum++;
									}
								}
							}

						}
					}

					$x++;

				}

			}

			foreach($data['features'] as $feature){

				if($feature['properties']['strat_section_id'] == "" && $feature['properties']['image_basemap'] == ""){

					$samples = $feature['properties']['samples'];
					if($samples){
						foreach($samples as $sample){

							foreach($sample as $key=>$value){
								if($key != "id"){
									if(!$columns['samples'][$key]){
										$columns['samples'][$key]=$colnum;
										$colnum++;
									}
								}
							}

						}
					}

					$x++;

				}

			}

			foreach($data['features'] as $feature){

				if($feature['properties']['strat_section_id'] == "" && $feature['properties']['image_basemap'] == ""){

					$trace = $feature['properties']['trace'];
					if($trace){
						foreach($trace as $key=>$value){

							if(!$columns['trace'][$key]){
								$columns['trace'][$key]=$colnum;
								$colnum++;
							}
						}
					}

					$x++;

				}

			}

			foreach($data['features'] as $feature){

				if($feature['properties']['strat_section_id'] == "" && $feature['properties']['image_basemap'] == ""){

					$tephra = $feature['properties']['tephra'];
					if($tephra){
						foreach($tephra as $t){
							foreach($t as $key=>$value){
								if($key != "id"){
									if(!$columns['tephra'][$key]){
										$columns['tephra'][$key]=$colnum;
										$colnum++;
									}
								}
							}
						}
					}

					$x++;

				}

			}

			foreach($data['features'] as $feature){

				if($feature['properties']['strat_section_id'] == "" && $feature['properties']['image_basemap'] == ""){

					$customfields = $feature['properties']['custom_fields'];
					if($customfields){
						foreach($customfields as $key=>$value){

							if(!$columns['custom_fields'][$key]){
								$columns['custom_fields'][$key]=$colnum;
								$colnum++;
							}
						}
					}

					$x++;

				}

			}

			$colnum=0;

			foreach($columns['spot'] as $key=>$value){

				$this->addHolding(0, $value, $this->shapefile_fix_column_name($key));

				$thiswidth=strlen($key)-1;
				if($thisheader=="Age (Ma)"){$thiswidth="12";}
				if($thiswidth<10){
					$thiswidth=10;
				}

				$colnum++;
			}

			if($columns['orientation']['tabular']){
				foreach($columns['orientation']['tabular'] as $key=>$value){

					$this->addHolding(0, $value, "to_".$this->shapefile_fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;

				}
			}

			if($columns['orientation']['planar']){
				foreach($columns['orientation']['planar'] as $key=>$value){

					$this->addHolding(0, $value, "po_".$this->shapefile_fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;

				}
			}

			if($columns['orientation']['linear']){
				foreach($columns['orientation']['linear'] as $key=>$value){

					$this->addHolding(0, $value, "lo_".$this->shapefile_fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;

				}
			}

			if($columns['pet']['metamorphic']){
				foreach($columns['pet']['metamorphic'] as $key=>$value){

					$this->addHolding(0, $value, "met_".$this->shapefile_fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;

				}
			}

			if($columns['pet']['igneous']){
				foreach($columns['pet']['igneous'] as $key=>$value){

					$this->addHolding(0, $value, "ig_".$this->shapefile_fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;

				}
			}

			if($columns['pet']['minerals']){
				foreach($columns['pet']['igneous'] as $key=>$value){

					$this->addHolding(0, $value, "min_".$this->shapefile_fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;

				}
			}

			if($columns['other_features']){
				foreach($columns['other_features'] as $key=>$value){

					$this->addHolding(0, $value, "other_".$this->shapefile_fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thisheader=="Age (Ma)"){$thiswidth="12";}
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;
				}
			}

			if($columns['samples']){
				foreach($columns['samples'] as $key=>$value){

					$this->addHolding(0, $value, "samp_".$this->shapefile_fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thisheader=="Age (Ma)"){$thiswidth="12";}
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;
				}
			}

			if($columns['trace']){
				foreach($columns['trace'] as $key=>$value){

					$this->addHolding(0, $value, "trace_".$this->shapefile_fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thisheader=="Age (Ma)"){$thiswidth="12";}
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;
				}
			}

			if($columns['tephra']){
				foreach($columns['tephra'] as $key=>$value){

					$this->addHolding(0, $value, "tephra_".$this->shapefile_fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thisheader=="Age (Ma)"){$thiswidth="12";}
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;
				}
			}

			if($columns['custom_fields']){
				foreach($columns['custom_fields'] as $key=>$value){

					$this->addHolding(0, $value, $this->shapefile_fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thisheader=="Age (Ma)"){$thiswidth="12";}
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;
				}
			}

			if($columns['tags']){
				foreach($columns['tags'] as $key=>$value){

					$taglabel = "";
					foreach($this->alltags as $tag){

						if($key == $tag->id){
							$taglabel = "Tag:".$tag->name;
						}
					}

					$this->addHolding(0, $value, $taglabel);

					$colnum++;
				}
			}

			//write data
			$rownum=1;
			foreach($data['features'] as $feature){

				if($feature['properties']['strat_section_id'] == "" && $feature['properties']['image_basemap'] == ""){

					//use geoPHP to get WKT
					$mygeojson=$feature['geometry'];
					$mygeojson=trim(json_encode($mygeojson));

					try {
						$mywkt=geoPHP::load($mygeojson,"json");
						$wkt = $mywkt->out('wkt');
						$geometry=$wkt;
					} catch (Exception $e) {
						$geometry="";
					}

					//use geoPHP to get WKT
					$mygeojson=$feature['original_geometry'];
					$mygeojson=trim(json_encode($mygeojson));

					try {
						$mywkt=geoPHP::load($mygeojson,"json");
						$wkt = $mywkt->out('wkt');
						$original_geometry=$wkt;
					} catch (Exception $e) {
						$original_geometry="";
					}

					$spotid = $feature['properties']['id'];
					$spotname = (string)$feature['properties']['name'];
					$spotdate = $feature['properties']['date'];
					$spotself = $feature['properties']['self'];
					$spotnotes = $feature['properties']['notes'];
					$altitude = $feature['properties']['altitude'];

					//build images here
					//https://strabospot.org/pi/16907249300069;https://strabospot.org/pi/16874590296725
					$foundImages = [];
					$imagesString = "";
					if(count($feature['properties']['images']) > 0){
						foreach($feature['properties']['images'] as $i){
							$foundImages[] = "https://strabospot.org/pi/".$i['id'];
						}
						$imagesString = implode(";", $foundImages);
					}

					$latitude = "";
					$longitude = "";

					if(strtolower(substr($geometry,0,5))=="point"){
						$lonlatgeom=$geometry;
						$lonlatgeom=strtolower($lonlatgeom);
						$lonlatgeom=str_replace("point ","",$lonlatgeom);
						$lonlatgeom=str_replace("(","",$lonlatgeom);
						$lonlatgeom=str_replace(")","",$lonlatgeom);
						$lonlatgeom=explode(" ",$lonlatgeom);
						$longitude=$lonlatgeom[0];
						$latitude=$lonlatgeom[1];
					}

					unset($arrayMultipleData);
					$maxcount = 0;
					$linear_orientation_count = 0;
					$planar_orientation_count = 0;
					$tabular_orientation_count = 0;
					$_3d_structure_count = 0;
					$other_features_count = 0;
					$samples_count = 0;
					$traces_count = 0;
					$tephras_count = 0;
					$metamorphics_count = 0;
					$igneouses_count = 0;
					$minerals_count = 0;
					$tags_count = 0;

					$orientationdatas = $feature['properties']['orientation_data'];
					if($orientationdatas){
						foreach($orientationdatas as $orientationdata){

							if($orientationdata->type=="linear_orientation"){
								foreach($orientationdata as $key=>$value){
									if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
										if($key=="feature_type"){$key="linear_feature_type";}
										$arrayMultipleData['linear'][$linear_orientation_count][$key]=$value;
									}
								}
								$linear_orientation_count++;
							}

							if($orientationdata->type=="planar_orientation"){
								foreach($orientationdata as $key=>$value){
									if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
										if($key=="feature_type"){$key="planar_feature_type";}
										$arrayMultipleData['planar'][$planar_orientation_count][$key]=$value;
									}
								}
								$planar_orientation_count++;
							}

							if($orientationdata->type=="tabular_orientation"){
								foreach($orientationdata as $key=>$value){
									if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
										if($key=="feature_type"){$key="tabular_feature_type";}
										$arrayMultipleData['tabular'][$tabular_orientation_count][$key]=$value;
									}
								}
								$tabular_orientation_count++;
							}

							$associatedorientations = $orientationdata->associated_orientation;
							if($associatedorientations){
								foreach($associatedorientations as $associatedorientation){
									if($associatedorientation->type=="planar_orientation"){
										foreach($associatedorientation as $key=>$value){
											if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
												if($key=="feature_type"){$key="planar_feature_type";}
												$arrayMultipleData['planar'][$planar_orientation_count][$key]=$value;
											}
										}
										$planar_orientation_count++;
									}

									if($associatedorientation->type=="linear_orientation"){
										foreach($associatedorientation as $key=>$value){
											if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
												if($key=="feature_type"){$key="linear_feature_type";}
												$arrayMultipleData['linear'][$linear_orientation_count][$key]=$value;
											}
										}
										$linear_orientation_count++;
									}

									if($associatedorientation->type=="tabular_orientation"){
										foreach($associatedorientation as $key=>$value){
											if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
												if($key=="feature_type"){$key="tabular_feature_type";}
												$arrayMultipleData['tabular'][$tabular_orientation_count][$key]=$value;
											}
										}
										$tabular_orientation_count++;
									}
								}
							}
						}
					}

					$metamorphics = $feature['properties']['pet']->metamorphic;
					if($metamorphics){
						foreach($metamorphics as $metamorphic){

							foreach($metamorphic as $key=>$value){
								if($key != "id" && $key != "" ){
									$arrayMultipleData['metamorphic'][$metamorphics_count][$key]=$value;
								}
							}
							$metamorphics_count++;
						}
					}

					$igneouses = $feature['properties']['pet']->igneous;
					if($igneouses){
						foreach($igneouses as $igneous){

							foreach($igneous as $key=>$value){
								if($key != "id" && $key != "" ){
									$arrayMultipleData['igneous'][$igneouses_count][$key]=$value;
								}
							}
							$igneouses_count++;
						}
					}

					$minerals = $feature['properties']['pet']->minerals;
					if($minerals){
						foreach($minerals as $mineral){

							foreach($mineral as $key=>$value){
								if($key != "id" && $key != "" ){
									$arrayMultipleData['mineral'][$minerals_count][$key]=$value;
								}
							}
							$minerals_count++;
						}
					}

					$other_features = $feature['properties']['other_features'];
					if($other_features){
						foreach($other_features as $other_feature){
							foreach($other_feature as $key=>$value){
								if($key != "id"){
									$arrayMultipleData['other_features'][$other_features_count][$key]=$value;
								}
							}
							$other_features_count++;
						}
					}

					$samples = $feature['properties']['samples'];
					if($samples){
						foreach($samples as $sample){
							foreach($sample as $key=>$value){
								if($key != "id"){
									$arrayMultipleData['samples'][$samples_count][$key]=$value;
								}
							}
							$samples_count++;
						}
					}

					$trace = $feature['properties']['trace'];
					if($trace){
						foreach($trace as $key=>$value){
							$arrayMultipleData['trace'][$traces_count][$key]=$value;
						}
						$traces_count++;
					}

					$tephras = $feature['properties']['tephra'];
					if($tephras){
						foreach($tephras as $tephra){
							foreach($tephra as $key=>$value){
								if($key != "id"){
									$arrayMultipleData['tephra'][$tephras_count][$key]=$value;
								}
							}
							$tephras_count++;
						}
					}

					if($linear_orientation_count > $maxcount) $maxcount = $linear_orientation_count;
					if($planar_orientation_count > $maxcount) $maxcount = $planar_orientation_count;
					if($tabular_orientation_count > $maxcount) $maxcount = $tabular_orientation_count;
					if($other_features_count > $maxcount) $maxcount = $other_features_count;
					if($samples_count > $maxcount) $maxcount = $samples_count;
					if($traces_count > $maxcount) $maxcount = $traces_count;
					if($tephras_count > $maxcount) $maxcount = $tephras_count;

					if($maxcount==0) $maxcount = 1;

					for($spotrepeat = 0; $spotrepeat < $maxcount; $spotrepeat ++){

						//write out each row here
						$this->addHolding($rownum,0, $spotname);
						$this->addHolding($rownum,1, $spotdate);
						$this->addHolding($rownum,2, $spotself);
						$this->addHolding($rownum,3,$spotnotes);
						$this->addHolding($rownum,4,$geometry);

						$this->addHolding($rownum,5,$imagesString);

						//JMA 20230730
						//}

						$this->addHolding($rownum,6,$latitude);
						$this->addHolding($rownum,7,$longitude);
						$this->addHolding($rownum,8,$altitude);

						//spotid

						$colnum = 9;
						foreach($this->alltags as $tag){

							if($tag->type == "geologic_unit"){
								if(in_array($spotid, $tag->spots)){
									$this->addHolding($rownum,$colnum,$tag->name);
									$colnum++;
								}
							}

						}

						foreach($columns as $key=>$value){
							if($key!="spot"){
								if($key == "orientation" || $key == "pet"){
									foreach($value as $otype=>$ors){
										foreach($ors as $orkey=>$orval){

											$rawVal = $arrayMultipleData[$otype][$spotrepeat][$orkey];
											if(is_array($rawVal)){
												$showVal = implode(", ", $rawVal);
											}else{
												$showVal = $rawVal;
											}

											$this->addHolding($rownum,$orval,$showVal);
										}
									}
								}else{
									foreach($value as $newkey=>$column){
										$this->addHolding($rownum,$column,$arrayMultipleData[$key][$spotrepeat][$newkey]);
									}
								}
							}
						}

						foreach($columns as $key=>$value){
							if($key=="tags"){
								//determine if the spot belongs in the tag and mark X if it is
								foreach($value as $tagid=>$colnum){
									foreach($this->alltags as $tag){
										if($tagid == $tag->id){

											if(in_array($spotid, $tag->spots)){
												$this->addHolding($rownum,$colnum,"X");
											}

											if($tag->features != null){
												foreach($tag->features as $snum=>$other){
													if($snum == $spotid){
														$this->addHolding($rownum,$colnum,"X");
													}
												}
											}

										}
									}
								}
							}
						}

						$rownum++;
					}

				}//end foreach feature

			}

		}

		$pointjson = "";
		$linejson = "";
		$polygonjson = "";

		$fields = $this->holdings[0];

		//Point
		$features = [];
		for($x=1; $x<count($this->holdings); $x++){
			$row = $this->holdings[$x];
			$geometry = $row[4];
			if(substr($geometry,0,5)=="POINT" || substr($geometry,0,10)=="MULTIPOINT"){
				$thisFeature = new stdClass();
				$thisFeature->type = "Feature";
				$mywkt=geoPHP::load($geometry,"wkt");
				$thisgeometry = json_decode($mywkt->out('json'));
				$thisFeature->geometry = $thisgeometry;
				$properties = [];
				for($col = 0; $col < count($fields); $col++){
					eval("\$properties['".$fields[$col]."'] = \$row[$col];");
				}
				$thisFeature->properties = $properties;
				$features[] = $thisFeature;
			}
		}
		if(count($features) > 0){
			$featureCollection = new stdClass();
			$featureCollection->type = "FeatureCollection";
			$featureCollection->features = $features;
			$pointjson = json_encode($featureCollection, JSON_PRETTY_PRINT);
		}

		//Line
		$features = [];
		for($x=1; $x<count($this->holdings); $x++){
			$row = $this->holdings[$x];
			$geometry = $row[4];
			if(substr($geometry,0,10)=="LINESTRING"){
				$thisFeature = new stdClass();
				$thisFeature->type = "Feature";
				$mywkt=geoPHP::load($geometry,"wkt");
				$thisgeometry = json_decode($mywkt->out('json'));
				$thisFeature->geometry = $thisgeometry;
				$properties = [];
				for($col = 0; $col < count($fields); $col++){
					eval("\$properties['".$fields[$col]."'] = \$row[$col];");
				}
				$thisFeature->properties = $properties;
				$features[] = $thisFeature;
			}
		}

		if(count($features) > 0){
			$featureCollection = new stdClass();
			$featureCollection->type = "FeatureCollection";
			$featureCollection->features = $features;
			$linejson = json_encode($featureCollection, JSON_PRETTY_PRINT);
		}

		//Polygon
		$features = [];
		for($x=1; $x<count($this->holdings); $x++){
			$row = $this->holdings[$x];
			$geometry = $row[4];
			if(substr($geometry,0,7)=="POLYGON"){
				$thisFeature = new stdClass();
				$thisFeature->type = "Feature";
				$mywkt=geoPHP::load($geometry,"wkt");
				$thisgeometry = json_decode($mywkt->out('json'));
				$thisFeature->geometry = $thisgeometry;
				$properties = [];
				for($col = 0; $col < count($fields); $col++){
					eval("\$properties['".$fields[$col]."'] = \$row[$col];");
				}
				$thisFeature->properties = $properties;
				$features[] = $thisFeature;
			}
		}
		if(count($features) > 0){
			$featureCollection = new stdClass();
			$featureCollection->type = "FeatureCollection";
			$featureCollection->features = $features;
			$polygonjson = json_encode($featureCollection, JSON_PRETTY_PRINT);
		}

		if($polygonjson!="" || $pointjson!="" || $linejson!=""){

			$randnum=$this->strabo->db->get_var("select nextval('file_seq')");

			//make directory in ogrtemp to hold data
			mkdir("ogrtemp/$randnum");
			mkdir("ogrtemp/$randnum/data");

			if($polygonjson!=""){
				file_put_contents("ogrtemp/$randnum/polygon.json", $polygonjson);
				exec("ogr2ogr -nlt POLYGON -f \"ESRI Shapefile\" -skipfailures ogrtemp/$randnum/data/polygons.shp ogrtemp/$randnum/polygon.json 2>&1",$results);
			}

			if($linejson!=""){
				file_put_contents("ogrtemp/$randnum/line.json", $linejson);
				exec("ogr2ogr -nlt LINESTRING -f \"ESRI Shapefile\" -skipfailures ogrtemp/$randnum/data/lines.shp ogrtemp/$randnum/line.json 2>&1",$results);
				unlink("ogrtemp/$randnum/line.json");
			}

			if($pointjson!=""){
				file_put_contents("ogrtemp/$randnum/point.json", $pointjson);
				exec("ogr2ogr -nlt POINT -f \"ESRI Shapefile\" -skipfailures ogrtemp/$randnum/data/points.shp ogrtemp/$randnum/point.json 2>&1",$results);
				unlink("ogrtemp/$randnum/point.json");
			}

			//create zip file
			exec("zip -j ogrtemp/$randnum/strabo$randnum.zip ogrtemp/$randnum/data/* 2>&1",$results);

			//force download of file
			header("Content-Type: application/zip");
			header("Content-Disposition: attachment; filename=search_download.zip");
			header("Content-Length: " . filesize("ogrtemp/$randnum/strabo$randnum.zip"));

			readfile("ogrtemp/$randnum/strabo$randnum.zip");

			//remove temp directory
			if($randnum!=""){
			}

		}else{
			echo "No data found for this dataset.";
		}

	}

	public function expandedShapefileOut(){

		if($this->get['dsids']!=""){

			$dsids=$this->get['dsids'];
			$this->alltags = $this->strabo->getTagsFromDatasetIds($dsids);

			$data = $this->strabo->getDatasetSpotsSearch(null,$this->get);

			if(count($data)==0){
			echo "no data found for dataset $id";exit();
			}

			$columns['spot']['name']=0;
			$columns['spot']['date']=1;
			$columns['spot']['self']=2;
			$columns['spot']['notes']=3;
			$columns['spot']['geometry']=4;
			$columns['spot']['images']=5;
			$columns['spot']['Latitude']=6;
			$columns['spot']['Longitude']=7;
			$columns['spot']['Altitude(m)']=8;
			$colnum=9;

			$tagcounts = [];
			foreach($data['features'] as $spot){
				$spotid = $spot['properties']['id'];
				$tagcounts[$spotid] = 0;
			}

			foreach($data['features'] as $spot){
				$spotid = $spot['properties']['id'];

				foreach($this->alltags as $tag){
					if($tag->type == "geologic_unit"){
						if(in_array($spotid, $tag->spots)){
							$tagcounts[$spotid]++;
						}
					}
				}

			}

			$maxnumliths = 0;
			foreach($tagcounts as $key=>$tagcount){
				if($tagcount > $maxnumliths) $maxnumliths = $tagcount;
			}

			for($lithnum = 1; $lithnum <= $maxnumliths; $lithnum++){
				$columns['spot']['lith'.$lithnum] = $colnum;
				$colnum++;
			}

			$x=0;

			$arrayMultipleData = [];

			foreach($data['features'] as $feature){

				if($feature['properties']['strat_section_id'] == "" && $feature['properties']['image_basemap'] == ""){

					$orientationdatas = $feature['properties']['orientation_data'];
					if($orientationdatas){
						foreach($orientationdatas as $orientationdata){

							if($orientationdata->type=="linear_orientation"){
								foreach($orientationdata as $key=>$value){
									if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
										if($key=="feature_type"){$key="linear_feature_type";}
										if(!$columns['orientation']['linear'][$key]){
											$columns['orientation']['linear'][$key]=$colnum;
											$colnum++;
										}
									}
								}
							}

							if($orientationdata->type=="planar_orientation"){
								foreach($orientationdata as $key=>$value){
									if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
										if($key=="feature_type"){$key="planar_feature_type";}
										if(!$columns['orientation']['planar'][$key]){
											$columns['orientation']['planar'][$key]=$colnum;
											$colnum++;
										}
									}
								}
							}

							if($orientationdata->type=="tabular_orientation"){
								foreach($orientationdata as $key=>$value){
									if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
										if($key=="feature_type"){$key="tabular_feature_type";}
										if(!$columns['orientation']['tabular'][$key]){
											$columns['orientation']['tabular'][$key]=$colnum;
											$colnum++;
										}
									}
								}
							}

							$associatedorientations = $orientationdata->associated_orientation;
							if($associatedorientations){
								foreach($associatedorientations as $associatedorientation){
									if($associatedorientation->type=="planar_orientation"){
										foreach($associatedorientation as $key=>$value){
											if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
												if($key=="feature_type"){$key="planar_feature_type";}
												if(!$columns['orientation']['planar'][$key]){
													$columns['orientation']['planar'][$key]=$colnum;
													$colnum++;
												}
											}
										}

									}

									if($associatedorientation->type=="linear_orientation"){
										foreach($associatedorientation as $key=>$value){
											if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
												if($key=="feature_type"){$key="linear_feature_type";}
												if(!$columns['orientation']['linear'][$key]){
													$columns['orientation']['linear'][$key]=$colnum;
													$colnum++;
												}
											}
										}
									}

									if($associatedorientation->type=="tabular_orientation"){
										foreach($associatedorientation as $key=>$value){
											if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
												if($key=="feature_type"){$key="tabular_feature_type";}
												if(!$columns['orientation']['tabular'][$key]){
													$columns['orientation']['tabular'][$key]=$colnum;
													$colnum++;
												}
											}
										}
									}
								}
							}
						}
					}

					$x++;

				}

			}

			foreach($data['features'] as $feature){

				$pet = $feature['properties']['pet'];
				if($pet){
					if($pet->metamorphic){

						foreach($pet->metamorphic as $met){

							foreach($met as $key=>$value){
								if($key != "id"){
									if(!$columns['pet']['metamorphic'][$key]){
										$columns['pet']['metamorphic'][$key]=$colnum;
										$colnum++;
									}
								}
							}

						}
					}
				}

				$x++;

			}

			foreach($data['features'] as $feature){

				$pet = $feature['properties']['pet'];
				if($pet){
					if($pet->igneous){

						foreach($pet->igneous as $ig){

							foreach($ig as $key=>$value){
								if($key != "id"){
									if(!$columns['pet']['igneous'][$key]){
										$columns['pet']['igneous'][$key]=$colnum;
										$colnum++;
									}
								}
							}

						}
					}
				}

				$x++;

			}

			foreach($data['features'] as $feature){

				$pet = $feature['properties']['pet'];
				if($pet){
					if($pet->minerals){

						foreach($pet->minerals as $min){

							foreach($min as $key=>$value){
								if($key != "id"){
									if(!$columns['pet']['minerals'][$key]){
										$columns['pet']['minerals'][$key]=$colnum;
										$colnum++;
									}
								}
							}

						}
					}
				}

				$x++;

			}

			foreach($data['features'] as $feature){

				if($feature['properties']['strat_section_id'] == "" && $feature['properties']['image_basemap'] == ""){

					$other_features = $feature['properties']['other_features'];
					if($other_features){
						foreach($other_features as $other_feature){

							foreach($other_feature as $key=>$value){
								if($key != "id"){
									if(!$columns['other_features'][$key]){
										$columns['other_features'][$key]=$colnum;
										$colnum++;
									}
								}
							}

						}
					}

					$x++;

				}

			}

			foreach($data['features'] as $feature){

				if($feature['properties']['strat_section_id'] == "" && $feature['properties']['image_basemap'] == ""){

					$samples = $feature['properties']['samples'];
					if($samples){
						foreach($samples as $sample){

							foreach($sample as $key=>$value){
								if($key != "id"){
									if(!$columns['samples'][$key]){
										$columns['samples'][$key]=$colnum;
										$colnum++;
									}
								}
							}

						}
					}

					$x++;

				}

			}

			foreach($data['features'] as $feature){

				if($feature['properties']['strat_section_id'] == "" && $feature['properties']['image_basemap'] == ""){

					$trace = $feature['properties']['trace'];
					if($trace){
						foreach($trace as $key=>$value){

							if(!$columns['trace'][$key]){
								$columns['trace'][$key]=$colnum;
								$colnum++;
							}
						}
					}

					$x++;

				}

			}

			foreach($data['features'] as $feature){

				if($feature['properties']['strat_section_id'] == "" && $feature['properties']['image_basemap'] == ""){

					$tephra = $feature['properties']['tephra'];
					if($tephra){
						foreach($tephra as $t){
							foreach($t as $key=>$value){
								if($key != "id"){
									if(!$columns['tephra'][$key]){
										$columns['tephra'][$key]=$colnum;
										$colnum++;
									}
								}
							}
						}
					}

					$x++;

				}

			}

			foreach($data['features'] as $feature){

				if($feature['properties']['strat_section_id'] == "" && $feature['properties']['image_basemap'] == ""){

					$customfields = $feature['properties']['custom_fields'];
					if($customfields){
						foreach($customfields as $key=>$value){

							if(!$columns['custom_fields'][$key]){
								$columns['custom_fields'][$key]=$colnum;
								$colnum++;
							}
						}
					}

					$x++;

				}

			}

			$colnum=0;

			foreach($columns['spot'] as $key=>$value){

				$this->addHolding(0, $value, $this->shapefile_fix_column_name($key));

				$thiswidth=strlen($key)-1;
				if($thisheader=="Age (Ma)"){$thiswidth="12";}
				if($thiswidth<10){
					$thiswidth=10;
				}

				$colnum++;
			}

			if($columns['orientation']['tabular']){
				foreach($columns['orientation']['tabular'] as $key=>$value){

					$this->addHolding(0, $value, "to_".$this->shapefile_fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;

				}
			}

			if($columns['orientation']['planar']){
				foreach($columns['orientation']['planar'] as $key=>$value){

					$this->addHolding(0, $value, "po_".$this->shapefile_fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;

				}
			}

			if($columns['orientation']['linear']){
				foreach($columns['orientation']['linear'] as $key=>$value){

					$this->addHolding(0, $value, "lo_".$this->shapefile_fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;

				}
			}

			if($columns['pet']['metamorphic']){
				foreach($columns['pet']['metamorphic'] as $key=>$value){

					$this->addHolding(0, $value, "met_".$this->shapefile_fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;

				}
			}

			if($columns['pet']['igneous']){
				foreach($columns['pet']['igneous'] as $key=>$value){

					$this->addHolding(0, $value, "ig_".$this->shapefile_fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;

				}
			}

			if($columns['pet']['minerals']){
				foreach($columns['pet']['igneous'] as $key=>$value){

					$this->addHolding(0, $value, "min_".$this->shapefile_fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;

				}
			}

			if($columns['other_features']){
				foreach($columns['other_features'] as $key=>$value){

					$this->addHolding(0, $value, "other_".$this->shapefile_fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thisheader=="Age (Ma)"){$thiswidth="12";}
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;
				}
			}

			if($columns['samples']){
				foreach($columns['samples'] as $key=>$value){

					$this->addHolding(0, $value, "samp_".$this->shapefile_fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thisheader=="Age (Ma)"){$thiswidth="12";}
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;
				}
			}

			if($columns['trace']){
				foreach($columns['trace'] as $key=>$value){

					$this->addHolding(0, $value, "trace_".$this->shapefile_fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thisheader=="Age (Ma)"){$thiswidth="12";}
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;
				}
			}

			if($columns['tephra']){
				foreach($columns['tephra'] as $key=>$value){

					$this->addHolding(0, $value, "tephra_".$this->shapefile_fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thisheader=="Age (Ma)"){$thiswidth="12";}
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;
				}
			}

			if($columns['custom_fields']){
				foreach($columns['custom_fields'] as $key=>$value){

					$this->addHolding(0, $value, $this->shapefile_fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thisheader=="Age (Ma)"){$thiswidth="12";}
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;
				}
			}

			if($columns['tags']){
				foreach($columns['tags'] as $key=>$value){

					$taglabel = "";
					foreach($this->alltags as $tag){

						if($key == $tag->id){
							$taglabel = "Tag:".$tag->name;
						}
					}

					$this->addHolding(0, $value, $taglabel);

					$colnum++;
				}
			}

			//write data
			$rownum=1;
			foreach($data['features'] as $feature){

				if($feature['properties']['strat_section_id'] == "" && $feature['properties']['image_basemap'] == ""){

					//use geoPHP to get WKT
					$mygeojson=$feature['geometry'];
					$mygeojson=trim(json_encode($mygeojson));

					try {
						$mywkt=geoPHP::load($mygeojson,"json");
						$wkt = $mywkt->out('wkt');
						$geometry=$wkt;
					} catch (Exception $e) {
						$geometry="";
					}

					//use geoPHP to get WKT
					$mygeojson=$feature['original_geometry'];
					$mygeojson=trim(json_encode($mygeojson));

					try {
						$mywkt=geoPHP::load($mygeojson,"json");
						$wkt = $mywkt->out('wkt');
						$original_geometry=$wkt;
					} catch (Exception $e) {
						$original_geometry="";
					}

					$spotid = $feature['properties']['id'];
					$spotname = (string)$feature['properties']['name'];
					$spotdate = $feature['properties']['date'];
					$spotself = $feature['properties']['self'];
					$spotnotes = $feature['properties']['notes'];
					$altitude = $feature['properties']['altitude'];

					//build images here
					//https://strabospot.org/pi/16907249300069;https://strabospot.org/pi/16874590296725
					$foundImages = [];
					$imagesString = "";
					if(count($feature['properties']['images']) > 0){
						foreach($feature['properties']['images'] as $i){
							$foundImages[] = "https://strabospot.org/pi/".$i['id'];
						}
						$imagesString = implode(";", $foundImages);
					}

					$latitude = "";
					$longitude = "";

					if(strtolower(substr($geometry,0,5))=="point"){
						$lonlatgeom=$geometry;
						$lonlatgeom=strtolower($lonlatgeom);
						$lonlatgeom=str_replace("point ","",$lonlatgeom);
						$lonlatgeom=str_replace("(","",$lonlatgeom);
						$lonlatgeom=str_replace(")","",$lonlatgeom);
						$lonlatgeom=explode(" ",$lonlatgeom);
						$longitude=$lonlatgeom[0];
						$latitude=$lonlatgeom[1];
					}

					unset($arrayMultipleData);
					$maxcount = 0;
					$linear_orientation_count = 0;
					$planar_orientation_count = 0;
					$tabular_orientation_count = 0;
					$_3d_structure_count = 0;
					$other_features_count = 0;
					$samples_count = 0;
					$traces_count = 0;
					$tephras_count = 0;
					$metamorphics_count = 0;
					$igneouses_count = 0;
					$minerals_count = 0;
					$tags_count = 0;

					$orientationdatas = $feature['properties']['orientation_data'];
					if($orientationdatas){
						foreach($orientationdatas as $orientationdata){

							if($orientationdata->type=="linear_orientation"){
								foreach($orientationdata as $key=>$value){
									if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
										if($key=="feature_type"){$key="linear_feature_type";}
										$arrayMultipleData['linear'][$linear_orientation_count][$key]=$value;
									}
								}
								$linear_orientation_count++;
							}

							if($orientationdata->type=="planar_orientation"){
								foreach($orientationdata as $key=>$value){
									if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
										if($key=="feature_type"){$key="planar_feature_type";}
										$arrayMultipleData['planar'][$planar_orientation_count][$key]=$value;
									}
								}
								$planar_orientation_count++;
							}

							if($orientationdata->type=="tabular_orientation"){
								foreach($orientationdata as $key=>$value){
									if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
										if($key=="feature_type"){$key="tabular_feature_type";}
										$arrayMultipleData['tabular'][$tabular_orientation_count][$key]=$value;
									}
								}
								$tabular_orientation_count++;
							}

							$associatedorientations = $orientationdata->associated_orientation;
							if($associatedorientations){
								foreach($associatedorientations as $associatedorientation){
									if($associatedorientation->type=="planar_orientation"){
										foreach($associatedorientation as $key=>$value){
											if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
												if($key=="feature_type"){$key="planar_feature_type";}
												$arrayMultipleData['planar'][$planar_orientation_count][$key]=$value;
											}
										}
										$planar_orientation_count++;
									}

									if($associatedorientation->type=="linear_orientation"){
										foreach($associatedorientation as $key=>$value){
											if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
												if($key=="feature_type"){$key="linear_feature_type";}
												$arrayMultipleData['linear'][$linear_orientation_count][$key]=$value;
											}
										}
										$linear_orientation_count++;
									}

									if($associatedorientation->type=="tabular_orientation"){
										foreach($associatedorientation as $key=>$value){
											if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
												if($key=="feature_type"){$key="tabular_feature_type";}
												$arrayMultipleData['tabular'][$tabular_orientation_count][$key]=$value;
											}
										}
										$tabular_orientation_count++;
									}
								}
							}
						}
					}

					$metamorphics = $feature['properties']['pet']->metamorphic;
					if($metamorphics){
						foreach($metamorphics as $metamorphic){

							foreach($metamorphic as $key=>$value){
								if($key != "id" && $key != "" ){
									$arrayMultipleData['metamorphic'][$metamorphics_count][$key]=$value;
								}
							}
							$metamorphics_count++;
						}
					}

					$igneouses = $feature['properties']['pet']->igneous;
					if($igneouses){
						foreach($igneouses as $igneous){

							foreach($igneous as $key=>$value){
								if($key != "id" && $key != "" ){
									$arrayMultipleData['igneous'][$igneouses_count][$key]=$value;
								}
							}
							$igneouses_count++;
						}
					}

					$minerals = $feature['properties']['pet']->minerals;
					if($minerals){
						foreach($minerals as $mineral){

							foreach($mineral as $key=>$value){
								if($key != "id" && $key != "" ){
									$arrayMultipleData['mineral'][$minerals_count][$key]=$value;
								}
							}
							$minerals_count++;
						}
					}

					$other_features = $feature['properties']['other_features'];
					if($other_features){
						foreach($other_features as $other_feature){
							foreach($other_feature as $key=>$value){
								if($key != "id"){
									$arrayMultipleData['other_features'][$other_features_count][$key]=$value;
								}
							}
							$other_features_count++;
						}
					}

					$samples = $feature['properties']['samples'];
					if($samples){
						foreach($samples as $sample){
							foreach($sample as $key=>$value){
								if($key != "id"){
									$arrayMultipleData['samples'][$samples_count][$key]=$value;
								}
							}
							$samples_count++;
						}
					}

					$trace = $feature['properties']['trace'];
					if($trace){
						foreach($trace as $key=>$value){
							$arrayMultipleData['trace'][$traces_count][$key]=$value;
						}
						$traces_count++;
					}

					$tephras = $feature['properties']['tephra'];
					if($tephras){
						foreach($tephras as $tephra){
							foreach($tephra as $key=>$value){
								if($key != "id"){
									$arrayMultipleData['tephra'][$tephras_count][$key]=$value;
								}
							}
							$tephras_count++;
						}
					}

					if($linear_orientation_count > $maxcount) $maxcount = $linear_orientation_count;
					if($planar_orientation_count > $maxcount) $maxcount = $planar_orientation_count;
					if($tabular_orientation_count > $maxcount) $maxcount = $tabular_orientation_count;
					if($other_features_count > $maxcount) $maxcount = $other_features_count;
					if($samples_count > $maxcount) $maxcount = $samples_count;
					if($traces_count > $maxcount) $maxcount = $traces_count;
					if($tephras_count > $maxcount) $maxcount = $tephras_count;

					if($maxcount==0) $maxcount = 1;

					for($spotrepeat = 0; $spotrepeat < $maxcount; $spotrepeat ++){

						//write out each row here
						$this->addHolding($rownum,0, $spotname);
						$this->addHolding($rownum,1, $spotdate);
						$this->addHolding($rownum,2, $spotself);
						$this->addHolding($rownum,3,$spotnotes);
						$this->addHolding($rownum,4,$geometry);

						$this->addHolding($rownum,5,$imagesString);

						//JMA 20230730
						//}

						$this->addHolding($rownum,6,$latitude);
						$this->addHolding($rownum,7,$longitude);
						$this->addHolding($rownum,8,$altitude);

						//spotid

						$colnum = 9;
						foreach($this->alltags as $tag){

							if($tag->type == "geologic_unit"){
								if(in_array($spotid, $tag->spots)){
									$this->addHolding($rownum,$colnum,$tag->name);
									$colnum++;
								}
							}

						}

						foreach($columns as $key=>$value){
							if($key!="spot"){
								if($key == "orientation" || $key == "pet"){
									foreach($value as $otype=>$ors){
										foreach($ors as $orkey=>$orval){

											$rawVal = $arrayMultipleData[$otype][$spotrepeat][$orkey];
											if(is_array($rawVal)){
												$showVal = implode(", ", $rawVal);
											}else{
												$showVal = $rawVal;
											}

											$this->addHolding($rownum,$orval,$showVal);
										}
									}
								}else{
									foreach($value as $newkey=>$column){
										$this->addHolding($rownum,$column,$arrayMultipleData[$key][$spotrepeat][$newkey]);
									}
								}
							}
						}

						foreach($columns as $key=>$value){
							if($key=="tags"){
								//determine if the spot belongs in the tag and mark X if it is
								foreach($value as $tagid=>$colnum){
									foreach($this->alltags as $tag){
										if($tagid == $tag->id){

											if(in_array($spotid, $tag->spots)){
												$this->addHolding($rownum,$colnum,"X");
											}

											if($tag->features != null){
												foreach($tag->features as $snum=>$other){
													if($snum == $spotid){
														$this->addHolding($rownum,$colnum,"X");
													}
												}
											}

										}
									}
								}
							}
						}

						$rownum++;
					}

				}//end foreach feature

			}

		}

		$pointjson = "";
		$linejson = "";
		$polygonjson = "";

		$fields = $this->holdings[0];

		//Point
		$features = [];
		for($x=1; $x<count($this->holdings); $x++){
			$row = $this->holdings[$x];
			$geometry = $row[4];
			if(substr($geometry,0,5)=="POINT" || substr($geometry,0,10)=="MULTIPOINT"){
				$thisFeature = new stdClass();
				$thisFeature->type = "Feature";
				$mywkt=geoPHP::load($geometry,"wkt");
				$thisgeometry = json_decode($mywkt->out('json'));
				$thisFeature->geometry = $thisgeometry;
				$properties = [];
				for($col = 0; $col < count($fields); $col++){
					eval("\$properties['".$fields[$col]."'] = \$row[$col];");
				}
				$thisFeature->properties = $properties;
				$features[] = $thisFeature;
			}
		}
		if(count($features) > 0){
			$featureCollection = new stdClass();
			$featureCollection->type = "FeatureCollection";
			$featureCollection->features = $features;
			$pointjson = json_encode($featureCollection, JSON_PRETTY_PRINT);
		}

		//Line
		$features = [];
		for($x=1; $x<count($this->holdings); $x++){
			$row = $this->holdings[$x];
			$geometry = $row[4];
			if(substr($geometry,0,10)=="LINESTRING"){
				$thisFeature = new stdClass();
				$thisFeature->type = "Feature";
				$mywkt=geoPHP::load($geometry,"wkt");
				$thisgeometry = json_decode($mywkt->out('json'));
				$thisFeature->geometry = $thisgeometry;
				$properties = [];
				for($col = 0; $col < count($fields); $col++){
					eval("\$properties['".$fields[$col]."'] = \$row[$col];");
				}
				$thisFeature->properties = $properties;
				$features[] = $thisFeature;
			}
		}

		if(count($features) > 0){
			$featureCollection = new stdClass();
			$featureCollection->type = "FeatureCollection";
			$featureCollection->features = $features;
			$linejson = json_encode($featureCollection, JSON_PRETTY_PRINT);
		}

		//Polygon
		$features = [];
		for($x=1; $x<count($this->holdings); $x++){
			$row = $this->holdings[$x];
			$geometry = $row[4];
			if(substr($geometry,0,7)=="POLYGON"){
				$thisFeature = new stdClass();
				$thisFeature->type = "Feature";
				$mywkt=geoPHP::load($geometry,"wkt");
				$thisgeometry = json_decode($mywkt->out('json'));
				$thisFeature->geometry = $thisgeometry;
				$properties = [];
				for($col = 0; $col < count($fields); $col++){
					eval("\$properties['".$fields[$col]."'] = \$row[$col];");
				}
				$thisFeature->properties = $properties;
				$features[] = $thisFeature;
			}
		}
		if(count($features) > 0){
			$featureCollection = new stdClass();
			$featureCollection->type = "FeatureCollection";
			$featureCollection->features = $features;
			$polygonjson = json_encode($featureCollection, JSON_PRETTY_PRINT);
		}

		if($polygonjson!="" || $pointjson!="" || $linejson!=""){

			$randnum=$this->strabo->db->get_var("select nextval('file_seq')");

			//make directory in ogrtemp to hold data
			mkdir("ogrtemp/$randnum");
			mkdir("ogrtemp/$randnum/data");

			if($polygonjson!=""){
				file_put_contents("ogrtemp/$randnum/polygon.json", $polygonjson);
				exec("ogr2ogr -nlt POLYGON -f \"ESRI Shapefile\" -skipfailures ogrtemp/$randnum/data/polygons.shp ogrtemp/$randnum/polygon.json 2>&1",$results);
			}

			if($linejson!=""){
				file_put_contents("ogrtemp/$randnum/line.json", $linejson);
				exec("ogr2ogr -nlt LINESTRING -f \"ESRI Shapefile\" -skipfailures ogrtemp/$randnum/data/lines.shp ogrtemp/$randnum/line.json 2>&1",$results);
				unlink("ogrtemp/$randnum/line.json");
			}

			if($pointjson!=""){
				file_put_contents("ogrtemp/$randnum/point.json", $pointjson);
				exec("ogr2ogr -nlt POINT -f \"ESRI Shapefile\" -skipfailures ogrtemp/$randnum/data/points.shp ogrtemp/$randnum/point.json 2>&1",$results);
				unlink("ogrtemp/$randnum/point.json");
			}

			//create zip file
			exec("zip -j ogrtemp/$randnum/strabo$randnum.zip ogrtemp/$randnum/data/* 2>&1",$results);

			//force download of file

			header("Content-Type: application/zip");
			header("Content-Disposition: attachment; filename=search_download.zip");
			header("Content-Length: " . filesize("ogrtemp/$randnum/strabo$randnum.zip"));

			readfile("ogrtemp/$randnum/strabo$randnum.zip");

			//remove temp directory
			if($randnum!=""){
			}

		}else{
			echo "No data found for this dataset.";
		}

	}

	public function bkupexpandedShapefileOut20240531(){

		if($this->get['dsids']!=""){

			$dsids=$this->get['dsids'];
			$this->alltags = $this->strabo->getTagsFromDatasetIds($dsids);

			$data = $this->strabo->getDatasetSpotsSearch(null,$this->get);

			if(count($data)==0){
			echo "no data found for dataset $id";exit();
			}

			$columns['spot']['name']=0;
			$columns['spot']['date']=1;
			$columns['spot']['self']=2;
			$columns['spot']['notes']=3;
			$columns['spot']['geometry']=4;
			$columns['spot']['images']=5;
			$columns['spot']['Latitude']=6;
			$columns['spot']['Longitude']=7;
			$columns['spot']['Altitude(m)']=8;

			$colnum=9;

			$x=0;

			$arrayMultipleData = [];

			foreach($data['features'] as $feature){

				if($feature['properties']['strat_section_id'] == "" && $feature['properties']['image_basemap'] == ""){

					$orientationdatas = $feature['properties']['orientation_data'];
					if($orientationdatas){
						foreach($orientationdatas as $orientationdata){

							if($orientationdata->type=="linear_orientation"){
								foreach($orientationdata as $key=>$value){
									if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
										if($key=="feature_type"){$key="linear_feature_type";}
										if(!$columns['orientation']['linear'][$key]){
											$columns['orientation']['linear'][$key]=$colnum;
											$colnum++;
										}
									}
								}
							}

							if($orientationdata->type=="planar_orientation"){
								foreach($orientationdata as $key=>$value){
									if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
										if($key=="feature_type"){$key="planar_feature_type";}
										if(!$columns['orientation']['planar'][$key]){
											$columns['orientation']['planar'][$key]=$colnum;
											$colnum++;
										}
									}
								}
							}

							if($orientationdata->type=="tabular_orientation"){
								foreach($orientationdata as $key=>$value){
									if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
										if($key=="feature_type"){$key="tabular_feature_type";}
										if(!$columns['orientation']['tabular'][$key]){
											$columns['orientation']['tabular'][$key]=$colnum;
											$colnum++;
										}
									}
								}
							}

							$associatedorientations = $orientationdata->associated_orientation;
							if($associatedorientations){
								foreach($associatedorientations as $associatedorientation){
									if($associatedorientation->type=="planar_orientation"){
										foreach($associatedorientation as $key=>$value){
											if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
												if($key=="feature_type"){$key="planar_feature_type";}
												if(!$columns['orientation']['planar'][$key]){
													$columns['orientation']['planar'][$key]=$colnum;
													$colnum++;
												}
											}
										}

									}

									if($associatedorientation->type=="linear_orientation"){
										foreach($associatedorientation as $key=>$value){
											if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
												if($key=="feature_type"){$key="linear_feature_type";}
												if(!$columns['orientation']['linear'][$key]){
													$columns['orientation']['linear'][$key]=$colnum;
													$colnum++;
												}
											}
										}
									}

									if($associatedorientation->type=="tabular_orientation"){
										foreach($associatedorientation as $key=>$value){
											if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
												if($key=="feature_type"){$key="tabular_feature_type";}
												if(!$columns['orientation']['tabular'][$key]){
													$columns['orientation']['tabular'][$key]=$colnum;
													$colnum++;
												}
											}
										}
									}
								}
							}
						}
					}

					$x++;

				}

			}

			foreach($data['features'] as $feature){

				$pet = $feature['properties']['pet'];
				if($pet){
					if($pet->metamorphic){

						foreach($pet->metamorphic as $met){

							foreach($met as $key=>$value){
								if($key != "id"){
									if(!$columns['pet']['metamorphic'][$key]){
										$columns['pet']['metamorphic'][$key]=$colnum;
										$colnum++;
									}
								}
							}

						}
					}
				}

				$x++;

			}

			foreach($data['features'] as $feature){

				$pet = $feature['properties']['pet'];
				if($pet){
					if($pet->igneous){

						foreach($pet->igneous as $ig){

							foreach($ig as $key=>$value){
								if($key != "id"){
									if(!$columns['pet']['igneous'][$key]){
										$columns['pet']['igneous'][$key]=$colnum;
										$colnum++;
									}
								}
							}

						}
					}
				}

				$x++;

			}

			foreach($data['features'] as $feature){

				$pet = $feature['properties']['pet'];
				if($pet){
					if($pet->minerals){

						foreach($pet->minerals as $min){

							foreach($min as $key=>$value){
								if($key != "id"){
									if(!$columns['pet']['minerals'][$key]){
										$columns['pet']['minerals'][$key]=$colnum;
										$colnum++;
									}
								}
							}

						}
					}
				}

				$x++;

			}

			foreach($data['features'] as $feature){

				if($feature['properties']['strat_section_id'] == "" && $feature['properties']['image_basemap'] == ""){

					$other_features = $feature['properties']['other_features'];
					if($other_features){
						foreach($other_features as $other_feature){

							foreach($other_feature as $key=>$value){
								if($key != "id"){
									if(!$columns['other_features'][$key]){
										$columns['other_features'][$key]=$colnum;
										$colnum++;
									}
								}
							}

						}
					}

					$x++;

				}

			}

			foreach($data['features'] as $feature){

				if($feature['properties']['strat_section_id'] == "" && $feature['properties']['image_basemap'] == ""){

					$samples = $feature['properties']['samples'];
					if($samples){
						foreach($samples as $sample){

							foreach($sample as $key=>$value){
								if($key != "id"){
									if(!$columns['samples'][$key]){
										$columns['samples'][$key]=$colnum;
										$colnum++;
									}
								}
							}

						}
					}

					$x++;

				}

			}

			foreach($data['features'] as $feature){

				if($feature['properties']['strat_section_id'] == "" && $feature['properties']['image_basemap'] == ""){

					$trace = $feature['properties']['trace'];
					if($trace){
						foreach($trace as $key=>$value){

							if(!$columns['trace'][$key]){
								$columns['trace'][$key]=$colnum;
								$colnum++;
							}
						}
					}

					$x++;

				}

			}

			foreach($data['features'] as $feature){

				if($feature['properties']['strat_section_id'] == "" && $feature['properties']['image_basemap'] == ""){

					$customfields = $feature['properties']['custom_fields'];
					if($customfields){
						foreach($customfields as $key=>$value){

							if(!$columns['custom_fields'][$key]){
								$columns['custom_fields'][$key]=$colnum;
								$colnum++;
							}
						}
					}

					$x++;

				}

			}

			$colnum=0;

			foreach($columns['spot'] as $key=>$value){

				$this->addHolding(0, $value, $this->shapefile_fix_column_name($key));

				$thiswidth=strlen($key)-1;
				if($thisheader=="Age (Ma)"){$thiswidth="12";}
				if($thiswidth<10){
					$thiswidth=10;
				}

				$colnum++;
			}

			if($columns['orientation']['tabular']){
				foreach($columns['orientation']['tabular'] as $key=>$value){

					$this->addHolding(0, $value, "to_".$this->shapefile_fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;

				}
			}

			if($columns['orientation']['planar']){
				foreach($columns['orientation']['planar'] as $key=>$value){

					$this->addHolding(0, $value, "po_".$this->shapefile_fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;

				}
			}

			if($columns['orientation']['linear']){
				foreach($columns['orientation']['linear'] as $key=>$value){

					$this->addHolding(0, $value, "lo_".$this->shapefile_fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;

				}
			}

			if($columns['pet']['metamorphic']){
				foreach($columns['pet']['metamorphic'] as $key=>$value){

					$this->addHolding(0, $value, "met_".$this->shapefile_fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;

				}
			}

			if($columns['pet']['igneous']){
				foreach($columns['pet']['igneous'] as $key=>$value){

					$this->addHolding(0, $value, "ig_".$this->shapefile_fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;

				}
			}

			if($columns['pet']['minerals']){
				foreach($columns['pet']['igneous'] as $key=>$value){

					$this->addHolding(0, $value, "min_".$this->shapefile_fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;

				}
			}

			if($columns['other_features']){
				foreach($columns['other_features'] as $key=>$value){

					$this->addHolding(0, $value, "other_".$this->shapefile_fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thisheader=="Age (Ma)"){$thiswidth="12";}
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;
				}
			}

			if($columns['samples']){
				foreach($columns['samples'] as $key=>$value){

					$this->addHolding(0, $value, "samp_".$this->shapefile_fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thisheader=="Age (Ma)"){$thiswidth="12";}
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;
				}
			}

			if($columns['trace']){
				foreach($columns['trace'] as $key=>$value){

					$this->addHolding(0, $value, "trace_".$this->shapefile_fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thisheader=="Age (Ma)"){$thiswidth="12";}
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;
				}
			}

			if($columns['custom_fields']){
				foreach($columns['custom_fields'] as $key=>$value){

					$this->addHolding(0, $value, $this->shapefile_fix_column_name($key));

					$thiswidth=strlen($key)-1;
					if($thisheader=="Age (Ma)"){$thiswidth="12";}
					if($thiswidth<10){
						$thiswidth=10;
					}

					$colnum++;
				}
			}

			if($columns['tags']){
				foreach($columns['tags'] as $key=>$value){

					$taglabel = "";
					foreach($this->alltags as $tag){

						if($key == $tag->id){
							$taglabel = "Tag:".$tag->name;
						}
					}

					$this->addHolding(0, $value, $taglabel);

					$colnum++;
				}
			}

			//write data
			$rownum=1;
			foreach($data['features'] as $feature){

				if($feature['properties']['strat_section_id'] == "" && $feature['properties']['image_basemap'] == ""){

					//use geoPHP to get WKT
					$mygeojson=$feature['geometry'];
					$mygeojson=trim(json_encode($mygeojson));

					try {
						$mywkt=geoPHP::load($mygeojson,"json");
						$wkt = $mywkt->out('wkt');
						$geometry=$wkt;
					} catch (Exception $e) {
						$geometry="";
					}

					//use geoPHP to get WKT
					$mygeojson=$feature['original_geometry'];
					$mygeojson=trim(json_encode($mygeojson));

					try {
						$mywkt=geoPHP::load($mygeojson,"json");
						$wkt = $mywkt->out('wkt');
						$original_geometry=$wkt;
					} catch (Exception $e) {
						$original_geometry="";
					}

					$spotid = $feature['properties']['id'];
					$spotname = (string)$feature['properties']['name'];
					$spotdate = $feature['properties']['date'];
					$spotself = $feature['properties']['self'];
					$spotnotes = $feature['properties']['notes'];
					$altitude = $feature['properties']['altitude'];

					//build images here
					//https://strabospot.org/pi/16907249300069;https://strabospot.org/pi/16874590296725
					$foundImages = [];
					$imagesString = "";
					if(count($feature['properties']['images']) > 0){
						foreach($feature['properties']['images'] as $i){
							$foundImages[] = "https://strabospot.org/pi/".$i['id'];
						}
						$imagesString = implode(";", $foundImages);
					}

					$latitude = "";
					$longitude = "";

					if(strtolower(substr($geometry,0,5))=="point"){
						$lonlatgeom=$geometry;
						$lonlatgeom=strtolower($lonlatgeom);
						$lonlatgeom=str_replace("point ","",$lonlatgeom);
						$lonlatgeom=str_replace("(","",$lonlatgeom);
						$lonlatgeom=str_replace(")","",$lonlatgeom);
						$lonlatgeom=explode(" ",$lonlatgeom);
						$longitude=$lonlatgeom[0];
						$latitude=$lonlatgeom[1];
					}

					unset($arrayMultipleData);
					$maxcount = 0;
					$linear_orientation_count = 0;
					$planar_orientation_count = 0;
					$tabular_orientation_count = 0;
					$_3d_structure_count = 0;
					$other_features_count = 0;
					$samples_count = 0;
					$traces_count = 0;
					$metamorphics_count = 0;
					$igneouses_count = 0;
					$minerals_count = 0;
					$tags_count = 0;

					$orientationdatas = $feature['properties']['orientation_data'];
					if($orientationdatas){
						foreach($orientationdatas as $orientationdata){

							if($orientationdata->type=="linear_orientation"){
								foreach($orientationdata as $key=>$value){
									if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
										if($key=="feature_type"){$key="linear_feature_type";}
										$arrayMultipleData['linear'][$linear_orientation_count][$key]=$value;
									}
								}
								$linear_orientation_count++;
							}

							if($orientationdata->type=="planar_orientation"){
								foreach($orientationdata as $key=>$value){
									if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
										if($key=="feature_type"){$key="planar_feature_type";}
										$arrayMultipleData['planar'][$planar_orientation_count][$key]=$value;
									}
								}
								$planar_orientation_count++;
							}

							if($orientationdata->type=="tabular_orientation"){
								foreach($orientationdata as $key=>$value){
									if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
										if($key=="feature_type"){$key="tabular_feature_type";}
										$arrayMultipleData['tabular'][$tabular_orientation_count][$key]=$value;
									}
								}
								$tabular_orientation_count++;
							}

							$associatedorientations = $orientationdata->associated_orientation;
							if($associatedorientations){
								foreach($associatedorientations as $associatedorientation){
									if($associatedorientation->type=="planar_orientation"){
										foreach($associatedorientation as $key=>$value){
											if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
												if($key=="feature_type"){$key="planar_feature_type";}
												$arrayMultipleData['planar'][$planar_orientation_count][$key]=$value;
											}
										}
										$planar_orientation_count++;
									}

									if($associatedorientation->type=="linear_orientation"){
										foreach($associatedorientation as $key=>$value){
											if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
												if($key=="feature_type"){$key="linear_feature_type";}
												$arrayMultipleData['linear'][$linear_orientation_count][$key]=$value;
											}
										}
										$linear_orientation_count++;
									}

									if($associatedorientation->type=="tabular_orientation"){
										foreach($associatedorientation as $key=>$value){
											if($key!="associated_orientation" && $key != "id" && $key != "type" && $key != "" ){
												if($key=="feature_type"){$key="tabular_feature_type";}
												$arrayMultipleData['tabular'][$tabular_orientation_count][$key]=$value;
											}
										}
										$tabular_orientation_count++;
									}
								}
							}
						}
					}

					$metamorphics = $feature['properties']['pet']->metamorphic;
					if($metamorphics){
						foreach($metamorphics as $metamorphic){

							foreach($metamorphic as $key=>$value){
								if($key != "id" && $key != "" ){
									$arrayMultipleData['metamorphic'][$metamorphics_count][$key]=$value;
								}
							}
							$metamorphics_count++;
						}
					}

					$igneouses = $feature['properties']['pet']->igneous;
					if($igneouses){
						foreach($igneouses as $igneous){

							foreach($igneous as $key=>$value){
								if($key != "id" && $key != "" ){
									$arrayMultipleData['igneous'][$igneouses_count][$key]=$value;
								}
							}
							$igneouses_count++;
						}
					}

					$minerals = $feature['properties']['pet']->minerals;
					if($minerals){
						foreach($minerals as $mineral){

							foreach($mineral as $key=>$value){
								if($key != "id" && $key != "" ){
									$arrayMultipleData['mineral'][$minerals_count][$key]=$value;
								}
							}
							$minerals_count++;
						}
					}

					$other_features = $feature['properties']['other_features'];
					if($other_features){
						foreach($other_features as $other_feature){
							foreach($other_feature as $key=>$value){
								if($key != "id"){
									$arrayMultipleData['other_features'][$other_features_count][$key]=$value;
								}
							}
							$other_features_count++;
						}
					}

					$samples = $feature['properties']['samples'];
					if($samples){
						foreach($samples as $sample){
							foreach($sample as $key=>$value){
								if($key != "id"){
									$arrayMultipleData['samples'][$samples_count][$key]=$value;
								}
							}
							$samples_count++;
						}
					}

					$trace = $feature['properties']['trace'];
					if($trace){
						foreach($trace as $key=>$value){
							$arrayMultipleData['trace'][$traces_count][$key]=$value;
						}
						$traces_count++;
					}

					if($linear_orientation_count > $maxcount) $maxcount = $linear_orientation_count;
					if($planar_orientation_count > $maxcount) $maxcount = $planar_orientation_count;
					if($tabular_orientation_count > $maxcount) $maxcount = $tabular_orientation_count;
					if($other_features_count > $maxcount) $maxcount = $other_features_count;
					if($samples_count > $maxcount) $maxcount = $samples_count;
					if($traces_count > $maxcount) $maxcount = $traces_count;

					if($maxcount==0) $maxcount = 1;

					for($spotrepeat = 0; $spotrepeat < $maxcount; $spotrepeat ++){

						//write out each row here
						$this->addHolding($rownum,0, $spotname);
						$this->addHolding($rownum,1, $spotdate);
						$this->addHolding($rownum,2, $spotself);
						$this->addHolding($rownum,3,$spotnotes);
						$this->addHolding($rownum,4,$geometry);

						$this->addHolding($rownum,5,$imagesString);

						//JMA 20230730
						//}

						$this->addHolding($rownum,6,$latitude);
						$this->addHolding($rownum,7,$longitude);
						$this->addHolding($rownum,8,$altitude);

						foreach($columns as $key=>$value){
							if($key!="spot"){
								if($key == "orientation" || $key == "pet"){
									foreach($value as $otype=>$ors){
										foreach($ors as $orkey=>$orval){

											$rawVal = $arrayMultipleData[$otype][$spotrepeat][$orkey];
											if(is_array($rawVal)){
												$showVal = implode(", ", $rawVal);
											}else{
												$showVal = $rawVal;
											}

											$this->addHolding($rownum,$orval,$showVal);
										}
									}
								}else{
									foreach($value as $newkey=>$column){
										$this->addHolding($rownum,$column,$arrayMultipleData[$key][$spotrepeat][$newkey]);
									}
								}
							}
						}

						foreach($columns as $key=>$value){
							if($key=="tags"){
								//determine if the spot belongs in the tag and mark X if it is
								foreach($value as $tagid=>$colnum){
									foreach($this->alltags as $tag){
										if($tagid == $tag->id){

											if(in_array($spotid, $tag->spots)){
												$this->addHolding($rownum,$colnum,"X");
											}

											if($tag->features != null){
												foreach($tag->features as $snum=>$other){
													if($snum == $spotid){
														$this->addHolding($rownum,$colnum,"X");
													}
												}
											}

										}
									}
								}
							}
						}

						$rownum++;
					}

				}//end foreach feature

			}

		}

		$pointjson = "";
		$linejson = "";
		$polygonjson = "";

		$fields = $this->holdings[0];

		//Point
		$features = [];
		for($x=1; $x<count($this->holdings); $x++){
			$row = $this->holdings[$x];
			$geometry = $row[4];
			if(substr($geometry,0,5)=="POINT" || substr($geometry,0,10)=="MULTIPOINT"){
				$thisFeature = new stdClass();
				$thisFeature->type = "Feature";
				$mywkt=geoPHP::load($geometry,"wkt");
				$thisgeometry = json_decode($mywkt->out('json'));
				$thisFeature->geometry = $thisgeometry;
				$properties = [];
				for($col = 0; $col < count($fields); $col++){
					eval("\$properties['".$fields[$col]."'] = \$row[$col];");
				}
				$thisFeature->properties = $properties;
				$features[] = $thisFeature;
			}
		}
		if(count($features) > 0){
			$featureCollection = new stdClass();
			$featureCollection->type = "FeatureCollection";
			$featureCollection->features = $features;
			$pointjson = json_encode($featureCollection, JSON_PRETTY_PRINT);
		}

		//Line
		$features = [];
		for($x=1; $x<count($this->holdings); $x++){
			$row = $this->holdings[$x];
			$geometry = $row[4];
			if(substr($geometry,0,10)=="LINESTRING"){
				$thisFeature = new stdClass();
				$thisFeature->type = "Feature";
				$mywkt=geoPHP::load($geometry,"wkt");
				$thisgeometry = json_decode($mywkt->out('json'));
				$thisFeature->geometry = $thisgeometry;
				$properties = [];
				for($col = 0; $col < count($fields); $col++){
					eval("\$properties['".$fields[$col]."'] = \$row[$col];");
				}
				$thisFeature->properties = $properties;
				$features[] = $thisFeature;
			}
		}

		if(count($features) > 0){
			$featureCollection = new stdClass();
			$featureCollection->type = "FeatureCollection";
			$featureCollection->features = $features;
			$linejson = json_encode($featureCollection, JSON_PRETTY_PRINT);
		}

		//Polygon
		$features = [];
		for($x=1; $x<count($this->holdings); $x++){
			$row = $this->holdings[$x];
			$geometry = $row[4];
			if(substr($geometry,0,7)=="POLYGON"){
				$thisFeature = new stdClass();
				$thisFeature->type = "Feature";
				$mywkt=geoPHP::load($geometry,"wkt");
				$thisgeometry = json_decode($mywkt->out('json'));
				$thisFeature->geometry = $thisgeometry;
				$properties = [];
				for($col = 0; $col < count($fields); $col++){
					eval("\$properties['".$fields[$col]."'] = \$row[$col];");
				}
				$thisFeature->properties = $properties;
				$features[] = $thisFeature;
			}
		}
		if(count($features) > 0){
			$featureCollection = new stdClass();
			$featureCollection->type = "FeatureCollection";
			$featureCollection->features = $features;
			$polygonjson = json_encode($featureCollection, JSON_PRETTY_PRINT);
		}

		if($polygonjson!="" || $pointjson!="" || $linejson!=""){

			$randnum=$this->strabo->db->get_var("select nextval('file_seq')");

			//make directory in ogrtemp to hold data
			mkdir("ogrtemp/$randnum");
			mkdir("ogrtemp/$randnum/data");

			if($polygonjson!=""){
				file_put_contents("ogrtemp/$randnum/polygon.json", $polygonjson);
				exec("ogr2ogr -nlt POLYGON -f \"ESRI Shapefile\" -skipfailures ogrtemp/$randnum/data/polygons.shp ogrtemp/$randnum/polygon.json 2>&1",$results);
			}

			if($linejson!=""){
				file_put_contents("ogrtemp/$randnum/line.json", $linejson);
				exec("ogr2ogr -nlt LINESTRING -f \"ESRI Shapefile\" -skipfailures ogrtemp/$randnum/data/lines.shp ogrtemp/$randnum/line.json 2>&1",$results);
				unlink("ogrtemp/$randnum/line.json");
			}

			if($pointjson!=""){
				file_put_contents("ogrtemp/$randnum/point.json", $pointjson);
				exec("ogr2ogr -nlt POINT -f \"ESRI Shapefile\" -skipfailures ogrtemp/$randnum/data/points.shp ogrtemp/$randnum/point.json 2>&1",$results);
				unlink("ogrtemp/$randnum/point.json");
			}

			//create zip file
			exec("zip -j ogrtemp/$randnum/strabo$randnum.zip ogrtemp/$randnum/data/* 2>&1",$results);

			//force download of file
			header("Content-Type: application/zip");
			header("Content-Disposition: attachment; filename=search_download.zip");
			header("Content-Length: " . filesize("ogrtemp/$randnum/strabo$randnum.zip"));

			readfile("ogrtemp/$randnum/strabo$randnum.zip");

			//remove temp directory
			if($randnum!=""){
			}

		}else{
			echo "No data found for this dataset.";
		}

	}

	public function bkupshapefileOut20240531(){

		if($this->get['dsids']!=""){

			$dsids=$this->get['dsids'];
			$this->alltags = $this->strabo->getTagsFromDatasetIds($dsids);

			$polygonjson = $this->strabo->getDatasetSpotsSearch('polygon',$this->get);
			if($polygonjson!=""){

				$newjson = array();
				$newjson['type']="FeatureCollection";
				$features = array();
				foreach($polygonjson['features'] as $spot){
					$spot = $this->fixSpot($spot);
					$features[]=$spot;
				}

				$newjson['features']=$features;
				$polygonjson = json_encode($newjson,JSON_PRETTY_PRINT);
			}

			$pointjson = $this->strabo->getDatasetSpotsSearch('point',$this->get);
			if($pointjson!=""){
				$newjson = array();
				$newjson['type']="FeatureCollection";
				$features = array();
				foreach($pointjson['features'] as $spot){
					$spot = $this->fixSpot($spot);
					$features[]=$spot;
				}

				$newjson['features']=$features;
				$pointjson = json_encode($newjson,JSON_PRETTY_PRINT);
			}

			$linejson = $this->strabo->getDatasetSpotsSearch('line',$this->get);
			if($linejson!=""){

				$newjson = array();
				$newjson['type']="FeatureCollection";
				$features = array();
				foreach($linejson['features'] as $spot){
					$spot = $this->fixSpot($spot);
					$features[]=$spot;
				}

				$newjson['features']=$features;
				$linejson = json_encode($newjson,JSON_PRETTY_PRINT);
			}

			if($polygonjson!="" || $pointjson!="" || $linejson!=""){

				$randnum=$this->strabo->db->get_var("select nextval('file_seq')");

				//make directory in ogrtemp to hold data
				mkdir("ogrtemp/$randnum");
				mkdir("ogrtemp/$randnum/data");

				if($polygonjson!=""){
					file_put_contents("ogrtemp/$randnum/polygon.json", $polygonjson);
					exec("ogr2ogr -nlt POLYGON -f \"ESRI Shapefile\" -skipfailures ogrtemp/$randnum/data/polygons.shp ogrtemp/$randnum/polygon.json 2>&1",$results);
				}

				if($linejson!=""){
					file_put_contents("ogrtemp/$randnum/line.json", $linejson);
					exec("ogr2ogr -nlt LINESTRING -f \"ESRI Shapefile\" -skipfailures ogrtemp/$randnum/data/lines.shp ogrtemp/$randnum/line.json 2>&1",$results);
					unlink("ogrtemp/$randnum/line.json");
				}

				if($pointjson!=""){
					file_put_contents("ogrtemp/$randnum/point.json", $pointjson);
					exec("ogr2ogr -nlt POINT -f \"ESRI Shapefile\" -skipfailures ogrtemp/$randnum/data/points.shp ogrtemp/$randnum/point.json 2>&1",$results);
					unlink("ogrtemp/$randnum/point.json");
				}

				//create zip file
				exec("zip -j ogrtemp/$randnum/strabo$randnum.zip ogrtemp/$randnum/data/* 2>&1",$results);

				//force download of file
				header("Content-Type: application/zip");
				header("Content-Disposition: attachment; filename=search_download.zip");
				header("Content-Length: " . filesize("ogrtemp/$randnum/strabo$randnum.zip"));

				readfile("ogrtemp/$randnum/strabo$randnum.zip");

				//remove temp directory
				if($randnum!=""){
				}

			}else{
				echo "No data found for this dataset.";
			}

		}

	}

	/**
	 * Build a SimpleMarker layer XML element for QGIS QML styles.
	 *
	 * @param string $shape  SimpleMarker shape name (line, circle, diamond, filled_arrowhead, etc.)
	 * @param string $size   Marker size in MM
	 * @param array  $opts   Optional overrides: color, outlineColor, outlineWidth, outlineStyle,
	 *                       rotationExpr, vAnchor, hAnchor, joinstyle, angle
	 * @return string XML for one <layer> element
	 */
	private function qmlMarkerLayer($shape, $size, $opts = []){
		$color        = $opts['color']        ?? '0,0,0,255';
		$outlineColor = $opts['outlineColor'] ?? '0,0,0,255';
		$outlineWidth = $opts['outlineWidth'] ?? '0.5';
		$outlineStyle = $opts['outlineStyle'] ?? 'solid';
		$vAnchor      = $opts['vAnchor']      ?? '1';
		$hAnchor      = $opts['hAnchor']      ?? '1';
		$angle        = $opts['angle']        ?? '0';
		$rotationExpr = $opts['rotationExpr'] ?? null;
		$joinstyle    = $opts['joinstyle']    ?? null;

		$joinstyleOpt = $joinstyle
			? '<Option value="' . $joinstyle . '" name="joinstyle" type="QString"/>' . "\n            "
			: '';

		if($rotationExpr !== null){
			$esc = htmlspecialchars($rotationExpr, ENT_XML1 | ENT_COMPAT, 'UTF-8');
			$ddProps = <<<XML
          <data_defined_properties>
            <Option type="Map">
              <Option value="" name="name" type="QString"/>
              <Option name="properties" type="Map">
                <Option name="angle" type="Map">
                  <Option value="true" name="active" type="bool"/>
                  <Option value="$esc" name="expression" type="QString"/>
                  <Option value="3" name="type" type="int"/>
                </Option>
              </Option>
              <Option value="collection" name="type" type="QString"/>
            </Option>
          </data_defined_properties>
XML;
		}else{
			$ddProps = <<<XML
          <data_defined_properties>
            <Option type="Map">
              <Option value="" name="name" type="QString"/>
              <Option name="properties"/>
              <Option value="collection" name="type" type="QString"/>
            </Option>
          </data_defined_properties>
XML;
		}

		return <<<XML
        <layer locked="0" class="SimpleMarker" pass="0" enabled="1">
          <Option type="Map">
            <Option value="$angle" name="angle" type="QString"/>
            <Option value="$color" name="color" type="QString"/>
            <Option value="$hAnchor" name="horizontal_anchor_point" type="QString"/>
            $joinstyleOpt<Option value="$shape" name="name" type="QString"/>
            <Option value="0,0" name="offset" type="QString"/>
            <Option value="3x:0,0,0,0,0,0" name="offset_map_unit_scale" type="QString"/>
            <Option value="MM" name="offset_unit" type="QString"/>
            <Option value="$outlineColor" name="outline_color" type="QString"/>
            <Option value="$outlineStyle" name="outline_style" type="QString"/>
            <Option value="$outlineWidth" name="outline_width" type="QString"/>
            <Option value="3x:0,0,0,0,0,0" name="outline_width_map_unit_scale" type="QString"/>
            <Option value="MM" name="outline_width_unit" type="QString"/>
            <Option value="diameter" name="scale_method" type="QString"/>
            <Option value="$size" name="size" type="QString"/>
            <Option value="3x:0,0,0,0,0,0" name="size_map_unit_scale" type="QString"/>
            <Option value="MM" name="size_unit" type="QString"/>
            <Option value="$vAnchor" name="vertical_anchor_point" type="QString"/>
          </Option>
$ddProps
        </layer>
XML;
	}

	/**
	 * Build a GeometryGenerator layer that draws lines from a QGIS expression.
	 * The expression should return a line or multi-line geometry in MM units.
	 * Used for complex symbols like contact (ladder) and shear zone (wavy).
	 *
	 * Note: In GeometryGenerator with MM units, project($geometry, dist, angle) uses
	 * angle in radians measured counterclockwise from east. To project at compass
	 * bearing B, use: project($geometry, dist, radians(-B))
	 */
	private function qmlGeometryGeneratorLayer($expression, $opts = []){
		$lineWidth = $opts['lineWidth'] ?? '0.5';
		$lineColor = $opts['lineColor'] ?? '0,0,0,255';

		$esc = htmlspecialchars($expression, ENT_XML1 | ENT_COMPAT, 'UTF-8');

		return <<<XML
        <layer locked="0" class="GeometryGenerator" pass="0" enabled="1">
          <Option type="Map">
            <Option value="Line" name="SymbolType" type="QString"/>
            <Option value="$esc" name="geometryModifier" type="QString"/>
            <Option value="MM" name="units" type="QString"/>
          </Option>
          <data_defined_properties>
            <Option type="Map">
              <Option value="" name="name" type="QString"/>
              <Option name="properties"/>
              <Option value="collection" name="type" type="QString"/>
            </Option>
          </data_defined_properties>
          <symbol name="@@geom" force_rhr="0" type="line" clip_to_extent="1" is_animated="0" frame_rate="10" alpha="1">
            <data_defined_properties>
              <Option type="Map">
                <Option value="" name="name" type="QString"/>
                <Option name="properties"/>
                <Option value="collection" name="type" type="QString"/>
              </Option>
            </data_defined_properties>
            <layer locked="0" class="SimpleLine" pass="0" enabled="1">
              <Option type="Map">
                <Option value="0" name="align_dash_pattern" type="QString"/>
                <Option value="square" name="capstyle" type="QString"/>
                <Option value="$lineColor" name="line_color" type="QString"/>
                <Option value="solid" name="line_style" type="QString"/>
                <Option value="$lineWidth" name="line_width" type="QString"/>
                <Option value="MM" name="line_width_unit" type="QString"/>
              </Option>
              <data_defined_properties>
                <Option type="Map">
                  <Option value="" name="name" type="QString"/>
                  <Option name="properties"/>
                  <Option value="collection" name="type" type="QString"/>
                </Option>
              </data_defined_properties>
            </layer>
          </symbol>
        </layer>
XML;
	}

	/**
	 * Wrap marker layers in a QGIS symbol element.
	 */
	private function qmlSymbol($name, $layersXml){
		return <<<XML
      <symbol name="$name" force_rhr="0" type="marker" clip_to_extent="1" is_animated="0" frame_rate="10" alpha="1">
        <data_defined_properties>
          <Option type="Map">
            <Option value="" name="name" type="QString"/>
            <Option name="properties"/>
            <Option value="collection" name="type" type="QString"/>
          </Option>
        </data_defined_properties>
$layersXml
      </symbol>
XML;
	}

	/**
	 * Generate QML style XML for orientation symbology in QGIS.
	 * Rule-based renderer matching the StraboField mobile app's symbol logic.
	 *
	 * QGIS SimpleMarker rotation conventions (determined empirically):
	 *   - "line" at angle=0: vertical (N-S). Rotation is clockwise.
	 *   - "filled_arrowhead" at angle=0: points RIGHT (East). Rotation is clockwise.
	 *   - For dip direction (strike+90), use po_strike directly as the arrowhead angle
	 *     because arrowhead default (0°=East) is already 90° offset from line default (0°=vertical).
	 *   - For a one-sided tick extending in the dip direction: use a "line" marker with
	 *     vertical_anchor_point=0 (top at point) and rotation = po_strike - 90.
	 */
	private function generateOrientationQml($layerName){

		// ----------------------------------------------------------------
		// Rules: evaluated top-to-bottom, first match wins.
		// Maps each orientation type/feature_type/dip combo to a symbol index.
		// ----------------------------------------------------------------
		// IMPORTANT: QGIS RuleRenderer applies ALL matching rules (not first-match-wins).
		// Every rule must be mutually exclusive to avoid overlapping symbols.
		// The lineation rule excludes spots that also have planar data (those get the planar symbol).
		$rules = [
			// Bedding variants (overturned checked first)
			['key' => 'bed_ot',  'filter' => "po_facing = 'overturned' AND po_feature_type = 'bedding'",     'label' => 'Bedding (overturned)',  'symbol' => '0'],
			['key' => 'bed_hz',  'filter' => "po_feature_type = 'bedding' AND po_dip = 0",                   'label' => 'Bedding (horizontal)',  'symbol' => '1'],
			['key' => 'bed_vt',  'filter' => "po_feature_type = 'bedding' AND po_dip = 90",                  'label' => 'Bedding (vertical)',    'symbol' => '2'],
			['key' => 'bed_in',  'filter' => "po_feature_type = 'bedding' AND po_dip > 0 AND po_dip < 90",   'label' => 'Bedding (inclined)',    'symbol' => '3'],
			// Foliation variants
			['key' => 'fol_hz',  'filter' => "po_feature_type = 'foliation' AND po_dip = 0",                 'label' => 'Foliation (horizontal)','symbol' => '4'],
			['key' => 'fol_vt',  'filter' => "po_feature_type = 'foliation' AND po_dip = 90",                'label' => 'Foliation (vertical)',  'symbol' => '5'],
			['key' => 'fol_in',  'filter' => "po_feature_type = 'foliation' AND po_dip > 0 AND po_dip < 90", 'label' => 'Foliation (inclined)',  'symbol' => '6'],
			// Contact variants
			['key' => 'cnt_vt',  'filter' => "po_feature_type = 'contact' AND po_dip = 90",                  'label' => 'Contact (vertical)',    'symbol' => '7'],
			['key' => 'cnt_in',  'filter' => "po_feature_type = 'contact' AND po_dip > 0 AND po_dip < 90",   'label' => 'Contact (inclined)',    'symbol' => '8'],
			// Shear zone variants
			['key' => 'sz_vt',   'filter' => "po_feature_type = 'shear_zone' AND po_dip = 90",               'label' => 'Shear zone (vertical)', 'symbol' => '9'],
			['key' => 'sz_in',   'filter' => "po_feature_type = 'shear_zone' AND po_dip > 0 AND po_dip < 90",'label' => 'Shear zone (inclined)', 'symbol' => '10'],
			// Features with no dip-dependent variant
			['key' => 'fault',   'filter' => "po_feature_type = 'fault'",                                    'label' => 'Fault',                 'symbol' => '11'],
			['key' => 'frac',    'filter' => "po_feature_type = 'fracture'",                                  'label' => 'Fracture',              'symbol' => '12'],
			['key' => 'vein',    'filter' => "po_feature_type = 'vein'",                                      'label' => 'Vein',                  'symbol' => '13'],
			// Linear orientation (only when no planar data — if both exist, planar rule handles it)
			['key' => 'linear',  'filter' => "lo_trend IS NOT NULL AND po_strike IS NULL",                    'label' => 'Lineation',             'symbol' => '14'],
			// Fallback for unmapped planar types (e.g., joint, fold_axial_surface, other)
			['key' => 'planar',  'filter' => "po_strike IS NOT NULL AND po_feature_type != 'bedding' AND po_feature_type != 'foliation' AND po_feature_type != 'contact' AND po_feature_type != 'shear_zone' AND po_feature_type != 'fault' AND po_feature_type != 'fracture' AND po_feature_type != 'vein'", 'label' => 'Other planar', 'symbol' => '15'],
			// Tabular zone orientations — use to_strike/to_feature_type columns
			['key' => 'tab_sz',  'filter' => "to_feature_type = 'shear_zone' AND to_strike IS NOT NULL",     'label' => 'Tabular shear zone',    'symbol' => '17'],
			['key' => 'tab_oth', 'filter' => "to_strike IS NOT NULL AND po_strike IS NULL AND to_feature_type != 'shear_zone'", 'label' => 'Tabular zone', 'symbol' => '18'],
			// Plain point (no orientation data at all)
			['key' => 'plain',   'filter' => "po_strike IS NULL AND lo_trend IS NULL AND to_strike IS NULL",  'label' => 'Plain point',           'symbol' => '16'],
		];

		$rulesXml = '';
		foreach($rules as $r){
			$f = htmlspecialchars($r['filter'], ENT_XML1 | ENT_COMPAT, 'UTF-8');
			$l = htmlspecialchars($r['label'],  ENT_XML1 | ENT_COMPAT, 'UTF-8');
			$rulesXml .= '      <rule key="{' . $r['key'] . '}" filter="' . $f . '" label="' . $l . '" symbol="' . $r['symbol'] . '"/>' . "\n";
		}

		// ----------------------------------------------------------------
		// Symbols: one per rule (17 total). Each rule gets its own symbol
		// to avoid QGIS rendering quirks with shared symbol references.
		// ----------------------------------------------------------------

		// Common marker layer snippets
		$strikeLine = $this->qmlMarkerLayer('line', '8', [
			'outlineWidth' => '0.5',
			'rotationExpr' => '"po_strike"',
		]);

		$strikeLineForTrend = $this->qmlMarkerLayer('line', '6', [
			'outlineWidth' => '0.5',
			'rotationExpr' => '"lo_trend"',
		]);

		$arrowheadLayer = $this->qmlMarkerLayer('filled_arrowhead', '3', [
			'outlineWidth' => '0',
			'joinstyle'    => 'bevel',
			'hAnchor'      => '0',
			'rotationExpr' => '"po_strike"',
		]);

		$dipTickLayer = $this->qmlMarkerLayer('line', '2.5', [
			'outlineWidth' => '0.5',
			'vAnchor'      => '0',
			'rotationExpr' => '"po_strike" - 90',
		]);

		$centeredPerpLayer = $this->qmlMarkerLayer('line', '3', [
			'outlineWidth' => '0.5',
			'rotationExpr' => '"po_strike" + 90',
		]);

		// Symbol builders for each visual type
		$makeArrowhead   = function($idx) use ($strikeLine, $arrowheadLayer)    { return $this->qmlSymbol($idx, $strikeLine . $arrowheadLayer); };
		$makeDipTick     = function($idx) use ($strikeLine, $dipTickLayer)      { return $this->qmlSymbol($idx, $strikeLine . $dipTickLayer); };
		$makeVertPlanar  = function($idx) use ($strikeLine, $centeredPerpLayer) { return $this->qmlSymbol($idx, $strikeLine . $centeredPerpLayer); };

		// --- 0: bedding_overturned — strike line + D-shaped arc on dip side ---
		// GeometryGenerator project() bearing convention (determined empirically):
		//   project($geometry, dist, radians(-B)) projects at compass bearing B.
		// Shortcuts: strike=radians(-S), anti-strike=radians(-(S+180)), dip=radians(-(S+90))
		$overturnedArcExpr = 'smooth(make_line('
			. 'project($geometry, 2, radians(-"po_strike")),'
			. 'project(project($geometry, 1.2, radians(-"po_strike")), 2, radians(-("po_strike"+90))),'
			. 'project($geometry, 2, radians(-("po_strike"+90))),'
			. 'project(project($geometry, 1.2, radians(-("po_strike"+180))), 2, radians(-("po_strike"+90))),'
			. 'project($geometry, 2, radians(-("po_strike"+180)))'
			. '), 3)';
		$sym0 = $this->qmlSymbol('0',
			$strikeLine .
			$this->qmlGeometryGeneratorLayer($overturnedArcExpr)
		);

		// --- 1: bedding_horizontal — circle + cross (no rotation) ---
		$sym1 = $this->qmlSymbol('1',
			$this->qmlMarkerLayer('circle', '6', [
				'color'        => '255,255,255,0',
				'outlineWidth' => '0.5',
			]) .
			$this->qmlMarkerLayer('cross', '6', [
				'outlineWidth' => '0.5',
			])
		);

		$sym2  = $makeVertPlanar('2');   // 2: bedding_vertical
		$sym3  = $makeDipTick('3');      // 3: bedding_inclined

		// --- 4: foliation_horizontal — circle + filled diamond (no rotation) ---
		$sym4 = $this->qmlSymbol('4',
			$this->qmlMarkerLayer('circle', '6', [
				'color'        => '255,255,255,0',
				'outlineWidth' => '0.5',
			]) .
			$this->qmlMarkerLayer('diamond', '2.5', [
				'outlineWidth' => '0',
			])
		);

		// --- 5: foliation_vertical — strike line + filled diamond at center ---
		$sym5 = $this->qmlSymbol('5',
			$strikeLine .
			$this->qmlMarkerLayer('diamond', '2.5', [
				'outlineWidth' => '0',
			])
		);

		$sym6  = $makeArrowhead('6');    // 6: foliation_inclined

		// --- 7: contact_vertical — strike line + 5 short ticks crossing BOTH sides ---
		// 5 ticks at -3, -1.5, 0, +1.5, +3mm along strike. Each 1.5mm per side.
		$contactVertExpr = 'collect_geometries('
			. 'make_line(project($geometry,1.5,radians(-("po_strike"+90))),project($geometry,1.5,radians(-("po_strike"+270)))),'
			. 'make_line(project(project($geometry,1.5,radians(-"po_strike")),1.5,radians(-("po_strike"+90))),project(project($geometry,1.5,radians(-"po_strike")),1.5,radians(-("po_strike"+270)))),'
			. 'make_line(project(project($geometry,1.5,radians(-("po_strike"+180))),1.5,radians(-("po_strike"+90))),project(project($geometry,1.5,radians(-("po_strike"+180))),1.5,radians(-("po_strike"+270)))),'
			. 'make_line(project(project($geometry,3,radians(-"po_strike")),1.5,radians(-("po_strike"+90))),project(project($geometry,3,radians(-"po_strike")),1.5,radians(-("po_strike"+270)))),'
			. 'make_line(project(project($geometry,3,radians(-("po_strike"+180))),1.5,radians(-("po_strike"+90))),project(project($geometry,3,radians(-("po_strike"+180))),1.5,radians(-("po_strike"+270))))'
			. ')';
		$sym7 = $this->qmlSymbol('7',
			$strikeLine .
			$this->qmlGeometryGeneratorLayer($contactVertExpr)
		);

		// --- 8: contact_inclined — strike line + 5 short ticks on DIP side only ---
		// Dip direction in GeometryGenerator: radians(-(po_strike-90)) not radians(-(po_strike+90))
		// because project() with radians(-B) projects at bearing (B+180), so to get dip at
		// bearing (strike+90) we use radians(-(strike+90-180)) = radians(-(strike-90)).
		$contactInclExpr = 'collect_geometries('
			. 'make_line($geometry,project($geometry,2,radians(-("po_strike"-90)))),'
			. 'make_line(project($geometry,1.5,radians(-"po_strike")),project(project($geometry,1.5,radians(-"po_strike")),2,radians(-("po_strike"-90)))),'
			. 'make_line(project($geometry,1.5,radians(-("po_strike"+180))),project(project($geometry,1.5,radians(-("po_strike"+180))),2,radians(-("po_strike"-90)))),'
			. 'make_line(project($geometry,3,radians(-"po_strike")),project(project($geometry,3,radians(-"po_strike")),2,radians(-("po_strike"-90)))),'
			. 'make_line(project($geometry,3,radians(-("po_strike"+180))),project(project($geometry,3,radians(-("po_strike"+180))),2,radians(-("po_strike"-90))))'
			. ')';
		$sym8 = $this->qmlSymbol('8',
			$strikeLine .
			$this->qmlGeometryGeneratorLayer($contactInclExpr)
		);

		// --- 9: shear_zone_vertical — S-curve + centered tick on BOTH sides ---
		// S-curve: endpoints along strike, control points offset to dip/anti-dip sides.
		$szCurveExpr = 'smooth(make_line('
			. 'project($geometry, 4, radians(-"po_strike")),'
			. 'project(project($geometry, 2, radians(-"po_strike")), 2, radians(-("po_strike"-90))),'
			. '$geometry,'
			. 'project(project($geometry, 2, radians(-("po_strike"+180))), 2, radians(-("po_strike"+90))),'
			. 'project($geometry, 4, radians(-("po_strike"+180)))'
			. '), 3)';
		$sym9 = $this->qmlSymbol('9',
			$this->qmlGeometryGeneratorLayer($szCurveExpr) .
			$centeredPerpLayer
		);

		// --- 10: shear_zone_inclined — S-curve + dip tick on one side ---
		$sym10 = $this->qmlSymbol('10',
			$this->qmlGeometryGeneratorLayer($szCurveExpr) .
			$dipTickLayer
		);

		// --- 11: fault — strike line + short shaft + arrowhead ---
		// The fault PNG shows a strike line with a small arrow (shaft + arrowhead) pointing
		// toward the dip side. The shaft is a short line from center to the arrowhead.
		$sym11 = $this->qmlSymbol('11',
			$strikeLine .
			$dipTickLayer .
			$arrowheadLayer
		);

		// --- 12: fracture — strike line + small open square on dip side ---
		$sym12 = $this->qmlSymbol('12',
			$strikeLine .
			$this->qmlMarkerLayer('square', '2', [
				'color'        => '255,255,255,0',
				'outlineWidth' => '0.4',
				'vAnchor'      => '0',
				'rotationExpr' => '"po_strike" - 90',
			])
		);

		// --- 13: vein — strike line + small filled square on dip side ---
		$sym13 = $this->qmlSymbol('13',
			$strikeLine .
			$this->qmlMarkerLayer('square', '2', [
				'outlineWidth' => '0',
				'vAnchor'      => '0',
				'rotationExpr' => '"po_strike" - 90',
			])
		);

		// --- 14: lineation — arrow pointing in trend direction ---
		// Arrowhead base at point, tip toward trend (hAnchor=0).
		// Short shaft extends backward from point (vAnchor=0) to show arrow origin.
		// Shaft kept short (3mm) to minimize overlap with other symbols at same location.
		// Uses GeometryGenerator so arrowhead is at the TIP, not the base.
		// Bearing formula: radians(180 - B) projects at compass bearing B.
		$lineationExpr = 'collect_geometries('
			. 'make_line($geometry, project($geometry, 5, radians(180-"lo_trend"))),'
			. 'make_line(project($geometry, 5, radians(180-"lo_trend")), project(project($geometry, 5, radians(180-"lo_trend")), 1.5, radians(30-"lo_trend"))),'
			. 'make_line(project($geometry, 5, radians(180-"lo_trend")), project(project($geometry, 5, radians(180-"lo_trend")), 1.5, radians(330-"lo_trend")))'
			. ')';
		$sym14 = $this->qmlSymbol('14',
			$this->qmlGeometryGeneratorLayer($lineationExpr)
		);

		// --- 15: other_planar (fallback) — plain dot for unmapped types ---
		// Mobile app renders unmapped feature_types as default_point (circle)
		$sym15 = $this->qmlSymbol('15',
			$this->qmlMarkerLayer('circle', '2.5', [
				'outlineWidth' => '0',
			])
		);

		// --- 16: plain — filled black circle ---
		$sym16 = $this->qmlSymbol('16',
			$this->qmlMarkerLayer('circle', '2.5', [
				'outlineWidth' => '0',
			])
		);

		// --- 17: tabular_shear_zone — S-curve using to_strike (same visual as shear zone) ---
		$szCurveExprTab = str_replace('"po_strike"', '"to_strike"', $szCurveExpr);
		$sym17 = $this->qmlSymbol('17',
			$this->qmlGeometryGeneratorLayer($szCurveExprTab) .
			$this->qmlMarkerLayer('line', '2.5', [
				'outlineWidth' => '0.5',
				'vAnchor'      => '0',
				'rotationExpr' => '"to_strike" - 90',
			])
		);

		// --- 18: tabular_other — strike line rotated by to_strike + tick ---
		$sym18 = $this->qmlSymbol('18',
			$this->qmlMarkerLayer('line', '8', [
				'outlineWidth' => '0.5',
				'rotationExpr' => '"to_strike"',
			]) .
			$this->qmlMarkerLayer('line', '2.5', [
				'outlineWidth' => '0.5',
				'vAnchor'      => '0',
				'rotationExpr' => '"to_strike" - 90',
			])
		);

		$symbolsXml = $sym0 . $sym1 . $sym2 . $sym3 . $sym4 . $sym5 . $sym6
			. $sym7 . $sym8 . $sym9 . $sym10 . $sym11 . $sym12 . $sym13
			. $sym14 . $sym15 . $sym16 . $sym17 . $sym18;

		// ----------------------------------------------------------------
		// Labeling: show dip or plunge value next to oriented spots.
		// ----------------------------------------------------------------
		$labelExpr = htmlspecialchars('coalesce("po_dip", "lo_plunge")', ENT_XML1 | ENT_COMPAT, 'UTF-8');

		$qml = <<<QML
<!DOCTYPE qgis PUBLIC 'http://mrcc.com/qgis.dtd' 'SYSTEM'>
<qgis simplifyDrawingHints="0" version="3.28.11-Firenze" styleCategories="AllStyleCategories" labelsEnabled="1">
  <renderer-v2 type="RuleRenderer" symbollevels="0" forceraster="0" enableorderby="0">
    <rules key="{root}">
$rulesXml    </rules>
    <symbols>
$symbolsXml    </symbols>
  </renderer-v2>
  <labeling type="simple">
    <settings calloutType="simple">
      <text-style allowHtml="0" capitalization="0" textOpacity="1" fontItalic="0" forcedItalic="0" blendMode="0" isExpression="1" legendString="Aa" fontUnderline="0" forcedBold="0" fieldName="$labelExpr" fontWeight="75" fontLetterSpacing="0" fontWordSpacing="0" multilineHeight="1" fontSizeMapUnitScale="3x:0,0,0,0,0,0" fontKerning="1" fontSize="8" multilineHeightUnit="Percentage" namedStyle="Bold" fontSizeUnit="Point" fontFamily="Sans Serif" previewBkgrdColor="255,255,255,255" textColor="0,0,0,255" useSubstitutions="0" textOrientation="horizontal" fontStrikeout="0">
        <families/>
        <text-buffer bufferJoinStyle="128" bufferSizeMapUnitScale="3x:0,0,0,0,0,0" bufferSize="1" bufferSizeUnits="MM" bufferOpacity="1" bufferDraw="1" bufferColor="255,255,255,255" bufferNoFill="1" bufferBlendMode="0"/>
        <text-mask maskJoinStyle="128" maskSize="1.5" maskEnabled="0" maskSizeMapUnitScale="3x:0,0,0,0,0,0" maskedSymbolLayers="" maskSizeUnits="MM" maskType="0" maskOpacity="1"/>
        <background shapeDraw="0" shapeType="0" shapeSizeType="0" shapeSizeX="0" shapeSizeY="0" shapeSizeUnit="MM" shapeRotationType="0" shapeRotation="0" shapeOffsetX="0" shapeOffsetY="0" shapeOffsetUnit="MM" shapeRadiiX="0" shapeRadiiY="0" shapeRadiiUnit="MM" shapeFillColor="255,255,255,255" shapeBorderColor="128,128,128,255" shapeBorderWidth="0" shapeBorderWidthUnit="MM" shapeOpacity="1" shapeBlendMode="0" shapeJoinStyle="64" shapeSVGFile="" shapeSizeMapUnitScale="3x:0,0,0,0,0,0" shapeOffsetMapUnitScale="3x:0,0,0,0,0,0" shapeRadiiMapUnitScale="3x:0,0,0,0,0,0" shapeBorderWidthMapUnitScale="3x:0,0,0,0,0,0">
          <symbol name="markerSymbol" force_rhr="0" type="marker" clip_to_extent="1" is_animated="0" frame_rate="10" alpha="1">
            <data_defined_properties>
              <Option type="Map">
                <Option value="" name="name" type="QString"/>
                <Option name="properties"/>
                <Option value="collection" name="type" type="QString"/>
              </Option>
            </data_defined_properties>
            <layer locked="0" class="SimpleMarker" pass="0" enabled="1">
              <Option type="Map">
                <Option value="0" name="angle" type="QString"/>
                <Option value="square" name="cap_style" type="QString"/>
                <Option value="145,82,45,255" name="color" type="QString"/>
                <Option value="1" name="horizontal_anchor_point" type="QString"/>
                <Option value="bevel" name="joinstyle" type="QString"/>
                <Option value="circle" name="name" type="QString"/>
                <Option value="0,0" name="offset" type="QString"/>
                <Option value="3x:0,0,0,0,0,0" name="offset_map_unit_scale" type="QString"/>
                <Option value="MM" name="offset_unit" type="QString"/>
                <Option value="35,35,35,255" name="outline_color" type="QString"/>
                <Option value="solid" name="outline_style" type="QString"/>
                <Option value="0" name="outline_width" type="QString"/>
                <Option value="3x:0,0,0,0,0,0" name="outline_width_map_unit_scale" type="QString"/>
                <Option value="MM" name="outline_width_unit" type="QString"/>
                <Option value="diameter" name="scale_method" type="QString"/>
                <Option value="2" name="size" type="QString"/>
                <Option value="3x:0,0,0,0,0,0" name="size_map_unit_scale" type="QString"/>
                <Option value="MM" name="size_unit" type="QString"/>
                <Option value="1" name="vertical_anchor_point" type="QString"/>
              </Option>
              <data_defined_properties>
                <Option type="Map">
                  <Option value="" name="name" type="QString"/>
                  <Option name="properties"/>
                  <Option value="collection" name="type" type="QString"/>
                </Option>
              </data_defined_properties>
            </layer>
          </symbol>
          <symbol name="fillSymbol" force_rhr="0" type="fill" clip_to_extent="1" is_animated="0" frame_rate="10" alpha="1">
            <data_defined_properties>
              <Option type="Map">
                <Option value="" name="name" type="QString"/>
                <Option name="properties"/>
                <Option value="collection" name="type" type="QString"/>
              </Option>
            </data_defined_properties>
            <layer locked="0" class="SimpleFill" pass="0" enabled="1">
              <Option type="Map">
                <Option value="3x:0,0,0,0,0,0" name="border_width_map_unit_scale" type="QString"/>
                <Option value="255,255,255,255" name="color" type="QString"/>
                <Option value="bevel" name="joinstyle" type="QString"/>
                <Option value="0,0" name="offset" type="QString"/>
                <Option value="3x:0,0,0,0,0,0" name="offset_map_unit_scale" type="QString"/>
                <Option value="MM" name="offset_unit" type="QString"/>
                <Option value="128,128,128,255" name="outline_color" type="QString"/>
                <Option value="no" name="outline_style" type="QString"/>
                <Option value="0" name="outline_width" type="QString"/>
                <Option value="MM" name="outline_width_unit" type="QString"/>
                <Option value="solid" name="style" type="QString"/>
              </Option>
              <data_defined_properties>
                <Option type="Map">
                  <Option value="" name="name" type="QString"/>
                  <Option name="properties"/>
                  <Option value="collection" name="type" type="QString"/>
                </Option>
              </data_defined_properties>
            </layer>
          </symbol>
        </background>
        <shadow shadowDraw="0" shadowOffsetDist="1" shadowOffsetUnit="MM" shadowOffsetAngle="135" shadowOffsetGlobal="1" shadowRadius="1.5" shadowRadiusUnit="MM" shadowOpacity="0.7" shadowScale="100" shadowColor="0,0,0,255" shadowBlendMode="6" shadowUnder="0" shadowRadiusAlphaOnly="0" shadowRadiusMapUnitScale="3x:0,0,0,0,0,0" shadowOffsetMapUnitScale="3x:0,0,0,0,0,0"/>
        <dd_properties>
          <Option type="Map">
            <Option value="" name="name" type="QString"/>
            <Option name="properties"/>
            <Option value="collection" name="type" type="QString"/>
          </Option>
        </dd_properties>
        <substitutions/>
      </text-style>
      <text-format placeDirectionSymbol="0" leftDirectionSymbol="&lt;" plussign="0" multilineAlign="3" addDirectionSymbol="0" decimals="3" useMaxLineLengthForAutoWrap="1" reverseDirectionSymbol="0" formatNumbers="0" wrapChar="" autoWrapLength="0" rightDirectionSymbol="&gt;"/>
      <placement overrunDistanceMapUnitScale="3x:0,0,0,0,0,0" polygonPlacementFlags="2" geometryGeneratorType="PointGeometry" fitInPolygonOnly="0" placementFlags="10" centroidWhole="0" repeatDistanceMapUnitScale="3x:0,0,0,0,0,0" overlapHandling="PreventOverlap" offsetUnits="MM" predefinedPositionOrder="TR,TL,BR,BL,R,L,TSR,BSR" distMapUnitScale="3x:0,0,0,0,0,0" allowDegraded="0" lineAnchorClipping="0" overrunDistance="0" repeatDistanceUnits="MM" centroidInside="0" repeatDistance="0" dist="0" xOffset="0" quadOffset="4" maxCurvedCharAngleIn="25" labelOffsetMapUnitScale="3x:0,0,0,0,0,0" geometryGenerator="" rotationAngle="0" layerType="PointGeometry" preserveRotation="1" placement="6" yOffset="0" rotationUnit="AngleDegrees" lineAnchorType="0" distUnits="MM" overrunDistanceUnit="MM" geometryGeneratorEnabled="0" priority="5" offsetType="1" lineAnchorTextPoint="FollowPlacement" lineAnchorPercent="0.5" maxCurvedCharAngleOut="-25"/>
      <rendering obstacleType="1" upsidedownLabels="0" fontLimitPixelSize="0" scaleVisibility="0" zIndex="0" drawLabels="1" minFeatureSize="0" scaleMax="0" fontMinPixelSize="3" labelPerPart="0" scaleMin="0" obstacleFactor="1" unplacedVisibility="0" mergeLines="0" maxNumLabels="2000" obstacle="1" fontMaxPixelSize="10000" limitNumLabels="0"/>
      <dd_properties>
        <Option type="Map">
          <Option value="" name="name" type="QString"/>
          <Option name="properties"/>
          <Option value="collection" name="type" type="QString"/>
        </Option>
      </dd_properties>
      <callout type="simple">
        <Option type="Map">
          <Option value="pole_of_inaccessibility" name="anchorPoint" type="QString"/>
          <Option value="0" name="blendMode" type="int"/>
          <Option name="ddProperties" type="Map">
            <Option value="" name="name" type="QString"/>
            <Option name="properties"/>
            <Option value="collection" name="type" type="QString"/>
          </Option>
          <Option value="false" name="drawToAllParts" type="bool"/>
          <Option value="0" name="enabled" type="QString"/>
          <Option value="point_on_exterior" name="labelAnchorPoint" type="QString"/>
          <Option value="&lt;symbol name=&quot;symbol&quot; force_rhr=&quot;0&quot; type=&quot;line&quot; clip_to_extent=&quot;1&quot; is_animated=&quot;0&quot; frame_rate=&quot;10&quot; alpha=&quot;1&quot;>&lt;data_defined_properties>&lt;Option type=&quot;Map&quot;>&lt;Option value=&quot;&quot; name=&quot;name&quot; type=&quot;QString&quot;/>&lt;Option name=&quot;properties&quot;/>&lt;Option value=&quot;collection&quot; name=&quot;type&quot; type=&quot;QString&quot;/>&lt;/Option>&lt;/data_defined_properties>&lt;layer locked=&quot;0&quot; class=&quot;SimpleLine&quot; pass=&quot;0&quot; enabled=&quot;1&quot;>&lt;Option type=&quot;Map&quot;>&lt;Option value=&quot;0&quot; name=&quot;align_dash_pattern&quot; type=&quot;QString&quot;/>&lt;Option value=&quot;square&quot; name=&quot;capstyle&quot; type=&quot;QString&quot;/>&lt;Option value=&quot;5;2&quot; name=&quot;customdash&quot; type=&quot;QString&quot;/>&lt;Option value=&quot;3x:0,0,0,0,0,0&quot; name=&quot;customdash_map_unit_scale&quot; type=&quot;QString&quot;/>&lt;Option value=&quot;MM&quot; name=&quot;customdash_unit&quot; type=&quot;QString&quot;/>&lt;Option value=&quot;0&quot; name=&quot;dash_pattern_offset&quot; type=&quot;QString&quot;/>&lt;Option value=&quot;3x:0,0,0,0,0,0&quot; name=&quot;dash_pattern_offset_map_unit_scale&quot; type=&quot;QString&quot;/>&lt;Option value=&quot;MM&quot; name=&quot;dash_pattern_offset_unit&quot; type=&quot;QString&quot;/>&lt;Option value=&quot;0&quot; name=&quot;draw_inside_polygon&quot; type=&quot;QString&quot;/>&lt;Option value=&quot;bevel&quot; name=&quot;joinstyle&quot; type=&quot;QString&quot;/>&lt;Option value=&quot;60,60,60,255&quot; name=&quot;line_color&quot; type=&quot;QString&quot;/>&lt;Option value=&quot;solid&quot; name=&quot;line_style&quot; type=&quot;QString&quot;/>&lt;Option value=&quot;0.3&quot; name=&quot;line_width&quot; type=&quot;QString&quot;/>&lt;Option value=&quot;MM&quot; name=&quot;line_width_unit&quot; type=&quot;QString&quot;/>&lt;Option value=&quot;0&quot; name=&quot;offset&quot; type=&quot;QString&quot;/>&lt;Option value=&quot;3x:0,0,0,0,0,0&quot; name=&quot;offset_map_unit_scale&quot; type=&quot;QString&quot;/>&lt;Option value=&quot;MM&quot; name=&quot;offset_unit&quot; type=&quot;QString&quot;/>&lt;Option value=&quot;0&quot; name=&quot;ring_filter&quot; type=&quot;QString&quot;/>&lt;Option value=&quot;0&quot; name=&quot;trim_distance_end&quot; type=&quot;QString&quot;/>&lt;Option value=&quot;3x:0,0,0,0,0,0&quot; name=&quot;trim_distance_end_map_unit_scale&quot; type=&quot;QString&quot;/>&lt;Option value=&quot;MM&quot; name=&quot;trim_distance_end_unit&quot; type=&quot;QString&quot;/>&lt;Option value=&quot;0&quot; name=&quot;trim_distance_start&quot; type=&quot;QString&quot;/>&lt;Option value=&quot;3x:0,0,0,0,0,0&quot; name=&quot;trim_distance_start_map_unit_scale&quot; type=&quot;QString&quot;/>&lt;Option value=&quot;MM&quot; name=&quot;trim_distance_start_unit&quot; type=&quot;QString&quot;/>&lt;Option value=&quot;0&quot; name=&quot;tweak_dash_pattern_on_corners&quot; type=&quot;QString&quot;/>&lt;Option value=&quot;0&quot; name=&quot;use_custom_dash&quot; type=&quot;QString&quot;/>&lt;Option value=&quot;3x:0,0,0,0,0,0&quot; name=&quot;width_map_unit_scale&quot; type=&quot;QString&quot;/>&lt;/Option>&lt;data_defined_properties>&lt;Option type=&quot;Map&quot;>&lt;Option value=&quot;&quot; name=&quot;name&quot; type=&quot;QString&quot;/>&lt;Option name=&quot;properties&quot;/>&lt;Option value=&quot;collection&quot; name=&quot;type&quot; type=&quot;QString&quot;/>&lt;/Option>&lt;/data_defined_properties>&lt;/layer>&lt;/symbol>" name="lineSymbol" type="QString"/>
          <Option value="0" name="minLength" type="double"/>
          <Option value="3x:0,0,0,0,0,0" name="minLengthMapUnitScale" type="QString"/>
          <Option value="MM" name="minLengthUnit" type="QString"/>
          <Option value="0" name="offsetFromAnchor" type="double"/>
          <Option value="3x:0,0,0,0,0,0" name="offsetFromAnchorMapUnitScale" type="QString"/>
          <Option value="MM" name="offsetFromAnchorUnit" type="QString"/>
          <Option value="0" name="offsetFromLabel" type="double"/>
          <Option value="3x:0,0,0,0,0,0" name="offsetFromLabelMapUnitScale" type="QString"/>
          <Option value="MM" name="offsetFromLabelUnit" type="QString"/>
        </Option>
      </callout>
    </settings>
  </labeling>
</qgis>
QML;

		return $qml;
	}

	/**
	 * Generate a simple QML style for line or polygon layers.
	 */
	private function generateSimpleQml($geometryType){

		if($geometryType == 'line'){
			$qml = <<<QML
<!DOCTYPE qgis PUBLIC 'http://mrcc.com/qgis.dtd' 'SYSTEM'>
<qgis simplifyDrawingHints="1" version="3.28.11-Firenze" styleCategories="AllStyleCategories" labelsEnabled="0">
  <renderer-v2 type="singleSymbol" symbollevels="0" forceraster="0" enableorderby="0">
    <symbols>
      <symbol name="0" force_rhr="0" type="line" clip_to_extent="1" is_animated="0" frame_rate="10" alpha="1">
        <data_defined_properties>
          <Option type="Map">
            <Option value="" name="name" type="QString"/>
            <Option name="properties"/>
            <Option value="collection" name="type" type="QString"/>
          </Option>
        </data_defined_properties>
        <layer locked="0" class="SimpleLine" pass="0" enabled="1">
          <Option type="Map">
            <Option value="0" name="align_dash_pattern" type="QString"/>
            <Option value="square" name="capstyle" type="QString"/>
            <Option value="5;2" name="customdash" type="QString"/>
            <Option value="3x:0,0,0,0,0,0" name="customdash_map_unit_scale" type="QString"/>
            <Option value="MM" name="customdash_unit" type="QString"/>
            <Option value="0" name="draw_inside_polygon" type="QString"/>
            <Option value="bevel" name="joinstyle" type="QString"/>
            <Option value="30,120,180,255" name="line_color" type="QString"/>
            <Option value="solid" name="line_style" type="QString"/>
            <Option value="0.5" name="line_width" type="QString"/>
            <Option value="MM" name="line_width_unit" type="QString"/>
            <Option value="0" name="offset" type="QString"/>
            <Option value="3x:0,0,0,0,0,0" name="offset_map_unit_scale" type="QString"/>
            <Option value="MM" name="offset_unit" type="QString"/>
            <Option value="0" name="ring_filter" type="QString"/>
            <Option value="0" name="use_custom_dash" type="QString"/>
            <Option value="3x:0,0,0,0,0,0" name="width_map_unit_scale" type="QString"/>
          </Option>
          <data_defined_properties>
            <Option type="Map">
              <Option value="" name="name" type="QString"/>
              <Option name="properties"/>
              <Option value="collection" name="type" type="QString"/>
            </Option>
          </data_defined_properties>
        </layer>
      </symbol>
    </symbols>
    <rotation/>
    <sizescale/>
  </renderer-v2>
</qgis>
QML;
		}else{
			$qml = <<<QML
<!DOCTYPE qgis PUBLIC 'http://mrcc.com/qgis.dtd' 'SYSTEM'>
<qgis simplifyDrawingHints="1" version="3.28.11-Firenze" styleCategories="AllStyleCategories" labelsEnabled="0">
  <renderer-v2 type="singleSymbol" symbollevels="0" forceraster="0" enableorderby="0">
    <symbols>
      <symbol name="0" force_rhr="0" type="fill" clip_to_extent="1" is_animated="0" frame_rate="10" alpha="1">
        <data_defined_properties>
          <Option type="Map">
            <Option value="" name="name" type="QString"/>
            <Option name="properties"/>
            <Option value="collection" name="type" type="QString"/>
          </Option>
        </data_defined_properties>
        <layer locked="0" class="SimpleFill" pass="0" enabled="1">
          <Option type="Map">
            <Option value="3x:0,0,0,0,0,0" name="border_width_map_unit_scale" type="QString"/>
            <Option value="30,120,180,80" name="color" type="QString"/>
            <Option value="bevel" name="joinstyle" type="QString"/>
            <Option value="0,0" name="offset" type="QString"/>
            <Option value="3x:0,0,0,0,0,0" name="offset_map_unit_scale" type="QString"/>
            <Option value="MM" name="offset_unit" type="QString"/>
            <Option value="30,120,180,255" name="outline_color" type="QString"/>
            <Option value="solid" name="outline_style" type="QString"/>
            <Option value="0.5" name="outline_width" type="QString"/>
            <Option value="MM" name="outline_width_unit" type="QString"/>
            <Option value="solid" name="style" type="QString"/>
          </Option>
          <data_defined_properties>
            <Option type="Map">
              <Option value="" name="name" type="QString"/>
              <Option name="properties"/>
              <Option value="collection" name="type" type="QString"/>
            </Option>
          </data_defined_properties>
        </layer>
      </symbol>
    </symbols>
    <rotation/>
    <sizescale/>
  </renderer-v2>
</qgis>
QML;
		}

		return $qml;
	}

	/**
	 * Inject QGIS layer_styles table into a GeoPackage using ogrinfo.
	 */
	private function injectGpkgStyles($gpkgPath, $layers){

		// Create the layer_styles table
		$createSql = "CREATE TABLE IF NOT EXISTS layer_styles ("
			. "id INTEGER PRIMARY KEY AUTOINCREMENT,"
			. "f_table_catalog TEXT DEFAULT '',"
			. "f_table_schema TEXT DEFAULT '',"
			. "f_table_name TEXT NOT NULL,"
			. "f_geometry_column TEXT NOT NULL,"
			. "styleName TEXT,"
			. "styleQML TEXT,"
			. "styleSLD TEXT,"
			. "useAsDefault BOOLEAN DEFAULT 1,"
			. "description TEXT,"
			. "owner TEXT DEFAULT '',"
			. "ui TEXT,"
			. "update_time TEXT DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))"
			. ")";

		exec("ogrinfo " . escapeshellarg($gpkgPath) . " -sql " . escapeshellarg($createSql) . " 2>&1");

		// Insert style for each layer
		foreach($layers as $layerName => $layerType){

			if($layerType == 'point'){
				$qml = $this->generateOrientationQml($layerName);
				$styleName = 'StraboSpot Orientations';
			}else{
				$qml = $this->generateSimpleQml($layerType);
				$styleName = 'StraboSpot Default';
			}

			// Write SQL to temp file to avoid shell argument length limits
			$escapedQml = str_replace("'", "''", $qml);
			$insertSql = "INSERT INTO layer_styles (f_table_name, f_geometry_column, styleName, styleQML, useAsDefault, description) "
				. "VALUES ('$layerName', 'geom', '$styleName', '$escapedQml', 1, 'Auto-generated by StraboSpot export')";

			// Write SQL to temp file and use @file syntax to avoid shell argument length limits
			$sqlFile = dirname($gpkgPath) . "/style_{$layerName}.sql";
			file_put_contents($sqlFile, $insertSql);
			exec("ogrinfo " . escapeshellarg($gpkgPath) . " -sql @" . escapeshellarg($sqlFile) . " 2>&1");
			unlink($sqlFile);
		}
	}

	/**
	 * Export dataset as GeoPackage (.gpkg) format.
	 * GeoPackage is an OGC standard based on SQLite that supports
	 * multiple geometry types and embedded QGIS styles.
	 */
	public function gpkgOut(){

		if($this->get['dsids']!=""){

			$dsids=$this->get['dsids'];
			$this->alltags = $this->strabo->getTagsFromDatasetIds($dsids);

			// Get spots by geometry type
			$polygonjson = $this->strabo->getDatasetSpotsSearch('polygon',$this->get);
			if($polygonjson!=""){
				$newjson = array();
				$newjson['type']="FeatureCollection";
				$features = array();
				foreach($polygonjson['features'] as $spot){
					$spot = $this->fixSpot($spot, true);
					$features[]=$spot;
				}
				$newjson['features']=$features;
				$polygonjson = json_encode($newjson,JSON_PRETTY_PRINT);
			}

			$pointjson = $this->strabo->getDatasetSpotsSearch('point',$this->get);
			if($pointjson!=""){
				$newjson = array();
				$newjson['type']="FeatureCollection";
				$features = array();
				foreach($pointjson['features'] as $spot){
					$spot = $this->fixSpot($spot, true);
					// Explode multi-orientation spots into separate features
					// (one per orientation, same geometry) for correct symbol rendering
					$exploded = $this->explodeOrientations($spot);
					foreach($exploded as $f){
						$features[] = $f;
					}
				}
				$newjson['features']=$features;
				$pointjson = json_encode($newjson,JSON_PRETTY_PRINT);
			}

			$linejson = $this->strabo->getDatasetSpotsSearch('line',$this->get);
			if($linejson!=""){
				$newjson = array();
				$newjson['type']="FeatureCollection";
				$features = array();
				foreach($linejson['features'] as $spot){
					$spot = $this->fixSpot($spot, true);
					$features[]=$spot;
				}
				$newjson['features']=$features;
				$linejson = json_encode($newjson,JSON_PRETTY_PRINT);
			}

			if($polygonjson!="" || $pointjson!="" || $linejson!=""){

				$randnum=$this->strabo->db->get_var("select nextval('file_seq')");

				// Create temp directory
				mkdir("ogrtemp/$randnum");

				$gpkgFile = "ogrtemp/$randnum/export.gpkg";
				$firstLayer = true;
				$layers = array();

				// Build GeoPackage - first layer creates file, subsequent layers append
				if($pointjson!=""){
					file_put_contents("ogrtemp/$randnum/point.json", $pointjson);
					exec("ogr2ogr -f GPKG -nlt POINT -nln points -skipfailures " . escapeshellarg($gpkgFile) . " ogrtemp/$randnum/point.json 2>&1");
					unlink("ogrtemp/$randnum/point.json");
					$layers['points'] = 'point';
					$firstLayer = false;
				}

				if($linejson!=""){
					$appendFlag = $firstLayer ? "" : "-append";
					file_put_contents("ogrtemp/$randnum/line.json", $linejson);
					exec("ogr2ogr -f GPKG $appendFlag -nlt LINESTRING -nln lines -skipfailures " . escapeshellarg($gpkgFile) . " ogrtemp/$randnum/line.json 2>&1");
					unlink("ogrtemp/$randnum/line.json");
					$layers['lines'] = 'line';
					$firstLayer = false;
				}

				if($polygonjson!=""){
					$appendFlag = $firstLayer ? "" : "-append";
					file_put_contents("ogrtemp/$randnum/polygon.json", $polygonjson);
					exec("ogr2ogr -f GPKG $appendFlag -nlt POLYGON -nln polygons -skipfailures " . escapeshellarg($gpkgFile) . " ogrtemp/$randnum/polygon.json 2>&1");
					unlink("ogrtemp/$randnum/polygon.json");
					$layers['polygons'] = 'polygon';
				}

				// Inject QGIS styles
				if(file_exists($gpkgFile)){
					$this->injectGpkgStyles($gpkgFile, $layers);
				}

				// Get dataset name for filename
				$dsname = $this->strabo->getDatasetName($dsids);
				$fixedname = $this->fixFileName($dsname);
				if($fixedname == ""){
					$fixedname = "strabo_export";
				}

				// Serve the GeoPackage file
				if(file_exists($gpkgFile)){
					header("Content-Type: application/geopackage+sqlite3");
					header("Content-Disposition: attachment; filename={$fixedname}.gpkg");
					header("Content-Length: " . filesize($gpkgFile));
					readfile($gpkgFile);

					// Clean up temp files
					unlink($gpkgFile);
					rmdir("ogrtemp/$randnum");
				}

			}else{
				echo "No data found for this dataset.";
			}

		}

	}

	public function shapefileOut(){

		if($this->get['dsids']!=""){

			$dsids=$this->get['dsids'];
			$this->alltags = $this->strabo->getTagsFromDatasetIds($dsids);

			$polygonjson = $this->strabo->getDatasetSpotsSearch('polygon',$this->get);
			if($polygonjson!=""){

				$newjson = array();
				$newjson['type']="FeatureCollection";
				$features = array();
				foreach($polygonjson['features'] as $spot){
					$spot = $this->fixSpot($spot);
					$features[]=$spot;
				}

				$newjson['features']=$features;
				$polygonjson = json_encode($newjson,JSON_PRETTY_PRINT);
			}

			$pointjson = $this->strabo->getDatasetSpotsSearch('point',$this->get);
			if($pointjson!=""){
				$newjson = array();
				$newjson['type']="FeatureCollection";
				$features = array();
				foreach($pointjson['features'] as $spot){
					$spot = $this->fixSpot($spot);
					$features[]=$spot;
				}

				$newjson['features']=$features;
				$pointjson = json_encode($newjson,JSON_PRETTY_PRINT);
			}

			$linejson = $this->strabo->getDatasetSpotsSearch('line',$this->get);
			if($linejson!=""){

				$newjson = array();
				$newjson['type']="FeatureCollection";
				$features = array();
				foreach($linejson['features'] as $spot){
					$spot = $this->fixSpot($spot);
					$features[]=$spot;
				}

				$newjson['features']=$features;
				$linejson = json_encode($newjson,JSON_PRETTY_PRINT);
			}

			if($polygonjson!="" || $pointjson!="" || $linejson!=""){

				$randnum=$this->strabo->db->get_var("select nextval('file_seq')");

				//make directory in ogrtemp to hold data
				mkdir("ogrtemp/$randnum");
				mkdir("ogrtemp/$randnum/data");

				if($polygonjson!=""){
					file_put_contents("ogrtemp/$randnum/polygon.json", $polygonjson);
					exec("ogr2ogr -nlt POLYGON -f \"ESRI Shapefile\" -skipfailures ogrtemp/$randnum/data/polygons.shp ogrtemp/$randnum/polygon.json 2>&1",$results);
				}

				if($linejson!=""){
					file_put_contents("ogrtemp/$randnum/line.json", $linejson);
					exec("ogr2ogr -nlt LINESTRING -f \"ESRI Shapefile\" -skipfailures ogrtemp/$randnum/data/lines.shp ogrtemp/$randnum/line.json 2>&1",$results);
					unlink("ogrtemp/$randnum/line.json");
				}

				if($pointjson!=""){
					file_put_contents("ogrtemp/$randnum/point.json", $pointjson);
					exec("ogr2ogr -nlt POINT -f \"ESRI Shapefile\" -skipfailures ogrtemp/$randnum/data/points.shp ogrtemp/$randnum/point.json 2>&1",$results);
					unlink("ogrtemp/$randnum/point.json");
				}

				//create zip file
				exec("zip -j ogrtemp/$randnum/strabo$randnum.zip ogrtemp/$randnum/data/* 2>&1",$results);

				//force download of file
				header("Content-Type: application/zip");
				header("Content-Disposition: attachment; filename=search_download.zip");
				header("Content-Length: " . filesize("ogrtemp/$randnum/strabo$randnum.zip"));

				readfile("ogrtemp/$randnum/strabo$randnum.zip");

				//remove temp directory
				if($randnum!=""){
				}

			}else{
				echo "No data found for this dataset.";
			}

		}

	}

	public function devshapefileOut(){

		if($this->get['dsids']!=""){

			$dsids=$this->get['dsids'];
			$this->alltags = $this->strabo->getTagsFromDatasetIds($dsids);

			$polygonjson = $this->strabo->getDatasetSpotsSearch('polygon',$this->get);
			if($polygonjson!=""){

				$newjson = array();
				$newjson['type']="FeatureCollection";
				$features = array();
				foreach($polygonjson['features'] as $spot){
					$spot = $this->fixSpot($spot);
					$features[]=$spot;
				}

				$newjson['features']=$features;
				$polygonjson = json_encode($newjson,JSON_PRETTY_PRINT);
			}

			$pointjson = $this->strabo->getDatasetSpotsSearch('point',$this->get);
			if($pointjson!=""){
				$newjson = array();
				$newjson['type']="FeatureCollection";
				$features = array();
				foreach($pointjson['features'] as $spot){
					$spot = $this->fixSpot($spot);
					$features[]=$spot;
				}

				$newjson['features']=$features;
				$pointjson = json_encode($newjson,JSON_PRETTY_PRINT);
			}

			$linejson = $this->strabo->getDatasetSpotsSearch('line',$this->get);
			if($linejson!=""){

				$newjson = array();
				$newjson['type']="FeatureCollection";
				$features = array();
				foreach($linejson['features'] as $spot){
					$spot = $this->fixSpot($spot);
					$features[]=$spot;
				}

				$newjson['features']=$features;
				$linejson = json_encode($newjson,JSON_PRETTY_PRINT);
			}

			$this->dumpVar($pointjson);exit();

			if($polygonjson!="" || $pointjson!="" || $linejson!=""){

				$randnum=$this->strabo->db->get_var("select nextval('file_seq')");

				//make directory in ogrtemp to hold data
				mkdir("ogrtemp/$randnum");
				mkdir("ogrtemp/$randnum/data");

				if($polygonjson!=""){
					file_put_contents("ogrtemp/$randnum/polygon.json", $polygonjson);
					exec("ogr2ogr -nlt POLYGON -f \"ESRI Shapefile\" -skipfailures ogrtemp/$randnum/data/polygons.shp ogrtemp/$randnum/polygon.json 2>&1",$results);
				}

				if($linejson!=""){
					file_put_contents("ogrtemp/$randnum/line.json", $linejson);
					exec("ogr2ogr -nlt LINESTRING -f \"ESRI Shapefile\" -skipfailures ogrtemp/$randnum/data/lines.shp ogrtemp/$randnum/line.json 2>&1",$results);
					unlink("ogrtemp/$randnum/line.json");
				}

				if($pointjson!=""){
					file_put_contents("ogrtemp/$randnum/point.json", $pointjson);
					exec("ogr2ogr -nlt POINT -f \"ESRI Shapefile\" -skipfailures ogrtemp/$randnum/data/points.shp ogrtemp/$randnum/point.json 2>&1",$results);
					unlink("ogrtemp/$randnum/point.json");
				}

				//create zip file
				exec("zip -j ogrtemp/$randnum/strabo$randnum.zip ogrtemp/$randnum/data/* 2>&1",$results);

				//force download of file

				header("Content-Type: application/zip");
				header("Content-Disposition: attachment; filename=search_download.zip");
				header("Content-Length: " . filesize("ogrtemp/$randnum/strabo$randnum.zip"));

				readfile("ogrtemp/$randnum/strabo$randnum.zip");

				//remove temp directory
				if($randnum!=""){
				}

			}else{
				echo "No data found for this dataset.";
			}

		}

	}

	public function projectGeoJSONOut($projectid=""){

		$showout = false;

		if($projectid == ""){
			$projectid = (int)$_GET['projectid'];
			$showout = true;
		}

		if($projectid != ""){

			$project = $this->strabo->getProject($projectid);

			if($project->Error == ""){

				$projectname = $project->description->project_name;

				$out = new stdClass();

				$out->mapNamesDb = new stdClass();
				$out->mapTilesDb = new stdClass();
				$out->otherMapsDb = new stdClass();

				//Gather datasets for later use
				$datasets = $this->strabo->getProjectDatasets($projectid);
				$datasets = $datasets['datasets'];
				$alldatasetids = [];
				foreach($datasets as $dataset){
					$alldatasetids[] = $dataset['id'];
				}

				//Create projectDb
				$projectDb = new stdClass();

				//project
				$projectDb->activeDatasetsIds = $alldatasetids;
				$projectDb->selectedDatasetId = $alldatasetids[0];
				$projectDb->project = $project;

				$spotsDbSpots = [];

				//datasets
				$filedatasets = new stdClass();
				foreach($datasets as $d){
					$d = (object) $d;
					$datasetid = $d->id;

					//Gather images and spots for dataset
					$getvals = array();
					$getvals['dsids'] = $datasetid;
					$getvals['userpkey'] = $this->strabo->userpkey;
					$getvals['type'] = "doi";
					$json = $this->strabo->getDatasetSpotsSearch(null,$getvals);
					$spots = $json['features'];

					$spotids = array();
					$imageids = array();

					foreach($spots as $spot){
						$spotsDbSpots[] = $spot;
						$spot = json_decode(json_encode($spot));
						$spot = $spot->properties;
						$spotids[] = $spot->id;
						foreach($spot->images as $im){
							$imageids[] = $im->id;
						}
					}

					$fileimages = new stdClass();
					$fileimages->neededImageIds = array();
					$fileimages->imageIds = $imageids;

					$d->images = $fileimages;
					$d->spotIds = $spotids;

					$filedatasets->$datasetid = $d;
				}

				$projectDb->datasets = $filedatasets;

				$projectDb->deviceBackUpDirectoryExists = true;

				//Build File Name
				$fixedprojectname = $this->fixFileName($projectname);
				$date = new DateTimeImmutable();
				$datestring = $date->format('Y-m-d_gia');
				$filename = $datestring."_".$fixedprojectname;
				$projectDb->backupFileName = $filename;

				$projectDb->downloadsDirectory = false;

				$projectDb->isTestingMode = false;

				$selectedProject = new stdClass();
				$selectedProject->project = "";
				$selectedProject->source = "";
				$projectDb->selectedProject = $selectedProject;

				//Get tag from project
				$projectDb->selectedTag = $project->tags[0];

				$projectDb->isMultipleFeaturesTaggingEnabled = false;
				$projectDb->addTagToSelectedSpot = false;
				$projectDb->projectTransferProgress = 0;
				$projectDb->isImageTransferring = false;

				$persist = new stdClass();
				$persist->version = -1;
				$persist->rehydrated = true;
				$projectDb->_persist = $persist;

				$out->projectDb = $projectDb;

				//Now spotsDb
				$spotsDb = new stdClass();

				foreach($spotsDbSpots as $spot){
					$spot = json_decode(json_encode($spot));
					$spotid = $spot->properties->id;
					$spot->geometry = $spot->original_geometry;
					unset($spot->original_geometry);
					$spotsDb->$spotid = $spot;
				}

				$out->spotsDb = $spotsDb;

				if($showout){
					//Send to browser for testing:
					header('Content-Type: application/json; charset=utf-8');
					echo json_encode($out, JSON_PRETTY_PRINT);
				}

				return($out);

			}else{
				die($project->Error);
			}

		}else{
			die("Project not provided.");
		}

	}

	public function bkupgeoJSONOut_20241106(){

		if($this->get['dsids']!=""){

			$dsids=$this->get['dsids'];
			$this->alltags = $this->strabo->getTagsFromDatasetIds($dsids);

			$polygonjson = $this->strabo->getDatasetSpotsSearch('polygon',$this->get);
			if($polygonjson!=""){

				$newjson = array();
				$newjson['type']="FeatureCollection";
				$features = array();
				foreach($polygonjson['features'] as $spot){
					$spot = $this->fixSpot($spot);
					$features[]=$spot;
				}

				$newjson['features']=$features;
				$polygonjson = json_encode($newjson,JSON_PRETTY_PRINT);
			}

			$pointjson = $this->strabo->getDatasetSpotsSearch('point',$this->get);
			if($pointjson!=""){
				$newjson = array();
				$newjson['type']="FeatureCollection";
				$features = array();
				foreach($pointjson['features'] as $spot){
					$spot = $this->fixSpot($spot);
					$features[]=$spot;
				}

				$newjson['features']=$features;
				$pointjson = json_encode($newjson,JSON_PRETTY_PRINT);
			}

			$linejson = $this->strabo->getDatasetSpotsSearch('line',$this->get);
			if($linejson!=""){

				$newjson = array();
				$newjson['type']="FeatureCollection";
				$features = array();
				foreach($linejson['features'] as $spot){
					$spot = $this->fixSpot($spot);
					$features[]=$spot;
				}

				$newjson['features']=$features;
				$linejson = json_encode($newjson,JSON_PRETTY_PRINT);
			}

			if($polygonjson!="" || $pointjson!="" || $linejson!=""){

				$out = new stdClass();
				$out->type="FeatureCollection";
				$features = array();

				if($pointjson != ""){
					$json = json_decode($pointjson);
					$spots = $json->features;
					foreach($spots as $spot){
						$features[] = $spot;
					}
				}

				if($linejson != ""){
					$json = json_decode($linejson);
					$spots = $json->features;
					foreach($spots as $spot){
						$features[] = $spot;
					}
				}

				if($polygonjson != ""){
					$json = json_decode($polygonjson);
					$spots = $json->features;
					foreach($spots as $spot){
						$features[] = $spot;
					}
				}

				$out->features = $features;

				$outname = $this->strabo->getDatasetProjectName($dsids);

				$outname .= "_".$this->strabo->getDatasetName($dsids);
				$fixedoutname = $this->fixFileName($outname);
				$date = new DateTimeImmutable();
				$datestring = $date->format('Y-m-d_gia');
				$filename = $datestring."_".$fixedoutname.".json";

				header("Content-disposition: attachment; filename=$filename");
				header('Content-type: application/json');
				echo json_encode($out, JSON_PRETTY_PRINT);

			}else{
				echo "No data found for this dataset.";
			}

		}

	}

	public function bkup_geoJSONOut_20241112(){

		if($this->get['dsids']!=""){

			$dsids=$this->get['dsids'];
			$this->alltags = $this->strabo->getTagsFromDatasetIds($dsids);

			$getvals = array();
			$getvals['dsids'] = $dsids;
			$getvals['userpkey'] = $this->strabo->userpkey;
			$getvals['type'] = "doi";
			$json = $this->strabo->getDatasetSpotsSearch(null,$getvals);
			$spots = $json['features'];

			$out = new stdClass();
			$out->type = "FeatureCollection";
			$features = [];

			foreach($spots as $spot){
				$spot['geometry'] = $spot['original_geometry'];
				unset($spot['original_geometry']);
				$features[] = $spot;
			}

			$out->features = $features;

			$outname .= "_".$this->strabo->getDatasetName($dsids);
			$fixedoutname = $this->fixFileName($outname);
			$date = new DateTimeImmutable();
			$datestring = $date->format('Y-m-d_gia');
			$filename = $datestring."_".$fixedoutname.".json";

			header("Content-disposition: attachment; filename=$filename");
			header('Content-type: application/json');
			echo json_encode($out, JSON_PRETTY_PRINT);

		}

	}

	public function geoJSONOut(){

		if($this->get['dsids']!=""){

			$dsids=$this->get['dsids'];
			$this->alltags = $this->strabo->getTagsFromDatasetIds($dsids);

			$getvals = array();
			$getvals['dsids'] = $dsids;
			$getvals['userpkey'] = $this->strabo->userpkey;
			$getvals['type'] = "doi";
			$json = $this->strabo->getDatasetSpotsSearch(null,$getvals);
			$spots = $json['features'];

			$out = new stdClass();
			$out->type = "FeatureCollection";
			$features = [];

			foreach($spots as $spot){

				$spotid = $spot['properties']['id'];

				$spot['geometry'] = $spot['original_geometry'];
				unset($spot['original_geometry']);

				$tags = [];
				//Add tags here if they exist
				foreach($this->alltags as $tag){
					$thistag = clone $tag;
					if(in_array($spotid, $thistag->spots)){
						unset($thistag->spots);
						$tags[] = $thistag;
					}
				}

				if(count($tags) > 0) $spot['properties']['tags'] = $tags;

				$features[] = $spot;
			}

			$out->features = $features;

			$outname .= "_".$this->strabo->getDatasetName($dsids);
			$fixedoutname = $this->fixFileName($outname);
			$date = new DateTimeImmutable();
			$datestring = $date->format('Y-m-d_gia');
			$filename = $datestring."_".$fixedoutname.".json";

			header("Content-disposition: attachment; filename=$filename");
			header('Content-type: application/json');
			echo json_encode($out, JSON_PRETTY_PRINT);
		}
	}

	public function devgeoJSONOut(){

		if($this->get['dsids']!=""){

			$dsids=$this->get['dsids'];
			$this->alltags = $this->strabo->getTagsFromDatasetIds($dsids);

			$getvals = array();
			$getvals['dsids'] = $dsids;
			$getvals['userpkey'] = $this->strabo->userpkey;
			$getvals['type'] = "doi";
			$json = $this->strabo->getDatasetSpotsSearch(null,$getvals);
			$spots = $json['features'];

			$out = new stdClass();
			$out->type = "FeatureCollection";
			$features = [];

			foreach($spots as $spot){

				$spotid = $spot['properties']['id'];

				$spot['geometry'] = $spot['original_geometry'];
				unset($spot['original_geometry']);

				$tags = [];
				//Add tags here if they exist
				foreach($this->alltags as $tag){
					$thistag = clone $tag;
					if(in_array($spotid, $thistag->spots)){
						unset($thistag->spots);
						$tags[] = $thistag;
					}
				}

				if(count($tags) > 0) $spot['properties']['tags'] = $tags;

				$features[] = $spot;
			}

			$out->features = $features;

			$outname .= "_".$this->strabo->getDatasetName($dsids);
			$fixedoutname = $this->fixFileName($outname);
			$date = new DateTimeImmutable();
			$datestring = $date->format('Y-m-d_gia');
			$filename = $datestring."_".$fixedoutname.".json";

			header('Content-type: application/json');
			echo json_encode($out, JSON_PRETTY_PRINT);
		}
	}

	public function geologicUnitsOut($projectid){

		$project = $this->strabo->getProject($projectid);
		$tags = $project->tags;

		$projectname = $project->description->project_name;

		$foundvars = [];
		$colvars = [];
		$varnum = 0;
		foreach($tags as $tag){
			if($tag->type == "geologic_unit"){
				foreach($tag as $key=>$value){
					if(!in_array($key,["type","spots","id"])){
						if(!in_array($key, $foundvars)){
							$foundvars[] = $key;
							$colvars[$key] = $varnum;
							$varnum++;
						}
					}
				}
			}
		}

		if(count($foundvars) == 0) return("empty");

		/** PHPExcel */
		include 'PHPExcel.php';

		/** PHPExcel_Writer_Excel2007 */
		include 'PHPExcel/Writer/Excel2007.php';

		$objPHPExcel = new PHPExcel();

		// Set properties
		$objPHPExcel->getProperties()->setCreator("strabospot.org");
		$objPHPExcel->getProperties()->setLastModifiedBy("strabospot.org");
		$objPHPExcel->getProperties()->setTitle("StraboSpot.org Download");
		$objPHPExcel->getProperties()->setSubject("StraboSpot.org Download");
		$objPHPExcel->getProperties()->setDescription("StraboSpot.org Download");

		// Rename sheet
		$objPHPExcel->getActiveSheet()->setTitle('Spots');

		// Add some data
		$objPHPExcel->setActiveSheetIndex(0);

		$objPHPExcel->getActiveSheet()->SetCellValue('A1', "StraboSpot Geologic Units Download: $projectname");

		$colnum=0;

		foreach($colvars as $key=>$val){
			$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol(2,$val), $this->fix_column_name($key));
		}

		$row = 3;
		foreach($tags as $tag){

			if($tag->type == "geologic_unit"){

				foreach($tag as $key=>$val){

					if(in_array($key, $foundvars)){

						if(is_array($val)) $val = implode(", ", $val);

						$colnum = $colvars[$key];

						$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol($row,$colnum), $val);

					}

				}

				$row++;

			}

		}

		foreach($columns['spot'] as $key=>$value){

			if($key=="geometry"){$key="Real World Coordinates";}
			if($key=="original_geometry"){$key="Pixel Coordinates";}

			$objPHPExcel->getActiveSheet()->SetCellValue($this->rowcol(2,$value), $this->fix_column_name($key));

			$thiswidth=strlen($key)-1;
			if($thisheader=="Age (Ma)"){$thiswidth="12";}
			if($thiswidth<10){
				$thiswidth=10;
			}

			$colnum++;
		}

		if(1==1){
			// Save Excel 2007 file
			$objWriter = new PHPExcel_Writer_Excel2007($objPHPExcel);

			// We'll be outputting an excel file
			header('Content-type: application/vnd.ms-excel');

			$filedate = date("m_d_Y");

			// It will be called file.xls
			header('Content-Disposition: attachment; filename="'."StraboSpot_Output_".$filedate.".xlsx".'"');

			// Write file to the browser
			$objWriter->save('php://output');
		}
	}

	// =========================================================================
	// GeMS (Geologic Map Schema) Export Methods
	// Ported from Python StraboGeMSTranslator by Andrew Hoxey (NM Bureau of Geology)
	// =========================================================================

	// GeMS config constants
	private $gemsLnSortOptions = ["ContactsAndFaults", "GeologicLines", "MapUnitLines", "CartographicLines"];

	private $gemsLnTypeOptions = [
		// ContactsAndFaults
		"contact","igneous contact", "intrusive contact", "metamorphic contact",
		"internal contact", "angular unconformity",
		"disconformity", "nonconformity", "paraconformity",
		"unconformity", "fault", "normal fault", "thrust fault",
		"reverse fault","right-lateral strike-slip fault",
		"left-lateral strike-slip fault", "right-lateral oblique-slip fault",
		"left-lateral oblique-slip fault", "detachment fault",
		"low-angle normal fault", "fault scarp", "scarp",
		"elevation profile", "eolian", "escarpment", "geophysical fault",
		"gradational contact", "headscarp", "joint", "breccia",
		"miscellaneous map element", "map boundary",
		// GeologicLines
		"anticline", "asymmetric anticline", "syncline", "asymmetric syncline", "breccia",
		"crest", "escarpment", "geophysical boundary", "geophysical survey",
		"headscarp", "landslide", "lineament", "lineation", "metamorphic facies",
		"monocline", "monocline, anticlinal bend", "monocline, synclinal bend",
		"overturned anticline", "overturned syncline", "scarp", "sedimentary facies",
		"shear zone",
		// MapUnitLines
		"key bed", "dike", "clay bed", "coal bed", "N/A",
		// CartographicLines
		"analytical", "bedding line", "crest", "cross-section line",
		"feature label", "geophysical survey", "leader", "measured-section line",
		"miscellaneous map element", "scale change", "trench", "well"
	];

	private $gemsPtTypeOptions = [
		"anticline", "bedding", "crenulation lineation", "cumulate foliation", "dike inclination",
		"eolian", "fault", "fault decoration", "fault inclination", "fault offset", "fluvial",
		"fold decoration", "fold hinge", "foliation", "groundwater movement", "intersection lineation",
		"joint", "landslide", "lineation", "local fault offset", "mineral lineation", "minor fault",
		"minor fold", "modern current", "overturned bedding", "paleocurrent", "plunge",
		"primary foliation", "secondary foliation", "slickenline", "spring", "stretching lineation",
		"syncline", "Toreva block"
	];

	private $gemsFgdcTable = null;

	/**
	 * Load the FGDC Symbols Table CSV into an associative array keyed by Symbol.
	 */
	public function gemsLoadFGDCTable(){
		if($this->gemsFgdcTable !== null) return $this->gemsFgdcTable;

		$this->gemsFgdcTable = [];
		$csvPath = dirname(__FILE__) . '/../data/FGDC_Symbols_Table.csv';
		if(($handle = fopen($csvPath, 'r')) !== false){
			$headers = fgetcsv($handle);
			while(($row = fgetcsv($handle)) !== false){
				$assoc = array_combine($headers, $row);
				$symbol = $assoc['Symbol'];
				$this->gemsFgdcTable[$symbol] = $assoc;
			}
			fclose($handle);
		}
		return $this->gemsFgdcTable;
	}

	// =========================================================================
	// GeMS Scanning Methods (called by gems_export.php)
	// =========================================================================

	/**
	 * Scan a dataset and separate features into lines and points.
	 * Returns raw (un-fixSpot'd) features so nested trace/orientation_data are intact.
	 */
	public function gemsScanDataset($dsids){
		$result = ['lines' => [], 'points' => [], 'errors' => []];

		// Fetch all spots for the dataset
		$getParams = ['dsids' => $dsids, 'userpkey' => $this->get['userpkey']];
		$json = $this->strabo->getDatasetSpotsSearch(null, $getParams);

		if(!isset($json['features']) || empty($json['features'])){
			return $result;
		}

		// Load tags for unit label lookups
		$this->alltags = $this->strabo->getTagsFromDatasetIds($dsids);

		foreach($json['features'] as $feature){
			$geomType = $feature['geometry']['type'] ?? '';
			$props = $feature['properties'] ?? [];

			// Attach tags to properties
			if(isset($this->alltags) && !empty($this->alltags)){
				$spotId = $props['id'] ?? '';
				foreach($this->alltags as $tag){
					if(isset($tag['spots']) && in_array($spotId, $tag['spots'])){
						$props['tags'][] = $tag;
					}
				}
				$feature['properties'] = $props;
			}

			if($geomType === 'LineString'){
				if(!isset($props['trace'])){
					$result['errors'][] = $props['name'] ?? 'Unknown';
				} else {
					$result['lines'][] = $feature;
				}
			} elseif($geomType === 'Point'){
				$result['points'][] = $feature;
			} else {
				// Polygons and other geometry types are flagged
				$result['errors'][] = $props['name'] ?? 'Unknown';
			}
		}

		return $result;
	}

	/**
	 * Extract unique line trace type strings from an array of line features.
	 */
	public function gemsGetUniqueLineTypes($lines){
		$types = [];
		$seen = [];
		foreach($lines as $line){
			$str = $this->gemsGetLineTraceString($line['properties']);
			if(!in_array($str, $seen)){
				$seen[] = $str;
				$types[] = $str;
			}
		}
		return $types;
	}

	/**
	 * Extract unique orientation type strings from an array of point features.
	 */
	public function gemsGetUniqueOrientationTypes($points){
		$types = [];
		$seen = [];
		foreach($points as $point){
			$props = $point['properties'];
			if(!isset($props['orientation_data']) || empty($props['orientation_data'])){
				continue;
			}
			$orientations = $this->gemsOrientationParser($props['orientation_data']);
			foreach($orientations as $orDict){
				$str = $this->gemsGetOrientationString($orDict);
				if(!in_array($str, $seen)){
					$seen[] = $str;
					$types[] = $str;
				}
			}
		}
		return $types;
	}

	/**
	 * Auto-compute FGDC symbol, GeMS sort, and GeMS type for each unique line type.
	 */
	public function gemsAutoMapLines($uniqueLineTypes){
		$this->gemsLoadFGDCTable();
		$sb = [];
		$symbols = [];
		$sorts = [];
		$types = [];
		foreach($uniqueLineTypes as $lt){
			$sb[] = $lt;
			$sym = $this->gemsGetLineSymbol($lt);
			$symbols[] = $sym;
			$sorts[] = $this->gemsSortLineFeatureClass($lt);
			$types[] = $this->gemsGetLineType($lt, $sym);
		}
		return ['sb' => $sb, 'symbol' => $symbols, 'sort' => $sorts, 'type' => $types];
	}

	/**
	 * Auto-compute FGDC symbol and GeMS type for each unique orientation type.
	 */
	public function gemsAutoMapOrientations($uniqueOrTypes){
		$sb = [];
		$symbols = [];
		$types = [];
		foreach($uniqueOrTypes as $ot){
			$sb[] = $ot;
			$sym = $this->gemsGetOrSymbol($ot);
			$symbols[] = $sym;
			$types[] = $this->gemsGetOrType($ot, $sym);
		}
		return ['sb' => $sb, 'symbol' => $symbols, 'type' => $types];
	}

	// =========================================================================
	// GeMS Line Translation Methods (ported from StraboLineAttributeTranslators.py)
	// =========================================================================

	/**
	 * Build a trace type string from a line feature's properties.
	 * Concatenates type/quality fields from the trace dict.
	 */
	public function gemsGetLineTraceString($properties){
		$trace = $properties['trace'] ?? [];
		$traceType = '';
		foreach($trace as $key => $val){
			if(strpos($key, 'type') !== false || strpos($key, 'quality') !== false){
				$traceType .= $val . ' ';
			}
		}
		return $traceType;
	}

	/**
	 * Compute FGDC symbol code from a trace type string.
	 * Port of Python getSymbol() - integer arithmetic approach.
	 */
	public function gemsGetLineSymbol($sbLineStr){
		$gemSymbol = 0;

		// Contacts
		if(strpos($sbLineStr, 'contact') !== false || strpos($sbLineStr, 'bedding') !== false){
			$gemSymbol += 10100;
			if(strpos($sbLineStr, 'unconformity') !== false){
				$gemSymbol += 24;
			} elseif(strpos($sbLineStr, 'bedding') !== false){
				$gemSymbol += 8;
			} elseif(strpos($sbLineStr, 'gradational') !== false){
				$gemSymbol += 16;
			} elseif(strpos($sbLineStr, 'volcanic') !== false){
				$gemSymbol += 723;
			} elseif(strpos($sbLineStr, 'marker_layer') !== false){
				$gemSymbol += 100;
				if(strpos($sbLineStr, 'clay') !== false) $gemSymbol += 8;
				if(strpos($sbLineStr, 'economic') !== false) $gemSymbol += 16;
				if(strpos($sbLineStr, 'coal') !== false) $gemSymbol += 24;
				if(strpos($sbLineStr, 'clinker') !== false) $gemSymbol += 32;
			}
		}

		// Dikes
		if(strpos($sbLineStr, 'dike') !== false){
			$gemSymbol += 200;
		}

		// Faults
		if(strpos($sbLineStr, 'fault') !== false){
			$gemSymbol += 20000;
			if(strpos($sbLineStr, 'dextral') !== false && strpos($sbLineStr, 'normal') === false && strpos($sbLineStr, 'reverse') === false){
				$gemSymbol += 600;
			} elseif(strpos($sbLineStr, 'sinistral') !== false && strpos($sbLineStr, 'normal') === false && strpos($sbLineStr, 'reverse') === false){
				$gemSymbol += 608;
			} elseif(strpos($sbLineStr, 'nomal') !== false && strpos($sbLineStr, 'low_angle') === false && strpos($sbLineStr, 'sinistral') === false && strpos($sbLineStr, 'dextral') === false){
				$gemSymbol += 200;
			} elseif(strpos($sbLineStr, 'low_angle_norm') !== false){
				$gemSymbol += 1000;
			} elseif(strpos($sbLineStr, 'reverse') !== false && strpos($sbLineStr, 'sinistral') === false && strpos($sbLineStr, 'dextral') === false){
				$gemSymbol += 400;
			} elseif(strpos($sbLineStr, 'thrust') !== false){
				$gemSymbol += 800;
			} elseif(strpos($sbLineStr, 'dextral_reverse') !== false || strpos($sbLineStr, 'dextral_normal') !== false){
				$gemSymbol += 700;
			} elseif(strpos($sbLineStr, 'sinistral_reverse') !== false || strpos($sbLineStr, 'sinistral_normal') !== false){
				$gemSymbol += 708;
			} else {
				$gemSymbol += 100;
			}
		}

		if(strpos($sbLineStr, 'fault') !== false && strpos($sbLineStr, 'scarp') !== false){
			$gemSymbol = 1200;
		}

		// Folds
		if(strpos($sbLineStr, 'fold_axial_tra') !== false){
			$gemSymbol += 50000;
			if(strpos($sbLineStr, 'syncline') !== false){
				$gemSymbol += 500;
			} elseif(strpos($sbLineStr, 'anticline') !== false){
				$gemSymbol += 100;
			} elseif(strpos($sbLineStr, 'monocline') !== false){
				$gemSymbol += 900;
			} elseif(strpos($sbLineStr, 'antiformal_syn') !== false){
				$gemSymbol += 732;
			} elseif(strpos($sbLineStr, 'synformal_anti') !== false){
				$gemSymbol += 332;
			} elseif(strpos($sbLineStr, 'synform') !== false){
				$gemSymbol += 600;
			} elseif(strpos($sbLineStr, 'antiform') !== false){
				$gemSymbol += 200;
			} elseif(strpos($sbLineStr, 's_fold') !== false || strpos($sbLineStr, 'z_fold') !== false || strpos($sbLineStr, 'm_fold') !== false){
				$gemSymbol += 1100;
			} elseif(strpos($sbLineStr, 'ptygmatic') !== false || strpos($sbLineStr, 'unknown') !== false){
				$gemSymbol += 1000;
			}
		}

		if(strpos($sbLineStr, 'sheath') !== false){
			$gemSymbol += 200;
		}

		// Geomorphic
		if(strpos($sbLineStr, 'geomorphic_fea') !== false){
			$gemSymbol = 11000;
			if(strpos($sbLineStr, 'glacial') !== false) $gemSymbol += 300;
			elseif(strpos($sbLineStr, 'fluvial') !== false) $gemSymbol += 200;
			elseif(strpos($sbLineStr, 'marine') !== false) $gemSymbol += 500;
			elseif(strpos($sbLineStr, 'lacustine') !== false) $gemSymbol += 500;
			elseif(strpos($sbLineStr, 'arid') !== false) $gemSymbol += 600;
			elseif(strpos($sbLineStr, 'debris') !== false || strpos($sbLineStr, 'landslide') !== false) $gemSymbol += 700;
			elseif(strpos($sbLineStr, 'volcanic') !== false) $gemSymbol += 801;

			if(strpos($sbLineStr, 'ridge') !== false) $gemSymbol += 10;
			elseif(strpos($sbLineStr, 'shoreline') !== false) $gemSymbol += 13;
		}

		// Anthropogenic
		if(strpos($sbLineStr, 'anthropenic_fe') !== false){
			$gemSymbol = 12800;
			if(strpos($sbLineStr, 'fence_line') !== false) $gemSymbol += 107;
			if(strpos($sbLineStr, 'property_line') !== false) $gemSymbol += 106;
			if(strpos($sbLineStr, 'road') !== false) $gemSymbol += 2;
			if(strpos($sbLineStr, 'trail') !== false) $gemSymbol += 15;
			if(strpos($sbLineStr, 'other') !== false) $gemSymbol += 19;
		}

		// Final digit - accuracy/confidence
		if(strpos($sbLineStr, 'known') !== false){
			$gemSymbol += 1;
		} elseif(strpos($sbLineStr, 'approximate(?)') !== false){
			$gemSymbol += 4;
		} elseif(strpos($sbLineStr, 'approximate') !== false){
			$gemSymbol += 3;
		} elseif(strpos($sbLineStr, 'inferred(?)') !== false){
			$gemSymbol += 6;
		} elseif(strpos($sbLineStr, 'inferred') !== false){
			$gemSymbol += 5;
		} elseif(strpos($sbLineStr, 'concealed') !== false){
			$gemSymbol += 7;
		} else {
			$gemSymbol += 31;
		}

		// Convert integer to dotted string
		$gemSymbol = '0' . strval($gemSymbol);
		$gemSymbol = substr($gemSymbol, 0, 2) . '.' . substr($gemSymbol, 2, 2) . '.' . substr($gemSymbol, 4);

		if(strpos($sbLineStr, 'anthropenic_fe') !== false || strpos($sbLineStr, 'geomorphic_fea') !== false){
			$gemSymbol = substr($gemSymbol, 3);
		}

		// Special cases
		if(strpos($sbLineStr, 'deformation_zo') !== false) $gemSymbol = '14.02';
		if(strpos($sbLineStr, 'shear_zone') !== false) $gemSymbol = '14.01';
		if(strpos($sbLineStr, 'plunging') !== false && strpos($sbLineStr, 'anticline') !== false) $gemSymbol = '05.10.05';
		if(strpos($sbLineStr, 'plunging') !== false && strpos($sbLineStr, 'syncline') !== false) $gemSymbol = '05.10.06';
		if(strpos($sbLineStr, 'other_feature') !== false && strpos($sbLineStr, 'extent_of_map') !== false) $gemSymbol = '31.08';
		if(strpos($sbLineStr, 'cross_section') !== false) $gemSymbol = '31.10';
		if(strpos($sbLineStr, 'stratigraphic_section') !== false) $gemSymbol = '31.05';

		return $gemSymbol;
	}

	/**
	 * Determine GeMS line type via fuzzy string matching against FGDC description.
	 * Port of Python getType() using similar_text() instead of fuzzywuzzy.
	 */
	public function gemsGetLineType($sbLineStr, $gmSymbol){
		$options = $this->gemsLnTypeOptions;
		// Remove "fault scarp" temporarily to avoid fuzz issues
		$options = array_values(array_filter($options, function($o){ return $o !== 'fault scarp'; }));

		// Apply substitutions like the Python code
		$sbStr = str_replace('sinistral', 'left-lateral', $sbLineStr);
		$sbStr = str_replace('dextral', 'right-lateral', $sbStr);
		$sbStr = str_replace('marker_layer', 'key bed', $sbStr);

		// Get FGDC description for this symbol
		$fgdcTable = $this->gemsLoadFGDCTable();
		$fgdcDesc = isset($fgdcTable[$gmSymbol]) ? $fgdcTable[$gmSymbol]['Description'] : 'No description found';

		// Compare first 10 chars of FGDC description against all options
		$descPrefix = substr($fgdcDesc, 0, 10);
		$ratios = [];
		foreach($options as $opt){
			similar_text($descPrefix, $opt, $pct);
			$ratios[] = $pct;
		}

		// Get top 3 matches
		$indexed = $ratios;
		arsort($indexed);
		$topIndices = array_slice(array_keys($indexed), 0, 3);
		$bestMatches = [];
		foreach($topIndices as $idx){
			$bestMatches[] = $options[$idx];
		}

		// Compare top results against last 20 chars of Strabo string
		$sbSuffix = substr($sbStr, -20);
		$topRatios = [];
		foreach($bestMatches as $match){
			similar_text($sbSuffix, $match, $pct);
			$topRatios[] = $pct;
		}

		$bestIdx = array_search(max($topRatios), $topRatios);
		$gemType = $bestMatches[$bestIdx];

		if(strpos($fgdcDesc, 'No description') !== false){
			$gemType = 'miscellaneous map element';
		}

		return $gemType;
	}

	/**
	 * Sort a line trace string into a GeMS feature class category.
	 */
	public function gemsSortLineFeatureClass($sbTraceStr){
		if(strpos($sbTraceStr, 'contact') !== false || strpos($sbTraceStr, 'geologic_struc') !== false){
			$sort = 'ContactsAndFaults';
			if(strpos($sbTraceStr, 'dike') !== false || strpos($sbTraceStr, 'sill') !== false || strpos($sbTraceStr, 'marker_layer') !== false){
				$sort = 'MapUnitLines';
			} elseif(strpos($sbTraceStr, 'fold_axial_tra') !== false){
				$sort = 'GeologicLines';
			}
		} elseif(strpos($sbTraceStr, 'geomorphic_fea') !== false){
			$sort = 'GeologicLines';
		} elseif(strpos($sbTraceStr, 'anthro') !== false){
			$sort = 'DefaultUnsorted';
		} elseif(strpos($sbTraceStr, 'scale_bar') !== false){
			$sort = 'DefaultUnsorted';
		} elseif(strpos($sbTraceStr, 'bedding') !== false){
			$sort = 'DefaultUnsorted';
		} elseif(strpos($sbTraceStr, 'geologic_cross') !== false){
			$sort = 'CartographicLines';
		} elseif(strpos($sbTraceStr, 'geophysical_cross') !== false){
			$sort = 'CartographicLines';
		} elseif(strpos($sbTraceStr, 'other_feature') !== false){
			$sort = 'DefaultUnsorted';
		} else {
			$sort = 'DefaultUnsorted';
		}
		return $sort;
	}

	/**
	 * Determine identity confidence from trace string keywords.
	 */
	public function gemsGetLineIdentity($sbLineStr){
		if(strpos($sbLineStr, 'approximate(?)') !== false || strpos($sbLineStr, 'inferred(?)') !== false){
			return 'questionable';
		}
		return 'certain';
	}

	/**
	 * Determine existence confidence from trace string keywords.
	 */
	public function gemsGetLineExistence($sbLineStr){
		if(strpos($sbLineStr, 'approximate(?)') !== false || strpos($sbLineStr, 'inferred(?)') !== false){
			return 'questionable';
		}
		return 'certain';
	}

	/**
	 * Determine if line is concealed from trace string keywords.
	 */
	public function gemsGetLineConcealed($sbLineStr){
		return (strpos($sbLineStr, 'concealed') !== false) ? 'y' : 'n';
	}

	/**
	 * Determine location confidence in meters from trace string keywords.
	 */
	public function gemsGetLineLocation($sbLineStr){
		if(strpos($sbLineStr, 'known') !== false) return 5;
		if(strpos($sbLineStr, 'concealed') !== false) return 100;
		if(strpos($sbLineStr, 'approximate') !== false || strpos($sbLineStr, 'inferred') !== false) return 50;
		return 5;
	}

	// =========================================================================
	// GeMS Point Translation Methods (ported from StraboPointAttributeTranslators.py + StraboUtils.py)
	// =========================================================================

	/**
	 * Flatten orientation_data array including associated orientations into a flat list.
	 */
	public function gemsOrientationParser($orientationData){
		$orientationList = [];
		if(!is_array($orientationData)) return $orientationList;

		foreach($orientationData as $orItem){
			if(isset($orItem['associated_orientation']) && is_array($orItem['associated_orientation'])){
				foreach($orItem['associated_orientation'] as $assoc){
					$orientationList[] = $assoc;
				}
				$copy = $orItem;
				unset($copy['associated_orientation']);
				$orientationList[] = $copy;
			} else {
				$orientationList[] = $orItem;
			}
		}
		return $orientationList;
	}

	/**
	 * Build an orientation type string from an orientation dict.
	 */
	public function gemsGetOrientationString($orDict){
		$orType = '';
		foreach($orDict as $key => $val){
			if(strpos($key, 'type') !== false){
				$orType .= $val . ' ';
			}
		}
		return $orType;
	}

	/**
	 * Sort a point into GeMS feature class categories.
	 * Returns an array of sort codes (a single spot can produce multiple features).
	 * 1=Station, 2=GenericSamples, 3=OrientationPoints, 5=MapUnitPolyLabels
	 */
	public function gemsSortPointFeatureClass($properties){
		$sort = [];

		if(!empty($properties['images'])){
			$sort[] = 1;
		}
		if(!empty($properties['notes'])){
			$sort[] = 1;
		}
		if(!empty($properties['samples'])){
			$sort[] = 2;
		}
		if(!empty($properties['orientation_data'])){
			$sort[] = 3;
		}
		if(!empty($properties['tags'])){
			foreach($properties['tags'] as $tag){
				if(isset($tag['type']) && $tag['type'] === 'concept'){
					$sort[] = 1;
				} else {
					$sort[] = 5;
				}
			}
		}
		return array_unique($sort);
	}

	/**
	 * Compute FGDC orientation symbol from an orientation type string.
	 */
	public function gemsGetOrSymbol($sbOrStr){
		$gmOrSym = '';

		if(strpos($sbOrStr, 'fault') !== false){
			$gmOrSym = '02.11.';
		} elseif(strpos($sbOrStr, 'joint') !== false){
			$gmOrSym = '04.03.';
		} elseif(strpos($sbOrStr, 'fold hinge') !== false){
			$gmOrSym = '05.11.';
		} elseif(strpos($sbOrStr, 'bedding') !== false){
			$gmOrSym = '06.';
		} elseif(strpos($sbOrStr, 'foliation') !== false){
			$gmOrSym = '08.01.';
		} elseif(strpos($sbOrStr, 'mineral alignment') !== false){
			$gmOrSym = '09.001';
		} elseif(strpos($sbOrStr, 'slickenlines') !== false){
			$gmOrSym = '09.017';
		} else {
			$gmOrSym = '31.';
		}

		// Digit 3
		if(strpos($sbOrStr, 'upright') !== false){
			$gmOrSym .= '02';
		} elseif(strpos($sbOrStr, 'overturned') !== false){
			$gmOrSym .= '04';
		} else {
			$gmOrSym .= '01';
		}

		return $gmOrSym;
	}

	/**
	 * Determine GeMS orientation type from an orientation type string.
	 */
	public function gemsGetOrType($sbOrStr, $gmOrSym){
		if(strpos($sbOrStr, 'fault') !== false) return 'fault';
		if(strpos($sbOrStr, 'joint') !== false) return 'joint';
		if(strpos($sbOrStr, 'fold hinge') !== false) return 'fold hinge';
		if(strpos($sbOrStr, 'foliation') !== false) return 'foliation';
		if(strpos($sbOrStr, 'mineral alignment') !== false) return 'mineral lineation';
		if(strpos($sbOrStr, 'slickenlines') !== false) return 'slickenline';
		if(strpos($sbOrStr, 'bedding') !== false) return 'bedding';
		return 'undefined';
	}

	/**
	 * Convert orientation quality (1-5) to confidence in degrees.
	 */
	public function gemsGetOrConfidence($orDict){
		$quality = isset($orDict['quality']) ? intval($orDict['quality']) : 5;
		if($quality <= 0) $quality = 5;
		return intdiv(15, $quality);
	}

	/**
	 * Convert unit label abbreviation to FGDC font characters for geologic periods.
	 */
	public function gemsGetUnitLabel($tagDict){
		$abbrev = $tagDict['unit_label_abbreviation'] ?? '';
		$gmLabel = $abbrev;

		// Build string from tag fields for period detection
		$tagStr = '';
		$excludeKeys = ['type', 'name', 'unit_label_abbreviation', 'map_unit_name', 'rock_type', 'id', 'continuousTagging'];
		foreach($tagDict as $key => $val){
			if(!in_array($key, $excludeKeys)){
				if(is_array($val)){
					$tagStr .= implode(' ', $val) . ' ';
				} else {
					$tagStr .= $val . ' ';
				}
			}
		}
		$tagStr = strtolower($tagStr);

		if(strpos($tagStr, 'paleogene') !== false){
			$gmLabel = str_replace('P', ':', $abbrev);
		} elseif(strpos($tagStr, 'triassic') !== false){
			$gmLabel = str_replace('Tr', '^', $abbrev);
		} elseif(strpos($tagStr, 'pennsylvanian') !== false){
			$gmLabel = str_replace('P', '*', $abbrev);
		} elseif(strpos($tagStr, 'cambrian') !== false){
			$gmLabel = str_replace('C', '_', $abbrev);
		} elseif(strpos($tagStr, 'precambrian') !== false){
			$gmLabel = str_replace('PC', '=', $abbrev);
		}

		return $gmLabel;
	}

	/**
	 * Get sample type string (currently returns "field sample" as default).
	 */
	public function gemsGetSampleType($sampleDict){
		return 'field sample';
	}

	/**
	 * Extract the first geologic_unit tag from a properties dict.
	 */
	public function gemsGetUnitTag($properties){
		if(!isset($properties['tags']) || !is_array($properties['tags'])){
			return null;
		}
		foreach($properties['tags'] as $tag){
			if(isset($tag['type']) && $tag['type'] === 'geologic_unit'){
				return $tag;
			}
		}
		return null;
	}

	// =========================================================================
	// GeMS Feature Builder Methods (ported from StraboUtils.py)
	// =========================================================================

	/**
	 * Build GeMS ContactsAndFaults properties for a line feature.
	 */
	public function gemsBuildContactsAndFaults($sbLineStr, $spotName, $traceNotes, $config){
		$index = array_search($sbLineStr, $config['ln_sb']);
		$props = [
			'Type' => ($index !== false) ? $config['ln_type'][$index] : 'miscellaneous map element',
			'Label' => '',
			'Symbol' => ($index !== false) ? $config['ln_symbol'][$index] : '',
			'IsConcealed' => $this->gemsGetLineConcealed($sbLineStr),
			'IdentityConfidence' => $this->gemsGetLineIdentity($sbLineStr),
			'ExistenceConfidence' => $this->gemsGetLineExistence($sbLineStr),
			'LocationConfidenceMeters' => $this->gemsGetLineLocation($sbLineStr),
			'DataSourceID' => '',
			'LocationSourceID' => '',
			'Notes' => $spotName . ' Notes: ' . $traceNotes
		];
		return $props;
	}

	/**
	 * Build GeMS GeologicLines properties for a line feature.
	 */
	public function gemsBuildGeologicLines($sbLineStr, $spotName, $traceNotes, $config){
		$index = array_search($sbLineStr, $config['ln_sb']);
		$props = [
			'Type' => ($index !== false) ? $config['ln_type'][$index] : 'miscellaneous map element',
			'Label' => '',
			'Symbol' => ($index !== false) ? $config['ln_symbol'][$index] : '',
			'IsConcealed' => $this->gemsGetLineConcealed($sbLineStr),
			'IdentityConfidence' => $this->gemsGetLineIdentity($sbLineStr),
			'ExistenceConfidence' => $this->gemsGetLineExistence($sbLineStr),
			'LocationConfidenceMeters' => $this->gemsGetLineLocation($sbLineStr),
			'DataSourceID' => '',
			'LocationSourceID' => '',
			'Notes' => $spotName . ' Notes: ' . $traceNotes
		];
		return $props;
	}

	/**
	 * Build GeMS MapUnitLines properties for a line feature.
	 */
	public function gemsBuildMapUnitLines($sbLineStr, $properties, $spotName, $traceNotes, $config){
		$index = array_search($sbLineStr, $config['ln_sb']);
		$props = [
			'MapUnit' => '',
			'Label' => '',
			'Symbol' => ($index !== false) ? $config['ln_symbol'][$index] : '',
			'IsConcealed' => $this->gemsGetLineConcealed($sbLineStr),
			'IdentityConfidence' => $this->gemsGetLineIdentity($sbLineStr),
			'ExistenceConfidence' => $this->gemsGetLineExistence($sbLineStr),
			'LocationConfidenceMeters' => $this->gemsGetLineLocation($sbLineStr),
			'PlotAtScale' => '',
			'DataSourceID' => '',
			'LocationSourceID' => '',
			'Notes' => $spotName . ' Notes: ' . $traceNotes
		];

		$unitTag = $this->gemsGetUnitTag($properties);
		if($unitTag){
			$props['MapUnit'] = $unitTag['unit_label_abbreviation'] ?? '';
			$props['Label'] = $this->gemsGetUnitLabel($unitTag);
		} else {
			$props['MapUnit'] = 'unassigned';
		}

		return $props;
	}

	/**
	 * Build GeMS CartographicLines properties for a line feature.
	 */
	public function gemsBuildCartographicLines($sbLineStr, $spotName, $traceNotes, $config){
		$index = array_search($sbLineStr, $config['ln_sb']);
		$props = [
			'Type' => ($index !== false) ? $config['ln_type'][$index] : 'miscellaneous map element',
			'Label' => '',
			'Symbol' => ($index !== false) ? $config['ln_symbol'][$index] : '',
			'DataSourceID' => '',
			'LocationSourceID' => '',
			'Notes' => $spotName . ' Notes: ' . $traceNotes
		];
		return $props;
	}

	/**
	 * Build GeMS Station properties for a point feature.
	 */
	public function gemsBuildStation($properties){
		$props = [
			'FieldID' => $properties['name'] ?? '',
			'ObservedMapUnit' => '',
			'MapUnit' => '',
			'Label' => '',
			'Symbol' => '',
			'LocationConfidenceMeters' => '',
			'PlotAtScale' => '',
			'LocationMethod' => '',
			'GPSX' => '',
			'GPSY' => '',
			'DataSourceID' => '',
			'LocationSourceID' => '',
			'Notes' => ''
		];

		$unitTag = $this->gemsGetUnitTag($properties);
		if($unitTag){
			$props['MapUnit'] = $unitTag['unit_label_abbreviation'] ?? '';
			$props['Label'] = $this->gemsGetUnitLabel($unitTag);
			$props['Symbol'] = $unitTag['unit_label_abbreviation'] ?? '';
		}

		$notes = 'Notes: ' . ($properties['notes'] ?? '') . ' Images: ';
		if(isset($properties['images']) && is_array($properties['images'])){
			foreach($properties['images'] as $img){
				$notes .= ($img['self'] ?? '') . ' ';
			}
		}
		$props['Notes'] = $notes;

		return $props;
	}

	/**
	 * Build GeMS GenericSamples properties for a sample.
	 */
	public function gemsBuildSample($sampleDict, $properties){
		$props = [
			'Type' => $this->gemsGetSampleType($sampleDict),
			'FieldSampleID' => $sampleDict['sample_id_name'] ?? '',
			'AlternateSampleID' => '',
			'ObservedMapUnit' => '',
			'MapUnit' => '',
			'Label' => '',
			'Symbol' => '31.21',
			'LocationConfidenceMeters' => '',
			'PlotAtScale' => '',
			'MaterialAnalyzed' => '',
			'StationsID' => $properties['name'] ?? '',
			'DataSourceID' => '',
			'LocationSourceID' => '',
			'AnalysisSourceID' => '',
			'Notes' => 'Notes: ' . ($sampleDict['sample_description'] ?? '') . ' / ' . ($sampleDict['sample_notes'] ?? '')
		];

		$unitTag = $this->gemsGetUnitTag($properties);
		if($unitTag){
			$props['MapUnit'] = $unitTag['unit_label_abbreviation'] ?? '';
			$props['Label'] = $this->gemsGetUnitLabel($unitTag);
		}

		return $props;
	}

	/**
	 * Build GeMS OrientationPoints properties for a single orientation measurement.
	 */
	public function gemsBuildOrientationPoint($orStr, $orDict, $properties, $config){
		$index = array_search($orStr, $config['pt_sb']);
		$props = [
			'Type' => ($index !== false) ? $config['pt_type'][$index] : 'undefined',
			'Azimuth' => '',
			'Inclination' => '',
			'ObservedMapUnit' => '',
			'MapUnit' => '',
			'Label' => '',
			'Symbol' => ($index !== false) ? $config['pt_symbol'][$index] : '',
			'LocationConfidenceMeters' => '',
			'OrientationConfidenceDegrees' => $this->gemsGetOrConfidence($orDict),
			'PlotAtScale' => '',
			'StationsID' => '',
			'DataSourceID' => '',
			'LocationSourceID' => '',
			'OrientationSourceID' => '',
			'Notes' => ''
		];

		// Azimuth: strike+90 for planar, trend for linear
		if(isset($orDict['strike'])){
			$props['Azimuth'] = ($orDict['strike'] + 90) % 360;
		} elseif(isset($orDict['trend'])){
			$props['Azimuth'] = $orDict['trend'];
		}

		// Inclination: dip for planar, plunge for linear
		if(isset($orDict['dip'])){
			$props['Inclination'] = $orDict['dip'];
		} elseif(isset($orDict['plunge'])){
			$props['Inclination'] = $orDict['plunge'];
		}

		$unitTag = $this->gemsGetUnitTag($properties);
		if($unitTag){
			$props['MapUnit'] = $unitTag['unit_label_abbreviation'] ?? '';
			$props['Label'] = $this->gemsGetUnitLabel($unitTag);
		}

		return $props;
	}

	/**
	 * Build GeMS MapUnitPoints properties for a point feature.
	 */
	public function gemsBuildMapUnitPoints($properties){
		$props = [
			'MapUnit' => '',
			'Label' => '',
			'Symbol' => '',
			'IdentityConfidence' => '',
			'ExistenceConfidence' => '',
			'LocationConfidenceMeters' => '',
			'PlotAtScale' => '',
			'DataSourceID' => '',
			'LocationSourceID' => '',
			'Notes' => 'Notes: ' . ($properties['notes'] ?? '')
		];

		$unitTag = $this->gemsGetUnitTag($properties);
		if($unitTag){
			$props['MapUnit'] = $unitTag['unit_label_abbreviation'] ?? '';
			$props['Label'] = $this->gemsGetUnitLabel($unitTag);
			$props['Symbol'] = $unitTag['unit_label_abbreviation'] ?? '';
		}

		return $props;
	}

	/**
	 * Build GeMS MapUnitPolyLabels properties for a point feature.
	 */
	public function gemsBuildMapUnitPolyLabels($properties){
		$props = [
			'MapUnit' => '',
			'Label' => '',
			'Symbol' => '',
			'IdentityConfidence' => '',
			'DataSourceID' => '',
			'LocationSourceID' => ''
		];

		$unitTag = $this->gemsGetUnitTag($properties);
		if($unitTag){
			$props['MapUnit'] = $unitTag['unit_label_abbreviation'] ?? '';
			$props['Label'] = $this->gemsGetUnitLabel($unitTag);
			$props['Symbol'] = $unitTag['unit_label_abbreviation'] ?? '';
		}

		return $props;
	}

	// =========================================================================
	// GeMS Main Export Entry Point
	// =========================================================================

	/**
	 * Main GeMS export method. Called from searchdownload.php.
	 * Reads POST data with metadata and user-customized mappings,
	 * fetches raw spots, runs translation, outputs zipped GeoJSON files.
	 */
	public function gemsOut(){
		// Read metadata from POST
		$dsids = $_POST['dsids'] ?? ($this->get['dsids'] ?? '');
		$dsid = $_POST['gems_dsid'] ?? '';
		$lsid = $_POST['gems_lsid'] ?? '';
		$osid = $_POST['gems_osid'] ?? '';
		$datasetName = $_POST['gems_dataset_name'] ?? 'GeMS_Export';
		$datasetName = $this->fixFileName($datasetName);

		if(empty($dsids)){
			echo 'Error: No dataset specified.';
			return;
		}

		// Build config from POST mapping arrays
		$config = [
			'ln_sb' => $_POST['ln_sb'] ?? [],
			'ln_symbol' => $_POST['ln_symbol'] ?? [],
			'ln_sort' => $_POST['ln_sort'] ?? [],
			'ln_type' => $_POST['ln_type'] ?? [],
			'pt_sb' => $_POST['pt_sb'] ?? [],
			'pt_symbol' => $_POST['pt_symbol'] ?? [],
			'pt_type' => $_POST['pt_type'] ?? [],
		];

		// Scan dataset
		$scanResult = $this->gemsScanDataset($dsids);
		$lines = $scanResult['lines'];
		$points = $scanResult['points'];

		// Initialize 9 feature collections
		$contactsAndFaults = [];
		$geologicLines = [];
		$mapUnitLines = [];
		$cartographicLines = [];
		$stations = [];
		$genericSamples = [];
		$orientationPoints = [];
		$mapUnitPoints = [];
		$mapUnitPolyLabels = [];

		// Process lines
		foreach($lines as $line){
			$props = $line['properties'];
			$geometry = $line['geometry'];
			$spotName = $props['name'] ?? '';
			$traceNotes = $props['trace']['trace_notes'] ?? '';
			$sbLineStr = $this->gemsGetLineTraceString($props);

			$index = array_search($sbLineStr, $config['ln_sb']);
			$sort = ($index !== false) ? ($config['ln_sort'][$index] ?? 'DefaultUnsorted') : 'DefaultUnsorted';

			$feature = ['type' => 'Feature', 'geometry' => $geometry, 'properties' => []];

			if($sort === 'ContactsAndFaults'){
				$feature['properties'] = $this->gemsBuildContactsAndFaults($sbLineStr, $spotName, $traceNotes, $config);
				$feature['properties']['DataSourceID'] = $dsid;
				$feature['properties']['LocationSourceID'] = $lsid;
				$contactsAndFaults[] = $feature;
			} elseif($sort === 'GeologicLines'){
				$feature['properties'] = $this->gemsBuildGeologicLines($sbLineStr, $spotName, $traceNotes, $config);
				$feature['properties']['DataSourceID'] = $dsid;
				$feature['properties']['LocationSourceID'] = $lsid;
				$geologicLines[] = $feature;
			} elseif($sort === 'MapUnitLines'){
				$feature['properties'] = $this->gemsBuildMapUnitLines($sbLineStr, $props, $spotName, $traceNotes, $config);
				$feature['properties']['DataSourceID'] = $dsid;
				$feature['properties']['LocationSourceID'] = $lsid;
				$mapUnitLines[] = $feature;
			} elseif($sort === 'CartographicLines'){
				$feature['properties'] = $this->gemsBuildCartographicLines($sbLineStr, $spotName, $traceNotes, $config);
				$feature['properties']['DataSourceID'] = $dsid;
				$feature['properties']['LocationSourceID'] = $lsid;
				$cartographicLines[] = $feature;
			}
		}

		// Process points
		foreach($points as $point){
			$props = $point['properties'];
			$geometry = $point['geometry'];
			$spotName = $props['name'] ?? '';
			$daughterCount = 0;

			$sortCodes = $this->gemsSortPointFeatureClass($props);

			// Stations (sort code 1)
			if(in_array(1, $sortCodes)){
				$feature = ['type' => 'Feature', 'geometry' => $geometry, 'properties' => []];
				$stProps = $this->gemsBuildStation($props);
				$stProps['DataSourceID'] = $dsid;
				$stProps['LocationSourceID'] = $lsid;
				$stProps['Notes'] = $spotName . '_' . $daughterCount . ' ' . $stProps['Notes'];
				$feature['properties'] = $stProps;
				$stations[] = $feature;
				$daughterCount++;
			}

			// GenericSamples (sort code 2)
			if(in_array(2, $sortCodes) && !empty($props['samples'])){
				foreach($props['samples'] as $sample){
					$feature = ['type' => 'Feature', 'geometry' => $geometry, 'properties' => []];
					$samProps = $this->gemsBuildSample($sample, $props);
					$samProps['DataSourceID'] = $dsid;
					$samProps['LocationSourceID'] = $lsid;
					$samProps['Notes'] = $spotName . '_' . $daughterCount . ' ' . $samProps['Notes'];
					$feature['properties'] = $samProps;
					$genericSamples[] = $feature;
					$daughterCount++;
				}
			}

			// OrientationPoints (sort code 3)
			if(in_array(3, $sortCodes) && !empty($props['orientation_data'])){
				$orientations = $this->gemsOrientationParser($props['orientation_data']);
				foreach($orientations as $orDict){
					$orStr = $this->gemsGetOrientationString($orDict);
					$feature = ['type' => 'Feature', 'geometry' => $geometry, 'properties' => []];
					$orProps = $this->gemsBuildOrientationPoint($orStr, $orDict, $props, $config);
					$orProps['DataSourceID'] = $dsid;
					$orProps['LocationSourceID'] = $lsid;
					$orProps['OrientationSourceID'] = $osid;
					$orProps['StationsID'] = $spotName . '_' . $daughterCount;
					$orProps['Notes'] = $spotName . '_' . $daughterCount;
					$feature['properties'] = $orProps;
					$orientationPoints[] = $feature;
					$daughterCount++;
				}
			}

			// MapUnitPolyLabels (sort code 5)
			if(in_array(5, $sortCodes)){
				$feature = ['type' => 'Feature', 'geometry' => $geometry, 'properties' => []];
				$muplProps = $this->gemsBuildMapUnitPolyLabels($props);
				$muplProps['DataSourceID'] = $dsid;
				$muplProps['LocationSourceID'] = $lsid;
				$muplProps['Notes'] = $spotName . '_' . $daughterCount;
				$feature['properties'] = $muplProps;
				$mapUnitPolyLabels[] = $feature;
				$daughterCount++;
			}
		}

		// Assemble 9 FeatureCollections
		$collections = [
			'ContactsAndFaults' => ['type' => 'FeatureCollection', 'features' => $contactsAndFaults],
			'GeologicLines' => ['type' => 'FeatureCollection', 'features' => $geologicLines],
			'MapUnitLines' => ['type' => 'FeatureCollection', 'features' => $mapUnitLines],
			'CartographicLines' => ['type' => 'FeatureCollection', 'features' => $cartographicLines],
			'Stations' => ['type' => 'FeatureCollection', 'features' => $stations],
			'GenericSamples' => ['type' => 'FeatureCollection', 'features' => $genericSamples],
			'OrientationPoints' => ['type' => 'FeatureCollection', 'features' => $orientationPoints],
			'MapUnitPoints' => ['type' => 'FeatureCollection', 'features' => $mapUnitPoints],
			'MapUnitPolyLabels' => ['type' => 'FeatureCollection', 'features' => $mapUnitPolyLabels],
		];

		// Write to temp directory and zip
		$randnum = $this->strabo->db->get_var("select nextval('file_seq')");
		$tempDir = "ogrtemp/$randnum";
		mkdir($tempDir, 0777, true);

		$jsonFiles = [];
		foreach($collections as $name => $collection){
			$filename = $datasetName . '_' . $name . '.json';
			$filepath = "$tempDir/$filename";
			file_put_contents($filepath, json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
			$jsonFiles[] = $filepath;
		}

		// Create zip
		$zipFile = "$tempDir/{$datasetName}_GeMS.zip";
		$zipCmd = 'cd ' . escapeshellarg($tempDir) . ' && zip -j ' . escapeshellarg($zipFile);
		foreach($jsonFiles as $jf){
			$zipCmd .= ' ' . escapeshellarg(basename($jf));
		}
		exec($zipCmd);

		// Serve download
		if(file_exists($zipFile)){
			header('Content-Type: application/zip');
			header('Content-Disposition: attachment; filename="' . $datasetName . '_GeMS.zip"');
			header('Content-Length: ' . filesize($zipFile));
			readfile($zipFile);
		} else {
			echo 'Error: Failed to create zip file.';
		}

		// Cleanup
		foreach($jsonFiles as $jf){
			if(file_exists($jf)) unlink($jf);
		}
		if(file_exists($zipFile)) unlink($zipFile);
		if(is_dir($tempDir)) rmdir($tempDir);
	}

}
?>