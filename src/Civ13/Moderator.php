<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2024-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ13;

use Discord\Discord;
use Monolog\Logger;

class Moderator
{
    public Civ13 $civ13;
    public Discord $discord;
    public Logger $logger;
    public array $timers = [];
    public string $status = 'status.txt';

    public function __construct(Civ13 $civ13)
    {
        $this->civ13 =& $civ13;
        $this->discord =& $civ13->discord;
        $this->logger =& $civ13->logger;
        $this->afterConstruct();
    }
    private function afterConstruct(): void
    {
        $this->discord->once('ready', function () {
            //
        });
    }

    /**
     * Scrutinizes the given ckey and applies ban rules if necessary.
     *
     * @param string $ckey The ckey to be scrutinized.
     * @return void
     */
    public function scrutinizeCkey(string $ckey): void
    { // Suspicious user ban rules
        if (! isset($this->civ13->permitted[$ckey]) && ! in_array($ckey, $this->civ13->seen_players)) {
            $this->civ13->seen_players[] = $ckey;
            $ckeyinfo = $this->civ13->ckeyinfo($ckey);
            $ban = ['ckey' => $ckey, 'duration' => '999 years', 'reason' => "Account under investigation. Appeal at {$this->civ13->discord_formatted}"];
            if ($ckeyinfo['altbanned']) { // Banned with a different ckey
                if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot'])) $this->civ13->sendMessage($channel, $this->civ13->ban($ban, null, null, true) . ' (Alt Banned)');
            } else foreach ($ckeyinfo['ips'] as $ip) {
                if (in_array($this->civ13->IP2Country($ip), $this->civ13->blacklisted_countries)) { // Country code
                    if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot'])) $this->civ13->sendMessage($channel, $this->civ13->ban($ban, null, null, true) . ' (Blacklisted Country)');
                    break;
                } else foreach ($this->civ13->blacklisted_regions as $region) if (str_starts_with($ip, $region)) { // IP Segments
                    if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot'])) $this->civ13->sendMessage($channel, $this->civ13->ban($ban, null, null, true) . ' (Blacklisted Region)');
                    break 2;
                }
            }
        }
        if ($this->civ13->verifier->verified->get('ss13', $ckey)) return; // Verified users are exempt from further checks
        if ($this->civ13->panic_bunker || (isset($this->civ13->serverinfo[1]['admins']) && $this->civ13->serverinfo[1]['admins'] == 0 && isset($this->civ13->serverinfo[1]['vote']) && $this->civ13->serverinfo[1]['vote'] == 0)) {
            $this->civ13->__panicBan($ckey); // Require verification for Persistence rounds
            return;
        }
        if (! isset($this->civ13->permitted[$ckey]) && ! isset($this->civ13->ages[$ckey]) && ! $this->civ13->checkByondAge($age = $this->civ13->getByondAge($ckey))) { // Force new accounts to register in Discord
            $ban = ['ckey' => $ckey, 'duration' => '999 years', 'reason' => "Byond account `$ckey` must register on Discord and be manually approved to play. ($age)"];
            if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot'])) $this->civ13->sendMessage($channel, $this->civ13->ban($ban, null, null, true));
        }
    }

    /**
     * Moderates game chat by checking for blacklisted words/phrases and taking appropriate actions.
     *
     * @param string $ckey The ckey of the player sending the chat message.
     * @param string $string The chat message string to be moderated.
     * @param array $badwords_array An array of blacklisted words/phrases and their moderation methods.
     * @param array &$badword_warnings An array to store any warnings generated by the moderation process.
     * @param string $server The server name where the chat message is being sent. Default is 'nomads'.
     * @return string The original chat message string.
     */
    public function moderate(string $ckey, string $string, array $badwords_array, array &$badword_warnings, string|int $gameserver_key): string
    {
        $lower = strtolower($string);
        foreach ($badwords_array as $badwords) switch ($badwords['method']) {
            case 'exact': // ban ckey if $string contains a blacklisted phrase exactly as it is defined
                if (preg_match('/\b' . $badwords['word'] . '\b/i', $lower)) {
                    $this->__relayViolation($gameserver_key, $ckey, $badwords, $badword_warnings);
                    break 2;
                }
                continue 2;
            case 'cyrillic': // ban ckey if $string contains a cyrillic character
                if (preg_match('/\p{Cyrillic}/ui', $lower)) {
                    $this->__relayViolation($gameserver_key, $ckey, $badwords, $badword_warnings);
                    break 2;
                }
                continue 2;
            case 'str_starts_with':
                if (str_starts_with($lower, $badwords['word'])) {
                    $this->__relayViolation($gameserver_key, $ckey, $badwords, $badword_warnings);
                    break 2;
                }
                continue 2;
            case 'str_ends_with':
                if (str_ends_with($lower, $badwords['word'])) {
                    $this->__relayViolation($gameserver_key, $ckey, $badwords, $badword_warnings);
                    break 2;
                }
                continue 2;
            case 'str_contains': // ban ckey if $string contains a blacklisted word
            default: // default to 'contains'
                if (str_contains($lower, $badwords['word'])) {
                    $this->__relayViolation($gameserver_key, $ckey, $badwords, $badword_warnings);
                    break 2;
                }
                continue 2;
        }
        return $string;
    }
    /**
     * This function is called from the game's chat hook if a player says something that contains a blacklisted word.
     *
     * @param string $server The server name.
     * @param string $ckey The player's unique identifier.
     * @param array $badwords_array An array containing information about the blacklisted word.
     * @param array &$badword_warnings A reference to an array that stores the number of warnings for each player.
     * @return string|bool Returns a string if the player is banned, or false if the player is not banned.
     */
    // This function is called from the game's chat hook if a player says something that contains a blacklisted word
    private function __relayViolation(string|int $gameserver_key, string $ckey, array $badwords_array, array &$badword_warnings): string|bool // TODO: return type needs to be decided
    {
        if ($this->civ13->sanitizeInput($ckey) === $this->civ13->sanitizeInput($this->discord->user->displayname)) return false; // Don't ban the bot
        $filtered = substr($badwords_array['word'], 0, 1) . str_repeat('%', strlen($badwords_array['word'])-2) . substr($badwords_array['word'], -1, 1);
        if (! $this->__relayWarningCounter($ckey, $badwords_array, $badword_warnings)) return $this->civ13->ban(['ckey' => $ckey, 'duration' => $badwords_array['duration'], 'reason' => "Blacklisted phrase ($filtered). Review the rules at {$this->civ13->rules}. Appeal at {$this->civ13->discord_formatted}"]);
        $warning = "You are currently violating a server rule. Further violations will result in an automatic ban that will need to be appealed on our Discord. Review the rules at {$this->civ13->rules}. Reason: {$badwords_array['reason']} ({$badwords_array['category']} => $filtered)";
        if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot'])) $this->civ13->sendMessage($channel, "`$ckey` is" . substr($warning, 7));
        if (isset($this->civ13->enabled_servers[$gameserver_key])) return $this->civ13->enabled_servers[$gameserver_key]->DirectMessage($warning, $this->discord->user->displayname, $ckey);
        return false;
    }
    /*
     * This function determines if a player has been warned too many times for a specific category of bad words
     * If they have, it will return false to indicate they should be banned
     * If they have not, it will return true to indicate they should be warned
     */
    private function __relayWarningCounter(string $ckey, array $badwords_array, array &$badword_warnings): bool
    {
        if (! isset($badword_warnings[$ckey][$badwords_array['category']])) $badword_warnings[$ckey][$badwords_array['category']] = 1;
        else ++$badword_warnings[$ckey][$badwords_array['category']];

        $filename = '';
        if ($badword_warnings === $this->civ13->ic_badwords_warnings) $filename = 'ic_badwords_warnings.json';
        elseif ($badword_warnings === $this->civ13->ooc_badwords_warnings) $filename = 'ooc_badwords_warnings.json';
        if ($filename !== '') $this->civ13->VarSave($filename, $badword_warnings);

        if ($badword_warnings[$ckey][$badwords_array['category']] > $badwords_array['warnings']) return false;
        return true;
    }
}