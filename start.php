<?php
/**
 * Rotates the address for the registration form
 */
elgg_register_event_handler('init', 'system', 'registration_randomizer_init');
/**
 * Init
 */
function registration_randomizer_init() {
	// Override registration page
	elgg_unregister_page_handler('register');

	// serve registration pages
	elgg_register_page_handler('register', 'registration_randomizer_page_handler');

	// check referrers
	elgg_register_plugin_hook_handler('action', 'register', 'registration_randomizer_referrer_check');

	// replace view vars
	elgg_register_plugin_hook_handler('register', 'menu:login', 'registration_randomizer_login_menu');

	elgg_set_config('rr_debug', false);
}

/**
 * Serves registration URLs as created by the registration_randomizer_login_menu() callback
 *
 * /register/:ts/:token Where :token is the token and :ts is the current timestamp.
 *
 * @param array $page
 */
function registration_randomizer_page_handler($page) {
	// tarpit if the wrong token + ts combo
	$ts = elgg_extract(0, $page);
	$token = elgg_extract(1, $page);

	if (!registration_randomizer_is_valid_token($token, $ts)) {
		registration_randomizer_log("Invalid token for registration page");
		registration_randomizer_tarpit();
		forward('/', 404);
	} else {
		echo elgg_view_resource('account/register');
		return true;
	}
	registration_randomizer_log("No token for registration page");
	registration_randomizer_tarpit();
	forward('/', 404);
}

/**
 * Sleep for a while to slow things down.
 *
 * @param int $multiplier A time multipler to tarpit repeat offending IPs
 */
function registration_randomizer_tarpit($wait = 5) {
	$ip = filter_input(INPUT_SERVER, 'REMOTE_ADDR', FILTER_VALIDATE_IP);
	$setting_name = "{$ip}_tarpit_count";

	$count = (int) elgg_get_plugin_setting($setting_name, 'registration_randomizer');
	if ($count > 4) {
		$wait = pow(4, 4);
	} else {
		$wait = pow($count, 4);
	}
	// now limit it to something reasonable, like 90% of max execution time
	$max_execution_time = ini_get('max_execution_time');
	if ($max_execution_time === false) {
		$max_execution_time = 30;
	}
	$max_execution_time = floor(0.9 * $max_execution_time);
	if ($max_execution_time && $wait > $max_execution_time) {
		$wait = $max_execution_time;
	}

	elgg_set_plugin_setting($setting_name, $count + 1, 'registration_randomizer');
	registration_randomizer_log("Tarpitting $ip for $wait seconds after $count failures.", false);

	if ($wait > 0) {
		sleep($wait);
	}
}

/**
 * Hashes the site secret, UA, and a ts.
 *
 * @return mixed A token if time or req is passed, and array of info if not
 */
function registration_randomizer_generate_token($passed_time = null, $passed_req = null) {
	if ($passed_time === null) {
		$ts = time();
	} else {
		$ts = $passed_time;
	}

	if ($passed_req === null) {
		$req = $_SERVER;
	} else {
		$req = $passed_req;
	}

	$str = get_site_secret();
	$str .= filter_input(INPUT_SERVER, 'HTTP_USER_AGENT');
	$str .= filter_input(INPUT_SERVER, 'REMOTE_ADDR', FILTER_VALIDATE_IP);
	$str .= $ts;

	$token = md5($str);

	if ($passed_time === null && $passed_req === null) {
		return array(
			'ts' => $ts,
			'token' => $token,
			'req' => $req
		);
	} else {
		return $token;
	}
}

/**
 * Checks if the token and ts are valid
 *
 * @param type $token
 * @param type $time
 * @param type $req
 * @return bool
 */
function registration_randomizer_is_valid_token($token, $time, $req = null) {
	return $token === registration_randomizer_generate_token($time, $req);
}

/**
 * Check the referrer to see if its token and timestamp match
 *
 * @param type $hook
 * @param type $action
 * @param type $return
 * @return null
 */
function registration_randomizer_referrer_check($hook, $action, $return) {
	$ref = filter_input(INPUT_SERVER, 'HTTP_REFERER');
	$url = elgg_get_site_url();
	list($register, $ts, $token) = explode('/', str_replace($url, '', $ref));

	if ($register !== 'register') {
		return $return;
	}

	if (!registration_randomizer_is_valid_token($token, $ts)) {
		registration_randomizer_log("Invalid referrer for registration action");
		register_error("Cannot complete registration at this time.");
		registration_randomizer_tarpit();
		forward('/', 403);
	}

	return $return;
}

/**
 * Log to file
 *
 * @param type $msg
 * @return type
 */
function registration_randomizer_log($msg, $all = true) {
	if (elgg_get_config('rr_debug') !== true) {
		return;
	}

	if (!$all) {
		file_put_contents(elgg_get_data_path() . 'rr_log.log', $msg . "\n", FILE_APPEND);
		return;
	}

	$data = $_REQUEST;
	$data['referrer'] = filter_input(INPUT_SERVER, 'HTTP_REFERER');
	$data['remote_ip'] = filter_input(INPUT_SERVER, 'REMOTE_ADDR');
	$data['remote_ua'] = filter_input(INPUT_SERVER, 'HTTP_USER_AGENT');
	$data['time'] = date("r");
	$data['error'] = $msg;

	file_put_contents(elgg_get_data_path() . 'rr_log.log', print_r($data, true), FILE_APPEND);
}

/**
 * Adds timestamp and token to the registration link
 *
 * @param string         $hook
 * @param string         $type
 * @param ElggMenuItem[] $menu
 * @param array          $params
 * @return ElggMenuItem[] $menu
 */
function registration_randomizer_login_menu($hook, $type, $menu, $params) {
	foreach ($menu as $key => $item) {
		if ($item->getName() == 'register') {
			$info = registration_randomizer_generate_token();
			$item->setHref('/register/' . $info['ts'] . '/' . $info['token']);
		}
	}

	return $menu;
}
