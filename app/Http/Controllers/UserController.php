<?php

namespace App\Http\Controllers;

use App\Cat_aptitudes;
use App\Cat_characters;
use App\Games;
use App\Items;
use App\Pets;
use App\Tasks;
use App\User_items;
use App\User_tasks;
use App\Users;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Validator;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function register(Request $request)
    {
        $validator = Validator::make($request->json()->all(), [
            'name' => 'required|string|unique:users,name',
            'password' => 'required'
        ]);

        if ($validator->fails()) {
            return $this->fail('缺少必要的参数');
        }

        try {
            $user = new Users();
            $user->name = $request->json()->get('name');
            $user->password = $request->json()->get('password');
            $user->save();

            $user_id = $user->id;
            //加密token用于用户登录验证
            $token = encrypt($user_id);

            //为用户生成七日任务
            $tasks = Tasks::select('id')->where('type', 1)->get();
            $tasks_data = [];
            foreach ($tasks as $v) {
                $tmp = [
                    'user_id' => $user->id,
                    'task_id' => $v->id
                ];
                array_push($tasks_data, $tmp);
            }
            User_tasks::insert($tasks_data);

        } catch (Exception $e) {
            return $this->fail('注册失败');
        }

        return $this->success([
            'token' => $token
        ]);
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->json()->all(), [
            'name' => 'required|string',
            'password' => 'required'
        ]);

        if ($validator->fails()) {
            return $this->fail('缺少必要的参数');
        }

        $user = Users::select('id', 'login_number')
            ->where('name', $request->json()->get('name'))
            ->where('password', $request->json()->get('password'))
            ->first();

        if ($user) {
            $token = encrypt($user->id);
            //如果第一次登录添加一个七日任务
            if (!$user->login_number) {
                //获取每日任务
                $tasks = Tasks::select('id')->where('type', 1)->get();
                $tasks_data = [];
                foreach ($tasks as $v) {
                    $tmp = [
                        'user_id' => $user->id,
                        'task_id' => $v->id
                    ];
                    array_push($tasks_data, $tmp);
                }
                User_tasks::insert($tasks_data);
            }
            $user->login_number++;//登录次数
            $user->save();

            //查看用户是否可以领取宠物
            $pet_sum = Pets::where('status', '<>', 0)->count();

            return $this->success([
                'is_get_pet' => !$pet_sum,
                'token' => $token
            ]);
        } else {
            return $this->fail('账号密码错误');
        }
    }

    //获取宠物
    public function newPet(Request $request)
    {
        $validator = Validator::make($request->json()->all(), [
            'nick' => 'required',
            'sex' => 'required'
        ]);

        if ($validator->fails()) {
            return $this->fail('缺少必要的参数');
        }

        //敏感词过滤
        if ($this->sensitive($request->json()->get('nick'))) {
            return $this->fail('昵称存在敏感词');
        }

        $user_id = decrypt($request->json()->get('token'));

        //判断是否可以获取宠物
        $active_pet_sum = Pets::where('user_id', $user_id)->where('status', '<>', 0)->count();
        if ($active_pet_sum) {
            return $this->fail('此用户已经有宠物了');
        }

        //定义宠物的资质和品质
        $qualitys_data = Cat_aptitudes::where('member_level', $request->json()->get('member_level', 1))->get()->toArray();
        $this_quality_data = $this->rand_pro($qualitys_data, 'pro');
        $rand_quality = range($this_quality_data['aptitude_lower'], $this_quality_data['aptitude_upon']);
        //获取宠物默认值
        $parameter = json_decode(Storage::disk('local')->get('param'), true)[0];
        $character_sum = range(1, $parameter['character_quantity']);
        $character_sum = $character_sum[array_rand($character_sum)];
        //随机抽取最多个性格
        $character_pet = Cat_characters::orderBy(DB::raw('RAND()'))->take($character_sum)->get()->toArray();
        $character_pet_id = array_column($character_pet, 'id');
        //保存不会改变的值
        $active_pet = new Pets();
        $active_pet->user_id = $user_id;
        $active_pet->cat_name = $request->json()->get('nick');
        $active_pet->cat_gender = $request->json()->get('sex');
        $active_pet->cat_level = $parameter['init_level'];
        $active_pet->cat_aptitude = $rand_quality[array_rand($rand_quality)];//资质
        $active_pet->cat_quality = $this_quality_data['quality'];//品质
        $active_pet->character_id = implode($character_pet_id, ',');
        $active_pet->max_cat_vitality = $parameter['max_energy'];
        $active_pet->cat_vitality = $parameter['init_energy'];
        $active_pet->cat_financing = $parameter['financing'];
        $active_pet->base_financing = $parameter['financing'];

        foreach ($character_pet as $v) {
            $parameter = $v['property'];
            //根据性格做相应是属性值变更
            switch ($v['operate']) {
                case 1:
                    $active_pet->$parameter = $active_pet->$parameter + $v['value'];
                    break;
                case 2:
                    $active_pet->$parameter = $active_pet->$parameter * (100 + $v['value']) / 100;
                    break;
            }
        }

        if ($active_pet->save()) {
            return $this->success([
                'id' => $active_pet->id
            ]);
        } else {
            return $this->fail('数据库操作失败');
        }
    }

    //获取用户信息
    public function getUser(Request $request)
    {
        $user_id = decrypt($request->json()->get('token'));
        $user = Users::select('users.id as user_id', 'users.name as user_name', 'users.star', 'users.gold')->find($user_id);
        return $this->success($user);
    }

    //获取用户游戏信息
    public function getGame(Request $request)
    {
        $game = Games::select('id', 'name', 'icon')->where('status', 1)->get();
        return $this->success($game);
    }

    //获取用户默认宠物基本信息
    public function getPetIndex(Request $request)
    {
        $user_id = decrypt($request->json()->get('token'));
        $key = 'pet:def';

        if (Redis::exists($key)) {
            $data = json_decode(Redis::get($key), true);
        } else {
            //查询有没有需要特别显示的宠物
            $status_count = Pets::where('user_id', $user_id)->where('status', 2)->count();
            if ($status_count) {
                $data = Pets::select('id', 'cat_name', 'cat_gender', 'cat_head', 'cat_vitality', 'max_cat_vitality')
                    ->where('user_id', $user_id)->where('status', 2)->first();
            } else {
                $data = Pets::select('id', 'cat_name', 'cat_gender', 'cat_head', 'cat_vitality', 'max_cat_vitality')
                    ->where('user_id', $user_id)->first();
            }
            Redis::setex($key, 300, json_encode($data));
            //更新一次活力值
            $this->getVitality($user_id, 'user_id');
        }
        return $this->success($data);
    }

    //获取用户任务
    public function getTask(Request $request)
    {
        $user_tasks_data = User_tasks::select('user_tasks.id', 'tasks.name', 'tasks.type', 'user_tasks.schedule_accomplish', 'tasks.des', 'tasks.quantity')
            ->leftJoin('tasks', 'tasks.id', 'user_tasks.task_id')
            ->where('user_id', decrypt($request->json()->get('token')))
            ->where('user_tasks.status', 1)
            ->get()->toArray();

        $data = array();
        foreach ($user_tasks_data as $v) {
            $data[$v['type']][] = $v;
        }

        //从radis中获取每日任务
        $key = 'task:random';
        $random_task = json_decode(Redis::get($key), true);
        $random_task_data = Tasks::select('id', 'name', 'type', 'des', 'quantity')->find($random_task['id'])->toArray();

        $data[] = $random_task_data;

        return $this->success($data);
    }

    //用户完成任务
    public function accomplishTask(Request $request)
    {
        $validator = Validator::make($request->json()->all(), [
            'task_id' => 'required'
        ]);

        if ($validator->fails()) {
            return $this->fail('缺少必要的参数');
        }

        $task_id = $request->json()->get('task_id');
        $user_id = decrypt($request->json()->get('token'));

        DB::beginTransaction();
        try {
            $task_data = Tasks::select('id', 'quantity')->find($task_id);
            $user_task_data = User_tasks::select('id', 'schedule_accomplish')->where('task_id', $task_id)->where('schedule_accomplish', 0)->first();

            if (!$user_task_data) {
                return $this->fail('此任务已经完成或此任务不存在');
            }

            //更新用户任务表
            $user_task_data->schedule_accomplish = 1;
            $user_task_data->save();
            //更新用户表
            $user_data = Users::select('id', 'gold')->find($user_id);
            $user_data->gold = $user_data->gold + $task_data->quantity;
            $user_data->save();

            DB::commit();
            return $this->success([
                'gold' => $task_data->quantity
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->fail('数据库操作失败');
        }
    }

    //获取用户所有宠物
    public function getAllPet(Request $request)
    {
        $user_id = decrypt($request->json()->get('token'));
        $key = 'pet:all';

        if (Redis::exists($key)) {
            $data = json_decode(Redis::get($key), true);
        } else {
            $data = Pets::select('id', 'cat_name', 'cat_gender', 'cat_exp', 'cat_vitality', 'cat_financing', 'cat_aptitude', 'status')
                ->where('user_id', $user_id)
                ->where('status', '<>', 0)
                ->get();

            Redis::setex($key, 300, json_encode($data));
            //更新一次活力值
            $this->getVitality($user_id, 'user_id');
        }

        return $this->success($data);
    }

    //用金币购买道具
    public function getProp(Request $request)
    {
        $validator = Validator::make($request->json()->all(), [
            'prop_id' => 'required'
        ]);

        if ($validator->fails()) {
            return $this->fail('缺少必要的参数');
        }

        $user_id = decrypt($request->json()->get('token'));
        $item_id = $request->json()->get('prop_id');

        DB::beginTransaction();
        try {
            $prop = Items::select('star')->find($item_id);
            if ($prop->star) {
                $user = new Users();

                $user_data = $user->select('id', 'star')->find($user_id);

                if ($user_data->star < $prop->star) {
                    return $this->fail('太贵了，买不起');
                }

                $user_data->star = $user_data->star - $prop->star;
                $user_data->save();
            }

            $user_items = new User_items();
            $user_items->user_id = $user_id;
            $user_items->prop_id = $item_id;
            $user_items->save();

            DB::commit();
            return $this->success();
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->fail('购买道具失败');
        }
    }

    //获取用户道具
    public function getItem(Request $request)
    {
        $user_id = decrypt($request->json()->get('token'));
        $key = 'user:item';

        if (Redis::exists($key)) {
            $data = json_decode(Redis::get($key), true);
        } else {
            $data = User_items::select(
                'items.id', 'items.category', 'items.name', 'items.icon', 'items.rank', 'items.des', 'items.star', 'items.stack'
            )
                ->leftJoin('items', 'items.id', 'user_items.prop_id')
                ->where('user_items.user_id', $user_id)
                ->where('user_items.status', 1)
                ->get();

            Redis::setex($key, 300, json_encode($data));
            //更新一次活力值
            $this->getVitality($user_id, 'user_id');
        }

        return $this->success($data);
    }
}
