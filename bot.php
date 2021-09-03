<?php
$command_symbol = '!s'; //Command prefix


ini_set('max_execution_time', 0);
ini_set('memory_limit', '-1'); //Unlimited memory usage
define('MAIN_INCLUDED', 1); //Token and SQL credential files may be protected locally and require this to be defined to access
require getcwd(). '/token.php'; //$token
include getcwd() . '/vendor/autoload.php';

function execInBackground($cmd) {
    if (substr(php_uname(), 0, 7) == "Windows") {
        pclose(popen("start ". $cmd, "r")); //pclose(popen("start /B ". $cmd, "r"));
    } else exec($cmd . " > /dev/null &");
}

function execInBackgroundWindows($cmd) {
    pclose(popen("start ". $cmd, "r")); //pclose(popen("start /B ". $cmd, "r"));
}

function execInBackgroundLinux($cmd) {
    exec($cmd . " > /dev/null &");
}

$logger = new Monolog\Logger('New logger');
$logger->pushHandler(new Monolog\Handler\StreamHandler('php://stdout'));
$loop = React\EventLoop\Factory::create();
use Discord\WebSockets\Intents;
$discord = new \Discord\Discord([
	'token' => "$token",
	/*'socket_options' => [
        'dns' => '8.8.8.8', // can change dns
	],*/
    'loadAllMembers' => true,
    'storeMessages' => false, //Not needed yet
	'logger' => $logger,
	'loop' => $loop,
	'intents' => Intents::getDefaultIntents() | Intents::GUILD_MEMBERS, // default intents as well as guild members
]);

function portIsAvailable(int $port = 1714): bool
{
	$s = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

	try {
		if (socket_bind($s, "127.0.0.1", $port)) {
			socket_close($s);
			return true;
		}
	} catch (Exception $e) {
		socket_close($s);
		return false;
	}
	socket_close($s);
	return false;
}

function remove_prefix(string $text = '', string $prefix = ''): string
{
	if (str_starts_with($text, $prefix)) # only modify the text if it starts with the prefix
		$text = str_replace($prefix, '', $text);# remove one instance of prefix
	return $text;
}

function my_message($msg): bool
{
	return ($message->author->user->id == $discord->user->id);
}

function search_players(string $ckey): string
{
	if ($playerlogs = fopen('/home/1713/civ13-rp/SQL/playerlogs.txt', "r")) {
		while (($fp = fgets($playerlogs, 4096)) !== false) {
			if (trim(strtolower($fp)) == trim(strtolower($ckey)))
				return $ckey;
		}
		return 'None';
	} else return 'Unable to access playerlogs.txt!';
}

function on_ready($discord)
{
	echo 'Logged in as ' . $discord->user->username . "#" . $discord->user->discriminator . ' ' . $discord->id . PHP_EOL;
	echo('------' . PHP_EOL);
}

function on_message($message, $discord, $loop, $command_symbol = '!s')
{
	
	if ($message->guild->owner_id != '196253985072611328') return; //Only allow this in a guild that Taislin owns
	
	//Move this into a loop->timer so this isn't being called on every single message to reduce read/write overhead
	if ($ooc = fopen('/home/1713/civ13-rp/ooc.log', "r+")) {
		while (($fp = fgets($ooc, 4096)) !== false) {
			$fp = str_replace('\n', "", $fp);
			if ($target_channel = $message->guild->channels->get('name', 'ooc-nomads'))
				$target_channel->sendMessage($fp);
		}
		ftruncate($ooc, 0); //clear the file
		fclose($ooc);
	}
	if ($ahelp = fopen('/home/1713/civ13-rp/admin.log', "r+")) {
		while (($fp = fgets($ahelp, 4096)) !== false) {
			$fp = str_replace('\n', "", $fp);
			if ($target_channel = $message->guild->channels->get('name', 'ahelp-nomads'))
				$target_channel->sendMessage($fp);
		}
		ftruncate($ahelp, 0); //clear the file
		fclose($ahelp);
	}
	if ($ooctdm = fopen('/home/1713/civ13-tdm/ooc.log', "r+")) {
		while (($fp = fgets($ooctdm, 4096)) !== false) {
			$fp = str_replace('\n', "", $fp);
			if ($target_channel = $message->guild->channels->get('name', 'ooc-tdm'))
				$target_channel->sendMessage($fp);
		}
		ftruncate($ooctdm, 0); //clear the file
		fclose($ooctdm);
	}
	if ($ahelptdm = fopen('/home/1713/civ13-tdm/admin.log', "r+")) {
		while (($fp = fgets($ahelptdm, 4096)) !== false) {
			$fp = str_replace('\n', "", $fp);
			if ($target_channel = $message->guild->channels->get('name', 'ahelp-tdm'))
				$target_channel->sendMessage($fp);
		}
		ftruncate($ahelptdm, 0); //clear the file
		fclose($ahelptdm);
	}
	
	if (str_starts_with($message->content, $command_symbol . ' ')) { //Add these as slash commands?
		$message_content = substr($message->content, strlen($command_symbol)+1);
		$message_content_lower = strtolower($message_content);
		if (str_starts_with($message_content_lower, 'ping')) {
			$message->reply('Pong!');
			return;
		}
		if (str_starts_with($message_content_lower, 'help')) {
			$message->reply('**List of Commands**: bancheck, insult, cpu, ping, (un)whitelistme, rankme, ranking. **Staff only**: ban, hostciv, killciv, restartciv, mapswap, hosttdm, killtdm, restarttdm, tdmmapswap');
			return;
		}
		
		if (str_starts_with($message_content_lower,'cpu')) {
			if (substr(php_uname(), 0, 7) == "Windows") {
				$p = shell_exec('powershell -command "gwmi Win32_PerfFormattedData_PerfOS_Processor | select PercentProcessorTime"');
				$p = preg_replace('/\s+/', ' ', $p); //reduce spaces
				$p = str_replace("PercentProcessorTime", "", $p);
				$p = str_replace("--------------------", "", $p);
				$p = preg_replace('/\s+/', ' ', $p); //reduce spaces
				$load_array = explode(" ", $p);

				$x=0;
				foreach ($load_array as $line) {
					if ($line != " " && $line != "") {
						if ($x==0) {
							$load = "CPU Usage: $line%\n";
							break;
						}
						if ($x!=0) {
							//$load = $load . "Core $x: $line%\n"; //No need to report individual cores right now
						}
						$x++;
					}
				}
				$message->channel->sendMessage($load);
			} else { //Linux
				$cpu_load = '-1';
				if ($cpu_load_array = sys_getloadavg())
					$cpu_load = array_sum($cpu_load_array) / count($cpu_load_array);
				$message->channel->sendMessage('CPU Usage: ' . $cpu_load . "%");
			}
			return;
		}
		
		if (str_starts_with($message_content_lower, 'insult')) {
			$split_message = explode(' ', $message_content); //$split_target[1] is the target
			if ((count($split_message) > 1 ) && strlen($split_message[1] > 0)) {
				$incel = $split_message[1];
				$insults_array = array();
				
				if ($file = fopen('insults.txt', 'r')) {
					while (($fp = fgets($file, 4096)) !== false) {
						if (trim(strtolower($fp)) == trim(strtolower($incel)))
							$insults_array[] = $insult;
					}
					if (count($insults_array > 0)) {
						$insult = $insults_array[rand(0, count($insults_array)-1)];
						$message->channel->sendMessage("$incel, $insult");
					}
				} else $message->channel->sendMessage('Unable to access insults.txt!');
			}
			return;
		}
		if (str_starts_with($message_content_lower, 'ooc ')) {
			$message_filtered = substr($message_content, 4);
			switch (strtolower($message->channel->name)) {
				case 'ooc-nomads':					
					$file = fopen("/home/1713/civ13-rp/SQL/discord2ooc.txt", "a");
					$txt = $message->user->username . ":::$message_filtered\n";
					fwrite($file, $txt);
					fclose($file);
					break;
				case 'ooc-tdm':
					$file = fopen("/home/1713/civ13-tdm/SQL/discord2ooc.txt", "a");
					$txt = $message->user->username . ":::$message_filtered\n";
					fwrite($file, $txt);
					fclose($file);
					break;
			}
			return;
		}
		if (str_starts_with($message_content_lower, 'asay ')) {
			$message_filtered = substr($message_content, 5);
			switch (strtolower($message->channel->name)) {
				case 'ahelp-nomads':
					$file = fopen("/home/1713/civ13-rp/SQL/discord2admin.txt", "a");
					$txt = $message->user->username . ":::$message_filtered\n";
					fwrite($file, $txt);
					fclose($file);
					break;
				case 'ahelp-tdm':
					$file = fopen("/home/1713/civ13-tdm/SQL/discord2admin.txt", "a");
					$txt = $message->user->username . ":::$message_filtered\n";
					fwrite($file, $txt);
					fclose($file);
					break;
			}
			return;
		}
		if (str_starts_with($message_content_lower, 'dm ')) {
			$message_content = substr($message_content, 3);
			$split_message = explode(": ", $message_content);
			switch (strtolower($message->channel->name)) {
				case 'ahelp-nomads':
					$file = fopen("/home/1713/civ13-rp/SQL/discord2dm.txt", "a");
					$txt = $message->user->username.":::".$split_message[0].":::".$split_message[1]."\n";
					fwrite($file, $txt);
					fclose($file);
					break;
				case 'ahelp-tdm':
					$file = fopen("/home/1713/civ13-tdm/SQL/discord2dm.txt", "a");
					$txt = $message->user->username.":::".$split_message[0].":::".$split_message[1]."\n";
					fwrite($file, $txt);
					fclose($file);
					break;
			}
			return;
		}
		if (str_starts_with($message_content_lower, 'pm ')) {
			$message_content = substr($message_content, 3);
			$split_message = explode(": ", $message_content);
			switch (strtolower($message->channel->name)) {
				case 'ahelp-nomads':
					$file = fopen("/home/1713/civ13-rp/SQL/discord2dm.txt", "a");
					$txt = $message->user->username.":::".$split_message[0].":::".$split_message[1]."\n";
					fwrite($file, $txt);
					fclose($file);
					break;
				case 'ahelp-tdm':
					$file = fopen("/home/1713/civ13-tdm/SQL/discord2dm.txt", "a");
					$txt = $message->user->username.":::".$split_message[0].":::".$split_message[1]."\n";
					fwrite($file, $txt);
					fclose($file);
					break;
			}
			return;
		}
		if (str_starts_with($message_content_lower, 'ban ')) {
			$message_content = substr($message_content, 4);
			$split_message = explode('; ', $message_content); //$split_target[1] is the target
			$file = fopen("/home/1713/civ13-rp/SQL/discord2ban.txt", "a");
			$txt = $message->user->username.":::".$split_message[0].":::".$split_message[1].":::".$split_message[2]."\n";
			fwrite($file, $txt);
			fclose($file);
			
			$file = fopen("/home/1713/civ13-tdm/SQL/discord2ban.txt", "a");
			$txt = $message->user->username.":::".$split_message[0].":::".$split_message[1].":::".$split_message[2]."\n";
			fwrite($file, $txt);
			fclose($file);
			$result = '**' . $message->user->username . '#' . $message->user->discriminator . '** banned **' . $split_message[0] . '** for **' . $split_message[1] . '** with the reason **' . $split_message[2] . '**.';
			$message->channel->sendMessage($result);
			return;
		}
		if (str_starts_with($message_content_lower, 'unban ')) {
			$message_content = substr($message_content, 6);
			$split_message = explode('; ', $message_content);
			
			$file = fopen("/home/1713/civ13-rp/SQL/discord2unban.txt", "a");
			$txt = $message->user->username . "#" . $message->user->discriminator . ":::".$split_message[0];
			fwrite($file, $txt);
			fclose($file);
			
			$file = fopen("/home/1713/civ13-tdm/SQL/discord2unban.txt", "a");
			$txt = $message->user->username . "#" . $message->user->discriminator . ":::".$split_message[0];
			fwrite($file, $txt);
			fclose($file);

			$result = '**' . $message->user->username . '** unbanned **' . $split_message[0] . '**.';
			$message->channel->sendMessage($result);
			return;
		}
		#whitelist
		if (str_starts_with($message_content_lower, 'whitelistme')) {
			$split_message = trim(substr($message_content, 11));
			if (strlen($split_message) > 0) { // if len($split_message) > 1 and len($split_message[1]) > 0:
				$ckey = $split_message;
				$ckey = strtolower($ckey);
				$ckey = str_replace('_', '', $ckey);
				$ckey = str_replace(' ', '', $ckey);
				$accepted = false;
				if ($author_member = $message->member) {
					foreach ($author_member->roles as $role) {
						switch ($role->name) {
							case 'Admiral':
							case 'Captain':
							case 'Lieutenant':
							case 'Brother At Arms':
							case 'Knight':
								$accepted = true;
						}
					}
					if ($accepted) {
						$found = false;
						$whitelist1 = fopen('/home/1713/civ13-rp/SQL/whitelist.txt', "r") ?? NULL;
						if ($whitelist1) {
							while (($fp = fgets($whitelist1, 4096)) !== false) {
								$line = trim(str_replace("\n", "", $fp));
								$linesplit = explode(";", $line);
								foreach ($linesplit as $split) {
									if ($split == $ckey)
										$found = true;
								}
							}
							fclose($whitelist1);
						}
						$whitelist2 = fopen('/home/1713/civ13-tdm/SQL/whitelist.txt', "r") ?? NULL;
						if ($whitelist2) {
							while (($fp = fgets($whitelist2, 4096)) !== false) {
								$line = trim(str_replace("\n", "", $fp));
								$linesplit = explode(";", $line);
								foreach ($linesplit as $split)
									if ($split == $ckey)
										$found = true;
							}
							fclose($whitelist2);
						}
						
						if (!$found) {
							$found2 = false;
							$whitelist1 = fopen('/home/1713/civ13-rp/SQL/whitelist.txt', "r") ?? NULL;
							if ($whitelist1) {
								while (($fp = fgets($whitelist1, 4096)) !== false) {
									$line = trim(str_replace("\n", "", $fp));
									$linesplit = explode(";", $line);
									foreach ($linesplit as $split) {
										if ($split == $message->author->username)
											$found2 = true;
									}
								}
							fclose($whitelist1);
							}
						}else $message->channel->sendMessage("$ckey is already in the whitelist!");
						
						$txt = $ckey."=".$message->author->username.'\n';
						if ($whitelist1 = fopen('/home/1713/civ13-rp/SQL/whitelist.txt', "a")) {
							fwrite($whitelist1, $txt);
							fclose($whitelist1);
						}
						if ($whitelist2 = fopen('/home/1713/civ13-tdm/SQL/whitelist.txt', "a")) {
							fwrite($whitelist2, $txt);
							fclose($whitelist2);
						}
						$message->channel->sendMessage("$ckey has been added to the whitelist.");
					} else $message->channel->sendMessage("Rejected! You need to have at least the [Brother At Arms] rank.");
				} else $message->channel->sendMessage('Error! Unable to get Discord Member class.');
			} else $message->channel->sendMessage("Wrong format. Please try '!s whitelistme [ckey].'");
			return;
		}
		if (str_starts_with($message_content_lower, 'unwhitelistme')) {
			$accepted = false;
			if ($author_member = $message->member) {
				foreach ($author_member->roles as $role) {
					switch ($role->name) {
						case 'Admiral':
						case 'Captain':
						case 'Lieutenant':
						case 'Footman':
						case 'Brother At Arms':
						case 'Knight':
							$accepted = true;
					}
				}
				if ($accepted) {
					$removed = "N/A";
					$lines_array = array();
					if ($wlist = fopen("/home/1713/civ13-rp/SQL/whitelist.txt", "r")) {
						while (($fp = fgets($playerlogs, 4096)) !== false) {
							$lines_array[] = $fp;
						}
						fclose($wlist);
					} else return $message->channel->sendMessage('Unable to access whitelist.txt!');
					if ($count($lines_array) > 0) {
						if ($wlist = fopen("/home/1713/civ13-rp/SQL/whitelist.txt", "w")) {
							foreach ($lines_array as $line)
								if (!str_contains($line, $message->author->username)) {
									fwrite($wlist, $line);
								} else {
									$removed = explode('=', $line);
									$removed = $removed[0];
								}
							fclose($wlist);
						} else return $message->channel->sendMessage('Unable to access Nomads whitelist.txt!');
					}
					
					$lines_array = array();
					if ($wlist = fopen("/home/1713/civ13-tdm/SQL/whitelist.txt", "r")) {
						while (($fp = fgets($playerlogs, 4096)) !== false) {
							$lines_array[] = $fp;
						}
						fclose($wlist);
					} else return $message->channel->sendMessage('Unable to access TDM whitelist.txt!');
					if ($count($lines_array) > 0) {
						if ($wlist = fopen("/home/1713/civ13-tdm/SQL/whitelist.txt", "w")) {
							foreach ($lines_array as $line)
								if (!str_contains($line, $message->author->username)) {
									fwrite($wlist, $line);
								} else {
									$removed = explode('=', $line);
									$removed = $removed[0];
								}
							fclose($wlist);
						} else return $message->channel->sendMessage('Unable to access whitelist.txt!');
					}
					$message->channel->sendMessage("Ckey $removed has been removed from the whitelist.");
				} else $message->channel->sendMessage("Rejected! You need to have at least the [Brother At Arms] rank.");
			} else $message->channel->sendMessage('Error! Unable to get Discord Member class.');
			return;
		}
		if (str_starts_with($message_content_lower, 'hostciv')) {
			$accepted = false;
			if ($author_member = $message->member) {
				foreach ($author_member->roles as $role) {
					switch ($role->name) {
						case 'Admiral':
						case 'Captain':
						case 'Lieutenant':
							$accepted = true;
					}
				}
				if ($accepted) {
					$message->channel->sendMessage("Please wait, updating the code...");
					execInBackgroundLinux('sudo python3 /home/1713/civ13-rp/scripts/updateserverabspaths.py');
					$message->channel->sendMessage("Updated the code.");
					execInBackgroundLinux('sudo rm -f /home/1713/civ13-rp/serverdata.txt');
					execInBackgroundLinux('sudo DreamDaemon /home/1713/civ13-rp/civ13.dmb 1715 -trusted -webclient -logself &');
					$message->channel->sendMessage("Attempted to bring up Civilization 13 (Main Server) <byond://51.254.161.128:1715>");
					$discord->getLoop()->addTimer(10, function() { # ditto
						execInBackgroundLinux('sudo python3 /home/1713/civ13-rp/scripts/killsudos.py');
					});
				} else $message->channel->sendMessage("Denied!");
			} else $message->channel->sendMessage('Error! Unable to get Discord Member class.');
			return;
		}
		if (str_starts_with($message_content_lower, 'killciv')) {
			$accepted = false;
			if ($author_member = $message->member) {
				foreach ($author_member->roles as $role) {
					switch ($role->name) {
						case 'Admiral':
						case 'Captain':
						case 'Lieutenant':
							$accepted = true;
					}
				}
				if ($accepted) {
					execInBackgroundLinux('sudo python3 /home/1713/civ13-rp/scripts/killciv13.py');
					$message->channel->sendMessage("Attempted to kill Civilization 13 Server.");
				} else $message->channel->sendMessage("Denied!");
			} else $message->channel->sendMessage('Error! Unable to get Discord Member class.');
			return;
		}
		if (str_starts_with($message_content_lower, 'restartciv')) {
			$accepted = false;
			if ($author_member = $message->member) {
				foreach ($author_member->roles as $role) {
					switch ($role->name) {
						case 'Admiral':
						case 'Captain':
						case 'Lieutenant':
							$accepted = true;
					}
				}
				if ($accepted) {
					execInBackgroundLinux('sudo python3 /home/1713/civ13-rp/scripts/killciv13.py');
					$message->channel->sendMessage("Attempted to kill Civilization 13 Server.");
					execInBackgroundLinux('sudo python3 /home/1713/civ13-rp/scripts/updateserverabspaths.py');
					$message->channel->sendMessage("Updated the code.");
					execInBackgroundLinux('sudo rm -f /home/1713/civ13-rp/serverdata.txt');
					execInBackgroundLinux('sudo DreamDaemon /home/1713/civ13-rp/civ13.dmb 1715 -trusted -webclient -logself &');
					$message->channel->sendMessage("Attempted to bring up Civilization 13 (Main Server) <byond://51.254.161.128:1715>");
					$discord->getLoop()->addTimer(10, function() { # ditto
						execInBackgroundLinux('sudo python3 /home/1713/civ13-rp/scripts/killsudos.py');
					});
				} else $message->channel->sendMessage("Denied!");
			} else $message->channel->sendMessage('Error! Unable to get Discord Member class.');
			return;
		}
		if (str_starts_with($message_content_lower, 'restarttdm')) {
			$accepted = false;
			if ($author_member = $message->member) {
				foreach ($author_member->roles as $role) {
					switch ($role->name) {
						case 'Admiral':
						case 'Captain':
						case 'Lieutenant':
							$accepted = true;
					}
				}
				if ($accepted) {
					execInBackgroundLinux('sudo python3 /home/1713/civ13-tdm/scripts/killciv13.py');
					$message->channel->sendMessage("Attempted to kill Civilization 13 TDM Server.");
					execInBackgroundLinux('sudo python3 /home/1713/civ13-tdmp/scripts/updateserverabspaths.py');
					$message->channel->sendMessage("Updated the code.");
					execInBackgroundLinux('sudo rm -f /home/1713/civ13-tdm/serverdata.txt');
					execInBackgroundLinux('sudo DreamDaemon /home/1713/civ13-tdm/civ13.dmb 1714 -trusted -webclient -logself &');
					$message->channel->sendMessage("Attempted to bring up Civilization 13 (TDM Server) <byond://51.254.161.128:1714>");
					$discord->getLoop()->addTimer(10, function() { # ditto
						execInBackgroundLinux('sudo python3 /home/1713/civ13-tdm/scripts/killsudos.py');
					});
				} else $message->channel->sendMessage("Denied!");
			} else $message->channel->sendMessage('Error! Unable to get Discord Member class.');
			return;
		}
		if (str_starts_with($message_content_lower, 'mapswap')) {
			$accepted = false;
			if ($author_member = $message->member) {
				foreach ($author_member->roles as $role) {
					switch ($role->name) {
						case 'Admiral':
						case 'Captain':
						case 'Lieutenant':
							$accepted = true;
					}
				}
				if ($accepted) {
					$split_message = explode("mapswap ", $message_content);
					if ((count($split_message) > 1) && (strlen($split_message[1]) > 0)) {
						$mapto = $split_message[1];
						$mapto = strtoupper($mapto);
						$message->channel->sendMessage("Changing map to $mapto...");
						execInBackgroundLinux("sudo python3 /home/1713/civ13-rp/scripts/mapswap.py $mapto");
						$message->channel->sendMessage("Sucessfully changed map to $mapto.");
					}
				} else $message->channel->sendMessage("Denied!");
			} else $message->channel->sendMessage('Error! Unable to get Discord Member class.');
			return;
		}
		if (str_starts_with($message_content_lower, 'hosttdm')) {
			$accepted = false;
			if ($author_member = $message->member) {
				foreach ($author_member->roles as $role) {
					switch ($role->name) {
						case 'Admiral':
						case 'Captain':
							$accepted = true;
					}
				}
				if ($accepted) {
					$message->channel->sendMessage("Please wait, updating the code...");
					execInBackgroundLinux('sudo python3 /home/1713/civ13-tdm/scripts/updateserverabspaths.py');
					$message->channel->sendMessage("Updated the code.");
					execInBackgroundLinux('sudo rm -f /home/1713/civ13-tdm/serverdata.txt');
					execInBackgroundLinux('sudo DreamDaemon /home/1713/civ13-tdm/civ13.dmb 1714 -trusted -webclient -logself &');
					$message->channel->sendMessage("Attempted to bring up Civilization 13 (TDM Server) <byond://51.254.161.128:1714>");
					$discord->getLoop()->addTimer(10, function() { # ditto
						execInBackgroundLinux('sudo python3 /home/1713/civ13-tdm/scripts/killsudos.py');
					});
				} else $message->channel->sendMessage("Denied!");
			} else $message->channel->sendMessage('Error! Unable to get Discord Member class.');
			return;
		}
		if (str_starts_with($message_content_lower, 'killtdm')) {
			$accepted = false;
			if ($author_member = $message->member) {
				foreach ($author_member->roles as $role) {
					switch ($role->name) {
						case 'Admiral':
						case 'Captain':
							$accepted = true;
					}
				}
				if ($accepted) {
					execInBackgroundLinux('sudo python3 /home/1713/civ13-tdm/scripts/killciv13.py');
					$message->channel->sendMessage("Attempted to kill Civilization 13 (TDM Server).");
				} else $message->channel->sendMessage("Denied!");
			} else $message->channel->sendMessage('Error! Unable to get Discord Member class.');
			return;
		}
		if (str_starts_with($message_content_lower, 'tdmmapswap')) {
			$accepted = false;
			if ($author_member = $message->member) {
				foreach ($author_member->roles as $role) {
					switch ($role->name) {
						case 'Admiral':
						case 'Captain':
						case 'Knight':
							$accepted = true;
					}
				}
				if ($accepted) {
					$split_message = explode("mapswap ", $message_content);
					if ((count($split_message) > 1) && (strlen($split_message[1]) > 0)) {
						$mapto = $split_message[1];
						$mapto = strtoupper($mapto);
						$message->channel->sendMessage("Changing map to $mapto...");
						execInBackgroundLinux("sudo python3 /home/1713/civ13-tdm/scripts/mapswap.py $mapto");
						$message->channel->sendMessage("Sucessfully changed map to $mapto.");
					}
				} else $message->channel->sendMessage("Denied!");
			} else $message->channel->sendMessage('Error! Unable to get Discord Member class.');
			return;
		}
				
		if (str_starts_with($message_content_lower, "bancheck")) {
			$split_message = explode('bancheck ', $message_content);
			if ((count($split_message) > 1) && (strlen($split_message[1]) > 0)) {
				$ckey = trim($split_message[1]);
				$ckey = strtolower($ckey);
				$ckey = str_replace('_', '', $ckey);
				$ckey = str_replace(' ', '', $ckey);
				$banreason = "unknown";
				$found = false;
				$filecheck1 = fopen("/home/1713/civ13-rp/SQL/bans.txt", "r") ?? NULL;
				if ($filecheck1) {
					while (($fp = fgets($filecheck1, 4096)) !== false) {
						str_replace("\n", "", $fp);
						$filter = "|||";
						$line = trim(str_replace($filter, "", $fp));
						$linesplit = explode(";", $line); //$split_ckey[0] is the ckey
						if ((count($linesplit)>=8) && ($linesplit[8] == $ckey)) {
							$found = true;
							$banreason = $linesplit[3];
							$bandate = $linesplit[5];
							$banner = $linesplit[4];
							$message->channel->sendMessage("**$ckey** has been banned from **Nomads** on **$bandate** for **$banreason** by $banner.");
						}
					}
					fclose($filecheck1);
				}
				$filecheck2 = fopen("/home/1713/civ13-tdm/SQL/bans.txt", "r") ?? NULL;
				if ($filecheck2) {
					while (($fp = fgets($filecheck2, 4096)) !== false) {
						str_replace("\n", "", $fp);
						$filter = "|||";
						$line = trim(str_replace($filter, "", $fp));
						$linesplit = explode(";", $line); //$split_ckey[0] is the ckey
						if ((count($linesplit)>=8) && ($linesplit[8] == $ckey)) {
							$found = true;
							$banreason = $linesplit[3];
							$bandate = $linesplit[5];
							$banner = $linesplit[4];
							$message->channel->sendMessage("**$ckey** has been banned from **Nomads** on **$bandate** for **$banreason** by $banner.");
						}
					}
					fclose($filecheck2);
				}
				if (!$found) $message->channel->sendMessage("No bans were found for **$ckey**.");
			} else $message->channel->sendMessage("Wrong format. Please try '!s bancheck [ckey].'");
			return;
		}
		if (str_starts_with($message_content_lower,'serverstatus')) {
			$embed = $discord->factory(\Discord\Parts\Embed\Embed::class);
			$_1714 = !portIsAvailable(1714);
			$server_is_up = $_1714;
			if (!$server_is_up) {
				$embed->setColor(0x00ff00);
				$embed->addFieldValues("TDM Server Status", "Offline");
				#$message->channel->sendEmbed($embed);
				#return;
			} else {
				$data = "None";
				if ($_1714) {
					if (!$data = file_get_contents('/home/1713/civ13-tdm/serverdata.txt'))
						$message->channel->sendMessage('Unable to access serverdata.txt!');
				} else {
					$embed->setColor(0x00ff00);
					$embed->addFieldValues("TDM Server Status", "Offline");
					#$message->channel->sendEmbed($embed);
					#return;
				}
				$data = str_replace('<b>Address</b>: ', '', $data);
				$data = str_replace('<b>Map</b>: ', '', $data);
				$data = str_replace('<b>Gamemode</b>: ', '', $data);
				$data = str_replace('<b>Players</b>: ', '', $data);
				$data = str_replace('</b>', '', $data);
				$data = str_replace('<b>', '', $data);
				$data = explode(';', $data);
				#embed = discord.Embed(title="**Civ13 Bot**", color=0x00ff00)
				$embed->setColor(0x00ff00);
				$embed->addFieldValues("TDM Server Status", "Online");
				if (isset($data[1])) $embed->addFieldValues("Address", '<'.$data[1].'>');
				if (isset($data[2])) $embed->addFieldValues("Map", $data[2]);
				if (isset($data[3])) $embed->addFieldValues("Gamemode", $data[3]);
				if (isset($data[4])) $embed->addFieldValues("Players", $data[4]);

				#$message->channel->sendEmbed($embed);
				#return;
			}
			$_1715 = !portIsAvailable(1715);
			$server_is_up = ($_1715);
			if (!$server_is_up) {
				$embed->setColor(0x00ff00);
				$embed->addFieldValues("Nomads Server Status", "Offline");
				#$message->channel->sendEmbed($embed);
				#return;
			} else {
				$data = "None";
				if ($_1714) {
					if (!$data = file_get_contents('/home/1713/civ13-rp/serverdata.txt'))
						$message->channel->sendMessage('Unable to access serverdata.txt!');
				} else {
					$embed->setColor(0x00ff00);
					$embed->addFieldValues("Nomads Server Status", "Offline");
					#$message->channel->sendEmbed($embed);
					#return;
				}
				$data = str_replace('<b>Address</b>: ', '', $data);
				$data = str_replace('<b>Map</b>: ', '', $data);
				$data = str_replace('<b>Gamemode</b>: ', '', $data);
				$data = str_replace('<b>Players</b>: ', '', $data);
				$data = str_replace('</b>', '', $data);
				$data = str_replace('<b>', '', $data);
				$data = explode(';', $data);
				#embed = discord.Embed(title="**Civ13 Bot**", color=0x00ff00)
				$embed->setColor(0x00ff00);
				$embed->addFieldValues("Nomads Server Status", "Online");
				if (isset($data[1])) $embed->addFieldValues("Address", '<'.$data[1].'>');
				if (isset($data[2])) $embed->addFieldValues("Map", $data[2]);
				if (isset($data[3])) $embed->addFieldValues("Gamemode", $data[3]);
				if (isset($data[4])) $embed->addFieldValues("Players", $data[4]);
			}
			$message->channel->sendEmbed($embed);
			return;
		}
	}
}

function recalculate_ranking() {
	$ranking = array();
	$ckeylist = array();
	$result = array();
	
	if ($search = fopen('/home/1713/civ13-tdm/SQL/awards.txt', "r")) {
		while(! feof($search)) {
			$medal_s = 0;
			$line = fgets($search);
			$line = trim(str_replace('\n', "", $line)); # remove '\n' at end of line
			$duser = explode(';', $line);
			if ($duser[2] == "long service medal")
				$medal_s += 0.75;
			if ($duser[2] == "combat medical badge")
				$medal_s += 2;
			if ($duser[2] == "tank destroyer silver badge")
				$medal_s += 1;
			if ($duser[2] == "tank destroyer gold badge")
				$medal_s += 2;
			if ($duser[2] == "assault badge")
				$medal_s += 1.5;
			if ($duser[2] == "wounded badge")
				$medal_s += 0.5;
			if ($duser[2] == "wounded silver badge")
				$medal_s += 0.75;
			if ($duser[2] == "wounded gold badge")
				$medal_s += 1;
			if ($duser[2] == "iron cross 1st class")
				$medal_s += 3;
			if ($duser[2] == "iron cross 2nd class")
				$medal_s += 5;
			$result[] = $medal_s . ';' . $duser[0];
			if (!in_array($duser[0], $ckeylist))
				$ckeylist[] = $duser[0];
		}
	} else $message->channel->sendMessage('Unable to access awards.txt!');
	
	foreach ($ckeylist as $i) {
		$sumc = 0;
		foreach ($result as $j) {
			$sj = explode(';', $j);
			if ($sj[1] == $i)
				$sumc += (float) $sj[0];
		}
		$ranking[] = [$sumc,$i];
	}
	usort($ranking, function($a, $b) {
		return $a[0] <=> $b[0];
	});
	$sorted_list = array_reverse($ranking);
	if ($search = fopen('ranking.txt', 'w'))
		foreach ($sorted_list as $i)
			fwrite($search, $i[0] . ";" . $i[1] . "\n");
	fclose ($search);
	return;
}

function on_message2($message, $discord, $loop, $command_symbol = '!s') {
	if (str_starts_with($message->content, $command_symbol . ' ')) { //Add these as slash commands?
		$message_content = substr($message->content, strlen($command_symbol)+1);
		$message_content_lower = strtolower($message_content);
		if (str_starts_with($message_content_lower, 'ranking')) {
			recalculate_ranking();
			$line_array = array();
			if ($search = fopen('ranking.txt', "r")) {
				while (($fp = fgets($search, 4096)) !== false) {
					$line_array[] = $fp;
				}
				fclose($search);
			} else $message->channel->sendMessage('Unable to access ranking.txt!');
			$topsum = 1;
			$msg = '';
			for ($x=0;$x<count($line_array);$x++) {
				$line = $line_array[$x];
				if ($topsum <= 10) {
					$line = trim(str_replace('\n', "", $line));
					$topsum += 1;
					$sline = explode(';', $line);
					$msg .= "(". ($topsum - 1) ."): **".$sline[1]."** with **".$sline[0]."** points.\n";
				} else break;
			}
			if ($msg != '') $message->channel->sendMessage($msg);
		}
		if (str_starts_with($message_content_lower, 'rankme')) {
			$split_message = explode('rankme ', $message_content);
			$ckey = "";
			$medal_s = 0;
			$result = "";
			if ((count($split_message) > 1) && (strlen($split_message[1]) > 0)) {
				$ckey = $split_message[1];
				$ckey = strtolower($ckey);
				$ckey = str_replace('_', '', $ckey);
				$ckey = str_replace(' ', '', $ckey);
			}
			recalculate_ranking();
			$line_array = array();
			if ($search = fopen('ranking.txt', "r")) {
				while (($fp = fgets($search, 4096)) !== false) {
					$line_array[] = $fp;
				}
				fclose($search);
			} else $message->channel->sendMessage('Unable to access ranking.txt!');
			$found = 0;
			$result = '';
			for ($x=0;$x<count($line_array);$x++) {
				$line = $line_array[$x];
				$line = trim(str_replace('\n', "", $line));
				$sline = explode(';', $line);
				if ($sline[1] == $ckey) {
					$found = 1;
					$result .= "**" . $sline[1] . "**" . " has a total rank of **" . $sline[0] . "**.";
				};
			}
			if (!$found) $message->channel->sendMessage("No medals found for this ckey.");
			else $message->channel->sendMessage($result);
		}
		if (str_starts_with($message_content_lower, 'medals')) {
			$split_message = explode('medals ', $message_content);
			$ckey = "";
			if ((count($split_message) > 1) && (strlen($split_message[1]) > 0)) {
				$ckey = $split_message[1];
				$ckey = strtolower($ckey);
				$ckey = str_replace('_', '', $ckey);
				$ckey = str_replace(' ', '', $ckey);
			}
			$result = '';
			$search = fopen('/home/1713/civ13-tdm/SQL/awards.txt', 'r');
			$found = false;
			while(! feof($search)) {
				$line = fgets($search);
				$line = trim(str_replace('\n', "", $line)); # remove '\n' at end of line
				if (str_contains($line, $ckey)) {
					$found = true;
					$duser = explode(';', $line);
					if ($duser[0] == $ckey) {
						$medal_s = "<:long_service:705786458874707978>";
						if ($duser[2] == "long service medal")
							$medal_s = "<:long_service:705786458874707978>";
						if ($duser[2] == "combat medical badge")
							$medal_s = "<:combat_medical_badge:706583430141444126>";
						if ($duser[2] == "tank destroyer silver badge")
							$medal_s = "<:tank_silver:705786458882965504>";
						if ($duser[2] == "tank destroyer gold badge")
							$medal_s = "<:tank_gold:705787308926042112>";
						if ($duser[2] == "assault badge")
							$medal_s = "<:assault:705786458581106772>";
						if ($duser[2] == "wounded badge")
							$medal_s = "<:wounded:705786458677706904>";
						if ($duser[2] == "wounded silver badge")
							$medal_s = "<:wounded_silver:705786458916651068>";
						if ($duser[2] == "wounded gold badge")
							$medal_s = "<:wounded_gold:705786458845216848>";
						if ($duser[2] == "iron cross 1st class")
							$medal_s = "<:iron_cross1:705786458572587109>";
						if ($duser[2] == "iron cross 2nd class")
							$medal_s = "<:iron_cross2:705786458849673267>";
						$result .= "**" . $duser[1] . ":**" . " " . $medal_s . " **" . $duser[2] . "**, *" . $duser[4] . "*, " . $duser[5] . "\n";
					}
				}
			}
			if ($result != '') $message->channel->sendMessage($result);
			if (!$found && ($result == '')) $message->channel->sendMessage("No medals found for this ckey.");
		}
		if (str_starts_with($message_content_lower, 'ts')) {
			$split_message = explode('ts ', $message_content);
			if ((count($split_message) > 1) && (strlen($split_message[1]) > 0)) {
				$state = $split_message[1];
				$accepted = false;
				
				if ($author_member = $message->member) {
					foreach ($author_member->roles as $role) {
						switch ($role->name) {
							case 'Admiral':
								$accepted = true;
						}
					}
				}else $message->channel->sendMessage('Error! Unable to get Discord Member class.');

				if ($accepted) {
					if ($state == "on") {
						execInBackgroundLinux('cd /home/1713/civ13-typespess');
						execInBackgroundLinux('sudo git pull');
						execInBackgroundLinux('sudo sh launch_server.sh &');
						$message->channel->sendMessage("Put **TypeSpess Civ13** test server on: http://civ13.com/ts");
					} elseif ($state == "off") {
						execInBackgroundLinux('sudo killall index.js');
						$message->channel->sendMessage("**TypeSpess Civ13** test server down.");
					}
				}
			}
		}
	}
}

$discord->once('ready', function ($discord) use ($loop, $command_symbol)
{
	on_ready($discord);
	
	$discord->on('message', function ($message) use ($discord, $loop, $command_symbol) { //Handling of a message
		on_message($message, $discord, $loop, $command_symbol);
		on_message2($message, $discord, $loop, $command_symbol);
	});
});

$discord->run();
