//json rest wrapper for satoshis.place socket.io-based api
//14.06.2018 anduck at anduck dot net
//usage: check the consts, do changes if needed.
//       install nodejs and npm  (node package manager)
//       install socket.io-client: npm install socket.io-client
//       run script and browse to http://127.0.0.1:8000 (default).


//import socket.io
const io = require('socket.io-client');

//import http, url
const http = require('http');
const url = require('url');

//your settings, modify if necessary
const hostname = '127.0.0.1';
const port = 8000;
const API_URI = 'https://api.satoshis.place/';


//global vars. bad practice.
global.global_res = null;
global.latest_orders_settled = [];

////////////
//script backbone taken from https://github.com/LightningK0ala/satoshis.place/

//handle socket.io connection to API_URI
console.log('Connecting to socket.io API of '+API_URI+'...');
const socket = io(API_URI, {
	transports: ['websocket']
});

// Listen for errors
socket.on('error', ({ message }) => {
	// Requests are rate limited by IP Address at 10 requests per second.
	// You might get an error returned here.
	console.log(message)
})

// Wait for connection to open before setting up event listeners
socket.on('connect', a => {
	console.log('API Socket connection established with id', socket.id)
	// Subscribe to events
	socket.on('GET_LATEST_PIXELS_RESULT', handleReceivedEvent)
	socket.on('NEW_ORDER_RESULT', handleReceivedEvent)
	//socket.on('ORDER_SETTLED', handleReceivedEvent)
	socket.on('ORDER_SETTLED', handleReceivedEvent_ORDER_SETTLED)
	socket.on('GET_SETTINGS_RESULT', handleReceivedEvent)
	
})

function handleReceivedEvent(result, callback) {
	//console.log(result);
	console.log("handleReceivedEvent");
	if (global_res == null) {
		console.log("global_res == null, returning");
		return;
	}
	if (result.error) {
		global_res.statusCode = 404;
		global_res.setHeader('Content-Type', 'text/plain');
		global_res.write(JSON.stringify(result));
		global_res.end('\n');
		console.log("responded HTTP query.");
	} else {
		global_res.statusCode = 200;
		global_res.setHeader('Content-Type', 'text/plain');
		global_res.write(JSON.stringify(result));
		global_res.end('\n');
		console.log("responded HTTP query.");
	}
	global_res = null;
	return;
}

function handleReceivedEvent_ORDER_SETTLED(result, callback) {
	if (result.error) {
		console.log(result);
	} else {
		latest_orders_settled[latest_orders_settled.length] = result;
	}
	return;
}

socket.on('disconnect', () => {
	console.log('Disconnected, trying to reconnect...');
	socket.open();
});
///////////




//fire up local HTTP server
const server = http.createServer((req, res) => {
	if (socket.connected === false) {
		res.statusCode = 200;
		res.setHeader('Content-Type', 'text/plain');
		res.end('Websocket is not connected.\n');
		return;
	}
	
	var queryData = url.parse(req.url, true).query;
	if (typeof queryData.json === 'undefined' || queryData.json === null) {
		res.statusCode = 200;
		res.setHeader('Content-Type', 'text/plain');
		res.write('Usage:\n\nUse GET query with \'json\' as the paremeter. \'json\' is of format: {"command":"COMMAND", "payload":"PAYLOAD"}');
		res.write('\nGET /?json={"command":"COMMAND", "payload":"PAYLOAD"}\nExample: GET /?json={"command":"GET_LATEST_PIXELS", "payload":""}');
		res.end('\n');
		return;
	} else {
		var jsonquery = JSON.parse(queryData.json);
		if (typeof jsonquery.command === 'undefined' || jsonquery.command === null) {
			res.statusCode = 404;
			res.setHeader('Content-Type', 'text/plain');
			res.end('Json needs \'command\'. Check format again.\n');
			console.log(queryData);
			return;
		} else if (typeof jsonquery.payload === 'undefined' || jsonquery.payload === null) {
			res.statusCode = 404;
			res.setHeader('Content-Type', 'text/plain');
			res.end('Json needs \'payload\'. Check format again.\n');
			console.log(queryData);
			return;
		} else {
			//format is OK, check if command is good to go:
			if ((jsonquery.command == 'GET_LATEST_PIXELS')
				|| (jsonquery.command == 'GET_SETTINGS')
				|| (jsonquery.command == 'NEW_ORDER')
			) {
				global_res = res; //make this http query respond var global so our
									//event listener can reply to it. this is a bad practice!
				socket.emit(jsonquery.command, jsonquery.payload); //emit query to api
				return;
			} else if (jsonquery.command == 'latest_orders_settled') {
				//return all latest orders settled
				res.statusCode = 200;
				res.setHeader('Content-Type', 'text/plain');
				res.write(JSON.stringify(latest_orders_settled));
				res.end('\n');
				return;
			} else {
				res.statusCode = 404;
				res.setHeader('Content-Type', 'text/plain');
				res.end('Command not supported.\n');
				console.log(queryData);
				return;
			}
		}
	}
	res.statusCode = 404;
	res.setHeader('Content-Type', 'text/plain');
	res.end('Endpoint not found.. Weird!\n');
	return;
});

server.listen(port, hostname, () => {
	console.log(`Server hosting JSON REST API running at http://${hostname}:${port}/`);
});








