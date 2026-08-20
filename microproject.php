<?

include("prepare_connections.php");


//include("logincheck.php");
SESSION_START();

$userpkey = $_SESSION['userpkey'];
if($userpkey == "") $userpkey = 999999;

//https://strabospot.org/microproject?id=385



//<script src='/assets/js/wheelZoom/wheelzoom.js'></script>
//<script src='/assets/js/mapZoom/mapzoom.js'></script>


require_once(__DIR__ . '/microdb/lib/permalink.php');

// This page is the tier-agnostic front door for Micro landing URLs.
// Preferred form: ?m=<permalink slug> (upload-stable, see microdb/lib/permalink.php).
// Legacy form:    ?id=<pkey> (still honored; upgraded to ?m= when possible).
$m = isset($_GET['m']) ? strtolower(trim($_GET['m'])) : '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$row = null;

if($m !== ''){
	$resolved = micro_permalink_resolve($db, $m);
	if($resolved !== null){
		$row = $db->get_row_prepared("select * from micro_projectmetadata where id = $1 and (ispublic or userpkey = $2)", array((int)$resolved->id, (int)$userpkey));
		if($row && $row->id != "") $id = (int)$row->id; else $row = null;
	}
}elseif($id > 0){
	$row = $db->get_row_prepared("select * from micro_projectmetadata where id = $1 and (ispublic or userpkey = $2)", array($id, (int)$userpkey));
	if(!$row || $row->id == "") $row = null;
}

if($row === null){
	echo "Error! Project not found.";
	exit();
}

// Legacy pkey arrival: upgrade the address bar to the upload-stable permalink
// so refresh and re-share keep working after the project's next upload.
if($m === ''){
	$slug = micro_permalink_get_or_create($db, $row->strabo_id, (int)$row->userpkey);
	if($slug !== null){
		header("Location: /microproject?m=$slug");exit();
	}
}


//echo $_SERVER['DOCUMENT_ROOT'];exit(); ///srv/app/www

// Tier routing by straboMicroFiles/<pkey> contents. Slug arrivals redirect
// slug-to-slug so the address bar never holds a perishable pkey URL.
//Look for directory: /straboMicroFiles/726/tiles
if (is_dir($_SERVER['DOCUMENT_ROOT']."/straboMicroFiles/$id/tiles")) {
	if($m !== ''){
		header("Location: /microview/?m=$m");exit();
	}
	header("Location: /microview/?p=$id");exit();
	//echo "found it!";
}
if ($m !== '' && is_dir($_SERVER['DOCUMENT_ROOT']."/straboMicroFiles/$id/webImages")) {
	header("Location: /straboMicroView/view?m=$m");exit();
}

include 'includes/header.php';
include 'microdb/microLandingClass.php';
?>



<style type='text/css'>
	#leftColumn{
		background-color:white;
		float: left;
		width: 220px;
		max-height: 500px;
		overflow: auto;
	}
	#centerColumn{
		background-color:white;
		float: left;
		width: 760px;
		padding-left:10px;
	}
	#rightColumn{
		background-color:white;
		float: left;
		width: 300px;
		height: 1000px;
		padding-left:5px;
	}
	#clearLeft{
		clear: left;
	}
	.projectWrapper{
		width:1500px !important;
	}

	
</style>
<?



$json = $row->projectjson;
$json = json_decode($json);
// Overlay strabosamples.* spine edits onto the samples so this public view
// reflects Samples-app edits. Owner is the project owner ($row->userpkey),
// which differs from the session viewer for a public project.
require_once __DIR__ . '/microdb/lib/sample_overlay.php';
micro_sample_overlay_apply($json, $db, (int)$row->userpkey);
$json->pkey = $id;
$ml = new MicroLanding($json);

//$db->dumpVar($json);exit(); //https://strabospot.org/download_micro_file?project_id=195
?>

<script>

	function switchToMicrograph(pkey, id){
		//alert('Switch to Micrograph '+pkey+' '+id);
		jQuery('#centerColumn').html('loading...&nbsp;');
		console.log("https://strabospot.org/micrographBigWindow?pkey="+pkey+"&micrograph_id="+id);
		jQuery.get( "https://strabospot.org/micrographBigWindow?pkey="+pkey+"&micrograph_id="+id, function( data ) {
			jQuery( "#centerColumn" ).html( data );
			//alert( "Load was performed." );
			//console.log(data);
		});

		jQuery('#sideDetails').attr('src', "/micrographDetailsPane?type=micrograph&pkey="+pkey+"&id="+id);
	}
	
	function showSpotDetails(pkey, id){
		jQuery('#sideDetails').attr('src', "/micrographDetailsPane?type=spot&pkey="+pkey+"&id="+id);
	}
	
	function testChangeContent(){
	
		var loc = "/micrographDetailsPane?type=micrograph&pkey=193&id=16384581687565";
		jQuery('#sideDetails').attr('src', loc);
	
	}
	
</script>

<div class="projectWrapper">

	<div style="float:left; width: 200px;">&nbsp;</div>
	<div style="float:left; width: 760px; padding-left:10px; text-align:center;"><h2>Project: <?=$json->name?></h2></div>
	<div style="float:left; width: 300px; text-align:left; padding-left: 100px; vertical-align:baseline; font-size:1.2em; background-color:white;">
		<a href="/download_micro_file?project_id=<?=$id?>">
			<img src="/assets/files/micro_download.png" width="13px" style="vertical-align:baseline;"> Download .SMZ
		</a>
		<?
		if(file_exists($_SERVER['DOCUMENT_ROOT']."/straboMicroFiles/".$id."/project.pdf")){
		?>
		<br>
		<a href="/download_micro_pdf?project_id=<?=$id?>" target="_blank">
			<img src="/assets/files/micro_download.png" width="13px" style="vertical-align:baseline;"> Download .PDF
		</a>
		<br>
		<?
			if($userpkey != 999999){
		?>
		<a href="/share_micro_file?project_id=<?=$id?>" target="_blank">
			<img src="/assets/files/micro_share.png" width="13px" style="vertical-align:baseline;"> Share Project File
		</a>
		<?
			}
		}
		?>
	</div>
	<div style="clear:left;">&nbsp;</div>
	
	<div id="leftColumn">
		<?
		$ml->sideBarHTML();
		?>
	</div>
	<div id="centerColumn">
		<?
		$ml->showFirstMicrograph();
		?>
	</div>
	<div id="rightColumn">
		<?
		$firstId = $ml->getFirstMicrographId();
		?>
		<iframe id="sideDetails" src="/micrographDetailsPane?type=micrograph&pkey=<?=$id?>&id=<?=$firstId?>" width="300" height="1000" frameborder="0" scrolling="no"></iframe>
	</div>
	<div id="clearLeft">
	</div>










<?
//$sm->htmlProject($json);
?>

</div>



<?
include 'includes/footer.php';
?>