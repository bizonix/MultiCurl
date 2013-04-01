<?php
include_once '../MultiCurl.class.php';

class MyMultiCurl extends MultiCurl {
    protected function onLoad($url, $content, $info) {
        print "[$url] $content ";
        print_r($info);
    }
}

try {
    $mc = new MyMultiCurl();
    $mc->setMaxSessions(2); // limit 2 parallel sessions (by default 10)
    $mc->setMaxSize(10240); // limit 10 Kb per session (by default 10 Mb)
    $mc->addUrl('http://google.com');
    $mc->addUrl('http://yahoo.com');
    $mc->addUrl('http://altavista.com');
    $mc->wait();
} catch (Exception $e) {
    die($e->getMessage());
}