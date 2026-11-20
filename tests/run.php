<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$testFiles = glob(__DIR__ . '/*Test.php') ?: [];
$results = [];

foreach ($testFiles as $testFile) {
    $cases = require $testFile;
    if (!is_array($cases)) {
        $results[basename($testFile) . ' returns test cases'] = false;
        continue;
    }
    foreach ($cases as $name => $case) {
        $label = basename($testFile) . ': ' . (string) $name;
        try {
            if (!is_callable($case)) {
                throw new RuntimeException('test case is not callable');
            }
            $case($root);
            $results[$label] = true;
        } catch (Throwable $error) {
            $results[$label] = $error->getMessage();
        }
    }
}

$passed = 0;
foreach ($results as $name => $result) {
    if ($result === true) {
        $passed++;
        echo "PASS {$name}\n";
    } else {
        echo "FAIL {$name}: {$result}\n";
    }
}
$total = count($results);
$failed = $total - $passed;
echo "Passed: {$passed}\nFailed: {$failed}\nTotal: {$total}\n";
exit($failed === 0 ? 0 : 1);