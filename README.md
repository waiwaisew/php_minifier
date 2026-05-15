# Installing
Run command below to install:
```bash
composer require waiwaisew/minifier
```
Run code into file by "require"/"inclide" etc.:
```php
<?php 
require '/your_folder_name/vendor/autoload.php';
```


# How to use
**General**<br>
```php
$min  = (new Waiwaisew/JsMinifier())->minify($javascript_text);
```
**Minify without mangle:**<br>
```php
$min  = (new Waiwaisew/JsMinifier())->minify($javascript_text, false);
```

# Note
1. The minifier is only working for JavaScript and jQuery.

# Others
1. This package is generated using Claude AI.
2. The code will be improved base on what creator face time by time.
