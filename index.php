<?php
/*
 * Forked from https://github.com/trigunflame/php-cdn and modified by Phy25
 *
 * *** Milestone: This is a passive CDN service, but what I need is an active one. ***
 * php-cdn
 * dynamic file caching pseudo cdn
 *
 * cdn root path   : http://cdn.com/
 * cdn example url : http://cdn.com/path/to/resource.css?d=12345
 * maps the uri    : /path/to/resource.css?d=12345
 * to the origin   : http://yoursite.com/path/to/resource.css?d=12345
 * caches file to  : ./cache/[base64-encoded-uri].css
 * returns local cached copy or issues 304 not modified
 */

// error_reporting(E_ALL);

// files.lst location
$f_listloc = './files.lst';
/*
 * File Format:
 * local_path remote_path caching_time(d = default, f = forever, (int)/second)
 */

$f_relative_path = '/remote/';

// default caching time (N seconds) (86400s = 1d)
$f_expires_default = 86400;

// make out the right path
$req_file = str_replace($f_relative_path, '', $_SERVER['REQUEST_URI']);

// encode as filename-safe base64 for the cache name
$f_name = strtr(base64_encode($req_file), '+/=', '-_,');

// parse the file extension
$f_ext = strrchr(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '.');


if(!file_exists($f_listloc)){
	header($_SERVER['SERVER_PROTOCOL'] . ' 500 Server Error');
	exit('<h1>500 Server Error</h1>');
}

$f_listh = fopen($f_listloc, 'r');

$f_matched = null;
while(!feof($f_listh)){
	$f_listl = explode(' ', fgets($f_listh));
	if(substr($f_listl[0], -1, 1) == '/' && strpos($req_file, $f_listl[0]) === 0){ // in folder mode we check if the url match the rule 
		$f_matched = $f_listl;
		$f_remote_uri = $f_listl[1].str_replace($f_listl[0], '', $req_file);
		break; // exit the loop
	}elseif($req_file == $f_listl[0]){ // file mode
		$f_matched = $f_listl;
		$f_remote_uri = $f_listl[1];
		break; // exit the loop
	}
	// if it doesn't match the line, go to the next line
}

fclose($f_listh);

//var_dump($f_matched, $f_remote_uri);

if(!is_array($f_matched)){ // if it matches nothing
	header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found');
	exit('<h1>404 Not Found</h1>');
}

$f_matched[2] = trim($f_matched[2]);

// assign the correct mime type
switch ($f_ext) {
	// images
	case '.gif'  : $f_type = 'image/gif';                break;
	case '.jpg'  : $f_type = 'image/jpeg';               break;
	case '.png'  : $f_type = 'image/png';                break;
	case '.ico'  : $f_type = 'image/x-icon';             break;
	// documents
	case '.js'   : $f_type = 'application/x-javascript'; break;
	case '.css'  : $f_type = 'text/css';                 break;
	case '.xml'  : $f_type = 'text/xml';                 break;
	case '.json' : $f_type = 'application/json';         break;
	// no match
	default      :
		// extension is not supported, issue *403*
		header($_SERVER['SERVER_PROTOCOL'] . ' 403 Forbidden');
		exit('<h1>403 Forbidden</h1>');
}

//exit('<h1>200 OK</h1>'); // STOP here

// construct usable file path
$f_path = './cache/'.$f_name.$f_ext;
if(!file_exists('./cache/')){
	// we have to create the folder first
	if(mkdir('./cache/')){
		header($_SERVER['SERVER_PROTOCOL'] . ' 403 Forbidden');
		exit('<h1>403 Forbidden</h1>');
	}
}

function fetch_file($f_remote_uri, $f_path, $f_modified){
	// http *HEAD* request 
	// verify that the file exists
	$ch = curl_init();
	curl_setopt_array($ch, 	array(
		CURLOPT_URL            => $f_remote_uri,
		CURLOPT_TIMEOUT        => 10,
		CURLOPT_CONNECTTIMEOUT => 5,
		CURLOPT_FAILONERROR    => 1,
		CURLOPT_RETURNTRANSFER => 1,
		CURLOPT_BINARYTRANSFER => 1,
		CURLOPT_HEADER         => 1,
		CURLOPT_NOBODY         => 1,
		CURLOPT_FOLLOWLOCATION => 1, 
	));
	
	$header = curl_exec($ch);
	if ($header !== false) { // remote file exists
		preg_match('/Last-Modified:(.*?)\n/', $header, $matches); 
		$remote_time = strtotime(trim(array_pop($matches)));

		if($remote_time > $f_modified){
			$fp = fopen($f_path, 'a+b'); // append and binary mode
			if(flock($fp, LOCK_EX | LOCK_NB)) {
				// empty *possible* contents
				ftruncate($fp, 0);
				rewind($fp);

				// http *GET* request
				// and write directly to the file
				$ch2 = curl_init();
				curl_setopt_array($ch2, 	array(
					CURLOPT_URL            => $f_remote_uri,
					CURLOPT_TIMEOUT        => 15, // it is a short time for a background service, right?
					CURLOPT_CONNECTTIMEOUT => 5,
					CURLOPT_FAILONERROR    => 1,
					CURLOPT_RETURNTRANSFER => 1,
					CURLOPT_BINARYTRANSFER => 1,
					CURLOPT_HEADER         => 0,
					CURLOPT_FILE           => $fp
					// CURLOPT_FOLLOWLOCATION => 1, 
				));
					
				// did the transfer complete?
				if (curl_exec($ch2) === false) {
					// something went wrong, null 
					// the file just in case >.>
					//ftruncate($fp, 0); 
				}
				
				// 1) flush output to the file
				// 2) release the file lock
				// 3) release the curl socket
				fflush($fp);
				flock($fp, LOCK_UN);
				curl_close($ch2);
			}
					
			// close the file
			fclose($fp);
				
			
		}else{
			touch($f_path, $remote_time); // Change the modified time to resign the cache expire
		}

		// issue *302* for *this* request
		header('Location: ' . $f_remote_uri, true, 302);
		
		curl_close($ch);
		exit(); // STOP here
	} else {
		// the file doesn't exist, issue *404*
		// *** TODO: we may cache the 404 result to prevent abuse
		header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found');
		header('Cache-Control: private');
	}
	
	// finished
	curl_close($ch);
}

function output_file($f_path, $f_modified, $f_type, $f_expires){
	// validate the client cache
	if (isset(    $_SERVER['HTTP_IF_MODIFIED_SINCE']) && 
	   (strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) == $f_modified)
	) {
		// client has a valid cache, issue *304*
		header($_SERVER['SERVER_PROTOCOL'] . ' 304 Not Modified');
	} else {
		// send all requisite cache-me-please! headers
		header('Pragma: public');
		header('Cache-Control: max-age=' . $f_expires);
		header('Content-type: ' . $f_type);
		header('Last-Modified: ' . gmdate('D, d M Y H:i:s \G\M\T', $f_modified));
		header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() + $f_expires));
		
		// stream the file
		readfile($f_path);
	}
}

// check the local cache
if (file_exists($f_path)) {
	// get last modified time
	$f_modified = filemtime($f_path);

	$f_expires = (int) $f_matched[2] ? (int) $f_matched[2] : $f_expires_default;
	if($f_matched[2] != 'f' && $f_modified + $f_expires < time()){//expired
		fetch_file($f_remote_uri, $f_path, $f_modified);
	}else{ // don't check the remote for changes
		output_file($f_path, $f_modified, $f_type, $f_expires);
	}
}else{ // no cache yet? fetch it first
	fetch_file($f_remote_uri, $f_path, 0);
}