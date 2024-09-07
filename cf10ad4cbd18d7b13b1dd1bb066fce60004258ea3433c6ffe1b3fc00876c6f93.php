<?php

# ----------- BVUNTAR2 Start -----------

abstract class Archive {

	const COMPRESS_NONE = 0;

	protected $callback;

	abstract public function open($file);

	abstract public function extract($outdir, $permissions = 0775, $strip = '', $exclude = '', $include = '');

	abstract public function close();

}

class FileInfo {

	protected $isdir = false;
	protected $path = '';
	protected $size = 0;
	protected $csize = 0;
	protected $mtime = 0;
	protected $mode = 0664;
	protected $owner = '';
	protected $group = '';
	protected $uid = 0;
	protected $gid = 0;
	protected $comment = '';

	public function __construct($path = '') {
		$this->mtime = time();
		$this->setPath($path);
	}

	public function getSize() {
		if($this->isdir) return 0;
		return $this->size;
	}

	public function setSize($size) {
		$this->size = $size;
	}

	public function getCompressedSize() {
		return $this->csize;
	}

	public function setCompressedSize($csize) {
		$this->csize = $csize;
	}

	public function getMtime() {
		return $this->mtime;
	}

	public function setMtime($mtime) {
		$this->mtime = $mtime;
	}

	public function getGid() {
		return $this->gid;
	}

	public function setGid($gid) {
		$this->gid = $gid;
	}

	public function getUid() {
		return $this->uid;
	}

	public function setUid($uid) {
		$this->uid = $uid;
	}

	public function getGroup() {
		return $this->group;
	}

	public function setGroup($group) {
		$this->group = $group;
	}

	public function getIsdir() {
		return $this->isdir;
	}

	public function setIsdir($isdir) {
		if ($isdir && $this->mode === 0664) {
			$this->mode = 0775;
		}
		$this->isdir = $isdir;
	}

	public function getMode() {
		return $this->mode;
	}

	public function setMode($mode) {
		$this->mode = $mode;
	}

	public function getOwner() {
		return $this->owner;
	}

	public function setOwner($owner) {
		$this->owner = $owner;
	}

	public function getPath() {
		return $this->path;
	}

	public function setPath($path, $absolute_names = false) {
		$this->path = $absolute_names ? $path : $this->cleanPath($path);
	}

	protected function cleanPath($path) {
		$path    = str_replace('\\', '/', $path);
		$path    = explode('/', $path);
		$newpath = array();
		foreach ($path as $p) {
			if ($p === '' || $p === '.') {
				continue;
			}
			if ($p === '..') {
				array_pop($newpath);
				continue;
			}
			array_push($newpath, $p);
		}
		return trim(implode('/', $newpath), '/');
	}

	public function strip($strip, $absolute_names) {
		$filename = $this->getPath();
		$striplen = strlen($strip);
		if (is_int($strip)) {
			$parts = explode('/', $filename);
			if (!$this->getIsdir()) {
				$base = array_pop($parts);
			} else {
				$base = '';
			}
			$filename = join('/', array_slice($parts, $strip));
			if ($base) {
				$filename .= "/$base";
			}
		} else {
			if (substr($filename, 0, $striplen) == $strip) {
				$filename = substr($filename, $striplen);
			}
		}

		$this->setPath($filename, $absolute_names);
	}

	public function matchExpression($include = '', $exclude = '') {
		$extract = true;
		if ($include && !preg_match($include, $this->getPath())) {
			$extract = false;
		}
		if ($exclude && preg_match($exclude, $this->getPath())) {
			$extract = false;
		}

		return $extract;
	}
}

class ArchiveCorruptedException extends \Exception {}

class ArchiveIOException extends \Exception {}

class BVTar extends Archive {

	protected $file = '';
	protected $comptype = Archive::COMPRESS_NONE;
	protected $complevel = 0;
	protected $fh;
	protected $memory = '';
	protected $closed = true;
	protected $writeaccess = false;
	protected $absolute_names = false;

	public function set_absolute_names($enabled = true)
	{
		$this->absolute_names = $enabled;
	}

	public function open($file) {
		$this->file = $file;
		$this->fh = @fopen($this->file, 'rb');

		if (!$this->fh) {
			throw new ArchiveIOException('Could not open file for reading: '.$this->file);
		}
		$this->closed = false;
	}

	public function extract($outdir, $permissions = 0775, $strip = '', $exclude = '', $include = '') {
		if ($this->closed || !$this->file) {
			throw new ArchiveIOException('Can not read from a closed archive');
		}

		$outdir = rtrim($outdir, '/');
		@mkdir($outdir, $permissions, true);
		if (!is_dir($outdir)) {
			throw new ArchiveIOException("Could not create directory '$outdir'");
		}

		$extracted = array();
		$failed = array();
		while ($dat = $this->readBytes(512)) {
			$header = $this->parseHeader($dat);
			if (!is_array($header)) {
				continue;
			}
			$fileinfo = $this->header2fileinfo($header);

			$fileinfo->strip($strip, $this->absolute_names);

			if (!strlen($fileinfo->getPath()) || !$fileinfo->matchExpression($include, $exclude)) {
				$this->skipBytes(ceil($header['size'] / 512) * 512);
				continue;
			}

			$output    = $outdir.'/'.$fileinfo->getPath();
			$directory = ($fileinfo->getIsdir()) ? $output : dirname($output);
			@mkdir($directory, $permissions, true);

			if (!$fileinfo->getIsdir()) {
				$fp = @fopen($output, "wb");
				if (!$fp) {
					$failed[] = $fileinfo;
					continue;
				}

				$size = floor($header['size'] / 512);
				for ($i = 0; $i < $size; $i++) {
					fwrite($fp, $this->readBytes(512), 512);
				}
				if (($header['size'] % 512) != 0) {
					fwrite($fp, $this->readBytes(512), $header['size'] % 512);
				}

				fclose($fp);
				@touch($output, $fileinfo->getMtime());
				@chmod($output, $fileinfo->getMode());
			} else {
				$this->skipBytes(ceil($header['size'] / 512) * 512);
			}

			if(is_callable($this->callback)) {
				call_user_func($this->callback, $fileinfo);
			}
			$extracted[] = $fileinfo;
		}

		$this->close();
		$_to_return = array();
		$_to_return["extracted"] = $extracted;
		$_to_return["failed"] = $failed;
		return $_to_return;
	}

	public function extract2($outdir, $permissions = 0775, $strip = '', $exclude = '', $include = '') {
		if ($this->closed || !$this->file) {
			throw new ArchiveIOException('Can not read from a closed archive');
		}

		$outdir = rtrim($outdir, '/');
		@mkdir($outdir, $permissions, true);
		if (!is_dir($outdir)) {
			throw new ArchiveIOException("Could not create directory '$outdir'");
		}

		$extracted = array();
		$failed = array();
		while ($dat = $this->readBytes(512)) {
			$header = $this->parseHeader($dat);
			if (!is_array($header)) {
				continue;
			}
			$fileinfo = $this->header2fileinfo($header);

			$fileinfo->strip($strip, $this->absolute_names);

			if (!strlen($fileinfo->getPath()) || !$fileinfo->matchExpression($include, $exclude)) {
				$this->skipBytes(ceil($header['size'] / 512) * 512);
				continue;
			}

			$output    = $outdir.'/'.$fileinfo->getPath();
			$directory = ($fileinfo->getIsdir()) ? $output : dirname($output);
			@mkdir($directory, $permissions, true);

			if (!$fileinfo->getIsdir()) {
				$fp = @fopen($output, "wb");
				if (!$fp) {
					$this->skipBytes(ceil($header['size'] / 512) * 512);
					$failed[$header['filename']] = true;
					continue;
				}

				$size = floor($header['size'] / 512);
				for ($i = 0; $i < $size; $i++) {
					fwrite($fp, $this->readBytes(512), 512);
				}
				if (($header['size'] % 512) != 0) {
					fwrite($fp, $this->readBytes(512), $header['size'] % 512);
				}

				fclose($fp);
				@touch($output, $fileinfo->getMtime());
				@chmod($output, $fileinfo->getMode());
			} else {
				$this->skipBytes(ceil($header['size'] / 512) * 512);
			}

			if(is_callable($this->callback)) {
				call_user_func($this->callback, $fileinfo);
			}
			$extracted[] = $fileinfo;
		}

		$this->close();
		$_to_return = array();
		$_to_return["extracted"] = $extracted;
		$_to_return["failed"] = $failed;
		return $_to_return;
	}

	public function close() {
		if ($this->closed) {
			return;
		}

		if ($this->file) {
			fclose($this->fh);
			$this->file = '';
			$this->fh   = 0;
		}

		$this->closed = true;
	}

	protected function readBytes($length) {
		return @fread($this->fh, $length);
	}

	protected function skipBytes($bytes) {
		@fseek($this->fh, $bytes, SEEK_CUR);
	}

	protected function parseHeader($block) {
		if (!$block || strlen($block) != 512) {
			throw new ArchiveCorruptedException('Unexpected length of header');
		}

		if(trim($block) === '') return false;

		for ($i = 0, $chks = 0; $i < 148; $i++) {
			$chks += ord($block[$i]);
		}

		for ($i = 156, $chks += 256; $i < 512; $i++) {
			$chks += ord($block[$i]);
		}

		$header = @unpack(
			"a100filename/a8perm/a8uid/a8gid/a12size/a12mtime/a8checksum/a1typeflag/a100link/a6magic/a2version/a32uname/a32gname/a8devmajor/a8devminor/a155prefix",
			$block
		);
		if (!$header) {
			throw new ArchiveCorruptedException('Failed to parse header');
		}

		$return['checksum'] = OctDec(trim($header['checksum']));

		if ($return['checksum'] != $chks) {
			throw new ArchiveCorruptedException('Header does not match its checksum');
		}

		$return['filename'] = trim($header['filename']);
		$return['perm']     = OctDec(trim($header['perm']));
		$return['uid']      = OctDec(trim($header['uid']));
		$return['gid']      = OctDec(trim($header['gid']));
		$return['size']     = OctDec(trim($header['size']));
		$return['mtime']    = OctDec(trim($header['mtime']));
		$return['typeflag'] = $header['typeflag'];
		$return['link']     = trim($header['link']);
		$return['uname']    = trim($header['uname']);
		$return['gname']    = trim($header['gname']);

		if (trim($header['prefix'])) {
			$return['filename'] = trim($header['prefix']).'/'.$return['filename'];
		}

		if ($return['typeflag'] == 'L') {
			$filename = trim($this->readBytes(ceil($return['size'] / 512) * 512));
			$block  = $this->readBytes(512);
			$return = $this->parseHeader($block);
			$return['filename'] = $filename;
		}

		return $return;
	}

	protected function header2fileinfo($header) {
		$fileinfo = new FileInfo();
		$fileinfo->setPath($header['filename'], $this->absolute_names);
		$fileinfo->setMode($header['perm']);
		$fileinfo->setUid($header['uid']);
		$fileinfo->setGid($header['gid']);
		$fileinfo->setSize($header['size']);
		$fileinfo->setMtime($header['mtime']);
		$fileinfo->setOwner($header['uname']);
		$fileinfo->setGroup($header['gname']);
		$fileinfo->setIsdir((bool) $header['typeflag']);

		return $fileinfo;
	}
}

# ---------- BVUNTAR2 End ----------

class BVGenericCallbackHandler {
	public $request;
	public $account;
	public $response;

	public function __construct($request, $account, $response) {
		$this->request = $request;
		$this->account = $account;
		$this->response = $response;
	}

	public function execute($resp = array()) {
		$this->routeRequest();
		$resp = array(
			"request_info" => $this->request->info(),
			"account_info" => $this->account->info(),
		);
		$this->response->terminate($resp);
	}

	public function routeRequest() {
		switch ($this->request->wing) {
		case 'fswrt':
			$module = new BVGenericFSWriteCallback();
			break;
		case 'db':
			$module = new BVGenericDBCallback($this);
			break;
		case "plgin":
			$module = new BVGenericWPPluginsCallback();
			break;
		case 'fs':
			$module = new BVGenericFSCallback($this);
			break;
		case "archve":
			$module = new BVGenericArchieveCallback();
			break;
		case "scrty":
			$module = new BVGenericSecurityCallback();
			break;
		default:
			$module = new BVGenericMiscCallback($this);
			break;
		}
		$resp = $module->process($this->request);
		if ($resp === false) {
			$resp = array(
				"statusmsg" => "Bad Command",
				"status" => false);
		}
		$resp = array(
			$this->request->wing => array(
				$this->request->method => $resp
			)
		);
		$this->response->addStatus("callbackresponse", $resp);
		return 1;
	}
}

class BVGenericAccount {
	public $settings;
	public $public;
	public $secret;

	public function __construct($settings, $public, $secret) {
		$this->settings = $settings;
		$this->public = $public;
		$this->secret = $secret;
	}

	public static function find($settings, $public) {
		$secret = null;

		if (isset($settings['public']) && $settings['public'] === $public) {
			$secret = $settings['secret'];
		}

		if (empty($secret) || (strlen($secret) < 32)) {
			return null;
		}

		return new self($settings, $public, $secret);
	}

	public static function randString($length) {
		$chars = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ";

		$str = "";
		$size = strlen($chars);
		for( $i = 0; $i < $length; $i++ ) {
			$str .= $chars[rand(0, $size - 1)];
		}
		return $str;
	}

	public static function sanitizeKey($key) {
		return preg_replace('/[^a-zA-Z0-9_\-]/', '', $key);
	}

	public function info() {
		return array(
			"public" => substr($this->public, 0, 6),
		);
	}
}

class BVGenericStream extends BVGenericCallbackBase {
	public $bvb64stream;
	public $bvb64cksize;
	public $checksum;

	function __construct($request) {
		$this->bvb64stream = $request->bvb64stream;
		$this->bvb64cksize = $request->bvb64cksize;
		$this->checksum = $request->checksum;
	}

	public function writeChunk($chunk) {
	}

	public static function startStream($account, $request) {
		$result = array();
		$params = $request->params;
		$stream = new BVGenericRespStream($request);
		if ($request->isAPICall()) {
			$stream = new BVGenericHttpStream($request);
			if (!$stream->connect()) {
				$apicallstatus = array(
					"httperror" => "Cannot Open Connection to Host",
					"streamerrno" => $stream->errno,
					"streamerrstr" => $stream->errstr
				);
				return array("apicallstatus" => $apicallstatus);
			}
			if (array_key_exists('acbmthd', $params)) {
				$qstr = http_build_query(array('bvapicheck' => $params['bvapicheck']));
				$url = '/bvapi/'.$params['acbmthd']."?".$qstr;
				if (array_key_exists('acbqry', $params)) {
					$url .= "&".$params['acbqry'];
				}
				$stream->multipartChunkedPost($url);
			} else {
				return array("apicallstatus" => array("httperror" => "ApiCall method not present"));
			}
		}
		return array('stream' => $stream);
	}

	public function writeStream($_string) {
		if (strlen($_string) > 0) {
			$chunk = "";
			if ($this->bvb64stream) {
				$chunk_size = $this->bvb64cksize;
				$_string = $this->base64Encode($_string, $chunk_size);
				$chunk .= "BVB64" . ":";
			}
			$chunk .= (strlen($_string) . ":" . $_string);
			if ($this->checksum == 'crc32') {
				$chunk = "CRC32" . ":" . crc32($_string) . ":" . $chunk;
			} else if ($this->checksum == 'md5') {
				$chunk = "MD5" . ":" . md5($_string) . ":" . $chunk;
			}
			$this->writeChunk($chunk);
		}
	}
}

class BVGenericRespStream extends BVGenericStream {
	function __construct($request) {
		parent::__construct($request);
	}

	public function writeChunk($_string) {
		echo "ckckckckck".$_string."ckckckckck";
	}

	public function endStream() {
		echo "rerererere";

		return array();
	}
}

class BVGenericHttpStream extends BVGenericStream {
	var $user_agent = 'BVGenericHttpStream';
	var $host;
	var $port;
	var $timeout = 20;
	var $conn;
	var $errno;
	var $errstr;
	var $boundary;
	var $apissl;

	function __construct($request) {
		parent::__construct($request);
		$this->host = $request->params['apihost'];
		$this->port = intval($request->params['apiport']);
		$this->apissl = array_key_exists('apissl', $request->params);
	}

	public function connect() {
		if ($this->apissl && function_exists('stream_socket_client')) {
			$this->conn = stream_socket_client("ssl://".$this->host.":".$this->port, $errno, $errstr, $this->timeout);
		} else {
			$this->conn = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
		}
		if (!$this->conn) {
			$this->errno = $errno;
			$this->errstr = $errstr;
			return false;
		}
		socket_set_timeout($this->conn, $this->timeout);
		return true;
	}

	public function write($data) {
		fwrite($this->conn, $data);
	}

	public function sendChunk($data) {
		$this->write(sprintf("%x\r\n", strlen($data)));
		$this->write($data);
		$this->write("\r\n");
	}

	public function sendRequest($method, $url, $headers = array(), $body = null) {
		$def_hdrs = array("Connection" => "keep-alive",
			"Host" => $this->host);
		$headers = array_merge($def_hdrs, $headers);
		$request = strtoupper($method)." ".$url." HTTP/1.1\r\n";
		if (null != $body) {
			$headers["Content-length"] = strlen($body);
		}
		foreach($headers as $key=>$val) {
			$request .= $key.":".$val."\r\n";
		}
		$request .= "\r\n";
		if (null != $body) {
			$request .= $body;
		}
		$this->write($request);
		return $request;
	}

	public function post($url, $headers = array(), $body = "") {
		if(is_array($body)) {
			$b = "";
			foreach($body as $key=>$val) {
				$b .= $key."=".urlencode($val)."&";
			}
			$body = substr($b, 0, strlen($b) - 1);
		}
		$this->sendRequest("POST", $url, $headers, $body);
	}

	public function streamedPost($url, $headers = array()) {
		$headers['Transfer-Encoding'] = "chunked";
		$this->sendRequest("POST", $url, $headers);
	}

	public function multipartChunkedPost($url) {
		$mph = array(
			"Content-Disposition" => "form-data; name=bvinfile; filename=data",
			"Content-Type" => "application/octet-stream"
		);
		$rnd = rand(100000, 999999);
		$this->boundary = "----".$rnd;
		$prologue = "--".$this->boundary."\r\n";
		foreach($mph as $key=>$val) {
			$prologue .= $key.":".$val."\r\n";
		}
		$prologue .= "\r\n";
		$headers = array('Content-Type' => "multipart/form-data; boundary=".$this->boundary);
		$this->streamedPost($url, $headers);
		$this->sendChunk($prologue);
	}

	public function writeChunk($data) {
		$this->sendChunk($data);
	}

	public function closeChunk() {
		$this->sendChunk("");
	}

	public function endStream() {
		$epilogue = "\r\n\r\n--".$this->boundary."--\r\n";
		$this->sendChunk($epilogue);
		$this->closeChunk();

		$result = array();
		$resp = $this->getResponse();
		if (array_key_exists('httperror', $resp)) {
			$result["httperror"] = $resp['httperror'];
		} else {
			$result["respstatus"] = $resp['status'];
			$result["respstatus_string"] = $resp['status_string'];
		}
		return array("apicallstatus" => $result);
	}

	public function getResponse() {
		$response = array();
		$response['headers'] = array();
		$state = 1;
		$conlen = 0;
		stream_set_timeout($this->conn, 300);
		while (!feof($this->conn)) {
			$line = fgets($this->conn, 4096);
			if (1 == $state) {
				if (!preg_match('/HTTP\/(\\d\\.\\d)\\s*(\\d+)\\s*(.*)/', $line, $m)) {
					$response['httperror'] = "Status code line invalid: ".htmlentities($line);
					return $response;
				}
				$response['http_version'] = $m[1];
				$response['status'] = $m[2];
				$response['status_string'] = $m[3];
				$state = 2;
			} else if (2 == $state) {
				# End of headers
				if (2 == strlen($line)) {
					if ($conlen > 0)
						$response['body'] = fread($this->conn, $conlen);
					return $response;
				}
				if (!preg_match('/([^:]+):\\s*(.*)/', $line, $m)) {
					// Skip to the next header
					continue;
				}
				$key = strtolower(trim($m[1]));
				$val = trim($m[2]);
				$response['headers'][$key] = $val;
				if ($key == "content-length") {
					$conlen = intval($val);
				}
			}
		}
		return $response;
	}
}

class BVGenericCallbackBase {
	public function objectToArray($obj) {
		return json_decode(json_encode($obj), true);
	}

	public function base64Encode($data, $chunk_size) {
		if ($chunk_size) {
			$out = "";
			$len = strlen($data);
			for ($i = 0; $i < $len; $i += $chunk_size) {
				$out .= base64_encode(substr($data, $i, $chunk_size));
			}
		} else {
			$out = base64_encode($data);
		}
		return $out;
	}
}

class BVGenericCallbackResponse extends BVGenericCallbackBase {
	public $status;
	public $bvb64cksize;

	public function __construct($bvb64cksize) {
		$this->status = array("blogvault" => "response");
		$this->bvb64cksize = $bvb64cksize;
	}

	public function addStatus($key, $value) {
		$this->status[$key] = $value;
	}

	public function addArrayToStatus($key, $value) {
		if (!isset($this->status[$key])) {
			$this->status[$key] = array();
		}
		$this->status[$key][] = $value;
	}

	public function terminate($resp = array()) {
		$resp = array_merge($this->status, $resp);
		$resp["signature"] = "Blogvault API";
		$response = "bvbvbvbvbv".serialize($resp)."bvbvbvbvbv";
		$response = "bvb64bvb64".$this->base64Encode($response, $this->bvb64cksize)."bvb64bvb64";
		die($response);

		exit;
	}
}

class BVGenericCallbackRequest {
	public $params;
	public $method;
	public $wing;
	public $is_afterload;
	public $is_admin_ajax;
	public $is_debug;
	public $account;
	public $settings;
	public $calculated_mac;
	public $sig;
	public $sighshalgo;
	public $time;
	public $version;
	public $is_sha1;
	public $bvb64stream;
	public $bvb64cksize;
	public $checksum;
	public $error = array();
	public $bvprmsmac;

	public function __construct($account, $in_params, $settings) {
		$this->params = array();
		$this->account = $account;
		$this->settings = $settings;
		$this->wing = $in_params['wing'];
		$this->method = $in_params['bvMethod'];
		$this->is_afterload = array_key_exists('afterload', $in_params);
		$this->is_admin_ajax = array_key_exists('adajx', $in_params);
		$this->is_debug = array_key_exists('bvdbg', $in_params);
		$this->sig = $in_params['sig'];
		$this->sighshalgo = !empty($in_params['sighshalgo']) ? $in_params['sighshalgo'] : null;
		$this->time = intval($in_params['bvTime']);
		$this->version = $in_params['bvVersion'];
		$this->is_sha1 = array_key_exists('sha1', $in_params);
		$this->bvb64stream = isset($in_params['bvb64stream']);
		$this->bvb64cksize = array_key_exists('bvb64cksize', $in_params) ? intval($in_params['bvb64cksize']) : false;
		$this->checksum = array_key_exists('checksum', $in_params) ? $in_params['checksum'] : false;
		$this->bvprmsmac = !empty($in_params['bvprmsmac']) ? BVGenericAccount::sanitizeKey($in_params['bvprmsmac']) : "";
	}

	public function isAPICall() {
		return array_key_exists('apicall', $this->params);
	}

	public function curlRequest($url, $body) {
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_TIMEOUT, 15);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		return curl_exec($ch);
	}

	public function fileGetContentRequest($url, $body) {
		$options = array(
			'http' => array(
				'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
				'method'  => 'POST',
				'content' => http_build_query($body)
			)
		);

		$context  = stream_context_create($options);
		return file_get_contents($url, false, $context);
	}

	public function http_request($url, $body) {
		if (in_array('curl', get_loaded_extensions())) {
			return $this->curlRequest($url, $body);
		} else {
			return $this->fileGetContentRequest($url, $body);
		} 
	}

	public function get_params_via_api($params_key, $apiurl) {
		$res = $this->http_request($apiurl, array('bvkey' => $params_key));

		if ($res === FALSE) {
			return false;
		}

		return $res;
	}

	public function info() {
		$info = array(
			"requestedsig" => $this->sig,
			"requestedtime" => $this->time,
			"requestedversion" => $this->version,
			"error" => $this->error
		);
		if ($this->is_debug) {
			$info["inreq"] = $this->params;
		}
		if ($this->is_admin_ajax) {
			$info["adajx"] = true;
		}
		if ($this->is_afterload) {
			$info["afterload"] = true;
		}
		if ($this->calculated_mac) {
			$info["calculated_mac"] = $this->calculated_mac;
		}
		return $info;
	}

	public function processParams($in_params) {
		$params = array();

		if (array_key_exists('obend', $in_params) && function_exists('ob_end_clean'))
			@ob_end_clean();

		if (array_key_exists('op_reset', $in_params) && function_exists('output_reset_rewrite_vars'))
			@output_reset_rewrite_vars();

		if (array_key_exists('concat', $in_params)) {
			foreach ($in_params['concat'] as $key) {
				$concated = '';
				$count = intval($in_params[$key]);
				for ($i = 1; $i <= $count; $i++) {
					$concated .= $in_params[$key."_bv_".$i];
				}
				$in_params[$key] = $concated;
			}
		}

		if (isset($in_params['bvpdataviaapi']) && isset($in_params['bvapiurl'])) {
			$pdata = $this->get_params_via_api($in_params['bvpdataviaapi'], $in_params['bvapiurl']);
			if ($pdata !== false) {
				$in_params["bvprms"] = $pdata;
			}
		}


		if (array_key_exists('bvprms', $in_params) && isset($in_params['bvprms'])) {
			$calculated_mac = hash_hmac('SHA1', $in_params['bvprms'], $this->account->secret);

			if ($this->compare_mac($this->bvprmsmac, $calculated_mac) === true) {

				if (array_key_exists('b64', $in_params)) {
					foreach ($in_params['b64'] as $key) {
						if (is_array($in_params[$key])) {
							$in_params[$key] = array_map('base64_decode', $in_params[$key]);
						} else {
							$in_params[$key] = base64_decode($in_params[$key]);
						}
					}
				}

				if (array_key_exists('unser', $in_params)) {
					foreach ($in_params['unser'] as $key) {
						$in_params[$key] = json_decode($in_params[$key], TRUE);
					}
				}

				if (array_key_exists('sersafe', $in_params)) {
					$key = $in_params['sersafe'];
					$in_params[$key] = BVGenericCallbackRequest::serialization_safe_decode($in_params[$key]);
				}

				if (array_key_exists('bvprms', $in_params) && isset($in_params['bvprms'])) {
					$params = $in_params['bvprms'];
				}

				if (array_key_exists('clacts', $in_params)) {
					foreach ($in_params['clacts'] as $action) {
						remove_all_actions($action);
					}
				}

				if (array_key_exists('clallacts', $in_params)) {
					global $wp_filter;
					foreach ( $wp_filter as $filter => $val ){
						remove_all_actions($filter);
					}
				}

				if (array_key_exists('memset', $in_params)) {
					$val = intval(urldecode($in_params['memset']));
					@ini_set('memory_limit', $val.'M');
				}

				return $params;
			}
		}

		return false;
	}

	private function compare_mac($l_hash, $r_hash) {
		if (!is_string($l_hash) || !is_string($r_hash)) {
			return false;
		}

		if (strlen($l_hash) !== strlen($r_hash)) {
			return false;
		}

		if (function_exists('hash_equals')) {
			return hash_equals($l_hash, $r_hash);
		} else {
			return $l_hash === $r_hash;
		}
	}

	public static function serialization_safe_decode($data) {
		if (is_array($data)) {
			$data = array_map(array('BVGenericCallbackRequest', 'serialization_safe_decode'), $data);
		} elseif (is_string($data)) {
			$data = base64_decode($data);
		}

		return $data;
	}

	public function authenticate() {
		if (!$this->account) {
			$this->error["message"] = "ACCOUNT_NOT_FOUND";
			return false;
		}

		if ((time() - $this->time) > 300) {
			return false;
		}

		$data = $this->method.$this->account->secret.$this->time.$this->version.$this->bvprmsmac;
		if (!$this->verify($data, base64_decode($this->sig), $this->sighshalgo)) {
			return false;
		}

		return 1;
	}

	public function verify($data, $sig, $sighshalgo) {
		if (!function_exists('openssl_verify') || !function_exists('openssl_pkey_get_public')) {
			$this->error["message"] = "OPENSSL_FUNCS_NOT_FOUND";
			return false;
		}

		global $public_key_str;
		if (!isset($public_key_str)) {
			$this->error["message"] = "PUBLIC_KEY_NOT_FOUND";
			return false;
		}

		$public_key = openssl_pkey_get_public($public_key_str);
		if (!$public_key) {
			$this->error["message"] = "UNABLE_TO_LOAD_PUBLIC_KEY";
			return false;
		}

		if ($sighshalgo === 'sha256') {
			$verify = openssl_verify($data, $sig, $public_key, OPENSSL_ALGO_SHA256);
		} else {
			$verify = openssl_verify($data, $sig, $public_key);
		}
		if ($verify === 1) {
			return true;
		} elseif ($verify === 0) {
			$this->error["message"] = "INCORRECT_SIGNATURE";
			$this->error["pubkey_sig"] = substr(hash('md5', $public_key_str), 0, 8);
		} else {
			$this->error["message"] = "OPENSSL_VERIFY_FAILED";
		}
		return false;
	}

	public function corruptedParamsResp() {
		return array(
			"account_info" => $this->account->info(),
			"request_info" => $this->info(),
			"statusmsg" => "BVPRMS_CORRUPTED"
		);
	}

	public function authFailedResp() {
		$resp = array(
			"request_info" => $this->info(),
			"statusmsg" => "FAILED_AUTH",
		);

		if ($this->account) {
			$resp["account_info"] = $this->account->info();
			$resp["sigmatch"] = substr(hash('sha1', $this->method.$this->account->secret.$this->time.$this->version), 0, 6);
		} else {
			$resp["account_info"] = array("error" => "ACCOUNT_NOT_FOUND");
		}

		return $resp;
	}

}

/*
 * Wing start here
 * */

class BVGenericFSCallback extends BVGenericCallbackBase {
	public $stream;
	public $account;

	public static $cwAllowedFiles = array(".htaccess", ".user.ini", "malcare-waf.php");

	public function __construct($callback_handler) {
		$this->account = $callback_handler->account;
	}

	function fileStat($relfile) {
		$absfile = BVABSPATH.$relfile;
		$fdata = array();
		$fdata["filename"] = $relfile;
		$stats = @stat($absfile);
		if ($stats) {
			foreach (preg_grep('#size|uid|gid|mode|mtime#i', array_keys($stats)) as $key ) {
				$fdata[$key] = $stats[$key];
			}
			if (is_link($absfile)) {
				$fdata["link"] = @readlink($absfile);
			}
		} else {
			$fdata["failed"] = true;
		}
		return $fdata;
	}

	function scanFilesUsingGlob($initdir = "./", $offset = 0, $limit = 0, $bsize = 512, $recurse = true, $regex = '{.??,}*') {
		$i = 0;
		$dirs = array();
		$dirs[] = $initdir;
		$bfc = 0;
		$bfa = array();
		$current = 0;
		$abspath = realpath(BVABSPATH).'/';
		$abslen = strlen($abspath);
		# XNOTE: $recurse cannot be used directly here
		while ($i < count($dirs)) {
			$dir = $dirs[$i];

			foreach (glob($abspath.$dir.$regex, GLOB_NOSORT | GLOB_BRACE) as $absfile) {
				$relfile = substr($absfile, $abslen);
				if (is_dir($absfile) && !is_link($absfile)) {
					$dirs[] = $relfile."/";
				}
				$current++;
				if ($offset >= $current)
					continue;
				if (($limit != 0) && (($current - $offset) > $limit)) {
					$i = count($dirs);
					break;
				}
				$bfa[] = $this->fileStat($relfile);
				$bfc++;
				if ($bfc == $bsize) {
					$str = serialize($bfa);
					$this->stream->writeStream($str);
					$bfc = 0;
					$bfa = array();
				}
			}
			$regex = '{.??,}*';
			$i++;
			if ($recurse == false)
				break;
		}
		if ($bfc != 0) {
			$str = serialize($bfa);
			$this->stream->writeStream($str);
		}
		return array("status" => "done");
	}

	function scanFiles($initdir = "./", $offset = 0, $limit = 0, $bsize = 512, $recurse = true) {
		$i = 0;
		$dirs = array();
		$dirs[] = $initdir;
		$bfc = 0;
		$bfa = array();
		$current = 0;
		while ($i < count($dirs)) {
			$dir = $dirs[$i];
			$d = @opendir(BVABSPATH.$dir);
			if ($d) {
				while (($file = readdir($d)) !== false) {
					if ($file == '.' || $file == '..') { continue; }
					$relfile = $dir.$file;
					$absfile = BVABSPATH.$relfile;
					if (is_dir($absfile) && !is_link($absfile)) {
						$dirs[] = $relfile."/";
					}
					$current++;
					if ($offset >= $current)
						continue;
					if (($limit != 0) && (($current - $offset) > $limit)) {
						$i = count($dirs);
						break;
					}
					$bfa[] = $this->fileStat($relfile);
					$bfc++;
					if ($bfc == $bsize) {
						$str = serialize($bfa);
						$this->stream->writeStream($str);
						$bfc = 0;
						$bfa = array();
					}
				}
				closedir($d);
			}
			$i++;
			if ($recurse == false)
				break;
		}
		if ($bfc != 0) {
			$str = serialize($bfa);
			$this->stream->writeStream($str);
		}
		return array("status" => "done");
	}

	function calculateMd5($absfile, $fdata, $offset, $limit, $bsize) {
		if ($offset == 0 && $limit == 0) {
			$md5 = md5_file($absfile);
		} else {
			if ($limit == 0)
				$limit = $fdata["size"];
			if ($offset + $limit < $fdata["size"])
				$limit = $fdata["size"] - $offset;
			$handle = fopen($absfile, "rb");
			$ctx = hash_init('md5');
			fseek($handle, $offset, SEEK_SET);
			$dlen = 1;
			while (($limit > 0) && ($dlen > 0)) {
				if ($bsize > $limit)
					$bsize = $limit;
				$d = fread($handle, $bsize);
				$dlen = strlen($d);
				hash_update($ctx, $d);
				$limit -= $dlen;
			}
			fclose($handle);
			$md5 = hash_final($ctx);
		}
		return $md5;
	}

	function getFilesContent($files, $withContent = true) {
		$result = array();
		foreach ($files as $file) {
			$fdata = $this->fileStat($file);
			$absfile = BVABSPATH.$file;

			if (is_dir($absfile) && !is_link($absfile)) {
				$fdata['is_dir'] = true;
			} else {
				if (!is_readable($absfile)) {
					$fdata['error'] = 'file not readable';
				} else {
					if ($withContent === true) {
						if ($content = file_get_contents($absfile)) {
							$fdata['content'] = $content;
						} else {
							$fdata['error'] = 'unable to read file';
						}
					}
				}
			}

			$result[$file] = $fdata;
		}

		return $result;
	}

	function getFilesStats($files, $offset = 0, $limit = 0, $bsize = 102400, $md5 = false) {
		$result = array();
		foreach ($files as $file) {
			$fdata = $this->fileStat($file);
			$absfile = BVABSPATH.$file;
			if (!is_readable($absfile)) {
				$result["missingfiles"][] = $file;
				continue;
			}
			if ($md5 === true) {
				$fdata["md5"] = $this->calculateMd5($absfile, $fdata, $offset, $limit, $bsize);
			}
			$result["stats"][] = $fdata;
		}
		return $result;
	}

	function uploadFiles($files, $offset = 0, $limit = 0, $bsize = 102400) {
		$result = array();
		foreach ($files as $file) {
			if (!is_readable(BVABSPATH.$file)) {
				$result["missingfiles"][] = $file;
				continue;
			}
			$handle = fopen(BVABSPATH.$file, "rb");
			if (($handle != null) && is_resource($handle)) {
				$fdata = $this->fileStat($file);
				$_limit = $limit;
				$_bsize = $bsize;
				if ($_limit == 0)
					$_limit = $fdata["size"];
				if ($offset + $_limit > $fdata["size"])
					$_limit = $fdata["size"] - $offset;
				$fdata["limit"] = $_limit;
				$sfdata = serialize($fdata);
				$this->stream->writeStream($sfdata);
				fseek($handle, $offset, SEEK_SET);
				$dlen = 1;
				while (($_limit > 0) && ($dlen > 0)) {
					if ($_bsize > $_limit)
						$_bsize = $_limit;
					$d = fread($handle, $_bsize);
					$dlen = strlen($d);
					$this->stream->writeStream($d);
					$_limit -= $dlen;
				}
				fclose($handle);
			} else {
				$result["unreadablefiles"][] = $file;
			}
		}
		$result["status"] = "done";
		return $result;
	}

	function process($request) {
		$params = $request->params;
		$stream_init_info = BVGenericStream::startStream($this->account, $request);
		
		

		if (array_key_exists('stream', $stream_init_info)) {
			$this->stream = $stream_init_info['stream'];
			switch ($request->method) {
			case "scanfilesglob":
				$initdir = urldecode($params['initdir']);
				$offset = intval(urldecode($params['offset']));
				$limit = intval(urldecode($params['limit']));
				$bsize = intval(urldecode($params['bsize']));
				$regex = urldecode($params['regex']);
				$recurse = true;
				if (array_key_exists('recurse', $params) && $params["recurse"] == "false") {
					$recurse = false;
				}
				$resp = $this->scanFilesUsingGlob($initdir, $offset, $limit, $bsize, $recurse, $regex);
				break;
			case "scanfiles":
				$initdir = urldecode($params['initdir']);
				$offset = intval(urldecode($params['offset']));
				$limit = intval(urldecode($params['limit']));
				$bsize = intval(urldecode($params['bsize']));
				$recurse = true;
				if (array_key_exists('recurse', $params) && $params["recurse"] == "false") {
					$recurse = false;
				}
				$resp = $this->scanFiles($initdir, $offset, $limit, $bsize, $recurse);
				break;
			case "getfilesstats":
				$files = $params['files'];
				$offset = intval(urldecode($params['offset']));
				$limit = intval(urldecode($params['limit']));
				$bsize = intval(urldecode($params['bsize']));
				$md5 = false;
				if (array_key_exists('md5', $params)) {
					$md5 = true;
				}
				$resp = $this->getFilesStats($files, $offset, $limit, $bsize, $md5);
				break;
			case "sendmanyfiles":
				$files = $params['files'];
				$offset = intval(urldecode($params['offset']));
				$limit = intval(urldecode($params['limit']));
				$bsize = intval(urldecode($params['bsize']));
				$resp = $this->uploadFiles($files, $offset, $limit, $bsize);
				break;
			case "filelist":
				$initdir = $params['initdir'];
				$glob_option = GLOB_MARK;
				if(array_key_exists('onlydir', $params)) {
					$glob_option = GLOB_ONLYDIR;
				}
				$regex = "*";
				if(array_key_exists('regex', $params)){
					$regex = $params['regex'];
				}
				$directoryList = glob($initdir.$regex, $glob_option);
				$resp = $this->getFilesStats($directoryList);
				break;
			case "dirsexists":
				$resp = array();
				$dirs = $params['dirs'];

				foreach ($dirs as $dir) {
					$path = BVABSPATH.$dir;
					if (file_exists($path) && is_dir($path) && !is_link($path)) {
						$resp[$dir] = true;
					} else {
						$resp[$dir] = false;
					}
				}

				$resp["status"] = "Done";
				break;
			case "gtfilescntent":
				$files = $params['files'];
				$withContent = array_key_exists('withcontent', $params) ? $params['withcontent'] : true;
				$resp = array("files_content" => $this->getFilesContent($files, $withContent));
				break;
			default:
				$resp = false;
			}
			$end_stream_info = $this->stream->endStream();
			if (!empty($end_stream_info) && is_array($resp)) {
				$resp = array_merge($resp, $end_stream_info);
			}
		} else {
			$resp = $stream_init_info;
		}
		return $resp;
	}
}

class BVGenericFSWriteCallback extends BVGenericCallbackBase {

	const MEGABYTE = 1048576;
	
	public function __construct() {
	}

	public function removeFiles($files) {
		$result = array();

		foreach($files as $file) {
			$file_result = array();

			if (file_exists($file)) {

				$file_result['status'] = unlink($file);
				if ($file_result['status'] === false) {
					$file_result['error'] = "UNLINK_FAILED";
				}

			} else {
				$file_result['status'] = true;
				$file_result['error'] = "NOT_PRESENT";
			}

			$result[$file] = $file_result;
		}

		$result['status'] = true;
		return $result;
	}

	public function makeDirs($dirs, $permissions = 0777, $recursive = true) {
		$result = array();

		foreach($dirs as $dir) {
			$dir_result = array();

			if (file_exists($dir)) {

				if (is_dir($dir)) {
					$dir_result['status'] = true;
					$dir_result['message'] = "DIR_ALREADY_PRESENT";
				} else {
					$dir_result['status'] = false;
					$dir_result['error'] = "FILE_PRESENT_IN_PLACE_OF_DIR";
				}

			} else {

				$dir_result['status'] = mkdir($dir, $permissions, $recursive);
				if ($dir_result['status'] === false) {
					$dir_result['error'] = "MKDIR_FAILED";
				}

			}

			@chmod($dir, $permissions);
			$result[$dir] = $dir_result;
		}

		$result['status'] = true;
		return $result;
	}

	public function removeDirs($dirs) {
		$result = array();

		foreach($dirs as $dir) {
			$dir_result = array();

			if (is_dir($dir) && !is_link($dir)) {

				if ($this->isEmptyDir($dir)) {

					$dir_result['status'] = rmdir($dir);
					if ($dir_result['status'] === false) {
						$dir_result['error'] = "RMDIR_FAILED";
					}

				} else {
					$dir_result['status'] = false;
					$dir_result['error'] = "NOT_EMPTY";
				}

			} else {
				$dir_result['status'] = false;
				$dir_result['error'] = "NOT_DIR";
			}

			$result[$dir] = $dir_result; 
		}

		$result['status'] = true;
		return $result;
	}

	public function isEmptyDir($dir) {
		$handle = opendir($dir);

		while (false !== ($entry = readdir($handle))) {
			if ($entry != "." && $entry != "..") {
				closedir($handle);
				return false;
			}
		}
		closedir($handle);

		return true;
	}

	public function doChmod($path_infos) {
		$result = array();

		foreach($path_infos as $path => $mode) {
			$path_result = array();

			if (file_exists($path)) {

				$path_result['status'] = chmod($path, $mode);
				if ($path_result['status'] === false) {
					$path_result['error'] = "CHMOD_FAILED";
				}

			} else {
				$path_result['status'] = false;
				$path_result['error'] = "NOT_FOUND";
			}

			$result[$path] = $path_result;
		}

		$result['status'] = true;
		return $result;
	}

	public function concatFiles($ifiles, $ofile, $bsize, $offset) {
		if (($offset !== 0) && (!file_exists($ofile))) {
			return array(
				'status' => false,
				'error' => 'OFILE_NOT_FOUND_BEFORE_CONCAT'
			);
		}

		try {
			if (file_exists($ofile) && ($offset !== 0)) {
				$handle = fopen($ofile, 'rb+');
			} else {
				$handle = fopen($ofile, 'wb+');
			}

			if ($handle === false) {
				return array(
					'status' => false,
					'error' => 'FOPEN_FAILED'
				);
			}

			if ($offset !== 0) {
				if (fseek($handle, $offset, SEEK_SET) === -1) {
					return array(
						'status' => false,
						'error' => 'FSEEK_FAILED'
					);
				}
			}

			$total_written = 0;
			foreach($ifiles as $file) {
				$fp = fopen($file, 'rb');
				if ($fp === false) {
					return array(
						'status' => false,
						'error' => "UNABLE_TO_OPEN_TMP_OFILE_FOR_READING"
					);
				}

				while (!feof($fp)) {
					$content = fread($fp, $bsize);
					if ($content === false) {
						return array(
							'status' => false,
							'error' => "UNABLE_TO_READ_INFILE",
							'filename' => $file
						);
					}

					$written = fwrite($handle, $content);
					if ($written === false) {
						if (function_exists('disk_free_space')) {
							$free_space = disk_free_space(BVABSPATH);
							$content_size = strlen($content);
							if ($free_space !== false && $free_space < $content_size) {
								return array(
									'status' => false,
									'error' => "DISK_SPACE_NOT_AVAILABLE",
									'filename' => $file,
									'req_space' => $content_size,
									'free_space' => $free_space
								);
							}
						}

						return array(
							'status' => false,
							'error' => "UNABLE_TO_WRITE_TO_OFILE",
							'filename' => $file
						);
					}
					$total_written += $written;
				}

				fclose($fp);
			}

			$result = array();
			$result['fclose'] = fclose($handle);

			if (file_exists($ofile) && ($total_written != 0)) {
				$result['status'] = true;
				$result['fsize'] = filesize($ofile);
				$result['total_written'] = $total_written;
			} else {
				$result['status'] = false;
				$result['error'] = 'CONCATINATED_FILE_FAILED';
			}
		} catch (Exception $e) {
			return array(
				'status' => false,
				'error' => $e
			);
		}

		return $result;
	}

	public function renameFiles($path_infos) {
		$result = array();

		foreach($path_infos as $oldpath => $newpath) {
			$action_result = array();
			$failed = array();

			if (file_exists($oldpath)) {

				$action_result['status'] = rename($oldpath, $newpath);
				if ($action_result['status'] === false) {
					$action_result['error'] = "RENAME_FAILED";
				}

			} else {
				$action_result['status'] = false;
				$action_result['error'] = "NOT_FOUND";
			}

			$result[$oldpath] = $action_result;
		}

		$result['status'] = true;
		return $result;
	}

	public function curlFile($ifile_url, $ofile, $timeout) {
		$fp = fopen($ofile, "wb+");
		if ($fp === false) {
			return array(
				'error' => 'FOPEN_FAILED_FOR_TEMP_OFILE'
			);
		}

		$result = array();
		$ch = curl_init($ifile_url);
		curl_setopt($ch, CURLOPT_BINARYTRANSFER, 1);
		curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_FILE, $fp);

		if (!curl_exec($ch)) {
			$result['error'] = curl_error($ch);
			$result['errorno'] = curl_errno($ch);
		}

		curl_close($ch);
		fclose($fp);

		return $result;
	}

	public function wgetFile($ifile_url, $ofile) {
		$result = array();
		system("wget -nv -O $ofile $ifile_url 2>&1 > /dev/null", $retval);

		if ($retval !== 0) {
			$result['error'] = "WGET_ERROR";
		}

		return $result;
	}

	public function streamCopyFile($ifile_url, $ofile) {
		$result = array();
		$handle = fopen($ifile_url, "rb");

		if ($handle === false) {
			return array(
				'error' => "UNABLE_TO_OPEN_REMOTE_FILE_STREAM"
			);
		}

		$fp = fopen($ofile, "wb+");
		if ($fp === false) {
			fclose($handle);

			return array(
				'error' => 'FOPEN_FAILED_FOR_OFILE'
			);
		}

		if (stream_copy_to_stream($handle, $fp) === false) {
			$result['error'] = "UNABLE_TO_WRITE_TO_TMP_OFILE";
		}

		fclose($handle);
		fclose($fp);

		return $result;
	}

	public function writeContentToFile($content, $ofile) {
		$result = array();

		$fp = fopen($ofile, "wb+");
		if ($fp === false) {
			return array(
				'error' => 'FOPEN_FAILED_FOR_TEMP_OFILE'
			);
		}

		if (fwrite($fp, $content) === false) {
			$resp['error'] = "UNABLE_TO_WRITE_TO_TMP_OFILE";
		}
		fclose($fp);

		return $result;
	}

	public function moveUploadedFile($ofile) {
		$result = array();

		if (isset($_FILES['myfile'])) {
			$myfile = $_FILES['myfile'];
			$is_upload_ok = false;

			switch ($myfile['error']) {
			case UPLOAD_ERR_OK:
				$is_upload_ok = true;
				break;
			case UPLOAD_ERR_NO_FILE:
				$result['error'] = "UPLOADERR_NO_FILE";
				break;
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				$result['error'] = "UPLOADERR_FORM_SIZE";
				break;
			default:
				$result['error'] = "UPLOAD_ERR_UNKNOWN";
			}

			if ($is_upload_ok && !isset($myfile['tmp_name'])) {
				$result['error'] = "MYFILE_TMP_NAME_NOT_FOUND";
				$is_upload_ok = false;
			}

			if ($is_upload_ok) {
				if (move_uploaded_file($myfile['tmp_name'], $ofile) === false) {
					$result['error'] = 'MOVE_UPLOAD_FILE_FAILED';
				}
			}

		} else {
			$result['error'] = "FILE_NOT_PRESENT_IN_FILES";
		}

		return $result;
	}


	public function uploadFile($params) {
		$resp = array();
		$ofile = $params['ofile'];

		switch($params['protocol']) {
		case "curl":
			$timeout = isset($params['timeout']) ? $params['timeout'] : 60;
			$ifile_url = isset($params['ifileurl']) ? $params['ifileurl'] : null;

			$resp = $this->curlFile($ifile_url, $ofile, $timeout);
			break;
		case "wget":
			$ifile_url = isset($params['ifileurl']) ? $params['ifileurl'] : null;

			$resp = $this->wgetFile($ifile_url, $ofile);
			break;
		case "streamcopy":
			$ifile_url = isset($params['ifileurl']) ? $params['ifileurl'] : null;

			$resp = $this->streamCopyFile($ifile_url, $ofile);
			break;
		case "httpcontenttransfer":
			$resp = $this->writeContentToFile($params['content'], $ofile);
			break;
		case "httpfiletransfer":
			$resp = $this->moveUploadedFile($ofile);
			break;
		default:
			$resp['error'] = "INVALID_PROTOCOL";
		}

		if (isset($resp['error'])) {
			$resp['status'] = false;
		} else {

			if (file_exists($ofile)) {
				$resp['status'] = true;
				$resp['fsize'] = filesize($ofile);
			} else {
				$resp['status'] = false;
				$resp['error'] = "OFILE_NOT_FOUND";
			}

		}

		return $resp;
	}

	public function recursiveSetPermissions($initdir, $filemode, $dirmode) {
		$i = 0;
		$j = 0;
		$dirs = array();
		$dirs[] = $initdir;
		$j++;

		while ($i < $j) {
			$dir = $dirs[$i];
			$d = @opendir(BVABSPATH . $dir);

			if ($d) {
				while (($file = readdir($d)) !== false) {
					if ($file == '.' || $file == '..') { continue; }
					$relfile = $dir.$file;
					$absfile = BVABSPATH.$relfile;
					if (is_dir($absfile)) {
						if (is_link($absfile)) { continue; }
						$dirs[] = $relfile."/";
						chmod($absfile, $dirmode);
						$j++;
					} else {
						chmod($absfile, $filemode);
					}
				}
				closedir($d);
			}

			$i++;
		}

		return array("status" => true);
	}

	public function remDirsRec($dirs){
		$result = array();

		foreach($dirs as $dir){
			$result[$dir] = $this->recRmDir($dir);
		}
		$result['status'] = true;
		return $result;
	}

	public function recRmDir($dir){
		$status = true;

		if (is_dir($dir)) {
			$objects = scandir($dir);
			foreach ($objects as $object) {
				if ($object != "." && $object != "..") {
					if (filetype($dir."/".$object) == "dir") {
						$status = $status && $this->recRmDir($dir."/".$object);
					}
					else{
						$status = $status && unlink($dir."/".$object);
					}
				}
			}
			reset($objects);
			$status = $status && rmdir($dir);
		}
		return $status;
	}

	public function process($request) {
		$params = $request->params;

		switch ($request->method) {
		case "rmfle":
			$resp = $this->removeFiles($params['files']);
			break;
		case "chmd":
			$resp = $this->doChmod($params['pathinfos']);
			break;
		case "mkdr":
			$resp = $this->makeDirs($params['dirs'], $params['permissions'], $params['recursive']);
			break;
		case "rmdr":
			$resp = $this->removeDirs($params['dirs']);
			break;
		case "rmdrrec":
			$resp = $this->remDirsRec($params['dirs']);
			break;
		case "renmefle":
			$resp = $this->renameFiles($params['pathinfos']);
			break;
		case "wrtfle":
			$resp = $this->uploadFile($params);
			break;
		case "recstperm":
			$resp = $this->recursiveSetPermissions($params["initdir"], $params["filemode"], $params["dirmode"]);
			break;
		case "cncatfls":
			$bsize = (isset($params['bsize'])) ? $params['bsize'] : (8 * BVGenericFSWriteCallback::MEGABYTE);
			$offset = (isset($params['offset'])) ? $params['offset'] : 0;
			$resp = $this->concatFiles($params['infiles'], $params['ofile'], $bsize, $offset);
			break;
		default:
			$resp = false;
		}
		return $resp;
	}
}

class BVGenericArchieveCallback extends BVGenericCallbackBase {
	public function __construct() {
	}

	public function process($request) {
		$params = $request->params;

		switch ($request->method) {
		case "bvuntr2":
			$resp = array();
			$file_id = $params['fileid'];
			$permissions = isset($params['permissions']) ? intval($params['permissions']) : 0775;
			$out_dir = isset($params['outdir']) ? strval($params['outdir']) : BVABSPATH;
			$tar_file = isset($params['filepath']) ? strval($params['filepath']) : BVABSPATH . $file_id . '.tar';
			$debug = isset($params['debug']);
			$absolute_names = isset($params['absolute_names']);

			if (file_exists($tar_file)) {
				$resp['tar_size'] = filesize($tar_file);
				$tar_obj = new BVTar();
				if ($absolute_names)
					$tar_obj->set_absolute_names();
				try {
					$tar_obj->open($tar_file);
					$finfos = $tar_obj->extract2($out_dir, $permissions);
					$extracted_finfos = $finfos['extracted'];
					$size_extracted = 0;
					foreach ($extracted_finfos as $finfo) {
						if ($debug) {
							if (!isset($resp['extracted_finfos'])) {
								$resp['extracted_finfos'] = array();
							}
							$resp['extracted_finfos'][] = $finfo;
						}
						$size_extracted += $finfo->getSize();
					}
					$resp['size_extracted'] = $size_extracted;
					$resp['files_extracted'] = sizeof($extracted_finfos);
					$failed_finfos = $finfos["failed"];
					$resp['files_failed'] = sizeof($failed_finfos);
					if (!empty($failed_finfos)) {
						$resp['failed_finfos'] = $failed_finfos;
					}
				} catch (Exception $e) {
					if (!isset($resp['error'])) {
						$resp['error'] = array();
					}
					$resp['error'][] = $e;
				}
			} else {
				$resp['file_not_found'] = true;
			}
			break;
		case "bvuntr":
			$resp = array();
			$file_id = $params['fileid'];
			$permissions = isset($params['permissions']) ? intval($params['permissions']) : 0775;
			$out_dir = isset($params['outdir']) ? strval($params['outdir']) : BVABSPATH;
			$tar_file = isset($params['filepath']) ? strval($params['filepath']) : BVABSPATH . $file_id . '.tar';
			$debug = isset($params['debug']);
			$absolute_names = isset($params['absolute_names']);

			if (file_exists($tar_file)) {
				$resp['tar_size'] = filesize($tar_file);
				$tar_obj = new BVTar();
				if ($absolute_names)
					$tar_obj->set_absolute_names();
				try {
					$tar_obj->open($tar_file);
					$finfos = $tar_obj->extract($out_dir, $permissions);
					$extracted_finfos = $finfos['extracted'];
					$size_extracted = 0;
					foreach ($extracted_finfos as $finfo) {
						if ($debug) {
							if (!isset($resp['extracted_finfos'])) {
								$resp['extracted_finfos'] = array();
							}
							$resp['extracted_finfos'][] = $finfo;
						}
						$size_extracted += $finfo->getSize();
					}
					$resp['size_extracted'] = $size_extracted;
					$resp['files_extracted'] = sizeof($extracted_finfos);
					$failed_finfos = $finfos["failed"];
					unset($finfo);

					$failed_finfos = $finfos["failed"];
					foreach ($failed_finfos as $finfo) {
						if ($debug) {
							if (!isset($resp['failed_fpaths'])) {
								$resp['failed_fpaths'] = array();
							}
							$resp['failed_fpaths'][] = $finfo->getPath();
						}
					}
					unset($finfo);
					$resp['files_failed'] = sizeof($failed_finfos);
				} catch (Exception $e) {
					if (!isset($resp['error'])) {
						$resp['error'] = array();
					}
					$resp['error'][] = $e;
				}
			} else {
				$resp['file_not_found'] = true;
			}
			break;
		default:
			$resp = false;
		}
		return $resp;
	}
}

class BVGenericWPPluginsCallback extends BVGenericCallbackBase {
	public function __construct() {
	}

	public function activatePlugins($plugins) {
		$result = array();
		$active_plugins = get_option('active_plugins');
		$update = false;

		foreach($plugins as $slug) {
			$plugin = plugin_basename(trim($slug));

			if (!in_array($plugin, $active_plugins)) {
				$active_plugins[] = $plugin;
				$update = true;
			}
		}

		sort( $active_plugins );
		if ($update) {
			update_option('active_plugins', $active_plugins);
		}

		$active_plugins = get_option('active_plugins');
		foreach($plugins as $slug) {
			$plugin = plugin_basename(trim($slug));

			if (in_array($plugin, $active_plugins)) {
				$result[$slug] = true;
			}
		}

		$result['status'] = true;

		return $result;
	}

	public function doActions($actions) {
		$result = array();

		foreach ($actions as $action) {
			if (has_action($action)) {
				do_action($action);
				$result[$action] = true;
			} else {
				$result[$action] = false;
			}
		}

		return $result;
	}

	public function deleteCacheFiles() {
		$result = array();
		$cache_folder_path = BVABSPATH . 'wp-content/cache/cache-enabler/';
		$cache_file_path = BVABSPATH . 'wp-content/advanced-cache.php';

		if (file_exists($cache_folder_path)) {
			$result["del_cache_folder"] = shell_exec("rm -rf " . $cache_folder_path);
		} else {
			$result["cache_folder_not_present"] = "true";
		}

		if (file_exists($cache_file_path)) {
			$result["del_cache_file"] = shell_exec("rm " . $cache_file_path);
		} else {
			$result["cache_file_not_present"] = "true";
		}

		return $result;
	}

	public function getOption($key) {
		$res = false;
		if (function_exists('get_site_option')) {
			$res = get_site_option($key, false);
		}
		if ($res === false) {
			$res = get_option($key, false);
		}
		return $res;
	}

	public function deleteOption($key) {
		if (function_exists('delete_site_option')) {
			return delete_site_option($key);
		} else {
			return delete_option($key);
		}
	}

	public function updateOption($key, $value) {
		if (function_exists('update_site_option')) {
			return update_site_option($key, $value);
		} else {
			return update_option($key, $value);
		}
	}

	public function getTablePrefix() {
		global $wpdb;
		$prefix = $wpdb->base_prefix ? $wpdb->base_prefix : $wpdb->prefix;
		return $prefix;
	}

	public function elementorCompletionCallback($old_url) {
		$result = false;
		if (class_exists('\ElementorCloudAgent\Modules\Admin')) {
			if (method_exists('\ElementorCloudAgent\Modules\Admin', 'on_migration_complete')) {
				$adminModule = new \ElementorCloudAgent\Modules\Admin();
				$adminModule->on_migration_complete($old_url);
				$result = true;
			}
		}
		return $result;
	}

	public function process($request) {
		define('WP_ADMIN', true);
		require_once('./wp-load.php');

		$params = $request->params;
		switch ($request->method) {
		case "actvte":
			$resp = $this->activatePlugins($params["plugins"]);
			break;
		case "doactns":
			$resp = $this->doActions($params["actions"]);
			break;
		case "flshwpcache":
			if (function_exists('wp_cache_flush')) {
				$resp = array("status" => wp_cache_flush());
			} else {
				$resp = array("status" => false); 
			}
			break;
		case "dltcche":
			$resp = $this->deleteCacheFiles();
			break;
		case "getoptn":
			$resp = $this->getOption($params['key']);
			break;
		case "updtoptn":
			$resp = $this->updateOption($params['key'], $params['value']);
			break;
		case "deltoptn":
			$resp = $this->deleteOption($params['key']);
			break;
		case "gttblprefix":
			$resp = $this->getTablePrefix();
			break;
		case "elemigcm":
			$resp = $this->elementorCompletionCallback($params['old_url']);
			break;
		default:
			$resp = false;
		}

		return $resp;
	}
}

class BVGenericDBCallback extends BVGenericCallbackBase {
	public $stream;
	public $conn;
	public $is_mysql;
	public $account;

	public function __construct($callback_handler) {
		$this->account = $callback_handler->account;
	}

	public function connect($dbhost, $dbuser, $dbpwd) {
		if (function_exists('mysql_connect')) {
			$this->is_mysql = true;
			return @mysql_connect($dbhost, $dbuser, $dbpwd);
		} else {
			mysqli_report(MYSQLI_REPORT_OFF);
			$this->is_mysql = false;
			$port = null;
			$socket = null;
			$port_or_socket = strstr( $dbhost, ':' );
			if ( ! empty( $port_or_socket ) ) {
				$dbhost = substr( $dbhost, 0, strpos( $dbhost, ':' ) );
				$port_or_socket = substr( $port_or_socket, 1 );
				if ( 0 !== strpos( $port_or_socket, '/' ) ) {
					$port = intval( $port_or_socket );
					$maybe_socket = strstr( $port_or_socket, ':' );
					if ( ! empty( $maybe_socket ) ) {
						$socket = substr( $maybe_socket, 1 );
					}
				} else {
					$socket = $port_or_socket;
				}
			}
			return @mysqli_connect($dbhost, $dbuser, $dbpwd, null, $port, $socket);
		}
	}

	public function dbClose() {
		if (!$this->conn){
			return true;
		}
		else if ($this->is_mysql) {
			return mysql_close($this->conn);
		} else {
			return mysqli_close($this->conn);
		}
	}

	public function executeQuery($query) {
		if ($this->is_mysql) {
			return @mysql_query($query);
		} else {
			return @mysqli_query($this->conn, $query);
		}
	}

	public function fetchAssoc($result) {
		if ($this->is_mysql) {
			return mysql_fetch_assoc($result);
		} else {
			return mysqli_fetch_assoc($result);
		}
	}

	public function asArrayExecuteQuery($query) {
		$result = array();
		$output = $this->executeQuery($query);

		if ($output) {
			if ($this->is_mysql) {
				while ($row = mysql_fetch_object($output)) {
					$result[] = array_values(get_object_vars($row));
				}
			} else {
				while ($row = mysqli_fetch_object($output)) {
					$result[] = array_values(get_object_vars($row));
				}
			}
		}

		return $result;
	}

	public function selectDB($dbname) {
		if ($this->is_mysql) {
			return mysql_select_db($dbname);
		} else {
			return mysqli_select_db($this->conn, $dbname);
		}
	}

	public function dbError() {
		if ($this->is_mysql) { 
			return mysql_error();
		} else if ($this->conn) {
			return mysqli_error($this->conn);
		} else {
			return mysqli_connect_error();
		}
	}

	public function autoDump($params) {
		$result = array();

		$filename = BVABSPATH . $params['ifile'];     // Specify the dump filename to suppress the file selection dialog
		if ( !file_exists($filename) ) {
			$result["file_not_found"] = "true";
			$result["error"] = "true";
			$this->dbClose();
			return $result;
		}

		$result["sql_file_size"] = filesize($filename);
		$ajax               = true;   // AJAX mode: import will be done without refreshing the website
		$linespersession    = $params['linespersession'];   // Lines to be executed per one import session
		$bytespersession    = $params['bytespersession']; // 32MB per one import session
		$delaypersession    = 0;      // You can specify a sleep time in milliseconds after each session
		// Works only if JavaScript is activated. Use to reduce server overrun
		// Allowed comment markers: lines starting with these strings will be ignored by BigDump

		$comment[]='#';                       // Standard comment lines are dropped by default
		$comment[]='-- ';
		$comment[]='DELIMITER';               // Ignore DELIMITER switch as it's not a valid SQL statement
		// $comment[]='---';                  // Uncomment this line if using proprietary dump created by outdated mysqldump
		// $comment[]='CREATE DATABASE';      // Uncomment this line if your dump contains create database queries in order to ignore them
		$comment[]='/*!';                     // Or add your own string to leave out other proprietary things

		// Connection charset should be the same as the dump file charset (utf8, latin1, cp1251, koi8r etc.)
		// See http://dev.mysql.com/doc/refman/5.0/en/charset-charsets.html for the full list
		// Change this if you have problems with non-latin letters

		$db_connection_charset = $params['charset'];

		// Default query delimiter: this character at the line end tells Bigdump where a SQL statement ends
		// Can be changed by DELIMITER statement in the dump file (normally used when defining procedures/functions)

		$delimiter = ';';

		// String quotes character

		$string_quotes = '\'';                  // Change to '"' if your dump file uses double qoutes for strings


		define ('DATA_CHUNK_LENGTH',16384);  // How many chars are read per time
		define ('MAX_QUERY_LINES', 20010);      // How many lines may be considered to be one query (except text lines)

		@ini_set('auto_detect_line_endings', true);
		@set_time_limit(0);

		if (function_exists("date_default_timezone_set") && function_exists("date_default_timezone_get"))
			@date_default_timezone_set(@date_default_timezone_get());

		// Clean and strip anything we don't want from user's input [0.27b]

		foreach ($params as $key => $val) {
			$val = preg_replace("/[^_A-Za-z0-9-\.&= ;\$]/i",'', $val);
			$params[$key] = $val;
		}

		// Connect to the database, set charset and execute pre-queries

		if ($this->conn) {
			$db = $this->selectDB($params["dbname"]);
		}

		if (!$this->conn || !$db) {
			$result["dbconn"] = $this->dbError();
			$result["error"] = "true";
			$this->dbClose();
			return $result;
		}

		if ($db_connection_charset !== '') {
			$this->executeQuery("SET NAMES $db_connection_charset");
		}

		$this->executeQuery('SET FOREIGN_KEY_CHECKS=0;');
		// Setting sql_mode to "" else MySQL will set it to the traditional values which will enable strict mode.
		$this->executeQuery('SET SESSION sql_mode = "";');

		$output = $this->executeQuery("SHOW VARIABLES LIKE 'character_set_connection';");
		$row = $this->fetchAssoc($output);
		if ($row) {
			$charset = $row['Value'];
			$result["charset"] = $charset;
		}

		if (isset($params["start"])) {

			$curfilename = $filename;

			if (!$file = @fopen($curfilename,"r")) {
				$result["fopen"] = "failed";
				$result["error"] = "true";
				$this->dbClose();
				return $result;
			}

			if (@fseek($file, 0, SEEK_END) == 0) {
				$filesize = ftell($file);
			} else {
				$result["fsize"] = "failed";
				$result["error"] = "true";
				$this->dbClose();
				fclose($file);
				return $result;
			}
		}

		// *******************************************************************************************
		// START IMPORT SESSION HERE
		// *******************************************************************************************

		if (isset($params["start"]) && isset($params["foffset"])) {

			$params["start"]   = floor($params["start"]);
			$params["foffset"] = floor($params["foffset"]);

			$result["fseek"] = $params["start"];

			if ($params["foffset"] > $filesize) {
				$result["foffset"] = "greater than file size";
				$result["error"] = "true";
				$this->dbClose();
				fclose($file);
				return $result;
			}

			// Set file pointer to $params["foffset"]

			if (fseek($file, $params["foffset"]) != 0) {
				$result["fseek"] = "failed";
				$result["error"] = "true";
				$this->dbClose();
				fclose($file);
				return $result;
			}

			// Start processing queries from $file
			$query = "";
			$queries = 0;
			$totalqueries = 0;
			$linenumber = $params["start"];
			$bytenumber = 0;
			$querylines = 0;
			$inparents = false;

			// Stay processing as long as the $linespersession is not reached or the query is still incomplete

			while ( (($bytenumber < $bytespersession) && ($linenumber < $params["start"] + $linespersession)) || ($query != "")) {

				// Read the whole next line
				$dumpline = "";
				while (!feof($file) && substr($dumpline, -1) != "\n" && substr($dumpline, -1) != "\r") {
					$dumpline .= fgets($file, DATA_CHUNK_LENGTH);
				}
				if ($dumpline === "")
					break;

				// Remove UTF8 Byte Order Mark at the file beginning if any
				if ($params["foffset"] == 0)
					$dumpline = preg_replace('|^\xEF\xBB\xBF|','',$dumpline);


				// Handle DOS and Mac encoded linebreaks (I don't know if it really works on Win32 or Mac Servers)
				$dumpline = str_replace("\r\n", "\n", $dumpline);
				$dumpline = str_replace("\r", "\n", $dumpline);

				// DIAGNOSTIC
				// echo ("<p>Line $linenumber: $dumpline</p>\n");

				// Skip comments and blank lines only if NOT in parents
				if (!$inparents) {
					$skipline=false;
					reset($comment);
					foreach ($comment as $comment_value) { 
						// DIAGNOSTIC
						//          echo ($comment_value);
						if (trim($dumpline)=="" || strpos (trim($dumpline), $comment_value) === 0) {
							$skipline=true;
							break;
						}
					}
					if ($skipline) {
						$linenumber++;
						$bytenumber += strlen($skipline);
						// DIAGNOSTIC
						// echo ("<p>Comment line skipped</p>\n");
						continue;
					}
				}

				// Remove double back-slashes from the dumpline prior to count the quotes ('\\' can only be within strings)

				$dumpline_deslashed = str_replace ("\\\\","",$dumpline);

				// Count ' and \' (or " and \") in the dumpline to avoid query break within a text field ending by $delimiter
				$parents = substr_count($dumpline_deslashed, $string_quotes) - substr_count($dumpline_deslashed, "\\$string_quotes");
				if ($parents % 2 != 0)
					$inparents=!$inparents;

				// Add the line to query
				$query .= $dumpline;

				// Don't count the line if in parents (text fields may include unlimited linebreaks)
				if (!$inparents)
					$querylines++;

				// Stop if query contains more lines as defined by MAX_QUERY_LINES
				if ($querylines > MAX_QUERY_LINES) {
					$result["maxlines"] = $querylines;
					$result["error"] = "true";
					$this->dbClose();
					fclose($file);
					return $result;
				}

				// Execute query if end of query detected ($delimiter as last character) AND NOT in parents

				// DIAGNOSTIC
				// echo ("<p>Regex: ".'/'.preg_quote($delimiter).'$/'."</p>\n");
				// echo ("<p>In Parents: ".($inparents?"true":"false")."</p>\n");
				// echo ("<p>Line: $dumpline</p>\n");
				if (preg_match('/'.preg_quote($delimiter).'$/',trim($dumpline)) && !$inparents) { 
					// Cut off delimiter of the end of the query
					$query = substr(trim($query),0,-1*strlen($delimiter));

					// DIAGNOSTIC
					// echo ("<p>Query: ".trim(nl2br(htmlentities($query)))."</p>\n");
					if (!$this->executeQuery($query)) {
						$result["query"] = $this->dbError();
						$result["lineno"] = $linenumber;
						$result["error"] = "true";
						$this->dbClose();
						fclose($file);
						return $result;
					}

					$totalqueries++;
					$queries++;
					$query = "";
					$querylines = 0;
				}

				$linenumber++;
				$bytenumber += strlen($dumpline);
			}

			// Get the current file position

			$foffset = ftell($file);
			if (!$foffset) {
				$result["ftell"] = "failed";
				$result["error"] = "true";
				$this->dbClose();
				fclose($file);
				return $result;
			}

			$result["foffset"] = $foffset;
			if ( ($bytenumber < $bytespersession) && ($linenumber < $params["start"] + $linespersession) ) {
				$result["success"] = "true";
			}
		}

		return $result;
	}

	public function dbValidation($dbname) {
		$result = array();

		if ($this->is_mysql === true) {
			$result['mysql'] = true;
		} else {
			$result['mysqli'] = true;
		}

		if ($this->conn) {
			$result["mysqlauth"] = "true";

			if ($this->selectDB($dbname)) {
				$result["mysqldb"] = "true";

				$res = $this->asArrayExecuteQuery("SHOW VARIABLES LIKE 'version'");
				$result["version"] = $res;

				$res = $this->asArrayExecuteQuery("SELECT @@max_allowed_packet");
				$result["max_allowed_packet"] = $res[0][0];

				$res = $this->asArrayExecuteQuery("SHOW ENGINES");
				$result["engines"] = $res;

				$res = $this->asArrayExecuteQuery("SHOW CHARACTER SET LIKE 'utf8mb4'");
				if (!empty($res)) {
					$result["utf8mb4"] = "true";
				}

				$res = $this->asArrayExecuteQuery("SHOW COLLATION LIKE '%utf8mb4_unicode_520_ci%'");
				if (!empty($res)) {
					$result["utf8mb4_unicode_520_ci"] = "true";
				}

				$res = $this->asArrayExecuteQuery("SHOW COLLATION LIKE '%utf8_unicode_520_ci%'");
				if (!empty($res)) {
					$result["utf8_unicode_520_ci"] = "true";
				}

				$res = $this->asArrayExecuteQuery("SHOW COLLATION LIKE '%utf8_general50_ci%'");
				if (!empty($res)) {
					$result["utf8_general50_ci"] = "true";
				}
			}

		} else {
			$result["dbconn"] = $this->dbError();
			$result["error"] = "true";
		}

		return $result;
	}

	public function myInstallTable($params) {
		$result = array();
		$filename = BVABSPATH . $params['ifile'];
		$dbport = "3306";

		$db_arr = explode(":", $params['dbhost']);
		$dbhost = $db_arr[0];
		if (sizeof($db_arr) > 1)
			$dbport = $db_arr[1];

		$dbhost = addslashes($dbhost);
		$dbname = addslashes($params['dbname']);
		$dbuser = addslashes($params['dbuser']);
		$dbpwd = $params['dbpwd'];

		if (file_exists($filename)) {
			$result["sql_file_size"] = filesize($filename);

			$result["mysql"] = shell_exec(
				"mysql --user='" . $dbuser . "' --password=" . $dbpwd . " --host='" .
				$dbhost . "' --port=" . $dbport . " '" . $dbname . "' < " . $filename . " 2>&1");
		} else {
			$result["file_not_found"] = "true";
		}

		return $result;
	}

	public function mysqlDump($params) {
		$result = array();

		$db_arr = explode(":", $params['dbhost']);
		$dbport = "3306";
		$dbhost = $db_arr[0];
		if (sizeof($db_arr) > 1)
			$dbport = $db_arr[1];

		$dbhost = addslashes($dbhost);
		$dbname = addslashes($params['dbname']);
		$dbuser = addslashes($params['dbuser']);
		$dbpwd = $params['dbpwd'];

		$tablename = $params["table"];
		$outputdir = $params["outdir"];

		$result["mysqldump"] = shell_exec(
			"mysqldump --single-transaction --user='".$dbuser."' --password='".$dbpwd."' --host='".
			$dbhost."' --port=".$dbport." '".$dbname."' ".$tablename." > ".$outputdir . $tablename.".sql"." 2>&1");

		return $result;
	}

	public function getTables($dbname) {
		$result = array();

		if ($this->selectDB($dbname)) {
			$resp = $this->asArrayExecuteQuery("SHOW TABLES;");
			foreach ($resp as $res) {
				$result["tables"][] = $res[0];
			}
		} else {
			$result["status"] = false;
			$result["error"] = "UNABLE_TO_SELECT_DB";
		}

		return $result;
	}

	public function dropTables($dbname, $tables) {
		$result = array();

		if ($this->selectDB($dbname)) {
			foreach ($tables as $table) {
				$result[$table] = $this->executeQuery("DROP TABLE IF EXISTS $table");
			}
		} else {
			$result["status"] = false;
			$result["error"] = "UNABLE_TO_SELECT_DB";
		}

		return $result;
	}

	public function renameTables($dbname, $tables) {
		$result = array();

		if ($this->selectDB($dbname)) {
			$query = implode(",", $tables);
			if (!$this->executeQuery("RENAME TABLES ".$query)) {
				$result["error"] = $this->dbError();
			} else {
				$result["status"] = true;
			}
		} else {
			$result["status"] = false;
			$result["error"] = "UNABLE_TO_SELECT_DB";
		}

		return $result;
	}

	public function updateUserRole($params) {
		$result = array();

		if ($this->selectDB($params['dbname'])) {

			$newtable = $params['newprefix'];
			$oldoption = $params['oldprefix'];
			$newoption = $params['newprefix'];
			if ($params['id']) {
				$newtable = $newtable.$params['id']."_";
				$oldoption = $oldoption.$params['id']."_";
				$newoption = $newoption.$params['id']."_";
			}
			$newtable = $newtable."options";
			$oldoption = $oldoption."user_roles";
			$newoption = $newoption."user_roles";

			$query = 'UPDATE '.$newtable.' SET option_name = "'.$newoption.'" WHERE option_name = "'.$oldoption.'"'; 

			if (!$this->executeQuery($query)) {
				$result["error"] = $this->dbError();
			} else {
				$result["status"] = true;
			}
		} else {
			$result["status"] = false;
			$result["error"] = "UNABLE_TO_SELECT_DB";
		}

		return $result;
	}

	public function updateUserMeta($params) {
		$result = array();

		if ($this->selectDB($params['dbname'])) {

			$oldprefix = $params['oldprefix'];
			$newprefix = $params['newprefix'];
			$newtable = $params['newprefix']."usermeta";
			$oldtable = $params['oldprefix']."usermeta";
			$keys = $params['keys'];

			foreach($keys as $key) {
				$oldkey = $oldprefix.$key;
				$newkey = $newprefix.$key;

				$query = 'UPDATE '.$newtable.' SET meta_key = "'.$newkey.'" WHERE meta_key = "'.$oldkey.'"';

				if (!$this->executeQuery($query)) {
					$result["error"] = $this->dbError();
					break;
				} else {
					$result[$key] = true;
				}

			}
		} else {
			$result["status"] = false;
			$result["error"] = "UNABLE_TO_SELECT_DB";
		}

		return $result;
	}
	
	public function generateRandomString($length = 128) {
		$characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$randomString = '';

		for ($i = 0; $i < $length; $i++) {
			$randomString .= $characters[rand(0, strlen($characters) - 1)];
		}

		return $randomString;
	}

	public function resetUserPass($params) {
		$result = array();

		if ($this->selectdb($params['dbname'])) {

			$prefix = $params['dbprefix'];
			$newpass = md5($this->generaterandomstring());

			$query = 'update '.$prefix.'users set user_pass = "'.$newpass.'"';

			if (!$this->executequery($query)) {
				$result["error"] = $this->dbError();
			} else {

				$query = 'update '.$prefix.'users set user_activation_key=""';

				if (!$this->executequery($query)) {
					$result["error"] = $this->dbError();
				} else {
					$result["status"] = true;
				}

			}

		} else {
			$result["status"] = false;
			$result["error"] = "unable_to_select_db";
		}

		return $result;
	}


	public function getDbVariables($dbname, $variable) {
		$result = array();

		if ($this->selectDB($dbname)) {
			$output = $this->executeQuery("Show variables like '%$variable%';");
			if ($output) {
				if ($this->is_mysql) {
					while ($row = mysql_fetch_object($output)) {
						$result[$row->Variable_name] = $row->Value;
					}
				} else {
					while ($row = mysqli_fetch_object($output)) {
						$result[$row->Variable_name] = $row->Value;
					}
				}
			}
		} else {
			$result["status"] = false;
			$result["error"] = "UNABLE_TO_SELECT_DB";
		}

		return $result;
	}

	public function getTablesStatus($dbname) {
		$result = array();

		if ($this->selectDB($dbname)) {
			$output = $this->executeQuery("SHOW TABLE STATUS;");
			if ($output) {
				if ($this->is_mysql) {
					while ($row = mysql_fetch_object($output)) {
						$result["tables"][] = $row;
					}
				} else {
					while ($row = mysqli_fetch_object($output)) {
						$result["tables"][] = $row;
					}
				}
			}
		} else {
			$result["status"] = false;
			$result["error"] = "UNABLE_TO_SELECT_DB";
		}

		return $result;
	}

	public function getCreateTableQueries($dbname, $tables) {
		$result = array();

		if ($this->selectDB($dbname)) {
			foreach ($tables as $table) {
				$result[$table] = $this->asArrayExecuteQuery("SHOW CREATE TABLE $table;");
			}
		} else {
			$result["status"] = false;
			$result["error"] = "UNABLE_TO_SELECT_DB";
		}

		return $result;
	}

	public function getTableContent($table, $fields = '*', $filter = '', $limit = 0, $offset = 0) {
		$rows = array();
		$query = "SELECT $fields from $table $filter";
		if ($limit > 0)
			$query .= " LIMIT $limit";
		if ($offset > 0)
			$query .= " OFFSET $offset";
		$output = $this->executeQuery($query);

		if ($output) {
			if ($this->is_mysql) {
				while ($row = mysql_fetch_object($output)) {
					$rows[] = $row;
				}
			} else {
				while ($row = mysqli_fetch_object($output)) {
					$rows[] = get_object_vars($row);
				}
			}
		}
		return $rows;
	}

	public function getTableData($dbname, $table, $offset, $limit, $bsize, $filter) {
		$result = array();

		if ($this->selectDB($dbname)) {
			$resp = $this->asArrayExecuteQuery("SELECT COUNT(*) FROM $table;");
			$rows_count = intval($resp[0][0]);
			$result['count'] = $rows_count;
			if ($limit == 0) {
				$limit = $rows_count;
			}
			$srows = 1;
			while (($limit > 0) && ($srows > 0)) {
				if ($bsize > $limit)
					$bsize = $limit;
				$rows = $this->getTableContent($table, '*', $filter, $bsize, $offset);
				$srows = sizeof($rows);
				$data = array();
				$data["table_name"] = $table;
				$data["offset"] = $offset;
				$data["size"] = $srows;
				$serialized_rows = serialize($rows);
				$data['md5'] = md5($serialized_rows);
				$data['length'] = strlen($serialized_rows);
				$data["rows"] = $rows;
				$str = serialize($data);
				$this->stream->writeStream($str);
				$offset += $srows;
				$limit -= $srows;
			}
			$result['size'] = $offset;
		} else {
			$result["status"] = false;
			$result["error"] = "UNABLE_TO_SELECT_DB";
		}

		return $result;
	}

	public function process($request) {
		$params = $request->params;
		$stream_init_info = BVGenericStream::startStream($this->account, $request);
		$this->conn = $this->connect($params["dbhost"], $params["dbuser"], $params["dbpwd"]);

		switch ($request->method) {
		case "dbval":
			$resp = $this->dbValidation($params["dbname"]);
			break;
		case "atodmp":
			$resp = $this->autoDump($params);
			break;
		case "myinstl":
			$resp = $this->myInstallTable($params);
			break;
		case "drptbls":
			$resp = $this->dropTables($params["dbname"], $params["tables"]);
			break;
		case "gttbls":
			$resp = $this->getTables($params["dbname"]);
			break;
		case "renmtbls":
			$resp = $this->renameTables($params["dbanme"], $params["tables"]);
			break;
		case "upusrrole":
			$resp = $this->updateUserRole($params);
			break;
		case "upusrmeta":
			$resp = $this->updateUserMeta($params);
			break;
		case "rstusrpass":
			$resp = $this->resetUserPass($params);
			break;
		case "mysqldmp":
			$resp = $this->mysqlDump($params);
			break;
		case "tblstatus":
			$resp = $this->getTablesStatus($params["dbname"]);
			break;
		case "gtdbvariables":
			$variable = (array_key_exists('variable', $params)) ? $params['variable'] : "";
			$resp = $this->getDbVariables($params["dbname"], $variable);
			break;
		case "getmlticrt":
			$resp = $this->getCreateTableQueries($params["dbname"], $params["tables"]);
			break;
		case "getmulttblsbackup":
			if (array_key_exists('stream', $stream_init_info)) {
				$this->stream = $stream_init_info['stream'];
				$result = array();
				$table_params = $params["table_params"];
				$resp = array();
				foreach($table_params as $table_param) {
					$tname = $table_param['tname'];
					$filter = (array_key_exists('filter', $table_param)) ? $table_param['filter'] : "";
					$limit = intval($table_param['limit']);
					$offset = intval($table_param['offset']);
					$bsize = intval($table_param['bsize']);
					$resp[$tname] = $this->getTableData($params["dbname"], $tname, $offset, $limit, $bsize, $filter);
				}
				$end_stream_info = $this->stream->endStream();
				if (!empty($end_stream_info) && is_array($resp)) {
					$resp = array_merge($resp, $end_stream_info);
				}
			} else {
				$resp = $stream_init_info;
			}
			break;
		default:
			$resp = false;
		}

		return $resp;
	}
}

class BVGenericMiscCallback extends BVGenericCallbackBase {
	public $account;

	public function isIIS() {
		if (!isset($_SERVER["SERVER_SOFTWARE"])) {
			return false;
		}

		$sSoftware = strtolower($_SERVER["SERVER_SOFTWARE"]);
		if (strpos($sSoftware, "iis") !== false) {
			return true;
		} else {
			return false;
		}
	}

	public function isValueInArray($needle, $haystack) {
		foreach ($haystack as $value) {
			if (trim($value) == $needle)
				return true;
		}
		return false;
	}

	public function phpValInfo() {
		$result = array();
		$serverip = urlencode($_SERVER['SERVER_ADDR']);
		$result['abspath'] = BVABSPATH;
		if (function_exists('getmyuid')) {
			$result['ftpuser'] = getmyuid();
		}
		if (function_exists('getmygid')) {
			$result['ftpgroup'] = getmygid();
		}
		$result['serverip'] = isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : null;
		$result['is_pressable'] = array_key_exists('IS_PRESSABLE', get_defined_constants());
		if (function_exists('posix_getuid')) {
			$result['webuser'] = posix_getuid();
			$result['webgroup'] = posix_getgid();
		} else {
			$tmpdir = BVABSPATH."bvtmp/";
			if (@mkdir($tmpdir, 0777)) {
				$tmpfile = $tmpdir."tfile";
				if (file_put_contents($tmpfile, "testing")) {
					$result['webuser'] = fileowner($tmpfile); 
					$result['webgroup'] = filegroup($tmpfile);
				}
				@unlink($tmpfile);
				@rmdir($tmpdir);
			}
		}
		if (ini_get('safe_mode')) {
			$result['safemode'] = "true";
		} else if (!$this->isIIS()) {
			$disabled = explode(',', ini_get('disable_functions'));

			if (!$this->isValueInArray('shell_exec', $disabled) && is_callable('shell_exec')) {
				if (strlen(`tar --help`)) {
					$result['tar'] = "true";
				}
				if (strlen(`mysql --help`)) {
					$result['mysqlcmd'] = "true";
				}
			}
		}
		if (class_exists('PharData')) {
			$result['phar'] = "true";
		}

		return $result;
	}

	public function getHostInfo() {
		$host_info = $_SERVER;
		$host_info['PHP_SERVER_NAME'] = php_uname('\n');
		if (array_key_exists('IS_PRESSABLE', get_defined_constants())) {
			$host_info['IS_PRESSABLE'] = true;
		}

		if (array_key_exists('GRIDPANE', get_defined_constants())) {
			$host_info['IS_GRIDPANE'] = true;
		}

		if (defined('WPE_APIKEY')) {
			$host_info['WPE_APIKEY'] = WPE_APIKEY;
		}

		if (defined('IS_ATOMIC')) {
			$host_info['IS_ATOMIC'] = IS_ATOMIC;
		}

		return $host_info;
	}

	public function getEnvVars($keys) {
		$result = array();
		foreach ($keys as $key) {
			if (array_key_exists($key, $_ENV))	{
				$result[$key] = $_ENV[$key];
			} else {
				$result[$key] = "NOT_FOUND";
			}
		}

		return $result;
	}

	public function getDBDetailsFromConstants() {
		$db_constants = ['DB_HOST', 'DB_NAME', 'DB_PASSWORD', 'DB_USER', 'DB_CHARSET', 'DB_COLLATE'];
		$db_details = array();
		foreach ($db_constants as $key) {
			if (defined($key)) {
				$db_details[$key] = constant($key);
			}
		}
		return $db_details;
	}

	public function shellExec($cmd) {
		$result = array();
		exec($cmd, $result);

		return $result;
	}

	public function execCmd($cmd) {
		$resp = array();

		if (function_exists('exec')) {
			$output = array();
			$retval = -1;
			$execRes = exec($cmd, $output, $retval);
			if ($execRes !== false && $execRes !== null) {
				$resp["content"] = implode("\n", $output);
				$resp["status"] = "success";
				$resp["code"] = $retval;
			}
		}
		if (empty($resp) && function_exists('popen')) {
			$handle = popen($cmd, 'rb');
			if ($handle) {
				$output = '';
				while (!feof($handle)) {
					$output .= fread($handle, 8192);
				}
				$resp["content"] = $output;
				$resp["status"] = "success";
				pclose($handle);
			} else {
				$resp["status"] = "failed";
			}
		}

		return $resp;
	}

	function downloadPlugin($plugin) {
		$handle = @fopen("https://downloads.wordpress.org/plugin/" . $plugin, 'r');

		if($handle !== false)
			$msg = @file_put_contents($plugin, $handle);
		if($handle === false || $msg === false)
			return false;
		else
			return true;
	}

	function unzipPlugin($plugin, $muplugindir) {
		$zip = new ZipArchive;

		if($zip->open($plugin) && $zip->extractTo($muplugindir) && $zip->close())
			return true;
		else
			return false;
	}

	public function installPantheonSessionsPlugin() {
		$muplugindir = BVABSPATH . "wp-content/mu-plugins/";
		$plugin = "wp-native-php-sessions.zip";
		$invoke_plugin_file = "wp-native-php-sessions.php";

		$result = array();

		$oldfile = BVABSPATH . $invoke_plugin_file;
		$newfile = $muplugindir . $invoke_plugin_file;

		if ($this->downloadPlugin($plugin) && $this->unzipPlugin($plugin, $muplugindir) && copy($oldfile, $newfile)) {
			$result['success'] = true;
		} else {
			$result['error'] = true;
		}

		return $result;
	}

	public function arrayContainsValue($params, $key, $value) {
		return isset($params[$key]) && in_array($value, $params[$key]);
	}

	public function clearVarnishCache($params) {
		$request_args = isset($params['request_args']) ? $params['request_args'] : null;
		$purge_url = isset($params['purge_url']) ? $params['purge_url'] : false;
		if ($request_args && $purge_url) {
			$remote_info = wp_remote_request($purge_url, $request_args);
			return $remote_info;
		}
		return "Unable to clear varnish cache due to invalid params";
	}

	public function rrmdir($dir) {
		if (is_dir($dir)) {
			$objects = scandir($dir);
			foreach ($objects as $object) {
				if ($object != "." && $object != "..") {
					if (is_dir($dir . "/" . $object) && !is_link($dir . "/" . $object)) {
						$this->rrmdir($dir . "/" . $object);
					} else {
						unlink($dir . "/" . $object);
					}
				}
			}
			return rmdir($dir);
		} else {
			return false;
		}
	}

	public function clearKinstaCacheUrl($url) {
		$url = trailingslashit($url) . 'kinsta-clear-cache/';
		return wp_remote_get($url, ['blocking' => false, 'timeout'  => 0.01]);
	}

	public function clearHostCacheUrl($purge_params, $url) {
		# XNOTE: need to handle for other hosts url as well
		if ($url) {
			$host_resp = array();
			switch ($purge_params['purge_host']) {
			case 'kinsta':
				$host_resp = $this->clearKinstaCacheUrl($url);
				break;
			default:
				$host_resp = $this->clearCompleteHostCache($purge_params);
			}
			return $host_resp;
		}
		return false;
	}

	public function clearCacheUrl($purge_url) {
		if (isset($purge_url)) {
			$parsed_url = parse_url($purge_url);
			$query = $parsed_url["query"];
			if (!empty($query)) {
				$query = "#" . $query;
			}
			if (!empty($parsed_url["path"])){
				return $this->rrmdir(WP_CONTENT_DIR . '/cache/airlift' . $parsed_url["host"] . $parsed_url["path"] . $query);
			}
		}
		return false;
	}

	public function clearPostsSpecificCache($purge_params) {
		$post_resp = array();
		if (isset($purge_params['clear_cached_post_ids']) && is_array($purge_params['clear_cached_post_ids'])) {
			$post_resp['post_ids_cache_purged'] = array();
			foreach ($purge_params['clear_cached_post_ids'] as $id) {
				$post_resp['post_ids_cache_purged'][$id] = array();
				$post_resp['post_ids_cache_purged'][$id][$id] = $this->clearCacheUrl(get_permalink($id));
				if (isset($purge_params['purge_host'])) {
					$post_resp['post_ids_cache_purged'][$id]['host_resp'] = $this->clearHostCacheUrl($purge_params, get_permalink($id));
				}
			}
		}
		if (isset($purge_params['clear_cached_urls']) && is_array($purge_params['clear_cached_urls'])) {
			$post_resp['urls_cache_purged'] = array();
			foreach ($purge_params['clear_cached_urls'] as $url) {
				$post_resp['urls_cache_purged'][$url] = array();
				$post_resp['post_ids_cache_purged'][$url][$url] = $this->clearCacheUrl($url);
				if (isset($purge_params['purge_host'])) {
					$post_resp['urls_cache_purged'][$url]['host_resp'] = $this->clearHostCacheUrl($purge_params, $url);
				}
			}
		}
		return $post_resp;
	}

	public function clear_batcache_cache() {
		global $batcache;
		$resp = false;
		if ( isset( $batcache ) && is_object( $batcache ) && method_exists( $batcache, 'flush' ) ) {
			if ( isset($_SERVER['HTTP_HOST']) ) {
				$resp = $batcache->flush( 'host', $_SERVER['HTTP_HOST'] );
			}
			if ( isset($batcache->key) && isset($batcache->group) ) {
				if ( $resp ) {
					$resp = $batcache->flush( $batcache->key, $batcache->group );
				}
			}
		}
		return $resp;
	}

	public function clear_siteground_dynamic_cache($hostname, $main_path, $url, $args, $request) {
		$host_resp = array();
		$site_tools_sock_file = '/chroot/tmp/site-tools.sock';

		if (!file_exists($site_tools_sock_file) || empty($args)) {
			$host_resp['cache_purge_status'] = false;
			$host_resp['site_tools_sock_file_found'] = false;
			return $host_resp;
		}
		$fp = stream_socket_client('unix://' . $site_tools_sock_file, $errno, $errstr, 5);
		if ( false === $fp ) {
			$host_resp['cache_purge_status'] = false;
			$host_resp['unix_socket_connection_success'] = false;
			return $host_resp;
		}

		$host_resp['unix_socket_connection_success'] = true;

		fwrite($fp, json_encode($request, JSON_FORCE_OBJECT) . "\n");

		$response = fgets($fp, 32 * 1024);

		fclose($fp);

		$result = @json_decode($response, true);

		if (false === $result || isset($result['err_code'])) {
			$host_resp['cache_purge_fail_resp'] = $result;
		}

		$host_resp['cache_purge_status'] = $result;

		return $host_resp;
	}

	public function clearCompleteHostCache($purge_params) {
		$host_resp = array();
		switch ($purge_params['purge_host']) {
		case 'cloudways':
		case 'flywheel':
		case 'dreamhost':
		case 'liquidweb':
			if (isset($purge_params['varnish_params'])) {
				$host_resp['varnish_cache'] = $this->clearVarnishCache($purge_params['varnish_params']);
			}
			break;
		case 'pagely':
			if (class_exists( 'PagelyCachePurge')) {
				$purger = new PagelyCachePurge();
				$purger->purgeAll();
				$host_resp['pagely_cache'] = true;
			} else {
				$host_resp['pagely_cache'] = false;
			}
			break;
		case 'kinsta':
			global $kinsta_cache;

			if (!empty($kinsta_cache->kinsta_cache_purge)) {
				$kinsta_cache->kinsta_cache_purge->purge_complete_caches();
				$host_resp['kinsta_cache'] = true;
			} else {
				$host_resp['kinsta_cache'] = false;
			}
			break;
		case 'savvii':
			do_action('warpdrive_domain_flush');
			$host_resp['savvii_cache'] = true;
			break;
		case 'wpengine':
			if (method_exists('WpeCommon', 'purge_varnish_cache')) {
				WpeCommon::purge_varnish_cache();
				$host_resp['wpengine_cache'] = true;
			} else {
				$host_resp['wpengine_cache'] = false;
			}
			break;
		case 'siteground':
			if (isset($purge_params['dynamic_cache_params'])) {
				$dyn_cache_params = $purge_params['dynamic_cache_params'];
				$hostname = $dyn_cache_params['hostname'];
				$main_path = $dyn_cache_params['main_path'];
				$url = $dyn_cache_params['url'];
				$args = $dyn_cache_params['args'];
				$request = $dyn_cache_params['request'];
				$host_resp['siteground_cache'] = $this->clear_siteground_dynamic_cache($hostname, $main_path, $url, $args, $request);
			}
			break;
		case 'pressable':
			$host_resp['pressable'] = $this->clear_batcache_cache();
			break;
		case 'automattic':
			$host_resp['automattic'] = $this->clear_batcache_cache();
			break;
		}
		return $host_resp;
	}

	public function __construct($callback_handler) {
		$this->account = $callback_handler->account;
	}

	public function process($request) {
		if (isset($request->params['loadwp']) && $request->params['loadwp'] === true) {
			require_once('./wp-load.php');
		}

		$params = $request->params;

		switch ($request->method) {
		case "dummyping":
			$resp = array();
			$resp = array_merge($resp, $this->account->info());
			break;
		case "phpval":
			$resp = $this->phpValInfo();
			break;
		case "gthost":
			$resp = array('host_info' => $this->getHostInfo());
			break;
		case "gtevnvars":
			$resp = $this->getEnvVars($params['keys']);
			break;
		case "gtdefnedconsts":
			$resp = get_defined_constants();
			break;
		case "gtdbdetlsfrmconsts":
			$resp = $this->getDBDetailsFromConstants();
			break;
		case "shlexec":
			$resp = $this->shellExec($params['cmd']);
			break;
		case "inpnthonsessnplgin":
			$resp = $this->installPantheonSessionsPlugin();
			break;
		case "exccmd":
			$resp = $this->execCmd($params['cmd']);
			break;
		case 'prge_cach':
			$resp = array();
			if (isset($params['purge_cache_params'])) {
				$purge_params = $params['purge_cache_params'];
			} else {
				$resp['invalid_params'] = true;
				break;
			}

			if (isset($purge_params['clear_cached_post_ids']) || isset($purge_params['clear_cached_urls'])) {
				$post_resp = $this->clearPostsSpecificCache($purge_params);
				if (!empty($post_resp)) {
					$resp['post_resp'] = $post_resp;
				}
			}

			if (isset($purge_params['purge_all']) && isset($purge_params['purge_host'])) {
				$host_resp = $this->clearCompleteHostCache($purge_params);
				if (!empty($host_resp)) {
					$resp['host_resp'] = $host_resp;
				}
			}

			if ($this->arrayContainsValue($purge_params, 'options', 'purge_object')) {
				if (function_exists('wp_cache_flush')) {
					$resp['object_cache_purged'] = wp_cache_flush();
				} else {
					$resp['object_cache_purged'] = false;
				}
			}

			if ($this->arrayContainsValue($purge_params, 'options', 'purge_complete_cache_storage')) {
				$resp['purge_complete_cache_storage'] = $this->rrmdir(WP_CONTENT_DIR . '/cache');
			}

			if ($this->arrayContainsValue($purge_params, 'options', 'purge_alcache')) {
				$resp['purge_alcache'] = $this->rrmdir(WP_CONTENT_DIR . '/cache/airlift');
			}
			break;
		default:
			$resp = false;
		}

		return $resp;
	}
}

class BVGenericSecurityCallback extends BVGenericCallbackBase {
	function getCrontab() {
		$resp = array();

		if (function_exists('exec')) {
			$output = array();
			$retval = -1;
			$execRes = exec('crontab -l', $output, $retval);
			if ($execRes !== false && $execRes !== null) {
				$resp["content"] = implode("\n", $output);
				$resp["status"] = "success";
				$resp["code"] = $retval;
			}
		}
		if (empty($resp) && function_exists('popen')) {
			$handle = popen('crontab -l', 'rb');
			if ($handle) {
				$output = '';
				while (!feof($handle)) {
					$output .= fread($handle, 8192);
				}
				$resp["content"] = $output;
				$resp["status"] = "success";
				pclose($handle);
			} else {
				$resp["status"] = "failed";
			}
		}

		return $resp;
	}

	function getProcessInfo() {
		$resp = array();

		if (function_exists('posix_getuid')) {
			$resp['uid'] = posix_getuid();
		}
		if (function_exists('posix_getgid')) {
			$resp['gid'] = posix_getgid();
		}
		if (function_exists('posix_getpwuid') && array_key_exists('uid', $resp)) {
			$resp['user'] = posix_getpwuid($resp['uid'])["name"];
		}
		if (function_exists('posix_getgrgid') && array_key_exists('gid', $resp)) {
			$resp['group'] = posix_getgrgid($resp['gid'])['name'];
		}

		return $resp;
	}

	function resetUserPass($args) {
		if (!function_exists('get_users') || !function_exists('reset_password') || !function_exists('wp_generate_password')) {
			return array("status" => false);
		}
		$users = get_users($args);

		foreach ($users as $user) {
			reset_password($user, wp_generate_password(32));
		}

		return array("users" => $users);
	}

	public function process($request) {
		if (isset($request->params['loadwp']) && $request->params['loadwp'] === true) {
			require_once('./wp-load.php');
		}

		$params = $request->params;

		switch ($request->method) {
		case "gtcrntb":
			$resp = $this->getCrontab();
			break;
		case "gtprcsinfo":
			$resp = $this->getProcessInfo();
			break;
		case "rstpwds":
			$resp = $this->resetUserPass($params['args']);
			break;
		case "gtusrscnt":
			if (!function_exists('count_users')) {
				$resp = array("status" => false);
			} else {
				$resp = array("user_count" => count_users());
			}
			break;
		default:
			$resp = false;
		}

		return $resp;
	}
}


/*
 * Execution starts here
 * */
$abspath = "";
##CHDIRABSPATH##

define('BVABSPATH', dirname(__FILE__) . '/' . $abspath);

$public_key_str = '-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAo9J7NpiHhYSoCufmleVw
j1Z5kLWj/MY2ogohUZZo8TbMM3+jo+iReGD+LabFWN7JVYIjxz78t0QWQ3RQIupd
o3jyfHa/i6nFB25pGNfOYD53RWRF8MD5qfDoBQg7NDFVVTviE0BMkCuQU8OqBpRa
iAlkSotLhU24pswiFfGs1P8tsRWRK5A8hqr2dfH9DdeRi7j2/olejqCkcV7PKrlv
g/4X1OTvnilUKtCtgJzH4MGgzFCucWnQMKMAl9JVXi82a+1WDjL2WMZeTVK0ySVc
86WZxclNt+zOhcK0n7eSM/HfFRfCDXwkKIegZb63KbNwfvieViVjDJKGo8vGK0TW
OwIDAQAB
-----END PUBLIC KEY-----
';

$bv_generic_conf = array(
	'public' => "35a410907db7c2e1a1fecc94b22e138c",
	'secret' => "e0fef5d7c89c1e90093e77b2a3692214"
);

if ((array_key_exists('bvreqmerge', $_POST)) || (array_key_exists('bvreqmerge', $_GET))) {
  $_REQUEST = array_merge($_GET, $_POST);
}

$pubkey = BVGenericAccount::sanitizeKey($_REQUEST['pubkey']);
$account = BVGenericAccount::find($bv_generic_conf, $pubkey);
$request = new BVGenericCallbackRequest($account, $_REQUEST, $bv_generic_conf);
$response = new BVGenericCallbackResponse($request->bvb64cksize);

if (1 === $request->authenticate()) {

	$params = $request->processParams($_REQUEST);
	if ($params === false) {
		$response->terminate($request->corruptedParamsResp());
	}

	$request->params = $params;
	$callback_handler = new BVGenericCallbackHandler($request, $account, $response);
	$callback_handler->execute();

} else {
	$response->terminate($request->authFailedResp());
}