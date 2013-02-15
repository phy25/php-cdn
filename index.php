<?php
/*
 * Forked from https://github.com/trigunflame/php-cdn and modified by Phy25
 *
 * *** DOING Milestone: This is a passive CDN service, but what I need is an active one. ***
 * This script will sync the accessed file.
 *
 * server -> this script -> remote
 */

error_reporting(E_ALL);

require_once('./upyun.class.php');

require_once('./config.inc.php');

$upyun = new UpYun(UPYUN_BUCKET, UPYUN_USERNAME, UPYUN_USERPASS, UpYun::ED_TELECOM);
$f_remote_publicprefix = 'http://phy25-cdn.b0.upaiyun.com';

$f_remote_path = '/';


// files.lst location
$f_listloc = './files.lst';
/*
 * File Format:
 * local_path server_path caching_time
 * @param cachingtime 
 *  	d = default;
 *  	f = forever;
 *  	n = no mtime check, use the default time;
 *  	(int)/second
 */

$f_relative_path = '/remote/';

// default caching time (N seconds) (86400s = 1d)
$f_expires_default = 60;

// make out the right path
$req_file = str_replace($f_relative_path, '', $_SERVER['REQUEST_URI']);

$req_filename = parse_url($req_file, PHP_URL_PATH);

// encode as filename-safe base64 for the cache name
$f_name = strtr(base64_encode($req_filename), '+/=', '-_,');

// parse the file extension
$f_ext = strrchr($req_filename, '.');

// do not compare the file with the server; just pull from the server (turn it on only when you are testing!)
$force_sync = false;

// to ignore the mtime check, use the list file to configure

if(!file_exists($f_listloc)){
	header($_SERVER['SERVER_PROTOCOL'] . ' 500 Server Error');
	exit('<h1>500 Server Error</h1>');
}

$f_listh = fopen($f_listloc, 'r');

$f_matched = null;
while(!feof($f_listh)){// match the accessed url with the list
	$f_listl = explode(' ', fgets($f_listh));
	if(substr($f_listl[0], -1, 1) == '/' && strpos($req_file, $f_listl[0]) === 0){ // in folder mode we check if the url match the rule 
		$f_matched = $f_listl;
		$server_uri = $f_listl[1].str_replace($f_listl[0], '', $req_file);
		$remote_uri = $f_remote_path.$req_file;
		$remote_publicuri = $f_remote_publicprefix.$f_remote_path.$req_file;
		break; // exit the loop
	}elseif($req_filename == $f_listl[0]){ // file mode; now it doesn't matter whether ?key=value is given
		$f_matched = $f_listl;
		$server_uri = $f_listl[1];
		$remote_uri = $f_remote_path.$req_filename; // when syncing to remote, ?key=value is useless
		$remote_publicuri = $f_remote_publicprefix.$f_remote_path.$req_file;
		break; // exit the loop
	}
	// if it doesn't match the line, go to the next line
}

fclose($f_listh);

//var_dump($f_matched, $server_uri);

if(!is_array($f_matched)){ // if it matches nothing
	header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found');
	exit('<h1>404 Not Found</h1>');
}

$f_matched[2] = trim($f_matched[2]);

//exit('<h1>200 OK</h1>'); // STOP here

// construct usable file path
$cache_path = './cache/'.$f_name.$f_ext;
if(!file_exists('./cache/')){
	// we have to create the folder first
	if(mkdir('./cache/')){
		header($_SERVER['SERVER_PROTOCOL'] . ' 403 Forbidden');
		exit('<h1>403 Forbidden</h1>');
	}
}

function fetch_file($server_uri){
	$ch = curl_init();
	curl_setopt_array($ch, array(
		CURLOPT_URL            => $server_uri,
		CURLOPT_TIMEOUT        => 15, // it is a short time for a background service, right?
		CURLOPT_CONNECTTIMEOUT => 5,
		CURLOPT_FAILONERROR    => 1,
		CURLOPT_RETURNTRANSFER => 1,
		CURLOPT_BINARYTRANSFER => 1,
		CURLOPT_HEADER         => 1,
		CURLOPT_FOLLOWLOCATION => 1
	));
	
	$resp = curl_exec($ch);
	if($resp === false) { // error handling
		return array('status'=>false, 'error'=>curl_error($ch));
	}

	$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
	$header = substr($resp, 0, $header_size);
	$contents = substr($resp, $header_size);

	return array('status'=>true, 'header'=>$header, 'contents'=>$contents, 'header_size'=>$header_size);
}

/**
 * @param mixed $fp 要识别的字符串或文件指针
 * @param int $header_length 如果 $fp 中同时带有 header 和内容，要自动截取出 header 部分的长度
 * @return int Unix 标准时间戳; 如果识别失败返回 -1
 */
function get_mtime_from_fp($fp, $header_length = 0){
	if(is_resource($fp)){
		$contents = '';
		rewind($fp);
		if($header_length > 0){
			$contents = fread($fp, $header_length);
		}else{
			while(!feof($handle)) {
				$contents .= fread($fp, 8192);
			}
		}
		rewind($fp);
	}else{
		$contents = $fp;
	}
	if($header_length > 0){
		$contents = substr($contents, 0, $header_length);
	}

	preg_match('/Last-Modified:(.*?)\n/', $contents, $matches);
	$result = trim(array_pop($matches));
	return $result ? strtotime($result) : -1;
}

function redirect2remote($uri){
	header('Location: ' . $uri, true, 302);
	exit();
}

function fetch_file_mtime($server_uri){
	$ch = curl_init();
	curl_setopt_array($ch, 	array(
		CURLOPT_URL            => $server_uri,
		CURLOPT_TIMEOUT        => 10,
		CURLOPT_CONNECTTIMEOUT => 5,
		CURLOPT_FAILONERROR    => 1,
		CURLOPT_RETURNTRANSFER => 1,
		CURLOPT_BINARYTRANSFER => 1,
		CURLOPT_HEADER         => 1,
		CURLOPT_NOBODY         => 1,
		CURLOPT_FOLLOWLOCATION => 1
	));
	
	$header = curl_exec($ch);
	if($header){
		return get_mtime_from_fp($header);
	}else{
		return -2;
	}
}

function get_cache_md5($path){
	return file_get_contents($path.'.md5');
}

function upload2remote($remote_uri, $contents, $md5 = null){
	global $upyun;
	try{
		if($md5){
			$opts = array(UpYun::CONTENT_MD5 => $md5);
		}else{
			$opts = array();
		}
		$res = $upyun->writeFile($remote_uri, $contents, true, $opts);
		return array('status' => $res);
	}catch(Exception $e){
		return array('status' => false, 'error' => $e->getMessage(), 'code' => $e->getCode());
	}
}

function save_cache($path, $md5, $mtime = 0){
	$r = file_put_contents($path.'.md5', $md5, LOCK_EX);
	if($mtime > 0){
		touch($path.'.md5', $mtime);
	}
	return $r;
}

// check the local cache
if (!$force_sync && file_exists($cache_path.'.md5')) {
	// get last modified time
	$f_modified = filemtime($cache_path.'.md5');

	$f_expires = (int) $f_matched[2] ? (int) $f_matched[2] : $f_expires_default;
	//var_dump($f_modified,  $f_matched, $f_expires, time());
	if($f_matched[2] != 'f' && $f_modified + $f_expires < time()){//expired
		if($f_matched[2] == 'n'){ // ignore the mtime check
			$server_mtime = -1;
		}else{
			$server_mtime = fetch_file_mtime($server_uri);
		}
		if($server_mtime == -2){
			// the file doesn't exist, issue *404*
			// *** TODO: we may cache the 404 result to prevent abuse
			header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found');
			header('Cache-Control: private');
			header('X-Checker-Status: Fresh');
			exit('<h1>404 Not Found</h1>');
		}elseif($server_mtime == -1 || $server_mtime > $f_modified){ // the server is newer; we should fetch the content
			$server_file = fetch_file($server_uri);
			if($server_file['status']){
				$server_md5 = md5($server_file['contents']);

				if($server_md5 == get_cache_md5($cache_path)){ // the file is the same one; just change the modified time to resign the cache expire
					touch($cache_path.'.md5'); // the modified time from the server is useless now
					header('X-Checker-Status: Same');
				}else{ // sync the file to the remote cdn
					save_cache($cache_path, $server_md5);
					$remote_res = upload2remote($remote_uri, $server_file['contents'], $server_md5);
					if($remote_res['status']){
						header('X-Checker-Status: Synced');
					}else{
						header($_SERVER['SERVER_PROTOCOL'] . ' 500 Server Error');
						exit('<h1>'.$remote_res['code'].' '.$remote_res['error'].'</h1>');
					}
				}
			}else{
				// the file doesn't exist at this time, issue *404*
				// when ignoring mtime check, the file exitance check is left here;
				// otherwise it would not occur unless you are in bad luck :(
				// *** TODO: we may cache the 404 result to prevent abuse
				header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found');
				header('Cache-Control: private');
				header('X-Checker-Status: Fresh');
				exit('<h1>404 Not Found</h1>');
			}
		}else{ // just change the modified time to resign the cache expire
			touch($cache_path.'.md5');
			header('X-Checker-Status: Fresh');
		}
		redirect2remote($remote_publicuri);
	}else{ // don't check the server for changes
		header('X-Checker-Status: No-Expired');
		redirect2remote($remote_publicuri);
	}
}else{
	// no cache yet? fetch it first
	$server_file = fetch_file($server_uri);
	if(!$server_file['status']){
		// the file doesn't exist, issue *404*
		// *** TODO: we may cache the 404 result to prevent abuse
		header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found');
		header('Cache-Control: private');
		header('X-Checker-Status: Created');
		exit('<h1>404 Not Found</h1>');
	}else{
		// save and sync it
		$server_md5 = md5($server_file['contents']);

		save_cache($cache_path, $server_md5, get_mtime_from_fp($server_file['header']));
		$remote_res = upload2remote($remote_uri, $server_file['contents'], $server_md5);
		if($remote_res['status']){
			header('X-Checker-Status: Created');
			redirect2remote($remote_publicuri);
		}else{
			header($_SERVER['SERVER_PROTOCOL'] . ' 500 Server Error');
			exit('<h1>'.$remote_res['code'].' '.$remote_res['error'].'</h1>');
		}
	}
}