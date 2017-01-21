<?php
require "../vendor/autoload.php";

$app = new \Slim\Slim();
$app->renderOptions = json_decode(file_get_contents("../internal/config.json"), true);

// Init check
if (!file_exists($app->renderOptions["wkhtmltopdf"]["exec"])) {
    die("Executable file not found");
}

$app->hook("slim.before", function () use ($app) {
    header("Access-Control-Allow-Origin: *");
    if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
        header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
        header("Access-Control-Allow-Headers: X-Requested-With, Accept, Content-Type, Origin");
        header("Access-Control-Request-Headers: X-Requested-With, Accept, Content-Type, Origin");
        exit;
    }
});

$app->get("/", function () {
    echo "HI!";
});

$app->any("/render", function () use ($app) {
    $url = $app->request()->params("url");
    if (empty($url)) {
        echoData(array("error" => "missing URL"), 400);
        exit();
    }
    $format = $app->request()->params("format", "png");
    $redirect = $app->request()->params("redirect", false);
    $optionsJson = $app->request()->params("options", "{}");

    $renderOptions = $app->renderOptions["wkhtmltopdf"];

    $hash = sha1($url . microtime(true));

    $validOptions = array();
    try {
        $validOptions = json_decode($optionsJson, true);
    } catch (Exception $e) {
        echoData(array("error" => "Invalid options JSON"), 400);
        exit();
    }
    if (($validOptions = parseOptions($validOptions, $renderOptions["allowedOptions"], $optionsError)) === false) {
        echoData(array("error" => $optionsError), 400);
        exit();
    }
    $optionsString = "";
    foreach ($validOptions as $oKey => $oValue) {
        $optionsString .= "--$oKey $oValue ";
    }

    $startTime = microtime(true);

    if ($app->renderOptions["virustotal"]["enabled"]) {
        $virusResult = checkVirusTotal($app, $url);
        if (!$virusResult) {
            echoData(array(
                "error" => "Virus scan failed. Please try again later.",
                "details" => $virusResult
            ), 500);
            exit();
        } else {
            if (!$virusResult["resource_scanned"]) {
                echoData(array(
                    "error" => "URL was not yet scanned for viruses. Please try again later.",
                    "details" => $virusResult
                ), 500);
                exit();
            } else if ($virusResult["score"]["positives"] > 0) {
                echoData(array(
                    "error" => "Positive results on virus scan. Cannot render this URL.",
                    "details" => $virusResult
                ), 400);
                exit();
            } else {
                // Everything okay!
            }
        }
    } else {
        $virusResult = "Virus Scan is disabled";
    }

    $exec = $renderOptions["exec"];
    $outputFormat = $renderOptions["outputFormat"];
    $fileFormat = $renderOptions["fileFormat"];
    $commandFormat = $renderOptions["commandFormat"];
    $urlFormat = $renderOptions["urlFormat"];

    $outputVariables = array(
        "{year}" => date("Y"),
        "{month}" => date("m"),
        "{day}" => date("d"),
        "{hour}" => date("H"),
        "{minute}" => date("i"),
        "{hash}" => $hash,
        "{format}" => $format
    );
    $outputDir = replaceVariables($outputVariables, $outputFormat);
    $file = replaceVariables($outputVariables, $fileFormat);
    $imageUrl = replaceVariables($outputVariables, $urlFormat);
    $outputFile = $outputDir . $file;

    if (!file_exists($outputDir)) {
        mkdir($outputDir, 0777, true);
    }

    $command = replaceVariables(array(
        "{exec}" => $exec,
        "{options}" => $optionsString,
        "{url}" => $url,
        "{output}" => $outputFile
    ), $commandFormat);

    //Run wkhtmltopdf
    touch($outputFile);
    $finalOutput = exec($command, $renderOutput, $returnVar);
    chmod($outputFile, 0777);

    $endTime = microtime(true);
    $duration = $endTime - $startTime;

    if ($returnVar === 0 && file_exists($outputFile)) {
        if ($redirect) {
            header("Location: $imageUrl");
        } else {
            echoData(array(
                "hash" => $hash,
                "url" => $url,
                "format" => $format,
                "options" => $validOptions,
                "time" => time(),
                "duration" => $duration,
                "expiration" => strtotime($app->renderOptions["expiration"]),
                "image" => $imageUrl,
                "size" => filesize($outputFile),
                "virusResult" => $virusResult,
                "render" => array(
                    "status" => $returnVar,
                    "output" => $renderOutput,
                    "finalOutput" => $finalOutput
                )
            ), 200);
        }
    } else {
        echoData(array(
            "error" => "Rendering failed",
            "details" => array(
                "message" => $finalOutput,
                "url" => $url,
                "command" => $command,
                "render" => array(
                    "status" => $returnVar,
                    "output" => $renderOutput,
                    "finalOutput" => $finalOutput
                ))), 500);
    }
});

$app->get("/getoptions", function () use ($app) {
    echoData($app->renderOptions["wkhtmltopdf"]["allowedOptions"]);
});

$app->run();

function replaceVariables($variables, $target)
{
    foreach ($variables as $variable => $value) {
        $target = str_replace($variable, $value, $target);
    }
    return $target;
}

function parseOptions($specifiedOptions, $allowedOptions, &$errorMessage)
{
    if (empty($specifiedOptions)) {// There are no options
        return array();
    }

    $validOptions = array();
    $ignoredOptions = array();//TODO

    foreach ($allowedOptions as $allowed) {
        $key = $allowed["key"];
        if (isset($specifiedOptions[$key])) {
            $value = $specifiedOptions[$key];
            switch ($allowed["type"]) {
                case "number": {
                    if (!is_numeric($value)) {
                        $errorMessage = "Option '$key' must be a number";
                        return false;
                    }
                    if (isset($allowed["boundaries"])) {
                        if ($value < $allowed["boundaries"]["min"]) {
                            $errorMessage = "Value for '$key' must not be smaller than " . $allowed["boundaries"]["min"];
                            return false;
                        }
                        if ($value > $allowed["boundaries"]["max"]) {
                            $errorMessage = "Value for '$key' must not be larger than " . $allowed["boundaries"]["max"];
                            return false;
                        }
                    }
                    break;
                }
                case "boolean": {
                    if (isset($allowed["changeTo"])) {// Change the option key based on the boolean
                        if (true === $value) {
                            $key = $allowed["changeTo"]["true"];
                        } else if (false === $value) {
                            $key = $allowed["changeTo"]["false"];
                        } else {
                            //Whatever
                        }
                    } else {
                        if ("false" === $value) {
                            continue;// Skip the option if it's specifically 'false'; treat anything else as true
                        }
                    }
                    $value = "";// remove the value
                    break;
                }
                case "string": {
                    break;
                }
            }

            $validOptions[$key] = $value;
        }
    }

    return $validOptions;
}

function checkVirusTotal($app, $url)
{
    $apiKey = $app->renderOptions["virustotal"]["api_key"];

    $post = array('apikey' => $apiKey, 'resource' => $url);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://www.virustotal.com/vtapi/v2/url/report');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_ENCODING, 'gzip,deflate');
    curl_setopt($ch, CURLOPT_USERAGENT, "gzip, Webrender-API");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);

    $result = curl_exec($ch);
    $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($status_code == 200) { // OK
        $json = json_decode($result, true);
    } else {  // Error occured
        $json = false;
    }
    curl_close($ch);
    if ($json) {
        $scanned = $json["response_code"] === 1;
        if (!$scanned) {// Start a new scan
            $post = array('apikey' => $apiKey, 'url' => $url);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'https://www.virustotal.com/vtapi/v2/url/scan');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);

            curl_exec($ch);
            curl_close($ch);
        }
        return array(
            "resource_scanned" => $scanned,
            "scan_link" => ($scanned ? $json["permalink"] : ""),
            "scan_date" => ($scanned ? $json["scan_date"] : ""),
            "scan_message" => $json["verbose_msg"],
            "score" => array(
                "positives" => ($scanned ? $json["positives"] : -1),
                "total" => ($scanned ? $json["total"] : -1)
            )
        );
    }
    return false;
}

function echoData($json, $status = 0)
{
    $app = \Slim\Slim::getInstance();

    $app->response()->header("X-Api-Time", time());
    $app->response()->header("Connection", "close");

    $paramPretty = $app->request()->params("pretty");
    $pretty = true;
    if (!is_null($paramPretty)) {
        $pretty = $paramPretty !== "false";
    }

    if ($status !== 0) {
        $app->response->setStatus($status);
        http_response_code($status);
    }

    $app->contentType("application/json; charset=utf-8");
    header("Content-Type: application/json; charset=utf-8");

    if ($pretty) {
        $serialized = json_encode($json, JSON_PRETTY_PRINT, JSON_UNESCAPED_UNICODE);
    } else {
        $serialized = json_encode($json, JSON_UNESCAPED_UNICODE);
    }

    $jsonpCallback = $app->request()->params("callback");
    if (!is_null($jsonpCallback)) {
        echo $jsonpCallback . "(" . $serialized . ")";
    } else {
        echo $serialized;
    }
}