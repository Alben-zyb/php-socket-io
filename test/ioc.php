<?php
/**
 *
 * Created by PhpStorm.
 * author ZhengYiBin
 * date   2022-03-03 18:09
 */
abstract class AbstractResourceDependent {
    public function debug()
    {
        return 'test';
    }
}

class ResourceDependent extends AbstractResourceDependent {}

abstract class AbstractResource
{
    protected $resourceDependent;

    public function __construct(AbstractResourceDependent $resourceDependent)
    {
        $this->resourceDependent = $resourceDependent;
    }

    public function debug()
    {
        return 'test';
    }
}

class Resource extends AbstractResource {}

class IocContainer
{
    // 绑定的数据
    protected $bindings = [];

    // 实例化的数据
    protected $instances = [];

    // 绑定数据
    public function bind($abstract, $concrete = null)
    {
        // 包装成闭包
        if (! $concrete instanceof Closure) {
            $concrete = $this->getClosure($abstract, $concrete);
        }

        $this->bindings[$abstract] = compact('concrete');
    }

    // 包装成闭包的方法
    protected function getClosure($abstract, $concrete)
    {
        return function ($container) use ($abstract, $concrete) {
            if ($abstract == $concrete) {
                return $container->build($concrete);
            }

            return $container->make($concrete);
        };
    }

    // 解析数据
    public function make($abstract)
    {
        // 已经有，直接返回
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        // 获取绑定的数据
        $concrete = $this->getConcrete($abstract);

        $object = $this->build($concrete);

        return $object;
    }

    // 创建数据
    public function build($concrete)
    {
        if ($concrete instanceof Closure) {
            return $concrete($this);
        }

        // 暂时屏蔽反射生成的逻辑
        // $reflector = new ReflectionClass($concrete);
        return new $concrete;
    }

    // 获取绑定的数据
    protected function getConcrete($abstract)
    {
        if (isset($this->bindings[$abstract])) {
            return $this->bindings[$abstract]['concrete'];
        }

        return $abstract;
    }
}

$app = new IocContainer;

$app->bind(AbstractResourceDependent::class, function ($app) {
    return new ResourceDependent();
});

$app->bind(AbstractResource::class, function ($app) {
    return new Resource($app->make(AbstractResourceDependent::class));
});

$resource = $app->make(AbstractResource::class);

var_dump($resource->resourceDependent->debug());
