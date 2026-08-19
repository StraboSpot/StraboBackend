<?

$manualtype = pg_escape_string($_GET['t']);

if($manualtype == "field"){
	$filename = "StraboField_Manual.pdf";
}elseif($manualtype == "micro"){
	$filename = "StraboMicro_Manual.pdf";
}elseif($manualtype == "micro2"){
	$filename = "StraboMicro2_Manual.pdf";
}elseif($manualtype == "experimental"){
	$filename = "StraboExperimental_Manual.pdf";
}elseif($manualtype == "tools"){
	$filename = "StraboTools_Manual.pdf";
}else{
	exit("Incorrect manual type provided.");
}

header("Content-type:application/pdf");
header("Content-Disposition:inline;filename=\"$filename\"");
readfile("manuals/$manualtype.pdf");

?>