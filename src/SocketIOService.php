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
use Workerman\Worker;

class SocketIOService
{
    /**
     * socket.io配置
     * @var array
     */
    private $config;
    /*
     '$config' => [
        //是否启动
        'enable' => true,
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

        //监听客户端连接事件
        $this->serviceIO->on('connection', function ($socket) {
            //监听自定义事件
            $socket->on('login', function ($uidEvents = null) use ($socket) {
                //参数有误，断开连接
                if (!$uidEvents) {
                    $socket->disconnect();
                    return;
                }
                //$uid:非空字符串，$events:非空字符串数组
                $uid = $uidEvents[0] ?? '';
                $events = $uidEvents[1] ?? [];
                //格式验证
                if (!is_array($events)) {
                    $events = [$events];
                }
                //用户自定义事件
                $userEvents = [];
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
                    if (!empty($uid)) {
                        if (!isset($this->eventsMap[$event]['users'][$uid])) {
                            $this->eventsMap[$event]['users'][$uid] = 0;
                        }
                        ++$this->eventsMap[$event]['users'][$uid];
                    }
                    $userEvents[] = $event;
                }
                $socket->userEvents = $userEvents;
                if (!empty($uid)) {
                    $socket->uid = $uid;
                    $socket->join($uid);    //多tab标签
                }
                //初始化推送事件消息
                foreach ($events ?: [] as $event) {
                    if (!empty($event)) {
                        $data = $this->httpGet($this->config['callback_url'], ['event' => $event, 'uid' => $socket->uid]);
                        if (is_array($data)) {
                            $socket->emit($event, $this->resultToSocketIO(true, $event, $data));
                        }
                    }
                }
            });
            // 绑定事件
            $socket->on('bind', function ($events = null) use ($socket) {
                if (!$events) {
                    return;
                }
                //格式验证(array)
                if (!is_array($events)) {
                    $events = [$events];
                }
                //用户自定义事件
                $userEvents = [];
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
                    if (!empty($socket->uid)) {
                        if (!isset($this->eventsMap[$event]['users'][$socket->uid])) {
                            $this->eventsMap[$event]['users'][$socket->uid] = 0;
                        }
                        ++$this->eventsMap[$event]['users'][$socket->uid];
                    }
                    $userEvents[] = $event;
                }
                $socket->userEvents = array_merge($socket->userEvents, $userEvents);
                if (!empty($socket->uid)) {
                    $socket->uid = $socket->uid;
                    $socket->join($socket->uid);    //多tab标签
                }
                //初始化推送事件消息
                foreach ($events ?: [] as $event) {
                    if (!empty($event)) {
                        $data = $this->httpGet($this->config['callback_url'], ['event' => $event, 'uid' => $socket->uid]);
                        if (is_array($data)) {
                            $socket->emit($event, $this->resultToSocketIO(true, 'bind success', $data));
                        }
                    }
                }
            });
            // 解绑事件
            $socket->on('unbind', function ($events = null) use ($socket) {
                if (!isset($socket->uid)) {
                    return;
                }
                foreach ($events ?: [] as $event) {
                    if (isset($this->eventsMap[$event]['users'][$socket->uid]) && --$this->eventsMap[$event]['users'][$socket->uid] <= 0) {
                        unset($this->eventsMap[$event]['users'][$socket->uid]);
                        if (count($this->eventsMap[$event]['users']) === 0) {
                            unset($this->eventsMap[$event]);
                        }
                    }
                }
            });
            // 当客户端断开连接时触发（一般是关闭网页或者跳转刷新导致）
            $socket->on('disconnect', function () use ($socket) {
                if (!isset($socket->uid)) {
                    return;
                }
                foreach ($socket->userEvents as $event) {
                    if (isset($this->eventsMap[$event]['users'][$socket->uid]) && --$this->eventsMap[$event]['users'][$socket->uid] <= 0) {
                        unset($this->eventsMap[$event]['users'][$socket->uid]);
                        if (count($this->eventsMap[$event]['users']) === 0) {
                            unset($this->eventsMap[$event]);
                        }
                    }
                }
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
                                $this->serviceIO->emit($event, $this->resultToSocketIO(true, $message, $data));
                            } else if ($to && isset($this->eventsMap[$event]['users'][$to])) {
                                $this->serviceIO->to($to)->emit($event, $this->resultToSocketIO(true, $message, $data));
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
     * 网络请求
     * @param $url
     * @param $params
     * @return array
     * author ZhengYiBin
     * date   2022-02-23 13:46
     */
    private function httpGet($url, $params): array
    {
        $url = "{$url}?" . http_build_query($params);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        $result = curl_exec($ch);
        curl_close($ch);
        return json_decode($result, true) ?: [];
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