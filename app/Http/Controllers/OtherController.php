<?php

namespace App\Http\Controllers;

use App\Cat_attributes;
use App\Cat_characters;
use App\Cat_levelups;
use App\Items;
use App\Params;
use App\Tasks;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use DB;

class OtherController extends Controller
{
    //获取所有道具
    public function itemAll()
    {
        $item = Items::select('id', 'category', 'name', 'icon', 'rank', 'des', 'star', 'stack', 'cat_exp', 'cat_vitality')->where('status', 1)->get();

        return $this->success($item);
    }

    //读取配置文件
    public function readConfig()
    {
        //等级经验等对应表
        Storage::disk('local')->put('levelup', Cat_levelups::get());
        //系统配置表
        $parames = Params::get();
        Storage::disk('local')->put('param', $parames);
        //属性说明表
        $attribute = Cat_attributes::get();
        Storage::disk('local')->put('attribute', $attribute);
        //性格
        Storage::disk('local')->put('character', Cat_characters::get());

        return $this->success(['parames' => $parames, 'attribute' => $attribute]);
    }

    //更新用户每日任务
    public function test()
    {
        $tasks = Tasks::select('id')
            ->where('type', 2)
            ->get()->toArray();

        $tasks_rand = json_encode($tasks[array_rand($tasks)]);
        $key = 'task:random';

        if (Redis::exists($key)) {
            Redis::del($key);
        }

        Redis::set($key, $tasks_rand);
    }
}
