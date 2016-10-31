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

    $hash = sha1($url . microtime(true));
    $optionsString = "";//TODO

    //TODO: Options, Format, Name, etc.


    $renderOptions = $app->renderOptions["wkhtmltopdf"];
    $exec = $renderOptions["exec"];
    $output = $renderOptions["output"];
    $outputFormat = $renderOptions["outputFormat"];
    $commandFormat = $renderOptions["command"];
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
    $output = replaceVariables($outputVariables, $output);
    $outputFormat = replaceVariables($outputVariables, $outputFormat);
    $imageUrl = replaceVariables($outputVariables, $urlFormat);
    $outputFile = $output . $outputFormat;

    if (!file_exists($output)) {
        mkdir($output, 0777, true);
    }

    $command = replaceVariables(array(
        "{exec}" => $exec,
        "{options}" => $optionsString,
        "{url}" => $url,
        "{output}" => $outputFile
    ), $commandFormat);

    //Run wkhtmltopdf
    $finalOutput = exec($command, $output, $returnVar);

    if ($returnVar === 0 && file_exists($outputFile)) {
        if ($redirect) {
            header("Location: $imageUrl");
        } else {
            echoData(array(
                "hash" => $hash,
                "url" => $url,
                "format" => $format,
                "time" => time(),
                "expiration" => strtotime($app->renderOptions["expiration"]),
                "image" => $imageUrl
            ));
        }
    } else {
        echoData(array(
            "error" => "Rendering failed",
            "details" => array(
                "command" => $command,
                "render" => array(
                    "status" => $returnVar,
                    "output" => $output,
                    "finalOutput" => $finalOutput
                ))));
    }
});

$app->run();

function replaceVariables($variables, &$target)
{
    foreach ($variables as $variable => $value) {
        $target = str_replace($variable, $value, $target);
    }
    return $target;
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