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

function get_attachment($part, $inbox, $email)
{
    $attachment = array();
    $attachment['is_attachment'] = false;
    if ($part->ifdparameters) {
        foreach ($part->dparameters as $object) {
            if (strtolower($object->attribute) == 'filename') {
                $attachment['is_attachment'] = true;
                $attachment['filename'] = $object->value;
                printf("filename: %s\n", $object->value);
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

    if ($attachment['is_attachment']) {
        # $attachment['attachment'] = $inbox->fetchMessageBody($email, $i+1);
        $attachment['attachment'] = "";
        if ($part->encoding == 3) { // 3 = BASE64
            $attachment['attachment'] = base64_decode($attachment['attachment']);
        } elseif ($part->encoding == 4) { // 4 = QUOTED-PRINTABLE
            $attachment['attachment'] = quoted_printable_decode($attachment['attachment']);
        }
    }
    return $attachment;
}

if (!$emails) {
    // delete all messages marked for deletion and return
    $inbox->expunge();
    print_r("no mail\n");
    return;
}

$bunchsize = 5;

for ($j = 0; $j < count($emails) && $j < $bunchsize; $j++) {
    $structure = $inbox->fetchMessageStructure($emails[$j]);
    $base64encode = false;
    if ($structure->encoding == 3) {
        $base64encode = true; // BASE64
    }
    $attachments = array();
    $attNames = array();
    $body = "";
    if (isset($structure->parts) && count($structure->parts)) {
        $nparts = count($structure->parts);
        if ($nparts > 2) {
            # Error
        }
        for ($ipart = 0; $ipart < $nparts; $ipart++) {
            $attachments[$ipart]['is_attachment'] = false;
            $part = $structure->parts[$ipart];
            if (strtolower($part->subtype) == 'mixed') {
                $mixedparts = $part->parts;
                $nmixedparts = count($mixedparts);
                for ($imixed = 0; $imixed < $nmixedparts; ++$imixed) {
                    $parttext = $inbox->fetchMessageBody($emails[$j], sprintf("%d.%d", $ipart, $imixed));
                    print($parttext);
                    $parttext = DECODE_SPECIAL_CHARACTERS ? quoted_printable_decode($parttext) : $parttext;
                    if ($base64encode) {
                        $parttext = base64_decode($parttext);
                    }
                    $subtype = strtolower($mixedparts[$imixed]->subtype);
                    if ($subtype == 'html') {
                        $parttext = (new ConvertToMD($parttext))->execute();
                        $body .= $parttext;
                    } else if ($subtype == 'plain') {
                        $body .= $parttext;
                    } else {
                        // array_push($attachments, get_attachment($mixedparts[$imixed], $inbox, $emails[$j]));
                    }
                }
                # stop processing after mixed parts:
                break;
            }
        }
    }

    for ($i = 0; $i < count($attachments); $i++) {
        if (! file_exists(getcwd() . '/attachments')) {
            mkdir(getcwd() . '/attachments');
        }
        if ($attachments[$i]['is_attachment'] == 1) {
            $filename = $attachments[$i]['name'];
            if (empty($filename)) $filename = $attachments[$i]['filename'];

            $fp = fopen(getcwd() . '/attachments/' . $filename, "w+");
            fwrite($fp, $attachments[$i]['attachment']);
            fclose($fp);
            array_push($attNames, $attachments[$i]['filename']);
        }
    }

    $overview = $inbox->headerInfo($emails[$j]);
    $board = null;
    if (isset($overview->{'X-Original-To'}) && strstr($overview->{'X-Original-To'}, '+')) {
        $board = strstr(substr($overview->{'X-Original-To'}, strpos($overview->{'X-Original-To'}, '+') + 1), '@', true);
    } else {
        if (strstr($overview->to[0]->mailbox, '+')) {
            $board = substr($overview->to[0]->mailbox, strpos($overview->to[0]->mailbox, '+') + 1);
        }
    };

    print($body);

    $data = new stdClass();
    $data->title = DECODE_SPECIAL_CHARACTERS ? mb_decode_mimeheader($overview->subject) : $overview->subject;
    $data->type = "plain";
    $data->order = -time();
    $data->attachments = null;
    # $body = $inbox->fetchMessageBody($emails[$j], sprintf("%d.%d", 2, 1));
    if ($body == "") {
        $body = $inbox->fetchMessageBody($emails[$j], 1);
    }
    if (count($attachments)) {
        $data->attachments = $attNames;
    }

    # print($body);

    $description = DECODE_SPECIAL_CHARACTERS ? quoted_printable_decode($body) : $body;
    if ($base64encode) {
        $description = base64_decode($description);
    }
    if ($description != strip_tags($description)) {
        $description = (new ConvertToMD($description))->execute();
    }
    $data->description = $description;

    $mailSender = new stdClass();
    $mailSender->userId = $overview->reply_to[0]->mailbox;

    continue;

    $board = "testboard";

    $newcard = new DeckClass();
    $response = $newcard->addCard($data, $mailSender, $board);
    print_r($response);
    # $mailSender->origin .= "{$overview->reply_to[0]->mailbox}@{$overview->reply_to[0]->host}";

    if (MAIL_NOTIFICATION) {
        if ($response) {
            $inbox->reply($mailSender->origin, $response);
        } else {
            $inbox->reply($mailSender->origin);
        }
    }
    if (!$response) {
        foreach ($attNames as $attachment) unlink(getcwd() . "/attachments/" . $attachment);
    }

    //remove email after processing
    if (DELETE_MAIL_AFTER_PROCESSING) {
        $inbox->delete($emails[$j]);
    }
}
