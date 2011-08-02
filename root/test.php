<?php
define('IN_PHPBB', true);
$phpbb_root_path = (defined('PHPBB_ROOT_PATH')) ? PHPBB_ROOT_PATH : './';
$phpEx = substr(strrchr(__FILE__, '.'), 1);
include($phpbb_root_path . 'common.' . $phpEx);

if (!function_exists('svmods_mcurl')){
	function svmods_mcurl($urls){
		global $svmods_mcurl_urls;
		$results=array();
		if (function_exists('curl_multi_init')){
			$ch=array();
			for ($x=0;$x<sizeof($urls);$x++){
				$ch[$x]=curl_init();
				curl_setopt($ch[$x], CURLOPT_URL, $urls[$x]);
				curl_setopt($ch[$x], CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch[$x], CURLOPT_HEADER, false);
				@curl_setopt($ch[$x], CURLOPT_SSL_VERIFYPEER, false);
				@curl_setopt($ch[$x], CURLOPT_FOLLOWLOCATION, true);
				@curl_setopt($ch[$x], CURLOPT_MAXREDIRS, 5);

			}
			$mh=curl_multi_init();
			for ($x=0;$x<sizeof($ch);$x++){
				curl_multi_add_handle($mh,$ch[$x]);
			}
			$x=0; do {
				curl_multi_exec($mh,$x);
			} while($x>0);
						
			for ($x=0;$x<sizeof($ch);$x++){
				$results[$x]=curl_multi_getcontent($ch[$x]);
			}
			for ($x=0;$x<sizeof($ch);$x++){
				curl_multi_remove_handle($mh, $ch[$x]);
			}
			curl_multi_close($mh);
		}
		else{
			for ($x=0;$x<sizeof($urls);$x++){
				$results[$x]=file_get_contents($urls[$x]);
			}
		}
		return $results;
	}
}



if(!function_exists('json_decode')){
	if (@include('./includes/JSON.php')){
		function json_decode($data){
			$json=new Services_JSON();
			return($json->decode($data));
		}
	}
}

$error=array();
if (!function_exists('json_decode')){
	$error[]='The json_decode function is not available.<br />Upload the file JSON.php to your includes folder.';
}
$temp=svmods_mcurl(array('https://graph.facebook.com/me/'));
$check=json_decode($temp[0]);
if (!isset($check->error->type)){
	$error[]='Your server failed to retrieve data from facebook.<br />It appears incompatible with this hook/mod. If you have already uploaded the hook_svmods_facebook_connect.php file, please delete it and purge your cache from the ACP.';
	set_config('svmods_facebook_compat', 1, false);
}

$message=NULL;
if (sizeof($error)>0){
	for ($x=0;$x<sizeof($error);$x++){
		$message.=$error[$x].'<br /><br />';
	}
}
else {
	$message='Congratulations!<br />It looks like your server should work fine with this hook.<br />You may proceed with installation.';
	set_config('svmods_facebook_compat', 2, false);
}

trigger_error($message);