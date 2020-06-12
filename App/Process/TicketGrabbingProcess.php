<?php
namespace App\Process;

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
            $favoriteNames = json_decode(file_get_contents(self::$path . 'favorite_names.txt'), true);
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
                if (!$this->uamtk()) {
                    echo '重新登陆' . PHP_EOL;
                    $result = $this->getLoginStorage();
                    if (!$result) {
                        echo '登陆失败' . PHP_EOL;
                        break;
                    }
                    $isLogin = $this->uamtk();
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
                $queryMyOrderNoComplete = $this->queryMyOrderNoComplete($this->headers);
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
        $data = $this->CURL($url, 0, '', $headers, 1);
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
        // 获取验证码
        $data = $this->captchaImage();
        $this->saveImage($data['image'], self::$path . 'captchaImg.jpg');
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
        $result = $this->captchaCheck($answer);
        if ($result['result_code'] != 4) {
            echo '验证码校验失败' . PHP_EOL;
            return ['result' => -1, 'errorMsg' => '验证码校验失败'];
        }
        echo '验证码校验成功' . PHP_EOL;
        file_put_contents(self::$path . 'coorNum.txt', '');
        return $this->login($answer);
    }

    /**
     * 登陆12306
     * @param $answer
     * @return mixed
     * Date: 2019/1/12
     * Time: 09:43
     * Author: sym
     */
    private function login($answer) {
        $url = self::$url . 'passport/web/login';
        $get_data = [
            'username' => $this->userInfo['train_username'],
            'password' => $this->userInfo['train_password'],
            'appid'    => 'otn',
            'answer'   => $answer,
        ];
        $get_data = http_build_query($get_data);
        $data = $this->CURL($url, 1, $get_data, $this->loginHeaders);
        $arr = json_decode($data, true);
        if (isset($arr['result_code']) && $arr['result_code'] == 0) {
            file_put_contents(self::$path . 'uamtk.txt', $arr['uamtk']);
            return true;
        }
        return false;
    }

    /**
     * 验证是否登陆   验证cookie是否有效
     * Date: 2019/1/12
     * Time: 11:19
     * Author: sym
     */
    protected function uamtk() {
        $url = self::$url . 'passport/web/auth/uamtk';
        if (!is_file(self::$path . 'uamtk.txt')) {
            File::touchFile(self::$path . 'uamtk.txt');
        }
        $uamtk = file_get_contents(self::$path . 'uamtk.txt');
        // 获取newapptk
        $header = ['Cookie:uamtk=' . $uamtk, 'Content-Type: application/x-www-form-urlencoded; charset=UTF-8', 'Referer:https://www.12306.cn/index/index.html'];
        $uamtk = $this->CURL($url, 1, 'appid=otn', $header);
        $uamtk = json_decode($uamtk, true);
        if ($uamtk['result_code'] === 0) {
            file_put_contents(self::$path . 'newapptk.txt', $uamtk['newapptk']);
            return true;
        } else {
            echo $uamtk['result_message'] . PHP_EOL;
            return false;
        }
    }

    /**
     * 查询个人信息接口
     * @param $headers
     * @return mixed
     */
    public function initQueryUserInfoApi($headers) {
        $url = self::$url . 'otn/modifyUser/initQueryUserInfoApi';
        $userInfo = $this->CURL($url, 1, '', $headers);
        $userInfo = json_decode($userInfo, true);
        return $userInfo;
    }

    /**
     * 验证码校验
     * @param $answer
     * @return mixed
     */
    private function captchaCheck($answer)
    {
        $url = self::$url . 'passport/captcha/captcha-check?';
        $get_data = [
            'callback'   => 'jQuery19109551424646697575_1547039839380',
            'answer'     => $answer,
            'rand'       => 'sjrand',
            'login_site' => 'E',
        ];
        $url = $url . http_build_query($get_data);
        $data = $this->CURL($url, 1);
        $str = rtrim($data, ');');
        $str = ltrim($str, '/**/jQuery19109551424646697575_1547039839380(');
        $arr = json_decode($str, true);
        return $arr;
    }

    /**
     * 转换坐标
     * @param $num
     * @return string
     * Date: 2019/1/10
     * Time: 21:40
     * Author: sym
     */
    private function coordinate($num)
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
    function saveImage($base64ImgContent, $path){
        if (file_put_contents($path, base64_decode($base64ImgContent))) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * 获取验证码
     * @return mixed
     */
    protected function captchaImage() {
        $url = self::$url . 'passport/captcha/captcha-image64?';
        $get_data = [
            'login_site' => 'E',
            'module'     => 'login',
            'rand'       => 'sjrand',
            'callback'   => 'jQuery19109551424646697575_1547039839380',
        ];
        $url = $url . http_build_query($get_data);
        $data = $this->CURL($url);
        $str = rtrim($data, ');');
        $str = ltrim($str, '/**/jQuery19109551424646697575_1547039839380(');
        $arr = json_decode($str, true);
        return $arr;
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
        \Co::sleep(600);
        //查询未完成订单
        $queryMyOrderNoComplete = $this->queryMyOrderNoComplete($this->headers);
        echo '----------------查询未完成订单--------------------' . PHP_EOL;
//        var_dump($queryMyOrderNoComplete);
        \Co::sleep(60);
        //$this->email();
    }

    /**
     * 查询车票
     */
    public function query()
    {
        $url = self::$url . 'otn/leftTicket/query?leftTicketDTO.train_date=' . $this->trainInfo['train_date'] . '&leftTicketDTO.from_station=' . $this->train_start . '&leftTicketDTO.to_station=' . $this->train_end . '&purpose_codes=ADULT';
        $data = $this->get($url);
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
        $checkUser = $this->CURL(self::$url . 'otn/login/checkUser', 1, 'json_att=', $this->headers);
        $checkUser = json_decode($checkUser, true);
        echo '----------------用户权限验证--------------------' . PHP_EOL;
        //var_dump($checkUser);
        if (!$checkUser['status']) {
            return $checkUser; //无权
        }

        // 提交订单请求
        $submitOrderRequest = $this->submitOrderRequest($query, $this->headers);
        echo '----------------提交订单信息--------------------' . PHP_EOL;
        //var_dump($submitOrderRequest);
        if (!$submitOrderRequest['status']) {
            return false; // 订单失败
        }

        $html = $this->CURL(self::$url . 'otn/confirmPassenger/initDc', 1, '_json_att=', $this->headers);
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
        $checkOrderInfo = $this->checkOrderInfo($my_name, $my_card, $my_phone, $repeat_submit_token, $this->headers);
        echo '----------------检查订单信息--------------------' . PHP_EOL;
        //var_dump($checkOrderInfo);
        if (!$checkOrderInfo['status']) {
            return false;
        }

        //确认订单
        $confirmSingleForQueue = $this->confirmSingleForQueue($my_name, $my_card, $my_phone, $key_check_isChange, $leftTicketStr, $query, $repeat_submit_token, $this->headers);
        echo '----------------确认订单--------------------' . PHP_EOL;
        var_dump($confirmSingleForQueue);

        //等待订单结果
        if (empty($confirmSingleForQueue['data']['submitStatus'])) {
            return false;
        }

        $time = time();
        do {
            $queryOrderWaitTime = $this->queryOrderWaitTime($repeat_submit_token, $this->headers);
            \Co::sleep(1);
        } while (empty($queryOrderWaitTime['data']['orderId']) && (time() - $time) < 10);

        echo '----------------等待订单结果--------------------' . PHP_EOL;
        //var_dump($queryOrderWaitTime);
        if (empty($queryOrderWaitTime['data']['orderId'])) {
            return false;
        }

        //订购队列结果
        $orderId = $queryOrderWaitTime['data']['orderId'];
        $resultOrderForDcQueue = $this->resultOrderForDcQueue($orderId, $repeat_submit_token, $this->headers);
        echo '----------------订购队列结果--------------------' . PHP_EOL;
        //var_dump($resultOrderForDcQueue);
        if (empty($resultOrderForDcQueue['data']['submitStatus'])) {
            return false;
        }
        return true;
    }

    /**
     * 提交订单请求
     * @param $query
     * @param $headers
     * @return mixed
     */
    public function submitOrderRequest($query, $headers) {
        // 验证查询
        $data = [
            'secretStr'               => urldecode($query[0]),
            'tour_flag'               => 'dc',
            'purpose_codes'           => 'ADULT',
            'query_from_station_name' => $this->trainInfo['fromStation'],
            'query_to_station_name'   => $this->trainInfo['toStation'],
            'undefined'               => '',
//            'train_date'              => '2020-06-13',
//            'back_train_date'         => '2020-06-10',
        ];
        //var_dump($data);
        // 表单信息获取
        $submitOrderRequest = $this->CURL(self::$url . 'otn/leftTicket/submitOrderRequest', 1, http_build_query($data), $headers);
        $submitOrderRequest = json_decode($submitOrderRequest, true);
        return $submitOrderRequest;
    }

    /**
     * 检查订单信息
     * @param $my_name
     * @param $my_card
     * @param $my_phone
     * @param $repeat_submit_token
     * @param $headers
     * @return mixed
     */
    public function checkOrderInfo($my_name, $my_card, $my_phone, $repeat_submit_token, $headers) {
        $info_data = [
            'cancel_flag'          => 2,
            'bed_level_order_num' => '000000000000000000000000000000',
            'passengerTicketStr'  => $this->trainInfo['train_seat'] . ',0,1,' . $my_name . ',1,' . $my_card . ',' . $my_phone . ',N',
            'oldPassengerStr'     => $my_name . ',1,' . $my_card . ',1_',
            'tour_flag'           => 'dc',
            'randCode'            => '',
            'whatsSelect'         => 1,
            '_json_att'           => '',
            'REPEAT_SUBMIT_TOKEN' => $repeat_submit_token,
            'sessionId' => '',
            'sig' => '',
            'scene' => 'nc_login',
        ];
        //var_dump($info_data);
        $checkOrderInfo = $this->CURL(self::$url . 'otn/confirmPassenger/checkOrderInfo', 1, http_build_query($info_data), $headers);
        $checkOrderInfo = json_decode($checkOrderInfo, true);
        return $checkOrderInfo;
    }

    /**
     * 确认订单
     * @param $my_name
     * @param $my_card
     * @param $my_phone
     * @param $key_check_isChange
     * @param $leftTicketStr
     * @param $query
     * @param $repeat_submit_token
     * @param $headers
     * @return mixed
     */
    public function confirmSingleForQueue($my_name, $my_card, $my_phone, $key_check_isChange, $leftTicketStr, $query, $repeat_submit_token, $headers) {
        $post_data = [
            'passengerTicketStr'  => $this->trainInfo['train_seat'] . ',0,1,' . $my_name . ',1,' . $my_card . ',' . $my_phone . ',N',
            'oldPassengerStr'     => $my_name . ',1,' . $my_card . ',1_',
            'randCode'            => '',
            'purpose_codes'       => '00',
            'key_check_isChange'  => $key_check_isChange,
            'leftTicketStr'       => $leftTicketStr,
            'train_location'      => $query[15],
            'choose_seats'        => '',
            'seatDetailType'      => '000',
            'whatsSelect'         => '1',
            'roomType'            => '00',
            'dwAll'               => 'N',
            '_json_att'           => '',
            'REPEAT_SUBMIT_TOKEN' => $repeat_submit_token,
        ];
        //var_dump($post_data);
        $confirmSingleForQueue = $this->CURL(self::$url . 'otn/confirmPassenger/confirmSingleForQueue', 1, http_build_query($post_data), $headers);
        $confirmSingleForQueue = json_decode($confirmSingleForQueue, true);
        return $confirmSingleForQueue;
    }

    /**
     * 等待订单结果
     * @param $repeat_submit_token
     * @param $headers
     * @return mixed
     */
    public function queryOrderWaitTime($repeat_submit_token, $headers) {
        $request_data = [
            'random' => $this->getUnixTimestamp(),
            'tourFlag' => 'dc',
            '_json_att' => '',
            'REPEAT_SUBMIT_TOKEN' => $repeat_submit_token
        ];
        $queryOrderWaitTime = $this->CURL(self::$url . 'otn/confirmPassenger/queryOrderWaitTime', 1, http_build_query($request_data), $headers);
        $queryOrderWaitTime = json_decode($queryOrderWaitTime, true);
        return $queryOrderWaitTime;
    }

    /**
     * 订购队列结果
     * @param $orderId
     * @param $repeat_submit_token
     * @param $headers
     * @return mixed
     */
    public function resultOrderForDcQueue($orderId, $repeat_submit_token, $headers) {
        $url = self::$url . 'otn/confirmPassenger/resultOrderForDcQueue';
        $request_data = [
            'orderSequence_no' => $orderId,
            'REPEAT_SUBMIT_TOKEN' => $repeat_submit_token,
            '_json_att' => ''
        ];
        $resultOrderForDcQueue = $this->CURL($url, 1, http_build_query($request_data), $headers);
        $resultOrderForDcQueue = json_decode($resultOrderForDcQueue, true);
        return $resultOrderForDcQueue;
    }

    /**
     * 查询未完成订单
     * @param $headers
     * @return mixed
     */
    public function queryMyOrderNoComplete($headers) {
        $url = self::$url . 'otn/queryOrder/queryMyOrderNoComplete';
        $queryMyOrderNoComplete = $this->CURL($url, 1, 'json_att=', $headers);
        $queryMyOrderNoComplete = json_decode($queryMyOrderNoComplete, true);
        return $queryMyOrderNoComplete;
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

    /**
     * 获取13位时间戳
     * @return float
     */
    public function getUnixTimestamp ()
    {
        list($s1, $s2) = explode(' ', microtime());
        return (int)sprintf('%.0f',(floatval($s1) + floatval($s2)) * 1000);
    }

    /**
     * post/get 请求
     * @param $url
     * @param int $cookie
     * @param string $post_data
     * @param array $headers
     * @param int $preserveHeaders
     * @return mixed
     */
    private function CURL($url, $cookie = 0, $post_data = '', $headers = [], $preserveHeaders = 0) {
        //初始化
        $curl = curl_init();
        //设置抓取的url
        curl_setopt($curl, CURLOPT_URL, $url);
        //设置头文件的信息作为数据流输出
        curl_setopt($curl, CURLOPT_HEADER, $preserveHeaders);
        curl_setopt($curl, CURLOPT_TIMEOUT, 10);    //设置本机的post请求超时时间
        // 头信息
        $header = [
            'User-Agent:Mozilla/5.0 (Macintosh; Intel Mac OS X 10_12_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/71.0.3578.98 Safari/537.36',
            'Host:kyfw.12306.cn',
            'Origin:https://kyfw.12306.cn',
        ];
        if (!empty($headers)) {
            $header = array_merge_recursive($header, $headers);
        }
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $header);

        if ($cookie) {
            curl_setopt($curl, CURLOPT_COOKIEFILE, self::$path . 'cookie.txt');//要发送的cookie文件
        }
        //设置post方式提交
        if (!empty($post_data)) {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $post_data);
        }
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); // 跳过证书检查
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);  // 从证书中检查SSL加密算法是否存在
        curl_setopt($curl, CURLOPT_COOKIEJAR, self::$path . 'cookie.txt');//获取的cookie 保存到指定的 文件路径，我这里是相对路径，可以是$变量

        //执行命令
        $data = curl_exec($curl);
        //关闭URL请求
        curl_close($curl);
        return $data;
    }

    /**
     * 模拟get
     * @param $url
     * @return mixed
     */
    private function get($url)
    {
        $ch = curl_init();
        $header = [
            'User-Agent:Mozilla/5.0 (Macintosh; Intel Mac OS X 10_12_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/71.0.3578.98 Safari/537.36',
            'Host:kyfw.12306.cn',
            'Cookie:JSESSIONID=111'
        ];
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, 0);//0代表不输出头文件信息
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // 跳过证书检查
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);  // 从证书中检查SSL加密算法是否存在
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }
}