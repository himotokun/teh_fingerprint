var keyboard_id = 1;
var scroll_id = 1;
var mouse_id = 1;
var nickname = document.querySelector('#nickname');
var p_value = document.querySelector('#p_value');
var XXX = document.querySelector('#x');
var popupTime = 10000;

function makePostParams(data){
	var text = '';
	for(i in data){
		if(text.length > 0)
			text += '&';
		text += i + '=' + data[i];
	}
	return text;
}

function showResult(){
	if(control == 'only-store')
		return;
	if(this.readyState == 4){
		// this is for super-low-level debugging
		XXX.innerHTML = this.responseText;
		return;
		result = this.responseText.replace(/<\/?[^>]+>/g,'');
		result = result.replace('TEST PAGE', ''); // the kostyl'
		//alert(result);
		array = JSON.parse(result);
		nickname.innerHTML = array[0]['nickname'];
		p_value.innerHTML = array[0]['p'] * 100;
		//T.innerHTML += '<br><br>+++ Response +++<br>' + result;
	}
}

function showRequest(data){
	if(debug){
		document.querySelector('#result-text').innerHTML =
			'<br>*** Request ***<br>' + JSON.stringify(data) + '<br>';
	}
}

function sendRequest(data, callback){
	if(control == 'stop')
		return;
	if(!('control' in data))
		data['control'] = control;
	var url = '/ru/test';
	var request = new XMLHttpRequest();
	request.open('POST', url, true);
	request.setRequestHeader('Content-Type',
		'application/x-www-form-urlencoded');
	if(callback != undefined)
		request.onreadystatechange = callback;
	else
		request.onreadystatechange = showResult;
	request.send(makePostParams(data));
}

function sendConfirmRequest(nickname){
	data = {
		'type': 'service',
		'control': 'force-confirm',
		'nickname': nickname
	};
	showRequest(data);
	sendRequest(data);
	control = 'stop';
}

function sendCreateRequest(nickname, callback){
	data = {
		'type': 'service',
		'control': 'force-create',
		'nickname': nickname
	};
	showRequest(data);
	sendRequest(data, callback);
	control = 'stop';
}

function keyboardEventHandler(e){
	data = {
		'type': 'keyboard',
		'id': keyboard_id++,
		'action': (e.type == 'keydown' ? 'down' : 'up'),
		'code': e.keyCode,
		'timestamp': Date.now()
	};
	showRequest(data);
	sendRequest(data);
}

function scrollEventHandler(e){
	data = {
		'type': 'scroll',
		'id': scroll_id++,
		'offset': window.pageYOffset,
		'width': document.querySelector('body').clientWidth,
		'timestamp': Date.now()
	};
	showRequest(data);
	sendRequest(data);
}

function mouseEventHandler(e){
	data = {
		'type': 'mouse',
		'id': mouse_id++,
		'action': e.type.substring(5),
		'x': e.clientX,
		'y': e.clientY,
		'timestamp': Date.now()
	};
	if(e.type != 'mousemove')
		data.button = e.which;
	showRequest(data);
	sendRequest(data);
}

X = document.querySelectorAll('input[type="text"],'+
	'input[type="number"], textarea');
X.forEach(function(F){
	F.addEventListener('keydown', keyboardEventHandler);
	F.addEventListener('keyup', keyboardEventHandler);
});
window.addEventListener('scroll', scrollEventHandler);
//window.addEventListener('scroll', scrollEventHandler);
window.addEventListener('mousemove', mouseEventHandler);
window.addEventListener('mousedown', mouseEventHandler);
window.addEventListener('mouseup', mouseEventHandler);
if(control != 'only-store'){
	document.querySelector('#yes').addEventListener('click', function(){
		sendCreateRequest(
			'YES_'+document.querySelector('#nickname').value, function(){
			document.querySelector('#user_window').style.display = 'none';
			});
	});
	document.querySelector('#no').addEventListener('click', function(){
		sendCreateRequest(
			'NO_'+document.querySelector('#nickname').value, function(){
			document.querySelector('#user_window').style.display = 'none';
			});
	});
	setTimeout(function(){
		document.querySelector('#user_window').style.display = 'block';
	}, popupTime);
}else{
	document.querySelector('#the-form').addEventListener('submit',
		function(e){
			e.preventDefault();
			sendCreateRequest(
				document.querySelector('#nickname-field').value,
				function(){
					window.location.href = '/ru/quest?result=success';
				});
		});
}
