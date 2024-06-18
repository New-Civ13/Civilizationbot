<?php


/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2024-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ13;

use Discord\Discord;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Channel\Message;
use Discord\Parts\Embed\Embed;
use Discord\Parts\Thread\Thread;
use Discord\Parts\User\Member;
use Monolog\Logger;
use React\EventLoop\StreamSelectLoop;
use React\EventLoop\TimerInterface;
use React\Promise\PromiseInterface;

class GameServer {
    public Discord $discord;
    public Logger $logger;
    public StreamSelectLoop $loop;
    public Civ13 $civ13;
    public bool $ready = false;

    // Resolved paths
    private readonly string $serverdata;
    private readonly string $discord2unban;
    private readonly string $discord2ban;
    private readonly string $admins;
    private readonly string $whitelist;
    private readonly string $factionlist;
    private readonly string $ranking_path;

    // Required settings
    public string $basedir; // The base directory on the local filesystem.
    public string $key; // The shorthand alias for the server.
    public string $name; // The name.
    public string $ip; // The IP.
    public string $port; // The port.
    public string $host; // The host (e.g. Taislin).
    public bool $supported; // Whether the server is supported by the remote webserver and will appear in data retrieved from it.
    public bool $enabled; // Whether the server is enabled and accessible by this bot.
    public bool $legacy; // Whether the server uses Civ13 legacy file cache or newer SQL methods.
    public bool $moderate; // Whether the server should moderate chat using the bot.
    public bool $panic_bunker; // Whether the server should only allow verified users to join.
    public bool $log_attacks; // Whether the server should log attacks to the attack channel.
    public string $relay_method ; // The method used to relay chat messages to the server (either 'file' or 'webhook').

    // Discord Channel IDs
    public string $discussion;
    public string $playercount;
    public string $ooc;
    public string $lobby;
    public string $asay;
    public string $ic;
    public string $transit;
    public string $adminlog;
    public string $debug;
    public string $garbage;
    public string $runtime;
    public string $attack;
    
    // Generated by the bot
    /**
     * @var Timerinterface[]
     */
    public array $timers = [];
    public array $serverinfo = [];
    public array $players = [];
    public array $seen_players = [];
    private int $playercount_ticker = 0;

    public function __construct(Civ13 &$civ13, array &$options)
    {
        $this->civ13 =& $civ13;
        $this->discord =& $civ13->discord;
        $this->logger =& $civ13->logger;
        $this->loop = $civ13->loop;
        $this->resolveOptions($options);
        $this->basedir = $options['basedir'];
        $this->key = $options['key'];
        $this->name = $options['name'];
        $this->ip = $options['ip'];
        $this->port = $options['port'];
        $this->host = $options['host'];
        $this->supported = $options['supported'] ?? false;
        $this->enabled = $options['enabled'] ?? false;
        $this->legacy = $options['legacy'] ?? true;
        $this->moderate = $options['moderate'] ?? true;
        $this->panic_bunker = $options['panic_bunker']  ?? false;
        $this->log_attacks = $options['log_attacks'] ?? true;
        $this->relay_method = $options['relay_method'] ?? 'webhook';
        $this->discussion = $options['discussion'];
        $this->playercount = $options['playercount'];
        $this->ooc = $options['ooc'];
        $this->lobby = $options['lobby'];
        $this->asay = $options['asay'];
        $this->ic = $options['ic'];
        $this->transit = $options['transit'];
        $this->adminlog = $options['adminlog'];
        $this->debug = $options['debug'];
        $this->garbage = $options['garbage'];
        $this->runtime = $options['runtime'];
        $this->attack = $options['attack'];
        $this->afterConstruct();
    }
    private function afterConstruct(): void
    {
        $this->serverdata = $this->basedir . Civ13::serverdata;
        $this->discord2unban = $this->basedir . Civ13::discord2unban;
        $this->discord2ban = $this->basedir . Civ13::discord2ban;
        $this->admins = $this->basedir . Civ13::admins;
        $this->whitelist = $this->basedir . Civ13::whitelist;
        $this->factionlist = $this->basedir . Civ13::factionlist;
        $this->ranking_path = $this->basedir . Civ13::ranking_path;
        $this->setup();

        if (! $this->enabled) return; // Don't start timers for disabled servers
        $this->discord->once('ready', function () {
            $this->logger->info("Getting player count for Gameserver {$this->name}");
            $this->localServerPlayerCount(); // Populates $this->players
            $this->playercountTimer(); // Update playercount channel every 10 minutes
            $this->serverinfoTimer(); // Hard check playercount and  ckeys to scrutinizeCkey() every 3 minutes
            $this->relayTimer(); // File chat relay
        });
    }
    /**
     * This method is responsible for setting up the game server by performing the following tasks:
     * - Checking if the number of game servers exceeds the limit of 5 and logging a warning if it does.
     * - Adding the game server to the list of game servers in the Civ13 object.
     * - Adding the game server to the list of enabled game servers in the Civ13 object if it is enabled.
     * - Logging an informational message about the added game server.
     * - Setting the "ready" flag to true.
     *
     * @return void
     */
    private function setup()
    {
        if ($this->ready) return;
        if (count($this->civ13->gameservers) > 5) $this->logger->warning('Configuring more than 5 gameservers are not supported and you will likely experience issues.');
        $this->civ13->gameservers[$this->key] =& $this;
        if ($this->enabled) $this->civ13->enabled_gameservers[$this->key] =& $this;
        $this->logger->info('Added ' . ($this->enabled ? 'enabled' : 'disabled') . " game server: {$this->name} ({$this->key})");
        $this->ready = true;
    }
    private function resolveOptions(array $options)
    {
        $requiredProperties = [
            'basedir',
            'key',
            'name',
            'ip',
            'port',
            'host'
        ];
        foreach ($requiredProperties as $property)
            if (! isset($options[$property]))
                throw new \RuntimeException("Gameserver missing required property: $property");
        $optionalProperties = [
            'discussion',
            'playercount',
            'ooc',
            'lobby',
            'asay',
            'ic',
            'transit',
            'adminlog',
            'debug',
            'garbage',
            'runtime',
            'attack'
        ];
        foreach ($optionalProperties as $property)
            if (! isset($options[$property]))
                trigger_error("Gameserver missing optional property: $property", E_USER_WARNING);
    }
    
    
    /**
     * Returns an array of the player count for each locally hosted server in the configuration file.
     *
     * @return int The total player count for this locally hosted server, or 0 if the server is not local.
     */
    public function localServerPlayerCount(array $players = []): int
    {    
        if (! $this->enabled) return 0;
        if ($this->ip !== $this->civ13->httpServiceManager->httpHandler->external_ip) return 0; // Don't try and access files if the server is not local
        $socket = @fsockopen('localhost', intval($this->port), $errno, $errstr, 1);
        if (! is_resource($socket)) return 0;
        fclose($socket);
        $playercount = 0;
        if (! @file_exists($this->serverdata) || ! $data = @file_get_contents($this->serverdata)) {
            $this->logger->warning("unable to open `{$this->serverdata}`");
            return 0;
        }
        $data = explode(';', str_replace(['<b>Address</b>: ', '<b>Map</b>: ', '<b>Gamemode</b>: ', '<b>Players</b>: ', 'round_timer=', 'map=', 'epoch=', 'season=', 'ckey_list=', '</b>', '<b>'], '', $data));
        /*
        0 => <b>Server Status</b> {Online/Offline}
        1 => <b>Address</b> byond://{ip_address}
        2 => <b>Map</b>: {map}
        3 => <b>Gamemode</b>: {gamemode}
        4 => <b>Players</b>: {playercount}
        5 => realtime={realtime}
        6 => world.address={ip}
        7 => round_timer={00:00}
        8 => map={map}
        9 => epoch={epoch}
        10 => season={season}
        11 => ckey_list={ckey&ckey}
        */
        if (isset($data[11])) { // Player list
            $players = explode('&', $data[11]);
            $players = array_filter($players, fn($player) => ! empty($player));
            $players = array_map(fn($player) => $this->civ13->sanitizeInput($player), $players);
        }
        if (isset($data[4])) $playercount = $data[4]; // Player count
        $this->players = $players;
        return $playercount;
    }
    
    public function relayTimer(): TimerInterface
    {
        if ($this->discord->guilds->get('id', $this->civ13->civ13_guild_id) && (! (isset($this->timers['relay_timer'])) || (! $this->timers['relay_timer'] instanceof TimerInterface))) {
            $this->logger->debug("Starting file chat relay timer for {$this->key}");
            if (! isset($this->timers['relay_timer'])) $this->timers['relay_timer'] = $this->discord->getLoop()->addPeriodicTimer(10, function ()
            {
                if ($this->relay_method !== 'file') return null;
                if (! $guild = $this->discord->guilds->get('id', $this->civ13->civ13_guild_id)) return $this->logger->error("Could not find Guild with ID `{$this->civ13->civ13_guild_id}`");
                if ($channel = $guild->channels->get('id', $this->ooc)) $this->civ13->gameChatFileRelay($this->basedir . Civ13::ooc_path, $channel);  // #ooc-server
                if ($channel = $guild->channels->get('id', $this->asay)) $this->civ13->gameChatFileRelay($this->basedir . Civ13::admin_path, $channel);  // #asay-server
            });
        }
        return $this->timers['relay_timer'];
    }
    public function serverinfoTimer(): TimerInterface
    {
        if (! isset($this->timers['serverinfo_timer'])) $this->timers['serverinfo_timer'] = $this->discord->getLoop()->addPeriodicTimer(180, function () {
            if (! /*$playercount =*/ $this->localServerPlayerCount()) return; // No data available
            //$this->playercountChannelUpdate($playercount); // This needs to be updated to pass $this instead of "{$server}-""
            foreach ($this->players as $ckey) {
                if (is_null($ckey)) continue;
                if (isset($this->civ13->moderator)) $this->civ13->moderator->scrutinizeCkey($ckey);
            }
        });
        return $this->timers['serverinfo_timer']; // Check players every minute
    }
    public function serverinfoPlayers(): array
    { 
        if (empty($data_json = $this->serverinfo)) return [];
        $this->players = [];
        foreach ($data_json as $server) {
            if (array_key_exists('ERROR', $server)) continue;
            $stationname = $server['stationname'] ?? '';
            foreach (array_keys($server) as $key) {
                $p = explode('player', $key); 
                if (isset($p[1]) && is_numeric($p[1])) $this->players[$stationname][] = $this->civ13->sanitizeInput(urldecode($server[$key]));
            }
        }
        return $this->players;
    }

    public function playercountTimer(): TimerInterface
    {
        if (! isset($this->timers['playercount_timer]'])) $this->timers['playercount_timer'] = $this->loop->addPeriodicTimer(60, function () { // Update playercount channel every 10 minutes
            if ($this->playercount_ticker % 10 !== 0) return false;
            $this->playercountChannelUpdate(count($this->players));
        });
        return $this->timers['playercount_timer'];
    }

    /*
     * This function parses the serverinfo data and updates the relevant Discord channel name with the current player counts
     * Prefix is used to differentiate between two different servers, however it cannot be used with more due to ratelimits on Discord
     * It is called on ready and every 5 minutes
     */
    public function playercountChannelUpdate(int $count = 0): bool
    {
        if (! $channel = $this->discord->getChannel($this->playercount)) {
            $this->logger->warning("Channel {$this->playercount} doesn't exist!");
            return false;
        }
        if (! $channel->created) {
            $this->logger->warning("Channel {$channel->name} hasn't been created!");
            return false;
        }
        [$channelPrefix, $existingCount] = explode('-', $channel->name);
        if ((int)$existingCount !== $count) {
            $channel->name = "{$channelPrefix}-{$count}";
            $channel->guild->channels->save($channel);
        }
        return true;
    }

    /**
     * Sends an out-of-character (OOC) message.
     *
     * @param string $message The message to be sent.
     * @param string $sender The sender of the message.
     * @return PromiseInterface|bool Returns a PromiseInterface if the message is successfully sent, or false if sending the message fails.
     */
    public function OOCMessage(string $message, string $sender): PromiseInterface|bool
    {
        if (! $this->enabled) return false;
        if (! touch($path = $this->basedir . Civ13::discord2ooc) || ! $file = @fopen($path, 'a')) {
            $this->logger->error("unable to open `$path` for writing");
            return false;
        }
        fwrite($file, "$sender:::$message" . PHP_EOL);
        fclose($file);
        if ($this->ooc && $channel = $this->discord->getChannel($this->ooc)) if ($promise = $this->civ13->relayPlayerMessage($channel, $message, $sender)) return $promise;
        return true;
    }
    /**
     * Sends an admin message to the server.
     *
     * @param string $message The message to send.
     * @param string $sender The sender of the message.
     * @return bool Returns true if the message was sent successfully, false otherwise.
     */
    public function AdminMessage(string $message, string $sender): PromiseInterface|bool
    {
        if (! $this->enabled) return false;
        if (! @touch($path = $this->basedir . Civ13::discord2admin) || ! $file = @fopen($path, 'a')) {
            $this->logger->error("unable to open `$path` for writing");
            return false;
        }
        fwrite($file, "$sender:::$message" . PHP_EOL);
        fclose($file);
        $urgent = true; // Check if there are any admins on the server, if not then send the message as urgent
        if ($guild = $this->discord->guilds->get('id', $this->civ13->civ13_guild_id)) {
            $admin = false;
            if (isset($this->civ13->verifier)) {
                if ($item = $this->civ13->verifier->get('ss13', $sender))
                    if ($member = $guild->members->get('id', $item['discord']))
                        if ($member->roles->has($this->civ13->role_ids['Admin']))
                            { $admin = true; $urgent = false;}
                if (! $admin) {
                    if ($playerlist = $this->players)
                        if ($admins = $guild->members->filter(function (Member $member) { return $member->roles->has($this->civ13->role_ids['Admin']); }))
                            foreach ($admins as $member)
                                if ($item = $this->civ13->verifier->get('discord', $member->id))
                                    if (in_array($item['ss13'], $playerlist))
                                        { $urgent = false; break; }
                }
            }
        }
        if ($this->asay && $channel = $this->discord->getChannel($this->asay)) if ($promise = $this->civ13->relayPlayerMessage($channel, $message, $sender, null, $urgent)) return $promise;
        return true;
        
    }
    /**
     * Sends a direct message to a recipient using the specified sender and message.
     *
     * @param string $recipient The recipient of the direct message.
     * @param string $message The content of the direct message.
     * @param string $sender The sender of the direct message.
     * @return bool Returns true if the direct message was sent successfully, false otherwise.
     */
    public function DirectMessage(string $message, string $sender, string $recipient): PromiseInterface|bool
    {
        if (! $this->enabled) return false;
        if (! @touch($path = $this->basedir . Civ13::discord2dm) || ! $file = @fopen($path, 'a')) {
            $this->logger->debug("unable to open `$path` for writing");
            return false;
        }
        fwrite($file, "$sender:::$recipient:::$message" . PHP_EOL);
        fclose($file);
        if ($this->asay && $channel = $this->discord->getChannel($this->asay)) if ($promise = $this->civ13->relayPlayerMessage($channel, $message, $sender, $recipient)) return $promise;
        return true;
    }

    public function mapswap(string $mapto, string $admin): string
    {
        if (! file_exists($fp = $this->civ13->files['map_defines_path']) || ! $file = @fopen($fp, 'r')) {
            $this->logger->error("Unable to open `$fp` for reading.");
            return "Unable to open `$fp` for reading.";
        }
    
        $maps = array();
        while (($fp = fgets($file, 4096)) !== false) {
            $linesplit = explode(' ', trim(str_replace('"', '', $fp)));
            if (isset($linesplit[2]) && $map = trim($linesplit[2])) $maps[] = $map;
        }
        fclose($file);
        if (! in_array($mapto, $maps)) return "`$mapto` was not found in the map definitions.";

        $promise = $this->OOCMessage($msg = "Server is now changing map to `$mapto`.", $this->civ13->verifier->getVerifiedItem($admin)['ss13'] ?? $this->civ13->discord->user->username);
        if ($channel = $this->civ13->discord->getChannel($this->discussion)) {
            if (isset($this->civ13->role_ids['mapswap']) && $role = $this->civ13->role_ids['mapswap']); $msg = "<@&$role>, $msg";
            $channel->sendMessage($msg);
        }
        $func = function () use ($mapto) { \execInBackground('python3 ' . $this->basedir . Civ13::mapswap . " $mapto" ); };
        $this->loop->addTimer(10, function () use ($promise, $func) {
            if ($promise instanceof PromiseInterface) $this->civ13->then($promise->then($func, $this->civ13->onRejectedDefault));
            else $func();
        });
        return $msg;
    }

    /**
     * Determines whether a ckey is currently banned from the server.
     *
     * This function is called when a user is verified to determine whether they should be given the banished role or have it taken away.
     * It checks the nomads_bans.txt and tdm_bans.txt files for the ckey.
     *
     * @param string $ckey The ckey to check for banishment.
     * @param bool $bypass (optional) If set to true, the function will not add or remove the banished role from the user.
     * @return bool Returns true if the ckey is found in either ban file, false otherwise.
     */
    public function bancheck(string $ckey, bool $bypass = false): bool
    {
        return $this->legacy ? $this->legacyBancheck($ckey) : $this->sqlBancheck($ckey);
    }
    public function permabancheck(string $id, bool $bypass = false): bool
    {
        if (! $id = $this->civ13->sanitizeInput($id)) return false;
        $permabanned = ($this->legacy ? $this->legacyPermabancheck($id) : $this->sqlPermabancheck($id));
        return $permabanned;
    }
    /**
     * Checks if a player with the given ckey is permabanned based on legacy settings.
     *
     * @param string $ckey The ckey of the player to check.
     * @return bool Returns true if the player is permabanned, false otherwise.
     */
    public function legacyPermabancheck(string $ckey): bool
    {
        if (! @file_exists($path = $this->basedir . Civ13::bans) || ! $file = @fopen($path, 'r')) {
            $this->logger->debug("unable to open `$path`");
            return false;
        }
        while (($fp = fgets($file, 4096)) !== false) {
            // str_replace(PHP_EOL, '', $fp); // Is this necessary?
            $linesplit = explode(';', trim(str_replace('|||', '', $fp))); // $split_ckey[0] is the ckey
            if ((count($linesplit)>=8) && ($linesplit[8] === $ckey) && ($linesplit[0] === 'Server') && (str_ends_with($linesplit[7], '999 years'))) {
                fclose($file);
                return true;
            }
        }
        fclose($file);
        return false;
    }
    /**
     * Checks if a player with the given ckey is permabanned.
     *
     * @param string $ckey The ckey of the player to check.
     * @return bool Returns true if the player is permabanned, false otherwise.
     */
    public function sqlPermabancheck(string $ckey): bool
    {
        // TODO
        return false;
    }
    /**
     * Checks if a given ckey is banned based on legacy ban data.
     *
     * @param string $ckey The ckey to check for ban.
     * @return bool Returns true if the ckey is banned, false otherwise.
     */
    public function legacyBancheck(string $ckey): bool
    {
        if (! @file_exists($path = $this->basedir . Civ13::bans) || ! $file = @fopen($path, 'r')) {
            $this->logger->debug("unable to open `$path`");
            return false;
        }
        while (($fp = fgets($file, 4096)) !== false) {
            // str_replace(PHP_EOL, '', $fp); // Is this necessary?
            $linesplit = explode(';', trim(str_replace('|||', '', $fp))); // $split_ckey[0] is the ckey
            if ((count($linesplit)>=8) && ($linesplit[8] === $ckey)) {
                fclose($file);
                return true;
            }
        }
        fclose($file);
        return false;
    }
    /**
     * Checks if a player with the given ckey is banned.
     *
     * @param string $ckey The ckey of the player to check.
     * @return bool Returns true if the player is banned, false otherwise.
     */
    public function sqlBancheck(string $ckey): bool
    {
        // TODO
        return false;
    }

    /*
     * These functions determine which of the above methods should be used to process a ban or unban
     * Ban functions will return a string containing the results of the ban
     * Unban functions will return nothing, but may contain error-handling messages that can be passed to $logger->warning()
     */
    public function ban(array $array /* = ['ckey' => '', 'duration' => '', 'reason' => ''] */, ?string $admin = null, bool $permanent = false): string
    {
        if (! isset($array['ckey'])) return "You must specify a ckey to ban.";
        if (! is_numeric($array['ckey']) && ! is_string($array['ckey'])) return "The ckey must be a Byond username or Discord ID.";
        if (! isset($array['duration'])) return "You must specify a duration to ban for.";
        if ($array['duration'] === '999 years') $permanent = true;
        if (! isset($array['reason'])) return "You must specify a reason for the ban.";

        if (is_numeric($array['ckey'] = $this->civ13->sanitizeInput($array['ckey']))) {
            if (! isset($this->civ13->verifier) || ! $item = $this->civ13->verifier->get('discord', $array['ckey'])) return "Unable to find a ckey for <@{$array['ckey']}>. Please use the ckey instead of the Discord ID.";
            $array['ckey'] = $item['ss13'];
        }
        if (isset($this->civ13->verifier) && $member = $this->civ13->verifier->getVerifiedMember($array['ckey'])) {
            if (! $member->roles->has($this->civ13->role_ids['banished'])) {
                $string = "Banned for {$array['duration']} with the reason {$array['reason']}";
                $permanent ? $member->setRoles([$this->civ13->role_ids['banished'], $this->civ13->role_ids['permabanished']], $string) : $member->addRole($this->civ13->role_ids['banished'], $string);
            }
        }
        return $this->legacy ? $this->legacyBan($array, $admin) : $this->sqlBan($array, $admin);
    }
    private function legacyBan(array $array, ?string $admin = null): string
    {
        $admin = $admin ?? $this->discord->user->username;
        if (str_starts_with(strtolower($array['duration']), 'perm')) $array['duration'] = '999 years';
        if (! @touch($this->discord2ban) || ! $file = @fopen($this->discord2ban, 'a')) {
            $this->logger->warning("unable to open `{$this->discord2ban}`");
            return "unable to open `{$this->discord2ban}`" . PHP_EOL;
        }
        fwrite($file, "$admin:::{$array['ckey']}:::{$array['duration']}:::{$array['reason']}" . PHP_EOL);
        fclose($file);
        return "**$admin** banned **{$array['ckey']}** from **{$this->name}** for **{$array['duration']}** with the reason **{$array['reason']}**" . PHP_EOL;
    }
    private function sqlBan(array $array, ?string $admin = null): string
    {
        return "SQL methods are not yet implemented!" . PHP_EOL;
    }

    /**
     * Unbans a player with the specified ckey.
     *
     * @param string $ckey The ckey of the player to unban.
     * @param string|null $admin The name of the admin who is performing the unban. If not provided, the display name of the Discord user will be used.
     * @return void
     */
    public function unban(string $ckey, ?string $admin = null,): void
    {
        $admin ??= $this->discord->user->username;
        $this->legacy ? $this->legacyUnban($ckey, $admin) : $this->sqlUnban($ckey, $admin);
        if (isset($this->civ13->verifier) && $member = $this->civ13->verifier->getVerifiedMember($ckey)) {
            if ($member->roles->has($this->civ13->role_ids['banished'])) $member->removeRole($this->civ13->role_ids['banished'], "Unbanned by $admin");
            if ($member->roles->has($this->civ13->role_ids['permabanished'])) {
                $member->removeRole($this->civ13->role_ids['permabanished'], "Unbanned by $admin");
                $member->addRole($this->civ13->role_ids['infantry'], "Unbanned by $admin");
            }
        }
    }
    private function legacyUnban(string $ckey, ?string $admin = null): void
    {
        $admin = $admin ?? $this->discord->user->username;
        if (! @touch($this->discord2unban) || ! $file = @fopen($this->discord2unban, 'a')) {
            $this->logger->warning("unable to open `$this->discord2unban`");
            return;
        }
        fwrite($file, $admin . ":::$ckey");
        fclose($file);
    }
    private function sqlUnban($array, ?string $admin = null): string
    {
        return "SQL methods are not yet implemented!" . PHP_EOL;
    }

    /**
     * Retrieves the rank for a given ckey from a file.
     *
     * @param string $ckey The ckey to search for.
     * @return false|string Returns the rank for the ckey as a string if found, or false if the file does not exist or cannot be accessed.
     */
    public function getRank(string $ckey): false|string
    {
        $line_array = array();
        if (! @touch($this->ranking_path) || ! $search = @fopen($this->ranking_path, 'r')) return false;
        while (($fp = fgets($search, 4096)) !== false) $line_array[] = $fp;
        fclose($search);
        
        $found = false;
        $result = '';
        foreach ($line_array as $line) {
            $sline = explode(';', trim(str_replace(PHP_EOL, '', $line)));
            if ($sline[1] == $ckey) {
                $found = true;
                $result .= "**{$sline[1]}** has a total rank of **{$sline[0]}**";
            };
        }
        if (! $found) return "No medals found for ckey `$ckey`.";
        return $result;
    }

    /**
     * Updates the whitelist based on the member roles.
     *
     * @param array|null $required_roles The required roles for whitelisting. Default is ['veteran'].
     * @return bool Returns true if the whitelist update is successful, false otherwise.
     */
    public function whitelistUpdate(?array $required_roles = ['veteran', 'infantry']): bool
    {
        if (! $this->civ13->hasRequiredConfigRoles($required_roles)) return false;
        if (! $this->enabled) return false;
        if (! @touch($this->whitelist)) {
            $this->logger->warning("unable to open `{$this->whitelist}`");
            return false;
        }
        $file_paths = [];
        $file_paths[] = $this->whitelist;

        $callback = function (Member $member, array $item, array $required_roles): string
        {
            $string = '';
            foreach ($required_roles as $role)
                if ($member->roles->has($this->civ13->role_ids[$role]))
                    $string .= "{$item['ss13']} = {$item['discord']}" . PHP_EOL;
            return $string;
        };
        $this->civ13->updateFilesFromMemberRoles($callback, $file_paths, $required_roles);
        return true;
    }
    /**
     * Updates the faction list based on the required roles.
     *
     * @param array|null $required_roles The required roles for updating the faction list. Default is ['red', 'blue', 'organizer'].
     * @return bool Returns true if the faction list is successfully updated, false otherwise.
     */
    public function factionlistUpdate(?array $required_roles = ['red', 'blue', 'organizer']): bool
    {
        if (! $this->enabled) return false;
        if (! $this->civ13->hasRequiredConfigRoles($required_roles)) return false;
        if (! @touch($this->factionlist)) {
            $this->logger->warning("unable to open `{$this->factionlist}`");
            return false;
        }
        $file_paths = [];
        $file_paths[] = $this->factionlist;

        $callback = function (Member $member, array $item, array $required_roles): string
        {
            $string = '';
            foreach ($required_roles as $role)
                if ($member->roles->has($this->civ13->role_ids[$role]))
                    $string .= "{$item['ss13']};{$role}" . PHP_EOL;
            return $string;
        };
        $this->civ13->updateFilesFromMemberRoles($callback, $file_paths, $required_roles);
        return true;
    }
    /**
     * Updates admin lists with required roles and permissions.
     *
     * @param array $required_roles An array of required roles and their corresponding permissions.
     * @return bool Returns true if the update was successful, false otherwise.
     */
    public function adminlistUpdate(
        $required_roles = [
            'Owner' => ['Host', '65535'],
            'Chief Technical Officer' => ['Chief Technical Officer', '65535'],
            'Host' => ['Host', '65535'], // Default Host permission, only used if another role is not found first
            'Head Admin' => ['Head Admin', '16382'],
            'Manager' => ['Manager', '16382'],
            'Supervisor' => ['Supervisor', '16382'],
            'High Staff' => ['High Staff', '16382'], // Default High Staff permission, only used if another role is not found first
            'Admin' => ['Admin', '16254'],
            'Moderator' => ['Moderator', '25088'],
            //'Developer' => ['Developer', '7288'], // This Discord role doesn't exist
            'Mentor' => ['Mentor', '16384'],
        ]
    ): bool
    {
        if (! $this->enabled) return false;
        if (! $this->civ13->hasRequiredConfigRoles(array_keys($required_roles))) return false;
        if (! @touch($this->admins)) {
            $this->logger->warning("unable to open `{$this->admins}`");
            return false;
        }
        $file_paths[] = $this->admins;

        $callback = function (Member $member, array $item, array $required_roles): string
        {
            $string = '';
            $checked_ids = [];
            foreach (array_keys($required_roles) as $role) if ($member->roles->has($this->civ13->role_ids[$role])) if (! in_array($member->id, $checked_ids)) {
                $string .= "{$item['ss13']};{$required_roles[$role][0]};{$required_roles[$role][1]}|||" . PHP_EOL;
                $checked_ids[] = $member->id;
            }
            return $string;
        };
        $this->civ13->updateFilesFromMemberRoles($callback, $file_paths, $required_roles);
        return true;
    }

    /**
     * Generates a server status embed.
     *
     * @return Embed The generated server status embed.
     */
    public function generateServerstatusEmbed(): ?Embed
    {
        if ($this->ip !== $this->civ13->httpServiceManager->httpHandler->external_ip) return $this->toEmbed(); // Don't try and access files if the server is not local
        if (! @touch($this->basedir . Civ13::serverdata) || ! $data = @file_get_contents($this->basedir . Civ13::serverdata)) {
            $this->logger->warning("Unable to open `{$this->basedir}" . Civ13::serverdata . "`");
            return null;
        }
        $embed = new Embed($this->discord);
        $embed->setFooter($this->civ13->embed_footer);
        $embed->setColor(0xe1452d);
        $embed->setTimestamp();
        $embed->setURL('');
        if (! is_resource($socket = @fsockopen('localhost', intval($this->port), $errno, $errstr, 1))) {
            $embed->addFieldValues($this->name, 'Offline');
            return $embed;
        }
        fclose($socket);
        $data = explode(';', str_replace(['<b>Address</b>: ', '<b>Map</b>: ', '<b>Gamemode</b>: ', '<b>Players</b>: ', 'round_timer=', 'map=', 'epoch=', 'season=', 'ckey_list=', '</b>', '<b>'], '', $data));
        /*
        0 => <b>Server Status</b> {Online/Offline}
        1 => <b>Address</b> byond://{ip_address}
        2 => <b>Map</b>: {map}
        3 => <b>Gamemode</b>: {gamemode}
        4 => <b>Players</b>: {playercount}
        5 => realtime={realtime}
        6 => world.address={ip}
        7 => round_timer={00:00}
        8 => map={map}
        9 => epoch={epoch}
        10 => season={season}
        11 => ckey_list={ckey&ckey}
        */
        if (isset($data[1])) $embed->addFieldValues($this->name, '<'.$data[1].'>');
        $embed->addFieldValues('Host', $this->host, true);
        if (isset($data[7])) {
            list($hours, $minutes) = explode(':', $data[7]);
            $hours = intval($hours);
            $minutes = intval($minutes);
            $days = floor($hours / 24);
            $hours = $hours % 24;
            $time = ($days ? $days . 'd' : '') . ($hours ? $hours . 'h' : '') . $minutes . 'm';
            $embed->addFieldValues('Round Time', $time, true);
        }
        if (isset($data[8])) $embed->addFieldValues('Map', $data[8], true); // Appears twice in the data
        //if (isset($data[3])) $embed->addFieldValues('Gamemode', $data[3], true);
        if (isset($data[9])) $embed->addFieldValues('Epoch', $data[9], true);
        if (isset($data[11])) { // Player list
            $players = explode('&', $data[11]);
            $players = array_map(fn($player) => $this->civ13->sanitizeInput($player), $players);
            if (! $players_list = implode(", ", $players)) $players_list = 'N/A';
            $embed->addFieldValues('Players', $players_list, true);
        }
        if (isset($data[10])) $embed->addFieldValues('Season', $data[10], true);
        //if (isset($data[5])) $embed->addFieldValues('Realtime', $data[5], true);
        //if (isset($data[6])) $embed->addFieldValues('IP', $data[6], true);
        return $embed;
    }
    public function toEmbed(): Embed
    {
        $embed = new Embed($this->discord);
        $embed->title = $this->name;
        $embed->addFieldValues("Server URL", "byond://{$this->ip}:{$this->port}", false);
        $embed->addFieldValues('Host', $this->host, true);
        $embed->addFieldValues('Players (' . count($this->players) . ')', empty($this->players) ? 'N/A' : implode(', ', $this->players), true);
        $embed->color = hexdec('FF0000');
        return $embed;
    }
    // Magic Methods
    public function __toString(): string
    {
        return $this->key;
    }
    public function __toArray(): array
    {
        $array = get_object_vars($this);
        unset($array['civ13']);
        return $array;
    }
    public function __serialize(): array
    {
        return $this->__toArray();
    }
    public function __debugInfo(): array
    {
        return $this->__toArray();
    }
    public function __destruct()
    {
        foreach ($this->timers as $timer) $this->loop->cancelTimer($timer);
    }
}