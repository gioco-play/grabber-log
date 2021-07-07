# GrabberLog;

## 使用方法


### 初始化 (兩種方法選一種)
1. 實體化物件
```php
use GiocoPlus\GrabberLog\GrabberLog;
```

```php
/**
 * GrabberLog constructor.
 * @param string $vendorCode
 * @param string $agent
 * @param string $recordType
 * @throws \Exception
 */
 
$grabberLog = new GrabberLog($vendorCode, $agent, $gameCode, $parentBetId, $recordType);
```

2. 注入懶載入代理
```php
use GiocoPlus\GrabberLog\GrabberLog;
```
```php
/**
 * @Inject(lazy=true)
 * @var GrabberLog
 */
private $grabberLog;
```
```php
$this->grabberLog->setDefault($vendorCode, $agent, $gameCode, $parentBetId, $recordType)
```

### 使用方法
待寫