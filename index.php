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

function get_attachment($part, $inbox, $email) {
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
        }
        elseif ($part->encoding == 4) { // 4 = QUOTED-PRINTABLE
            $attachment['attachment'] = quoted_printable_decode($attachment['attachment']);
        }
    }
    return $attachment;
}

if(!$emails) {
    // delete all messages marked for deletion and return
    $inbox->expunge();
    print_r("no mail\n");
    return;
}

for ($j = 0; $j < count($emails) && $j < 5; $j++) {
    $structure = $inbox->fetchMessageStructure($emails[$j]);
    $base64encode = false;
    if($structure->encoding == 3) {
        $base64encode = true; // BASE64
    }
    $attachments = array();
    $attNames = array();
    if (isset($structure->parts) && count($structure->parts)) {
    	printf("number of parts: %d\n", count($structure->parts));
        for ($i = 0; $i < count($structure->parts); $i++) {
            $attachments[$i]['is_attachment'] = false;
	    $part = $structure->parts[$i];
	    if (strtolower($part->subtype) == 'mixed') {
		$mixedparts = $part->parts;
	    	printf("number of mixed parts: %d\n", count($mixedparts));
		print_r($mixedparts);
		foreach($mixedparts as $part) {
			if (strtolower($part->subtype) == 'html') {
    				$body = $inbox->fetchMessageBody($emails[$j], 1.2);
				break;
			}
	    		array_push($attachments, get_attachment($part, $inbox, $emails[$j]));
		}
		# stop processing after mixed parts:
		break;
	    }
        }
    }

    for ($i = 0; $i < count($attachments); $i++) {
        if(! file_exists(getcwd() . '/attachments')) {
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
    if(isset($overview->{'X-Original-To'}) && strstr($overview->{'X-Original-To'}, '+')) {
        $board = strstr(substr($overview->{'X-Original-To'}, strpos($overview->{'X-Original-To'}, '+') + 1), '@', true);
    } else {
        if(strstr($overview->to[0]->mailbox, '+')) {
            $board = substr($overview->to[0]->mailbox, strpos($overview->to[0]->mailbox, '+') + 1);
        }
    };

    if($board && strstr($board, '+')) $board = str_replace('+', ' ', $board);

    $data = new stdClass();
    $data->title = DECODE_SPECIAL_CHARACTERS ? mb_decode_mimeheader($overview->subject) : $overview->subject;
    $data->type = "plain";
    $data->order = -time();
    $data->attachments = null;
    $body = $inbox->fetchMessageBody($emails[$j], sprintf("%d.%d", 2, 1));
    if ($body == "") {
        $body = $inbox->fetchMessageBody($emails[$j], 1);
    }
    if(count($attachments)) {
        $data->attachments = $attNames;
        $description = DECODE_SPECIAL_CHARACTERS ? quoted_printable_decode($body) : $body;
    } else {
        $description = DECODE_SPECIAL_CHARACTERS ? quoted_printable_decode($body) : $body;
    }
    if($base64encode) {
        $description = base64_decode($description);
    }
    if($description != strip_tags($description)) {
	# print_r($description);
	# print_r(strip_tags($description));
        $description = (new ConvertToMD($description))->execute();
	$description = strip_tags($description);
	print_r($description);
    }
    $data->description = $description;

    $mailSender = new stdClass();
    $mailSender->userId = $overview->reply_to[0]->mailbox;

    # return;
    continue;

    $board = "testboard";

    $newcard = new DeckClass();
    $response = $newcard->addCard($data, $mailSender, $board);
    # $mailSender->origin .= "{$overview->reply_to[0]->mailbox}@{$overview->reply_to[0]->host}";

    if(MAIL_NOTIFICATION) {
        if($response) {
            $inbox->reply($mailSender->origin, $response);
        } else {
            $inbox->reply($mailSender->origin);
        }
    }
    if(!$response) {
        foreach($attNames as $attachment) unlink(getcwd() . "/attachments/" . $attachment);
    }

    //remove email after processing
    if(DELETE_MAIL_AFTER_PROCESSING) {
        $inbox->delete($emails[$j]);
    }
}
?>
