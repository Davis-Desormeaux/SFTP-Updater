<?php
/*
 *                   GNU GENERAL PUBLIC LICENSE
 *                     Version 3, 29 June 2007
 *
 * Copyright (c) 2012 Davis Desormeaux
 * More: https://github.com/Davis-Desormeaux
 *
 */

$PROJECT_DIR = '/home/mazlok/workspace';
$LOCAL_TZONE = 'America/Montreal';
$LAST_UPDATE = null;
$HOST = 'host.com';
$USER = 'username';
$PASS = 'password';

/**
* Set $LAST_UPDATE to the last time this script was updated.
* If the lastupdate file doesn't exist, create it.
*/
function initLastUpdate(){
  global $LAST_UPDATE, $LOCAL_TZONE;
  if (file_exists('./lastupdate')) {
    $LAST_UPDATE = trim(file_get_contents('./lastupdate'));
    if (!is_numeric($LAST_UPDATE)) {
      unlink('./lastupdate');
      initLastUpdate();
    }
  } else {
    $now = time();
    file_put_contents('./lastupdate', $now, LOCK_EX);
    echo 'First run!' . PHP_EOL;
    date_default_timezone_set($LOCAL_TZONE);
    echo 'Files changed since ' . date ("F d Y H:i:s", $now) . ' will ';
    echo 'be send to sftp.' . PHP_EOL;
    exit();
  }
}

/**
* @param  string  $pattern  glob pattern to match
* @param  int     $flags    glob flags
* @param  string  path      directory to update
* @param  int     depth     recursion depth -1 for unlimited, 0 for current directory
*
* @return array
*/
function getFiles($pattern = '*', $flags = 0, $path = false, $depth = 0, $level = 0) {
  $ftree = array();
  $files = glob($path.$pattern, $flags);
  $paths = glob($path.'*', GLOB_ONLYDIR|GLOB_NOSORT);

  if (!empty($paths) && ($level < $depth || $depth == -1)) {
    $level++;
    foreach ($paths as $sub_path) {
      $ftree = array_merge(
          $ftree,
          getFiles($pattern, $flags, $sub_path.DIRECTORY_SEPARATOR, $depth, $level)
      );
    }
  }
  $toUpdate = array_merge($ftree, $files);

  array_walk($toUpdate, function($val,$key) use(&$toUpdate){
    global $LAST_UPDATE;
    if (filemtime($val) - $LAST_UPDATE < 0) {
      unset($toUpdate[$key]);
    }
  });

  return $toUpdate;
}

/**
* @param  string  $locFile
* @param  string  $destFoder
* @param  string  $user
* @param  string  $pass
* @param  string  $host
* @param  bool    $silent Optional
*/
function sendToSFTP($locFile, $user, $pass, $host, $destFoder = '/', $silent = false) {
  $ch = curl_init();
  $fp = fopen($locFile, 'r');

  // curl_setopt($ch, CURLOPT_URL, 'sftp://'.$user.':'.$pass.'@'.$host.$locFile);
  curl_setopt($ch, CURLOPT_URL, 'sftp://'.$host.$destFoder);
  curl_setopt($ch, CURLOPT_USERPWD, $user.':'.$pass);
  curl_setopt($ch, CURLOPT_UPLOAD, 1);
  curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_SFTP);
  curl_setopt($ch, CURLOPT_INFILE, $fp);
  curl_setopt($ch, CURLOPT_INFILESIZE, filesize($locFile));
  curl_exec ($ch);
  $error_no = curl_errno($ch);
  curl_close ($ch);

  $msg = ($error_no == 0) ? 'OK: '.$locFile.' Sent ' : 'Error: ' . curl_error($ch);
  if (!silent) echo $msg . PHP_EOL;
}

function update() {
  global $USER, $PASS, $HOST, $PROJECT_DIR;
  // Get last updated stamp.
  initLastUpdate();
  // Get a the list of file that where modified since last update.
  $files = getFiles('*', 0, $PROJECT_DIR, -1);
  // Send the files to SFTP server
  foreach ($files as $fileToUpdate) {
    if (is_dir($fileToUpdate)) continue;
    $remoteToFile = str_replace(DIRECTORY_SEPARATOR, '/', $fileToUpdate);
    $remoteToFile = substr($remoteToFile, strlen($PROJECT_DIR)+1);
    $remoteFolder = '/';

    if ($dirPos = strrpos($remoteToFile, '/')) {
      $remoteFolder = '/' . substr($remoteToFile, 0, $dirPos + 1);
    }
    sendToSFTP($fileToUpdate, $USER, $PASS, $HOST, $remoteFolder);
  }
  // Update the timestamp.
  file_put_contents('./lastupdate', time(), LOCK_EX);
}

update();