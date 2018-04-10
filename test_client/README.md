### phpsocket.io (服务端)
* `php artisan workerman start` 运行


#### phpsocket.io 说明
* 文档: https://github.com/walkor/phpsocket.io/tree/master/docs/zh

* 当前项目文件 `app\Console\Commands\Workerman.php`:
    * `on()` 监听事件
    * `emit()` 触发事件


### socket.io (客户端)
* 进入项目的 `socket_test_client` 目录
* 运行 `php -S localhost:8000`,
* 在浏览器访问 `localhost:8000` 看到客户端效果
* 模拟 学生1 学生2 在 教室1 教室2：
    * http://localhost:8000/?room_id=room_1&student_id=1
    * http://localhost:8000/?room_id=room_1&student_id=2
    * http://localhost:8000/?room_id=room_2&student_id=1
    * http://localhost:8000/?room_id=room_2&student_id=2


#### socket.io 说明
* 文档: https://socket.io/docs/client-api/

* 当前项目文件 `socket_test_client\index.html`:
    * `on()` 监听事件
    * `emit()` 触发事件



### 示例说明
* socket.io 客户端连接
```
// 客户端连接或重新连成功时被触发
socket.on('connect', function() {
    console.log(socket.id); // 客户端的唯一标识符， 自动产生， 可以在服务端获取到

    ...
});
```

* socket.io 客户端登陆 (发送房间信息 用户信息)
```
// 向服务端发送 login 事件 -- 发送房间信息 / 用户信息
socket.emit('login', {
    'room_id': room_id, 
    'student_id': student_id
});
```

* phpsocket.io 服务端收到客户端连接
```
// 客户端发起连接事件时，设置连接socket的各种事件回调
$this->io->on('connection', function ($socket) {
    ...
});
```

* phpsocket.io 登陆处理
```
$socket->on('login', function ($loginInfo) use ($socket) {
    ...
});
```

* SASS 后台通过 phpsocket.io(服务端) 主动向 socket.io (客户端) 推送数据:
    * 通过访问连接(curl): http://localhost:9499/?type=publish&to=room_1&content=%E5%90%91%E5%BD%93%E5%89%8D%E6%88%BF%E9%97%B4%E5%8F%91%E9%80%81%E6%B6%88%E6%81%AF%20--%20TO:%20room_1
        * url参数： type, to, content (可能调整)
    * TODO: 把 GET 改成 POST 更合理

### 多个项目 socket 通信
* TODO: 建议弄成多个 xxx_start.php 去运行 phpsocket.io
    * 避免修改一个项目时影响到另一个项目