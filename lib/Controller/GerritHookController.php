<?php
/**
 * T3Bot.
 *
 * @author Frank Nägler <typo3@naegler.net>
 *
 * @link http://www.t3bot.de
 * @link http://wiki.typo3.org/T3Bot
 */
namespace T3Bot\Controller;

use T3Bot\Slack\Message;

/**
 * Class GerritHookController.
 */
class GerritHookController
{
    /**
     * public method to start processing the request.
     *
     * @param string $hook
     */
    public function process($hook)
    {
        $entityBody = file_get_contents('php://input');
        $json = json_decode($entityBody);

        if ($GLOBALS['config']['gerrit']['webhookToken'] != $json->token) {
            exit;
        }
        if ($json->project !== 'Packages/TYPO3.CMS') {
            // only core patches please...
            exit;
        }
        $patchId = (int) str_replace('https://review.typo3.org/', '', $json->{'change-url'});
        $patchSet = intval($json->patchset);
        $branch = $json->branch;

        switch ($hook) {
            case 'patchset-created':
                if ($patchSet == 1 && $branch == 'master') {
                    foreach ($GLOBALS['config']['gerrit'][$hook]['channels'] as $channel) {
                        $item = $this->queryGerrit('change:'.$patchId);
                        $item = $item[0];
                        $created = substr($item->created, 0, 19);

                        $message = new Message();
                        $message->setText(' ');
                        $attachment = new Message\Attachment();

                        $attachment->setColor(Message\Attachment::COLOR_NOTICE);
                        $attachment->setTitle('[NEW] '.$item->subject);
                        $attachment->setAuthorName($item->owner->name);

                        $text = "Branch: *{$branch}* | :calendar: _{$created}_ | ID: {$item->_number}\n";
                        $text .= ":link: <https://review.typo3.org/{$item->_number}|Review now>";
                        $attachment->setText($text);
                        $attachment->setFallback($text);
                        $message->addAttachment($attachment);
                        $payload = json_decode($message->getJSON());
                        $payload->channel = $channel;
                        $this->postToSlack($payload);
                    }
                }
                break;
            case 'change-merged':
                foreach ($GLOBALS['config']['gerrit'][$hook]['channels'] as $channel) {
                    $item = $this->queryGerrit('change:'.$patchId);
                    $item = $item[0];
                    $created = substr($item->created, 0, 19);

                    $message = new Message();
                    $message->setText(' ');
                    $attachment = new Message\Attachment();

                    $attachment->setColor(Message\Attachment::COLOR_GOOD);
                    $attachment->setTitle(':white_check_mark: [MERGED] '.$item->subject);
                    $attachment->setAuthorName($item->owner->name);

                    $text = "Branch: {$branch} | :calendar: {$created} | ID: {$item->_number}\n";
                    $text .= ":link: <https://review.typo3.org/{$item->_number}|Goto Review>";
                    $attachment->setText($text);
                    $attachment->setFallback($text);
                    $message->addAttachment($attachment);
                    $payload = json_decode($message->getJSON());
                    $payload->channel = $channel;
                    $this->postToSlack($payload);
                }
                $files = $this->getFilesForPatch($patchId, $patchSet);
                $rstFiles = array();
                foreach ($files as $fileName => $changeInfo) {
                    if ($this->endsWith(strtolower($fileName), '.rst')) {
                        $rstFiles[$fileName] = $changeInfo;
                    }
                }
                if (count($rstFiles) > 0) {
                    $channel = '#fntest';
                    foreach ($rstFiles as $fileName => $changeInfo) {
                        $status = !empty($changeInfo['status']) ? $changeInfo['status'] : null;

                        $message = new Message();
                        $message->setText(' ');
                        $attachment = new Message\Attachment();

                        switch ($status) {
                            case 'A':
                                $attachment->setColor(Message\Attachment::COLOR_GOOD);
                                $attachment->setTitle(':white_check_mark: [MERGED] '.$item->subject);
                                $attachment->setAuthorName($item->owner->name);

                                $text = "A new documentation file has been added\n";
                                $text .= ":link: <https://git.typo3.org/Packages/TYPO3.CMS.git/blob/HEAD:/{$fileName}|Show reST file>";
                                $attachment->setText($text);
                                $attachment->setFallback($text);
                                $message->addAttachment($attachment);
                                break;
                            case 'D':
                                $attachment->setColor(Message\Attachment::COLOR_WARNING);
                                $attachment->setTitle(':white_check_mark: [MERGED] '.$item->subject);
                                $attachment->setAuthorName($item->owner->name);

                                $text = "A documentation file has been removed\n";
                                $text .= ":link: <https://git.typo3.org/Packages/TYPO3.CMS.git/blob/HEAD:/{$fileName}|Show reST file>";
                                $attachment->setText($text);
                                $attachment->setFallback($text);
                                $message->addAttachment($attachment);
                                break;
                            default:
                                $attachment->setColor(Message\Attachment::COLOR_WARNING);
                                $attachment->setTitle(':white_check_mark: [MERGED] '.$item->subject);
                                $attachment->setAuthorName($item->owner->name);

                                $text = "A documentation file has been updated\n";
                                $text .= ":link: <https://git.typo3.org/Packages/TYPO3.CMS.git/blob/HEAD:/{$fileName}|Show reST file>";
                                $attachment->setText($text);
                                $attachment->setFallback($text);
                                $message->addAttachment($attachment);
                                break;
                        }
                        $payload = json_decode($message->getJSON());
                        $payload->channel = $channel;
                        $this->postToSlack($payload);
                        sleep(1);
                    }
                }
                break;
            default:
                exit;
            break;
        }
    }

    /**
     * @param $query
     *
     * @return object|array
     */
    protected function queryGerrit($query)
    {
        $url = 'https://review.typo3.org/changes/?q='.$query;
        $result = file_get_contents($url);
        $result = json_decode(str_replace(")]}'\n", '', $result));

        return $result;
    }

    /**
     * @param int $changeId
     * @param int $revision
     *
     * @return mixed|string
     */
    protected function getFilesForPatch($changeId, $revision)
    {
        $url = 'https://review.typo3.org/changes/'.$changeId.'/revisions/'.$revision.'/files';
        $result = file_get_contents($url);
        $result = json_decode(str_replace(")]}'\n", '', $result));

        return $result;
    }

    /**
     * @param string $payload a json string
     */
    protected function postToSlack($payload)
    {
        $payload = json_encode($payload);
        if (!empty($GLOBALS['config']['slack']['botAvatar'])) {
            $payload->icon_emoji = $GLOBALS['config']['slack']['botAvatar'];
        }
        $command = 'curl -X POST --data-urlencode '.escapeshellarg('payload='.$payload).' https://'.$GLOBALS['config']['slack']['apiHost'].'/services/hooks/incoming-webhook?token='.$GLOBALS['config']['slack']['incomingWebhookToken'];
        exec($command);
    }

    /**
     * @param string $haystack
     * @param string $needle
     *
     * @return bool
     */
    protected function endsWith($haystack, $needle)
    {
        // search forward starting from end minus needle length characters
        return $needle === '' || (($temp = strlen($haystack) - strlen($needle)) >= 0 && strpos($haystack, $needle, $temp) !== false);
    }
}
