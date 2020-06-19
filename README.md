# 12306-tickets
12306抢票(EasySwoole实现)  
使用方法：  
php easyswoole start 启动  
详细命令可点击EasySwoole官网 https://www.easyswoole.com/  
访问 http://ip:端口/index/ticket  
在表单页面填写个人信息、列车信息、验证码答案、设备信息，验证通过后往数据库中插入一条抢票任务  
后台启动自定义用户进程，查询数据库中未开始的抢票任务并登陆，开始查询余票和下单