## What the code will do
1. This is PHP minifier code that only minify for JavaScript at this time.
2. The code works even if JavaScript is hybrid with jQuery. 

## Installing
Run command below to install:
```bash
composer require waiwaisew/minifier
```
Run code into file by "require"/"include" etc.:
```php
<?php 
require '/your_folder_name/vendor/autoload.php';
```


## How to use
**General**<br>
```php
$min  = (new Waiwaisew/JsMinifier())->minify($javascript_text);
```
**Minify without mangle:**<br>
```php
$min  = (new Waiwaisew/JsMinifier())->minify($javascript_text, false);
```

**Facade:**<br>
```php
use Waiwaisew\Minifier\Minify;

$min = Minify::js($javascript_text);          // minify + mangle
$min = Minify::js($javascript_text, false);   // minify only
$min = Minify::file('app.js');
Minify::toFile('app.js', 'app.min.js');
```
**Command line:**<br>
```bash
vendor/bin/minify app.js > app.min.js
vendor/bin/minify --no-mangle app.js
cat app.js | vendor/bin/minify
```

## Others
1. This package is generated using Claude AI.
2. The code will be improved base on what creator face time by time.
3. Licence: MIT
