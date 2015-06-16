<?php
/*
 * Forked from https://github.com/trigunflame/php-cdn and modified by Phy25
 *
 * *** DOING Milestone: This is a passive CDN service, but what I need is an active one. ***
 * This script will sync the accessed file.
 *
 * server -> this script -> remote
 */

//error_reporting(E_ALL);

require_once('./upyun.class.php');
require_once('./config.inc.php');
require_once('./pcdn.class.php');

$cdn = new PCDNUploader_HTTPWrapper(UPYUN_BUCKET, UPYUN_USERNAME, UPYUN_USERPASS, REMOTE_PATH, RELATIVE_PATH);
try{
	$file = $cdn->createFileFromURL();
	$cdn->checkFileList($file);
}catch(Exception $e){
	// cache error / list error
	header($_SERVER['SERVER_PROTOCOL'] . ' 403 Forbidden');
	exit('<h1>403 Forbidden</h1>');
}
$cdn->sync($file);
$file->redirect2remote();