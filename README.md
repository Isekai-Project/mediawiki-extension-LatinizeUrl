# LatinizeUrl
## 安装
将文件放入extensions/LatinizeUrl
添加
```php
wfLoadExtension('LatinizeUrl');
wfLoadExtension('LatinizeUrl/ChineseConvertor');
```
到LocalSettings.php
执行maintenance/update.php或者网页版更新器
检查includes/MediaWiki.php里有没有
```php
// Start LatinizeUrl 1.0.0 InitializeParseTitleHook Patch
// This code is added by LatinizeUrl, Donnot remove untill you uninstall LatinizeUrl.
Hooks::run( 'InitializeParseTitle', [ &$ret, $request ] );
// End LatinizeUrl 1.0.0 InitializeParseTitleHook Patch
```
如果没有的话，请将其手动加至 ```private function parseTitle()``` 的 ```return $ret;``` 之前

## 配置
### 使用PHP内置的解析器（较慢）
```php
$LatinizeUrlChineseConvertorConfig['parser'] = 'inner';
```
开启分词功能
```php
$LatinizeUrlChineseConvertorConfig['cutWord'] = true;
```
开启分词后会很慢，建议使用daemon解析

### 使用daemon api解析
项目地址：[Isekai-LatinizeUrl-Backend](https://github.com/Isekai-Project/Isekai-LatinizeUrl-Backend)
```php
$LatinizeUrlChineseConvertorConfig['parser'] = 'api';
$LatinizeUrlChineseConvertorConfig['url'] = '指向daemon的url，默认的path是网址:端口/asciiurl/hanzi2pinyin';
$LatinizeUrlChineseConvertorConfig['fallback'] = false;
//日语转换
$LatinizeUrlJapaneseConvertorConfig['url'] = '指向daemon的url，默认的path是网址:端口/asciiurl/kanji2romaji';
```
也可以配置在daemon离线时自动退回php解析
```php
$LatinizeUrlChineseConvertorConfig['fallback'] = 'inner';
```
另：虚拟主机可以使用异世界百科的开放api
```
https://static-www.isekai.cn:8082/api/toolkit/asciiurl/hanzi2pinyin
http://static-www.isekai.cn:8081/api/toolkit/asciiurl/hanzi2pinyin
```
不保证稳定性，建议自建daemon

### 使用首字母排列分类中的标题
```php
$wgCategoryCollation = 'latinize';
```
いい夢見てね