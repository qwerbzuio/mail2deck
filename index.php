<?php
error_reporting(E_ERROR | E_PARSE);
error_reporting(E_ALL);
ini_set('display_errors', 'on');
require_once(__DIR__ . '/vendor/autoload.php');
require_once(__DIR__ . '/config.php');

use Mail2Deck\MailClass;
use Mail2Deck\DeckClass;
use Mail2Deck\ConvertToMD;
use ZBateson\MailMimeParser\Message;
// use PHPMailer\PHPMailer\PHPMailer;
// use PHPMailer\PHPMailer\Exception;

// require 'vendor/phpmailer/phpmailer/src/Exception.php';
// require 'vendor/phpmailer/phpmailer/src/PHPMailer.php';
// require 'vendor/phpmailer/phpmailer/src/SMTP.php';

class Mail2DeckException extends Exception
{
    public $sender = '';
    public $subject = '';
}

function decode_special_chars($text)
{
    return DECODE_SPECIAL_CHARACTERS ? mb_decode_mimeheader($text) : $text;
}

function extract_attachments($message)
{
    $attachments = array();
    if (! file_exists(getcwd() . '/attachments')) {
        mkdir(getcwd() . '/attachments');
    }
    foreach ($message->getAllAttachmentParts() as $attachment) {
        array_push($attachments, $attachment->getFilename());
        $attachment->saveContent(getcwd() . '/attachments/' . $attachment->getFilename());
    }
    return $attachments;

    $attachments = array();
    foreach ($contents as $content) {
        if (!$content['is_attachment']) {
            continue;
        }
        if (! file_exists(getcwd() . '/attachments')) {
            mkdir(getcwd() . '/attachments');
        }
        $filename = $content['name'];
        if (empty($filename)) $filename = $content['filename'];

        $fp = fopen(getcwd() . '/attachments/' . $filename, "w");
        fwrite($fp, $content['content']);
        fclose($fp);
        array_push($attachments, $content['filename']);
    }
    return $attachments;
}

function process_mail($email, $inbox)
{
    // use an instance of MailMimeParser as a class dependency
    $raw = $inbox->fetchMessageBody($email, "");
    $message = Message::from($raw, false);
    $fromaddress = $message->getHeaderValue('From');
    $date = $message->getHeaderValue('Date');
    $subject = $message->getHeaderValue('Subject');
    $html = $message->getHtmlContent();
    $plaintext = $message->getTextContent();
    // replace hyperlinks
    $plaintext = preg_replace(
        '#((https?|ftp)://(\S*?\.\S*?))([\s)\[\]{},;"\':<]|\.\s|$)#i',
        "[$1]($1)",
        $plaintext
    );

    $datestamp = strtotime($date);
    if (FILTER_DATE_BEGIN) {
        if ($datestamp < strtotime(FILTER_DATE_BEGIN)) {
            printf("Skipping too old mail from %s\n", $date);
            return;
        }
    }
    if (FILTER_DATE_END) {
        if ($datestamp >= strtotime(FILTER_DATE_END)) {
            printf("Skipping too new mail from %s\n", $date);
            return;
        }
    }

    // extract body part and convert to markdown
    if ($html) {
        preg_match('/<body[^>]*>(.*?)<\/body>/is', $html, $matches);
        if ($matches) {
            $html = $matches[1];
        }
        $mdtext = (new ConvertToMD($html))->execute();
    } else {
        print("Warning: Detected empty HTML, using plain part instead.\n");
        $mdtext = $plaintext;
    }

    $mdtext = sprintf("(From: <%s>)\n\n%s", $fromaddress, $mdtext);

    $data = new stdClass();
    $data->title = $subject;
    $data->type = "plain";
    $data->order = -time();
    $data->description = $mdtext;
    $data->attachments = extract_attachments($message);
    $data->duedate = $date;

    $mailSender = new stdClass();
    // $mailSender->userId = $overview->reply_to[0]->mailbox;

    $board = DEFAULT_BOARD_NAME;
    $stack = DEFAULT_DECK_NAME;

    $newcard = new DeckClass();
    $stackid = $newcard->getStackID($board, $stack);
    if (!$stackid) {
        throw new Mail2DeckException(sprintf("Could not access stack '%s' on board '%s'.", $stack, $board));
    }

    try {
        $response = $newcard->addCard($data, $mailSender, $board, $stackid);
    } catch (Exception $e) {
        printf("Could not add card for mail '%s' from '%s'\n", $data->title, $fromaddress);
        print("  Trying again with alternative message representation...\n");
        $data->description = sprintf("(From: <%s>)\n\n%s", $fromaddress, $plaintext);
        try {
            $response = $newcard->addCard($data, $mailSender, $board, $stackid);
        } catch (Exception $e) {
            print("  ... still not possible. Giving up on this.\n");
            $newex = new Mail2DeckException($e->getMessage());
            $newex->sender = $fromaddress;
            $newex->subject = $data->title;
            throw $newex;
        }
    }

    // print_r($response);
    // $mailSender->origin .= "{$overview->reply_to[0]->mailbox}@{$overview->reply_to[0]->host}";

    if (!$response) {
        foreach ($data->attachments as $attachment) unlink(getcwd() . "/attachments/" . $attachment);
    }
}

function process_mail_bunch($startmail, $bunchsize)
{
    $inbox = new MailClass();

    $which = 'UNSEEN';
    $which = 'ALL'; // for initialization
    $emails = $inbox->getNewMessages($which);

    $emails_todo = array_slice($emails, $startmail, $bunchsize);
    $iemail = $startmail;
    foreach ($emails_todo as $email) {
        printf("%d\n", $iemail++);
        try {
            process_mail($email, $inbox);
        } catch (Mail2DeckException $e) {
            if (MAIL_NOTIFICATION) {
                $subject = sprintf("Could not process mail '%s' from '%s'", $e->subject, $e->sender);
                $message = $e->getMessage();
                printf("%s:\n%s\n", $subject, $message);
                printf("Sending mail about failure to %s\n", MAIL_NOTIFICATION);
                // $inbox->sendmail(MAIL_NOTIFICATION, $subject, $message);
            }
            return;
        }
    }
}

function process_mails($argv)
{
    $startmail = 0;
    $bunchsize = null;

    # for testing
    if (count($argv) > 1) {
        $startmail = $argv[1];
    }
    if (count($argv) > 2) {
        $bunchsize = $argv[2];
    }

    process_mail_bunch($startmail, $bunchsize);
}

process_mails($argv);
