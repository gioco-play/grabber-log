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
     * @var bool Line 通知啟用狀態
     */
    private $notifyEnabled = false;

    /**
     * @var string
     */
    private $notifyAccessToken;

    /**
     * @var int 錯誤幾次發送通知
     */
    private $failCountNotify = 0;

    private $lastLogTmp = [];

    /**
     * @var string
     */
    private $mongodbPool = 'default';

//    private $allowField = [
//        'start',
//        'end',
//        're_grabber'
//    ];

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
//        $this->agent = $agent;
//        $this->recordType = $recordType;

        $this->agent = $options['agent'] ?? '';
        $this->recordType = $options['record_type'] ?? '';

        if (!empty(env("LINE_NOTIFY_ACCESS_TOKEN"))) {
            if (isset($options['fail_count_notify']) && intval($options['fail_count_notify']) >= 1) {
                $this->notifyEnabled = true;
                $this->notifyAccessToken = env("LINE_NOTIFY_ACCESS_TOKEN");
                $this->failCountNotify = intval($options['fail_count_notify']);
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
    public function fail($extraParams = [])
    {
        if ($this->notifyEnabled) {
            $failCount = 1;
            $lastLog = [];
            if (empty($this->lastLogTmp)) {
                $lastLog = $this->lastLog();
            }  else {
                $lastLog = $this->lastLogTmp;
            }

            if (!empty($lastLog) && isset($lastLog['status']) && $lastLog['status'] == 'fail') {
                $lastFailCount = 0;
                if (isset($lastLog['fail_count'])) {
                    $lastFailCount = intval($lastLog['fail_count']);
                }

                $totalFailCount = $lastFailCount + 1;
                if ($totalFailCount % $this->failCountNotify == 0) {
                    $message = "[" . env("APP_ENV") ."]" . "\r\n";
                    $message .= "遊戲商：{$this->vendorCode}" . "\r\n";
                    $message .= "代理：{$this->agent}" . "\r\n";
                    if (!empty($this->recordType)) {
                        $message .= "{$this->recordType}" . "\r\n";
                    }
                    $message .= "拉單失敗 已達到 {$totalFailCount} 次";

                    $curl = curl_init();
                    $headers = array(
                        "Content-Type: application/x-www-form-urlencoded",
                        "Authorization: Bearer {$this->notifyAccessToken}",
                    );
                    curl_setopt_array($curl, array(
                        CURLOPT_URL => 'https://notify-api.line.me/api/notify',
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_ENCODING => '',
                        CURLOPT_MAXREDIRS => 10,
                        CURLOPT_TIMEOUT => 0,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                        CURLOPT_CUSTOMREQUEST => 'POST',
                        CURLOPT_POSTFIELDS => http_build_query(['message' => $message]),
                        CURLOPT_HTTPHEADER => $headers,
                    ));

                    $response = curl_exec($curl);
                    var_dump("line notif curl :". json_encode($response));
                    curl_close($curl);
                }
                $failCount = $totalFailCount;
            }

            $this->mongodb->setPool($this->mongodbPool)->updateRow($this->collectionName, ["_id" => $this->grabberId], array_merge(
                [
                    'status' => 'fail',
                    'updated_at' => new UTCDateTime(),
                    'fail_count' => $failCount,
                ],
                $extraParams
            ));
        } else {
            $this->mongodb->setPool($this->mongodbPool)->updateRow($this->collectionName, ["_id" => $this->grabberId], array_merge(
                [
                    'status' => 'fail',
                    'updated_at' => new UTCDateTime()
                ],
                $extraParams
            ));
        }
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
        return (!empty($lastLog[0])) ? $lastLog[0] : null;
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
        $this->lastLogTmp = $lastLog;

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

}