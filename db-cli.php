#!/usr/bin/env php
<?php

/**
 * Scan all WordPress tables for domain values
 */

require(__DIR__ . '/common.php');
ini_set('display_errors', 1);
error_reporting(E_ERROR);

$help = 'Scan all WordPress tables for domain values' . PHP_EOL;
$help .= PHP_EOL;
$help .= 'USAGE: ' . $argv[0] . ' [config]' . PHP_EOL;
$help .= '  [config] - Specified as option_key=option_value' . PHP_EOL;
$help .= '    connect - Show the "mysql" connection command' . PHP_EOL;
$help .= '    mycnf - Generate my.cnf style file contents' . PHP_EOL;
$help .= '    structure - Show the database structure' . PHP_EOL;
$help .= '    old - The domain to replace (include http:// to replace with https://)' . PHP_EOL;
$help .= '    search - Alias for old' . PHP_EOL;
$help .= '    dbhost - The host name to use for the database connection' . PHP_EOL;
$help .= '    replace - Run replace values process' . PHP_EOL;
$help .= '    summary - Show summary of large text fields to be changed' . PHP_EOL;
$help .= '    tables - Limit run to individual tables' . PHP_EOL;
$help .= '    autoreplace - Automatically replace values' . PHP_EOL;
$help .= '    skip - Do not show skipped records' . PHP_EOL;
$help .= '    allowemptyreplace - Force to allow empty replace value' . PHP_EOL;
$help .= '    table.[table_name].[option] - Set an option for an individual table' . PHP_EOL;
$help .= '      option:' . PHP_EOL;
$help .= '        exclude - Exclude this table from the run' . PHP_EOL;
$help .= '        where - A SQL WHERE clause to be applied' . PHP_EOL;
$help .= '        limit - Limit the number of records to process at a time' . PHP_EOL;
$help .= '        show_columns - Command delimited list of columns to display (other than ID and text matches)' . PHP_EOL;
$help .= '        summary - Summarize each of the old values within the record' . PHP_EOL;

$run_replace = false;
$db_host = '';
$old_value = '';
$new_value = '';
$limit_tables = [];
$show_summary = false;
$show_connect = false;
$show_mycnf = false;
$show_structure = false;
$show_skipped = true;
$table_rules = [];
$auto_replace_all = false;
$allow_empty_replace = false;
$case_sensitive_search = false;
/**
 * Process passed arguments as options
 */
for ($i=1; $i < count($argv); $i++) {
	list($opt_key, $opt_val) = array_pad(explode('=', $argv[$i], 2), 2, '');

	switch ($opt_key) {
		case 'old':
		case 'search':
			$old_value = $opt_val;
			break;
        case 'case':
            $case_sensitive_search = true;
            break;
		case 'dbhost':
			$db_host = $opt_val;
			break;
		case 'tables':
			$limit_tables = explode(',', $opt_val);
			break;
        case 'allowemptyvalue':
            $allow_empty_replace = true;
            break;
		case 'replace':
			$run_replace = true;
			$new_value = $opt_val;
			if (empty($new_value) && !$allow_empty_replace) die('Replace value cannot be empty' . PHP_EOL);
			break;
		case 'summary':
			$show_summary = true;
			break;
		case 'connect':
			$show_connect = true;
			break;
		case 'mycnf':
			$show_mycnf = true;
			break;
		case 'structure':
			$show_structure = true;
			break;
        case 'help':
            echo $help . PHP_EOL;
            exit;
        case 'autoreplace':
            $auto_replace_all = true;
            break;
        case 'skip':
            $show_skipped = false;
            break;
		default:
			$valid = false;
			if (preg_match('/table\.(.+?)\.(.+)/', $opt_key, $table_matches)) {
				list($_, $table, $table_opt) = $table_matches;

				switch ($table_opt) {
					case 'exclude':
						$valid = true;
//						$opt_val = $opt_val === 'true';
						$opt_val = true;
						break;
					case 'show_columns':
					case 'replace_columns':
						$valid = true;
						$opt_val = explode(',', $opt_val);
						break;
					case 'where':
					case 'limit':
					case 'summary':
						$valid = true;
						break;
					//					case 'serialized':
//						$valid = true;
//						$opt_val = explode(',', $opt_val);
//						break;
				}
			}
			if ($valid) {
				$table_rules[$table][$table_opt] = $opt_val;
			} else {
				echo 'Invalid option: ' . $opt_key . PHP_EOL;
				exit(1);
			}
	}
}

$wp_config = 'wp-config.php';
$tables_cache = '.tables';

if (!file_exists($wp_config)) die('ERROR: Run from wp-config.php directory' . PHP_EOL);

$wp_config_contents = file_get_contents($wp_config);

preg_match_all('/define\(\s*[\'"](.+)[\'"],\s*[\'"](.+)[\'"]\s*\)/', $wp_config_contents, $matches);
if (file_exists('.wpconfig')) require('.wpconfig');
for($m=0; $m < count($matches[0]); $m++) {
	$key = $matches[1][$m];
	$val = $matches[2][$m];
	if (!defined($key)) define($key, $val);
}

if (empty($db_host)) $db_host = file_exists('.dbhost') ? trim(file_get_contents('.dbhost')) : DB_HOST; // If dbhost is not defined, default to: (1) values in .dbhost file or (2) wp-config's version
if ($show_mycnf) die('# Generated by ' . __FILE__ . PHP_EOL . '[client]' . PHP_EOL . 'host = ' . $db_host . PHP_EOL . 'user = ' . DB_USER . PHP_EOL . 'password = ' . DB_PASSWORD . PHP_EOL . 'database = ' . DB_NAME . PHP_EOL);
if ($show_connect) die('mysql -h ' . $db_host . ' -u ' . DB_USER . ' -p\'' . DB_PASSWORD . '\' ' . DB_NAME . PHP_EOL);
$dh = mysqli_connect($db_host, DB_USER, DB_PASSWORD, DB_NAME);

if ($run_replace && !$allow_empty_replace && empty($new_value)) die('"new" must be specified' . PHP_EOL . $help);

if (!$dh) {
	echo 'Unable to connect to database: ' . PHP_EOL;
	echo '  DB_HOST: ' . DB_HOST . ($db_host != DB_HOST ? ' => ' . $db_host : '') . PHP_EOL;
	echo '  DB_NAME: ' . DB_NAME . PHP_EOL;
	echo '  DB_USER: ' . DB_USER . PHP_EOL;
	echo '  DB_PASSWORD: ' . DB_PASSWORD . PHP_EOL;
	echo '  Error: ' . mysqli_connect_error($dh) . PHP_EOL;
	exit(1);
}

$excl_types = ['bigint', 'binary', 'bit', 'blob', 'datetime', 'enum', 'float', 'int', 'longblob', 'mediumint', 'timestamp', 'tinyblob', 'tinyint', 'decimal', 'double', 'smallint', 'varbinary'];
$incl_types = ['char', 'longtext', 'mediumtext', 'text', 'tinytext', 'varchar'];

echo 'Scanning tables... ';

$tables = [];

if (file_exists($tables_cache)) {
	echo 'from ' . $tables_cache . ' (' . date('Y-m-d H:i:s', filemtime($tables_cache)) . ')' . PHP_EOL;
	$tables = json_decode(file_get_contents($tables_cache), true);
} else {
	$query = mysqli_query($dh, "SHOW TABLES");

	$unknown_types = [];
	while ($row = mysqli_fetch_row($query)) {
		list($table) = $row;
		$desc_query = mysqli_query($dh, "DESCRIBE $table");

		$tables[$table] = [
			'id' => [],
			'cols' => [],
			'search_cols' => []
		];
		echo $table . '... ';
		while ($desc_row = mysqli_fetch_row($desc_query)) {
			list($column, $col_type, $is_null, $key, $default, $extra) = $desc_row;
			$is_null = $is_null === 'YES';

			$col_type = strtolower(preg_replace('/\(.*/', '', $col_type));
			$is_primary = $key == 'PRI';
			if ($is_primary) $tables[$table]['id'][] = $column;
			$tables[$table]['cols'][] = $column;
			if (!in_array($col_type, $excl_types) && !in_array($col_type, $incl_types) && !in_array($col_type, $unknown_types)) $unknown_types[] = $col_type;
			if (in_array($col_type, $incl_types)) $tables[$table]['search_cols'][] = $column;
			//		echo '  ' . $column . ($is_primary?'*':'') . ' (' . $col_type . ')' . PHP_EOL;
		}
		echo 'done' . PHP_EOL;
	}
	if (count($tables) > 0) {
		file_put_contents($tables_cache, json_encode($tables));
	}
}

if ($show_structure) {
	foreach($tables as $table => $info) {
		if (!isset($info['cols'])) die('Remove .tables and re-run' . PHP_EOL);
		echo '- ' . $table . ': ' . implode(', ', $info['cols']) . PHP_EOL;
	}
	if (empty($old_value)) exit;
}

if (count($unknown_types) > 0) {
	echo 'Unknown types:' . PHP_EOL;
	foreach ($unknown_types as $unknown_type) {
		echo '- ' . $unknown_type . PHP_EOL;
	}
	exit(1);
}

if (empty($old_value)) die('Missing old value'.PHP_EOL.$help);

echo PHP_EOL;

echo 'Searching...' . PHP_EOL;
$max_table_len = 0;
foreach(array_keys($tables) as $table) {
    $max_table_len = max($max_table_len, strlen($table));
}
foreach($tables as $table => $info) {
	if (count($limit_tables) > 0 && !in_array($table, $limit_tables)) continue;

	$auto_replace = $auto_replace_all || (isset($table_rules[$table]['auto_replace']) && $table_rules[$table]['auto_replace'] === true);
	$skip_replace = false;
    $is_excluded = isset($table_rules[$table]['exclude']) && $table_rules[$table]['exclude'];
    $any_ids = count($info['id']) > 0; // count($info['id']) == 0
    $is_missing_search_cols = count($info['search_cols']) == 0;

    $show_info = false; // $show_skipped || !$is_excluded || !$any_ids || !$is_missing_search_cols;
    $label = str_pad($table, $max_table_len, '.') . '... ';

//    if ($show_info) {
//		echo $label;
//        echo '[' . ($show_skipped ? ' ':'x') . '] Skip ';
//        echo '[' . ($is_excluded ? 'x':' ') . '] Excl ';
//        echo '[' . ($any_ids ? 'x':' ') . '] IDs ';
//        echo '[' . ($is_missing_search_cols ? 'x':' ') . '] Miss ';
//        echo '| ';
//	}

	if ($is_excluded) {
		if ($show_skipped) echo $label . ' exclude' . PHP_EOL;
		continue;
	}
	if (!$any_ids) {
		if ($show_skipped) echo $label . ' no IDs.  Skipping' . PHP_EOL;
		continue;
	}
	if ($is_missing_search_cols) {
		if ($show_skipped ) echo $label . ' no search columns.  Skipping' . PHP_EOL;
		continue;
	}

	$where = array_map(function($col) use ($old_value, $case_sensitive_search) {
//		return "`$col` LIKE '%" . str_replace('\\', '\\\\', str_replace('%', '\\%', str_replace("'", "\'", $old_value))) . "%'";
        $where = $old_value;
        $where = safe_sql_value($where, true);
        $where = str_replace('%', '\\%', $where);
		return "BINARY `$col` LIKE '%" . $where . "%'";
	}, $info['search_cols']);

	$sel_columns = $info['id'];
	if (isset($table_rules[$table]) && isset($table_rules[$table]['show_columns'])) {
		foreach($table_rules[$table]['show_columns'] as $extra_column) {
			$sel_columns[] = $extra_column;
		}
	}
	$sql = "SELECT `" . implode("`, `", $sel_columns) . "`, `" . implode("`, `", $info['search_cols']) . "` FROM $table WHERE (" . implode(' OR ', $where) . ')';

	if (isset($table_rules[$table])) {
		if (isset($table_rules[$table]['where'])) $sql .= ' AND ' . $table_rules[$table]['where'];
		if (isset($table_rules[$table]['limit'])) $sql .= 'LIMIT ' . $table_rules[$table]['limit'];
	}

	$search_query = mysqli_query($dh, $sql);

	if (!$search_query) {
		echo $label . ' Error on ' . $table . ': ' . mysqli_error($dh) . PHP_EOL;
		continue;
	}
	$num_rows = mysqli_num_rows($search_query);
	if ($num_rows == 0) {
//        if (!$show_info && $show_skipped)
        if ($show_skipped) {
			echo $label;
			echo 'none' . PHP_EOL;
		}
		continue;
	}

    echo $label . ': ';
	echo 'found ' . number_format($num_rows) . PHP_EOL;

	$row_num = 0;
	while ($row = mysqli_fetch_assoc($search_query)) {
		$row_num ++;
		echo '  ';
		echo number_format($row_num) . '/' . number_format(mysqli_num_rows($search_query)) . ' ';
		echo '[';
		$keys = [];
		$serialized_columns = [];
		foreach($info['id'] as $id) {
			$keys[] = $id . ' = ' . $row[$id];
		}
		echo implode('; ', $keys);
		echo '] = ';
		$found_cols = [];
		foreach($info['search_cols'] as $col) {
			if (stripos($row[$col], $old_value) !== false) {
				$found_cols[] = $col . (is_serialized($row[$col]) ? ' (serialized)':' (not serialized)');
			}
		}
		echo implode(', ', $found_cols);

		$values = [];
		if (isset($table_rules[$table]) && isset($table_rules[$table]['show_columns'])) {
			foreach($table_rules[$table]['show_columns'] as $col) {
				$values[] = $col . '=' . $row[$col];
			}
		}

		if (count($values) > 0) {
			echo ' ' . implode('; ', $values);
		}
		echo PHP_EOL;
		if (!$run_replace && $show_summary) {
			foreach($info['search_cols'] as $col) {
				show_summary('    ', $row[$col], $old_value);
			}
		}
		if ($run_replace && !$skip_replace) {
			$should_replace = $auto_replace;

			if (!$auto_replace) {

				echo PHP_EOL;
				echo str_repeat('-', 50) . PHP_EOL;
				echo PHP_EOL;

				foreach($info['id'] as $key) {
					echo $key . ' = ' . $row[$key] . PHP_EOL;
				}
				foreach($info['search_cols'] as $col) {
					$max_preview_len = 500;
					$len = strlen($row[$col]);
					$display_val = $row[$col];
					$display_val = strlen($display_val) > $max_preview_len ? substr($display_val, 0, $max_preview_len) . '... (snipped) = '.$len : $display_val;
					$display_col = $col . ' (' . (is_serialized($row[$col]) ? 'Serialized' : 'Not serialized') . ')';

					echo $display_col . ' = ' . $display_val . PHP_EOL;

					if ($len > $max_preview_len && ($show_summary || isset($table_rules[$table]['summary']))) {
						show_summary('  ', $row[$col], $old_value, $new_value);
					}
				}
				$action = get_response('Update ' . $table . ' [' . number_format($row_num) . ' of ' . number_format(mysqli_num_rows($search_query)) . '] record (y=yes, n=no, a=replace all, s=skip all) ', function($answer) {
					return in_array($answer, ['', 'y', 'n', 'a', 's']);
				});

				switch ($action) {
					case 'y':
						$should_replace = true;
						break;
					case 'a':
						$auto_replace = true;
						$should_replace = true;
						break;
					case 's':
						$skip_replace = true;
						break;
					case 'n':
					default:
						break;
				}
				echo 'Should replace: ' . ($should_replace ? 'Y' : 'N') . PHP_EOL;
			}

			if ($should_replace) {
				$where = [];
				foreach($info['id'] as $id) {
					$where[] = "`$id` = '" . $row[$id] . "'";
				}
				$replace_columns = [];
				foreach($info['search_cols'] as $col) {
					if (is_serialized($row[$col])) {
						$data = unserialize($row[$col]);
						if (!$data) {
//							class WP_User
//							{
//								public $data;
//								public $ID = 0;
//								public $caps = array();
//								public $cap_key;
//								public $roles = array();
//								public $allcaps = array();
//								public $filter = null;
//								private $site_id = 0;
//								private static $back_compat_keys;
//							}
//                            $data = unserialize($row[$col]);
//                            print_r($data); die(__FILE__ . ':' . __LINE__ . PHP_EOL);
                            dump_serialized($row[$col]);
                            throw new Exception('Failed to unserialize: ' . $row[$col]);
						}

						$data = serialize(replace_recursively($data, $old_value, $new_value));
						$replace_columns[] = "`$col` = '" . str_replace("'", "\'", $data) . "'";
//						$replace_columns[] = "`$col` = '" . safe_sql_value($data) . "'";
					} else {
                        $old_value_search = safe_sql_value($old_value);
                        $new_value_search = safe_sql_value($new_value);
						$replace_columns[] = "`$col` = REPLACE(`$col`, '$old_value_search', '$new_value_search')";
					}
				}
				$sql_update = "UPDATE $table SET " . implode(', ', $replace_columns) . " WHERE " . implode(', ', $where);
//                echo $sql_update . PHP_EOL;
//                die(__FILE__ . ':' . __LINE__ . '<br />' . PHP_EOL);

				$update_query = mysqli_query($dh, $sql_update);
                if (!$update_query) {
                    echo 'Error updating: ' . mysqli_error($dh) . PHP_EOL;
//                } else {
//				    echo 'Affected: ' . mysqli_affected_rows($dh) . PHP_EOL;
//					echo $sql_update . PHP_EOL;
				}
			}
		}
	}
}

function safe_sql_value($value, bool $double_replace = false) {
    $value = str_replace("'", "\'", $value);
    $value = str_replace('\\', '\\\\', $value);
    if ($double_replace) $value = str_replace('\\', '\\\\', $value);
//    echo 'VALUE: ' . $value . PHP_EOL;
//    die(__FILE__ . ':' . __LINE__ . '<br />' . PHP_EOL);
    return $value;
}
function dump_serialized(string $text) {
    $result = dump_serialized_next_key($text);
    print_r($result);
    die(__FILE__ . ':' . __LINE__ . '<br />' . PHP_EOL);
//    for($i=0, $j=strlen($text); $i < $j; $i++) {
//        $char = $text[$i];
//        if ($mode === DUMP_SERIALIZE_KEY) {
//            if ($char == ':') {
//                $len = '';
//                for($x=$i+1; $x < $j; $x++) {
//                    if (is_numeric($text[$x])) {
//                        $len .= $text[$x];
//                    } else {
//                        $i = $x;
//                        break;
//                    }
//                }
//                $len = intval($len);
//                $mode = DUMP_SERIALIZE_VALUE;
//            } else {
//                $type .= $char;
//            }
//        } else {
//            echo 'KEY: ' . $type . PHP_EOL;
//            echo 'LEN: ' . $len . PHP_EOL;
//            echo 'Value: ' . substr($text, $i, 10) . PHP_EOL;
//            exit;
//        }
//    }
}

function dump_serialized_next_key($text, &$offset=0) {
    $type = '';
    $type = dump_serialized_until($text, $offset, [':', ';']);

    switch($type) {
        case 'a': // array
            $len = dump_serialized_until($text, $offset, [':']);
            return dump_serialized_array($text, $offset, $len);
        case 's': // string
            $len = dump_serialized_until($text, $offset, [':']);
            return dump_serialized_string($text, $offset, $len);
        case 'i': // integer
            return dump_serialized_until($text, $offset, [], function($char, $value) {
                return is_numeric($char);
            });
        default:
            echo 'Unknown type: ' . $type . PHP_EOL;
            exit;
    }
    echo substr($text, 0, 10) . PHP_EOL;
    die(__FILE__ . ':' . __LINE__ . '<br />' . PHP_EOL);
    echo 'Next: ' .  substr($text, $offset, 10) . PHP_EOL;
    echo 'Type: ' . $type . PHP_EOL;
    echo 'Len: ' . $len . PHP_EOL;
    die(__FILE__ . ':' . __LINE__ . '<br />' . PHP_EOL);
}

function dump_serialized_string($text, &$offset, $len): string
{
//    echo 'LEN: ' . $len . PHP_EOL;
//    echo 'OFFSET: ' . $offset . PHP_EOL;
//	echo 'TEXT: ' . substr($text, 0, 50) . PHP_EOL;
//	echo 'RESULT: ' . substr($text, $offset, 1) . PHP_EOL;

    if (substr($text, $offset, 1) != '"') {
        echo 'Expecting " at ' . $offset . PHP_EOL;
        die(__FILE__ . ':' . __LINE__ . '<br />' . PHP_EOL);
    }
    $offset++;
    $string = substr($text, $offset, $len);
    $offset += $len+1;

    return $string;
}

function dump_serialized_until($text, &$offset, array $chars, callable $callback = null) {
    $len = strlen($text);
    $value = '';
    while($offset < $len && !in_array($text[$offset], $chars) && ($callback === null || call_user_func($callback, $text[$offset], $value) === true)) {
        $value .= $text[$offset];
        $offset++;
    }
    $offset ++;

    return $value;
}

function dump_serialized_array($text, &$offset, $count): array {
    ini_set('DISPLAY_ERRORS', 1); error_reporting(E_ALL);

    $original_offset = $offset;
    if (substr($text, $offset, 1) != '{') {
        echo 'Expecting { at ' . $offset . PHP_EOL;
        die(__FILE__ . ':' . __LINE__ . '<br />' . PHP_EOL);
    }
    $offset ++;
    $array = [];
    echo 'ARRAY INCOMING: ' . $count . ' with next: ' . substr($text, $offset, 2) . PHP_EOL;
    for($x = 0; $x < $count; $x++) { // each piece of array
        #echo 'ITERATION COUNT: ' . $x  . ' < ' . $count . PHP_EOL;
        $index = dump_serialized_next_key($text, $offset);
        if (is_numeric($index)) {
            echo '--> INDEX(int):  '. $index . PHP_EOL;
//			echo 'TEST 2: ' . $text . PHP_EOL;
//			echo 'TEST 3: ' . substr($text, $offset, 10) . PHP_EOL;
            $array[$index] = dump_serialized_next_key($text, $offset);
        } else {
            echo '--> INDEX(string): ' . $index . PHP_EOL;
            $offset++; // Skip the colon
            $array[$index] = dump_serialized_next_key($text, $offset);
        }
        if (substr($text, $offset, 1) != ';') {
            echo '----> Invalid array terminator at ' . $offset . PHP_EOL;
            $preview = 50;
            echo 'PREVIOUS:  '. substr($text, $original_offset-$preview, $preview) . PHP_EOL;
            echo 'PREVIOUS NOW:  '. substr($text, $offset-$preview, $preview) . PHP_EOL;
            echo 'COUNT: ' . $count . PHP_EOL;
            echo 'TEXT: ' . substr($text, $original_offset, 50) . PHP_EOL;
            echo 'NOW: ' . substr($text, $offset, 50) . PHP_EOL;
            die(__FILE__ . ':' . __LINE__ . '<br />' . PHP_EOL);
        }
        echo '--> Increasing offset: ' . substr($text, $offset, 2) . PHP_EOL;
        $offset++;
    }
    if (substr($text, $offset, 1) != '}') {
        echo 'Expecting } at ' . $offset . PHP_EOL;
        die(__FILE__ . ':' . __LINE__ . '<br />' . PHP_EOL);
    }
    $offset++;

    return $array;
}
function dump_serialized_value($text, $type, $len) {
    echo 'Type: ' . $type . PHP_EOL;
    echo 'Value: ' . substr($text, 0, 10) . PHP_EOL;
    die(__FILE__ . ':' . __LINE__ . '<br />' . PHP_EOL);
}

function show_summary(string $prefix, $cur_value, $old_value, $new_value=null) {
	$last_pos = 0;
	$preview_pad = 50;
	$preview_count = 0;

	$count_prefix = 'Preview #';
	$count_prefix_len = strlen($count_prefix);

	while (false !== ($pos = stripos($cur_value, $old_value, $last_pos))) {
		$preview = substr($cur_value, $pos - $preview_pad, strlen($old_value) + (2 * $preview_pad));
//        $preview = $cur_value;
		$indent_chars = $prefix . str_repeat(' ', $count_prefix_len + strlen($preview_count) + 2);
		echo $prefix . 'Preview #' . ++$preview_count . ': ' . multi_line_preview('>', $indent_chars, $preview);
		// if (null !== $new_value) echo $prefix . '         ' . str_pad(' ', strlen($preview_count) + 2) . str_replace($old_value, $new_value, $preview) . PHP_EOL;
		if (null !== $new_value) echo multi_line_preview('<', $indent_chars, str_replace($old_value, $new_value, $preview));
		$last_pos = $pos + 1;
	}
}

function multi_line_preview($direction_char, $indent_chars, $value) {
	$lines = explode(PHP_EOL, $value);
	$output = '';

	for($i=0; $i < count($lines); $i++) {
		$output .= ($i > 0 ? $indent_chars : '') . $direction_char . ' ' . $lines[$i] . PHP_EOL;
	}
	return $output;
}
