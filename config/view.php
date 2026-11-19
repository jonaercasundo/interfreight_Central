<?php

return ['paths' => [resource_path('views')], 'compiled' => env('VIEW_COMPILED_PATH', env('APP_RUNNING_IN_CONSOLE') ? sys_get_temp_dir() : storage_path('framework/views'))];
