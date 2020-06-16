<?php
namespace App\Process;

use App\Model\Ticket;
use App\Service\Api;
use EasySwoole\Component\Process\AbstractProcess;
use EasySwoole\EasySwoole\Config;
use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\Config as MysqliConf;

class TicketGrabbingProcess extends AbstractProcess {

    private static $url;
    private static $sleep;
    private static $path;

    private $favoriteNames;  //站点信息
    private $jsSessionId;
    private $bigIpServerOtn;
    private $mysqli;
    private $ticketModel;

    const TRAIN_TYPE = [
        23 => '软卧一等 动卧',
        28 => '硬卧二等 软座',
        29 => '硬座',
        26 => '无座',
        32 => '商务特等座',
        30 => '二等座',
        31 => '一等座',
        33 => '高级商务'
    ];


    public function __construct(...$args) {
        parent::__construct(...$args);
        self::$url = 'https://kyfw.12306.cn/';
        self::$sleep = 1; //查询间隔(无票时)
        self::$path = EASYSWOOLE_ROOT . '/Data/';
        $mysqliConfig = new MysqliConf(Config::getInstance()->getConf('mysqli'));
        $this->mysqli = new Client($mysqliConfig);
        $this->ticketModel = new Ticket();
    }

    /**
     * 将用户信息和车次信息初始化到静态变量
     */
    public function init() {
        // 加载站点
        if (empty($this->favoriteNames)) {
            $favoriteNames = Api::getInstance()->getStationName();
            $this->favoriteNames = $favoriteNames;
        }
    }

    protected function run($arg) {
        // TODO: Implement run() method.
        go(function () {
            while (true) {
                $data = $this->ticketModel->getTicketProcess();
                if (empty($data)) {
                    \Co::sleep(10);
                }
                foreach ($data as $value) {
                    $this->ticketModel->updateTicketProcess($value['id'], 2);
                    $this->handle($value); //登陆监控余票并下单
                }
            }
        });
    }

    public function handle($data) {
        $this->init(); //初始化站点信息
        $cookie = include_once (self::$path . 'cookie.php');
        $loginHeaders = ['Cookie:RAIL_EXPIRATION=' . $cookie['RAIL_EXPIRATION'] . '; RAIL_DEVICEID=' . $cookie['RAIL_DEVICEID']];

        //检查登陆状态，状态异常则重新登陆
        if (!Api::getInstance()->uamtk($data['train_username'])) {
            echo '重新登陆' . PHP_EOL;
            $result = $this->getLoginStorage($data, $loginHeaders);
            if (!$result) {
                echo '登陆失败' . PHP_EOL;
            }
            $isLogin = Api::getInstance()->uamtk($data['train_username']);
            if (!$isLogin) {
                echo '登陆验证失败' . PHP_EOL;
                \Co::sleep(20);
                return false;
            } else {
                echo '登陆成功' . PHP_EOL;
            }
        } else {
            echo '登陆正常'. PHP_EOL;
        }

        $this->loginInit(); //登陆初始化
        $ticketLogin = $this->ticketModel->getTicketLogin($data['train_username']);
        $headers = ['Cookie:JSESSIONID='. $this->jsSessionId . '; tk=' . $ticketLogin['newapptk']];
        //查询未完成订单
        $queryMyOrderNoComplete = Api::getInstance()->queryMyOrderNoComplete($headers);
        if (!empty($queryMyOrderNoComplete['data']['orderDBList'])) {
            $orderInfo = $this->getOrderInfo($queryMyOrderNoComplete['data']['orderDBList'][0]);
            echo '您有尚未完成的订单，订单信息 : ' . $orderInfo . '，请前往12306处理' . PHP_EOL;
        } else {
            $this->poll($data, $headers);
        }
    }

    /**
     * 登陆初始化，获取jsSessionId和bigIpServerOtn
     */
    public function loginInit() {
        $url = self::$url . 'otn/login/init';
        $headers = ['Upgrade-Insecure-Requests: 1'];
        $data = CURL($url, 0, '', $headers, 1);
        preg_match("/Set-Cookie: JSESSIONID=(.*);/", $data, $jsSessionId);
        preg_match("/Set-Cookie: BIGipServerotn=(.*);/", $data, $bigIpServerOtn);
        $this->jsSessionId = $jsSessionId[1];
        $this->bigIpServerOtn = $bigIpServerOtn[1];
        //var_dump($data);
        //\Co::sleep(60);
    }

    /**
     * 登陆数据产生
     * @param $data
     * @param $loginHeaders
     * @return bool|mixed
     */
    public function getLoginStorage($data, $loginHeaders) {
        $verifyCode = $this->ticketModel->getVerifyCode($data['id']);
        $answer = $this->coordinate($verifyCode);
        return Api::getInstance()->login($data['train_username'], $data['train_password'], $answer, $loginHeaders);
    }

    /**
     * 转换坐标
     * @param $num
     * @return string
     */
    public function coordinate($num)
    {
        $coor = [
            '1' => '40,40',
            '2' => '115,40',
            '3' => '175,40',
            '4' => '250,40',
            '5' => '40,128',
            '6' => '115,128',
            '7' => '175,128',
            '8' => '250,128',
        ];
        $numArr = explode(',', $num);
        $coorArr = [];
        foreach ($numArr as $v) {
            $coorArr[] = $coor[$v];
        }
        $str = implode(',', $coorArr);
        echo '坐标为：' . $str . PHP_EOL;
        return $str;
    }

    /**
     * 轮询检测余票并下单通知用户
     * @param $data
     * @param $headers
     */
    public function poll($data, $headers)
    {
        $i = 1;
        do {
            echo '------------------------------------' . PHP_EOL;
            echo '第' . $i . '次查询: ' . date('Y-m-d H:i:s') . PHP_EOL;
            $res = $this->query($data, $headers);
            $i++;
            if (self::$sleep) {
                \Co::sleep(self::$sleep);
            }
        } while (!$res);

        echo '抢票成功' . PHP_EOL;
        $this->ticketModel->updateTicketProcess($data['id'], 1);
        //查询未完成订单
        //$this->email();
    }

    /**
     * 查询车票并下单
     * @param $data
     * @param $headers
     * @return bool|mixed
     */
    public function query($data, $headers)
    {
        $trainStart = $this->favoriteNames[$data['fromStation']][2];
        $trainEnd = $this->favoriteNames[$data['toStation']][2];
        $url = self::$url . 'otn/leftTicket/query?leftTicketDTO.train_date=' . $data['train_date'] . '&leftTicketDTO.from_station=' . $trainStart . '&leftTicketDTO.to_station=' . $trainEnd . '&purpose_codes=ADULT';
        $curlData = GET($url);
        $dataArr = json_decode($curlData, true);
        $result = $dataArr['data']['result'];
        $order = 0;
        $trainType = $data['train_type'];
        if (empty($result)) {
            return false;
        }
        foreach ($result as &$v) {
            $v = explode('|', $v);
            $trainInfo = "车次[{$v[3]}]";
            if ($trainType === 'all') {
                // 任意车次
                foreach (self::TRAIN_TYPE as $typeNum => $typeName) {
                    if ($v[$typeNum] > 0 || $v[$typeNum] == '有') {
                        echo $trainInfo . '类型[' . $typeName . ']有票了！准备下单' . PHP_EOL;
                        $order = $v;
                        break 2;
                    } else {
                        echo $trainInfo . '类型[' . $typeName . ']无票' . PHP_EOL;
                        continue;
                    }
                }
            } else {
                //指定车次抢票
                if ($v[3] != $data['train_num']) {
                    continue;
                }
                if (($v[$trainType] > 0 || $v[$trainType] == '有')) {
                    echo $trainInfo . '类型[' . self::TRAIN_TYPE[$trainType] . ']有票了！准备下单' . PHP_EOL;
                    $order = $v;
                } else {
                    echo $trainInfo . '类型[' . self::TRAIN_TYPE[$trainType] . ']无票' . PHP_EOL;
                }
                break;
            }
        }
        if (empty($order)) {
            return false;
        } else {
            $result = $this->order($data, $order, $headers);
            return $result;
        }

    }

    /**
     * 整个下单流程
     * @param $data
     * @param $query
     * @param $headers
     * @return bool|mixed
     */
    public function order($data, $query, $headers) {
        // 用户权限验证
        $checkUser = CURL(self::$url . 'otn/login/checkUser', 1, 'json_att=', $headers);
        $checkUser = json_decode($checkUser, true);
        echo '----------------用户权限验证--------------------' . PHP_EOL;
        //var_dump($checkUser);
        if (!$checkUser['status']) {
            return $checkUser; //无权
        }

        // 提交订单请求
        $submitOrderRequest = Api::getInstance()->submitOrderRequest($data['fromStation'], $data['toStation'], $query, $headers);
        echo '----------------提交订单信息--------------------' . PHP_EOL;
        //var_dump($submitOrderRequest);
        if (!$submitOrderRequest['status']) {
            return false; // 订单失败
        }

        $html = CURL(self::$url . 'otn/confirmPassenger/initDc', 1, '_json_att=', $headers);
        //var_dump($html);
        preg_match("/var globalRepeatSubmitToken = '(.*)'/", $html, $repeat_submit_token);
        preg_match("/'key_check_isChange':'(.*?)'/", $html, $key_check_isChange);
        preg_match("/'leftTicketStr':'(.*?)'/", $html, $leftTicketStr);
        $key_check_isChange = $key_check_isChange[1] ?? '';
        $leftTicketStr = $leftTicketStr[1] ?? '';
        $repeat_submit_token = $repeat_submit_token[1] ?? '';
        if (empty($key_check_isChange) || empty($leftTicketStr)) {
            return false;
        }

        $my_name = $data['my_name'];
        $my_card = $data['my_card'];
        $my_phone = $data['my_phone'];

        //检查订单信息
        $checkOrderInfo = Api::getInstance()->checkOrderInfo($data['train_seat'], $data['user_auth'], $my_name, $my_card, $my_phone, $repeat_submit_token, $headers);
        echo '----------------检查订单信息--------------------' . PHP_EOL;
        //var_dump($checkOrderInfo);
        if (!$checkOrderInfo['status']) {
            return false;
        }

        //确认订单
        $confirmSingleForQueue = Api::getInstance()->confirmSingleForQueue($data['train_seat'], $data['user_auth'], $my_name, $my_card, $my_phone, $key_check_isChange, $leftTicketStr, $query, $repeat_submit_token, $headers);
        echo '----------------确认订单--------------------' . PHP_EOL;
        var_dump($confirmSingleForQueue);

        //等待订单结果
        if (empty($confirmSingleForQueue['data']['submitStatus'])) {
            return false;
        }

        $time = time();
        do {
            $queryOrderWaitTime = Api::getInstance()->queryOrderWaitTime($repeat_submit_token, $headers);
            \Co::sleep(1);
        } while (empty($queryOrderWaitTime['data']['orderId']) && (time() - $time) < 10);

        echo '----------------获取下单结果--------------------' . PHP_EOL;
        //var_dump($queryOrderWaitTime);
        if (empty($queryOrderWaitTime['data']['orderId'])) {
            return false;
        }

        //查询是否有未完成订单
        echo '----------------查询未完成订单--------------------' . PHP_EOL;
        do {
            $queryMyOrderNoComplete = Api::getInstance()->queryMyOrderNoComplete($headers);
            \Co::sleep(1);
        } while (empty($queryMyOrderNoComplete['data']['orderDBList']) && (time() - $time) < 10);

        if (empty($queryMyOrderNoComplete['data']['orderDBList'])) {
            return false;
        }
        return true;
    }

    /**
     * 获得详细车票信息
     * @param $orderInfo
     * @return string
     */
    public function getOrderInfo($orderInfo) {
        $passengerName = $orderInfo['tickets'][0]['passengerDTO']['passenger_name'];
        $orderDate = $orderInfo['order_date'];
        $trainCode = $orderInfo['train_code_page'];
        $fromStationName = $orderInfo['from_station_name_page'][0];
        $toStationName = $orderInfo['to_station_name_page'][0];
        $startTrainDate = $orderInfo['start_train_date_page'];
        $startTime = $orderInfo['start_time_page'];
        $arriveTime = $orderInfo['arrive_time_page'];
        $ticketTotalPrice = $orderInfo['ticket_total_price_page'];
        $coachName = $orderInfo['tickets'][0]['coach_name'];
        $seatName = $orderInfo['tickets'][0]['seat_name'];
        $orderInfo = "姓名[{$passengerName}] 车次[{$trainCode}] 出发站[{$fromStationName}] 到达站[{$toStationName}] 出发日期[{$startTrainDate}] 出发时间[{$startTime}] 到达时间[{$arriveTime}] 票价[{$ticketTotalPrice}] 车厢号[{$coachName}] 座位号[{$seatName}] 下单时间[{$orderDate}]";
        return $orderInfo;
    }
}