<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: text/event-stream");
header("X-Accel-Buffering: no");
set_time_limit(0);
session_start();
$postData = $_SESSION['data'];
$responsedata = "";
$ch = curl_init();
$OPENAI_API_KEY = "";

//下面这段代码是从文件中获取apikey，采用轮询方式调用。配置apikey请访问key.php
$content = "<?php header('HTTP/1.1 404 Not Found');exit; ?>\n";
$line = 0;
$handle = fopen(__DIR__ . "/apiKeyData/_apikey.php", "r") or die("Writing file failed.");
if ($handle) {
    while (($buffer = fgets($handle)) !== false) {
        $line++;
        if ($line == 2) {
            $OPENAI_API_KEY = str_replace("\n", "", $buffer);
        }
        if ($line > 2) {
            $content .= $buffer;
        }
    }
    fclose($handle);
}
$content .= $OPENAI_API_KEY . "\n";
$handle = fopen(__DIR__ . "/apiKeyData/_apikey.php", "w") or die("Writing file failed.");
if ($handle) {
    fwrite($handle, $content);
    fclose($handle);
}

//如果首页开启了输入自定义apikey，则采用用户输入的apikey
if (isset($_SESSION['key'])) {
    $OPENAI_API_KEY = $_SESSION['key'];
}
session_write_close();
$headers  = [
    'Accept: application/json',
    'Content-Type: application/json',
    'Authorization: Bearer ' . $OPENAI_API_KEY
];

setcookie("errcode", ""); //EventSource无法获取错误信息，通过cookie传递
setcookie("errmsg", "");

$callback = function ($ch, $data) {
    global $responsedata;
    $complete = json_decode($data);
    if (isset($complete->error)) {
        setcookie("errcode", $complete->error->code);
        setcookie("errmsg", $data);
        if (strpos($complete->error->message, "Rate limit reached") === 0) { //访问频率超限错误返回的code为空，特殊处理一下
            setcookie("errcode", "rate_limit_reached");
        }
        if (strpos($complete->error->message, "Your access was terminated") === 0) { //违规使用，被封禁，特殊处理一下
            setcookie("errcode", "access_terminated");
        }
        if (strpos($complete->error->message, "You didn't provide an API key") === 0) { //未提供API-KEY
            setcookie("errcode", "no_api_key");
        }
        if (strpos($complete->error->message, "You exceeded your current quota") === 0) { //API-KEY余额不足
            setcookie("errcode", "insufficient_quota");
        }
        if (strpos($complete->error->message, "That model is currently overloaded") === 0) { //OpenAI模型超负荷
            setcookie("errcode", "model_overloaded");
        }
        $responsedata = $data;
    } else {
        echo $data;
        $responsedata .= $data;
        flush();
    }
    return strlen($data);
};

curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
curl_setopt($ch, CURLOPT_URL, 'https://api.openai.com/v1/chat/completions');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
curl_setopt($ch, CURLOPT_WRITEFUNCTION, $callback);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 120); // 设置连接超时时间为30秒
curl_setopt($ch, CURLOPT_MAXREDIRS, 3); // 设置最大重定向次数为3次
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // 允许自动重定向
curl_setopt($ch, CURLOPT_AUTOREFERER, true); // 自动设置Referer
//curl_setopt($ch, CURLOPT_PROXY, "http://127.0.0.1:1081");

curl_exec($ch);
curl_close($ch);

$answer = "";
if (substr(trim($responsedata), -6) == "[DONE]") {
    $responsedata = substr(trim($responsedata), 0, -6) . "{";
}
$responsearr = explode("}\n\ndata: {", $responsedata);

foreach ($responsearr as $msg) {
    $contentarr = json_decode("{" . trim($msg) . "}", true);
    if (isset($contentarr['choices'][0]['delta']['content'])) {
        $answer .= $contentarr['choices'][0]['delta']['content'];
    }
}
$questionarr = json_decode($postData, true);
$filecontent = $_SERVER["REMOTE_ADDR"] . " | " . date("Y-m-d H:i:s") . "\n";
$filecontent .= "Q:" . end($questionarr['messages'])['content'] .  "\nA:" . trim($answer) . "\n----------------\n";
$myfile = fopen(__DIR__ . "/chat.txt", "a") or die("Writing file failed.");
fwrite($myfile, $filecontent);
fclose($myfile);
