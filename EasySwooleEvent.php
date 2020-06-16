<?php
namespace EasySwoole\EasySwoole;


use App\Process\TicketGrabbingProcess;
use App\Service\Smarty;
use EasySwoole\EasySwoole\Swoole\EventRegister;
use EasySwoole\EasySwoole\AbstractInterface\Event;
use EasySwoole\Http\Request;
use EasySwoole\Http\Response;
use EasySwoole\Template\Render;

class EasySwooleEvent implements Event
{

    public static function initialize()
    {
        // TODO: Implement initialize() method.
        date_default_timezone_set('Asia/Shanghai');
    }

    public static function mainServerCreate(EventRegister $register)
    {
        //在全局的主服务中创建事件中，实例化该Render,并注入你的驱动配置
        Render::getInstance()->getConfig()->setRender(new Smarty());
        Render::getInstance()->getConfig()->setTempDir(EASYSWOOLE_TEMP_DIR);
        Render::getInstance()->attachServer(ServerManager::getInstance()->getSwooleServer());
        // TODO: Implement mainServerCreate() method.
        $server = ServerManager::getInstance()->getSwooleServer();
        $server->addProcess((new TicketGrabbingProcess('ticketGrabbingProcess'))->getProcess());
    }

    public static function onRequest(Request $request, Response $response): bool
    {
        // TODO: Implement onRequest() method.
        return true;
    }

    public static function afterRequest(Request $request, Response $response): void
    {
        // TODO: Implement afterAction() method.
    }
}