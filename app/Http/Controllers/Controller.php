<?php

namespace App\Http\Controllers;

use App\Params;
use App\Pets;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Storage;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    protected function success($data = [])
    {
        return response()->json([
            'status' => true,
            'data' => $data,
        ], 200, [], 320);
    }

    protected function fail($message)
    {
        return response()->json([
            'status' => false,
            'message' => $message
        ], 200, [], 320);
    }

    //根据权重随机取出数据
    protected function rand_pro($data, $pro)
    {
        $weight = 0;
        $tempdata = array();
        foreach ($data as $one) {
            $weight += $one[$pro];
            for ($i = 0; $i < $one[$pro]; $i++) {
                $tempdata[] = $one;
            }
        }
        return $tempdata[rand(0, $weight - 1)];
    }

    protected function sensitive($str)
    {
        $words = str_replace(PHP_EOL, '', file_get_contents(storage_path('app/sensitive')));
        $blacklist = "/" . $str . "/i";
        if (preg_match($blacklist, $words)) {
            return true;
        }

        return false;
    }

    //更新活力值
    public function getVitality($id, $type)
    {
        $pets = Pets::select('id', 'cat_vitality', 'vitiate_time');

        switch ($type) {
            case 'user_id':
                $pets = $pets->where('user_id', $id)->first();
                break;
            case 'pet_id':
                $pets = $pets->find($id);
                break;
        }

        $parameter = json_decode(Storage::disk('local')->get('param'), true)[0];

        if ($pets->cat_vitality) {
            $vitality = $pets->cat_vitality - floor(((time() - $pets->vitiate_time) / $parameter['energy_reduce_time']));

            if ($vitality < 0) {
                $vitality = 0;
            }
            $pets->cat_vitality = $vitality;
            $pets->vitiate_time = time();

            $pets->save();
        }

        return true;
    }
}
