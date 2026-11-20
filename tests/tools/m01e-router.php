<?php
declare(strict_types=1);
$path=parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);
if($path!=='/merchant-dev.php'){http_response_code(404);exit;}
require 'C:/KiosB0/public/merchant-dev.php';