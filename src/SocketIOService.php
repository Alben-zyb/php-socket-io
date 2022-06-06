<?php
declare(strict_types=1);
/**
 *
 * Created by PhpStorm.
 * author ZhengYiBin
 * date   2022-02-25 14:31
 */


namespace Zyb\PhpSocketIo;


use PHPSocketIO\SocketIO;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Http\Client;
use Workerman\Worker;

class SocketIOService
{
    /**
     * 内部异步回调参数
     * @var int[]
     */
    private $options = [
        'max_conn_per_addr' => 128, // 每个地址最多维持多少并发连接
        'keepalive_timeout' => 15,  // 连接多长时间不通讯就关闭
        'connect_timeout' => 20,  // 连接超时时间
        'timeout' => 10,  // 等待响应的超时时间
    ];

    /**
     * socket.io配置
     * @var array
     */
    private $config;
    /*
     '$config' => [
        //是否启动
        'enable' => true,
        //白名单(选填)
        'white_list' => [
            'http://localhost',
        ],
        //socket.io端口
        'port' => env('SOCKET_PORT', 2120),
        //socket.io内部监听地址
        'inner_host' => env('SOCKET_INNER_HOST', 'http://0.0.0.0:2121'),
        //注册事件
        'events' => [
            'todoEvent' => 0,   //0=单播，1=广播
        ],
        //回调事件获取数据地址
        'callback_url' => env('SOCKET_CALLBACK_HOST', 'http://172.22.11.46:8099/todoEvent'),
    ],
     * */

    /**
     * 全局数组保存事件在线数据
     * @var array
     */
    private $eventsMap = [];
    /*
     * $eventsMap: {
            //事件名称（键）
            todoEvent: {
                //事件类型（0:单播，1：广播）
                broadcast: 0,
                //连接用户
                users: {
                    //userId:连接数（tab页面）
                    1: 2
                }
            }
        }
    */

    /**
     * PHPSocketIO服务
     * @var
     */
    private $serviceIO;

    public function __construct($config = [])
    {
        //socket.io配置
        $this->config = $config;
        $this->run();
    }

    /**
     * author ZhengYiBin
     * date   2022-02-24 09:01
     */
    public function run(): void
    {
        // 创建PHPSocketIO服务
        $this->serviceIO = new SocketIO($this->config['port']);
        /** @noinspection PhpUndefinedFieldInspection */
        $this->serviceIO->worker->name = $this->config['name'];
        //白名单过滤
        if (isset($this->config['white_list']) && is_array($this->config['white_list'])) {
            $whiteLists = array_filter($this->config['white_list']);
            if (!empty($whiteLists)) {
                $list = '';
                foreach ($whiteLists ?? [] as $whiteList) {
                    $list .= $whiteList . ' ';
                }
                //添加白名单
                $this->serviceIO->origins($list);
            }
        }
        //监听客户端连接事件
        $this->serviceIO->on('connection', function ($socket) {
            //监听自定义事件
            $socket->on('login', function ($uidEvents = null) use ($socket) {
                //参数有误，断开连接
                if (!$uidEvents) {
                    $socket->disconnect();
                    return;
                }
                //已登录，返回
                if (!empty($socket->uid)) {
                    $socket->emit('login', ['success' => false, 'message' => '已登录']);
                    return;
                }

                $uid = $uidEvents;
                $events = [];
                if (is_array($uidEvents)) {
                    //$uid:非空字符串，$events:非空字符串数组
                    $uid = $uidEvents[0] ?? '';
                    $events = $uidEvents[1] ?? [];
                    //格式验证
                    if (!is_array($events)) {
                        $events = [$events];
                    }
                }
                //在线事件绑定
                foreach ($events ?: [] as $event) {
                    //事件数据格式：非空字符串
                    if (empty($event) || !is_string($event)) {
                        continue;
                    }
                    if (!isset($this->eventsMap[$event])) {
                        $this->eventsMap[$event] = [
                            'broadcast' => $this->config['events'][$event] ?? 0,
                            'users' => [],
                        ];
                    }
                    //用户唯一标识（分组、用户组）
                    if (!empty($uid)) {
                        if (!isset($this->eventsMap[$event]['users'][$uid])) {
                            $this->eventsMap[$event]['users'][$uid] = 0;
                        }
                        ++$this->eventsMap[$event]['users'][$uid];
                    }
                }
                //socket加入用户组
                if (!empty($uid)) {
                    $socket->uid = $uid;
                    $socket->join($uid);    //主要根据$socket->join($uid)进行消息定点推送
                }
                //初始化推送事件消息
                $this->push($socket, $events);
            });
            // 绑定事件
            $socket->on('bind', function ($events = null) use ($socket) {
                if (empty($socket->uid)) {
                    $socket->emit('bind', ['success' => false, 'message' => '未登录']);
                    return;
                }
                if (!$events) {
                    return;
                }
                //格式验证(array)
                if (!is_array($events)) {
                    $events = [$events];
                }
                foreach ($events ?: [] as $event) {
                    //事件数据格式：非空字符串
                    if (empty($event) || !is_string($event)) {
                        continue;
                    }
                    if (!isset($this->eventsMap[$event])) {
                        $this->eventsMap[$event] = [
                            'broadcast' => $this->config['events'][$event] ?? 0,
                            'users' => [],
                        ];
                    }
                    if (!isset($this->eventsMap[$event]['users'][$socket->uid])) {
                        $this->eventsMap[$event]['users'][$socket->uid] = 0;
                    }
                    ++$this->eventsMap[$event]['users'][$socket->uid];
                }
                $socket->join($socket->uid);    //主要根据$socket->join($uid)进行消息定点推送
                //初始化推送事件消息
                $this->push($socket, $events);
            });
            // 解绑事件
            $socket->on('unbind', function ($events = null) use ($socket) {
                if (!isset($socket->uid)) {
                    return;
                }
                if (!is_array($events)) {
                    $events = [$events];
                }
                foreach ($events ?: [] as $event) {
                    $this->unbind($this->eventsMap, $event, $socket->uid);
                }
            });

            // 当客户端断开连接时触发（一般是关闭网页或者跳转刷新导致）
            $socket->on('disconnect', function () use ($socket) {
                if (!isset($socket->uid)) {
                    return;
                }
                $this->disconnect($this->eventsMap, $socket->uid);
            });
        });

        //开启socket.io内部监听
        $this->serviceIO->on('workerStart', function () {
            //提供外部调用
            $innerHttpWorker = new Worker($this->config['inner_host']);
            //url格式
            //type=public,query
            //example1:type=public&to=uid&event=event&message=hello&data=json
            //example2:type=public&event=event&data=json&message=hello
            //example3:type=query
            $innerHttpWorker->onMessage = function (TcpConnection $connection, Request $request) {
                $params = $request->get() + $request->post();
                if (!isset($params['type'])) {
                    return $connection->send($this->resultToHttp(false, '请求的参数type格式不正确'));
                }
                switch ($params['type']) {
                    case 'public':
                        //推送消息
                        //客户端监听的事件
                        $event = $params['event'] ?? 'new_msg';
                        $to = $params['to'] ?? null;
                        $success = (bool)($params['success'] ?? true);
                        $message = $params['message'] ?? '';
                        $data = json_decode($params['data'] ?? '', true);
                        //类型控制，$data须为数组
                        if (!is_array($data)) {
                            $data = [];
                        }
                        //存在连接事件
                        if (isset($this->eventsMap[$event])) {
                            //是否广播
                            if ($this->eventsMap[$event]['broadcast']) {
                                $this->serviceIO->emit($event, $this->resultToSocketIO($success, $message, $data));
                            } else if ($to && isset($this->eventsMap[$event]['users'][$to])) {
                                $this->serviceIO->to($to)->emit($event, $this->resultToSocketIO($success, $message, $data));
                            } else {
                                // http接口返回，如果用户离线socket返回fail
                                return $connection->send($this->resultToHttp(false, 'offline'));
                            }
                        }
                        return $connection->send($this->resultToHttp(true, 'ok', $data));
                    case 'query':
                        //查询当前连接事件
                        return $connection->send($this->resultToHttp(true, 'success', $this->eventsMap));
                    default:
                        return $connection->send($this->resultToHttp(false, '请求的参数type格式不正确'));
                }
            };
            $innerHttpWorker->listen();
        });
    }

    /**
     * 断开事件
     * @param $eventsMap
     * @param $uid
     * author ZhengYiBin
     * date   2022-03-01 09:50
     */
    public function disconnect(&$eventsMap, $uid): void
    {
        foreach ($eventsMap as $eventName => $event) {
            if (isset($event['users'][$uid])) {
                $this->unbind($eventsMap, $eventName, $uid);
            }
        }
    }

    /**
     * 解绑事件
     * @param $eventsMap
     * @param $event
     * @param $uid
     * author ZhengYiBin
     * date   2022-03-01 09:50
     */
    public function unbind(&$eventsMap, $event, $uid): void
    {
        if (isset($eventsMap[$event]['users'][$uid]) && --$eventsMap[$event]['users'][$uid] <= 0) {
            unset($eventsMap[$event]['users'][$uid]);
            if (count($eventsMap[$event]['users']) === 0) {
                unset($eventsMap[$event]);
            }
        }
    }

    /**
     * 初始化推送事件消息
     * @param $socket
     * @param $events
     */
    private function push($socket, $events): void
    {
        //初始化推送事件消息
        foreach ($events ?: [] as $event) {
            if (!empty($event) && isset($this->config['callback_url'])) {
                $this->asyncPost($this->config['callback_url'], ['event' => $event, 'uid' => $socket->uid], function ($response) use ($socket, $event) {
                    $data = str_replace([], [], $response->getBody());
                    if (is_string($data)) {
                        $data = json_decode($data, true);
                    }
                    $socket->emit($event, $this->resultToSocketIO(true, 'bind success', $data));
                });
            } else {
                $socket->emit($event, $this->resultToSocketIO(true, 'bind success', []));
            }
        }
    }

    /**
     * 网络请求
     * @param $url
     * @param $params
     * @param \Closure|null $callback
     * @param \Closure|null $error
     * @return void author ZhengYiBin
     * author ZhengYiBin
     * date   2022-02-23 13:46
     */
    private function asyncPost($url, $params, \Closure $callback = null, \Closure $error = null): void
    {
        $http = new Client($this->options);
        $http->post($url, $params, $callback ?? static function ($response) {
                echo "------------------------socket bind push success";
            }, $error ?? static function ($exception) {
                echo "------------------------socket bind push error";
            });
    }

    /**
     * socket.io推送消息的格式（array）
     * @param bool $success
     * @param string $message
     * @param array $data
     * @return array
     * author ZhengYiBin
     * date   2022-02-24 10:04
     */
    private function resultToSocketIO(bool $success, string $message, array $data = []): array
    {
        return [
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ];
    }

    /**
     * socket.io内部http连接返回的格式（json）
     * @param bool $success
     * @param string $message
     * @param array $data
     * @return string
     * author ZhengYiBin
     * date   2022-02-24 10:04
     */
    private function resultToHttp(bool $success, string $message, array $data = []): string
    {
        return json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE);
    }

}