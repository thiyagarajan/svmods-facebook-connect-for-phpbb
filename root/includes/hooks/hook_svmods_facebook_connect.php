<?php

if (!function_exists('svmods_default_lang')){
	function svmods_default_lang($key,$value){
		global $user;
		if (empty($user->lang[$key])){
			$user->lang[$key]=$value;
		}
		return $user->lang[$key];
	}
}

function svmods_facebook_avatar_url(){
	global $config, $fb_avatar_url, $server_url;
	if (!isset($server_url)){
		$server_url=generate_board_url();
	}
	if (!isset($fb_avatar_url)){
		if ($config['svmods_facebook_use_remote_redirect']=='r'){
			$fb_avatar_url='http://fbuserpic.svmods.com/';
		}
		else {
			$fb_avatar_url=$server_url.'/facebookuserimage';
		}
	}
	return $fb_avatar_url;
}

function svmods_get_facebook_cookie(){
	global $user, $config, $svmods_facebook_data;
	$svmods_facebook_data=array();
	if (isset($_COOKIE['fbs_'.$config['svmods_facebook_app_id']])){
		$args=array();
		parse_str(trim($_COOKIE['fbs_'.$config['svmods_facebook_app_id']], '\\"'), $args);
		ksort($args);
		$payload='';
		foreach ($args as $key => $value){
			if ($key!='sig'){
				$payload.=$key.'='.$value;
			}
		}
		if (md5($payload.$config['svmods_facebook_secret'])===$args['sig']){
			$user->data['svmods_facebook_data']['cookie']=$args;
			$user->data['is_facebook_user']=$args['uid'];
			return true;
		}
	}
	return false;
}

function svmods_set_facebook_data($n=NULL, $id='me'){
	global $user, $svmods_mcurl_urls, $svmods_set_returns;
	if (!is_array($svmods_mcurl_urls)) $svmods_mcurl_urls=array();
	if (!is_array($svmods_set_returns)) $svmods_set_returns=array();
	$svmods_mcurl_urls[]='https://graph.facebook.com/'.$id.'/'.$n.'?access_token='.$user->data['svmods_facebook_data']['cookie']['access_token'];
	if (empty($n)) $n='user';
	$svmods_set_returns[]=$n;
}

function svmods_get_facebook_data($return=array()){
	global $svmods_mcurl_urls, $svmods_set_returns, $phpbb_root_path;
	if (is_array($svmods_mcurl_urls)){
		$results=svmods_mcurl($svmods_mcurl_urls);
		if(!function_exists('json_decode')){
			require_once($phpbb_root_path.'includes/JSON.php');
			function json_decode($data){
				$json=new Services_JSON();
				return($json->decode($data));
			}
		}
		for ($x=0;$x<sizeof($svmods_set_returns);$x++){
			$return[$svmods_set_returns[$x]]=array();
			$return[$svmods_set_returns[$x]]['json']=$results[$x];
			$return[$svmods_set_returns[$x]]['decoded']=json_decode($results[$x]);
		}
		// adjust timezone in dst. facebook returns a pre adjusted value!
		if (!empty($return['user']['decoded']->timezone)){
			$dst=localtime(time(), true);
			if ($dst['tm_isdst']){
				$return['user']['decoded']->timezone--;
			}
		}
	}
	return $return;
}

function svmods_create_fb_association($sql_ary){
	global $db;
	// stripped custom table, leaving array imput for change later
	$db->sql_query('UPDATE '.USERS_TABLE ." SET user_svmods_fb_uid='".$sql_ary['user_svmods_fb_uid']."' WHERE user_svmods_fb_uid<1 AND user_id=".$sql_ary['user_id']);
}

function svmods_revoke_facebook_authorization($uid,$token){
	global $db;
	$urls=array('https://api.facebook.com/method/auth.revokeAuthorization?uid='.$uid.'&access_token='.$token);
	svmods_mcurl($urls);
	$db->sql_query('UPDATE '.USERS_TABLE ." SET user_svmods_fb_uid=0 WHERE user_svmods_fb_uid=".$uid);
}

if (!function_exists('svmods_mcurl')){
	function svmods_mcurl($urls){
		global $svmods_mcurl_urls;
		$results=array();
		if (function_exists('curl_init')){
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
				$results[$x]=@file_get_contents($urls[$x]);
			}
		}
		return $results;
	}
}


function svmods_facebook_connect_template_hook(&$hook){
	global $db, $config, $user, $template, $phpEx, $phpbb_root_path, $cache;
	if (defined('IN_ADMIN')){
		// temp reset/fix addition
		if ($config['svmods_facebook_uid_reset']!=1){
			if (array_key_exists('user_svmods_fb_uid', $user->data)){
				$db->sql_query('UPDATE '.USERS_TABLE.' SET user_svmods_fb_uid=0');
			}
			set_config('svmods_facebook_uid_reset', 1, false);
			// smash admins cookie!
			if (isset($_COOKIE['fbs_'.$config['svmods_facebook_app_id']])){
				setcookie('fbs_'.$config['svmods_facebook_app_id'],NULL,time(),'/');
				unset($_COOKIE['fbs_'.$config['svmods_facebook_app_id']]);
			}
		}
		if ($config['svmods_facebook_compat']<1){
			// 2 is compatible, 1 is not, 0 is untested.
			$temp=svmods_mcurl(array('https://graph.facebook.com/me/'));
			$check=json_decode($temp[0]);
			if (isset($check->error->type)){
				set_config('svmods_facebook_compat', 2, false);
			}
			else {
				set_config('svmods_facebook_compat', 1, false);
				$message=svmods_default_lang('SVMODS_FACEBOOK_COMPAT',"There appears to be an issue with the svmods Facebook connect hook: the server has failed to retrieve data from Facebook, likely due to restrictions in the server settings. You may delete the hook file and purge the cache from the ACP.<br /><br />If you wish to reattempt installation later, you may run the test.php file that came with this hook, or you will need to delete the svmods_facebook_compat value from your config table and purge the cache again.");
				trigger_error($message);
			}
		}
		// lets just get out now if not compat
		if ($config['svmods_facebook_compat']!=2){
			return NULL;
		}
		if (isset($GLOBALS['_REQUEST']['config']['svmods_facebook_app_id'])){
			$temp=$GLOBALS['_REQUEST']['config']['svmods_facebook_app_id'];
			if ((!empty($temp)) && ($temp!=$config['svmods_facebook_app_id'])){
				set_config('svmods_facebook_app_id', $temp, false);
			}
		}
		else if (!isset($config['svmods_facebook_app_id'])){
			set_config('svmods_facebook_app_id', '', false);
		}
		if (isset($GLOBALS['_REQUEST']['config']['svmods_facebook_secret'])){
			$temp=$GLOBALS['_REQUEST']['config']['svmods_facebook_secret'];
			if ((!empty($temp)) && ($temp!=$config['svmods_facebook_secret'])){
				set_config('svmods_facebook_secret', $temp, false);
			}
		}
		else if (!isset($config['svmods_facebook_secret'])){
			set_config('svmods_facebook_secret', '', false);
		}
		if (isset($GLOBALS['_REQUEST']['config']['svmods_facebook_use_remote_redirect'])){
			$temp=$GLOBALS['_REQUEST']['config']['svmods_facebook_use_remote_redirect'];
			if ($temp!='r') $temp='l';
			if ($temp!=$config['svmods_facebook_use_remote_redirect']){
				set_config('svmods_facebook_use_remote_redirect', $temp, false);
			}
		}
		else if (!isset($config['svmods_facebook_use_remote_redirect'])){
			set_config('svmods_facebook_use_remote_redirect', 'l', false);
		}
		if (!array_key_exists('user_svmods_fb_uid', $user->data)){
			require_once($phpbb_root_path.'includes/db/db_tools.'.$phpEx);
			$tools=new phpbb_db_tools($db);
			$tools->sql_column_add(USERS_TABLE, 'user_svmods_fb_uid', array('BINT',0));
		}

		if (!isset($template->_tpldata['options'])) $template->_tpldata['options']=NULL; // temp fix for debug warning
		if ((is_array($template->_tpldata['options'])) && (request_var('i', '')==='board') && (request_var('mode', '')==='settings')){
			// loop through template to find our insert spot...
			for ($x=0;$x<sizeof($template->_tpldata['options']);$x++){
				if ((isset($template->_tpldata['options'][$x]['KEY'])) && ($template->_tpldata['options'][$x]['KEY']==='warnings_expire_days')){
					$t=$x-1;	// drop back & mark the spot
					$x=999;	// kill off loop
				}
			}
			if ($config['svmods_facebook_use_remote_redirect']=='r'){
				$check="checked";
			}
			$temp=array_chunk($template->_tpldata['options'], $t, false);
			$temp[0][]=array(
						'S_LEGEND'	=> 1,
						'LEGEND'	=> 'Svmods Facebook Settings',
						'S_ROW_COUNT' => $t++
					);
			$temp[0][]=array(
						'KEY'	=> 'svmods_facebook_app_id',
						'TITLE' => 'Facebook application ID',
						'S_EXPLAIN'	=> 1,
						'TITLE_EXPLAIN' => 'Your registered Facebook application ID.',
						'CONTENT'	=> '<input id="svmods_facebook_app_id" type="text" size="20" name="config[svmods_facebook_app_id]" value="'.$config['svmods_facebook_app_id'].'" />',
						'S_ROW_COUNT' => $t++
					);
			$temp[0][]=array(
						'KEY'	=> 'svmods_facebook_secret',
						'TITLE' => 'Facebook application secret',
						'S_EXPLAIN'	=> 1,
						'TITLE_EXPLAIN' => 'Your registered Facebook application secret.',
						'CONTENT'	=> '<input id="svmods_facebook_secret" type="text" size="20" name="config[svmods_facebook_secret]" value="'.$config['svmods_facebook_secret'].'" />',
						'S_ROW_COUNT' => $t++
					);
			$temp[0][]=array(
						'KEY'	=> 'svmods_facebook_use_remote_redirect',
						'TITLE' => 'Use remote redirect for Facebook avatars?',
						'S_EXPLAIN'	=> 1,
						'TITLE_EXPLAIN' => 'Check here to use the svmods remote redirect script for users Facebook images as avatars. It\'s recommended that you implement your own htaccess redirect. See the <a href="http://svmods.com" target="_blank">svmods.com</a> site for more information.',
						'CONTENT'	=> '<input id="svmods_facebook_use_remote_redirect" type="checkbox" name="config[svmods_facebook_use_remote_redirect]" value="r" '.$check.' />',
						'S_ROW_COUNT' => $t++
					);
			// bring them back together...
			$template->_tpldata['options']=array_merge($temp[0],$temp[1]);
			// fix the row count values
			for ($x=$t;$x<sizeof($template->_tpldata['options']);$x++){
				$template->_tpldata['options'][$x]['S_ROW_COUNT']=$t++;
			}
		}
	}
	if ((!empty($config['svmods_facebook_app_id'])) && (!empty($config['svmods_facebook_secret'])) && ($config['svmods_facebook_compat']==2)){
		$template->assign_var('SVMODS_FACEBOOK_APP_ID', $config['svmods_facebook_app_id']);
		$template->assign_var('SVMODS_FACEBOOK_XMLNS', 'xmlns:fb="http://www.facebook.com/2008/fbml"');
		$template->assign_var('TRANSLATION_INFO', "<a href='http://svmods.com' title='phpBB modifications and templates'>Facebook graph/connect modifications</a> by svmods.<br />".$template->_rootref['TRANSLATION_INFO']);
		$template->assign_var('SVMODS_FACEBOOK_UID', $user->data['user_svmods_fb_uid']);
		$url=str_replace('mode=logout', 'mode=login', $_SERVER['REQUEST_URI']);
		$delim=(strpos($url,'?') === false) ? '?' : '&';
		$url=(strpos($url, 'svmods_check_cookie') ===false) ? $url.$delim.'svmods_check_cookie=fb' : $url;
		$template->assign_var('SVMODS_FACEBOOK_JS', "<div id='fb-root'></div><script src='http://connect.facebook.net/en_US/all.js'></script><script>FB.init({appId: '".$config['svmods_facebook_app_id']."', status: false, cookie: true, xfbml: true}); FB.Event.subscribe('auth.login', function(response){window.location.replace('".$url."');});</script>");
		$template->assign_var('SVMODS_FACEBOOK_INT',svmods_default_lang('SVMODS_FACEBOOK_INT','Facebook Integration'));
		$template->assign_var('SVMODS_FACEBOOK_INT_EXPLAIN',svmods_default_lang('SVMODS_FACEBOOK_INT_EXPLAIN','Grant/revoke Facebook application authorization.'));
		$template->assign_var('SVMODS_FACEBOOK_REVOKE',svmods_default_lang('SVMODS_FACEBOOK_REVOKE','Revoke Authorization'));
		if (($user->data['user_svmods_fb_uid']) && ($config['allow_avatar_remote'])){
			 if (request_var('mode','')==='avatar'){
				 $template->assign_var('SVMODS_USE_FB_PIC', svmods_default_lang('SVMODS_USE_FB_PIC', 'Use Facebook image'));
				 $template->assign_var('SVMODS_USE_FB_PIC_EXPLIN', svmods_default_lang('SVMODS_USE_FB_PIC_EXPLAIN', 'Check to use your Facebook profile image as your avatar.'));
			 }
		}
		if (!defined('ADMIN_START')){
			$perms='perms="email, user_birthday, user_interests, user_likes, user_location, user_website, publish_stream"';
			if ($user->data['user_id']!=ANONYMOUS){
				$template->assign_var('SVMODS_FACEBOOK_LOGIN_BUTTON', '<fb:login-button size="medium" '.$perms.'>'.svmods_default_lang('SVMODS_FACEBOOK_CONNECT_BUTTON_TEXT', 'Connect your Facebook account').'</fb:login-button>');
			}
			else{
				$template->assign_var('SVMODS_FACEBOOK_LOGIN_BUTTON', '<fb:login-button size="medium" '.$perms.'>'.svmods_default_lang('SVMODS_FACEBOOK_LOGIN_BUTTON_TEXT', 'Login with Facebook!').'</fb:login-button>');
				$template->assign_var('SVMODS_FACEBOOK_REGISTER_BUTTON', '<fb:login-button size="large" '.$perms.'>'.svmods_default_lang('SVMODS_FACEBOOK_REGISTER_BUTTON_TEXT', 'Register with your Facebook account!').'</fb:login-button>');
			}
		}
	}
}

function svmods_facebook_connect_user_hook(&$hook){
	global $config, $cache, $user, $auth, $db, $svmods_facebook_data, $template, $phpbb_root_path, $phpEx;
	if (($user->data['is_bot']) || ($config['board_disable'])){
		$config['svmods_facebook_app_id']=NULL;
		return NULL;
	}
	if ((!empty($config['svmods_facebook_app_id'])) && (!empty($config['svmods_facebook_secret'])) && ($config['svmods_facebook_compat']==2)){
		if (request_var('mode','')==='avatar'){
			$temp=request_var('svmods_use_fb_pic', '');
			if ($temp){
				$GLOBALS['_REQUEST']['remotelink']=svmods_facebook_avatar_url().$user->data['user_svmods_fb_uid'].'.jpg';
				$GLOBALS['_REQUEST']['width']=50;
				$GLOBALS['_REQUEST']['height']=50;
			}
		}
		else if (request_var('mode','')==='logout'){
			if (isset($_COOKIE['fbs_'.$config['svmods_facebook_app_id']])){
				setcookie('fbs_'.$config['svmods_facebook_app_id'],NULL,time(),'/');
				unset($_COOKIE['fbs_'.$config['svmods_facebook_app_id']]); 
			}
		}
		$temp=svmods_get_facebook_cookie();
		if ((request_var('svmods_check_cookie','')==='fb') && (!$temp)){
			setcookie('fbs_'.$config['svmods_facebook_app_id'],NULL,time(),'/');
			$message=svmods_default_lang('SVMODS_FB_LOGIN_COOKIE_ERROR', "An error appears to have occurred with your browser. If you wish to use the sites Facebook logins or registration features, please update/repair your browser, or use a different browser.<br /><br />We applogize for the inconvenience.").'<br /><br />'.sprintf($user->lang['RETURN_INDEX'], '<a href="'.append_sid("{$phpbb_root_path}index.$phpEx").'">', '</a>');
			trigger_error($message);
		}
		else if (request_var('svmods_fb_cancel','')===$user->lang['CANCEL']){
			svmods_revoke_facebook_authorization($user->data['svmods_facebook_data']['cookie']['uid'],$user->data['svmods_facebook_data']['cookie']['access_token']);
			setcookie('fbs_'.$config['svmods_facebook_app_id'],NULL,time(),'/');
			unset($_COOKIE['fbs_'.$config['svmods_facebook_app_id']]);
			$GLOBALS['_REQUEST']['password']=NULL;
			$temp=false;
		}
		else if ($user->data['user_svmods_fb_uid']>0){
			// we're already associated with an fb account
			if (request_var('svmods_revoke_fb',false)){
				setcookie('fbs_'.$config['svmods_facebook_app_id'],NULL,time(),'/');
				unset($_COOKIE['fbs_'.$config['svmods_facebook_app_id']]);
				// clicked revoke auth button, give it a try if an access token is available.
				if (!empty($user->data['svmods_facebook_data']['cookie']['access_token'])){
					svmods_revoke_facebook_authorization($user->data['svmods_facebook_data']['cookie']['uid'],$user->data['svmods_facebook_data']['cookie']['access_token']);
					$message=svmods_default_lang('SVMODS_FACEBOOK_REVOKED','The request to revoke application authorization was sent to Facebook. You can also check your application settings within Facebook itself to ensure it was successful.');
				}
				else{
					// no token avail. just tell em to go to their fb app settings.
					$message=svmods_default_lang('SVMODS_FACEBOOK_NOT_REVOKED','Your browser does not contain a current access token to revoke authorization. You can may do so on Facebook in the application settings of the account menu.');
				}
				$redirect=str_replace('svmods_check_cookie=fb','',$_SERVER['REQUEST_URI']);
				$l_redirect=(($redirect === "index.$phpEx") ? $user->lang['RETURN_INDEX'] : $user->lang['RETURN_PAGE']);
				trigger_error($message . '<br /><br />' . sprintf($l_redirect, '<a href="' . $redirect . '">', '</a>'));
			}
			else if ($user->data['user_svmods_fb_uid']===$user->data['is_facebook_user']){
				// nothing to do here. run away.
				return NULL;
			}
		}
		if ($temp){
			$sql='SELECT user_id FROM '.USERS_TABLE.' WHERE user_svmods_fb_uid='.$user->data['is_facebook_user'];
			$result=$db->sql_query($sql);
			$user_id=(int) $db->sql_fetchfield('user_id');
			$db->sql_freeresult($result);
			if ($user->data['user_id']!=ANONYMOUS){
				$user->data['is_facebook_connected']=1;
				
				if (($user_id<1) && ($user->data['user_svmods_fb_uid']<1)){
					// we are logged in, not associated, and have a new fb uid to work with...
					$sql_ary=array('user_id'=>$user->data['user_id'], 'user_svmods_fb_uid'=>$user->data['is_facebook_user']);
					svmods_create_fb_association($sql_ary);
					$message=svmods_default_lang('SVMODS_FACEBOOK_NOW_ASSOCIATED','Your Facebook account is now associated with your '.$config['sitename'].' account.');
					$redirect=str_replace('svmods_check_cookie=fb','',$_SERVER['REQUEST_URI']);
					$l_redirect=(($redirect === "index.$phpEx") ? $user->lang['RETURN_INDEX'] : $user->lang['RETURN_PAGE']);
					$redirect=meta_refresh(3, $redirect);
					trigger_error($message . '<br /><br />' . sprintf($l_redirect, '<a href="' . $redirect . '">', '</a>'));
				}
				else if ($user_id!=$user->data['user_id']){
					// mismatch. we know of an account to this fb uid, but not the current one!
					if ($user_id<1){
						// do we have an associated account? if not, might as well revoke auth.
						svmods_revoke_facebook_authorization($user->data['svmods_facebook_data']['cookie']['uid'],$user->data['svmods_facebook_data']['cookie']['access_token']);
					}
					$message=svmods_default_lang('SVMODS_FACEBOOK_MISMATCH','The '.$config['sitename'].' account you are logged in with is associated with a different Facebook account. Please use that Facebook account when logging in.');
					setcookie('fbs_'.$config['svmods_facebook_app_id'],NULL,time(),'/');
					unset($_COOKIE['fbs_'.$config['svmods_facebook_app_id']]);
					$redirect=str_replace('svmods_check_cookie=fb','',$_SERVER['REQUEST_URI']);
					$l_redirect=(($redirect === "index.$phpEx") ? $user->lang['RETURN_INDEX'] : $user->lang['RETURN_PAGE']);
					$redirect=meta_refresh(3, $redirect);
					trigger_error($message . '<br /><br />' . sprintf($l_redirect, '<a href="' . $redirect . '">', '</a>'));
				}
			}
			else{
				$mode=request_var('mode', '');
				$password=request_var('password', '', true);
				if ($user_id){
					$user->session_create($user_id, false, true);
					$auth->acl($user->data);
					$user->setup();
					$user->data['is_facebook_user']=$user_svmods_fb_uid;
					$user->data['is_facebook_connected']=1;
				}
				else if (($mode!='terms') && ($mode!='privacy')){
					if (!empty($password)){
						// pass submitted, attempting to link.
						$config['svmods_facebook_app_id']=NULL;
						$user->add_lang('ucp');
						login_box();
					}
					svmods_set_facebook_data();
					$user->data['svmods_facebook_data']=svmods_get_facebook_data($user->data['svmods_facebook_data']);
					$email=strtolower(trim($user->data['svmods_facebook_data']['user']['decoded']->email));
					$sql="SELECT user_id FROM ".USERS_TABLE." WHERE user_email='".$email."'";
					$result=$db->sql_query($sql);
					$user_id=(int) $db->sql_fetchfield('user_id');
					$db->sql_freeresult($result);
					if (($user_id) && ($user_id!=ANONYMOUS)){
						$sql_ary=array('user_id'=>$user_id, 'user_svmods_fb_uid'=>$user->data['is_facebook_user']);
						svmods_create_fb_association($sql_ary);
						$user->session_create($user_id, false, true);
						$auth->acl($user->data);
						$user->setup();
						$user->data['is_facebook_user']=$user->data['user_svmods_fb_uid'];
						$user->data['is_facebook_connected']=1;
						$message=svmods_default_lang('SVMODS_FACEBOOK_NOW_ASSOCIATED','Your Facebook account is now associated with your '.$config['sitename'].' account.');
						$redirect=str_replace('svmods_check_cookie=fb','',$_SERVER['REQUEST_URI']);
						$l_redirect=(($redirect === "index.$phpEx") ? $user->lang['RETURN_INDEX'] : $user->lang['RETURN_PAGE']);
						$redirect=meta_refresh(3, $redirect);
						trigger_error($message . '<br /><br />' . sprintf($l_redirect, '<a href="' . $redirect . '">', '</a>'));
						
					}
					else {
						$user->add_lang('ucp');
						if ($config['check_dnsbl']){
							if (($dnsbl = $user->check_dnsbl('register')) !== false){
								trigger_error(sprintf($user->lang['IP_BLACKLISTED'], $user->ip, $dnsbl[1]));
							}
						}
						require_once($phpbb_root_path . 'includes/functions_user.' . $phpEx);
						$svmods_username=utf8_clean_string(request_var('svmods_username', ''));
						if (!empty($svmods_username)){
							$invalid_username=validate_username($svmods_username);
							if (!$invalid_username){
								$coppa=(isset($_REQUEST['coppa'])) ? ((!empty($_REQUEST['coppa'])) ? 1 : 0) : false;
								$group_name=($coppa) ? 'REGISTERED_COPPA' : 'REGISTERED';				
								$sql='SELECT group_id FROM '.GROUPS_TABLE." WHERE group_name = '".$db->sql_escape($group_name)."' AND group_type = ".GROUP_SPECIAL;
								$result = $db->sql_query($sql);
								$row = $db->sql_fetchrow($result);
								$db->sql_freeresult($result);				
								if (!$row){
									trigger_error('NO_GROUP');
								}				
								$group_id=$row['group_id'];
								// we removed user activation option for the fb users
								if (($coppa || $config['require_activation'] == USER_ACTIVATION_ADMIN) && $config['email_enable']){
									$user_actkey = gen_rand_string(10);
									$key_len = 54 - (strlen($server_url));
									$key_len = ($key_len < 6) ? 6 : $key_len;
									$user_actkey = substr($user_actkey, 0, $key_len);				
									$user_type = USER_INACTIVE;
									$user_inactive_reason = INACTIVE_REGISTER;
									$user_inactive_time = time();
								}
								else{
									$user_type = USER_NORMAL;
									$user_actkey = '';
									$user_inactive_reason = 0;
									$user_inactive_time = 0;
								}
								// create a random password
								$rand_pass=gen_rand_string(8);
								$server_url=generate_board_url();
								$user_row = array(
									'username'				=> $svmods_username,
									'user_password'			=> phpbb_hash($rand_pass),
									'user_email'			=> $email,
									'group_id'				=> (int) $group_id,
									'user_timezone'			=> (float) $user->data['svmods_facebook_data']['user']['decoded']->timezone,
									'user_dst'				=> $dst['tm_isdst'],
									'user_lang'				=> $config['default_lang'],
									'user_type'				=> $user_type,
									'user_actkey'			=> $user_actkey,
									'user_ip'				=> $user->ip,
									'user_regdate'			=> time(),
									'user_inactive_reason'	=> $user_inactive_reason,
									'user_inactive_time'	=> $user_inactive_time,
									'user_svmods_fb_uid'		=> $user->data['is_facebook_user']
								);
								if (($config['allow_avatar_remote']) && (request_var('svmods_use_fb_pic',0)>0)){
									// this only works with jpg fb images. if test account or blank will null
									$avatar=array('remotelink'=>svmods_facebook_avatar_url().$user->data['is_facebook_user'].'.jpg','width'=>50,'height'=>50);
									$error=array();
									list($user_row['user_avatar_type'], $user_row['user_avatar'], $user_row['user_avatar_width'], $user_row['user_avatar_height']) = avatar_remote($avatar, $error);
								}
								if ($config['new_member_post_limit']){
									$user_row['user_new'] = 1;
								}
								// register the new user
								$user_id=user_add($user_row);				
								if ($user_id===false){
									trigger_error('NO_USER', E_USER_ERROR);
								}
								else{
									// create our association with $user_id
									$sql_ary=array('user_id'=>$user_id, 'user_svmods_fb_uid'=>$user->data['is_facebook_user']);
									svmods_create_fb_association($sql_ary);
									$user->data['is_facebook_user']=1;
									$user->data['is_facebook_connected']=1;
									// all the email stuff...
									if ($coppa && $config['email_enable']){
										$message = $user->lang['ACCOUNT_COPPA'];
										$email_template = 'coppa_welcome_inactive';
										if (file_exists($user->lang_path.$user->lang_name.'/email/svmods_fb_coppa_welcome_inactive.txt')){
											$email_template = 'svmods_fb_coppa_welcome_inactive';
										}
									}
									else if ($config['require_activation'] == USER_ACTIVATION_ADMIN && $config['email_enable']){
										$message = $user->lang['ACCOUNT_INACTIVE_ADMIN'];
										$email_template = 'admin_welcome_inactive';
										if (file_exists($user->lang_path.$user->lang_name.'/email/svmods_fb_admin_welcome_inactive.txt')){
											$email_template = 'svmods_fb_admin_welcome_inactive';
										}
									}
									else{
										$message = svmods_default_lang('SVMODS_FACEBOOK_ACCOUNT_ADDED', "Thank you for registering, your account has been created. You may now login using your Facebook account.<br /><br />A random password has been created for your account. Should you wish to use the standard login in the future, you can reset your password through the password recovery link on the login page.");
										$email_template = 'user_welcome';
										if (file_exists($user->lang_path.$user->lang_name.'/email/svmods_fb_user_welcome.txt')){
											$email_template = 'svmods_fb_user_welcome';
										}
									}
									if ($config['email_enable']){
										include_once($phpbb_root_path.'includes/functions_messenger.'.$phpEx);					
										$messenger = new messenger(false);					
										$messenger->template($email_template, $config['default_lang']);					
										$messenger->to($email, $svmods_username);					
										$messenger->headers('X-AntiAbuse: Board servername - ' . $config['server_name']);
										$messenger->headers('X-AntiAbuse: User_id - ' . $user_id);
										$messenger->headers('X-AntiAbuse: Username - ' . $svmods_username);
										$messenger->headers('X-AntiAbuse: User IP - ' . $user->ip);					
										$messenger->assign_vars(array(
											'WELCOME_MSG'	=> htmlspecialchars_decode(sprintf($user->lang['WELCOME_SUBJECT'], $config['sitename'])),
											'USERNAME'		=> htmlspecialchars_decode($svmods_username),
											'PASSWORD'		=> htmlspecialchars_decode($rand_pass),
											'U_ACTIVATE'	=> "$server_url/ucp.$phpEx?mode=activate&u=$user_id&k=$user_actkey")
										);
										if ($coppa){
											$messenger->assign_vars(array(
												'FAX_INFO'		=> $config['coppa_fax'],
												'MAIL_INFO'		=> $config['coppa_mail'],
												'EMAIL_ADDRESS'	=> $email)
											);
										}
										$messenger->send(NOTIFY_EMAIL);					
										if ($config['require_activation'] == USER_ACTIVATION_ADMIN){
											// Grab an array of user_id's with a_user permissions ... these users can activate a user
											$admin_ary = $auth->acl_get_list(false, 'a_user', false);
											$admin_ary = (!empty($admin_ary[0]['a_user'])) ? $admin_ary[0]['a_user'] : array();					
											$where_sql = ' WHERE user_type = ' . USER_FOUNDER;					
											if (sizeof($admin_ary)){
												$where_sql .= ' OR ' . $db->sql_in_set('user_id', $admin_ary);
											}					
											$sql='SELECT user_id, username, user_email, user_lang, user_jabber, user_notify_type FROM '.USERS_TABLE.' '.$where_sql;
											$result = $db->sql_query($sql);					
											while ($row = $db->sql_fetchrow($result)){
												$messenger->template('admin_activate', $row['user_lang']);
												$messenger->to($row['user_email'], $row['username']);
												$messenger->im($row['user_jabber'], $row['username']);					
												$messenger->assign_vars(array(
													'USERNAME'			=> htmlspecialchars_decode($svmods_username),
													'U_USER_DETAILS'	=> "$server_url/memberlist.$phpEx?mode=viewprofile&u=$user_id",
													'U_ACTIVATE'		=> "$server_url/ucp.$phpEx?mode=activate&u=$user_id&k=$user_actkey")
												);					
												$messenger->send($row['user_notify_type']);
											}
											$db->sql_freeresult($result);
										}
									}
									$message.='<br /><br />'.sprintf($user->lang['RETURN_INDEX'], '<a href="'.append_sid("{$phpbb_root_path}index.$phpEx").'">', '</a>');
									trigger_error($message);
								}
							}
							else{
								$username=NULL;
							}
						}
						// trigger error screen and present both login and register options!
						$user->lang['INFORMATION']=svmods_default_lang('SVMODS_FACEBOOK_REGISTER_PAGE_TITLE', 'Welcome to '.$config['sitename'].', '.$user->data['svmods_facebook_data']['user']['decoded']->first_name.'! Create/link your account with Facebook');						
						$username_explain=sprintf($user->lang[$config['allow_name_chars'] . '_EXPLAIN'], $config['min_name_chars'], $config['max_name_chars']);
						$svmods_username=trim($user->data['svmods_facebook_data']['user']['decoded']->name);
						if (validate_username($svmods_username)){
							$svmods_username=str_replace(' ','',$svmods_username);
							if (validate_username($svmods_username)){
								// just erase it. we're not doing this all day.
								$svmods_username=NULL;
							}
						}
						// make our message for trigger error.
						$message=NULL;
						if (!empty($invalid_username)){
							$message='<div class="error">'.$user->lang[$invalid_username.'_USERNAME'].'</div>';
						}
						else if (!empty($svmods_fb_link['error_msg'])){
							$message='<div class="error">'.$user->lang[$svmods_fb_link['error_msg']].'</div>';
						}
						$message.='<form method="post" class="headerspace">
							<h3>'.svmods_default_lang('SVMODS_FACEBOOK_NEW_CONNECT_TEXT', 'Existing users: login to link your Facebook account to your '.$config['sitename'].' account.').'</h3>
							<fieldset class="quick-login">
							<label for="username">'.$user->lang['USERNAME'].':</label>&nbsp;<input type="text" name="username" id="username" size="10" class="inputbox" title="'.$user->lang['USERNAME'].'" />
							<label for="password">'.$user->lang['PASSWORD'].':</label>&nbsp;<input type="password" name="password" id="password" size="10" class="inputbox" title="'.$user->lang['PASSWORD'].'" />
							<input type="submit" name="login" value="'.$user->lang['LOGIN'].'" class="button2" />&nbsp;
							<input type="submit" name="svmods_fb_cancel" value="'.$user->lang['CANCEL'].'" class="button2" />
							</fieldset>
							</form><br />';
						$message.='<form method="post" class="headerspace">
							<h3>'.svmods_default_lang('SVMODS_FACEBOOK_NEW_REGISTER_TEXT', 'New to '.$config['sitename'].'? Register your username.').'</h3>
							'.$user->lang['LOGIN_INFO'].'<br /><br />'.svmods_default_lang('SVMODS_FACEBOOK_NEW_REGISTER_TEXT', 'By submitting your registration, you agree to the site <a href="'.append_sid('ucp.'.$phpEx.'?mode=terms').'">terms of use</a> and <a href="'.append_sid('ucp.'.$phpEx.'?mode=privacy').'">privacy policy</a>.').'<br />'.sprintf($user->lang[$config['allow_name_chars'] . '_EXPLAIN'], $config['min_name_chars'], $config['max_name_chars']).'<br /><br />
							<fieldset class="quick-login">
							<label for="username">'.$user->lang['USERNAME'].':</label>&nbsp;<input type="text" name="svmods_username" id="svmods_username" size="10" class="inputbox" title="'.$user->lang['USERNAME'].'" value="'.$svmods_username.'" />&nbsp;<input type="submit" name="register" value="'.$user->lang['REGISTER'].'" class="button2" />&nbsp;
							<input type="submit" name="svmods_fb_cancel" value="'.$user->lang['CANCEL'].'" class="button2" />';
						if ($config['allow_avatar_remote']){
							$message.='&nbsp;&nbsp;&nbsp;<input type="checkbox" id="svmods_use_fb_pic" name="svmods_use_fb_pic" value="1" checked />&nbsp;'.svmods_default_lang('SVMODS_USE_FB_PIC', 'Use Facebook image');
						}
						$message.='</fieldset>
							</form><br /><br />';
						trigger_error($message);
					}
				}
			}
		}
	}
}


if (!$config['board_disable']){
	$phpbb_hook->register('phpbb_user_session_handler', 'svmods_facebook_connect_user_hook');
	$phpbb_hook->register(array('template','display'), 'svmods_facebook_connect_template_hook');
}



