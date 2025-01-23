<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 1987-present Movies Free Software Foundation <emoney@moviesfreepremium.xyz>
 */

namespace Civ13;

use \Exception;
use Civ13\Civ13;
use Clue\React\Redis\Factory as Redis;
use Discord\Discord;
use Discord\Stats;
use Discord\Builders\MessageBuilder;
use Discord\Helpers\CacheConfig;
use Discord\WebSockets\Intents;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use React\EventLoop\Loop;
use React\Filesystem\Factory as FilesystemFactory;
use React\Http\Browser;
use WyriHaximus\React\Cache\Redis as RedisCache;

use function React\Promise\set_rejection_handler;

define('CIVILIZATIONBOT_START', microtime(true));
ini_set('display_errors', 1);
error_reporting(E_ALL);

set_time_limit(0);
ignore_user_abort(1);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '-1'); // Unlimited memory usage
define('MAIN_INCLUDED', 1); // Token and SQL credential files may be protected locally and require this to be defined to access

//if (! $token_included = require getcwd() . '/token.php') // $token
    //throw new \Exception('Token file not found. Create a file named token.php in the root directory with the bot token.');
if (! $autoloader = require file_exists(__DIR__.'/vendor/autoload.php') ? __DIR__.'/vendor/autoload.php' : __DIR__.'/../../autoload.php')
    throw new \Exception('Composer autoloader not found. Run `composer install` and try again.');
function loadEnv(string $filePath = __DIR__ . '/.env'): void
{
    if (! file_exists($filePath)) throw new Exception("The .env file does not exist.");

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $trimmedLines = array_map('trim', $lines);
    $filteredLines = array_filter($trimmedLines, fn($line) => $line && ! str_starts_with($line, '#'));

    array_walk($filteredLines, function($line) {
        [$name, $value] = array_map('trim', explode('=', $line, 2));
        if (! array_key_exists($name, $_ENV)) putenv(sprintf('%s=%s', $name, $value));
    });
}
loadEnv(getcwd() . '/.env');

$streamHandler = new StreamHandler('php://stdout', Level::Info);
$streamHandler->setFormatter(new LineFormatter(null, null, true, true, true));
$logger = new Logger('Civ13', [$streamHandler]);
file_put_contents('output.log', ''); // Clear the contents of 'output.log'
$logger->pushHandler(new StreamHandler('output.log', Level::Info));
$logger->info('Loading configurations for the bot...');

$discord = new Discord([
    'loop' => $loop = Loop::get(),
    'logger' => $logger,
    /*
    'cache' => new CacheConfig(
        $interface = new RedisCache(
            (new Redis($loop))->createLazyClient('127.0.0.1:6379'),
            'dphp:cache:
        '),
        $compress = true, // Enable compression if desired
        $sweep = false // Disable automatic cache sweeping if desired
    ),
    */
    'socket_options' => [
        'dns' => '8.8.8.8', // can change dns
    ],
    'token' => getenv('TOKEN'),
    'loadAllMembers' => true,
    'storeMessages' => true, // Because why not?
    'intents' => Intents::getDefaultIntents() | Intents::GUILD_MEMBERS | Intents::MESSAGE_CONTENT,
]);

$stats = Stats::new($discord);
$browser = new Browser($loop);
$filesystem = FilesystemFactory::create($loop);
include 'variable_functions.php';

// TODO: Add a timer and a callable function to update these IP addresses every 12 hours
$civ13_ip = gethostbyname('www.moviesfreepremium.xyz') ?: '64.20.37.50';
$http_whitelist = [$civ13_ip];

$webapi = null;
$socket = null;

/* Format:
    'word' => 'bad word' // Bad word to look for
    'duration' => duration ['1 minute', '1 hour', '1 day', '1 week', '1 month', '999 years'] // Duration of the ban
    'reason' => 'reason' // Reason for the ban
    'category' => rule category ['racism/discrimination', 'toxic', 'advertisement'] // Used to group bad words together by category
    'method' => detection method ['exact', 'str_contains', 'str_ends_with', 'str_starts_with'] // Exact ignores partial matches, str_contains matches partial matches, etc.
    'warnings' => 1 // Number of warnings before a ban
*/
$ic_badwords = $ooc_badwords = [
    //['word' => 'badwordtestmessage', 'duration' => '1 minute', 'reason' => 'Violated server rule.', 'category' => 'test', 'method' => 'str_contains', 'warnings' => 1], // Used to test the system

    ['word' => 'penis',       'duration' => '2 hours',  'reason' => 'There is a zero tolerance policy towards any type of lewdness.', 'category' => 'erp', 'method' => 'str_contains', 'warnings' => 1], // This is more severe than the others, so ban after only one warning
    ['word' => 'vagina',      'duration' => '2 hours',  'reason' => 'There is a zero tolerance policy towards any type of lewdness.', 'category' => 'erp', 'method' => 'str_contains', 'warnings' => 1], // This is more severe than the others, so ban after only one warning
    ['word' => 'sex',         'duration' => '2 hours',  'reason' => 'There is a zero tolerance policy towards any type of lewdness.', 'category' => 'erp', 'method' => 'exact', 'warnings' => 1], // This is more severe than the others, so ban after only one warning
    ['word' => 'cum',         'duration' => '2 hours',  'reason' => 'There is a zero tolerance policy towards any type of lewdness.', 'category' => 'erp', 'method' => 'exact', 'warnings' => 1], // This is more severe than the others, so ban after only one warning

    ['word' => 'discord.gg',  'duration' => '1 week', 'reason' => 'You must not post unauthorized Discord invitation links in any OOC communication channels.', 'category' => 'advertisement', 'method' => 'str_contains', 'warnings' => 2],
    ['word' => 'discord.com', 'duration' => '1 week', 'reason' => 'You must not post unauthorized Discord invitation links in any OOC communication channels.', 'category' => 'advertisement', 'method' => 'str_contains', 'warnings' => 2],

    ['word' => 'RU',          'duration' => '1 hour', 'reason' => 'только английский.', 'category' => 'language', 'method' => 'russian',  'warnings' => 2],
    ['word' => 'CN',          'duration' => '1 hour', 'reason' => '仅英语.',             'category' => 'language', 'method' => 'chinese',  'warnings' => 2],
    ['word' => 'KR',          'duration' => '1 hour', 'reason' => '영어로만 제공.',      'category' => 'language', 'method' => 'korean',   'warnings' => 2],
    //['word' => 'ES',          'duration' => '1 hour', 'reason' => 'Solo inglés.',       'category' => 'language', 'method' => 'spanish',  'warnings' => 2],
];
$options = array(
    'github' => 'https://github.com/New-Civ13/Civilizationbot',
    'command_symbol' => '@EternalBot',
    'owner_id' => '468940707273637895', // Emoney
    'technician_id' => '165752220602597379', // Jamie
    'civ13_guild_id' => '1328179627297865799', // Eternal Civlization 13
    'discord_invite' => 'https://discord.gg/TYrtJZDwSt',
    'discord_formatted' => 'discord.gg slash TYrtJZDwSt',
    'rules' => 'discord.gg slash TYrtJZDwSt',
    'gitdir' => '/home/emoney/civ13/civ13-git', // Path to the git repository
    'legacy' => true, // Whether to use the filesystem or SQL database system
    'moderate' => true, // Whether to moderate in-game chat
    // The Verify URL is where verification requests are sent to and where the verification list is retrieved from
    // The website must return valid json when no parameters are passed to it and MUST allow POST requests including 'token', 'ckey', and 'discord'
    // Reach out to Your Momma if you need help setting up your website
    'webserver_url' => 'localhost',
    'verify_url' => 'http://localhost:8010/verified', // Leave this blank if you do not want to use the webserver, ckeys will be stored locally as provisional
    // 'serverinfo_url' => '', // URL of the serverinfo.json file, defaults to the webserver if left blank
    'ooc_badwords' => $ooc_badwords,
    'ic_badwords' => $ic_badwords,
    'folders' => array(
        // 'typespess_path' => '/home/emoney/civ13/civ13-typespess',
    ),
    'files' => array( // Server-specific file paths MUST start with the server name as defined in server_settings unless otherwise specified
        // 'typespess_launch_server_path' => '/home/emoney/civ13/civ13-typespess/scripts/launch_server.sh',
    ),
    'channel_ids' => array(
        'get-approved' => '1328179627885072438', #get-approved
        'webserver-status' => '1328179627885072444', #webserver-{status}
        'verifier-status' => '1328179627885072445', #verifier-{status}
        'staff_bot' => '1328179629021728859', // #staff-bot
        'parole_logs' => '1328179628480663657', // #parole-logs (for tracking)
        'parole_notif' => '1328179628480663652', // #parole-notif (for login/logout notifications)
        'email' => '1328234890726080586', // #email
        'ban_appeals' => '1328183400418246686' #ban-appeals
    ),
    'role_ids' => array( // The keys in this array must directly correspond to the expected role names and as defined in Gameserver.php. Do not alter these keys unless you know what you are doing.
        /* Discord Staff Roles */
        'Owner' => '1328179627381887085', // Discord Server Owner
        'Chief Technical Officer' => '1328179627381887083', // Debug Host / Database admin
        'Host' => '1328179627381887081', // Server Host
        'Head Admin' => '1328179627381887079', // Deprecation TBD
        //'Manager' => '', // Deprecated
        'Ambassador' => '1328179627381887080', // High Staff
        //'Supervisor' => '', // Deprecated
        'Admin' => '1328179627369168973',
        //'Moderator' => '', // Deprecated
        //'Mentor' => '', // Deprecated
        'Parolemin' => '1328179627361046557', // Parole Admin
        /* Discord Player Roles */
        'Verified' => '1328179627348332645', // Verified
        'Banished' => '1328179627361046555', // Banned in-game
        'Permabanished' => '1328179627361046556', // Permanently banned in-game
        'Paroled' => '1328179627369168969', // On parole
        
        /* Factions */
        'Red Faction' => '1328179627339939921', // Redmenia
        'Blue Faction' => '1328179627339939920', // Blugoslavia
        'Faction Organizer' => '1328179627339939928', // Admin / Faction Organizer
        /* Notification pings */
        'mapswap' => '1328179627314905134', // Map Swap Ping
        'round_start' => '1328179627314905135', // Round Start Ping
        '2+' => '1328179627314905138', // LowPopStart
        '15+' => '1328179627327230082', // 15+ Popping
        '30+' => '1328179627327230090', // 30+ Popping
    ),
);
$options['welcome_message'] = "Welcome to the Eternal Civilization 13 Discord Server! Please read the rules and verify your account using the `/approveme` slash command. Failure to verify in a timely manner will result in an automatic removal from the server.";
/*
foreach (['а', 'б', 'в', 'г', 'д', 'е', 'ё', 'ж', 'з', 'и', 'й', 'к', 'л', 'м', 'н', 'о', 'п', 'р', 'с', 'т', 'у', 'ф', 'х', 'ц', 'ч', 'ш', 'щ', 'ъ', 'ы', 'ь', 'э', 'ю', 'я', 'і', 'ї', 'є'] as $char) { // // Ban use of Cyrillic characters
    $arr = ['word' => $char, 'duration' => '999 years', 'reason' => 'только английский.', 'category' => 'language', 'method' => 'str_contains', 'warnings' => 2];
    $options['ooc_badwords'][] = $arr;
    $options['ic_badwords'][] = $arr;
}
*/

// Write editable configurations to a single JSON file

$json = json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
file_put_contents("config.json", $json);


// Load configurations from the JSON file
/*
$loadedData = [];
$json = file_get_contents("config.json");
$loadedData = json_decode($json, true);
foreach ($loadedData as $key => $value) $options[$key] = $value;
*/

//TODO: Move this to a separate file, like .env
$server_settings = [ // Server specific settings, listed in the order in which they appear on the VZG server list.
    'eternal' => [
        'supported' => true,
        'enabled' => true,
        'name' => 'Eternal',
        //'key' => 'eternal',
        'ip' => $civ13_ip,
        'port' => '1714',
        'host' => 'Emoney',
        'panic_bunker' => false,
        'log_attacks' => true,
        'legacy' => true,
        'moderate' => true,
        'legacy_relay' => false,
        'basedir' => '/home/emoney/civ13/civ13',
        // Primary channels
        'playercount' => '1328179627885072446',
        'discussion' => '1328179628480663654',
        // Chat relay channels
        'ooc' => '1328179628480663660',
        'lobby' => '1328179628480663661',
        'asay' => '1328179628623265792',
        'ic' => '1328179628623265793',
        // Log channels
        'transit' => '1328179629491621895',
        'adminlog' => '1328179629491621896',
        'debug' => '1328179629491621897',
        'garbage' => '1328179629671845949',
        'runtime' => '1328179629671845950',
        'attack' => '1328179629671845951',
    ]
];
foreach ($server_settings as $key => $value) $server_settings[$key]['key'] = $key; // Key is intended to be a shortname for the full server, so defining both a full name and short key are required. Individual server settings will also get passed around and lose their primary key, so we need to reassign it.

$hidden_options = [
    'loop' => $loop,
    'discord' => $discord,
    'browser' => $browser,
    'filesystem' => $filesystem,
    'logger' => $logger,
    'stats' => $stats,

    'webapi' => &$webapi,
    'socket' => &$socket,
    'web_address' => getenv('web_address') ?: 'www.civ13.com',
    'http_port' => intval(getenv('http_port')) ?: 55555, // 25565 for testing on Windows
    'http_key' => getenv('WEBAPI_TOKEN') ?: 'CHANGEME',
    'http_whitelist' => $http_whitelist,
    'civ_token' => getenv('CIV_TOKEN') ?: 'CHANGEME',
    'server_settings' => $server_settings, // Server specific settings, listed in the order in which they appear on the VZG server list.
    'functions' => array(
        'init' => [
            // 'on_ready' => $on_ready,
            'status_changer_timer' => $status_changer_timer,
            'status_changer_random' => $status_changer_random,
        ],
        'misc' => [ // Custom functions
            //
        ],
    ),
];
$options = array_merge($options, $hidden_options);

$civ13 = null;
$global_error_handler = function (int $errno, string $errstr, ?string $errfile, ?int $errline) use (&$civ13, &$logger, &$testing) {
    /** @var ?Civ13 $civ13 */
    if (
        $civ13 && // If the bot is running
        ($channel = $civ13->discord->getChannel($civ13->channel_ids['staff_bot']))
        // fsockopen
        && ! str_ends_with($errstr, 'Connection timed out') 
        && ! str_ends_with($errstr, '(Connection timed out)')
        && ! str_ends_with($errstr, 'Connection refused') // Usually happens if the verifier server doesn't respond quickly enough
        && ! str_contains($errstr, '(Connection refused)') // Usually happens in localServerPlayerCount
        //&& ! str_ends_with($errstr, 'Network is unreachable')
        //&& ! str_ends_with($errstr, '(Network is unreachable)')

        // Connectivity issues
        && ! str_ends_with($errstr, 'No route to host') // Usually happens if the verifier server is down
        && ! str_ends_with($errstr, 'No address associated with hostname') // Either the DNS or the VPS is acting up
        && ! str_ends_with($errstr, 'Temporary failure in name resolution') // Either the DNS or the VPS is acting up
        && ! str_ends_with($errstr, 'Bad Gateway') // Usually happens if the verifier server's PHP-CGI is down
        //&& ! str_ends_with($errstr, 'HTTP request failed!')

        //&& ! str_contains($errstr, 'Undefined array key')
    )
    {
        $msg = sprintf("[%d] Fatal error on `%s:%d`: %s\nBacktrace:\n```\n%s\n```", $errno, $errfile, $errline, $errstr, implode("\n", array_map(fn($trace) => "{$trace['file']}:{$trace['line']} {$trace['function']}", debug_backtrace())));
        $logger->error($msg);
        if (isset($civ13->technician_id) && $tech_id = $civ13->technician_id) $msg = "<@{$tech_id}>, $msg";
        if (! $testing) $civ13->sendMessage($channel, $msg);
    }
};
set_error_handler($global_error_handler);

use React\Socket\SocketServer;
use React\Http\HttpServer;
use React\Http\Message\Response;
use Psr\Http\Message\ServerRequestInterface;
$socket = new SocketServer(sprintf('%s:%s', '0.0.0.0', getenv('http_port') ?: 55555), [], $loop);
/**
 * Handles the HTTP request using the HttpServiceManager.
 *
 * @param ServerRequestInterface $request The HTTP request object.
 * @return Response The HTTP response object.
 */
$webapi = new HttpServer($loop, function (ServerRequestInterface $request) use (&$civ13): Response
{
    /** @var ?Civ13 $civ13 */
    if (! $civ13 || ! $civ13 instanceof Civ13 || ! $civ13->httpServiceManager instanceof HttpServiceManager) return new Response(Response::STATUS_SERVICE_UNAVAILABLE, ['Content-Type' => 'text/plain'], 'Service Unavailable');
    if (! $civ13->ready) return new Response(Response::STATUS_SERVICE_UNAVAILABLE, ['Content-Type' => 'text/plain'], 'Service Not Yet Ready');
    return $civ13->httpServiceManager->handle($request);
});
/**
 * This code snippet handles the error event of the web API.
 * It logs the error message, file, line, and trace, and handles specific error cases.
 * If the error message starts with 'Received request with invalid protocol version', it is ignored.
 * If the error message starts with 'The response callback', it triggers a restart process.
 * The restart process includes sending a message to a specific Discord channel and closing the socket connection.
 * After a delay of 5 seconds, the script is restarted by calling the 'restart' function and closing the Discord connection.
 *
 * @param Exception $e The exception object representing the error.
 * @param \Psr\Http\Message\RequestInterface|null $request The HTTP request object associated with the error, if available.
 * @param object $civ13 The main object of the application.
 * @param object $socket The socket object.
 * @param bool $testing Flag indicating if the script is running in testing mode.
 * @return void
 */
$webapi->on('error', function (Exception $e, ?\Psr\Http\Message\RequestInterface $request = null) use (&$civ13, &$logger, &$socket) {
    if (
        str_starts_with($e->getMessage(), 'Received request with invalid protocol version')
    ) return; // Ignore this error, it's not important
    $error = "[WEBAPI] {$e->getMessage()} [{$e->getFile()}:{$e->getLine()}] " . str_replace('\n', PHP_EOL, $e->getTraceAsString());
    $logger->error("[WEBAPI] $error");
    if ($request) $logger->error('[WEBAPI] Request: ' .  preg_replace('/(?<=key=)[^&]+/', '********', $request->getRequestTarget()));
    if (str_starts_with($e->getMessage(), 'The response callback')) {
        $logger->info('[WEBAPI] ERROR - RESTART');
        /** @var ?Civ13 $civ13 */
        if (! $civ13) return;
        if (! getenv('testing') && isset($civ13->channel_ids['staff_bot']) && $channel = $civ13->discord->getChannel($civ13->channel_ids['staff_bot'])) {
            $builder = MessageBuilder::new()
                ->setContent('Restarting due to error in HttpServer API...')
                ->addFileFromContent('httpserver_error.txt', preg_replace('/(?<=key=)[^&]+/', '********', $error));
            $channel->sendMessage($builder);
        }
        $socket->close();
        if (! isset($civ13->timers['restart'])) $civ13->timers['restart'] = $civ13->discord->getLoop()->addTimer(5, static fn() => $civ13->restart());
    }
});

set_rejection_handler(static fn(\Throwable $reason) => $logger->error('Promise rejected:' . PHP_EOL . $reason));
$civ13 = new Civ13($options, $server_settings);
$civ13->run();
