<?php
/*
Used by ShiftEdit.net to connect to server and perform file ops over http
Author: Adam Jimenez <adam@shiftcreate.com>
URL: https://github.com/adamjimenez/shiftedit-ajax

Edit the username and password below
*/

//config
$username = 'username'; //username or ftp username
$password = 'password'; //password or ftp password
$dir = '/httpdocs/'; //path to files e.g. dirname(__FILE__).'/';
$server_type = 'ftp'; //local or ftp. local requires webserver to have write permissions to files.
$pasv = true; //pasv mode for ftp
$port = 21;
$definitions = ''; //autocomplete definitions e.g. http://example.org/defs.json

//restrict access by ip
$ip_restrictions = false;

//allowed ips. get your ip from https://www.google.co.uk/search?q=ip+address
$ips = array('');

//api version
$version = '1.04';

//cors origin
$origin = '{$origin}';

//set error level
error_reporting(E_ALL ^ E_NOTICE);

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
    die('{"success":false,"error":"access denied"}');
}

//authentication
if( $username and !$_SESSION['shiftedit_logged_in'] ){
    if( $username!==$_POST['user'] or sha1($password)!==$_POST['pass'] ){
        //delay to protect against brute force attack
        sleep(1);
        die('{"success":false,"error":"Login incorrect"}');
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

	function server(){
		//max upload size
		$this->max_size = 20000000;
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
	
	function close(){		
	}
}

class local extends server{
	function local()
	{
		global $dir;
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

	function put($file, $content)
	{
		if( !$file ){
			return false;
		}

		$path = $this->dir.$file;
		return file_put_contents($path, $content)!==false;
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

	function chmod($file, $mode)
	{
		$file = $this->dir.$file;
		return chmod($file, $mode);
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
			foreach ($list as $item){
				if( $item['name'] != '..' && $item['name'] != '.' ){
					$this->delete($file.'/'.$item['name']);
				}
			}

			chdir('../');

			if( !rmdir($path) ){
				$this->log[]= 'rmdir '.$this->pwd;
				return false;
			}else{
				return true;
			}
		}else{
			if( $this->file_exists($file) ){
				$this->log[]='delete '.$path;
				return unlink($path);
			}
		}
	}

	function parse_raw_list( $path )
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
				'size' => filesize($entry),
				'modified' => filemtime($entry),
				'type' => is_dir($entry) ? 'folder' : 'file',
			);
		}
		$d->close();

		return $items;
	}

	function search_nodes($s, $path, $file_extensions)
	{
		$list = $this->parse_raw_list($path);

		if( !$list ){
			return array();
		}

		$items = array();

		foreach( $list as $v ){
			if( $v['type']!='file' ){
				if( $v['name']=='.' or $v['name']=='..' or $v['name']=='.svn' ){
					continue;
				}

				$arr = $this->search_nodes($s, $path.$v['name'].'/', $file_extensions);
				$items = array_merge($items,$arr);
			}else{
				if( strstr($v['name'], $s) and in_array(file_ext($v['name']), $file_extensions) ){
					$items[] = $path.$v['name'];
					$this->send_msg($this->startedAt , $path.$v['name']);
				}
			}
		}

		return $items;
	}

	function search($s, $path, $file_extensions)
	{
		$this->startedAt = time();
		return $this->search_nodes($s, $path, $file_extensions);
	}
}

class ftp extends server{
	function connect($host, $user, $pass, $port=21, $dir, $options)
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

		if( $encryption ){
			$this->conn_id = ftp_ssl_connect($host,$port,$options['timeout']);
		}else{
			$this->conn_id = ftp_connect($host,$port,$options['timeout']);
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

		$this->ftp_log[]=$command;
		$this->ftp_log=array_merge($this->ftp_log, $result);

		return trim(end($result));
	}

	function chdir($path){
		if( $path === $this->pwd ){
			return true;
		}else{
			$this->ftp_log[] = 'chdir '.$path;
			if( ftp_chdir($this->conn_id, $path) ){
				$this->pwd = ftp_pwd($this->conn_id);
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

		$tmpfname = tempnam("/tmp", "shiftedit_ftp_");
		$handle = fopen($tmpfname, "w+");

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
		}else{
			fclose($handle);
			unlink($tmpfname);

			return false;
		}
	}

	function put($file, $content)
	{
		$mode = FTP_BINARY;
		$resume_pos = null;

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

		$result = ftp_fput($this->conn_id, basename_safe($path), $tmp, $mode, $resume_pos);

		//try deleting first
		if( $result === false ){
			$items = $this->parse_raw_list(dirname($file));

			$perms=0;
			foreach( $items as $v ){
				if( $v['name'] == basename($file) ){
					$perms = $v['permsn'];
				}
			}

			//delete before save otherwise save does not work on some servers
			$this->delete($file);

			if( $perms ){
				$this->chmod(intval($perms, 8), $file);
			}

			$result = ftp_fput($this->conn_id, basename_safe($path), $tmp, $mode, $resume_pos);
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
		$dir=$this->dir.$dir;

		// Get the current working directory
		$origin = ftp_pwd($this->conn_id);

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

	function chmod($mode,$file)
	{
		$file=$this->dir.$file;

		return ftp_chmod($this->conn_id,$mode, $file);
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
			foreach ($list as $item){
				if( $item['name'] != '..' && $item['name'] != '.' ){
					$this->delete($file.'/'.$item['name']);
				}
			}

			if( !$this->chdir(dirname($path)) ){
				return false;
			}

			$this->ftp_log[]= 'rmdir '.$path;
			if( !ftp_rmdir($this->conn_id, basename($path)) ){
				return false;
			}else{
				return true;
			}
		}else{
			if( $this->file_exists($file) ){
				$this->ftp_log[]='delete '.$path;
				return ftp_delete($this->conn_id, $path);
			}
		}
	}

	function parse_raw_list( $path )
	{
		$path = $this->dir.$path;

		if( $path and !$this->chdir($path) ){
			return false;
		}

		$array = ftp_rawlist($this->conn_id, '-a');

		if( $array === false ){
			return false;
		}

		$items=array();

		$systype = ftp_systype($this->conn_id);

		foreach( $array as $folder ){
			$struc = array();

			if( preg_match("/([0-9]{2})-([0-9]{2})-([0-9]{2}) +([0-9]{2}):([0-9]{2})(AM|PM) +([0-9]+|<DIR>) +(.+)/",$folder,$split) ){
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
				$current = preg_split("/[\s]+/",$folder,9);

				//print_r($current);

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

	function search_nodes($s, $path, $file_extensions)
	{
		$list = $this->parse_raw_list($path);

		if( !$list ){
			return array();
		}

		$items = array();

		foreach( $list as $v ){
			if( $v['type']!='file' ){
				if( $v['name']=='.' or $v['name']=='..' or $v['name']=='.svn' ){
					continue;
				}

				$arr = $this->search_nodes($s, $path.$v['name'].'/', $file_extensions);

				$items = array_merge($items,$arr);
			}else{
				if( strstr($v['name'], $s) and in_array(file_ext($v['name']), $file_extensions) ){
					$items[] = $path.$v['name'];

					$this->send_msg($this->startedAt , $path.$v['name']);
				}
			}
		}

		return $items;
	}

	function search($s, $path, $file_extensions)
	{
		$this->startedAt = time();
		return $this->search_nodes($s, $path, $file_extensions);
	}

	function close()
	{
		ftp_close($this->conn_id);
	}
}

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
		if( $v['type'] != 'file' ){
			if( $v['name']=='.' or $v['name']=='..' or $v['name']=='.svn' or $v['name']=='' ){
				continue;
			}

			$files[$i] = array(
				'text' => basename_safe($v['name']),
				'iconCls' => 'folder',
				'disabled' => false,
				'leaf' => false,
				'perms' => $v['permsn'],
				'modified' =>  $v['modified'],
				'size' => '',
				'id' => $path.$v['name']
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

			if( $expand	){
				$files[$i]['expanded']=true;
			}
		}else{
			$ext = file_ext(basename_safe($v['name']));

			if( $ext == 'lck' ){
				continue;
			}

			$files[$i] = array(
				'text' => basename_safe($v['name']),
				'iconCls' => 'file-'.$ext,
				'disabled' => false,
				'leaf' => true,
				'perms' => $v['permsn'],
				'modified' =>  $v['modified'],
				'size' => $v['size'],
				'id' => $path.$v['name']
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

	if( !$list ){
		return array();
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

			$arr=list_nodes($path.$v['name'].'/',$dest.'/'.$v['name']);

			$items=array_merge($items,$arr);
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

if($server_type==='ftp'){
	$server = new ftp();
	$result = $server->connect('localhost', $username, $password, $port, $dir, array('pasv'=>$pasv));
	if($result===false){
		print_r($server->ftp_log);
		exit;
	}
}else{
	$server = new local();
}

if( $_GET['cmd'] ){
	$_POST['cmd'] = $_GET['cmd'];
}

switch( $_POST['cmd'] ){
    case 'test':
        $files = $server->parse_raw_list('/');
        $response['success'] = $files!==false;
        if(!$response['success']){
        	$response['error'] = 'Dir listing failed';
        }
		echo json_encode($response);
    break;

	case 'save':
		if( $server->put($_POST['file'], $_POST['content']) ){
		    $response['success'] = true;
		    $response['last_modified'] = $server->last_modified($_POST['file']);
		}else{
		    $response['success'] = false;
		    $response['error'] = 'Failed saving '.$_POST['file'];
		}

		echo json_encode($response);
	break;

	case 'open':
		$response['content'] = $server->get($_POST['file']);
		$response['success'] = true;
		echo json_encode($response);
	break;

	case 'get':
		if( $_POST['path'] and substr($_POST['path'],-1)!=='/' ){
			$_POST['path'].='/';
		}

		$files=array();

		if( $_POST['path'] == '/' ){
			$_POST['path'] = '';
		}

		if( $_POST['path']=='' and $_GET['path'] ){ //used by save as
			$files = get_nodes($_POST['path'], array(dirname($_GET['path']).'/'));
		}else{ //preload paths
			$files = get_nodes($_POST['path'], $_SESSION['paths']);
		}

		//include root
		if( $_POST['path'] == '' and $_GET['root']==='false' ){
			$root[0] = array(
				'text' => $site['dir'],
				'iconCls' => 'folder',
				'disabled' => false,
				'leaf' => false,
				'modified' => '',
				'size' => '',
				'expanded' => true,
				'children'=>$files
			);

			$files = $root;
		}

		echo json_encode($files);
	break;

	case 'list':
		if( $_POST['path'] and substr($_POST['path'],-1)!=='/' ){
			$_POST['path'].='/';
		}

		$server_src = $server;

		$response = array();
		$response['success'] = true;
		$response['files'] = list_nodes($_POST['path']);

		echo json_encode($response);
	break;

	case 'rename':
		$old_name = $_POST['oldname'];
		$new_name = $_POST['newname'];

		if( $server->rename($old_name, $new_name) ){
			echo '{"success":true}';
		}else{
			echo '{"success":false,"error":"Cannot rename file"}';
		}
	break;

	case 'newdir':
		$dir=$_POST['dir'];

		if( $server->mkdir($dir) ){
			echo '{"success":true}';
		}else{
			echo '{"success":false,"error":"Cannot create directory"}';
		}
	break;

	case 'newfile':
		$content='';

		if( $server->put($_POST['file'], $content) ){
			echo '{"success":true}';
		}else{
			echo '{"success":false,"error":"Cannot create file"}';
		}
	break;

	case 'duplicate':
	case 'paste':
		if( !$_POST['dest'] or !$_POST['path'] ){
			echo '{"success":false,"error":"Cannot create file"}';
		}else{

			if( $_POST['site'] and $_POST['site']!=$_GET['site'] and $_POST['isDir']!=='true' ){
				$server_src = open_site($_POST['site']);
			}else{
				$server_src = $server;
			}

			if( $_POST['isDir']=="true" ){
				if( $_POST['dest'] and $server->file_exists($_POST['dest']) ){
					echo '{"success":true}';
				}elseif( $_POST['dest'] and $server->mkdir($_POST['dest']) ){
					echo '{"success":true}';
				}else{
					echo '{"success":false,"error":"Cannot create folder: '.$_POST['dest'].'"}';
				}
			}else{
				$content = $server_src->get($_POST['path']);

				if( $content === false ){
					echo '{"success":false,"error":"Cannot read file: '.$_POST['path'].'"}';
				}elseif(  $_POST['dest'] and $server->put($_POST['dest'], $content) ){
					echo '{"success":true}';
				}else{
					echo '{"success":false,"error":"Cannot create file: '.$_POST['dest'].'"}';
				}
			}

			if( $_POST['path'] and $_POST['cut']=='true' ){
				$server_src->delete($_POST['path']);
			}
		}
	break;

	case 'delete':
		$file = $_POST['file'];

		if( !$server->is_dir($file) ){
			if( $server->delete($file) ){
				echo '{"success":true}';
			}else{
				echo '{"success":false,"error":"Cannot delete file: '.end($server->log).'"}';
			}
		}else{
			if( $server->delete($file) ){
				echo '{"success":true}';
			}else{
				echo '{"success":false,"error":"Cannot delete directory: '.end($server->log).'"}';
			}
		}
	break;

	case 'upload':
		$response=array();

		$response['success']=true;

		if( isset($_POST['file']) and isset($_POST['content']) ){
			$content = $_POST['content'];

			if( substr($content,0,5)=='data:' ){
				$pos = strpos($content, 'base64');

				if( $pos ){
					$content = base64_decode(substr($content, $pos+6));
				}
			}

			if( strstr($_POST['file'],'.') ){
				if( $server->put($_POST['file'], $content) ){
					//success
				}else{
					$response['success']=false;

					$response['error'] = 'Can\'t create file '.$file['name'];
				}
			}else{
				if( $server->mkdir($_POST['file']) ){
					//success
				}else{
					$response['success']=false;

					$response['error'] = 'Can\'t create folder '.$file['name'];
				}
			}
		}else{
			foreach( $_FILES as $key=>$file ){
				if( $file['error'] == UPLOAD_ERR_OK ){
					$content = file_get_contents($file['tmp_name']);

					if( $server->put($_POST['path'].'/'.$file['name'], $content) ){
						//success
					}else{
						$response['success']=false;

						$response['error']='Can\'t create file '.$file['name'];
					}
				}else{
					$response['success']=false;
					$response['error'] = $error.' '.$file['name'];
				}
			}
		}

		echo json_encode($response);
	break;

	case 'download':
		$file = $_POST['path'];

		$data = $server->get($file);

		header("Content-Type: application/octet-stream");
		header("Content-Disposition: attachment; filename=\"".basename($file)."\"");
		//header('Content-type: '.$row['type']);
		print $data;
	break;

	case 'chmod':
		$file = $_POST['file'];

		if( $server->chmod($file, intval($_POST['mode'], 8)) ){
			echo '{"success":true}';
		}else{
			echo '{"success":false,"error":"Cannot chmod file"}';
		}
	break;

	case 'uploadByURL':
		if( substr($_POST['url'],0,7)!='http://' && substr($_POST['url'],0,8)!='https://' ){
			$response['success']=false;
			$response['error']='Invalid URL';
		}else{
			$content = file_get_contents($_POST['url']);

			$file = basename_safe($_POST['url']);

			if( !$file ){
				$response['success']=false;

				$response['error']='Missing file name';
			}else{
				if( $server->put($_POST['path'].'/'.$file, $content) ){
					//success
					$response['success']=true;
				}else{
					$response['success']=false;
					$response['error']='Can\'t save file';
				}
			}
		}

		echo json_encode($response);
	break;

	case 'saveByURL':
		if( substr($_POST['url'],0,7)!='http://' && substr($_POST['url'],0,8)!='https://' ){
			$response['success']=false;

			$response['error']='Invalid URL';
		}else{
			$content=file_get_contents($_POST['url']);

			if( $server->put($_POST['path'], $content) ){
				//success
				$response['success']=true;
			}else{
				$response['success']=false;
				$response['error']='Can\'t save file';
			}
		}

		echo json_encode($response);
	break;

	case 'extract':
		header('Content-Type: text/event-stream');
		header('Cache-Control: no-cache');

		ignore_user_abort();

		$startedAt = time();
		$file = $server->get($_GET['file'], true);
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
	break;

	case 'compress':
		if( $_GET['d'] ){
			if( $_SESSION['download']['name'] ){
				header("Content-Disposition: attachment; filename=" . urlencode($_SESSION['download']['name']));
				header("Content-Type: application/octet-stream");
				print file_get_contents($_SESSION['download']['file']);
				unlink($_SESSION['download']['file']);
				unset($_SESSION['download']);
				exit;
			}else{
				die('no zip file');
			}
		}

		header('Content-Type: text/event-stream');

		$size = 0;
		$max_size = 10000000;

		$id = time();

		$site = $_GET['site'];
		$file = $_GET['file'];

		$server->send_msg($id, 'Initializing');

		$is_dir = $server->is_dir($file);

		if( !$is_dir ){
			$files = array($file);

			if( $server->size($file) > $max_size ){
				$server->send_msg($id, 'File size limit exceeded '.$file);
			}

		}else{
			$files = get_paths($file.'/');

			if( !$files ){
				$server->send_msg($id, 'File size limit exceeded');
				exit;
			}
		}

		$zip_file = tempnam("/tmp", "shiftedit_zip_");

		$zip = new ZipArchive();
		if ($zip->open($zip_file, ZipArchive::CREATE)!==TRUE) {
			die("cannot open <$zip_file>\n");
		}

		foreach( $files as $file ){
			$server->send_msg($id, 'Compressing '.$file);

			$content = $server->get($file);

			if( $content!==false ){
				$name = $is_dir ? substr($file, strlen(dirname($_GET['file']))+1) : $file;
				$zip->addFromString($name, $content);
			}
		}

		$zip->close();
		$data = file_get_contents($zip_file);

		$_SESSION['download'] = array(
			'name' => basename($_GET['file']).'.zip',
			'file' => $zip_file
		);

		$server->send_msg($id, 'done');
	break;

	case 'definitions':
		$response['success'] = true;
		$json = file_get_contents($definitions);
		$response['definitions'] = json_decode($json);
	break;

	case 'save_path':
		if( $_POST['expand']!='false' ){
			$_SESSION['paths'][] = $_POST['path'];
			$_SESSION['paths'] = array_unique($_SESSION['paths']);
		}else{
			foreach($_SESSION['paths'] as $k=>$v ){
				if( substr($v, 0, strlen($_POST['path'])) == $_POST['path'] ){
					unset($_SESSION['paths'][$k]);
				}
			}
		}
	break;

	default:
		echo '{"success":false,"error":"No command"}';
	break;
}

$server->close();
?>
