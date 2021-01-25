<?php

function get_response($prompt, callable $func=null) {
	while (true) {
		echo $prompt;
		$answer = trim(fgets(STDIN));

		if ($func !== null) {
			if (false === call_user_func($func, $answer)) continue;
		}

		return $answer;
	}
}

//function is_serialized($txt) {
//	$val = @unserialize($txt);
//	if ($val === false && $txt !== 'b:0;') {
//		return false;
//	}
//	return true;
//}
function is_serialized( $data, $strict = true ) {
	// if it isn't a string, it isn't serialized.
	if ( ! is_string( $data ) ) {
		return false;
	}
	$data = trim( $data );
	if ( 'N;' == $data ) {
		return true;
	}
	if ( strlen( $data ) < 4 ) {
		return false;
	}
	if ( ':' !== $data[1] ) {
		return false;
	}
	if ( $strict ) {
		$lastc = substr( $data, -1 );
		if ( ';' !== $lastc && '}' !== $lastc ) {
			return false;
		}
	} else {
		$semicolon = strpos( $data, ';' );
		$brace     = strpos( $data, '}' );
		// Either ; or } must exist.
		if ( false === $semicolon && false === $brace ) {
			return false;
		}
		// But neither must be in the first X characters.
		if ( false !== $semicolon && $semicolon < 3 ) {
			return false;
		}
		if ( false !== $brace && $brace < 4 ) {
			return false;
		}
	}
	$token = $data[0];
	switch ( $token ) {
		case 's':
			if ( $strict ) {
				if ( '"' !== substr( $data, -2, 1 ) ) {
					return false;
				}
			} elseif ( false === strpos( $data, '"' ) ) {
				return false;
			}
		// or else fall through
		case 'a':
		case 'O':
			return (bool) preg_match( "/^{$token}:[0-9]+:/s", $data );
		case 'b':
		case 'i':
		case 'd':
			$end = $strict ? '$' : '';
			return (bool) preg_match( "/^{$token}:[0-9.E-]+;$end/", $data );
	}
	return false;
}

function replace_recursively($data, $old_value, $new_value) {
	if (is_object($data)) {
		foreach(get_object_vars($data) as $key => $val) {
			$data->$key = replace_recursively($val, $old_value, $new_value);
		}
	} else if (is_array($data)) {
		foreach($data as $key => $val) {
			$data[ replace_recursively($key, $old_value, $new_value) ]  =  replace_recursively($val, $old_value, $new_value);
		}
	} else if (is_string($data)) {
		$data = str_replace($old_value, $new_value, $data);
	} else if (!is_numeric($data) && !is_bool($data) && !is_null($data)) {
		throw new Exception('Unknown type: ' . gettype($data) . ' ' . $data);
	}

	return $data;
}