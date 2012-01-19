<?php
/**
 * I always learn best by seeing things in action. This example shows how to use the notifier
 * client to catch uncaught errors and report them to Airbrake. To run this example, simply
 * execute this script in the context of an web server or as a command line script.
 * The script will look for your API key in either the request parameters (i.e. /example.php?key=YOUR_KEY)
 * or through the command line arguments (i.e. php example.php key=YOUR_KEY)
 */

header('Content-Type: text/plain');
require(__DIR__ . '/airbrake.notifier.php');

// get api key from querystring or first argument
$args = array();
$query = array_key_exists('argv', $_SERVER) ? $_SERVER['argv'][1] : $_SERVER['QUERY_STRING'];
parse_str($query, $args);
$apiKey = array_key_exists('key', $args) ? $args['key'] : '';

if ($apiKey) {

	/**
	 * Set the globals for this project...
	 * (You should do this, but they are all optional)
	 */
	AirbrakeNotifier::$debugMode       = true;      // enables error_log'ing
	AirbrakeNotifier::$projectRoot     = __DIR__;   // used by Airbrake...
	AirbrakeNotifier::$environmentName = 'example'; // the env name, i.e. 'production' vs. 'development'
	AirbrakeNotifier::$projectVersion  = '1.0';     // good values for this are git or svn revision numbers.

	/**
	 * This function will be called when the script terminates
	 */
	function onScriptShutDown() {
		global $apiKey;
		$error = error_get_last();
		$error = is_array($error) ? $error : array('type' => -1);

		// check to see if an error actually occurred
		if (in_array($error['type'], array(E_PARSE, E_ERROR))) {

            // get the error message
			$message = $error['message'];

			// I'm simulating the backtrace because we are in the context of a shutdown handler.
			// If we were in the context of a script that had not terminated (say, in the catch
			// block of a try/catch control structure), then we could use PHP's built-in
			// debug_backtrace. See http://php.net/manual/en/function.debug-backtrace.php
			$backtrace = /* debug_backtrace(); */ array(
				array(
					'line'     => $error['line'],
					'file'     => $error['file'],
					'class'    => 'example.php',
					'function' => 'onScriptShutDown'
				)
			);

			// We may also add session specific information in our tracking call.
			// This info is optional, but encouraged.
			$session = array(
				'user' => get_current_user(),
				'title'  => 'Running the Airbrake PHP client',
				'date'   => date('Y-m-d H:i:s')
			);

			// Create a new tracker, bound to the passed-in API key
			$notifier = new AirbrakeNotifier($apiKey);

			// Track the error. Note that this call will log messages
			// to the error console if AirbrakeNotifier::$debugMode is true
			$noticeId = $notifier->notify($message, $backtrace, $session);

			if ($noticeId) {
				die("Your error has been successfully logged, verify by visiting your Airbrake dashboard. Notice ID {$noticeId}\r\n");
			}

        }

	}

	// Register the shutdown function to be called on script end
	register_shutdown_function('onScriptShutDown');

	// Now we want to force our script to terminate by triggering an error.
	// tl;dr: this is obviously a contrived example - but it is also a
	// straight-forward way to integrate if your adding this to an existing
	// project. If you are starting a project from scratch, you should
	// probably be making use of try/catch to trigger error logging.
	throw new Exception('Uncaught errors are caught by onScriptShutDown');

} else {
	echo 'Unable to find API key in args: ' . print_r($args, true);
}