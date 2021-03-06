<?php

/**
 * 命令行运行示例
 * php run.php start 以debug（调试）方式启动
 * php run.php start -d 以daemon（守护进程）方式启动
 * php run.php stop 停止
 * php run.php restart 重启
 * php run.php reload 平滑重启
 * php run.php status 查看状态
 *
 */

require __DIR__ . '/vendor/autoload.php';

use PHPSocketIO\SocketIO;
use Workerman\Worker;
use Workerman\Lib\Timer;

/**
 * 参考来源: https://github.com/walkor/web-msg-sender/blob/master/start_io.php
 *
 * Class Run
 */
class Run
{
    const APP_ENV = 'local'; // local product

    const HTTS_PEM = '/usrdata/cert/test.com/pem';

    const HTTPS_KEY = '/usrdata/cert/test.com/key';

    const SOCKET_DOMAIN = 'oa.test.app';

    const SOCKET_PORT_SAAS = 9600;

    const SOCKET_WORKER_NUM = 2;

    public $socketIO;

    public $httpWorker;

    // 全局数组保存 uid 在线数据
    public $uidConnectionMap = array();

    // 记录最后一次广播的在线用户数
    public $last_online_count = 0;

    // 记录最后一次广播的在线页面数
    public $last_online_page_count = 0;

    protected $argv = [];

    public function __construct()
    {
        global $argv;

        $this->argv = $argv;

        $this->checkArgv();

        $this->start();
    }

    /**
     * 校验脚本传参
     *
     * @throws Exception
     */
    protected function checkArgv()
    {
        if (count($this->argv) < 2) {
            throw new Exception('运行参数错误');
        }

        $action = $this->argv[1];

        if (! in_array($action, ['start', 'stop', 'reload', 'status', 'restart'])) {
            throw new Exception('Error Arguments: start | start --daemonize | stop | reload | status | restart');
        }
    }

    /**
     * SSL 配置
     *
     * @return array
     */
    protected function getSSL()
    {
        $ssl = array(
            'ssl' => array(
                'local_cert'  => self::HTTPS_PEM,
                'local_pk'    => self::HTTPS_KEY,
                'verify_peer' => false,
            )
        );

        return $ssl;
    }

    /**
     * SocketIO 实例
     *
     * @return SocketIO
     */
    protected function getSocketIO()
    {
        if (self::APP_ENV == 'local') {
            $socketIO = new SocketIO(self::SOCKET_PORT_SAAS);
        } else {
            $socketIO = new SocketIO(self::SOCKET_PORT_SAAS, $this->getSSL());
        }

        // TODO: 无效, 如何多进程?
        $socketIO->count = self::SOCKET_WORKER_NUM;

        return $socketIO;
    }

    /**
     * Worker 实例
     *
     * @return Worker
     */
    protected function getWorker()
    {
        if (self::APP_ENV == 'local') {
            $worker = new Worker("http://0.0.0.0:" . (self::SOCKET_PORT_SAAS - 1));
        } else {
            $worker = new Worker("http://0.0.0.0:" . (self::SOCKET_PORT_SAAS - 1), $this->getSSL());
            $worker->transport = 'ssl';
        }

        // TODO: 是否生效无从查看
        $worker->count = self::SOCKET_WORKER_NUM;

        return $worker;
    }

    protected function start()
    {
        $this->socketIO = $this->getSocketIO();

        // 客户端发起连接事件时，设置连接 socket 的各种事件回调
        $this->socketIO->on('connection', function ($socket) {
            printOnLog('connection');
            printLog("client id -- {$socket->id}"); // 客户端唯一标识符

            // 当客户端发来登录事件时触发
            // $loginInfo: 是来自客户端提交的数据
            $socket->on('login', function ($loginInfo) use ($socket) {
                printOnLog('login');

                if (isset($socket->uid)) { // 是否已经登录过了
                    return;
                }

                $room_id    = (string)$loginInfo['room_id'];
                $student_id = (string)$loginInfo['student_id'];
                $uid        = $student_id; // 用 $student_id 当作 $uid

                // 将这个连接加入到 $uid 分组，方便针对 $uid 推送数据
                $socket->join($uid);
                $socket->join($room_id);
                $socket->uid = $uid;

                printLog("socket uid -- {$socket->uid}");

                // 更新对应 $uid 的在线数据
                if (! isset($this->uidConnectionMap[$uid])) {
                    $this->uidConnectionMap[$uid] = 0;
                }

                // 这个 $uid 有 ++$this->uidConnectionMap[$uid] 个 socket 连接
                ++$this->uidConnectionMap[$uid];
            });

            // 当客户端断开连接是触发（一般是关闭网页或者跳转刷新导致）
            $socket->on('disconnect', function () use ($socket) {
                printOnLog('disconnect');

                if (! isset($socket->uid)) {
                    return;
                }

                // 更新对应 $uid 的在线数据, 将 $uid 的在线 socket 数减一
                if (--$this->uidConnectionMap[$socket->uid] <= 0) {
                    unset($this->uidConnectionMap[$socket->uid]);
                }
            });
        });

        // 当 $this->socketIO 启动后监听一个 http 端口，通过这个端口可以给任意 uid 或者所有 uid 推送数据
        $this->socketIO->on('workerStart', function () {
            printOnLog('workerStart');

            // 监听一个 http 端口
            $this->httpWorker = $this->getWorker();

            // 当 http 客户端发来数据时触发
            $this->httpWorker->onMessage = function ($http_connection, $data) {
                $_POST = $_POST ? $_POST : $_GET;
                // 推送数据的url格式 type=publish&to=uid&content=xxxx

                /*
                // For ab test
                $_POST['to'] = 1;
                $_POST['type']  = 'publish';
                $_POST['content'] = htmlspecialchars('向当前用户发送消息');
                */

                switch (@$_POST['type']) {
                    case 'publish':
                        echo "publish \n";
                        echo "emit: new_msg \n";

                        $to               = @$_POST['to'];
                        $_POST['content'] = htmlspecialchars(@$_POST['content']);

                        if ($to) { // 有指定 uid 则向 uid 所在 socket 组发送数据
                            $this->socketIO->to($to)->emit('new_msg', $_POST['content']);
                        } else { // 否则向所有 uid 推送数据
                            $this->socketIO->emit('new_msg', @$_POST['content']);
                        }

                        // http 接口返回，如果用户离线 socket 返回 fail
                        if ($to && ! isset($this->uidConnectionMap[$to])) {
                            return $http_connection->send('offline');
                        } else {
                            return $http_connection->send('ok');
                        }
                }

                return $http_connection->send('fail');
            };

            // 执行监听
            $this->httpWorker->listen();

            //  一个定时器，定时向所有 uid 推送当前 uid 在线数及在线页面数
            Timer::add(1, function () {
                $this->online_count_now = count($this->uidConnectionMap);
                $this->online_page_count_now = array_sum($this->uidConnectionMap);

                // 只有在客户端在线数变化了才广播，减少不必要的客户端通讯
                if ($this->last_online_count != $this->online_count_now || $this->last_online_page_count != $this->online_page_count_now) {
                    echo "emit: update_online_count \n";
                    $this->socketIO->emit('update_online_count', "当前<b>{$this->online_count_now}</b>人在线，共打开<b>{$this->online_page_count_now}</b>个页面");

                    $this->last_online_count = $this->online_count_now;
                    $this->last_online_page_count = $this->online_page_count_now;
                }
            });
        });

        // 运行worker
        if (!  defined('WORKERMAN_START')) {
            Worker::runAll();
        }
    }
}

$instance = new Run();
