<?php
define("NC_SERVER", "localhost"); // https://server.domain (without "https://" attachments will not be created)
define("NC_USER", "deckbot");
define("NC_PASSWORD", "****"); // if your Nextcloud instance uses Two-Factor-Authentication, use generated token here instead of password.
define("MAIL_SERVER", "localhost"); // server.domain
define("MAIL_SERVER_FLAGS", "/novalidate-cert"); // flags needed to connect to server. Refer to https://www.php.net/manual/en/function.imap-open.php for a list of valid flags.
define("MAIL_SERVER_PORT", "143");
define("MAIL_SERVER_SMTPPORT", "587"); // port for outgoing smtp server. Actually only used to configure Docker image outgoing SMTP Server
define("MAIL_USER", "incoming");
define("MAIL_PASSWORD", "****");
define("DECODE_SPECIAL_CHARACTERS", true); //requires mbstring, if false special characters (like öäüß) won't be displayed correctly
define("ASSIGN_SENDER", true); // if true, sender will be assigned to card if has NC account
define("MAIL_NOTIFICATION", ""); // if non-empty, send notification to this address when an error occured
define("DELETE_MAIL_AFTER_PROCESSING", false);
define("IGNORE_INLINE_ATTACHMENTS", false); // if true, inline attachments will be dropped (cannot be rendered inline)
define("DEFAULT_BOARD_NAME", "testboard");
define("DEFAULT_DECK_NAME", "anhören");
define("FILTER_DATE_BEGIN", ""); // skip mails older than this (e.g. 2024-01-01)
define("FILTER_DATE_END", ""); // skip mails newer than this (e.g. 2024-01-01)