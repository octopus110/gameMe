<?php
return [

    /*
    |--------------------------------------------------------------------------
    | customized http code
    |--------------------------------------------------------------------------
    |
    | The first number is error type, the second and third number is
    | product type, and it is a specific error code from fourth to
    | sixth.But the success is different.
    |
    */

    'code' => [
        200 => '成功',
        20001 => '参数传递有误',

        40001 => '缺少ID参数',
        40002 => '不合法的token',
        40003 => '你尚未登陆',
        40004 => '没有此token',
        40005 => '不存在此记录',
        40006 => '不符合更新条件',
        40007 => '不满足操作条件',

        50001 => '上传文件的格式不正确',
        50002 => '数据库操作失败',
    ],
];