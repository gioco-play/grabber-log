# GrabberLog

- [GrabberLog](#grabberlog)
  * [初始化](#---)
    + [參數說明](#----)
  * [紀錄開始](#----)
    + [參數說明](#-----1)
  * [紀錄結束](#----)
    + [參數說明](#-----2)
  * [額外功能](#----)
    + [取得最後一筆 grabber log](#-------grabber-log)
      - [參數說明](#-----3)
    + [取得下次抓取時間](#--------)
      - [參數說明](#-----4)



## 初始化
```php
use GiocoPlus\GrabberLog\GrabberLog;
```

```php
/**
 * GrabberLog constructor.
 * @param string $vendorCode
 * @param array $options
 * @throws \Exception
 */
 
$grabberLog = new GrabberLog($vendorCode, $options);
```

### 參數說明
| 參數 | 類型 | 說明 |
| - | - | - |
| $vendorCode | string | 遊戲商代碼 |
| $options | array | 請看 options 說明 |

options
> ex: $options['agent']

>參數皆為選填

| 參數 | 類型 | 說明 |
| - | - | - |
| agent | string | 代理 |
| record_type | string | 單類型 |
| operatorCode | string | 營商代碼 |
| fail_count_notify | int | 錯誤次數達到時發送 Line Notify 需設定 `.env` 內 `LINE_NOTIFY_ACCESS_TOKEN`，通知環境請設定 `SERVICE_ENV`，未設定預設 unknow


---


## 紀錄開始
```php
$grabberLog->running($start, $extraParams);
```
### 參數說明
| 參數 | 類型 | 說明 |
| - | - | - |
| $start | string | 紀錄開始 id or date |
| $extraParams | array | 紀錄額外搜尋條件，若有明確的結束時間陣列 key 請使用 `end`|


---


## 紀錄結束
```php
# 完成時使用
$grabberLog->complete($extraParams);

# 錯誤時使用
$grabberLog->fail($extraParams);

```
### 參數說明
| 參數 | 類型 | 說明 |
| - | - | - |
| $extraParams | array | 提供完成時需額外紀錄|


## 額外功能
### 取得最後一筆 grabber log
```php
$grabberLog->lastLog($filter);
```
#### 參數說明
| 參數 | 類型 | 說明 |
| - | - | - |
| $filter | array | 額外搜尋條件|

---

### 取得下次抓取時間
> 僅適用 log 內有 `start`、`end`

> nextGrabber ， 返回 Carbon

> nextGrabberTime ， 返回 timestamp 10 位



```php
['start' => $startTime, 'end' => $endTime] = $grabberLog->nextGrabberTime(
                    $pastMinutes,
                    $longTimeRang,
                    ['bufferNowMin' => $bufferMin]
                );
```
#### 參數說明
| 參數 | 類型 | 說明 |
| - | - | - |
| $past_minutes | int | 過去分鐘數 |
| $longTimeRang | int | 最長時間範圍 (單位 min) |
| $options | array | 請看 options 說明 |

options
| 參數 | 類型 | 說明 |
| - | - | - |
| bufferNowMin | int | 距離現在時間 int (單位 min)，影響結束時間(end) |
| coverTimeRang | int | 包含上次抓取時間 (單位 min) |
| lastLogFilter | array | 最後一條紀錄 filter |


---