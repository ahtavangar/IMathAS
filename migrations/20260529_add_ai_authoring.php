<?php

//Add imas_ai_authoring_log table (AI Question Authoring usage log)
$DBH->beginTransaction();

$query = 'CREATE TABLE `imas_ai_authoring_log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `userid` INT UNSIGNED NOT NULL,
  `mode` VARCHAR(16) NOT NULL,
  `qtype` VARCHAR(20) NOT NULL DEFAULT \'\',
  `libs` VARCHAR(254) NOT NULL DEFAULT \'\',
  `prompt` MEDIUMTEXT NOT NULL,
  `response` MEDIUMTEXT NOT NULL,
  `verified` TINYINT(1) NOT NULL DEFAULT 0,
  `retries` TINYINT(1) NOT NULL DEFAULT 0,
  `tokens_in` INT UNSIGNED NOT NULL DEFAULT 0,
  `tokens_out` INT UNSIGNED NOT NULL DEFAULT 0,
  `created` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  INDEX (`userid`),
  INDEX (`created`)
) CHARACTER SET UTF8 COLLATE utf8_general_ci ENGINE = InnoDB ROW_FORMAT=DYNAMIC ;';
$res = $DBH->query($query);
if ($res===false) {
    echo "<p>Query failed: ($query) : ".implode(' ',$DBH->errorInfo())."</p>";
    $DBH->rollBack();
    return false;
}

if ($DBH->inTransaction()) { $DBH->commit(); }
echo '<p style="color: green;">✓ table imas_ai_authoring_log.</p>';

return true;
