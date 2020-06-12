<?php
if (!function_exists('CURL')) {
    /**
     * post/get 请求
     * @param $url
     * @param int $cookie
     * @param string $post_data
     * @param array $headers
     * @param int $preserveHeaders
     * @return mixed
     */
    function CURL($url, $cookie = 0, $post_data = '', $headers = [], $preserveHeaders = 0) {
        $path = EASYSWOOLE_ROOT . '/Data/';
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
            curl_setopt($curl, CURLOPT_COOKIEFILE, $path . 'cookie.txt');//要发送的cookie文件
        }
        //设置post方式提交
        if (!empty($post_data)) {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $post_data);
        }
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); // 跳过证书检查
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);  // 从证书中检查SSL加密算法是否存在
        curl_setopt($curl, CURLOPT_COOKIEJAR, $path . 'cookie.txt');//获取的cookie 保存到指定的 文件路径，我这里是相对路径，可以是$变量

        //执行命令
        $data = curl_exec($curl);
        //关闭URL请求
        curl_close($curl);
        return $data;
    }
}

if (!function_exists('GET')) {
    /**
     * 模拟get
     * @param $url
     * @return mixed
     */
    function GET($url)
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

if (!function_exists('getUnixTimestamp')) {
    /**
     * 获取13位时间戳
     * @return float
     */
    function getUnixTimestamp ()
    {
        list($s1, $s2) = explode(' ', microtime());
        return (int)sprintf('%.0f',(floatval($s1) + floatval($s2)) * 1000);
    }
}