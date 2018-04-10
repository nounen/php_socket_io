<?php
/**
 * 调试打印
 *
 * @param $target
 */
function dd($target) {
    var_dump($target);

    exit();
}

/**
 * 打印日志
 *
 * @param $str
 */
function printLog($str) {
    echo "{$str}\n";
}

function printOnLog($str) {
    echo "on -- {$str}\n";
}