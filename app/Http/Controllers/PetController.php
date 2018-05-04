<?php

namespace App\Http\Controllers;

use App\Busines_pets;
use App\Business;
use App\Items;
use App\Pets;
use App\User_items;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Validator;

class PetController extends Controller
{
    //获取宠物详细信息
    public function getDetail(Request $request)
    {
        $validator = Validator::make($request->json()->all(), [
            'id' => 'required',
        ]);

        if ($validator->fails()) {
            return $this->fail('缺少必要的参数');
        }

        $pet_id = $request->json()->get('id');
        //更新活力值
        $this->getVitality($pet_id, 'pet_id');

        $pet_data = Pets::select(
            'id',
            'cat_name',
            'cat_gender',
            'cat_level',
            'cat_exp',
            'cat_aptitude',
            'max_cat_vitality',
            'cat_vitality',
            'cat_financing',
            'character_id'
        )
            ->find($pet_id)->toArray();

        //获取性格
        $characters = json_decode(Storage::disk('local')->get('character'), true);
        $pet_characters = explode(',', $pet_data['character_id']);
        $pet_character_tmp = [];
        foreach ($characters as $v) {
            if (in_array($v['id'], $pet_characters)) {
                $pet_character_tmp[] = [
                    'id' => $v['id'],
                    'name' => $v['name'],
                    'des' => $v['des']
                ];
            }
        }

        $pet_data['character'] = $pet_character_tmp;

        return $this->success($pet_data);
    }

    //指派宠物到店铺
    public function assign(Request $request)
    {
        $validator = Validator::make($request->json()->all(), [
            'pet_id' => 'required',
            'shop_id' => 'required'
        ]);

        if ($validator->fails()) {
            return $this->fail('缺少必要的参数');
        }

        //判断是否为非营业状态
        $this_status = Business::select('status')->find($request->json()->get('shop_id'));

        if ($this_status->status == 2) {
            return $this->fail('此店铺已经营业');
        }

        $shop_pet = Busines_pets::select('pet_id')
            ->where('shop_id', $request->json()->get('shop_id'))
            ->where('status', 1)
            ->get()->toArray();

        if (count($shop_pet) >= 3) {
            return $this->fail('此店铺已经入驻满了');
        }

        $pet_all = array_column($shop_pet, 'pet_id');

        if (in_array($request->json()->get('pet_id'), $pet_all)) {
            return $this->fail('此宠物已经入驻这个店铺了');
        }

        $busines_pets = new Busines_pets();
        $busines_pets->shop_id = $request->json()->get('shop_id');
        $busines_pets->pet_id = $request->json()->get('pet_id');

        if ($busines_pets->save()) {
            return $this->success();
        } else {
            return $this->fail('入驻失败');
        }
    }

    //宠物互动
    public function interaction(Request $request)
    {
        $validator = Validator::make($request->json()->all(), [
            'pet_id' => 'required',
            'prop_id' => 'required'
        ]);

        if ($validator->fails()) {
            return $this->fail('缺少必要的参数');
        }

        $item_id = $request->json()->get('prop_id');
        $pet_id = $request->json()->get('pet_id');

        DB::beginTransaction();
        try {
            //删除相应道具
            $user_props = User_items::select('id', 'status')->where('prop_id', $item_id)->first();
            $user_props->status = 0;
            $user_props->save();
            //计算用户增益
            $props = Items::select('id', 'cat_exp', 'cat_vitality')->find($item_id);
            $user_pet = Pets::select('id', 'cat_exp', 'cat_vitality')->find($pet_id);
            $user_pet->cat_exp = $user_pet->cat_exp + $props->cat_exp;
            $user_pet->cat_vitality = $user_pet->cat_vitality + $props->cat_vitality;
            $user_pet->save();
            DB::commit();
            return $this->success([
                'cat_exp' => $props->cat_exp,
                'cat_vitality' => $props->cat_vitality
            ]);
        } catch (\Exception $e) {

            DB::rollBack();
            return $this->fail('互动失败，请重试');
        }
    }

    //修改宠物昵称接口
    public function ModifyNick(Request $request)
    {
        $validator = Validator::make($request->json()->all(), [
            'pet_id' => 'required',
            'nick' => 'required'
        ]);

        if ($validator->fails()) {
            return $this->fail('缺少必要的参数');
        }

        $pet = Pets::select('id', 'cat_name')->find($request->json()->get('pet_id'));
        $pet->cat_name = $request->json()->get('nick');
        $res = $pet->save();

        if ($res) {
            //清空radis
            Redis::del('pet:def');

            return $this->success();
        } else {
            return $this->fail('昵称修改失败');
        }
    }

    //切换宠物
    public function switchover(Request $request)
    {
        $validator = Validator::make($request->json()->all(), [
            'pet_id' => 'required',
        ]);

        if ($validator->fails()) {
            return $this->fail('缺少必要的参数');
        }

        $user_id = decrypt($request->json()->get('token'));

        DB::beginTransaction();
        try {
            Pets::where('user_id', $user_id)->update([
                'status' => 1,
            ]);
            Pets::where('id', $request->json()->get('pet_id'))->update([
                'status' => 2,
            ]);
            DB::commit();
            return $this->success();
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->fail('切换宠物失败');
        }
    }

    //清理垃圾接口
    public function scavenging(Request $request)
    {
        $validator = Validator::make($request->json()->all(), [
            'pet_id' => 'required',
            'sum',
        ]);

        if ($validator->fails()) {
            return $this->fail('缺少必要的参数');
        }

        $pet_id = $request->json()->get('pet_id');

        //更新活力值
        $this->getVitality($pet_id, 'pet_id');

        $pet = Pets::select('id', 'cat_vitality')->find($pet_id);

        $pet->cat_vitality += $request->json()->get('sum', 1);

        if ($pet->save()) {
            return $this->success([
                'cat_vitality' => $request->json()->get('sum', 1)
            ]);
        }
    }
}
