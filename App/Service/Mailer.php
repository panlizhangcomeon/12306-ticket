<?php
namespace App\Service;

use EasySwoole\Component\Singleton;
use EasySwoole\EasySwoole\Logger;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Mailer extends PHPMailer{

    use Singleton;

    public function __construct($exceptions = null)
    {
        parent::__construct($exceptions);
        $this->SMTPDebug = 1; // 是否启用smtp的debug进行调试 开发环境建议开启 生产环境注释掉即可 默认关闭debug调试模式
        $this->isSMTP(); // 使用smtp鉴权方式发送邮件
        $this->SMTPAuth = true; // smtp需要鉴权 这个必须是true
        $this->Host = 'smtp.qq.com'; // 链接qq域名邮箱的服务器地址
        $this->SMTPSecure = 'ssl'; // 设置使用ssl加密方式登录鉴权
        $this->Port = 465; // 设置ssl连接smtp服务器的远程服务器端口号
        $this->CharSet = 'UTF-8'; // 设置发送的邮件的编码
        $this->setFrom('***@***.com','抢票成功提醒');
        $this->Username = '***@**.com'; // smtp登录的账号 QQ邮箱即可
        $this->Password = '***'; // smtp登录的密码 使用生成的授权码
    }

    /**
     * 发送邮件
     * @param $receiver
     * @param $subject
     * @param $body
     */
    public function sendEmail($receiver, $subject, $body) {
        try {
            $this->isHTML(true); // 邮件正文是否为html编码 注意此处是一个方法
            $this->addAddress($receiver); // 设置收件人邮箱地址
            $this->Subject = $subject; // 添加该邮件的主题
            $this->Body = $body; //添加邮件正文
            $res = $this->send(); //发送邮件
            var_dump($res);
        } catch (Exception $exception) {
            Logger::getInstance()->log('发送邮件失败，错误信息: ' . $exception->getMessage(), Logger::LOG_LEVEL_ERROR, 'SEND_EMAIL');
        }

    }

}