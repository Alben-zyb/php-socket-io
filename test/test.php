<?php

/**
 *
 * Created by PhpStorm.
 * author ZhengYiBin
 * date   2022-03-07 13:47
 */
class test
{
    public $a1 = '1a';
    public $b2 = '2b';
}

// $a = new test();
// $c = $b = &$a;
// $d=&$a->a1;
// $e=&$d;
// xdebug_debug_zval('a');
// unset($b, $c);
// xdebug_debug_zval('a');

echo "测试字符串引用计数\n";
$a = "new string";
$b = $a;
xdebug_debug_zval('a');
unset($b);
xdebug_debug_zval('a');
$b = &$a;
xdebug_debug_zval('a');
echo "测试数组引用计数\n";
$c = array('a', 'b');
xdebug_debug_zval('c');
$d = $c;
xdebug_debug_zval('c');
$c[2] = 'c';
xdebug_debug_zval('c');
echo "测试int型计数\n";
$e = 1;
xdebug_debug_zval('e');

//当在类的外部调用serialize()时会自动被调用

class autofelix
{
    // public function __sleep()
    // {
    //     echo '弄啥嘞~';
    // }
}

$a = new autofelix();

echo serialize($a);

//结果: 弄啥嘞~


//结果: autofelix

