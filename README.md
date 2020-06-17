# 12306-tickets
12306抢票(EasySwoole实现)  
使用方法：  
将Data目录下的.example文件复制成.php文件，文件内容见注释  
php easyswoole start 启动
访问 http://ip:端口/index/ticket  
在表单页面填写个人信息列车信息以及验证码答案，验证通过后在数据库中插入一条抢票任务
后台启动自定义用户进程，查询数据库中未开始的抢票任务并登陆，开始查询余票和下单