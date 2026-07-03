<?php
declare(strict_types=1);
namespace GiocoPlus\GrabberLog;

use Carbon\Carbon;
use GiocoPlus\Mongodb\MongoDb;
use Hyperf\Utils\ApplicationContext;
use MongoDB\BSON\UTCDateTime;

class GrabberLog
{
    /**
     * @var MongoDb
     */
    protected $mongodb;

    protected $collectionName = "grabber_log";

    /**
     * @var string 遊戲商代碼
     */
    protected $vendorCode;

    /**
     * @var string 代理線路
     *
     */
    protected $agent;

    private $grabberId;

    /**
     * @var string 注單類型
     */
    private $recordType;

    /**
     * @var string
     */
    private $lineNotifyToken;

    /**
     * @var string
     */
    private $discordWebhookUrl;

    /**
     * @var string
     */
    private $telegramBotToken;

    /**
     * @var string
     */
    private $telegramChatId;

    /**
     * @var int|null Telegram Super Group Topic ID (message_thread_id)
     */
    private $telegramTopicId;

    /**
     * @var bool
     */
    private $enableLineNotify;

    /**
     * @var bool
     */
    private $enableTelegram;

    /**
     * @var bool
     */
    private $enableDiscord;

    /**
     * @var int 錯誤幾次發送通知
     */
    private $failCountNotify = 0;

    private $lastLogTmp = [];

    /**
     * @var string
     */
    private $mongodbPool = 'default';

    /**
     * @var string
     */
    private $operatorCode;

    /**
     * GrabberLog constructor.
     * @param string $vendorCode
     * @param array $options
     * @throws \Exception
     */
    public function __construct(string $vendorCode, array $options)
    {
        if (! ApplicationContext::getContainer()->has(MongoDb::class)) {
            throw new \Exception('Please make sure if there is "MongoDb" in the container');
        }
        $this->mongodb = ApplicationContext::getContainer()->get(MongoDb::class);
        $this->mongodb->setPool($this->mongodbPool);

        $this->vendorCode = $vendorCode;

        $this->agent = $options['agent'] ?? '';
        $this->recordType = $options['record_type'] ?? '';
        $this->operatorCode = $options['operator_code'] ?? '';

        $this->failCountNotify = intval($options['fail_count_notify']);

        // 根據是否有各平台 token 判斷是否發送通知
//        if (! empty(env("LINE_NOTIFY_ACCESS_TOKEN"))) {
//            $this->lineNotifyToken = env("LINE_NOTIFY_ACCESS_TOKEN");
//            $this->enableLineNotify = true;
//        }
        if (! empty(env("DISCORD_WEBHOOK_URL"))) {
            $this->enableDiscord = true;
            $this->discordWebhookUrl = env("DISCORD_WEBHOOK_URL");
        }
        if (! empty(env("TELEGRAM_BOT_TOKEN")) && ! empty(env("TELEGRAM_CHAT_ID"))) {
            $this->enableTelegram = true;
            $this->telegramBotToken = env("TELEGRAM_BOT_TOKEN");
            $this->telegramChatId = env("TELEGRAM_CHAT_ID");
            // 讀取 Telegram 討論區 (Topic) 的子主題 ID (message_thread_id)
            if (! empty(env("TELEGRAM_TOPIC_ID"))) {
                $this->telegramTopicId = intval(env("TELEGRAM_TOPIC_ID"));
            }
        }
    }

//    /**
//     * 設定 log 初始值
//     * @param string $vendorCode 遊戲商代碼
//     * @param string $agent 代理線路 ex: op_code or 線路代號(名稱)
//     * @param string $recordType settled_status | api 不同接口
//     * @return GrabberLog
//     */
//    public function setDefault(string $vendorCode, string $agent = '', string $recordType = ''): GrabberLog
//    {
//        $this->vendorCode = $vendorCode;
//        $this->agent = $agent;
//        $this->recordType = $recordType;
//
//        return $this;
//    }

    /**
     * 設定 grabber_id
     *
     * @param string $id
     * @return $this
     */
    public function setId(string $id): GrabberLog
    {
        $this->grabberId = $id;
        return $this;
    }

    /**
     * 啟動抓單紀錄
     * @param string $start 紀錄開始 id or date
     * @param array $extraParams
     */
    public function running(string $start, array $extraParams = [])
    {
        $defaultData = [
            'vendor_code' => $this->vendorCode,
            'agent' => $this->agent,
            'status' => 'running',
            'start' => $start
        ];

        if (!empty($this->recordType)) {
            $defaultData = array_merge($defaultData, [
                'record_type' => $this->recordType
            ]);
        }

        if (!empty($this->operatorCode)) {
            $defaultData = array_merge($defaultData, [
                'operator_code' => $this->operatorCode
            ]);
        }

        $this->grabberId = $this->mongodb->setPool($this->mongodbPool)->insert($this->collectionName, array_merge(
            $defaultData,
            $extraParams,
            [
                'created_at' => new UTCDateTime(),
                'updated_at' => new UTCDateTime(),
                'host_name' => gethostname(),
            ]
        ));
        return $this->grabberId;
    }

    /**
     * 抓單失敗
     * 更新抓單紀錄 status fail
     * @param array $extraParams
     * @throws \GiocoPlus\Mongodb\Exception\MongoDBException
     */
    public function fail($extraParams = [], $options = [])
    {
        // 判斷是否維護
        $maintain = false;
        if (!empty($options['maintain']) && gettype($options['maintain']) == 'boolean') {
            $maintain = $options['maintain'];
        }

        if ($maintain === false && ($this->enableDiscord || $this->enableTelegram)) {
            $failCount = 1;
            $lastLog = [];
            if (empty($this->lastLogTmp)) {
                $lastLog = $this->lastFinishedLog();
            }  else {
                $lastLog = $this->lastLogTmp;
            }

            // 判斷是否達到發送通知頻率數量
            if (!empty($lastLog) && isset($lastLog['status']) && $lastLog['status'] == 'fail') {
                $envTxt = env('SERVICE_ENV', 'unknown');
                $sendNotify = false;
                $maxNotifyCount = 500;

                // 判斷上一筆抓單紀錄內是否有失敗次數，若有則加上前一筆失敗次數
                if (isset($lastLog['fail_count'])) {
                    $failCount = intval($lastLog['fail_count']);
                    $failCount ++;
                }

                // 建立發送訊息 (依照 Go 版本格式)
                $message = sprintf("*%s*\n", $this->escapeMarkdownV2("(" . $envTxt . ")"));
                $message .= sprintf("*🎮 遊戲商：* %s \n", $this->vendorCode);
                $message .= sprintf("*🛣️ 代理 / 線路：* `%s` \n", $this->escapeCode($this->agent));
                if (!empty($this->recordType)) {
                    $message .= sprintf("*recordType：*%s \n", $this->escapeMarkdownV2($this->recordType));
                }
                if (!empty($this->operatorCode)) {
                    $message .= sprintf("*🏷️ 營商代碼：* `%s` \n", $this->operatorCode);
                }
                $message .= sprintf("*❗ 拉單失敗次數：* %d \n", $failCount);

                if ($failCount < $maxNotifyCount) {
                    if ($failCount % $this->failCountNotify == 0) { // 餘數為 0 時，發送
                        $sendNotify = true;
                    } elseif ($failCount == 10) { // 預設第 10 次時皆通知
                        $sendNotify = true;
                    }
                } elseif ($failCount == $maxNotifyCount || $failCount % 2000 == 0) { // 第 500 次警示，之後每 2000 次通知一次（含 2000, 4000, 6000...）
                    $message .= "\n*🚨 失敗次數已超過上限，請相關技術儘速處理*\n\n";
                    $sendNotify = true;
                }

                $message .= sprintf("*🧾 Grabber Trace：* `%s`", $this->escapeCode((string)$this->grabberId));
                $message .= "\n\n";

                // 限制錯誤訊息長度，避免通知過長
                $errorMsg = $extraParams['error_message'] ?? ($extraParams['error']['msg'] ?? '');
                $errorMsg = substr($errorMsg, 0, 500); // 限制 500 字元
                $message .= sprintf("```\n%s```", $this->escapeCode($errorMsg));


                if ($sendNotify) {
//                    if ($this->enableLineNotify) {
//                        co(function () use ($message) {
//                            $this->sendLineNotify($message);
//                        });
//                    }

                    if ($this->enableTelegram) {
                        co(function () use ($message) {
                            $this->sendTelegram($message);
                        });
                    }

                    if ($this->enableDiscord) {
                        co(function () use ($message) {
                            $this->sendDiscord($message);
                        });
                    }
                }
            }

            $grabberUpdateField = [
                'status' => 'fail',
                'updated_at' => new UTCDateTime(),
                'fail_count' => $failCount,
            ];
        } else {
            $grabberUpdateField = [
                'status' => 'fail',
                'updated_at' => new UTCDateTime()
            ];
        }

        $this->mongodb->setPool($this->mongodbPool)->updateRow($this->collectionName, ["_id" => $this->grabberId], array_merge(
            $grabberUpdateField,
            $extraParams
        ));
    }

    /**
     * 抓單完成
     * 更新抓單紀錄 status complete
     * @param array $extraParams
     * @throws \GiocoPlus\Mongodb\Exception\MongoDBException
     */
    public function complete($extraParams = [])
    {
        $this->mongodb->setPool($this->mongodbPool)->updateRow($this->collectionName, ["_id" => $this->grabberId], array_merge(
            [
                'status' => 'complete',
                'updated_at' => new UTCDateTime()
            ],
            $extraParams
        ));
    }

    /**
     * 取得最新一筆紀錄
     */
    public function lastLog($filter = [])
    {
        $mongoFilter = [
            'vendor_code' => $this->vendorCode,
        ];

        if (!empty($this->agent)) {
            $mongoFilter = array_merge($mongoFilter, [
                'agent' => $this->agent
            ]);
        }

        if (!empty($this->recordType)) {
            $mongoFilter = array_merge($mongoFilter, [
                'record_type' => $this->recordType
            ]);
        }

        // 額外條件
        $mongoFilter = array_merge($mongoFilter, $filter);

        $lastLog = $this->mongodb->setPool($this->mongodbPool)->fetchAll($this->collectionName, $mongoFilter, ['sort' => ['_id' => -1]]);
        $lastLog = (!empty($lastLog[0])) ? $lastLog[0] : null;
        $this->lastLogTmp = $lastLog;
        return $lastLog;
    }

    private function lastFinishedLog($filter = [])
    {
        $filter['status'] = ['$ne' => 'running']; // 只找非 running 的
        return $this->lastLog($filter);
    }

    /**
     * 取得下次抓取時間 (適用參數有 [ start | end ] 並且是時間) 返回 timestamp
     *
     * @param int $pastMin 過去分鐘數
     * @param int $longTimeRang 最長時間範圍 (單位 min)
     * @param array $options [ bufferNowMin 距離現在時間 int (單位 min) | coverTimeRang 包含上次抓取時間 int (單位 min) | lastLogFilter 最後一條紀錄 filter ]
     * @return array [ start | end ] 10 digit timestamp
     */
    public function nextGrabberTime(int $pastMin, int $longTimeRang, array $options = []): array
    {
        [$start, $end] = array_values($this->nextGrabber($pastMin, $longTimeRang, $options));

        return [
            "start" => $start->timestamp,
            "end" => $end->timestamp
        ];
    }

    /**
     * 取得下次抓取時間 (適用參數有 [ start | end ] 並且是時間) 返回 Carbon
     *
     * @param int $pastMin 過去分鐘數
     * @param int $longTimeRang 最長時間範圍 (單位 min)
     * @param array $options [ bufferNowMin 距離現在時間 int (單位 min) | coverTimeRang 包含上次抓取時間 int (單位 min) | lastLogFilter 最後一條紀錄 filter ]
     * @return array [ start | end ] Carbon
     */
    public function nextGrabber(int $pastMin, int $longTimeRang, array $options = [])
    {
        $carbonTimeZone = 'Asia/Taipei';

        $start = Carbon::now($carbonTimeZone)->subMinutes($pastMin);
        $nowLimit = Carbon::now($carbonTimeZone);
        if (!empty($options["bufferNowMin"])) {
            $nowLimit = $nowLimit->subMinutes($options["bufferNowMin"]);
        }

        $end = $nowLimit;

        if (!empty($options["lastLogFilter"])) {
            $lastLog = $this->lastLog($options["lastLogFilter"]);
        } else {
            $lastLog = $this->lastLog();
        }

        if (!empty($lastLog)) {
            // 檢查最後一筆是否有時間差距
            $lastStartTime = Carbon::createFromFormat("Y-m-d H:i:s", $lastLog["start"], $carbonTimeZone);
            $lastEndTime = Carbon::createFromFormat("Y-m-d H:i:s", $lastLog["end"], $carbonTimeZone);

            $lastCheckTime = ($lastLog["status"] == 'complete') ? $lastEndTime : $lastStartTime;
            if ($lastCheckTime->lt($start)) {
                $coverTimeRange = (!empty($options["coverTimeRang"])) ? $options["coverTimeRang"] : 1;
                $start = ($lastLog["status"] == 'complete') ? $lastCheckTime->subMinutes($coverTimeRange) : $lastStartTime ;
            }

            // 如果上一筆拉單失敗，使用自動補單，並檢查開始時間，取得比較早的開始時間
            if ($lastLog["status"] == "fail") {
                $start = ($lastStartTime < $start) ? $lastStartTime : $start;
            }
        }

        // 若拉單間距時間過長則只拉長區間單位，拉不完的下次排程繼續拉，若小於長區間則直接抓到現在
        if ($end->diffInMinutes($start) >= $longTimeRang) {
            $end = $start->copy()->addMinutes($longTimeRang);
        }

        return [
            "start" => $start,
            "end" => $end
        ];
    }

    /**
     * 發送 Line Notify 訊息 (使用協程安全/非阻塞的 Guzzle Client)
     *
     * @param string $message
     */
    private function sendLineNotify(string $message)
    {
        $url = 'https://notify-api.line.me/api/notify';

        try {
            $clientFactory = ApplicationContext::getContainer()->get(\Hyperf\Guzzle\ClientFactory::class);
            $client = $clientFactory->create(['timeout' => 10]);
            $response = $client->post($url, [
                'headers' => [
                    'Authorization' => "Bearer {$this->lineNotifyToken}",
                ],
                'form_params' => [
                    'message' => $message
                ]
            ]);
            var_dump("line notify curl :". json_encode($response->getBody()->getContents()));
        } catch (\Throwable $e) {
            var_dump("line notify error :". $e->getMessage());
        }
    }

    /**
     * 發送 Telegram 訊息 (使用協程安全/非阻塞的 Guzzle Client)
     * 採用 MarkdownV2 parse_mode 並支援討論串 (Topic) 發送
     *
     * @param string $message
     */
    private function sendTelegram(string $message) {
        $apiUrl = "https://api.telegram.org/bot{$this->telegramBotToken}/sendMessage";
        $params = [
            'chat_id' => $this->telegramChatId,
            'parse_mode' => 'MarkdownV2',
            'text' => $message,
        ];

        // 若有設定主題/討論區 ID，則帶入對應參數
        if (! empty($this->telegramTopicId)) {
            $params['message_thread_id'] = $this->telegramTopicId;
        }

        try {
            $clientFactory = ApplicationContext::getContainer()->get(\Hyperf\Guzzle\ClientFactory::class);
            $client = $clientFactory->create(['timeout' => 10]);
            $response = $client->post($apiUrl, [
                'json' => $params
            ]);
            var_dump("telegram sendMessage curl :". json_encode($response->getBody()->getContents()));
        } catch (\Throwable $e) {
            var_dump("telegram sendMessage error :". $e->getMessage());
        }
    }

    /**
     * 發送 Discord Webhook 訊息 (使用協程安全/非阻塞的 Guzzle Client)
     *
     * @param string $message
     */
    private function sendDiscord(string $message)
    {
        $params = [
            'content' => $message,
        ];

        try {
            $clientFactory = ApplicationContext::getContainer()->get(\Hyperf\Guzzle\ClientFactory::class);
            $client = $clientFactory->create(['timeout' => 10]);
            $response = $client->post($this->discordWebhookUrl, [
                'json' => $params
            ]);
            var_dump("discord textMessage curl :". json_encode($response->getBody()->getContents()));
        } catch (\Throwable $e) {
            var_dump("discord textMessage error :". $e->getMessage());
        }
    }

    /**
     * 用於一般文字的 MarkdownV2 轉義，避免因 Markdown 解析語法錯誤而導致 Telegram 回傳 400 錯誤。
     * 注意：反斜線必須放在第一位替換，否則會把後續替換產生的反斜線也再次轉義。
     *
     * @param string $text
     * @return string
     */
    private function escapeMarkdownV2(string $text): string
    {
        $chars = ["\\", "_", "*", "[", "]", "(", ")", "~", "`", ">", "<", "&", "#", "+", "-", "=", "|", "{", "}", ".", "!"];

        foreach ($chars as $c) {
            $text = str_replace($c, "\\" . $c, $text);
        }
        return $text;
    }

    /**
     * 專門用於 Code 區塊 (“`text`”) 內的轉義。在 Code 區塊內，只需要轉義 ` 和 \
     *
     * @param string $text
     * @return string
     */
    private function escapeCode(string $text): string
    {
        $text = str_replace("\\", "\\\\", $text); // 先轉義反斜線
        $text = str_replace("`", "\\`", $text);   // 再轉義反引號
        return $text;
    }
}