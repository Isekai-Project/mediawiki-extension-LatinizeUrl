# LatinizeUrl
## 安装
将文件放入extensions/LatinizeUrl
添加
```php
wfLoadExtension('LatinizeUrl');
```
到LocalSettings.php

## 配置
### 使用PHP内置的解析器（较慢）
```php
$wgLatinizeUrlConfig['parser'] = 'inner';
```
开启分词功能
```php
$wgLatinizeUrlConfig['cutWord'] = true;
```
开启分词后会很慢，建议使用daemon解析

### 使用daemon api解析
项目地址：[Isekai-LatinizeUrl-Backend](https://github.com/Isekai-Project/Isekai-LatinizeUrl-Backend)
```php
$wgLatinizeUrlConfig['parser'] = 'api';
$wgLatinizeUrlConfig['url'] = '指向daemon的url，默认的path是网址:端口/asciiurl/hanzi2pinyin';
$wgLatinizeUrlConfig['fallback'] = false;
```
也可以配置在daemon离线时自动退回php解析
```php
$wgLatinizeUrlConfig['fallback'] = 'inner';
```
另：虚拟主机可以使用异世界百科的开放api
```
https://static-www.isekai.cn:8082/api/toolkit/asciiurl/hanzi2pinyin
或
http://static-www.isekai.cn:8081/api/toolkit/asciiurl/hanzi2pinyin
```
不保证稳定性，建议自建daemon