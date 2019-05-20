<?php

$GLOBALS['__RAW_OUTPUT__'] = true;

/*function randomWords($count){
	$abc_big = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
	$abc = 'abcdefghijklmnopqrstuvwxyz';
	$abc_vow = 'aeiouy';
	$sent = rand(3, 10);
	$text = $abc_big[rand(0,25)];
	for($i = 0, $j = 0; $i < $count; $i++, $j++){
		if($j == $sent){
			$sent = rand(3, 10);
			$text[-1] = '.';
			$text .= ' '.$abc_big[rand(0, 25)];
			$j = -1;
		}
		$wlen = rand(1, 7);
		for($k = 0; $k < $wlen; $k++){
			$text .= $abc[rand(0, 25)];
			if($k % rand(2, 3) == 0)
				$text .= $abc_vow[rand(0,5)];
		}
		$text .= ' ';
		if($i % rand(100, 200) == 0){
			$text[-1] = '.';
			$text .= '<br><br>'.$abc_big[rand(0,25)];
		}
	}
	return $text;
}*/

function showUserWindow(){
	echo '<style>#yes, #no{
	border:2px black solid;
	border-radius:0;
	background-color:white;
	padding:8px 20px;
	cursor:pointer;
	transition:all 0.3s linear 0;
	font-size:14px;
	font-family:sans-serif;
	}
	button:hover{
	background-color:black;
	color:white;
	transition:all 0.3s linear 0;
	}</style>';
	echo '<div style="
	border:3px gray solid;
	background-color: #eee;
	border-radius: 10px;
	min-height:150px;
	max-width:300px;
	padding:0px 20px;
	margin:20px;
	text-align:center;
	font-family:sans-serif;
	position:fixed;
	top:20px;
	left:20px;
	z-index:9999999;
	display:none"
	id="user_window">';
	echo '<br>';
	echo '<p id="x">Ваше имя — <span id="nickname">7h3_5up3r_h4ck3r</span>
		с&nbsp;вероятностью <span id="p_value">87</span>%!</p>';
	echo '<br><br>';
	echo '<div style="margin:0 50px">';
	echo '<button style="float:left" id="yes">Да</button>';
	echo '<button style="float:right" id="no">Нет</button>';
	echo '<div style="clear:both"></div>';
	echo '</div></div>';
}

function showDebugWindow(){
	echo '<div style="background-color:black; id="debug_window"
		position:fixed; top:40%; left:0px; margin:25px;
		max-height:40%; overflow:auto; z-index:999999999">';
	echo '<p id="result-text" style="margin:25px; font-weight:800;
		font-family:courier; font-size:12px; color:#0f0">T357 M3!</p>';
	echo '</div>';
}

function loadFingerprintingScript(string $mode, bool $debug){
	echo '<script>';
	if($mode == 'store')
		echo 'var control = "only-store";';
	elseif($mode == 'check')
		echo 'var control = "only-count";';
	echo 'var debug = '.($debug ? 'true' : 'false').';';
	require_once 'client.js';
	echo '</script>';
}

if(!function_exists('__showContent')){
	function __showContent(){
		echo 'It works!';
		/*showUserWindow();
		showDebugWindow();*/
	}
}

function loadFingerprint(string $mode){
	if($mode == 'store'){
		//showDebugWindow();
		loadFingerprintingScript('store', false);
	}elseif($mode == 'check'){
		showUserWindow();
		//showDebugWindow();
		loadFingerprintingScript('check', false);
	}else{
		throw 'tfpClient: unknown mode';
	}
}

?>
