<?php
namespace App\Service;

use EasySwoole\Component\Singleton;

/**
 * 12306通用接口
 * Class Api
 * @package App\Service
 */
class Api {
    use Singleton;

    /**
     * 获取站点信息
     * @param $url
     * @return array
     */
    public function getStationName($url) {
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
     * 查询订单结果
     * @param $url
     * @param $repeat_submit_token
     * @param $headers
     * @return mixed
     */
    public function queryOrderWaitTime($url, $repeat_submit_token, $headers) {
        $request_data = [
            'random' => getUnixTimestamp(),
            'tourFlag' => 'dc',
            '_json_att' => '',
            'REPEAT_SUBMIT_TOKEN' => $repeat_submit_token
        ];
        $queryOrderWaitTime = CURL($url . 'otn/confirmPassenger/queryOrderWaitTime', 1, http_build_query($request_data), $headers);
        $queryOrderWaitTime = json_decode($queryOrderWaitTime, true);
        return $queryOrderWaitTime;
    }

    /**
     * 查询未完成订单
     * @param $url
     * @param $headers
     * @return mixed
     */
    public function queryMyOrderNoComplete($url, $headers) {
        $url = $url . 'otn/queryOrder/queryMyOrderNoComplete';
        $queryMyOrderNoComplete = CURL($url, 1, 'json_att=', $headers);
        $queryMyOrderNoComplete = json_decode($queryMyOrderNoComplete, true);
        return $queryMyOrderNoComplete;
    }
}