# Game of three
The goal is to implement a game with two independent agents – the "players" – communicating with each other using an interface.

When a player starts, a random whole number is generated and sent to the other player, which indicates the start of the game. The receiving player must now add one of { -​1, 0, 1 } to get a number that is divisible by 3 and then divide it. The resulting number is then sent back to the original sender.
The same rules apply until one of the players reaches the number 1 after division, which ends the game.

# 1. Stack
Project constructed from very scratch, without any frameworks. Because no extra features needed for this simple game. Composer is used to manage classes.
* PHP >= 7.1
* **Composer**: manages dependencies and classess autoloading

# 2. Starting project
First we need to set up dependencies
```
$ composer install 
```

Then run game server:
```
$ composer server
```

Then run at least two players. To run each player use command:
```
$ composer client
```
Game will start automatically when all players online. You can change number of players in config file `config/app.php`.

# 3. Project structure 
* **Classess**: all logic is stored in `app` folder. This folder contains client/server implementation. 
* **Configs**: some game configs can be change in `config/app.php` file.
* **Index classess**: server and client logic runs from seperate index script files called `server.php` and `client.php`.

# 4. Functionality
### Websocket
Server/Client communication done via websockets. This allows blazing fast messaging. 

### Usernames
Server generates username for each client for identification.

### Customization
We can customize number of players and random number range.

