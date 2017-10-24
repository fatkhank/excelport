<?php

//web
Route::get('imports/{uuid}/result', '\Hamba\ExcelPort\Controllers\ImportController@downloadResult');

//api
Route::get('imports/', '\Hamba\ExcelPort\Controllers\ImportController@index');
Route::post('imports/{uuid}/cancel', '\Hamba\ExcelPort\Controllers\ImportController@cancel');
Route::post('imports/{uuid}/stop', '\Hamba\ExcelPort\Controllers\ImportController@stop');
Route::get('imports/{uuid}/result', '\Hamba\ExcelPort\Controllers\ImportController@downloadResult');
Route::get('imports/{uuid}/status', '\Hamba\ExcelPort\Controllers\ImportController@showStatus');