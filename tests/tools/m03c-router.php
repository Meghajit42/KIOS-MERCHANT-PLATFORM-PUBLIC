<?php
declare(strict_types=1);
$path=parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);
$assets=['/assets/merchant-setup.css'=>['merchant-setup.css','text/css'],'/assets/restaurant-setup.css'=>['restaurant-setup.css','text/css'],'/assets/layout-studio.css'=>['layout-studio.css','text/css'],'/assets/layout-studio.js'=>['layout-studio.js','text/javascript'],'/assets/brand-studio.css'=>['brand-studio.css','text/css'],'/assets/brand-product-placeholder.svg'=>['brand-product-placeholder.svg','image/svg+xml']];
if(isset($assets[$path])){header('Content-Type: '.$assets[$path][1]);header('X-Content-Type-Options: nosniff');readfile('C:/KiosB0/public/assets/'.$assets[$path][0]);exit;}
if($path!=='/merchant-dev.php'){http_response_code(404);exit;}
require 'C:/KiosB0/public/merchant-dev.php';

