<?php
error_reporting(E_ERROR | E_PARSE);
error_reporting(E_ALL);
ini_set('display_errors', 'on');
require_once(__DIR__ . '/vendor/autoload.php');
require_once(__DIR__ . '/config.php');

use Mail2Deck\MailClass;
use Mail2Deck\DeckClass;
use Mail2Deck\ConvertToMD;

$inbox = new MailClass();
$emails = $inbox->getNewMessages();

function get_part($inbox, $email, $part_id_array)
{
    $content = array(
        'is_attachment' => false,
        'filename' => '',
        'name' => '',
        'content' => ''
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
        // print_r($parttext);
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
        print("============================================ plain =========================\n");
    }

    $content['content'] = $parttext;

    return $content;
}

function process_parts($inbox, $email, $part_id_array = array())
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
            $subcontents = process_parts($inbox, $email, $subpart_id_array);
            // ignore inline-attachment parts (cannot be rendered in markdown)
            $subcontents = array_filter($subcontents, function ($content) {
                return !$content['is_attachment'];
            });
            $contents = array_merge($contents, $subcontents);
        }
    } elseif ($subtype == 'alternative') {
        $lastpart = count($part->parts) - 1; // select only last part
        array_push($part_id_array, $lastpart);
        $contents = process_parts($inbox, $email, $part_id_array);
    } else {
        array_push($contents, get_part($inbox, $email, $part_id_array));
    }

    return $contents;
}

if (!$emails) {
    // delete all messages marked for deletion and return
    $inbox->expunge();
    print("no mail\n");
    return;
}

$startmail = 96;
$bunchsize = 1;

for ($iemail = $startmail; $iemail < count($emails) && $iemail < $startmail + $bunchsize; $iemail++) {
    print($iemail);
    $email = $emails[$iemail];
    $overview = $inbox->headerInfo($email);
    // print_r($overview);

    $part = $inbox->fetchMessageStructure($email);
    // print_r($part);

    $contents = process_parts($inbox, $email);

    $data = new stdClass();
    $data->title = DECODE_SPECIAL_CHARACTERS ? mb_decode_mimeheader($overview->subject) : $overview->subject;
    $data->type = "plain";
    $data->order = -time();
    $data->description = "";
    $data->attachments = array();

    foreach ($contents as $content) {
        if ($content['is_attachment']) {
            if (! file_exists(getcwd() . '/attachments')) {
                mkdir(getcwd() . '/attachments');
            }
            $filename = $content['name'];
            if (empty($filename)) $filename = $content['filename'];

            $fp = fopen(getcwd() . '/attachments/' . $filename, "w");
            fwrite($fp, $content['content']);
            fclose($fp);
            array_push($data->attachments, $content['filename']);
        } else {
            $data->description .= $content['content'];
        }
    }

    // add fromadress on top
    $from = $overview->from[0];
    $fromaddress = sprintf("%s@%s", $from->mailbox, $from->host);
    $data->description = sprintf("(From: %s)\n\n%s", $fromaddress, $data->description);

    $board = null;
    if (isset($overview->{'X-Original-To'}) && strstr($overview->{'X-Original-To'}, '+')) {
        $board = strstr(substr($overview->{'X-Original-To'}, strpos($overview->{'X-Original-To'}, '+') + 1), '@', true);
    } else if (strstr($overview->to[0]->mailbox, '+')) {
        $board = substr($overview->to[0]->mailbox, strpos($overview->to[0]->mailbox, '+') + 1);
    };

    // continue;

    $mailSender = new stdClass();
    $mailSender->userId = $overview->reply_to[0]->mailbox;

    $board = "testboard";
    // print_r($data);

    $newcard = new DeckClass();
    $response = $newcard->addCard($data, $mailSender, $board);

    // print_r($response);
    // $mailSender->origin .= "{$overview->reply_to[0]->mailbox}@{$overview->reply_to[0]->host}";

    if (MAIL_NOTIFICATION) {
        if ($response) {
            $inbox->reply($mailSender->origin, $response);
        } else {
            $inbox->reply($mailSender->origin);
        }
    }

    if (!$response) {
        foreach ($data->attachments as $attachment) unlink(getcwd() . "/attachments/" . $attachment);
    }

    //remove email after processing
    if (DELETE_MAIL_AFTER_PROCESSING) {
        $inbox->delete($email);
    }
}
