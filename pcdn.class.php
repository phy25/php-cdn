<?php
class PCDNUploader{
	private $_upyun = NULL;
	private $_remote_domain = '';
	private $_remote_path = '';
	private $_cache_path = './cache/';
	private $_f_relative_path = '';
	public $force_sync = false;
	public $f_listloc = './files.lst';
	public $f_expires_default = 86400; // default caching time (N seconds) (86400s = 1d)
	public $f_expires_short = 60; // short caching time to ignore origin temperary error
	
	/*
	* File Format:
	* local_path server_path caching_time
	* @param caching_time 
	*  	d = default;
	*  	f = forever;
	*  	n = no mtime check, use the default time;
	*  	(int)/second
	*/

	public function __construct($up_bucket, $up_user, $up_pass, $remote_path, $f_relative_path){
		$this->_upyun = new UpYun($up_bucket, $up_user, $up_pass, UpYun::ED_AUTO);
		$this->_remote_domain = 'http://'.$up_bucket.'.b0.upaiyun.com';
		$this->_remote_path = $remote_path;
		$this->_f_relative_path = $f_relative_path;
	}

	/* in new PCDNUploader_File, there may be exceptions */
	public function createFileFromURL($url = NULL){
		if(!$url) $url = $_SERVER['REQUEST_URI'];

		$path = str_replace($this->_f_relative_path, '', $url);
		return new PCDNUploader_File($path, $this->_cache_path);
	}

	/* return void */
	public function checkFileList($file){
		if(!file_exists($this->f_listloc)){
			throw new Exception('List file not found');
			return false;
			/*
			header($_SERVER['SERVER_PROTOCOL'] . ' 500 Server Error');
			exit('<h1>500 Server Error</h1>');
			*/
		}

		$f_listh = fopen($this->f_listloc, 'r');
		$f_matched = null;
		$origin_uri = NULL;

		while(!feof($f_listh)){ // match the accessed url with the list
			$f_listl = explode(' ', fgets($f_listh));
			if(substr($f_listl[0], -1, 1) == '/' && strpos($file->getPath(), $f_listl[0]) === 0){
				// in folder mode, we check if the url match the rule 
				$f_matched = $f_listl;
				$origin_uri = $f_listl[1].str_replace($f_listl[0], '', $file->getPath());
				// Here we have to remove part of the path
				break; // exit the loop
			}elseif($file->getName() == $f_listl[0]){
				// file mode; now it doesn't matter whether ?key=value is given
				$f_matched = $f_listl;
				$origin_uri = $f_listl[1];
				break; // exit the loop
			}
			// if it doesn't match the line, go to the next line
		}
		fclose($f_listh);

		if(is_array($f_matched)){
			$f_matched[2] = trim($f_matched[2]);

			$file->setRule($f_matched);
			$file->setFile($origin_uri, $this->_remote_domain, $this->_remote_path);
		}else{
			$file->setRule(array());
		}
	}

	public function upload2remote($remote_uri, $contents, $md5 = null){
		try{
			if($md5){
				$opts = array(UpYun::CONTENT_MD5 => $md5);
			}else{
				$opts = array();
			}
			$res = $this->upyun->writeFile($remote_uri, $contents, true, $opts);
			return array('status' => $res);
		}catch(Exception $e){
			return array('status' => false, 'error' => $e->getMessage(), 'code' => $e->getCode());
		}
	}

	/**
	 * @param mixed $fp 要识别的字符串或文件指针
	 * @param int $header_length 如果 $fp 中同时带有 header 和内容，要自动截取出 header 部分的长度
	 * @return int Unix 标准时间戳; 如果识别失败返回 -1
	 */
	static public function get_mtime_from_fp($fp, $header_length = 0){
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

	public function sync($file){
		$this->syncBegin($file);
		// check the local cache
		if (!$this->force_sync && $file->cacheExist()) {
			// get last modified time
			$f_modified = $file->cacheMTime();

			$f_rule_mode = $file->getRule()[2];
			$f_expires = (int) $f_rule_mode;
			if(!$f_expires) $f_expires = $this->f_expires_default;

			//var_dump($f_modified,  $f_matched, $f_expires, time());
			if($f_rule_mode != 'f' && $f_modified + $f_expires < time()){//expired
				if($f_rule_mode == 'n'){ // ignore the mtime check
					$server_mtime = -1;
				}else{
					$server_mtime = $file->fetch_origin_mtime();
				}

				if($server_mtime == -2){
					// the file doesn't exist, issue *404*
					// here we apply a short-life cache
					$file->save_cache_md5('404', time()-$this->f_expires_default+$this->f_expires_short);
					$file->setStatus('Fresh', 404);
					return $this->syncExit($file);
				}elseif($server_mtime == -1 || $server_mtime > $f_modified){
					// the server is newer; we should fetch the content
					$server_file = $file->fetch_origin();
					if($server_file['status']){
						$server_md5 = md5($server_file['contents']);

						if($server_md5 == $file->get_cache_md5()){
							// the file is the same one; just change the modified time to resign the cache expire
							$file->cacheMTime(0); // the modified time from the server is useless now
							$file->setStatus('Same');
						}else{ 
							// sync the file to the remote cdn
							$file->save_cache_md5($server_md5);
							$remote_res = $this->upload2remote($file->getRemoteUri(), $server_file['contents'], $server_md5);
							if($remote_res['status']){
								$file->setStatus('Synced');
							}else{
								$file->setStatus('Not-Synced', 500, $remote_res['code'].' '.$remote_res['error']);
								return $this->syncExit($file);
							}
						}
					}else{
						// the file doesn't exist at this time, issue *404*
						// when ignoring mtime check (mtime = -1), the file existance check is left here;
						// otherwise it would not occur unless you are in bad luck :(
						// here we apply a short-life cache
						$file->save_cache_md5('404', time()-$this->f_expires_default+$this->f_expires_short);
						$file->setStatus('Fresh', 404);
						return $this->syncExit($file);
					}
				}else{ // just change the modified time to resign the cache expire
					$file->cacheMTime(0);
					$file->setStatus('Fresh');
				}
			}else{ // don't check the server for changes
				$file->setStatus('Not-Expired');
			}
		}else{
			// no cache yet? fetch it first
			$server_file = $file->fetch_origin();
			if(!$server_file['status']){
				// the file doesn't exist, issue *404*
				// here we apply a short-life cache
				$file->save_cache_md5('404', time()-$this->f_expires_default+$this->f_expires_short);
				$file->setStatus('Created', 404);
				return $this->syncExit($file);
			}else{
				// save and sync it
				$server_md5 = md5($server_file['contents']);

				$file->save_cache_md5($server_md5, PCDNUploader::get_mtime_from_fp($server_file['header']));
				$remote_res = $this->upload2remote($file->getRemoteUri(), $server_file['contents'], $server_md5);
				if($remote_res['status']){
					$file->setStatus('Created');
				}else{
					$file->setStatus('Not-Created', 500, $remote_res['code'].' '.$remote_res['error']);
					return $this->syncExit($file);
				}
			}
		}
		return $this->syncExit($file);
	}

	public function syncExit($file){
		if($file->sync_code){
			return array('status'=>false, 'step'=>$file->sync_status, 'code'=> $file->sync_code, 'msg'=>$file->sync_msg);
		}else{
			return array('status'=>true, 'step'=>$file->sync_status);
		}
	}

	public function syncBegin($file){
		$file->sync_status = NULL;
		$file->sync_code = NULL;
		$file->sync_msg = NULL;
	}
}

Class PCDNUploader_HTTPWrapper extends PCDNUploader{
	public function syncExit(){
		// header('Cache-Control: private');
		header('X-Sync-Status: '.$file->sync_status);

		if($file->sync_code){
			// Error, should shutdown the page
			$codemsg = NULL;
			switch($file->sync_code){
				case 403:
					$codemsg = '403 Forbidden';break;
				case 404:
					$codemsg = '404 Not Found';break;
				case 500:
					$codemsg = '500 Server Error';break;
			}
			if($codemsg) header($_SERVER['SERVER_PROTOCOL'] . ' '. $codemsg);

			if($file->sync_msg) exit("<h1>".$file->sync_msg."</h1>");
			exit($codemsg?"<h1>{$codemsg}</h1>":'');
		}
	}
}

Class PCDNUploader_File{
	private $path = NULL;
	private $name = NULL;
	private $_rule = NULL;
	private $cache_path = '';

	private $origin_uri = '';
	private $remote_uri = '';
	private $remote_publicuri = '';

	public $sync_status = NULL;
	public $sync_code = NULL;
	public $sync_msg = NULL;

	public function __construct($path, $cachePath){
		$this->path = $path;
		$this->name = parse_url($path, PHP_URL_PATH);	
		$this->_createCachePath($cachePath);
	}
	public function getPath(){return $this->path;}
	public function getName(){return $this->name;}
	public function getRemoteUri(){return $this->remote_uri;}
	public function getRule(){return $this->_rule;}
	public function setRule($rule){
		if(!is_array($rule)) return false;
		$this->_rule = $rule;
	}
	public function setFile($origin_uri, $remote_domain, $remote_path){
		$this->origin_uri = $origin_uri;
		$this->remote_uri = $remote_path.$this->getPath();
		$this->remote_publicuri = $remote_domain.$remote_path.$this->getPath();
	}
	public function setStatus($status, $code = 0, $msg = NULL){
		$this->sync_status = $status;
		$this->sync_code = $code;
		$this->sync_msg = $msg;
	}

	public function _createCachePath($path){
		// encode as filename-safe base64 for the cache name
		$f_name = strtr(base64_encode($this->name), '+/=', '-_,');

		// parse the file extension
		$f_ext = strrchr($this->name, '.');

		$this->cache_path = $path.$f_name.$f_ext;

		if(!file_exists($path)){
			// we have to create the folder first
			if(mkdir($path)){
				throw new Exception('Cache path cannot be created');
				return false;
			}
		}
	}
	public function cacheExist(){
		return file_exists($this->cache_path.'.md5');
	}
	public function cacheMTime($mtime = -1){
		if($mtime == 0){
			$mtime = time();
		}
		if($mtime > 0){
			return touch($this->cache_path.'.md5', $mtime)?$mtime:false;
		}else{
			return filemtime($this->cache_path.'.md5');
		}
	}
	public function get_cache_md5(){
		return file_get_contents($this->cache_path.'.md5');
	}
	public function save_cache_md5($md5, $mtime = 0){
		$r = file_put_contents($this->cache_path.'.md5', $md5, LOCK_EX);
		if($mtime > 0){
			$this->cacheMTime($mtime);
		}
		return $r;
	}

	public function fetch_origin(){
		$ch = curl_init();
		curl_setopt_array($ch, array(
			CURLOPT_URL            => $this->origin_uri,
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

	public function fetch_origin_mtime(){
		$ch = curl_init();
		curl_setopt_array($ch, 	array(
			CURLOPT_URL            => $this->origin_uri,
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
			return PCDNUploader::get_mtime_from_fp($header);
		}else{
			return -2;
		}
	}

	public function redirect2remote(){
		header('Location: ' . $this->remote_publicuri, true, 302);
		exit();
	}
}
