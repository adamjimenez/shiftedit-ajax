<?php
/*
Used by ShiftEdit.net to connect to server and perform file ops
Author: Adam Jimenez <adam@shiftcreate.com>
URL: https://github.com/adamjimenez/shiftedit-ajax

Edit the username and password below
*/

//config
$host = 'localhost';
$username = '{$username}'; // username or ftp username
$password = '{$password}'; // password or ftp password
$dir = '{$dir}'; // path to files e.g. dirname(__FILE__).'/';
$server_type = '{$server_type}'; // local, ftp or sftp. local requires webserver to have write permissions to files.
$pasv = '{$pasv}'; // true for pasv mode / false for active mode
$port = '{$port}'; // usually 21 for ftp and 22 for sftp
$definitions = '{$definitions}'; // autocomplete definitions e.g. http://example.org/defs.json
$phpseclib_path = ''; // path to phpseclib for sftp, get from: https://github.com/phpseclib/phpseclib
$origin = $_SERVER['HTTP_ORIGIN'] ?: '{$origin}'; // CORS origin: https://shiftedit.net

// restrict access by ip
$ip_restrictions = false;

// allowed ips. get your ip from https://www.google.co.uk/search?q=ip+address
$ips = array('');

// api version
$version = '1.3';

//set error level
error_reporting(E_ALL ^ E_NOTICE);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

set_error_handler('error_handler');
register_shutdown_function('shutdown');

function shutdown() {
	if($error = error_get_last()) {
		error_handler($error['type'], $error['message'], $error['file'], $error['line']);
	}
}

function error_handler($errno, $errstr, $errfile, $errline, $errcontext="") {
	switch ($errno) {
		case E_WARNING:
		case E_USER_ERROR:
		case E_ERROR:
		case E_PARSE:
		case E_CORE_ERROR:
		case E_COMPILE_ERROR:
			$response = array();
			$response['success'] = false;
			$response['error'] = $errstr.' on line '.$errline;
			echo json_encode($response);
			exit;
		break;
	}
}

//include path
if ($phpseclib_path) {
	set_include_path(get_include_path() . PATH_SEPARATOR . $phpseclib_path);
}

session_start();

//prevent magic quotes
if (get_magic_quotes_gpc()) {
	function stripslashes_gpc(&$value){
		$value = stripslashes($value);
	}
	array_walk_recursive($_GET, 'stripslashes_gpc');
	array_walk_recursive($_POST, 'stripslashes_gpc');
}

// CORS Allow from shiftedit
if (isset($_SERVER['HTTP_ORIGIN'])) {
	header('Access-Control-Allow-Origin: '.$origin);
	header('Access-Control-Allow-Credentials: true');
	header('Access-Control-Max-Age: 86400');
}

// Access-Control headers are received during OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
	if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])){
		header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
	}

	if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])){
		header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
	}
	exit;
}

//ip restrictions
if( $ip_restrictions and !in_array($_SERVER['REMOTE_ADDR'], $ips) ){
	trigger_error('access denied, ip restrictions in effect');
}

//authentication
if( $username and !$_SESSION['shiftedit_logged_in'] ){
	if( $username!==$_POST['user'] or sha1($password)!==$_POST['pass'] ){
		//delay to protect against brute force attack
		sleep(1);
		trigger_error('Login incorrect');
	}

	$_SESSION['shiftedit_logged_in'] = true;
}

header('Content-Type: application/json, charset=utf-8');

abstract class server
{
	function chmod_num($permissions)
	{
		$mode = 0;

		if ($permissions[1] == 'r') $mode += 0400;
		if ($permissions[2] == 'w') $mode += 0200;
		if ($permissions[3] == 'x') $mode += 0100;
		else if ($permissions[3] == 's') $mode += 04100;
		else if ($permissions[3] == 'S') $mode += 04000;

		if ($permissions[4] == 'r') $mode += 040;
		if ($permissions[5] == 'w') $mode += 020;
		if ($permissions[6] == 'x') $mode += 010;
		else if ($permissions[6] == 's') $mode += 02010;
		else if ($permissions[6] == 'S') $mode += 02000;

		if ($permissions[7] == 'r') $mode += 04;
		if ($permissions[8] == 'w') $mode += 02;
		if ($permissions[9] == 'x') $mode += 01;
		else if ($permissions[9] == 't') $mode += 01001;
		else if ($permissions[9] == 'T') $mode += 01000;

		return sprintf('%o', $mode);
	}

	function __construct() {
		//max upload size
		$this->max_size = 20000000;
		$this->ftp_log = array();
	}

	function send_msg($id , $msg) {
		echo "id: $id" . PHP_EOL;
		echo "data: {\n";
		echo "data: \"msg\": \"$msg\", \n";
		echo "data: \"id\": $id\n";
		echo "data: }\n";
		echo PHP_EOL;
		ob_flush();
		flush();
	}

	function log($msg) {
		$this->ftp_log[] = $msg;

		if($this->startedAt) {
			$this->send_msg($this->startedAt, $msg);
		}
	}

	function close(){
	}
}

if($server_type==='local') {
	class local extends server{
		function __construct()
		{
			global $dir;
		
			// prefix dir with slash
			if(strlen($dir) and substr($dir, 0, 1)!='/') {
				$dir = '/'.$dir;
			}
			
			$this->dir = $dir;
		}
	
		function chdir($path){
			if( $path === $this->pwd ){
				return true;
			}else{
				if( chdir($path) ){
					$this->pwd = $path;
					return true;
				}else{
					return false;
				}
			}
		}
	
		function get($remote_file)
		{
			$path = $this->dir.$remote_file;
			return file_get_contents($path);
		}
	
		function put($file, $content, $resume_pos=0)
		{
			if( !$file ){
				return false;
			}
	
			$path = $this->dir.$file;
			$fp = fopen($path, 'w');
			fseek($fp, $resume_pos);
			$result = fwrite($fp, $content);
			fclose($fp);
	
			return $result;
		}
	
		function last_modified($file)
		{
			$file=$this->dir.$file;
			return filemtime($file);
		}
	
		function is_dir($dir)
		{
			$dir = $this->dir.$dir;
			return is_dir($dir);
		}
	
		function file_exists($file)
		{
			$file = $this->dir.$file;
			return file_exists($file);
		}
	
		function chmod($mode, $file)
		{
			$file = $this->dir.$file;
			return chmod($mode, $file);
		}
	
		function rename($old_name, $new_name)
		{
			$old_name = $this->dir.$old_name;
			$new_name = $this->dir.$new_name;
			return rename($old_name, $new_name);
		}
	
		function mkdir($dir)
		{
			$dir = $this->dir.$dir;
			return mkdir($dir);
		}
	
		function delete($file)
		{
			if( !$file ){
				$this->log[] = 'no file';
				return false;
			}
	
			$path = $this->dir.$file;
	
			if( $this->is_dir($file) ){
				$list = $this->parse_raw_list($file);
				if (!is_array($list)) {
					return false;
				}
				
				foreach ($list as $item){
					if( $item['name'] != '..' && $item['name'] != '.' ){
						$this->delete($file.'/'.$item['name']);
					}
				}
	
				chdir('../');
	
				$this->log('rmdir '.$file);
				if( !rmdir($path) ){
					return false;
				}else{
					return true;
				}
			}else{
				if( $this->file_exists($file) ){
					$this->log('delete '.$file);
					return unlink($path);
				}
			}
		}
	
		function parse_raw_list($path)
		{
			$path = $this->dir.$path;
	
			if( $path and !$this->chdir($path) ){
				return false;
			}
	
			$d = dir($path);
	
			if( $d === false ){
				return false;
			}
	
			$items=array();
	
			while (false !== ($entry = $d->read())) {
				$items[] = array(
					'name' => $entry,
					'permsn' => substr(decoct( fileperms($entry) ), 2),
					'size' => (int)filesize($entry),
					'modified' => filemtime($entry),
					'type' => is_dir($entry) ? 'folder' : 'file',
				);
			}
			$d->close();
	
			return $items;
		}
	
		function search_nodes($s, $path)
		{
			$list = $this->parse_raw_list($path);
			if( !is_array($list) ){
				return array();
			}
	
			$items = array();
	
			foreach( $list as $v ){
				if( $v['type']!='file' ){
					if( $v['name']=='.' or $v['name']=='..' or $v['name']=='.svn' ){
						continue;
					}
	
					$arr = $this->search_nodes($s, $path.$v['name'].'/');
					$items = array_merge($items, $arr);
				}else{
					if( strstr($v['name'], $s) ){
						$items[] = $path.$v['name'];
						$this->send_msg($this->startedAt , $path.$v['name']);
					}
				}
			}
	
			return $items;
		}
	
		function search($s, $path)
		{
			$this->startedAt = time();
			return $this->search_nodes($s, $path);
		}
	}

} elseif($server_type==='ftp') {
	
	class ftp extends server{
		function connect($host, $user, $pass, $port=21, $dir, $options=array())
		{
			$pasv = $options['pasv'] ? $options['pasv'] : true;
			$logon_type = $options['logon_type'];
			$encryption = $options['encryption'];
	
			if( !$host ){
				$this->ftp_log[]='No domain';
				return false;
			}
	
			if( !$options['timeout'] ){
				$options['timeout'] = 10;
			}
	
			if(!function_exists('ftp_connect')){
				$this->ftp_log[] = 'PHP FTP module is not installed';
				return false;
			}
	
			if (function_exists('ftp_ssl_connect')) {
				$this->conn_id = ftp_ssl_connect($host, $port, $options['timeout']);
			} else {
				$this->conn_id = ftp_connect($host, $port, $options['timeout']);
			}
	
			if( !$this->conn_id ){
				$this->ftp_log[] = 'connection to host failed';
				return false;
			}
	
			if( $encryption ){
				$result = ftp_login($this->conn_id, $user, $pass);
			}else{
				$this->command("USER ".$user);
				$result = $this->command("PASS ".$pass);
			}
	
			if( substr($result,0,3)=='530' ){
				$this->require_password = true;
			}
	
			if( $pasv ){
				ftp_pasv($this->conn_id, true);
			}
		
			// prefix dir with slash
			if(strlen($dir) and substr($dir, 0, 1)!='/') {
				$dir = '/'.$dir;
			}
	
			$this->dir = $dir;
	
			if( substr($result,0,3)!=='230' and $result!==true ){
				return false;
			}elseif( $dir and !$this->chdir($dir) ){
				$this->ftp_log[] = 'Dir does not exist: '.$dir;
				return false;
			}else{
				return true;
			}
		}
	
		function command($command)
		{
			$result=ftp_raw($this->conn_id, $command);
	
			if( substr($command,0,5)=='PASS ' ){
				$command='PASS ******';
			}
	
			$this->ftp_log[] = $command;
			$this->ftp_log = array_merge($this->ftp_log, $result);
	
			return trim(end($result));
		}
	
		function chdir($path){
			if( $path === $this->pwd ){
				return true;
			}else{
				$this->ftp_log[] = 'chdir '.$path;
				if( @ftp_chdir($this->conn_id, $path) ){
					$this->pwd = $path;
					return true;
				}else{
					return false;
				}
			}
		}
	
		function get($remote_file, $get_file=false)
		{
			$remote_file = $this->dir.$remote_file;
	
			//check file size
			$size = ftp_size($this->conn_id, $remote_file);
			if( $size > $this->max_size ){
				$this->ftp_log[] = 'File too large: '.file_size($size);
				return false;
			}
	
			$tmpdir = sys_get_temp_dir() or trigger_error('failed to get tmp dir');
			$tmpfname = tempnam($tmpdir, "shiftedit_ftp_") or trigger_error('failed to create tmp file');
			$handle = fopen($tmpfname, "w+") or trigger_error('failed to open tmp file');
	
			if( ftp_fget($this->conn_id, $handle, $remote_file, FTP_BINARY) ){
				if($get_file){
					fclose($handle);
					return $tmpfname;
				}
	
				rewind($handle);
				$data = stream_get_contents($handle, $this->max_size);
				
				fclose($handle);
				unlink($tmpfname);
	
				return $data;
			} else {
				fclose($handle);
				unlink($tmpfname);
	
				return false;
			}
		}
	
		function put($file, $content, $resume_pos=0)
		{
			$mode = FTP_BINARY;
	
			if( !$file ){
				return false;
			}
	
			$path = $this->dir.$file;
	
			$tmp = tmpfile();
			if( fwrite($tmp, $content)===false ){
				$this->ftp_log[]='can\'t write to filesystem';
				return false;
			}
			rewind($tmp);
	
			$this->chdir(dirname($path));
	
			if($resume_pos){
				ftp_raw($this->conn_id, "REST ".$resume_pos);
			}
	
			$result = ftp_fput($this->conn_id, basename_safe($path), $tmp, $mode);
	
			//try deleting first
			if( $result === false ){
				$items = $this->parse_raw_list(dirname($file));
				if (!is_array($items)) {
					return false;
				}
	
				$perms=0;
				foreach( $items as $v ){
					if( $v['name'] == basename($file) ){
						$perms = $v['permsn'];
					}
				}
	
				//delete before save otherwise save does not work on some servers
				$this->delete($file);
	
				if( $perms ){
					$this->chmod($perms, $file);
				}
	
				if($resume_pos){
					ftp_raw($this->conn_id, "REST ".$resume_pos);
				}
	
				$result = ftp_fput($this->conn_id, basename_safe($path), $tmp, $mode);
			}
	
			fclose($tmp);
	
			return $result;
		}
	
		function last_modified($file){
			$file = $this->dir.$file;
			return ftp_mdtm($this->conn_id, $file);
		}
	
		function size($file){
			$file = $this->dir.$file;
			return ftp_size($this->conn_id, $file);
		}
	
		function is_dir($dir)
		{
			$dir = $this->dir.$dir;
	
			// Get the current working directory
			$origin = ftp_pwd($this->conn_id);
	
			// Attempt to change directory, suppress errors
			return ftp_size($this->conn_id, $dir)===-1;
		}
	
		function file_exists($file)
		{
			$file=$this->dir.$file;
	
			if(ftp_size($this->conn_id, $file) == '-1'){
				//folder?
				if($this->chdir($file)){
					return true;
				}
				return false;
			}else{
				return true;
			}
		}
	
		function chmod($mode, $file)
		{
			$file = $this->dir.$file;
			return ftp_chmod($this->conn_id, intval($mode, 8), $file);
		}
	
		function rename($old_name, $new_name)
		{
			$old_name=$this->dir.$old_name;
			$new_name=$this->dir.$new_name;
	
			return ftp_rename($this->conn_id, $old_name, $new_name);
		}
	
		function mkdir($dir)
		{
			$dir = $this->dir.$dir;
			return ftp_mkdir($this->conn_id, $dir) !== false ? true : false;
		}
	
		function delete($file)
		{
			if( !$file ){
				$this->ftp_log[]='no file';
				return false;
			}
	
			$path = $this->dir.$file;
	
			if( $this->is_dir($file) ){
				$list = $this->parse_raw_list($file);
				if (!is_array($list)) {
					return false;
				}
				
				foreach ($list as $item){
					if( $item['name'] != '..' && $item['name'] != '.' ){
						$this->delete($file.'/'.$item['name']);
					}
				}
	
				if( !$this->chdir(dirname($path)) ){
					return false;
				}
	
				$this->log('rmdir '.$file);
				if( !ftp_rmdir($this->conn_id, basename($path)) ){
					return false;
				}else{
					return true;
				}
			}else{
				if( $this->file_exists($file) ){
					$this->log('delete '.$file);
					return ftp_delete($this->conn_id, $path);
				}
			}
		}
	
		function parse_raw_list( $path )
		{
			$path = $this->dir.$path;
	
			$array = ftp_rawlist($this->conn_id, '-a '.$path);
	
			if( $array === false ){
				return false;
			}
	
			$items = array();
	
			//$systype = ftp_systype($this->conn_id);
	
			foreach( $array as $folder ){
				$struc = array();
	
				if( preg_match("/([0-9]{2})-([0-9]{2})-([0-9]+) +([0-9]{2}):([0-9]{2})(AM|PM) +([0-9]+|<DIR>) +(.+)/", $folder, $split) ){
					if (is_array($split)) {
						if ($split[3]<70) { $split[3]+=2000; } else { $split[3]+=1900; } // 4digit year fix
						$struc['month'] = $split[1];
						$struc['day'] = $split[2];
	
						if( strlen($split[3])==4 ){
							$struc['year'] = $split[3];
							$struc['time'] = '00:00';
						}else{
							$struc['year'] = date('Y');
							$struc['time'] = $split[3];
	
							if (strtotime($struc['month'].' '.$struc['day'].' '.$struc['year'].' '.$struc['time'])>time()) {
								$struc['year']-=1;
							}
						}
	
						$struc['modified'] = strtotime($struc['month'].' '.$struc['day'].' '.$struc['year'].' '.$struc['time']);
	
						$struc['name'] = $split[8];
	
						if ($split[7]=="<DIR>"){
							$struc['type'] = 'folder';
						}else{
							$struc['type'] = 'file';
							$struc['size'] = $split[7];
						}
					}
				}else{
					$current = preg_split("/[\s]+/", $folder, 9);
	
					$i = 0;
	
					$struc['perms'] = $current[0];
					$struc['permsn'] = $this->chmod_num($struc['perms']);
					$struc['number'] = $current[1];
					$struc['owner'] = $current[2];
	
					$struc['group'] = $current[4];
	
					$struc['size'] = $current[(count($current)-5)];
					$struc['month'] = $current[(count($current)-4)];
					$struc['day'] = $current[(count($current)-3)];
					$date = $current[(count($current)-2)];
					$struc['name'] = str_replace('//', '', end($current));
	
					if( strlen($date)==4 ){
						$struc['year'] = $date;
						$struc['time'] = '00:00';
					}else{
						$struc['year'] = date('Y');
	
						if( strtotime($struc['month'].' '.$struc['day'])>time() ){
							$struc['year']--;
						}
	
						$struc['time'] = $date;
					}
	
					$struc['modified'] = strtotime($struc['month'].' '.$struc['day'].' '.$struc['year'].' '.$struc['time']);
	
					$struc['raw'] = $folder;
	
					if( substr($folder, 0, 1) == "d" ){
						$struc['type'] = 'folder';
					}elseif (substr($folder, 0, 1) == "l"){
						$struc['type'] = 'link';
						continue;
					}else{
						$struc['type'] = 'file';
					}
				}
	
				if( $struc['name'] ){
					$items[] = $struc;
				}
			}
	
			return $items;
		}
	
		function search_nodes($s, $path)
		{
			$list = $this->parse_raw_list($path);
			if( !is_array($list) ){
				return array();
			}
	
			$items = array();
	
			foreach( $list as $v ){
				if( $v['type']!='file' ){
					if( $v['name']=='.' or $v['name']=='..' or $v['name']=='.svn' ){
						continue;
					}
	
					$arr = $this->search_nodes($s, $path.$v['name'].'/');
					$items = array_merge($items,$arr);
				}else{
					if( strstr($v['name'], $s) ){
						$items[] = $path.$v['name'];
	
						$this->send_msg($this->startedAt , $path.$v['name']);
					}
				}
			}
	
			return $items;
		}
	
		function search($s, $path)
		{
			$this->startedAt = time();
			return $this->search_nodes($s, $path);
		}
	
		function close()
		{
			ftp_close($this->conn_id);
		}
	}

} else if($server_type==='sftp') {
	if ( $phpseclib_path) {
		class sftp extends server{
			function __construct()
			{
				$this->debug = false;
	
				parent::__construct();
				require_once('Net/SFTP.php');
	
				if( $this->debug ){
					define('NET_SSH2_LOGGING', NET_SSH2_LOG_COMPLEX);
				}
			}
	
			function errorHandler($errno, $errstr, $errfile, $errline)
			{
				if( $this->debug ){
					print $errstr." in ";
					print $errfile." on line ";
					print $errline."\n";
				}
	
				if( $errno===E_USER_NOTICE ){
					$this->ftp_log[] = $errstr;
					$this->failed = true;
				}
			}
	
			function connect($host, $user, $pass, $port=22, $dir, $options=array())
			{
				if( !$host ){
					return false;
				}
	
				$this->ftp_log = array();
				$this->failed = false;
	
				$logon_type = $options['logon_type'];
	
				if( !$options['timeout'] ){
					$options['timeout'] = 10;
				}
	
				set_error_handler(array($this, 'errorHandler'));
	
				$this->sftp = new Net_SFTP($host, $port, $options['timeout']);
	
				if( !$this->sftp ){
					$this->ftp_log[] = 'connection to host failed';
					$this->ftp_log[] = $this->sftp->getSFTPLog();
					return false;
				}
	
				if( $this->failed ){
					return;
				}
	
				if( $logon_type=='key' ){
					if( !$private_key ){
						$this->ftp_log[]='missing key - set a key from your account';
						return false;
					}
	
					require_once("Crypt/RSA.php");
	
					$pass = new Crypt_RSA();
	
					if( !$pass->loadKey($private_key) ){
						$this->ftp_log[] = 'invalid key';
						return false;
					}
				}
	
				if( $this->sftp->login($user, $pass) === false ) {
					$stars = '';
	
					for( $i=0; $i<strlen($pass); $i++ ){
						$stars.='*';
					}
	
					$this->ftp_log[] = $this->sftp->getLog();
					$this->ftp_log[] = $this->sftp->getSFTPLog();
	
					if( $logon_type == 'key' ){
						$this->ftp_log[] = 'Can\'t connect with key';
					}else{
						$this->ftp_log[] = 'login incorrect<br>User: '.$user.'<br>Pass: '.$stars.'<br>'.$log;
						$this->require_password = true;
					}
	
					return false;
				}
	
				$this->dir = $dir;
	
				if( !$this->sftp ){
					return false;
				}elseif( $dir and !$this->sftp->stat($dir) ){
					$this->ftp_log[] = 'Dir does not exist: '.$dir;
					return false;
				}else{
					return true;
				}
			}
	
			function get($remote_file)
			{
				$remote_file = $this->dir.$remote_file;
	
				//check file size
				$size = $this->sftp->size($remote_file);
				if( $size > $this->max_size ){
					$this->ftp_log[] = 'File too large: '.file_size($size);
					return false;
				}
	
				$data = $this->sftp->get($remote_file);
	
				if($data===false){
					return false;
				}
	
				return $data;
			}
	
			function put($remote_file, $content, $resume_pos=-1)
			{
				$remote_file = $this->dir.$remote_file;
				return $this->sftp->put($remote_file, $content, NET_SFTP_STRING, $resume_pos);
			}
	
			function last_modified($file)
			{
				$file = $this->dir.$file;
				$stat = $this->sftp->stat($file);
	
				return $stat['mtime'];
			}
	
			function size($file)
			{
				$file = $this->dir.$file;
				return $this->sftp->size($file);
			}
	
			function is_dir($file)
			{
				$file = $this->dir.$file;
				$stat = $this->sftp->stat($file);
	
				return ($stat['type']==2) ? true : false;
			}
	
			function file_exists($file)
			{
				$file = $this->dir.$file;
				$stat = $this->sftp->stat($file);
	
				return $stat ? true : false;
			}
	
			function chmod($mode, $file)
			{
				$file = $this->dir.$file;
	
				return $this->sftp->chmod($mode,$file);
			}
	
			function rename($old_name, $new_name)
			{
				$old_name = $this->dir.$old_name;
				$new_name = $this->dir.$new_name;
	
				return $this->sftp->rename($old_name, $new_name);
			}
	
			function mkdir($dir)
			{
				$dir = $this->dir.$dir;
				return $this->sftp->mkdir($dir);
			}
	
			function delete($file)
			{
				if( !$file ){
					return false;
				}
	
				$path = $this->dir.$file;
	
				return $this->sftp->delete($path,true);
			}
	
			function parse_raw_list($subdir)
			{
				$path = $this->dir.$subdir;
	
				$items = array();
				$files = $this->sftp->rawlist($path);
	
				// List all the files
				$i=0;
				foreach ($files as $file=>$stat) {
					if( $file!= '.' and $file!= '..' ){
						$items[$i]['name'] = $file;
						$items[$i]['permsn'] = $stat['permissions'];
	
						if( $stat['type']==1 ){
							$items[$i]['type'] = 'file';
							$items[$i]['size'] = (int)$stat['size'];
						}elseif( $stat['type']==2 ){
							$items[$i]['type'] = 'folder';
						}else{
							//ignore symlinks
							continue;
						}
	
						$items[$i]['modified'] = $stat['mtime'];
					}
					$i++;
				}
	
				return $items;
			}
	
			function search_nodes($s, $path)
			{
				$packet_handler = function($string) {
					$items = explode("\n", trim($string));
					$items = array_unique($items);
					sort($items);
					
					foreach($items as $k=>$v) {
						if (strstr($v, ':')) {
							continue;
						}
						
						$v = substr($v, strlen($dir));
						$this->send_msg($this->startedAt , $v);
					}
				};
				
				$dir = $this->dir.$path;
				
				$this->exec("pkill grep");
				$this->exec("pkill find");
				$results = $this->exec('grep -Ilr '.escapeshellarg($s).' '.escapeshellarg($dir), $packet_handler);
				$results .= $this->exec('find '.escapeshellarg($dir).' -name "'.escapeshellarg($s).'*"', $packet_handler);
			}
		
			function search($s, $path)
			{
				$this->startedAt = time();
				return $this->search_nodes($s, $path);
			}
	
			function close()
			{
				if( $this->sftp ){
					$this->sftp->__destruct();
				}
			}
		}
	}else if(function_exists('ssh2_connect')) {
		class sftp extends server{
			function connect($host, $user, $pass, $port=22, $dir, $options=array())
			{
				if (!function_exists('ssh2_connect')) {
					$this->ftp_log[]='PHP SSH2 module not loaded';
					return false;
				}
					
				$logon_type = $options['logon_type'];
		
				if( !$options['timeout'] ){
					$options['timeout'] = 10;
				}
		
				if( !$host ){
					$this->ftp_log[]='No domain';
					return false;
				}
		
				if( !$options['timeout'] ){
					$options['timeout'] = 10;
				}
		
				$this->conn_id = ssh2_connect($host, $port);
		
				if( !$this->conn_id ){
					$this->ftp_log[] = 'connection to host failed';
					return false;
				}
		
				$result = ssh2_auth_password($this->conn_id, $user, $pass);
		
				if( !$result ){
					$this->ftp_log[] = 'login incorrect';
					$this->require_password = true;
				}
		
				//print var_dump((stream_get_contents(ssh2_exec($this->conn_id, 'pwd')))); exit;
		
				$this->sftp = ssh2_sftp($this->conn_id);
		
				if($this->sftp === null){
					die('can\'t establish sftp');
				}
		
				$this->dir = $dir;
		
				if( substr($result,0,3)!=='230' and $result!==true ){
					return false;
				}elseif( $dir and !$this->chdir($dir) ){
					$this->ftp_log[] = 'Dir does not exist: '.$dir;
					return false;
				}else{
					return true;
				}
			}
		
			function chdir($path){
				if( $path === $this->pwd ){
					return true;
				}else{
					$this->ftp_log[] = 'chdir '.$path;
					if( $this->exec('cd '.$path.'; pwd').'/'===$path ){
						$this->pwd = $this->exec('pwd');
						return true;
					}else{
						return false;
					}
				}
			}
		
			function get($remote_file, $mode=FTP_BINARY, $resume_pos=null)
			{
				$remote_file = $this->dir.$remote_file;
		
				//check file size
				$stat = ssh2_sftp_stat($this->sftp, $remote_file);
				$size = $stat['size'];
				if( $size > $this->max_size ){
					$this->ftp_log[] = 'File too large: '.file_size($size);
					return false;
				}
		
				$handle = fopen("ssh2.sftp://".$this->sftp."/".$remote_file, 'r');
		
				if( $handle ){
					$data = stream_get_contents($handle, $this->max_size);
					fclose($handle);
					return $data;
				}else{
					fclose($handle);
					return false;
				}
			}
		
			function put($file, $content, $resume_pos=-1)
			{
				$remote_file = $this->dir.$file;
				$handle = fopen("ssh2.sftp://".$this->sftp."/".$remote_file, 'w');
				
				if ($resume_pos!=-1) {
					fseek($handle, $resume_pos);
				}

				return fwrite($handle , $content);
			}
		
			function last_modified($file){
				$remote_file = $this->dir.$file;
				$stat = ssh2_sftp_stat($this->sftp, $remote_file);
				return $stat['mtime'];
			}
		
			function size($file){
				$remote_file = $this->dir.$file;
				$stat = ssh2_sftp_stat($this->sftp, $remote_file);
				return $stat['size'];
			}
		
			function is_dir($dir)
			{
				$dir = $this->dir.$dir;
		
				// Get the current working directory
				$origin = $this->exec('pwd');
		
				// Attempt to change directory, suppress errors
				if (@$this->chdir($dir))
				{
					// If the directory exists, set back to origin
					$this->chdir($origin);
					return true;
				}
		
				// Directory does not exist
				return false;
			}
		
			function file_exists($file)
			{
				$file = $this->dir.$file;
				$stat = ssh2_sftp_stat($this->sftp, $file);
		
				if($stat['size'] == '-1'){
					//folder?
					if($this->chdir($file)){
						return true;
					}
					return false;
				}else{
					return true;
				}
			}
		
			function chmod($mode,$file)
			{
				$file = $this->dir.$file;
		
				return ssh2_sftp_chmod($this->sftp, $file, $mode);
			}
		
			function rename($old_name, $new_name)
			{
				$old_name = $this->dir.$old_name;
				$new_name = $this->dir.$new_name;
		
				return ssh2_sftp_rename($this->sftp, $old_name, $new_name);
			}
		
			function mkdir($dir)
			{
				$dir = $this->dir.$dir;
				return ssh2_sftp_mkdir($this->sftp, $dir) !== false ? true : false;
			}
		
			function delete($file)
			{
				if( !$file ){
					$this->ftp_log[]='no file';
					return false;
				}
		
				$path = $this->dir.$file;
		
				if( $this->is_dir($file) ){
					$list = $this->parse_raw_list($file);
					if (!is_array($list)) {
						return false;
					}
					
					foreach ($list as $item){
						if( $item['name'] != '..' && $item['name'] != '.' ){
							$this->delete($file.'/'.$item['name']);
						}
					}
		
					if( !$this->chdir(dirname($path)) ){
						return false;
					}
		
					$this->ftp_log[]= 'rmdir '.$path;
					if( !ssh2_sftp_rmdir($this->sftp, basename($path)) ){
						return false;
					}else{
						return true;
					}
				}else{
					if( $this->file_exists($file) ){
						$this->ftp_log[]='delete '.$path;
						return ssh2_sftp_unlink($this->sftp, $path);
					}
				}
			}
		
			function chmod_num($permissions)
			{
				$mode = 0;
		
				if ($permissions[1] == 'r') $mode += 0400;
				if ($permissions[2] == 'w') $mode += 0200;
				if ($permissions[3] == 'x') $mode += 0100;
				else if ($permissions[3] == 's') $mode += 04100;
				else if ($permissions[3] == 'S') $mode += 04000;
		
				if ($permissions[4] == 'r') $mode += 040;
				if ($permissions[5] == 'w') $mode += 020;
				if ($permissions[6] == 'x') $mode += 010;
				else if ($permissions[6] == 's') $mode += 02010;
				else if ($permissions[6] == 'S') $mode += 02000;
		
				if ($permissions[7] == 'r') $mode += 04;
				if ($permissions[8] == 'w') $mode += 02;
				if ($permissions[9] == 'x') $mode += 01;
				else if ($permissions[9] == 't') $mode += 01001;
				else if ($permissions[9] == 'T') $mode += 01000;
		
				return sprintf('%o', $mode);
			}
		
			function parse_raw_list($subdir='')
			{
				$path = $this->dir.$subdir;
		
				$items = array();
				$list = $this->exec('ls -al '.escapeshellarg($path));
				$files = explode("\n", $list);
		
				// List all the files
				$i=0;
				foreach ($files as $folder) {
					if (preg_match("/total [\d]+/", $folder)) {
						continue;
					}
					
					$struc = array();
		
					$current = preg_split("/[\s]+/", $folder, 9);
		
					$i = 0;
		
					$struc['perms'] = $current[0];
					$struc['permsn'] = $this->chmod_num($struc['perms']);
					$struc['number'] = $current[1];
					$struc['owner'] = $current[2];
		
					$struc['group'] = $current[4];
		
					$struc['size'] = $current[(count($current)-5)];
					$struc['month'] = $current[(count($current)-4)];
					$struc['day'] = $current[(count($current)-3)];
					$date = $current[(count($current)-2)];
					$struc['name'] = str_replace('//', '', end($current));
		
					if( strlen($date)==4 ){
						$struc['year'] = $date;
						$struc['time'] = '00:00';
					}else{
						$struc['year'] = date('Y');
		
						if( strtotime($struc['month'].' '.$struc['day'])>time() ){
							$struc['year']--;
						}
		
						$struc['time'] = $date;
					}
		
					$struc['modified'] = strtotime($struc['month'].' '.$struc['day'].' '.$struc['year'].' '.$struc['time']);
		
					$struc['raw'] = $folder;
		
					if( substr($folder, 0, 1) == "d" ){
						$struc['type'] = 'folder';
					}elseif (substr($folder, 0, 1) == "l"){
						$struc['type'] = 'link';
						$pos = strpos($struc['name'], ' -> ');
						
						if ($pos) {
							$struc['name'] = substr($struc['name'], 0, $pos);
						}
					}else{
						$struc['type'] = 'file';
					}
		
					if( $struc['name'] ){
						$items[] = $struc;
					}
		
					$i++;
				}
		
				return $items;
			}
		
			function search_nodes($s, $path)
			{
				$packet_handler = function($string) {
					global $dir;
					$items = explode("\n", trim($string));
					$items = array_unique($items);
					sort($items);
					
					foreach($items as $k=>$v) {
						if (strstr($v, ':')) {
							continue;
						}
						
						$v = substr($v, strlen($dir));
						if ($v) {
							$this->send_msg($this->startedAt , $v);
						}
					}
				};
				
				$dir = $this->dir.$path;
				
				$this->exec("pkill grep");
				$this->exec("pkill find");
				$results = $this->exec('grep -Ilr '.escapeshellarg($s).' '.escapeshellarg($dir));
				$results .= $this->exec('find '.escapeshellarg($dir).' -name "'.escapeshellarg($s).'*"');
				$packet_handler($results);
			}
		
			function search($s, $path)
			{
				$this->startedAt = time();
				return $this->search_nodes($s, $path);
			}
		
			function close()
			{
			}
		
			function exec($command)
			{
				$stream = ssh2_exec($this->conn_id, $command);
		
				if(!$stream){
					return false;
				}
		
				stream_set_blocking($stream, true);
		
				$result = stream_get_contents($stream);
		
				if( substr($command,0 , 5) == 'PASS ' ){
					$command='PASS ******';
				}
		
				$this->ftp_log[] = $command;
				
				if ($result) {
					$this->ftp_log[] = $result;
				}
		
				return trim($result);
			}

		}
	
	}else if(function_exists('curl_version')) {
		class sftp extends server{
			function cd($path) {
				curl_setopt($this->curl, CURLOPT_URL, "sftp://".$this->host.':'.$this->port.'/'.$path);
			}
			
			function connect($host, $user, $pass, $port=22, $dir, $options=array())
			{
				$this->debug = false;
				
				if(!function_exists('curl_version')) {
					$this->log('PHP curl not loaded');
					return false;
				}
					
				if(!$host) {
					return false;
				}
		
				if(!$port) {
					$port = 22;
				}
		
				$this->failed = false;
		
				if(!$options['timeout']) {
					$options['timeout'] = 10;
				}
				$this->timeout = $options['timeout'];
				
				$this->host = $host;
				$this->port = $port;
				$this->dir = $dir;
				
				$this->curl = curl_init();
				$this->cd($this->dir);
				curl_setopt($this->curl, CURLOPT_TIMEOUT, $this->timeout); 
				curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, 1);
		
				if($options['logon_type']=='key') {
					global $auth;
		
					if( !$auth->user['private_key'] ){
						$this->log('missing key - set a key from your account');
						return false;
					}
					
					$this->keyfile = tempnam();
					file_put_contents($this->keyfile, $auth->user['private_key']);
					
					curl_setopt($this->curl, CURLOPT_SSH_PRIVATE_KEYFILE, $this->keyfile);
					curl_setopt($this->curl, CURLOPT_SSH_AUTH_TYPES,CURLSSH_AUTH_PUBLICKEY);
				} else {
					curl_setopt($this->curl, CURLOPT_USERPWD, $user.":".$pass);
				}
				
				return true;
			}
			
			function meta($remote_file) {
				// caching
				if ($this->meta[$remote_file]) {
					return $this->meta[$remote_file];
				}
				
				$dir = dirname($remote_file);
				if($dir === '.') {
					$dir = '';
				}
				
				$files = $this->parse_raw_list($dir);
				if (!is_array($files)) {
					return false;
				}
				
				foreach($files as $v) {
					$this->meta[$dir.'/'.$v['name']] = $v;
				}
		
				return $this->meta[$remote_file] ?: false;
			}
		
			function get($remote_file)
			{
				$path = $this->dir.$remote_file;
				
				//check file size
				$size = $this->size($remote_file);
				if($size > $this->max_size) {
					$this->log('File too large: '.file_size($size));
					return false;
				}
		
				$this->cd($path);
				$data = curl_exec($this->curl);
		
				if($data===false) {
					return false;
				}
		
				return $data;
			}
		
			function put($remote_file, $content, $resume_pos=-1)
			{
				$path = $this->dir.$remote_file;
				$this->cd($path);
				
				$tmp = tmpfile();
				if( fwrite($tmp, $content)===false ){
					$this->log('can\'t write to filesystem');
					return false;
				}
				rewind($tmp);
				
				curl_setopt($this->curl, CURLOPT_UPLOAD, 1);
				curl_setopt($this->curl, CURLOPT_INFILE, $tmp);
				curl_setopt($this->curl, CURLOPT_INFILESIZE, strlen($content));
				curl_exec($this->curl);
				$error_no = curl_errno($this->curl);
				fclose($tmp);

				$this->curl = curl_init();
				curl_setopt($this->curl, CURLOPT_TIMEOUT, $this->timeout); 
				curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, 1);
				
				if ($error_no === 0) {
					return true;
				} else {
					$this->log(curl_error($this->curl));
					return false;
				}
			}
		
			function last_modified($file)
			{
				$meta = $this->meta($file);
				return $meta['modified'];
			}
		
			function size($file)
			{
				$meta = $this->meta($file);
				return $meta['size'];
			}
		
			function is_dir($file)
			{
				$meta = $this->meta($file);
				return $meta['type'] === 'folder';
			}
		
			function file_exists($file)
			{
				$meta = $this->meta($file);
				return $meta!==false ? true : false;
			}
		
			function chmod($mode, $file)
			{
				$path = $this->dir.$file;
				return $this->exec('chmod '.$mode.' "'.$path.'"');
			}
		
			function rename($old_name, $new_name)
			{
				$old_name = $this->dir.$old_name;
				$new_name = $this->dir.$new_name;
		
				return $this->exec('rename "'.$old_name.'" "'.$new_name.'"');
			}
		
			function mkdir($dir)
			{
				$path = $this->dir.$dir;
				return $this->exec('mkdir "'.$path.'"');
			}
		
			function delete($file)
			{
				if( !$file ){
					$this->log('no file');
					return false;
				}
		
				$path = $this->dir.$file;
		
				if($this->is_dir($file)) {
					$list = $this->parse_raw_list($file);
					if (!is_array($list)) {
						return false;
					}
					
					foreach ($list as $item){
						if( $item['name'] != '..' && $item['name'] != '.' ){
							return $this->exec('rm "'.$file.'/'.$item['name'].'"');
						}
					}
		
					if(!$this->exec('rmdir "'.$path.'"')) {
						return false;
					} else {
						return true;
					}
				} else {
					if($this->file_exists($file)) {
						return $this->exec('rm "'.$path.'"');
					}
				}
			}
		
			function parse_raw_list($dir='')
			{
				$items = array();
				
				$this->cd($this->dir.$dir.'/');
				curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, 'LIST -a');
				$list = curl_exec($this->curl);
				
				if (curl_errno($this->curl)) {
					$this->log(curl_error($this->curl));
					return false;
				}
		
				$files = explode("\n", $list);
		
				// List all the files
				$i=0;
				foreach ($files as $folder) {
					$struc = array();
		
					$current = preg_split("/[\s]+/",$folder,9);
		
					$i = 0;
		
					$struc['perms'] = $current[0];
					$struc['permsn'] = $this->chmod_num($struc['perms']);
					$struc['number'] = $current[1];
					$struc['owner'] = $current[2];
		
					$struc['group'] = $current[4];
		
					$struc['size'] = $current[(count($current)-5)];
					$struc['month'] = $current[(count($current)-4)];
					$struc['day'] = $current[(count($current)-3)];
					$date = $current[(count($current)-2)];
					$struc['name'] = trim(str_replace('//', '', end($current)));
		
					if( strlen($date)==4 ){
						$struc['year'] = $date;
						$struc['time'] = '00:00';
					}else{
						$struc['year'] = date('Y');
		
						if( strtotime($struc['month'].' '.$struc['day'])>time() ){
							$struc['year']--;
						}
		
						$struc['time'] = $date;
					}
		
					$struc['modified'] = strtotime($struc['month'].' '.$struc['day'].' '.$struc['year'].' '.$struc['time']);
		
					$struc['raw'] = $folder;
		
					if( substr($folder, 0, 1) == "d" ){
						$struc['type'] = 'folder';
					}elseif (substr($folder, 0, 1) == "l"){
						$struc['type'] = 'link';
						
						if ($pos) {
							$struc['name'] = substr($struc['name'], 0, $pos);
						}
					}else{
						$struc['type'] = 'file';
					}
		
					if($struc['name']) {
						$items[] = $struc;
					}
		
					$i++;
				}
		
				return $items;
			}
		
			function search_nodes($s, $path)
			{
				$packet_handler = function($string) {
					$items = explode("\n", trim($string));
					$items = array_unique($items);
					sort($items);
					
					foreach($items as $k=>$v) {
						if (strstr($v, ':')) {
							continue;
						}
						
						$v = substr($v, strlen($dir));
						$this->send_msg($this->startedAt , $v);
					}
				};
				
				$dir = $this->dir.$path;
				
				$this->exec("pkill grep");
				$this->exec("pkill find");
				$results = $this->exec('grep -Ilr '.escapeshellarg($s).' '.escapeshellarg($dir), $packet_handler);
				$results .= $this->exec('find '.escapeshellarg($dir).' -name "'.escapeshellarg($s).'*"', $packet_handler);
			}
		
			function search($s, $path)
			{
				return $this->search_nodes($s, $path);
			}
		
			function close()
			{
				if( $this->curl ){
					curl_close($this->curl);
				}
				
				if ($this->keyfile) {
					unlink($this->keyfile);
				}
			}
			
			function exec($command)
			{
				if (!is_array($command)) {
					$command = array($command);
				}
				
				$this->log($command);
				
				// Make curl run our command before the actual operation, ...
				curl_setopt($this->curl, CURLOPT_QUOTE, $command);
				// ... but do not do any operation at all
				curl_setopt($this->curl, CURLOPT_NOBODY, 1);
				
				if ($this->debug) {
					curl_setopt($this->curl, CURLOPT_VERBOSE, true);
					$stream_log = fopen('php://temp', 'r+b');
					curl_setopt($this->curl, CURLOPT_STDERR, $stream_log);
				}
				
				$result = curl_exec($this->curl);
				$error_no = curl_errno($this->curl);
				
				if ($error_no === 0) {
					return true;	
				} else {
					if ($this->debug) {
						//print_r(curl_getinfo($this->curl));
						rewind($stream_log);
						$this->log(stream_get_contents($stream_log));
						fclose($stream_log);
					} else {
						$this->log(curl_error($this->curl));
					}
					
					return false;
				}
			}
		}
	} else {
		trigger_error('no sftp library found');
	}
} else if ($server_type){
	trigger_error('invalid server type: '.$server_type);
} else {
	trigger_error("missing server type in proxy file");
}

/* START OF GIT CLASS */

/**
 * Git Interface Class
 *
 * This class enables the creating, reading, and manipulation
 * of git repositories.
 *
 * @class Git
 */
class Git {

	/**
	* Git executable location
	*
	* @var string
	*/
	protected static $bin = '/usr/bin/git';

	/**
	* Sets git executable path
	*
	* @param string $path executable location
	*/
	public static function set_bin($path) {
		self::$bin = $path;
	}

	/**
	* Gets git executable path
	*/
	public static function get_bin() {
		return self::$bin;
	}

	/**
	* Sets up library for use in a default Windows environment
	*/
	public static function windows_mode() {
		self::set_bin('git');
	}

	/**
	* Create a new git repository
	*
	* Accepts a creation path, and, optionally, a source path
	*
	* @access public
	* @param	string repository path
	* @param	string directory to source
	* @return GitRepo
	*/
	public static function &create($repo_path, $source = null) {
		return GitRepo::create_new($repo_path, $source);
	}

	/**
	* Open an existing git repository
	*
	* Accepts a repository path
	*
	* @access public
	* @param	string repository path
	* @return GitRepo
	*/
	public static function open($repo_path) {
		return new GitRepo($repo_path);
	}

	/**
	* Clones a remote repo into a directory and then returns a GitRepo object
	* for the newly created local repo
	*
	* Accepts a creation path and a remote to clone from
	*
	* @access public
	* @param	string repository path
	* @param	string remote source
	* @param	string reference path
	* @return GitRepo
	**/
	public static function &clone_remote($repo_path, $remote, $reference = null) {
		return GitRepo::create_new($repo_path, $remote, true, $reference);
	}

	/**
	* Checks if a variable is an instance of GitRepo
	*
	* Accepts a variable
	*
	* @access public
	* @param	mixed	variable
	* @return bool
	*/
	public static function is_repo($var) {
		return (get_class($var) == 'GitRepo');
	}

}

// ------------------------------------------------------------------------

/**
 * Git Repository Interface Class
 *
 * This class enables the creating, reading, and manipulation
 * of a git repository
 *
 * @class GitRepo
 */
class GitRepo {

	protected $repo_path = null;
	protected $bare = false;
	protected $envopts = array();

	/**
	* Create a new git repository
	*
	* Accepts a creation path, and, optionally, a source path
	*
	* @access public
	* @param	string repository path
	* @param	string directory to source
	* @param	string reference path
	* @return GitRepo
	*/
	public static function &create_new($repo_path, $source = null, $remote_source = false, $reference = null) {
		if (is_dir($repo_path) && file_exists($repo_path."/.git") && is_dir($repo_path."/.git")) {
			throw new Exception('"'.$repo_path.'" is already a git repository');
		} else {
			$repo = new self($repo_path, true, false);
			if (is_string($source)) {
				if ($remote_source) {
					if (!is_dir($reference) || !is_dir($reference.'/.git')) {
						throw new Exception('"'.$reference.'" is not a git repository. Cannot use as reference.');
					} else if (strlen($reference)) {
						$reference = realpath($reference);
						$reference = "--reference $reference";
					}
					$repo->clone_remote($source, $reference);
				} else {
					$repo->clone_from($source);
				}
			} else {
				$repo->run('init');
			}
			return $repo;
		}
	}

	/**
	* Constructor
	*
	* Accepts a repository path
	*
	* @access public
	* @param	string repository path
	* @param	bool	create if not exists?
	* @return void
	*/
	public function __construct($repo_path = null, $create_new = false, $_init = true) {
		if (is_string($repo_path)) {
			$this->set_repo_path($repo_path, $create_new, $_init);
		}
	}

	/**
	* Set the repository's path
	*
	* Accepts the repository path
	*
	* @access public
	* @param	string repository path
	* @param	bool	create if not exists?
	* @param	bool	initialize new Git repo if not exists?
	* @return void
	*/
	public function set_repo_path($repo_path, $create_new = false, $_init = true) {
		if (is_string($repo_path)) {
			if ($new_path = realpath($repo_path)) {
				$repo_path = $new_path;
				if (is_dir($repo_path)) {
					// Is this a work tree?
					if (file_exists($repo_path."/.git") && is_dir($repo_path."/.git")) {
						$this->repo_path = $repo_path;
						$this->bare = false;
						
						if (!is_writable($repo_path."/.git")) {
							$user_info = posix_getpwuid(fileowner($repo_path."/.git"));
							$file_owner = $user_info['name'];
							$this->command_prefix = 'sudo -u '.$file_owner.' ';
						}
					// Is this a bare repo?
					} else if (is_file($repo_path."/config")) {
						$parse_ini = parse_ini_file($repo_path."/config");
						if ($parse_ini['bare']) {
							$this->repo_path = $repo_path;
							$this->bare = true;
						}
					} else {
						if ($create_new) {
							$this->repo_path = $repo_path;
							if ($_init) {
								$this->run('init');
							}
						} else {
							throw new Exception('"'.$repo_path.'" is not a git repository');
						}
					}
				} else {
					throw new Exception('"'.$repo_path.'" is not a directory');
				}
			} else {
				if ($create_new) {
					if ($parent = realpath(dirname($repo_path))) {
						mkdir($repo_path);
						$this->repo_path = $repo_path;
						if ($_init) $this->run('init');
					} else {
						throw new Exception('cannot create repository in non-existent directory');
					}
				} else {
					throw new Exception('"'.$repo_path.'" does not exist');
				}
			}
		}
	}
	
	/**
	* Get the path to the git repo directory (eg. the ".git" directory)
	* 
	* @access public
	* @return string
	*/
	public function git_directory_path() {
		return ($this->bare) ? $this->repo_path : $this->repo_path."/.git";
	}

	/**
	* Tests if git is installed
	*
	* @access public
	* @return bool
	*/
	public function test_git() {
		$descriptorspec = array(
			1 => array('pipe', 'w'),
			2 => array('pipe', 'w'),
		);
		$pipes = array();
		$resource = proc_open(Git::get_bin(), $descriptorspec, $pipes);

		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		foreach ($pipes as $pipe) {
			fclose($pipe);
		}

		$status = trim(proc_close($resource));
		return ($status != 127);
	}

	/**
	* Run a command in the git repository
	*
	* Accepts a shell command to run
	*
	* @access protected
	* @param	string command to run
	* @return string
	*/
	protected function run_command($command) {
		$descriptorspec = array(
			1 => array('pipe', 'w'),
			2 => array('pipe', 'w'),
		);
		$pipes = array();
		/* Depending on the value of variables_order, $_ENV may be empty.
		* In that case, we have to explicitly set the new variables with
		* putenv, and call proc_open with env=null to inherit the reset
		* of the system.
		*
		* This is kind of crappy because we cannot easily restore just those
		* variables afterwards.
		*
		* If $_ENV is not empty, then we can just copy it and be done with it.
		*/
		if(count($_ENV) === 0) {
			$env = NULL;
			foreach($this->envopts as $k => $v) {
				putenv(sprintf("%s=%s",$k,$v));
			}
		} else {
			$env = array_merge($_ENV, $this->envopts);
		}
		$cwd = $this->repo_path;
		$resource = proc_open($command, $descriptorspec, $pipes, $cwd, $env);

		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		foreach ($pipes as $pipe) {
			fclose($pipe);
		}

		$status = trim(proc_close($resource));
		if ($status) throw new Exception($stderr);
		
		// weird issue where result is in stderr
		if(!$stdout and $stderr) return $stderr;

		return $stdout;
	}

	/**
	* Run a git command in the git repository
	*
	* Accepts a git command to run
	*
	* @access public
	* @param	string command to run
	* @return string
	*/
	public function run($command) {
		return $this->run_command($this->command_prefix.Git::get_bin()." ".$command);
	}

	/**
	* Runs a 'git status' call
	*
	* Accept a convert to HTML bool
	*
	* @access public
	* @param bool return string with <br />
	* @return string
	*/
	public function status($html = false) {
		$msg = $this->run("status");
		if ($html == true) {
			$msg = str_replace("\n", "<br />", $msg);
		}
		return $msg;
	}

	/**
	* Runs a `git add` call
	*
	* Accepts a list of files to add
	*
	* @access public
	* @param	mixed	files to add
	* @return string
	*/
	public function add($files = "*") {
		if (is_array($files)) {
			$files = '"'.implode('" "', $files).'"';
		}
		return $this->run("add $files -v");
	}

	/**
	* Runs a `git rm` call
	*
	* Accepts a list of files to remove
	*
	* @access public
	* @param	mixed	files to remove
	* @param	Boolean use the --cached flag?
	* @return string
	*/
	public function rm($files = "*", $cached = false) {
		if (is_array($files)) {
			$files = '"'.implode('" "', $files).'"';
		}
		return $this->run("rm ".($cached ? '--cached ' : '').$files);
	}


	/**
	* Runs a `git commit` call
	*
	* Accepts a commit message string
	*
	* @access public
	* @param	string commit message
	* @param	boolean should all files be committed automatically (-a flag)
	* @return string
	*/
	public function commit($message = "", $commit_all = true) {
		$flags = $commit_all ? '-av' : '-v';
		return $this->run("commit ".$flags." -m ".escapeshellarg($message));
	}

	/**
	* Runs a `git clone` call to clone the current repository
	* into a different directory
	*
	* Accepts a target directory
	*
	* @access public
	* @param	string target directory
	* @return string
	*/
	public function clone_to($target) {
		return $this->run("clone --local ".$this->repo_path." $target");
	}

	/**
	* Runs a `git clone` call to clone a different repository
	* into the current repository
	*
	* Accepts a source directory
	*
	* @access public
	* @param	string source directory
	* @return string
	*/
	public function clone_from($source) {
		return $this->run("clone --local $source ".$this->repo_path);
	}

	/**
	* Runs a `git clone` call to clone a remote repository
	* into the current repository
	*
	* Accepts a source url
	*
	* @access public
	* @param	string source url
	* @param	string reference path
	* @return string
	*/
	public function clone_remote($source, $reference) {
		return $this->run("clone $reference $source ".$this->repo_path);
	}

	/**
	* Runs a `git clean` call
	*
	* Accepts a remove directories flag
	*
	* @access public
	* @param	bool	delete directories?
	* @param	bool	force clean?
	* @return string
	*/
	public function clean($dirs = false, $force = false) {
		return $this->run("clean".(($force) ? " -f" : "").(($dirs) ? " -d" : ""));
	}

	/**
	* Runs a `git branch` call
	*
	* Accepts a name for the branch
	*
	* @access public
	* @param	string branch name
	* @return string
	*/
	public function create_branch($branch) {
		return $this->run("branch $branch");
	}

	/**
	* Runs a `git branch -[d|D]` call
	*
	* Accepts a name for the branch
	*
	* @access public
	* @param	string branch name
	* @return string
	*/
	public function delete_branch($branch, $force = false) {
		return $this->run("branch ".(($force) ? '-D' : '-d')." $branch");
	}

	/**
	* Runs a `git branch` call
	*
	* @access public
	* @param	bool	keep asterisk mark on active branch
	* @return array
	*/
	public function list_branches($keep_asterisk = false) {
		$branchArray = explode("\n", $this->run("branch"));
		foreach($branchArray as $i => &$branch) {
			$branch = trim($branch);
			if (! $keep_asterisk) {
				$branch = str_replace("* ", "", $branch);
			}
			if ($branch == "") {
				unset($branchArray[$i]);
			}
		}
		return $branchArray;
	}

	/**
	* Lists remote branches (using `git branch -r`).
	*
	* Also strips out the HEAD reference (e.g. "origin/HEAD -> origin/master").
	*
	* @access public
	* @return array
	*/
	public function list_remote_branches() {
		$branchArray = explode("\n", $this->run("branch -r"));
		foreach($branchArray as $i => &$branch) {
			$branch = trim($branch);
			if ($branch == "" || strpos($branch, 'HEAD -> ') !== false) {
				unset($branchArray[$i]);
			}
		}
		return $branchArray;
	}

	/**
	* Returns name of active branch
	*
	* @access public
	* @param	bool	keep asterisk mark on branch name
	* @return string
	*/
	public function active_branch($keep_asterisk = false) {
		$branchArray = $this->list_branches(true);
		$active_branch = preg_grep("/^\*/", $branchArray);
		reset($active_branch);
		if ($keep_asterisk) {
			return current($active_branch);
		} else {
			return str_replace("* ", "", current($active_branch));
		}
	}

	/**
	* Runs a `git checkout` call
	*
	* Accepts a name for the branch
	*
	* @access public
	* @param	string branch name
	* @return string
	*/
	public function checkout($branch) {
		return $this->run("checkout $branch");
	}

	/**
	* Runs a `git merge` call
	*
	* Accepts a name for the branch to be merged
	*
	* @access public
	* @param	string $branch
	* @return string
	*/
	public function merge($branch) {
		return $this->run("merge $branch --no-ff");
	}

	/**
	* Runs a git fetch on the current branch
	*
	* @access public
	* @return string
	*/
	public function fetch() {
		return $this->run("fetch");
	}

	/**
	* Add a new tag on the current position
	*
	* Accepts the name for the tag and the message
	*
	* @param string $tag
	* @param string $message
	* @return string
	*/
	public function add_tag($tag, $message = null) {
		if ($message === null) {
			$message = $tag;
		}
		return $this->run("tag -a $tag -m " . escapeshellarg($message));
	}

	/**
	* List all the available repository tags.
	*
	* Optionally, accept a shell wildcard pattern and return only tags matching it.
	*
	* @access	public
	* @param	string	$pattern	Shell wildcard pattern to match tags against.
	* @return	array				Available repository tags.
	*/
	public function list_tags($pattern = null) {
		$tagArray = explode("\n", $this->run("tag -l $pattern"));
		foreach ($tagArray as $i => &$tag) {
			$tag = trim($tag);
			if ($tag == '') {
				unset($tagArray[$i]);
			}
		}

		return $tagArray;
	}

	/**
	* Push specific branch to a remote
	*
	* Accepts the name of the remote and local branch
	*
	* @param string $remote
	* @param string $branch
	* @return string
	*/
	public function push($remote, $branch) {
		return $this->run("push --tags $remote $branch");
	}

	/**
	* Pull specific branch from remote
	*
	* Accepts the name of the remote and local branch
	*
	* @param string $remote
	* @param string $branch
	* @return string
	*/
	public function pull($remote, $branch) {
		return $this->run("pull $remote $branch");
	}

	/**
	* List log entries.
	*
	* @param strgin $format
	* @return string
	*/
	public function log($format = null) {
		if ($format === null)
			return $this->run('log');
		else
			return $this->run('log --pretty=format:"' . $format . '"');
	}

	/**
	* Sets the project description.
	*
	* @param string $new
	*/
	public function set_description($new) {
		$path = $this->git_directory_path();
		file_put_contents($path."/description", $new);
	}

	/**
	* Gets the project description.
	*
	* @return string
	*/
	public function get_description() {
		$path = $this->git_directory_path();
		return file_get_contents($path."/description");
	}

	/**
	* Sets custom environment options for calling Git
	*
	* @param string key
	* @param string value
	*/
	public function setenv($key, $value) {
		$this->envopts[$key] = $value;
	}
}

/* END OF GIT CLASS */

function basename_safe($path){
	if( mb_strrpos($path, '/')!==false ){
		return mb_substr($path, mb_strrpos($path, '/')+1);
	}else{
		return $path;
	}
}

function file_ext($file){
	$tmp = explode('.', $file);
	return strtolower(end($tmp));
}

function so($a, $b) //sort files
{
	if( $a['leaf']==$b['leaf'] ){
		return (strcasecmp($a['text'],$b['text']));
	}else{
		return $a['leaf'];
	}
}

function get_nodes($path, $paths)
{
	global $server;

	if( !$paths ){
		$paths = array();
	}

	$list = $server->parse_raw_list($path);

	if( $list === false ){
		return false;
	}

	$files = array();

	$i=0;
	foreach( $list as $v ){
		$name = basename_safe($v['name']);

		if( $v['type'] != 'file' ){
			if( $v['name']=='.' or $v['name']=='..' or $v['name']=='.svn' or $v['name']=='' ){
				continue;
			}

			$files[$i] = array(
				'id' => (string)$path.$name,
				'text' => (string)$name,
				'type' => 'folder',
				'children' => true,
				'data' => array(
					'perms' => $v['permsn'],
					'modified' => $v['modified'],
					'size' => -1
				)
			);

			// which paths to preload
			$subdir = $path.$v['name'].'/';

			$expand = false;

			foreach( $paths as $p ){
				if( substr($p, 0, strlen($path.$v['name'])+1) == $path.$v['name'].'/' ){
					$expand=true;
					break;
				}
			}

			if ($expand) {
				$files[$i]['state']['opened'] = true;
				$files[$i]['children'] = get_nodes($path.$v['name'].'/', $paths);
			}
		}else{
			$ext = file_ext(basename_safe($v['name']));

			if($ext == 'lck') {
				continue;
			}

			$files[$i] = array(
				'id' => $path.$name,
				'text' => $name,
				'type' => 'file',
				'children' => false,
				'data' => array(
					'perms' => $v['permsn'],
					'modified' => $v['modified'],
					'size' => (int)$v['size']
				)
			);
		}

		$i++;
	}

	usort($files, 'so');
	return $files;
}

function get_paths($path){
	global $server, $size, $max_size;
	$list = $server->parse_raw_list($path);

	if( $list===false ){
		return false;
	}

	$items = array();

	foreach( $list as $v ){
		if( $v['type']!='file' ){
			if( $v['name']=='.' or $v['name']=='..' ){
				continue;
			}

			$size += $v['size'];

			if( $size > $max_size ){
				return false;
			}

			$arr = get_paths($path.$v['name'].'/');
			$items = array_merge($items, $arr);
		}else{
			$items[] = $path.$v['name'];
		}
	}

	return $items;
}

function list_nodes($path)
{
	global $server, $server_src, $id;

	$list = $server_src->parse_raw_list($path);

	if( !$list ){
		return array();
	}

	$items=array();

	foreach( $list as $v ){
		if( $v['name']=='' ){
			continue;
		}

		if( $v['type']!='file' ){
			if( $v['name']=='.' or $v['name']=='..' or $v['name']=='.svn' ){
				continue;
			}

			$items[]=array(
				'path'=>$path.$v['name'],
				'isDir'=>true
			);

			$arr = list_nodes($path.$v['name'].'/',$dest.'/'.$v['name']);
			$items = array_merge($items, $arr);
		}else{
			$items[]=array(
				'path'=>$path.$v['name'],
				'isDir'=>false
			);
		}
	}

	return $items;
}

function copy_nodes($path,$dest)
{
	global $server,$server_src,$id;

	$list = $server_src->parse_raw_list($path);

	if( $list===false ){
		return false;
	}

	$server->mkdir($dest);

	$i=0;
	foreach( $list as $v ){
		if( $v['type']!='file' ){
			if( $v['name']=='.' or $v['name']=='..' or $v['name']=='.svn' ){
				continue;
			}

			copy_nodes($path.$v['name'].'/',$dest.'/'.$v['name']);
		}else{
			$content=$server_src->get($path.'/'.$v['name']);
			$server->put($dest.'/'.$v['name'],$content);
		}

		$i++;
	}
}

if( $_POST['path'] == 'root' ){
	$_POST['path'] = '';
}

$site = $_GET['site'];

if( $_POST['server_type'] ){
	$options = array(
		'site'=>$_POST
	);
}

switch($server_type){
	case 'ftp':
		$server = new ftp();
		$result = $server->connect($host, $username, $password, $port, $dir, array('pasv'=>$pasv));
		if($result===false){
			$response['error'] = end($server->ftp_log);
			echo json_encode($response);
			exit;
		}
	break;
	case 'sftp':
		$server = new sftp();
		$result = $server->connect($host, $username, $password, $port, $dir);
		if($result===false){
			$response['error'] = end($server->ftp_log);
			echo json_encode($response);
			exit;
		}
	break;
	default:
		$server = new local();
	break;
}

if( $_GET['cmd'] ){
	$_POST['cmd'] = $_GET['cmd'];
}

$response = array();
switch( $_POST['cmd'] ){
	case 'test':
		$files = $server->parse_raw_list('/');
		if($files===false){
			$response['error'] = 'Dir listing failed';
		}
	break;

	case 'save':
		if( $server->put($_POST['file'], $_POST['content'])!==false ){
			$response['last_modified'] = $server->last_modified($_POST['file']);
		}else{
			$response['error'] = 'Failed saving '.$_POST['file'];
		}

		if( file_ext($_POST['file'])=='less' and file_exists('shiftedit-lessc.inc.php') ){
			require_once('shiftedit-lessc.inc.php');

			$less = new lessc;

			try {
				$_POST['content'] = $less->compile($_POST['content']);
				$file = substr($_POST['file'], 0, -5).'.css';
				$server->put($file, $_POST['content'], $_POST['compileId'], $_POST['parent']);
			} catch (exception $e) {
				$response['error'] = 'Error compiling '.$e->getMessage();
			}
		}elseif( file_ext($_POST['file'])=='scss' and file_exists('shiftedit-scss.inc.php') ){
			require_once('shiftedit-scss.inc.php');

			$scss = new scssc();
			$scss->setImportPaths(dirname($_POST['file']));

			try {
				$_POST['content'] = $scss->compile($_POST['content']);
				$file = substr($_POST['file'], 0, -5).'.css';
				$server->put($file, $_POST['content'], $_POST['compileId'], $_POST['parent']);
				$response['file_id'] = $_POST['file'];
			} catch (exception $e) {
				$response['error'] = 'Error compiling '.$e->getMessage();
			}
		}
	break;

	case 'open':
		$response['content'] = $server->get($_POST['file']);
	break;
	
	case 'search':
		ob_end_clean();
		header('Content-Type: text/event-stream');
		header('Cache-Control: no-cache');
		//print_r(ob_get_status());
		$server->search($_GET['s'], $_GET['path']);
		$server->close();
		exit;
	break;

	case 'get':
	case 'list':
		session_write_close();
		
		if( $_POST['path'] and substr($_POST['path'],-1)!=='/' ){
			$_POST['path'].='/';
		}

		$response['files'] = array();

		if( $_POST['path'] == '/' ){
			$_POST['path'] = '';
		}

		if( $_POST['path']=='' and $_GET['path'] ){ //used by save as
			$response['files'] = get_nodes($_GET['path'], array(dirname($_GET['path']).'/'));
		}else{ //preload paths
			$response['files'] = get_nodes($_POST['path'], $_SESSION['paths']);
		}

		if( $response['files'] === false ){
			$response['error'] = 'Error getting files: '.end($server->ftp_log);
		}
	break;

	case 'list_all':
		if( $_POST['path'] and substr($_POST['path'],-1)!=='/' ){
			$_POST['path'].='/';
		}

		$server_src = $server;

		$response['files'] = list_nodes($_POST['path']);
	break;

	case 'file_exists':
		$response['file_exists'] = $server->file_exists($_GET['file']);
	break;

	case 'rename':
		$old_name = $_POST['oldname'];
		$new_name = $_POST['newname'];

		if( !$server->rename($old_name, $new_name) ){
			$response['error'] = 'Cannot rename file';
		}
	break;

	case 'newdir':
		$dir = $_POST['dir'];

		if( !$server->mkdir($dir) ){
			$response['error'] = 'Cannot create dir';
		}
	break;

	case 'newfile':
		$content='';

		if( !$server->put($_POST['file'], $content) ){
			$response['error'] = 'Cannot create file';
		}
	break;

	case 'duplicate':
	case 'paste':
		if( !$_POST['dest'] or !$_POST['path'] ){
			$response['error'] = 'Cannot create file';
		}else{
			$server_src = $server;

			if( $_POST['isDir'] ){
				if( $_POST['dest'] and $server->file_exists($_POST['dest']) ){
				}elseif( $_POST['dest'] and $server->mkdir($_POST['dest']) ){
				}else{
					$response['error'] = 'Cannot create folder: '.$_POST['dest'];
				}
			}else{
				$content = $server_src->get($_POST['path']);

				if( $content === false ){
					$response['error'] = 'Cannot read file: '.$_POST['path'];
				}elseif( $_POST['dest'] and $server->put($_POST['dest'], $content) ){
				}else{
					$response['error'] = 'Cannot create file: '.$_POST['dest'];
				}
			}

			if( $_POST['path'] and $_POST['cut']=='true' ){
				$server_src->delete($_POST['path']);
			}
		}
	break;

	case 'delete':
		$files = array();
		
		// backcompat
		if ($_GET['file']) {
			header('Content-Type: text/event-stream');
			header('Cache-Control: no-cache');
			$server->startedAt = time();
			$files[] = $_GET['file'];
		}else if ($_POST['files']) {
			if (count($_POST['files'])===1) {
				$files = $_POST['files'];
			} else {
				$_SESSION['del_queue'] = $_POST['files'];
				$response['queue'] = 1;
			}
		} else if ($_GET['queue']) {
			$files = $_SESSION['del_queue'];
			unset($_SESSION['del_queue']);

			if(!$files) {
				$response['error'] = 'No files to delete';
			} else {
				header('Content-Type: text/event-stream');
				header('Cache-Control: no-cache');
				$server->startedAt = time();
			}
		}

		// delete files
		foreach($files as $file) {
			if( !$server->is_dir($file) ){
				if( !$server->delete($file) ){
					$response['error'] = 'Cannot delete file: '.end($server->ftp_log);
				}
			}else{
				if( !$server->delete($file) ){
					$response['error'] = 'Cannot delete directory: '.end($server->ftp_log);
				}
			}
		}
	break;

	case 'upload':
		$response = array();

		if( $_POST['chunked'] ){
			$path = $_POST['path'].'/'.$_POST['resumableFilename'];
			$content = file_get_contents($_FILES['file']['tmp_name']);
			$resume_pos = ($_POST['resumableChunkNumber']-1) * $_POST['resumableChunkSize'];

			if( !$server->put($path, $content, $resume_pos) ){
				$response['error'] = 'Cannot save file '.$path;
			}
		}elseif( isset($_POST['file']) and isset($_POST['content']) ){
			$content = $_POST['content'];

			if( substr($content,0,5)=='data:' ){
				$pos = strpos($content, 'base64');

				if( $pos ){
					$content = base64_decode(substr($content, $pos+6));
				}
			}

			if( strstr($_POST['file'],'.') ){
				if( !$server->put($_POST['file'], $content) ){
					$response['error'] = 'Can\'t create file '.$file['name'];
				}
			}else{
				if( !$server->mkdir($_POST['file']) ){
					$response['error'] = 'Can\'t create folder '.$file['name'];
				}
			}
		}else{
			foreach( $_FILES as $key=>$file ){
				if( $file['error'] == UPLOAD_ERR_OK ){
					$content = file_get_contents($file['tmp_name']);

					if( !$server->put($_POST['path'].'/'.$file['name'], $content) ){
						$response['error'] = 'Can\'t create file '.$file['name'];
					}
				}else{
					$response['error'] = $error.' '.$file['name'];
				}
			}
		}
	break;

	case 'download':
		$response['content'] = base64_encode($server->get($_GET['file']));
	break;

	case 'chmod':
		$file = $_GET['file'];

		if( !$server->chmod($_GET['mode'], $file) ){
			$response['error'] = 'Cannot chmod file';
		}
	break;

	case 'uploadByURL':
		if( substr($_POST['url'],0,7)!='http://' && substr($_POST['url'],0,8)!='https://' ){
			$response['error'] = 'Invalid URL';
		}else{
			$content = file_get_contents($_POST['url']);

			$file = basename_safe($_POST['url']);

			if( !$file ){

				$response['error'] = 'Missing file name';
			}else{
				if( !$server->put($_POST['path'].'/'.$file, $content) ){
					$response['error'] = 'Can\'t save file';
				}
			}
		}
	break;

	case 'saveByURL':
		if( substr($_POST['url'],0,7)!='http://' && substr($_POST['url'],0,8)!='https://' ){
			$response['error'] = 'Invalid URL';
		}else{
			$content = file_get_contents($_POST['url']);

			if( $server->put($_POST['path'], $content) ){
				//success
			}else{
				$response['error'] = 'Can\'t save file';
			}
		}
	break;

	case 'extract':
		header('Content-Type: text/event-stream');
		header('Cache-Control: no-cache');

		$startedAt = time();
		$file = $server->get($_GET['file'], true);

		$pos = strpos($_GET['file'], '.');
		$file_ext = substr($_GET['file'], $pos);
		$tmpfname = tmpfile();

		$handle = fopen($tmpfname, "w");
		fwrite($handle, $data);
		fclose($handle);

		$za = new ZipArchive();
		$za->open($file);
		$server->send_msg($startedAt, $za->numFiles);

		$complete=0;
		for ($i=0; $i<$za->numFiles; $i++) {
			$entry = $za->statIndex($i);

			$server->send_msg($startedAt, $entry['name']);

			if( substr($entry['name'],-1)=='/' ){
				$server->mkdir(dirname($_GET['file']).'/'.$entry['name']);
			}else{
				$server->put(dirname($_GET['file']).'/'.$entry['name'], $za->getFromIndex($i));
			}

			$complete++;
		}

		unlink($tmpfname);
	break;

	case 'compress':
		if ($_POST['paths']) {
			$_SESSION['paths'] = $_POST['paths'];
		} else {
			if( $_GET['d'] ){
				if( $_SESSION['download']['name'] ){
					header("Content-Disposition: attachment; filename=" . $_SESSION['download']['name']);
					header("Content-Type: application/octet-stream");
					print file_get_contents($_SESSION['download']['file']);
					unlink($_SESSION['download']['file']);
					unset($_SESSION['download']);
					unset($_SESSION['paths']);
					exit;
				}else{
					trigger_error('no zip file');
				}
			}

			header('Content-Type: text/event-stream');

			$size = 0;
			$max_size = 10000000;

			$id = time();

			$server->send_msg($id, 'Initializing');

			$tmpdir = sys_get_temp_dir() or trigger_error('failed to get tmp dir');
			$zip_file = tempnam($tmpdir, "shiftedit_zip_") or trigger_error('failed to create tmp file');
			
			$zip = new ZipArchive();
			if ($zip->open($zip_file, ZipArchive::CREATE)!==TRUE) {
				trigger_error("cannot open <$zip_file>\n");
			}

			$paths = $_SESSION['paths'];
			foreach($paths as $file) {
				$is_dir = $server->is_dir($file);

				if( !$is_dir ){
					$files = array($file);

					if( $server->size($file) > $max_size ){
						$server->send_msg($id, 'File size limit exceeded '.$file);
					}
				}else{
					$files = get_paths($file.'/');

					if( $files===false ){
						$server->send_msg($id, 'Error getting files');
						exit;
					}

					$zip->addEmptyDir($file);
				}

				foreach( $files as $file ){
					$server->send_msg($id, 'Compressing '.$file);

					$dir = dirname($file);
					$zip->addEmptyDir($dir);

					$content = $server->get($file);

					if( $content!==false ){
						$zip->addFromString($file, $content);
					}
				}
			}

			$zip->close();

			$zip_name = (count($paths)===1) ? basename($paths[0]) : 'files';

			$_SESSION['download'] = array(
				'name' => $zip_name.'.zip',
				'file' => $zip_file
			);

			$server->send_msg($id, 'done');
		}
	break;

	case 'definitions':
		$json = file_get_contents($definitions);
		$response['definitions'] = json_decode($json);
	break;

	case 'save_path':
		if( $_GET['path'] and substr($_GET['path'], -1)!=='/' ){
			$_GET['path'].='/';
		}

		if($_GET['expand']) {
			$_SESSION['paths'][] = $_GET['path'];
			$_SESSION['paths'] = array_unique($_SESSION['paths']);
		} else {
			foreach($_SESSION['paths'] as $k=>$v ){
				if (substr($v, 0, strlen($_GET['path'])) == $_GET['path']) {
					unset($_SESSION['paths'][$k]);
				}
			}
		}
	break;
	
	case 'git_info':
		try {
			$git = Git::open(dirname(__FILE__)); // -or- Git::create('/path/to/repo')
		} catch (exception $e) {
			$response['error'] = $e->getMessage();
			break;
		}
		
		// user info
		try {
			$raw = $git->run('config user.name');
			$response['config']['name'] = trim($raw);
			
			$raw = $git->run('config user.email');
			$response['config']['email'] = trim($raw);
		} catch (exception $e) {
		}
		
		// commit history
		try {
			$raw = $git->run('log --pretty=format:"%H|%an|%ar|%s" --max-count=20');
		} catch (exception $e) {
			$response['error'] = $e->getMessage();
			break;
		}
		
		$lines = explode("\n", trim($raw));
		
		$response['commits'] = array();
		foreach($lines as $line) {
			$arr = explode('|', $line);
			$response['commits'][] = array(
				'hash' => $arr[0],
				'author' => $arr[1],
				'date' => $arr[2],
				'subject' => $arr[3],
			);
		}
		
		// stashes
		try {
			$raw = $git->run("stash list");
		} catch (exception $e) {
			$response['error'] = $e->getMessage();
			break;
		}
		
		$lines = explode("\n", trim($raw));
		
		$response['stashes'] = array();
		foreach($lines as $v) {
			preg_match('/stash@{([0-9]+)}: (.*)/', $v, $matches);
			
			$response['stashes'][] = array(
				'index' => $matches[1],
				'name' => $matches[2],
			);
		}
		
		// branches
		try {
			$raw = $git->run("branch");
		} catch (exception $e) {
			$response['error'] = $e->getMessage();
			break;
		}
		
		$lines = explode("\n", trim($raw));
		
		$response['branches'] = array();
		foreach($lines as $v) {
			$selected = (substr($v, 0, 1) === '*');
			$branch = substr($v, 0, 1) === '*' ? substr($v, 2) : $v;
			
			$response['branches'][] = array(
				'name' => $branch,
				'selected' => $selected,
			);
		}
		
		// status
		try {
			$raw = $git->run('status -bs');
		} catch (exception $e) {
			$response['error'] = $e->getMessage();
			break;
		}
		
		$raw = rtrim($raw);
		if ($raw) {
			$lines = explode("\n", $raw);
			$response['status'] = array_shift($lines);
			
			$response['changes'] = array();
			foreach($lines as $line) {
				$path = substr($line, 3);
				
				$response['changes'][] = array(
					'path' => $path,
					'status' => substr($line, 1, 1),
				);
			}
		}
	break;
	
	case 'config':
		try {
			$git = Git::open(dirname(__FILE__)); // -or- Git::create('/path/to/repo')
		} catch (exception $e) {
			$response['error'] = $e->getMessage();
			break;
		}
		
		if (trim($_GET['name']) and trim($_GET['email'])) {
			try {
				$response['data'][] = $git->run('config user.name "'.trim($_GET['name']).'"');
				$response['data'][] = $git->run('config user.email "'.trim($_GET['email']).'"');
			} catch (exception $e) {
				$response['error'] = $e->getMessage();
			}
		}
	break;
	
	case 'clone':
		try {
			$git = Git::open(dirname(__FILE__)); // -or- Git::create('/path/to/repo')
		} catch (exception $e) {
			$response['error'] = $e->getMessage();
			break;
		}
		
		if (trim($_GET['url'])) {
			try {
				$response['data'] = $git->run("clone ".$_GET['url'].' .');
				
				if (trim($_GET['name']) and trim($_GET['email'])) {
					$git->run('config user.name "'.trim($_GET['name']).'"');
					$git->run('config user.email "'.trim($_GET['email']).'"');
				}
			} catch (exception $e) {
				$response['error'] = $e->getMessage();
			}
		}
	break;
	
	case 'checkout':
		try {
			$git = Git::open(dirname(__FILE__)); // -or- Git::create('/path/to/repo')
		} catch (exception $e) {
			$response['error'] = $e->getMessage();
			break;
		}
		
		if (trim($_GET['branch'])) {
			try {
				$response['data'] = $git->run("checkout -q ".$_GET['branch']);
			} catch (exception $e) {
				$response['error'] = $e->getMessage();
			}
		}
	break;
	
	case 'discard':
		try {
			$git = Git::open(dirname(__FILE__)); // -or- Git::create('/path/to/repo')
		} catch (exception $e) {
			$response['error'] = $e->getMessage();
			break;
		}
		
		if (trim($_GET['path'])) {
			try {
				$raw = $git->run('status -s');
			} catch (exception $e) {
				$response['error'] = $e->getMessage();
				break;
			}
			
			$lines = explode("\n", rtrim($raw));
			
			foreach($lines as $line) {
				$path = substr($line, 3);
				$status = substr($line, 0, 1);
			
				if ($path === $_GET['path']) {
					try {
						if ($status === '?' or $status === 'A') {
							if ($status === 'A') {
								$response['data'] = $git->run("reset ".$_GET['path']);
							}
							
							$response['data'] = $git->run("clean -f ".$_GET['path']);
						} else {
							$response['data'] = $git->run("checkout -- ".$_GET['path']);
						}
					} catch (exception $e) {
						$response['error'] = $e->getMessage();
						break 2;
					}
					break;
				}
			}
		}
	break;
	
	case 'create_branch':
		try {
			$git = Git::open(dirname(__FILE__)); // -or- Git::create('/path/to/repo')
		} catch (exception $e) {
			$response['error'] = $e->getMessage();
			break;
		}
		
		if (trim($_GET['name']) and trim($_GET['from'])) {
			try {
				$raw = $git->run('checkout -b '.$_GET['name'] . ' ' . $_GET['from']);
				$raw = $git->run('branch --set-upstream-to=origin/' . $_GET['name']);
			} catch (exception $e) {
				$response['error'] = $e->getMessage();
				break;
			}
		}
	break;
	
	case 'delete_branch':
		try {
			$git = Git::open(dirname(__FILE__)); // -or- Git::create('/path/to/repo')
		} catch (exception $e) {
			$response['error'] = $e->getMessage();
			break;
		}
		
		if (trim($_GET['branch'])) {
			try {
				$git->run("checkout -q master");
				if ($_GET['force']) {
					$raw = $git->run('branch -D '.$_GET['branch']);
				} else {
					$raw = $git->run('branch -d '.$_GET['branch']);
				}
			} catch (exception $e) {
				$response['error'] = $e->getMessage();
				break;
			}
		}
	break;
	
	case 'revert':
		try {
			$git = Git::open(dirname(__FILE__));  // -or- Git::create('/path/to/repo')
		} catch (exception $e) {
			$response['error'] = $e->getMessage();
			break;
		}
		
		if (trim($_GET['hash'])) {
			try {
				$raw = $git->run('revert '.$_GET['hash']);
			} catch (exception $e) {
				$response['error'] = $e->getMessage();
				break;
			}
		}
	break;
	
	case 'commit':
		try {
			$git = Git::open(dirname(__FILE__)); // -or- Git::create('/path/to/repo')
		} catch (exception $e) {
			$response['error'] = $e->getMessage();
			break;
		}
		
		if (count($_POST['paths']) and $_POST['subject']) {
			try {
				foreach($_POST['paths'] as $path) {
					$git->run("add ".$path);
				}
				$response['result'] = $git->run('commit -m "'.addslashes($_POST['subject']).'" -m "'.addslashes($_POST['description']).'"');
			} catch (exception $e) {
				$response['error'] = $e->getMessage();
				break;
			}
		}
	break;
	
	case 'diff':
		try {
			$git = Git::open(dirname(__FILE__)); // -or- Git::create('/path/to/repo')
		} catch (exception $e) {
			$response['error'] = $e->getMessage();
			break;
		}
		
		if ($_GET['path']) {
			try {
				$response['result'] = $git->run('add -N '.$_GET['path']);
				$response['result'] = $git->run('--no-pager diff '.$_GET['path']);
			} catch (exception $e) {
				$response['error'] = $e->getMessage();
				break;
			}
		}
	break;
	
	case 'show':
		try {
			$git = Git::open(dirname(__FILE__)); // -or- Git::create('/path/to/repo')
		} catch (exception $e) {
			$response['error'] = $e->getMessage();
			break;
		}
		
		if ($_GET['commit']) {
			try {
				$response['result'] = $git->run('--no-pager show '.$_GET['commit']);
			} catch (exception $e) {
				$response['error'] = $e->getMessage();
				break;
			}
		}
	break;
	
	case 'stash_push':
		try {
			$git = Git::open(dirname(__FILE__)); // -or- Git::create('/path/to/repo')
		} catch (exception $e) {
			$response['error'] = $e->getMessage();
			break;
		}
		
		try {
			$response['result'] = $git->run('stash push ' . ($_POST['subject'] ? '-m "'.addslashes($_POST['subject']).'"' : ''));
		} catch (exception $e) {
			$response['error'] = $e->getMessage();
			break;
		}
	break;
	
	case 'stash_show':
		try {
			$git = Git::open(dirname(__FILE__)); // -or- Git::create('/path/to/repo')
		} catch (exception $e) {
			$response['error'] = $e->getMessage();
			break;
		}
		
		try {
			$response['result'] = $git->run('--no-pager stash show -p stash@{' . (int)$_GET['index']  . '}');
		} catch (exception $e) {
			$response['error'] = $e->getMessage();
			break;
		}
	break;
	
	case 'stash_apply':
		try {
			$git = Git::open(dirname(__FILE__)); // -or- Git::create('/path/to/repo')
		} catch (exception $e) {
			$response['error'] = $e->getMessage();
			break;
		}
		
		try {
			$response['result'] = $git->run('stash apply stash@{' . (int)$_GET['index']  . '}');
		} catch (exception $e) {
			$response['error'] = $e->getMessage();
			break;
		}
	break;
	
	case 'stash_drop':
		try {
			$git = Git::open(dirname(__FILE__)); // -or- Git::create('/path/to/repo')
		} catch (exception $e) {
			$response['error'] = $e->getMessage();
			break;
		}
		
		try {
			$response['result'] = $git->run('stash drop stash@{' . (int)$_GET['index']  . '}');
		} catch (exception $e) {
			$response['error'] = $e->getMessage();
			break;
		}
	break;
	
	case 'sync':
		try {
			$git = Git::open(dirname(__FILE__)); // -or- Git::create('/path/to/repo')
		} catch (exception $e) {
			$response['error'] = $e->getMessage();
			break;
		}
		
		try {
			$response['result'] = $git->run('pull');
		} catch (exception $e) {
			$response['error'] = $e->getMessage();
			break;
		}
		
		try {
			$response['result'] = $git->run('push');
		} catch (exception $e) {
			$response['error'] = $e->getMessage();
			break;
		}
	break;

	default:
		$response['error'] = 'No command';
	break;
}

$response['success'] = ($response['error']) ? false : true;
print json_encode($response);

$server->close();
