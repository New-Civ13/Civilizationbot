<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2022-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ13;

use Byond\Byond;
use Discord\Discord;
use Discord\DiscordWebAuth;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Channel\Message;
use Discord\Parts\User\Member;
use Monolog\Logger;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\TimerInterface;
use React\Http\HttpServer;
use React\Http\Message\Response as HttpResponse;
use React\Promise\PromiseInterface;
use React\Socket\SocketServer;

class HttpServiceManager
{
    const HTMLDIR = '/html';
    readonly string $basedir;

    public Discord $discord;
    public Logger $logger;
    public HttpHandler $httpHandler;
    public HttpServer $webapi;
    public SocketServer $socket;
    public string $web_address;
    public int $http_port;

    protected array $dwa_sessions = [];
    protected array $dwa_timers = [];
    protected array $dwa_discord_ids = [];

    public function __construct(public Civ13 &$civ13) {
        $this->discord =& $civ13->discord;
        $this->logger =& $civ13->logger;
        $this->httpHandler = new HttpHandler($this->civ13, [], $this->civ13->options['http_whitelist'] ?? [], $this->civ13->options['http_key'] ?? '');
        $this->basedir = getcwd();
        $this->__afterConstruct();
    }

    public function __destruct() {
        if (isset($this->socket)) $this->socket->close();
    }

    /*
    * This function is called after the constructor is finished.
    * It is used to load the files, start the timers, and start handling events.
    */
    protected function __afterConstruct()
    {
        if (! isset($this->civ13->options['webapi'], $this->civ13->options['socket'], $this->civ13->options['web_address'], $this->civ13->options['http_port'])) {
            $this->logger->warning('HttpServer API not set up! Missing variables in options.');
            $this->logger->warning('Missing webapi variable: ' . (isset($this->civ13->options['webapi']) ? 'false' : 'true'));
            $this->logger->warning('Missing socket variable: ' . (isset($this->civ13->options['socket']) ? 'false' : 'true'));
            $this->logger->warning('Missing web_address variable: ' . (isset($this->civ13->options['web_address']) ? 'false' : 'true'));
            $this->logger->warning('Missing http_port variable: ' . (isset($this->civ13->options['http_port']) ? 'false' : 'true'));
            return;
        }
        $this->webapi = $this->civ13->options['webapi'];
        $this->socket = $this->civ13->options['socket'];
        $this->web_address = $this->civ13->options['web_address'];
        $this->http_port = $this->civ13->options['http_port'];

        $this->__generateEndpoints();
        $fn = function () {
            //$this->logger->info('Populating HttpServer API whitelist...');
            //$this->__populateWhitelist(); // This is disabled for now because it takes >20 seconds.
            $this->webapi->listen($this->socket);
            $this->logger->info("HttpServer API is now listening on port {$this->http_port}");
            $this->logger->debug('[HTTP COMMAND LIST] ' . PHP_EOL . $this->httpHandler->generateHelp());
        };
        $this->civ13->ready
            ? $fn()
            : $this->discord->once('init', fn() => $fn());
    }

    public function handle(ServerRequestInterface $request): HttpResponse
    {
        if ($this->civ13->ready) {
            $response = $this->httpHandler->handle($request);
            if ($response->getStatusCode() === HttpResponse::STATUS_INTERNAL_SERVER_ERROR) $this->logger->warning('Internal Server Error on ' .  $request->getUri()->getPath());
            return $response;
        }
        return new HttpResponse(HttpResponse::STATUS_SERVICE_UNAVAILABLE, ['Content-Type' => 'text/plain'], 'Service Unavailable');
    }

    public static function decodeParamData(ServerRequestInterface $request): array
    {
        return ($params = $request->getQueryParams()) && isset($params['data']) ? @json_decode(urldecode($params['data']), true) : [];
    }

    private function __populateWhitelist()
    {
        if ($this->httpHandler && $this->civ13->civ13_guild_id && $guild = $this->discord->guilds->get('id', $this->civ13->civ13_guild_id)) // Whitelist the IPs of all Ambassador            $members = $guild->members->filter(function ($member) {
            foreach ($guild->members->filter(function ($member) {
                return $member->roles->has($this->civ13->role_ids['Ambassador']);
            }) as $member)
                if ($item = $this->civ13->verifier->getVerifiedItem($member))
                    if (isset($item['ss13']) && $ckey = $item['ss13'])
                        if ($playerlogs = $this->civ13->getCkeyLogCollections($ckey)['playerlogs'])
                            foreach ($playerlogs as $log)
                                if (isset($log['ip']))
                                    $this->httpHandler->whitelist($log['ip']);
    }

    private function __generateEndpoints()
    {
        $this->httpHandler
            ->offsetSet('/get-channels',
                function (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse
                {
                    $doc = new \DOMDocument();
                    $html = $doc->createElement('html');
                    $body = $doc->createElement('body');

                    // Create input box
                    $input = $doc->createElement('input');
                    $input->setAttribute('type', 'text');
                    $input->setAttribute('placeholder', 'Enter message');
                    $input->setAttribute('style', 'margin-left: 10px;');
                    $input->setAttribute('id', 'message-input');
                    $body->appendChild($input);
                    
                    $h2 = $doc->createElement('h2', 'Guilds');
                    $body->appendChild($h2);
                    // CSS for .guild class
                    $guildStyle = $doc->createElement('style', '.guild { margin-bottom: 20px; }');
                    $html->appendChild($guildStyle);

                    /** @var \Discord\Parts\Guild\Guild $guild An instance of a Discord guild. */
                    foreach ($this->discord->guilds as $guild) {
                        $guildDiv = $doc->createElement('div');
                        $guildDiv->setAttribute('class', 'guild');
                        $guildName = $doc->createElement('h3');
                        $a = $doc->createElement('a', $guild->name);
                        $a->setAttribute('href', 'https://discord.com/channels/' . $guild->id);
                        $a->setAttribute('target', '_blank');
                        $guildName->appendChild($a);
                        $guildDiv->appendChild($guildName);

                        // CSS for .channel class
                        $channelStyle = $doc->createElement('style', '.channel { margin-left: 20px; }');
                        $guildDiv->appendChild($channelStyle);
                        
                        $channels = $guild->channels
                            ->filter(static fn($channel) => $channel->isTextBased())
                            ->sort(static fn($a, $b) => $a->position <=> $b->position);

                        foreach ($channels as $channel) {
                            $channelDiv = $doc->createElement('div');
                            $channelDiv->setAttribute('class', 'channel');

                            $channelName = $doc->createElement('div');
                            $channelSpan = $doc->createElement('span');
                            $a = $doc->createElement('a', $channel->name);
                            $a->setAttribute('href', 'https://discord.com/channels/' . $guild->id . '/' . $channel->id);
                            $a->setAttribute('target', '_blank');
                            $channelSpan->appendChild($a);
                            $channelName->appendChild($channelSpan);

                            // Create button and input box
                            $button = $doc->createElement('button', 'Send Message');
                            $button->setAttribute('onclick', "sendMessage('{$channel->id}')");
                            $button2 = $doc->createElement('button', 'Send Embed');
                            $button2->setAttribute('onclick', "sendEmbed('{$channel->id}')");
                            $channelName->appendChild($doc->createTextNode(' ')); // Add space here
                            $channelName->appendChild($button);
                            $channelName->appendChild($button2);

                            $channelDiv->appendChild($channelName);
                            $guildDiv->appendChild($channelDiv);
                        }

                        $body->appendChild($guildDiv);
                    }

                    // Create javascript function for /send-message
                    $script = $doc->createElement('script', '
                        function sendMessage(channelId) {
                            var input = document.querySelector(`#message-input`);
                            var message = input.value;
                            input.value = \'\';
                            fetch("/send-message?channel=" + encodeURIComponent(channelId) + "&message=" + encodeURIComponent(message))
                                .then(response => response.json())
                                .then(data => console.log(data))
                                .catch(error => console.error(error));
                        }
                    ');
                    $body->appendChild($script);
                    // Create javascript function for /send-embed
                    $script = $doc->createElement('script', '
                        function sendEmbed(channelId) {
                            var input = document.querySelector(`#message-input`);
                            var message = input.value;
                            input.value = \'\';
                            fetch("/send-embed?channel=" + encodeURIComponent(channelId) + "&message=" + encodeURIComponent(message))
                                .then(response => response.json())
                                .then(data => console.log(data))
                                .catch(error => console.error(error));
                        }
                    ');
                    $body->appendChild($script);
                    
                    $html->appendChild($body);
                    $doc->appendChild($html);
                    return HttpResponse::html($doc->saveHTML());
                }, true)
            ->offsetSet('/send-message',
                function (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse
                {
                    $params = $request->getQueryParams();

                    isset($params['channel']) ? $channelId = $params['channel'] : $channelId = null;
                    if (! $channelId || ! $channel = $this->discord->getChannel($channelId)) return HttpResponse::json(['error' => "Channel `$channelId` not found"]);
                    if (! $channel->isTextBased()) return HttpResponse::json(['error' => "Cannot send messages to channel `$channelId`"]);

                    isset($params['message']) ? $message = $params['message'] : $message = null;
                    if (! $message) return HttpResponse::json(['error' => "Message not found"]);

                    $channel->sendMessage($message);
                    return HttpResponse::json(['success' => true]);
                }, true)
            ->offsetSet('/send-embed',
                function (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse
                {
                    $params = $request->getQueryParams();

                    isset($params['channel']) ? $channelId = $params['channel'] : $channelId = null;
                    if (! $channel = $this->discord->getChannel($channelId)) return HttpResponse::json(['error' => "Channel `$channelId` not found"]);
                    if (! $channel->isTextBased()) return HttpResponse::json(['error' => "Cannot send messages to channel `$channelId`"]);

                    isset($params['message']) ? $content = $params['message'] : $content = '';
                    if (! $content) return HttpResponse::json(['error' => "Message not found"]);

                    $builder = MessageBuilder::new();
                    if (isset($this->dwa_discord_ids[$request->getServerParams()['REMOTE_ADDR']]) && $user = $this->discord->users->get('id', $this->dwa_discord_ids[$request->getServerParams()['REMOTE_ADDR']])) { // This will not work if the user didn't login with oauth2 during this runtime session (i.e. the bot was restarted)
                        $builder->addEmbed($this->civ13->createEmbed()
                            ->setAuthor("{$user->username} ({$user->id})", $user->avatar)
                            ->addField('Message', $content)
                            ->setFooter($this->civ13->embed_footer)
                        );
                    } else {
                        $builder->setContent($content);
                        $this->logger->info("Either the IP was not associated with a user or no user could be found.");
                        $this->logger->info("IP: {$request->getServerParams()['REMOTE_ADDR']}");
                        if (isset($this->dwa_discord_ids[$request->getServerParams()['REMOTE_ADDR']])) $this->logger->info("Discord ID: {$this->dwa_discord_ids[$request->getServerParams()['REMOTE_ADDR']]}");
                    }
                    
                    $channel->sendMessage($builder);
                    return HttpResponse::json(['success' => true]);
                }, true)
            // HttpHandler website endpoints
            ->offsetSets(['/', '/index.html', '/index.php'],
                function (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse
                {
                    if ($whitelisted && $method = $this->httpHandler->offsetGet('/botlog', 'handlers')) return $method($request, $endpoint, $whitelisted);
                    if ($method = $this->httpHandler->offsetGet('/home.html', 'handlers')) return $method($request, $endpoint, $whitelisted);
                    return new HttpResponse(HttpResponse::STATUS_FOUND, ['Location' => 'https://www.valzargaming.com/?login']);
                })
            ->offsetSet('/robots.txt',
                fn(ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse =>
                    HttpResponse::plaintext('User-agent: *' . PHP_EOL . 'Disallow: /'))
            ->offsetSet($endpoint = '/.well-known/security.txt',
                fn(ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse =>
                    HttpResponse::plaintext(//'Contact: mailto:valithor@valzargaming.com' . PHP_EOL . 
                    "Contact: {$this->civ13->github}" . PHP_EOL .
                    'Acknowledgments: http://valzargaming.com/partners' . PHP_EOL .
                    'Preferred-Languages: en' . PHP_EOL . 
                    "Canonical: http://{$this->httpHandler->external_ip}:{$this->http_port}/.well-known/security.txt" . PHP_EOL . 
                    'Policy: http://valzargaming.com/legal'))
                ->setRateLimit($endpoint, 1, 10) // 1 request per 10 seconds
            ->offsetSet('/ping',
                fn(ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse =>
                    HttpResponse::plaintext("Hello wörld!"))
            ->offsetSet('/favicon.ico',
                fn(ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse =>
                    ($favicon = @file_get_contents('favicon.ico')) ? new HttpResponse(HttpResponse::STATUS_OK, ['Content-Type' => 'image/x-icon', 'Cache-Control' => 'public, max-age=2592000'], $favicon) : new HttpResponse(HttpResponse::STATUS_NOT_FOUND, ['Content-Type' => 'text/plain'], "Unable to access `favicon.ico`"))
            // HttpHandler management endpoints
            ->offsetSet('/reset',
                function (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse
                {
                    OSFunctions::execInBackground('git reset --hard origin/main');
                    $message = 'Forcefully moving the HEAD back to origin/main...';
                    if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot'])) $this->civ13->sendMessage($channel, $message);
                    return HttpResponse::plaintext($message);
                }, true)
            ->offsetSet('/githubupdated',
                function (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse
                {
                    if (! $signature = $request->getHeaderLine('X-Hub-Signature')) {
                        $headers = $request->getHeaders();
                        $this->logger->warning("Unauthorized Request Headers on `$endpoint` endpoint: " . json_encode($headers));
                        //$this->logger->warning("Signature: $signature, Hash: $hash");
                        $tech_ping = '';
                        if (isset($this->civ13->technician_id)) $tech_ping = "<@{$this->civ13->technician_id}>, ";
                        if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot'])) $this->civ13->sendMessage($channel, $tech_ping . "Unauthorized Request Headers on `$endpoint` endpoint: " . json_encode($headers));
                        return new HttpResponse(HttpResponse::STATUS_UNAUTHORIZED);
                    }
                    if ($signature !== $hash = 'sha1=' . hash_hmac('sha1', $request->getBody(), getenv('github_secret'))) {
                        $this->logger->warning("Unauthorized Request Signature on `$endpoint` endpoint: `$signature` != `$hash`");
                        return new HttpResponse(HttpResponse::STATUS_UNAUTHORIZED);
                    }
                    if (! $channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot'])) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                    $promise = isset($this->civ13->restart_message) && $this->civ13->restart_message instanceof Message
                        ? $this->civ13->restart_message->edit(MessageBuilder::new()->setContent('Updating code from GitHub... (1/2)'))
                        : $this->civ13->sendMessage($channel, 'Updating code from GitHub... (1/2)');
                    $promise->then(fn(Message $message) => OSFunctions::execInBackground('git pull'));
                    $this->civ13->loop->addTimer(5, fn(): PromiseInterface => $promise
                        ->then(static fn(Message $message): PromiseInterface => $message->edit(MessageBuilder::new()->setContent('Forcefully moving the HEAD back to origin/main... (2/2)')))
                        ->then(fn(Message $message) => $this->civ13->restart_message = $message)
                        ->then(static fn() => OSFunctions::execInBackground('git reset --hard origin/main'))
                        /*if (isset($this->civ13->timers['restart_pending']) && $this->civ13->timers['restart_pending'] instanceof TimerInterface) $this->civ13->loop->cancelTimer($this->civ13->timers['restart_pending']);
                        $this->civ13->timers['restart_pending'] = $this->civ13->loop->addTimer(300, fn() => 
                            (isset($this->civ13->restart_message) && $this->civ13->restart_message instanceof Message)
                                ? $this->civ13->restart_message->edit(MessageBuilder::new()->setContent('Restarting... (3/3)'))->then(fn() => $this->civ13->restart())
                                : $this->civ13->sendMessage($channel, 'Restarting... (3/3)')->then(fn() => $this->civ13->restart())
                        );*/
                    );
                    return new HttpResponse(HttpResponse::STATUS_OK);
                })
            ->offsetSet('/cancelupdaterestart',
                function (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse
                {
                    if (isset($this->civ13->timers['restart_pending']) && $this->civ13->timers['restart_pending'] instanceof TimerInterface) {
                        $this->civ13->loop->cancelTimer($this->civ13->timers['restart_pending']);
                        unset($this->civ13->timers['restart_pending']);
                        if (isset($this->civ13->restart_message) && $this->civ13->restart_message instanceof Message) $this->civ13->restart_message->edit(MessageBuilder::new()->setContent('Restart cancelled.'));
                        return HttpResponse::plaintext('Restart cancelled.');
                    }
                    return HttpResponse::plaintext('No restart pending.');
                })
            ->offsetSet('/pull',
                function (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse
                {
                    OSFunctions::execInBackground('git pull');
                    $message = 'Updating code from GitHub...';
                    if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot'])) $this->civ13->sendMessage($channel, $message);
                    return HttpResponse::plaintext($message);
                }, true)
            ->offsetSet('/update',
                function (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse
                {
                    OSFunctions::execInBackground('composer update');
                    $message = 'Updating dependencies...';
                    if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot'])) $this->civ13->sendMessage($channel, $message);
                    return HttpResponse::plaintext($message);
                }, true)
            ->offsetSet('/restart',
                function (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse
                {
                    $message = 'Manually Restarting...';
                    if (isset($this->civ13->restart_message) && $this->civ13->restart_message instanceof Message) {
                        $this->civ13->restart_message->edit(MessageBuilder::new()->setContent('Manually Restarting... (3/3)'))->then(fn() => $this->civ13->restart());
                        return HttpResponse::plaintext($message);
                    }
                    if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot'])) $this->civ13->sendMessage($channel, $message)->then(fn() => $this->civ13->restart());
                    return HttpResponse::plaintext($message);
                }, true)
            ->offsetSet('/kill',
                function (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse
                {
                    $message = 'Shutting down...';
                    $this->discord->getLoop()->addTimer(3, $this->civ13->restart());
                    return HttpResponse::plaintext($message);
                }, true)
            ->offsetSet('/clearenv',
                function (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse
                {
                    $message = 'Clearing .env...';
                    if (file_exists($env = getcwd() . '/.env')) file_put_contents($env, '');
                    return HttpResponse::plaintext($message);
                }, true)
            ->offsetSet('/updateadmins',
                fn(ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse =>
                    $this->civ13->adminlistUpdate()
                        ? HttpResponse::plaintext("Admin lists updated")->withStatus(HttpResponse::STATUS_OK)
                        : HttpResponse::plaintext("Unable to update admin lists")->withStatus(HttpResponse::STATUS_OK),
                true)
            ->offsetSet('/bancheck_centcom',
                function (ServerRequestInterface $request, string $endpoint, bool $whitelisted) use (&$gameserver): HttpResponse
                {
                    $params = $request->getQueryParams();
                    if (! isset($params['ckey'])) return HttpResponse::plaintext("`ckey` must be included as a query parameter")->withStatus(HttpResponse::STATUS_BAD_REQUEST);
                    if (is_numeric($ckey = $params['ckey'])) {
                        if (! $item = $this->civ13->verifier->get('discord', $ckey)) return HttpResponse::plaintext("Unable to locate Byond username for Discord ID `$ckey`")->withStatus(HttpResponse::STATUS_BAD_REQUEST);
                        $ckey = $item['ss13'];
                    }
                    if (! $json = Byond::bansearch_centcom($ckey, false)) return HttpResponse::plaintext("Unable to locate bans for `$ckey` on CentCom")->withStatus(HttpResponse::STATUS_OK);                
                    return new HttpResponse(HttpResponse::STATUS_OK, ['Content-Type' => 'application/json'], $json);
                })
            // HttpHandler log endpoints
            ->offsetSets(['/botlog', '/botlog2'],
                function (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse
                {
                    $webpage_content = function (string $return) use ($endpoint) {
                        return '<meta name="color-scheme" content="light dark"> 
                                <div class="button-container">
                                    <button style="width:8%" onclick="sendGetRequest(\'pull\')">Pull</button>
                                    <button style="width:8%" onclick="sendGetRequest(\'reset\')">Reset</button>
                                    <button style="width:8%" onclick="sendGetRequest(\'update\')">Update</button>
                                    <button style="width:8%" onclick="sendGetRequest(\'restart\')">Restart</button>
                                    <button style="background-color: black; color:white; display:flex; justify-content:center; align-items:center; height:100%; width:68%; flex-grow: 1;" onclick="window.open(\''. $this->civ13->github . '\')">' . $this->discord->username . '</button>
                                </div>
                                <div class="alert-container"></div>
                                <div class="checkpoint">' . 
                                    str_replace('[' . date("Y"), '</div><div> [' . date("Y"), 
                                        str_replace([PHP_EOL, '[] []', ' [] '], '</div><div>', $return)
                                    ) . 
                                "</div>
                                <div class='reload-container'>
                                    <button onclick='location.reload()'>Reload</button>
                                </div>
                                <div class='loading-container'>
                                    <div class='loading-bar'></div>
                                </div>
                                <script>
                                    var mainScrollArea=document.getElementsByClassName('checkpoint')[0];
                                    var scrollTimeout;
                                    window.onload=function(){
                                        if (window.location.href==localStorage.getItem('lastUrl')){
                                            mainScrollArea.scrollTop=localStorage.getItem('scrollTop');
                                        } else {
                                            localStorage.setItem('lastUrl',window.location.href);
                                            localStorage.setItem('scrollTop',0);
                                        }
                                    };
                                    mainScrollArea.addEventListener('scroll',function(){
                                        clearTimeout(scrollTimeout);
                                        scrollTimeout=setTimeout(function(){
                                            localStorage.setItem('scrollTop',mainScrollArea.scrollTop);
                                        },100);
                                    });
                                    function sendGetRequest(endpoint) {
                                        var xhr = new XMLHttpRequest();
                                        xhr.open('GET', window.location.protocol + '//' + window.location.hostname + ':{$this->http_port}/' + endpoint, true);
                                        xhr.onload = function () {
                                            var response = xhr.responseText.replace(/(<([^>]+)>)/gi, '');
                                            var alertContainer = document.querySelector('.alert-container');
                                            var alert = document.createElement('div');
                                            alert.innerHTML = response;
                                            alertContainer.appendChild(alert);
                                            setTimeout(function() {
                                                alert.remove();
                                            }, 80000);
                                            if (endpoint === 'restart') {
                                                var loadingBar = document.querySelector('.loading-bar');
                                                var loadingContainer = document.querySelector('.loading-container');
                                                loadingContainer.style.display = 'block';
                                                var width = 0;
                                                var interval = setInterval(function() {
                                                    if (width >= 100) {
                                                        clearInterval(interval);
                                                        location.reload();
                                                    } else {
                                                        width += 1.25;
                                                        loadingBar.style.width = width + '%';
                                                    }
                                                }, 800);
                                                loadingBar.style.backgroundColor = 'white';
                                                loadingBar.style.height = '20px';
                                                loadingBar.style.position = 'fixed';
                                                loadingBar.style.top = '50%';
                                                loadingBar.style.left = '50%';
                                                loadingBar.style.transform = 'translate(-50%, -50%)';
                                                loadingBar.style.zIndex = '9999';
                                                loadingBar.style.borderRadius = '5px';
                                                loadingBar.style.boxShadow = '0 0 10px rgba(0, 0, 0, 0.5)';
                                                var backdrop = document.createElement('div');
                                                backdrop.style.position = 'fixed';
                                                backdrop.style.top = '0';
                                                backdrop.style.left = '0';
                                                backdrop.style.width = '100%';
                                                backdrop.style.height = '100%';
                                                backdrop.style.backgroundColor = 'rgba(0, 0, 0, 0.5)';
                                                backdrop.style.zIndex = '9998';
                                                document.body.appendChild(backdrop);
                                                setTimeout(function() {
                                                    clearInterval(interval);
                                                    if (!document.readyState || document.readyState === 'complete') {
                                                        location.reload();
                                                    } else {
                                                        setTimeout(function() {
                                                            location.reload();
                                                        }, 80000);
                                                    }
                                                }, 80000);
                                            }
                                        };
                                        xhr.send();
                                    }
                                    </script>
                                    <style>
                                        .button-container {
                                            position: fixed;
                                            top: 0;
                                            left: 0;
                                            right: 0;
                                            background-color: #f1f1f1;
                                            overflow: hidden;
                                        }
                                        .button-container button {
                                            float: left;
                                            display: block;
                                            color: black;
                                            text-align: center;
                                            padding: 14px 16px;
                                            text-decoration: none;
                                            font-size: 17px;
                                            border: none;
                                            cursor: pointer;
                                            color: white;
                                            background-color: black;
                                        }
                                        .button-container button:hover {
                                            background-color: #ddd;
                                        }
                                        .checkpoint {
                                            margin-top: 100px;
                                        }
                                        .alert-container {
                                            position: fixed;
                                            top: 0;
                                            right: 0;
                                            width: 300px;
                                            height: 100%;
                                            overflow-y: scroll;
                                            padding: 20px;
                                            color: black;
                                            background-color: black;
                                        }
                                        .alert-container div {
                                            margin-bottom: 10px;
                                            padding: 10px;
                                            background-color: #fff;
                                            border: 1px solid #ddd;
                                        }
                                        .reload-container {
                                            position: fixed;
                                            bottom: 0;
                                            left: 50%;
                                            transform: translateX(-50%);
                                            margin-bottom: 20px;
                                        }
                                        .reload-container button {
                                            display: block;
                                            color: black;
                                            text-align: center;
                                            padding: 14px 16px;
                                            text-decoration: none;
                                            font-size: 17px;
                                            border: none;
                                            cursor: pointer;
                                        }
                                        .reload-container button:hover {
                                            background-color: #ddd;
                                        }
                                        .loading-container {
                                            position: fixed;
                                            top: 0;
                                            left: 0;
                                            right: 0;
                                            bottom: 0;
                                            background-color: rgba(0, 0, 0, 0.5);
                                            display: none;
                                        }
                                        .loading-bar {
                                            position: absolute;
                                            top: 50%;
                                            left: 50%;
                                            transform: translate(-50%, -50%);
                                            width: 0%;
                                            height: 20px;
                                            background-color: white;
                                        }
                                        .nav-container {
                                            position: fixed;
                                            bottom: 0;
                                            right: 0;
                                            margin-bottom: 20px;
                                        }
                                        .nav-container button {
                                            display: block;
                                            color: black;
                                            text-align: center;
                                            padding: 14px 16px;
                                            text-decoration: none;
                                            font-size: 17px;
                                            border: none;
                                            cursor: pointer;
                                            color: white;
                                            background-color: black;
                                            margin-right: 10px;
                                        }
                                        .nav-container button:hover {
                                            background-color: #ddd;
                                        }
                                        .checkbox-container {
                                            display: inline-block;
                                            margin-right: 10px;
                                        }
                                        .checkbox-container input[type=checkbox] {
                                            display: none;
                                        }
                                        .checkbox-container label {
                                            display: inline-block;
                                            background-color: #ddd;
                                            padding: 5px 10px;
                                            cursor: pointer;
                                        }
                                        .checkbox-container input[type=checkbox]:checked + label {
                                            background-color: #bbb;
                                        }
                                    </style>
                                    <div class='nav-container'>"
                                        . ($endpoint === '/botlog' ? "<button onclick=\"location.href='/botlog2'\">Botlog 2</button>" : "<button onclick=\"location.href='/botlog'\">Botlog 1</button>")
                                    . "</div>
                                    <div class='reload-container'>
                                        <div class='checkbox-container'>
                                            <input type='checkbox' id='auto-reload-checkbox' " . (isset($_COOKIE['auto-reload']) && $_COOKIE['auto-reload'] === 'true' ? 'checked' : '') . ">
                                            <label for='auto-reload-checkbox'>Auto Reload</label>
                                        </div>
                                        <button id='reload-button'>Reload</button>
                                    </div>
                                    <script>
                                        var reloadButton = document.getElementById('reload-button');
                                        var autoReloadCheckbox = document.getElementById('auto-reload-checkbox');
                                        var interval;
                
                                        reloadButton.addEventListener('click', function () {
                                            clearInterval(interval);
                                            location.reload();
                                        });
                
                                        autoReloadCheckbox.addEventListener('change', function () {
                                            if (this.checked) {
                                                interval = setInterval(function() {
                                                    location.reload();
                                                }, 15000);
                                                localStorage.setItem('auto-reload', 'true');
                                            } else {
                                                clearInterval(interval);
                                                localStorage.setItem('auto-reload', 'false');
                                            }
                                        });
                
                                        if (localStorage.getItem('auto-reload') == 'true') {
                                            autoReloadCheckbox.checked = true;
                                            interval = setInterval(function() {
                                                location.reload();
                                            }, 15000);
                                        }
                                    </script>";
                    };
                    if (! $return = @file_get_contents($fp = 'output.log')) return $this->httpHandler->__throwError("Unable to access `$fp`", HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                    return HttpResponse::html($webpage_content($return));
                }, true)
            ->offsetSet('/verified',
                fn(ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse =>
                    HttpResponse::json($this->civ13->verifier->verified->toArray()),
                true)
            ->offsetSet($endpoint = '/contact',
                function (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse
                {
                    $parsedBody = $request->getParsedBody();
                    $ip = $request->getServerParams()['REMOTE_ADDR'];
                    $ckey = htmlspecialchars($parsedBody['ckey'] ?? 'Anonymous');
                    $email = htmlspecialchars($parsedBody['email'] ?? 'No email provided');
                    $messageContent = htmlspecialchars($parsedBody['message'] ?? 'No message provided');
                    
                    $embed = $this->civ13->createEmbed()
                        ->addFieldValues('IP', $ip)
                        ->addFieldValues('Byond Username', $ckey)
                        ->addFieldValues('Email', $email)
                        ->addFieldValues('Message', $messageContent)
                        ->setAuthor('Anonymous', $this->discord->avatar);
                    if ($item = $this->civ13->verifier->getVerifiedItem(Civ13::sanitizeInput($ckey)))
                        if (isset($item['discord']) && $user = $this->discord->users->get('id', $item['discord']))
                            $embed->setAuthor("{$user->username} ({$user->id})", $user->avatar ?? $this->discord->avatar);
                    $this->logger->info("[CONTACT FORM] IP: $ip, Byond Username: $ckey, Email: $email, Message: $messageContent");
                    if (isset($this->civ13->channel_ids['email']) && $channel = $this->discord->getChannel($this->civ13->channel_ids['email'])) $channel->sendMessage(MessageBuilder::new()->addEmbed($embed));
                    return HttpResponse::plaintext('Form submitted successfully');
                })
                ->setRateLimit($endpoint, 1, 43200) // 1 form every 12 hours

            // HttpHandler data endpoints
            /*
            ->offsetSet('/endpoint',
                function (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse
                {
                    return HttpResponse::plaintext("Hello wörld!\n");
                    return HttpResponse::html("<!doctype html><html><body>Hello wörld!</body></html>");
                    return new HttpResponse(
                        HttpResponse::STATUS_OK,
                        ['Content-Type' => 'text/json'],
                        json_encode($json ?? '')
                    );
                })
            */
        ;
        // HttpHandler whitelisting with DiscordWebAuth
        if (include('dwa_secrets.php'))
        if ($dwa_client_id = getenv('dwa_client_id'))
        if ($dwa_client_secret = getenv('dwa_client_secret'))
        $this->httpHandler
            ->offsetSet('/dwa',
                function (ServerRequestInterface $request, string $endpoint, bool $whitelisted) use ($dwa_client_id, $dwa_client_secret): HttpResponse
                {
                    $ip = $request->getServerParams()['REMOTE_ADDR'];
                    if (! isset($this->dwa_sessions[$ip])) {
                        $this->dwa_sessions[$ip] = [];
                        $this->dwa_timers[$ip] = $this->discord->getLoop()->addTimer(30 * 60, function () use ($ip) { // Set a timer to unset the session after 30 minutes
                            unset($this->dwa_sessions[$ip]);
                        });
                    }

                    $DiscordWebAuth = new DiscordWebAuth($this->civ13, $this->dwa_sessions, $dwa_client_id, $dwa_client_secret, $this->web_address, $this->http_port, $request);
                    if (isset($params['code']) && isset($params['state']))
                        return $DiscordWebAuth->getToken($params['state']);
                    elseif (isset($params['login']))
                        return $DiscordWebAuth->login();
                    elseif (isset($params['logout']))
                        return $DiscordWebAuth->logout();
                    elseif ($DiscordWebAuth->isAuthed() && isset($params['remove']))
                        return $DiscordWebAuth->removeToken();
                    
                    $tech_ping = '';
                    if (isset($this->civ13->technician_id)) $tech_ping = "<@{$this->civ13->technician_id}>, ";
                    if (isset($DiscordWebAuth->user) && isset($DiscordWebAuth->user->id)) {
                        $this->dwa_discord_ids[$ip] = $DiscordWebAuth->user->id;
                        if (! $this->civ13->verifier->get('discord', $DiscordWebAuth->user->id)) {
                            if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot'])) $this->civ13->sendMessage($channel, $tech_ping . "<@&$DiscordWebAuth->user->id> tried to log in with Discord but does not have permission to! Please check the logs.");
                            return new HttpResponse(HttpResponse::STATUS_UNAUTHORIZED);
                        }
                        if ($this->httpHandler->whitelist($ip))
                            if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot']))
                                $this->civ13->sendMessage($channel, $tech_ping . "<@{$DiscordWebAuth->user->id}> has logged in with Discord.");
                        if ($this->httpHandler->offsetGet('/botlog', 'handlers'))
                            return new HttpResponse(HttpResponse::STATUS_FOUND, ['Location' => "http://{$this->httpHandler->external_ip}:{$this->http_port}/botlog"]);
                    }

                    return new HttpResponse(HttpResponse::STATUS_FOUND, ['Location' => "http://{$this->httpHandler->external_ip}:{$this->http_port}/botlog"]);
                })
            ;
        // HttpHandler redirect endpoints
        if ($this->civ13->github)
        $this->httpHandler
            ->offsetSet('/github',
                fn(ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse =>
                    new HttpResponse(HttpResponse::STATUS_FOUND,['Location' => $this->civ13->github]))
            ;

        if ($this->civ13->discord_invite)
        $this->httpHandler
            ->offsetSet('/discord',
                fn(ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse =>
                    new HttpResponse(HttpResponse::STATUS_FOUND,['Location' => $this->civ13->discord_invite]))
        ;

        $this->__generateServerEndpoints();
        $this->__generateWebsiteEndpoints();
    }

    private function __generateServerEndpoints()
    {        
        foreach ($this->civ13->enabled_gameservers as &$gameserver) {
            // General usage server endpoints
            $server_endpoint = '/' . $gameserver->key;
            $this->httpHandler
                ->offsetSet($server_endpoint.'/bans',
                    function (ServerRequestInterface $request, string $endpoint, bool $whitelisted) use (&$gameserver): HttpResponse
                    {
                        if (! file_exists($bans = $gameserver->basedir . Civ13::bans)) return HttpResponse::plaintext("Unable to access `$bans`")->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                        if (! $content = @file_get_contents($bans)) return HttpResponse::plaintext("Unable to read `$bans`")->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                        // Current format: type;job;uid;reason;admin;date;timestamp;expires;ckey;cid;ip|||
                        $rows = explode('|||', trim($content));
                        $json = [];
                        $id = 0;
                        foreach ($rows as $data) {
                            if (! $ban = explode(';', $data)) continue;
                            if (! isset($ban[10])) continue; // Must be missing some data
                            /*$old_json[] = [ 
                                'type' => $ban[0],
                                'job' => $ban[1],
                                'uid' => $ban[2],
                                'reason' => $ban[3],
                                'admin' => $ban[4],
                                'date' => $ban[5],
                                'timestamp' => $ban[6],
                                'expires' => $ban[7],
                                'ckey' => $ban[8],
                                'cid' => $ban[9],
                                'ip' => $ban[10]
                            ];
                            */
                            
                            $ban_type = str_replace(PHP_EOL, '', $ban[0]);

                            $date = str_replace('.', ':', $ban[5]);
                            $dateTime = new \DateTime($date);
                            $banned_on_timestamp = $dateTime->getTimestamp();
                            $banned_on = date('c', $banned_on_timestamp);

                            $d = explode(' ', $ban[7]);
                            $dur['int'] = intval($d[2]);
                            $dur['unit'] = $d[3];
                            $expires_in = strtotime('+'.$dur['int']. ' ' . $dur['unit'], $banned_on_timestamp);
                            $expires_date = date('c', $expires_in);

                            $json[] = [
                                'id' => $id,
                                'banType' => $ban_type,
                                'ckey' => $ban[8],
                                'bannedOn' => $banned_on,
                                'bannedBy' => $ban[4],
                                'reason' => $ban[3],
                                'expires' => $expires_date,
                                'unbannedBy' => null,
                                'jobBans' => null
                            ];
                            $id++;
                        }
                        $response = new HttpResponse(HttpResponse::STATUS_OK, ['Content-Type' => 'text/json'], json_encode($json ?? ''));
                        $response = $response->withHeader('Cache-Control', 'public, max-age=3600');
                        return $response;
                    })

                ->offsetSet($server_endpoint.'/playerlogs',
                    function (ServerRequestInterface $request, string $endpoint, bool $whitelisted) use (&$gameserver): HttpResponse
                    {
                        if (! file_exists($playerlogs = $gameserver->basedir . Civ13::playerlogs)) return HttpResponse::plaintext("Unable to access `$playerlogs`")->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                        if (! $return = @file_get_contents($playerlogs)) return HttpResponse::plaintext("Unable to read `$playerlogs`")->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                        return HttpResponse::plaintext($return);
                    }, true)
                ->offsetSet($server_endpoint.'/ooclogs',
                    function (ServerRequestInterface $request, string $endpoint, bool $whitelisted) use (&$gameserver): HttpResponse
                    {
                        if (! file_exists($ooclogs = $gameserver->basedir . Civ13::ooc_path)) return HttpResponse::plaintext("Unable to access `$ooclogs`")->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                        if (! $return = @file_get_contents($ooclogs)) return HttpResponse::plaintext("Unable to read `$ooclogs`")->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                        return HttpResponse::plaintext($return);
                    }, true)
            ;
            // Webhooks received from the game server
            $server_endpoint = '/webhook/' . $gameserver->key; // If no parameters are passed to a server_endpoint, try to find it using the query parameters
            $this->httpHandler
                ->offsetSet($server_endpoint,
                    function (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse
                    {
                        $params = $request->getQueryParams();
                        //if ($params['method']) $this->logger->info("[METHOD] `{$params['method']}`");
                        if ($method = $this->httpHandler->offsetGet($endpoint.'/'.($params['method'] ?? ''), 'handlers')) return $method($request, $endpoint, $whitelisted);
                        return HttpResponse::plaintext("Method not found for `{$endpoint}/" . ($params['method'] ?? '') . "`")->withStatus(HttpResponse::STATUS_NOT_FOUND);
                    }, true)
                ->offsetSet($server_endpoint.'/ahelpmessage',
                    function (ServerRequestInterface $request, string $endpoint, bool $whitelisted) use (&$gameserver): HttpResponse
                    {
                        if ($gameserver->legacy_relay) return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                        if (! isset($gameserver->asay)) return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                        if (! $this->discord->getChannel($channel_id = $gameserver->asay)) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);

                        $data = self::decodeParamData($request);
                        
                        $time = '['.date('H:i:s', time()).']';
                        isset($data['ckey']) ? $ckey = Civ13::sanitizeInput($data['ckey']) : $ckey = '(NULL)';
                        isset($data['message']) ? $message = strip_tags(htmlspecialchars_decode(html_entity_decode($data['message']))) : $message = '(NULL)';
                        $message = "**__{$time} AHELP__ $ckey:** " . $message;

                        $gameserver->gameChatWebhookRelay($message, $channel_id, $ckey, true);
                        
                        // Check if there are any Discord admins on the server, notify staff in Discord if there are not
                        if (isset($this->civ13->verifier) && $guild = $this->discord->guilds->get('id', $this->civ13->civ13_guild_id)) {
                            $urgent = true;
                            $admin = false;
                            if ($item = $this->civ13->verifier->get('ss13', $ckey))
                                if ($member = $guild->members->get('id', $item['discord']))
                                    if ($member->roles->has($this->civ13->role_ids['Admin']))
                                        { $admin = true; $urgent = false;}
                            if (! $admin) {
                                if ($playerlist = $gameserver->players)
                                    if ($admins = $guild->members->filter(function (Member $member) { return $member->roles->has($this->civ13->role_ids['Admin']); }))
                                        $urgent = empty($admins->filter(fn($member) => $item = $this->civ13->verifier->get('discord', $member->id) && in_array($item['ss13'], $playerlist)));
                            }
                            if ($urgent && $channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot']))
                                $this->civ13->sendMessage($channel,
                                    "<@&{$this->civ13->role_ids['Admin']}>, a request for help "
                                    . ($ckey ? "from `$ckey` " : '')
                                    . (($member = ($item = $this->civ13->verifier->get('ss13', $ckey)) ? $guild->members->get('id', $item['discord']) : null) ? "$member " : '')
                                    . " has been received in the {$gameserver->name} server. Please see the relevant message in <#{$gameserver->asay}>: $message"
                                );
                        }
                        return new HttpResponse(HttpResponse::STATUS_OK);
                    }, true)
                ->offsetSet($server_endpoint.'/asaymessage',
                    function (ServerRequestInterface $request, string $endpoint, bool $whitelisted) use (&$gameserver): HttpResponse
                    {
                        if ($gameserver->legacy_relay) return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                        if (! isset($gameserver->asay)) return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                        if (! $channel = $this->discord->getChannel($channel_id = $gameserver->asay)) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                        
                        $data = self::decodeParamData($request);
                        $time = '['.date('H:i:s', time()).']';
                        isset($data['ckey']) ? $ckey = Civ13::sanitizeInput($data['ckey']) : $ckey = '(NULL)';
                        isset($data['message']) ? $message = strip_tags(htmlspecialchars_decode(html_entity_decode($data['message']))) : $message = '(NULL)';
                        //$message = "**__{$time} ASAY__ $ckey:** $message";
                        $message = "**__{$time}__** $message";

                        
                        $gameserver->gameChatWebhookRelay($message, $channel_id, $ckey, true, str_contains($data['message'], $this->discord->username)); // Message was probably meant for the bot

                        // Check if there are any Discord admins on the server, notify staff in Discord if there are not
                        if ($guild = $this->discord->guilds->get('id', $this->civ13->civ13_guild_id)) {
                            $urgent = true;
                            $admin = false;
                            if (isset($this->civ13->verifier)) {
                                if ($item = $this->civ13->verifier->get('ss13', $ckey))
                                    if ($member = $guild->members->get('id', $item['discord']))
                                        if ($member->roles->has($this->civ13->role_ids['Admin']))
                                            { $admin = true; $urgent = false;}
                                if (! $admin) {
                                    if ($playerlist = $gameserver->players)
                                        if ($admins = $guild->members->filter(function (Member $member) { return $member->roles->has($this->civ13->role_ids['Admin']); }))
                                            foreach ($admins as $member)
                                                if ($item = $this->civ13->verifier->get('discord', $member->id))
                                                    if (in_array($item['ss13'], $playerlist))
                                                        { $urgent = false; break; }
                                }
                            }
                            if ($urgent && $channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot']) && !strstr($message, "ADMINPM")) $this->civ13->sendMessage($channel, "<@&{$this->civ13->role_ids['Admin']}>, an urgent asay message has been received in the {$gameserver->name} server. Please see the relevant message in <#{$gameserver->asay}>: `$message`");
                        }

                        return new HttpResponse(HttpResponse::STATUS_OK);
                    }, true)
                ->offsetSet($server_endpoint.'/urgentasaymessage',
                    function (ServerRequestInterface $request, string $endpoint, bool $whitelisted) use (&$gameserver): HttpResponse
                    {
                        if ($gameserver->legacy_relay) return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                        if (! isset($gameserver->asay)) return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                        if (! $this->discord->getChannel($channel_id = $gameserver->asay)) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);

                        $data = self::decodeParamData($request);
                        $time = '['.date('H:i:s', time()).']';
                        isset($data['ckey']) ? $ckey = Civ13::sanitizeInput($data['ckey']) : $ckey = '(NULL)';
                        $message = "<@{$this->civ13->role_ids['Admin']}>, ";
                        isset($data['message']) ? $message .= strip_tags(htmlspecialchars_decode(html_entity_decode($data['message']))) : $message .= '(NULL)';
                        //$message = "**__{$time} ASAY__ $ckey:** $message";
                        $message = "**__{$time}__** $message";

                        $gameserver->gameChatWebhookRelay($message, $channel_id, $ckey, true, false);
                        return new HttpResponse(HttpResponse::STATUS_OK);
                    }, true)

                ->offsetSet($server_endpoint.'/lobbymessage',
                    function (ServerRequestInterface $request, string $endpoint, bool $whitelisted) use (&$gameserver): HttpResponse
                    {
                        if ($gameserver->legacy_relay) return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                        if (! isset($gameserver->lobby)) return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                        if (! $this->discord->getChannel($channel_id = $gameserver->lobby)) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);

                        $data = self::decodeParamData($request);
                        $time = '['.date('H:i:s', time()).']';
                        isset($data['ckey']) ? $ckey = Civ13::sanitizeInput($data['ckey']) : $ckey = '(NULL)';
                        isset($data['message']) ? $message = strip_tags(htmlspecialchars_decode(html_entity_decode($data['message']))) : $message = '(NULL)';
                        $message = "**__{$time} LOBBY__ $ckey:** $message";

                        $gameserver->gameChatWebhookRelay($message, $channel_id, $ckey, true);
                        return new HttpResponse(HttpResponse::STATUS_OK);
                    }, true)
                ->offsetSet($server_endpoint.'/oocmessage',
                    function (ServerRequestInterface $request, string $endpoint, bool $whitelisted) use (&$gameserver): HttpResponse
                    {
                        if ($gameserver->legacy_relay) return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                        if (! isset($gameserver->ooc)) return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                        if (! $this->discord->getChannel($channel_id = $gameserver->ooc)) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);

                        $data = self::decodeParamData($request);
                        //$time = '['.date('H:i:s', time()).']';
                        isset($data['ckey']) ? $ckey = Civ13::sanitizeInput($data['ckey']) : $ckey = '(NULL)';
                        isset($data['message']) ? $message = strip_tags(htmlspecialchars_decode(html_entity_decode($data['message']))) : $message = '(NULL)';
                        //$message = "**__{$time} OOC__ $ckey:** $message";

                        $gameserver->gameChatWebhookRelay($message, $channel_id, $ckey, true);
                        return new HttpResponse(HttpResponse::STATUS_OK);
                    }, true)
                ->offsetSet($server_endpoint.'/icmessage',
                    function (ServerRequestInterface $request, string $endpoint, bool $whitelisted) use (&$gameserver): HttpResponse
                    {
                        if ($gameserver->legacy_relay) return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                        if (! isset($gameserver->ic)) return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                        if (! $this->discord->getChannel($channel_id = $gameserver->ic)) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);

                        $data = self::decodeParamData($request);
                        //$time = '['.date('H:i:s', time()).']';
                        isset($data['ckey']) ? $ckey = Civ13::sanitizeInput($data['ckey']) : $ckey = '(NULL)';
                        isset($data['message']) ? $message = strip_tags(htmlspecialchars_decode(html_entity_decode($data['message']))) : $message = '(NULL)';
                        //$message = "**__{$time} OOC__ $ckey:** $message";

                        $gameserver->gameChatWebhookRelay($message, $channel_id, $ckey, false);
                        return new HttpResponse(HttpResponse::STATUS_OK);
                    }, true)
                ->offsetSet($server_endpoint.'/memessage',
                    function (ServerRequestInterface $request, string $endpoint, bool $whitelisted) use (&$gameserver): HttpResponse
                    {
                        if ($gameserver->legacy_relay) return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                        if (! isset($gameserver->ic)) return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                        if (! $this->discord->getChannel($channel_id = $gameserver->ic)) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);

                        $data = self::decodeParamData($request);
                        $time = '['.date('H:i:s', time()).']';
                        isset($data['ckey']) ? $ckey = Civ13::sanitizeInput($data['ckey']) : $ckey = '(NULL)';
                        isset($data['message']) ? $message = strip_tags(htmlspecialchars_decode(html_entity_decode($data['message']))) : $message = '(NULL)';
                        $message = "**__{$time} EMOTE__ $ckey:** $message";

                        $gameserver->gameChatWebhookRelay($message, $channel_id, $ckey, false);
                        return new HttpResponse(HttpResponse::STATUS_OK);
                    }, true)
                ->offsetSet($server_endpoint.'/garbage',
                    function (ServerRequestInterface $request, string $endpoint, bool $whitelisted) use (&$gameserver): HttpResponse
                    {
                        if ($gameserver->legacy_relay) return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                        if (! isset($gameserver->adminlog)) return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                        if (! $this->discord->getChannel($channel_id = $gameserver->adminlog)) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);

                        $data = self::decodeParamData($request);
                        $time = '['.date('H:i:s', time()).']';
                        isset($data['ckey']) ? $ckey = Civ13::sanitizeInput($data['ckey']) : $ckey = '(NULL)';
                        isset($data['message']) ? $message = strip_tags(htmlspecialchars_decode(html_entity_decode($data['message']))) : $message = '(NULL)';
                        $message = "**__{$time} GARBAGE__ $ckey:** $message";

                        $gameserver->gameChatWebhookRelay($message, $channel_id, $ckey);
                        return new HttpResponse(HttpResponse::STATUS_OK);
                    }, true)
                ->offsetSet($server_endpoint.'/round_start',
                    function (ServerRequestInterface $request, string $endpoint, bool $whitelisted) use (&$gameserver): HttpResponse
                    {
                        if ($gameserver->legacy_relay) return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                        if (! isset($gameserver->discussion)) return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                        if (! $channel = $this->discord->getChannel($gameserver->discussion)) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);

                        $data = self::decodeParamData($request);
                        $time = '['.date('H:i:s', time()).']';
                        $message = '';
                        if (isset($this->civ13->role_ids['round_start'])) $message .= "<@&{$this->civ13->role_ids['round_start']}>, ";
                        $message .= 'New round ';
                        if (isset($data, $data['round']) && $game_id = $data['round']) {
                            $gameserver->logNewRound($game_id, $time);
                            $message .= "`$game_id` ";
                        }
                        $message .= 'has started!';
                        if ($playercount_channel = $this->discord->getChannel($gameserver->playercount))
                        if ($existingCount = explode('-', $playercount_channel->name)[1]) {
                            $existingCount = intval($existingCount);
                            switch ($existingCount) {
                                case 0:
                                    $message .= " There are currently no players on the {$gameserver->name} server.";
                                    break;
                                case 1:
                                    $message .= " There is currently 1 player on the {$gameserver->name} server.";
                                    break;
                                default:
                                    if (isset($this->civ13->role_ids['30+']) && $this->civ13->role_ids['30+'] && ($existingCount >= 30)) $message .= " <@&{$this->civ13->role_ids['30+']}>,";
                                    elseif (isset($this->civ13->role_ids['15+']) && $this->civ13->role_ids['15+'] && ($existingCount >= 15)) $message .= " <@&{$this->civ13->role_ids['15+']}>,";
                                    elseif (isset($this->civ13->role_ids['2+']) && $this->civ13->role_ids['2+'] && ($existingCount >= 2)) $message .= " <@&{$this->civ13->role_ids['2+']}>,";
                                    $message .= " There are currently $existingCount players on the {$gameserver->name} server.";
                                    break;
                            }
                        }
                        $this->civ13->sendMessage($channel, $message);
                        return new HttpResponse(HttpResponse::STATUS_OK);
                    }, true)
                ->offsetSet($server_endpoint.'/respawn_notice',
                    function (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse
                    { // NYI
                        return new HttpResponse(HttpResponse::STATUS_OK);
                    }, true)
                ->offsetSet($server_endpoint.'/login',
                    function (ServerRequestInterface $request, string $endpoint, bool $whitelisted) use (&$gameserver): HttpResponse
                    {
                        if ($gameserver->legacy_relay) return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                        if (! isset($gameserver->transit)) return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                        if (! $this->discord->getChannel($channel_id = $gameserver->transit)) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                        if (! isset($this->civ13->channel_ids['parole_notif'])) return HttpResponse::plaintext('Parole Notification Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                        if (! $parole_notif_channel = $this->discord->getChannel($this->civ13->channel_ids['parole_notif'])) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);

                        $data = self::decodeParamData($request);
                        isset($data['ckey']) ? $ckey = Civ13::sanitizeInput($data['ckey']) : $ckey = '(NULL)';
                            if ($gameserver = $this->civ13->enabled_gameservers[$gameserver->key])
                            if (! $ckey !== '(NULL)' && ! in_array($ckey, $gameserver->players))
                                $gameserver->players[] = $ckey;

                        $time = '['.date('H:i:s', time()).']';
                        $message = "$ckey connected to the server";
                        if (isset($data, $data['ip'])) $message .= " with IP of {$data['ip']}";
                        if (isset($data, $data['cid'])) $message .= " and CID of {$data['cid']}";
                        $message .= '.';
                        $gameserver->logPlayerLogin($ckey, $time, $data['ip'] ?? '', $data['cid'] ?? '');

                        if (isset($this->civ13->paroled[$ckey])) {
                            $message2 = '';
                            if (isset($this->civ13->role_ids['Parolemin'])) $message2 .= "<@&{$this->civ13->role_ids['Parolemin']}>, ";
                            $message2 .= "`$ckey` has logged into `{$gameserver->name}`";
                            $this->civ13->sendMessage($parole_notif_channel, $message2);
                        }

                        $gameserver->gameChatWebhookRelay($message, $channel_id, $ckey, true, false);
                        if ($ckey && $ckey !== '(NULL)') {
                            $this->civ13->moderator->scrutinizeCkey($ckey);
                            $gameserver->panicCheck($ckey); 
                        }
                        return new HttpResponse(HttpResponse::STATUS_OK);
                    }, true)
                ->offsetSet($server_endpoint.'/logout',
                    function (ServerRequestInterface $request, string $endpoint, bool $whitelisted) use (&$gameserver): HttpResponse
                    {
                        if ($gameserver->legacy_relay) return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                        if (! isset($gameserver->transit)) return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                        if (! $this->discord->getChannel($channel_id = $gameserver->transit)) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                        if (! isset($this->civ13->channel_ids['parole_notif'])) return HttpResponse::plaintext('Parole Notification Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                        if (! $parole_notif_channel = $this->discord->getChannel($this->civ13->channel_ids['parole_notif'])) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);

                        $data = self::decodeParamData($request);
                        isset($data['ckey']) ? $ckey = Civ13::sanitizeInput($data['ckey']) : $ckey = '(NULL)';
                        $time = '['.date('H:i:s', time()).']';
                        $message = "$ckey disconnected from the server.";
                        $gameserver->logPlayerLogout($ckey, $time);

                        $gameserver->gameChatWebhookRelay($message, $channel_id, $ckey, true, false);
                        if (isset($this->civ13->paroled[$ckey])) {
                            $message2 = '';
                            if (isset($this->civ13->role_ids['Parolemin'])) $message2 .= "<@&{$this->civ13->role_ids['Parolemin']}>, ";
                            $message2 .= "`$ckey` has log out of `{$gameserver->name}`";
                            $this->civ13->sendMessage($parole_notif_channel, $message2);
                        }
                        return new HttpResponse(HttpResponse::STATUS_OK);
                    }, true)
                ->offsetSet($server_endpoint.'/runtimemessage',
                    function (ServerRequestInterface $request, string $endpoint, bool $whitelisted) use (&$gameserver): HttpResponse
                    {
                        if ($gameserver->legacy_relay) return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                        if (! isset($gameserver->runtime)) return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                        if (! $this->discord->getChannel($gameserver->runtime)) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);

                        $data = self::decodeParamData($request);
                        
                        $time = '['.date('H:i:s', time()).']';
                        //isset($data, $data['ckey']) ? $ckey = Civ13::sanitizeInput($data['ckey']) : $ckey = '(NULL)';
                        isset($data['message']) ? $message = strip_tags(htmlspecialchars_decode(html_entity_decode($data['message']))) : $message = '(NULL)';
                        $message = "**__{$time} RUNTIME__:** $message";

                        return new HttpResponse(HttpResponse::STATUS_OK);
                    }, true)
                ->offsetSet($server_endpoint.'/alogmessage',
                    function (ServerRequestInterface $request, string $endpoint, bool $whitelisted) use (&$gameserver): HttpResponse
                    {
                        if ($gameserver->legacy_relay) return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                        if (! isset($gameserver->adminlog)) return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                        if (! $this->discord->getChannel($channel_id = $gameserver->adminlog)) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);

                        $data = self::decodeParamData($request);
                        
                        $time = '['.date('H:i:s', time()).']';
                        isset($data['message']) ? $message = strip_tags(htmlspecialchars_decode(html_entity_decode($data['message']))) : $message = '(NULL)';
                        $message = "**__{$time} ADMIN LOG__:** " . $message;

                        $gameserver->gameChatWebhookRelay($message, $channel_id);
                        return new HttpResponse(HttpResponse::STATUS_OK);
                    }, true)
                ->offsetSet($server_endpoint.'/attacklogmessage',
                    function (ServerRequestInterface $request, string $endpoint, bool $whitelisted) use (&$gameserver): HttpResponse
                    {
                        if ($gameserver->legacy_relay) return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                        if (! $gameserver->log_attacks) return new HttpResponse(HttpResponse::STATUS_FORBIDDEN); // Disabled on TDM, use manual checking of log files instead
                        if (! isset($gameserver->attack)) return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                        if (! $this->discord->getChannel($channel_id = $gameserver->attack)) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);

                        $data = self::decodeParamData($request);
                        
                        $time = '['.date('H:i:s', time()).']';
                        isset($data['ckey']) ? $ckey = Civ13::sanitizeInput($data['ckey']) : $ckey = null;
                        isset($data['ckey2']) ? $ckey2 = Civ13::sanitizeInput($data['ckey2']) : $ckey2 = null;
                        isset($data['message']) ? $message = strip_tags(htmlspecialchars_decode(html_entity_decode($data['message']))) : $message = '(NULL)';
                        $message = "**__{$time} ATTACK LOG__:** " . $message;
                        if ($ckey && $ckey2) if ($ckey === $ckey2) $message .= " (Self-Attack)";
                        
                        $gameserver->gameChatWebhookRelay($message, $channel_id);
                        return new HttpResponse(HttpResponse::STATUS_OK);
                    }, true)
                ->offsetSets([$server_endpoint.'/roundstatus', $server_endpoint.'/status_update'],
                    fn(ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse => new HttpResponse(HttpResponse::STATUS_OK)
                    , true)
                /*
                ->offsetSet($server_endpoint.'/', (function (ServerRequestInterface $request, string $endpoint, bool $whitelisted) use ($key, $server): HttpResponse
                {
                    return new HttpResponse(HttpResponse::STATUS_OK);
                }), true);
                */
            ;
        }
    }

    private function __generateWebsiteEndpoints()
    {
        if (! is_dir($dirPath = $this->basedir . self::HTMLDIR) && ! mkdir($dirPath, 0664, true))
                return $this->logger->error('Failed to create `/html` directory');
        $files = [];
        foreach (new \DirectoryIterator($dirPath) as $file) {
            if ($file->isDot() || ! $file->isFile() || $file->getExtension() !== 'html') continue;
            $files[] = substr($file->getPathname(), strlen($dirPath));
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . /*<?xml-stylesheet type="text/xsl" href="sitemap.xsl"?> .*/ '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($files as &$file) {
            if (! $fileContent = @file_get_contents(substr(self::HTMLDIR, 1) . $file)) {
                $this->logger->error("Failed to read file: `$file`");
                unset($file);
                continue;
            }
            $this->httpHandler->offsetSet($file, fn(ServerRequestInterface $request, string $endpoint, bool $whitelisted) => HttpResponse::html($fileContent));
            $xml .= "<url><loc>$file</loc></url>";
            $this->logger->info("[HTTP FILE ENDPOINT] `$file`");
        }
        $xml .= '</urlset>';
        $this->httpHandler->offsetSet($endpoint = '/sitemap.xml', fn(ServerRequestInterface $request, string $endpoint, bool $whitelisted) => HttpResponse::xml($xml))->setRateLimit($endpoint, 1, 10);

        $sitemalxsl = (function (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse
        {
            $xml = '<?xml version="1.0" encoding="UTF-8"?>
                <xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform">
                <xsl:output method="html" indent="yes"/>

                <xsl:template match="/">
                    <html>
                    <head>
                    <title>Sitemap</title>
                    <meta http-equiv="Cache-Control" content="max-age=31536000, public"/>
                    <style>
                        body { font-family: Arial, sans-serif; }
                        table { width: 100%; border-collapse: collapse; }
                        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
                        th { background-color: #f2f2f2; }
                    </style>
                    </head>
                    <body>
                    <h1>Sitemap</h1>
                    <table>
                        <tr>
                        <th>URL</th>
                        </tr>
                        <xsl:for-each select="urlset/url">
                        <tr>
                            <td><a href="{loc}"><xsl:value-of select="loc"/></a></td>
                        </tr>
                        </xsl:for-each>
                    </table>
                    </body>
                    </html>
                </xsl:template>
                </xsl:stylesheet>';
            return HttpResponse::xml($xml);
        });
        //->offsetSet('/sitemap.xsl', $sitemalxsl);
        //$this->httpHandler->setRateLimit('/sitemap.xsl', 1, 10); // 1 request per 10 seconds
    }

    /**
     * Magic method to dynamically call methods on the HttpHandler object.
     *
     * @param string $name The name of the method being called.
     * @param array $arguments The arguments passed to the method.
     * @return mixed The result of the method call.
     * @throws \BadMethodCallException If the method does not exist.
     */
    public function __call(string $name, array $arguments)
    { // Forward calls to the HttpHandler object (offsetSet, setRateLimit, etc.)
        if (method_exists($this->httpHandler, $name)) return call_user_func_array([$this->httpHandler, $name], $arguments);
        throw new \BadMethodCallException("Method {$name} does not exist.");
    }
}
