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
        if (! empty(env("LINE_NOTIFY_ACCESS_TOKEN"))) {
            $this->lineNotifyToken = env("LINE_NOTIFY_ACCESS_TOKEN");
            $this->enableLineNotify = true;
        }
        if (! empty(env("DISCORD_WEBHOOK_URL"))) {
            $this->enableDiscord = true;
            $this->discordWebhookUrl = env("DISCORD_WEBHOOK_URL");
        }
        if (! empty(env("TELEGRAM_BOT_TOKEN")) && ! empty(env("TELEGRAM_CHAT_ID"))) {
            $this->enableTelegram = true;
            $this->telegramBotToken = env("TELEGRAM_BOT_TOKEN");
            $this->telegramChatId = env("TELEGRAM_CHAT_ID");
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

        if ($maintain === false && ($this->enableLineNotify || $this->enableDiscord || $this->enableTelegram)) {
            $failCount = 1;
            $lastLog = [];
            if (empty($this->lastLogTmp)) {
                $lastLog = $this->lastLog();
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

                // 建立發送訊息
                $message = "[{$envTxt}]" . "\r\n";
                $message .= "遊戲商：{$this->vendorCode}" . "\r\n";
                $message .= "\(代理 / 線路\)：{$this->agent}" . "\r\n";
                if (!empty($this->recordType)) {
                    $message .= "recordType: {$this->recordType}" . "\r\n";
                }
                if (!empty($this->operatorCode)) {
                    $message .= "營商代碼：{$this->operatorCode}" . "\r\n";
                }
                $message .= "拉單失敗 已達到 {$failCount} 次" . "\r\n";

                if ($failCount < $maxNotifyCount) {
                    if ($failCount % $this->failCountNotify == 0) {
                        $sendNotify = true;
                    } elseif ($failCount == 10) {
                        $sendNotify = true;
                    }
                } elseif ($failCount == $maxNotifyCount) {
                    $message .= '已達通知次數上限，不再進行通知，請相關技術儘速處理';
                    $sendNotify = true;
                }

                if ((isset($extraParams['error_message']) && gettype($extraParams['error_message']) == 'string') || (isset($extraParams['error']['msg']) && gettype($extraParams['error']['msg']) == 'string' ) ) {
                    $errorMsg = $extraParams['error_message'] ?? ($extraParams['error']['msg'] ?? '');
                    $errorMsg = substr($errorMsg, 0, 300);

                    $message .= "\r\n";
                    $message .= "\r\n";
                    $message .= 'error: ' . "\r\n";
                    $message .= "``` ". $errorMsg . " ```". "\r\n";
                }

                if (strpos($message, "_") !== false) {
                    $message = str_replace("_", "\_", $message);
                }


                if ($sendNotify) {
                    if ($this->enableLineNotify) {
                        co(function () use ($message) {
                            $this->sendLineNotify($message);
                        });
                    }

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

    private function sendLineNotify(string $message)
    {
        $url = 'https://notify-api.line.me/api/notify';

        $curl = curl_init();
        $headers = array(
            "Content-Type: application/x-www-form-urlencoded",
            "Authorization: Bearer {$this->lineNotifyToken}",
        );
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => http_build_query(['message' => $message]),
            CURLOPT_HTTPHEADER => $headers,
        ));

        $response = curl_exec($curl);
        var_dump("line notify curl :". json_encode($response));
        curl_close($curl);
    }

    private function sendTelegram(string $message) {
        $curl = curl_init();
        $apiUrl = "https://api.telegram.org/bot{$this->telegramBotToken}/sendMessage";
        $params = [
            'chat_id' => $this->telegramChatId,
            'parse_mode' => 'MarkdownV2',
            'text' => $message,
        ];

        curl_setopt_array($curl, array(
            CURLOPT_URL => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($params),
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
            ),
        ));

        $response = curl_exec($curl);
        var_dump("telegram sendMessage curl :". json_encode($response));

        curl_close($curl);
    }

    private function sendDiscord(string $message)
    {
        $params = [
            'content' => $message,
        ];

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $this->discordWebhookUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($params),
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
            ),
        ));

        $response = curl_exec($curl);

        var_dump("discord textMessage curl :". json_encode($response));

        curl_close($curl);
    }
}