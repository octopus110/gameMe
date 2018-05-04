<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class deleteFuncController extends Controller
{
    //萌宠升级
    public function upgrade(Request $request)
    {
        $validator = Validator::make($request->json()->all(), [
            'id' => 'required',
            'grade' => 'required',
            'experience' => 'required'
        ]);

        if ($validator->fails()) {
            return $this->fail('缺少必要的参数');
        }

        $experience_grades_list = Cat_levelups::select('index', 'pile_exp', 'financing')->get();

        $grade = $financiong = 0;
        foreach ($experience_grades_list as $k => $v) {
            if ($request->json()->get('experience') >= $v->pile_exp) {
                continue;
            }
            $grade = $k;
            $financiong = $v->financing;
            break;
        }

        //获取最大等级
        $max_grade = Params::select('max_grade', 'aptitude_range', 'aptitude')->first();

        //判断是否满足升级条件
        if ($grade > $request->json()->get('grade') && $grade <= $max_grade->max_grade) {
            $active_pet_data = Pets::find($request->json()->get('id'));

            $active_pet_data->cat_financing = $active_pet_data->cat_financing + floor($active_pet_data->cat_aptitude * $max_grade->aptitude_range);
            $active_pet_data->cat_vitality = 100;
            $active_pet_data->cat_level = $grade;

            /*计算宠物当前理财的数值*/
            $active_pet_data->cat_financing = $financiong * exp($max_grade->aptitude_range * ($active_pet_data->cat_aptitude / $active_pet_data->base_financiong));

            $res = $active_pet_data->save();

            if ($res) {
                return $this->success();
            } else {
                return $this->fail('不满足升级条件');
            }
        }

        return $this->fail('数据库操作失败');
    }

}
