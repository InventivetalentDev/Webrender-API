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