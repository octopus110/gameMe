<?php
//用户相关
Route::post('/register', 'UserController@register');
Route::post('/login', 'UserController@login');

//读取配置文件
Route::post('/config', 'OtherController@readConfig');

Route::group(['middleware' => ['login']], function () {
    Route::post('/new/pet', 'UserController@newPet');//用户新增宠物
    Route::post('/pet/index', 'UserController@getPetIndex');//获取用户默认的宠物信息
    Route::post('/pet/all', 'UserController@getAllPet');//获取用户所有宠物
    Route::post('/pet/detail', 'PetController@getDetail');//获得宠物详情
    Route::post('/interaction', 'PetController@interaction');//萌宠互动
    Route::post('/assign', 'PetController@assign');//指派宠物到店铺
    Route::post('/modify/nick', 'PetController@ModifyNick');//修改宠物昵称
    Route::post('/switchover', 'PetController@switchover');//切换宠物
    Route::post('/scavenging', 'PetController@scavenging');//清理垃圾接口

    Route::post('/game', 'UserController@getGame');//获取游戏列表

    Route::post('/user', 'UserController@getUser');//用户信息
    Route::post('/task', 'UserController@getTask');//获取用户任务
    Route::post('/task/done', 'UserController@accomplishTask');//用户完成任务

    Route::post('/pay/item', 'UserController@getProp');//用户购买道具
    Route::post('/item', 'UserController@getItem');//获取当前用户持有的道具
    Route::post('/item/all', 'OtherController@itemAll');//获取所有道具

    Route::post('/business', 'ShopController@getShop');//获取店铺列表
    Route::post('/business/checked', 'ShopController@shopChecked');//获取指定店铺信息
    Route::post('/business/start', 'ShopController@shopStart');//店铺开始营业
    Route::post('/business/doing', 'ShopController@shopDoing');//获取营业中数据
    Route::post('/business/end', 'ShopController@shopEnd');//店铺结束营业
    
    Route::post('/test', 'OtherController@test');//测试接口
});