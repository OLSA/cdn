<?php

require_once __DIR__ . '/vendor/autoload.php';
use League\Flysystem\Local\LocalFilesystemAdapter as Adapter;
use League\Flysystem\Filesystem;

class Request {

	private $source_root;
	public $source;

	private $thumbs_root;
	private $thumbs;

	public $path;


	public function __construct($path) {

		$url_parts = parse_url($path);
		$this->path = $url_parts['path'];

		$this->source_root = '/opt/redmine/files';
		$adapter = new Adapter($this->source_root);
		$this->source = new Filesystem($adapter);

		$this->thumbs_root = 'thumbs';
		$adapter = new Adapter($this->thumbs_root);
		$this->thumbs = new Filesystem($adapter);
	}

	public function getFile($filesystem, $path)	{
		$response = array();
		if ($filesystem->fileExists($path)) {
			$response['content'] = $filesystem->read($path);
			$response['last_modified'] = $filesystem->lastModified($path);
			$response['etag'] = '"' . md5($path) . '"';
			$response['mime_type'] = $filesystem->mimeType($path);
			$response['file_size'] = $filesystem->fileSize($path);
			$response['file_name'] = basename($path);
		} else {
			$response['error'] = true;
			$response['status'] = 404;
		}
		return $response;
	}


	public function getThumbnail($size)	{

		$response = array();
		$image = $this->path;

		// check if file exists (validation)
		if (!$this->source->fileExists($image) || !$this->is_image($this->source, $image)) {
			$response['error'] = true;
			$response['status'] = 404;
			return $response;
		}

		// thumbnail size
		$thumb_width = 0;
		$thumb_height = 0;

		// build thumbnail path
		$pathinfo = pathinfo($image);
		$thumb_path = $pathinfo['dirname'] . '/' . $pathinfo['filename'];//relative to 'thumbs/...'

		$dim = explode(',', $size);
		if (isset($dim[0])) {
			if (ctype_digit($dim[0])) {
				$thumb_width = $dim[0];
				$thumb_path .= '_' . $thumb_width;
			}
		}

		if (isset($dim[1])) {
			if (ctype_digit($dim[1])) {
				$thumb_height = $dim[1];
				$thumb_path .= '_' . $thumb_height;
			}
		}

		// full thumbnail path
		$thumb_path .= '.' . $pathinfo['extension'];

		// check if we have already created thumbnail
		if ($this->thumbs->fileExists($thumb_path)) {
			return $this->getFile($this->thumbs, $thumb_path);
		}

		// create new thumbnail
		$resize = $this->resizeImage(
			array(
				'image' => $this->source->read($image),
				'mime_type' => $this->source->mimeType($image),
				'new_width' => $thumb_width,
				'new_height' => $thumb_height,
				'thumb_path' => $thumb_path
			)
		);

		if ($resize) {
			$response = $this->getFile($this->thumbs, $thumb_path);
		} else {
			$response['error'] = true;
		}

		return $response;
	}


	private function resizeImage($params = array())	{

		// get original width and height
		$image = imagecreatefromstring($params['image']);
		$width = imagesx($image);
		$height = imagesy($image);

		$new_width = $params['new_width'];
		$new_height = $params['new_height'];

		// generate new w/h if not provided
		if ($new_width && !$new_height) {
			$new_height = $height * ($new_width / $width);
		} elseif ($new_height && !$new_width) {
			$new_width = $width * ($new_height / $height);
		} elseif (!$new_width && !$new_height) {
			$new_width = $width;
			$new_height = $height;
		}

		// create a new true color image
		$canvas = imagecreatetruecolor($new_width, $new_height);
		$src_x = $src_y = 0;
		$src_w = $width;
		$src_h = $height;

		$cmp_x = $width / $new_width;
		$cmp_y = $height / $new_height;

		// calculate x or y coordinate and width or height of source
		if ($cmp_x > $cmp_y) {
			$src_w = round(($width / $cmp_x * $cmp_y));
			$src_x = round(($width - ($width / $cmp_x * $cmp_y)) / 2);
		} elseif ($cmp_y > $cmp_x) {
			$src_h = round(($height / $cmp_y * $cmp_x));
			$src_y = round(($height - ($height / $cmp_y * $cmp_x)) / 2);
		}

		imagecopyresampled($canvas, $image, 0, 0, $src_x, $src_y, $new_width, $new_height, $src_w, $src_h);
		$img_str = $this->imgToStr($canvas, $params['mime_type']);

		// free memory
		ImageDestroy($canvas);

		// write file
		$result = true;
		try {
			$this->thumbs->write($params['thumb_path'], $img_str);
		} catch (FilesystemException | UnableToWriteFile $exception) {
			$result = false;
			$this->logger("Error creating file: " . $params['thumb_path']);
		}
		return $result;
	}

	//convert image object to string
	private function imgToStr($img, $mime_type)	{
		$stream = fopen('php://memory', 'r+');
		switch ($mime_type) {
			case 'image/jpg':
			case 'image/jpeg':
				imagejpeg($img, $stream);
				break;
			case 'image/png':
				imagepng($img, $stream);
				break;
			case 'image/gif':
				imagegif($img, $stream);
				break;
		}
		rewind($stream);
		$str = stream_get_contents($stream);
		return $str;
	}

	// helper
	private function is_image($source, $file){
		$images = array(
			'image/jpeg',
			'image/jpg',
			'image/png',
			'image/gif'
		);
		return in_array($source->mimeType($file), $images);
	}

	// logger
	public function logger($logMsg)	{

		if (is_array($logMsg) || is_object($logMsg)) {
			$msg = json_encode($logMsg);
		} else {
			$msg = $logMsg;
		}

		if ($fh = @fopen(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'log.txt', "a+")) {
			$msg = "[" . date('d-m-Y H:i:s') . "] " . $msg . "\r\n";
			fputs($fh, $msg, strlen($msg));
			fclose($fh);
			return true;
		}
	}

}


if (isset($_SERVER['REQUEST_URI'])) {

	$path = $_SERVER['REQUEST_URI'];
	$request = new Request($path);

	if (isset($_GET['size']) && $_GET['size'] !== "") {
		$response = $request->getThumbnail($_GET['size']);
	} else {
		$response = $request->getFile($request->source, $request->path);
	}


	if (!isset($response['error'])) {

		$cache_time = 86400;//604800

		header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $response['last_modified']) . ' GMT');
		header('Etag: ' . $response['etag']);

	    header('Pragma: public');
		header("Cache-Control: public, max-age=$cache_time");
		header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $cache_time) . ' GMT');
		header('Content-type: ' . $response['mime_type']);
		echo $response['content'];
	} else {
		$status = isset($response['status']) ? $response['status'] : 404;
		http_response_code($status);
		die("ERROR: $status");
	}
}
