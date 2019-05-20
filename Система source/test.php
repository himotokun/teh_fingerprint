<?php

$GLOBALS['__RAW_OUTPUT__'] = true;

function __showContent(){
	//phpinfo();
	//return;
	require_once 'main.php';
	$T = new tfpServer();
	$result = $T->quickCycle($_POST);
	echo json_encode($result);
}

?>
