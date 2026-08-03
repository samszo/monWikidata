<?php
declare(strict_types=1);

const TIME_LIMIT  = (3600*3);
set_time_limit(TIME_LIMIT);

require_once __DIR__ . '/vendor/autoload.php';

use Wikibase\JsonDumpReader\JsonDumpFactory;

// ─── Configuration ────────────────────────────────────────────────────────────
const DUMP_FILE  = __DIR__ . '/wikidata-20240101-all.json.gz';
const MAX_RESULTS = 200;
const NO_LIMIT    = PHP_INT_MAX;
const PREF_LANG    = ['fr','en'];

// ─── MariaDB ─────────────────────────────────────────────────────────────────

function getDb(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $cfg = require __DIR__ . '/config.php';
    $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['dbname']};charset={$cfg['charset']}";
    $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("CREATE TABLE IF NOT EXISTS wikidata_entities (
        id          VARCHAR(20)  NOT NULL PRIMARY KEY,
        type        VARCHAR(20),
        label       VARCHAR(500),
        description TEXT,
        p31         TEXT,
        p279        TEXT,
        wikipedia   VARCHAR(500),
        exported_at DATETIME DEFAULT NOW(),
        INDEX idx_type (type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS wikidata_search (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        query       VARCHAR(500) NOT NULL DEFAULT '',
        lang        VARCHAR(20)  NOT NULL DEFAULT '',
        p31         VARCHAR(20)  NOT NULL DEFAULT '',
        p279        VARCHAR(20)  NOT NULL DEFAULT '',
        entity_type VARCHAR(20)  NOT NULL DEFAULT '',
        created_at  DATETIME DEFAULT NOW(),
        UNIQUE KEY uniq_search (query(191), lang, p31, p279, entity_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS wikidata_search_entities (
        search_id   INT UNSIGNED NOT NULL,
        entity_id   VARCHAR(20)  NOT NULL,
        matched_in  VARCHAR(100) NULL,
        created_at  DATETIME DEFAULT NOW(),
        PRIMARY KEY (search_id, entity_id),
        INDEX idx_se_entity (entity_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS wikidata_properties (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        entity_id   VARCHAR(20)  NOT NULL,
        property    VARCHAR(20)  NOT NULL,
        value_type  VARCHAR(50),
        value_id    VARCHAR(20)  NULL,
        value_str   TEXT         NULL,
        value_lang  VARCHAR(20)  NULL,
        rank        VARCHAR(20)  DEFAULT 'normal',
        created_at  DATETIME     DEFAULT NOW(),
        UNIQUE KEY uniq_epv (entity_id, property, value_id, value_str(100)),
        INDEX idx_entity   (entity_id),
        INDEX idx_property (property),
        INDEX idx_value_id (value_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // migrations pour tables existantes
    try { $pdo->exec("ALTER TABLE wikidata_entities DROP COLUMN IF EXISTS matched_in"); } catch (\Throwable) {}
    try { $pdo->exec("ALTER TABLE wikidata_entities DROP COLUMN IF EXISTS search_q");   } catch (\Throwable) {}
    try { $pdo->exec("ALTER TABLE wikidata_entities DROP INDEX IF EXISTS idx_search");  } catch (\Throwable) {}
    try { $pdo->exec("ALTER TABLE wikidata_properties DROP COLUMN IF EXISTS search_q"); } catch (\Throwable) {}
    try { $pdo->exec("ALTER TABLE wikidata_properties DROP INDEX IF EXISTS idx_psearch"); } catch (\Throwable) {}
    try { $pdo->exec("ALTER TABLE wikidata_entities ADD COLUMN IF NOT EXISTS sitelinks   INT DEFAULT 0 AFTER p279"); } catch (\Throwable) {}
    try { $pdo->exec("ALTER TABLE wikidata_entities ADD COLUMN IF NOT EXISTS statements  INT DEFAULT 0 AFTER sitelinks"); } catch (\Throwable) {}
    try { $pdo->exec("ALTER TABLE wikidata_entities ADD COLUMN IF NOT EXISTS externalIds INT DEFAULT 0 AFTER statements"); } catch (\Throwable) {}
    try { $pdo->exec("ALTER TABLE wikidata_entities ADD COLUMN IF NOT EXISTS dump_line   BIGINT DEFAULT 0 AFTER externalIds"); } catch (\Throwable) {}
    try { $pdo->exec("ALTER TABLE wikidata_entities ADD COLUMN IF NOT EXISTS dump_pos    BIGINT DEFAULT 0 AFTER dump_line"); } catch (\Throwable) {}
    try { $pdo->exec("ALTER TABLE wikidata_entities ADD INDEX IF NOT EXISTS idx_dump_line (dump_line)"); } catch (\Throwable) {}
    try { $pdo->exec("ALTER TABLE wikidata_search ADD COLUMN IF NOT EXISTS dump_position BIGINT DEFAULT 0 AFTER entity_type"); } catch (\Throwable) {}
    $pdo->exec("CREATE TABLE IF NOT EXISTS wikidata_geos (
        id          VARCHAR(20)  NOT NULL PRIMARY KEY,
        label       VARCHAR(500),
        latitude    DOUBLE       NULL,
        longitude   DOUBLE       NULL,
        precis      DOUBLE       NULL,
        globe       VARCHAR(100) NULL,
        source_prop VARCHAR(20)  NOT NULL DEFAULT 'P19',
        exported_at DATETIME DEFAULT NOW(),
        INDEX idx_geo_lat  (latitude),
        INDEX idx_geo_lon  (longitude)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS wikidata_occupations (
        id          VARCHAR(20)  NOT NULL PRIMARY KEY,
        label       VARCHAR(500),
        subclass_of TEXT         NULL,
        source_prop VARCHAR(20)  NOT NULL DEFAULT 'P106',
        exported_at DATETIME DEFAULT NOW()
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS wikidata_p279_graph (
        src_id  VARCHAR(20) NOT NULL,
        dst_id  VARCHAR(20) NOT NULL,
        PRIMARY KEY (src_id, dst_id),
        INDEX idx_p279g_dst (dst_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS wikidata_nodes (
        id          VARCHAR(20)  NOT NULL PRIMARY KEY,
        label       VARCHAR(500),
        dump_line   BIGINT       DEFAULT 0,
        dump_pos    BIGINT       DEFAULT 0,
        exported_at DATETIME     DEFAULT NOW()
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try { $pdo->exec("ALTER TABLE wikidata_scan_state ADD COLUMN IF NOT EXISTS frontier_json TEXT NULL"); } catch (\Throwable) {}
    $pdo->exec("CREATE TABLE IF NOT EXISTS wikidata_scan_state (
        scan_key      VARCHAR(50)  NOT NULL PRIMARY KEY,
        dump_position BIGINT       NOT NULL DEFAULT 0,
        total_target  INT          NOT NULL DEFAULT 0,
        found_so_far  INT          NOT NULL DEFAULT 0,
        updated_at    DATETIME     DEFAULT NOW()
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    return $pdo;
}

function dbAvailable(): bool {
    try { getDb(); return true; }
    catch (\Throwable) { return false; }
}

function upsertSearch(string $query, string $lang, string $p31, string $p279, string $etype): int {
    $pdo  = getDb();
    $stmt = $pdo->prepare("INSERT INTO wikidata_search (query, lang, p31, p279, entity_type, created_at)
        VALUES (:q,:l,:p31,:p279,:etype, NOW())
        ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), created_at=NOW()");
    $stmt->execute([':q'=>$query,':l'=>$lang,':p31'=>$p31,':p279'=>$p279,':etype'=>$etype]);
    return (int)$pdo->lastInsertId();
}

function saveSearchPosition(int $searchId, int $position): void {
    $pdo  = getDb();
    $stmt = $pdo->prepare("UPDATE wikidata_search SET dump_position=:pos WHERE id=:id");
    $stmt->execute([':pos' => $position, ':id' => $searchId]);
}

function getSearchPosition(int $searchId): int {
    $pdo  = getDb();
    $stmt = $pdo->prepare("SELECT dump_position FROM wikidata_search WHERE id=:id");
    $stmt->execute([':id' => $searchId]);
    return (int)($stmt->fetchColumn() ?: 0);
}

// Retourne [dump_line, dump_pos] de l'entité dont dump_line est le plus proche ≤ $targetLine
function nearestDumpPos(int $targetLine): array {
    try {
        $pdo  = getDb();
        $stmt = $pdo->prepare(
            "SELECT dump_line, dump_pos FROM wikidata_entities
             WHERE dump_line > 0 AND dump_pos > 0 AND dump_line <= :l
             ORDER BY dump_line DESC LIMIT 1");
        $stmt->execute([':l' => $targetLine]);
        $row = $stmt->fetch();
        return $row ? [(int)$row['dump_line'], (int)$row['dump_pos']] : [0, 0];
    } catch (\Throwable) { return [0, 0]; }
}

function resetSearchPosition(int $searchId): void {
    $pdo  = getDb();
    $stmt = $pdo->prepare("UPDATE wikidata_search SET dump_position=0 WHERE id=:id");
    $stmt->execute([':id' => $searchId]);
}

// ─── Scan state (reprise timeout pour scans génériques) ───────────────────────

function getScanState(string $key): array {
    try {
        $stmt = getDb()->prepare("SELECT * FROM wikidata_scan_state WHERE scan_key=:k");
        $stmt->execute([':k' => $key]);
        return $stmt->fetch() ?: ['dump_position' => 0, 'total_target' => 0, 'found_so_far' => 0];
    } catch (\Throwable) { return ['dump_position' => 0, 'total_target' => 0, 'found_so_far' => 0]; }
}

function saveScanState(string $key, int $position, int $total, int $found, string $frontierJson = ''): void {
    try {
        $pdo = getDb();
        $pdo->prepare("INSERT INTO wikidata_scan_state (scan_key, dump_position, total_target, found_so_far, frontier_json, updated_at)
            VALUES (:k,:pos,:tot,:found,:fj, NOW())
            ON DUPLICATE KEY UPDATE dump_position=:pos, total_target=:tot, found_so_far=:found, frontier_json=:fj, updated_at=NOW()")
            ->execute([':k'=>$key, ':pos'=>$position, ':tot'=>$total, ':found'=>$found, ':fj'=>$frontierJson]);
    } catch (\Throwable) {}
}

function clearScanState(string $key): void {
    try { getDb()->prepare("DELETE FROM wikidata_scan_state WHERE scan_key=:k")->execute([':k'=>$key]); }
    catch (\Throwable) {}
}

function exportToDb(array $rows, int $searchId): int {
    $pdo    = getDb();
    $stmtE  = $pdo->prepare("INSERT INTO wikidata_entities
        (id, type, label, description, p31, p279, wikipedia, sitelinks, statements, externalIds, dump_line, dump_pos, exported_at)
        VALUES (:id,:type,:label,:desc,:p31,:p279,:wikipedia,:sitelinks,:statements,:externalIds,:dump_line,:dump_pos, NOW())
        ON DUPLICATE KEY UPDATE
            sitelinks=VALUES(sitelinks), statements=VALUES(statements),
            externalIds=VALUES(externalIds), dump_line=VALUES(dump_line), dump_pos=VALUES(dump_pos), exported_at=NOW()");
    $stmtSE = $pdo->prepare("INSERT INTO wikidata_search_entities
        (search_id, entity_id, matched_in, created_at)
        VALUES (:sid,:eid,:min, NOW())
        ON DUPLICATE KEY UPDATE created_at=NOW()");
    $count = 0;
    foreach ($rows as $r) {
        $stmtE->execute([
            ':id'          => $r['id'],   ':type'        => $r['type'],
            ':label'       => $r['label'],':desc'        => $r['desc'],
            ':p31'         => implode('|', $r['p31']),
            ':p279'        => implode('|', $r['p279']),
            ':wikipedia'   => $r['wikipedia'],
            ':sitelinks'   => $r['sitelinks']   ?? 0,
            ':statements'  => $r['statements']  ?? 0,
            ':externalIds' => $r['externalIds'] ?? 0,
            ':dump_line'   => $r['dump_line']   ?? 0,
            ':dump_pos'    => $r['dump_pos']    ?? 0,
        ]);
        $stmtSE->execute([':sid'=>$searchId, ':eid'=>$r['id'], ':min'=>$r['matchedIn']]);
        $count++;
    }
    return $count;
}

function getDbRows(int $searchId = 0): array {
    $pdo = getDb();
    if ($searchId > 0) {
        $stmt = $pdo->prepare(
            "SELECT we.*, se.matched_in, se.created_at AS linked_at
             FROM wikidata_entities we
             JOIN wikidata_search_entities se ON se.entity_id = we.id
             WHERE se.search_id = :sid
             ORDER BY we.exported_at DESC LIMIT 1000");
        $stmt->execute([':sid' => $searchId]);
    } else {
        $stmt = $pdo->query(
            "SELECT we.*, se.matched_in, se.created_at AS linked_at
             FROM wikidata_entities we
             LEFT JOIN wikidata_search_entities se ON se.entity_id = we.id
             ORDER BY we.exported_at DESC LIMIT 1000");
    }
    return $stmt->fetchAll();
}

function countDbRows(): int {
    try { return (int)getDb()->query("SELECT COUNT(*) FROM wikidata_entities")->fetchColumn(); }
    catch (\Throwable) { return 0; }
}

function deleteDbRow(string $id): void {
    $pdo = getDb();
    $pdo->prepare("DELETE FROM wikidata_search_entities WHERE entity_id=:id")->execute([':id'=>$id]);
    $pdo->prepare("DELETE FROM wikidata_entities WHERE id=:id")->execute([':id'=>$id]);
}

function clearDb(): void {
    $pdo = getDb();
    $pdo->exec("DELETE FROM wikidata_search_entities");
    $pdo->exec("DELETE FROM wikidata_entities");
    $pdo->exec("DELETE FROM wikidata_search");
}

// ─── wikidata_properties helpers ─────────────────────────────────────────────

function getDistinctSearchQueries(): array {
    try {
        return getDb()->query(
            "SELECT ws.id, ws.query, ws.lang, ws.p31, ws.p279, ws.entity_type,
                    ws.created_at, COUNT(se.entity_id) AS cnt
             FROM wikidata_search ws
             LEFT JOIN wikidata_search_entities se ON se.search_id = ws.id
             GROUP BY ws.id ORDER BY ws.created_at DESC"
        )->fetchAll();
    } catch (\Throwable) { return []; }
}

function getEntityIdsBySearchId(int $searchId): array {
    $stmt = getDb()->prepare(
        "SELECT entity_id FROM wikidata_search_entities WHERE search_id = :sid"
    );
    $stmt->execute([':sid' => $searchId]);
    return array_column($stmt->fetchAll(), 'entity_id');
}

function extractPropertyValues(array $entity, string $prop): array {
    $rows = [];
    foreach ($entity['claims'][$prop] ?? [] as $snak) {
        $ms   = $snak['mainsnak'] ?? [];
        $rank = $snak['rank'] ?? 'normal';
        if (($ms['snaktype'] ?? '') !== 'value') continue;
        $dv   = $ms['datavalue'] ?? [];
        $type = $dv['type'] ?? '';
        $val  = $dv['value'] ?? null;

        $valueId   = null;
        $valueStr  = null;
        $valueLang = null;

        switch ($type) {
            case 'wikibase-entityid':
                $valueId = $val['id'] ?? null;
                break;
            case 'string':
            case 'url':
                $valueStr = is_string($val) ? $val : null;
                break;
            case 'monolingualtext':
                $valueStr  = $val['text']     ?? null;
                $valueLang = $val['language']  ?? null;
                break;
            case 'time':
                $valueStr = $val['time'] ?? null;
                break;
            case 'quantity':
                $valueStr = $val['amount'] ?? null;
                break;
            case 'globecoordinate':
                $valueStr = ($val['latitude'] ?? '') . ',' . ($val['longitude'] ?? '');
                break;
            default:
                $valueStr = is_string($val) ? $val : json_encode($val);
        }

        $rows[] = [
            'entity_id'  => $entity['id'],
            'property'   => $prop,
            'value_type' => $type,
            'value_id'   => $valueId,
            'value_str'  => $valueStr,
            'value_lang' => $valueLang,
            'rank'       => $rank,
        ];
    }
    return $rows;
}

function insertProperties(array $rows): int {
    if (empty($rows)) return 0;
    $pdo  = getDb();
    $stmt = $pdo->prepare("INSERT INTO wikidata_properties
        (entity_id, property, value_type, value_id, value_str, value_lang, rank, created_at)
        VALUES (:entity_id,:property,:value_type,:value_id,:value_str,:value_lang,:rank, NOW())
        ON DUPLICATE KEY UPDATE
            value_type=VALUES(value_type), value_lang=VALUES(value_lang),
            rank=VALUES(rank), created_at=NOW()");
    $count = 0;
    foreach ($rows as $r) {
        $stmt->execute([
            ':entity_id'  => $r['entity_id'],
            ':property'   => $r['property'],
            ':value_type' => $r['value_type'],
            ':value_id'   => $r['value_id'],
            ':value_str'  => mb_strimwidth($r['value_str'] ?? '', 0, 500),
            ':value_lang' => $r['value_lang'],
            ':rank'       => $r['rank'],
        ]);
        $count++;
    }
    return $count;
}

function scanDumpForProperties(array $targetIds, string $prop): array {
    if (empty($targetIds)) return ['inserted' => 0, 'checked' => 0, 'found' => 0, 'timedOut' => false];
    $lookup   = array_flip($targetIds);
    $factory  = new JsonDumpFactory();
    $reader   = $factory->newGzDumpReader(DUMP_FILE);
    $found    = 0;
    $inserted = 0;
    $checked  = 0;
    $batch    = [];
    $deadline = (int)$_SERVER['REQUEST_TIME'] + TIME_LIMIT - 2;

    while (true) {
        if (time() >= $deadline) break;
        if ($found >= count($targetIds)) break;

        try { $line = $reader->nextJsonLine(); }
        catch (\Wikibase\JsonDumpReader\DumpReadingException) { break; }
        if ($line === null) break;

        $checked++;
        $entity = json_decode($line, true);
        if (!is_array($entity)) continue;
        $eid = $entity['id'] ?? '';
        if (!isset($lookup[$eid])) continue;

        $found++;
        foreach (extractPropertyValues($entity, $prop) as $r) $batch[] = $r;

        if (count($batch) >= 500) {
            $inserted += insertProperties($batch);
            $batch = [];
        }
    }
    if (!empty($batch)) $inserted += insertProperties($batch);

    return ['inserted' => $inserted, 'checked' => $checked, 'found' => $found,
            'timedOut' => (time() >= $deadline)];
}

function getPropertyRows(int $searchId = 0, string $prop = ''): array {
    $pdo    = getDb();
    $cond   = [];
    $params = [];
    if ($searchId > 0) {
        $cond[]         = 'se.search_id = :sid';
        $params[':sid'] = $searchId;
    }
    if ($prop !== '') {
        $cond[]          = 'wp.property = :prop';
        $params[':prop'] = $prop;
    }
    $join  = $searchId > 0
        ? 'JOIN wikidata_search_entities se ON se.entity_id = wp.entity_id'
        : 'LEFT JOIN wikidata_search_entities se ON se.entity_id = wp.entity_id';
    $where = $cond ? 'WHERE ' . implode(' AND ', $cond) : '';
    $stmt  = $pdo->prepare(
        "SELECT wp.*, we.label, we.type AS entity_type
         FROM wikidata_properties wp
         LEFT JOIN wikidata_entities we ON we.id = wp.entity_id
         $join
         $where
         GROUP BY wp.id
         ORDER BY wp.entity_id, wp.property LIMIT 2000");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function countPropertyRows(): int {
    try { return (int)getDb()->query("SELECT COUNT(*) FROM wikidata_properties")->fetchColumn(); }
    catch (\Throwable) { return 0; }
}

function clearProperties(): void {
    getDb()->exec("DELETE FROM wikidata_properties");
}

function deletePropertyRows(string $entityId, string $prop): void {
    $stmt = getDb()->prepare("DELETE FROM wikidata_properties WHERE entity_id=:e AND property=:p");
    $stmt->execute([':e' => $entityId, ':p' => $prop]);
}

// ─── Géos ────────────────────────────────────────────────────────────────────

function getGeoTargetIds(string $sourceProp = 'P19'): array {
    try {
        $stmt = getDb()->prepare(
            "SELECT DISTINCT value_id FROM wikidata_properties
             WHERE property = :p AND value_id IS NOT NULL AND value_id != ''");
        $stmt->execute([':p' => $sourceProp]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Throwable) { return []; }
}

function insertGeos(array $rows): int {
    if (empty($rows)) return 0;
    $pdo  = getDb();
    $stmt = $pdo->prepare("INSERT INTO wikidata_geos
        (id, label, latitude, longitude, precis, globe, source_prop, exported_at)
        VALUES (:id,:label,:lat,:lon,:prec,:globe,:src, NOW())
        ON DUPLICATE KEY UPDATE
            label=VALUES(label), latitude=VALUES(latitude), longitude=VALUES(longitude),
            precis=VALUES(precis), globe=VALUES(globe), exported_at=NOW()");
    $count = 0;
    foreach ($rows as $r) {
        $stmt->execute([
            ':id'    => $r['id'],    ':label' => $r['label'],
            ':lat'   => $r['lat'],   ':lon'   => $r['lon'],
            ':prec'  => $r['prec'],  ':globe' => $r['globe'],
            ':src'   => $r['source_prop'],
        ]);
        $count++;
    }
    return $count;
}

function scanDumpForGeos(array $targetIds, string $sourceProp = 'P19', bool $resume = false): array {
    if (empty($targetIds)) return ['inserted' => 0, 'checked' => 0, 'found' => 0, 'timedOut' => false, 'resumePos' => 0];

    $stateKey = 'geo_' . $sourceProp;
    $factory  = new JsonDumpFactory();
    $reader   = $factory->newGzDumpReader(DUMP_FILE);

    // Reprise : seek à la position sauvée et restaurer le compteur found
    $foundOffset = 0;
    if ($resume) {
        $state = getScanState($stateKey);
        $resumePos = (int)$state['dump_position'];
        if ($resumePos > 0) {
            $reader->seekToPosition($resumePos);
            $foundOffset = (int)$state['found_so_far'];
        }
    }

    // Exclure les entités déjà importées en mode reprise
    if ($resume && $foundOffset > 0) {
        try {
            $stmt = getDb()->prepare("SELECT id FROM wikidata_geos WHERE source_prop=:s");
            $stmt->execute([':s' => $sourceProp]);
            $done = $stmt->fetchAll(PDO::FETCH_COLUMN);
            foreach ($done as $id) unset($targetIds[array_search($id, $targetIds)]);
            $targetIds = array_values($targetIds);
        } catch (\Throwable) {}
    }

    $lookup  = array_flip($targetIds);
    $total    = count($targetIds) + $foundOffset;
    $found    = $foundOffset;
    $inserted = 0; $checked = 0;
    $batch    = [];
    $deadline = (int)$_SERVER['REQUEST_TIME'] + TIME_LIMIT - 2;
    $timedOut = false;

    while (true) {
        if (time() >= $deadline) { $timedOut = true; break; }
        if ($found - $foundOffset >= count($lookup)) break;

        try { $line = $reader->nextJsonLine(); }
        catch (\Wikibase\JsonDumpReader\DumpReadingException) { break; }
        if ($line === null) break;

        $checked++;
        $entity = json_decode($line, true);
        if (!is_array($entity)) continue;
        $eid = $entity['id'] ?? '';
        if (!isset($lookup[$eid])) continue;
        $found++;

        // Label (fr puis en puis premier disponible)
        $labels = $entity['labels'] ?? [];
        $label  = $labels['fr']['value'] ?? $labels['en']['value']
               ?? (array_values($labels)[0]['value'] ?? '');

        // Coordonnées P625
        $lat = $lon = $prec = $globe = null;
        foreach ($entity['claims']['P625'] ?? [] as $st) {
            $dv = $st['mainsnak']['datavalue']['value'] ?? null;
            if (is_array($dv) && isset($dv['latitude'])) {
                $lat   = (float)$dv['latitude'];
                $lon   = (float)$dv['longitude'];
                $prec  = isset($dv['precision']) ? (float)$dv['precision'] : null;
                $globe = $dv['globe'] ?? null;
                if ($globe) $globe = preg_replace('#.*/entity/#', '', $globe);
                break;
            }
        }

        $batch[] = [
            'id'          => $eid,
            'label'       => $label,
            'lat'         => $lat,
            'lon'         => $lon,
            'prec'        => $prec,
            'globe'       => $globe,
            'source_prop' => $sourceProp,
        ];
        if (count($batch) >= 500) {
            $inserted += insertGeos($batch);
            $batch = [];
        }
    }
    if (!empty($batch)) $inserted += insertGeos($batch);

    // Gestion de l'état de reprise
    $finalPos = 0;
    if ($timedOut) {
        try { $finalPos = $reader->getPosition(); } catch (\Throwable) {}
        saveScanState($stateKey, $finalPos, $total, $found);
    } else {
        clearScanState($stateKey);
    }

    return ['inserted' => $inserted, 'checked' => $checked, 'found' => $found,
            'total' => $total, 'timedOut' => $timedOut, 'resumePos' => $finalPos];
}

function getGeoRows(string $sourceProp = ''): array {
    $pdo    = getDb();
    $where  = $sourceProp !== '' ? 'WHERE source_prop = ?' : '';
    $params = $sourceProp !== '' ? [$sourceProp] : [];
    $stmt   = $pdo->prepare("SELECT * FROM wikidata_geos $where ORDER BY id LIMIT 2000");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function countGeoRows(): int {
    try { return (int)getDb()->query("SELECT COUNT(*) FROM wikidata_geos")->fetchColumn(); }
    catch (\Throwable) { return 0; }
}

// ─── Occupations ─────────────────────────────────────────────────────────────

function getOccupationTargetIds(string $sourceProp = 'P106'): array {
    try {
        $stmt = getDb()->prepare(
            "SELECT DISTINCT value_id FROM wikidata_properties
             WHERE property = :p AND value_id IS NOT NULL AND value_id != ''");
        $stmt->execute([':p' => $sourceProp]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Throwable) { return []; }
}

function insertOccupations(array $rows): int {
    if (empty($rows)) return 0;
    $pdo  = getDb();
    $stmt = $pdo->prepare("INSERT INTO wikidata_occupations
        (id, label, subclass_of, source_prop, exported_at)
        VALUES (:id,:label,:subclass_of,:src, NOW())
        ON DUPLICATE KEY UPDATE
            label=VALUES(label), subclass_of=VALUES(subclass_of), exported_at=NOW()");
    $count = 0;
    foreach ($rows as $r) {
        $stmt->execute([
            ':id'          => $r['id'],
            ':label'       => $r['label'],
            ':subclass_of' => $r['subclass_of'],
            ':src'         => $r['source_prop'],
        ]);
        $count++;
    }
    return $count;
}

function scanDumpForOccupations(array $targetIds, string $sourceProp = 'P106', bool $resume = false): array {
    if (empty($targetIds)) return ['inserted' => 0, 'checked' => 0, 'found' => 0, 'total' => 0, 'timedOut' => false, 'resumePos' => 0];

    $stateKey = 'occ_' . $sourceProp;
    $factory  = new JsonDumpFactory();
    $reader   = $factory->newGzDumpReader(DUMP_FILE);

    $foundOffset = 0;
    if ($resume) {
        $state = getScanState($stateKey);
        $resumePos = (int)$state['dump_position'];
        if ($resumePos > 0) {
            $reader->seekToPosition($resumePos);
            $foundOffset = (int)$state['found_so_far'];
        }
    }

    if ($resume && $foundOffset > 0) {
        try {
            $stmt = getDb()->prepare("SELECT id FROM wikidata_occupations WHERE source_prop=:s");
            $stmt->execute([':s' => $sourceProp]);
            $done = $stmt->fetchAll(PDO::FETCH_COLUMN);
            foreach ($done as $id) {
                $key = array_search($id, $targetIds);
                if ($key !== false) unset($targetIds[$key]);
            }
            $targetIds = array_values($targetIds);
        } catch (\Throwable) {}
    }

    $lookup    = array_flip($targetIds);
    $total     = count($targetIds) + $foundOffset;
    $found     = $foundOffset;
    $inserted  = 0; $checked = 0;
    $batch     = [];
    $deadline  = (int)$_SERVER['REQUEST_TIME'] + TIME_LIMIT - 2; // 2 s de marge pour flush+save
    $timedOut  = false;

    while (true) {
        if (time() >= $deadline) { $timedOut = true; break; }
        if ($found - $foundOffset >= count($lookup)) break;

        try { $line = $reader->nextJsonLine(); }
        catch (\Wikibase\JsonDumpReader\DumpReadingException) { break; }
        if ($line === null) break;

        $checked++;
        $entity = json_decode($line, true);
        if (!is_array($entity)) continue;
        $eid = $entity['id'] ?? '';
        if (!isset($lookup[$eid])) continue;
        $found++;

        $labels = $entity['labels'] ?? [];
        $label  = $labels['fr']['value'] ?? $labels['en']['value']
               ?? (array_values($labels)[0]['value'] ?? '');

        // P279 = subclass of (valeurs multiples séparées par |)
        $p279 = [];
        foreach ($entity['claims']['P279'] ?? [] as $st) {
            $vid = $st['mainsnak']['datavalue']['value']['id'] ?? null;
            if ($vid) $p279[] = $vid;
        }

        $batch[] = [
            'id'          => $eid,
            'label'       => $label,
            'subclass_of' => implode('|', $p279),
            'source_prop' => $sourceProp,
        ];
        if (count($batch) >= 500) {
            $inserted += insertOccupations($batch);
            $batch = [];
        }
    }
    if (!empty($batch)) $inserted += insertOccupations($batch);

    $finalPos = 0;
    if ($timedOut) {
        try { $finalPos = $reader->getPosition(); } catch (\Throwable) {}
        saveScanState($stateKey, $finalPos, $total, $found);
    } else {
        clearScanState($stateKey);
    }

    return ['inserted' => $inserted, 'checked' => $checked, 'found' => $found,
            'total' => $total, 'timedOut' => $timedOut, 'resumePos' => $finalPos];
}

function getOccupationRows(string $sourceProp = ''): array {
    $pdo    = getDb();
    $where  = $sourceProp !== '' ? 'WHERE source_prop = ?' : '';
    $params = $sourceProp !== '' ? [$sourceProp] : [];
    $stmt   = $pdo->prepare("SELECT * FROM wikidata_occupations $where ORDER BY label LIMIT 2000");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function countOccupationRows(): int {
    try { return (int)getDb()->query("SELECT COUNT(*) FROM wikidata_occupations")->fetchColumn(); }
    catch (\Throwable) { return 0; }
}

// ─── Réseau P279 ─────────────────────────────────────────────────────────────

function countP279GraphRows(): int {
    try { return (int)getDb()->query("SELECT COUNT(*) FROM wikidata_p279_graph")->fetchColumn(); }
    catch (\Throwable) { return 0; }
}

function countNetworkNodes(): int {
    try { return (int)getDb()->query("SELECT COUNT(*) FROM wikidata_nodes")->fetchColumn(); }
    catch (\Throwable) { return 0; }
}

function insertNodes(array $rows): int {
    if (empty($rows)) return 0;
    $pdo  = getDb();
    $stmt = $pdo->prepare("INSERT INTO wikidata_nodes (id, label, dump_line, dump_pos, exported_at)
        VALUES (:id,:label,:dl,:dp, NOW())
        ON DUPLICATE KEY UPDATE label=VALUES(label), dump_line=VALUES(dump_line), dump_pos=VALUES(dump_pos), exported_at=NOW()");
    $count = 0;
    foreach ($rows as $r) {
        $stmt->execute([':id'=>$r['id'],':label'=>$r['label'],':dl'=>$r['dump_line'],':dp'=>$r['dump_pos']]);
        $count++;
    }
    return $count;
}

// BFS niveau par niveau dans le dump.
// Chaque appel reprend là où il s'est arrêté (frontier + position).
// Un appel = autant de niveaux que le timeout le permet.
function exploreP279Network(string $startId, bool $resume = false): array {
    $stateKey = 'net_' . $startId;
    $pdo      = getDb();

    if ($resume) {
        $state       = getScanState($stateKey);
        $frontier    = json_decode($state['frontier_json'] ?? '[]', true) ?: [];
        $resumePos   = (int)$state['dump_position'];
        $totalNodes  = (int)$state['total_target'];
        $totalLinks  = (int)$state['found_so_far'];
    } else {
        // Nouveau départ : vider les tables et initialiser
        $pdo->exec("DELETE FROM wikidata_p279_graph");
        $pdo->exec("DELETE FROM wikidata_nodes");
        clearScanState($stateKey);
        // Insérer le nœud de départ (label provisoire = id)
        insertNodes([['id'=>$startId,'label'=>$startId,'dump_line'=>0,'dump_pos'=>0]]);
        $frontier   = [$startId];
        $resumePos  = 0;
        $totalNodes = 1;
        $totalLinks = 0;
    }

    // Charger les entités déjà visitées depuis la table
    $visited = [];
    try {
        $stmt = $pdo->query("SELECT id FROM wikidata_nodes");
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) $visited[$id] = true;
    } catch (\Throwable) {}

    $factory         = new JsonDumpFactory();
    $deadline        = (int)$_SERVER['REQUEST_TIME'] + TIME_LIMIT - 5;
    $timedOut        = false;
    $levelsCompleted = 0;
    $stmtLink        = $pdo->prepare("INSERT IGNORE INTO wikidata_p279_graph (src_id, dst_id) VALUES (:s,:d)");

    while (!empty($frontier) && time() < $deadline) {
        $reader      = $factory->newGzDumpReader(DUMP_FILE);
        if ($resumePos > 0) $reader->seekToPosition($resumePos);
        $frontierSet = array_flip($frontier);
        $newNodes    = [];
        $newLinks    = [];
        $checked     = 0;
        $levelDone   = false;

        while (true) {
            if (time() >= $deadline) { $timedOut = true; break; }

            try { $jsonLine = $reader->nextJsonLine(); }
            catch (\Wikibase\JsonDumpReader\DumpReadingException) { $levelDone = true; break; }
            if ($jsonLine === null) { $levelDone = true; break; }

            $checked++;
            $entity = json_decode($jsonLine, true);
            if (!is_array($entity)) continue;
            $eid = $entity['id'] ?? '';
            if ($eid === '') continue;

            // Mettre à jour le label du nœud de départ si on le rencontre
            if ($eid === $startId && isset($visited[$startId])) {
                $labels = $entity['labels'] ?? [];
                $lbl    = $labels['fr']['value'] ?? $labels['en']['value']
                       ?? (array_values($labels)[0]['value'] ?? $startId);
                try { $pos = $reader->getPosition(); } catch (\Throwable) { $pos = 0; }
                insertNodes([['id'=>$startId,'label'=>$lbl,'dump_line'=>$checked,'dump_pos'=>$pos]]);
            }

            if (isset($visited[$eid])) continue;

            // Chercher si une valeur P279 de cette entité est dans la frontière courante
            $matched = [];
            foreach ($entity['claims']['P279'] ?? [] as $st) {
                $vid = $st['mainsnak']['datavalue']['value']['id'] ?? null;
                if ($vid !== null && isset($frontierSet[$vid])) $matched[] = $vid;
            }
            if (empty($matched)) continue;

            $visited[$eid] = true;
            $labels = $entity['labels'] ?? [];
            $label  = $labels['fr']['value'] ?? $labels['en']['value']
                   ?? (array_values($labels)[0]['value'] ?? '');
            try { $curPos = $reader->getPosition(); } catch (\Throwable) { $curPos = 0; }

            $newNodes[] = ['id'=>$eid,'label'=>$label,'dump_line'=>$checked,'dump_pos'=>$curPos];
            foreach ($matched as $dst) $newLinks[] = [':s'=>$eid,':d'=>$dst];
        }

        // Flush nœuds et liens
        if (!empty($newNodes)) {
            $totalNodes += insertNodes($newNodes);
        }
        if (!empty($newLinks)) {
            $pdo->beginTransaction();
            foreach ($newLinks as $l) $stmtLink->execute($l);
            $pdo->commit();
            $totalLinks += count($newLinks);
        }

        if ($timedOut) {
            try { $resumePos = $reader->getPosition(); } catch (\Throwable) { $resumePos = 0; }
            // Conserver la frontière actuelle (niveau pas encore terminé)
            break;
        }

        // Niveau terminé : la nouvelle frontière = entités découvertes à ce niveau
        $levelsCompleted++;
        $frontier  = array_column($newNodes, 'id');
        $resumePos = 0; // Repart du début du dump pour le prochain niveau
    }

    $done = !$timedOut && empty($frontier);
    if (!$done) {
        saveScanState($stateKey, $resumePos, $totalNodes, $totalLinks, json_encode($frontier));
    } else {
        clearScanState($stateKey);
    }

    return [
        'nodesAdded'      => $totalNodes,
        'linksAdded'      => $totalLinks,
        'levelsCompleted' => $levelsCompleted,
        'frontierSize'    => count($frontier),
        'timedOut'        => $timedOut,
        'done'            => $done,
    ];
}

// Retourne le réseau stocké en DB au format nodes/links
function getP279NetworkFromDb(): array {
    $pdo = getDb();
    try {
        $nodes = $pdo->query("SELECT id, label FROM wikidata_nodes ORDER BY id")->fetchAll();
        $links = $pdo->query("SELECT src_id AS idSrc, dst_id AS idDst FROM wikidata_p279_graph")->fetchAll();
    } catch (\Throwable) { $nodes = []; $links = []; }
    return ['nodes' => $nodes, 'links' => $links];
}

// ─── CSV export ───────────────────────────────────────────────────────────────

function outputCsv(array $rows, string $filename): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
    fputcsv($out, ['id','type','label','description','matched_in','p31','p279']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'], $r['type'], $r['label'], $r['desc'],
            $r['matchedIn'],
            implode('|', $r['p31']),
            implode('|', $r['p279']),
        ]);
    }
    fclose($out);
    exit;
}

function outputDbCsv(array $rows, string $filename): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    if (!empty($rows)) fputcsv($out, array_keys($rows[0]));
    foreach ($rows as $r) fputcsv($out, $r);
    fclose($out);
    exit;
}

// ─── Search ───────────────────────────────────────────────────────────────────

function matchesClaims(array $entity, string $p31, string $p279): bool {
    if ($p31 === '' && $p279 === '') return true;
    $claims = $entity['claims'] ?? [];
    if ($p31 !== '') {
        $ok = false;
        foreach ($claims['P31'] ?? [] as $s) {
            if (strcasecmp($s['mainsnak']['datavalue']['value']['id'] ?? '', $p31) === 0) { $ok = true; break; }
        }
        if (!$ok) return false;
    }
    if ($p279 !== '') {
        $ok = false;
        foreach ($claims['P279'] ?? [] as $s) {
            if (strcasecmp($s['mainsnak']['datavalue']['value']['id'] ?? '', $p279) === 0) { $ok = true; break; }
        }
        if (!$ok) return false;
    }
    return true;
}

function extractClaimIds(array $entity, string $prop): array {
    $ids = [];
    foreach ($entity['claims'][$prop] ?? [] as $s) {
        $id = $s['mainsnak']['datavalue']['value']['id'] ?? null;
        if ($id) $ids[] = $id;
    }
    return $ids;
}

function extractWikiPage(array $entity): string {
    //vérifie les pages wiki préférentielles
    foreach (PREF_LANG as $l) {
      if(isset($entity['sitelinks'][$l."wiki"])) return $l."_".$entity['sitelinks'][$l."wiki"]["title"];
    }
    //renvoie le premier site wikipedia
    foreach ($entity['sitelinks'] ?? [] as $k => $v) {
        if (!str_ends_with($k, 'wiki')) continue;
        return substr($k,0,2)."_".$entity['sitelinks'][$k]["title"];
    }
    return "";
}

function buildRow(array $entity, string $lang, string $matchedIn, int $dumpLine = 0, int $dumpPos = 0): array {
    $dl    = $lang === 'all' ? 'fr' : $lang;
    $label = $entity['labels'][$dl]['value']
        ?? $entity['labels']['en']['value']
        ?? (array_values($entity['labels'] ?? [])[0]['value'] ?? '—');
    $desc  = $entity['descriptions'][$dl]['value']
        ?? $entity['descriptions']['en']['value']
        ?? (array_values($entity['descriptions'] ?? [])[0]['value'] ?? '');
    $claims     = $entity['claims'] ?? [];
    $statements = array_sum(array_map('count', $claims));
    $externalIds = 0;
    foreach ($claims as $snaks) {
        if (($snaks[0]['mainsnak']['datatype'] ?? '') === 'external-id') $externalIds++;
    }
    return [
        'id'          => $entity['id'] ?? '?',
        'type'        => $entity['type'] ?? '?',
        'label'       => $label,
        'desc'        => $desc,
        'matchedIn'   => $matchedIn,
        'p31'         => extractClaimIds($entity, 'P31'),
        'p279'        => extractClaimIds($entity, 'P279'),
        'wikipedia'   => extractWikiPage($entity),
        'sitelinks'   => count($entity['sitelinks'] ?? []),
        'statements'  => $statements,
        'externalIds' => $externalIds,
        'dump_line'   => $dumpLine,
        'dump_pos'    => $dumpPos,
    ];
}

function matchEntity(array $entity, string $queryLc, bool $textMode,
                     string $p31, string $p279, string $idFilter = ''): string|false
{
    // filtre sur l'identifiant exact ou préfixe (ex: Q42 ou Q42,Q43)
    if ($idFilter !== '') {
        $eid  = $entity['id'] ?? '';
        $ids  = array_map('trim', explode(',', strtoupper($idFilter)));
        $matched = false;
        foreach ($ids as $candidate) {
            if ($candidate !== '' && strcasecmp($eid, $candidate) === 0) { $matched = true; break; }
        }
        if (!$matched) return false;
        // si pas d'autre critère, retourner directement
        if (!$textMode && $p31 === '' && $p279 === '') return "id={$eid}";
    }

    if (!matchesClaims($entity, $p31, $p279)) return false;

    if (!$textMode)
        return trim(($p31 !== '' ? "P31={$p31} " : '') . ($p279 !== '' ? "P279={$p279}" : ''));

    $labels = $entity['labels'] ?? [];
    $check  = $GLOBALS['_lang'] === 'all' ? $labels : array_filter([$GLOBALS['_lang'] => $labels[$GLOBALS['_lang']] ?? null]);
    foreach ($check as $lc => $d) {
        if (str_contains(mb_strtolower($d['value'] ?? ''), $queryLc)) return "label[$lc]";
    }
    $descs = $entity['descriptions'] ?? [];
    $check = $GLOBALS['_lang'] === 'all' ? $descs : array_filter([$GLOBALS['_lang'] => $descs[$GLOBALS['_lang']] ?? null]);
    foreach ($check as $lc => $d) {
        if (str_contains(mb_strtolower($d['value'] ?? ''), $queryLc)) return "description[$lc]";
    }
    $aliases = $entity['aliases'] ?? [];
    $check   = $GLOBALS['_lang'] === 'all' ? $aliases : array_filter([$GLOBALS['_lang'] => $aliases[$GLOBALS['_lang']] ?? null]);
    foreach ($check as $lc => $list) {
        foreach ((array)$list as $a) {
            if (str_contains(mb_strtolower($a['value'] ?? ''), $queryLc)) return "alias[$lc]";
        }
    }
    return false;
}

/**
 * $directExport = true  → flush each batch directly to DB, keep no results in memory
 * $directExport = false → collect results in array and return them
 */
function searchDump(string $query, string $lang, string $entityType,
                    string $p31, string $p279, string $idFilter = '',
                    int $limit = MAX_RESULTS,
                    bool $directExport = false, int $searchId = 0,
                    int $resumePos = 0,
                    int $lineFrom = 0, int $lineTo = 0): array
{
    $GLOBALS['_lang'] = $lang;

    $factory  = new JsonDumpFactory();
    $reader   = $factory->newGzDumpReader(DUMP_FILE);

    // Priorité : resumePos (reprise timeout) > seek depuis lineFrom (si DB connue)
    $lineOffset = 0; // numéro de ligne absolu au point de départ du seek
    if ($resumePos > 0) {
        $reader->seekToPosition($resumePos);
    } elseif ($lineFrom > 0) {
        [$nearLine, $nearPos] = nearestDumpPos($lineFrom);
        if ($nearPos > 0) {
            $reader->seekToPosition($nearPos);
            $lineOffset = $nearLine - 1; // checked s'incrémente avant la comparaison
        }
    }

    $results   = [];
    $checked   = $lineOffset;
    $exported  = 0;
    $queryLc   = mb_strtolower($query);
    $textMode  = $query !== '';
    $deadline  = (int)$_SERVER['REQUEST_TIME'] + TIME_LIMIT - 2;
    $batch     = [];
    $batchSize = 500;
    $timedOut  = false;

    while (true) {
        if (time() >= $deadline) { $timedOut = true; break; }
        if (!$directExport && count($results) >= $limit) break;
        if ($directExport && ($checked - $exported - $lineOffset) >= $limit && $limit !== NO_LIMIT) break;

        try { $line = $reader->nextJsonLine(); }
        catch (\Wikibase\JsonDumpReader\DumpReadingException) { break; }
        if ($line === null) break;

        $checked++;

        // Filtre plage de lignes
        if ($lineFrom > 0 && $checked < $lineFrom) continue;
        if ($lineTo   > 0 && $checked > $lineTo)   break;

        $entity = json_decode($line, true);
        if (!is_array($entity)) continue;
        if ($entityType !== 'all' && ($entity['type'] ?? '') !== $entityType) continue;

        $matchedIn = matchEntity($entity, $queryLc, $textMode, $p31, $p279, $idFilter);
        if ($matchedIn === false) continue;

        try { $curPos = $reader->getPosition(); } catch (\Throwable) { $curPos = 0; }
        $row = buildRow($entity, $lang, $matchedIn, $checked, $curPos);

        if ($directExport) {
            $batch[] = $row;
            if (count($batch) >= $batchSize) {
                $exported += exportToDb($batch, $searchId);
                $batch = [];
            }
        } else {
            $results[] = $row;
        }
        if ($idFilter) break;
    }

    // flush remaining batch
    if ($directExport && !empty($batch)) {
        $exported += exportToDb($batch, $searchId);
    }

    // Sauvegarder la position courante si timeout et searchId connu
    $finalPos = 0;
    if ($timedOut && $searchId > 0) {
        try { $finalPos = $reader->getPosition(); } catch (\Throwable) {}
        if ($finalPos > 0) saveSearchPosition($searchId, $finalPos);
    } elseif (!$timedOut && $searchId > 0) {
        // Scan terminé : remettre la position à 0
        resetSearchPosition($searchId);
    }

    return [
        'results'   => $results,
        'checked'   => $checked,
        'exported'  => $exported,
        'timedOut'  => $timedOut,
        'resumePos' => $finalPos,
        'searchId'  => $searchId,
    ];
}

// ─── Request handling ─────────────────────────────────────────────────────────
$query      = trim($_GET['q'] ?? '');
$lang       = preg_replace('/[^a-z\-]/', '', $_GET['lang'] ?? 'fr');
$entityType = in_array($_GET['etype'] ?? '', ['item','property','all']) ? $_GET['etype'] : 'all';
$p31Filter  = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $_GET['p31']  ?? ''));
$p279Filter = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $_GET['p279'] ?? ''));
$idFilter   = strtoupper(preg_replace('/[^a-zA-Z0-9,]/', '', $_GET['eid'] ?? ''));
$noLimit      = isset($_GET['nolimit'])  && $_GET['nolimit']  === '1';
$directExport = isset($_GET['directdb']) && $_GET['directdb'] === '1';
$resumeSid    = (int)($_GET['sid']        ?? 0);  // search_id pour la reprise
$lineFrom     = max(0, (int)($_GET['line_from'] ?? 0));
$lineTo       = max(0, (int)($_GET['line_to']   ?? 0));
$searchLimit  = $noLimit ? NO_LIMIT : MAX_RESULTS;
$searchTag    = $query ?: trim(
    ($idFilter   ? "id={$idFilter} "         : '') .
    ($lineFrom   ? "ligne≥{$lineFrom} "      : '') .
    ($lineTo     ? "ligne≤{$lineTo} "        : '') .
    ($p31Filter  ? "P31={$p31Filter} "       : '') .
    ($p279Filter ? "P279={$p279Filter}"      : '')
);
$tab        = in_array($_GET['tab'] ?? '', ['search','db','props','geos','occs','net']) ? $_GET['tab'] : 'search';
$action     = $_POST['action'] ?? $_GET['action'] ?? '';
$dbSearchId = (int)($_GET['dsid'] ?? 0); // filtre onglet entités
// onglet propriétés
$propSid    = (int)($_GET['psid'] ?? 0); // search_id pour le filtre
$propId     = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $_GET['pid'] ?? ''));
$propFilter = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $_GET['pf']  ?? ''));
// onglet géos
$geoSrcProp   = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $_GET['geo_src'] ?? 'P19'));
$geoResume    = isset($_GET['geo_resume']) && $_GET['geo_resume'] === '1';
// onglet occupations
$occSrcProp   = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $_GET['occ_src'] ?? 'P106'));
$occResume    = isset($_GET['occ_resume']) && $_GET['occ_resume'] === '1';
// onglet réseau P279
$netStart  = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $_GET['net_start'] ?? ''));
$netResume = isset($_GET['net_resume']) && $_GET['net_resume'] === '1';

$dbOk      = dbAvailable();
$dbMessage = '';
$dbError   = '';

// ── DB actions ────────────────────────────────────────────────────────────────
if ($dbOk) {
    // Crée ou retrouve l'enregistrement de recherche pour les exports manuels (POST)
    $postSearchId = 0;
    if (in_array($action, ['export_sel','export_all'])) {
        $postSearchId = upsertSearch(
            $_POST['sq']    ?? '',
            $_POST['slang'] ?? '',
            $_POST['sp31']  ?? '',
            $_POST['sp279'] ?? '',
            $_POST['setype']?? ''
        );
    }

    if ($action === 'export_sel' && !empty($_POST['ids'])) {
        $ids      = json_decode($_POST['ids'], true) ?? [];
        $allRows  = json_decode($_POST['rows'], true) ?? [];
        $toExport = array_values(array_filter($allRows, fn($r) => in_array($r['id'], $ids)));
        $n = exportToDb($toExport, $postSearchId);
        $dbMessage = "$n entité(s) exportée(s) vers MariaDB.";
    }
    if ($action === 'export_all' && !empty($_POST['rows'])) {
        $allRows = json_decode($_POST['rows'], true) ?? [];
        $n = exportToDb($allRows, $postSearchId);
        $dbMessage = "$n entité(s) exportée(s) vers MariaDB (export complet).";
    }
    if ($action === 'delete' && !empty($_GET['del'])) {
        deleteDbRow($_GET['del']);
        $dbMessage = "Entité supprimée.";
        $tab = 'db';
    }
    if ($action === 'cleardb') {
        clearDb();
        $dbMessage = "Base vidée.";
        $tab = 'db';
    }
    if ($action === 'csv_db') {
        $rows = getDbRows($dbSearchId);
        outputDbCsv($rows, 'wikidata_db_' . date('Ymd_His') . '.csv');
    }
    // Propriétés
    if ($action === 'scan_props' && $propSid > 0 && $propId !== '') {
        $targetIds = getEntityIdsBySearchId($propSid);
        $res = scanDumpForProperties($targetIds, $propId);
        $elapsed = round(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 1);
        $dbMessage = "Propriété {$propId} · {$res['found']} entités trouvées sur "
            . count($targetIds) . " · {$res['inserted']} valeur(s) insérée(s) en {$elapsed} s"
            . ($res['timedOut'] ? ' ⏱ timeout' : '');
        $tab = 'props';
    }
    if ($action === 'clear_props') {
        clearProperties();
        $dbMessage = "Table wikidata_properties vidée.";
        $tab = 'props';
    }
    if ($action === 'del_prop' && !empty($_GET['pe']) && !empty($_GET['pp'])) {
        deletePropertyRows($_GET['pe'], $_GET['pp']);
        $dbMessage = "Valeurs supprimées.";
        $tab = 'props';
    }
    if ($action === 'csv_props') {
        $rows = getPropertyRows($propSid, $propFilter);
        outputDbCsv($rows, 'wikidata_props_' . date('Ymd_His') . '.csv');
    }
    if ($action === 'scan_geos') {
        $targetIds = getGeoTargetIds($geoSrcProp);
        $res = scanDumpForGeos($targetIds, $geoSrcProp, $geoResume);
        $elapsed = round(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 1);
        $suffix = $geoResume ? ' (reprise)' : '';
        $dbMessage = "Géos ({$geoSrcProp}){$suffix} · {$res['found']}/{$res['total']} entités"
            . " · {$res['inserted']} enregistrement(s) en {$elapsed} s"
            . ($res['timedOut'] ? ' ⏱ timeout' : ' ✓ terminé');
        $tab = 'geos';
    }
    if ($action === 'clear_geos') {
        getDb()->exec("DELETE FROM wikidata_geos");
        clearScanState('geo_' . $geoSrcProp);
        $dbMessage = "Table wikidata_geos vidée.";
        $tab = 'geos';
    }
    if ($action === 'csv_geos') {
        $rows = getGeoRows($geoSrcProp);
        outputDbCsv($rows, 'wikidata_geos_' . date('Ymd_His') . '.csv');
    }
    if ($action === 'scan_occs') {
        $targetIds = getOccupationTargetIds($occSrcProp);
        $res = scanDumpForOccupations($targetIds, $occSrcProp, $occResume);
        $elapsed = round(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 1);
        $suffix = $occResume ? ' (reprise)' : '';
        $dbMessage = "Occupations ({$occSrcProp}){$suffix} · {$res['found']}/{$res['total']} entités"
            . " · {$res['inserted']} enregistrement(s) en {$elapsed} s"
            . ($res['timedOut'] ? ' ⏱ timeout' : ' ✓ terminé');
        $tab = 'occs';
    }
    if ($action === 'clear_occs') {
        getDb()->exec("DELETE FROM wikidata_occupations");
        clearScanState('occ_' . $occSrcProp);
        $dbMessage = "Table wikidata_occupations vidée.";
        $tab = 'occs';
    }
    if ($action === 'csv_occs') {
        $rows = getOccupationRows($occSrcProp);
        outputDbCsv($rows, 'wikidata_occupations_' . date('Ymd_His') . '.csv');
    }
    if ($action === 'explore_net' && $netStart !== '') {
        $res = exploreP279Network($netStart, $netResume);
        $elapsed = round(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 1);
        $suffix  = $netResume ? ' (reprise)' : '';
        $dbMessage = "Réseau depuis {$netStart}{$suffix}"
            . " · {$res['nodesAdded']} nœuds · {$res['linksAdded']} liens"
            . " · {$res['levelsCompleted']} niveau(x) · {$elapsed} s"
            . ($res['timedOut']  ? ' ⏱ timeout — continuez' : '')
            . ($res['done']      ? ' ✓ exploration terminée' : (!$res['timedOut'] ? " · frontière restante : {$res['frontierSize']}" : ''));
        $tab = 'net';
    }
    if ($action === 'clear_net') {
        getDb()->exec("DELETE FROM wikidata_p279_graph");
        getDb()->exec("DELETE FROM wikidata_nodes");
        clearScanState('net_' . $netStart);
        $dbMessage = "Tables wikidata_p279_graph et wikidata_nodes vidées.";
        $tab = 'net';
    }
    if ($action === 'json_net') {
        $net = getP279NetworkFromDb();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="p279_network_' . date('Ymd_His') . '.json"');
        echo json_encode($net, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// CSV export from search results (no DB needed)
if ($action === 'csv_results' && !empty($_POST['rows'])) {
    $rows = json_decode($_POST['rows'], true) ?? [];
    outputCsv($rows, 'wikidata_search_' . date('Ymd_His') . '.csv');
}

// ── Search ────────────────────────────────────────────────────────────────────
$canSearch = ($query !== '' || $p31Filter !== '' || $p279Filter !== '' || $idFilter !== '' || $lineFrom > 0 || $lineTo > 0) && file_exists(DUMP_FILE);
$results   = [];
$checked   = 0;
$exported  = 0;
$error     = '';
$elapsed   = 0;
$timedOut  = false;

if ($directExport && !$dbOk) {
    $error = 'Export direct impossible : connexion MariaDB indisponible.';
    $canSearch = false;
}

if ($canSearch && $query !== '' && mb_strlen($query) < 2) {
    $error = 'La recherche textuelle doit contenir au moins 2 caractères.';
    $canSearch = false;
}

$resumePos    = 0;
$resumeSearchId = 0;

if ($canSearch) {
    $t0 = microtime(true);
    try {
        $directSearchId = 0;
        $startPos       = 0;
        if ($directExport && $dbOk) {
            $directSearchId = upsertSearch($query, $lang, $p31Filter, $p279Filter, $entityType);
            // Reprise depuis la dernière position sauvée si on reçoit le même search_id
            if ($resumeSid > 0 && $resumeSid === $directSearchId) {
                $startPos = getSearchPosition($directSearchId);
            }
        }
        $res      = searchDump($query, $lang, $entityType, $p31Filter, $p279Filter, $idFilter,
                               $searchLimit, $directExport, $directSearchId, $startPos,
                               $lineFrom, $lineTo);
        $results        = $res['results'];
        $checked        = $res['checked'];
        $exported       = $res['exported'];
        $timedOut       = $res['timedOut'];
        $resumePos      = $res['resumePos'];
        $resumeSearchId = $res['searchId'];
        $elapsed  = round(microtime(true) - $t0, 1);
        if ($directExport && $exported > 0) {
            $dbMessage = "{$exported} entité(s) exportée(s) directement vers MariaDB en {$elapsed} s"
                . ($startPos > 0 ? " (reprise depuis position {$startPos})" : '') . '.';
            if (!$timedOut) $tab = 'db';
        }
    } catch (\Throwable $e) {
        $error = 'Erreur : ' . $e->getMessage();
    }
}

$dumpExists   = file_exists(DUMP_FILE);
$dumpSize     = $dumpExists ? round(filesize(DUMP_FILE) / 1e9, 1) . ' Go' : '—';
$dbCount       = $dbOk ? countDbRows() : 0;
$propCount     = $dbOk ? countPropertyRows() : 0;
$geoCount      = $dbOk ? countGeoRows() : 0;
$dbRows        = ($tab === 'db'    && $dbOk) ? getDbRows($dbSearchId) : [];
$propRows      = ($tab === 'props' && $dbOk) ? getPropertyRows($propSid, $propFilter) : [];
$geoRows       = ($tab === 'geos'  && $dbOk) ? getGeoRows($geoSrcProp) : [];
$geoScanState  = $dbOk ? getScanState('geo_' . $geoSrcProp) : ['dump_position' => 0, 'total_target' => 0, 'found_so_far' => 0];
$occCount      = $dbOk ? countOccupationRows() : 0;
$occRows       = ($tab === 'occs'  && $dbOk) ? getOccupationRows($occSrcProp) : [];
$occScanState  = $dbOk ? getScanState('occ_' . $occSrcProp) : ['dump_position' => 0, 'total_target' => 0, 'found_so_far' => 0];
$netLinkCount  = $dbOk ? countP279GraphRows() : 0;
$netNodeCount  = $dbOk ? countNetworkNodes() : 0;
$netScanState  = $dbOk && $netStart !== '' ? getScanState('net_' . $netStart) : ['dump_position' => 0, 'total_target' => 0, 'found_so_far' => 0, 'frontier_json' => '[]'];
$netNetwork    = ($tab === 'net' && $dbOk && ($netNodeCount > 0)) ? getP279NetworkFromDb() : null;
$searchQueries = $dbOk ? getDistinctSearchQueries() : [];

$rowsJson   = json_encode($results);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Explorateur Wikidata Dump</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: system-ui, sans-serif; background: #f4f6f9; color: #212529; min-height: 100vh; }

header { background: #006699; color: #fff; padding: 1rem 2rem; display: flex; align-items: center; gap: 1rem; }
header h1 { font-size: 1.4rem; font-weight: 600; }
header small { opacity: .75; font-size: .8rem; }

main { max-width: 1150px; margin: 2rem auto; padding: 0 1rem; }

.card { background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,.1); padding: 1.25rem 1.5rem; margin-bottom: 1.5rem; }
.card-title { font-size: .95rem; font-weight: 600; color: #006699; margin-bottom: .9rem; padding-bottom: .5rem; border-bottom: 1px solid #eee; }

form { display: grid; gap: .7rem; }
.row { display: flex; gap: .7rem; flex-wrap: wrap; align-items: center; }
.row label { font-size: .83rem; color: #555; white-space: nowrap; }
input[type=text] { flex: 1 1 180px; padding: .48rem .7rem; border: 1px solid #ccd; border-radius: 6px; font-size: .93rem; }
input.narrow  { flex: 0 0 110px; }
input.xnarrow { flex: 0 0 80px; }
select { padding: .48rem .55rem; border: 1px solid #ccd; border-radius: 6px; font-size: .88rem; background: #fff; }
.btn { padding: .45rem 1.1rem; border: none; border-radius: 6px; font-size: .9rem; cursor: pointer; white-space: nowrap; }
.btn-primary   { background: #006699; color: #fff; }
.btn-primary:hover { background: #005580; }
.btn-success   { background: #198754; color: #fff; }
.btn-success:hover { background: #146c43; }
.btn-warning   { background: #fd7e14; color: #fff; }
.btn-warning:hover { background: #e8680e; }
.btn-info      { background: #0dcaf0; color: #000; }
.btn-info:hover { background: #0bb5d6; }
.btn-danger    { background: #dc3545; color: #fff; }
.btn-danger:hover { background: #bb2d3b; }
.btn-sm { font-size: .78rem; padding: .28rem .65rem; }

.alert { padding: .65rem 1rem; border-radius: 6px; margin-bottom: 1rem; font-size: .88rem; }
.alert-success { background: #d1e7dd; border: 1px solid #badbcc; color: #0f5132; }
.alert-danger  { background: #f8d7da; border: 1px solid #f5c2c7; color: #842029; }
.alert-warning { background: #fff3cd; border: 1px solid #ffc107; color: #856404; }
.alert-info    { background: #cff4fc; border: 1px solid #9eeaf9; color: #055160; }

.stat-bar { display: flex; gap: 1.5rem; flex-wrap: wrap; align-items: center; margin-bottom: .9rem; }
.stat { font-size: .85rem; color: #444; }
.stat strong { color: #006699; font-size: 1.05rem; }
.timeout-badge { background: #ffc107; color: #212529; padding: .15rem .55rem; border-radius: 4px; font-size: .78rem; font-weight: 600; }

table { width: 100%; border-collapse: collapse; font-size: .86rem; }
th { background: #006699; color: #fff; text-align: left; padding: .5rem .65rem; position: sticky; top: 0; z-index: 1; }
td { padding: .45rem .65rem; border-bottom: 1px solid #eee; vertical-align: top; }
tr:nth-child(even) td { background: #f8f9fc; }
tr:hover td { background: #e8f0f7; }
.cb-col { width: 30px; text-align: center; }

.badge { display: inline-block; padding: .1rem .42rem; border-radius: 4px; font-size: .73rem; font-weight: 600; }
.badge-item     { background: #cce5ff; color: #004085; }
.badge-property { background: #d4edda; color: #155724; }
.pill { display: inline-block; background: #e9ecef; border-radius: 4px; padding: .08rem .38rem; font-size: .7rem; margin: .1rem .1rem 0 0; }
.id-link { color: #006699; text-decoration: none; font-weight: 600; }
.id-link:hover { text-decoration: underline; }
.muted { font-size: .73rem; color: #888; }
.no-results { text-align: center; color: #999; padding: 2rem; }

.tabs { display: flex; border-bottom: 2px solid #006699; margin-bottom: 1.5rem; }
.tab { padding: .5rem 1.1rem; cursor: pointer; font-size: .9rem; border: 1px solid #ccd; border-bottom: none;
       border-radius: 6px 6px 0 0; background: #f0f4f8; color: #555; margin-right: 4px; }
.tab.active { background: #006699; color: #fff; border-color: #006699; }
.tab-panel { display: none; }
.tab-panel.active { display: block; }

footer { text-align: center; font-size: .78rem; color: #aaa; padding: 2rem 0 3rem; }
</style>
</head>
<body>

<header>
  <div>
    <h1>🔍 Explorateur Wikidata Dump</h1>
    <small>wikidata-20240101-all.json.gz &nbsp;·&nbsp; <?= h($dumpSize) ?> &nbsp;·&nbsp; timeout <?= TIME_LIMIT ?> s
    &nbsp;·&nbsp; DB MariaDB <?= $dbOk ? '✅' : '❌ non connectée' ?>
    </small>
  </div>
</header>

<main>

<?php if (!$dumpExists): ?>
<div class="alert alert-danger">⚠️ Fichier dump introuvable : <code><?= h(DUMP_FILE) ?></code></div>
<?php endif; ?>
<?php if ($dbMessage): ?><div class="alert alert-success"><?= h($dbMessage) ?></div><?php endif; ?>
<?php if ($dbError):   ?><div class="alert alert-danger"><?= h($dbError) ?></div><?php endif; ?>

<div class="tabs">
  <div class="tab <?= $tab === 'search' ? 'active' : '' ?>" onclick="showTab('search',this)">Recherche</div>
  <div class="tab <?= $tab === 'db'     ? 'active' : '' ?>" onclick="showTab('db',this)">Entités (<?= $dbCount ?>)</div>
  <div class="tab <?= $tab === 'props'  ? 'active' : '' ?>" onclick="showTab('props',this)">Propriétés (<?= $propCount ?>)</div>
  <div class="tab <?= $tab === 'geos'   ? 'active' : '' ?>" onclick="showTab('geos',this)">Géos (<?= $geoCount ?>)</div>
  <div class="tab <?= $tab === 'occs'   ? 'active' : '' ?>" onclick="showTab('occs',this)">Occupations (<?= $occCount ?>)</div>
  <div class="tab <?= $tab === 'net'    ? 'active' : '' ?>" onclick="showTab('net',this)">Réseau P279 (<?= $netNodeCount ?>)</div>
</div>

<!-- ═══ RECHERCHE ═══ -->
<div id="tab-search" class="tab-panel <?= $tab === 'search' ? 'active' : '' ?>">

  <div class="card">
    <div class="card-title">Paramètres</div>
    <form method="get" action="">
      <input type="hidden" name="tab" value="search">
      <div class="row">
        <label>Texte</label>
        <input type="text" name="q" value="<?= h($query) ?>" placeholder="label, description, alias… (optionnel si P31/P279)" autofocus>
        <label>Langue</label>
        <select name="lang">
          <?php foreach (['fr','en','de','es','it','pt','nl','all'] as $l): ?>
          <option value="<?= $l ?>" <?= $lang === $l ? 'selected' : '' ?>><?= $l === 'all' ? 'Toutes' : strtoupper($l) ?></option>
          <?php endforeach; ?>
        </select>
        <label>Entité</label>
        <select name="etype">
          <option value="all"      <?= $entityType==='all'      ? 'selected':'' ?>>Tous</option>
          <option value="item"     <?= $entityType==='item'     ? 'selected':'' ?>>Items (Q)</option>
          <option value="property" <?= $entityType==='property' ? 'selected':'' ?>>Propriétés (P)</option>
        </select>
      </div>
      <div class="row">
        <label>ID entité</label>
        <input type="text" name="eid" class="narrow" value="<?= h($idFilter) ?>" placeholder="ex: Q42 ou Q42,Q43">
        <label>Ligne dump de</label>
        <input type="number" name="line_from" class="xnarrow" value="<?= $lineFrom ?: '' ?>" placeholder="1" min="0" style="width:7rem">
        <label>à</label>
        <input type="number" name="line_to" class="xnarrow" value="<?= $lineTo ?: '' ?>" placeholder="∞" min="0" style="width:7rem">
        <label>wdt:P31 instance de</label>
        <input type="text" name="p31"  class="xnarrow" value="<?= h($p31Filter) ?>"  placeholder="ex: Q5">
        <label>wdt:P279 sous-classe de</label>
        <input type="text" name="p279" class="xnarrow" value="<?= h($p279Filter) ?>" placeholder="ex: Q7187">
        <label style="display:flex;align-items:center;gap:.35rem;cursor:pointer;">
          <input type="checkbox" name="nolimit" value="1" id="cbNolimit" <?= $noLimit ? 'checked' : '' ?>>
          Sans limite
        </label>
        <?php if ($dbOk): ?>
        <label style="display:flex;align-items:center;gap:.35rem;cursor:pointer;" title="Exporte chaque résultat directement en DB sans les afficher — recommandé pour les grandes quantités">
          <input type="checkbox" name="directdb" value="1" id="cbDirectdb" <?= $directExport ? 'checked' : '' ?>
                 onchange="document.getElementById('cbNolimit').checked = this.checked || document.getElementById('cbNolimit').checked">
          <span style="color:#198754;font-weight:600">↗ Export direct → MariaDB</span>
        </label>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary">Rechercher</button>
      </div>
      <div class="muted" style="margin-top:.3rem;">
        Recherche séquentielle · timeout <?= TIME_LIMIT ?> s
        · <?= $noLimit ? 'aucune limite' : 'max ' . MAX_RESULTS . ' résultats' ?>
        <?= $directExport ? '· <strong>mode export direct</strong> : résultats non affichés, insérés par lots de 500' : '' ?>
        · P31/P279 sans texte = tous les items du type
      </div>
    </form>
  </div>

  <?php if ($error): ?>
  <div class="alert alert-danger"><?= h($error) ?></div>
  <?php endif; ?>

  <?php if ($canSearch && !$error): ?>
  <div class="card">

    <!-- Stats bar -->
    <div class="stat-bar">
      <?php if ($directExport): ?>
      <div class="stat">Exportées en DB&nbsp;: <strong style="color:#198754"><?= $exported ?></strong></div>
      <?php else: ?>
      <div class="stat">Résultats&nbsp;: <strong><?= count($results) ?></strong></div>
      <?php endif; ?>
      <div class="stat">Entités lues&nbsp;: <strong><?= number_format($checked, 0, ',', '\u{202F}') ?></strong></div>
      <div class="stat">Durée&nbsp;: <strong><?= $elapsed ?> s</strong></div>
      <?php if ($timedOut): ?>
        <span class="timeout-badge">⏱ timeout atteint</span>
        <?php if ($directExport && $resumePos > 0 && $resumeSearchId > 0):
            $continueUrl = '?' . http_build_query([
                'tab'       => 'search',
                'q'         => $query,
                'lang'      => $lang,
                'etype'     => $entityType,
                'p31'       => $p31Filter,
                'p279'      => $p279Filter,
                'eid'       => $idFilter,
                'line_from' => $lineFrom,
                'line_to'   => $lineTo,
                'nolimit'   => $noLimit ? '1' : '0',
                'directdb'  => '1',
                'sid'       => $resumeSearchId,
            ]);
        ?>
        <a href="<?= h($continueUrl) ?>" class="btn btn-warning btn-sm" style="margin-left:.5rem">
          ▶ Continuer (pos.&nbsp;<?= number_format($resumePos, 0, ',', '&nbsp;') ?>)
        </a>
        <?php endif; ?>
      <?php endif; ?>
      <?php if (!$noLimit && !$directExport && count($results) === MAX_RESULTS): ?>
      <span class="timeout-badge" style="background:#0dcaf0">limite <?= MAX_RESULTS ?> résultats</span>
      <?php endif; ?>
    </div>

    <?php if (empty($results)): ?>
      <div class="no-results">Aucun résultat</div>
    <?php else: ?>

    <!-- Barre d'actions export -->
    <div class="row" style="margin-bottom:.9rem; gap:.5rem;">
      <?php if ($dbOk): ?>
      <button class="btn btn-success btn-sm" onclick="submitExport('export_sel')">💾 Exporter sélection → MariaDB</button>
      <button class="btn btn-warning btn-sm" onclick="submitExport('export_all')">💾 Exporter tout → MariaDB</button>
      <?php endif; ?>
      <button class="btn btn-info btn-sm" onclick="submitExport('csv_results')">⬇ Télécharger CSV (sélection)</button>
      <button class="btn btn-info btn-sm" onclick="submitExportAll('csv_results')">⬇ Télécharger CSV (tout)</button>
    </div>

    <!-- Formulaire hidden pour POST -->
    <form id="exportForm" method="post" action="?tab=search&q=<?= h(urlencode($query)) ?>&lang=<?= h($lang) ?>&etype=<?= h($entityType) ?>&p31=<?= h($p31Filter) ?>&p279=<?= h($p279Filter) ?>&eid=<?= h($idFilter) ?>&nolimit=<?= $noLimit ? '1' : '0' ?>">
      <input type="hidden" name="action"  id="exportAction">
      <input type="hidden" name="sq"      value="<?= h(trim($searchTag)) ?>">
      <input type="hidden" name="slang"   value="<?= h($lang) ?>">
      <input type="hidden" name="sp31"    value="<?= h($p31Filter) ?>">
      <input type="hidden" name="sp279"   value="<?= h($p279Filter) ?>">
      <input type="hidden" name="setype"  value="<?= h($entityType) ?>">
      <input type="hidden" name="ids"     id="exportIds">
      <input type="hidden" name="rows"    value="<?= h($rowsJson) ?>">
    </form>

    <table>
      <thead>
        <tr>
          <th class="cb-col"><input type="checkbox" id="selAll" onchange="toggleAll(this)"></th>
          <th>ID</th><th>Type</th><th>Label</th><th>Description</th>
          <th>P31</th><th>P279</th><th>Corresp.</th>
          <th title="Nombre de sitelinks">🔗</th><th title="Nombre de statements">📋</th><th title="Identifiants externes">🆔</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($results as $row): ?>
        <tr>
          <td class="cb-col"><input type="checkbox" class="rcb" value="<?= h($row['id']) ?>"></td>
          <td><a class="id-link" href="https://www.wikidata.org/wiki/<?= h($row['id']) ?>" target="_blank"><?= h($row['id']) ?></a></td>
          <td><span class="badge badge-<?= h($row['type']) ?>"><?= h($row['type']) ?></span></td>
          <td><?= h($row['label']) ?></td>
          <td><?= h(mb_strimwidth($row['desc'], 0, 120, '…')) ?></td>
          <td><?php foreach ($row['p31']  as $p): ?><span class="pill"><a class="id-link" href="https://www.wikidata.org/wiki/<?= h($p) ?>" target="_blank"><?= h($p) ?></a></span><?php endforeach; ?></td>
          <td><?php foreach ($row['p279'] as $p): ?><span class="pill"><a class="id-link" href="https://www.wikidata.org/wiki/<?= h($p) ?>" target="_blank"><?= h($p) ?></a></span><?php endforeach; ?></td>
          <td class="muted"><?= h($row['matchedIn']) ?></td>
          <td class="muted"><?= (int)($row['sitelinks']   ?? 0) ?></td>
          <td class="muted"><?= (int)($row['statements']  ?? 0) ?></td>
          <td class="muted"><?= (int)($row['externalIds'] ?? 0) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

  </div>
  <?php endif; ?>

</div><!-- /tab-search -->

<!-- ═══ BASE MARIADB ═══ -->
<div id="tab-db" class="tab-panel <?= $tab === 'db' ? 'active' : '' ?>">
  <div class="card">
    <div class="row" style="margin-bottom:1rem; justify-content:space-between;">
      <div class="card-title" style="margin:0; border:none; padding:0;">
        Entités en base &nbsp;<strong><?= $dbCount ?></strong>
      </div>
      <div class="row" style="gap:.5rem;">
        <!-- Filtre par recherche -->
        <form method="get" action="" style="display:contents">
          <input type="hidden" name="tab" value="db">
          <select name="dsid" style="max-width:220px">
            <option value="0">Toutes les recherches</option>
            <?php foreach ($searchQueries as $sq): ?>
            <option value="<?= (int)$sq['id'] ?>" <?= $dbSearchId === (int)$sq['id'] ? 'selected' : '' ?>>
              <?= h($sq['query'] ?: 'P31='.$sq['p31'].' P279='.$sq['p279']) ?> (<?= $sq['cnt'] ?>)
            </option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-primary btn-sm">Filtrer</button>
        </form>
        <!-- CSV DB -->
        <form method="get" action="?tab=db&dsid=<?= $dbSearchId ?>&action=csv_db" style="display:contents">
          <button type="submit" class="btn btn-info btn-sm">⬇ CSV</button>
        </form>
        <!-- Vider -->
        <form method="post" action="?tab=db" onsubmit="return confirm('Vider toute la base ?')" style="display:contents">
          <input type="hidden" name="action" value="cleardb">
          <button type="submit" class="btn btn-danger btn-sm">🗑 Vider la base</button>
        </form>
      </div>
    </div>

    <?php if (!$dbOk): ?>
    <div class="alert alert-danger">Connexion MariaDB impossible. Vérifiez <code>config.php</code>.</div>
    <?php elseif (empty($dbRows)): ?>
    <div class="no-results">Aucune entité en base.</div>
    <?php else: ?>

    <div class="stat-bar" style="margin-bottom:.7rem;">
      <div class="stat">Affichés&nbsp;: <strong><?= count($dbRows) ?></strong></div>
      <div class="stat">Total en base&nbsp;: <strong><?= $dbCount ?></strong></div>
    </div>

    <table>
      <thead>
        <tr><th>ID</th><th>Type</th><th>Label</th><th>Description</th><th>P31</th><th>P279</th><th>Correspondance</th>
            <th title="Sitelinks">🔗</th><th title="Statements">📋</th><th title="Identifiants externes">🆔</th>
            <th>Date</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($dbRows as $row): ?>
        <tr>
          <td><a class="id-link" href="https://www.wikidata.org/wiki/<?= h($row['id']) ?>" target="_blank"><?= h($row['id']) ?></a></td>
          <td><span class="badge badge-<?= h($row['type'] ?? '') ?>"><?= h($row['type'] ?? '') ?></span></td>
          <td><?= h($row['label'] ?? '') ?></td>
          <td><?= h(mb_strimwidth($row['description'] ?? '', 0, 100, '…')) ?></td>
          <td><?php foreach (array_filter(explode('|', $row['p31'] ?? '')) as $p): ?><span class="pill"><?= h($p) ?></span><?php endforeach; ?></td>
          <td><?php foreach (array_filter(explode('|', $row['p279'] ?? '')) as $p): ?><span class="pill"><?= h($p) ?></span><?php endforeach; ?></td>
          <td class="muted"><?= h($row['matched_in'] ?? '') ?></td>
          <td class="muted"><?= (int)($row['sitelinks']   ?? 0) ?></td>
          <td class="muted"><?= (int)($row['statements']  ?? 0) ?></td>
          <td class="muted"><?= (int)($row['externalIds'] ?? 0) ?></td>
          <td class="muted"><?= h(substr($row['exported_at'] ?? '', 0, 16)) ?></td>
          <td>
            <a href="?tab=db&action=delete&del=<?= h(urlencode($row['id'])) ?>&dsid=<?= $dbSearchId ?>"
               onclick="return confirm('Supprimer <?= h($row['id']) ?> ?')"
               class="btn btn-danger btn-sm" style="text-decoration:none">✕</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div><!-- /tab-db -->

<!-- ═══ PROPRIÉTÉS ═══ -->
<div id="tab-props" class="tab-panel <?= $tab === 'props' ? 'active' : '' ?>">

  <div class="card">
    <div class="card-title">Extraire une propriété depuis le dump</div>
    <?php if (!$dbOk): ?>
    <div class="alert alert-danger">Connexion MariaDB indisponible.</div>
    <?php elseif (empty($searchQueries)): ?>
    <div class="alert alert-warning">Aucune entité en base. Commencez par rechercher et exporter des entités (onglet Recherche).</div>
    <?php else: ?>
    <form method="get" action="">
      <input type="hidden" name="tab"    value="props">
      <input type="hidden" name="action" value="scan_props">
      <div class="row">
        <label>Groupe d'entités (recherche)</label>
        <select name="psid" style="flex:1 1 250px">
          <option value="0">— choisir —</option>
          <?php foreach ($searchQueries as $sq): ?>
          <option value="<?= (int)$sq['id'] ?>" <?= $propSid === (int)$sq['id'] ? 'selected' : '' ?>>
            #<?= $sq['id'] ?> — <?= h($sq['query'] ?: 'P31='.$sq['p31'].' P279='.$sq['p279']) ?>
            (<?= $sq['cnt'] ?> entités · <?= $sq['lang'] ?>)
          </option>
          <?php endforeach; ?>
        </select>
        <label>Propriété à extraire</label>
        <input type="text" name="pid" class="xnarrow" value="<?= h($propId) ?>" placeholder="ex: P18">
        <button type="submit" class="btn btn-success" <?= !$dumpExists ? 'disabled' : '' ?>>
          ↗ Scanner le dump
        </button>
      </div>
      <div class="muted" style="margin-top:.3rem;">
        Le dump est parcouru pour trouver les entités du groupe sélectionné et en extraire toutes les valeurs de la propriété indiquée.
        Timeout <?= TIME_LIMIT ?> s — relancez si le groupe est grand.
      </div>
    </form>
    <?php endif; ?>
  </div>

  <!-- Tableau des propriétés -->
  <div class="card">
    <div class="row" style="margin-bottom:1rem; justify-content:space-between; flex-wrap:wrap; gap:.5rem;">
      <div class="card-title" style="margin:0;border:none;padding:0;">
        Valeurs en base &nbsp;<strong><?= $propCount ?></strong>
      </div>
      <div class="row" style="gap:.5rem;">
        <!-- Filtre affichage -->
        <form method="get" action="" style="display:contents">
          <input type="hidden" name="tab" value="props">
          <select name="psid" title="Filtrer par groupe" style="max-width:200px">
            <option value="0">Tous les groupes</option>
            <?php foreach ($searchQueries as $sq): ?>
            <option value="<?= (int)$sq['id'] ?>" <?= $propSid === (int)$sq['id'] ? 'selected' : '' ?>>
              #<?= $sq['id'] ?> <?= h($sq['query'] ?: 'P31='.$sq['p31']) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <input type="text" name="pf" class="xnarrow" value="<?= h($propFilter) ?>" placeholder="ex: P31">
          <button type="submit" class="btn btn-primary btn-sm">Filtrer</button>
        </form>
        <!-- CSV -->
        <form method="get" action="" style="display:contents">
          <input type="hidden" name="tab"    value="props">
          <input type="hidden" name="action" value="csv_props">
          <input type="hidden" name="psid"   value="<?= $propSid ?>">
          <input type="hidden" name="pf"     value="<?= h($propFilter) ?>">
          <button type="submit" class="btn btn-info btn-sm">⬇ CSV</button>
        </form>
        <!-- Vider -->
        <form method="get" action="" onsubmit="return confirm('Vider toute la table wikidata_properties ?')" style="display:contents">
          <input type="hidden" name="tab"    value="props">
          <input type="hidden" name="action" value="clear_props">
          <button type="submit" class="btn btn-danger btn-sm">🗑 Vider</button>
        </form>
      </div>
    </div>

    <?php if (empty($propRows)): ?>
    <div class="no-results">Aucune propriété en base<?= $propFilter ? ' pour ce filtre' : '' ?>.</div>
    <?php else: ?>
    <div class="stat-bar" style="margin-bottom:.7rem;">
      <div class="stat">Affichés&nbsp;: <strong><?= count($propRows) ?></strong></div>
      <div class="stat">Total&nbsp;: <strong><?= $propCount ?></strong></div>
    </div>
    <table>
      <thead>
        <tr>
          <th>Entité</th><th>Label</th><th>Propriété</th>
          <th>Type</th><th>Valeur (ID)</th><th>Valeur (texte)</th>
          <th>Langue</th><th>Rang</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($propRows as $row): ?>
        <tr>
          <td><a class="id-link" href="https://www.wikidata.org/wiki/<?= h($row['entity_id']) ?>" target="_blank"><?= h($row['entity_id']) ?></a></td>
          <td><?= h($row['label'] ?? '') ?></td>
          <td><span class="badge badge-property"><?= h($row['property']) ?></span></td>
          <td class="muted"><?= h($row['value_type'] ?? '') ?></td>
          <td><?php if ($row['value_id']): ?>
            <a class="id-link" href="https://www.wikidata.org/wiki/<?= h($row['value_id']) ?>" target="_blank"><?= h($row['value_id']) ?></a>
          <?php endif; ?></td>
          <td><?= h(mb_strimwidth($row['value_str'] ?? '', 0, 100, '…')) ?></td>
          <td class="muted"><?= h($row['value_lang'] ?? '') ?></td>
          <td class="muted"><?= h($row['rank'] ?? '') ?></td>
          <td>
            <a href="?tab=props&action=del_prop&pe=<?= h(urlencode($row['entity_id'])) ?>&pp=<?= h(urlencode($row['property'])) ?>&psid=<?= $propSid ?>&pf=<?= h(urlencode($propFilter)) ?>"
               onclick="return confirm('Supprimer les valeurs <?= h($row['property']) ?> de <?= h($row['entity_id']) ?> ?')"
               class="btn btn-danger btn-sm" style="text-decoration:none">✕</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

</div><!-- /tab-props -->

<!-- ═══ GÉOS ═══ -->
<div id="tab-geos" class="tab-panel <?= $tab === 'geos' ? 'active' : '' ?>">

  <div class="card">
    <div class="card-title">Importer les coordonnées géographiques (P625)</div>
    <?php if (!$dbOk): ?>
    <div class="alert alert-danger">Connexion MariaDB indisponible.</div>
    <?php else: ?>
    <form method="get" action="">
      <input type="hidden" name="tab"    value="geos">
      <input type="hidden" name="action" value="scan_geos">
      <div class="row">
        <label>Propriété source</label>
        <input type="text" name="geo_src" class="xnarrow" value="<?= h($geoSrcProp) ?>"
               placeholder="P19" title="Propriété dont les value_id seront les entités à géolocaliser">
        <button type="submit" class="btn btn-success" <?= !$dumpExists ? 'disabled' : '' ?>>
          ↗ Scanner le dump
        </button>
      </div>
      <div class="muted" style="margin-top:.3rem;">
        Récupère les <code>value_id</code> distincts de <code>wikidata_properties</code> pour la propriété source
        (ex&nbsp;: P19 = lieu de naissance), puis parcourt le dump pour extraire le label et la localisation P625
        de chacune de ces entités.
      </div>
    </form>
    <?php if ((int)$geoScanState['dump_position'] > 0): ?>
    <div class="stat-bar" style="margin-top:.75rem; background:#fff3cd; border-radius:6px; padding:.5rem .75rem;">
      <span>⏱ Scan interrompu · <?= (int)$geoScanState['found_so_far'] ?>/<?= (int)$geoScanState['total_target'] ?> entités trouvées</span>
      <?php
        $continueGeoUrl = '?' . http_build_query([
            'tab'        => 'geos',
            'action'     => 'scan_geos',
            'geo_src'    => $geoSrcProp,
            'geo_resume' => '1',
        ]);
      ?>
      <a href="<?= h($continueGeoUrl) ?>" class="btn btn-warning btn-sm" style="margin-left:.75rem">
        ▶ Continuer (pos.&nbsp;<?= number_format((int)$geoScanState['dump_position'], 0, ',', '&nbsp;') ?>)
      </a>
      <?php
        $resetGeoUrl = '?' . http_build_query([
            'tab'     => 'geos',
            'action'  => 'scan_geos',
            'geo_src' => $geoSrcProp,
        ]);
      ?>
      <a href="<?= h($resetGeoUrl) ?>" class="btn btn-secondary btn-sm" style="margin-left:.25rem"
         onclick="return confirm('Recommencer depuis le début ?')">
        ↺ Recommencer
      </a>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>

  <?php if ($dbMessage && $tab === 'geos'): ?>
  <div class="alert alert-success"><?= h($dbMessage) ?></div>
  <?php endif; ?>

  <div class="card">
    <div class="row" style="margin-bottom:1rem; justify-content:space-between; flex-wrap:wrap; gap:.5rem;">
      <div class="card-title" style="margin:0;border:none;padding:0;">
        Géos en base &nbsp;<strong><?= $geoCount ?></strong>
      </div>
      <div class="row" style="gap:.5rem;">
        <form method="get" action="" style="display:contents">
          <input type="hidden" name="tab" value="geos">
          <label>Source</label>
          <input type="text" name="geo_src" class="xnarrow" value="<?= h($geoSrcProp) ?>" placeholder="P19">
          <button type="submit" class="btn btn-primary btn-sm">Filtrer</button>
        </form>
        <form method="get" action="" style="display:contents">
          <input type="hidden" name="tab"     value="geos">
          <input type="hidden" name="action"  value="csv_geos">
          <input type="hidden" name="geo_src" value="<?= h($geoSrcProp) ?>">
          <button type="submit" class="btn btn-info btn-sm">⬇ CSV</button>
        </form>
        <form method="get" action="" onsubmit="return confirm('Vider toute la table wikidata_geos ?')" style="display:contents">
          <input type="hidden" name="tab"    value="geos">
          <input type="hidden" name="action" value="clear_geos">
          <button type="submit" class="btn btn-danger btn-sm">🗑 Vider</button>
        </form>
      </div>
    </div>

    <?php if (empty($geoRows)): ?>
    <div class="no-results">Aucune donnée géographique en base.</div>
    <?php else: ?>
    <div class="stat-bar" style="margin-bottom:.5rem;">
      <div class="stat">Affichés&nbsp;: <strong><?= count($geoRows) ?></strong></div>
      <div class="stat">Total&nbsp;: <strong><?= $geoCount ?></strong></div>
    </div>
    <table>
      <thead>
        <tr>
          <th>ID</th><th>Label</th>
          <th>Latitude</th><th>Longitude</th><th>Précision</th><th>Globe</th><th>Source</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($geoRows as $row): ?>
        <tr>
          <td><a class="id-link" href="https://www.wikidata.org/wiki/<?= h($row['id']) ?>" target="_blank"><?= h($row['id']) ?></a></td>
          <td><?= h($row['label'] ?? '') ?></td>
          <td class="muted"><?= $row['latitude']  !== null ? number_format((float)$row['latitude'],  6) : '—' ?></td>
          <td class="muted"><?= $row['longitude'] !== null ? number_format((float)$row['longitude'], 6) : '—' ?></td>
          <td class="muted"><?= $row['precis']  !== null ? $row['precis']  : '—' ?></td>
          <td class="muted"><?= h($row['globe']  ?? '—') ?></td>
          <td class="muted"><?= h($row['source_prop']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

</div><!-- /tab-geos -->

<!-- ═══ OCCUPATIONS ═══ -->
<div id="tab-occs" class="tab-panel <?= $tab === 'occs' ? 'active' : '' ?>">

  <div class="card">
    <div class="card-title">Importer les occupations / métiers (P279 = sous-classe de)</div>
    <?php if (!$dbOk): ?>
    <div class="alert alert-danger">Connexion MariaDB indisponible.</div>
    <?php else: ?>
    <form method="get" action="">
      <input type="hidden" name="tab"    value="occs">
      <input type="hidden" name="action" value="scan_occs">
      <div class="row">
        <label>Propriété source</label>
        <input type="text" name="occ_src" class="xnarrow" value="<?= h($occSrcProp) ?>"
               placeholder="P106" title="Propriété dont les value_id seront les entités à traiter">
        <button type="submit" class="btn btn-success" <?= !$dumpExists ? 'disabled' : '' ?>>
          ↗ Scanner le dump
        </button>
      </div>
      <div class="muted" style="margin-top:.3rem;">
        Récupère les <code>value_id</code> distincts de <code>wikidata_properties</code> pour la propriété source
        (P106 = occupation), puis parcourt le dump pour extraire le label et les sous-classes (P279) de chacune.
      </div>
    </form>
    <?php if ((int)$occScanState['dump_position'] > 0): ?>
    <div class="stat-bar" style="margin-top:.75rem; background:#fff3cd; border-radius:6px; padding:.5rem .75rem;">
      <span>⏱ Scan interrompu · <?= (int)$occScanState['found_so_far'] ?>/<?= (int)$occScanState['total_target'] ?> entités trouvées</span>
      <?php
        $continueOccUrl = '?' . http_build_query(['tab'=>'occs','action'=>'scan_occs','occ_src'=>$occSrcProp,'occ_resume'=>'1']);
        $resetOccUrl    = '?' . http_build_query(['tab'=>'occs','action'=>'scan_occs','occ_src'=>$occSrcProp]);
      ?>
      <a href="<?= h($continueOccUrl) ?>" class="btn btn-warning btn-sm" style="margin-left:.75rem">
        ▶ Continuer (pos.&nbsp;<?= number_format((int)$occScanState['dump_position'], 0, ',', '&nbsp;') ?>)
      </a>
      <a href="<?= h($resetOccUrl) ?>" class="btn btn-secondary btn-sm" style="margin-left:.25rem"
         onclick="return confirm('Recommencer depuis le début ?')">↺ Recommencer</a>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>

  <?php if ($dbMessage && $tab === 'occs'): ?>
  <div class="alert alert-success"><?= h($dbMessage) ?></div>
  <?php endif; ?>

  <div class="card">
    <div class="row" style="margin-bottom:1rem; justify-content:space-between; flex-wrap:wrap; gap:.5rem;">
      <div class="card-title" style="margin:0;border:none;padding:0;">
        Occupations en base &nbsp;<strong><?= $occCount ?></strong>
      </div>
      <div class="row" style="gap:.5rem;">
        <form method="get" action="" style="display:contents">
          <input type="hidden" name="tab" value="occs">
          <label>Source</label>
          <input type="text" name="occ_src" class="xnarrow" value="<?= h($occSrcProp) ?>" placeholder="P106">
          <button type="submit" class="btn btn-primary btn-sm">Filtrer</button>
        </form>
        <form method="get" action="" style="display:contents">
          <input type="hidden" name="tab"     value="occs">
          <input type="hidden" name="action"  value="csv_occs">
          <input type="hidden" name="occ_src" value="<?= h($occSrcProp) ?>">
          <button type="submit" class="btn btn-info btn-sm">⬇ CSV</button>
        </form>
        <form method="get" action="" onsubmit="return confirm('Vider toute la table wikidata_occupations ?')" style="display:contents">
          <input type="hidden" name="tab"    value="occs">
          <input type="hidden" name="action" value="clear_occs">
          <button type="submit" class="btn btn-danger btn-sm">🗑 Vider</button>
        </form>
      </div>
    </div>

    <?php if (empty($occRows)): ?>
    <div class="no-results">Aucune occupation en base.</div>
    <?php else: ?>
    <div class="stat-bar" style="margin-bottom:.5rem;">
      <div class="stat">Affichés&nbsp;: <strong><?= count($occRows) ?></strong></div>
      <div class="stat">Total&nbsp;: <strong><?= $occCount ?></strong></div>
    </div>
    <table>
      <thead>
        <tr><th>ID</th><th>Label</th><th>Sous-classe de (P279)</th><th>Source</th></tr>
      </thead>
      <tbody>
        <?php foreach ($occRows as $row): ?>
        <tr>
          <td><a class="id-link" href="https://www.wikidata.org/wiki/<?= h($row['id']) ?>" target="_blank"><?= h($row['id']) ?></a></td>
          <td><?= h($row['label'] ?? '') ?></td>
          <td class="muted">
            <?php foreach (array_filter(explode('|', $row['subclass_of'] ?? '')) as $p): ?>
            <span class="pill"><a class="id-link" href="https://www.wikidata.org/wiki/<?= h($p) ?>" target="_blank"><?= h($p) ?></a></span>
            <?php endforeach; ?>
          </td>
          <td class="muted"><?= h($row['source_prop']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

</div><!-- /tab-occs -->

<!-- ═══ RÉSEAU P279 ═══ -->
<div id="tab-net" class="tab-panel <?= $tab === 'net' ? 'active' : '' ?>">

  <!-- Formulaire d'exploration -->
  <div class="card">
    <div class="card-title">Explorer le réseau P279 depuis une entité</div>
    <?php if (!$dbOk): ?>
    <div class="alert alert-danger">Connexion MariaDB indisponible.</div>
    <?php else: ?>
    <form method="get" action="">
      <input type="hidden" name="tab"    value="net">
      <input type="hidden" name="action" value="explore_net">
      <div class="row">
        <label>Entité de départ</label>
        <input type="text" name="net_start" class="xnarrow" value="<?= h($netStart) ?>"
               placeholder="ex: Q28640" style="width:8rem" autofocus>
        <button type="submit" class="btn btn-success" <?= !$dumpExists ? 'disabled' : '' ?>>
          ↗ Explorer depuis le dump
        </button>
        <?php if ($netNodeCount > 0): ?>
        <a href="?tab=net&action=clear_net&net_start=<?= h($netStart) ?>" class="btn btn-danger btn-sm"
           onclick="return confirm('Vider les tables wikidata_nodes et wikidata_p279_graph ?')">🗑 Vider</a>
        <a href="?tab=net&action=json_net" class="btn btn-info btn-sm">⬇ JSON</a>
        <?php endif; ?>
      </div>
      <div class="muted" style="margin-top:.3rem;">
        BFS niveau par niveau dans le dump : trouve les entités ayant l'entité de départ comme valeur de P279,
        puis leurs propres sous-classes, etc. S'arrête sur les entités déjà explorées.
        Les nœuds (id, label, position dump) sont stockés dans <code>wikidata_nodes</code>,
        les liens dans <code>wikidata_p279_graph</code>.
      </div>
    </form>

    <?php if ((int)$netScanState['dump_position'] > 0 || count(json_decode($netScanState['frontier_json'] ?? '[]', true) ?: []) > 0): ?>
    <?php
      $frontier     = json_decode($netScanState['frontier_json'] ?? '[]', true) ?: [];
      $continueUrl  = '?' . http_build_query(['tab'=>'net','action'=>'explore_net','net_start'=>$netStart,'net_resume'=>'1']);
    ?>
    <div class="stat-bar" style="margin-top:.75rem; background:#fff3cd; border-radius:6px; padding:.5rem .75rem;">
      <span>⏱ Exploration interrompue · <?= $netNodeCount ?> nœuds · <?= $netLinkCount ?> liens
            · frontière&nbsp;: <?= count($frontier) ?> entité(s)</span>
      <a href="<?= h($continueUrl) ?>" class="btn btn-warning btn-sm" style="margin-left:.75rem">
        ▶ Continuer
      </a>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>

  <?php if ($dbMessage && $tab === 'net'): ?>
  <div class="alert alert-success"><?= h($dbMessage) ?></div>
  <?php endif; ?>

  <!-- Résultats -->
  <?php if ($netNetwork !== null && ($netNodeCount > 0)): ?>
  <div class="card">
    <?php
      $nodeCount = count($netNetwork['nodes']);
      $linkCount = count($netNetwork['links']);
    ?>
    <div class="row" style="justify-content:space-between; flex-wrap:wrap; gap:.5rem; margin-bottom:.75rem;">
      <div class="card-title" style="margin:0;border:none;padding:0;">
        Réseau &nbsp;·&nbsp; <strong><?= $nodeCount ?></strong> nœuds &nbsp;·&nbsp; <strong><?= $linkCount ?></strong> liens
      </div>
    </div>

    <!-- Nœuds -->
    <details open style="margin-bottom:1rem;">
      <summary style="cursor:pointer;font-weight:600;margin-bottom:.5rem;">Nœuds (<?= $nodeCount ?>)</summary>
      <div style="max-height:300px;overflow-y:auto;">
        <table>
          <thead><tr><th>ID</th><th>Label</th><th>Ligne dump</th></tr></thead>
          <tbody>
            <?php foreach ($netNetwork['nodes'] as $n): ?>
            <tr>
              <td><a class="id-link" href="https://www.wikidata.org/wiki/<?= h($n['id']) ?>" target="_blank"><?= h($n['id']) ?></a></td>
              <td><?= h($n['label'] ?? '') ?></td>
              <td class="muted"><?= (int)($n['dump_line'] ?? 0) ?: '—' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </details>

    <!-- Liens -->
    <details style="margin-bottom:1rem;">
      <summary style="cursor:pointer;font-weight:600;margin-bottom:.5rem;">Liens (<?= $linkCount ?>)</summary>
      <div style="max-height:300px;overflow-y:auto;">
        <table>
          <thead><tr><th>Source (sous-classe)</th><th>Destination (superclasse)</th></tr></thead>
          <tbody>
            <?php foreach ($netNetwork['links'] as $l): ?>
            <tr>
              <td><a class="id-link" href="https://www.wikidata.org/wiki/<?= h($l['idSrc']) ?>" target="_blank"><?= h($l['idSrc']) ?></a></td>
              <td><a class="id-link" href="https://www.wikidata.org/wiki/<?= h($l['idDst']) ?>" target="_blank"><?= h($l['idDst']) ?></a></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </details>

    <!-- JSON brut -->
    <details>
      <summary style="cursor:pointer;font-weight:600;margin-bottom:.5rem;">JSON brut</summary>
      <pre style="max-height:400px;overflow:auto;background:#1e1e1e;color:#d4d4d4;padding:1rem;border-radius:6px;font-size:.8rem"><?= h(json_encode($netNetwork, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
    </details>
  </div>
  <?php endif; ?>

</div><!-- /tab-net -->

</main>
<footer>
  <a href="https://github.com/JeroenDeDauw/JsonDumpReader" target="_blank">jeroen/json-dump-reader</a>
  &nbsp;·&nbsp; <a href="https://www.wikidata.org/" target="_blank">Wikidata</a> CC0
</footer>

<script>
function showTab(name, el) {
  document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');
  el.classList.add('active');
}

function toggleAll(master) {
  document.querySelectorAll('.rcb').forEach(cb => cb.checked = master.checked);
}

function getCheckedIds() {
  return [...document.querySelectorAll('.rcb:checked')].map(cb => cb.value);
}

function submitExport(action) {
  const ids = getCheckedIds();
  if ((action === 'export_sel' || action === 'csv_results') && ids.length === 0) {
    alert('Cochez au moins une ligne.'); return;
  }
  document.getElementById('exportAction').value = action;
  document.getElementById('exportIds').value = JSON.stringify(ids);
  document.getElementById('exportForm').submit();
}

function submitExportAll(action) {
  document.getElementById('exportAction').value = action;
  // ids vide = le serveur utilisera rows complet
  document.getElementById('exportIds').value = JSON.stringify(
    [...document.querySelectorAll('.rcb')].map(cb => cb.value)
  );
  document.getElementById('exportForm').submit();
}
</script>
</body>
</html>
