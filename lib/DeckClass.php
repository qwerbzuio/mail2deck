<?php

namespace Mail2Deck;

class DeckClass {
    private $responseCode;

    private function apiCall($request, $endpoint, $data = null, $attachment = false, $userapi = false){
        $curl = curl_init();
        if($data && !$attachment) {
            $endpoint .= '?' . http_build_query($data);
        }
        curl_setopt_array($curl, array(
            CURLOPT_URL => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $request,
            CURLOPT_HTTPHEADER => array(
                'Authorization: Basic ' . base64_encode(NC_USER . ':' . NC_PASSWORD),
                'OCS-APIRequest: true',
            ),
        ));

        if ($request === 'POST') {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($curl, CURLOPT_HTTPHEADER, array_merge(
                array(
                    'Accept: application/json',
                    'OCS-APIRequest: true',
                    'Content-Type:  application/json',
                    'Authorization: Basic ' . base64_encode(NC_USER . ':' . NC_PASSWORD),
                )
            ));
        }

        $response = curl_exec($curl);
        $err = curl_error($curl);
        if($userapi) return simplexml_load_string($response);
        $this->responseCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        curl_close($curl);
        if($err) echo "cURL Error #:" . $err;

        return json_decode($response);
    }

    public function getParameters($params, $boardFromMail = null) {// get the board and the stack
	    if(!$boardFromMail) // if board is not set within the email address, look for board into email subject
        	if(preg_match('/b-"([^"]+)"/', $params, $m) || preg_match("/b-'([^']+)'/", $params, $m)) {
            		$boardFromMail = $m[1];
            		$params = str_replace($m[0], '', $params);
        	}else{
                $boardFromMail = NC_DEFAULT_BOARD;
            }
        if(preg_match('/s-"([^"]+)"/', $params, $m) || preg_match("/s-'([^']+)'/", $params, $m)) {
            $stackFromMail = $m[1];
            $params = str_replace($m[0], '', $params);
        }
        if(preg_match('/u-"([^"]+)"/', $params, $m) || preg_match("/u-'([^']+)'/", $params, $m)) {
            $userFromMail = $m[1];
            $params = str_replace($m[0], '', $params);
        }
        if(preg_match('/d-"([^"]+)"/', $params, $m) || preg_match("/d-'([^']+)'/", $params, $m)) {
            $duedateFromMail = $m[1];
            $params = str_replace($m[0], '', $params);
        }

        $boards = $this->apiCall("GET", NC_SERVER . "/index.php/apps/deck/api/v1.0/boards");
        $boardId = $boardName = null;
        foreach($boards as $board) {
            if(strtolower($board->title) == strtolower($boardFromMail)) {
                if(!$this->checkBotPermissions($board)) {
                    return false;
                }
                $boardId = $board->id;
                $boardName = $board->title;
                break;
            }
        }

        if($boardId) {
            $stacks = $this->apiCall("GET", NC_SERVER . "/index.php/apps/deck/api/v1.0/boards/$boardId/stacks");
            foreach($stacks as $key => $stack)
                if(strtolower($stack->title) == strtolower($stackFromMail)) {
                    $stackId = $stack->id;
                    break;
                }
                if($key == array_key_last($stacks) && !isset($stackId)) $stackId = $stacks[0]->id;
        } else {
            return false;
        }

        $boardStack = new \stdClass();
        $boardStack->board = $boardId;
        $boardStack->stack = $stackId;
        $boardStack->newTitle = $params;
        $boardStack->boardTitle = $boardName;
        $boardStack->userId = strtolower($userFromMail);
        $boardStack->dueDate = $duedateFromMail;


        return $boardStack;
    }

    //Add a new card
    public function addCard($data, $user, $board = null) {
        $params = $this->getParameters($data->title, $board);
        if($params) {
            $data->title = $params->newTitle;
            $data->duedate = $params->dueDate;
            $card = $this->apiCall("POST", NC_SERVER . "/index.php/apps/deck/api/v1.0/boards/{$params->board}/stacks/{$params->stack}/cards", $data);
            $card->board = $params->board;
            $card->stack = $params->stack;

            if($this->responseCode == 200) {
                if(ASSIGN_SENDER || $user) $this->assignUser($card, $user);
                if($data->attachments) $this->addAttachments($card, $data->attachments);
                $card->boardTitle = $params->boardTitle;
            }
            else {
                return false;
            }
            return $card;
        }
        return false;
    }

    //Add a new attachment
    private function addAttachments($card, $attachments) {
        $fullPath = getcwd() . "/attachments/"; //get full path to attachments directory
        for ($i = 0; $i < count($attachments); $i++) {
            $file = $fullPath . $attachments[$i];
            $data = array(
                'file' => new \CURLFile($file)
            );
            $this->apiCall("POST", NC_SERVER . "/index.php/apps/deck/api/v1.0/boards/{$card->board}/stacks/{$card->stack}/cards/{$card->id}/attachments?type=file", $data, true);
            unlink($file);
        }
    }

    //Assign a user to the card
    public function assignUser($card, $mailUser)
    {
        $adminUser = urlencode(NC_USER);
        $adminPassword = urlencode(NC_PASSWORD);

        $url = "http://{$adminUser}:{$adminPassword}@" . NC_HOST;
        $allUsers= $this->apiCall("GET",$url."/ocs/v1.php/cloud/users", null, false, true); //nextcloud user list

        foreach ($allUsers->data->users->element as $userId) {
            $userDetails = $this->apiCall("GET",$url."/ocs/v1.php/cloud/users/{$userId}",null, false, true);//search in the nextcloud user list
            if (isset($userDetails->data->email[0]) && $userDetails->data->email[0] == $mailUser) {
                $this->apiCall("PUT", NC_SERVER . "/index.php/apps/deck/api/v1.0/boards/{$card->board}/stacks/{$card->stack}/cards/{$card->id}/assignUser", ['userId' => (string)$userId]);
                break;
            }
        }
    }

    private function checkBotPermissions($board) {
        foreach($board->acl as $acl)
            if($acl->participant->uid == NC_USER && $acl->permissionEdit)
                return true;

        return false;
    }

    //Identify the card by email subject
    public function findCardBySubject($subject) {
        $boards = $this->apiCall("GET", NC_SERVER . "/index.php/apps/deck/api/v1.0/boards");
        $cleanSubject = preg_replace("/\s[b|s|u]-'.*?'/", '', $subject);
        foreach ($boards as $board) {
            $stacks = $this->apiCall("GET", NC_SERVER . "/index.php/apps/deck/api/v1.0/boards/{$board->id}/stacks");
            foreach ($stacks as $stack) {
                foreach ($stack->cards as $card) {
                    if (strtolower(trim($card->title)) == strtolower(trim($cleanSubject))) {
                        return [
                            'cardId' => $card->id,
                            'boardId' => $board->id
                        ];
                    }
                }
            }
        }
        return null;
    }

    //Add new comment
    public function addCommentToCard($result, $comment) {
        $cardId = $result['cardId'];
        $boardId = $result['boardId'];

        $url = NC_SERVER . "/ocs/v2.php/apps/deck/api/v1.0/cards/{$cardId}/comments";
        $data = [
            'comment' => $comment,
            'actorType' => 'users',
            'message' => $comment,
             'verb' => 'create',
        ];

        $response = $this->apiCall("POST", $url, $data);
        if ($response) {
            echo "Comment added successfully to card ID: $cardId on board ID: $boardId.";
            return true;
        } else {
            echo "Failed to add comment to card ID: $cardId.";
            return false;
        }
    }

    //Extract the initial message from all forwarded content
    public function extractForwardedContent($body) {
        $separator = "---------- Forwarded message ---------";
        $position = strpos($body, $separator);
        if ($position !== false) {
            $body = substr($body, 0, $position);
        }
        $body = trim($body);
        return $body;
    }

    //Extract the initial subject from the description
    public function findOriginalSubject($message) {
        preg_match_all('/\bSubject:\s*([^\n\r]*)/i', $message, $matches);
        if (!empty($matches[1])) {
            $subject = trim($matches[1][0]);
            $subject = preg_replace('/\s+To:.*$/', '', $subject);
            return $subject;
        }
        return null;
    }
}
