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
}elseif($manualtype == "samples"){
	$filename = "StraboSamples_Manual.pdf";
}elseif($manualtype == "groupworkflows"){
	$filename = "StraboField_Group_Workflows.pdf";
}elseif($manualtype == "tools"){
	$filename = "StraboTools_Manual.pdf";
}else{
	exit("Incorrect manual type provided.");
}

if(!file_exists("manuals/$manualtype.pdf")){
	http_response_code(404);
	exit("This manual is not available yet.");
}

header("Content-type:application/pdf");
header("Content-Disposition:inline;filename=\"$filename\"");
readfile("manuals/$manualtype.pdf");

?>