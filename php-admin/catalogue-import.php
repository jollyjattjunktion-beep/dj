<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$expected = envv('IMPORT_TOKEN', '');
$provided = $_SERVER['HTTP_X_IMPORT_TOKEN'] ?? '';
if ($expected === '' || !hash_equals($expected, $provided)) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'Unauthorized']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'POST required']);
    exit;
}
try {
    $data = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) throw new RuntimeException('Invalid JSON payload');
    $title = trim((string)($data['title'] ?? ''));
    $artist = trim((string)($data['artist'] ?? ''));
    $source = trim((string)($data['source_url'] ?? ''));
    if ($title === '' || $source === '') throw new RuntimeException('Song title and source URL are required');
    $sourceParts = parse_url($source);
    if (($sourceParts['scheme'] ?? '') !== 'https' || !in_array(strtolower($sourceParts['host'] ?? ''), ['djjohal.com','www.djjohal.com'], true)) {
        throw new RuntimeException('Unapproved source URL');
    }
    $pdo = db();
    $columns = $pdo->query("PRAGMA table_info(songs)")->fetchAll();
    $names = array_column($columns, 'name');
    if (!in_array('source_url', $names, true)) {
        $pdo->exec("ALTER TABLE songs ADD COLUMN source_url TEXT DEFAULT ''");
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_songs_source_url ON songs(source_url) WHERE source_url IS NOT NULL AND source_url <> ''");
    }
    $artistId = null;
    if ($artist !== '') {
        $artistSlug = slugify($artist);
        $q = $pdo->prepare('INSERT INTO artists(name,slug,bio) VALUES(?,?,?) ON CONFLICT(slug) DO UPDATE SET name=excluded.name');
        $q->execute([$artist, $artistSlug, '']);
        $q = $pdo->prepare('SELECT id FROM artists WHERE slug=?');
        $q->execute([$artistSlug]);
        $artistId = (int)$q->fetchColumn();
    }
    $q = $pdo->prepare('SELECT id,slug FROM songs WHERE source_url=? LIMIT 1');
    $q->execute([$source]);
    $existing = $q->fetch();
    $slugBase = slugify($title . ($artist !== '' ? ' ' . $artist : ''));
    $slug = $slugBase;
    if (!$existing) {
        $q = $pdo->prepare('SELECT 1 FROM songs WHERE slug=?');
        $q->execute([$slug]);
        if ($q->fetchColumn()) $slug .= '-' . substr(hash('sha256', $source), 0, 8);
    } else {
        $slug = $existing['slug'];
    }
    $fields = [
        $title, $slug, $artistId, null, trim((string)($data['album'] ?? '')),
        trim((string)($data['release_date'] ?? '')), trim((string)($data['description'] ?? '')),
        trim((string)($data['artwork_url'] ?? '')), '', 'stream', '',
        $title . ($artist !== '' ? ' – ' . $artist : '') . ' | Punjabi Song | JattBeat',
        'Explore ' . $title . ($artist !== '' ? ' by ' . $artist : '') . ' on JattBeat.',
        1, $source
    ];
    if ($existing) {
        $q = $pdo->prepare('UPDATE songs SET title=?,artist_id=COALESCE(?,artist_id),album=?,release_date=?,description=?,artwork_url=?,meta_title=?,meta_description=?,published=1,source_url=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
        $q->execute([$title,$artistId,$fields[4],$fields[5],$fields[6],$fields[7],$fields[11],$fields[12],$source,(int)$existing['id']]);
        $id = (int)$existing['id'];
        $action = 'updated';
    } else {
        $q = $pdo->prepare('INSERT INTO songs(title,slug,artist_id,category_id,album,release_date,description,artwork_url,media_url,media_type,rights_note,meta_title,meta_description,published,source_url) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $q->execute($fields);
        $id = (int)$pdo->lastInsertId();
        $action = 'created';
    }
    echo json_encode(['ok'=>true,'action'=>$action,'song_id'=>$id]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>substr($e->getMessage(),0,240)]);
}
