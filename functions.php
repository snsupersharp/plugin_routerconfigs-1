<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2007-2017 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

function display_tabs() {
	global $config;

	/* ================= input validation ================= */
	get_filter_request_var('tab', FILTER_VALIDATE_REGEXP, array('options' => array('regexp' => '/^([a-zA-Z]+)$/')));
	/* ==================================================== */

	$tabs = array(
		'devices'  => __('Devices', 'routerconfigs'),
		'devtypes' => __('Device Types', 'routerconfigs'),
		'accounts' => __('Authentication', 'routerconfigs'),
		'backups'  => __('Backups', 'routerconfigs'),
		'compare'  => __('Compare', 'routerconfigs')
	);

	/* set the default tab */
 	$current_tab = get_nfilter_request_var('tab');
	if (!isset($current_tab) || !strlen($current_tab)) {
		$back_trace = debug_backtrace();
		$file_info = pathinfo($back_trace[0]['file']);
		$file_tab = preg_replace('~router-([a-zA-Z]+).php~','\\1',$file_info['basename']);
		if (array_key_exists($file_tab,$tabs)) {
			$current_tab = $file_tab;
		}
	}
	if (!isset($current_tab) || !strlen($current_tab)) {
		load_current_session_value('tab', 'sess_rc_tabs', 'devices');
		$current_tab = get_nfilter_request_var('tab');
	}
	$header_label = __('Technical Support [ %s ]', $tabs[$current_tab], 'routerconfigs');

	if (sizeof($tabs)) {
		/* draw the tabs */
		print "<div class='tabs'><nav><ul>\n";

		foreach (array_keys($tabs) as $tab_short_name) {
			print "<li class='subTab'><a class='tab" . (($tab_short_name == $current_tab) ? " selected'" : "'") .
				" href='" . htmlspecialchars($config['url_path'] .
				'plugins/routerconfigs/router-' . $tab_short_name . '.php' .
				'?tab=' . $tab_short_name) .
				"'>" . $tabs[$tab_short_name] . "</a></li>\n";
		}

		api_plugin_hook('routerconfigs_tab');

		print "</ul></nav></div>\n";
	}
}

function plugin_routerconfigs_maskpw($pass) {
	return !isset($pass) ? __('(Not Set)','routerconfigs') : __('(%s chars)',strlen($pass),'routerconfigs');
}

function plugin_routerconfigs_backtrace($skip = 1) {
	$backtrace = debug_backtrace();
	foreach ($backtrace as $trace) {
		if ($skip == 0) {
			plugin_routerconfigs_log('DEBUG: BACKTRACE: '. $trace['function'] . '() at ' . $trace['line'] . ' in ' . $trace['file']);
		} else {
			$skip--;
		}
	}
}

function plugin_routerconfigs_download($retry = false, $force = false, $devices = null) {
	ini_set('max_execution_time', '0');
	ini_set('memory_limit', '256M');

	$filter_devices = array();
	if ($devices != null && strlen($devices)) {
		plugin_routerconfigs_log(__('NOTICE: Starting manual backup of %s devices',sizeof($filter_devices),'routerconfigs'));
		$filter_devices = explode(',',$devices);
	} else {
		plugin_routerconfigs_log(__('NOTICE: Starting automatic backup','routerconfigs'));
		plugin_routerconfigs_start($force);
	}
	$start  = microtime(true);
	$stime  = time();
	$passed = array();
	$success = 0;
	$cfailed = 0;

	$backuppath = read_config_option('routerconfigs_backup_path');
	if (!is_dir($backuppath) || strlen($backuppath) < 2) {
		plugin_routerconfigs_log(__('FATAL: Backup Path is not set or is not a directory', 'routerconfigs'));
	} else {
		$tftpserver = read_config_option('routerconfigs_tftpserver');
		if (strlen($tftpserver) < 2) {
			plugin_routerconfigs_log(__('FATAL: TFTP Server is not set', 'routerconfigs'));
		} else {

			// Get device that have not backed up in 24 hours + 30 minutes and that haven't been tried in the last 30 minutes
			$lastattempt = '';
			$lastbackup = '';

			// If we aren't forcing all backups...
			if (sizeof($filter_devices)) {
				$lastbackup =  'AND id IN (' . implode(',',$filter_devices) .')';
			} elseif (!$force) {
				$lastattempt = $retry ? "AND $stime - lastattempt > 7200" : '';
				$lastbackup = "AND ($stime - (schedule * 86400)) - 3600 > lastbackup";
			}

			$sql = "SELECT *
				FROM plugin_routerconfigs_devices
				WHERE enabled = 'on'
				$lastbackup
				$lastattempt";
			plugin_routerconfigs_log('DEBUG: SQL: ' . str_replace("\n","",$sql));
			$devices = db_fetch_assoc($sql, false);

			$failed = array();
			$passed = array();

			if (sizeof($devices)) {
				foreach ($devices as $device) {
					$t = time();
					plugin_routerconfigs_log(__('DEBUG: Attempting download for %s', $device['hostname'], 'routerconfigs'));
					$found = plugin_routerconfigs_download_config($device);
					if ($found) {
						plugin_routerconfigs_log('NOTICE: Download successful for ' . $device['hostname']);
						$passed[] = array ('hostname' => $device['hostname']);
					} else {
						plugin_routerconfigs_log('NOTICE: Failed to download for ' . $device['hostname']);
						$fmsg = db_fetch_cell_prepared('SELECT lasterror
							FROM plugin_routerconfigs_devices
					                WHERE id = ?',
					                array($device['id']));
						$failed[] = array('hostname' => $device['hostname'], 'lasterror' => $fmsg);
					}
				}
			}

			$success = count($devices) - count($failed);
			$cfailed = count($failed);
			$totalsecs = time() - $stime;

			$notice_level = 'NOTICE:';
			if ($cfailed > 0) {
				$notice_level = 'WARNING:';
			}

			plugin_routerconfigs_log("$notice_level $success Devices Backed Up and $cfailed Devices Failed in $totalsecs seconds");

			if ($success != 0 || $cfailed != 0 || $retry != true) {
				/* print out failures */
				plugin_routerconfigs_message($message, __('%s devices backed up successfully.', $success, 'routerconfigs'));
				plugin_routerconfigs_message($message, __('%s devices failed to backup.', $cfailed, 'routerconfigs'));

				if (!empty($passed) && $retry) {
					plugin_routerconfigs_message($message, __('These devices have now backuped', 'routerconfigs'));
					plugin_routerconfigs_message($message, __('-------------------------------', 'routerconfigs'));
					foreach ($passed as $f) {
						plugin_routerconfigs_message($message, $f['hostname']);
					}
				}

				if (!empty($failed)) {
					plugin_routerconfigs_message($message, __('These devices failed to backup', 'routerconfigs'));
					plugin_routerconfigs_message($message, __('------------------------------', 'routerconfigs'));
					foreach ($failed as $f) {
						plugin_routerconfigs_message($message, $f['hostname'] . ' - ' . $f['lasterror']);
					}
				}

				$from_email = read_config_option('settings_from_email');
				$from_name  = read_config_option('settings_from_name');
				$to         = read_config_option('routerconfigs_email');

				if ($to != '' && $from_email != '') {
					if ($from_name == '') {
						$from_name = __('Config Backups', 'routerconfigs');
					}

					$from[0] = $from_email;
					$from[1] = $from_name;
					$subject = __('Network Device Configuration Backups%s', ($retry?__(' - Reattempt','routerconfigs') : ''), 'routerconfigs');

					send_mail($to, $from, __('Network Device Configuration Backups - Reattempt', 'routerconfigs'), $message, $filename = '', $headers = '', $html = true);
				}
			}
			/* remove old backups */
			plugin_routerconfigs_retention ();
		}
	}

	$end = microtime(true);
	$download_stats = sprintf('Time:%01.2f Downloaded:%s Failed:%s', $end - $start, $success, $cfailed);

	plugin_routerconfigs_log(__('STATS: ','routerconfigs') . $download_stats);

	plugin_routerconfigs_stop(sizeof($filter_devices) == 0);
}

function plugin_routerconfigs_message(&$message, $text) {
	plugin_routerconfigs_log("DEBUG: $text");
	$message .= $text .'<br>';
}

function plugin_routerconfigs_retention() {
	$backuppath = read_config_option('routerconfigs_backup_path');
	if (!is_dir($backuppath) || strlen($backuppath) < 2) {
		plugin_routerconfigs_log(__('ERROR: Backup Path is not set or is not a directory', 'routerconfigs'));
		exit;
	}

	$days = read_config_option('routerconfigs_retention');
	if ($days < 1 || $days > 365) {
		$days = 30;
	}
	$time = time() - ($days * 24 * 60 * 60);
	$backups = db_fetch_assoc_prepared('SELECT *
		FROM plugin_routerconfigs_backups
		WHERE btime < ?',
		array($time));

	if (sizeof($backups)) {
		foreach ($backups as $backup) {
			$dir = $backup['directory'];
			$filename = $backup['filename'];
			@unlink("$backupdir/$filename");
		}
	}

	db_execute_prepared('DELETE FROM plugin_routerconfigs_backups
		WHERE btime < ?',
		array($time));
}

function plugin_routerconfigs_check_config($data) {
	if (preg_match('/\n[^\w]*end[^\w]*$/',$data))
		return true;
	return false;
}

function plugin_routerconfigs_start($force = false) {
	global $config;

	$running = db_fetch_cell('SELECT value FROM settings WHERE name = \'plugin_routerconfigs_running\'');
	if ($running == 1) {
		$running = time();
		db_execute('REPLACE INTO settings (name, value)
			VALUES (\'plugin_routerconfigs_running\', ' . $running .')');
	}

	if ($running < time() - 7200 || $force) {
		$running = time();

		db_execute('REPLACE INTO settings (name, value)
			VALUES (\'plugin_routerconfigs_running\', ' . $running .')');

		$datetime = new DateTime();
		$datetime->setTimestamp($running);
		cacti_log(__('STATS: Backup now running since %s',$datetime->format('Y-m-d H:i:s'),'routerconfigs'), !$config['is_web'],'RCONFIG');
	} else {
		$datetime = new DateTime();
		$datetime->setTimestamp($running);
		cacti_log(__('WARN: Backup already running since %s',$datetime->format('Y-m-d H:i:s'),'routerconfigs'), !$config['is_web'],'RCONFIG');
		exit();
	}
}

function plugin_routerconfigs_stop($force_stop) {
	if ($force_stop) {
		db_execute('REPLACE INTO settings (name, value)
			VALUES (\'plugin_routerconfigs_running\', 0)');
	}
	exit();
}

function plugin_routerconfigs_download_config($device) {
	$info = plugin_routerconfigs_retrieve_account($device['id']);
	$dir  = $device['directory'];
	$ip   = $device['ipaddress'];

	$backuppath = trim(read_config_option('routerconfigs_backup_path'));
	$tftpserver = read_config_option('routerconfigs_tftpserver');

	$tftpfilename = $device['hostname'];
	$filename     = $tftpfilename;

	if (strlen($backuppath) && $backuppath[strlen($backuppath) - 1] != '/') {
		$backuppath .= '/';
	}

	$backupdir = $backuppath  . $dir;

	if (strlen($backupdir) && $backupdir[strlen($backupdir) - 1] != '/') {
		$backupdir .= '/';
	}

	$devicetype = db_fetch_row_prepared('SELECT *
		FROM plugin_routerconfigs_devicetypes
		WHERE id = ?',
		array($device['devicetype']));

	if (empty($devicetype)){
		$devicetype = array('username' => 'username:',
			'password' => 'password:',
			'copytftp' => 'copy start tftp',
			'version' => 'show version',
			'forceconfirm' => '',
			'checkendinconfig' => 'on'
		);
	}

	$result = 1;
	$type_dev = isset($device['connect_type']) ? $device['connect_type'] : 'both';
	$types_ssh = array('','both','ssh');
	$types_tel = array('','both','telnet');

	if (in_array($type_dev,$types_ssh)) {
		plugin_routerconfigs_log("$ip -> DEBUG: Attempting to connect via SSH");

		$connection = new PHPSsh($device['ipaddress'], $info['username'], $info['password'], $info['enablepw'], $devicetype);

		$result = $connection->Connect();
		if (!$result) {
			$connection->Log("DEBUG: Connected via ssh");
		} else {
			$connection = NULL;
		}
	}

	if ($result && in_array($type_dev,$types_tel)) {
		plugin_routerconfigs_log("$ip -> DEBUG: Attempting to connect via Telnet");

		$connection = new PHPTelnet($device['ipaddress'], $info['username'], $info['password'], $info['enablepw'], $devicetype);

		$result = $connection->Connect();
		if (!$result) {
			$connection->Log("NOTICE: Connected via telnet");
		} else {
			$connection = NULL;
		}
	}

	if ($result) {
		$fail_msg = __("ERROR: Failed to Connect to Device '%s' using connection type: %s",$device['hostname'],$type_dev,'routerconfigs');
		plugin_routerconfigs_save_error($device['id'],null,$fail_msg);
		plugin_routerconfigs_log($fail_msg);
		return false;
	}

	$debug = $connection->debug;
	$ip    = $connection->ip;
	$file  = false;

	db_execute_prepared('UPDATE plugin_routerconfigs_devices
		SET lastattempt = ? WHERE id = ?',
		array(time(), $device['id']));

	if ($result == 0) {
		$command = $devicetype['copytftp'];
		if (stristr($command, '%SERVER%')) {
			$command = str_replace('%SERVER%', $tftpserver, $command);
		}

		if (stristr($command, '%FILE%')) {
			$command=str_replace('%FILE%', $filename, $command);
		}

		if (!$connection->EnsureEnabled()) {
			$connection->error = 9;
			plugin_routerconfigs_save_error($device['id'], $connection);
			plugin_routerconfigs_save_debug($device, $debug);
			$connection->Disconnect();
			return false;
		}

		$connection->DoCommand($command, $result);
		$debug .= $result;

		//check if there is questions to confirm
		//i.e: in ASA it ask for confirmation of the source file
		//but this also confirm in case that all command is executed
		//in one line
		$x = 0;
		$ret = 0;
		$confirmed = false;

		while (($ret == 0 || $ret == 8) && $x<30) {
			$x++;

			$try_command='';
			$try_prompt='';

			if (stristr($result, $command)) {
				continue;
			} else if (stristr($result, 'bytes copied') ||
				stristr($result,'successful')) {
				$connection->Log("DEBUG: TFP TRANSFER SUCCESSFUL");
				break;
			} else if (stristr($result, 'error')) {
				$connection->Log("DEBUG: TFTP TRANSFER ERRORED");
				break;
			} else {
				$matches = preg_match('/[\d\w\[]\]\?[^\w]*$/',$result);
				$connection->log("DEBUG: Prompt match ($x) returned ".sizeof($matches) . " matches");

				if (sizeof($matches) > 0) {
					if (stristr($result, 'address') && !stristr($result, "[$ip]")) {
						//send tftpserver if necessary
						$try_command=$tftpserver;
						$try_prompt='Server:';
					} else if (stristr($result, 'filename')) {
						if (!stristr($result, 'source') && !stristr($result, "[$filename]")) {
							//send filename if necessary
							$try_command=$filename;
						}
						$try_prompt='Filename:';
					}
				}

				if ($try_prompt == '' && !$confirmed && (strpos($result, 'confirm') !== FALSE || strpos($result, 'to tftp:') !== FALSE || $devicetype['forceconfirm'])) {
					$try_command='y';
					$try_prompt='confirmation:';
					$confirmed = true;
				}
			}

			$connection->log("DEBUG: Sending $try_prompt $try_command");
			if ($try_prompt == '') {
				$try_prompt = 'a return';
			}

			$ret = $connection->DoCommand($try_command, $result);
			$debug .= $result;
			$lines = explode("\r\n", $result);
			foreach ($lines as $line) {
				$connection->log("DEBUG: Result: ($ret), Line: $line");
			}
		}

		$data = '';

		$readname = "$backuppath$tftpfilename";
		$connection->Log("DEBUG: CHECKING FOR valid file at $readname");

		clearstatcache();

		$file = false;
		$data = false;
		if (!file_exists("$readname")) {
			$connection->Log("ERROR: Failed to find file at $readname");
		} else {
			if (filesize("$readname") > 0) {
				$connection->Log("DEBUG: Attempting to open file at $readname");
				$file = @fopen("$readname", 'r');
			}

			if ($file === false) {
				$connection->Log("ERROR: Failed to open file at $readname");
			} else {
				$data = @fread($file, filesize("$readname"));
				@fclose($file);

				if ($data === false) {
					$connection->Log("ERROR: Failed to read file at $readname");
				}
			}
		}

		if ($data === false) {
			$connection->error = 7;
			plugin_routerconfigs_save_error($device['id'], $connection);
			plugin_routerconfigs_save_debug($device, $debug);
			$connection->Disconnect();
			return false;
		}

		@unlink("$readname");
		if (file_exists("$readname")) {
			$connection->Log("WARNING: Failed to remove file at $readname");
		}

		if ($devicetype['checkendinconfig'] == 'on' && !plugin_routerconfigs_check_config($data)) {
			$connection->error = 5;
			plugin_routerconfigs_save_error($device['id'], $connection);
			plugin_routerconfigs_save_debug($device, $debug);
			$connection->Log("DEBUG: checking end in config");
			$connection->Disconnect();
			return false;
		}

		if ($devicetype['checkendinconfig'] == 'on') {
			$connection->Log("DEBUG: Configuration end check successful");
		} else {
			$connection->Log("DEBUG: Configuration end check was not performed");
		}

		$data       = str_replace ("\n", "\r\n", $data);
		$data2      = explode("\r\n", $data);
		$lastchange = '';
		$lastuser   = '';
		if (sizeof($data2)) {
			foreach ($data2 as $d) {
				if (strpos($d, 'Last configuration change at') !== FALSE) {
					$lastchange = substr($d, strpos($d, 'change at') + 10, strpos($d, ' by ') - (strpos($d, 'change at') + 10));

					$t = explode(' ', $lastchange);
					if (isset($t[5])) {
						$t = array($t[3], $t[4], $t[5], $t[0], $t[1]);
						$t = implode(' ', $t);
						$lastchange = strtotime($t);
						if (substr($d, strpos($d, ' by ')) !== FALSE) {
							$lastuser = substr($d, strpos($d, ' by ') + 4);
						}
					}
				}

				if (substr($d, 0, 9) == 'hostname ') {
					$filename = trim(substr($d, 9));
					if ($device['hostname'] != $filename) {
						db_execute_prepared('UPDATE plugin_routerconfigs_devices
							SET hostname = ?
							WHERE id = ?',
							array($filename, $device['id']));
					}
				}

				if (substr($d, 0, 17) == 'set system name ') {
					$filename = trim(substr($d, 17));
					if ($device['hostname'] != $filename) {
						db_execute_prepared('UPDATE plugin_routerconfigs_devices
							SET hostname = ?
							WHERE id = ?',
							array($filename, $device['id']));
					}
				}
			}
		}

		if ($lastchange != '' && $lastchange != $device['lastchange']) {
			db_execute_prepared('UPDATE plugin_routerconfigs_devices
				SET lastchange = ?, username = ?
				WHERE id = ?',
				array($lastchange, $lastuser, $device['id']));
		} elseif ($lastchange == '' && $devicetype['version'] != '') {
			$connection->DoCommand('terminal length 0', $version);
			$connection->DoCommand('terminal pager 0', $version);
			$connection->DoCommand($devicetype['version'], $version);

			$t       = time();
			$debug  .= $version;
			$version = explode("\n", $version);

			if (sizeof($version)) {
				foreach ($version as $v) {
					if (strpos($v, ' uptime is ') !== FALSE) {
						$uptime = 0;
						$up     = trim(substr($v, strpos($v, ' uptime is ') + 11));
						$up     = explode(',', $up);
						$x      = 0;

						foreach ($up as $u) {
							$s = explode(' ', trim($u));
							switch (trim($s[1])) {
								case 'years':
								case 'year':
									$uptime += ($s[0] * 31449600);
									break;
								case 'months':
								case 'month':
									$uptime += ($s[0] * 2419200);
									break;
								case 'weeks':
								case 'week':
									$uptime += ($s[0] * 604800);
									break;
								case 'days':
								case 'day':
									$uptime += ($s[0] * 86400);
									break;
								case 'hours':
								case 'hour':
									$uptime += ($s[0] * 3600);
									break;
								case 'minutes':
								case 'minute':
									$uptime += ($s[0] * 60);
									break;
								case 'seconds':
								case 'second':
									$uptime += $s[0];
									break;
							}
						}

						$lastuser   = '-- Reboot --';
						$lastchange = $t - $uptime;
						$diff       = $lastchange - $device['lastchange'];

						if ($diff < 0) {
							$diff = $diff * -1;
						}

						if ($diff > 60) {
							db_execute_prepared('UPDATE plugin_routerconfigs_devices
								SET lastchange = ?, username = ?
								WHERE id = ?',
								array($lastchange, $lastuser, $device['id']));
						} else {
							$lastchange = $device['lastchange'];
						}
					}
				}
			}
		}

		$connection->Disconnect();

		$connection->Log("DEBUG: Configuration Data Length " . strlen($data));

		if (strlen($data) > 100) {
			$connection->Log("DEBUG: Checking backup directory exists: $backupdir");
			if (!is_dir("$backupdir")) {
				$connection->Log("DEBUG: Creating backup directory: $backupdir");
				@mkdir("$backupdir", 0777, true);
			}

			$file = false;
			if (!is_dir("$backupdir")) {
				$connection->Log("ERROR: Failed to create backup directory: $backupdir");
			} else {
				$date = date('Y-m-d-Hi');
				$savename = "$backupdir$filename-$date";
				$connection->Log("DEBUG: Attempting to backup to filename '$savename'");

				clearstatcache();
				if (file_exists($savename)) {
					$connection->Log("WARNING: Overwritting existing file '$savename'");
				}

				$file = @fopen($savename, 'w');

				if ($file === false) {
					$connection->Log("ERROR: Failed to open file '$savename' for writing");
				} else {
					@fwrite($file, $data);
					@fclose($file);

					clearstatcache();
					$filesize = @filesize("$savename");
					if ($filesize < strlen($data)) {
						$connection->Log("WARNING: File '$savename' has size $filesize, expected ".strlen($data));
						$file = false;
					} else {
						$data2 = $data;
						$t     = time();

						if ($lastchange == '') {
							 $lastchange = 0;
						}

						db_execute_prepared('UPDATE plugin_routerconfigs_devices
							SET lastbackup = ?
							WHERE id = ?',
							array(time(), $device['id']));

						db_execute_prepared('INSERT INTO plugin_routerconfigs_backups
							(device, btime, directory, filename, config, lastchange, username)
						VALUES (?, ?, ?, ?, ?, ?, ?)',
						array($device['id'], $t, $dir, "$filename-$date", $data2, $lastchange, $lastuser));
					}
				}
			}
		}
	}

	if ($file === false) {
		plugin_routerconfigs_save_error($device['id'], $connection);
		plugin_routerconfigs_save_debug($device, $debug);

		$connection->Log("DEBUG: Exiting download as failed");
		return false;
	}

	plugin_routerconfigs_save_error($device['id'], $connection);
	plugin_routerconfigs_save_debug($device, $debug);
	$connection->Log("DEBUG: Backed up");

	return true;
}

function plugin_routerconfigs_save_debug($device, $debug) {
	$debug = base64_encode($debug);
	//echo "Saving Debug\n";
	db_execute_prepared('UPDATE plugin_routerconfigs_devices
		SET debug = ?
		WHERE id = ?',
		array($debug, $device['id']));
}

function plugin_routerconfigs_save_error($id, $telnet, $error='') {
	if ($telnet != null) {
		$error = $telnet->ConnectError($telnet->error);
	}

	db_execute_prepared('UPDATE plugin_routerconfigs_devices
		SET lasterror = ?
		WHERE id = ?',
		array($error, $id));
}

function plugin_routerconfigs_retrieve_account ($device) {
	if ($device == '') {
		return false;
	}

	$info = db_fetch_row_prepared('SELECT plugin_routerconfigs_accounts.*
		FROM plugin_routerconfigs_accounts,plugin_routerconfigs_devices
		WHERE plugin_routerconfigs_accounts.id = plugin_routerconfigs_devices.account
		AND plugin_routerconfigs_devices.id = ?',
                array($device));

	if (isset($info['username'])) {
		$info['password'] = plugin_routerconfigs_decode($info['password']);
		$info['enablepw'] = plugin_routerconfigs_decode($info['enablepw']);
		return $info;
	}

	return false;
}

function plugin_routerconfigs_decode($info) {
	$info = base64_decode($info);
	$debug_info = preg_replace('~(;s:\d+:"password";s:(\d+:))"(.*)\"~','\\1"(\\2 chars)"', $info);
	plugin_routerconfigs_log("DEBUG: Base64 decoded: $debug_info");

	$info = unserialize($info);
	return $info['password'];
}

function plugin_routerconfigs_encode($info) {
	$crypt = array(rand(1, time()) => rand(1, time()), 'password' => '', rand(1, time()) => rand(1, time()));
	$crypt['password'] = $info;
	$crypt = serialize($crypt);
	$crypt = base64_encode($crypt);
	return $crypt;
}

function plugin_routerconfigs_messagetype($message) {
	$types = array('ERROR:','FATAL:','STATS:','WARNING:','NOTICE:','DEBUG:');
	$typepos = array();
	foreach ($types as $type) {
		$pos = strpos($message,$type);
		if ($pos !== false) {
			$typepos[$pos] = $type;
		}
	}

	ksort($typepos);
	foreach ($typepos as $pos=>$type) {
		return $type;
	}

	return $message;
}

/*
//Log messages to cacti log or syslog
//This function is the same as thold plugin with a litle changes
//to respect cacti log level settings
*/
function plugin_routerconfigs_log($message, $log_level = POLLER_VERBOSITY_NONE) {
	global $config, $debug;

	$environ = 'RCONFIG';

	if ($log_level == POLLER_VERBOSITY_NONE) {
		$log_level = POLLER_VERBOSITY_HIGH;

		$message_type = plugin_routerconfigs_messagetype($message);
		if (substr_count($message_type,'ERROR:') || substr_count($message_type, 'FATAL:') || substr_count($message_type,'STATS:')) {
			$log_level = POLLER_VERBOSITY_LOW;
		} else if (substr_count($message_type,'WARNING:') || substr_count($message_type,'NOTICE:')) {
			$log_level = POLLER_VERBOSITY_MEDIUM;
		} else if (substr_count($message_type,'DEBUG:')) {
			$log_level = POLLER_VERBOSITY_DEBUG;
		}
	}

	if ($debug) {
		$log_level = POLLER_VERBOSITY_NONE;
	}
	cacti_log($message,!$config['is_web'],$environ, $log_level);
}

abstract class PHPConnection {
	var $debugbuffer = false;
	var $use_usleep  = 1;	// change to 1 for faster execution
				// don't change to 1 on Windows servers unless you have PHP 5
	var $sleeptime=125000;
	var $debug = '';
	var $timeout     = 1; //Seconds to avoid buggies connections

	var $connection  = NULL; //stores the ssh connection pointer
	var $stream      = NULL; //points to the ssh session stream
	var $errorcode   = 0;
	var $error = 0;
	var $ip='';

	function __construct($classType, $server, $user, $pass, $enablepw, $devicetype) {
		$this->classType = $classType;

		$this->pw1_text = plugin_routerconfigs_maskpw($pass);
		$this->pw2_text = plugin_routerconfigs_maskpw($enablepw);

		$this->server = $server;
		$this->user = $user;
		$this->pass = $pass;
		$this->enablepw = $pass;
		$this->devicetype = $devicetype;

		$this->debug = '';
		$this->debugbuffer = read_config_option('routerconfigs_debug_buffer') == 'on';

		$this->setServerDetails();

		$this->Log("DEBUG: SSH->Connect(Server: $this->server, User: $this->user, Password: $this->pw1_text, Enablepw: $this->pw2_text, Devicetype: ".json_encode($this->devicetype));
	}

	protected function setServerDetails() {
		$server = $this->server;

		if (strlen($server)) {
			if (preg_match('/[^0-9.]/', $server)) {
				$ip = gethostbyname($server);
				if ($ip == $server) {
					$ip = '';
				}
			} else {
				$ip = $server;
			}
		} else {
			$ip = '127.0.0.1';
		}

		$this->ip = $ip;
	}

	function Log($message) {
		$lines = explode("\r\n", $message);
		if (sizeof($lines)) {
			foreach ($lines as $line) {
				plugin_routerconfigs_log("$this->ip ($this->classType) -> $line");
			}
		}
	}

	function EnsureEnabled() {
		# Get > to show we are at the command prompt and ready to input the en command
		# Get # to show we are already enabled so we don't need to enable
		$is_enabled = false;

		$this->Log('NOTICE: Ensuring process is enabled');

		$res = '';
		$x = 0;
		while ($x < 10) {
			$r = '';
			$this->DoCommand('', $r, $this->pass);
			$res .= $r;

			$this->Log("DEBUG: Attempt $x of 10 to find enabled prompt");
			if (preg_match("|[a-zA-Z0-9\-_]>[ ]*$|", $r) === 1) {
				break;
			} else if (preg_match("|[a-zA-Z0-9\-_]#[ ]*$|", $r) === 1) {
				$is_enabled = true;
				break;
			}
			$x++;
		}

		if ($x < 10) {
			if ($this->enablepw != '' && !$is_enabled) {
				$this->Log("DEBUG: Sending enable command");
				@fputs($this->stream, "en\r");

				# Get the password prompt again to input the enable password
				$res = '';
				$x = 0;
				while ($x < 10) {
					$this->Sleep();
					$this->GetResponse($response);
					if (strstr($response,'>')) {
						$this->DoCommand('en',$response);
					}

					if (stristr($response,$this->devicetype['password'])) {
						if($this->DoCommand($this->enablepw, $response, $this->enablepw)){
							$this->Log('DEBUG: Enable login failed');
							$this->Disconnect();
							break;
						}

						if (preg_match("|[a-zA-Z0-9\-_]#[ ]*$|", $r) === 1) {
							$this->Log('DEBUG: Ok we are in enabled mode');
							$is_enabled = true;
						}
					}
				}

				$x++;
			}
		}

		$this->Log('Process is ' . ($is_enabled ? '' : 'NOT ') . ' enabled');
		return $is_enabled;
	}


	function Disconnect($exit=1) {
		if ($this->stream) {
			if ($exit) {
				$this->DoCommand('exit', $junk);
			}

			fclose($this->stream);
			$this->stream = NULL;
		}
	}

	function startsWith($haystack, $needle)
	{
		$length = strlen($needle);
		return (substr($haystack, 0, $length) === $needle);
	}

	function endsWith($haystack, $needle)
	{
		$length = strlen($needle);
		return $length === 0 || (substr($haystack, -$length) === $needle);
	}

	function Sleep() {
		if ($this->use_usleep) {
			usleep($this->sleeptime);
		} else {
			sleep($this->sleeptime);
		}
	}

	function DoCommand($cmd, &$response, $pass = null) {
		$result = 0;
		if ($this->stream) {
			@fwrite($this->stream,$cmd.PHP_EOL);
			$this->Sleep();
			$result = $this->GetResponse($response, $pass);
			$response = preg_replace("/^.*?\n(.*)\n([^\n]*)$/", "$2", $response);
		}

		return $result;
	}

	function GetResponse(&$response, $pass = null) {
		$time_start = time();
		$data = '';

		stream_set_timeout($this->stream, 1);
		while (true && isset($this->stream)) {
			$buf = @fgets($this->stream);
			if ($buf !== false) {
				if ($pass != null) {
					$buf = str_replace($pass,'__password__',$buf);
				}
				$data .= $buf;
				$response .= $buf;
				$line_buf = explode("\n",str_replace("\r", "", $buf));

				if ($this->debugbuffer) {
					if (is_array($line_buf)) {
						foreach ($line_buf as $line) {
							$line = str_replace("`\r","",str_replace("\n","",$line));
							$buf_line = "DEBUG: Buffer: ";
							$buf_line .= $line;
							$this->Log($buf_line);
						}
					} else {
						$line = str_replace("`\r","",str_replace("\n","",$line));
						$buf_line = "DEBUG: Buffer: ";
						$buf_line .= $line;
						$this->Log($buf_line);
					}
				}

				$trim_buf = trim($buf);
				if (preg_match("|[a-zA-Z0-9\-_]>[ ]*$|", $buf) === 1) {
					$this->Log("DEBUG: Found Prompt (Normal)");
					return 0;
				} else if (preg_match("|[a-zA-Z0-9\-_]#[ ]*$|", $buf) === 1) {
					$this->Log("DEBUG: Found Prompt (Enabled)");
					return 0;
				} else if (stristr($buf, $this->devicetype['password'])) {
					$this->Log("DEBUG: Found Prompt (Password)");
					return 0;
				} else if (preg_match("|[a-zA-Z0-9\-_]:[ ]*$|", $buf) === 1) {
					$this->Log("DEBUG: Found Prompt (Colon)");
					return 0;
				}
			}

			$s = socket_get_status($this->stream);
			if (!$s['unread_bytes'] && strlen($data)) {
				return 0;
			}

			if ((time()-$time_start) > $this->timeout) {
				$this->Log("DEBUG: Timeout of {$this->timeout} seconds has been reached");
				return 8;
			}

		}

		return 0;
	}

}

/*
PHPSsh
by Abdul Pallares: abdul(at)riereta.net
following the PHPTelnet structure
and looking arround on Internet
*/
class PHPSsh extends PHPConnection {
	var $show_connect_error = 1;

	/*
	0 = success
	1 = couldn't open network connection
	2 = unknown host
	3 = login failed
	4 = No ssh2 extension
	5 = Error enabling device
	*/
	function __construct($server, $user, $pass, $enablepw, $devicetype) {
		parent::__construct('SSH', $server, $user, $pass, $enablepw, $devicetype);
	}

	function Connect() {
		$rv = 0;

		if (!function_exists('ssh2_auth_password')) {
			$this->Log("DEBUG: PHP doesn't have the ssh2 module installed");
			$this->Log("DEBUG: Follow the installation instructions in the official manual at http://www.php.net/manual/en/ssh2.installation.php");
			$rv=4;
			return $rv;
		}

		if (strlen($this->ip)) {
			if(!($this->connection = ssh2_connect($this->server, 22))){
				$rv=1;
			} else {
				// try to authenticate
				if (!@ssh2_auth_password($this->connection, $this->user, $this->pass)) {
					$rv=3;
				} else {
					if ($this->stream = @ssh2_shell($this->connection,'xterm')) {
						$this->Log('DEBUG: okay: logged in...');
					}
				}
			}
		}

		if ($rv) {
			$error = $this->ConnectError($rv);
			$this->Log($error);
		}

		return $rv; //everything goes well ;)
	}

	function ConnectError($num) {
		if ($this->show_connect_error) {
			$this->error=$num;
			switch ($num) {
			case 1:
				return 'WARNING: Unable to open ssh network connection';
				break;
			case 2:
				return 'ERROR: Unknown host';
				break;
			case 3:
				return 'ERROR: SSH login failed';
				break;
			case 4:
				return "ERROR: PHP doesn't have the ssh2 module installed\nFollow the installation instructions in the official manual: http://www.php.net/manual/en/ssh2.installation.php";
				break;
			case 5:
				return 'ERROR: Bad download of config';
				break;
			case 6:
				return 'ERROR: SSH access not Permitted';
				break;
			case 7:
				return 'ERROR: SSH no Config uploaded from Router';
				break;
			case 8:
				return "NOTICE: SSH Timeout of {$this->timeout} seconds has been reached";
				break;
			case 9:
				return 'ERROR: SSH Enable login failed';
			}
		}
	}
}

/*
PHPTelnet 1.1
by Antone Roundy
adapted from code found on the PHP website
public domain
*/
class PHPTelnet extends PHPConnection {
	var $show_connect_error=1;

	var $loginsleeptime=1000000;

	var $loginprompt;

	var $conn1;
	var $conn2;

	/*
	0 = success
	1 = couldn't open network connection
	2 = unknown host
	3 = login failed
	4 = PHP version too low
	*/
	function __construct($server, $user, $pass, $enablepw, $devicetype) {
		parent::__construct('Telnet',$server, $user, $pass, $enablepw, $devicetype);

		$this->conn1=chr(0xFF).chr(0xFB).chr(0x1F).chr(0xFF).chr(0xFB).
			chr(0x20).chr(0xFF).chr(0xFB).chr(0x18).chr(0xFF).chr(0xFB).
			chr(0x27).chr(0xFF).chr(0xFD).chr(0x01).chr(0xFF).chr(0xFB).
			chr(0x03).chr(0xFF).chr(0xFD).chr(0x03).chr(0xFF).chr(0xFC).
			chr(0x23).chr(0xFF).chr(0xFC).chr(0x24).chr(0xFF).chr(0xFA).
			chr(0x1F).chr(0x00).chr(0x50).chr(0x00).chr(0x18).chr(0xFF).
			chr(0xF0).chr(0xFF).chr(0xFA).chr(0x20).chr(0x00).chr(0x33).
			chr(0x38).chr(0x34).chr(0x30).chr(0x30).chr(0x2C).chr(0x33).
			chr(0x38).chr(0x34).chr(0x30).chr(0x30).chr(0xFF).chr(0xF0).
			chr(0xFF).chr(0xFA).chr(0x27).chr(0x00).chr(0xFF).chr(0xF0).
			chr(0xFF).chr(0xFA).chr(0x18).chr(0x00).chr(0x58).chr(0x54).
			chr(0x45).chr(0x52).chr(0x4D).chr(0xFF).chr(0xF0);

		$this->conn2=chr(0xFF).chr(0xFC).chr(0x01).chr(0xFF).chr(0xFC).
			chr(0x22).chr(0xFF).chr(0xFE).chr(0x05).chr(0xFF).chr(0xFC).chr(0x21);
	}

	function Connect() {
		$rv=0;
		$vers=explode('.',PHP_VERSION);
		$needvers=array(4,3,0);
		$j=count($vers);
		$k=count($needvers);
		if ($k<$j) $j=$k;
		for ($i=0;$i<$j;$i++) {
			if (($vers[$i]+0)>$needvers[$i]) break;
			if (($vers[$i]+0)<$needvers[$i]) {
				$error = $this->ConnectError(4);
				$this->Log("Connect 4 error");
				$this->Log($error);
				return 4;
			}
		}

		$this->Log("Disconnect()");
		$this->Disconnect();

		if (strlen($this->ip)) {
			$this->Log("Attempting to open socked to $this->ip:23");
			if (@$this->stream = fsockopen($this->ip, 23)) {
				@fputs($this->stream, $this->conn1);
				$this->Sleep();

				@fputs($this->stream, $this->conn2);
				$this->Sleep();

				$this->GetResponse($r);

				$this->Log("Looking for ".$this->devicetype['username']);

				$failed_username = stripos($r, $this->devicetype['username']) === FALSE;
				$failed_access = stripos($r, 'Access not permitted.') !== FALSE;

				if (!$this->stream || $failed_username || $failed_access) {
					if ($failed_username) {
						$this->Log("ERROR: Failed to find username prompt");
					}
					if ($failed_access) {
						$this->Log("ERROR: Access not permitted");
					}
					return 6;
				}

				// Get Username Prompt
				$res = $r;

				$x = 0;
				while ($x < 10) {
					$this->GetResponse($r);
					$res .= $r;

					if (stripos($res, $this->devicetype['username']) !== FALSE) {
						break;
					} else {
						$this->Log("DEBUG: No Prompt received");
						@fputs($this->stream, "\r");
					}

					$x++;
					$this->Sleep();
				}

				if ($x == 10) {
					return 8;
				}

				// Send Username, wait for Password Prompt
				$this->Log("DEBUG: Sending username: $this->user");
				@fputs($this->stream, "$this->user\r");

				$res = '';
				$x = 0;
				while ($x < 10) {
					$this->Sleep();
					$this->GetResponse($r);
					$res .= $r;
					if (strpos($res, $this->devicetype['password']) !== FALSE) {
						break;
					}
					$x++;
				}

				if ($x == 10) {
					return 3;
				}

				$r=explode("\n", $r);
				$loginprompt = trim(substr($r[count($r) - 1], 0, strpos($r[count($r) - 1], ' ')));

				$this->Log("DEBUG: Found prompt '$loginprompt', Sending password: $this->pw1_text");
				@fputs($this->stream, "$this->pass\r");

				$r='';
				$this->Sleep();
				$this->GetResponse($r);
				$r=explode("\n", $r);
				if (($r[count($r) - 1] == '') || ($loginprompt == trim($r[count($r) - 1]))) {
					$this->Log('DEBUG: Failed, disconnecting');
					$rv = 3;
					$this->Disconnect();
				}
			} else $rv = 1;
		}

		if ($rv) {
			$this->Log($this->ConnectError($rv));
		}
		return $rv;
	}

	function ConnectError($num) {
		if ($this->show_connect_error) {
			$this->error = $num;
			switch ($num) {
				case 1:
					return 'ERROR: Unable to open telnet network connection';
					break;
				case 2:
					return 'ERROR: Unknown host';
					break;
				case 3:
					return 'ERROR: TELNET login failed';
					break;
				case 4:
					return 'ERROR: Connect failed: Your servers PHP version is too low for PHP Telnet';
					break;
				case 5:
					return 'ERROR: Bad download of config';
					break;
				case 6:
					return 'ERROR: TELNET access not Permitted';
					break;
				case 7:
					return 'ERROR: TELNET no Config uploaded from Router';
					break;
				case 9:
					return 'ERROR: TELNET Enable login failed';
			}
		}

		return '';
	}
}

function plugin_routerconfigs_date_from_time_with_na($time) {
	return ($time > 0) ? date(CACTI_DATE_TIME_FORMAT, $time) : 'N/A';
}

function plugin_routerconfigs_date_from_time($time) {
	return ($time > 0) ? date(CACTI_DATE_TIME_FORMAT, $time) : '';
}

