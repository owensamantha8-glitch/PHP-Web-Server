<?php
// Shared settings, login and page access (see /var/www/Lynx/bootstrap.php)
require_once '/var/www/Lynx/bootstrap.php';
lum_page('imports', 'view');
// Uploading, starting, cancelling or clearing an import changes data: edit rights required
if (isset($_GET['action']) || isset($_GET['clear_progress']) || isset($_GET['kill_import'])) {
    lum_require_access('imports', 'edit');
}
ini_set('pcre.jit', '0'); // Safely disable PCRE JIT to prevent memory allocation security warnings

// Detect if PHP dropped the payload due to post_max_size limits
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    // Send a 413 Payload Too Large HTTP status
    http_response_code(413);
    die("Server Error: Total upload size exceeds server limits.");
}

$userId = md5($_SESSION['user_name'] ?? session_id());

// Directories and State Files
$progFile = sys_get_temp_dir() . '/lynx_import_prog_' . $userId . '.json';
$killFile = sys_get_temp_dir() . '/lynx_import_kill_' . $userId . '.flag';
$stagingDir = sys_get_temp_dir() . '/lynx_import_staging_' . $userId;

function clearStaging($dir) {
    if (is_dir($dir)) {
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) { @unlink("$dir/$file"); }
        @rmdir($dir);
    }
}

// ---------------------------------------------------------
// RECOVERY ENDPOINTS (Triggered by frontend JavaScript)
// ---------------------------------------------------------
if (isset($_GET['check_progress'])) {
    session_write_close(); // Prevent session locking during polls
    echo "###REC_START###";
    if (file_exists($progFile)) {
        echo file_get_contents($progFile);
    } else {
        echo json_encode(['status' => 'none']);
    }
    echo "###REC_END###";
    exit;
}

// Import control endpoints change state, so they require the session CSRF token
$lum_control_token_ok = (isset($_GET['csrf_token']) && hash_equals($_SESSION['csrf_token'] ?? '', (string)$_GET['csrf_token']));

if (isset($_GET['clear_progress'])) {
    if (!$lum_control_token_ok) { http_response_code(403); die("Security Error: Invalid CSRF Token."); }
    @unlink($progFile);
    @unlink($killFile);
    clearStaging($stagingDir);
    echo "###REC_START###" . json_encode(['status' => 'cleared']) . "###REC_END###";
    exit;
}

if (isset($_GET['kill_import'])) {
    if (!$lum_control_token_ok) { http_response_code(403); die("Security Error: Invalid CSRF Token."); }
    file_put_contents($killFile, '1');
    @unlink($progFile);
    clearStaging($stagingDir);
    echo "###REC_START###" . json_encode(['status' => 'killed']) . "###REC_END###";
    exit;
}

// ---------------------------------------------------------
// SEQUENTIAL FILE UPLOAD RECEIVER (Bypasses ModSecurity PCRE Limits)
// ---------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'upload_file') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403); die("Security Error: Invalid CSRF Token.");
    }
    if (!is_dir($stagingDir)) {
        mkdir($stagingDir, 0700, true);
    }
    if (isset($_FILES['single_file']) && $_FILES['single_file']['error'] === UPLOAD_ERR_OK) {
        $safeName = preg_replace('/[^a-zA-Z0-9_\-\.\s]/', '_', basename($_FILES['single_file']['name']));
        $safeName = ltrim($safeName, '.');
        $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
        if ($safeName === '' || !in_array($ext, ['csv', 'txt'], true)) {
            http_response_code(400); die("Upload Error: only CSV files can be imported.");
        }
        move_uploaded_file($_FILES['single_file']['tmp_name'], $stagingDir . '/' . $safeName);
        @chmod($stagingDir . '/' . $safeName, 0600);
        echo "OK";
    } else {
        http_response_code(400); echo "Upload Error";
    }
    exit;
}

// ---------------------------------------------------------
// MAIN BACKGROUND PROCESSING ENGINE
// ---------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'process_all') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403); die("Security Error: Invalid CSRF Token.");
    }

    // Server-level connection for the yearly import databases (settings from bootstrap.php)
    $config = lum_db_config();
    if ($config === null) { die("Critical Error: Unable to load secure database configuration."); }

    $conn = new mysqli($config['host'], $config['username'], $config['password']);
    if ($conn->connect_error) { error_log('LUM import DB connection failed: ' . $conn->connect_error); die("Database connection failed."); }
    $conn->set_charset('utf8mb4');

    $importMessages = '';

    // Prevent massive files from timing out and survive tab closures
    ignore_user_abort(true);
    set_time_limit(0);
    
    // Release the active session lock immediately
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    // Immediately sever the HTTP connection to the browser so the UI unblocks
    header('Connection: close');
    header('Content-Length: 0');
    @ob_end_flush();
    @ob_flush();
    @flush();

    $statementCache = [];

    // Gather all sequentially uploaded files from the isolated staging directory
    $filesToProcess = [];
    if (is_dir($stagingDir)) {
        $items = array_diff(scandir($stagingDir), ['.', '..']);
        foreach ($items as $item) {
            if (pathinfo($item, PATHINFO_EXTENSION) === 'csv') {
                $filesToProcess[] = $stagingDir . '/' . $item;
            }
        }
    }

    // --- PHASE 1: Rapid Pre-Calculation of Total Rows ---
    $totalRowsToProcess = 0;
    foreach ($filesToProcess as $filepath) {
        $f = fopen($filepath, 'rb');
        if ($f) {
            while (!feof($f)) {
                $totalRowsToProcess += substr_count(fread($f, 8192), "\n");
            }
            fclose($f);
        }
    }
    if ($totalRowsToProcess === 0) $totalRowsToProcess = 1;
    $globalProcessedRows = 0;

    // --- INITIALIZE RECOVERY STATE ---
    $startTime = time();
    @unlink($killFile); // Clear any old kill signals
    file_put_contents($progFile, json_encode([
        'status' => 'running', 
        'processed' => 0, 
        'total' => $totalRowsToProcess, 
        'start_time' => $startTime
    ]));

    // --- AUDIT TRAIL: one entry per imported file; all files of one job share the job number ($startTime) ---
    lum_use('audit');
    $lum_import_audit = function ($fileName, $importType, $status, $rows, $targets, $serials) use ($startTime) {
        if (!function_exists('lum_audit_log')) return;
        $details = [
            'file'          => $fileName,
            'import_type'   => $importType,
            'status'        => $status,
            'rows_inserted' => (string)(int)$rows,
            'targets'       => implode(', ', array_slice(array_keys($targets), 0, 20)),
            'meter_serials' => implode(', ', array_slice(array_keys($serials), 0, 50)),
        ];
        lum_audit_log('IMPORT', 'csv_import', $startTime, $fileName, null, null, $details);
    };

    // --- PHASE 2: Database Routing & Insertion ---
    foreach ($filesToProcess as $filepath) {
        $fileName = basename($filepath);
        $handle = fopen($filepath, "r");

        if ($handle !== FALSE) {
            $rowCount = 0;
            $lum_file_targets = []; // Databases/tables written by this file (audit trail)
            $lum_file_serials = []; // Meter serials found in this file (audit trail)
            
            $filePreview = fread($handle, 2048);
            $cleanPreview = preg_replace('/[^a-zA-Z0-9\s:;,-]/', '', $filePreview);
            
            $isManualExtract = false;
            if (stripos($cleanPreview, 'Analysis Logger') !== false || 
                stripos($cleanPreview, 'Debit Logger') !== false || 
                stripos($cleanPreview, 'Log id') !== false || 
                stripos($fileName, 'Analysis') !== false || 
                stripos($fileName, 'Debit Logger') !== false) {
                $isManualExtract = true;
            }
            
            if ($isManualExtract) {
                // ==========================================
                // DYNAMIC SCHEMA MANUAL EXTRACT LOGIC
                // ==========================================
                $meterSerial = 'unknown';
                if (preg_match('/Serial\s*number[^\w]*([a-zA-Z0-9]+)/i', $cleanPreview, $matches)) {
                    $meterSerial = preg_replace('/[^a-zA-Z0-9]/', '', $matches[1]);
                } else {
                    if (preg_match('/-\s*([a-zA-Z0-9]+)\s*-/', $fileName, $matches)) {
                        $meterSerial = preg_replace('/[^a-zA-Z0-9]/', '', $matches[1]);
                    }
                }
                
                rewind($handle);
                $headerLineRaw = '';
                while (($line = fgets($handle)) !== false) {
                    if (stripos($line, 'Log id') !== false || stripos($line, 'Log_id') !== false) {
                        $headerLineRaw = $line;
                        break;
                    }
                }
                
                if (empty($headerLineRaw)) {
                    $importMessages .= '<span style="color: red; font-weight: bold;">[-] ERROR</span> Could not locate header row starting with "Log id" in ' . htmlspecialchars($fileName) . '. Ensure the file contains valid headers.<br>';
                    fclose($handle);
                    continue;
                }
                
                $commaCount = substr_count($headerLineRaw, ',');
                $semiCount = substr_count($headerLineRaw, ';');
                $tabCount = substr_count($headerLineRaw, "\t");
                $delimiter = ',';
                if ($semiCount > $commaCount && $semiCount > $tabCount) $delimiter = ';';
                elseif ($tabCount > $commaCount && $tabCount > $semiCount) $delimiter = "\t";
                
                $headerLine = str_getcsv($headerLineRaw, $delimiter, "\"", "\\");
                
                $columns = [];
                $seen = [];
                foreach($headerLine as $col) {
                    $col = trim($col);
                    if ($col !== '') {
                        $colName = str_replace(['+', '-'], ['_plus', '_minus'], $col);
                        $colName = str_replace(' ', '_', $colName);
                        $colName = @preg_replace('/[^a-zA-Z0-9_]/', '', $colName);
                        $colName = @preg_replace('/_+/', '_', $colName);
                        $colName = trim($colName, '_');
                        if ($colName === '') { $colName = 'col'; }
                        $colName = substr($colName, 0, 60); 
                        
                        $originalColName = $colName;
                        $counter = 1;
                        while (isset($seen[$colName])) {
                            $counter++;
                            $colName = $originalColName . '_' . $counter;
                        }
                        $seen[$colName] = true;
                        $columns[] = $colName;
                    }
                }
                
                $firstDataRow = false;
                $posBeforeData = ftell($handle);
                while (($line = fgets($handle)) !== false) {
                    $row = str_getcsv($line, $delimiter, "\"", "\\");
                    if (!empty(array_filter($row))) {
                        $firstDataRow = $row;
                        break;
                    }
                }
                fseek($handle, $posBeforeData);

                $colTypes = [];
                $bindTypes = '';
                
                foreach ($columns as $index => $col) {
                    $val = $firstDataRow ? trim($firstDataRow[$index] ?? '') : '';
                    
                    if (stripos($col, 'rtc') !== false || @preg_match('/^\d{2,4}[\/\-]\d{2}[\/\-]\d{2,4}/', $val)) {
                        $colTypes[] = "DATETIME";
                        $bindTypes .= "s";
                    } elseif (preg_match('/(kWh|kvarh|kW|V|A|kVA)/i', $col) || ($val !== '' && is_numeric(str_replace(',', '.', $val)))) {
                        $colTypes[] = "DECIMAL(50,4)";
                        $bindTypes .= "d";
                    } elseif (stripos($col, 'Log_id') !== false) {
                        $colTypes[] = "INT(11)";
                        $bindTypes .= "i";
                    } else {
                        $colTypes[] = "VARCHAR(255)";
                        $bindTypes .= "s";
                    }
                }
                
                $baseType = 'debit';
                if (stripos($cleanPreview, 'Analysis') !== false || stripos($fileName, 'Analysis') !== false) {
                    $baseType = 'analysis';
                } elseif (stripos($cleanPreview, 'Debit Logger 1') !== false || stripos($fileName, 'Debit Logger 1') !== false || stripos($fileName, 'Debit 1') !== false) {
                    $baseType = 'debit1';
                } elseif (stripos($cleanPreview, 'Debit Logger 2') !== false || stripos($fileName, 'Debit Logger 2') !== false || stripos($fileName, 'Debit 2') !== false) {
                    $baseType = 'debit2';
                }

                if ($meterSerial !== 'unknown' && !empty($columns)) {
                    
                    $schemaString = implode('|', $columns);
                    $schemaHash = substr(md5($schemaString), 0, 10);
                    $tableName = "tb_data_" . $schemaHash;
                    
                    $stmtCache = [];
                    $bindTypesExt = "s" . $bindTypes;
                    
                    $rtcIndex = -1;
                    $logIdIndex = -1;
                    foreach ($columns as $idx => $col) {
                        if (stripos($col, 'rtc') !== false && $rtcIndex === -1) {
                            $rtcIndex = $idx;
                        }
                        if (stripos($col, 'Log_id') !== false && $logIdIndex === -1) {
                            $logIdIndex = $idx;
                        }
                    }
                    
                    // Readings that could not be stored: without a valid time, or no usable table
                    $lum_not_stored = ['no_time' => 0, 'no_table' => 0];
                    $processAndInsert = function($data) use (&$stmtCache, $conn, $columns, $colTypes, $bindTypesExt, $meterSerial, $baseType, $tableName, $rtcIndex, $logIdIndex, &$rowCount, &$lum_file_targets, &$lum_file_serials, &$lum_not_stored) {
                        $insertData = array_slice($data, 0, count($columns));
                        while(count($insertData) < count($columns)) { $insertData[] = ''; }
                        
                        $finalData = [$meterSerial];
                        $year = date('Y');
                        
                        for ($i = 0; $i < count($columns); $i++) {
                            $val = trim($insertData[$i]);
                            
                            if ($colTypes[$i] === 'DATETIME') {
                                if ($val !== '') {
                                    $val = trim(str_replace(['!', '/'], ['', '-'], $val)); 
                                    $parsedDate = strtotime($val);
                                    if ($parsedDate) {
                                        $finalData[] = date('Y-m-d H:i:s', $parsedDate);
                                        if ($i === $rtcIndex) { $year = date('Y', $parsedDate); }
                                    } else {
                                        $finalData[] = null;
                                    }
                                } else {
                                    $finalData[] = null;
                                }
                            } elseif ($colTypes[$i] === 'DECIMAL(50,4)') {
                                if ($val !== '') {
                                    $finalData[] = (float)str_replace(',', '.', $val);
                                } else {
                                    $finalData[] = null;
                                }
                            } else {
                                if ($i === $logIdIndex && $val !== '') {
                                    $finalData[] = (int)$val;
                                } else {
                                    $finalData[] = $val;
                                }
                            }
                        }
                        
                        // A reading without a valid time cannot be stored: the time decides the year's database and is part of the key
                        if ($rtcIndex >= 0 && $finalData[$rtcIndex + 1] === null) {
                            $lum_not_stored['no_time']++;
                            return;
                        }

                        $targetDB = "db_manual_" . $baseType . "_" . $year;
                        $lum_file_targets[$targetDB . '.' . $tableName] = true;
                        $lum_file_serials[$meterSerial] = true;
                        
                        if (!array_key_exists($targetDB, $stmtCache)) {
                            try {
                                $conn->query("CREATE DATABASE IF NOT EXISTS `$targetDB`");
                                
                                $createSql = "CREATE TABLE IF NOT EXISTS `$targetDB`.`$tableName` (";
                                $createSql .= "`meter_serial` VARCHAR(50) NOT NULL, ";
                                
                                $hasRTC = false;
                                $rtcColName = '';
                                $logIdCol = '';
                                
                                foreach($columns as $index => $col) {
                                    if ($index === $rtcIndex) {
                                        $hasRTC = true;
                                        $rtcColName = $col;
                                    }
                                    if ($index === $logIdIndex) {
                                        $logIdCol = $col;
                                        $createSql .= "`$col` INT(11) NOT NULL, ";
                                    } elseif ($index === $rtcIndex) {
                                        // Part of the primary key, so it must be NOT NULL (MySQL refuses the table otherwise)
                                        $createSql .= "`$col` " . $colTypes[$index] . " NOT NULL, ";
                                    } else {
                                        $createSql .= "`$col` " . $colTypes[$index] . " DEFAULT NULL, ";
                                    }
                                }
                                
                                if ($hasRTC) {
                                    $createSql .= "PRIMARY KEY (`meter_serial`, `$rtcColName`)";
                                } elseif ($logIdCol !== '') {
                                    $createSql .= "PRIMARY KEY (`meter_serial`, `$logIdCol`)";
                                } else {
                                    $createSql = rtrim($createSql, ", ");
                                }
                                $createSql .= ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
                                
                                $conn->query($createSql);

                                // Older tables are keyed on the logger's record number (Log_id), which can start again after a
                                // logger reset. Switch them to the reading time - only when that is safe (checked once per import).
                                $lum_pk_key = $targetDB . '.' . $tableName;
                                if ($hasRTC && empty($GLOBALS['lum_import_pk_checked'][$lum_pk_key])) {
                                    $GLOBALS['lum_import_pk_checked'][$lum_pk_key] = true;
                                    $pkCheck = $conn->query("SHOW KEYS FROM `$targetDB`.`$tableName` WHERE Key_name = 'PRIMARY'");
                                    if ($pkCheck !== false) {
                                        $hasRtcInPk = false;
                                        $hasAnyPk = ($pkCheck->num_rows > 0);
                                        while ($pkRow = $pkCheck->fetch_assoc()) {
                                            if ($pkRow['Column_name'] === $rtcColName) { $hasRtcInPk = true; break; }
                                        }
                                        if (!$hasRtcInPk) {
                                            $keyStats = $conn->query("SELECT COALESCE(SUM(`$rtcColName` IS NULL), 0) AS no_time,
                                                                             COUNT(*) - COALESCE(SUM(`$rtcColName` IS NULL), 0) - COUNT(DISTINCT `meter_serial`, `$rtcColName`) AS dup
                                                                      FROM `$targetDB`.`$tableName`")->fetch_assoc();
                                            if ((int)$keyStats['no_time'] === 0 && (int)$keyStats['dup'] === 0) {
                                                $conn->query("ALTER TABLE `$targetDB`.`$tableName` MODIFY `$rtcColName` DATETIME NOT NULL, "
                                                           . ($hasAnyPk ? "DROP PRIMARY KEY, " : "")
                                                           . "ADD PRIMARY KEY (`meter_serial`, `$rtcColName`)");
                                                error_log("LUM import: $lum_pk_key is now keyed on meter and reading time ($rtcColName)");
                                            } else {
                                                error_log("LUM import: $lum_pk_key keeps its key on the record number - " . (int)$keyStats['no_time']
                                                        . " reading(s) without a time, " . (int)$keyStats['dup'] . " duplicate time(s) (see lynx-manual-keys.sh)");
                                            }
                                        }
                                    }
                                }
                            } catch (Exception $e) {
                                // The table may still be usable (e.g. only the key change failed): the insert below decides
                                error_log("LUM import: table check for $targetDB.$tableName: " . $e->getMessage());
                            }
                            
                            $placeholders = "?, " . implode(",", array_fill(0, count($columns), "?"));
                            $colNames = "`meter_serial`, `" . implode("`, `", $columns) . "`";
                            $insertSql = "INSERT IGNORE INTO `$targetDB`.`$tableName` ($colNames) VALUES ($placeholders)";
                            
                            try {
                                $stmt = $conn->prepare($insertSql);
                                if ($stmt) {
                                    $stmtCache[$targetDB] = $stmt;
                                } else {
                                    $stmtCache[$targetDB] = false;
                                    error_log("LUM import: readings for $targetDB.$tableName cannot be stored: " . $conn->error);
                                }
                            } catch (Exception $e) {
                                $stmtCache[$targetDB] = false;
                                error_log("LUM import: readings for $targetDB.$tableName cannot be stored: " . $e->getMessage());
                            }
                        }
                        if ($stmtCache[$targetDB] === false) {
                            $lum_not_stored['no_table']++;
                            return;
                        }
                        
                        $stmt = $stmtCache[$targetDB];
                        $refs = [];
                        $refs[] = $bindTypesExt;
                        foreach ($finalData as $k => $v) { $refs[] = &$finalData[$k]; }
                        call_user_func_array([$stmt, 'bind_param'], $refs);
                        $stmt->execute();
                        if ($stmt->affected_rows > 0) { $rowCount++; }
                    };

                    if ($firstDataRow) { $processAndInsert($firstDataRow); }

                    while (($line = fgets($handle)) !== false) {
                        $data = str_getcsv($line, $delimiter, "\"", "\\");
                        $globalProcessedRows++;
                        
                        if ($globalProcessedRows % 100 === 0 && file_exists($killFile)) {
                            $lum_import_audit($fileName, ($isManualExtract ? 'Manual extract' : 'OBIS log'), 'cancelled by user', $rowCount, $lum_file_targets, $lum_file_serials); @unlink($killFile); @unlink($progFile); clearStaging($stagingDir); exit;
                        }
                        
                        if ($globalProcessedRows % 500 === 0) {
                            $progData = json_encode(['status' => 'running', 'processed' => $globalProcessedRows, 'total' => $totalRowsToProcess, 'start_time' => $startTime]);
                            file_put_contents($progFile, $progData);
                        }

                        if (empty(array_filter($data))) continue;
                        $processAndInsert($data);
                    }
                    
                    foreach ($stmtCache as $s) { if ($s) $s->close(); }
                    $importMessages .= '<span style="color: #0dcaf0; font-weight: bold;">[+] IMPORTED</span> File: ' . htmlspecialchars($fileName) . ' (Dynamic Schema Extract) processed (' . $rowCount . ' new rows)<br>';
                    if ($lum_not_stored['no_time'] > 0 || $lum_not_stored['no_table'] > 0) {
                        $importMessages .= '<span style="color: orange; font-weight: bold;">[!] NOT STORED</span> ' . htmlspecialchars($fileName) . ': '
                            . ($lum_not_stored['no_time'] > 0 ? (int)$lum_not_stored['no_time'] . ' reading(s) without a valid time' : '')
                            . ($lum_not_stored['no_time'] > 0 && $lum_not_stored['no_table'] > 0 ? ', ' : '')
                            . ($lum_not_stored['no_table'] > 0 ? (int)$lum_not_stored['no_table'] . ' reading(s) because the table could not be prepared (see the error log)' : '')
                            . '<br>';
                    }
                    $lum_import_audit($fileName, 'Manual extract', 'imported', $rowCount, $lum_file_targets, $lum_file_serials);
                    
                } else {
                    $importMessages .= '<span style="color: red; font-weight: bold;">[-] ERROR</span> Unrecognized Manual Extract structure in: ' . htmlspecialchars($fileName) . '<br>';
                    $lum_import_audit($fileName, 'Manual extract', 'error: unrecognised file structure', 0, [], []);
                }
                
            } else {
                
                // ==========================================
                // CONSOLIDATED SMART METER IMPORT LOGIC
                // ==========================================
                rewind($handle);
                $firstLine = fgets($handle);
                $commaCount = substr_count($firstLine, ',');
                $semiCount = substr_count($firstLine, ';');
                $delimiter = ($semiCount > $commaCount) ? ';' : ',';
                rewind($handle); 

                while (($data = fgetcsv($handle, 1000, $delimiter, "\"", "\\")) !== FALSE) {
                    $globalProcessedRows++;
                    
                    if ($globalProcessedRows % 100 === 0 && file_exists($killFile)) {
                        $lum_import_audit($fileName, ($isManualExtract ? 'Manual extract' : 'OBIS log'), 'cancelled by user', $rowCount, $lum_file_targets, $lum_file_serials); @unlink($killFile); @unlink($progFile); clearStaging($stagingDir); exit;
                    }

                    if ($globalProcessedRows % 500 === 0) {
                        $progData = json_encode(['status' => 'running', 'processed' => $globalProcessedRows, 'total' => $totalRowsToProcess, 'start_time' => $startTime]);
                        file_put_contents($progFile, $progData);
                    }

                    if (count($data) < 3) continue;

                    // STRICT HEADER BYPASS: Skip any row where the first or second column contains literal header strings
                    if (stripos($data[0], 'Serial') !== false || stripos($data[1], 'Time') !== false || stripos($data[2], 'OBIS') !== false) {
                        continue; 
                    }

                    if (!@preg_match('/[0-9]/', $data[1])) continue; 

                    $rawDate = str_replace('/', '-', $data[1]);
                    $timestamp = strtotime($rawDate);

                    if ($timestamp === false || $timestamp < 946684800) { continue; }

                    $formattedDate = date('Y-m-d H:i:s', $timestamp);
                    $readingYear = date('Y', $timestamp);

                    $rawObis = $data[2] ?? '';
                    $formattedObis = str_replace('.255', '', $rawObis);
                    $cleanCode = @preg_replace('/[^0-9]/', '', $formattedObis);
                    $meterSerial = @preg_replace('/[^a-zA-Z0-9]/', '', $data[0]);

                    if (empty($cleanCode) || strlen($cleanCode) > 10 || strlen($cleanCode) < 4) { continue; }

                    $targetDB = "db_obis_" . $cleanCode . "_" . $readingYear;
                    $tableName = "tb_obis_" . $cleanCode . "_" . $readingYear;
                    $valueColumn = $cleanCode . "_value"; 
                    $cacheKey = $targetDB . "_" . $tableName;
                    $lum_file_targets[$targetDB . '.' . $tableName] = true;
                    if ($meterSerial !== '' && count($lum_file_serials) < 200) { $lum_file_serials[$meterSerial] = true; }

                    if (!isset($statementCache[$cacheKey])) {
                        try {
                            $conn->query("CREATE DATABASE IF NOT EXISTS `$targetDB`");
                            $conn->select_db($targetDB);
                            
                            $createTableSql = "CREATE TABLE IF NOT EXISTS `$tableName` (
                                `meter_serial` varchar(50) NOT NULL,
                                `OBIS_code` varchar(50) NOT NULL,
                                `Time_stamp` datetime NOT NULL,
                                `Unit` varchar(20) DEFAULT NULL,
                                `$valueColumn` decimal(50,4) NOT NULL,
                                PRIMARY KEY (`meter_serial`, `Time_stamp`),
                                INDEX `idx_timestamp` (`Time_stamp`)
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
                            
                            $conn->query($createTableSql);
                        } catch (Exception $e) {
                            error_log("Failed to prepare smart meter schema: $tableName in $targetDB. Error: " . $e->getMessage());
                            continue;
                        }
                        
                        try {
                            $stmt = $conn->prepare("INSERT IGNORE INTO `$targetDB`.`$tableName` (`meter_serial`, `OBIS_code`, `Time_stamp`, `Unit`, `$valueColumn`) VALUES (?, ?, ?, ?, ?)");
                            if ($stmt) { $statementCache[$cacheKey] = $stmt; } else { continue; }
                        } catch (Exception $e) {
                            continue;
                        }
                    }

                    if (isset($statementCache[$cacheKey])) {
                        $unit = $data[4] ?? ''; 
                        $statementCache[$cacheKey]->bind_param("sssss", $meterSerial, $formattedObis, $formattedDate, $unit, $data[3]);
                        $statementCache[$cacheKey]->execute();
                        if ($statementCache[$cacheKey]->affected_rows > 0) { $rowCount++; }
                    }
                }
                
                $importMessages .= '<span style="color: green; font-weight: bold;">[+] SUCCESS</span> File: ' . htmlspecialchars($fileName) . ' (Smart OBIS Log) routed & processed (' . $rowCount . ' new rows)<br>';
                $lum_import_audit($fileName, 'OBIS log', 'imported', $rowCount, $lum_file_targets, $lum_file_serials);
            }
            fclose($handle);
        } else {
            $importMessages .= '<span style="color: red; font-weight: bold;">[-] ERROR</span> Could not open file: ' . htmlspecialchars($fileName) . '<br>';
            $lum_import_audit($fileName, 'unknown', 'error: file could not be opened', 0, [], []);
        }
    }

    foreach ($statementCache as $cachedStmt) { $cachedStmt->close(); }
    
    // Finalize state recovery file to 'complete' so the Javascript UI knows to unlock the form
    file_put_contents($progFile, json_encode(['status' => 'complete', 'html' => $importMessages]));
    clearStaging($stagingDir);
    exit;
}
?>

<!-- HTML FORM & UI MARKUP -->
<div class="container bg-dark text-light p-4 rounded-3 shadow-sm border border-secondary mt-3">
    <div class="row mb-2">
        <div class="col-md">
            <h5 class="text-uppercase tracking-wider fw-bold text-danger"><i class="bi bi-file-earmark-arrow-up me-2"></i>Import</h5>
        </div>
    </div>

    <form id="uploadForm">
        <input type="hidden" name="csrf_token" id="csrf_token" value="<?php echo $csrf_token; ?>">
        <div class="row align-items-center g-2 mt-2">
            <div class="col-md-8">
                <input type="file" id="csv_files" name="csv_files[]" class="form-control bg-dark text-light border-secondary rounded-0" multiple accept=".csv">
            </div>
            <div class="col-md-4 d-flex">
                <button type="submit" id="submitBtn" class="btn btn-danger w-100 fw-bold rounded-0">
                    <i class="bi bi-cloud-upload me-2"></i>Execute Global Import
                </button>
                <button type="button" id="cancelBtn" class="btn btn-outline-secondary rounded-0 ms-2 fw-bold" style="display: none; transition: 0.2s;">
                    <i class="bi bi-x-circle me-1"></i>Cancel Upload
                </button>
            </div>
        </div>
    </form>    

    <!-- Dynamic AJAX Progress UI -->
    <div id="progressContainer" class="mt-4" style="display: none;">
        <div class="d-flex justify-content-between mb-1">
            <span class="small text-info" id="progressText" style="font-family: 'Segoe UI', Tahoma, sans-serif;">0% Uploaded</span>
        </div>
        <div class="progress rounded-0" style="height: 12px; background-color: #222; border: 1px solid #444;">
            <div id="progressBar" class="progress-bar bg-info progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%; transition: width 0.1s linear;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
        </div>
    </div>
</div>

<!-- AJAX Result Container -->
<div id="resultContainer" class="container bg-dark text-light p-4 rounded-3 shadow-sm border border-secondary mt-3" style="display: none;">
    <h5 class="text-uppercase tracking-wider fw-bold text-info mb-3"><i class="bi bi-journal-check me-2"></i>Import Logs</h5>
    <div id="resultBox" class="small" style="line-height: 1.8;"></div>
</div>

<!-- INTERACTIVE ISOLATED POLLING JAVASCRIPT -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('uploadForm');
    const fileInput = document.getElementById('csv_files');
    const csrfToken = document.getElementById('csrf_token').value;
    const progressContainer = document.getElementById('progressContainer');
    const progressBar = document.getElementById('progressBar');
    const progressText = document.getElementById('progressText');
    const cancelBtn = document.getElementById('cancelBtn');
    const submitBtn = document.getElementById('submitBtn');
    const resultContainer = document.getElementById('resultContainer');
    const resultBox = document.getElementById('resultBox');

    let recoveryInterval = null;
    let killSignalReceived = false;

    // Mathematical UI Formatter
    function updateProgressUI(proc, tot, sTime) {
        let perc = Math.round((proc / tot) * 100);
        let elapsed = (Date.now() / 1000) - sTime;
        let rowsPerSec = elapsed > 0 ? (proc / elapsed) : 0;
        let secsLeft = rowsPerSec > 0 ? Math.round((tot - proc) / rowsPerSec) : 0;
        
        let mins = Math.floor(secsLeft / 60);
        let secs = secsLeft % 60;
        let timeLeftStr = mins > 0 ? `${mins}m ${secs}s` : `${secs}s`;

        progressBar.style.width = perc + '%';
        progressBar.setAttribute('aria-valuenow', perc);
        progressText.innerHTML = `Formatting & Writing DB Rows: <strong style="color: #0dcaf0;">${proc.toLocaleString()} / ${tot.toLocaleString()}</strong> (${perc}%) &nbsp;|&nbsp; <span class="text-warning">Time Remaining: ${timeLeftStr}</span>`;
    }

    // Polling function for Isolated Status Recovery
    function checkRecovery() {
        if (killSignalReceived) return;
        
        fetch(window.location.pathname + '?check_progress=1', { cache: 'no-store' })
        .then(r => r.text())
        .then(text => {
            const match = text.match(/###REC_START###(.*?)###REC_END###/s);
            if (match && match[1]) {
                const data = JSON.parse(match[1]);
                
                if (data.status === 'running') {
                    progressContainer.style.display = 'block';
                    cancelBtn.style.display = 'inline-block';
                    fileInput.disabled = true;
                    submitBtn.disabled = true;
                    form.style.opacity = '0.5';

                    progressBar.classList.add('progress-bar-striped', 'progress-bar-animated');
                    updateProgressUI(data.processed, data.total, data.start_time);

                    if (!recoveryInterval) {
                        recoveryInterval = setInterval(checkRecovery, 1500);
                    }
                    
                } else if (data.status === 'complete') {
                    if (recoveryInterval) { clearInterval(recoveryInterval); recoveryInterval = null; }
                    
                    progressText.innerHTML = '<span class="text-success fw-bold">Execution Finished Successfully.</span>';
                    progressBar.style.width = '100%';
                    progressBar.classList.remove('progress-bar-striped', 'progress-bar-animated');
                    
                    resultBox.innerHTML = data.html;
                    resultContainer.style.display = 'block';
                    
                    fileInput.disabled = false;
                    submitBtn.disabled = false;
                    cancelBtn.style.display = 'none';
                    form.style.opacity = '1';
                    
                    fetch(window.location.pathname + '?clear_progress=1&csrf_token=' + encodeURIComponent(document.getElementById('csrf_token').value));
                }
            }
        }).catch(err => console.log("Polling offline."));
    }

    checkRecovery();

    if (form) {
        form.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const files = fileInput.files;
            if (files.length === 0) {
                alert('Please select files to upload.');
                return;
            }

            killSignalReceived = false;
            progressContainer.style.display = 'block';
            cancelBtn.style.display = 'inline-block';
            fileInput.disabled = true;
            submitBtn.disabled = true;
            form.style.opacity = '0.5';

            progressBar.style.width = '0%';
            progressBar.setAttribute('aria-valuenow', 0);
            progressBar.classList.add('progress-bar-striped', 'progress-bar-animated');
            resultContainer.style.display = 'none';
            resultBox.innerHTML = '';

            let hasError = false;

            // SEQUENTIAL CHUNKING: Bypass WAF limits by uploading files individually
            for (let i = 0; i < files.length; i++) {
                if (killSignalReceived) break;
                
                let perc = Math.round((i / files.length) * 100);
                progressBar.style.width = perc + '%';
                progressText.innerText = `Bypassing WAF limits... Uploading file ${i + 1} of ${files.length}...`;

                const fd = new FormData();
                fd.append('single_file', files[i]);
                fd.append('csrf_token', csrfToken);

                try {
                    const response = await fetch(window.location.pathname + '?action=upload_file', {
                        method: 'POST',
                        body: fd
                    });
                    if (!response.ok) throw new Error('Upload blocked');
                } catch (err) {
                    hasError = true;
                    progressText.innerHTML = '<span class="text-danger fw-bold">Network or Security Error during upload. Check WAF constraints.</span>';
                    break;
                }
            }

            if (hasError || killSignalReceived) {
                fileInput.disabled = false;
                submitBtn.disabled = false;
                cancelBtn.style.display = 'none';
                form.style.opacity = '1';
                progressBar.classList.remove('progress-bar-striped', 'progress-bar-animated');
                return;
            }

            progressBar.style.width = '100%';
            progressText.innerText = 'Upload complete. Launching Background Engine... Please wait.';

            // LAUNCH THE BACKGROUND PROCESSING ENGINE
            try {
                const params = new URLSearchParams({ csrf_token: csrfToken });
                fetch(window.location.pathname + '?action=process_all', { method: 'POST', body: params });
                
                if (!recoveryInterval) {
                    recoveryInterval = setInterval(checkRecovery, 1500);
                }
            } catch (err) {
                progressText.innerHTML = '<span class="text-danger fw-bold">Failed to launch background engine.</span>';
            }
        });
    }

    if (cancelBtn) {
        cancelBtn.addEventListener('click', function() {
            killSignalReceived = true;
            fetch(window.location.pathname + '?kill_import=1&csrf_token=' + encodeURIComponent(document.getElementById('csrf_token').value)).then(() => {
                if (recoveryInterval) { clearInterval(recoveryInterval); recoveryInterval = null; }
                fileInput.disabled = false;
                submitBtn.disabled = false;
                cancelBtn.style.display = 'none';
                form.style.opacity = '1';
                progressBar.style.width = '0%';
                progressBar.classList.remove('progress-bar-striped', 'progress-bar-animated');
                progressText.innerHTML = '<span class="text-danger fw-bold">Upload Cancelled. Background process and temporary files purged.</span>';
                fileInput.value = ''; 
                fetch(window.location.pathname + '?clear_progress=1&csrf_token=' + encodeURIComponent(document.getElementById('csrf_token').value));
            });
        });
    }
});
</script>