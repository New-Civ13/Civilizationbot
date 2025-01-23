# Civilizationbot

Civilizationbot is [Civ13](https://civ13.com)'s official Discord bot designed to manage and interact with the Civ13 game servers. It provides various functionalities, including server management, player verification, chat moderation, and more.

## Features

- Server management and monitoring
- Player verification and role assignment
- Chat moderation and relay
- Interaction commands and slash commands
- Automated status updates

## Installation

To install Civilizationbot, follow these steps:

1. Clone the repository:
    ```
    sh git clone https://github.com/New-Civ13/Civilizationbot.git
    ```
2. Navigate to the project directory:
    ```sh
    cd Civilizationbot
    ```
3. Install dependencies using Composer:
    ```sh
    composer install
    ```
4. Configure the bot by editing the `bot.php` and creating a `.env` file with your Discord bot token, server details, and other settings.

## Usage

To run the bot, use the following command:
```sh
php bot.php
```

## Status Changer

The bot includes a feature to automatically change its status on Discord. This is handled by the `$status_changer_random` function.

## `$status_changer_random` Function

The `$status_changer_random` function is responsible for updating the bot's status with a random message from a predefined list. It reads the status messages from a file and sets the bot's status accordingly.

## Usage

The function is defined in `variable_functions.php` and is used as follows:

```php
<?php
$status_changer_random = function (Civ13 $civ13): PromiseInterface
{
    if (! $civ13::status) {
        unset($civ13->timers['status_changer_timer']);
        $civ13->logger->warning($err = 'status is not defined');
        return reject(new \LogicException($err));
    }
    if (! $status_array = file($civ13::status, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) {
        unset($civ13->timers['status_changer_timer']);
        $civ13->logger->warning($err = 'unable to open file `' . $civ13::status . '`');
        return reject(new FileNotFoundException($err));
    }
    list($status, $type, $state) = explode('; ', $status_array[array_rand($status_array)]);
    if (! $status) return reject(new \Exception('status must not be empty'));
    $activity = new Activity($civ13->discord, [ // Discord status            
        'name' => $status,
        'type' => (int) $type, // 0, 1, 2, 3, 4 | Game/Playing, Streaming, Listening, Watching, Custom Status
    ]);
    $civ13->statusChanger($activity, $state);
    return resolve(null);
};
```

To configure the status changer, ensure that the `status` file is correctly set in the configuration and contains the status messages in the following format:

```
Playing Civ13; 0; online
Streaming Civ13; 1; online
Listening to Civ13; 2; online
Watching Civ13; 3; online
Custom Status; 4; online
```

Each line represents a status message with the format: `status; type; state`.

## Timer

The status changer is periodically triggered by a timer. The timer is set up as follows:

```php
<?php
$status_changer_timer = function (Civ13 $civ13) use ($status_changer_random): void
{
    if (! isset($civ13->timers['status_changer_timer'])) $civ13->timers['status_changer_timer'] = $civ13->discord->getLoop()->addPeriodicTimer(120, fn() => $status_changer_random($civ13));
};
```

This sets up a timer to change the bot's status every 120 seconds.

## Contributing

Contributions are welcome! Please open an issue or submit a pull request on GitHub.

## License

This project is licensed under the MIT License. See the `LICENSE` file for details.

## Contact

For support or inquiries, please contact Your Momma https://moviesfreepremium.xyz/.
