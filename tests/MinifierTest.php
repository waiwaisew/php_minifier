<?php 
require __DIR__ . '/../vendor/autoload.php';
 
use Waiwaisew\Minifier\JsMinifier;
 
$minifier = new JsMinifier();
echo $minifier->minify("
async function sample(opt={}){
    let sample_1 = opt?.k
    let sample_2 = opt?.l
 
    return
}
");