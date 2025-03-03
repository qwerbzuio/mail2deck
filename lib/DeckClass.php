<?php

namespace Mail2Deck;

use Exception;

class DeckClass
{
    private $responseCode;

    private function apiCall($request, $endpoint, $data = null, $attachment = false)
    {
        $curl = curl_init();
        if ($data && !$attachment) {
            $endpoint .= '?' . http_build_query($data);
        }
        curl_setopt_array($curl, array(
            CURLOPT_URL => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $request,
            CURLOPT_HTTPHEADER => array(
                'Authorization: Basic ' . base64_encode(NC_USER . ':' . NC_PASSWORD),
                'OCS-APIRequest: true',
            ),
        ));

        if ($request === 'POST') curl_setopt($curl, CURLOPT_POSTFIELDS, (array) $data);

        $response = curl_exec($curl);
        $err = curl_error($curl);
        $this->responseCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        curl_close($curl);
        if ($err) echo "cURL Error #:" . $err;

        return json_decode($response);
    }

    public function getStackID($boardName, $stackName)
    {
        $boards = $this->apiCall("GET", NC_SERVER . "/index.php/apps/deck/api/v1.0/boards");
        $boardId = null;
        foreach ($boards as $board) {
            if (strtolower($board->title) == strtolower($boardName)) {
                if (!$this->checkBotPermissions($board)) {
                    return false;
                }
                $boardId = $board->id;
                break;
            }
        }
        $stacks = $this->apiCall("GET", NC_SERVER . "/index.php/apps/deck/api/v1.0/boards/$boardId/stacks");
        foreach ($stacks as $key => $stack) {
            if (strtolower($stack->title) == strtolower($stackName)) {
                return $stack->id;
            }
        }
        return null;
    }

    public function getParameters($params, $boardFromMail = null)
    { // get the board and the stack
        $stackFromMail = null;
        $userFromMail = null;
        $duedateFromMail = null;
        if (!$boardFromMail) // if board is not set within the email address, look for board into email subject
            if (preg_match('/b-"([^"]+)"/', $params, $m) || preg_match("/b-'([^']+)'/", $params, $m)) {
                $boardFromMail = $m[1];
                $params = str_replace($m[0], '', $params);
            }
        if (preg_match('/s-"([^"]+)"/', $params, $m) || preg_match("/s-'([^']+)'/", $params, $m)) {
            $stackFromMail = $m[1];
            $params = str_replace($m[0], '', $params);
        }
        if (preg_match('/u-"([^"]+)"/', $params, $m) || preg_match("/u-'([^']+)'/", $params, $m)) {
            $userFromMail = $m[1];
            $params = str_replace($m[0], '', $params);
        }
        if (preg_match('/d-"([^"]+)"/', $params, $m) || preg_match("/d-'([^']+)'/", $params, $m)) {
            $duedateFromMail = $m[1];
            $params = str_replace($m[0], '', $params);
        }

        $boards = $this->apiCall("GET", NC_SERVER . "/index.php/apps/deck/api/v1.0/boards");
        if (!$boards) {
            throw new Exception(sprintf("Could not access board '%s'", $params->boardTitle));
        }

        $boardId = $boardName = null;
        foreach ($boards as $board) {
            if (strtolower($board->title) == strtolower($boardFromMail)) {
                if (!$this->checkBotPermissions($board)) {
                    return false;
                }
                $boardId = $board->id;
                $boardName = $board->title;
                break;
            }
        }

        if ($boardId) {
            $stacks = $this->apiCall("GET", NC_SERVER . "/index.php/apps/deck/api/v1.0/boards/$boardId/stacks");
            foreach ($stacks as $key => $stack)
                if ($stackFromMail != null && strtolower($stack->title) == strtolower($stackFromMail)) {
                    $stackId = $stack->id;
                    break;
                }
            if ($key == array_key_last($stacks) && !isset($stackId)) $stackId = $stacks[0]->id;
        } else {
            return false;
        }

        $boardStack = new \stdClass();
        $boardStack->board = $boardId;
        $boardStack->stack = $stackId;
        $boardStack->newTitle = $params;
        $boardStack->boardTitle = $boardName;
        $boardStack->userId = null;
        if ($userFromMail != null) {
            $boardStack->userId = strtolower($userFromMail);
        }
        $boardStack->dueDate = $duedateFromMail;

        return $boardStack;
    }

    public function addCard($data, $board, $stackid)
    {
        $params = $this->getParameters($data->title, $board);

        if ($params) {
            $data->title = $params->newTitle;
            if ($params->dueDate) {
                $data->duedate = $params->dueDate;
            }

            $maxlength = 5000; // +/- empirical maxlength for description in Deck-cards
            $desc_length = strlen($data->description);
            $full_description = null;
            if ($desc_length > $maxlength) {
                printf("Warning: description length (%d) exceeds soft-limit of %d characters. Shortening text.\n", $desc_length, $maxlength);
                $full_description = $data->description;
                $data->description = substr($data->description, 0, $maxlength);
                $data->description = sprintf(
                    "*Achtung: Der Mailtext war zu lang und wurde gekürzt. Der vollständige Mailtext befindet sich bei den Anhängen ('%s.md')*\n\n%s",
                    $data->title,
                    $data->description
                );
            }

            $card = $this->apiCall("POST", NC_SERVER . "/index.php/apps/deck/api/v1.0/boards/{$params->board}/stacks/{$stackid}/cards", $data);
            if (! $card) {
                $stack = $this->apiCall("GET", NC_SERVER . "/index.php/apps/deck/api/v1.0/boards/{$params->board}/stacks/{$stackid}");
                throw new Exception(sprintf("Could not create card on board '%s', stack '%s'", $params->boardTitle, $stack->title));
            }
            $card->board = $params->board;
            $card->stack = $stackid;

            if ($full_description) {
                $this->addDescriptionAttachments($card, $data->title, $full_description);
            }

            if ($this->responseCode == 200) {
                if ($data->attachments) {
                    $this->addAttachments($card, $data->attachments);
                }
                $card->boardTitle = $params->boardTitle;
            } else {
                return false;
            }
            return $card;
        }
        return false;
    }

    private function addAttachments($card, $attachments)
    {
        $fullPath = getcwd() . "/attachments/"; //get full path to attachments directory
        foreach ($attachments as $attachment) {
            if (! $attachment) {
                print("Warning: empty attachment name. Skipping.\n");
                continue;
            }
            $file = $fullPath . $attachment;
            $data = array(
                'file' => new \CURLFile($file)
            );
            $this->apiCall("POST", NC_SERVER . "/index.php/apps/deck/api/v1.0/boards/{$card->board}/stacks/{$card->stack}/cards/{$card->id}/attachments?type=file", $data, true);
            unlink($file);
        }
    }

    private function addDescriptionAttachments($card, $title, $description)
    {
        $fullPath = getcwd() . "/attachments/"; //get full path to attachments directory
        $file = $fullPath . $title . ".md";
        file_put_contents($file, $description);
        $data = array(
            'file' => new \CURLFile($file)
        );
        $this->apiCall("POST", NC_SERVER . "/index.php/apps/deck/api/v1.0/boards/{$card->board}/stacks/{$card->stack}/cards/{$card->id}/attachments?type=file", $data, true);
        unlink($file);
    }

    public function assignUser($card, $mailUser)
    {
        $board = $this->apiCall("GET", NC_SERVER . "/index.php/apps/deck/api/v1.0/boards/{$card->board}");
        $boardUsers = array_map(function ($user) {
            return $user->uid;
        }, $board->users);

        foreach ($boardUsers as $user) {
            if ($user === $mailUser->userId) {
                $this->apiCall("PUT", NC_SERVER . "/index.php/apps/deck/api/v1.0/boards/{$card->board}/stacks/{$card->stack}/cards/{$card->id}/assignUser", $mailUser);
                break;
            }
        }
    }

    private function checkBotPermissions($board)
    {
        return true;
        # if($acl->participant->uid == NC_USER && $acl->permissionEdit)
        foreach ($board->acl as $acl)
            if ($acl->participant->uid == NC_USER)
                return true;

        return false;
    }
}
