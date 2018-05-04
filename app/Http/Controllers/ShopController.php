<?php

namespace App\Http\Controllers;

use App\Busines_items;
use App\Busines_pets;
use App\Business;
use App\Cat_levelups;
use App\Pets;
use App\User_items;
use App\Users;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Validator;
use Illuminate\Support\Facades\Redis;

class ShopController extends Controller
{
    //获取店铺列表
    public function getShop()
    {
        $shop = Business::select('id', 'name', 'exp', 'star')
            ->where('status', '<>', 0)
            ->get();

        return $this->success($shop);
    }

    //获取指定店铺信息
    public function shopChecked(Request $request)
    {
        $key = 'business:info';

        if (Redis::exists($key)) {
            $data = json_decode(Redis::get($key), true);
        } else {
            //判断店铺营业状态
            if ($request->has('shop_id')) {
                $business_status = Business::select('id', 'status')->find($request->json()->get('shop_id'));
            } else {
                $business_status = Business::select('id', 'status')->first();
            }

            switch ($business_status->status) {
                case 1: //未营业
                    $shop = Business::select(
                        'business.id', 'business.name as business_name', 'business.exp as business_exp', 'business.star as business_star',
                        'items.name', 'items.des', 'items.star as props_star'
                    )
                        ->where('business.id', $business_status->id)
                        ->leftJoin('busines_items', 'busines_items.shop_id', 'business.id')
                        ->leftJoin('items', 'items.id', 'busines_items.prop_id')
                        ->get();
                    $data = [];
                    foreach ($shop as $v) {
                        $props['name'] = $v->name;
                        $props['des'] = $v->des;
                        $props['props_star'] = $v->props_star;

                        if (!isset($data[$v->id])) {
                            $data[$v->id] = [
                                'shop_name' => $v->business_name,
                                'shop_experience' => $v->business_exp,
                                'shop_star' => $v->business_star,
                                'item' => []
                            ];
                        }
                        array_push($data[$v->id]['item'], $props);
                    }
                    $data = $data[1];
                    break;
                case 2: //营业中
                    $business = Business::select('id', 'start', 'end', 'status', 'exp', 'star', 'opening')
                        ->find($business_status->id);
                    //经营的时间 (s)
                    $businessHours = floor((time() - $business->start) / 60);
                    $data = [];
                    //获得的经验
                    $data['exp'] = floor($businessHours / 10 * $business->exp);
                    //获得星钻
                    $data['star'] = floor(($businessHours / $business->opening) * $business->star);
                    //获得的道具
                    $busines_item = Busines_items::select('id', 'prop_id', 'name', 'icon')->where('shop_id', $business->id)
                        ->where('status', 1)->get()->toArray();
                    $data['items'] = array_slice($busines_item, 0, floor(($businessHours / $business->opening) * count($busines_item)));
                    break;
            }

            Redis::setex($key, 300, json_encode($data));
        }

        return $this->success($data);
    }

    //店铺开始营业
    public function shopStart(Request $request)
    {
        $validator = Validator::make($request->json()->all(), [
            'shop_id' => 'required'
        ]);

        if ($validator->fails()) {
            return $this->fail('缺少ID参数');
        }

        $shop = Business::select('id', 'start', 'status')->find($request->json()->get('shop_id'));
        $shop->start = time();
        $shop->status = 2;
        $res = $shop->save();

        if ($res) {
            return $this->success();
        } else {
            return $this->fail('未知原因导致营业失败');
        }
    }

    //获取营业中数据
    public function shopDoing(Request $request)
    {
        $validator = Validator::make($request->json()->all(), [
            'shop_id' => 'required'
        ]);

        if ($validator->fails()) {
            return $this->fail('缺少ID参数');
        }

        $business = Business::select('id', 'start', 'end', 'status', 'exp', 'star', 'opening')->find($request->json()->get('shop_id'));

        //经营的时间 (s)
        $businessHours = floor((time() - $business->start) / 60);

        $data = [];

        //获得的经验
        $data['exp'] = floor($businessHours / 10 * $business->exp);
        //获得的道具
        $busines_item = Busines_items::select('id', 'prop_id', 'name', 'icon')->where('shop_id', $business->id)->where('status', 1)->get()->toArray();
        $data['items'] = array_slice($busines_item, 0, floor(($businessHours / $business->opening) * count($busines_item)));
        //获得星钻
        $data['star'] = floor(($businessHours / $business->opening) * $business->star);

        return $this->success($data);
    }

    //店铺结束营业
    public function shopEnd(Request $request)
    {
        $validator = Validator::make($request->json()->all(), [
            'shop_id' => 'required'
        ]);

        if ($validator->fails()) {
            return $this->fail('缺少ID参数');
        }

        DB::beginTransaction();
        try {
            //店铺更新为待营业状态
            $shop = Business::select('id', 'start', 'end', 'status', 'exp', 'star', 'opening')->find($request->json()->get('shop_id'));
            $shop->end = time();
            $shop->status = 1;
            $shop->save();

            //存放经营奖励的数组
            $data = [
                'experience' => 0,
                'star' => 0,
                'item' => [],
                'upgrade_pet' => [],
                'pet' => []
            ];

            //经营的时间 (s)
            $businessHours = floor((time() - $shop->start) / 60);

            //不足一分钟无法获得奖励
            if ($businessHours < 1) {
                return $this->success($data);
            }

            //获取商店道具
            $busines_item = Busines_items::select('id', 'prop_id')->where('shop_id', $shop->id)->where('status', 1)->get()->toArray();
            //获取商中的宠物信息
            $pet = Busines_pets::select('pets.id', 'pets.cat_level', 'pets.cat_exp', 'pets.max_cat_exp', 'pets.cat_vitality', 'pets.cat_financing')
                ->leftJoin('pets', 'pets.id', 'busines_pets.pet_id')
                ->where('busines_pets.shop_id', $request->json()->get('shop_id'))->get()->toArray();

            $user_id = decrypt($request->json()->get('token'));

            if ($businessHours > $shop->opening) {//超过了店铺的最大经营时间
                //更新用户获得的星钻
                Users::where('id', $user_id)->update([
                    'star' => $shop->star
                ]);
                //更新用户获得的道具
                $user_item = [];
                foreach ($busines_item as $v) {
                    $user_item['user_id'] = $user_id;
                    $user_item['prop_id'] = $v['prop_id'];
                }
                User_items::insert($user_item);

                //已经升级的宠物数组
                $upgrade_pet = [];

                //更新猫的经验值
                foreach ($pet as $v) {
                    $pet_active = Pets::select('id', 'cat_level', 'cat_exp', 'cat_financing')->find($v['id']);
                    $exp = Cat_levelups::select('exp')->first($pet_active->cat_level + 1);//下一级的经验值

                    //升级的宠物
                    if ($exp->exp - $pet_active->cat_exp <= $shop->exp) {
                        $upgrade_pet[] = $v['id'];

                        $pet_active->cat_level++;
                        $pet_active->cat_financing += 10;
                    }
                    $pet_active->cat_exp += $shop->exp;
                    $pet_active->save();
                }

                $data = [
                    'experience' => $shop->exp,
                    'star' => $shop->star,
                    'item' => $user_item,
                    'upgrade_pet' => $upgrade_pet
                ];
            } else {
                //更新用户获得的星钻
                Users::where('id', $user_id)->update([
                    'star' => floor(($businessHours / $shop->opening) * $shop->star)
                ]);
                //更新用户获得的道具
                $user_item = array_slice($busines_item, 0, floor(($businessHours / $shop->opening) * count($busines_item)));
                User_items::insert($user_item);

                //已经升级的宠物数组
                $upgrade_pet = [];

                //更新猫的经验值
                foreach ($pet as $v) {
                    $pet_active = Pets::select('id', 'cat_level', 'cat_exp', 'cat_vitality')->find($v['id']);
                    $exp = Cat_levelups::select('exp')->first($pet_active->cat_level + 1);//下一级的经验值

                    //升级的宠物
                    if ($exp->exp - $pet_active->cat_exp <= $shop->exp) {
                        $upgrade_pet[] = $v['id'];
                        $pet_active->cat_level++;
                        $pet_active->cat_financing += 10;
                    }
                    $pet_active->cat_exp += floor($businessHours / 10 * $shop->exp);
                    $pet_active->save();
                }

                $data = [
                    'experience' => floor($businessHours / 10 * $shop->exp),
                    'star' => floor(($businessHours / $shop->opening) * $shop->star),
                    'item' => $user_item,
                    'upgrade_pet' => $upgrade_pet
                ];
            }
            $data['pet'] = $pet;
            DB::commit();
            return $this->success($data);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->fail('停业失败');
        }
    }
}
