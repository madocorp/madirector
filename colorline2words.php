<?php

$text = "Héllo世界"; // UTF-8 string
$props = "...";      // 7 bytes per character, total = chars * 7

// Split UTF-8 string into characters
$chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);

$result = [];

$currentText  = '';
$currentProps = null;

foreach ($chars as $i => $char) {
    $propChunk = substr($props, $i * 7, 7);
    if ($currentProps === null || $propChunk !== $currentProps) {
        // Start new group
        if ($currentText !== '') {
            $result[] = [
                'text'  => $currentText,
                'props' => $currentProps,
            ];
        }
        $currentText  = $char;
        $currentProps = $propChunk;
    } else {
        // Same properties, append character
        $currentText .= $char;
    }
}

// Push last group
if ($currentText !== '') {
    $result[] = [
        'text'  => $currentText,
        'props' => $currentProps,
    ];
}

print_r($result);

