<?php
error_reporting(E_ERROR | E_PARSE);
error_reporting(E_ALL);
ini_set('display_errors', 'on');
require_once(__DIR__ . '/vendor/autoload.php');
require_once(__DIR__ . '/config.php');

use Mail2Deck\MailClass;
use Mail2Deck\DeckClass;
use Mail2Deck\ConvertToMD;

class Mail2DeckException extends Exception
{
    public $sender = '';
    public $subject = '';
}

function get_part($inbox, $email, $part_id_array, $is_alternative)
{
    $content = array(
        'is_attachment' => false,
        'filename' => '',
        'name' => '',
        'content' => '',
        'alternative' => '',
    );

    $part = $inbox->fetchMessageStructure($email);
    foreach ($part_id_array as $ipart) { // select subpart
        $part = $part->parts[$ipart];
    }

    $spec_array = array_map(fn($id): int => $id + 1, $part_id_array);
    $partspec = join(".", $spec_array);
    $parttext = $inbox->fetchMessageBody($email, $partspec);

    if ($part->encoding == 3) { // 3 = BASE64
        $parttext = base64_decode($parttext);
    } elseif ($part->encoding == 4) { // 4 = QUOTED-PRINTABLE
        $parttext = DECODE_SPECIAL_CHARACTERS ? quoted_printable_decode($parttext) : $parttext;
    }

    $encoding = 'UTF-8';
    if ($part->ifparameters) {
        foreach ($part->parameters as $object) {
            $attribute = strtolower($object->attribute);
            if ($attribute == 'name') {
                $content['is_attachment'] = true;
                $content['name'] = $object->value;
            } elseif ($attribute == 'charset') {
                $encoding = $object->value;
            }
        }
    }

    if ($part->ifdparameters) {
        foreach ($part->dparameters as $object) {
            if (strtolower($object->attribute) == 'filename') {
                $content['is_attachment'] = true;
                $content['filename'] = $object->value;
            }
        }
    }

    $subtype = strtolower($part->subtype);

    if ($subtype == 'html') {
        $parttext = mb_convert_encoding($parttext, "UTF-8", $encoding);
        preg_match('/<body[^>]*>(.*?)<\/body>/is', $parttext, $matches);
        if ($matches) {
            $parttext = $matches[1];
        }
        $parttext = (new ConvertToMD($parttext))->execute();
    } elseif ($subtype == 'plain') {
        $parttext = mb_convert_encoding($parttext, "UTF-8", $encoding);
        $nlines = $part->lines;
        $lines = explode("\n", $parttext);
        $lines = array_slice($lines, -$nlines);
        $parttext = implode("\n", $lines);
    }

    if ($is_alternative) {
        $content['alternative'] = $parttext;
    } else {
        $content['content'] = $parttext;
    }

    return $content;
}

function process_parts($inbox, $email, $part_id_array = array(), $is_alternative = false)
{
    $contents = array();

    $part = $inbox->fetchMessageStructure($email);
    foreach ($part_id_array as $ipart) { // select subpart
        $part = $part->parts[$ipart];
    }

    $subtype = strtolower($part->subtype);
    if ($subtype == 'mixed' || $subtype == 'related') {
        $nparts = count($part->parts);
        for ($imixed = 0; $imixed < $nparts; ++$imixed) { // process all subparts
            $subpart_id_array = $part_id_array;
            array_push($subpart_id_array, $imixed);
            $subcontents = process_parts($inbox, $email, $subpart_id_array, $is_alternative);
            if (IGNORE_INLINE_ATTACHMENTS) {
                // ignore inline-attachment parts (cannot be rendered in markdown)
                $subcontents = array_filter($subcontents, function ($content) {
                    return !$content['is_attachment'];
                });
            }
            $contents = array_merge($contents, $subcontents);
        }
    } elseif ($subtype == 'alternative') {
        // set last part as main content
        $lastpart = count($part->parts) - 1;
        $last_part_id_array = $part_id_array;
        array_push($last_part_id_array, $lastpart);
        $subcontents = process_parts($inbox, $email, $last_part_id_array, false);
        $contents = array_merge($contents, $subcontents);
        // set first part as alternative
        $first_part_id_array = $part_id_array;
        array_push($first_part_id_array, 0);
        $subcontents = process_parts($inbox, $email, $first_part_id_array, true);
        $contents = array_merge($contents, $subcontents);
    } else {
        array_push($contents, get_part($inbox, $email, $part_id_array, $is_alternative));
    }

    return $contents;
}

function extract_attachments($contents)
{
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

function extract_description($contents, $fromaddress, $is_alternative)
{
    $description = "";
    foreach ($contents as $content) {
        if ($content['is_attachment']) {
            continue;
        }
        if ($is_alternative) {
            $description .= $content['alternative'];
        } else {
            $description .= $content['content'];
        }
    }
    $description = sprintf("(From: <%s>)\n\n%s", $fromaddress, $description);
    if (strlen($description) > 6272) {
        print("WARNING: description length exceeds 6272\n");
        // $description = substr($description, 0, 4096);
    }
    return $description;
}

function process_mail($email, $inbox)
{
    $overview = $inbox->headerInfo($email);

    $datestamp = strtotime($overview->date);
    if (FILTER_DATE_BEGIN) {
        if ($datestamp < strtotime(FILTER_DATE_BEGIN)) {
            printf("Skipping too old mail from %s\n", $overview->date);
            return;
        }
    }
    if (FILTER_DATE_END) {
        if ($datestamp >= strtotime(FILTER_DATE_END)) {
            printf("Skipping too new mail from %s\n", $overview->date);
            return;
        }
    }

    $part = $inbox->fetchMessageStructure($email);
    // print_r($part);

    $contents = process_parts($inbox, $email);
    // print_r($contents);

    // add fromaddress on top
    $from = $overview->from[0];
    $fromaddress = sprintf("%s@%s", $from->mailbox, $from->host);

    $data = new stdClass();
    $data->title = DECODE_SPECIAL_CHARACTERS ? mb_decode_mimeheader($overview->subject) : $overview->subject;
    $data->type = "plain";
    $data->order = -time();
    $data->description = extract_description($contents, $fromaddress, false);
    $data->attachments = extract_attachments($contents);
    $data->duedate = $overview->date;

    // return;

    $mailSender = new stdClass();
    $mailSender->userId = $overview->reply_to[0]->mailbox;

    $board = DEFAULT_BOARD_NAME;
    $stack = DEFAULT_DECK_NAME;

    $newcard = new DeckClass();
    $stackid = $newcard->getStackID($board, $stack);
    if (!$stackid) {
        throw new Exception(sprintf("Could not access stack '%s' on board '%s'.", $stack, $board));
    }

    try {
        $response = $newcard->addCard($data, $mailSender, $board, $stackid);
    } catch (Exception $e) {
        printf("Could not add card for mail '%s' from '%s':\n  ", $data->title, $fromaddress);
        print("\n  Trying again with alternative message representation...\n");
        $data->description = extract_description($contents, $fromaddress, true);
        // print($data->description);
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

    //remove email after processing
    if (DELETE_MAIL_AFTER_PROCESSING) {
        $inbox->delete($email);
    }
}

function process_mails($argv)
{
    $inbox = new MailClass();
    $emails = $inbox->getNewMessages();

    if (!$emails) {
        // delete all messages marked for deletion and return
        $inbox->expunge();
        print("no mail\n");
        return;
    }

    $startmail = 0;
    $bunchsize = null;

    # for testing
    if (count($argv) > 1) {
        $startmail = $argv[1];
    }
    if (count($argv) > 2) {
        $bunchsize = $argv[2];
    }

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

process_mails($argv);
