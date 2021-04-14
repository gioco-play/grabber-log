<?php
declare(strict_types=1);
namespace GiocoPlus\GrabberLog;

use Carbon\Carbon;
use GiocoPlus\Mongodb\MongoDb;
use Hyperf\Di\Annotation\Inject;
use Psr\Container\ContainerInterface;
use MongoDB\BSON\UTCDateTime;

class GrabberLog
{

    /**
     * @var MongoDb
     */
    protected $mongodb;

    protected $collectionName = "grabber_log";

    /*
     * 遊戲商代碼
     */
    protected $vendorCode;
    /*
     * 代理線路
     */
    protected $agent;

    private $grabberId;
    /**
     * @var string
     */
    private $recordType;

    /**
     * @var string
     */
    private $mongodbPool = 'default';

    public function __construct(ContainerInterface $container)
    {
        $this->mongodb = $container->get(MongoDb::class);
        $this->mongodb->setPool($this->mongodbPool);
    }


    /**
     * 設定 log 初始值
     * @param string $vendorCode 遊戲商代碼
     * @param string $agent 代理線路 ex: op_code or 線路代號(名稱)
     * @param string $recordType settled_status | api 不同接口
     * @return GrabberLog
     */
    public function setVendor(string $vendorCode, string $agent = '', string $recordType = '')
    {
        $this->vendorCode = $vendorCode;
        $this->agent = $agent;
        $this->recordType = $recordType;

        return $this;
    }

    /**
     * 設定grabber_id
     *
     * @param string $id
     * @return $this
     */
    public function setId(string $id)
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
        $this->mongodb->setPool($this->mongodbPool)->updateRow($this->collectionName, ["_id" => $this->grabberId], array_merge(
            [
                'status' => 'fail',
                'updated_at' => new UTCDateTime()
            ],
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
        return (!empty($lastLog[0])) ? $lastLog[0] : null;
    }

    /**
     * 取得下次抓取時間 ( 適用參數有 [start | end] 並且是時間)
     *
     * @param int $pastMin 過去分鐘數
     * @param int $longTimeRang 最長時間範圍 (單位 min)
     * @param array $options [bufferNowMin 距離現在時間 int (單位 min) | coverTimeRang 包含上次抓取時間 int (單位 min) | lastLogFilter 最後一條紀 filter ]
     */
    public function nextGrabberTime(int $pastMin, int $longTimeRang, array $options = [])
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
            "start" => $start->timestamp,
            "end" => $end->timestamp
        ];
    }




}