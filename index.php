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
    $attachment = array(
        'is_attachment' => false,
        'filename' => '',
        'name' => '',
        'content' => ''
    );

    $part = $inbox->fetchMessageStructure($email);
    foreach ($part_id_array as $ipart) { # select subpart
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

    if (property_exists($part, "subtype")) {
        $subtype = strtolower($part->subtype);
    } else if (property_exists($part, "type")) {
        $subtype = strtolower($part->subtype);
    } else {
        print("Error\n");
        return $attachment;
    }

    if ($subtype == 'html') {
        $parttext = (new ConvertToMD($parttext))->execute();
    }

    if ($part->ifdparameters) {
        foreach ($part->dparameters as $object) {
            if (strtolower($object->attribute) == 'filename') {
                $attachment['is_attachment'] = true;
                $attachment['filename'] = $object->value;
            }
        }
    }

    if ($part->ifparameters) {
        foreach ($part->parameters as $object) {
            if (strtolower($object->attribute) == 'name') {
                $attachment['is_attachment'] = true;
                $attachment['name'] = $object->value;
            }
        }
    }

    $attachment['content'] = $parttext;

    return $attachment;
}

function process_parts($inbox, $email, $part_id_array = array())
{
    $contents = array();

    $part = $inbox->fetchMessageStructure($email);
    foreach ($part_id_array as $ipart) { # select subpart
        $part = $part->parts[$ipart];
    }

    $subtype = strtolower($part->subtype);
    printf("Processing %s part: %s\n", $subtype, join(".", $part_id_array));
    if ($subtype == 'mixed' || $subtype == 'related') {
        $nparts = count($part->parts);
        for ($imixed = 0; $imixed < $nparts; ++$imixed) { # process all subparts
            $subpart_id_array = $part_id_array;
            array_push($subpart_id_array, $imixed);
            array_merge($contents, process_parts($inbox, $email, $subpart_id_array));
        }
    } elseif ($subtype == 'alternative') { # select last part
        $lastpart = count($part->parts) - 1;
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

$bunchsize = 2;

for ($iemail = 1; $iemail < count($emails) && $iemail < $bunchsize; $iemail++) {
    $email = $emails[$iemail];
    $overview = $inbox->headerInfo($email);

    $part = $inbox->fetchMessageStructure($email);

    $parts = process_parts($inbox, $email);

    $data = new stdClass();
    $data->title = DECODE_SPECIAL_CHARACTERS ? mb_decode_mimeheader($overview->subject) : $overview->subject;
    $data->type = "plain";
    $data->order = -time();
    $data->description = "";
    $data->attachments = array();

    foreach ($parts as $part) {
        if ($part['is_attachment']) {
            if (! file_exists(getcwd() . '/attachments')) {
                mkdir(getcwd() . '/attachments');
            }
            $filename = $part['name'];
            if (empty($filename)) $filename = $part['filename'];

            $fp = fopen(getcwd() . '/attachments/' . $filename, "w");
            fwrite($fp, $part['content']);
            fclose($fp);
            array_push($data->attachments, $part['filename']);
        } else {
            $data->description .= $part['content'];
        }
    }

    $board = null;
    if (isset($overview->{'X-Original-To'}) && strstr($overview->{'X-Original-To'}, '+')) {
        $board = strstr(substr($overview->{'X-Original-To'}, strpos($overview->{'X-Original-To'}, '+') + 1), '@', true);
    } else if (strstr($overview->to[0]->mailbox, '+')) {
        $board = substr($overview->to[0]->mailbox, strpos($overview->to[0]->mailbox, '+') + 1);
    };

    # printf("%s\n", $data->title);
    print_r($data->description);
    continue;

    $mailSender = new stdClass();
    $mailSender->userId = $overview->reply_to[0]->mailbox;

    $board = "testboard";

    $newcard = new DeckClass();
    $response = $newcard->addCard($data, $mailSender, $board);

    # print_r($response);
    # $mailSender->origin .= "{$overview->reply_to[0]->mailbox}@{$overview->reply_to[0]->host}";

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
