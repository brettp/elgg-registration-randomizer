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
	// don't need to pass anything to the action.
	// just need to check that the token and ts in the referrer sent are correct.
	elgg_register_plugin_hook_handler('action', 'register', 'registration_randomizer_referrer_check');
}

/**
 * Serves registration URLs as created by the output/registration_url view
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
		registration_randomizer_tarpit();
		forward('/', 404);
	} else {
		include elgg_get_config('path') . 'pages/account/register.php';
		return true;
	}

	forward('/', 404);
}

/**
 * Sleep for a while to slow things down.
 *
 * @todo Increment the sleep base on IP
 *
 * @param int $time
 */
function registration_randomizer_tarpit($time = null) {
	if ($time === null) {
		$time = 5;
	}

	sleep($time);
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

	$str .= elgg_extract('HTTP_USER_AGENT', $req);
	$str .= elgg_extract('REMOTE_ADDR', $req);
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
	$ref = elgg_extract('HTTP_REFERER', $_SERVER);
	$url = elgg_get_site_url();
	list($register, $ts, $token) = explode('/', str_replace($url, '', $ref));

	if ($register !== 'register') {
		return $return;
	}

	if (!registration_randomizer_is_valid_token($token, $ts)) {
		register_error("Cannot complete registration at this time.");
		forward('/', 403);
	}

	return $return;
}