<?php
namespace App\Model;

use EasySwoole\EasySwoole\Config;
use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\Config as MysqliConf;

class Ticket {
    private $mysqli;
    public function __construct()
    {
        if (empty($this->mysqli)) {
            $mysqliConfig = new MysqliConf(Config::getInstance()->getConf('mysqli'));
            $this->mysqli = new Client($mysqliConfig);
        }
    }

    public function getTicketProcess() {
        $sql = "select * from ticketProcess where ticket_status = 0 order by id";
        $this->mysqli->queryBuilder()->raw($sql);
        return $this->mysqli->execBuilder();
    }

    public function addTicketProcess($params) {
        $time = date('Y-m-d H:i:s');
        $sql = "insert into ticketProcess(train_username,train_password,my_name,my_card,my_phone,user_auth,verify_code,fromStation,toStation,train_date,train_num,train_type,train_seat,ticket_status,create_date,update_date) 
                values('{$params['train_username']}','{$params['train_password']}','{$params['my_name']}','{$params['my_card']}','{$params['my_phone']}','{$params['user_auth']}','{$params['verify_code']}','{$params['fromStation']}','{$params['toStation']}','{$params['train_date']}','{$params['train_num']}',{$params['train_type']},'{$params['train_seat']}',0,'{$time}','{$time}')";
        $this->mysqli->queryBuilder()->raw($sql);
        return $this->mysqli->execBuilder();
    }

    public function updateTicketProcess($id, $status) {
        $sql = "update ticketProcess set ticket_status = {$status} where id = {$id}";
        $this->mysqli->queryBuilder()->raw($sql);
        return $this->mysqli->execBuilder();
    }

    public function getVerifyCode($id) {
        $this->mysqli->queryBuilder()->raw("select verify_code from ticketProcess where id = {$id}");
        $result = $this->mysqli->execBuilder();
        if (!empty($result)) {
            return $result[0]['verify_code'];
        }
        return 0;
    }

    public function getTicketLogin($trainUsername) {
        $this->mysqli->queryBuilder()->raw("select * from ticketLogin where train_username = '{$trainUsername}'");
        $result = $this->mysqli->execBuilder();
        if (!empty($result)) {
            return $result[0];
        }
        return [];
    }

    public function updateUmatk($trainUsername, $umatk) {
        $date = date('Y-m-d H:i:s');
        $this->mysqli->queryBuilder()->raw("replace into ticketLogin(train_username,umatk,create_date,update_date) values('{$trainUsername}','{$umatk}','{$date}','{$date}')");
        return $this->mysqli->execBuilder();
    }

    public function updateNewApptk($trainUsername, $newApptk) {
        $this->mysqli->queryBuilder()->raw("update ticketLogin set newapptk = '{$newApptk}' where train_username = '{$trainUsername}'");
        return $this->mysqli->execBuilder();
    }
}