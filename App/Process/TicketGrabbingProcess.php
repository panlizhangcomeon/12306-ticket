<?php
namespace App\Process;

use App\Service\Api;
use EasySwoole\Component\Process\AbstractProcess;
use EasySwoole\Utility\File;

class TicketGrabbingProcess extends AbstractProcess {

    private static $url;
    private static $sleep;
    private static $path;

    protected $train_start;
    protected $train_end;

    protected $userInfo = [];  //用户信息
    protected $trainInfo = [];  //车次信息
    private $headers = []; //header信息
    private $loginHeaders; //登陆的header信息

    private $jsSessionId;
    private $bigIpServerOtn;

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
    }

    /**
     * 将用户信息和车次信息初始化到静态变量
     */
    private function init() {
        //加载车次信息
        if (empty($this->trainInfo)) {
            $this->trainInfo = include_once (self::$path . 'trainInfo.php');
        }
        //加载个人信息
        if (empty($this->userInfo)) {
            $this->userInfo = include_once (self::$path . 'userInfo.php');
        }

        // 加载站点
        if (empty($this->train_start) || empty($this->train_end)) {
            $favoriteNames = Api::getInstance()->getStationName();
            $this->train_start = $favoriteNames[$this->trainInfo['fromStation']][2];
            $this->train_end = $favoriteNames[$this->trainInfo['toStation']][2];
        }
    }

    protected function run($arg) {
        // TODO: Implement run() method.
        go(function () {
            while (true) {
                $this->init(); //初始化用户信息和车次信息
                $this->loginInit(); //登陆初始化

                if (empty($this->headers)) {
                    $cookie = include_once (self::$path . 'cookie.php');
                    $this->loginHeaders = ['Cookie:RAIL_EXPIRATION=' . $cookie['RAIL_EXPIRATION'] . '; RAIL_DEVICEID=' . $cookie['RAIL_DEVICEID']];
                }

                //检查登陆状态，状态异常则重新登陆
                if (!Api::getInstance()->uamtk()) {
                    echo '重新登陆' . PHP_EOL;
                    $result = $this->getLoginStorage();
                    if (!$result) {
                        echo '登陆失败' . PHP_EOL;
                        break;
                    }
                    $isLogin = Api::getInstance()->uamtk();
                    if (!$isLogin) {
                        echo '登陆验证失败' . PHP_EOL;
                        break;
                    }
                    echo '登陆成功' . PHP_EOL;
                } else {
                    echo '登陆正常'. PHP_EOL;
                }

                $this->headers = ['Cookie:JSESSIONID='. $this->jsSessionId . '; tk=' . file_get_contents(self::$path . 'newapptk.txt')];
                //查询未完成订单
                $queryMyOrderNoComplete = Api::getInstance()->queryMyOrderNoComplete($this->headers);
                if (!empty($queryMyOrderNoComplete['data']['orderDBList'])) {
                    $orderInfo = $this->getOrderInfo($queryMyOrderNoComplete['data']['orderDBList'][0]);
                    echo '您有尚未完成的订单，订单信息 : ' . $orderInfo . '，请前往12306处理' . PHP_EOL;
                } else {
                    $this->poll();
                }
                \Co::sleep(60);
            }
        });
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
     */
    public function getLoginStorage() {
        $data = Api::getInstance()->captchaImage(); // 获取验证码
        $this->saveImage($data['image'], self::$path . 'captchaImg.jpg'); // 保存验证码图片
        if (!is_file(self::$path . 'coorNum.txt')) {
            File::touchFile(self::$path . 'coorNum.txt');
        }
        $num = file_get_contents(self::$path . 'coorNum.txt');
        while (empty($num)) {
            //每隔一秒从文件中读取验证码结果
            \Co::sleep(1);
            $num = file_get_contents(self::$path . 'coorNum.txt');
        }
        $answer = $this->coordinate($num);
        $result = Api::getInstance()->captchaCheck($answer); // 验证码校验
        if ($result['result_code'] != 4) {
            echo '验证码校验失败' . PHP_EOL;
            return ['result' => -1, 'errorMsg' => '验证码校验失败'];
        }
        echo '验证码校验成功' . PHP_EOL;
        file_put_contents(self::$path . 'coorNum.txt', '');
        return Api::getInstance()->login($this->userInfo['train_username'], $this->userInfo['train_password'], $answer, $this->loginHeaders);
    }

    /**
     * 转换坐标
     * @param $num
     * @return string
     * Date: 2019/1/10
     * Time: 21:40
     * Author: sym
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
        $numArr = str_split($num);
        $coorArr = [];
        foreach ($numArr as $v) {
            $coorArr[] = $coor[$v];
        }
        $str = implode(',', $coorArr);
        echo '坐标为：' . $str . PHP_EOL;
        return $str;
    }

    /**
     * 保存图片
     * @param $base64ImgContent
     * @param $path
     * @return bool
     */
    public function saveImage($base64ImgContent, $path){
        if (file_put_contents($path, base64_decode($base64ImgContent))) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * 轮询检测余票并下单通知用户
     * Date: 2019/1/13
     * Time: 12:23
     * Author: sym
     */
    public function poll()
    {
        $i = 1;
        do {
            echo '------------------------------------' . PHP_EOL;
            echo '第' . $i . '次查询: ' . date('Y-m-d H:i:s') . PHP_EOL;
            $res = $this->query();
            $i++;
            if (self::$sleep) {
                \Co::sleep(self::$sleep);
            }
        } while (!$res);

        echo '抢票成功' . PHP_EOL;
        //查询未完成订单
        \Co::sleep(60);
        //$this->email();
    }

    /**
     * 查询车票并下单
     */
    public function query()
    {
        $url = self::$url . 'otn/leftTicket/query?leftTicketDTO.train_date=' . $this->trainInfo['train_date'] . '&leftTicketDTO.from_station=' . $this->train_start . '&leftTicketDTO.to_station=' . $this->train_end . '&purpose_codes=ADULT';
        $data = GET($url);
        $data_arr = json_decode($data, true);
        $result = $data_arr['data']['result'];
        $order = 0;
        $trainType = $this->trainInfo['train_type'];
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
                if ($v[3] != $this->trainInfo['train_num']) {
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
            $result = $this->order($order);
            return $result;
        }

    }

    //整个下单流程
    public function order($query) {
        // 用户权限验证
        $checkUser = CURL(self::$url . 'otn/login/checkUser', 1, 'json_att=', $this->headers);
        $checkUser = json_decode($checkUser, true);
        echo '----------------用户权限验证--------------------' . PHP_EOL;
        //var_dump($checkUser);
        if (!$checkUser['status']) {
            return $checkUser; //无权
        }

        // 提交订单请求
        $submitOrderRequest = Api::getInstance()->submitOrderRequest($this->trainInfo['fromStation'], $this->trainInfo['toStation'], $query, $this->headers);
        echo '----------------提交订单信息--------------------' . PHP_EOL;
        //var_dump($submitOrderRequest);
        if (!$submitOrderRequest['status']) {
            return false; // 订单失败
        }

        $html = CURL(self::$url . 'otn/confirmPassenger/initDc', 1, '_json_att=', $this->headers);
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

        $my_name = $this->userInfo['my_name'];
        $my_card = $this->userInfo['my_card'];
        $my_phone = $this->userInfo['my_phone'];

        //检查订单信息
        $checkOrderInfo = Api::getInstance()->checkOrderInfo($this->trainInfo['train_seat'], $this->userInfo['user_auth'], $my_name, $my_card, $my_phone, $repeat_submit_token, $this->headers);
        echo '----------------检查订单信息--------------------' . PHP_EOL;
        //var_dump($checkOrderInfo);
        if (!$checkOrderInfo['status']) {
            return false;
        }

        //确认订单
        $confirmSingleForQueue = Api::getInstance()->confirmSingleForQueue($this->trainInfo['train_seat'], $this->userInfo['user_auth'], $my_name, $my_card, $my_phone, $key_check_isChange, $leftTicketStr, $query, $repeat_submit_token, $this->headers);
        echo '----------------确认订单--------------------' . PHP_EOL;
        var_dump($confirmSingleForQueue);

        //等待订单结果
        if (empty($confirmSingleForQueue['data']['submitStatus'])) {
            return false;
        }

        $time = time();
        do {
            $queryOrderWaitTime = Api::getInstance()->queryOrderWaitTime($repeat_submit_token, $this->headers);
            \Co::sleep(1);
        } while (empty($queryOrderWaitTime['data']['orderId']) && (time() - $time) < 10);

        echo '----------------获取下单结果--------------------' . PHP_EOL;
        //var_dump($queryOrderWaitTime);
        if (empty($queryOrderWaitTime['data']['orderId'])) {
            return false;
        }

        //查询是否有未完成订单
        echo '----------------查询未完成订单--------------------' . PHP_EOL;
        $queryMyOrderNoComplete = Api::getInstance()->queryMyOrderNoComplete($this->headers);
        if (!empty($queryMyOrderNoComplete['data']['orderDBList'])) {
            return true;
        }
        return false;
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