<?php


namespace App\HttpController;


use App\Model\Ticket;
use EasySwoole\EasySwoole\Config;
use EasySwoole\Http\AbstractInterface\Controller;
use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\Config as MysqliConf;
use EasySwoole\Template\Render;
use App\Service\Api;

class Index extends Controller
{

    public function index()
    {
        $file = EASYSWOOLE_ROOT.'/vendor/easyswoole/easyswoole/src/Resource/Http/welcome.html';
        if(!is_file($file)){
            $file = EASYSWOOLE_ROOT.'/src/Resource/Http/welcome.html';
        }
        $this->response()->write(file_get_contents($file));
    }

    public function ticket() {
        $captchaImg = Api::getInstance()->captchaImage();
        $imgSrc = $captchaImg['image'];
        $this->response()->write(Render::getInstance()->render('index.html', ['imgSrc' => 'data:image/jpg;base64,' . $imgSrc]));
    }

    /**
     * 增加抢票任务
     * @return bool
     */
    public function addTicketTask() {
        $request = $this->request();
        $params = $request->getRequestParam();
        foreach ($params as $value) {
            if (empty($value)) {
                return $this->writeJson(200, ['result' => false, 'errMsg' => '信息不可为空'], 'fail');
            }
        }
        $verifyResult = $this->verifyCaptcha($params['verify_code']);
        if (!$verifyResult) {
            return $this->writeJson(200, ['result' => false, 'errMsg' => '验证码校验失败'], 'fail');
        }
        $ticketModel = new Ticket();
        $result = $ticketModel->addTicketProcess($params);
        if ($result) {
            $this->writeJson(200, ['result' => true, 'errMsg' => ''], 'success');
        } else {
            $this->writeJson(200, ['result' => false, 'errMsg' => '插入记录失败'], 'success');
        }
    }

    /**
     * 验证码校验
     * @param $verifyCode
     * @return bool
     */
    public function verifyCaptcha($verifyCode) {
        $answer = $this->coordinate($verifyCode);
        $result = Api::getInstance()->captchaCheck($answer); // 验证码校验
        if ($result['result_code'] != 4) {
            return false;
        }
        return true;
    }

    /**
     * 转换坐标
     * @param $num
     * @return string
     */
    public function coordinate($num) {
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

    protected function actionNotFound(?string $action)
    {
        $this->response()->withStatus(404);
        $file = EASYSWOOLE_ROOT.'/vendor/easyswoole/easyswoole/src/Resource/Http/404.html';
        if(!is_file($file)){
            $file = EASYSWOOLE_ROOT.'/src/Resource/Http/404.html';
        }
        $this->response()->write(file_get_contents($file));
    }
}