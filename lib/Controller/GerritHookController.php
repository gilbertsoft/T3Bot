<?php
/**
 * T3Bot
 * @author Frank Nägler <typo3@naegler.net>
 * @link http://www.t3bot.de
 * @link http://wiki.typo3.org/T3Bot
 */

namespace T3Bot\Controller;

/**
 * Class GerritHookController
 *
 * @package T3Bot\Controller
 */
class GerritHookController {

	/**
	 * public method to start processing the request
	 *
	 * @param string $hook
	 */
	public function process($hook) {
		// JSON???
		$entityBody = file_get_contents('php://input');
		$json = json_decode($entityBody);

		if ($GLOBALS['config']['gerrit']['webhookToken'] != $json->token) {
			exit;
		}
		switch ($hook) {
			case 'patchset-created':
				// changeId=$CHANGE_ID&projectName=$PROJECT_NAME&branch=$BRANCH
				//   &commit=$COMMIT&patchset=$PATCHSET
				//if (intval($json->patchset) == 1) {
					$patchId = (int) str_replace('http://review.typo3.org/', '', $json->{'change-url'});
					$patchSet = intval($json->patchset);
					foreach ($GLOBALS['config']['gerrit'][$hook]['channels'] as $channel) {
						$item = $this->queryGerrit('change:' . $patchId);
						$item = $item[0];
						$created = substr($item->created, 0, 19);
						$updated = substr($item->updated, 0, 19);

						$text = ':bangbang: *[NEW] ' . $item->subject . "* by _{$item->owner->name}_\n";
						$text .= "Created: {$created} | Last update: {$updated} | ID: {$item->_number} | Patchset: {$patchSet}\n";
						$text .= "<https://review.typo3.org/{$item->_number}|Review now>";
						$payload = new \stdClass();
						$payload->channel = $channel;
						$payload->text = $text;
						$this->postToSlack(json_encode($payload));
					}
				//}
			break;
			case 'change-merged':
				// changeId=$CHANGE_ID&projectName=$PROJECT_NAME&branch=$BRANCH&submitter=$SUBMITTER&commit=$COMMIT
				foreach ($GLOBALS['config']['gerrit'][$hook]['channels'] as $channel) {
					$patchId = (int) str_replace('http://review.typo3.org/', '', $json->{'change-url'});
					$item = $this->queryGerrit('change:' . $patchId);
					$item = $item[0];
					$created = substr($item->created, 0, 19);
					$updated = substr($item->updated, 0, 19);

					$text = ':white_check_mark: *[MERGED] ' . $item->subject . "* by _{$item->owner->name}_\n";
					$text .= "Created: {$created} | Last update: {$updated} | ID: {$item->_number}\n";
					$text .= "<https://review.typo3.org/{$item->_number}|Goto Review>";
					$payload = new \stdClass();
					$payload->channel = $channel;
					$payload->text = $text;
					$this->postToSlack(json_encode($payload));
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
	protected function queryGerrit($query) {
		$url = 'https://review.typo3.org/changes/?q=' . $query;
		$result = file_get_contents($url);
		$result = json_decode(str_replace(")]}'\n", '', $result));
		return $result;
	}

	/**
	 * @param string $payload a json string
	 */
	protected function postToSlack($payload) {
		$command = 'curl -X POST --data-urlencode ' . escapeshellarg('payload=' . $payload) . ' https://phile.slack.com/services/hooks/incoming-webhook?token=' . $GLOBALS['config']['slack']['incomingWebhookToken'];
		exec($command);

	}
}