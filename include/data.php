<?php


//------------------------------------ analyse_db -----------------------------------------------------

function intropage_analyse_db() {
	global $config;

	$result = array(
		'name' => 'Database check',
		'alarm' => 'green',
		'data' => '',
		'detail' => '',
	);

	$damaged        = 0;
	$memtables      = 0;
	$db_check_level = read_config_option('intropage_analyse_db_level');

	$tables = db_fetch_assoc('SHOW TABLES');
	foreach ($tables as $key => $val) {
		$row = db_fetch_row('check table ' . current($val) . ' ' . $db_check_level);

		if (preg_match('/^note$/i', $row['Msg_type']) && preg_match('/doesn\'t support/i', $row['Msg_text'])) {
			$memtables++;
		} elseif (!preg_match('/OK/i', $row['Msg_text']) && !preg_match('/Table is already up to date/i', $row['Msg_text'])) {
			$damaged++;
			$result['detail'] .= 'Table ' . $row['Table'] . ' status ' . $row['Msg_text'] . '<br/>';
		}
	}

	if ($damaged > 0) {
		$result['alarm'] = 'red';
		$result['data']  = '<span class="txt_big">DB problem</span><br/><br/>';
	} else {
		$result['data'] = '<span class="txt_big">DB OK</span><br/><br/>';
	}

	// connection errors
	$cerrors = 0;
	$con_err = db_fetch_assoc("SHOW GLOBAL STATUS LIKE '%Connection_errors%'");

	foreach ($con_err as $key => $val) {
		$cerrors = $cerrors + $val['Value'];
	}

	if ($cerrors > 0) {	// only yellow
		$result['detail'] .= 'Connection errors - try to restart database. <br/>';

		if ($result['alarm'] == 'green') {
			$result['alarm'] = 'yellow';
		}
	}

	$result['data'] .= 'Connection errors: ' . $cerrors . '<br/>';
	$result['data'] .= 'Damaged tables: ' . $damaged . '<br/>Memory tables: ' . $memtables . '<br/>All tables: ' . count($tables);

	return $result;
}


//------------------------------------ analyse_log -----------------------------------------------------


function intropage_analyse_log() {
	global $config, $log;

	$result = array(
		'name' => 'Analyse cacti log',
		'alarm' => 'green',
		'data' => '',
		'detail' => '',
	);

//	if (read_config_option('intropage_analyse_log')) {
	$log = array(
			'file' => read_config_option('path_cactilog'),
			'nbr_lines' => read_config_option('intropage_analyse_log_rows'),
		);
	$log['size']  = filesize($log['file']);
	$log['lines'] = tail_log($log['file'], $log['nbr_lines']);

	if (!$log['size'] || !isset($log['lines'])) {
		$result['alarm'] = 'red';
		$result['data'] .= 'Log file not accessible';
	} else {
		$error  = 0;
		$ecount = 0;
		$warn   = 0;
		foreach ($log['lines'] as $line) {
			if (preg_match('/(WARN|ERROR|FATAL)/', $line, $matches)) {
				if (strcmp($matches[1], 'WARN') === 0) {
					$warn++;
					$ecount++;
					$result['detail'] .= '<b>' . $line . '</b><br/>';
				} elseif (strcmp($matches[1], 'ERROR') === 0 || strcmp($matches[1], 'FATAL') === 0) {
					$error++;
					$ecount++;
					$result['detail'] .= '<b>' . $line .'</b><br/>';
				}
			}
		}

		$result['data'] .= '<span class="txt_big">';
		$result['data'] .= 'Errors: ' . $error . '<br/>';
		$result['data'] .= 'Warnings: ' . $warn . '<br/>';
		$result['data'] .= '</span>';



		if ($log['size'] < 0) {
			$result['alarm'] = 'red';
			$result['data'] .= '<span class="txt_big">Log size: file is larger than 2GB</span><br/>';
		} elseif ($log['size'] < 255999999) {
			$result['data'] .= '<span class="txt_big">Log size: ' . human_filesize($log['size']) . '</span><br/>(Log size OK)<br/>';
		} else {
			$result['alarm'] = 'yellow';
			$result['data'] .= '<span class="txt_big">Log size: ' . human_filesize($log['size']) . '</span><br/>(Logfile is quite large)<br/>';
		}


		$result['data'] .= '<br/>(errors and warning in last ' . read_config_option('intropage_analyse_log_rows') . ' lines)';

		if ($error > 0) {
			$result['alarm'] = 'red';
		}

		if ($warn > 0 && $result['alarm'] == 'green') {
			$result['alarm'] = 'yellow';
		}
	}

	return $result;
}



//------------------------------------ analyse_login -----------------------------------------------------


function intropage_analyse_login() {
	global $config;

	$result = array(
		'name' => 'Last 10 logins',
		'alarm' => 'green',
		'data' => '',
		'detail' => '',
	);


	// active users in last hour:

	$flog       = 0;
	$sql_result = db_fetch_assoc('SELECT user_log.username, user_auth.full_name, user_log.time, user_log.result, user_log.ip FROM user_auth INNER JOIN user_log ON user_auth.username = user_log.username ORDER  BY user_log.time desc LIMIT 10');
	foreach ($sql_result as $row) {
		if ($row['result'] == 0) {
			$result['alarm'] = 'red';
			$flog++;
		}
		$result['detail'] .= sprintf('%s | %s | %s | %s<br/>', $row['time'], $row['ip'], $row['username'], ($row['result'] == 0) ? 'failed' : 'succes');
	}
	$result['data'] = '<span class="txt_big">Failed logins: ' . $flog . '</span><br/><br/>';


	// active users in last hour:
	$result['data'] .= 'Active users in last hour:<br/>';
	$sql_result = db_fetch_assoc('select distinct username from user_log  where time > adddate(now(), INTERVAL -1 HOUR)');
	foreach ($sql_result as $row) {
		$result['data'] .= $row['username'] . '<br/>';
	}

	$loggin_access = (db_fetch_assoc("select realm_id from user_auth_realm where user_id='" . $_SESSION['sess_user_id'] . "' and user_auth_realm.realm_id=19")) ? true : false;
	if ($result['detail'] && $loggin_access) {
		$result['detail'] .= '<br/><br/><a href="' . htmlspecialchars($config['url_path']) . 'utilities.php?action=view_user_log">Full log</a><br/>';
	}

	return $result;
}



//------------------------------------ analyse_tree_host_graph  -----------------------------------------------------


function intropage_analyse_tree_host_graph() {
	global $config, $allowed_hosts;

	$result = array(
	'name' => 'Analyse tree/host/graph',
	'alarm' => 'green',
	'data' => '',
	'detail' => '',
	);

	$total_errors = 0;

	// hosts with same IP

	$pom = 0;
	$result['data'] .= 'Devices with the same IP: ';

	$sql_result = db_fetch_assoc("SELECT count(*) NoDups, id, hostname FROM host  WHERE id IN ($allowed_hosts)  AND disabled != 'on'  GROUP BY hostname,snmp_port HAVING count(*)>1");

	$result['data'] .= count($sql_result) . '<br/>';

	if (count($sql_result) > 0) {
		$result['alarm'] = 'red';
		foreach ($sql_result as $row) {
			$sql_hosts = db_fetch_assoc_prepared("SELECT id,description,hostname from host WHERE hostname IN(SELECT  hostname FROM host  WHERE id IN ($allowed_hosts) GROUP BY hostname,snmp_port HAVING count(*)>1) order by hostname");
			foreach ($sql_hosts as $row) {
				if ($pom == 0) {
					$pom++;
					$result['detail'] .= '<br/><br/>Device with same ip and port:<br/>';
				}

				$result['detail'] .= sprintf('<a href="%shost.php?action=edit&amp;id=%d">%s %s (ID: %d)</a><br/>', htmlspecialchars($config['url_path']), $row['id'], $row['description'], $row['hostname'], $row['id']);
			}
		}
	}
	$total_errors += count($sql_result);



	// same description

	$result['data'] .= 'Hosts with the same description: ';

	$pom        = 0;
	$sql_result = db_fetch_assoc("SELECT count(*) NoDups, description FROM host  WHERE id IN ($allowed_hosts) AND  disabled != 'on' GROUP BY description HAVING count(*)>1");
	$result['data'] .= count($sql_result) . '<br/>';

	$total_errors += count($sql_result);

	if (count($sql_result) > 0) {
		$result['alarm'] = 'red';
		foreach ($sql_result as $row) {
			$sql_hosts = db_fetch_assoc_prepared('SELECT id,description,hostname from host WHERE description IN(SELECT  description FROM host  WHERE id IN (' . $allowed_hosts . ') GROUP BY description HAVING count(*)>1) ORDER BY description');
			foreach ($sql_hosts as $row) {
				if ($pom == 0) {
					$pom++;
					$result['detail'] .= 'Same description:<br/>';
				}
				$result['detail'] .= sprintf('<a href="%shost.php?action=edit&amp;id=%d">%s (ID: %d)</a><br/>', htmlspecialchars($config['url_path']), $row['id'], $row['description'], $row['id']);
			}
		}
	}
	$total_errors += count($sql_result);



	// orphaned DS
	// orphaned
	$sql_result = db_fetch_assoc('SELECT dtd.local_data_id, dtd.name_cache, dtd.active, dtd.rrd_step, dt.name AS data_template_name, dl.host_id, dtd.data_source_profile_id, COUNT(DISTINCT gti.local_graph_id) AS deletable FROM data_local AS dl INNER JOIN data_template_data AS dtd ON dl.id=dtd.local_data_id LEFT JOIN data_template AS dt ON dl.data_template_id=dt.id LEFT JOIN data_template_rrd AS dtr ON dtr.local_data_id=dtd.local_data_id LEFT JOIN graph_templates_item AS gti ON (gti.task_item_id=dtr.id) GROUP BY dl.id HAVING deletable=0 ORDER BY `name_cache` ASC');
	$result['data'] .= 'Orphaned DS: ' . count($sql_result);
	if (count($sql_result) > 0) {
		if ($result['alarm'] == 'green') {
			$result['alarm'] = 'yellow';
		}

		$result['detail'] .= 'Orphaned DS detail:<br/>';
		foreach ($sql_result as $row) {
			$result['detail'] .= '<a href="' . htmlspecialchars($config['url_path']) . 'data_sources.php?action=ds_edit&id=' . $row['local_data_id'] . '">' .
			$row['name_cache'] . '</a><br/>';
		}
	}
	$total_errors += count($sql_result);



	// below - only information without red/yellow/green
	$result['data'] .= '<br/><b>Information only (no warn/error):</b><br/>';


	// device in more trees

	$pom = 0;
	$result['data'] .= 'Devices in more then one tree: ';

	$sql_result = db_fetch_assoc('SELECT host.id, host.description, count(*) AS count FROM host INNER JOIN graph_tree_items ON (host.id = graph_tree_items.host_id) GROUP BY description HAVING count(*)>1');
	$result['data'] .= count($sql_result) . '<br/>';

	if (count($sql_result) > 0) {
//	if ($result['alarm'] == 'green')
//	    $result['alarm'] = 'yellow';

		foreach ($sql_result as $row) {
			$sql_hosts = db_fetch_assoc_prepared('SELECT graph_tree.id as gtid, host.description, graph_tree_items.title, graph_tree_items.parent, graph_tree.name FROM host INNER JOIN graph_tree_items ON (host.id = graph_tree_items.host_id) INNER JOIN graph_tree ON (graph_tree_items.graph_tree_id = graph_tree.id) WHERE host.id = ?', array($row['id']));
			foreach ($sql_hosts as $host) {
				$parent = $host['parent'];
				$tree   = $host['name'] . ' / ';
				while ($parent != 0) {
					$sql_parent = db_fetch_row('SELECT parent, title FROM graph_tree_items WHERE id = ' . $parent);
					$parent     = $sql_parent['parent'];
					$tree .= $sql_parent['title'] . ' / ';
				}

				if ($pom == 0) {
					$pom++;
					$result['detail'] .= '<br/>Device on more then one tree:<br/>';
				}

				$result['detail'] .= sprintf('<a href="%stree.php?action=edit&id=%d">Node: %s | Tree: %s</a><br/>', htmlspecialchars($config['url_path']), $host['gtid'], $host['description'], $tree);
			}
		}
	}

	//    $total_errors += count($sql_result);

	// host without graph

	$pom = 0;
	$result['data'] .= 'Hosts without graphs: ';

	$sql_result = db_fetch_assoc("SELECT id , description FROM host WHERE id IN ($allowed_hosts) AND  disabled != 'on'  AND id NOT IN (SELECT DISTINCT host_id FROM graph_local) AND snmp_version != 0");

	$result['data'] .= count($sql_result) . '<br/>';
	if (count($sql_result) > 0) {
//	if ($result['alarm'] == 'green')
//	    $result['alarm'] = 'yellow';

		foreach ($sql_result as $row) {
			if ($pom == 0) {
				$pom++;
				$result['detail'] .= '<br/><br/>Host without graph:<br/>';
			}

			$result['detail'] .= sprintf('<a href="%shost.php?action=edit&amp;id=%d">%s (ID: %d)</a><br/>', htmlspecialchars($config['url_path']), $row['id'], $row['description'], $row['id']);
		}
	}

	//    $total_errors += count($sql_result);


	// host without tree

	$pom = 0;
	$result['data'] .= 'Hosts without tree: ';

	$sql_result = db_fetch_assoc("SELECT id , description FROM host WHERE id IN ($allowed_hosts) AND  disabled != 'on' AND  id NOT IN (SELECT DISTINCT host_id FROM graph_tree_items)");
	$result['data'] .= count($sql_result) . '<br/>';
	if (count($sql_result) > 0) {
//	if ($result['alarm'] == 'green')
//	    $result['alarm'] = 'yellow';

		foreach ($sql_result as $row) {
			if ($pom == 0) {
				$pom++;
				$result['detail'] .= '<br/><br/>Hosts without tree:<br/>';
			}

			$result['detail'] .= sprintf('<a href="%shost.php?action=edit&amp;id=%d">%s (ID: %d)</a><br/>', htmlspecialchars($config['url_path']), $row['id'], $row['description'], $row['id']);
		}
	}

	//    $total_errors += count($sql_result);




	// plugin monitor - host without monitoring
	$pom = 0;
	if (db_fetch_cell("SELECT directory FROM plugin_config where directory='monitor'")) {	// installed plugin monitor?

		$result['data'] .= 'Plugin monitor, not monitored: ';

		$sql_result = db_fetch_assoc("SELECT id,description,hostname FROM host WHERE id in ($allowed_hosts) and monitor != 'on'");

		$result['data'] .= count($sql_result) . '<br/>';

		if (count($sql_result) > 0) {

//	    if ($result['alarm'] == 'green')
			//    		$result['alarm'] = 'yellow';

			foreach ($sql_result as $row) {
				if ($pom == 0) {
					$pom++;
					$result['detail'] .= '<br/><br/>Plugin monitor, not monitored devices:<br/>';
				}

				$result['detail'] .= sprintf('<a href="%shost.php?action=edit&amp;id=%d">%s %s (ID: %d)</a><br/>', htmlspecialchars($config['url_path']), $row['id'], $row['description'], $row['hostname'], $row['id']);
			}
		}
	}

	//    $total_errors += count($sql_result);



	// public/private community
	//select id,hostname from host where snmp_community ='public' or snmp_community='private'
	$pom = 0;
	$result['data'] .= 'Hosts with default public/private community: ';

	$sql_result = db_fetch_assoc("SELECT id,description FROM host WHERE id IN ($allowed_hosts) AND  disabled != 'on' AND (snmp_community ='public' or snmp_community='private') order by description");

	$result['data'] .= count($sql_result) . '<br/>';
	if (count($sql_result) > 0) {
//	if ($result['alarm'] == 'green')
//	    $result['alarm'] = 'yellow';

		foreach ($sql_result as $row) {
			if ($pom == 0) {
				$pom++;
				$result['detail'] .= '<br/><br/>Default community name (public/private):<br/>';
			}

			$result['detail'] .= sprintf('<a href="%shost.php?action=edit&amp;id=%d">%s (ID: %d)</a><br/>', htmlspecialchars($config['url_path']), $row['id'], $row['description'], $row['id']);
		}
	}

	//    $total_errors += count($sql_result);

	if ($total_errors > 0) {
		$result['data'] = '<span class="txt_big">Found ' . $total_errors . ' problems</span><br/>' . $result['data'];
	} else {
		$result['data'] = '<span class="txt_big">Everything OK</span><br/>' . $result['data'];
	}

	return $result;
}

//------------------------------------ boost -----------------------------------------------------


function intropage_boost() {
	global $config, $boost_refresh_interval, $boost_max_runtime;

	$result = array(
	'name' => 'Boost statistics',
	'alarm' => 'green',
	'data' => '',
	'detail' => '',
	);


	// from lib/boost.php

	$rrd_updates     = read_config_option('boost_rrd_update_enable', true);
	$last_run_time   = read_config_option('boost_last_run_time', true);
	$next_run_time   = read_config_option('boost_next_run_time', true);

	$max_records     = read_config_option('boost_rrd_update_max_records', true);
	$max_runtime     = read_config_option('boost_rrd_update_max_runtime', true);
	$update_interval = read_config_option('boost_rrd_update_interval', true);
	$peak_memory     = read_config_option('boost_peak_memory', true);
	$detail_stats    = read_config_option('stats_detail_boost', true);

	/* get the boost table status */
	$boost_table_status = db_fetch_assoc("SELECT *
                FROM INFORMATION_SCHEMA.TABLES WHERE table_schema=SCHEMA()
                AND (table_name LIKE 'poller_output_boost_arch_%' OR table_name LIKE 'poller_output_boost')");

	$pending_records = 0;
	$arch_records    = 0;
	$data_length     = 0;
	$engine          = '';
	$max_data_length = 0;

	foreach ($boost_table_status as $table) {
		if ($table['TABLE_NAME'] == 'poller_output_boost') {
			$pending_records += $table['TABLE_ROWS'];
		} else {
			$arch_records += $table['TABLE_ROWS'];
		}

		$data_length += $table['DATA_LENGTH'];
		$data_length += $table['INDEX_LENGTH'];
		$engine          = $table['ENGINE'];
		$max_data_length = $table['MAX_DATA_LENGTH'];
	}

	$total_records  = $pending_records + $arch_records;
	$avg_row_length = ($total_records ? intval($data_length / $total_records) : 0);

	$boost_status = read_config_option('boost_poller_status', true);
	if ($boost_status != '') {
		$boost_status_array = explode(':', $boost_status);

		$boost_status_date  = $boost_status_array[1];

		if (substr_count($boost_status_array[0], 'complete')) {
			$boost_status_text = __('Idle');
		} elseif (substr_count($boost_status_array[0], 'running')) {
			$boost_status_text = __('Running');
		} elseif (substr_count($boost_status_array[0], 'overrun')) {
			$boost_status_text = __('Overrun Warning');
			$result['alarm']   = 'red';
		} elseif (substr_count($boost_status_array[0], 'timeout')) {
			$boost_status_text = __('Timed Out');
			$result['alarm']   = 'red';
		} else {
			$boost_status_text = __('Other');
		}
	} else {
		$boost_status_text = __('Never Run');
		$boost_status_date = '';
	}


	if ($total_records) {
		$result['data'] .= __('Pending Boost Records: ') . number_format_i18n($pending_records, -1) . '<br/>';

		$result['data'] .= __('Archived Boost Records: ') . number_format_i18n($arch_records, -1) . '<br/>';

		if ($total_records > ($max_records - ($max_records / 10)) && $result['alarm'] == 'green') {
			$result['alarm'] = 'yellow';
			$result['data'] .= '<b>' . __('Total Boost Records: ') . number_format_i18n($total_records, -1) . '</b><br/>';
		} elseif ($total_records > ($max_records - ($max_records / 20)) && $result['alarm'] == 'green') {
			$result['alarm'] = 'red';
			$result['data'] .= '<b>' . __('Total Boost Records: ') . number_format_i18n($total_records, -1) . '</b><br/>';
		} else {
			$result['data'] .= __('Total Boost Records: ') . number_format_i18n($total_records, -1) . '<br/>';
		}
	}


	$stats_boost = read_config_option('stats_boost', true);
	if ($stats_boost != '') {
		$stats_boost_array = explode(' ', $stats_boost);

		$stats_duration          = explode(':', $stats_boost_array[0]);
		$boost_last_run_duration = $stats_duration[1];

		$stats_rrds         = explode(':', $stats_boost_array[1]);
		$boost_rrds_updated = $stats_rrds[1];
	} else {
		$boost_last_run_duration = '';
		$boost_rrds_updated      = '';
	}


	$result['data'] .= __('Boost On-demand Updating:') . ' ' . ($rrd_updates == '' ? 'Disabled' : $boost_status_text) . '<br/>';

	$data_length = db_fetch_cell("SELECT data_length 
                FROM INFORMATION_SCHEMA.TABLES WHERE table_schema=SCHEMA()
                AND (table_name LIKE 'poller_output_boost_arch_%' OR table_name LIKE 'poller_output_boost')");

	/* tell the user how big the table is */
	$result['data'] .= __('Current Boost Table(s) Size:') . ' ' . human_filesize($data_length) . '<br/>';

	/* tell the user about the average size/record */
	$result['data'] .= __('Avg Bytes/Record:') . ' ' . human_filesize($avg_row_length) . '<br/>';


	$result['data'] .= 'Last run duration: ';
	if (is_numeric($boost_last_run_duration)) {
		$result['data'] .= $boost_last_run_duration . ' s';
	} else {
		$result['data'] .= __('N/A');
	}
	$result['data'] .= '<br/>';


	$result['data'] .= __('RRD Updates') . ' / Max: ' . ($boost_rrds_updated != '' ? number_format_i18n($boost_rrds_updated, -1) : '-') . ' / ' . number_format_i18n($max_records, -1)  . '<br/>';
	//    $result['data'] .= __('Maximum Records:') . ' ' . number_format_i18n($max_records, -1) .  '<br/>';

	$result['data'] .= __('Update Frequency:') . ' ' . ($rrd_updates == '' ? __('N/A') : $boost_refresh_interval[$update_interval]) . '<br/>';

	$result['data'] .= __('Next Start Time:') . ' ' . $next_run_time . '<br/>';




	return $result;
}


//------------------------------------ cpu -----------------------------------------------------


function intropage_cpu() {
	global $config;

	$result = array(
	'name' => 'CPU utilization',
	'alarm' => 'grey',
	'data' => '',
		'line' => array(
			'title' => 'CPU load: ',
			'label1' => array(),
			'data1' => array(),
		),
	);


	if (stristr(PHP_OS, 'win')) {
		$result['data'] = 'This function is not implemented on Windows platforms';
		unset($result['line']);
	} else {
		$sql = db_fetch_assoc("SELECT date_format(time(date),'%H:%i') as xdate,name,value FROM plugin_intropage_trends where name='cpuload' order by date desc limit 10");
		if (count($sql)) {
			$result['line']['title1'] = 'Load';
			foreach ($sql as $row) {
				array_push($result['line']['label1'], $row['xdate']);
				array_push($result['line']['data1'], $row['value']);
			}
			$result['line']['data1']  = array_reverse($result['line']['data1']);
			$result['line']['label1'] = array_reverse($result['line']['label1']);
		} else {
			unset($result['line']);
			$result['data'] = 'Waiting for data';
		}
	}

	return $result;
}


//------------------------------------ extrem -----------------------------------------------------

function intropage_extrem() {
	global $config, $allowed_hosts, $console_access;

	$result = array(
		'name' => '24 hour extrem',
		'alarm' => 'grey',
		'data' => '',
	);


	$result['data'] .= '<table><tr><td class="rpad">';

	// long run poller
	$result['data'] .= '<strong>Long run<br/>poller: </strong>';
	$sql_result = db_fetch_assoc("select date_format(time(date),'%H:%i') as xdate,substring(value,instr(value,':')+1) as xvalue FROM plugin_intropage_trends WHERE name='poller' and date > date_sub(date,interval 1 day) order by xvalue desc, date  limit 5");
	if (count($sql_result) > 0) {
		foreach ($sql_result as $row) {
			$result['data'] .= '<br/>' . $row['xdate'] . ' ' . $row['xvalue'] . 's';
		}
	} else {
		$result['data'] .= '<br/>Waiting<br/>for data';
	}
	$result['data'] .= '</td><td class="rpad texalirig">';

	// max host down
	$result['data'] .= '<strong>Max host<br/>down: </strong>';
	$sql_result = db_fetch_assoc("select date_format(time(date),'%H:%i') as xdate,value FROM plugin_intropage_trends WHERE name='host' and date > date_sub(date,interval 1 day) order by value desc,date limit 5");
	if (count($sql_result) > 0) {
		foreach ($sql_result as $row) {
			$result['data'] .= '<br/>' . $row['xdate'] . ' ' . $row['value'];
		}
	} else {
		$result['data'] .= '<br/>Waiting<br/>for data';
	}

	$result['data'] .= '</td><td class="rpad texalirig">';

	// max thold trig
	// extrems don't use user permission!
	$result['data'] .= '<strong>Max thold<br/>triggered: </strong>';

	if (db_fetch_cell("SELECT directory FROM plugin_config where directory='thold' and status=1")) {
		$sql_result = db_fetch_assoc("select date_format(time(date),'%H:%i') as xdate,value FROM plugin_intropage_trends WHERE name='thold' and date > date_sub(date,interval 1 day) order by value desc,date limit 5");
		if (count($sql_result) > 0) {
			foreach ($sql_result as $row) {
				$result['data'] .= '<br/>' . $row['xdate'] . ' ' . $row['value'];
			}
		} else {
			$result['data'] .= '<br/>Waiting<br/>for data';
		}
	} else {
		$result['data'] .= '<br/>no<br/>plugin<br/>installed<br/>or<br/> running';
	}

	$result['data'] .= '</td></tr>';

	$result['data'] .= '</table>';


	return $result;
}

//------------------------------------ graph_datasource -----------------------------------------------------


function intropage_graph_data_source() {
	global $config, $input_types;

	$result = array(
		'name' => 'Data sources',
		'alarm' => 'grey',
		'data' => '',
		'pie' => array(
			'title' => 'Datasources: ',
			'label' => array(),
			'data' => array(),
		),
	);

	$sql_ds = db_fetch_assoc('SELECT data_input.type_id, COUNT(data_input.type_id) AS total FROM data_local INNER JOIN data_template_data ON (data_local.id = data_template_data.local_data_id) LEFT JOIN data_input ON (data_input.id=data_template_data.data_input_id) LEFT JOIN data_template ON (data_local.data_template_id=data_template.id) WHERE local_data_id<>0 group by type_id LIMIT 6');
	if (sizeof($sql_ds) > 0) {
		foreach ($sql_ds as $item) {
			if (!is_null($item['type_id'])) {
				array_push($result['pie']['label'], preg_replace('/script server/', 'SS', $input_types[$item['type_id']]));
				array_push($result['pie']['data'], $item['total']);

				$result['data'] .= preg_replace('/script server/', 'SS', $input_types[$item['type_id']]) . ': ';
				$result['data'] .= $item['total'] . '<br/>';
			}
		}
	} else {
		$result['data'] = 'Waiting for data';
		unset($result['pie']);
	}

	return $result;
}


//------------------------------------ graph_host -----------------------------------------------------


function intropage_graph_host() {
	global $config, $allowed_hosts, $console_access;

	$result = array(
		'name' => 'Hosts',
		'data' => '',
		'alarm' => 'green',
		'detail' => '',
	);

	$h_all  = db_fetch_cell("SELECT count(id) FROM host WHERE id IN ($allowed_hosts)");
	$h_up   = db_fetch_cell("SELECT count(id) FROM host WHERE id IN ($allowed_hosts) AND status=3 AND disabled=''");
	$h_down = db_fetch_cell("SELECT count(id) FROM host WHERE id IN ($allowed_hosts) AND status=1 AND disabled=''");
	$h_reco = db_fetch_cell("SELECT count(id) FROM host WHERE id IN ($allowed_hosts) AND status=2 AND disabled=''");
	$h_disa = db_fetch_cell("SELECT count(id) FROM host WHERE id IN ($allowed_hosts) AND disabled='on'");

	$count = $h_all + $h_up + $h_down + $h_reco + $h_disa;

	if ($console_access) {
		$result['data']  = '<a href="' . htmlspecialchars($config['url_path']) . 'host.php?host_status=-1">All: ' . $h_all . '</a><br/>';
		$result['data'] .= '<a href="' . htmlspecialchars($config['url_path']) . 'host.php?host_status=3">Up: ' . $h_up . '</a><br/>';
		$result['data'] .= '<a href="' . htmlspecialchars($config['url_path']) . 'host.php?host_status=1">Down: ' . $h_down . '</a><br/>';
		$result['data'] .= '<a href="' . htmlspecialchars($config['url_path']) . 'host.php?host_status=-2">Disabled: ' . $h_disa . '</a><br/>';
		$result['data'] .= '<a href="' . htmlspecialchars($config['url_path']) . 'host.php?host_status=2">Recovering: ' . $h_reco . '</a>';
	} else {
		$result['data']  = 'All: ' . $h_all . '<br/>';
		$result['data'] .= 'Up: ' . $h_up . '<br/>';
		$result['data'] .= 'Down: ' . $h_down . '<br/>';
		$result['data'] .= 'Disabled: ' . $h_disa . '<br/>';
		$result['data'] .= 'Recovering: ' . $h_reco;
	}

	if ($count > 0) {
		$result['pie'] = array('title' => 'Hosts: ', 'label' => array('Up', 'Down', 'Recovering', 'Disabled'), 'data' => array($h_up, $h_down, $h_reco, $h_disa));
	} else {
		unset($result['pie']);
	}



	// alarms and details
	if ($h_reco > 0) {
		$result['alarm'] = 'yellow';
		$hosts           = db_fetch_assoc("SELECT description FROM host WHERE id IN ($allowed_hosts) AND status=2 AND disabled=''");
		$result['detail'] .= '<b>RECOVERING:</b><br/>';
		foreach ($hosts as $host) {
			$result['detail'] .= $host['description'] . '<br/>';
		}
		$result['detail'] .= '<br/><br/>';
	}

	if ($h_down > 0) {
		$result['alarm'] = 'red';
		$hosts           = db_fetch_assoc("SELECT description FROM host WHERE id IN ($allowed_hosts) AND status=1 AND disabled=''");
		$result['detail'] .= '<b>DOWN:</b><br/>';
		foreach ($hosts as $host) {
			$result['detail'] .= $host['description'] . '<br/>';
		}
		$result['detail'] .= '<br/><br/>';
	}


	return $result;
}



//------------------------------------ graph host_template -----------------------------------------------------

function intropage_graph_host_template() {
	global $config, $allowed_hosts;

	$result = array(
		'name' => 'Host templates',
		'alarm' => 'grey',
		'data' => '',
		'pie' => array(
			'title' => 'Host Templates: ',
			'label' => array(),
			'data' => array(),
		),
	);

	$sql_ht = db_fetch_assoc("SELECT host_template.id as id, name, count(host.host_template_id) AS total FROM host_template LEFT JOIN host ON (host_template.id = host.host_template_id) AND host.id IN ($allowed_hosts) GROUP by host_template_id ORDER BY total desc LIMIT 6");
	if (sizeof($sql_ht) > 0) {
		foreach ($sql_ht as $item) {
			array_push($result['pie']['label'], $item['name']);
			array_push($result['pie']['data'], $item['total']);

			$result['data'] .= $item['name'] . ': ';
			$result['data'] .= $item['total'] . '<br/>';
		}
	} else {
		unset($result['pie']);
		$result['data'] = 'Waiting for data';
	}

	return $result;
}

//------------------------------------ graph_thold -----------------------------------------------------

function intropage_graph_thold() {
	global $config, $sql_where;

	$result = array(
		'name' => 'Thresholds',
		'data' => '',
		'alarm' => 'green',
		'detail' => '',
		'pie' => array(
			'title' => 'Thresholds: ',
			'label' => array(),
			'data' => array(),
		),

	);

	if (!db_fetch_cell("SELECT directory FROM plugin_config where directory='thold' and status=1")) {
		$result['alarm'] = 'grey';
		$result['data']  = 'Thold plugin not installed/running';
	} elseif (!db_fetch_cell('SELECT DISTINCT user_id FROM user_auth_realm WHERE user_id = ' . $_SESSION['sess_user_id'] . " AND realm_id IN (SELECT id + 100 FROM plugin_realms WHERE file LIKE '%thold%')")) {
		$result['data'] = "You don't have permission";
	} else {
		$sql_join = ' LEFT JOIN host ON thold_data.host_id=host.id     LEFT JOIN user_auth_perms ON ((thold_data.graph_template_id=user_auth_perms.item_id AND user_auth_perms.type=1 AND user_auth_perms.user_id= ' . $_SESSION['sess_user_id'] . ') OR
			(thold_data.host_id=user_auth_perms.item_id AND user_auth_perms.type=3 AND user_auth_perms.user_id= ' . $_SESSION['sess_user_id'] . ') OR
			(thold_data.graph_template_id=user_auth_perms.item_id AND user_auth_perms.type=4 AND user_auth_perms.user_id= ' . $_SESSION['sess_user_id'] . '))';

		$t_all  = db_fetch_cell("SELECT COUNT(*) FROM thold_data $sql_join WHERE $sql_where");
		$t_brea = db_fetch_cell("SELECT COUNT(*) FROM thold_data $sql_join WHERE (thold_data.thold_alert!=0 OR thold_data.bl_alert>0) AND $sql_where");
		$t_trig = db_fetch_cell("SELECT COUNT(*) FROM thold_data $sql_join WHERE (thold_data.thold_alert!=0 OR thold_data.bl_fail_count >= thold_data.bl_fail_trigger) AND $sql_where");
		$t_trig = db_fetch_cell("SELECT COUNT(*) FROM thold_data $sql_join WHERE ((thold_data.thold_alert!=0 AND thold_data.thold_fail_count >= thold_data.thold_fail_trigger) OR (thold_data.bl_alert>0 AND thold_data.bl_fail_count >= thold_data.bl_fail_trigger)) AND $sql_where");

		$t_disa = db_fetch_cell("SELECT COUNT(*) FROM thold_data $sql_join WHERE thold_data.thold_enabled='off' AND $sql_where");

		$count = $t_all + $t_brea + $t_trig + $t_disa;

		if (db_fetch_cell('SELECT COUNT(*) FROM user_auth_realm WHERE user_id = '.$_SESSION['sess_user_id']." AND realm_id IN (SELECT id + 100 FROM plugin_realms WHERE file LIKE '%thold_graph.php%')")) {
			$result['data']  = '<a href="' . htmlspecialchars($config['url_path']) . "plugins/thold/thold_graph.php?tab=thold&amp;triggered=-1\">All: $t_all</a><br/>";
			$result['data'] .= '<a href="' . htmlspecialchars($config['url_path']) . "plugins/thold/thold_graph.php?tab=thold&amp;triggered=1\">Breached: $t_brea</a><br/>";
			$result['data'] .= '<a href="' . htmlspecialchars($config['url_path']) . "plugins/thold/thold_graph.php?tab=thold&amp;triggered=3\">Trigged: $t_trig</a><br/>";
			$result['data'] .= '<a href="' . htmlspecialchars($config['url_path']) . "plugins/thold/thold_graph.php?tab=thold&amp;triggered=0\">Disabled: $t_disa</a><br/>";
		} else {
			$result['data']  = 'All: ' . $t_all . '<br/>';
			$result['data'] .= 'Breached: ' . $t_brea . '<br/>';
			$result['data'] .= 'Trigged: ' . $t_trig . '<br/>';
			$result['data'] .= 'Disabled: ' . $t_disa . '<br/>';
		}
		if ($count > 0) {
			$result['pie'] = array('title' => 'Thresholds: ', 'label' => array('OK', 'Breached', 'Trigerred', 'Disabled'), 'data' => array($t_all - $t_brea - $t_trig - $t_disa, $t_brea, $t_trig, $t_disa));
		} else {
			unset($result['pie']);
		}

		// alarms and details
		if ($t_brea > 0) {
			$result['alarm'] = 'yellow';
			$hosts           = db_fetch_assoc("select description FROM thold_data $sql_join WHERE (thold_data.thold_alert!=0 OR thold_data.bl_alert>0) AND $sql_where");
			$result['detail'] .= '<b>BREACHED:</b><br/>';
			foreach ($hosts as $host) {
				$result['detail'] .= $host['description'] . '<br/>';
			}
			$result['detail'] .= '<br/><br/>';
		}

		if ($t_trig > 0) {
			$result['alarm'] = 'red';
			$hosts           = db_fetch_assoc("SELECT description FROM thold_data $sql_join WHERE (thold_data.thold_alert!=0 OR thold_data.bl_fail_count >= thold_data.bl_fail_trigger) AND $sql_where");
			$result['detail'] .= '<b>TRIGGERED:</b><br/>';
			foreach ($hosts as $host) {
				$result['detail'] .= $host['description'] . '<br/>';
			}
			$result['detail'] .= '<br/><br/>';
		}
	}


	return $result;
}


//------------------------------------ info -----------------------------------------------------

function intropage_info() {
	global $config, $allowed_hosts, $poller_options;

	$result = array(
		'name' => 'Info',
		'alarm' => 'grey',
		'data' => '',

	);

	$xdata = '';

	$result['data'] .= 'Cacti version: ' . CACTI_VERSION . '<br/>';


	if ($poller_options[read_config_option('poller_type')] == 'spine' && file_exists(read_config_option('path_spine')) && (function_exists('is_executable')) && (is_executable(read_config_option('path_spine')))) {
		$spine_version = 'SPINE';
		exec(read_config_option('path_spine') . ' --version', $out_array);
		if (sizeof($out_array)) {
			$spine_version = $out_array[0];
		}

		$result['data'] .= 'Poller type: <a href="' . htmlspecialchars($config['url_path']) .  'settings.php?tab=poller">Spine</a><br/>';
		$result['data'] .= 'Spine version: ' . $spine_version . '<br/>';
		if (!strpos($spine_version, CACTI_VERSION, 0)) {
			$result['data'] .= '<span class="red">You are using incorrect spine version!</span><br/>';
			$result['alarm'] = 'red';
		}
	} else {
		$result['data'] .= 'Poller type: <a href="' . htmlspecialchars($config['url_path']) .  'settings.php?tab=poller">' . $poller_options[read_config_option('poller_type')] . '</a><br/>';
	}

	$result['data'] .= 'Running on: ';
	if (function_exists('php_uname')) {
		$xdata = php_uname();
	} else {
		$xdata .= PHP_OS;
	}

	$xdata2 = str_split($xdata, 50);
	$xdata  = join('<br/>', $xdata2);
	$result['data'] .= $xdata;

	return $result;
}




//------------------------------------ mactrack -----------------------------------------------------


function intropage_mactrack() {
	global $config, $console_access;

	$result = array(
		'name' => 'Mactrack',
		'alarm' => 'green',
	);

	// select id from plugin_realms where plugin='mactrack' and display like '%view%';
	// = 329 +100


	if (!db_fetch_cell("SELECT directory FROM plugin_config where directory='mactrack' and status=1")) {
		$result['alarm'] = 'grey';
		$result['data']  = 'Mactrack plugin not installed/running';
	} else {
		$mactrack_id = db_fetch_cell("select id from plugin_realms where plugin='mactrack' and display like '%view%'");
		if (!db_fetch_cell('SELECT DISTINCT user_id FROM user_auth_realm WHERE user_id = '.$_SESSION['sess_user_id'].' AND realm_id =' . ($mactrack_id + 100))) {
			$result['data'] =  "You don't have permission";
		} else {	// mactrack is running and you have permission
			/*
			$sql_no_mt = db_fetch_assoc("SELECT id, description, hostname FROM host WHERE id NOT IN (SELECT DISTINCT host_id FROM mac_track_devices) AND snmp_version != 0");
			if ($sql_no_mt) {
				$result['detail'] .= 'Host without mac-track: <br/>';
				foreach ($sql_no_mt as $item) {
					$result['detail'] .= ($console_access)?
						sprintf("<a href=\"%shost.php?action=edit&amp;id=%s\">%s-%s</a><br/>",$config['url_path'],$item['id'],$item['description'],$item['hostname']):
						sprintf("%s-%s<br/>",$item['description'],$item['hostname']);
				}
			}
			*/

			$m_all  = db_fetch_cell('select count(host_id) from mac_track_devices');
			$m_up   = db_fetch_cell("select count(host_id) from mac_track_devices where snmp_status='3'");
			$m_down = db_fetch_cell("select count(host_id) from mac_track_devices where snmp_status='1'");
			$m_disa = db_fetch_cell("select count(host_id) from mac_track_devices where snmp_status='-2'");
			$m_err  = db_fetch_cell("select count(host_id) from mac_track_devices where snmp_status='4'");
			$m_unkn = db_fetch_cell("select count(host_id) from mac_track_devices where snmp_status='0'");

			if ($m_down > 0 || $m_err > 0 || $m_unkn > 0) {
				$result['alarm'] = 'red';
			} elseif ($m_disa > 0) {
				$result['alarm'] = 'yellow';
			}

			$result['data']  = 'All: ' . $m_all . '</a> | ';
			$result['data'] .= 'Up: ' . $m_up . ' | ';
			$result['data'] .= 'Down: ' . $m_down . ' | ';
			$result['data'] .= 'Error: ' . $m_err . ' | ';
			$result['data'] .= 'Unknown: ' . $m_unkn . ' | ';
			$result['data'] .= 'Disabled: ' . $m_disa . ' | ';


			$result['pie'] = array('title' => 'MAC Tracks:', 'label' => array('Up', 'Down', 'Error', 'Unknown', 'Disabled'), 'data' => array($m_up, $m_down, $m_err, $m_unkn, $m_disa));
		}
	}

	return $result;
}

//------------------------------------ mactrack sites -----------------------------------------------------


function intropage_mactrack_sites() {
	global $config, $console_access;

	$result = array(
		'name' => 'Mactrack sites',
		'alarm' => 'grey',
		'data' => '',

	);


	// SELECT site_name, total_devices, total_device_errors, total_macs, total_ips, total_oper_ports, total_user_ports FROM mac_track_sites  order by total_devices desc limit 5;
	if (!db_fetch_cell("SELECT directory FROM plugin_config where directory='mactrack' and status=1")) {
		$result['alarm'] = 'grey';
		$result['data']  = 'Mactrack plugin not installed/running';
	} else {
		$result['data'] .= '<table><tr><td class="rpad">Site</td><td class="rpad">Devices</td>';
		$result['data'] .= '<td class="rpad">IPs</td><td class="rpad">Ports</td>';
		$result['data'] .= '<td class="rpad">Ports up</td><td class="rpad">MACs</td>';
		$result['data'] .= '<td class="rpad">Device errors</td></tr>';

		$sql_result = db_fetch_assoc('SELECT site_name, total_devices, total_device_errors, total_macs, total_ips, total_oper_ports, total_user_ports FROM mac_track_sites  order by total_devices desc limit 5');
		if (sizeof($sql_result) > 0) {
			foreach ($sql_result as $row) {
				$result['data'] .= '<tr><td>' . $row['site_name'] . '</td><td>' . $row['total_devices'] . '</td>';
				$result['data'] .= '<td>' . $row['total_ips'] . '</td><td>' . $row['total_user_ports'] . '</td>';
				$result['data'] .= '<td>' . $row['total_oper_ports'] . '</td><td>' . $row['total_macs'] . '</td>';
				$result['data'] .= '<td>' . $row['total_device_errors'] . '</td></tr>';
			}
			$result['data'] .= '</table>';
		} else {
			$result['data'] = 'No mactrack sites found';
		}
	}

	return $result;
}

//------------------------------------ ntp -----------------------------------------------------

function intropage_ntp() {
	global $config;

	$result = array(
		'name' => 'Time synchronization',
		'alarm' => 'green',
	);

	$ntp_server = read_config_option('intropage_ntp_server');

	if (filter_var($ntp_server, FILTER_VALIDATE_IP) || preg_match('/^(([a-z0-9]|[a-z0-9][a-z0-9\-]*[a-z0-9])\.)*([a-z0-9]|[a-z0-9][a-z0-9\-]*[a-z])$/i', $ntp_server)) {
		$ntp_time = ntp_time($ntp_server);
		if ($ntp_time > 0) {
			$diff_time = date('U') - $ntp_time;
			if ($diff_time < -600 || $diff_time > 600) {
				$result['alarm'] = 'red';
				$result['data']  = '<span class="txt_big">' . date('Y-m-d') . '<br/>'. date('H:i:s') . "</span><br/><br/>Please check time.<br/>It is different (more than $diff_time seconds) from NTP server $ntp_server";
			} elseif ($diff_time < -120 || $diff_time > 120) {
				$values['time']['alarm'] = 'yellow';
				$values['time']['data']  = '<span class="txt_big">' . date('Y-m-d') . '<br/>' . date('H:i:s') . "</span><br/><br/>Please check time.<br/>It is different (more than $diff_time seconds) from NTP server $ntp_server";
			} else {
				$result['data'] = '<span class="txt_big">' . date('Y-m-d') . '<br/>' . date('H:i:s') . "</span><br/><br/>Localtime is equal to NTP server<br/>$ntp_server";
			}
		} else {
			$result['alarm'] = 'red';
			$result['data']  = 'Unable to contact the NTP server indicated.<br/>Please check your configuration';
		}
	} else {
		$result['alarm'] = 'red';
		$result['data']  = 'Incorrect ntp server address, please insert IP or DNS name';
	}

	return $result;
}



//------------------------------------ poller_info -----------------------------------------------------

/*
		0 => '<div class="deviceUnknown">'    . __('New/Idle')     . '</div>',
		1 => '<div class="deviceUp">'         . __('Running')      . '</div>',
		2 => '<div class="deviceRecovering">' . __('Idle')         . '</div>',
		3 => '<div class="deviceDown">'       . __('Unknown/Down') . '</div>',
		4 => '<div class="deviceDisabled">'   . __('Disabled')     . '</div>',
		5 => '<div class="deviceDown">'       . __('Recovering')   . '</div>'
*/


function intropage_poller_info() {
	global $config, $sql_where;

	$result = array(
		'name' => 'Poller info',
		'alarm' => 'green',
	);

	$result['data'] = '<b>ID/Name/max. time/total time/state</b><br/>';

	$sql_pollers = db_fetch_assoc('SELECT id,name,status,last_update,total_time FROM poller ORDER BY id limit 5');

	$count    = count($sql_pollers);
	$ok       = 0;
	$running  = 0;
	$xpollers = array();
	if (sizeof($sql_pollers)) {
		foreach ($sql_pollers as $poller) {
			if ($poller['status'] == 0 || $poller['status'] == 1 || $poller['status'] == 2 || $poller['status'] == 5) {
				$ok++;
			}

			$age = db_fetch_cell('select time_to_sec(max(timediff(end_time,start_time))) from poller_time where poller_id = ' . $poller['id']);
			if ($age < 0) {
				$age = '---';
			}

			$result['data'] .= $poller['id'] . '/' .  $poller['name'] . '/' .
			 $age . 's/' .
			round($poller['total_time']) . 's/';
			if ($poller['status'] == 0) {
				$result['data'] .= 'New/Idle';
			} elseif ($poller['status'] == 1) {
				$result['data'] .= 'Running';
			} elseif ($poller['status'] == 2) {
				$result['data'] .= 'Idle';
			} elseif ($poller['status'] == 3) {
				$result['data'] .= 'Unkn/down';
			} elseif ($poller['status'] == 4) {
				$result['data'] .= 'Disabled';
			} elseif ($poller['status'] == 5) {
				$result['data'] .= 'Recovering';
			}

			$result['data'] .= '<br/>';
		}
	}

	$result['data'] = '<span class="txt_big">' . $ok . '</span>(ok)<span class="txt_big">/' . $count . '</span>(all)</span><br/>' . $result['data'];



	if ($count > $ok) {
		$result['alarm'] = 'red';
	} else {
		$result['alarm'] = 'green';
	}

	return $result;
}



//------------------------------------ poller_stat -----------------------------------------------------

function intropage_poller_stat() {
	global $config;


	$poller_interval = read_config_option('poller_interval');
	$result          = array(
		'name' => 'Poller stats (interval ' . $poller_interval . 's)',
		'alarm' => 'green',
		'data' => '',
				'line' => array(
						'title1' => '',
						'label1' => array(),
						'data1' => array(),
						'title2' => '',
						'label2' => array(),
						'data2' => array(),
						'title3' => '',
						'label3' => array(),
						'data3' => array(),
						'title4' => '',
						'label4' => array(),
						'data4' => array(),
						'title5' => '',
						'label5' => array(),
						'data5' => array(),
		),
	);


	$pollers   = db_fetch_assoc('SELECT id from poller order by id limit 5');
	$new_index = 1;
	foreach ($pollers as $xpoller) {
		$poller_time = db_fetch_assoc("SELECT  date_format(time(date),'%H:%i') as xdate,value from plugin_intropage_trends where name='poller' and value like '" . $xpoller['id'] . ":%' order by date desc limit 10");
		$poller_time = array_reverse($poller_time);

		foreach ($poller_time as $one_poller) {
			list($id, $time) = explode(':', $one_poller['value']);

			if ($time > ($poller_interval - 10)) {
				$result['alarm'] = 'red';
				$result['data'] .= '<b>' . $one_poller['xdate'] . ' Poller ID: ' . $xpoller['id'] . ' ' . $time . 's</b><br/>';
			} else {
				$result['data'] .= $one_poller['xdate'] . ' Poller ID: ' . $xpoller['id'] . ' ' . $time . 's<br/>';
			}

			// graph data
			array_push($result['line']['label' . $new_index], $one_poller['xdate']);
			array_push($result['line']['data' . $new_index], $time);

			$result['line']['title' . $new_index] = 'ID: ' . $xpoller['id'];
		}

		$new_index++;
	}

	if (count($result['line']['data1']) < 3) {
		$result['data'] = 'Waiting for data';
		unset($result['line']);
	}

	return $result;
}


//------------------------------------ thold_events -----------------------------------------------------


function intropage_thold_event() {
	global $config;

	$result = array(
		'name' => 'Last thold events',
		'alarm' => 'green',
		'data' => '',
		'detail' => '',
	);

	if (db_fetch_cell("select count(*) from plugin_config where directory='thold' and status = 1") == 0) {
		$result['alarm'] = 'yellow';
		$result['data']  = "Plugin Thold isn't installed or started";
	} else {
		$sql_result = db_fetch_assoc('SELECT tl.description as description,tl.time as time, tl.status as status, uap0.user_id AS user0, uap1.user_id AS user1, uap2.user_id AS user2 FROM plugin_thold_log AS tl INNER JOIN thold_data AS td ON tl.threshold_id=td.id INNER JOIN graph_local AS gl ON gl.id=td.local_graph_id LEFT JOIN graph_templates AS gt ON gt.id=gl.graph_template_id LEFT JOIN graph_templates_graph AS gtg ON gtg.local_graph_id=gl.id LEFT JOIN host AS h ON h.id=gl.host_id LEFT JOIN user_auth_perms AS uap0 ON (gl.id=uap0.item_id AND uap0.type=1) LEFT JOIN user_auth_perms AS uap1 ON (gl.host_id=uap1.item_id AND uap1.type=3) LEFT JOIN user_auth_perms AS uap2 ON (gl.graph_template_id=uap2.item_id AND uap2.type=4) HAVING (user0 IS NULL OR (user1 IS NULL OR user2 IS NULL)) ORDER BY `time` DESC LIMIT 10');
		if (sizeof($sql_result) > 0) {
			foreach ($sql_result as $row) {
				$result['data'] .= date('Y-m-d H:i:s', $row['time']) . ' - ' . $row['description'] . '<br/>';
				if ($row['status'] == 1 || $row['status'] == 4 || $row['status'] == 7) {
					$result['alarm'] = 'red';
				} elseif ($result['alarm'] == 'green' && ($row['status'] == 2 || $row['status'] == 3)) {
					$result['alarm'] == 'yellow';
				}
			}
		} else {
			$result['data'] = 'Without events yet';
		}
	}

	return $result;
}

//------------------------------------ top5_ping -----------------------------------------------------


function intropage_top5_ping() {
	global $config, $allowed_hosts, $console_access;

	$result = array(
		'name' => 'Top5 ping (avg, current)',
		'alarm' => 'green',
	);


	$sql_worst_host = db_fetch_assoc("SELECT description, id , avg_time, cur_time FROM host where host.id in ($allowed_hosts) and disabled != 'on' order by avg_time desc limit 5");
	if (sizeof($sql_worst_host) > 0) {
		$result['data'] = '<table>';
		foreach ($sql_worst_host as $host) {
			if ($console_access) {
				$result['data'] .= '<tr><td class="rpad"><a href="' . htmlspecialchars($config['url_path']) . 'host.php?action=edit&id=' . $host['id'] . '">' . $host['description'] . '</a>';
			} else {
				$result['data'] .= '<tr><td class="rpad">' . $host['description'] . '</td>';
			}

			$result['data'] .= '<td class="rpad texalirig">' . round($host['avg_time'], 2) . 'ms</td>';

			if ($host['cur_time'] > 1000) {
				$result['alarm'] = 'yellow';
				$result['data'] .= '<td class="rpad texalirig"><b>' . round($host['cur_time'], 2) . 'ms</b></td></tr>';
			} else {
				$result['data'] .= '<td class="rpad texalirig">' . round($host['cur_time'], 2) . 'ms</td></tr>';
			}
		}
		$result['data'] .= '</table>';
	} else {	// no data
		$result['data'] = 'Waiting for data';
	}

	return $result;
}


//------------------------------------ top5_availability -----------------------------------------------------

function intropage_top5_availability() {
	global $config, $allowed_hosts, $console_access;

	$result = array(
		'name' => 'Top5 worst availability',
		'alarm' => 'green',
	);


	$sql_worst_host = db_fetch_assoc("SELECT description, id, availability FROM host where  host.id in ($allowed_hosts) and disabled != 'on' order by availability  limit 5");

	if (sizeof($sql_worst_host) > 0) {
		$result['data'] = '<table>';

		foreach ($sql_worst_host as $host) {
			if ($console_access) {
				$result['data'] .= '<tr><td class="rpad"><a href="' . htmlspecialchars($config['url_path']) . 'host.php?action=edit&id=' . $host['id'] . '">' . $host['description'] . '</a>';
			} else {
				$result['data'] .= '<tr><td class="rpad">' . $host['description'] . '</td>';
			}

			if ($host['availability'] < 90) {
				$result['alarm'] = 'yellow';
				$result['data'] .= '<td class="rpad texalirig"><b>' . round($host['availability'], 2) . '%</b></td></tr>';
			} else {
				$result['data'] .= '<td class="rpad texalirig">' . round($host['availability'], 2) . '%</td></tr>';
			}
		}
		$result['data'] .= '</table>';
	} else {	// no data
		$result['data'] = 'Waiting for data';
	}

	return $result;
}


//------------------------------------ trend -----------------------------------------------------


function intropage_trend() {
	global $config, $allowed_hosts;

	$result = array(
		'name' => 'Trends',
		'alarm' => 'grey',
		'data' => '',

				'line' => array(
						'title' => 'Trends: ',
						'label1' => array(),
						'data1' => array(),
						'title1' => '',
						'data2' => array(),
						'title2' => '',
					),
	);

	if (db_fetch_cell("SELECT directory FROM plugin_config where directory='thold' and status=1")) {
		$sql = db_fetch_assoc("SELECT date_format(time(date),'%H:%i') as xdate,name,value FROM plugin_intropage_trends where name='thold' order by date desc limit 10");
		if (count($sql)) {
			$result['line']['title1'] = 'Tholds triggered';
			foreach ($sql as $row) {
				// no gd data
				$result['data'] .= $row['xdate'] . ' ' . $row['name'] . ' ' . $row['value'] . '<br/>';
				array_push($result['line']['label1'], $row['xdate']);
				array_push($result['line']['data1'], $row['value']);
			}
		}
		//else	{
		//unset ($result['line']['label1']);
		//unset ($result['line']['data1']);
		//}
	}
	// no plugin installed or running



	$sql = db_fetch_assoc("SELECT date_format(time(date),'%h:%i') as xdate,name,value FROM plugin_intropage_trends where name='host' order by date desc limit 10");
	if (count($sql)) {
		$result['line']['title2'] = 'Hosts down';

		foreach ($sql as $row) {
			// no gd data
			$result['data'] .= $row['xdate'] . ' ' . $row['name'] . ' ' . $row['value'] . '<br/>';
			array_push($result['line']['data2'], $row['value']);
		}
	}
	//else	{
	//unset ($result['line']['data2']);
	//}

	if (count($sql) < 3) {
		unset($result['line']);
		$result['data'] = 'Waiting for data';
	} else {
		$result['line']['data1'] = array_reverse($result['line']['data1']);
		$result['line']['data2'] = array_reverse($result['line']['data2']);

		$result['line']['label1'] = array_reverse($result['line']['label1']);
	}

	return $result;
}

//-----------------favourite graph----------

function intropage_favourite_graph($fav_graph_id) {
	global $config;

	if (isset($fav_graph_id)) {
		$result = array(
				'name' => 'Favourite graph ',
				'alarm' => 'grey',
				'data' => '',
				'detail' => '',
		);


		$result['name'] .= db_fetch_cell_prepared('select title_cache from graph_templates_graph where local_graph_id = ?',
				 array($fav_graph_id));

		$result['data'] = '<img src="' . $config['url_path'] . 'graph_image.php?' .
			'local_graph_id=' . $fav_graph_id . '&' .
			'graph_height=105&' .
			'graph_width=300&' .
			'graph_nolegend=true"/>&nbsp;';

		return $result;
	}
}
