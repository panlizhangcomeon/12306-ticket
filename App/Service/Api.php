<?php
namespace App\Service;

use App\Model\Ticket;
use EasySwoole\Component\Singleton;

/**
 * 12306通用接口
 * Class Api
 * @package App\Service
 */
class Api {
    use Singleton;

    private static $url = 'https://kyfw.12306.cn/';
    private static $path = EASYSWOOLE_ROOT . '/Data/';
    private $ticketModel;

    public function __construct()
    {
        $this->ticketModel = new Ticket();
    }

    /**
     * 获取站点信息
     * @return array
     */
    public function getStationName() {
        $url = self::$url . 'otn/resources/js/framework/station_name.js';
        $data = [];
        $stationNameStr = CURL($url);
        $stationNameArr = explode('@', $stationNameStr);
        foreach ($stationNameArr as $value) {
            $tmp = explode('|', $value);
            $city = $tmp[1] ?? '';
            $data[$city] = $tmp;
        }
        return $data;
    }

    /**
     * 获取验证码
     * @return mixed
     */
    public function captchaImage() {
        $url = self::$url . 'passport/captcha/captcha-image64?';
        $get_data = [
            'login_site' => 'E',
            'module'     => 'login',
            'rand'       => 'sjrand',
            'callback'   => 'jQuery19109551424646697575_1547039839380',
        ];
        $url = $url . http_build_query($get_data);
        $data = CURL($url);
        $str = rtrim($data, ');');
        $str = ltrim($str, '/**/jQuery19109551424646697575_1547039839380(');
        $arr = json_decode($str, true);
        return $arr;
    }

    /**
     * 验证码校验
     * @param $answer
     * @return mixed
     */
    public function captchaCheck($answer)
    {
        $url = self::$url . 'passport/captcha/captcha-check?';
        $get_data = [
            'callback'   => 'jQuery19109551424646697575_1547039839380',
            'answer'     => $answer,
            'rand'       => 'sjrand',
            'login_site' => 'E',
        ];
        $url = $url . http_build_query($get_data);
        $data = CURL($url, 1);
        $str = rtrim($data, ');');
        $str = ltrim($str, '/**/jQuery19109551424646697575_1547039839380(');
        $arr = json_decode($str, true);
        return $arr;
    }

    /**
     * 登陆12306
     * @param $username
     * @param $password
     * @param $answer
     * @param $headers
     * @return mixed
     */
    public function login($username, $password, $answer, $headers) {
        $url = self::$url . 'passport/web/login';
        $get_data = [
            'username' => $username,
            'password' => $password,
            'appid'    => 'otn',
            'answer'   => $answer,
        ];
        $get_data = http_build_query($get_data);
        $data = CURL($url, 1, $get_data, $headers);
        $arr = json_decode($data, true);
        if (isset($arr['result_code']) && $arr['result_code'] == 0) {
            $this->ticketModel->updateUmatk($username, $arr['uamtk']);
            return true;
        }
        return false;
    }

    /**
     * 验证是否登陆   验证cookie是否有效
     */
    public function uamtk($trainUsername) {
        $url = self::$url . 'passport/web/auth/uamtk';
        $uamtkData = $this->ticketModel->getTicketLogin($trainUsername);
        $uamtk = $uamtkData['umatk'] ?? '';
        // 获取newapptk
        $header = ['Cookie:uamtk=' . $uamtk, 'Content-Type: application/x-www-form-urlencoded; charset=UTF-8', 'Referer:https://www.12306.cn/index/index.html'];
        $uamtk = CURL($url, 1, 'appid=otn', $header);
        $uamtk = json_decode($uamtk, true);
        if ($uamtk['result_code'] === 0) {
            $this->ticketModel->updateNewApptk($trainUsername, $uamtk['newapptk']);
            return true;
        } else {
            echo $uamtk['result_message'] . PHP_EOL;
            return false;
        }
    }

    /**
     * 提交订单请求
     * @param $fromStation
     * @param $toStation
     * @param $query
     * @param $headers
     * @return mixed
     */
    public function submitOrderRequest($fromStation, $toStation, $query, $headers) {
        // 验证查询
        $data = [
            'secretStr'               => urldecode($query[0]),
            'tour_flag'               => 'dc',
            'purpose_codes'           => 'ADULT',
            'query_from_station_name' => $fromStation,
            'query_to_station_name'   => $toStation,
            'undefined'               => '',
//            'train_date'              => '2020-06-13',
//            'back_train_date'         => '2020-06-10',
        ];
        //var_dump($data);
        // 表单信息获取
        $submitOrderRequest = CURL(self::$url . 'otn/leftTicket/submitOrderRequest', 1, http_build_query($data), $headers);
        $submitOrderRequest = json_decode($submitOrderRequest, true);
        return $submitOrderRequest;
    }

    /**
     * 检查订单信息
     * @param $trainSeat
     * @param $userAuth
     * @param $my_name
     * @param $my_card
     * @param $my_phone
     * @param $repeat_submit_token
     * @param $headers
     * @return mixed
     */
    public function checkOrderInfo($trainSeat, $userAuth, $my_name, $my_card, $my_phone, $repeat_submit_token, $headers) {
        $info_data = [
            'cancel_flag'          => 2,
            'bed_level_order_num' => '000000000000000000000000000000',
            'passengerTicketStr'  => $trainSeat . ',0,1,' . $my_name . ',1,' . $my_card . ',' . $my_phone . ',N,' . $userAuth,
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
        $checkOrderInfo = CURL(self::$url . 'otn/confirmPassenger/checkOrderInfo', 1, http_build_query($info_data), $headers);
        $checkOrderInfo = json_decode($checkOrderInfo, true);
        return $checkOrderInfo;
    }

    /**
     * 确认订单
     * @param $trainSeat
     * @param $userAuth
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
    public function confirmSingleForQueue($trainSeat, $userAuth, $my_name, $my_card, $my_phone, $key_check_isChange, $leftTicketStr, $query, $repeat_submit_token, $headers) {
        $post_data = [
            'passengerTicketStr'  => $trainSeat . ',0,1,' . $my_name . ',1,' . $my_card . ',' . $my_phone . ',N,' . $userAuth,
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
        $confirmSingleForQueue = CURL(self::$url . 'otn/confirmPassenger/confirmSingleForQueue', 1, http_build_query($post_data), $headers);
        $confirmSingleForQueue = json_decode($confirmSingleForQueue, true);
        return $confirmSingleForQueue;
    }

    /**
     * 查询订单结果
     * @param $repeat_submit_token
     * @param $headers
     * @return mixed
     */
    public function queryOrderWaitTime($repeat_submit_token, $headers) {
        $request_data = [
            'random' => getUnixTimestamp(),
            'tourFlag' => 'dc',
            '_json_att' => '',
            'REPEAT_SUBMIT_TOKEN' => $repeat_submit_token
        ];
        $queryOrderWaitTime = CURL(self::$url . 'otn/confirmPassenger/queryOrderWaitTime', 1, http_build_query($request_data), $headers);
        $queryOrderWaitTime = json_decode($queryOrderWaitTime, true);
        return $queryOrderWaitTime;
    }

    /**
     * 查询未完成订单
     * @param $headers
     * @return mixed
     */
    public function queryMyOrderNoComplete($headers) {
        $url = self::$url . 'otn/queryOrder/queryMyOrderNoComplete';
        $queryMyOrderNoComplete = CURL($url, 1, 'json_att=', $headers);
        $queryMyOrderNoComplete = json_decode($queryMyOrderNoComplete, true);
        return $queryMyOrderNoComplete;
    }

    /**
     * 查询个人信息接口
     * @param $headers
     * @return mixed
     */
    public function initQueryUserInfoApi($headers) {
        $url = self::$url . 'otn/modifyUser/initQueryUserInfoApi';
        $userInfo = CURL($url, 1, '', $headers);
        $userInfo = json_decode($userInfo, true);
        return $userInfo;
    }
}