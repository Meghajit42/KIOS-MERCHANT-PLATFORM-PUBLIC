<?php
declare(strict_types=1);
$path=parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);
$assets=['/assets/merchant-setup.css'=>'merchant-setup.css','/assets/restaurant-setup.css'=>'restaurant-setup.css'];
if(isset($assets[$path])){header('Content-Type: text/css; charset=utf-8');header('X-Content-Type-Options: nosniff');readfile('C:/KiosB0/public/assets/'.$assets[$path]);exit;}
if($path!=='/merchant-dev.php'){http_response_code(404);exit;}
require 'C:/KiosB0/public/merchant-dev.php';
