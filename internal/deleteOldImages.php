<?php
$config = json_decode(file_get_contents(__DIR__ . "/config.json"), true);

$dir = $config["deleteBaseDir"];
$command = $config["deleteCommand"];
$command = str_replace("{path}", $dir, $command);

echo "Executing '$command'...\n";
echo exec($command);
