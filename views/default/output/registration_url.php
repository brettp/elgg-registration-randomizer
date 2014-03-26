<?php
/**
 * Provides a link to the registration URL
 */

$info = registration_randomizer_generate_token();
$vars['href'] = '/register/' . $info['ts'] . '/' . $info['token'];

echo elgg_view('output/url', $vars);