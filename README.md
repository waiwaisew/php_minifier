# Installing
Run command below to install
composer require waiwaisew/minifier

# How to use
General
$min  = (new Waiwaisew/JsMinifier())->minify($javascript_text);

Minify without mangle:
$min  = (new Waiwaisew/JsMinifier())->minify($javascript_text, false);

# Note
1. The minifier is only working for JavaScript and jQuery.

# Others
1. This package is generated using Claude AI.
2. The code will be improved base on what creator face time by time.
