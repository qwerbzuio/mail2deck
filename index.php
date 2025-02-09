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

function get_attachment($part, $parttext)
{
    $attachment = array(
        'is_attachment' => false,
        'filename' => '',
        'name' => '',
        'attachment' => ''
    );
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

    if ($attachment['is_attachment']) {
        $attachment['attachment'] = $parttext;
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

for ($iemail = 0; $iemail < count($emails) && $iemail < $bunchsize; $iemail++) {
    $email = $emails[$iemail];
    $structure = $inbox->fetchMessageStructure($email);
    $attachments = array();
    $attNames = array();
    $body = "";
    if (isset($structure->parts) && count($structure->parts)) {
        $nparts = count($structure->parts);
        if ($nparts > 2) {
            print("nparts > 2\n");
            # Error
        }
        for ($ipart = 0; $ipart < $nparts; $ipart++) {
            $attachments[$ipart]['is_attachment'] = false;
            $part = $structure->parts[$ipart];
            if (strtolower($part->subtype) == 'mixed') {
                $mixedparts = $part->parts;
                $nmixedparts = count($mixedparts);
                for ($imixed = 0; $imixed < $nmixedparts; ++$imixed) {
                    $parttext = $inbox->fetchMessageBody($email, sprintf("%d.%d", $ipart + 1, $imixed + 1));
                    if ($part->encoding == 3) { // 3 = BASE64
                        $parttext = base64_decode($parttext);
                    } elseif ($part->encoding == 4) { // 4 = QUOTED-PRINTABLE
                        $parttext = DECODE_SPECIAL_CHARACTERS ? quoted_printable_decode($parttext) : $parttext;
                    }
                    $subtype = strtolower($mixedparts[$imixed]->subtype);
                    if ($subtype == 'html') {
                        $parttext = (new ConvertToMD($parttext))->execute();
                        $body .= $parttext;
                    } else if ($subtype == 'plain') {
                        $body .= $parttext;
                    } else {
                        array_push($attachments, get_attachment($mixedparts[$imixed], $parttext));
                    }
                }
                # stop processing after mixed parts:
                break;
            }
        }
    }
    if ($body == "") {
        print("Empty body\n");
        $body = $inbox->fetchMessageBody($email, 1);
    }

    continue;

    foreach ($attachments as $attachment) {
        if (! file_exists(getcwd() . '/attachments')) {
            mkdir(getcwd() . '/attachments');
        }
        if ($attachment['is_attachment'] == 1) {
            $filename = $attachment['name'];
            if (empty($filename)) $filename = $attachment['filename'];

            $fp = fopen(getcwd() . '/attachments/' . $filename, "w");
            fwrite($fp, $attachment['attachment']);
            fclose($fp);
            array_push($attNames, $attachment['filename']);
        }
    }

    $overview = $inbox->headerInfo($email);
    $board = null;
    if (isset($overview->{'X-Original-To'}) && strstr($overview->{'X-Original-To'}, '+')) {
        $board = strstr(substr($overview->{'X-Original-To'}, strpos($overview->{'X-Original-To'}, '+') + 1), '@', true);
    } else if (strstr($overview->to[0]->mailbox, '+')) {
        $board = substr($overview->to[0]->mailbox, strpos($overview->to[0]->mailbox, '+') + 1);
    };

    $data = new stdClass();
    $data->title = DECODE_SPECIAL_CHARACTERS ? mb_decode_mimeheader($overview->subject) : $overview->subject;
    $data->type = "plain";
    $data->order = -time();
    $data->description = $body;
    $data->attachments = null;
    if (count($attachments)) {
        $data->attachments = $attNames;
    }

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
        foreach ($attNames as $attachment) unlink(getcwd() . "/attachments/" . $attachment);
    }

    //remove email after processing
    if (DELETE_MAIL_AFTER_PROCESSING) {
        $inbox->delete($email);
    }
}
