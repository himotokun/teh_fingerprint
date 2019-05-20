<?php

/* Server class of the Total Fingerprinting System, version 0.1 alpha. */

// For testing purposes
if(!function_exists('mysql_real_escape_string')){
	function mysql_real_escape_string($s){
		return $s;
	}
}

class simpleSql{
	private $mysqliObject;
	private $tableName;
	private $cachedData;

	function __construct($serverAddr, $login, $password, $dbName,
				$tableName = null){
		$this->mysqliObject = mysqli_connect($serverAddr, $login,
			$password, $dbName);
		if(!empty($tableName)){
			$this->tableName = $tableName;
			$this->cachedData = $this->loadTable($tableName);
		}else{
			$this->tableName = null;
			$this->cachedData = null;
		}
	}

	private function assocToQueryString($assocData){
		$queryString = '';
		foreach($assocData as $key=>$value){
			if(!empty($queryString))
				$queryString .= ', ';
			$queryString .= $key.' = '.
				(gettype($value) == 'string' ?
					'\''.mysql_real_escape_string($value).'\'' : $value);
		}
		return $queryString;
	}

	function dbQuery($q){
		$sqlData = mysqli_query($this->mysqliObject, $q);
		if($sqlData === true || $sqlData === false)
			return null;
		for($sqlAssoc = [];
			$row = mysqli_fetch_assoc($sqlData); $sqlAssoc[] = $row)
			;
		/*if(count($sqlAssoc) == 1)
			$sqlAssoc = $sqlAssoc[0];*/
		return $sqlAssoc;
	}

	function loadTable($tableName = null){
		if(empty($tableName) && !empty($this->tableName)){
			if(empty($this->cachedData))
				$this->cachedData = $this->loadTable($this->tableName);
			return $this->cachedData;
		}else{
			return $this->dbQuery('SELECT * FROM '.
				mysql_real_escape_string($tableName));
		}
	}

	function insertSign($tableName, $assocData = null){
		// If the table is fixed, shift args
		if(empty($assocData) && !empty($this->tableName)){
			$assocData = $tableName;
			$tableName = $this->tableName;
		}
		$queryString = $this->assocToQueryString($assocData);
		$this->dbQuery('INSERT INTO '.mysql_real_escape_string($tableName).
			' SET '.$queryString);
		$this->cachedData = null;
	}

	function updateSign($tableName, $keyColumn, $keyValue, $assocData = null){
		// If the table is fixed, shift args
		if(empty($assocData) && !empty($this->tableName)){
			$assocData = $keyValue;
			$keyValue = $keyColumn;
			$keyColumn = $tableName;
			$tableName = $this->tableName;
		}
		$queryString = $this->assocToQueryString($assocData);
		$this->dbQuery('UPDATE '.mysql_real_escape_string($tableName).
			' SET '.$queryString.' WHERE '.mysql_real_escape_string($keyColumn)
			.' = '.(gettype($keyValue) == 'string' ? '\''.
			mysql_real_escape_string($keyValue).'\'' : $keyValue));
		$this->cachedData = null;
	}

}

class tfpServer{

	// Working data of the entire system

	/* This is an array of events. Event is an array:
	 * ['type'=>(string), 'data'=>(array), 'id'=>(int), 'timestamp'=>(string)]
	 * - Type is a name of processing event. At the moment the system might work
	 * with 'keyboard', 'mouse' and 'scroll' events;
	 * - Data contains some values depending on type;
	 * - Id contains number of event __OF ITS TYPE__, beginning from 1;
	 * - Timestamp contains time of event SENDING encoded as string. */
	private $currentStateRegister;

	// This is just a result of analyzeData() applied to $currentStateRegister
	private $currentVector;

	/* An array of users prediction. This array looks like
	 *        ['user' => 1337, 'p' => 0.874276152]
	 * where 'user' is user's id, and 'p' is the probability between 0 and 1.
	 * We decide that the user is well-defined when the p value is more than
	 * RECOGNITION_THRESHOLD, which is defined below. */
	private $predictionVector;

	/* This var contains everything for working with SQL database.
	 * It is an object of class simpleSql (see above) */
	private $mappedSqlTable;

	/* This var is used for checking the timeout.
	 * It is set when the first event comes, and checks at every coming event.
	 * After the timeout the result is being output. */
	private $startedTimestamp;

	/* If this var is set, the system is being stopped. */
	private $finalUserId;

	// Parameters of the system
	
	/* This is a time limit for obtaining the information.
	 * After this timeout we the system should return a certain user's id,
	 * otherwise the new user should be created in the database. */
	private const PREDICTION_TIMEOUT = 10000; // in milliseconds

	private const RECOGNITION_THRESHOLD = 0.5; // min accepted probability

	function __construct(bool $restore = true){
		if($restore){
			$this->systemLoad();
		}else
			$this->systemReset();
	}

	function __destruct(){
		$this->systemSave();
	}

	private function systemLoad(){
		if(!session_id())
			session_start();
		$this->currentStateRegister = $_SESSION['FP_currentStateRegister'];
		$this->currentVector = $_SESSION['FP_currentVector'];
		$this->predictionVector = $_SESSION['FP_predictionVector'];
		$this->startedTimestamp = $_SESSION['FP_startedTimestamp'];
		$this->finalUserId = $_SESSION['FP_finalUserId'];
		$this->loadSqlTable();
	}

	private function systemSave(){
		if(!session_id())
			session_start();
		$_SESSION['FP_currentStateRegister'] = $this->currentStateRegister;
		$_SESSION['FP_currentVector'] = $this->currentVector;
		$_SESSION['FP_predictionVector'] = $this->predictionVector;
		$_SESSION['FP_startedTimestamp'] = $this->startedTimestamp;
		$_SESSION['FP_finalUserId'] = $this->finalUserId;
	}

	private function systemReset(){
		$this->currentStateRegister = [];
		$this->currentVector = [];
		$this->predictionVector = [];
		$this->startedTimestamp = null;
		$this->finalUserId = null;
		$this->loadSqlTable();
	}

	private function loadSqlTable(){
		$this->mappedSqlTable = new simpleSql('localhost', 'root',
			'haha_did_y0u_r34lly_th1nk_y0u_will_see_my_p4ssw0rd_h3r3?',
			'my_super_secret_db', 'fingerprint_table');
	}

	/* This function pushes an event to the register */
	private function pushEvent($event){
		$this->currentStateRegister []= $event;
		if(empty($this->startedTimestamp))
			$this->startedTimestamp = $event['timestamp'];
	}

	private function recountVector(){
		$this->predictionVector = [];
		$this->currentVector = $this->analyzeData();
		$tempVector = [];

		$table = $this->mappedSqlTable->loadTable();
		$p_sum = 0.0;
		if(empty($table))
			return [];
		foreach($table as $tableSign){
			$userArray = $this->parseData($tableSign['data']);
			$p_first = 
				// TODO choose the function
				$this->measureDifference($this->currentVector, $userArray);
			$p_sum += $p_first;
			$newSign = ['user' => $tableSign['id'], 'p' => $p_first,
				'nickname' => $tableSign['nickname']];
			$tempVector []= $newSign;
		}
		foreach($tempVector as $userIter){
			$newSign['user'] = $userIter['user'];
			if(count($tempVector) == 1){
				$newSign['p'] = 1.0 - $userIter['p'];
				if($newSign['p'] < 0.0)
					$newSign['p'] = 0.0;
			}else
				$newSign['p'] = 1.0 - $userIter['p'] / $p_sum;
			$this->predictionVector []= $newSign;
		}

		// Sorting by probability descend
		array_multisort(array_column($this->predictionVector, 'p'), SORT_DESC,
			$this->predictionVector);

		return $this->predictionVector;
	}

	// Checks if the data is even real for a human
	private function checkIfNotBot(){
		// TODO implement
	}

	private function confirmUser($confirmId){
		foreach($this->predictionVector as $i)
			if($i['user'] == $confirmId){
				$this->mappedSqlTable->updateSign('id', $i['user'],
					['data' => $this->serializeData($this->currentVector) ]);
				return;
			}
	}

	private function createNewUser($nickname = null){
		$data = $this->serializeData($this->currentVector);
		$this->mappedSqlTable->insertSign(
			['data' => $data, 'nickname' => $nickname]);
		// idk yet how else todo this
		return count($this->mappedSqlTable->loadTable());
	}



	/*** THIS IS WHAT ALL WE INVESTIGATED FOR ***/
	/* This is the very main function of the whole system */
	/* It makes some analysis of the presented data and
	 * gives us us the parameters which we can use to
	 * identify our dear customers. */

	/* This function makes an array which contains some data for every
	 * type of events. Example: ['keyboard'=>[...], 'mouse'=>[...], ...] */

	private function analyzeData(){
		$resultArray = [];
		$resultArray['keyboard'] = $this->analyzeData_Keyboard();
		$resultArray['scroll'] = $this->analyzeData_Scroll();
		$resultArray['mouse'] = $this->analyzeData_Mouse();

		return $resultArray;//$this->normalizeData($resultArray);
	}

	/* Here the keyboard events are analyzed.
	 * The output has 3 parameters:
	 * 1. Average typing speed (in symbols per minute);
	 * 2. Average keypress length (in milliseconds);
	 * 3. Typo quotient (by count of 'backspace' pressing);
	 *
	 * The structure of keyboard event data is:
	 *        ['action' => {'up', 'down'}, 'code'=> (int)keycode]
	 * */
	private function analyzeData_Keyboard(){

		/* Maximal pause between letters. If time difference is more than
		 * the timeout, it's decided as a new typing 'instance'.
		 * In milliseconds. */
		$typeTimeout = 3000;

		$speedAcc = ['time' => 0, 'count' => 0, 'last_timestamp' => null];

		$lengthAcc = ['time' => 0, 'count' => 0, 'last_action' => null,
			'last_code' => null, 'last_timestamp' => null];

		$typoAcc = ['count_total' => 0, 'count_backspace' => 0];

		$backspaceKeycode = 8;

		foreach($this->currentStateRegister as $index=>$event){
			if($event['type'] != 'keyboard')
				continue;

			if($event['action'] == $lengthAcc['last_action'] &&
				$event['code'] == $lengthAcc['last_code'])
				continue;

			// Process speed
			if($event['action'] == 'down'){
				if($speedAcc['count'] > 0 && $typeTimeout >
						$event['timestamp'] - $speedAcc['last_timestamp']){

					$speedAcc['time'] +=
						$event['timestamp'] - $lengthAcc['last_timestamp'];
				}
				$speedAcc['count']++;
				$speedAcc['last_timestamp'] = $event['timestamp'];
			}

			// Process length
			if($event['action'] == 'up' &&
					$lengthAcc['last_action'] == 'down' &&
					$lengthAcc['last_code'] == $event['code']){

				$lengthAcc['count']++;
				$lengthAcc['time'] +=
					$event['timestamp'] - $lengthAcc['last_timestamp'];
			}
			$lengthAcc['last_action'] = $event['action'];
			$lengthAcc['last_code'] = $event['code'];
			$lengthAcc['last_timestamp'] = $event['timestamp'];

			// Process typos
			if($event['action'] == 'down'){
				$typoAcc['count_total']++;
				if($event['code'] == $backspaceKeycode)
					$typoAcc['count_backspace']++;
			}
		}

		if($speedAcc['time'] > 0)
			$resultSpeed =
				(int)($speedAcc['count'] / ($speedAcc['time'] / 60000));
		else
			$resultSpeed = 0;
		
		if($lengthAcc['count'] > 0)
			$resultLength = $lengthAcc['time'] / $lengthAcc['count'];
		else
			$resultLength = 0;
		
		if($typoAcc['count_total'] > 0)
			$resultTypo =
				$typoAcc['count_backspace'] / $typoAcc['count_total'];
		else
			$resultTypo = 0;

		$result = [
			'speed' => $resultSpeed,
			'length' => $resultLength,
			'typo' => $resultTypo
		];

		return $result;
	}

	/* Here the scrolling event are analyzed.
	 * The output has 2 parameters:
	 * 1. Average scrolling speed at all (in pixels * width kpx per minute)
	 * 2. Average scrolling span by slice (in pixels * width kpx)
	 * 3. Average scrolling time by slice (in milliseconds)
	 *
	 * The structure of scrolling event data is:
	 *        ['offset' => (int)px, 'width' => (int)px]
	 * */
	private function analyzeData_Scroll(){

		// In miliseconds
		//$stopTimeout = 500;
		// or
		$stopQuotient = 10;

		$widthNormQuot = 1000;

		$speedAcc = ['start_timestamp' => null, 'start_offset' => null,
			'last_offset' => null, 'last_timestamp' => null];

		$spanAcc = ['event_time' => 0, 'event_count' => 0,
			'start_offset' => null, 'current_offset' => null,
			'total_length' => 0, 'total_time' => 0, 'slice_count' => 0,
			'last_timestamp' => null];

		foreach($this->currentStateRegister as $index=>$event){
			if($event['type'] != 'scroll')
				continue;

			// Normalize offset using screen width
			$event['offset'] *=
				$event['width'] / $widthNormQuot;

			// Process speed
			
			if($speedAcc['start_offset'] === null)
				$speedAcc['start_offset'] = $event['offset'];
			else
				$speedAcc['last_offset'] = $event['offset'];

			if(empty($speedAcc['start_timestamp']))
				$speedAcc['start_timestamp'] = $event['timestamp'];
			else
				$speedAcc['last_timestamp'] = $event['timestamp'];

			// Process spans

			/*if($event['timestamp'] - $spanAcc['last_timestamp']
				< $stopTimeout){*/
			if($spanAcc['event_count'] > 1 &&
				$event['timestamp'] - $spanAcc['last_timestamp'] >
				$stopQuotient *
				($spanAcc['event_time'] / $spanAcc['event_count'])){
				// Make a new slice
				$spanAcc['total_length'] +=
					abs($spanAcc['current_offset'] - $spanAcc['start_offset']);
				$spanAcc['current_offset'] = $spanAcc['start_offset'] = null;
				$spanAcc['total_time'] += $spanAcc['event_time'];
				$spanAcc['event_time'] = 0;
				$spanAcc['event_count'] = 0;
				$spanAcc['last_timestamp'] = null;
				$spanAcc['slice_count']++;
			}
			// Add to current slice
			if($spanAcc['start_offset'] === null)
				$spanAcc['start_offset'] = $event['offset'];
			$spanAcc['current_offset'] = $event['offset'];
			if(!empty($spanAcc['last_timestamp'])){
				$spanAcc['event_time'] +=
					$event['timestamp'] - $spanAcc['last_timestamp'];
			}
			$spanAcc['last_timestamp'] = $event['timestamp'];
			$spanAcc['event_count']++;
		}


		/*echo '<br><br>';
		var_dump($speedAcc);
		echo '<br><br>';
		var_dump($spanAcc);
		echo '<br><br>';*/

		if($spanAcc['slice_count'] == 0)
			return [];

		$resultSpeed = ($speedAcc['last_offset'] - $speedAcc['start_offset'])
			/ ($speedAcc['last_timestamp'] - $speedAcc['start_timestamp']);

		$resultSliceSpan = $spanAcc['total_length'] / $spanAcc['slice_count'];

		$resultSliceTime = $spanAcc['total_time'] / $spanAcc['slice_count'];

		$result = [
			'speed' => $resultSpeed,
			'slice_span' => $resultSliceSpan,
			'slice_time' => $resultSliceTime,
			// debugging field
			'slice_count' => $spanAcc['slice_count']
		];

		return $result;
	}

	private function analyzeData_mouse(){

		$strokeQuotient = 5;

		$totalPath = 0;
		$totalDelta = 0;
		$totalTime = 0;
		//$totalAngle = 0;
		$totalPressLength = 0;
		$strokeCount = 0;
		$dotsCount = 0;
		$pressCount = 0;
		$lastTimestamp = null;
		$lastDownTimestamp = null;
		$startTimestamp = null;
		$startX = null;
		$startY = null;
		$lastX = null;
		$lastY = null;

		foreach($this->currentStateRegister as $index=>$event){
			if($event['type'] != 'mouse')
				continue;

			/*echo 'l: '.$lastTimestamp;
			echo '<br><br>';
			echo 's: '.$startTimestamp;
			echo '<br><br>';*/
			if($dotsCount > 1 && ($event['timestamp'] - $lastTimestamp) >
				$strokeQuotient * ($lastTimestamp - $startTimestamp) /
				$dotsCount){ // if new stroke
				// Save the data of current stroke
				$totalDelta += sqrt(($lastX - $totalStartX)**2 +
					($lastY - $totalStartY)**2 );
				$totalTime += $lastTimestamp - $startTimestamp;
				// Renew stroke iterators
				$dotsCount = 0;
				$strokeCount++;
				$startX = null;
				$startY = null;
				$lastX = null;
				$lastY = null;
				$startTimestamp = null;
				$lastTimestamp = null;
			}
			if($event['action'] == 'move'){
				$totalPath += sqrt(($lastX - $event['x']) ** 2 +
					($lastY - $event['y']) **2);
				$dotsCount++;
				if(empty($startTimestamp))
					$startTimestamp = $event['timestamp'];
				$lastTimestamp = $event['timestamp'];
				if(empty($startX))
					$startX = $event['x'];
					$startY = $event['y'];
				$lastX = $event['x'];
				$lastY = $event['y'];
			}elseif($event['action'] == 'down'){
				$lastDownTimestamp = $event['timestamp'];
			}elseif($event['action'] == 'up'){
				if(!empty($lastDownTimestamp)){
					$totalPressLength +=
						$event['timestamp'] - $lastDownTimestamp;
					$pressCount++;
				}
			}else
				throw new Exception('tfpServer: unknown mouse action');

		}

		$resultSpeed = ($totalTime == 0 ? 0 :
			$totalPath / $totalTime);

		//$resultStraightness = ($strokeCount == 0 ? 0 :
		//	$totalAngle / $strokeCount);

		$resultPrecision = ($totalDelta == 0 ? 0 : $totalPath / $totalDelta);

		$resultSpan = ($strokeCount == 0 ? 0 : $totalPath / $strokeCount);

		$resultStrokeTime = ($strokeCount == 0 ? 0 :
			$totalTime / $strokeCount);

		$resultPressLength = ($pressCount == 0 ? 0 :
			$totalPressLength / $pressCount);

		$result = [
			'speed' => $resultSpeed,
			//'straightness' => $resultStraightness,
			'precision' => $resultPrecision,
			'span' => $resultSpan,
			'stroke_time' => $resultStrokeTime,
			'press_length' => $resultPressLength,
			// debugging field
			'stroke_count' => $strokeCount
		];
		return $result;
	}

	/* Makes a new 'data' entry for the database */
	private function serializeData($data){
		return json_encode($data);
	}

	private function parseData($data){
		return json_decode($data, true);
	}

	private function countNormQuots(){
		$result = [];
		foreach($this->mappedSqlTable->loadTable() as $tableSign){
			$signArray = $this->parseData($tableSign['data']);
			foreach($signArray as $k_i=>$v_i)
				foreach($v_i as $k_j=>$v_j)
					$result[$k_i][$k_j] += $v_j;
		}
		$signCount = count($this->mappedSqlTable->loadTable());
		foreach($result as &$v_i)
			foreach($v_i as &$v_j)
				$v_j /= $signCount;
		return $result;
	}

	private function normalizeData($arrayData){

		$normalizationQuotients = $this->countNormQuots();
		$normalizedData = [];

		if(empty($arrayData))
			return null;
		foreach($arrayData as $k_i=>$v_i)
			foreach($v_i as $k_j=>$v_j)
				if(!empty($normalizationQuotients[$k_i][$k_j]))
					$normalizedData[$k_i][$k_j] = $arrayData[$k_i][$k_j]
						/ $normalizationQuotients[$k_i][$k_j];

		return $normalizedData;
	}

	private function measureDifference($arrayData1, $arrayData2){
		if(empty($arrayData1) || empty($arrayData2))
			return null;

		$normalizedArray1 = $this->normalizeData($arrayData1);
		$normalizedArray2 = $this->normalizeData($arrayData2);

		$value = 0.0;

		// Difference norm (abs) value counting
		// The function is: SUM[i] of (a_i - b_i)^2
		foreach($normalizedArray1 as $k_i=>$v_i)
			foreach($v_i as $k_j=>$v_j){
				if(!isset($normalizedArray1[$k_i][$k_j]) ||
							!isset($normalizedArray2[$k_i][$k_j]))
					continue;
				$value += ($normalizedArray1[$k_i][$k_j] -
					$normalizedArray2[$k_i][$k_j]) ** 2;
			}

		return $value;
	}

	private function measureDifference2($arrayData1, $arrayData2){
		if(empty($arrayData1) || empty($arrayData2))
			return null;

		$value = 0.0;

		foreach($arrayData1 as $k_i=>$v_i)
			foreach($v_i as $k_j=>$v_j){
				if(!isset($arrayData[$k_i][$k_j]) ||
							!isset($arrayData2[$k_i][$k_j]))
					continue;
				if($arrayData2[$k_i][$k_j] == 0.0)
					$value += $arrayData1[$k_i][$k_j] ** 2;
				else
					$value += ($arrayData1[$k_i][$k_j] /
						$arrayData2[$k_i][$k_j] - 1) ** 2;
			}

		return $value;
	}

	/* Interpolates the register if any skipped id's */
	private function interpolateDataIfNeeded(){
		// TODO implement
	}

	/* This is the main API function which processes the POST requests */
	private function parseRequest($newServerRequest){
		/* Fields of acceptable POST request:
		 * 'type' - type of event:
		 * 1. 'keyboard'
		 * 2. 'mouse'
		 * 3. 'scroll'
		 * 1337. etc
		 *
		 * Other fields depend on the type:
		 * 1. Keyboard:
		 * - 'action' is 'down' for keydown, 'up' for keyup;
		 * - 'code' is the keycode.
		 *
		 * 2. Mouse:
		 * - 'x' is x coord, 'y' is y coord
		 *
		 * 3. Scroll:
		 * - 'direction' is 'up' if scrolling up, 'down' if down;
		 * - 'length' is length of scrolling (in pixels)
		 * - 'method' is type: 'keys' for pgup/pgdn keys,
		 * 'wheel' for mouse wheel scrolling,
		 * 'strip' for scrolling strip movement, 'touch' for touchscreen.
		 */
		$newRegisterEntry = [
			'type' => $newServerRequest['type'],
			'id' => $newServerRequest['id'],
			'timestamp' => $newServerRequest['timestamp']
		];
		switch($newRegisterEntry['type']){
			case 'keyboard':
				$newRegisterEntry['action'] = $newServerRequest['action'];
				$newRegisterEntry['code'] = $newServerRequest['code'];
				break;
			case 'mouse':
				$newRegisterEntry['action'] = $newServerRequest['action'];
				$newRegisterEntry['x'] = $newServerRequest['x'];
				$newRegisterEntry['y'] = $newServerRequest['y'];
				break;
			case 'scroll':
				$newRegisterEntry['offset'] = $newServerRequest['offset'];
				$newRegisterEntry['width'] = $newServerRequest['width'];
				break;
			default:
				throw new Exception('tfpServer: unknown request type');
		}
		$this->pushEvent($newRegisterEntry);
	}

	/*** Public section ***/

	public function getPredictionVector(){
		return $this->recountVector();
	}

	/* This function outputs the result of all calculations */
	public function tryGetUserId(){
		$this->recountVector();
		if(empty($this->predictionVector))
			return null;
		// TODO remove this for release
		if(1 || $this->predictionVector[0]['p'] >= self::RECOGNITION_THRESHOLD)
			return $this->predictionVector[0]['user'];
		else
			return null;
	}

	/*public function getUserId(){
		if(!empty($this->finalUserId))
			return $this->finalUserId;
		$id = $this->tryGetUserId();
		if(!empty($id))
			$this->confirmUser($id);
			//$id = 'user confirmed';
		elseif(end($this->currentStateRegister)['timestamp'] -
				$this->startedTimestamp > self::PREDICTION_TIMEOUT)
			$id = $this->createNewUser();
			//$id = 'user created';
		else
			$id = null;
		$this->finalUserId = $id;
		return $id;
	}*/

	/*public function quickCycle($q){
		$this->parseRequest($q);
		//$this->getUserId();
		echo json_encode($this->currentVector);
		echo '<br><br>';
		return $this->recountVector();
	}*/

	// Legacy
	public function quickCycle($q){
		if($q['control'] == 'only-store')
			return $this->store_data($q);
		elseif($q['control'] == 'only-count')
			//return $this->store_and_count($q, true);
			return $this->store_and_count($q);
		elseif($q['control'] == 'force-confirm')
			return $this->force_confirm();
		elseif($q['control'] == 'force-create')
			return $this->force_create();
		else
			return 'no control field';
	}

	public function store_data($q){
		$this->parseRequest($q);
		return 'ok, stored';
	}

	public function store_and_count($q, bool $debug = false){
		$this->parseRequest($q);
		if($debug){
			echo json_encode($this->currentVector);
			echo '<br><br>';
		}
		return $this->recountVector();
	}

	public function force_confirm(){
		$this->recountVector();
		$id = $this->tryGetUserId();
		if(empty($id))
			return 'nothing to confirm :(';
		$this->confirmUser($id);
		return 'ok, confirmed';
	}

	public function force_create(){
		$this->recountVector();
		$id = $this->createNewUser($_POST['nickname']);
		if(empty($id))
			return 'something went wrong :(';
		return 'ok, created';
	}

}

?>
