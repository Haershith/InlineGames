<?php
/**
 * Inline Games - Telegram Bot (@inlinegamesbot)
 *
 * (c) 2017 Jack'lul <jacklulcat@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Longman\TelegramBot\Commands\AdminCommands;

use Bot\Helper\DebugLog;
use Bot\Manager\Game;
use Longman\TelegramBot\Commands\AdminCommand;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\InlineKeyboardButton;
use Longman\TelegramBot\DB;
use Longman\TelegramBot\Request;

class CleanCommand extends AdminCommand
{
    protected $name = 'clean';
    protected $description = 'Clean old game messages and set them as empty';
    protected $usage = '/clean';

    public function execute()
    {
        $message = $this->getMessage();
        $edited_message = $this->getUpdate()->getEditedMessage();

        if ($edited_message) {
            $message = $edited_message;
        }

        if ($message) {
            $text = trim($message->getText(true));
        }

        $cleanInterval = $this->getConfig('clean_interval');

        if (isset($text) && is_numeric($text) && $text > 0) {
            $cleanInterval = $text;
        }

        if (empty($cleanInterval)) {
            $cleanInterval = 86400;  // 86400 seconds = 1 day
        }

        if (DB::isDbConnected()) {
            $storage = 'Bot\Storage\BotDB';
        } elseif (getenv('DATABASE_URL')) {
            $storage = 'Bot\Storage\DB';
        } else {
            $storage = 'Bot\Storage\JsonFile';
        }

        $game = new Game('_', '_', $this);

        $inactive = $game->getStorage()::action('list', $cleanInterval);

        $edited = 0;
        $cleaned = 0;
        foreach ($inactive as $inactive_game) {
            $data = $storage::action('get', $inactive_game['id']);

            if (isset($data['game_code'])) {
                $game = new Game($inactive_game['id'], $data['game_code'], $this);

                if ($game->canRun()) {
                    $result = Request::editMessageText(
                        [
                            'inline_message_id' => $inactive_game['id'],
                            'text' => '<b>' . $game->getGame()::getTitle() . '</b>' . PHP_EOL . PHP_EOL . '<i>' . __("This game session is empty.") . '</i>',
                            'reply_markup' => $this->createInlineKeyboard($data['game_code']),
                            'parse_mode' => 'HTML',
                            'disable_web_page_preview' => true,
                        ]
                    );

                    if ($result->isOk()) {
                        $edited++;
                    } else {
                        DebugLog::log('Failed to edit message for game ID \'' . $inactive_game['id'] . '\', error: ' . $result->getDescription());
                    }
                }
            }

            DebugLog::log('Cleaned: ' . $inactive_game['id']);

            if ($storage::action('remove', $inactive_game['id'])) {
                $cleaned++;
            }
        }

        $removed = 0;
        if (is_dir($dir = VAR_PATH . '/tmp')) {
            foreach (new \DirectoryIterator($dir) as $file) {
                if (!$file->isDir() && !$file->isDot() && $file->getMTime() < strtotime('-1 minute')) {
                    if (@unlink($dir . '/' . $file->getFilename())) {
                        $removed++;
                    }
                }
            }
        }

        if ($message) {
            $data = [];
            $data['chat_id'] = $message->getFrom()->getId();
            $data['text'] = 'Cleaned ' . $cleaned . ' games, edited ' . $edited . ' messages, removed ' . $removed . ' temporary files!';

            return Request::sendMessage($data);
        }

        return Request::emptyResponse();
    }

    private function createInlineKeyboard($game_code)
    {
        $inline_keyboard = [
            [
                new InlineKeyboardButton(
                    [
                        'text' => __('Create'),
                        'callback_data' => $game_code . ';new'
                    ]
                )
            ]
        ];

        $inline_keyboard_markup = new InlineKeyboard(...$inline_keyboard);

        return $inline_keyboard_markup;
    }
}
