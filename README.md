# mail2deck

This is an overhauled version of the upstream version in order to serve a different usecase: We use NC as a poor-man's ticket system: people send us mails and we create deck cards from the mails. 

* I added various configuration options for that use case: Look into config.example.php for some explanation.
* Due to the very different type of incoming mails I had to improve robustness in various codeparts. 
* On the other hand, we don't want to set stack, board, assigned user, or due date by some keyword in the subject text. I removed this feature.
* Also, I have no sudo-rights on the server, so I used PHPMailer which doesn't need that.

Provides an "email in" solution for the Nextcloud Deck app

## Assign Deck Bot to the board.
* Deck Bot is the user who will create the cards and it must be set up by your nextcloud admin.
* Deck Bot must be assigned and must have edit permission inside the board.

# ⚙️ B. For NextCloud admins to setup
## Requirements
This app requires php-curl, php-mbstring, php-imap.

## NC new user
Create a new user from User Management on your NC server, which shall to function as a bot to post cards received as mail. We chose to call it *deckbot*, but you can call it whatever you want.<br>
__Note__: that you have to give *deckbot* permissions on each board you want to add new cards from email.

## Download and install
```
git clone https://github.com/newroco/mail2deck.git mail2deck
```

Create config.php file and edit it for your needs: 
```
cd mail2deck
cp config.example.php config.php
vim config.php
```

*You can refer to https://www.php.net/manual/en/function.imap-open.php for setting the value of MAIL_SERVER_FLAGS*

## Add a cronjob to run mail2deck.
```
crontab -e
```
Add the following line in the opened file (in this example, it runs every 5 minutes):
<code>*/5 * * * * /usr/bin/php /home/incoming/mail2deck/index.php >/dev/null 2>&1</code>

Now __mail2deck__ will add new cards every five minutes if new emails are received.
